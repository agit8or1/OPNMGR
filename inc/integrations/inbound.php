<?php
/**
 * Integrations: inbound synchronisation (webhook receiver and polling).
 *
 * Authentication of an inbound webhook, in order:
 *   1. the destination exists, is enabled and has an inbound secret
 *      (the secret is per destination and is the authorisation);
 *   2. X-OPNManager-Signature = v1=HMAC-SHA256(secret, timestamp "." raw body);
 *   3. X-OPNManager-Timestamp within the freshness window (default 300 s);
 *   4. a nonce (X-OPNManager-Nonce, or "event_id" in the body) never seen
 *      before for this destination (replay protection).
 * Every failure looks the same to the caller.
 *
 * Semantics: an external acknowledgement may acknowledge the local incident,
 * a comment may add an audited note, an assignment may set local ownership -
 * each only if enabled on the destination. A closed external ticket records
 * ticket workflow state only; it NEVER resolves the incident, because the
 * incident clears when the monitored condition clears. Events caused here
 * carry their origin so they are not sent back to the same destination.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/destinations.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/connector_webhook.php';

if (!function_exists('integration_inbound_verify')) {
    /**
     * @param array $hdr timestamp, signature, nonce (strings, may be empty)
     * @return array{ok:bool,code:int,error:string}
     */
    function integration_inbound_verify(?array $dest, string $raw, array $hdr, ?int $now = null): array {
        $fail = ['ok' => false, 'code' => 401, 'error' => 'unauthorized'];
        $now = $now ?? time();
        if (!$dest || !$dest['enabled'] || empty($dest['secrets']['inbound_secret'])) { return $fail + ['reason' => 'destination not eligible']; }
        $ts = (string)($hdr['timestamp'] ?? '');
        $sig = (string)($hdr['signature'] ?? '');
        if (!ctype_digit($ts) || $sig === '') { return $fail + ['reason' => 'missing timestamp or signature']; }
        $expected = integration_sign((string)$dest['secrets']['inbound_secret'], $ts, $raw);
        if (!hash_equals($expected, $sig)) { return $fail + ['reason' => 'bad signature']; }
        $window = max(30, (int)integration_setting('integrations_inbound_window_seconds', '300'));
        if (abs($now - (int)$ts) > $window) { return $fail + ['reason' => 'timestamp outside freshness window']; }
        $nonce = (string)($hdr['nonce'] ?? '');
        if ($nonce === '') {
            $j = json_decode($raw, true);
            $nonce = is_array($j) ? (string)($j['event_id'] ?? '') : '';
        }
        if ($nonce === '' || strlen($nonce) > 200) { return $fail + ['reason' => 'missing nonce']; }
        $ins = db()->prepare('INSERT IGNORE INTO integration_inbound_nonces (destination_id, nonce_hash, seen_at) VALUES (?,?,NOW())');
        $ins->execute([(int)$dest['id'], hash('sha256', $nonce)]);
        if ($ins->rowCount() === 0) { return $fail + ['reason' => 'replay: nonce already used']; }
        return ['ok' => true, 'code' => 200, 'error' => ''];
    }
}

if (!function_exists('integration_inbound_log')) {
    function integration_inbound_log(?int $destId, string $result, ?string $action, ?string $ext, ?int $incidentId, ?int $customerId, string $detail, ?string $ip): void {
        try {
            db()->prepare('INSERT INTO integration_inbound_log (destination_id, remote_ip, result, action, external_id, incident_id, customer_id, detail)
                           VALUES (?,?,?,?,?,?,?,?)')->execute([$destId, $ip, $result, $action, $ext !== null ? substr($ext, 0, 191) : null, $incidentId, $customerId,
                           substr(integration_redact_string($detail), 0, 300)]);
        } catch (Throwable $e) { error_log('OPNMGR integrations: inbound log failed: ' . $e->getMessage()); }
    }
}

