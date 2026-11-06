<?php
/**
 * Policy deployments: select a revision and targets, review each firewall's
 * concrete plan, approve it, run it.
 *
 *   ?revision=R            choose targets (with a quick preview per firewall)
 *   ?revision=R&preview=F  one firewall's plan without creating anything
 *   ?id=D                  a deployment: plans, blockers, approval, progress
 *   (none)                 list of deployments
 *
 * Every plan shows exactly which objects are created, modified (field by
 * field), adopted, reordered or removed, what is unsupported and what an
 * exception skips. Approval binds that plan; if the firewall's configuration
 * changes before execution the approval is voided and the plan is shown again.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/policy/policy.php';

require_permission('template.view');

$h = fn($v) => htmlspecialchars((string)$v);
$message = '';
$depId = (int)($_GET['id'] ?? 0);
$revisionId = (int)($_GET['revision'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = '<div class="alert alert-danger">Invalid CSRF token.</div>';
    } else {
        $a = (string)($_POST['action'] ?? '');
        $out = null;
        switch ($a) {
            case 'plan':
                require_permission('template.manage');
                $r = policy_deployment_create((int)$_POST['revision_id'], (array)($_POST['firewalls'] ?? []), (string)($_POST['notes'] ?? ''));
                if ($r['ok']) { header('Location: policy_deploy.php?id=' . $r['id']); exit; }
                $out = $r;
                break;
            case 'approve':
                require_permission('template.approve');
                $out = policy_deployment_approve($depId);
                break;
            case 'execute':
                require_permission('template.apply');
                $out = policy_deployment_execute($depId);
                break;
            case 'cancel':
                require_permission('template.manage');
                $out = policy_deployment_cancel($depId);
                break;
            case 'replan':
                require_permission('template.manage');
                $t = policy_target_get((int)$_POST['target_id']);
                $out = ($t && (int)$t['deployment_id'] === $depId && scope_can_firewall((int)$t['firewall_id']))
                     ? policy_replan_target((int)$t['id']) : ['ok' => false, 'error' => 'Target not found'];
                break;
            case 'fresh_backup':
                require_permission('template.manage');
                $fid = (int)$_POST['firewall_id'];
                $out = scope_can_firewall($fid) ? policy_request_backup($fid, 'Fresh backup for a policy plan')
                                                : ['ok' => false, 'error' => 'Outside your scope'];
                if ($out['ok']) { $out['error'] = ''; $out['msg'] = 'Backup requested. Re-plan once it has arrived (next agent check-in).'; }
                break;
        }
        if ($out !== null) {
            $message = ($out['ok'] ?? false)
                ? '<div class="alert alert-success">' . $h($out['msg'] ?? 'Done.') . '</div>'
                : '<div class="alert alert-warning">' . $h($out['error'] ?? 'Failed') . '</div>';
        }
    }
}

/** One plan, rendered. */
function render_plan(array $plan, array $blockers, callable $h): string {
    $o = '';
    if ($blockers) {
        $o .= '<div class="alert alert-warning small py-2 mb-2"><strong>Not approvable:</strong><ul class="mb-0">'
            . implode('', array_map(fn($b) => '<li>' . $h($b) . '</li>', $blockers)) . '</ul></div>';
    }
    foreach ($plan['warnings'] ?? [] as $w) {
        $o .= '<div class="small text-warning"><i class="fas fa-triangle-exclamation me-1"></i>' . $h($w) . '</div>';
    }
    if (!empty($plan['skipped'])) {
        $o .= '<div class="small text-muted mb-1">Skipped by exception: ' . implode(', ', array_map(fn($s) =>
            '<code>' . $h($s['key']) . '</code> (' . $h($s['reason']) . ', ' . $h($s['owner']) . ')', $plan['skipped'])) . '</div>';
    }
    if (!empty($plan['unsupported'])) {
        $o .= '<div class="small text-danger mb-1">Unsupported here: ' . implode(', ', array_map(fn($u) =>
            '<code>' . $h($u['key']) . '</code> ' . $h($u['reason']), $plan['unsupported'])) . '</div>';
    }
    if (empty($plan['changes'])) {
        return $o . '<div class="small text-muted">No changes.</div>';
    }
    $o .= '<table class="table table-sm small mb-0"><thead><tr><th>Change</th><th>Object</th><th>Details</th></tr></thead><tbody>';
    foreach ($plan['changes'] as $c) {
        $badge = ['create' => 'success', 'modify' => 'warning text-dark', 'adopt' => 'info text-dark', 'delete' => 'danger',
                  'reorder' => 'secondary'][$c['op']] ?? 'secondary';
        $details = '';
        if (!empty($c['fields'])) {
            foreach ($c['fields'] as $f => [$from, $to]) {
                $details .= '<div><code>' . $h($f) . '</code>: <span class="text-danger">' . $h($from ?? '∅') . '</span> &rarr; <span class="text-success">'
                          . $h($to ?? '∅') . '</span></div>';
            }
        } elseif (!empty($c['object'])) {
            $details = '<span class="font-monospace">' . $h(json_encode($c['object'], JSON_UNESCAPED_SLASHES)) . '</span>';
        }
        $o .= '<tr><td><span class="badge bg-' . $badge . '">' . $h($c['op']) . '</span></td><td>' . $h($c['kind']) . ' <code>' . $h($c['key'])
            . '</code><div class="text-muted">' . $h($c['identity']) . '</div></td><td>' . $details . '</td></tr>';
    }
    return $o . '</tbody></table>';
}

