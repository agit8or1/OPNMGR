<?php
/**
 * OPNMGR Maintenance Windows
 *
 * A maintenance window marks a period during which planned work is expected on
 * a firewall, a site, or a whole customer.
 *
 * During maintenance the system keeps doing everything except notifying:
 * agents keep checking in, health keeps being collected and displayed, and
 * incidents are still opened and recorded. Only the outbound notification is
 * withheld, and the incident carries the reason. Discarding the events would
 * mean losing the record of what happened during the work, which is exactly
 * when you most want it.
 *
 * Scope resolution is hierarchical: a firewall is in maintenance if it has its
 * own window, or its site does, or its customer does.
 *
 * @since 3.15.0
 */

require_once __DIR__ . '/audit.php';

if (!function_exists('maintenance_cache_generation')) {
    /**
     * Generation counter for the per-process maintenance cache.
     *
     * Bumped by maintenance_reset_cache(); comparing against it lets the cache
     * invalidate itself without every caller having to know it exists.
     */
    function maintenance_cache_generation(): int {
        static $generation = 0;
        if (func_num_args() > 0) {
            $generation++;
        }
        return $generation;
    }
}

if (!function_exists('maintenance_reset_cache')) {
    /**
     * Discard cached maintenance lookups.
     *
     * Call this after creating or cancelling a window, and at the start of each
     * pass in a long-running process.
     */
    function maintenance_reset_cache(): void {
        maintenance_cache_generation(true);
    }
}

