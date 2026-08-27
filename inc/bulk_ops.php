<?php
/**
 * OPNMGR Bulk Operations
 *
 * Applies one operation across a selected set of firewalls.
 *
 * The catalogue is deliberately narrow. Raw shell is NOT here: "run this shell
 * command on every firewall" must not be a button anyone can reach by
 * accident. It remains a single-firewall, admin-only, explicitly confirmed
 * path in api/queue_command.php.
 *
 * High-risk operations require a typed confirmation phrase, not just a checkbox,
 * because the cost of a mis-click scales with the number of targets.
 *
 * @since 3.16.0
 */

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/agent_commands.php';
require_once __DIR__ . '/customers.php';

if (!function_exists('bulk_operation_catalog')) {
    /**
     * Operations that may be applied in bulk.
     *
     * 'action' names an entry in the structured command catalogue; operations
     * without one are handled locally on the server (tagging, assignment) and
     * touch no firewall.
     *
     * @return array<string, array>
     */
    function bulk_operation_catalog(): array {
        return [
            // --- safe, agent-side ------------------------------------------
            'check_updates' => [
                'label' => 'Check for updates', 'risk' => 'LOW',
                'action' => 'check_updates', 'capability' => 'update.check',
            ],
            'refresh_health' => [
                'label' => 'Refresh health', 'risk' => 'LOW',
                'action' => 'gateway_status', 'capability' => 'command.diagnostic',
            ],
            'agent_status' => [
                'label' => 'Agent status', 'risk' => 'LOW',
                'action' => 'agent_status', 'capability' => 'command.diagnostic',
            ],
            'backup_config' => [
                'label' => 'Back up configuration', 'risk' => 'LOW',
                'action' => null, 'capability' => 'backup.create', 'handler' => 'bulk_handle_backup',
            ],

            // --- server-side organisation, no firewall contact --------------
            'assign_customer' => [
                'label' => 'Assign to customer', 'risk' => 'LOW',
                'action' => null, 'capability' => 'firewall.manage', 'handler' => 'bulk_handle_assign_customer',
                'params' => ['customer_id'],
            ],
            'assign_site' => [
                'label' => 'Assign to site', 'risk' => 'LOW',
                'action' => null, 'capability' => 'firewall.manage', 'handler' => 'bulk_handle_assign_site',
                'params' => ['site_id'],
            ],
            'set_ring' => [
                'label' => 'Set update ring', 'risk' => 'LOW',
                'action' => null, 'capability' => 'update.schedule', 'handler' => 'bulk_handle_set_ring',
                'params' => ['ring'],
            ],
            'add_tag' => [
                'label' => 'Add tag', 'risk' => 'LOW',
                'action' => null, 'capability' => 'firewall.manage', 'handler' => 'bulk_handle_add_tag',
                'params' => ['tag'],
            ],
            'remove_tag' => [
                'label' => 'Remove tag', 'risk' => 'LOW',
                'action' => null, 'capability' => 'firewall.manage', 'handler' => 'bulk_handle_remove_tag',
                'params' => ['tag'],
            ],

            // --- disruptive -------------------------------------------------
            'agent_update' => [
                'label' => 'Update agent', 'risk' => 'MEDIUM',
                'action' => null, 'capability' => 'agent.update', 'handler' => 'bulk_handle_agent_update',
            ],
            'restart_service' => [
                'label' => 'Restart a service', 'risk' => 'MEDIUM',
                'action' => 'service_restart', 'capability' => 'command.operational',
                'params' => ['service'],
            ],
            'install_updates' => [
                'label' => 'Install OPNsense updates', 'risk' => 'HIGH',
                'action' => 'install_updates', 'capability' => 'update.install', 'confirm' => true,
            ],
            'reboot' => [
                'label' => 'Reboot', 'risk' => 'CRITICAL',
                'action' => 'reboot', 'capability' => 'command.privileged', 'confirm' => true,
            ],
        ];
    }
}