if (!function_exists('integration_inbound_find_link')) {
    function integration_inbound_find_link(int $destId, string $externalId): ?array {
        $s = db()->prepare('SELECT * FROM integration_ticket_links WHERE destination_id = ? AND external_id = ?
                              ORDER BY (active_key IS NOT NULL) DESC, id DESC LIMIT 1');
        $s->execute([$destId, $externalId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('integration_inbound_apply')) {
    /**
     * Apply one normalised inbound action to the mapped incident.
     * @param string $action ack|comment|assign|closed|reopened|updated
     * @return array{ok:bool,result:string,detail:string}
     */
    function integration_inbound_apply(array $dest, array $link, string $action, array $data, ?string $ip = null): array {
        require_once __DIR__ . '/../alerting.php';
        $destId = (int)$dest['id'];
        $incId = (int)$link['incident_id'];
        $by = 'ext:' . $dest['name'];
        $s = db()->prepare('SELECT * FROM alert_incidents WHERE id = ?'); $s->execute([$incId]);
        $inc = $s->fetch(PDO::FETCH_ASSOC);
        if (!$inc) { return ['ok' => false, 'result' => 'unknown_incident', 'detail' => 'incident no longer exists']; }
        $who = integration_clean_text((string)($data['author'] ?? $data['actor'] ?? ''), 80);
        $out = ['ok' => true, 'result' => 'applied', 'detail' => ''];
        $touch = ['last_inbound_at' => integration_now()];

        switch ($action) {
            case 'ack':
                if (!$dest['inbound_ack_enabled']) { return ['ok' => true, 'result' => 'ignored', 'detail' => 'inbound acknowledgement is disabled']; }
                $touch['ticket_state'] = 'acknowledged';
                if ($inc['status'] === 'open') {
                    $prev = $_SESSION['username'] ?? null;
                    $_SESSION['username'] = $by;
                    try {
                        $r = integration_with_origin('inbound', $destId, fn() => alert_acknowledge($incId, 'Acknowledged from external ticket ' . $link['external_id'] . ($who ? " by {$who}" : '')));
                    } finally {
                        if ($prev === null) { unset($_SESSION['username']); } else { $_SESSION['username'] = $prev; }
                    }
                    $out['detail'] = $r['ok'] ? 'incident acknowledged' : $r['error'];
                } else {
                    $out['detail'] = 'incident is already ' . $inc['status'];
                }
                break;
            case 'comment':
                if (!$dest['inbound_comment_enabled']) { return ['ok' => true, 'result' => 'ignored', 'detail' => 'inbound comments are disabled']; }
                $text = integration_clean_text(integration_redact_string((string)($data['comment'] ?? '')), 400);
                if ($text === '') { return ['ok' => false, 'result' => 'bad_request', 'detail' => 'empty comment']; }
                alert_incident_event($incId, 'updated', 'External comment' . ($who ? " from {$who}" : '') . ': ' . $text, $by);
                audit_log('integration.inbound_comment', ['actor_type' => 'system', 'username' => $by, 'object_type' => 'incident', 'object_id' => (string)$incId,
                    'customer_id' => $inc['customer_id'], 'firewall_id' => $inc['firewall_id'], 'message' => 'External comment added to incident',
                    'metadata' => ['destination_id' => $destId, 'external_id' => $link['external_id']]]);
                $out['detail'] = 'note added';
                break;
            case 'assign':
                if (!$dest['inbound_assign_enabled']) { return ['ok' => true, 'result' => 'ignored', 'detail' => 'inbound assignment is disabled']; }
                $owner = integration_clean_text((string)($data['assignee'] ?? ''), 128);
                db()->prepare('UPDATE alert_incidents SET owner = ? WHERE id = ?')->execute([$owner !== '' ? $owner : null, $incId]);
                $touch['external_assignee'] = $owner !== '' ? $owner : null;
                alert_incident_event($incId, 'updated', 'Owner set from external ticket: ' . ($owner !== '' ? $owner : '(unassigned)'), $by);
                audit_log('integration.inbound_assign', ['actor_type' => 'system', 'username' => $by, 'object_type' => 'incident', 'object_id' => (string)$incId,
                    'customer_id' => $inc['customer_id'], 'message' => 'Incident owner updated from external ticket']);
                $out['detail'] = 'owner updated';
                break;
            case 'closed':
                // Workflow state only. The incident is untouched: it clears when the condition clears.
                $active = in_array($inc['status'], ['open', 'acknowledged'], true);
                $touch['ticket_state'] = 'closed';
                $touch['closed_while_incident_active'] = $active ? 1 : 0;
                alert_incident_event($incId, 'updated', 'External ticket ' . $link['external_id'] . ' closed' . ($active ? '; incident remains ' . $inc['status'] . ' (condition still present)' : ''), $by);
                $out['detail'] = $active ? 'ticket closed; incident left ' . $inc['status'] : 'ticket closed';
                break;
            case 'reopened':
                $touch['ticket_state'] = 'open';
                $touch['closed_while_incident_active'] = 0;
                $out['detail'] = 'ticket reopened';
                break;
            case 'updated':
                $out['detail'] = 'noted';
                break;
            default:
                return ['ok' => false, 'result' => 'bad_request', 'detail' => 'unknown action'];
        }
        integration_ticket_link_touch((int)$link['id'], $touch);
        integration_inbound_log($destId, $out['result'], $action, $link['external_id'], $incId, $inc['customer_id'] !== null ? (int)$inc['customer_id'] : null, $out['detail'], $ip);
        return $out;
    }
}

if (!function_exists('integration_ticket_link_touch')) {
    function integration_ticket_link_touch(int $linkId, array $set): void {
        require_once __DIR__ . '/tickets.php';
        integration_ticket_link_update($linkId, $set);
    }
}

if (!function_exists('integration_inbound_handle')) {
    /**
     * Full inbound request handling. @return array{code:int, body:array}
     */
    function integration_inbound_handle(int $destId, string $raw, array $hdr, ?string $ip = null, ?int $now = null): array {
        if (strlen($raw) > 65536) { return ['code' => 413, 'body' => ['ok' => false, 'error' => 'payload too large']]; }
        $dest = $destId > 0 ? integration_destination_load($destId) : null;
        $v = integration_inbound_verify($dest, $raw, $hdr, $now);
        if (!$v['ok']) {
            integration_inbound_log($dest ? $destId : null, 'rejected', null, null, null, null, $v['reason'] ?? 'unauthorized', $ip);
            return ['code' => 401, 'body' => ['ok' => false, 'error' => 'unauthorized']];
        }
        $j = json_decode($raw, true);
        $map = ['ticket.acknowledged' => 'ack', 'ticket.comment' => 'comment', 'ticket.assigned' => 'assign',
                'ticket.closed' => 'closed', 'ticket.reopened' => 'reopened', 'ticket.updated' => 'updated'];
        if (!is_array($j) || !isset($map[$j['type'] ?? '']) || !is_scalar($j['external_id'] ?? null) || (string)$j['external_id'] === '') {
            integration_inbound_log($destId, 'bad_request', null, null, null, null, 'invalid payload', $ip);
            return ['code' => 400, 'body' => ['ok' => false, 'error' => 'invalid payload']];
        }
        $link = integration_inbound_find_link($destId, (string)$j['external_id']);
        if (!$link) {
            integration_inbound_log($destId, 'unknown_ticket', $map[$j['type']], (string)$j['external_id'], null, null, 'no mapping', $ip);
            return ['code' => 404, 'body' => ['ok' => false, 'error' => 'unknown ticket']];
        }
        $r = integration_inbound_apply($dest, $link, $map[$j['type']], $j, $ip);
        return ['code' => $r['ok'] ? 200 : 422, 'body' => ['ok' => $r['ok'], 'result' => $r['result'], 'detail' => $r['detail']]];
    }
}

if (!function_exists('integration_poll_destination')) {
    /**
     * Outbound polling for LAN/VPN installs that cannot receive webhooks.
     * Needs poll_url (secret, with {external_id}) and poll_status_path in the config;
     * optional poll_closed_values / poll_ack_values (csv) and poll_assignee_path.
     * @return int changes applied
     */
    function integration_poll_destination(array $dest, int $limit = 50): int {
        if ($dest['type'] !== 'ticket_webhook' || empty($dest['secrets']['poll_url']) || !$dest['enabled']) { return 0; }
        require_once __DIR__ . '/tickets.php';
        $c = new OpnmgrConnectorTicketWebhook();
        $closed = array_map('strtolower', integration_csv($dest['config']['poll_closed_values'] ?? ''));
        $acked = array_map('strtolower', integration_csv($dest['config']['poll_ack_values'] ?? ''));
        $s = db()->prepare("SELECT * FROM integration_ticket_links WHERE destination_id = ? AND link_state = 'active' AND external_id IS NOT NULL
                             ORDER BY COALESCE(last_inbound_at, '2000-01-01') LIMIT " . (int)$limit);
        $s->execute([$dest['id']]);
        $changes = 0;
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $link) {
            $r = $c->call('poll', $dest, ['external_id' => $link['external_id'], 'incident_id' => $link['incident_id'], 'idempotency_key' => ''], null, '');
            if ($r['outcome'] === 'rate_limited') { break; }
            if ($r['outcome'] !== 'success' || !is_array($r['json'])) { continue; }
            $status = strtolower(trim((string)integration_json_path($r['json'], (string)($dest['config']['poll_status_path'] ?? ''))));
            $action = null;
            if ($status !== '' && in_array($status, $closed, true) && $link['ticket_state'] !== 'closed') { $action = 'closed'; }
            elseif ($status !== '' && in_array($status, $acked, true) && $link['ticket_state'] === 'open') { $action = 'ack'; }
            elseif ($status !== '' && !in_array($status, $closed, true) && $link['ticket_state'] === 'closed') { $action = 'reopened'; }
            if ($action) {
                $res = integration_inbound_apply($dest, $link, $action, ['author' => 'poll'], 'poll');
                $changes += $res['ok'] ? 1 : 0;
            }
            if (!empty($dest['config']['poll_assignee_path'])) {
                $as = integration_json_path($r['json'], (string)$dest['config']['poll_assignee_path']);
                if (is_string($as) && $as !== (string)$link['external_assignee']) {
                    integration_inbound_apply($dest, $link, 'assign', ['assignee' => $as], 'poll');
                }
            }
            integration_ticket_link_update((int)$link['id'], ['last_inbound_at' => integration_now()]);
        }
        return $changes;
    }
}

if (!function_exists('integration_prune')) {
    function integration_prune(): void {
        $q = [
            "DELETE FROM integration_inbound_nonces WHERE seen_at < DATE_SUB(NOW(), INTERVAL 2 DAY)",
            "DELETE FROM integration_inbound_log WHERE received_at < DATE_SUB(NOW(), INTERVAL 90 DAY)",
            "DELETE a FROM integration_delivery_attempts a JOIN integration_deliveries d ON d.id = a.delivery_id
              WHERE d.status IN ('succeeded','suppressed','cancelled','dead') AND d.created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)",
            "DELETE FROM integration_deliveries WHERE status IN ('succeeded','suppressed','cancelled','dead') AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)",
            "DELETE e FROM integration_events e WHERE e.status = 'routed' AND e.created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
               AND NOT EXISTS (SELECT 1 FROM integration_deliveries d WHERE d.event_row_id = e.id)",
        ];
        foreach ($q as $sql) { try { db()->exec($sql); } catch (Throwable $e) { error_log('OPNMGR integrations: prune: ' . $e->getMessage()); } }
    }
}
