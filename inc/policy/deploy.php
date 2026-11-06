<?php
/**
 * OPNMGR policy templates: preview, approval, staged application, verification.
 *
 * Lifecycle of one deployment target (one firewall):
 *
 *   planned ──approve──> approved ──start──> applying ──agent ok──> verifying
 *      │                    │                   │                      │
 *      └ blocked/stale      └ stale (config     └ failed (refused,     ├ read-back exact ─> confirming ─> succeeded
 *        (not approvable)     changed: re-plan    fetch/install error)  └ mismatch/timeout ─> rollback ─> rolled_back
 *
 * Rules enforced here:
 *
 *   - A plan is only approvable when the base configuration is a validated
 *     backup younger than policy_max_backup_age_minutes, every variable
 *     validated, nothing is unsupported, the management path is provably
 *     kept, and the platform allows policy.apply. On every platform today
 *     policy.apply is untested, so outside designated lab firewalls every plan
 *     is preview-only and says why.
 *   - Approval binds target, firewall, revision, content hash, plan hash and
 *     base fingerprint (approval_hash). Before execution the target is
 *     re-planned from the newest backup; any difference invalidates the
 *     approval (status stale) and the deployment returns to planned.
 *   - One deployment per firewall at a time (policy_firewall_locks), on top
 *     of the platform's own config lock taken by installConfigShell.
 *   - Targets run one at a time in ring order (canary, pilot, production).
 *     The first failure stops the deployment and holds every later target.
 *   - HA: when a firewall is the sync source of a pair and the plan touches
 *     sections it syncs, only it is written; the partner is verified by
 *     read-back (secondary_verify) and never written independently.
 *   - Success is never inferred from a command exit. The confirm that
 *     disarms the device's recovery timer is queued only after a fresh backup
 *     taken after the apply contains exactly the planned managed objects and
 *     the agent has checked in since the apply.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/render.php';
require_once __DIR__ . '/device.php';
require_once __DIR__ . '/../agent_commands.php';
require_once __DIR__ . '/../backup_storage.php';
require_once __DIR__ . '/../config_drift.php';
require_once __DIR__ . '/../fleet_updates.php';
require_once __DIR__ . '/../scope.php';

if (!function_exists('policy_setting')) {
    function policy_setting(string $name, string $default): string {
        $v = function_exists('platform_setting') ? platform_setting($name, $default) : $default;
        return $v === '' ? $default : $v;
    }
}

if (!function_exists('policy_manager_ips')) {
    /** Addresses the manager reaches firewalls from (setting policy_manager_source_ips). */
    function policy_manager_ips(): array {
        $raw = platform_setting('policy_manager_source_ips', '');
        return array_values(array_filter(preg_split('/[\s,]+/', $raw) ?: [], fn($v) => policy_valid_ip($v)));
    }
}

if (!function_exists('policy_base_config')) {
    /**
     * The newest validated backup a plan may be built from, and whether it is
     * fresh enough to approve.
     *
     * @return array{ok:bool, error:string, xml:string, backup:?array, age_minutes:?int, fresh:bool}
     */
    function policy_base_config(int $firewallId): array {
        $out = ['ok' => false, 'error' => '', 'xml' => '', 'backup' => null, 'age_minutes' => null, 'fresh' => false];
        $row = drift_latest_backup($firewallId);
        if ($row === null) {
            $out['error'] = 'No configuration backup with a readable file';
            return $out;
        }
        if ((int)($row['validated'] ?? 0) !== 1) {
            $out['error'] = 'The newest backup has not been validated';
            $out['backup'] = $row;
            return $out;
        }
        $loaded = drift_load_backup_xml((int)$row['id']);
        if (!$loaded['ok']) {
            $out['error'] = $loaded['error'];
            return $out;
        }
        if (!empty($row['checksum_sha256']) && !hash_equals((string)$row['checksum_sha256'], hash('sha256', $loaded['xml']))) {
            $out['error'] = 'The newest backup no longer matches its recorded checksum';
            return $out;
        }
        $at = strtotime((string)($row['uploaded_at'] ?: $row['created_at']));
        $age = (int)floor((time() - $at) / 60);
        $max = max(1, (int)policy_setting('policy_max_backup_age_minutes', '60'));
        return ['ok' => true, 'error' => '', 'xml' => $loaded['xml'], 'backup' => $row,
                'age_minutes' => $age, 'fresh' => $age <= $max];
    }
}

