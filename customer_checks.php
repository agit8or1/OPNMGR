<?php
/**
 * Customer checks: probes on customer networks and the paths they test.
 *
 * Each probe belongs to one customer and sits behind one of that customer's
 * firewalls (covering its CARP partner too). An administrator approves the
 * exact checks it may run - internal DNS, a service across the VPN, an
 * internet service - and update campaigns that opt in use the results to hold
 * promotion when a client path breaks.
 *
 * @since 3.81.0
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/customer_checks.php';
require_once __DIR__ . '/inc/scope.php';

require_permission('customer.view');

$canManage = can('customer.manage');
$message = '';
$newToken = null;

$scoped = scope_customer_ids();

/** The probe being acted on, if the user may manage its customer. */
$probeInScope = function (int $id): ?array {
    $p = cc_get_probe($id);
    return ($p && scope_can_customer((int)$p['customer_id'])) ? $p : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('customer.manage');
    $act = (string)($_POST['action'] ?? '');
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = '<div class="alert alert-danger">Invalid CSRF token.</div>';
    } elseif ($act === 'create_probe') {
        $cid = (int)($_POST['customer_id'] ?? 0);
        if (!scope_can_customer($cid)) {
            $message = '<div class="alert alert-danger">You do not have access to that customer.</div>';
        } else {
            $r = cc_create_probe($cid, (int)($_POST['firewall_id'] ?? 0), (string)($_POST['name'] ?? ''), (string)($_POST['kind'] ?? 'standalone'));
            if ($r['ok']) {
                $newToken = ['probe_id' => $r['id'], 'token' => $r['token']];
                $message = '<div class="alert alert-success">Probe created. Add the checks it should run, then start it with the token below.</div>';
            } else {
                $message = '<div class="alert alert-danger">' . htmlspecialchars($r['error']) . '</div>';
            }
        }
    } elseif ($p = $probeInScope((int)($_POST['probe_id'] ?? 0))) {
        $pid = (int)$p['id'];
        switch ($act) {
            case 'add_check':
                $r = cc_add_check($pid, $_POST);
                $message = $r['ok'] ? '<div class="alert alert-success">Check approved.</div>'
                                    : '<div class="alert alert-danger">' . htmlspecialchars($r['error']) . '</div>';
                break;
            case 'delete_check':
                cc_delete_check($pid, (int)($_POST['check_id'] ?? 0));
                $message = '<div class="alert alert-info">Check removed.</div>';
                break;
            case 'toggle_required':
                cc_set_check_required($pid, (int)($_POST['check_id'] ?? 0), !empty($_POST['required']));
                $message = '<div class="alert alert-info">Updated.</div>';
                break;
            case 'rotate':
                $r = cc_rotate_token($pid);
                $newToken = ['probe_id' => $pid, 'token' => $r['token']];
                $message = '<div class="alert alert-warning">New token issued. The old one no longer works.</div>';
                break;
            case 'toggle_enabled':
                cc_set_probe_enabled($pid, empty($p['enabled']));
                $message = '<div class="alert alert-info">Probe ' . (empty($p['enabled']) ? 'enabled' : 'disabled') . '.</div>';
                break;
            case 'delete_probe':
                cc_delete_probe($pid);
                $message = '<div class="alert alert-info">Probe deleted.</div>';
                break;
        }
    } else {
        $message = '<div class="alert alert-danger">Probe not found.</div>';
    }
}

