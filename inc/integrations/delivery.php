<?php
/**
 * Integrations: the delivery engine.
 *
 *   outbox event -> routing -> one delivery per destination (idempotency key
 *   unique per destination+event) -> lease -> connector -> outcome.
 *
 * Reliability model
 *   - Idempotency: key = sha256(destination | event id). The unique index
 *     (destination_id, idempotency_key) makes routing the same event twice a
 *     no-op, and the key travels to the receiver as a header.
 *   - Leasing: workers take rows with SELECT ... FOR UPDATE SKIP LOCKED while
 *     holding a lock on the destination row, so the per-destination
 *     concurrency limit holds across processes. A lease expires (crashed
 *     worker) and the row is retried; for ticket creation the "outcome
 *     unknown" flag was persisted before the request, so a crash never
 *     produces a second ticket.
 *   - Retry: exponential backoff with jitter up to max_attempts, then dead
 *     letter. 429 + Retry-After pauses the whole destination and does not
 *     consume the attempt budget.
 *   - Ordering: ticket deliveries for one incident+destination are sent in
 *     order; an earlier unfinished one holds back the later ones.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/routing.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/connector_base.php';

if (!function_exists('integration_idempotency_key')) {
    function integration_idempotency_key(int $destId, string $eventId, string $suffix = ''): string {
        return hash('sha256', "dest:{$destId}|event:{$eventId}" . ($suffix !== '' ? "|{$suffix}" : ''));
    }
}

if (!function_exists('integration_event_row_facts')) {
    function integration_event_row_facts(array $row): array {
        return [
            'event_type' => $row['event_type'], 'customer_id' => $row['customer_id'], 'site_id' => $row['site_id'],
            'firewall_id' => $row['firewall_id'], 'incident_id' => $row['incident_id'], 'severity' => $row['severity'],
            'origin_destination_id' => $row['origin_destination_id'], 'forced_route_id' => $row['forced_route_id'],
        ];
    }
}

if (!function_exists('integration_route_event')) {
    /** Fan one outbox event out into deliveries. Idempotent. @return int deliveries created */
    function integration_route_event(array $row): int {
        $n = 0;
        $pdo = db();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            foreach (integration_routing_decisions(integration_event_row_facts($row)) as $d) {
                if (!$d['matched'] || !in_array($d['verdict'], ['deliver', 'suppress', 'defer'], true) || !$d['destination']) { continue; }
                $dest = $d['destination'];
                if ($d['detail'] === 'destination disabled') { continue; }
                $status = $d['verdict'] === 'suppress' ? 'suppressed' : 'pending';
                $next = $d['verdict'] === 'defer' ? gmdate('Y-m-d H:i:s', $d['defer_until']) : null;
                $ins = $pdo->prepare('INSERT IGNORE INTO integration_deliveries
                    (event_row_id, event_id, event_type, destination_id, route_id, idempotency_key, action, status, suppress_reason,
                     max_attempts, next_attempt_at, customer_id, site_id, firewall_id, incident_id, severity)
                    VALUES (?,?,?,?,?,?,?,?,?,?,' . ($next ? 'FROM_UNIXTIME(?)' : 'NOW()') . ',?,?,?,?,?)');
                $args = [$row['id'], $row['event_id'], $row['event_type'], $dest['id'], $d['route']['id'],
                         integration_idempotency_key((int)$dest['id'], $row['event_id']),
                         $dest['type'] === 'ticket_webhook' ? 'ticket' : 'notify', $status,
                         $status === 'suppressed' ? substr($d['detail'], 0, 128) : null, $dest['max_attempts']];
                if ($next) { $args[] = $d['defer_until']; }
                array_push($args, $row['customer_id'], $row['site_id'], $row['firewall_id'], $row['incident_id'], $row['severity']);
                $ins->execute($args);
                $n += $ins->rowCount();
            }
            $pdo->prepare("UPDATE integration_events SET status='routed', routed_at=NOW() WHERE id = ?")->execute([$row['id']]);
            if ($own) { $pdo->commit(); }
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('OPNMGR integrations: routing failed for event ' . $row['event_id'] . ': ' . $e->getMessage());
            return 0;
        }
        return $n;
    }
}