if (!function_exists('policy_request_backup')) {
    /** Queue a fresh configuration backup (the "fresh backup" offered when a plan is stale). */
    function policy_request_backup(int $firewallId, string $why): array {
        $filename = sprintf('policy-%d-%s.xml', $firewallId, date('Y-m-d_H-i-s'));
        db()->prepare('INSERT INTO backups (firewall_id, backup_file, description, backup_type, created_at)
                       VALUES (?,?,?,"automated",NOW())')
            ->execute([$firewallId, $filename, substr($why, 0, 255)]);
        $backupId = (int)db()->lastInsertId();
        $q = queue_firewall_command($firewallId, build_backup_upload_command($firewallId, $backupId, $filename), $why,
                                    ['is_raw' => false, 'action' => 'retrieve_config', 'risk' => 'MEDIUM']);
        if (!$q['ok']) {
            db()->prepare('DELETE FROM backups WHERE id = ? AND storage_path IS NULL')->execute([$backupId]);
            return ['ok' => false, 'error' => $q['error'], 'backup_id' => 0, 'command_id' => 0];
        }
        return ['ok' => true, 'error' => '', 'backup_id' => $backupId, 'command_id' => $q['command_id']];
    }
}

if (!function_exists('policy_tracked_values')) {
    /** Values of slot-style settings from the last successful application (pfSense syslog slots). */
    function policy_tracked_values(int $firewallId, int $templateId): array {
        $s = db()->prepare('SELECT t.plan_json FROM policy_deployment_targets t
                              JOIN policy_deployments d ON d.id = t.deployment_id
                             WHERE t.firewall_id = ? AND d.template_id = ? AND t.status = "succeeded"
                             ORDER BY t.id DESC LIMIT 1');
        $s->execute([$firewallId, $templateId]);
        $plan = json_decode((string)$s->fetchColumn(), true) ?: [];
        $out = [];
        foreach ($plan['managed'] ?? [] as $m) {
            if ($m['kind'] === 'syslog' && isset($m['value'])) { $out[$m['key']] = $m['value']; }
        }
        return $out;
    }
}

if (!function_exists('policy_context')) {
    /**
     * Everything the planner needs for one firewall and revision.
     *
     * @return array{ok:bool, errors:string[], ctx:array, revision:?array}
     */
    function policy_context(array $fw, int $revisionId): array {
        $rev = policy_revision_get($revisionId);
        if (!$rev) {
            return ['ok' => false, 'errors' => ['Revision not found'], 'ctx' => [], 'revision' => null];
        }
        $errors = [];
        if (!$rev['integrity_ok']) {
            $errors[] = 'The revision content does not match its recorded hash; it has been modified outside OPNManager';
        }
        if ($rev['status'] !== 'published') {
            $errors[] = "Revision {$rev['revision']} is {$rev['status']}; only published revisions are planned for deployment";
        }
        $tid = (int)$rev['template_id'];
        $vars = policy_resolve_variables($fw, $tid, $rev['content_decoded']);
        $errors = array_merge($errors, $vars['errors']);
        $ctx = [
            'template_id'  => $tid,
            'revision_id'  => $revisionId,
            'content_hash' => $rev['content_hash'],
            'content'      => $rev['content_decoded'],
            'values'       => $vars['values'],
            'family'       => platform_adapter($fw)->family(),
            'exceptions'   => policy_active_exceptions((int)$fw['id'], $tid),
            'mappings'     => policy_mappings_for((int)$fw['id'], $tid),
            'manager_ips'  => policy_manager_ips(),
            'tracked'      => policy_tracked_values((int)$fw['id'], $tid),
        ];
        return ['ok' => !$errors, 'errors' => $errors, 'ctx' => $ctx, 'revision' => $rev, 'sources' => $vars['sources']];
    }
}

if (!function_exists('policy_preview_firewall')) {
    /**
     * Preview one revision on one firewall: the plan plus every reason it is
     * not approvable. Never queues anything.
     *
     * @return array{plan:array, blockers:string[], approvable:bool, base:array, apply:array, context_errors:string[], preview_only:bool}
     */
    function policy_preview_firewall(array $fw, int $revisionId): array {
        $blockers = [];
        $preview = platform_capability_allows($fw, 'policy.preview');
        $apply = platform_capability_allows($fw, 'policy.apply');
        $c = policy_context($fw, $revisionId);
        $base = policy_base_config((int)$fw['id']);
        $plan = ['ok' => false, 'errors' => [], 'blockers' => [], 'warnings' => [], 'changes' => [], 'unsupported' => [],
                 'skipped' => [], 'managed' => [], 'sync_items' => [], 'change_count' => 0, 'base_fingerprint' => null,
                 'artifact_sha256' => null, 'plan_hash' => null];

        if (!$preview['allowed']) {
            $blockers[] = 'Preview is not available: ' . $preview['reason'];
        } elseif (!$base['ok']) {
            $blockers[] = 'No usable base configuration: ' . $base['error'];
        } elseif ($c['ctx'] && $c['revision']) {
            // Variable/integrity errors still let the planner report what it can.
            $plan = policy_plan($base['xml'], $c['ctx']);
            unset($plan['xml']);
        }
        $blockers = array_merge($blockers, $c['errors'], $plan['errors'], $plan['blockers']);
        if ($base['ok'] && !$base['fresh']) {
            $blockers[] = sprintf('The base configuration is %d minutes old (limit %s); request a fresh backup and re-plan',
                $base['age_minutes'], policy_setting('policy_max_backup_age_minutes', '60'));
        }
        $previewOnly = !$apply['allowed'];
        if ($previewOnly) {
            $blockers[] = 'Preview only: ' . $apply['reason'];
        }
        return ['plan' => policy_redact($plan), 'blockers' => array_values(array_unique($blockers)),
                'approvable' => !$blockers && $plan['ok'], 'base' => array_diff_key($base, ['xml' => 1]),
                'apply' => $apply, 'context_errors' => $c['errors'], 'preview_only' => $previewOnly,
                'variable_sources' => $c['sources'] ?? []];
    }
}

// ---------------------------------------------------------------------------
// Deployments
// ---------------------------------------------------------------------------

if (!function_exists('policy_ha_roles')) {
    /**
     * Decide which targets are written and which are verified by read-back.
     *
     * @param array $targets fw_id => ['fw' => row, 'model' => normalized model|null, 'sync_items' => plan sync items]
     * @return array fw_id => ['role' => standalone|primary|secondary_verify, 'primary' => ?fw_id, 'blocker' => ?string]
     */
    function policy_ha_roles(array $targets): array {
        $out = [];
        foreach ($targets as $id => $t) {
            $out[$id] = ['role' => 'standalone', 'primary' => null, 'blocker' => null];
        }
        foreach ($targets as $id => $t) {
            $fw = $t['fw'];
            $peerId = (int)($fw['ha_peer_firewall_id'] ?? 0);
            if ((int)($fw['carp_enabled'] ?? 0) !== 1 || $peerId <= 0) {
                continue;
            }
            $ha = $t['model']['ha'] ?? [];
            $isSource = !empty($ha['sync_peer']);
            if ($isSource) {
                $synced = array_intersect($t['sync_items'], $ha['sync_items'] ?? []);
                if (!$synced) {
                    continue;   // nothing this plan touches is synced: both are written, staged
                }
                $out[$id]['role'] = 'primary';
                if (isset($targets[$peerId])) {
                    $out[$peerId] = ['role' => 'secondary_verify', 'primary' => $id, 'blocker' => null];
                }
                continue;
            }
            // Not the source. If the partner is a source that syncs what we
            // change, writing this member would be the second independent write.
            $peerModel = $targets[$peerId]['model'] ?? null;
            if ($peerModel === null) {
                $base = policy_base_config($peerId);
                $peerModel = $base['ok'] ? platform_normalize_config($base['xml']) : null;
            }
            $peerHa = $peerModel['ha'] ?? [];
            if (!empty($peerHa['sync_peer']) && array_intersect($t['sync_items'], $peerHa['sync_items'] ?? [])) {
                if (isset($targets[$peerId])) {
                    $out[$id] = ['role' => 'secondary_verify', 'primary' => $peerId, 'blocker' => null];
                } else {
                    $out[$id]['blocker'] = sprintf('This firewall receives %s from its HA primary (#%d) by config sync. '
                        . 'Deploy to the primary; this member is then verified by read-back, never written independently.',
                        implode(', ', array_intersect($t['sync_items'], $peerHa['sync_items'] ?? [])), $peerId);
                }
            } elseif ($peerModel === null) {
                $out[$id]['blocker'] = 'This firewall is in an HA pair and the partner\'s sync settings could not be read';
            }
        }
        return $out;
    }
}

if (!function_exists('policy_deployment_create')) {
    /**
     * Plan a revision for a set of firewalls.
     *
     * @return array{ok:bool, error:string, id:int}
     */
    function policy_deployment_create(int $revisionId, array $firewallIds, string $notes = ''): array {
        $fail = fn(string $e) => ['ok' => false, 'error' => $e, 'id' => 0];
        $rev = policy_revision_get($revisionId);
        if (!$rev || $rev['status'] !== 'published') {
            return $fail('Only a published revision can be deployed');
        }
        $firewallIds = array_values(array_unique(array_filter(array_map('intval', $firewallIds))));
        if (!$firewallIds) {
            return $fail('Select at least one firewall');
        }
        $rings = array_flip(UPDATE_RINGS);
        $targets = [];
        foreach ($firewallIds as $fid) {
            if (!scope_can_firewall($fid)) {
                return $fail("Firewall #{$fid} is outside your customer scope");
            }
            $fw = platform_firewall($fid);
            if (!$fw) {
                return $fail("Firewall #{$fid} not found");
            }
            $pre = policy_preview_firewall($fw, $revisionId);
            $base = policy_base_config($fid);
            $targets[$fid] = ['fw' => $fw, 'preview' => $pre, 'model' => $base['ok'] ? platform_normalize_config($base['xml']) : null,
                              'sync_items' => $pre['plan']['sync_items'] ?? [], 'base' => $base];
        }
        uasort($targets, fn($a, $b) => [$rings[$a['fw']['update_ring'] ?? 'production'] ?? 2, $a['fw']['hostname']]
                                    <=> [$rings[$b['fw']['update_ring'] ?? 'production'] ?? 2, $b['fw']['hostname']]);
        $roles = policy_ha_roles($targets);

        db()->prepare('INSERT INTO policy_deployments (template_id, revision_id, notes, created_by) VALUES (?,?,?,?)')
            ->execute([(int)$rev['template_id'], $revisionId, substr($notes, 0, 255) ?: null, policy_actor()]);
        $depId = (int)db()->lastInsertId();

        $i = 0;
        foreach ($targets as $fid => $t) {
            $p = $t['preview'];
            $blockers = $p['blockers'];
            if ($roles[$fid]['blocker']) { $blockers[] = $roles[$fid]['blocker']; }
            $role = $roles[$fid]['role'];
            if ($role === 'secondary_verify') {
                // Not written: preview-only and freshness do not apply to a read-back check.
                $blockers = array_values(array_filter($blockers, fn($b) => !str_starts_with($b, 'Preview only:')));
            }
            $plan = $p['plan'];
            $status = $blockers ? 'blocked' : ((int)($plan['change_count'] ?? 0) === 0 && $role !== 'secondary_verify' ? 'skipped' : 'planned');
            $approvable = !$blockers && ($plan['ok'] ?? false) && $status === 'planned';
            db()->prepare('INSERT INTO policy_deployment_targets
                    (deployment_id, firewall_id, order_index, ring, ha_role, status, approvable, base_backup_id,
                     base_fingerprint, plan_hash, artifact_sha256, plan_json, change_count, blockers, detail)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$depId, $fid, $i++, $t['fw']['update_ring'] ?? null, $role, $status, $approvable ? 1 : 0,
                           $t['base']['backup']['id'] ?? null, $plan['base_fingerprint'] ?? null, $plan['plan_hash'] ?? null,
                           $plan['artifact_sha256'] ?? null,
                           json_encode(policy_redact($plan + ['ha_primary' => $roles[$fid]['primary']]), JSON_UNESCAPED_SLASHES),
                           (int)($plan['change_count'] ?? 0), $blockers ? json_encode($blockers) : null,
                           $status === 'skipped' ? 'No changes: the firewall already matches this revision' : null]);
        }
        audit_log('policy.deployment.create', ['object_type' => 'policy_deployment', 'object_id' => (string)$depId,
            'message' => sprintf('Deployment planned: template #%d rev %d to %d firewall(s)', $rev['template_id'],
                                 $rev['revision'], count($targets)),
            'metadata' => ['revision_id' => $revisionId, 'firewalls' => array_keys($targets)]]);
        return ['ok' => true, 'error' => '', 'id' => $depId];
    }
}

if (!function_exists('policy_deployment_get')) {
    function policy_deployment_get(int $id): ?array {
        $s = db()->prepare('SELECT d.*, t.name AS template_name, r.revision
                              FROM policy_deployments d
                              JOIN policy_templates t ON t.id = d.template_id
                              JOIN policy_template_revisions r ON r.id = d.revision_id WHERE d.id = ?');
        $s->execute([$id]);
        $d = $s->fetch(PDO::FETCH_ASSOC);
        if (!$d) {
            return null;
        }
        $s = db()->prepare('SELECT t.*, f.hostname, f.platform_family, f.customer_id, f.site_id
                              FROM policy_deployment_targets t JOIN firewalls f ON f.id = t.firewall_id
                             WHERE t.deployment_id = ? ORDER BY t.order_index');
        $s->execute([$id]);
        $d['targets'] = $s->fetchAll(PDO::FETCH_ASSOC);
        return $d;
    }
}

if (!function_exists('policy_approval_hash')) {
    function policy_approval_hash(array $t, array $dep, string $contentHash): string {
        return hash('sha256', policy_canonical_json([
            'target' => (int)$t['id'], 'firewall' => (int)$t['firewall_id'], 'revision' => (int)$dep['revision_id'],
            'content_hash' => $contentHash, 'plan_hash' => (string)$t['plan_hash'],
            'base_fingerprint' => (string)$t['base_fingerprint'],
        ]));
    }
}

if (!function_exists('policy_deployment_approve')) {
    /**
     * Approve the concrete plans of a deployment. Only targets whose plan is
     * approvable are bound; blocked and skipped targets are left as they are.
     */
    function policy_deployment_approve(int $deploymentId): array {
        $dep = policy_deployment_get($deploymentId);
        if (!$dep || $dep['status'] !== 'planned') {
            return ['ok' => false, 'error' => 'Only a planned deployment can be approved', 'approved' => 0];
        }
        $rev = policy_revision_get((int)$dep['revision_id']);
        if (!$rev || !$rev['integrity_ok'] || $rev['status'] !== 'published') {
            return ['ok' => false, 'error' => 'The revision is no longer a valid published revision', 'approved' => 0];
        }
        $n = 0;
        foreach ($dep['targets'] as $t) {
            if ($t['status'] !== 'planned' || (int)$t['approvable'] !== 1) {
                continue;
            }
            if (!scope_can_firewall((int)$t['firewall_id'])) {
                continue;
            }
            db()->prepare('UPDATE policy_deployment_targets
                              SET status = "approved", approval_hash = ?, approved_plan_hash = plan_hash,
                                  approved_fingerprint = base_fingerprint, approved_by = ?, approved_at = NOW()
                            WHERE id = ? AND status = "planned"')
                ->execute([policy_approval_hash($t, $dep, $rev['content_hash']), policy_actor(), $t['id']]);
            $n++;
        }
        if ($n === 0) {
            return ['ok' => false, 'error' => 'No target has an approvable plan', 'approved' => 0];
        }
        db()->prepare('UPDATE policy_deployments SET status = "approved", approved_by = ?, approved_at = NOW() WHERE id = ?')
            ->execute([policy_actor(), $deploymentId]);
        audit_log('policy.deployment.approve', ['object_type' => 'policy_deployment', 'object_id' => (string)$deploymentId,
            'message' => sprintf('Deployment #%d approved for %d target(s)', $deploymentId, $n),
            'metadata' => ['targets' => array_map(fn($t) => ['firewall_id' => (int)$t['firewall_id'], 'plan_hash' => $t['plan_hash'],
                                                             'base_fingerprint' => $t['base_fingerprint']], $dep['targets'])]]);
        return ['ok' => true, 'error' => '', 'approved' => $n];
    }
}

if (!function_exists('policy_deployment_execute')) {
    /** Start an approved deployment; the reconciler carries it forward. */
    function policy_deployment_execute(int $deploymentId): array {
        $stmt = db()->prepare('UPDATE policy_deployments SET status = "running", started_at = NOW() WHERE id = ? AND status = "approved"');
        $stmt->execute([$deploymentId]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'error' => 'Only an approved deployment can be started'];
        }
        audit_log('policy.deployment.start', ['object_type' => 'policy_deployment', 'object_id' => (string)$deploymentId,
            'message' => "Deployment #{$deploymentId} started"]);
        return policy_dispatch_next($deploymentId);
    }
}

if (!function_exists('policy_deployment_cancel')) {
    function policy_deployment_cancel(int $deploymentId): array {
        $active = db()->prepare('SELECT COUNT(*) FROM policy_deployment_targets WHERE deployment_id = ?
                                    AND status IN ("pre_backup","applying","verifying","confirming")');
        $active->execute([$deploymentId]);
        if ((int)$active->fetchColumn() > 0) {
            return ['ok' => false, 'error' => 'A target is mid-application; it finishes (confirm or roll back) first'];
        }
        db()->prepare('UPDATE policy_deployment_targets SET status = "cancelled" WHERE deployment_id = ?
                         AND status IN ("planned","approved","held","stale","blocked")')->execute([$deploymentId]);
        db()->prepare('UPDATE policy_deployments SET status = "cancelled", finished_at = NOW() WHERE id = ?
                         AND status IN ("planned","approved","running","stopped")')->execute([$deploymentId]);
        audit_log('policy.deployment.cancel', ['object_type' => 'policy_deployment', 'object_id' => (string)$deploymentId,
            'message' => "Deployment #{$deploymentId} cancelled"]);
        return ['ok' => true, 'error' => ''];
    }
}

// ---------------------------------------------------------------------------
// Locks
// ---------------------------------------------------------------------------

if (!function_exists('policy_lock_acquire')) {
    /** One in-flight policy application per firewall. Expired locks are reclaimed. */
    function policy_lock_acquire(int $firewallId, int $targetId): bool {
        $ttl = (int)policy_setting('policy_recovery_window_seconds', '900') + 3600;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                db()->prepare('INSERT INTO policy_firewall_locks (firewall_id, target_id, acquired_at, expires_at)
                               VALUES (?,?,NOW(),DATE_ADD(NOW(), INTERVAL ? SECOND))')
                    ->execute([$firewallId, $targetId, $ttl]);
                return true;
            } catch (PDOException $e) {
                $s = db()->prepare('SELECT target_id, expires_at < NOW() AS expired FROM policy_firewall_locks WHERE firewall_id = ?');
                $s->execute([$firewallId]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if ($row && (int)$row['target_id'] === $targetId) {
                    return true;
                }
                if (!$row || !(int)$row['expired']) {
                    return false;
                }
                db()->prepare('DELETE FROM policy_firewall_locks WHERE firewall_id = ? AND expires_at < NOW()')->execute([$firewallId]);
            }
        }
        return false;
    }
}

if (!function_exists('policy_lock_release')) {
    function policy_lock_release(int $firewallId, int $targetId): void {
        db()->prepare('DELETE FROM policy_firewall_locks WHERE firewall_id = ? AND target_id = ?')->execute([$firewallId, $targetId]);
    }
}

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

if (!function_exists('policy_target_update')) {
    function policy_target_update(int $id, array $cols): void {
        $set = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($cols)));
        db()->prepare("UPDATE policy_deployment_targets SET {$set} WHERE id = ?")->execute([...array_values($cols), $id]);
    }
}

if (!function_exists('policy_target_get')) {
    function policy_target_get(int $id): ?array {
        $s = db()->prepare('SELECT t.*, d.revision_id, d.template_id, d.status AS deployment_status
                              FROM policy_deployment_targets t JOIN policy_deployments d ON d.id = t.deployment_id WHERE t.id = ?');
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('policy_dispatch_next')) {
    /**
     * Start the next target of a running deployment, if nothing is in flight
     * and nothing has failed.
     */
    function policy_dispatch_next(int $deploymentId): array {
        $dep = policy_deployment_get($deploymentId);
        if (!$dep || $dep['status'] !== 'running') {
            return ['ok' => false, 'error' => 'Deployment is not running'];
        }
        $byFw = [];
        foreach ($dep['targets'] as $t) {
            if (in_array($t['status'], ['pre_backup', 'applying', 'verifying', 'confirming'], true)) {
                return ['ok' => true, 'error' => '', 'waiting' => (int)$t['id']];
            }
            if (in_array($t['status'], ['failed', 'rolled_back'], true)) {
                policy_deployment_stop($deploymentId, sprintf('Target %s failed', $t['hostname']));
                return ['ok' => false, 'error' => 'Deployment stopped after a failure'];
            }
            $byFw[(int)$t['firewall_id']] = $t;
        }
        foreach ($dep['targets'] as $t) {
            if ($t['status'] !== 'approved') {
                continue;
            }
            if ($t['ha_role'] === 'secondary_verify') {
                $primary = json_decode((string)$t['plan_json'], true)['ha_primary'] ?? null;
                $p = $primary !== null ? ($byFw[(int)$primary] ?? null) : null;
                if ($p === null || $p['status'] !== 'succeeded') {
                    continue;   // wait for the primary; it is earlier or later in order
                }
                return policy_secondary_start($t);
            }
            return policy_target_start((int)$t['id']);
        }
        // Nothing left to start: finished when nothing is pending.
        $left = array_filter($dep['targets'], fn($t) => in_array($t['status'], ['approved', 'held'], true));
        if (!$left) {
            db()->prepare('UPDATE policy_deployments SET status = "completed", finished_at = NOW() WHERE id = ? AND status = "running"')
                ->execute([$deploymentId]);
            audit_log('policy.deployment.complete', ['actor_type' => 'system', 'object_type' => 'policy_deployment',
                'object_id' => (string)$deploymentId, 'message' => "Deployment #{$deploymentId} completed"]);
        }
        return ['ok' => true, 'error' => '', 'done' => !$left];
    }
}

if (!function_exists('policy_deployment_stop')) {
    /** Stop on failure: every not-yet-started target is held. */
    function policy_deployment_stop(int $deploymentId, string $why): void {
        db()->prepare('UPDATE policy_deployment_targets SET status = "held", detail = ? WHERE deployment_id = ? AND status = "approved"')
            ->execute(['Held: an earlier target failed (' . substr($why, 0, 200) . ')', $deploymentId]);
        db()->prepare('UPDATE policy_deployments SET status = "stopped", finished_at = NOW(), detail = ? WHERE id = ? AND status = "running"')
            ->execute([substr($why, 0, 1000), $deploymentId]);
        audit_log('policy.deployment.stop', ['actor_type' => 'system', 'success' => false, 'object_type' => 'policy_deployment',
            'object_id' => (string)$deploymentId, 'message' => "Deployment #{$deploymentId} stopped: {$why}"]);
    }
}

if (!function_exists('policy_target_fail')) {
    function policy_target_fail(array $t, string $status, string $why): void {
        policy_target_update((int)$t['id'], ['status' => $status, 'detail' => substr($why, 0, 2000),
                                             'finished_at' => date('Y-m-d H:i:s'), 'fetch_token' => null]);
        policy_lock_release((int)$t['firewall_id'], (int)$t['id']);
        $fw = platform_firewall((int)$t['firewall_id']) ?? [];
        audit_log('policy.target.failed', ['actor_type' => 'system', 'success' => false, 'object_type' => 'policy_target',
            'object_id' => (string)$t['id'], 'firewall_id' => (int)$t['firewall_id'],
            'customer_id' => $fw['customer_id'] ?? null, 'site_id' => $fw['site_id'] ?? null,
            'message' => sprintf('Policy deployment #%d on %s: %s', $t['deployment_id'], $fw['hostname'] ?? '?', substr($why, 0, 300))]);
        if (function_exists('integration_emit')) {
            integration_emit('policy.deployment_failed', [
                'firewall_id'   => (int)$t['firewall_id'],
                'hostname'      => $fw['hostname'] ?? null,
                'customer_id'   => isset($fw['customer_id']) ? (int)$fw['customer_id'] : null,
                'site_id'       => isset($fw['site_id']) ? (int)$fw['site_id'] : null,
                'template_id'   => (int)($t['template_id'] ?? 0),
                'revision_id'   => (int)($t['revision_id'] ?? 0),
                'deployment_id' => (int)$t['deployment_id'],
                'target_id'     => (int)$t['id'],
                'status'        => $status,
                'summary'       => substr(preg_replace('/[0-9a-f]{64}/', '[hash]', $why), 0, 300),
            ]);
        }
        policy_deployment_stop((int)$t['deployment_id'], sprintf('%s on firewall #%d', $status, $t['firewall_id']));
    }
}

if (!function_exists('policy_target_start')) {
    /**
     * Re-check everything, then back up and dispatch the apply for one target.
     */
    function policy_target_start(int $targetId): array {
        $t = policy_target_get($targetId);
        if (!$t || $t['status'] !== 'approved') {
            return ['ok' => false, 'error' => 'Target is not approved'];
        }
        $fw = platform_firewall((int)$t['firewall_id']);
        $fid = (int)$t['firewall_id'];

        $allow = platform_capability_allows($fw ?? ['id' => 0], 'policy.apply');
        if (!$allow['allowed']) {
            policy_target_fail($t, 'failed', 'Template application is not allowed: ' . $allow['reason']);
            return ['ok' => false, 'error' => $allow['reason']];
        }
        $ha = ha_dispatch_check($fid);
        if (!$ha['safe']) {
            policy_target_update($targetId, ['detail' => 'Waiting: ' . $ha['reason']]);
            return ['ok' => true, 'error' => '', 'waiting' => $targetId];
        }

        // Re-plan from the newest backup. Any difference voids the approval.
        $rev = policy_revision_get((int)$t['revision_id']);
        $base = policy_base_config($fid);
        $c = policy_context($fw, (int)$t['revision_id']);
        $dep = ['revision_id' => $t['revision_id']];
        $stale = null;
        if (!$base['ok'] || !$base['fresh']) {
            $stale = $base['ok'] ? 'The base configuration is older than the allowed age; request a fresh backup and re-plan'
                                 : 'No usable base configuration: ' . $base['error'];
        } elseif (!$c['ok']) {
            $stale = 'The plan context is no longer valid: ' . implode('; ', $c['errors']);
        }
        $plan = null;
        if ($stale === null) {
            $plan = policy_plan($base['xml'], $c['ctx']);
            $nowHash = policy_approval_hash(['id' => $t['id'], 'firewall_id' => $fid, 'plan_hash' => $plan['plan_hash'],
                                             'base_fingerprint' => $plan['base_fingerprint']], $dep, $rev['content_hash']);
            if (!hash_equals((string)$t['approval_hash'], $nowHash)
                || !hash_equals((string)$t['approved_fingerprint'], $plan['base_fingerprint'])) {
                $stale = 'The configuration or the plan changed after approval; it has been re-planned and needs a new approval';
            } elseif (!$plan['ok'] || $plan['blockers'] || $plan['xml'] === null) {
                $stale = 'The plan no longer validates: ' . implode('; ', array_merge($plan['errors'], $plan['blockers']));
            }
        }
        if ($stale !== null) {
            policy_invalidate_target($t, $stale, $plan, $base);
            return ['ok' => false, 'error' => $stale, 'stale' => true];
        }

        if (!policy_lock_acquire($fid, $targetId)) {
            policy_target_update($targetId, ['detail' => 'Waiting: another policy deployment holds this firewall']);
            return ['ok' => false, 'error' => 'Another deployment holds the lock for this firewall', 'locked' => true];
        }

        // Pre-change backup (the device also keeps its own copy for the timer).
        $pre = policy_request_backup($fid, 'Automatic pre-policy snapshot (deployment #' . $t['deployment_id'] . ')');
        if (!$pre['ok']) {
            policy_target_fail($t, 'failed', 'Could not queue the pre-change backup: ' . $pre['error']);
            return ['ok' => false, 'error' => $pre['error']];
        }

        $token = bin2hex(random_bytes(32));
        $window = max(300, (int)policy_setting('policy_recovery_window_seconds', '900'));
        $script = policy_build_apply_script(platform_adapter($fw), [
            'target_id' => $targetId, 'firewall_id' => $fid, 'token' => $token,
            'base_sha' => $plan['base_fingerprint'], 'artifact_sha' => $plan['artifact_sha256'], 'window' => $window,
        ]);
        policy_target_update($targetId, ['fetch_token' => $token, 'pre_backup_id' => $pre['backup_id']]);
        $q = queue_firewall_command($fid, $script, sprintf('Apply template revision (deployment #%d)', $t['deployment_id']),
            ['is_raw' => false, 'action' => 'policy_apply', 'risk' => 'CRITICAL',
             'parameters' => ['target_id' => $targetId, 'deployment_id' => (int)$t['deployment_id'],
                              'plan_hash' => $plan['plan_hash'], 'artifact_sha256' => $plan['artifact_sha256']]]);
        if (!$q['ok']) {
            policy_target_fail($t, 'failed', 'Apply refused at queueing: ' . $q['error']);
            return ['ok' => false, 'error' => $q['error']];
        }
        policy_target_update($targetId, [
            'status' => 'applying', 'apply_command_id' => $q['command_id'], 'checkin_before' => $fw['last_checkin'],
            'applied_at' => date('Y-m-d H:i:s'), 'recovery_deadline' => date('Y-m-d H:i:s', time() + $window),
            'detail' => 'Apply queued; waiting for the agent',
        ]);
        audit_log('policy.target.apply', ['object_type' => 'policy_target', 'object_id' => (string)$targetId, 'firewall_id' => $fid,
            'message' => sprintf('Template apply dispatched to %s (deployment #%d)', $fw['hostname'], $t['deployment_id']),
            'metadata' => ['plan_hash' => $plan['plan_hash'], 'changes' => $plan['change_count'], 'pre_backup_id' => $pre['backup_id']]]);
        return ['ok' => true, 'error' => '', 'command_id' => $q['command_id']];
    }
}

if (!function_exists('policy_invalidate_target')) {
    /** Stale approval: store the fresh plan, clear the approval, send the deployment back to planned. */
    function policy_invalidate_target(array $t, string $why, ?array $plan, array $base): void {
        $cols = ['status' => 'stale', 'approvable' => 0, 'approval_hash' => null, 'approved_plan_hash' => null,
                 'approved_fingerprint' => null, 'approved_by' => null, 'approved_at' => null, 'detail' => $why];
        if ($plan !== null) {
            $cols += ['plan_hash' => $plan['plan_hash'], 'base_fingerprint' => $plan['base_fingerprint'],
                      'artifact_sha256' => $plan['artifact_sha256'], 'change_count' => $plan['change_count'],
                      'plan_json' => json_encode(policy_plan_public($plan), JSON_UNESCAPED_SLASHES),
                      'base_backup_id' => $base['backup']['id'] ?? null];
        }
        policy_target_update((int)$t['id'], $cols);
        db()->prepare('UPDATE policy_deployments SET status = "planned", approved_by = NULL, approved_at = NULL, detail = ?
                        WHERE id = ? AND status IN ("approved","running")')
            ->execute(['Approval invalidated: ' . substr($why, 0, 500), $t['deployment_id']]);
        audit_log('policy.target.stale', ['actor_type' => 'system', 'success' => false, 'object_type' => 'policy_target',
            'object_id' => (string)$t['id'], 'firewall_id' => (int)$t['firewall_id'], 'message' => 'Approval invalidated: ' . $why]);
    }
}

if (!function_exists('policy_replan_target')) {
    /** Re-plan a stale/blocked target from the newest backup so it can be approved again. */
    function policy_replan_target(int $targetId): array {
        $t = policy_target_get($targetId);
        if (!$t || !in_array($t['status'], ['stale', 'blocked', 'planned'], true)) {
            return ['ok' => false, 'error' => 'Only planned, blocked or stale targets can be re-planned'];
        }
        $fw = platform_firewall((int)$t['firewall_id']);
        $p = policy_preview_firewall($fw, (int)$t['revision_id']);
        $base = policy_base_config((int)$t['firewall_id']);
        $blockers = $p['blockers'];
        if ($t['ha_role'] === 'secondary_verify') {
            $blockers = array_values(array_filter($blockers, fn($b) => !str_starts_with($b, 'Preview only:')));
        }
        $plan = $p['plan'];
        $status = $blockers ? 'blocked' : ((int)$plan['change_count'] === 0 && $t['ha_role'] !== 'secondary_verify' ? 'skipped' : 'planned');
        $old = json_decode((string)$t['plan_json'], true) ?: [];
        policy_target_update($targetId, [
            'status' => $status, 'approvable' => (!$blockers && $plan['ok'] && $status === 'planned') ? 1 : 0,
            'base_backup_id' => $base['backup']['id'] ?? null, 'base_fingerprint' => $plan['base_fingerprint'],
            'plan_hash' => $plan['plan_hash'], 'artifact_sha256' => $plan['artifact_sha256'],
            'plan_json' => json_encode($plan + ['ha_primary' => $old['ha_primary'] ?? null], JSON_UNESCAPED_SLASHES),
            'change_count' => (int)$plan['change_count'], 'blockers' => $blockers ? json_encode($blockers) : null,
            'detail' => $status === 'skipped' ? 'No changes: the firewall already matches this revision' : 'Re-planned',
        ]);
        return ['ok' => true, 'error' => '', 'status' => $status];
    }
}

if (!function_exists('policy_secondary_start')) {
    /** HA secondary: never written. Ask for a fresh backup and verify the synced result by read-back. */
    function policy_secondary_start(array $t): array {
        $req = policy_request_backup((int)$t['firewall_id'], 'HA read-back verification (deployment #' . $t['deployment_id'] . ')');
        if (!$req['ok']) {
            policy_target_fail(policy_target_get((int)$t['id']), 'failed', 'Could not request the read-back backup: ' . $req['error']);
            return ['ok' => false, 'error' => $req['error']];
        }
        policy_target_update((int)$t['id'], ['status' => 'verifying', 'verify_backup_id' => $req['backup_id'],
            'applied_at' => date('Y-m-d H:i:s'), 'detail' => 'HA secondary: waiting for a read-back backup (not written)']);
        return ['ok' => true, 'error' => ''];
    }
}

// ---------------------------------------------------------------------------
// Reconcile (cron/policy_reconcile.php)
// ---------------------------------------------------------------------------

if (!function_exists('policy_command_row')) {
    function policy_command_row(?int $id): ?array {
        if (!$id) { return null; }
        $s = db()->prepare('SELECT id, status, result, completed_at FROM firewall_commands WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('policy_uploaded_backup_xml')) {
    /** The bytes of a requested backup once the agent has uploaded them. */
    function policy_uploaded_backup_xml(?int $backupId): ?string {
        if (!$backupId) { return null; }
        $s = db()->prepare('SELECT * FROM backups WHERE id = ? AND validated = 1 AND uploaded_at IS NOT NULL');
        $s->execute([$backupId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) { return null; }
        $l = drift_load_backup_xml($backupId);
        return $l['ok'] ? $l['xml'] : null;
    }
}

if (!function_exists('policy_reconcile')) {
    /**
     * Advance every in-flight target on evidence.
     *
     * @return array{advanced:int, succeeded:int, failed:int}
     */
    function policy_reconcile(): array {
        $stats = ['advanced' => 0, 'succeeded' => 0, 'failed' => 0];
        $margin = 120;   // the confirm must reach the device before its deadline
        $rows = db()->query('SELECT id FROM policy_deployment_targets
                              WHERE status IN ("applying","verifying","confirming") ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $id) {
            $t = policy_target_get((int)$id);
            $fw = platform_firewall((int)$t['firewall_id']);
            $deadline = $t['recovery_deadline'] ? strtotime($t['recovery_deadline']) : null;
            $now = time();
            try {
                if ($t['status'] === 'applying') {
                    $cmd = policy_command_row((int)$t['apply_command_id']);
                    $marker = policy_parse_marker_line($cmd['result'] ?? null, 'APPLY');
                    if ($marker === 'ok') {
                        $req = policy_request_backup((int)$t['firewall_id'], 'Policy read-back (deployment #' . $t['deployment_id'] . ')');
                        if (!$req['ok']) {
                            policy_target_rollback($t, 'Could not request the read-back backup: ' . $req['error']);
                            $stats['failed']++;
                            continue;
                        }
                        // The check-in that delivered the apply happened before it ran;
                        // only one after the reported result proves the agent survived it.
                        policy_target_update((int)$id, ['status' => 'verifying', 'verify_backup_id' => $req['backup_id'],
                            'applied_at' => $cmd['completed_at'] ?: date('Y-m-d H:i:s'),
                            'detail' => 'Applied; waiting for read-back and a check-in']);
                        $stats['advanced']++;
                    } elseif ($marker !== null || in_array($cmd['status'] ?? '', ['failed', 'cancelled'], true)) {
                        policy_target_fail($t, 'failed', 'The device did not apply the change: ' . ($marker ?? ($cmd['status'] ?? 'unknown'))
                            . ($marker === 'install_failed' ? ' (the device reverted to its saved configuration)' : ''));
                        $stats['failed']++;
                    } elseif (($cmd['status'] ?? '') === 'completed') {
                        // Completed without a marker: outcome unknown. Never confirm on that.
                        policy_target_rollback($t, 'The apply reported no outcome marker; treating as unverified');
                        $stats['failed']++;
                    } elseif ($deadline !== null && $now > $deadline) {
                        policy_target_rollback($t, 'No apply result before the recovery deadline; the device reverts on its own if it ran');
                        $stats['failed']++;
                    }
                    continue;
                }

                if ($t['status'] === 'verifying') {
                    $xml = policy_uploaded_backup_xml((int)$t['verify_backup_id']);
                    $checkedIn = $fw && $fw['last_checkin'] && $t['applied_at']
                                 && strtotime($fw['last_checkin']) > strtotime($t['applied_at']);
                    if ($xml === null || !$checkedIn) {
                        $limit = $t['ha_role'] === 'secondary_verify'
                            ? strtotime($t['applied_at']) + 60 * (int)policy_setting('policy_verify_minutes', '20')
                            : ($deadline !== null ? $deadline - $margin : $now + 1);
                        if ($now > $limit) {
                            $t['ha_role'] === 'secondary_verify'
                                ? policy_target_fail($t, 'failed', 'HA secondary read-back did not arrive in time')
                                : policy_target_rollback($t, 'Read-back or check-in did not arrive before the recovery deadline');
                            $stats['failed']++;
                        }
                        continue;
                    }
                    $plan = json_decode((string)$t['plan_json'], true) ?: [];
                    $c = policy_context($fw, (int)$t['revision_id']);
                    $v = $c['ctx'] ? policy_verify_readback($xml, $c['ctx'], $plan['managed'] ?? [])
                                   : ['ok' => false, 'reasons' => $c['errors']];
                    if (!$v['ok']) {
                        $why = 'Read-back does not match the plan: ' . implode('; ', array_slice($v['reasons'], 0, 10));
                        $t['ha_role'] === 'secondary_verify' ? policy_target_fail($t, 'failed', $why . ' (HA sync not observed)')
                                                             : policy_target_rollback($t, $why);
                        $stats['failed']++;
                        continue;
                    }
                    if ($t['ha_role'] === 'secondary_verify') {
                        policy_target_succeed($t, 'HA secondary verified by read-back (not written)');
                        $stats['succeeded']++;
                        continue;
                    }
                    if ($deadline !== null && $now > $deadline - $margin) {
                        policy_target_rollback($t, 'Verified too close to the recovery deadline to confirm safely');
                        $stats['failed']++;
                        continue;
                    }
                    $q = queue_firewall_command((int)$t['firewall_id'], policy_build_confirm_script(platform_adapter($fw), (int)$id),
                        sprintf('Confirm template apply (deployment #%d)', $t['deployment_id']),
                        ['is_raw' => false, 'action' => 'policy_confirm', 'risk' => 'HIGH', 'parameters' => ['target_id' => (int)$id]]);
                    if (!$q['ok']) {
                        policy_target_rollback($t, 'Could not queue the confirm: ' . $q['error']);
                        $stats['failed']++;
                        continue;
                    }
                    policy_target_update((int)$id, ['status' => 'confirming', 'confirm_command_id' => $q['command_id'],
                        'detail' => 'Read-back verified; confirm queued']);
                    $stats['advanced']++;
                    continue;
                }

                if ($t['status'] === 'confirming') {
                    $cmd = policy_command_row((int)$t['confirm_command_id']);
                    $marker = policy_parse_marker_line($cmd['result'] ?? null, 'CONFIRM');
                    if ($marker === 'ok') {
                        policy_target_succeed($t, 'Applied, verified by read-back, and confirmed on the device');
                        $stats['succeeded']++;
                    } elseif ($marker !== null) {
                        policy_target_fail($t, 'rolled_back', "Confirm was too late ({$marker}); the device reverted to its saved configuration");
                        $stats['failed']++;
                    } elseif ($deadline !== null && $now > $deadline + 300) {
                        policy_target_fail($t, 'rolled_back', 'No confirm result; the device will have reverted at its deadline');
                        $stats['failed']++;
                    }
                }
            } catch (Throwable $e) {
                error_log('OPNMGR: policy_reconcile target ' . $id . ': ' . $e->getMessage());
            }
        }
        // Carry running deployments forward.
        foreach (db()->query('SELECT id FROM policy_deployments WHERE status = "running"')->fetchAll(PDO::FETCH_COLUMN) as $d) {
            policy_dispatch_next((int)$d);
        }
        return $stats;
    }
}

if (!function_exists('policy_target_rollback')) {
    /** Ask the device to revert now (it would at its deadline anyway) and fail the target. */
    function policy_target_rollback(array $t, string $why): void {
        $fw = platform_firewall((int)$t['firewall_id']);
        $q = $fw ? queue_firewall_command((int)$t['firewall_id'], policy_build_rollback_script(platform_adapter($fw), (int)$t['id']),
            sprintf('Roll back template apply (deployment #%d)', $t['deployment_id']),
            ['is_raw' => false, 'action' => 'policy_rollback', 'risk' => 'HIGH', 'parameters' => ['target_id' => (int)$t['id']]])
            : ['ok' => false, 'command_id' => null];
        if ($q['ok']) {
            policy_target_update((int)$t['id'], ['rollback_command_id' => $q['command_id']]);
        }
        policy_target_fail($t, 'rolled_back', $why . ($q['ok'] ? '; rollback queued' : '; the device reverts at its deadline'));
    }
}

if (!function_exists('policy_target_succeed')) {
    function policy_target_succeed(array $t, string $detail): void {
        policy_target_update((int)$t['id'], ['status' => 'succeeded', 'detail' => $detail, 'finished_at' => date('Y-m-d H:i:s'),
                                             'fetch_token' => null]);
        policy_lock_release((int)$t['firewall_id'], (int)$t['id']);
        db()->prepare('INSERT INTO policy_firewall_state (firewall_id, template_id, desired_revision_id, last_applied_revision_id,
                                                          last_applied_at, last_deployment_id, compliance, last_evaluated_at)
                       VALUES (?,?,?,?,NOW(),?,"compliant",NOW())
                       ON DUPLICATE KEY UPDATE last_applied_revision_id = VALUES(last_applied_revision_id),
                           last_applied_at = NOW(), last_deployment_id = VALUES(last_deployment_id),
                           compliance = "compliant", out_of_band_changes = 0, last_evaluated_at = NOW()')
            ->execute([(int)$t['firewall_id'], (int)$t['template_id'], (int)$t['revision_id'], (int)$t['revision_id'],
                       (int)$t['deployment_id']]);
        audit_log('policy.target.succeeded', ['actor_type' => 'system', 'object_type' => 'policy_target',
            'object_id' => (string)$t['id'], 'firewall_id' => (int)$t['firewall_id'], 'message' => $detail]);
    }
}