$probes = cc_probe_overview($scoped);
[$w, $wp] = scope_sql('c.id');
$cs = db()->prepare("SELECT c.id, c.name FROM customers c WHERE {$w} ORDER BY c.name");
$cs->execute($wp);
$customers = $cs->fetchAll(PDO::FETCH_ASSOC);
[$w, $wp] = scope_sql('f.customer_id');
$fs = db()->prepare("SELECT f.id, f.hostname, f.customer_id, c.name AS customer_name FROM firewalls f
                       JOIN customers c ON c.id = f.customer_id WHERE {$w} ORDER BY c.name, f.hostname");
$fs->execute($wp);
$firewalls = $fs->fetchAll(PDO::FETCH_ASSOC);
$staleMin = cc_setting('customer_check_stale_minutes', 10);
$base = opnmgr_server_url() ?: 'https://your-opnmanager';

function cc_state_badge(string $state): string {
    $map = ['pass' => ['success', 'healthy'], 'fail' => ['danger', 'failing'], 'stale' => ['warning text-dark', 'stale'],
            'waiting' => ['info', 'waiting']];
    [$cls, $txt] = $map[$state] ?? ['secondary', $state];
    return '<span class="badge bg-' . $cls . '">' . $txt . '</span>';
}

include __DIR__ . '/inc/header.php';
?>
<div class="container-fluid">
    <div class="mb-3">
        <h4 class="mb-0"><i class="fas fa-route me-2"></i>Customer Checks</h4>
        <small class="text-muted">
            A firewall can check in while a workstation behind it cannot reach a service. A probe on the customer network
            tests the paths users rely on. Update campaigns that enable <em>customer path checks</em> hold the next ring,
            and the second member of a CARP pair, while a required check fails or has not reported for <?php echo (int)$staleMin; ?> minutes.
        </small>
    </div>

    <?php echo $message; ?>

    <?php if ($newToken): ?>
    <div class="alert alert-warning">
        <strong>Probe token &mdash; shown once.</strong> Copy it now; it is stored only as a hash.
        <pre class="mt-2 mb-2 p-2 border rounded small" style="white-space:pre-wrap"><?php echo htmlspecialchars($newToken['token']); ?></pre>
        Run the standalone probe (Python 3, no install) on a machine behind the firewall, or have Rem0te run the same script:
        <pre class="mt-2 mb-0 p-2 border rounded small" style="white-space:pre-wrap">OPNMGR_PROBE_TOKEN=<?php echo htmlspecialchars($newToken['token']); ?> python3 opnmgr_probe.py --manager <?php echo htmlspecialchars($base); ?></pre>
        <div class="small mt-1">The probe is <code>tools/opnmgr-probe/opnmgr_probe.py</code> in this installation (see its README for a service unit and Windows task).</div>
    </div>
    <?php endif; ?>

    <?php if (!$probes): ?>
        <div class="card mb-3"><div class="card-body text-muted small">No probes yet. Customer checks are optional: campaigns without them behave exactly as before.</div></div>
    <?php endif; ?>

    <?php foreach ($probes as $p):
        $silent = $p['silent_seconds'] === null ? null : (int)$p['silent_seconds'];
        $fresh = $silent !== null && $silent <= $staleMin * 60; ?>
    <div class="card mb-3">
        <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="small">
                <strong><?php echo htmlspecialchars($p['name']); ?></strong>
                <span class="text-muted">&middot; <?php echo htmlspecialchars($p['customer_name']); ?>
                    &middot; behind <?php echo htmlspecialchars($p['hostname'] ?? ('#' . $p['firewall_id'])); ?>
                    <?php if ($p['peer_hostname']): ?>(and CARP partner <?php echo htmlspecialchars($p['peer_hostname']); ?>)<?php endif; ?>
                    &middot; <?php echo $p['kind'] === 'rem0te' ? 'Rem0te' : 'standalone'; ?></span>
                <?php if (!(int)$p['enabled']): ?><span class="badge bg-secondary ms-1">disabled</span>
                <?php elseif ($silent === null): ?><span class="badge bg-warning text-dark ms-1">never reported</span>
                <?php elseif ($fresh): ?><span class="badge bg-success ms-1">reporting</span>
                <?php else: ?><span class="badge bg-warning text-dark ms-1">offline <?php echo intdiv($silent, 60); ?> min</span><?php endif; ?>
            </div>
            <?php if ($canManage): ?>
            <form method="post" class="d-flex gap-1">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="probe_id" value="<?php echo (int)$p['id']; ?>">
                <button class="btn btn-sm btn-outline-secondary" name="action" value="toggle_enabled"><?php echo (int)$p['enabled'] ? 'Disable' : 'Enable'; ?></button>
                <button class="btn btn-sm btn-outline-warning" name="action" value="rotate" onclick="return confirm('Issue a new token? The running probe stops working until it gets it.')">New token</button>
                <button class="btn btn-sm btn-outline-danger" name="action" value="delete_probe" onclick="return confirm('Delete this probe, its checks and results?')">Delete</button>
            </form>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr><th>Path</th><th>Check</th><th>Target</th><th>Gates rollout</th><th>Now</th><th>Last result</th><th></th></tr></thead>
                <tbody>
                <?php if (!$p['checks']): ?>
                    <tr><td colspan="7" class="text-muted small">No checks approved yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($p['checks'] as $c): $now = $c['now']; ?>
                    <tr>
                        <td class="small"><?php echo htmlspecialchars(CUSTOMER_CHECK_TYPES[$c['check_type']]); ?></td>
                        <td class="small"><?php echo htmlspecialchars($c['label']); ?></td>
                        <td class="small"><code><?php echo htmlspecialchars($c['target']); ?></code>
                            <?php if ($c['resolver']): ?><span class="text-muted">via <?php echo htmlspecialchars($c['resolver']); ?></span><?php endif; ?>
                            <?php if ($c['expect']): ?><span class="text-muted">expect <?php echo htmlspecialchars($c['expect']); ?></span><?php endif; ?></td>
                        <td class="small"><?php echo (int)$c['required'] ? 'required' : '<span class="text-muted">informational</span>'; ?></td>
                        <td><?php echo cc_state_badge($now['state']); ?></td>
                        <td class="small text-muted"><?php echo htmlspecialchars($now['detail']); ?>
                            <?php if ($now['result']): ?><div style="font-size:.7rem"><?php echo htmlspecialchars($now['result']['observed_at']); ?>
                                <?php if ($now['result']['latency_ms'] !== null): ?>&middot; <?php echo (int)$now['result']['latency_ms']; ?> ms<?php endif; ?></div><?php endif; ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($canManage): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                <input type="hidden" name="probe_id" value="<?php echo (int)$p['id']; ?>">
                                <input type="hidden" name="check_id" value="<?php echo (int)$c['id']; ?>">
                                <input type="hidden" name="required" value="<?php echo (int)$c['required'] ? '' : '1'; ?>">
                                <button class="btn btn-sm btn-outline-secondary" name="action" value="toggle_required"><?php echo (int)$c['required'] ? 'Make informational' : 'Make required'; ?></button>
                                <button class="btn btn-sm btn-outline-danger" name="action" value="delete_check">Remove</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canManage && count($p['checks']) < CUSTOMER_PROBE_MAX_CHECKS): ?>
        <div class="card-footer">
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="probe_id" value="<?php echo (int)$p['id']; ?>">
                <input type="hidden" name="action" value="add_check">
                <div class="col-md-2"><label class="form-label small mb-0">Path</label>
                    <select name="check_type" class="form-select form-select-sm">
                        <option value="dns">Internal DNS</option>
                        <option value="vpn_service">Service across VPN</option>
                        <option value="internet_service">Internet service</option>
                    </select></div>
                <div class="col-md-2"><label class="form-label small mb-0">Label</label>
                    <input name="label" class="form-control form-control-sm" placeholder="e.g. File server"></div>
                <div class="col-md-3"><label class="form-label small mb-0">Target</label>
                    <input name="target" class="form-control form-control-sm" required
                           placeholder="fs.corp.lan / 10.20.0.5:445 / https://example.com/"></div>
                <div class="col-md-2"><label class="form-label small mb-0">Resolver (DNS)</label>
                    <input name="resolver" class="form-control form-control-sm" placeholder="10.0.0.53"></div>
                <div class="col-md-1"><label class="form-label small mb-0">Expect</label>
                    <input name="expect" class="form-control form-control-sm" placeholder="IP/CIDR or 200"></div>
                <div class="col-md-1"><div class="form-check">
                    <input class="form-check-input" type="checkbox" name="required" value="1" id="req<?php echo (int)$p['id']; ?>" checked>
                    <label class="form-check-label small" for="req<?php echo (int)$p['id']; ?>">Required</label></div></div>
                <div class="col-md-1 d-grid"><button class="btn btn-sm btn-primary">Approve</button></div>
            </form>
            <div class="form-text">VPN services must be on private addresses; internet services must be public https:// hosts. The probe refuses anything else.</div>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if ($canManage): ?>
    <div class="card">
        <div class="card-header py-2"><strong class="small">Add a probe</strong></div>
        <div class="card-body">
            <?php if (!$firewalls): ?>
                <div class="text-muted small">Probes belong to a customer. Assign a firewall to a customer first.</div>
            <?php else: ?>
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="action" value="create_probe">
                <div class="col-md-3"><label class="form-label small mb-0">Customer</label>
                    <select name="customer_id" class="form-select form-select-sm" id="ccCustomer">
                        <?php foreach ($customers as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-3"><label class="form-label small mb-0">Behind firewall</label>
                    <select name="firewall_id" class="form-select form-select-sm" id="ccFirewall">
                        <?php foreach ($firewalls as $f): ?><option value="<?php echo (int)$f['id']; ?>" data-customer="<?php echo (int)$f['customer_id']; ?>">
                            <?php echo htmlspecialchars($f['customer_name'] . ' / ' . $f['hostname']); ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-3"><label class="form-label small mb-0">Name</label>
                    <input name="name" class="form-control form-control-sm" required maxlength="100" placeholder="e.g. Front office PC"></div>
                <div class="col-md-2"><label class="form-label small mb-0">Runs as</label>
                    <select name="kind" class="form-select form-select-sm">
                        <option value="standalone">Standalone probe</option>
                        <option value="rem0te">Rem0te script</option>
                    </select></div>
                <div class="col-md-1 d-grid"><button class="btn btn-sm btn-success">Create</button></div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <script>
    (function () {
        var c = document.getElementById('ccCustomer'), f = document.getElementById('ccFirewall');
        if (!c || !f) return;
        function sync() {
            var first = null;
            Array.prototype.forEach.call(f.options, function (o) {
                var show = o.dataset.customer === c.value;
                o.hidden = !show;
                if (show && first === null) first = o;
            });
            if (first && f.selectedOptions[0] && f.selectedOptions[0].hidden) f.value = first.value;
        }
        c.addEventListener('change', sync);
        sync();
    })();
    </script>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
