<?php
/**
 * OPNMGR Configuration Restore
 *
 * Restoring overwrites a live firewall, so this path is deliberately more
 * careful than queueing an ordinary command:
 *
 *   1. The target and the backup are validated, including that the backup
 *      actually belongs to that firewall and still parses as an OPNsense config.
 *   2. A pre-restore backup is taken first, so there is always a way back.
 *   3. The agent fetches the configuration using its own credentials plus a
 *      single-use token, rather than the operator's session.
 *   4. Success is not inferred from the command exiting zero. The job stays in
 *      'verifying' until the agent has checked in AFTER the restore, which is
 *      the only evidence the firewall came back at all.
 *
 * @since 3.16.0
 */

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/backup_storage.php';
require_once __DIR__ . '/config_drift.php';
require_once __DIR__ . '/agent_commands.php';

if (!function_exists('restore_setting')) {
    /** Read a restore setting with a fallback. */
    function restore_setting(string $name, $default) {
        try {
            $stmt = db()->prepare('SELECT `value` FROM settings WHERE `name` = ?');
            $stmt->execute([$name]);
            $v = $stmt->fetchColumn();
            return ($v === false || $v === '') ? $default : $v;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('restore_validate')) {
    /**
     * Check that a restore is safe to attempt.
     *
     * @return array{ok:bool, error:string, backup:array, firewall:array}
     */
    function restore_validate(int $backupId): array {
        $fail = fn(string $e) => ['ok' => false, 'error' => $e, 'backup' => [], 'firewall' => []];

        $stmt = db()->prepare(
            'SELECT b.*, f.hostname, f.status AS fw_status, f.id AS fw_id, f.last_checkin
               FROM backups b JOIN firewalls f ON f.id = b.firewall_id
              WHERE b.id = ?'
        );
        $stmt->execute([$backupId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return $fail('Backup not found');
        }
        if ($row['fw_status'] !== 'online') {
            return $fail('The target firewall is not online');
        }

        $path = resolve_backup_path($row);
        if ($path === null) {
            return $fail('The backup file is missing on disk');
        }

        // If we recorded a checksum, the bytes must still match it.
        if (!empty($row['checksum_sha256'])) {
            $actual = hash_file('sha256', $path);
            if ($actual === false || !hash_equals((string)$row['checksum_sha256'], $actual)) {
                return $fail('The backup file no longer matches its recorded checksum');
            }
        }

        $xml = @file_get_contents($path);
        if ($xml === false || $xml === '') {
            return $fail('The backup file could not be read');
        }

        $validation = validate_backup_upload($path, (int)($row['file_size'] ?: strlen($xml)));
        if (!$validation['ok']) {
            return $fail('The backup is not a usable configuration: ' . $validation['reason']);
        }

        return ['ok' => true, 'error' => '', 'backup' => $row,
                'firewall' => ['id' => (int)$row['fw_id'], 'hostname' => $row['hostname'],
                               'last_checkin' => $row['last_checkin']]];
    }
}

if (!function_exists('restore_start')) {
    /**
     * Queue a validated restore, taking a pre-restore backup first.
     *
     * @param int    $backupId
     * @param string $confirm  Must equal the firewall's hostname
     * @param string $reason
     * @return array{ok:bool, error:string, restore_id:int}
     */
    function restore_start(int $backupId, string $confirm, string $reason = ''): array {
        $fail = fn(string $e) => ['ok' => false, 'error' => $e, 'restore_id' => 0];

        $check = restore_validate($backupId);
        if (!$check['ok']) {
            return $fail($check['error']);
        }

        $firewallId = $check['firewall']['id'];
        $hostname   = $check['firewall']['hostname'];

        // Typing the hostname is the confirmation. A generic "yes" checkbox does
        // not distinguish restoring the firewall you meant from the one above it
        // in the list.
        if (!hash_equals($hostname, trim($confirm))) {
            return $fail('Type the firewall hostname exactly to confirm the restore');
        }

        try {
            $token = bin2hex(random_bytes(32));

            db()->prepare(
                'INSERT INTO config_restores
                    (firewall_id, backup_id, status, fetch_token, requested_by_user_id,
                     requested_by_username, requested_from_ip, reason, checkin_before)
                 VALUES (?,?,"pre_backup",?,?,?,?,?,?)'
            )->execute([
                $firewallId, $backupId, $token,
                $_SESSION['user_id'] ?? null,
                $_SESSION['username'] ?? null,
                audit_client_ip(),
                substr($reason, 0, 255) ?: null,
                $check['firewall']['last_checkin'],
            ]);
            $restoreId = (int)db()->lastInsertId();

            // --- pre-restore backup ------------------------------------------
            $preBackupId = null;
            if ((string)restore_setting('restore_require_pre_backup', '1') === '1') {
                $filename = sprintf('pre-restore-%d-%s.xml', $firewallId, date('Y-m-d_H-i-s'));
                db()->prepare(
                    'INSERT INTO backups (firewall_id, backup_file, description, backup_type, created_at)
                     VALUES (?,?,?,"automated",NOW())'
                )->execute([$firewallId, $filename, 'Automatic pre-restore snapshot']);
                $preBackupId = (int)db()->lastInsertId();

                $pre = queue_firewall_command(
                    $firewallId,
                    build_backup_upload_command($firewallId, $preBackupId, $filename),
                    'Pre-restore configuration snapshot',
                    ['is_raw' => false, 'action' => 'retrieve_config', 'risk' => 'MEDIUM']
                );

                if (!$pre['ok']) {
                    db()->prepare('UPDATE config_restores SET status = "failed", detail = ? WHERE id = ?')
                        ->execute(['Could not queue the pre-restore backup', $restoreId]);
                    return $fail('Could not queue the pre-restore backup; restore aborted');
                }

                db()->prepare('UPDATE config_restores SET pre_restore_backup_id = ? WHERE id = ?')
                    ->execute([$preBackupId, $restoreId]);
            }

            // --- restore command ---------------------------------------------
            $queued = queue_firewall_command(
                $firewallId,
                build_restore_command($restoreId, $firewallId, $token),
                sprintf('Restore configuration from backup #%d', $backupId),
                ['is_raw' => false, 'action' => 'restore_config', 'risk' => 'CRITICAL',
                 'parameters' => ['backup_id' => $backupId, 'restore_id' => $restoreId]]
            );

            if (!$queued['ok']) {
                db()->prepare('UPDATE config_restores SET status = "failed", detail = ? WHERE id = ?')
                    ->execute([$queued['error'], $restoreId]);
                return $fail($queued['error']);
            }

            db()->prepare(
                'UPDATE config_restores
                    SET status = "dispatched", command_id = ?, dispatched_at = NOW()
                  WHERE id = ?'
            )->execute([$queued['command_id'], $restoreId]);

            audit_log('backup.restore', [
                'object_type' => 'firewall',
                'object_id'   => (string)$firewallId,
                'firewall_id' => $firewallId,
                'message'     => sprintf('Configuration restore dispatched to %s from backup #%d', $hostname, $backupId),
                'metadata'    => ['restore_id' => $restoreId, 'backup_id' => $backupId,
                                  'pre_restore_backup_id' => $preBackupId, 'reason' => $reason],
            ]);

            return ['ok' => true, 'error' => '', 'restore_id' => $restoreId];
        } catch (Throwable $e) {
            error_log('OPNMGR: restore_start failed: ' . $e->getMessage());
            return $fail('Could not start the restore');
        }
    }
}

if (!function_exists('build_restore_command')) {
    /**
     * Shell the firewall runs to fetch and apply a configuration.
     *
     * The agent authenticates with its own credentials read from disk, plus a
     * single-use token. The previous implementation built a URL from the
     * client-supplied Host header with no scheme, and pointed it at an endpoint
     * that requires an operator's browser session - so it could not have worked
     * even if the URL had been well-formed.
     */
    function build_restore_command(int $restoreId, int $firewallId, string $token): string {
        $url = opnmgr_server_url() . '/api/download_restore_config.php';

        return <<<SH
#!/bin/sh
# OPNManager configuration restore
set -e

HW=\$(cat /usr/local/etc/opnmanager_hardware_id 2>/dev/null)
KEY=\$(cat /usr/local/etc/opnmanager_api_key 2>/dev/null)
TMP=/tmp/opnmgr-restore-{$restoreId}.xml

echo "Fetching configuration..."
curl -sS -f -o "\$TMP" \\
  --data-urlencode "firewall_id={$firewallId}" \\
  --data-urlencode "hardware_id=\$HW" \\
  --data-urlencode "api_key=\$KEY" \\
  --data-urlencode "restore_id={$restoreId}" \\
  --data-urlencode "token={$token}" \\
  {$url} || { echo "ERROR: could not fetch configuration"; exit 1; }

# Refuse to apply anything that is not a well-formed OPNsense config.
if ! grep -q "<opnsense>" "\$TMP"; then
    echo "ERROR: fetched file is not an OPNsense configuration"
    rm -f "\$TMP"
    exit 1
fi

echo "Applying configuration..."
/usr/local/sbin/configctl firmware restore "\$TMP"
RC=\$?
rm -f "\$TMP"

if [ \$RC -ne 0 ]; then
    echo "ERROR: restore failed with code \$RC"
    exit \$RC
fi

echo "Restore applied. The firewall will reload; the server verifies success on the next check-in."
exit 0
SH;
    }
}

if (!function_exists('restore_reconcile')) {
    /**
     * Advance restore jobs based on evidence, not optimism.
     *
     * A dispatched job whose command completed moves to 'verifying', not
     * 'succeeded'. It only succeeds once the agent has checked in again since
     * the restore was dispatched, which is the only proof the firewall came
     * back. If it has not returned within the verification window, the job is
     * failed so somebody looks at it.
     *
     * @return array{verified:int, failed:int}
     */
    function restore_reconcile(): array {
        $verified = 0;
        $failed   = 0;
        $window   = max(5, (int)restore_setting('restore_verify_minutes', 20));

        try {
            // dispatched -> verifying / failed, based on the command outcome
            $stmt = db()->query(
                'SELECT r.id, r.firewall_id, c.status AS cmd_status, c.result
                   FROM config_restores r
                   JOIN firewall_commands c ON c.id = r.command_id
                  WHERE r.status = "dispatched" AND c.status IN ("completed","failed","cancelled")'
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['cmd_status'] === 'completed') {
                    db()->prepare('UPDATE config_restores SET status = "verifying" WHERE id = ?')
                        ->execute([$row['id']]);
                } else {
                    db()->prepare(
                        'UPDATE config_restores SET status = "failed", completed_at = NOW(), detail = ? WHERE id = ?'
                    )->execute([substr((string)$row['result'], 0, 2000), $row['id']]);
                    $failed++;
                }
            }

            // verifying -> succeeded once the agent has checked in since dispatch
            $stmt = db()->prepare(
                'SELECT r.id, r.firewall_id, r.dispatched_at, f.last_checkin, f.status AS fw_status,
                        TIMESTAMPDIFF(MINUTE, r.dispatched_at, NOW()) AS age
                   FROM config_restores r JOIN firewalls f ON f.id = r.firewall_id
                  WHERE r.status = "verifying"'
            );
            $stmt->execute();

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $returned = $row['last_checkin'] !== null
                    && strtotime($row['last_checkin']) > strtotime($row['dispatched_at'])
                    && $row['fw_status'] === 'online';

                if ($returned) {
                    db()->prepare(
                        'UPDATE config_restores SET status = "succeeded", completed_at = NOW(),
                                detail = "Agent checked in after the restore" WHERE id = ?'
                    )->execute([$row['id']]);
                    $verified++;

                    audit_log('backup.restore.verified', [
                        'actor_type'  => 'system',
                        'object_type' => 'firewall',
                        'object_id'   => (string)$row['firewall_id'],
                        'firewall_id' => (int)$row['firewall_id'],
                        'message'     => 'Restore verified: the agent checked in after the restore',
                    ]);
                } elseif ((int)$row['age'] > $window) {
                    db()->prepare(
                        'UPDATE config_restores SET status = "failed", completed_at = NOW(), detail = ? WHERE id = ?'
                    )->execute([
                        sprintf('The agent did not check in within %d minutes of the restore', $window),
                        $row['id'],
                    ]);
                    $failed++;

                    audit_log('backup.restore.unverified', [
                        'actor_type'  => 'system',
                        'success'     => false,
                        'object_type' => 'firewall',
                        'object_id'   => (string)$row['firewall_id'],
                        'firewall_id' => (int)$row['firewall_id'],
                        'message'     => 'Restore could not be verified: the agent has not returned',
                    ]);
                }
            }
        } catch (Throwable $e) {
            error_log('OPNMGR: restore_reconcile failed: ' . $e->getMessage());
        }

        return ['verified' => $verified, 'failed' => $failed];
    }
}