[$sw, $sp] = scope_sql('f.customer_id');

include __DIR__ . '/inc/header.php';
?>
<div class="container-fluid">
<?php echo $message; ?>
<?php if ($depId > 0 && ($dep = policy_deployment_get($depId))):
    $dep['targets'] = array_values(array_filter($dep['targets'], fn($t) => scope_can_firewall((int)$t['firewall_id'])));
    $approvable = array_filter($dep['targets'], fn($t) => $t['status'] === 'planned' && (int)$t['approvable'] === 1); ?>
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <a href="policy_deploy.php" class="small text-muted"><i class="fas fa-arrow-left me-1"></i>Deployments</a>
            <h4 class="mb-0">Deployment #<?php echo (int)$dep['id']; ?>: <?php echo $h($dep['template_name']); ?> rev <?php echo (int)$dep['revision']; ?>
                <span class="badge bg-secondary"><?php echo $h($dep['status']); ?></span></h4>
            <small class="text-muted">Planned by <?php echo $h($dep['created_by']); ?> <?php echo $h($dep['created_at']); ?>
                <?php if ($dep['approved_by']): ?> · approved by <?php echo $h($dep['approved_by']); ?> <?php echo $h($dep['approved_at']); ?><?php endif; ?>
                <?php if ($dep['detail']): ?><br><?php echo $h($dep['detail']); ?><?php endif; ?></small>
        </div>
        <div class="d-flex gap-2">
            <?php if ($dep['status'] === 'planned' && $approvable && can('template.approve')): ?>
            <form method="post" onsubmit="return confirm('Approve these exact plans? The approval is bound to each plan and configuration fingerprint.')">
                <input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>"><input type="hidden" name="action" value="approve">
                <button class="btn btn-sm btn-warning"><i class="fas fa-stamp me-1"></i>Approve <?php echo count($approvable); ?> plan(s)</button></form>
            <?php endif; ?>
            <?php if ($dep['status'] === 'approved' && can('template.apply')): ?>
            <form method="post" onsubmit="return confirm('Apply to the approved firewalls, one at a time in ring order? Each is re-checked first.')">
                <input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>"><input type="hidden" name="action" value="execute">
                <button class="btn btn-sm btn-danger"><i class="fas fa-play me-1"></i>Apply</button></form>
            <?php endif; ?>
            <?php if (in_array($dep['status'], ['planned', 'approved', 'running', 'stopped'], true) && can('template.manage')): ?>
            <form method="post"><input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>"><input type="hidden" name="action" value="cancel">
                <button class="btn btn-sm btn-outline-secondary">Cancel</button></form>
            <?php endif; ?>
        </div>
    </div>

    <?php foreach ($dep['targets'] as $t):
        $plan = json_decode((string)$t['plan_json'], true) ?: [];
        $blockers = json_decode((string)$t['blockers'], true) ?: [];
        $fw = platform_firewall((int)$t['firewall_id']) ?? []; ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <strong><?php echo (int)$t['order_index'] + 1; ?>. <?php echo $h($t['hostname']); ?></strong>
                <span class="text-muted small"><?php echo $h(platform_summary($fw)); ?> · ring <?php echo $h($t['ring']); ?></span>
                <?php if ($t['ha_role'] !== 'standalone'): ?><span class="badge bg-info text-dark">HA <?php echo $h($t['ha_role']); ?></span><?php endif; ?>
                <span class="badge bg-secondary"><?php echo $h($t['status']); ?></span>
                <span class="small text-muted"><?php echo (int)$t['change_count']; ?> change(s)</span>
            </div>
            <div class="d-flex gap-1">
                <?php if (in_array($t['status'], ['stale', 'blocked', 'planned'], true) && can('template.manage')): ?>
                <form method="post"><input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>"><input type="hidden" name="action" value="fresh_backup">
                    <input type="hidden" name="firewall_id" value="<?php echo (int)$t['firewall_id']; ?>">
                    <button class="btn btn-sm btn-outline-secondary py-0">Request fresh backup</button></form>
                <form method="post"><input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>"><input type="hidden" name="action" value="replan">
                    <input type="hidden" name="target_id" value="<?php echo (int)$t['id']; ?>">
                    <button class="btn btn-sm btn-outline-primary py-0">Re-plan</button></form>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <?php if ($t['detail']): ?><div class="small mb-2"><?php echo $h($t['detail']); ?></div><?php endif; ?>
            <?php if ($t['ha_role'] === 'secondary_verify'): ?>
                <div class="small text-info mb-2">Not written: this member receives the synced sections from its HA primary and is verified by read-back.</div>
            <?php endif; ?>
            <div class="small text-muted mb-2 font-monospace">base backup #<?php echo (int)$t['base_backup_id']; ?>
                · fingerprint <?php echo $h(substr((string)$t['base_fingerprint'], 0, 16)); ?>
                · plan <?php echo $h(substr((string)$t['plan_hash'], 0, 16)); ?>
                <?php if ($t['approval_hash']): ?> · approved by <?php echo $h($t['approved_by']); ?><?php endif; ?>
                <?php if ($t['recovery_deadline']): ?> · recovery deadline <?php echo $h($t['recovery_deadline']); ?><?php endif; ?></div>
            <?php echo render_plan($plan, $blockers, $h); ?>
        </div>
    </div>
    <?php endforeach; ?>