if (!function_exists('bulk_requires_confirmation')) {
    /**
     * Whether an operation needs a typed confirmation phrase.
     */
    function bulk_requires_confirmation(string $operation): bool {
        $entry = bulk_operation_catalog()[$operation] ?? null;
        return $entry !== null
            && (!empty($entry['confirm']) || in_array($entry['risk'], ['HIGH', 'CRITICAL'], true));
    }
}

if (!function_exists('bulk_confirmation_phrase')) {
    /**
     * The phrase an operator must type for a high-risk bulk operation.
     *
     * Includes the target count so that confirming a 3-firewall reboot does not
     * also confirm a 300-firewall one.
     */
    function bulk_confirmation_phrase(string $operation, int $count): string {
        return sprintf('%s %d', strtoupper(str_replace('_', ' ', $operation)), $count);
    }
}

if (!function_exists('bulk_execute')) {
    /**
     * Run an operation across a set of firewalls.
     *
     * @param string $operation Catalogue key
     * @param int[]  $firewalls Target ids
     * @param array  $params    Operation parameters
     * @param string $confirm   Typed confirmation, for high-risk operations
     * @return array{ok:bool, error:string, bulk_id:int, queued:int, skipped:int}
     */
    function bulk_execute(string $operation, array $firewalls, array $params = [], string $confirm = ''): array {
        $fail = fn(string $e) => ['ok' => false, 'error' => $e, 'bulk_id' => 0, 'queued' => 0, 'skipped' => 0];

        $catalog = bulk_operation_catalog();
        if (!isset($catalog[$operation])) {
            return $fail('Unknown bulk operation');
        }
        $entry = $catalog[$operation];

        if (!can($entry['capability'])) {
            audit_log('bulk.denied', [
                'success'  => false,
                'message'  => 'Role denied bulk operation ' . $operation,
                'metadata' => ['operation' => $operation, 'capability' => $entry['capability']],
            ]);
            return $fail('Your role does not permit this operation');
        }

        $firewalls = array_values(array_unique(array_filter(array_map('intval', $firewalls))));
        if (!$firewalls) {
            return $fail('Select at least one firewall');
        }

        $max = 200;
        try {
            $stmt = db()->prepare('SELECT `value` FROM settings WHERE `name` = ?');
            $stmt->execute(['bulk_max_targets']);
            $v = (int)$stmt->fetchColumn();
            if ($v > 0) { $max = $v; }
        } catch (Throwable $e) { /* default */ }

        if (count($firewalls) > $max) {
            return $fail(sprintf('Too many targets: %d selected, limit is %d', count($firewalls), $max));
        }

        // High-risk operations need the phrase, including the count, typed back.
        if (bulk_requires_confirmation($operation)) {
            $expected = bulk_confirmation_phrase($operation, count($firewalls));
            if (!hash_equals($expected, trim($confirm))) {
                return $fail(sprintf('Type "%s" to confirm this operation', $expected));
            }
        }

        // Validate parameters up front, once, rather than per firewall.
        $builtCommand = null;
        if (!empty($entry['action'])) {
            $built = build_structured_command($entry['action'], $params);
            if (!$built['ok']) {
                return $fail($built['error']);
            }
            $builtCommand = $built;
        }

        try {
            db()->prepare(
                'INSERT INTO bulk_operations
                    (operation, parameters, risk_level, target_count, created_by_user_id,
                     created_by_username, created_from_ip)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                $operation,
                $params ? json_encode(audit_scrub_metadata($params)) : null,
                $entry['risk'],
                count($firewalls),
                $_SESSION['user_id'] ?? null,
                $_SESSION['username'] ?? null,
                audit_client_ip(),
            ]);
            $bulkId = (int)db()->lastInsertId();
        } catch (Throwable $e) {
            error_log('OPNMGR: bulk_execute failed to record: ' . $e->getMessage());
            return $fail('Could not record the bulk operation');
        }

        audit_log('bulk.execute', [
            'object_type' => 'bulk_operation',
            'object_id'   => (string)$bulkId,
            'message'     => sprintf('Bulk %s across %d firewall(s)', $entry['label'], count($firewalls)),
            'metadata'    => ['operation' => $operation, 'risk' => $entry['risk'],
                              'targets' => $firewalls, 'parameters' => $params],
        ]);

        $queued = 0;
        $skipped = 0;

        foreach ($firewalls as $fid) {
            $targetStatus = 'queued';
            $detail = null;
            $commandId = null;

            try {
                if (!empty($entry['handler']) && function_exists($entry['handler'])) {
                    $r = ($entry['handler'])($fid, $params);
                    $targetStatus = $r['ok'] ? 'succeeded' : 'failed';
                    $detail = $r['detail'] ?? null;
                    $commandId = $r['command_id'] ?? null;
                } elseif ($builtCommand !== null) {
                    $q = queue_firewall_command(
                        $fid,
                        $builtCommand['command'],
                        sprintf('Bulk #%d: %s', $bulkId, $builtCommand['label']),
                        ['is_raw' => false, 'action' => $entry['action'], 'risk' => $builtCommand['risk']]
                    );
                    if ($q['ok']) {
                        $commandId = $q['command_id'];
                    } else {
                        $targetStatus = 'failed';
                        $detail = $q['error'];
                    }
                } else {
                    $targetStatus = 'skipped';
                    $detail = 'No handler for this operation';
                }
            } catch (Throwable $e) {
                $targetStatus = 'failed';
                $detail = 'Internal error';
                error_log('OPNMGR: bulk target failed: ' . $e->getMessage());
            }

            db()->prepare(
                'INSERT INTO bulk_operation_targets (bulk_id, firewall_id, status, command_id, detail)
                 VALUES (?,?,?,?,?)'
            )->execute([$bulkId, $fid, $targetStatus, $commandId, $detail !== null ? substr($detail, 0, 255) : null]);

            if ($targetStatus === 'failed' || $targetStatus === 'skipped') {
                $skipped++;
            } else {
                $queued++;
            }
        }

        db()->prepare(
            'UPDATE bulk_operations
                SET succeeded = ?, failed = ?, status = "completed", completed_at = NOW()
              WHERE id = ?'
        )->execute([$queued, $skipped, $bulkId]);

        return ['ok' => true, 'error' => '', 'bulk_id' => $bulkId, 'queued' => $queued, 'skipped' => $skipped];
    }
}

