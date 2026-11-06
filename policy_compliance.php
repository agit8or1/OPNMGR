<?php
/**
 * Template compliance: per firewall and template, the desired revision, the
 * last applied revision, the actual state of the managed objects, authorized
 * exceptions and out-of-band changes, next to the whole-config drift status.
 *
 * Nothing here corrects drift. A drifted firewall is brought back by planning
 * and approving a deployment, like any other change.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/policy/policy.php';

require_permission('template.view');

$h = fn($v) => htmlspecialchars((string)$v);
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = '<div class="alert alert-danger">Invalid CSRF token.</div>';
    } else {
        require_permission('template.manage');
        [$w, $p] = scope_sql('f.customer_id');
        $ids = isset($_POST['firewall_id']) ? [(int)$_POST['firewall_id']] : null;
        if ($ids === null) {
            $s = db()->prepare("SELECT f.id FROM firewalls f WHERE {$w}");
            $s->execute($p);
            $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        }
        $n = 0;
        foreach ($ids as $fid) {
            if (scope_can_firewall($fid)) { policy_evaluate_compliance($fid); $n++; }
        }
        $message = '<div class="alert alert-info">Evaluated ' . $n . ' firewall(s).</div>';
    }
}

$rows = policy_compliance_overview();
$counts = array_count_values(array_column($rows, 'compliance'));

include __DIR__ . '/inc/header.php';
?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h4 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Template Compliance</h4>
            <small class="text-muted">Re-plans each effective template against the newest backup. <em>Pending</em>: the desired revision has not been applied yet.
                <em>Drifted</em>: it was applied, and the managed objects have since been changed on the firewall (out of band). Nothing is corrected automatically.</small>
        </div>
        <?php if (can('template.manage')): ?>
        <form method="post"><input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>">
            <button class="btn btn-sm btn-outline-secondary"><i class="fas fa-rotate me-1"></i>Evaluate all</button></form>
        <?php endif; ?>
    </div>
    <?php echo $message; ?>

    <div class="row g-2 mb-3">
        <?php foreach (['compliant', 'pending', 'drifted', 'exception', 'conflict', 'invalid', 'unknown'] as $st): ?>
        <div class="col-6 col-md">
            <div class="card h-100"><div class="card-body py-2">
                <div class="text-muted small"><?php echo ucfirst($st); ?></div>
                <div class="fs-4"><span class="badge bg-<?php echo policy_compliance_badge($st); ?>"><?php echo (int)($counts[$st] ?? 0); ?></span></div>
            </div></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>Firewall</th><th>Customer / site</th><th>Template</th><th>Desired</th><th>Last applied</th>
                    <th>State</th><th>Out of band</th><th>Details</th><th>Config drift</th><th></th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4">Nothing evaluated yet. Assign a template, then evaluate.</td></tr><?php endif; ?>
                <?php foreach ($rows as $r): $d = json_decode((string)$r['detail'], true) ?: []; ?>
                <tr>
                    <td><a href="firewall_details.php?id=<?php echo (int)$r['firewall_id']; ?>"><?php echo $h($r['hostname']); ?></a>
                        <div class="small text-muted"><?php echo $h($r['platform_family'] ?? 'unknown'); ?> <?php echo $h($r['platform_os_version']); ?></div></td>
                    <td class="small"><?php echo $h($r['customer_name'] ?: 'Unassigned'); ?><?php if ($r['site_name']): ?> / <?php echo $h($r['site_name']); ?><?php endif; ?></td>
                    <td><a href="policy_template.php?id=<?php echo (int)$r['template_id']; ?>"><?php echo $h($r['template_name']); ?></a>
                        <?php if (!empty($d['scope'])): ?><div class="small text-muted">via <?php echo $h($d['scope']); ?></div><?php endif; ?></td>
                    <td><?php echo $r['desired_revision'] ? 'rev ' . (int)$r['desired_revision'] : '—'; ?></td>
                    <td><?php echo $r['applied_revision'] ? 'rev ' . (int)$r['applied_revision'] . '<div class="small text-muted">' . $h($r['last_applied_at']) . '</div>' : '—'; ?></td>
                    <td><span class="badge bg-<?php echo policy_compliance_badge($r['compliance']); ?>"><?php echo $h($r['compliance']); ?></span></td>
                    <td><?php echo (int)$r['out_of_band_changes'] ?: '—'; ?></td>
                    <td class="small">
                        <?php if (!empty($d['reason'])): ?><?php echo $h($d['reason']); ?><?php endif; ?>
                        <?php foreach (array_slice($d['changes'] ?? [], 0, 5) as $c): ?>
                            <div><span class="badge bg-secondary"><?php echo $h($c['op']); ?></span> <?php echo $h($c['kind'] . ' ' . $c['key']); ?></div>
                        <?php endforeach; ?>
                        <?php if (count($d['changes'] ?? []) > 5): ?><div class="text-muted">+<?php echo count($d['changes']) - 5; ?> more</div><?php endif; ?>
                        <?php foreach ($d['exceptions'] ?? [] as $e): ?>
                            <div class="text-muted">exception <code><?php echo $h($e['key']); ?></code> (<?php echo $h($e['owner']); ?><?php echo $e['expires_at'] ? ', until ' . $h($e['expires_at']) : ''; ?>)</div>
                        <?php endforeach; ?>
                        <?php foreach ($d['blockers'] ?? [] as $b): ?><div class="text-warning"><?php echo $h($b); ?></div><?php endforeach; ?>
                    </td>
                    <td><a class="small" href="config_drift.php?firewall=<?php echo (int)$r['firewall_id']; ?>"><?php echo $h($r['drift_status'] ?? 'unknown'); ?></a></td>
                    <td class="text-end text-nowrap">
                        <?php if (in_array($r['compliance'], ['pending', 'drifted'], true) && $r['desired_revision_id']): ?>
                        <a class="btn btn-sm btn-outline-primary py-0" href="policy_deploy.php?revision=<?php echo (int)$r['desired_revision_id']; ?>&preview=<?php echo (int)$r['firewall_id']; ?>">Preview fix</a>
                        <?php endif; ?>
                        <?php if (can('template.manage')): ?>
                        <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>">
                            <input type="hidden" name="firewall_id" value="<?php echo (int)$r['firewall_id']; ?>">
                            <button class="btn btn-sm btn-outline-secondary py-0" title="Re-evaluate"><i class="fas fa-rotate"></i></button></form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