if (!function_exists('maintenance_active_for')) {
    /**
     * The maintenance window currently covering a firewall, if any.
     *
     * Checks the firewall, then its site, then its customer.
     *
     * @return array|null The window row, or null when not in maintenance
     */
    function maintenance_active_for(int $firewallId): ?array {
        static $cache = [];

        // The cache exists so a page rendering many firewalls does not run this
        // query per row. It is per-process, which is wrong for anything
        // long-lived (the alert evaluator, tests), so those call
        // maintenance_reset_cache() rather than silently reading stale state.
        if (maintenance_cache_generation() !== ($cache['__gen'] ?? null)) {
            $cache = ['__gen' => maintenance_cache_generation()];
        }
        if (array_key_exists($firewallId, $cache)) {
            return $cache[$firewallId];
        }

        try {
            $stmt = db()->prepare(
                'SELECT m.*
                   FROM maintenance_windows m
                   JOIN firewalls f ON f.id = ?
                  WHERE m.status IN ("scheduled","active")
                    AND NOW() BETWEEN m.starts_at AND m.ends_at
                    AND (
                          (m.scope = "firewall" AND m.scope_id = f.id)
                       OR (m.scope = "site"     AND f.site_id     IS NOT NULL AND m.scope_id = f.site_id)
                       OR (m.scope = "customer" AND f.customer_id IS NOT NULL AND m.scope_id = f.customer_id)
                    )
                  ORDER BY FIELD(m.scope, "firewall", "site", "customer")
                  LIMIT 1'
            );
            $stmt->execute([$firewallId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            error_log('OPNMGR: maintenance_active_for failed: ' . $e->getMessage());
            $row = null;
        }

        $cache[$firewallId] = $row;
        return $row;
    }
}

if (!function_exists('maintenance_suppresses_alerts')) {
    /**
     * Whether notification should be withheld for this firewall right now.
     *
     * A window can be created with suppress_alerts = 0 to mark work in progress
     * without silencing anything.
     */
    function maintenance_suppresses_alerts(int $firewallId): bool {
        $window = maintenance_active_for($firewallId);
        return $window !== null && (int)$window['suppress_alerts'] === 1;
    }
}

if (!function_exists('maintenance_save')) {
    /**
     * Create or update a maintenance window.
     *
     * @param array    $data scope, scope_id, starts_at, ends_at, reason, suppress_alerts
     * @param int|null $id   Null to create
     * @return array{ok:bool, error:string, id:int}
     */
    function maintenance_save(array $data, ?int $id = null): array {
        $scope   = (string)($data['scope'] ?? '');
        $scopeId = (int)($data['scope_id'] ?? 0);

        if (!in_array($scope, ['firewall', 'site', 'customer'], true)) {
            return ['ok' => false, 'error' => 'Scope must be firewall, site or customer', 'id' => 0];
        }
        if ($scopeId <= 0) {
            return ['ok' => false, 'error' => 'Select what the window applies to', 'id' => 0];
        }

        // The target must exist, otherwise the window silently covers nothing.
        $table = match ($scope) {
            'firewall' => 'firewalls',
            'site'     => 'sites',
            'customer' => 'customers',
        };
        $check = db()->prepare("SELECT COUNT(*) FROM `{$table}` WHERE id = ?");
        $check->execute([$scopeId]);
        if ((int)$check->fetchColumn() === 0) {
            return ['ok' => false, 'error' => ucfirst($scope) . ' not found', 'id' => 0];
        }

        $start = strtotime((string)($data['starts_at'] ?? ''));
        $end   = strtotime((string)($data['ends_at'] ?? ''));

        if ($start === false || $end === false) {
            return ['ok' => false, 'error' => 'Start and end times are required', 'id' => 0];
        }
        if ($end <= $start) {
            return ['ok' => false, 'error' => 'The window must end after it starts', 'id' => 0];
        }
        if (($end - $start) > 86400 * 30) {
            return ['ok' => false, 'error' => 'A maintenance window cannot exceed 30 days', 'id' => 0];
        }

        $fields = [
            'scope'           => $scope,
            'scope_id'        => $scopeId,
            'starts_at'       => date('Y-m-d H:i:s', $start),
            'ends_at'         => date('Y-m-d H:i:s', $end),
            'reason'          => substr(trim((string)($data['reason'] ?? '')), 0, 255) ?: null,
            'suppress_alerts' => array_key_exists('suppress_alerts', $data)
                                 ? (!empty($data['suppress_alerts']) ? 1 : 0) : 1,
            'status'          => $start <= time() && $end > time() ? 'active' : 'scheduled',
        ];

        try {
            if ($id === null) {
                $fields['created_by_user_id']  = $_SESSION['user_id'] ?? null;
                $fields['created_by_username'] = $_SESSION['username'] ?? null;

                $cols = implode(',', array_keys($fields));
                $ph   = implode(',', array_fill(0, count($fields), '?'));
                db()->prepare("INSERT INTO maintenance_windows ({$cols}) VALUES ({$ph})")
                    ->execute(array_values($fields));
                $id = (int)db()->lastInsertId();
                $action = 'maintenance.create';
            } else {
                $set = implode(' = ?, ', array_keys($fields)) . ' = ?';
                $params = array_values($fields);
                $params[] = $id;
                db()->prepare("UPDATE maintenance_windows SET {$set} WHERE id = ?")->execute($params);
                $action = 'maintenance.update';
            }
        } catch (Throwable $e) {
            error_log('OPNMGR: maintenance_save failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save the maintenance window', 'id' => 0];
        }

        maintenance_reset_cache();

        audit_log($action, [
            'object_type' => 'maintenance_window',
            'object_id'   => (string)$id,
            'firewall_id' => $scope === 'firewall' ? $scopeId : null,
            'customer_id' => $scope === 'customer' ? $scopeId : null,
            'site_id'     => $scope === 'site' ? $scopeId : null,
            'message'     => sprintf('Maintenance window %s %s from %s to %s',
                $action === 'maintenance.create' ? 'created for' : 'updated for',
                $scope . ' #' . $scopeId, $fields['starts_at'], $fields['ends_at']),
            'metadata'    => ['reason' => $fields['reason'], 'suppress_alerts' => $fields['suppress_alerts']],
        ]);

        return ['ok' => true, 'error' => '', 'id' => $id];
    }
}

if (!function_exists('maintenance_cancel')) {
    /**
     * Cancel a window. Kept rather than deleted so the history survives.
     */
    function maintenance_cancel(int $id): array {
        $stmt = db()->prepare(
            'UPDATE maintenance_windows SET status = "cancelled"
              WHERE id = ? AND status IN ("scheduled","active")'
        );
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'error' => 'That window is not scheduled or active'];
        }

        maintenance_reset_cache();

        audit_log('maintenance.cancel', [
            'object_type' => 'maintenance_window',
            'object_id'   => (string)$id,
            'message'     => 'Maintenance window cancelled',
        ]);

        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('maintenance_refresh_statuses')) {
    /**
     * Move windows between scheduled, active and completed.
     *
     * Called from the alert evaluator so status is accurate without needing its
     * own cron entry.
     *
     * @return array{activated:int, completed:int}
     */
    function maintenance_refresh_statuses(): array {
        $activated = 0;
        $completed = 0;

        try {
            $stmt = db()->prepare(
                'UPDATE maintenance_windows SET status = "active"
                  WHERE status = "scheduled" AND starts_at <= NOW() AND ends_at > NOW()'
            );
            $stmt->execute();
            $activated = $stmt->rowCount();

            $stmt = db()->prepare(
                'UPDATE maintenance_windows SET status = "completed"
                  WHERE status IN ("scheduled","active") AND ends_at <= NOW()'
            );
            $stmt->execute();
            $completed = $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('OPNMGR: maintenance_refresh_statuses failed: ' . $e->getMessage());
        }

        return ['activated' => $activated, 'completed' => $completed];
    }
}

if (!function_exists('maintenance_list')) {
    /**
     * Windows with their target resolved to a name.
     *
     * @param bool $includeFinished Include completed and cancelled windows
     */
    function maintenance_list(bool $includeFinished = false, int $limit = 200): array {
        $where = $includeFinished ? '' : "WHERE m.status IN ('scheduled','active')";
        $limit = max(1, min(500, $limit));

        try {
            return db()->query("
                SELECT m.*,
                       CASE m.scope
                           WHEN 'firewall' THEN (SELECT hostname FROM firewalls WHERE id = m.scope_id)
                           WHEN 'site'     THEN (SELECT CONCAT(c.name, ' / ', s.name)
                                                   FROM sites s JOIN customers c ON c.id = s.customer_id
                                                  WHERE s.id = m.scope_id)
                           WHEN 'customer' THEN (SELECT name FROM customers WHERE id = m.scope_id)
                       END AS target_name
                  FROM maintenance_windows m
                  {$where}
                 ORDER BY FIELD(m.status,'active','scheduled','completed','cancelled'),
                          m.starts_at DESC
                 LIMIT {$limit}
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('OPNMGR: maintenance_list failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('maintenance_firewalls_in_window')) {
    /**
     * Firewall ids currently covered by any active window.
     *
     * Used by the dashboard to badge them, and by the evaluator to avoid
     * resolving the scope per firewall in a loop.
     *
     * @return int[]
     */
    function maintenance_firewalls_in_window(): array {
        try {
            return array_map('intval', db()->query('
                SELECT DISTINCT f.id
                  FROM firewalls f
                  JOIN maintenance_windows m
                    ON m.status IN ("scheduled","active")
                   AND NOW() BETWEEN m.starts_at AND m.ends_at
                   AND (
                         (m.scope = "firewall" AND m.scope_id = f.id)
                      OR (m.scope = "site"     AND f.site_id     IS NOT NULL AND m.scope_id = f.site_id)
                      OR (m.scope = "customer" AND f.customer_id IS NOT NULL AND m.scope_id = f.customer_id)
                   )
            ')->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            error_log('OPNMGR: maintenance_firewalls_in_window failed: ' . $e->getMessage());
            return [];
        }
    }
}
