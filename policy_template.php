<?php
/**
 * One policy template: revisions and their lifecycle, the draft editor,
 * per-component compatibility, assignments, variables, exceptions and
 * explicit object mappings.
 *
 * The draft is edited as JSON on purpose: the component set is small and
 * typed, every field is validated server-side, and the JSON is exactly what
 * is hashed and frozen at publish time - there is no hidden translation step.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/policy/policy.php';
require_once __DIR__ . '/inc/customers.php';

require_permission('template.view');

$id = (int)($_GET['id'] ?? 0);
$tpl = policy_template_get($id);
if (!$tpl) {
    header('Location: policy_templates.php');
    exit;
}

$h = fn($v) => htmlspecialchars((string)$v);
$message = '';
$editorText = null;
$flash = function (bool $ok, string $good, $errors) use (&$message, $h) {
    $errors = (array)$errors;
    $message .= $ok ? '<div class="alert alert-success">' . $h($good) . '</div>'
                    : '<div class="alert alert-warning"><ul class="mb-0">' . implode('', array_map(fn($e) => '<li>' . $h($e) . '</li>', $errors)) . '</ul></div>';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = '<div class="alert alert-danger">Invalid CSRF token.</div>';
    } else {
        $a = (string)($_POST['action'] ?? '');
        $fwArg = (int)($_POST['firewall_id'] ?? 0);
        if ($fwArg > 0 && !scope_can_firewall($fwArg)) {
            $message = '<div class="alert alert-danger">That firewall is outside your customer scope.</div>';
            $a = '';
        }
        switch ($a) {
            case 'save_draft':
                require_permission('template.manage');
                $editorText = (string)($_POST['content'] ?? '');
                $decoded = json_decode($editorText, true);
                $r = $decoded === null ? ['ok' => false, 'errors' => ['Content is not valid JSON: ' . json_last_error_msg()]]
                                       : policy_revision_update_draft((int)$_POST['revision_id'], $decoded, (string)($_POST['notes'] ?? ''));
                $flash($r['ok'], 'Draft saved.', $r['errors']);
                if ($r['ok']) { $editorText = null; }
                break;
            case 'new_draft':
                require_permission('template.manage');
                $r = policy_revision_create_draft($id, null, (int)$_POST['from_revision_id'] ?: null);
                $flash($r['ok'], 'New draft created.', $r['error']);
                break;
            case 'publish':
                require_permission('template.publish');
                $r = policy_revision_publish((int)$_POST['revision_id']);
                $flash($r['ok'], 'Revision published. It can no longer be edited.', $r['errors']);
                break;
            case 'retire':
                require_permission('template.publish');
                $r = policy_revision_retire((int)$_POST['revision_id']);
                $flash($r['ok'], 'Revision retired.', $r['errors']);
                break;
            case 'assign':
                require_permission('template.manage');
                $st = (string)$_POST['scope_type'];
                $sid = (int)($_POST['scope_id_' . $st] ?? 0);
                if ($st === 'customer' && !scope_can_customer($sid)) { $flash(false, '', 'Customer outside your scope'); break; }
                if ($st === 'firewall' && !scope_can_firewall($sid)) { $flash(false, '', 'Firewall outside your scope'); break; }
                if ($st === 'global' && scope_customer_ids() !== null) { $flash(false, '', 'Only unrestricted staff can assign globally'); break; }
                $r = policy_assignment_create($id, (int)$_POST['revision_id'], $st, $sid ?: null);
                $flash($r['ok'], 'Assigned.', $r['error']);
                break;
            case 'unassign':
                require_permission('template.manage');
                $r = policy_assignment_remove((int)$_POST['assignment_id']);
                $flash($r['ok'], 'Assignment removed. Objects already on firewalls are left in place.', $r['error']);
                break;
            case 'set_var':
                require_permission('template.manage');
                $st = (string)$_POST['scope_type'];
                $sid = (int)($_POST['scope_id_' . $st] ?? 0);
                if ($st === 'firewall' && !scope_can_firewall($sid)) { $flash(false, '', 'Firewall outside your scope'); break; }
                $type = (string)($_POST['var_type'] ?? 'string');
                $r = policy_variable_set($st, $sid, isset($_POST['this_template']) ? $id : 0, (string)$_POST['name'], $type, (string)$_POST['value']);
                $flash($r['ok'], 'Variable saved.', $r['error']);
                break;
            case 'del_var':
                require_permission('template.manage');
                policy_variable_delete((string)$_POST['scope_type'], (int)$_POST['scope_id'], (int)$_POST['template_scope'], (string)$_POST['name']);
                $flash(true, 'Variable cleared.', []);
                break;
            case 'add_exception':
                require_permission('template.exception');
                $r = policy_exception_create($fwArg, $id, (string)$_POST['object_key'], (string)$_POST['reason'],
                                             (string)$_POST['owner'], (string)($_POST['expires_at'] ?? '') ?: null);
                $flash($r['ok'], 'Exception recorded.', $r['error']);
                break;
            case 'revoke_exception':
                require_permission('template.exception');
                $r = policy_exception_revoke((int)$_POST['exception_id']);
                $flash($r['ok'], 'Exception revoked.', $r['error']);
                break;
            case 'map':
                require_permission('template.manage');
                $r = policy_mapping_set($fwArg, $id, (string)$_POST['object_key'], (string)$_POST['target_ref']);
                $flash($r['ok'], 'Mapping saved. The next plan shows the adoption for approval.', $r['error']);
                break;
        }
    }
}

$revisions = policy_revisions_for($id);
$revId = (int)($_GET['rev'] ?? 0);
if (!$revId && $revisions) {
    $revId = (int)$revisions[0]['id'];
}
$rev = $revId ? policy_revision_get($revId) : null;
if ($rev && (int)$rev['template_id'] !== $id) {
    $rev = null;
}
$published = array_values(array_filter($revisions, fn($r) => $r['status'] === 'published'));
$content = $rev['content_decoded'] ?? ['variables' => [], 'components' => []];
$assignments = policy_assignment_list($id);
[$sw, $sp] = scope_sql('f.customer_id');
$fwStmt = db()->prepare("SELECT f.* FROM firewalls f WHERE {$sw} ORDER BY f.hostname");
$fwStmt->execute($sp);
$firewalls = $fwStmt->fetchAll(PDO::FETCH_ASSOC);
$customers = array_values(array_filter(customer_list(), fn($c) => scope_can_customer((int)$c['id'])));
$sites = array_values(array_filter(site_list(), fn($s) => scope_can_customer((int)$s['customer_id'])));
$varRows = db()->prepare('SELECT * FROM policy_variable_values WHERE template_id IN (0, ?) ORDER BY name, FIELD(scope_type,"global","customer","site","firewall")');
$varRows->execute([$id]);
$varRows = $varRows->fetchAll(PDO::FETCH_ASSOC);
$exceptions = array_values(array_filter(policy_exception_history(null, $id), fn($e) => scope_can_firewall((int)$e['firewall_id'])));
$mapRows = db()->prepare('SELECT m.*, f.hostname FROM policy_object_mappings m JOIN firewalls f ON f.id = m.firewall_id WHERE m.template_id = ?');
$mapRows->execute([$id]);
$mapRows = array_values(array_filter($mapRows->fetchAll(PDO::FETCH_ASSOC), fn($m) => scope_can_firewall((int)$m['firewall_id'])));

// Compatibility per component: per platform, and per assigned/visible target
// using the dialect of its newest configuration.
$compatTargets = [];
foreach (array_slice($firewalls, 0, 25) as $fw) {
    $fam = platform_adapter($fw)->family();
    $dialect = [];
    $b = policy_base_config((int)$fw['id']);
    if ($b['ok'] && ($doc = policy_dom_load($b['xml']))) {
        $dialect = policy_config_dialect($doc->documentElement);
    }
    $compatTargets[] = ['fw' => $fw, 'family' => $fam, 'dialect' => $dialect, 'has_config' => $b['ok']];
}
$catalog = policy_component_catalog();

include __DIR__ . '/inc/header.php';
?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <a href="policy_templates.php" class="small text-muted"><i class="fas fa-arrow-left me-1"></i>Templates</a>
            <h4 class="mb-0"><?php echo $h($tpl['name']); ?></h4>
            <?php if ($tpl['description']): ?><small class="text-muted"><?php echo $h($tpl['description']); ?></small><?php endif; ?>
        </div>
        <?php if ($published): ?>
        <a class="btn btn-sm btn-primary" href="policy_deploy.php?revision=<?php echo (int)$published[0]['id']; ?>">
            <i class="fas fa-eye me-1"></i>Preview / deploy rev <?php echo (int)$published[0]['revision']; ?></a>
        <?php endif; ?>
    </div>

    <?php echo $message; ?>

    <div class="row g-3">
        <div class="col-xl-3">
            <div class="card mb-3">
                <div class="card-header"><strong>Revisions</strong></div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($revisions as $r): ?>
                    <li class="list-group-item <?php echo (int)$r['id'] === $revId ? 'active' : ''; ?>">
                        <a class="<?php echo (int)$r['id'] === $revId ? 'text-reset' : ''; ?>" href="?id=<?php echo $id; ?>&rev=<?php echo (int)$r['id']; ?>">
                            rev <?php echo (int)$r['revision']; ?></a>
                        <span class="badge bg-<?php echo ['draft' => 'secondary', 'published' => 'success', 'retired' => 'dark'][$r['status']]; ?>">
                            <?php echo $h($r['status']); ?></span>
                        <div class="text-muted"><?php echo $h($r['published_at'] ?: $r['created_at']); ?> <?php echo $h($r['published_by'] ?: $r['created_by']); ?></div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (can('template.manage') && !array_filter($revisions, fn($r) => $r['status'] === 'draft')): ?>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="new_draft">
                        <input type="hidden" name="from_revision_id" value="<?php echo (int)$revId; ?>">
                        <button class="btn btn-sm btn-outline-primary w-100">New draft from rev <?php echo (int)($rev['revision'] ?? 0); ?></button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <div class="card">
                <div class="card-header"><strong>Reference</strong></div>
                <div class="card-body small">
                    <div class="fw-bold">Components</div>
                    <?php foreach ($catalog as $type => $c): ?>
                        <div><code><?php echo $h($type); ?></code>: <?php echo $h(implode(', ', array_keys($c['fields']))); ?></div>
                    <?php endforeach; ?>
                    <div class="fw-bold mt-2">Variable types</div>
                    <?php foreach (policy_variable_types() as $k => $label): ?><div><code><?php echo $h($k); ?></code> <?php echo $h($label); ?></div><?php endforeach; ?>
                    <div class="mt-2 text-muted">Reference a variable as <code>{{name}}</code>. Endpoints: <code>any</code>, <code>self</code>,
                        <code>net:&lt;interface&gt;</code>, an IP, a network-aligned CIDR or an alias name.</div>
                </div>
            </div>
        </div>

        <div class="col-xl-9">
            <?php if ($rev): ?>
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Revision <?php echo (int)$rev['revision']; ?> &middot; <?php echo $h($rev['status']); ?></strong>
                    <span class="small text-muted font-monospace" title="Content hash">sha256 <?php echo $h(substr($rev['content_hash'], 0, 16)); ?>…
                        <?php if (!$rev['integrity_ok']): ?><span class="badge bg-danger">integrity check failed</span><?php endif; ?></span>
                </div>
                <div class="card-body">
                    <?php if ($rev['status'] === 'draft' && can('template.manage')): ?>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>">
                            <input type="hidden" name="action" value="save_draft">
                            <input type="hidden" name="revision_id" value="<?php echo (int)$rev['id']; ?>">
                            <textarea class="form-control font-monospace small mb-2" name="content" rows="18" spellcheck="false"><?php
                                echo $h($editorText ?? json_encode($content ?: ['variables' => policy_well_known_variables(), 'components' => []],
                                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea>
                            <div class="d-flex gap-2">
                                <input class="form-control form-control-sm" name="notes" placeholder="Revision notes" value="<?php echo $h($rev['notes']); ?>">
                                <button class="btn btn-sm btn-primary text-nowrap">Save draft</button>
                            </div>
                        </form>
                        <?php if (can('template.publish')): ?>
                        <form method="post" class="mt-2" onsubmit="return confirm('Publish this revision? Published revisions are immutable.')">
                            <input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>">
                            <input type="hidden" name="action" value="publish">
                            <input type="hidden" name="revision_id" value="<?php echo (int)$rev['id']; ?>">
                            <button class="btn btn-sm btn-success"><i class="fas fa-lock me-1"></i>Publish</button>
                        </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <pre class="small mb-2"><?php echo $h(json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                        <?php if ($rev['status'] === 'published' && can('template.publish')): ?>
                        <form method="post" onsubmit="return confirm('Retire this revision? Assignments pinned to it become invalid.')">
                            <input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>">
                            <input type="hidden" name="action" value="retire">
                            <input type="hidden" name="revision_id" value="<?php echo (int)$rev['id']; ?>">
                            <button class="btn btn-sm btn-outline-danger">Retire</button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><strong>Compatibility</strong> <small class="text-muted">per component and target (dialect from each firewall's newest backup)</small></div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 small">
                        <thead><tr><th>Component</th><th>OPNsense</th><th>pfSense</th>
                            <?php foreach ($compatTargets as $ct): ?><th class="text-nowrap"><?php echo $h($ct['fw']['hostname']); ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                        <?php if (!$content['components']): ?><tr><td colspan="99" class="text-muted">No components.</td></tr><?php endif; ?>
                        <?php foreach ($content['components'] as $c): ?>
                        <tr>
                            <td><code><?php echo $h($c['key']); ?></code> <span class="text-muted"><?php echo $h($c['type']); ?></span></td>
                            <?php foreach (['opnsense', 'pfsense'] as $fam): $cc = policy_component_compat($c['type'], $fam); ?>
                                <td><span class="badge bg-<?php echo $cc['supported'] ? 'success' : 'danger'; ?>" title="<?php echo $h($cc['reason'] ?: $cc['target']); ?>">
                                    <?php echo $cc['supported'] ? $h($cc['target']) : 'unsupported'; ?></span></td>
                            <?php endforeach; ?>
                            <?php foreach ($compatTargets as $ct):
                                $cc = policy_component_compat($c['type'], $ct['family'], $ct['dialect']);
                                if ($c['type'] === 'remote_syslog' && $ct['family'] === 'pfsense' && ($c['transport'] ?? 'udp4') !== 'udp4') {
                                    $cc = ['supported' => false, 'reason' => 'pfSense remote syslog is UDP only', 'target' => ''];
                                } ?>
                                <td><?php if (!$ct['has_config'] && $cc['supported']): ?><span class="badge bg-secondary" title="No configuration backup yet">?</span>
                                    <?php else: ?><i class="fas <?php echo $cc['supported'] ? 'fa-check text-success' : 'fa-xmark text-danger'; ?>"
                                       title="<?php echo $h($cc['reason'] ?: $cc['target']); ?>"></i><?php endif; ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <div class="card mb-3">
                <div class="card-header"><strong>Assignments</strong></div>
                <div class="card-body">
                    <table class="table table-sm small mb-2">
                        <thead><tr><th>Scope</th><th>Target</th><th>Revision</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($assignments as $a): ?>
                        <tr>
                            <td><span class="badge bg-secondary"><?php echo $h($a['scope_type']); ?></span></td>
                            <td><?php echo $h($a['customer_name'] ?? $a['site_name'] ?? $a['firewall_hostname'] ?? 'all firewalls'); ?></td>
                            <td>rev <?php echo (int)$a['revision']; ?> <?php if ($a['revision_status'] !== 'published'): ?><span class="badge bg-danger"><?php echo $h($a['revision_status']); ?></span><?php endif; ?></td>
                            <td class="text-end"><?php if (can('template.manage')): ?>
                                <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="unassign"><input type="hidden" name="assignment_id" value="<?php echo (int)$a['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger py-0">Remove</button></form><?php endif; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (can('template.manage') && $published): ?>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="assign">
                        <div class="col-md-2"><label class="form-label small">Revision</label>
                            <select name="revision_id" class="form-select form-select-sm"><?php foreach ($published as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>">rev <?php echo (int)$p['revision']; ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><label class="form-label small">Scope</label>
                            <select name="scope_type" class="form-select form-select-sm">
                                <option value="global">global</option><option value="customer">customer</option>
                                <option value="site">site</option><option value="firewall">firewall</option></select></div>
                        <div class="col-md-6"><label class="form-label small">Customer / site / firewall (for the chosen scope)</label>
                            <div class="d-flex gap-1">
                                <select name="scope_id_customer" class="form-select form-select-sm"><option value="">customer…</option>
                                    <?php foreach ($customers as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo $h($c['name']); ?></option><?php endforeach; ?></select>
                                <select name="scope_id_site" class="form-select form-select-sm"><option value="">site…</option>
                                    <?php foreach ($sites as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo $h(($s['customer_name'] ?? '') . ' / ' . $s['name']); ?></option><?php endforeach; ?></select>
                                <select name="scope_id_firewall" class="form-select form-select-sm"><option value="">firewall…</option>
                                    <?php foreach ($firewalls as $f): ?><option value="<?php echo (int)$f['id']; ?>"><?php echo $h($f['hostname']); ?></option><?php endforeach; ?></select>
                            </div></div>
                        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Assign</button></div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><strong>Variable values</strong>
                    <small class="text-muted ms-2">Inherited global &lt; customer &lt; site &lt; firewall; a value for this template beats a value for all templates at the same scope.</small></div>
                <div class="card-body">
                    <table class="table table-sm small mb-2">
                        <thead><tr><th>Name</th><th>Scope</th><th>Applies to</th><th>Value</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($varRows as $v): if ($v['scope_type'] === 'firewall' && !scope_can_firewall((int)$v['scope_id'])) { continue; } ?>
                        <tr>
                            <td><code><?php echo $h($v['name']); ?></code></td>
                            <td><?php echo $h($v['scope_type'] . ($v['scope_type'] === 'global' ? '' : ' #' . $v['scope_id'])); ?></td>
                            <td><?php echo (int)$v['template_id'] ? 'this template' : 'all templates'; ?></td>
                            <td class="font-monospace"><?php echo $h(implode(', ', (array)json_decode($v['value'], true))); ?></td>
                            <td class="text-end"><?php if (can('template.manage')): ?>
                                <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="del_var"><input type="hidden" name="scope_type" value="<?php echo $h($v['scope_type']); ?>">
                                    <input type="hidden" name="scope_id" value="<?php echo (int)$v['scope_id']; ?>"><input type="hidden" name="template_scope" value="<?php echo (int)$v['template_id']; ?>">
                                    <input type="hidden" name="name" value="<?php echo $h($v['name']); ?>"><button class="btn btn-sm btn-outline-danger py-0">Clear</button></form><?php endif; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (can('template.manage') && $content['variables']): ?>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="set_var">
                        <div class="col-md-2"><label class="form-label small">Variable</label>
                            <select name="name" class="form-select form-select-sm" onchange="this.form.var_type.value=this.selectedOptions[0].dataset.type">
                                <?php foreach ($content['variables'] as $v): ?><option value="<?php echo $h($v['name']); ?>" data-type="<?php echo $h($v['type']); ?>"><?php echo $h($v['name'] . ' (' . $v['type'] . ')'); ?></option><?php endforeach; ?></select>
                            <input type="hidden" name="var_type" value="<?php echo $h($content['variables'][0]['type']); ?>"></div>
                        <div class="col-md-2"><label class="form-label small">Scope</label>
                            <select name="scope_type" class="form-select form-select-sm"><option>global</option><option>customer</option><option>site</option><option>firewall</option></select></div>
                        <div class="col-md-3"><label class="form-label small">Which</label>
                            <div class="d-flex gap-1">
                                <select name="scope_id_customer" class="form-select form-select-sm"><option value="0">customer…</option>
                                    <?php foreach ($customers as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo $h($c['name']); ?></option><?php endforeach; ?></select>
                                <select name="scope_id_site" class="form-select form-select-sm"><option value="0">site…</option>
                                    <?php foreach ($sites as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo $h($s['name']); ?></option><?php endforeach; ?></select>
                                <select name="scope_id_firewall" class="form-select form-select-sm"><option value="0">fw…</option>
                                    <?php foreach ($firewalls as $f): ?><option value="<?php echo (int)$f['id']; ?>"><?php echo $h($f['hostname']); ?></option><?php endforeach; ?></select>
                            </div></div>
                        <div class="col-md-3"><label class="form-label small">Value (lists: comma separated)</label>
                            <input name="value" class="form-control form-control-sm" required></div>
                        <div class="col-md-1"><div class="form-check small"><input class="form-check-input" type="checkbox" name="this_template" id="tt" checked>
                            <label class="form-check-label" for="tt">this tpl</label></div></div>
                        <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Set</button></div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="card mb-3">
                        <div class="card-header"><strong>Exceptions</strong> <small class="text-muted">waive one object (or * for all) on one firewall</small></div>
                        <div class="card-body">
                            <table class="table table-sm small mb-2">
                                <thead><tr><th>Firewall</th><th>Object</th><th>Reason / owner</th><th>Expires</th><th>State</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($exceptions as $e): ?>
                                <tr>
                                    <td><?php echo $h($e['hostname']); ?></td><td><code><?php echo $h($e['object_key']); ?></code></td>
                                    <td><?php echo $h($e['reason']); ?><div class="text-muted"><?php echo $h($e['owner']); ?> · by <?php echo $h($e['created_by']); ?></div></td>
                                    <td><?php echo $h($e['expires_at'] ?: 'never'); ?></td>
                                    <td><span class="badge bg-<?php echo $e['state'] === 'active' ? 'warning text-dark' : 'secondary'; ?>"><?php echo $h($e['state']); ?></span></td>
                                    <td><?php if ($e['state'] === 'active' && can('template.exception')): ?>
                                        <form method="post"><input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="revoke_exception"><input type="hidden" name="exception_id" value="<?php echo (int)$e['id']; ?>">
                                            <button class="btn btn-sm btn-outline-danger py-0">Revoke</button></form><?php endif; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (can('template.exception')): ?>
                            <form method="post" class="row g-1">
                                <input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>">
                                <input type="hidden" name="action" value="add_exception">
                                <div class="col-md-4"><select name="firewall_id" class="form-select form-select-sm">
                                    <?php foreach ($firewalls as $f): ?><option value="<?php echo (int)$f['id']; ?>"><?php echo $h($f['hostname']); ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-3"><select name="object_key" class="form-select form-select-sm"><option value="*">* (whole template)</option>
                                    <?php foreach ($content['components'] as $c): ?><option><?php echo $h($c['key']); ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-5"><input name="owner" class="form-control form-control-sm" placeholder="Owner" required></div>
                                <div class="col-md-8"><input name="reason" class="form-control form-control-sm" placeholder="Reason" required></div>
                                <div class="col-md-4"><input name="expires_at" type="datetime-local" class="form-control form-control-sm" title="Optional expiry"></div>
                                <div class="col-12"><button class="btn btn-sm btn-warning">Record exception</button></div>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card mb-3">
                        <div class="card-header"><strong>Adopt existing objects</strong></div>
                        <div class="card-body small">
                            <p class="text-muted">Unmanaged objects are never taken over implicitly. Map a template object to an existing alias name,
                                rule uuid / pfSense tracker or syslog destination uuid; the next plan shows the adoption for approval.</p>
                            <?php foreach ($mapRows as $m): ?>
                                <div><?php echo $h($m['hostname']); ?>: <code><?php echo $h($m['object_key']); ?></code> &rarr; <code><?php echo $h($m['target_ref']); ?></code></div>
                            <?php endforeach; ?>
                            <?php if (can('template.manage')): ?>
                            <form method="post" class="row g-1 mt-2">
                                <input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>">
                                <input type="hidden" name="action" value="map">
                                <div class="col-12"><select name="firewall_id" class="form-select form-select-sm">
                                    <?php foreach ($firewalls as $f): ?><option value="<?php echo (int)$f['id']; ?>"><?php echo $h($f['hostname']); ?></option><?php endforeach; ?></select></div>
                                <div class="col-6"><input name="object_key" class="form-control form-control-sm" placeholder="object key"></div>
                                <div class="col-6"><input name="target_ref" class="form-control form-control-sm" placeholder="existing name / uuid"></div>
                                <div class="col-12"><button class="btn btn-sm btn-outline-primary">Map</button></div>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