<?php elseif ($revisionId > 0 && ($rev = policy_revision_get($revisionId))):
    $tpl = policy_template_get((int)$rev['template_id']);
    $fwStmt = db()->prepare("SELECT f.*, c.name AS customer_name FROM firewalls f LEFT JOIN customers c ON c.id = f.customer_id
                              WHERE {$sw} ORDER BY FIELD(f.update_ring,'canary','pilot','production'), f.hostname");
    $fwStmt->execute($sp);
    $fws = $fwStmt->fetchAll(PDO::FETCH_ASSOC);
    $previewId = (int)($_GET['preview'] ?? 0); ?>
    <a href="policy_template.php?id=<?php echo (int)$rev['template_id']; ?>" class="small text-muted"><i class="fas fa-arrow-left me-1"></i><?php echo $h($tpl['name']); ?></a>
    <h4>Deploy <?php echo $h($tpl['name']); ?> rev <?php echo (int)$rev['revision']; ?>
        <span class="badge bg-<?php echo $rev['status'] === 'published' ? 'success' : 'secondary'; ?>"><?php echo $h($rev['status']); ?></span></h4>

    <?php if ($previewId && scope_can_firewall($previewId) && ($pfw = platform_firewall($previewId))):
        $pv = policy_preview_firewall($pfw, $revisionId); ?>
    <div class="card mb-3">
        <div class="card-header"><strong>Preview: <?php echo $h($pfw['hostname']); ?></strong>
            <span class="small text-muted"><?php echo $h(platform_summary($pfw)); ?> ·
            base <?php echo $pv['base']['ok'] ? 'backup #' . (int)$pv['base']['backup']['id'] . ', ' . (int)$pv['base']['age_minutes'] . ' min old' : $h($pv['base']['error']); ?></span></div>
        <div class="card-body">
            <?php if ($pv['variable_sources']): ?><div class="small text-muted mb-2">Variables from: <?php
                echo implode(', ', array_map(fn($k, $v) => $h($k) . ' &larr; ' . $h($v), array_keys($pv['variable_sources']), $pv['variable_sources'])); ?></div><?php endif; ?>
            <?php echo render_plan($pv['plan'], $pv['blockers'], $h); ?>
        </div>
    </div>
    <?php endif; ?>

    <form method="post" class="card">
        <input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>">
        <input type="hidden" name="action" value="plan">
        <input type="hidden" name="revision_id" value="<?php echo (int)$revisionId; ?>">
        <div class="card-header"><strong>Targets</strong> <small class="text-muted">Plans are generated from each firewall's newest validated backup.
            Targets run one at a time in ring order; the first failure stops the rest.</small></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small">
                <thead><tr><th></th><th>Firewall</th><th>Customer</th><th>Platform</th><th>Ring</th><th>Template application</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($fws as $f): $allow = platform_capability_allows($f, 'policy.apply'); ?>
                <tr>
                    <td><input type="checkbox" class="form-check-input" name="firewalls[]" value="<?php echo (int)$f['id']; ?>"></td>
                    <td><?php echo $h($f['hostname']); ?></td>
                    <td><?php echo $h($f['customer_name'] ?? '—'); ?></td>
                    <td><?php echo $h(platform_summary($f)); ?></td>
                    <td><?php echo $h($f['update_ring']); ?></td>
                    <td><?php echo $allow['allowed'] ? '<span class="badge bg-warning text-dark">lab: allowed</span>'
                        : '<span class="badge bg-secondary" title="' . $h($allow['reason']) . '">preview only</span>'; ?></td>
                    <td><a class="btn btn-sm btn-outline-primary py-0" href="?revision=<?php echo (int)$revisionId; ?>&preview=<?php echo (int)$f['id']; ?>">Preview</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (can('template.manage') && $rev['status'] === 'published'): ?>
        <div class="card-body d-flex gap-2">
            <input class="form-control form-control-sm" name="notes" placeholder="Change notes / ticket">
            <button class="btn btn-sm btn-primary text-nowrap"><i class="fas fa-list-check me-1"></i>Generate plans</button>
        </div>
        <?php endif; ?>
    </form>

<?php else:
    $rows = db()->prepare("SELECT d.*, t.name AS template_name, r.revision,
            (SELECT COUNT(*) FROM policy_deployment_targets x WHERE x.deployment_id = d.id) AS targets,
            (SELECT COUNT(*) FROM policy_deployment_targets x WHERE x.deployment_id = d.id AND x.status = 'succeeded') AS ok_targets
          FROM policy_deployments d JOIN policy_templates t ON t.id = d.template_id
          JOIN policy_template_revisions r ON r.id = d.revision_id
         WHERE EXISTS (SELECT 1 FROM policy_deployment_targets x JOIN firewalls f ON f.id = x.firewall_id
                        WHERE x.deployment_id = d.id AND {$sw})
         ORDER BY d.id DESC LIMIT 200");
    $rows->execute($sp);
    $rows = $rows->fetchAll(PDO::FETCH_ASSOC); ?>
    <h4><i class="fas fa-rocket me-2"></i>Policy deployments</h4>
    <div class="card"><div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>#</th><th>Template</th><th>Status</th><th>Targets</th><th>Planned</th><th>Approved</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No deployments yet. Open a template to plan one.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><a href="?id=<?php echo (int)$r['id']; ?>">#<?php echo (int)$r['id']; ?></a></td>
                <td><?php echo $h($r['template_name']); ?> rev <?php echo (int)$r['revision']; ?></td>
                <td><span class="badge bg-secondary"><?php echo $h($r['status']); ?></span></td>
                <td><?php echo (int)$r['ok_targets']; ?> / <?php echo (int)$r['targets']; ?></td>
                <td class="small"><?php echo $h($r['created_at']); ?> <?php echo $h($r['created_by']); ?></td>
                <td class="small"><?php echo $h($r['approved_at']); ?> <?php echo $h($r['approved_by']); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
<?php endif; ?>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