if (!function_exists('integration_route_pending_events')) {
    function integration_route_pending_events(int $limit = 200): int {
        $rows = db()->query("SELECT * FROM integration_events WHERE status = 'pending' ORDER BY id LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_ASSOC);
        $n = 0;
        foreach ($rows as $r) { $n += integration_route_event($r); }
        return $n;
    }
}

if (!function_exists('integration_sweep_leases')) {
    /** Return deliveries whose worker vanished to the retry queue. */
    function integration_sweep_leases(): int {
        $s = db()->prepare("SELECT id, attempts FROM integration_deliveries WHERE status IN ('leased','sending') AND lease_until < NOW()");
        $s->execute();
        $n = 0;
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $d) {
            db()->prepare("UPDATE integration_deliveries SET status='retry', lease_until=NULL, lease_token=NULL, next_attempt_at=NOW(),
                           last_error='worker lease expired; retrying' WHERE id=? AND status IN ('leased','sending')")->execute([$d['id']]);
            $n++;
        }
        return $n;
    }
}

if (!function_exists('integration_lease_deliveries')) {
    /**
     * Claim due deliveries, honouring each destination's concurrency limit and pause.
     * @return array[] leased delivery rows
     */
    function integration_lease_deliveries(int $max = 20, ?int $onlyDest = null, int $leaseSeconds = 120): array {
        $pdo = db();
        $leased = [];
        $dests = $pdo->query("SELECT id FROM integration_destinations WHERE enabled = 1 AND (paused_until IS NULL OR paused_until <= NOW())"
            . ($onlyDest ? ' AND id = ' . (int)$onlyDest : ''))->fetchAll(PDO::FETCH_COLUMN);
        foreach ($dests as $destId) {
            if (count($leased) >= $max) { break; }
            $token = bin2hex(random_bytes(16));
            $pdo->beginTransaction();
            try {
                // Serialise lease decisions per destination so the concurrency count is race-free.
                $lock = $pdo->prepare('SELECT concurrency_limit FROM integration_destinations WHERE id = ? FOR UPDATE');
                $lock->execute([$destId]);
                $limit = (int)$lock->fetchColumn();
                $busy = $pdo->prepare("SELECT COUNT(*) FROM integration_deliveries WHERE destination_id = ? AND status IN ('leased','sending') AND lease_until >= NOW()");
                $busy->execute([$destId]);
                $cap = min($limit - (int)$busy->fetchColumn(), $max - count($leased));
                if ($cap > 0) {
                    $sel = $pdo->prepare("SELECT d.id FROM integration_deliveries d
                         WHERE d.destination_id = ? AND d.status IN ('pending','retry') AND d.next_attempt_at <= NOW()
                           AND NOT EXISTS (SELECT 1 FROM integration_deliveries e
                                            WHERE e.destination_id = d.destination_id AND e.incident_id = d.incident_id
                                              AND d.action = 'ticket' AND e.action = 'ticket' AND e.id < d.id AND d.incident_id IS NOT NULL
                                              AND e.status IN ('pending','retry','leased','sending','needs_reconciliation'))
                         ORDER BY d.id LIMIT " . (int)$cap . " FOR UPDATE SKIP LOCKED");
                    $sel->execute([$destId]);
                    $ids = $sel->fetchAll(PDO::FETCH_COLUMN);
                    if ($ids) {
                        $marks = implode(',', array_fill(0, count($ids), '?'));
                        $pdo->prepare("UPDATE integration_deliveries SET status='leased', lease_token=?,
                                       lease_until = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id IN ({$marks})")
                            ->execute(array_merge([$token, $leaseSeconds], array_map('intval', $ids)));
                        $got = $pdo->prepare("SELECT * FROM integration_deliveries WHERE id IN ({$marks}) ORDER BY id");
                        $got->execute(array_map('intval', $ids));
                        foreach ($got->fetchAll(PDO::FETCH_ASSOC) as $r) { $leased[] = $r; }
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('OPNMGR integrations: lease failed: ' . $e->getMessage());
            }
        }
        return $leased;
    }
}

if (!function_exists('integration_record_attempt')) {
    function integration_record_attempt(int $deliveryId, int $no, string $startedAt, array $res, ?int $ms): void {
        db()->prepare('INSERT INTO integration_delivery_attempts (delivery_id, attempt_no, started_at, duration_ms, outcome, http_status, resolved_ip, error)
                       VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$deliveryId, $no, $startedAt, $ms, $res['outcome'], $res['http'], $res['ip'] ?: null,
                       $res['error'] !== '' ? substr($res['error'], 0, 500) : null]);
    }
}

if (!function_exists('integration_apply_outcome')) {
    function integration_apply_outcome(array $d, array $dest, array $res): void {
        $pdo = db();
        $err = $res['error'] !== '' ? substr(integration_redact_string($res['error'], integration_destination_secrets_list($dest)), 0, 500) : null;
        $common = [$res['http'], $err, $res['ip'] ?: null];
        $attempts = (int)$d['attempts'];
        switch ($res['outcome']) {
            case 'success':
                $pdo->prepare("UPDATE integration_deliveries SET status='succeeded', attempts=attempts+1, delivered_at=NOW(), lease_until=NULL, lease_token=NULL,
                    last_http_status=?, last_error=?, resolved_ip=? WHERE id=?")->execute(array_merge($common, [$d['id']]));
                break;
            case 'rate_limited':
                $wait = max(1, min(3600, (int)($res['retry_after'] ?? 60)));
                $hits = (int)$d['rate_limit_hits'] + 1;
                $dead = $hits >= 20;
                $pdo->prepare("UPDATE integration_deliveries SET status=?, rate_limit_hits=?, lease_until=NULL, lease_token=NULL,
                    next_attempt_at=DATE_ADD(NOW(), INTERVAL ? SECOND), last_http_status=?, last_error=?, resolved_ip=? WHERE id=?")
                    ->execute(array_merge([$dead ? 'dead' : 'retry', $hits, $wait + random_int(0, 3)], $common, [$d['id']]));
                $pdo->prepare('UPDATE integration_destinations SET paused_until = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?')->execute([$wait, $dest['id']]);
                break;
            case 'reconcile':
                $pdo->prepare("UPDATE integration_deliveries SET status='needs_reconciliation', attempts=attempts+1, lease_until=NULL, lease_token=NULL,
                    last_http_status=?, last_error=?, resolved_ip=? WHERE id=?")->execute(array_merge($common, [$d['id']]));
                break;
            case 'permanent':
                $pdo->prepare("UPDATE integration_deliveries SET status='dead', attempts=attempts+1, lease_until=NULL, lease_token=NULL,
                    last_http_status=?, last_error=?, resolved_ip=? WHERE id=?")->execute(array_merge($common, [$d['id']]));
                break;
            default: // retry, ambiguous
                $attempts++;
                $dead = $attempts >= (int)$d['max_attempts'];
                $pdo->prepare("UPDATE integration_deliveries SET status=?, attempts=?, lease_until=NULL, lease_token=NULL,
                    next_attempt_at=DATE_ADD(NOW(), INTERVAL ? SECOND), last_http_status=?, last_error=?, resolved_ip=? WHERE id=?")
                    ->execute(array_merge([$dead ? 'dead' : 'retry', $attempts, integration_backoff_seconds($attempts)], $common, [$d['id']]));
        }
    }
}

if (!function_exists('integration_load_envelope')) {
    function integration_load_envelope(int $eventRowId): ?array {
        $s = db()->prepare('SELECT * FROM integration_events WHERE id = ?');
        $s->execute([$eventRowId]);
        $e = $s->fetch(PDO::FETCH_ASSOC);
        return $e ? ['row' => $e, 'envelope' => json_decode($e['envelope_json'], true) ?: []] : null;
    }
}

if (!function_exists('integration_process_delivery')) {
    /** Send one leased delivery. Never throws. @return string final status */
    function integration_process_delivery(array $d): string {
        $pdo = db();
        $started = integration_now();
        $t0 = microtime(true);
        $upd = $pdo->prepare("UPDATE integration_deliveries SET status='sending' WHERE id = ? AND lease_token = ? AND status = 'leased'");
        $upd->execute([$d['id'], $d['lease_token']]);
        if ($upd->rowCount() === 0) { return 'lost-lease'; }

        $dest = integration_destination_load((int)$d['destination_id']);
        $ev = integration_load_envelope((int)$d['event_row_id']);
        $no = (int)$d['attempts'] + 1;
        if (!$dest || !$ev) {
            $res = integration_result('permanent', ['error' => 'destination or event no longer exists']);
            integration_record_attempt((int)$d['id'], $no, $started, $res, 0);
            integration_apply_outcome($d, $dest ?: ['id' => $d['destination_id']], $res);
            return 'dead';
        }

        // Send-time gate: the incident may have been acknowledged, resolved or put into maintenance since routing.
        if (empty($d['is_test'])) {
            $inc = null;
            if ($d['incident_id']) {
                $s = $pdo->prepare('SELECT * FROM alert_incidents WHERE id = ?'); $s->execute([$d['incident_id']]);
                $inc = $s->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            $gate = integration_gate($dest, integration_event_row_facts($ev['row']), $inc);
            if ($gate !== null) {
                $pdo->prepare("UPDATE integration_deliveries SET status='suppressed', suppress_reason=?, lease_until=NULL, lease_token=NULL WHERE id=?")
                    ->execute([substr($gate, 0, 128), $d['id']]);
                return 'suppressed';
            }
        }

        // DNS pinning guard: remember the resolved address of the primary endpoint and flag changes.
        $primaryHost = strtolower((string)parse_url((string)($dest['secrets']['url'] ?? ''), PHP_URL_HOST));
        $GLOBALS['OPNMGR_INTEGRATION_IP_GUARD'] = function (string $host, string $ip) use ($dest, $primaryHost): ?string {
            if (strtolower($host) !== $primaryHost) { return null; }
            return integration_check_endpoint_ip((int)$dest['id'], $ip);
        };
        try {
            $connector = integration_connector_for($dest['type']);
            $res = $connector
                ? $connector->deliver($dest, $d, $ev['envelope'])
                : integration_result('permanent', ['error' => 'unknown connector type']);
        } catch (Throwable $e) {
            $res = integration_result('retry', ['error' => integration_redact_string(get_class($e) . ': ' . $e->getMessage(), integration_destination_secrets_list($dest))]);
        } finally {
            unset($GLOBALS['OPNMGR_INTEGRATION_IP_GUARD']);
        }
        $res += ['outcome' => 'retry', 'http' => null, 'error' => '', 'retry_after' => null, 'ip' => ''];
        $res['error'] = integration_redact_string((string)$res['error'], integration_destination_secrets_list($dest));
        integration_record_attempt((int)$d['id'], $no, $started, $res, (int)round((microtime(true) - $t0) * 1000));
        integration_apply_outcome($d, $dest, $res);
        $s = $pdo->prepare('SELECT status FROM integration_deliveries WHERE id = ?'); $s->execute([$d['id']]);
        return (string)$s->fetchColumn();
    }
}

if (!function_exists('integration_check_endpoint_ip')) {
    /**
     * Track the address an endpoint resolves to. A change is recorded and
     * audited; with ip_change_policy=block the delivery is held until an
     * operator accepts it. Returns an error string to block, else null.
     */
    function integration_check_endpoint_ip(int $destId, string $ip): ?string {
        $s = db()->prepare('SELECT last_ip, ip_change_pending, ip_change_policy FROM integration_destinations WHERE id = ?');
        $s->execute([$destId]);
        $d = $s->fetch(PDO::FETCH_ASSOC);
        if (!$d) { return null; }
        if ($d['last_ip'] === null || $d['last_ip'] === '') {
            db()->prepare('UPDATE integration_destinations SET last_ip = ? WHERE id = ?')->execute([$ip, $destId]);
            return null;
        }
        if ($d['last_ip'] === $ip) { return null; }
        if (!(int)$d['ip_change_pending']) {
            db()->prepare('UPDATE integration_destinations SET ip_change_pending = 1, last_ip_changed_at = NOW() WHERE id = ?')->execute([$destId]);
            audit_log('integration.endpoint_ip_changed', ['actor_type' => 'system', 'object_type' => 'integration_destination', 'object_id' => (string)$destId,
                'success' => false, 'message' => "Endpoint address changed from {$d['last_ip']} to {$ip}"]);
        }
        if ($d['ip_change_policy'] === 'block') {
            return "endpoint address changed from {$d['last_ip']} to {$ip}; an operator must accept the change";
        }
        db()->prepare('UPDATE integration_destinations SET last_ip = ? WHERE id = ?')->execute([$ip, $destId]);
        return null;
    }
}

if (!function_exists('integration_escalation_sweep')) {
    /** Emit incident.escalated for open, unacknowledged incidents older than a route's threshold. @return int emitted */
    function integration_escalation_sweep(): int {
        require_once __DIR__ . '/events.php';
        $n = 0;
        $routes = db()->query('SELECT * FROM integration_routes WHERE enabled = 1 AND escalate_after_minutes IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($routes as $r) {
            $s = db()->prepare("SELECT i.* FROM alert_incidents i
                                 WHERE i.status = 'open' AND i.first_seen_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)
                                   AND NOT EXISTS (SELECT 1 FROM integration_escalations x WHERE x.route_id = ? AND x.incident_id = i.id)");
            $s->execute([(int)$r['escalate_after_minutes'], $r['id']]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $inc) {
                if ($inc['firewall_id'] !== null && maintenance_suppresses_alerts((int)$inc['firewall_id'])) { continue; } // try again after the window
                $ev = ['event_type' => 'incident.escalated', 'customer_id' => $inc['customer_id'], 'site_id' => $inc['site_id'],
                       'firewall_id' => $inc['firewall_id'], 'incident_id' => $inc['id'], 'severity' => $inc['severity'], 'forced_route_id' => $r['id']];
                $facts = integration_event_facts_for_routing($ev);
                if (integration_route_matches($r, $ev, $facts) !== null) { continue; }
                $ins = db()->prepare('INSERT IGNORE INTO integration_escalations (route_id, incident_id, fired_at) VALUES (?,?,NOW())');
                $ins->execute([$r['id'], $inc['id']]);
                if ($ins->rowCount() === 0) { continue; }
                integration_emit('incident.escalated', [
                    'incident_id' => $inc['id'], 'forced_route_id' => (int)$r['id'],
                    'dedupe_key' => "escalate:{$r['id']}:{$inc['id']}",
                    'summary' => 'Unacknowledged after ' . (int)$r['escalate_after_minutes'] . ' min: ' . $inc['title'],
                ]);
                $n++;
            }
        }
        return $n;
    }
}

if (!function_exists('integration_run_cycle')) {
    /** One worker pass: route, sweep, escalate, deliver until idle or out of time. */
    function integration_run_cycle(int $budgetSeconds = 50): array {
        $stats = ['routed' => 0, 'escalated' => 0, 'swept' => 0, 'sent' => 0, 'by_status' => []];
        $deadline = microtime(true) + $budgetSeconds;
        $stats['swept'] = integration_sweep_leases();
        $stats['escalated'] = integration_escalation_sweep();
        $stats['routed'] = integration_route_pending_events();
        while (microtime(true) < $deadline) {
            $batch = integration_lease_deliveries(20);
            if (!$batch) { break; }
            foreach ($batch as $d) {
                $st = integration_process_delivery($d);
                $stats['sent']++;
                $stats['by_status'][$st] = ($stats['by_status'][$st] ?? 0) + 1;
                if (microtime(true) >= $deadline) { break; }
            }
        }
        return $stats;
    }
}

if (!function_exists('integration_replay')) {
    /**
     * Manual replay. Dead/cancelled deliveries are reset in place (same
     * idempotency key, so a receiver that already acted still de-duplicates);
     * succeeded/suppressed notification deliveries are re-sent as a new
     * delivery. needs_reconciliation is resolved through
     * integration_ticket_reconcile(), not replay.
     * @return array{ok:bool,error:string,id:int}
     */
    function integration_replay(int $deliveryId, string $actor): array {
        $s = db()->prepare('SELECT * FROM integration_deliveries WHERE id = ?');
        $s->execute([$deliveryId]);
        $d = $s->fetch(PDO::FETCH_ASSOC);
        if (!$d) { return ['ok' => false, 'error' => 'Unknown delivery', 'id' => 0]; }
        if (!scope_can_customer($d['customer_id'] !== null ? (int)$d['customer_id'] : null)) {
            return ['ok' => false, 'error' => 'Not permitted for this customer', 'id' => 0];
        }
        $id = $deliveryId;
        if (in_array($d['status'], ['dead', 'cancelled'], true)) {
            db()->prepare("UPDATE integration_deliveries SET status='pending', attempts=0, rate_limit_hits=0, next_attempt_at=NOW(),
                           replay_count=replay_count+1, last_error=NULL, suppress_reason=NULL, lease_until=NULL, lease_token=NULL WHERE id=?")->execute([$deliveryId]);
        } elseif (in_array($d['status'], ['succeeded', 'suppressed'], true) && $d['action'] === 'notify') {
            $n = $d['replay_count'] + 1;
            db()->prepare('UPDATE integration_deliveries SET replay_count = ? WHERE id = ?')->execute([$n, $deliveryId]);
            $ins = db()->prepare("INSERT INTO integration_deliveries (event_row_id, event_id, event_type, destination_id, route_id, idempotency_key, action,
                status, max_attempts, next_attempt_at, customer_id, site_id, firewall_id, incident_id, severity, replay_of, is_test)
                VALUES (?,?,?,?,?,?,?,'pending',?,NOW(),?,?,?,?,?,?,?)");
            $ins->execute([$d['event_row_id'], $d['event_id'], $d['event_type'], $d['destination_id'], $d['route_id'],
                integration_idempotency_key((int)$d['destination_id'], $d['event_id'], "replay:{$deliveryId}:{$n}"), $d['action'],
                $d['max_attempts'], $d['customer_id'], $d['site_id'], $d['firewall_id'], $d['incident_id'], $d['severity'], $deliveryId, $d['is_test']]);
            $id = (int)db()->lastInsertId();
        } else {
            return ['ok' => false, 'error' => "A delivery in status '{$d['status']}' cannot be replayed", 'id' => 0];
        }
        audit_log('integration.delivery_replayed', ['object_type' => 'integration_delivery', 'object_id' => (string)$deliveryId,
            'customer_id' => $d['customer_id'], 'message' => "Delivery {$deliveryId} replayed by {$actor}"]);
        return ['ok' => true, 'error' => '', 'id' => $id];
    }
}

if (!function_exists('integration_send_test')) {
    /**
     * Send one clearly labelled test message to a destination, synchronously.
     * Called only from the explicit Test button.
     * @return array{ok:bool,status:string,error:string}
     */
    function integration_send_test(int $destId, string $actor): array {
        require_once __DIR__ . '/events.php';
        $dest = integration_destination_load($destId);
        if (!$dest) { return ['ok' => false, 'status' => 'missing', 'error' => 'Unknown destination']; }
        $eid = integration_emit('integration.test', [
            'summary' => 'Test message from OPNManager (sent by ' . $actor . ')', 'test' => true,
            'customer_id' => $dest['customer_id'], 'details' => ['note' => 'This is a test message; no action is required.'],
        ]);
        if (!$eid) { return ['ok' => false, 'status' => 'error', 'error' => 'Could not record test event']; }
        $s = db()->prepare('SELECT * FROM integration_events WHERE event_id = ?'); $s->execute([$eid]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        db()->prepare("UPDATE integration_events SET status='routed', routed_at=NOW() WHERE id=?")->execute([$row['id']]);
        $token = bin2hex(random_bytes(16));
        db()->prepare("INSERT INTO integration_deliveries (event_row_id, event_id, event_type, destination_id, idempotency_key, action, status, max_attempts,
              next_attempt_at, lease_until, lease_token, customer_id, is_test)
              VALUES (?,?,?,?,?,?,'leased',1,NOW(),DATE_ADD(NOW(), INTERVAL 120 SECOND),?,?,1)")
            ->execute([$row['id'], $eid, 'integration.test', $destId, integration_idempotency_key($destId, $eid, 'test'),
                       $dest['type'] === 'ticket_webhook' ? 'ticket' : 'notify', $token, $dest['customer_id']]);
        $did = (int)db()->lastInsertId();
        $s = db()->prepare('SELECT * FROM integration_deliveries WHERE id = ?'); $s->execute([$did]);
        $status = integration_process_delivery($s->fetch(PDO::FETCH_ASSOC));
        $s = db()->prepare('SELECT last_error FROM integration_deliveries WHERE id = ?'); $s->execute([$did]);
        audit_log('integration.test_sent', ['object_type' => 'integration_destination', 'object_id' => (string)$destId, 'customer_id' => $dest['customer_id'],
            'success' => $status === 'succeeded', 'message' => "Test message to '{$dest['name']}' by {$actor}: {$status}"]);
        return ['ok' => $status === 'succeeded', 'status' => $status, 'error' => (string)$s->fetchColumn(), 'delivery_id' => $did];
    }
}
