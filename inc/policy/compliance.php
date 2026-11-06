<?php
/**
 * OPNMGR policy templates: compliance and drift.
 *
 * Extends the existing drift view (inc/config_drift.php, which compares whole
 * configurations against an approved baseline) with the template dimension:
 *
 *   desired revision     what the assignment chain says this firewall should run
 *   last applied         what OPNMGR last applied and verified
 *   actual               a re-plan of the desired revision against the newest
 *                        backup: zero changes means the managed objects are
 *                        exactly as the template says
 *   exceptions           authorized deviations, shown, never "fixed"
 *   out-of-band changes  changes found when the applied revision IS the desired
 *                        one: someone edited a managed object on the firewall
 *
 * Nothing here writes to a firewall. Drift is reported; correcting it is a new,
 * previewed and approved deployment, never an automatic overwrite.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/deploy.php';

if (!function_exists('policy_evaluate_compliance')) {
    /**
     * Evaluate every effective template for one firewall and store the state.
     *
     * @return array<int, array> template_id => state row
     */
    function policy_evaluate_compliance(int $firewallId): array {
        $fw = platform_firewall($firewallId);
        if (!$fw) {
            return [];
        }
        $res = policy_resolve_for_firewall($fw);
        $out = [];
        $base = policy_base_config($firewallId);

        $store = function (int $tid, ?int $desired, string $state, int $oob, array $detail) use ($firewallId, $base, &$out) {
            db()->prepare('INSERT INTO policy_firewall_state (firewall_id, template_id, desired_revision_id, compliance,
                                                              evaluated_backup_id, out_of_band_changes, detail, last_evaluated_at)
                           VALUES (?,?,?,?,?,?,?,NOW())
                           ON DUPLICATE KEY UPDATE desired_revision_id = VALUES(desired_revision_id),
                               compliance = VALUES(compliance), evaluated_backup_id = VALUES(evaluated_backup_id),
                               out_of_band_changes = VALUES(out_of_band_changes), detail = VALUES(detail), last_evaluated_at = NOW()')
                ->execute([$firewallId, $tid, $desired, $state, $base['backup']['id'] ?? null, $oob,
                           json_encode(policy_redact($detail), JSON_UNESCAPED_SLASHES)]);
            $out[$tid] = ['compliance' => $state, 'desired_revision_id' => $desired, 'out_of_band_changes' => $oob] + $detail;
        };

        foreach ($res['conflicts'] as $c) {
            foreach ($c['template_ids'] as $tid) {
                $store($tid, null, 'conflict', 0, ['reason' => $c['reason']]);
            }
        }
        foreach ($res['invalid'] as $tid => $why) {
            $store($tid, null, 'invalid', 0, ['reason' => $why]);
        }
        foreach ($res['effective'] as $tid => $e) {
            $exc = policy_active_exceptions($firewallId, $tid);
            if (isset($exc['*'])) {
                $store($tid, $e['revision_id'], 'exception', 0, ['reason' => $exc['*']['reason'], 'owner' => $exc['*']['owner'],
                                                              'expires_at' => $exc['*']['expires_at']]);
                continue;
            }
            if (!$base['ok']) {
                $store($tid, $e['revision_id'], 'unknown', 0, ['reason' => $base['error']]);
                continue;
            }
            $c = policy_context($fw, $e['revision_id']);
            if (!$c['ok']) {
                $store($tid, $e['revision_id'], 'invalid', 0, ['reason' => implode('; ', $c['errors'])]);
                continue;
            }
            $plan = policy_plan($base['xml'], $c['ctx']);
            $st = db()->prepare('SELECT last_applied_revision_id FROM policy_firewall_state WHERE firewall_id = ? AND template_id = ?');
            $st->execute([$firewallId, $tid]);
            $applied = $st->fetchColumn();
            $applied = $applied === false || $applied === null ? null : (int)$applied;
            $detail = ['changes' => array_map(fn($ch) => ['op' => $ch['op'], 'kind' => $ch['kind'], 'key' => $ch['key'],
                                                            'identity' => $ch['identity']], $plan['changes']),
                       'blockers' => $plan['blockers'], 'errors' => $plan['errors'],
                       'exceptions' => array_values(array_map(fn($x) => ['key' => $x['object_key'], 'owner' => $x['owner'],
                                                                         'expires_at' => $x['expires_at']], $exc)),
                       'scope' => $e['scope_type'], 'last_applied_revision_id' => $applied];
            if ($plan['errors']) {
                $store($tid, $e['revision_id'], 'invalid', 0, $detail);
            } elseif ($plan['change_count'] === 0) {
                $store($tid, $e['revision_id'], $exc ? 'exception' : 'compliant', 0, $detail);
            } elseif ($applied === $e['revision_id']) {
                // The firewall was converged on this revision and has moved.
                $store($tid, $e['revision_id'], 'drifted', $plan['change_count'], $detail);
            } else {
                $store($tid, $e['revision_id'], 'pending', 0, $detail);
            }
        }
        return $out;
    }
}

if (!function_exists('policy_compliance_overview')) {
    /** Compliance rows for the firewalls the current user may see. */
    function policy_compliance_overview(): array {
        [$w, $p] = scope_sql('f.customer_id');
        $s = db()->prepare("SELECT s.*, f.hostname, f.platform_family, f.platform_edition, f.platform_os_version,
                                   c.name AS customer_name, si.name AS site_name, t.name AS template_name,
                                   rd.revision AS desired_revision, ra.revision AS applied_revision,
                                   d.status AS drift_status
                              FROM policy_firewall_state s
                              JOIN firewalls f ON f.id = s.firewall_id
                              JOIN policy_templates t ON t.id = s.template_id
                              LEFT JOIN customers c ON c.id = f.customer_id
                              LEFT JOIN sites si ON si.id = f.site_id
                              LEFT JOIN policy_template_revisions rd ON rd.id = s.desired_revision_id
                              LEFT JOIN policy_template_revisions ra ON ra.id = s.last_applied_revision_id
                              LEFT JOIN config_drift d ON d.firewall_id = f.id
                             WHERE {$w}
                             ORDER BY FIELD(s.compliance,'drifted','conflict','invalid','pending','unknown','exception','compliant'),
                                      f.hostname, t.name");
        $s->execute($p);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('policy_compliance_badge')) {
    function policy_compliance_badge(string $state): string {
        return match ($state) {
            'compliant' => 'success',
            'drifted'   => 'warning text-dark',
            'pending'   => 'info text-dark',
            'exception' => 'secondary',
            'conflict', 'invalid' => 'danger',
            default     => 'light text-dark',
        };
    }
}