// ---------------------------------------------------------------------------
// Server-side handlers
// ---------------------------------------------------------------------------

if (!function_exists('bulk_handle_assign_customer')) {
    /** Assign a firewall to a customer. Touches no firewall. */
    function bulk_handle_assign_customer(int $firewallId, array $params): array {
        $r = save_firewall_assignment($firewallId, (int)($params['customer_id'] ?? 0) ?: null, null);
        return ['ok' => $r['ok'], 'detail' => $r['ok'] ? 'assigned' : $r['error']];
    }
}

if (!function_exists('bulk_handle_assign_site')) {
    /** Assign a firewall to a site, which implies its customer. */
    function bulk_handle_assign_site(int $firewallId, array $params): array {
        $r = save_firewall_assignment($firewallId, null, (int)($params['site_id'] ?? 0) ?: null);
        return ['ok' => $r['ok'], 'detail' => $r['ok'] ? 'assigned' : $r['error']];
    }
}

if (!function_exists('bulk_handle_set_ring')) {
    /** Move a firewall between rollout rings. */
    function bulk_handle_set_ring(int $firewallId, array $params): array {
        require_once __DIR__ . '/fleet_updates.php';
        $r = set_update_ring($firewallId, (string)($params['ring'] ?? ''));
        return ['ok' => $r['ok'], 'detail' => $r['ok'] ? 'ring set' : $r['error']];
    }
}

