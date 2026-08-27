<?php
/**
 * Bulk Operations.
 *
 * Applies one catalogued operation across selected firewalls.
 *
 * Raw shell is deliberately absent: "run this command on every firewall" is not
 * a button anyone should be able to reach by accident. It stays a
 * single-firewall, admin-only, explicitly confirmed path.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/bulk_ops.php';

require_permission('bulk.operate');

$message = '';
$catalog = bulk_operation_catalog();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run'])) {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = '<div class="alert alert-danger">Invalid CSRF token.</div>';
    } else {
        $operation = (string)($_POST['operation'] ?? '');
        $targets   = array_map('intval', (array)($_POST['targets'] ?? []));
        $params    = [
            'customer_id' => $_POST['customer_id'] ?? null,
            'site_id'     => $_POST['site_id'] ?? null,
            'ring'        => $_POST['ring'] ?? null,
            'tag'         => $_POST['tag'] ?? null,
            'service'     => $_POST['service'] ?? null,
        ];
        $params = array_filter($params, fn($v) => $v !== null && $v !== '');

        $r = bulk_execute($operation, $targets, $params, (string)($_POST['confirm'] ?? ''));

        $message = $r['ok']
            ? sprintf('<div class="alert alert-success">Bulk operation #%d: %d queued, %d skipped or failed.</div>',
                      $r['bulk_id'], $r['queued'], $r['skipped'])
            : '<div class="alert alert-danger">' . htmlspecialchars($r['error']) . '</div>';
    }
}

$firewalls = db()->query(
    'SELECT f.id, f.hostname, f.status, f.update_ring, c.name AS customer_name, s.name AS site_name
       FROM firewalls f
       LEFT JOIN customers c ON c.id = f.customer_id
       LEFT JOIN sites s ON s.id = f.site_id
      ORDER BY f.hostname'
)->fetchAll(PDO::FETCH_ASSOC);

$customers = db()->query('SELECT id, name FROM customers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$sites = db()->query('SELECT s.id, s.name, c.name AS customer_name FROM sites s
                        JOIN customers c ON c.id = s.customer_id ORDER BY c.name, s.name')->fetchAll(PDO::FETCH_ASSOC);
$history = bulk_recent(25);

function risk_badge(string $r): string {
    return match ($r) {
        'CRITICAL' => 'danger', 'HIGH' => 'danger',
        'MEDIUM'   => 'warning text-dark', default => 'secondary',
    };
}

include __DIR__ . '/inc/header.php';
?>

<div class="container-fluid">
    <div class="mb-3">
        <h4 class="mb-0"><i class="fas fa-layer-group me-2"></i>Bulk Operations</h4>
        <small class="text-muted">
            High-risk operations require typing a confirmation phrase that includes the target count.
            Raw shell commands are not available in bulk by design.
        </small>
    </div>

    <?php echo $message; ?>

    <form method="post" id="bulkForm">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">

    <div class="card mb-3">
        <div class="card-header py-2"><strong class="small">Operation</strong></div>
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1">What to do</label>
                    <select name="operation" id="bulkOp" class="form-select form-select-sm" required>
                        <?php foreach ($catalog as $key => $entry):
                            if (!can($entry['capability'])) continue; ?>
                            <option value="<?php echo htmlspecialchars($key); ?>"
                                    data-risk="<?php echo $entry['risk']; ?>"
                                    data-params="<?php echo htmlspecialchars(implode(',', $entry['params'] ?? [])); ?>">
                                <?php echo htmlspecialchars($entry['label']); ?> (<?php echo $entry['risk']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3 bulk-param" data-param="customer_id" style="display:none">
                    <label class="form-label small mb-1">Customer</label>
                    <select name="customer_id" class="form-select form-select-sm">
                        <?php foreach ($customers as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 bulk-param" data-param="site_id" style="display:none">
                    <label class="form-label small mb-1">Site</label>
                    <select name="site_id" class="form-select form-select-sm">
                        <?php foreach ($sites as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>">
                                <?php echo htmlspecialchars($s['customer_name'] . ' / ' . $s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 bulk-param" data-param="ring" style="display:none">
                    <label class="form-label small mb-1">Ring</label>
                    <select name="ring" class="form-select form-select-sm">
                        <option value="canary">Canary</option>
                        <option value="pilot">Pilot</option>
                        <option value="production">Production</option>
                    </select>
                </div>
                <div class="col-md-3 bulk-param" data-param="tag" style="display:none">
                    <label class="form-label small mb-1">Tag</label>
                    <input type="text" name="tag" class="form-control form-control-sm" placeholder="tag name">
                </div>
                <div class="col-md-3 bulk-param" data-param="service" style="display:none">
                    <label class="form-label small mb-1">Service</label>
                    <select name="service" class="form-select form-select-sm">
                        <?php foreach (agent_service_allowlist() as $svc): ?>
                            <option value="<?php echo htmlspecialchars($svc); ?>"><?php echo htmlspecialchars($svc); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="confirmBlock" class="alert alert-danger mt-3 mb-0" style="display:none">
                <strong>This is a high-risk operation.</strong>
                <div class="small mb-2">
                    It will be applied to <span id="targetCount">0</span> firewall(s).
                    Type <code id="confirmPhrase"></code> below to proceed.
                </div>
                <input type="text" name="confirm" class="form-control form-control-sm"
                       placeholder="type the confirmation phrase" autocomplete="off">
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <strong class="small">Targets (<span id="selCount">0</span> selected)</strong>
            <div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="selNone">Clear</button>
                <button type="submit" name="run" class="btn btn-sm btn-primary">Run operation</button>
            </div>
        </div>
        <div class="table-responsive" style="max-height:420px;overflow-y:auto">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr>
                    <th style="width:30px"><input type="checkbox" id="selAll" class="form-check-input"></th>
                    <th>Firewall</th><th>Customer / Site</th><th>Ring</th><th>Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($firewalls as $f): ?>
                    <tr>
                        <td><input type="checkbox" name="targets[]" value="<?php echo (int)$f['id']; ?>" class="form-check-input row-sel"></td>
                        <td class="small"><?php echo htmlspecialchars($f['hostname']); ?></td>
                        <td class="small text-muted">
                            <?php echo htmlspecialchars($f['customer_name'] ?: 'Unassigned'); ?>
                            <?php if ($f['site_name']): ?>/ <?php echo htmlspecialchars($f['site_name']); ?><?php endif; ?>
                        </td>
                        <td class="small"><span class="badge bg-secondary"><?php echo htmlspecialchars($f['update_ring']); ?></span></td>
                        <td><span class="badge bg-<?php echo $f['status'] === 'online' ? 'success' : 'danger'; ?>">
                            <?php echo htmlspecialchars($f['status'] ?: 'unknown'); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    </form>

    <?php if ($history): ?>
    <div class="card">
        <div class="card-header py-2"><strong class="small">Recent bulk operations</strong></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>When</th><th>Operation</th><th>Risk</th><th>Targets</th><th>Result</th><th>By</th></tr></thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td class="small text-muted"><?php echo htmlspecialchars($h['created_at']); ?></td>
                        <td class="small"><?php echo htmlspecialchars($catalog[$h['operation']]['label'] ?? $h['operation']); ?></td>
                        <td><span class="badge bg-<?php echo risk_badge($h['risk_level']); ?>"><?php echo htmlspecialchars($h['risk_level']); ?></span></td>
                        <td class="small"><?php echo (int)$h['target_count']; ?></td>
                        <td class="small">
                            <span class="text-success"><?php echo (int)$h['succeeded']; ?> ok</span>
                            <?php if ((int)$h['failed'] > 0): ?>
                                <span class="text-danger"><?php echo (int)$h['failed']; ?> failed</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?php echo htmlspecialchars($h['created_by_username'] ?: 'system'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
  var op = document.getElementById('bulkOp');
  var all = document.getElementById('selAll');
  var none = document.getElementById('selNone');
  var confirmBlock = document.getElementById('confirmBlock');
  var confirmPhrase = document.getElementById('confirmPhrase');
  var targetCount = document.getElementById('targetCount');
  var selCount = document.getElementById('selCount');

  function selected() { return document.querySelectorAll('.row-sel:checked').length; }

  function syncParams() {
    var wanted = (op.selectedOptions[0].dataset.params || '').split(',').filter(Boolean);
    document.querySelectorAll('.bulk-param').forEach(function (el) {
      var show = wanted.indexOf(el.dataset.param) !== -1;
      el.style.display = show ? '' : 'none';
      el.querySelectorAll('input,select').forEach(function (i) { i.disabled = !show; });
    });
  }

  function syncConfirm() {
    var risk = op.selectedOptions[0].dataset.risk;
    var n = selected();
    selCount.textContent = n;
    var needs = (risk === 'HIGH' || risk === 'CRITICAL');
    confirmBlock.style.display = needs ? '' : 'none';
    confirmBlock.querySelector('input').disabled = !needs;
    if (needs) {
      targetCount.textContent = n;
      // Must match bulk_confirmation_phrase() on the server.
      confirmPhrase.textContent = op.value.replace(/_/g, ' ').toUpperCase() + ' ' + n;
    }
  }

  function sync() { syncParams(); syncConfirm(); }

  op.addEventListener('change', sync);
  all.addEventListener('change', function () {
    document.querySelectorAll('.row-sel').forEach(function (c) { c.checked = all.checked; });
    syncConfirm();
  });
  none.addEventListener('click', function () {
    document.querySelectorAll('.row-sel').forEach(function (c) { c.checked = false; });
    all.checked = false;
    syncConfirm();
  });
  document.querySelectorAll('.row-sel').forEach(function (c) {
    c.addEventListener('change', syncConfirm);
  });
  sync();
})();
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
