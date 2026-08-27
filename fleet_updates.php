<?php
/**
 * Fleet Updates.
 *
 * What every managed firewall is running, what is available, and the campaigns
 * rolling updates out in rings.
 *
 * Rings are a rollout mechanism, not customer tiers. Progression is manual
 * unless a campaign explicitly enables auto-progress, and a ring with any
 * failure never counts as clean.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fleet_updates.php';

require_permission('firewall.view');

$message   = '';
$canUpdate = can('update.install');
$canRing   = can('update.schedule');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = '<div class="alert alert-danger">Invalid CSRF token.</div>';
    } elseif (isset($_POST['set_ring'])) {
        if (!$canRing) {
            $message = '<div class="alert alert-danger">Your role does not permit changing rings.</div>';
        } else {
            $r = set_update_ring((int)$_POST['firewall_id'], (string)$_POST['ring']);
            $message = $r['ok']
                ? '<div class="alert alert-success">Ring updated.</div>'
                : '<div class="alert alert-danger">' . htmlspecialchars($r['error']) . '</div>';
        }
    } elseif (isset($_POST['create_campaign'])) {
        if (!$canUpdate) {
            $message = '<div class="alert alert-danger">Your role does not permit starting updates.</div>';
        } else {
            $targets = array_map('intval', (array)($_POST['targets'] ?? []));
            $r = campaign_create($_POST, $targets);
            $message = $r['ok']
                ? '<div class="alert alert-success">Campaign created. Start a ring when you are ready.</div>'
                : '<div class="alert alert-danger">' . htmlspecialchars($r['error']) . '</div>';
        }
    } elseif (isset($_POST['start_ring'])) {
        if (!$canUpdate) {
            $message = '<div class="alert alert-danger">Your role does not permit starting updates.</div>';
        } else {
            $cid = (int)$_POST['campaign_id'];
            $r = campaign_start_ring($cid, (string)$_POST['ring']);
            if ($r['ok']) {
                $d = campaign_dispatch($cid);
                $message = sprintf(
                    '<div class="alert alert-success">Ring started: %d dispatched, %d held, %d skipped.%s</div>',
                    $d['dispatched'], $d['held'], $d['skipped'],
                    $d['notes'] ? '<br><small>' . htmlspecialchars(implode('; ', array_slice($d['notes'], 0, 5))) . '</small>' : ''
                );
            } else {
                $message = '<div class="alert alert-danger">' . htmlspecialchars($r['error']) . '</div>';
            }
        }
    } elseif (isset($_POST['dispatch'])) {
        $cid = (int)$_POST['campaign_id'];
        campaign_reconcile($cid);
        $d = campaign_dispatch($cid);
        $message = sprintf('<div class="alert alert-info">%d dispatched, %d held, %d skipped.</div>',
                           $d['dispatched'], $d['held'], $d['skipped']);
    }
}

$filters = array_filter([
    'ring'         => $_GET['ring'] ?? '',
    'customer_id'  => $_GET['customer'] ?? '',
    'updates_only' => !empty($_GET['updates_only']),
]);
$fleet = fleet_update_overview($filters);

$campaigns = db()->query(
    'SELECT * FROM update_campaigns ORDER BY FIELD(status,"running","paused","draft","completed","cancelled"), created_at DESC LIMIT 25'
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($campaigns as &$c) {
    campaign_reconcile((int)$c['id']);
    $c['rings'] = campaign_ring_summary((int)$c['id']);
}
unset($c);

$customers = db()->query('SELECT id, name FROM customers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

$counts = ['total' => count($fleet), 'available' => 0, 'reboot' => 0, 'offline' => 0];
foreach ($fleet as $f) {
    if ((int)$f['updates_available'] === 1) $counts['available']++;
    if ((int)$f['reboot_required'] === 1)   $counts['reboot']++;
    if ($f['status'] !== 'online')          $counts['offline']++;
}

function ring_badge(string $r): string {
    return match ($r) { 'canary' => 'warning text-dark', 'pilot' => 'info', default => 'secondary' };
}

include __DIR__ . '/inc/header.php';
?>

<div class="container-fluid">
    <div class="mb-3">
        <h4 class="mb-0"><i class="fas fa-rocket me-2"></i>Fleet Updates</h4>
        <small class="text-muted">
            Rings are a rollout mechanism &mdash; canary, then pilot, then production &mdash; not customer tiers.
            HA pairs are never updated simultaneously.
        </small>
    </div>

    <?php echo $message; ?>

    <div class="row g-2 mb-3">
        <?php foreach ([
            ['Managed', $counts['total'], 'secondary'],
            ['Updates available', $counts['available'], 'warning'],
            ['Reboot required', $counts['reboot'], 'danger'],
            ['Offline', $counts['offline'], 'danger'],
        ] as [$label, $n, $colour]): ?>
        <div class="col-6 col-md-3">
            <div class="card h-100"><div class="card-body py-2">
                <div class="text-muted small"><?php echo $label; ?></div>
                <div class="fs-4 text-<?php echo $n > 0 ? $colour : 'success'; ?>"><?php echo (int)$n; ?></div>
            </div></div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($campaigns): ?>
    <div class="card mb-3">
        <div class="card-header py-2"><strong class="small">Campaigns</strong></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr><th>Name</th><th>Status</th><th>Ring</th><th>Progress</th><th>Options</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($campaigns as $c): ?>
                    <tr>
                        <td class="small">
                            <?php echo htmlspecialchars($c['name']); ?>
                            <div class="text-muted"><?php echo htmlspecialchars($c['created_by_username'] ?: 'system'); ?>
                                &middot; <?php echo htmlspecialchars($c['operation']); ?></div>
                        </td>
                        <td><span class="badge bg-<?php echo $c['status'] === 'running' ? 'primary' : 'secondary'; ?>">
                            <?php echo htmlspecialchars($c['status']); ?></span></td>
                        <td><?php if ($c['current_ring']): ?>
                            <span class="badge bg-<?php echo ring_badge($c['current_ring']); ?>">
                                <?php echo htmlspecialchars($c['current_ring']); ?></span>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                        <td class="small">
                            <?php foreach (UPDATE_RINGS as $ring):
                                $s = $c['rings'][$ring];
                                if ($s['total'] === 0) continue; ?>
                                <div>
                                    <span class="badge bg-<?php echo ring_badge($ring); ?>"><?php echo $ring; ?></span>
                                    <span class="text-success"><?php echo $s['succeeded']; ?> ok</span>
                                    <?php if ($s['failed']): ?><span class="text-danger"><?php echo $s['failed']; ?> failed</span><?php endif; ?>
                                    <?php if ($s['dispatched']): ?><span class="text-primary"><?php echo $s['dispatched']; ?> running</span><?php endif; ?>
                                    <?php if ($s['holding']): ?><span class="text-warning"><?php echo $s['holding']; ?> held</span><?php endif; ?>
                                    <?php if ($s['pending']): ?><span class="text-muted"><?php echo $s['pending']; ?> pending</span><?php endif; ?>
                                    <span class="text-muted">/ <?php echo $s['total']; ?></span>
                                    <?php $clean = campaign_ring_is_clean((int)$c['id'], $ring); ?>
                                    <span class="text-muted small">(<?php echo htmlspecialchars($clean['reason']); ?>)</span>
                                </div>
                            <?php endforeach; ?>
                        </td>
                        <td class="small text-muted">
                            <?php echo (int)$c['auto_progress'] ? 'auto-progress' : 'manual'; ?><br>
                            <?php echo (int)$c['ha_safe'] ? 'HA-safe' : '<span class="text-danger">HA-safe OFF</span>'; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if ($canUpdate && !in_array($c['status'], ['completed','cancelled'], true)): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                <input type="hidden" name="campaign_id" value="<?php echo (int)$c['id']; ?>">
                                <select name="ring" class="form-select form-select-sm d-inline-block" style="width:auto">
                                    <?php foreach (UPDATE_RINGS as $r): ?>
                                        <option value="<?php echo $r; ?>"><?php echo $r; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-primary" name="start_ring"
                                        onclick="return confirm('Start this ring? Updates will be dispatched to eligible firewalls.')">Start ring</button>
                                <button class="btn btn-sm btn-outline-secondary" name="dispatch" title="Re-check held targets">Refresh</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <form method="post" id="fleetForm">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">

    <div class="card">
        <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <strong class="small">Fleet</strong>
            <div class="d-flex gap-2 align-items-center">
                <select name="ring_filter" class="form-select form-select-sm" style="width:auto"
                        onchange="location.href='?ring='+this.value">
                    <option value="">All rings</option>
                    <?php foreach (UPDATE_RINGS as $r): ?>
                        <option value="<?php echo $r; ?>" <?php echo ($_GET['ring'] ?? '') === $r ? 'selected' : ''; ?>>
                            <?php echo ucfirst($r); ?></option>
                    <?php endforeach; ?>
                </select>
                <a class="btn btn-sm btn-outline-secondary"
                   href="?<?php echo !empty($_GET['updates_only']) ? '' : 'updates_only=1'; ?>">
                    <?php echo !empty($_GET['updates_only']) ? 'Show all' : 'Only with updates'; ?>
                </a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr>
                    <th style="width:30px"><input type="checkbox" id="selAll" class="form-check-input"></th>
                    <th>Firewall</th><th>Customer / Site</th><th>Ring</th>
                    <th>Current</th><th>Available</th><th>Agent</th>
                    <th>Update</th><th>Reboot</th><th>HA</th><th>Last attempt</th>
                </tr></thead>
                <tbody>
                <?php if (!$fleet): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">No firewalls match.</td></tr>
                <?php else: foreach ($fleet as $f): ?>
                    <tr>
                        <td><input type="checkbox" name="targets[]" value="<?php echo (int)$f['id']; ?>" class="form-check-input row-sel"></td>
                        <td class="small">
                            <span class="badge bg-<?php echo $f['status'] === 'online' ? 'success' : 'danger'; ?> me-1">&nbsp;</span>
                            <a href="firewall_details.php?id=<?php echo (int)$f['id']; ?>"><?php echo htmlspecialchars($f['hostname']); ?></a>
                        </td>
                        <td class="small text-muted">
                            <?php echo htmlspecialchars($f['customer_name'] ?: 'Unassigned'); ?>
                            <?php if ($f['site_name']): ?>/ <?php echo htmlspecialchars($f['site_name']); ?><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($canRing): ?>
                            <select class="form-select form-select-sm ring-select" data-fw="<?php echo (int)$f['id']; ?>" style="width:auto">
                                <?php foreach (UPDATE_RINGS as $r): ?>
                                    <option value="<?php echo $r; ?>" <?php echo $f['update_ring'] === $r ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($r); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php else: ?>
                                <span class="badge bg-<?php echo ring_badge($f['update_ring']); ?>">
                                    <?php echo htmlspecialchars($f['update_ring']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?php echo htmlspecialchars(substr((string)($f['current_version'] ?: $f['version']), 0, 18) ?: '—'); ?></td>
                        <td class="small"><?php echo htmlspecialchars(substr((string)$f['available_version'], 0, 18) ?: '—'); ?></td>
                        <td class="small text-muted"><?php echo htmlspecialchars($f['agent_version'] ?: '—'); ?></td>
                        <td><?php echo (int)$f['updates_available'] === 1
                                ? '<span class="badge bg-warning text-dark">available</span>'
                                : '<span class="text-muted small">up to date</span>'; ?></td>
                        <td><?php echo (int)$f['reboot_required'] === 1
                                ? '<span class="badge bg-danger">required</span>' : ''; ?></td>
                        <td class="small">
                            <?php if ((int)$f['carp_enabled'] === 1): ?>
                                <span class="badge bg-<?php echo $f['carp_state'] === 'MASTER' ? 'primary' : 'secondary'; ?>">
                                    <?php echo htmlspecialchars($f['carp_state'] ?: 'INIT'); ?></span>
                                <?php if ($f['ha_peer_hostname']): ?>
                                    <div class="text-muted" style="font-size:.7rem">
                                        ↔ <?php echo htmlspecialchars($f['ha_peer_hostname']); ?></div>
                                <?php endif; ?>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="small text-muted">
                            <?php if ($f['last_update_attempt_at']): ?>
                                <?php echo htmlspecialchars($f['last_update_result'] ?: '—'); ?>
                                <div style="font-size:.7rem"><?php echo htmlspecialchars($f['last_update_attempt_at']); ?></div>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($canUpdate): ?>
        <div class="card-footer">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1">Campaign name</label>
                    <input type="text" name="name" class="form-control form-control-sm"
                           placeholder="e.g. OPNsense 26.7.2 rollout">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Operation</label>
                    <select name="operation" class="form-select form-select-sm">
                        <option value="check">Check for updates</option>
                        <option value="install">Install updates</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Target version</label>
                    <input type="text" name="target_version" class="form-control form-control-sm" placeholder="optional">
                </div>
                <div class="col-md-3">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="ha_safe" id="haSafe" value="1" checked>
                        <label class="form-check-label small" for="haSafe">HA-safe</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="respect_maintenance" id="respMw" value="1" checked>
                        <label class="form-check-label small" for="respMw">Respect maintenance</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="auto_progress" id="autoProg" value="1">
                        <label class="form-check-label small" for="autoProg">Auto-progress rings</label>
                    </div>
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-primary btn-sm" name="create_campaign"
                            onclick="return confirm('Create a campaign over the selected firewalls?')">
                        Create campaign
                    </button>
                </div>
            </div>
            <div class="form-text">
                Creating a campaign does not dispatch anything. Start a ring when you are ready.
            </div>
        </div>
        <?php endif; ?>
    </div>
    </form>
</div>

<script>
(function () {
  var all = document.getElementById('selAll');
  if (all) {
    all.addEventListener('change', function () {
      document.querySelectorAll('.row-sel').forEach(function (c) { c.checked = all.checked; });
    });
  }
  // Ring changes post immediately rather than riding on the campaign form,
  // which would otherwise submit a ring change as part of creating a campaign.
  document.querySelectorAll('.ring-select').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var f = document.createElement('form');
      f.method = 'post';
      f.innerHTML = '<input name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">' +
                    '<input name="firewall_id" value="' + sel.dataset.fw + '">' +
                    '<input name="ring" value="' + sel.value + '">' +
                    '<input name="set_ring" value="1">';
      document.body.appendChild(f);
      f.submit();
    });
  });
})();
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