if (!function_exists('bulk_handle_add_tag')) {
    /** Attach a tag, creating it if needed. */
    function bulk_handle_add_tag(int $firewallId, array $params): array {
        $tag = trim((string)($params['tag'] ?? ''));
        if ($tag === '') {
            return ['ok' => false, 'detail' => 'No tag given'];
        }
        try {
            db()->prepare('INSERT IGNORE INTO tags (name) VALUES (?)')->execute([$tag]);
            $stmt = db()->prepare('SELECT id FROM tags WHERE name = ?');
            $stmt->execute([$tag]);
            $tagId = (int)$stmt->fetchColumn();
            if (!$tagId) {
                return ['ok' => false, 'detail' => 'Tag could not be created'];
            }
            db()->prepare('INSERT IGNORE INTO firewall_tags (firewall_id, tag_id) VALUES (?,?)')
                ->execute([$firewallId, $tagId]);
            return ['ok' => true, 'detail' => 'tagged'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'Could not add the tag'];
        }
    }
}

if (!function_exists('bulk_handle_remove_tag')) {
    /** Detach a tag. */
    function bulk_handle_remove_tag(int $firewallId, array $params): array {
        $tag = trim((string)($params['tag'] ?? ''));
        try {
            db()->prepare(
                'DELETE ft FROM firewall_tags ft JOIN tags t ON t.id = ft.tag_id
                  WHERE ft.firewall_id = ? AND t.name = ?'
            )->execute([$firewallId, $tag]);
            return ['ok' => true, 'detail' => 'untagged'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'Could not remove the tag'];
        }
    }
}

if (!function_exists('bulk_handle_backup')) {
    /** Queue a configuration backup. */
    function bulk_handle_backup(int $firewallId, array $params): array {
        require_once __DIR__ . '/backup_storage.php';
        try {
            $timestamp = date('Y-m-d_H-i-s') . '-' . substr((string)microtime(), 2, 6);
            $filename  = "bulk-backup-{$firewallId}-{$timestamp}.xml";

            db()->prepare(
                'INSERT INTO backups (firewall_id, backup_file, backup_type, created_at)
                 VALUES (?,?,"automated",NOW())'
            )->execute([$firewallId, $filename]);
            $backupId = (int)db()->lastInsertId();

            $q = queue_firewall_command(
                $firewallId,
                build_backup_upload_command($firewallId, $backupId, $filename),
                'Bulk configuration backup',
                ['is_raw' => false, 'action' => 'retrieve_config', 'risk' => 'MEDIUM']
            );
            return ['ok' => $q['ok'], 'detail' => $q['ok'] ? 'queued' : $q['error'],
                    'command_id' => $q['command_id'] ?? null];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'Could not queue the backup'];
        }
    }
}

if (!function_exists('bulk_handle_agent_update')) {
    /** Queue a verified agent update. */
    function bulk_handle_agent_update(int $firewallId, array $params): array {
        require_once __DIR__ . '/agent_update.php';

        $target = latest_agent_artifact();
        if (!$target['ok']) {
            return ['ok' => false, 'detail' => $target['error']];
        }
        $built = build_verified_agent_update_command($target['artifact'], $target['version']);
        if (!$built['ok']) {
            return ['ok' => false, 'detail' => $built['error']];
        }

        $q = queue_firewall_command(
            $firewallId, $built['command'],
            'Bulk verified agent update to v' . $target['version'],
            ['is_raw' => false, 'action' => 'agent_update', 'risk' => 'HIGH']
        );
        return ['ok' => $q['ok'], 'detail' => $q['ok'] ? 'queued' : $q['error'],
                'command_id' => $q['command_id'] ?? null];
    }
}

if (!function_exists('bulk_recent')) {
    /** Recent bulk operations for the history table. */
    function bulk_recent(int $limit = 50): array {
        $limit = max(1, min(200, $limit));
        try {
            return db()->query("
                SELECT b.*,
                       (SELECT GROUP_CONCAT(f.hostname SEPARATOR ', ')
                          FROM bulk_operation_targets t JOIN firewalls f ON f.id = t.firewall_id
                         WHERE t.bulk_id = b.id LIMIT 1) AS target_names
                  FROM bulk_operations b
                 ORDER BY b.created_at DESC
                 LIMIT {$limit}
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('OPNMGR: bulk_recent failed: ' . $e->getMessage());
            return [];
        }
    }
}
