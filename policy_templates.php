<?php
/**
 * Shared policies and configuration templates: overview.
 *
 * Lists templates, every assignment with its precedence, and recent
 * deployments. Creating a template here creates its first draft; everything
 * else happens on policy_template.php.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/policy/policy.php';

require_permission('template.view');

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = '<div class="alert alert-danger">Invalid CSRF token.</div>';
    } elseif (isset($_POST['create'])) {
        require_permission('template.manage');
        $r = policy_template_create((string)($_POST['name'] ?? ''), (string)($_POST['description'] ?? ''));
        if ($r['ok']) {
            header('Location: policy_template.php?id=' . $r['id']);
            exit;
        }
        $message = '<div class="alert alert-warning">' . htmlspecialchars($r['error']) . '</div>';
    }
}

$templates = policy_template_list();
$assignments = policy_assignment_list();
$deployments = db()->query('SELECT d.*, t.name AS template_name, r.revision,
        (SELECT COUNT(*) FROM policy_deployment_targets x WHERE x.deployment_id = d.id) AS targets
      FROM policy_deployments d JOIN policy_templates t ON t.id = d.template_id
      JOIN policy_template_revisions r ON r.id = d.revision_id ORDER BY d.id DESC LIMIT 15')->fetchAll(PDO::FETCH_ASSOC);
$labIds = platform_lab_firewall_ids();

include __DIR__ . '/inc/header.php';
?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h4 class="mb-0"><i class="fas fa-layer-group me-2"></i>Policy Templates</h4>
            <small class="text-muted">Versioned aliases, rules, DNS/NTP, management access and syslog, assigned globally or per
                customer, site or firewall. Every change is previewed as a concrete per-firewall plan before approval.</small>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="policy_compliance.php"><i class="fas fa-clipboard-check me-1"></i>Compliance</a>
            <a class="btn btn-sm btn-outline-secondary" href="policy_deploy.php"><i class="fas fa-rocket me-1"></i>Deployments</a>
        </div>
    </div>

    <?php echo $message; ?>

    <div class="alert alert-info small">
        <i class="fas fa-shield-halved me-1"></i>
        <strong>Application is preview-only by default.</strong>
        Writing templates to firewalls (<code>policy.apply</code>) has not been validated on a live OPNsense or pfSense device,
        so it is only enabled on designated lab firewalls (setting <code>policy_apply_lab_firewall_ids</code>,
        currently <?php echo $labIds ? htmlspecialchars(implode(', ', $labIds)) : 'none'; ?>).
        Previews, plans and compliance work on every firewall whose configuration parses.
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header"><strong>Templates</strong></div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>Name</th><th>Published</th><th>Latest</th><th>Assignments</th><th></th></tr></thead>
                        <tbody>
                        <?php if (!$templates): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No templates yet.</td></tr>
                        <?php else: foreach ($templates as $t): ?>
                            <tr>
                                <td><a href="policy_template.php?id=<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></a>
                                    <?php if ($t['description']): ?><div class="small text-muted"><?php echo htmlspecialchars(substr($t['description'], 0, 120)); ?></div><?php endif; ?>
                                </td>
                                <td><?php echo $t['published_revision'] ? 'rev ' . (int)$t['published_revision'] : '<span class="text-muted">—</span>'; ?></td>
                                <td>rev <?php echo (int)$t['latest_revision']; ?></td>
                                <td><?php echo (int)$t['assignment_count']; ?></td>
                                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="policy_template.php?id=<?php echo (int)$t['id']; ?>">Open</a></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><strong>Assignments</strong>
                    <small class="text-muted ms-2">Precedence: global &lt; customer &lt; site &lt; firewall. Conflicts at the same scope are reported, never resolved silently.</small>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Template</th><th>Revision</th><th>Scope</th><th>Target</th></tr></thead>
                        <tbody>
                        <?php if (!$assignments): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">No assignments.</td></tr>
                        <?php else: foreach ($assignments as $a): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($a['template_name']); ?></td>
                                <td>rev <?php echo (int)$a['revision']; ?>
                                    <?php if ($a['revision_status'] !== 'published'): ?><span class="badge bg-danger"><?php echo htmlspecialchars($a['revision_status']); ?></span><?php endif; ?></td>
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($a['scope_type']); ?></span></td>
                                <td><?php echo htmlspecialchars($a['customer_name'] ?? $a['site_name'] ?? $a['firewall_hostname'] ?? 'all firewalls'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <?php if (can('template.manage')): ?>
            <div class="card mb-3">
                <div class="card-header"><strong>New template</strong></div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input class="form-control form-control-sm mb-2" name="name" placeholder="Name" required maxlength="128">
                        <textarea class="form-control form-control-sm mb-2" name="description" rows="2" placeholder="Description"></textarea>
                        <button class="btn btn-sm btn-primary" name="create"><i class="fas fa-plus me-1"></i>Create draft</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            <div class="card">
                <div class="card-header"><strong>Recent deployments</strong></div>
                <ul class="list-group list-group-flush small">
                    <?php if (!$deployments): ?><li class="list-group-item text-muted">None yet.</li><?php endif; ?>
                    <?php foreach ($deployments as $d): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <a href="policy_deploy.php?id=<?php echo (int)$d['id']; ?>">#<?php echo (int)$d['id']; ?>
                            <?php echo htmlspecialchars($d['template_name']); ?> rev <?php echo (int)$d['revision']; ?></a>
                        <span><span class="badge bg-secondary"><?php echo htmlspecialchars($d['status']); ?></span>
                            <?php echo (int)$d['targets']; ?> fw</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
