<?php
/**
 * OPNMGR Fleet Update Management
 *
 * Rolls an OPNsense update across the managed fleet in rings, with HA safety.
 *
 * Rings (canary -> pilot -> production) are a rollout mechanism, not customer
 * tiers. They let an MSP prove a release on a handful of its own managed
 * firewalls before touching the rest. Progression between rings is manual
 * unless auto_progress is explicitly enabled, because "canary looks fine" is a
 * judgement a technician makes.
 *
 * HA safety is the part that matters most. If two firewalls are a CARP pair,
 * dispatching an update to both at once takes the customer offline. The
 * dispatcher therefore:
 *
 *   1. never dispatches to a firewall whose HA partner is mid-update
 *   2. prefers the BACKUP member first, so the MASTER keeps serving
 *   3. requires the first member to come back healthy, and CARP to have
 *      settled, before the second is eligible
 *   4. stops and holds rather than guessing when health cannot be confirmed
 *
 * @since 3.16.0
 */

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/agent_commands.php';
require_once __DIR__ . '/maintenance.php';

if (!defined('UPDATE_RINGS')) {
    define('UPDATE_RINGS', ['canary', 'pilot', 'production']);
}

if (!function_exists('fleet_update_setting')) {
    /** Read a setting with a numeric fallback. */
    function fleet_update_setting(string $name, $default) {
        static $cache = null;
        if ($cache === null) {
            try {
                $cache = db()->query('SELECT `name`,`value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            } catch (Throwable $e) {
                $cache = [];
            }
        }
        $v = $cache[$name] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }
}

if (!function_exists('fleet_update_overview')) {
    /**
     * The fleet update table: what each firewall is on and what is available.
     *
     * @param array $filters ring, customer_id, status, updates_only
     */
    function fleet_update_overview(array $filters = []): array {
        $where  = [];
        $params = [];

        if (!empty($filters['ring']) && in_array($filters['ring'], UPDATE_RINGS, true)) {
            $where[]  = 'f.update_ring = ?';
            $params[] = $filters['ring'];
        }
        if (!empty($filters['customer_id'])) {
            $where[]  = 'f.customer_id = ?';
            $params[] = (int)$filters['customer_id'];
        }
        if (!empty($filters['updates_only'])) {
            $where[] = 'f.updates_available = 1';
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        try {
            $stmt = db()->prepare("
                SELECT f.id, f.hostname, f.status, f.update_ring,
                       f.version, f.current_version, f.available_version,
                       f.agent_version, f.updates_available, f.reboot_required,
                       f.last_update_check, f.last_update_attempt_at,
                       f.last_update_result, f.last_update_error,
                       f.carp_enabled, f.carp_state, f.ha_peer_firewall_id,
                       c.name AS customer_name, s.name AS site_name,
                       peer.hostname AS ha_peer_hostname, peer.carp_state AS ha_peer_state
                  FROM firewalls f
                  LEFT JOIN customers c ON c.id = f.customer_id
                  LEFT JOIN sites s ON s.id = f.site_id
                  LEFT JOIN firewalls peer ON peer.id = f.ha_peer_firewall_id
                  {$whereSql}
                 ORDER BY FIELD(f.update_ring,'canary','pilot','production'), f.hostname
            ");
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('OPNMGR: fleet_update_overview failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('set_update_ring')) {
    /**
     * Move a firewall between rollout rings.
     */
    function set_update_ring(int $firewallId, string $ring): array {
        if (!in_array($ring, UPDATE_RINGS, true)) {
            return ['ok' => false, 'error' => 'Unknown ring'];
        }

        $stmt = db()->prepare('UPDATE firewalls SET update_ring = ? WHERE id = ?');
        $stmt->execute([$ring, $firewallId]);

        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'error' => 'Firewall not found'];
        }

        audit_log('update.ring.set', [
            'object_type' => 'firewall',
            'object_id'   => (string)$firewallId,
            'firewall_id' => $firewallId,
            'message'     => 'Update ring set to ' . $ring,
        ]);

        return ['ok' => true, 'error' => ''];
    }
}

// ---------------------------------------------------------------------------
// HA safety
// ---------------------------------------------------------------------------

if (!function_exists('ha_dispatch_check')) {
    /**
     * Whether it is safe to dispatch a disruptive operation to this firewall.
     *
     * Returns a hold reason instead of a bare false, so the UI can explain why
     * something is waiting rather than appearing stuck.
     *
     * @param int   $firewallId
     * @param array $inFlight   Firewall ids already dispatched in this pass
     * @return array{safe:bool, reason:string}
     */
    function ha_dispatch_check(int $firewallId, array $inFlight = []): array {
        try {
            $stmt = db()->prepare(
                'SELECT f.id, f.hostname, f.carp_enabled, f.carp_state, f.ha_peer_firewall_id,
                        p.hostname AS peer_hostname, p.carp_state AS peer_state,
                        p.status AS peer_status,
                        TIMESTAMPDIFF(MINUTE, p.last_update_attempt_at, NOW()) AS peer_update_age,
                        p.last_update_result AS peer_result,
                        TIMESTAMPDIFF(SECOND, p.last_checkin, NOW()) AS peer_silent
                   FROM firewalls f
                   LEFT JOIN firewalls p ON p.id = f.ha_peer_firewall_id
                  WHERE f.id = ?'
            );
            $stmt->execute([$firewallId]);
            $fw = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('OPNMGR: ha_dispatch_check failed: ' . $e->getMessage());
            return ['safe' => false, 'reason' => 'Could not evaluate HA safety'];
        }

        if (!$fw) {
            return ['safe' => false, 'reason' => 'Firewall not found'];
        }

        // Not part of a pair: nothing to coordinate.
        if ((int)$fw['carp_enabled'] !== 1 || $fw['ha_peer_firewall_id'] === null) {
            return ['safe' => true, 'reason' => ''];
        }

        $peerId = (int)$fw['ha_peer_firewall_id'];

        // 1. Never both at once.
        if (in_array($peerId, $inFlight, true)) {
            return ['safe' => false,
                    'reason' => sprintf('HA partner %s is being updated in this run', $fw['peer_hostname'] ?? $peerId)];
        }

        // 2. If the partner was updated recently, it must have come back.
        $settle = (int)fleet_update_setting('update_ha_settle_minutes', 10);
        $peerAge = $fw['peer_update_age'];

        if ($peerAge !== null && (int)$peerAge < $settle) {
            if ($fw['peer_result'] === 'failed') {
                return ['safe' => false,
                        'reason' => sprintf('HA partner %s failed its update; resolve that first',
                                            $fw['peer_hostname'] ?? $peerId)];
            }
            if ($fw['peer_status'] !== 'online' || (int)($fw['peer_silent'] ?? 99999) > 600) {
                return ['safe' => false,
                        'reason' => sprintf('HA partner %s has not come back online yet',
                                            $fw['peer_hostname'] ?? $peerId)];
            }
            if ($fw['peer_state'] === 'INIT' || $fw['peer_state'] === null) {
                return ['safe' => false,
                        'reason' => sprintf('CARP on %s has not settled since its update',
                                            $fw['peer_hostname'] ?? $peerId)];
            }
            return ['safe' => false,
                    'reason' => sprintf('Waiting %d minutes for %s to settle after its update',
                                        $settle, $fw['peer_hostname'] ?? $peerId)];
        }

        // 3. Prefer the BACKUP member first, so the MASTER keeps serving.
        if ($fw['carp_state'] === 'MASTER' && $fw['peer_state'] === 'BACKUP') {
            // Only hold if the backup still needs the update; otherwise the
            // master is legitimately next.
            $peerPending = db()->prepare(
                'SELECT updates_available FROM firewalls WHERE id = ?'
            );
            $peerPending->execute([$peerId]);
            if ((int)$peerPending->fetchColumn() === 1) {
                return ['safe' => false,
                        'reason' => sprintf('Update the BACKUP member (%s) before the MASTER',
                                            $fw['peer_hostname'] ?? $peerId)];
            }
        }

        return ['safe' => true, 'reason' => ''];
    }
}

// ---------------------------------------------------------------------------
// Campaigns
// ---------------------------------------------------------------------------

if (!function_exists('campaign_create')) {
    /**
     * Create an update campaign over a set of firewalls.
     *
     * @param array $data      name, operation, auto_progress, reboot_if_required, ha_safe
     * @param int[] $firewalls Target firewall ids
     * @return array{ok:bool, error:string, id:int}
     */
    function campaign_create(array $data, array $firewalls): array {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'error' => 'Give the campaign a name', 'id' => 0];
        }

        $firewalls = array_values(array_unique(array_map('intval', $firewalls)));
        if (!$firewalls) {
            return ['ok' => false, 'error' => 'Select at least one firewall', 'id' => 0];
        }

        $max = (int)fleet_update_setting('bulk_max_targets', 200);
        if (count($firewalls) > $max) {
            return ['ok' => false, 'error' => "Too many targets (limit {$max})", 'id' => 0];
        }

        $operation = in_array($data['operation'] ?? '', ['check', 'install'], true)
            ? $data['operation'] : 'install';

        try {
            db()->prepare(
                'INSERT INTO update_campaigns
                    (name, description, target_version, operation, status, auto_progress,
                     reboot_if_required, respect_maintenance, ha_safe,
                     created_by_user_id, created_by_username)
                 VALUES (?,?,?,?,"draft",?,?,?,?,?,?)'
            )->execute([
                substr($name, 0, 255),
                substr(trim((string)($data['description'] ?? '')), 0, 512) ?: null,
                substr(trim((string)($data['target_version'] ?? '')), 0, 64) ?: null,
                $operation,
                !empty($data['auto_progress']) ? 1 : 0,
                !empty($data['reboot_if_required']) ? 1 : 0,
                array_key_exists('respect_maintenance', $data) ? (!empty($data['respect_maintenance']) ? 1 : 0) : 1,
                array_key_exists('ha_safe', $data) ? (!empty($data['ha_safe']) ? 1 : 0) : 1,
                $_SESSION['user_id'] ?? null,
                $_SESSION['username'] ?? null,
            ]);
            $campaignId = (int)db()->lastInsertId();

            $ins = db()->prepare(
                'INSERT IGNORE INTO update_campaign_targets
                    (campaign_id, firewall_id, ring, version_before)
                 SELECT ?, id, update_ring, LEFT(IFNULL(current_version, version), 64) FROM firewalls WHERE id = ?'
            );
            foreach ($firewalls as $fid) {
                $ins->execute([$campaignId, $fid]);
            }
        } catch (Throwable $e) {
            error_log('OPNMGR: campaign_create failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not create the campaign', 'id' => 0];
        }

        audit_log('update.campaign.create', [
            'object_type' => 'update_campaign',
            'object_id'   => (string)$campaignId,
            'message'     => sprintf('Update campaign "%s" created over %d firewall(s)', $name, count($firewalls)),
            'metadata'    => ['operation' => $operation, 'targets' => count($firewalls),
                              'auto_progress' => !empty($data['auto_progress'])],
        ]);

        return ['ok' => true, 'error' => '', 'id' => $campaignId];
    }
}

if (!function_exists('campaign_start_ring')) {
    /**
     * Begin, or advance to, a ring.
     *
     * Explicit: nothing advances a campaign except this being called, either by
     * an operator or by the dispatcher when auto_progress is on and the
     * previous ring has soaked cleanly.
     */
    function campaign_start_ring(int $campaignId, string $ring): array {
        if (!in_array($ring, UPDATE_RINGS, true)) {
            return ['ok' => false, 'error' => 'Unknown ring'];
        }

        $stmt = db()->prepare('SELECT * FROM update_campaigns WHERE id = ?');
        $stmt->execute([$campaignId]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$campaign) {
            return ['ok' => false, 'error' => 'Campaign not found'];
        }
        if (in_array($campaign['status'], ['completed', 'cancelled'], true)) {
            return ['ok' => false, 'error' => 'That campaign has finished'];
        }

        $count = db()->prepare(
            'SELECT COUNT(*) FROM update_campaign_targets WHERE campaign_id = ? AND ring = ?'
        );
        $count->execute([$campaignId, $ring]);
        if ((int)$count->fetchColumn() === 0) {
            return ['ok' => false, 'error' => 'No firewalls in the ' . $ring . ' ring for this campaign'];
        }

        db()->prepare(
            'UPDATE update_campaigns
                SET status = "running", current_ring = ?,
                    started_at = COALESCE(started_at, NOW())
              WHERE id = ?'
        )->execute([$ring, $campaignId]);

        audit_log('update.campaign.ring', [
            'object_type' => 'update_campaign',
            'object_id'   => (string)$campaignId,
            'message'     => sprintf('Campaign "%s" started ring %s', $campaign['name'], $ring),
        ]);

        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('campaign_dispatch')) {
    /**
     * Dispatch pending targets in the campaign's current ring.
     *
     * Idempotent and safe to call repeatedly: only 'pending' or 'holding'
     * targets are considered, and each becomes 'dispatched' exactly once.
     *
     * @return array{dispatched:int, held:int, skipped:int, notes:string[]}
     */
    function campaign_dispatch(int $campaignId): array {
        $out = ['dispatched' => 0, 'held' => 0, 'skipped' => 0, 'notes' => []];

        $stmt = db()->prepare('SELECT * FROM update_campaigns WHERE id = ?');
        $stmt->execute([$campaignId]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$campaign || $campaign['status'] !== 'running' || !$campaign['current_ring']) {
            return $out;
        }

        $targets = db()->prepare(
            'SELECT t.*, f.hostname, f.status AS fw_status, f.updates_available, f.reboot_required
               FROM update_campaign_targets t
               JOIN firewalls f ON f.id = t.firewall_id
              WHERE t.campaign_id = ? AND t.ring = ? AND t.status IN ("pending","holding")
              ORDER BY f.carp_state = "MASTER", f.hostname'
        );
        $targets->execute([$campaignId, $campaign['current_ring']]);

        // Anything already dispatched in this campaign counts as in flight for
        // HA purposes, not just what this pass dispatches.
        $inFlight = db()->prepare(
            'SELECT firewall_id FROM update_campaign_targets
              WHERE campaign_id = ? AND status = "dispatched"'
        );
        $inFlight->execute([$campaignId]);
        $inFlightIds = array_map('intval', $inFlight->fetchAll(PDO::FETCH_COLUMN));

        foreach ($targets->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $fid  = (int)$t['firewall_id'];
            $host = $t['hostname'];

            // Offline firewalls are held, not failed: they may come back.
            if ($t['fw_status'] !== 'online') {
                campaign_hold($t['id'], 'Firewall is offline');
                $out['held']++;
                continue;
            }

            // Nothing to do.
            if ($campaign['operation'] === 'install' && (int)$t['updates_available'] !== 1) {
                campaign_set_status($t['id'], 'skipped', 'No update available');
                $out['skipped']++;
                continue;
            }

            // Maintenance windows, when the campaign respects them.
            if ((int)$campaign['respect_maintenance'] === 1) {
                $windows = db()->prepare(
                    'SELECT COUNT(*) FROM maintenance_windows m JOIN firewalls f ON f.id = ?
                      WHERE m.status IN ("scheduled","active")
                        AND ((m.scope="firewall" AND m.scope_id=f.id)
                          OR (m.scope="site" AND f.site_id IS NOT NULL AND m.scope_id=f.site_id)
                          OR (m.scope="customer" AND f.customer_id IS NOT NULL AND m.scope_id=f.customer_id))'
                );
                $windows->execute([$fid]);

                if ((int)$windows->fetchColumn() > 0) {
                    maintenance_reset_cache();
                    if (maintenance_active_for($fid) === null) {
                        campaign_hold($t['id'], 'Waiting for the scheduled maintenance window');
                        $out['held']++;
                        continue;
                    }
                }
            }

            // HA safety.
            if ((int)$campaign['ha_safe'] === 1) {
                $ha = ha_dispatch_check($fid, $inFlightIds);
                if (!$ha['safe']) {
                    campaign_hold($t['id'], $ha['reason']);
                    $out['held']++;
                    $out['notes'][] = $host . ': ' . $ha['reason'];
                    continue;
                }
            }

            // Dispatch.
            $action = $campaign['operation'] === 'check' ? 'check_updates' : 'install_updates';
            $built  = build_structured_command($action, []);
            if (!$built['ok']) {
                campaign_set_status($t['id'], 'failed', $built['error']);
                continue;
            }

            $queued = queue_firewall_command(
                $fid,
                $built['command'],
                sprintf('Campaign #%d (%s): %s', $campaignId, $campaign['current_ring'], $built['label']),
                ['is_raw' => false, 'action' => $action, 'risk' => $built['risk']]
            );

            if (!$queued['ok']) {
                campaign_set_status($t['id'], 'failed', $queued['error']);
                continue;
            }

            db()->prepare(
                'UPDATE update_campaign_targets
                    SET status = "dispatched", command_id = ?, dispatched_at = NOW(), hold_reason = NULL
                  WHERE id = ?'
            )->execute([$queued['command_id'], $t['id']]);

            db()->prepare(
                'UPDATE firewalls SET last_update_attempt_at = NOW(), last_update_result = "dispatched" WHERE id = ?'
            )->execute([$fid]);

            $inFlightIds[] = $fid;
            $out['dispatched']++;

            audit_log('update.dispatch', [
                'object_type' => 'firewall',
                'object_id'   => (string)$fid,
                'firewall_id' => $fid,
                'message'     => sprintf('Update dispatched by campaign #%d (ring %s)', $campaignId, $campaign['current_ring']),
                'metadata'    => ['campaign_id' => $campaignId, 'command_id' => $queued['command_id']],
            ]);
        }

        return $out;
    }
}

if (!function_exists('campaign_hold')) {
    /** Park a target with an explanation rather than failing it. */
    function campaign_hold(int $targetId, string $reason): void {
        db()->prepare('UPDATE update_campaign_targets SET status = "holding", hold_reason = ? WHERE id = ?')
            ->execute([substr($reason, 0, 255), $targetId]);
    }
}

if (!function_exists('campaign_set_status')) {
    /** Set a terminal status on a target. */
    function campaign_set_status(int $targetId, string $status, ?string $detail = null): void {
        db()->prepare(
            'UPDATE update_campaign_targets
                SET status = ?, result = ?, completed_at = NOW(), hold_reason = NULL
              WHERE id = ?'
        )->execute([$status, $detail, $targetId]);
    }
}

if (!function_exists('campaign_reconcile')) {
    /**
     * Fold completed commands back into campaign targets.
     *
     * A dispatched target stays dispatched until the agent reports the command
     * finished; this is what turns that into succeeded or failed.
     *
     * @return array{resolved:int}
     */
    function campaign_reconcile(int $campaignId): array {
        $resolved = 0;

        $stmt = db()->prepare(
            'SELECT t.id, t.firewall_id, t.command_id, c.status AS cmd_status, c.result,
                    f.current_version, f.version
               FROM update_campaign_targets t
               JOIN firewall_commands c ON c.id = t.command_id
               JOIN firewalls f ON f.id = t.firewall_id
              WHERE t.campaign_id = ? AND t.status = "dispatched"
                AND c.status IN ("completed","failed","cancelled")'
        );
        $stmt->execute([$campaignId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ok = $row['cmd_status'] === 'completed';

            db()->prepare(
                'UPDATE update_campaign_targets
                    SET status = ?, completed_at = NOW(),
                        version_after = LEFT(?, 64),
                        result = LEFT(?, 2000)
                  WHERE id = ?'
            )->execute([
                $ok ? 'succeeded' : 'failed',
                $row['current_version'] ?: $row['version'],
                (string)$row['result'],
                $row['id'],
            ]);

            db()->prepare(
                'UPDATE firewalls SET last_update_result = ?, last_update_error = ? WHERE id = ?'
            )->execute([
                $ok ? 'succeeded' : 'failed',
                $ok ? null : substr((string)$row['result'], 0, 255),
                $row['firewall_id'],
            ]);

            $resolved++;
        }

        return ['resolved' => $resolved];
    }
}

if (!function_exists('campaign_ring_summary')) {
    /**
     * Per-ring progress for a campaign.
     */
    function campaign_ring_summary(int $campaignId): array {
        $summary = [];
        foreach (UPDATE_RINGS as $ring) {
            $summary[$ring] = ['pending' => 0, 'holding' => 0, 'dispatched' => 0,
                               'succeeded' => 0, 'failed' => 0, 'skipped' => 0, 'total' => 0];
        }

        try {
            $stmt = db()->prepare(
                'SELECT ring, status, COUNT(*) n FROM update_campaign_targets
                  WHERE campaign_id = ? GROUP BY ring, status'
            );
            $stmt->execute([$campaignId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $summary[$r['ring']][$r['status']] = (int)$r['n'];
                $summary[$r['ring']]['total'] += (int)$r['n'];
            }
        } catch (Throwable $e) {
            error_log('OPNMGR: campaign_ring_summary failed: ' . $e->getMessage());
        }

        return $summary;
    }
}

if (!function_exists('campaign_ring_is_clean')) {
    /**
     * Whether a ring finished with no failures and has soaked long enough.
     *
     * Used as the precondition for automatic progression. A ring with any
     * failure is never clean, so auto-progress cannot roll a bad release
     * onward.
     */
    function campaign_ring_is_clean(int $campaignId, string $ring): array {
        $summary = campaign_ring_summary($campaignId)[$ring] ?? null;
        if (!$summary || $summary['total'] === 0) {
            return ['clean' => false, 'reason' => 'ring is empty'];
        }
        if ($summary['failed'] > 0) {
            return ['clean' => false, 'reason' => $summary['failed'] . ' target(s) failed'];
        }
        if ($summary['pending'] + $summary['holding'] + $summary['dispatched'] > 0) {
            return ['clean' => false, 'reason' => 'ring is still in progress'];
        }

        $soak = (int)fleet_update_setting('update_ring_soak_hours', 24);
        $stmt = db()->prepare(
            'SELECT TIMESTAMPDIFF(HOUR, MAX(completed_at), NOW())
               FROM update_campaign_targets WHERE campaign_id = ? AND ring = ?'
        );
        $stmt->execute([$campaignId, $ring]);
        $hours = (int)$stmt->fetchColumn();

        if ($hours < $soak) {
            return ['clean' => false, 'reason' => sprintf('soaking (%dh of %dh)', $hours, $soak)];
        }

        return ['clean' => true, 'reason' => 'ring completed cleanly'];
    }
}
