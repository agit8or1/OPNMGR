<?php
/**
 * Maintenance Windows.
 *
 * Marks planned work on a firewall, a site or a whole customer.
 *
 * During a window everything continues except outbound notification: agents
 * keep checking in, health keeps being collected and shown, and incidents are
 * still opened and recorded with the suppression noted. The period you most
 * want a record of is the one somebody was working through.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/maintenance.php';

require_permission('maintenance.manage');

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = '<div class="alert alert-danger">Invalid CSRF token.</div>';
    } elseif (isset($_POST['create'])) {
        $r = maintenance_save($_POST);
        $message = $r['ok']
            ? '<div class="alert alert-success">Maintenance window scheduled.</div>'
            : '<div class="alert alert-danger">' . htmlspecialchars($r['error']) . '</div>';
    } elseif (isset($_POST['cancel'])) {
        $r = maintenance_cancel((int)$_POST['window_id']);
        $message = $r['ok']
            ? '<div class="alert alert-success">Window cancelled.</div>'
            : '<div class="alert alert-warning">' . htmlspecialchars($r['error']) . '</div>';
    }
}

maintenance_refresh_statuses();

$showAll = !empty($_GET['all']);
$windows = maintenance_list($showAll);
$covered = maintenance_firewalls_in_window();

$firewalls = db()->query('SELECT id, hostname FROM firewalls ORDER BY hostname')->fetchAll(PDO::FETCH_ASSOC);
$customers = db()->query('SELECT id, name FROM customers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$sites = db()->query('SELECT s.id, s.name, c.name AS customer_name
                        FROM sites s JOIN customers c ON c.id = s.customer_id
                       ORDER BY c.name, s.name')->fetchAll(PDO::FETCH_ASSOC);

function mw_badge(string $status): string {
    return match ($status) {
        'active'    => 'success',
        'scheduled' => 'primary',
        'completed' => 'secondary',
        'cancelled' => 'dark',
        default     => 'secondary',
    };
}

include __DIR__ . '/inc/header.php';
?>

<div class="container-fluid">
    <div class="mb-3">
        <h4 class="mb-0"><i class="fas fa-screwdriver-wrench me-2"></i>Maintenance Windows</h4>
        <small class="text-muted">
            Suppresses alert notifications during planned work. Monitoring, health collection and
            incident recording all continue &mdash; nothing is discarded.
        </small>
    </div>

    <?php echo $message; ?>

    <?php if ($covered): ?>
    <div class="alert alert-info py-2">
        <strong><?php echo count($covered); ?></strong> firewall(s) are currently in a maintenance window.
    </div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header py-2"><strong class="small">Schedule a window</strong></div>
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <div class="col-md-2">
                    <label class="form-label small mb-1">Applies to</label>
                    <select name="scope" id="mwScope" class="form-select form-select-sm" required>
                        <option value="firewall">Firewall</option>
                        <option value="site">Site</option>
                        <option value="customer">Customer</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Target</label>
                    <select name="scope_id" id="mwTargetFirewall" class="form-select form-select-sm mw-target" required>
                        <?php foreach ($firewalls as $f): ?>
                            <option value="<?php echo (int)$f['id']; ?>"><?php echo htmlspecialchars($f['hostname']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="scope_id" id="mwTargetSite" class="form-select form-select-sm mw-target d-none" disabled>
                        <?php foreach ($sites as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>">
                                <?php echo htmlspecialchars($s['customer_name'] . ' / ' . $s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="scope_id" id="mwTargetCustomer" class="form-select form-select-sm mw-target d-none" disabled>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Starts</label>
                    <input type="datetime-local" name="starts_at" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Ends</label>
                    <input type="datetime-local" name="ends_at" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Reason</label>
                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="e.g. firmware upgrade">
                </div>
                <div class="col-md-1 d-grid">
                    <button class="btn btn-primary btn-sm" name="create"><i class="fas fa-plus"></i></button>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="suppress_alerts" id="mwSuppress" value="1" checked>
                        <label class="form-check-label small" for="mwSuppress">
                            Suppress alert notifications during this window
                            <span class="text-muted">(uncheck to mark work in progress without silencing anything)</span>
                        </label>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <strong class="small">Windows</strong>
            <a class="btn btn-sm btn-outline-secondary"
               href="?<?php echo $showAll ? '' : 'all=1'; ?>">
                <?php echo $showAll ? 'Hide finished' : 'Show finished'; ?>
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr>
                    <th>Status</th><th>Scope</th><th>Target</th><th>Starts</th><th>Ends</th>
                    <th>Reason</th><th>Alerts</th><th>Created by</th><th></th>
                </tr></thead>
                <tbody>
                <?php if (!$windows): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No maintenance windows.</td></tr>
                <?php else: foreach ($windows as $w): ?>
                    <tr>
                        <td><span class="badge bg-<?php echo mw_badge($w['status']); ?>">
                            <?php echo htmlspecialchars($w['status']); ?></span></td>
                        <td class="small text-muted"><?php echo htmlspecialchars($w['scope']); ?></td>
                        <td class="small"><?php echo htmlspecialchars($w['target_name'] ?: '(deleted)'); ?></td>
                        <td class="small text-muted"><?php echo htmlspecialchars($w['starts_at']); ?></td>
                        <td class="small text-muted"><?php echo htmlspecialchars($w['ends_at']); ?></td>
                        <td class="small"><?php echo htmlspecialchars($w['reason'] ?: '—'); ?></td>
                        <td class="small">
                            <?php if ((int)$w['suppress_alerts'] === 1): ?>
                                <span class="badge bg-secondary">suppressed</span>
                            <?php else: ?>
                                <span class="badge bg-success">still notifying</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?php echo htmlspecialchars($w['created_by_username'] ?: 'system'); ?></td>
                        <td class="text-end">
                            <?php if (in_array($w['status'], ['scheduled','active'], true)): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Cancel this maintenance window?')">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                <input type="hidden" name="window_id" value="<?php echo (int)$w['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger" name="cancel">Cancel</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Show only the target selector matching the chosen scope, and disable the
// others so the form submits exactly one scope_id.
(function () {
  var scope = document.getElementById('mwScope');
  var targets = {
    firewall: document.getElementById('mwTargetFirewall'),
    site:     document.getElementById('mwTargetSite'),
    customer: document.getElementById('mwTargetCustomer')
  };
  function sync() {
    Object.keys(targets).forEach(function (k) {
      var el = targets[k];
      if (!el) return;
      var active = (k === scope.value);
      el.classList.toggle('d-none', !active);
      el.disabled = !active;
    });
  }
  scope.addEventListener('change', sync);
  sync();
})();
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
