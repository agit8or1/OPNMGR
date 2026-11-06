<?php
/**
 * Integrations: destination storage. Non-secret settings live in config_json;
 * every secret (URLs often embed tokens) lives in secrets_enc, encrypted with
 * opnmgr_encrypt and never returned to a browser unmasked.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/connector_base.php';

if (!function_exists('integration_destination_load')) {
    /** @return array|null destination row plus decoded 'config' and 'secrets' */
    function integration_destination_load(int $id): ?array {
        $s = db()->prepare('SELECT * FROM integration_destinations WHERE id = ?');
        $s->execute([$id]);
        $d = $s->fetch(PDO::FETCH_ASSOC);
        if (!$d) { return null; }
        $d['config'] = json_decode((string)$d['config_json'], true) ?: [];
        $plain = $d['secrets_enc'] ? opnmgr_decrypt($d['secrets_enc']) : '';
        $d['secrets'] = json_decode((string)$plain, true) ?: [];
        foreach (['id', 'allow_internal', 'concurrency_limit', 'max_attempts', 'timeout_seconds', 'enabled', 'reopen_window_minutes'] as $k) {
            $d[$k] = (int)$d[$k];
        }
        return $d;
    }
}

if (!function_exists('integration_destination_list')) {
    /** Destinations visible to the current user (scoped staff see only their customers' destinations). */
    function integration_destination_list(): array {
        [$w, $p] = integration_scope_sql('customer_id');
        $s = db()->prepare("SELECT id, name, type, enabled, customer_id, allow_internal, last_ip, ip_change_pending,
                                   paused_until, concurrency_limit, inbound_ack_enabled
                              FROM integration_destinations WHERE {$w} ORDER BY name");
        $s->execute($p);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('integration_destination_save')) {
    /**
     * Create or update a destination. Blank secret fields keep the stored value.
     * @return array{ok:bool,error:string,id:int}
     */
    function integration_destination_save(array $in, ?int $id, string $actor): array {
        $name = trim((string)($in['name'] ?? ''));
        $type = (string)($in['type'] ?? '');
        if ($name === '' || strlen($name) > 100) { return ['ok' => false, 'error' => 'Name is required (max 100 characters)', 'id' => 0]; }
        $connector = integration_connector_for($type);
        if (!$connector) { return ['ok' => false, 'error' => 'Unknown destination type', 'id' => 0]; }

        $existing = $id ? integration_destination_load($id) : null;
        if ($id && !$existing) { return ['ok' => false, 'error' => 'Destination not found', 'id' => 0]; }
        if ($existing && $existing['type'] !== $type) { return ['ok' => false, 'error' => 'The type of a destination cannot change', 'id' => 0]; }

        $config = is_array($in['config'] ?? null) ? $in['config'] : [];
        $secrets = $existing['secrets'] ?? [];
        foreach ((array)($in['secrets'] ?? []) as $k => $v) {
            if (is_string($v) && trim($v) !== '') { $secrets[$k] = trim($v); }
            elseif (is_string($v) && !empty($in['clear_secrets'][$k])) { unset($secrets[$k]); }
        }
        $allowInternal = !empty($in['allow_internal']);
        $errors = $connector::validate($config, $secrets, $allowInternal);
        if ($errors) { return ['ok' => false, 'error' => implode('; ', $errors), 'id' => 0]; }

        $enum = fn($v, array $ok, $def) => in_array($v, $ok, true) ? $v : $def;
        $cust = ($in['customer_id'] ?? '') === '' ? null : (int)$in['customer_id'];
        $row = [
            $name, $type, empty($in['disabled']) ? 1 : 0, $cust,
            json_encode($config, JSON_UNESCAPED_SLASHES), opnmgr_encrypt(json_encode($secrets, JSON_UNESCAPED_SLASHES)),
            $allowInternal ? 1 : 0, $enum($in['ip_change_policy'] ?? 'warn', ['warn', 'block'], 'warn'),
            max(1, min(10, (int)($in['concurrency_limit'] ?? 2))), max(1, min(20, (int)($in['max_attempts'] ?? 6))),
            max(2, min(60, (int)($in['timeout_seconds'] ?? 10))),
            $enum($in['on_resolve'] ?? 'resolve', ['resolve', 'comment', 'none'], 'resolve'),
            $enum($in['on_recurrence'] ?? 'new_ticket', ['reopen', 'new_ticket'], 'new_ticket'),
            max(1, min(525600, (int)($in['reopen_window_minutes'] ?? 1440))),
            $enum($in['on_external_close'] ?? 'note', ['note', 'reopen_ticket'], 'note'),
            !empty($in['inbound_ack_enabled']) ? 1 : 0, !empty($in['inbound_comment_enabled']) ? 1 : 0,
            !empty($in['inbound_assign_enabled']) ? 1 : 0,
        ];
        try {
            if ($existing) {
                db()->prepare('UPDATE integration_destinations SET name=?, type=?, enabled=?, customer_id=?, config_json=?, secrets_enc=?,
                    allow_internal=?, ip_change_policy=?, concurrency_limit=?, max_attempts=?, timeout_seconds=?, on_resolve=?,
                    on_recurrence=?, reopen_window_minutes=?, on_external_close=?, inbound_ack_enabled=?, inbound_comment_enabled=?,
                    inbound_assign_enabled=? WHERE id=?')->execute(array_merge($row, [$id]));
            } else {
                db()->prepare('INSERT INTO integration_destinations (name, type, enabled, customer_id, config_json, secrets_enc,
                    allow_internal, ip_change_policy, concurrency_limit, max_attempts, timeout_seconds, on_resolve,
                    on_recurrence, reopen_window_minutes, on_external_close, inbound_ack_enabled, inbound_comment_enabled,
                    inbound_assign_enabled, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($row, [$actor]));
                $id = (int)db()->lastInsertId();
            }
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => $e->getCode() === '23000' ? 'A destination with that name already exists' : 'Database error', 'id' => 0];
        }
        audit_log($existing ? 'integration.destination_updated' : 'integration.destination_created', [
            'object_type' => 'integration_destination', 'object_id' => (string)$id, 'customer_id' => $cust,
            'message' => "Destination '{$name}' ({$type}) saved",
            // Secrets deliberately absent: only which secret fields were set.
            'metadata' => ['type' => $type, 'secret_fields_set' => array_keys($secrets), 'allow_internal' => $allowInternal],
        ]);
        return ['ok' => true, 'error' => '', 'id' => $id];
    }
}

if (!function_exists('integration_destination_masked')) {
    /** Secrets as shown in the UI: last 4 characters only. */
    function integration_destination_masked(array $dest): array {
        $o = [];
        foreach ($dest['secrets'] as $k => $v) { $o[$k] = opnmgr_mask_secret((string)$v); }
        return $o;
    }
}

if (!function_exists('integration_destination_delete')) {
    function integration_destination_delete(int $id, string $actor): bool {
        $d = integration_destination_load($id);
        if (!$d) { return false; }
        db()->prepare('DELETE FROM integration_routes WHERE destination_id = ?')->execute([$id]);
        db()->prepare("UPDATE integration_deliveries SET status='cancelled', last_error='destination deleted' WHERE destination_id=? AND status IN ('pending','retry','leased','needs_reconciliation')")->execute([$id]);
        db()->prepare('DELETE FROM integration_destinations WHERE id = ?')->execute([$id]);
        audit_log('integration.destination_deleted', ['object_type' => 'integration_destination', 'object_id' => (string)$id,
            'message' => "Destination '{$d['name']}' deleted by {$actor}"]);
        return true;
    }
}

if (!function_exists('integration_destination_accept_ip')) {
    /** Operator accepts the new resolved address of an endpoint. */
    function integration_destination_accept_ip(int $id, string $actor): void {
        db()->prepare('UPDATE integration_destinations SET ip_change_pending = 0 WHERE id = ?')->execute([$id]);
        audit_log('integration.endpoint_ip_accepted', ['object_type' => 'integration_destination', 'object_id' => (string)$id,
            'message' => "Endpoint address change accepted by {$actor}"]);
    }
}
