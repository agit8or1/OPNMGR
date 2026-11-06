<?php
/**
 * Integrations: ticket synchronisation.
 *
 * Invariants:
 *   - One live link (integration_ticket_links, active_key) per incident per
 *     destination. Later events update that ticket.
 *   - The ticket's workflow state (ticket_state) is stored apart from the
 *     observed incident state (incident_state). Nothing here ever resolves a
 *     local incident because an external ticket was closed.
 *   - A create is never repeated while its outcome is unknown. Before each
 *     create attempt outcome_unknown is persisted. On retry the ticket is
 *     looked up by idempotency key (lookup_url) or, when the connector cannot
 *     look up, the link is parked as needs_reconciliation for an operator.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/connector_base.php';

if (!function_exists('integration_link_key')) {
    function integration_link_key(int $destId, int $incidentId): string { return $destId . ':' . $incidentId; }
}

if (!function_exists('integration_ticket_link')) {
    /** The live link (including parked ones) for an incident at a destination. */
    function integration_ticket_link(int $destId, int $incidentId): ?array {
        $s = db()->prepare('SELECT * FROM integration_ticket_links WHERE active_key = ?');
        $s->execute([integration_link_key($destId, $incidentId)]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('integration_ticket_link_update')) {
    function integration_ticket_link_update(int $linkId, array $set): void {
        $cols = []; $vals = [];
        foreach ($set as $k => $v) { $cols[] = "`{$k}` = ?"; $vals[] = $v; }
        $vals[] = $linkId;
        db()->prepare('UPDATE integration_ticket_links SET ' . implode(',', $cols) . ' WHERE id = ?')->execute($vals);
    }
}

if (!function_exists('integration_ticket_payload')) {
    function integration_ticket_payload(string $op, array $envelope, ?array $link, string $idem, array $extra = []): array {
        $inc = $envelope['incident'] ?? [];
        $status = in_array($op, ['resolve'], true) ? 'resolved'
                : (($inc['status'] ?? '') === 'acknowledged' ? 'acknowledged' : 'open');
        return [
            'schema' => 'opnmanager.ticket', 'version' => 1, 'operation' => $op,
            'idempotency_key' => $idem,
            'external_id' => $link['external_id'] ?? null,
            'ticket' => [
                'title' => integration_event_title($envelope),
                'description' => $envelope['summary'] ?? '',
                'severity' => $inc['severity'] ?? null,
                'status' => $status,
                'incident_id' => $inc['id'] ?? null,
                'incident_type' => $inc['type'] ?? null,
                'customer' => $envelope['customer'] ?? null,
                'site' => $envelope['site'] ?? null,
                'firewall' => $envelope['firewall'] ?? null,
                'manager_link' => $envelope['links']['manager'] ?? null,
                'comment' => $extra['comment'] ?? null,
            ],
            'event' => ['id' => $envelope['id'], 'type' => $envelope['type'], 'occurred_at' => $envelope['occurred_at']],
        ];
    }
}

if (!function_exists('integration_ticket_deliver')) {
    /**
     * Decide and perform the ticket call for one delivery. See file header.
     * Extra outcome 'reconcile' = park the delivery as needs_reconciliation.
     */
    function integration_ticket_deliver(OpnmgrConnectorTicketWebhook $c, array $dest, array $delivery, array $envelope): array {
        $incidentId = (int)($envelope['incident']['id'] ?? 0);
        $type = $envelope['type'];

        if (!empty($envelope['test'])) {
            // Connectivity test: a clearly labelled create-style call, never linked to an incident.
            $p = integration_ticket_payload('create', $envelope, null, $delivery['idempotency_key']);
            $p['test'] = true;
            $r = $c->call('create', $dest, ['idempotency_key' => $delivery['idempotency_key']], $p, $delivery['idempotency_key']);
            return $r;
        }
        if ($incidentId <= 0) {
            return integration_result('permanent', ['error' => 'ticketing needs an incident']);
        }

        $link = integration_ticket_link((int)$dest['id'], $incidentId);
        $needsCreate = !$link || ($link['link_state'] === 'creating' && empty($link['external_id']));

        if ($needsCreate) {
            // Nothing to resolve or acknowledge if no ticket was ever made.
            if ($type === 'incident.resolved' || $type === 'incident.acknowledged') {
                return integration_result('success', ['error' => 'no ticket exists for this incident; nothing to update']);
            }
            return integration_ticket_create($c, $dest, $delivery, $envelope, $link);
        }
        if ($link['link_state'] === 'needs_reconciliation') {
            return integration_result('reconcile', ['error' => 'ticket mapping needs reconciliation by an operator']);
        }
        return integration_ticket_update($c, $dest, $delivery, $envelope, $link);
    }
}

if (!function_exists('integration_ticket_create')) {
    function integration_ticket_create(OpnmgrConnectorTicketWebhook $c, array $dest, array $delivery, array $envelope, ?array $link): array {
        $incidentId = (int)$envelope['incident']['id'];
        $destId = (int)$dest['id'];
        if (!$link) {
            db()->prepare('INSERT IGNORE INTO integration_ticket_links
                (destination_id, incident_id, active_key, link_state, create_idempotency_key, incident_state, customer_id, site_id, firewall_id)
                VALUES (?,?,?,"creating",?,?,?,?,?)')
              ->execute([$destId, $incidentId, integration_link_key($destId, $incidentId), $delivery['idempotency_key'],
                         $envelope['incident']['status'] ?? null, $delivery['customer_id'], $delivery['site_id'], $delivery['firewall_id']]);
            $link = integration_ticket_link($destId, $incidentId);
            if (!$link) { return integration_result('retry', ['error' => 'could not create ticket mapping']); }
        }
        $idem = $link['create_idempotency_key'] ?: $delivery['idempotency_key'];
        $vars = ['idempotency_key' => $idem, 'incident_id' => $incidentId];

        // Recurrence: reopen the previous ticket instead of creating a new one, when the policy says so.
        if ($dest['on_recurrence'] === 'reopen' && !$link['outcome_unknown'] && empty($link['reopened_from_link_id'])) {
            $prev = integration_ticket_previous($destId, $incidentId, (int)$dest['reopen_window_minutes']);
            if ($prev) {
                $vars['external_id'] = $prev['external_id'];
                $r = $c->call('reopen', $dest, $vars, integration_ticket_payload('reopen', $envelope, $prev, $idem), $idem);
                if ($r['outcome'] === 'success') {
                    integration_ticket_link_update((int)$link['id'], [
                        'link_state' => 'active', 'external_id' => $prev['external_id'], 'external_url' => $prev['external_url'],
                        'ticket_state' => 'open', 'reopened_from_link_id' => $prev['id'], 'last_synced_at' => integration_now(),
                        'incident_state' => $envelope['incident']['status'] ?? null,
                    ]);
                    $r['external'] = ['id' => $prev['external_id'], 'url' => $prev['external_url'], 'state' => 'open'];
                    return $r;
                }
                if ($r['outcome'] !== 'permanent') { return $r; }
                // Ticket gone / not reopenable: fall through to a fresh ticket.
            }
        }

        // Ambiguous history: look before creating again.
        if ($link['outcome_unknown']) {
            if (!$c->supportsLookup($dest)) {
                integration_ticket_link_update((int)$link['id'], ['link_state' => 'needs_reconciliation']);
                return integration_result('reconcile', ['error' => 'earlier create attempt has an unknown outcome and this connector cannot look tickets up; operator reconciliation required']);
            }
            $found = integration_ticket_lookup($c, $dest, $link, $idem);
            if ($found['state'] === 'found') {
                return integration_ticket_adopt($link, $found['id'], $found['url'], $envelope);
            }
            if ($found['state'] === 'error') {
                return integration_result('retry', ['error' => 'lookup failed: ' . $found['error']]);
            }
            // definitively absent: safe to create
        }

        integration_ticket_link_update((int)$link['id'], [
            'outcome_unknown' => 1, 'create_attempted_at' => integration_now(),
        ]); // persisted BEFORE the request leaves
        $r = $c->call('create', $dest, $vars, integration_ticket_payload('create', $envelope, null, $idem), $idem);

        if ($r['outcome'] === 'success') {
            $x = $c->extract($dest, $r['json']);
            if ($x['id'] === null) {
                // Created, but we cannot tell what it is.
                if ($c->supportsLookup($dest)) {
                    $found = integration_ticket_lookup($c, $dest, $link, $idem);
                    if ($found['state'] === 'found') { return integration_ticket_adopt($link, $found['id'], $found['url'], $envelope) + ['http' => $r['http']]; }
                }
                integration_ticket_link_update((int)$link['id'], ['link_state' => 'needs_reconciliation']);
                return integration_result('reconcile', ['http' => $r['http'], 'ip' => $r['ip'],
                    'error' => 'ticket was created but its id was not found at the configured JSON path']);
            }
            $res = integration_ticket_adopt($link, $x['id'], $x['url'], $envelope);
            return $res + ['http' => $r['http'], 'ip' => $r['ip']];
        }
        if ($r['outcome'] === 'ambiguous') {
            if (!$c->supportsLookup($dest)) {
                integration_ticket_link_update((int)$link['id'], ['link_state' => 'needs_reconciliation']);
                $r['outcome'] = 'reconcile';
                $r['error'] = 'create outcome unknown (' . $r['error'] . '); not retried to avoid a duplicate ticket';
            }
            return $r; // outcome_unknown stays 1
        }
        // Provably not created (never reached the server, rejected, throttled).
        integration_ticket_link_update((int)$link['id'], ['outcome_unknown' => 0]);
        return $r;
    }
}

if (!function_exists('integration_ticket_adopt')) {
    function integration_ticket_adopt(array $link, string $id, ?string $url, array $envelope): array {
        integration_ticket_link_update((int)$link['id'], [
            'link_state' => 'active', 'outcome_unknown' => 0, 'external_id' => $id, 'external_url' => $url,
            'ticket_state' => 'open', 'last_synced_at' => integration_now(),
            'incident_state' => $envelope['incident']['status'] ?? null,
        ]);
        return integration_result('success', ['external' => ['id' => $id, 'url' => $url, 'state' => 'open']]);
    }
}

if (!function_exists('integration_ticket_lookup')) {
    /** @return array{state:string,id:?string,url:?string,error:string} state: found|absent|error */
    function integration_ticket_lookup(OpnmgrConnectorTicketWebhook $c, array $dest, array $link, string $idem): array {
        $r = $c->call('lookup', $dest, ['idempotency_key' => $idem, 'incident_id' => $link['incident_id']], null, $idem);
        if ($r['raw_status'] === 404) { return ['state' => 'absent', 'id' => null, 'url' => null, 'error' => '']; }
        if ($r['outcome'] !== 'success') { return ['state' => 'error', 'id' => null, 'url' => null, 'error' => $r['error']]; }
        $j = $r['json'];
        // A list response: first element counts. Empty = absent.
        if (is_array($j) && array_is_list($j)) { $j = $j[0] ?? null; }
        $x = $j === null ? ['id' => null, 'url' => null] : $c->extract($dest, $j, 'lookup_id_path');
        return $x['id'] === null ? ['state' => 'absent', 'id' => null, 'url' => null, 'error' => '']
                                 : ['state' => 'found', 'id' => $x['id'], 'url' => $x['url'], 'error' => ''];
    }
}

if (!function_exists('integration_ticket_previous')) {
    /** The newest closed ticket for an earlier incident of the same condition, within the reopen window. */
    function integration_ticket_previous(int $destId, int $incidentId, int $windowMinutes): ?array {
        $s = db()->prepare('SELECT dedupe_source FROM alert_incidents WHERE id = ?');
        $s->execute([$incidentId]);
        $src = $s->fetchColumn();
        if (!$src) { return null; }
        $s = db()->prepare('SELECT l.* FROM integration_ticket_links l
                              JOIN alert_incidents i ON i.id = l.incident_id
                             WHERE l.destination_id = ? AND i.dedupe_source = ? AND i.id <> ?
                               AND l.link_state = "closed" AND l.external_id IS NOT NULL
                               AND l.updated_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
                             ORDER BY l.id DESC LIMIT 1');
        $s->execute([$destId, $src, $incidentId, max(1, $windowMinutes)]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('integration_ticket_update')) {
    function integration_ticket_update(OpnmgrConnectorTicketWebhook $c, array $dest, array $delivery, array $envelope, array $link): array {
        $type = $envelope['type'];
        $idem = $delivery['idempotency_key'];
        $vars = ['external_id' => $link['external_id'], 'idempotency_key' => $idem, 'incident_id' => $link['incident_id']];
        $incState = $envelope['incident']['status'] ?? null;
        $set = ['last_synced_at' => integration_now(), 'incident_state' => $incState];

        if ($type === 'incident.resolved') {
            $policy = $dest['on_resolve'];
            if ($policy === 'none') {
                integration_ticket_link_update((int)$link['id'], $set + ['link_state' => 'closed', 'active_key' => null, 'closed_while_incident_active' => 0]);
                return integration_result('success', ['error' => 'resolve policy: no ticket call']);
            }
            $op = $policy === 'resolve' ? 'resolve' : 'comment';
            $r = $c->call($op, $dest, $vars, integration_ticket_payload($op, $envelope, $link, $idem,
                ['comment' => 'OPNManager: the monitored condition has cleared.']), $idem);
            if ($r['outcome'] === 'success') {
                integration_ticket_link_update((int)$link['id'], $set + [
                    'link_state' => 'closed', 'active_key' => null, 'closed_while_incident_active' => 0,
                    'ticket_state' => $op === 'resolve' ? 'closed' : $link['ticket_state'],
                ]);
            }
            return $r;
        }

        // Externally closed but the condition persists: policy decides.
        if ($link['ticket_state'] === 'closed') {
            $recent = $link['last_auto_reopen_at'] && strtotime($link['last_auto_reopen_at']) > time() - 3600;
            if ($dest['on_external_close'] === 'reopen_ticket' && !$recent) {
                $r = $c->call('reopen', $dest, $vars, integration_ticket_payload('reopen', $envelope, $link, $idem,
                    ['comment' => 'OPNManager: the ticket was closed but the condition is still present.']), $idem);
                if ($r['outcome'] === 'success') {
                    integration_ticket_link_update((int)$link['id'], $set + ['ticket_state' => 'open', 'closed_while_incident_active' => 0, 'last_auto_reopen_at' => integration_now()]);
                }
                return $r;
            }
            integration_ticket_link_update((int)$link['id'], $set + ['closed_while_incident_active' => 1]);
            return integration_result('success', ['error' => 'ticket is closed externally; condition still present; not updated (policy: note)']);
        }

        $op = 'update';
        $comment = match ($type) {
            'incident.acknowledged' => 'OPNManager: incident acknowledged' . (!empty($envelope['details']['acknowledged_by']) ? ' by ' . $envelope['details']['acknowledged_by'] : '') . '.',
            'incident.severity_changed' => 'OPNManager: severity changed to ' . ($envelope['incident']['severity'] ?? '?') . '.',
            'incident.escalated' => 'OPNManager: escalated, still unacknowledged.',
            default => null,
        };
        $r = $c->call($op, $dest, $vars, integration_ticket_payload($op, $envelope, $link, $idem, ['comment' => $comment]), $idem);
        if ($r['outcome'] === 'success') {
            integration_ticket_link_update((int)$link['id'], $set + ($type === 'incident.acknowledged' ? ['ticket_state' => 'acknowledged'] : []));
        }
        return $r;
    }
}

// --- operator reconciliation -------------------------------------------------
if (!function_exists('integration_ticket_parked_delivery')) {
    function integration_ticket_parked_delivery(array $link): ?array {
        $s = db()->prepare("SELECT * FROM integration_deliveries WHERE destination_id = ? AND incident_id = ?
                              AND status = 'needs_reconciliation' ORDER BY id LIMIT 1");
        $s->execute([$link['destination_id'], $link['incident_id']]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('integration_ticket_reconcile')) {
    /**
     * Resolve a needs_reconciliation mapping.
     * $mode: 'link' (ticket exists: needs $externalId), 'not_created' (retry the create
     * with the same idempotency key), 'abandon' (stop tracking this incident here).
     * @return array{ok:bool,error:string}
     */
    function integration_ticket_reconcile(int $linkId, string $mode, ?string $externalId, string $actor): array {
        $s = db()->prepare('SELECT * FROM integration_ticket_links WHERE id = ?');
        $s->execute([$linkId]);
        $link = $s->fetch(PDO::FETCH_ASSOC);
        if (!$link) { return ['ok' => false, 'error' => 'Unknown mapping']; }
        if (!scope_can_customer($link['customer_id'] !== null ? (int)$link['customer_id'] : null)) {
            return ['ok' => false, 'error' => 'Not permitted for this customer'];
        }
        if ($link['link_state'] !== 'needs_reconciliation') { return ['ok' => false, 'error' => 'Mapping is not awaiting reconciliation']; }
        $parked = integration_ticket_parked_delivery($link);
        if ($mode === 'link') {
            $externalId = trim((string)$externalId);
            if ($externalId === '' || strlen($externalId) > 191) { return ['ok' => false, 'error' => 'Enter the external ticket id']; }
            integration_ticket_link_update($linkId, ['link_state' => 'active', 'outcome_unknown' => 0, 'external_id' => $externalId,
                'ticket_state' => 'open', 'last_synced_at' => integration_now()]);
            if ($parked) {
                db()->prepare("UPDATE integration_deliveries SET status='succeeded', delivered_at=NOW(), last_error='reconciled by operator' WHERE id=?")->execute([$parked['id']]);
            }
        } elseif ($mode === 'not_created') {
            integration_ticket_link_update($linkId, ['link_state' => 'creating', 'outcome_unknown' => 0]);
            if ($parked) {
                db()->prepare("UPDATE integration_deliveries SET status='pending', next_attempt_at=NOW(), last_error='operator confirmed no ticket was created' WHERE id=?")->execute([$parked['id']]);
            }
        } elseif ($mode === 'abandon') {
            integration_ticket_link_update($linkId, ['link_state' => 'abandoned', 'active_key' => null]);
            if ($parked) {
                db()->prepare("UPDATE integration_deliveries SET status='cancelled', last_error='mapping abandoned by operator' WHERE id=?")->execute([$parked['id']]);
            }
        } else {
            return ['ok' => false, 'error' => 'Unknown action'];
        }
        audit_log('integration.ticket_reconciled', [
            'object_type' => 'integration_ticket_link', 'object_id' => (string)$linkId,
            'customer_id' => $link['customer_id'], 'message' => "Ticket mapping reconciled ({$mode}) by {$actor}",
            'metadata' => ['mode' => $mode, 'incident_id' => $link['incident_id']],
        ]);
        return ['ok' => true, 'error' => ''];
    }
}
