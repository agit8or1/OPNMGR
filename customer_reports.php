<?php
/**
 * Customer Reports: generate, browse, schedule and deliver service reports.
 * Schedules, delivery, share links and AI summaries ship disabled and are
 * switched on here by an administrator. Everything is filtered by staff
 * customer scope.
 * @since 3.79.0
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/scope.php';
require_once __DIR__ . '/inc/reports/actions.php';

require_permission('report.view');

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = '<div class="alert alert-danger">Invalid CSRF token.</div>';
    } else {
        $in = $_POST;
        if (isset($in['sections']) && !is_array($in['sections'])) { unset($in['sections']); }
        foreach (['report_schedules_enabled', 'report_delivery_enabled', 'report_share_enabled', 'report_ai_enabled'] as $k) {
            if (($in['action'] ?? '') === 'save_settings' && !isset($in[$k])) { $in[$k] = ''; }
        }
        $r = report_action((string)($in['action'] ?? ''), $in);
        $message = '<div class="alert alert-' . ($r['ok'] ? 'success' : 'danger') . '">' . htmlspecialchars($r['message']) . '</div>';
        if ($r['ok'] && !empty($r['data']['url'])) {
            $message .= '<div class="alert alert-warning">Share link (shown once): <code>' . htmlspecialchars($r['data']['url']) . '</code></div>';
        }
        if ($r['ok'] && ($in['action'] ?? '') === 'generate' && !empty($r['data']['report_id'])) {
            header('Location: /customer_report.php?id=' . (int)$r['data']['report_id']); exit;
        }
    }
}

[$sw, $sp] = scope_sql('customer_id');
$cs = db()->prepare("SELECT id, name FROM customers WHERE " . str_replace('customer_id', 'id', $sw) . " ORDER BY name"); $cs->execute($sp); $customers = $cs->fetchAll(PDO::FETCH_ASSOC);
$ss = db()->prepare("SELECT s.id, s.name, c.name cname FROM sites s JOIN customers c ON c.id = s.customer_id WHERE " . str_replace('customer_id', 's.customer_id', $sw) . " ORDER BY c.name, s.name"); $ss->execute($sp); $sites = $ss->fetchAll(PDO::FETCH_ASSOC);
$fs = db()->prepare("SELECT id, hostname FROM firewalls WHERE $sw ORDER BY hostname"); $fs->execute($sp); $fws = $fs->fetchAll(PDO::FETCH_ASSOC);
$unrestricted = scope_customer_ids() === null;

$rs = db()->prepare("SELECT id, title, report_type, customer_id, generated_at, pdf_status, ai_status FROM reports WHERE " . ($unrestricted ? '1=1' : $sw) . " ORDER BY id DESC LIMIT 50"); $rs->execute($unrestricted ? [] : $sp);
$reports = $rs->fetchAll(PDO::FETCH_ASSOC);
$js = db()->prepare("SELECT id, report_type, status, attempts, max_attempts, last_error, created_at FROM report_jobs WHERE " . ($unrestricted ? '1=1' : $sw) . " AND status IN ('queued','running','failed') ORDER BY id DESC LIMIT 30"); $js->execute($unrestricted ? [] : $sp);
$jobs = $js->fetchAll(PDO::FETCH_ASSOC);
$sch = db()->prepare("SELECT * FROM report_schedules WHERE " . ($unrestricted ? '1=1' : $sw) . " ORDER BY id"); $sch->execute($unrestricted ? [] : $sp);
$schedules = $sch->fetchAll(PDO::FETCH_ASSOC);
$recips = db()->query('SELECT r.*, c.name cname FROM report_recipients r LEFT JOIN customers c ON c.id = r.customer_id ORDER BY r.customer_id, r.email')->fetchAll(PDO::FETCH_ASSOC);
$recips = array_values(array_filter($recips, fn($r) => (int)$r['customer_id'] === 0 ? $unrestricted : scope_can_customer((int)$r['customer_id'])));

$on = fn($k) => report_setting($k, '0') === '1';
$csrf = csrf_token();
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
include __DIR__ . '/inc/header.php';
?>
<div class="container-fluid py-3">
  <h1 class="h3 mb-3"><i class="fas fa-file-lines me-2"></i>Customer Reports</h1>
  <?= $message ?>
  <?php if (!report_pdf_available()): ?><div class="alert alert-info">PDF output is unavailable: wkhtmltopdf is not installed. HTML and CSV reports still work.</div><?php endif; ?>

  <?php if (can('report.generate')): ?>
  <div class="card mb-4"><div class="card-header">Generate a report</div><div class="card-body">
    <form method="post" class="row g-3">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="generate">
      <div class="col-md-3"><label class="form-label">Report type</label>
        <select name="report_type" class="form-select" id="rt">
          <option value="customer">Customer summary</option><option value="site">Site</option><option value="firewall">Firewall</option>
          <?php if ($unrestricted): ?><option value="fleet">Fleet summary</option><?php endif; ?></select></div>
      <div class="col-md-3"><label class="form-label">Scope</label>
        <select name="scope_id" class="form-select">
          <optgroup label="Customers"><?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>" data-t="customer"><?= $e($c['name']) ?></option><?php endforeach; ?></optgroup>
          <optgroup label="Sites"><?php foreach ($sites as $c): ?><option value="<?= (int)$c['id'] ?>" data-t="site"><?= $e($c['cname'] . ' / ' . $c['name']) ?></option><?php endforeach; ?></optgroup>
          <optgroup label="Firewalls"><?php foreach ($fws as $c): ?><option value="<?= (int)$c['id'] ?>" data-t="firewall"><?= $e($c['hostname']) ?></option><?php endforeach; ?></optgroup>
        </select><div class="form-text">Pick an entry matching the type (fleet ignores this).</div></div>
      <div class="col-md-3"><label class="form-label">Period (in the scope's timezone)</label>
        <select name="preset" class="form-select"><option value="last_week">Last full week (Mon-Sun)</option><option value="last_month">Last calendar month</option>
          <option value="last_7_days">Previous 7 days</option><option value="last_30_days">Previous 30 days</option><option value="custom">Custom dates</option></select></div>
      <div class="col-md-3"><label class="form-label">Custom from / to</label>
        <div class="input-group"><input type="date" name="from" class="form-control"><input type="date" name="to" class="form-control"></div></div>
      <div class="col-12"><label class="form-label">Sections</label><div class="d-flex flex-wrap gap-3">
        <?php foreach (report_sections_catalog() as $k => $label): ?>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="sections[]" value="<?= $e($k) ?>" id="s_<?= $e($k) ?>" checked><label class="form-check-label" for="s_<?= $e($k) ?>"><?= $e($label) ?></label></div>
        <?php endforeach; ?></div></div>
      <div class="col-12 d-flex gap-4 align-items-center">
        <div class="form-check"><input class="form-check-input" type="checkbox" name="pdf" value="1" id="pdf" <?= report_pdf_available() ? 'checked' : 'disabled' ?>><label class="form-check-label" for="pdf">PDF</label></div>
        <?php if ($on('report_ai_enabled')): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="include_ai" value="1" id="ai"><label class="form-check-label" for="ai">AI narrative (built by the background worker)</label></div><?php endif; ?>
        <button class="btn btn-primary">Generate</button></div>
    </form></div></div>
  <?php endif; ?>

  <?php if ($jobs): ?>
  <div class="card mb-4"><div class="card-header">Jobs in progress or failed</div><div class="table-responsive"><table class="table table-sm mb-0">
    <tr><th>#</th><th>Type</th><th>Status</th><th>Attempts</th><th>Error</th><th></th></tr>
    <?php foreach ($jobs as $j): ?><tr><td><?= (int)$j['id'] ?></td><td><?= $e($j['report_type']) ?></td>
      <td><span class="badge bg-<?= $j['status'] === 'failed' ? 'danger' : 'secondary' ?>"><?= $e($j['status']) ?></span></td><td><?= (int)$j['attempts'] ?>/<?= (int)$j['max_attempts'] ?></td><td><?= $e($j['last_error']) ?></td>
      <td><?php if (can('report.generate')): ?><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="job_id" value="<?= (int)$j['id'] ?>">
        <?php if ($j['status'] === 'failed'): ?><button name="action" value="retry_job" class="btn btn-sm btn-outline-warning">Retry</button>
        <?php elseif ($j['status'] === 'queued'): ?><button name="action" value="cancel_job" class="btn btn-sm btn-outline-secondary">Cancel</button><?php endif; ?></form><?php endif; ?></td></tr>
    <?php endforeach; ?></table></div></div>
  <?php endif; ?>

  <div class="card mb-4"><div class="card-header">Generated reports (immutable)</div><div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <tr><th>#</th><th>Report</th><th>Generated</th><th>Formats</th><th>AI</th></tr>
    <?php foreach ($reports as $r): ?><tr><td><a href="/customer_report.php?id=<?= (int)$r['id'] ?>"><?= (int)$r['id'] ?></a></td><td><?= $e($r['title']) ?></td><td><?= $e($r['generated_at']) ?></td>
      <td>HTML, CSV<?= $r['pdf_status'] === 'ok' ? ', PDF' : ($r['pdf_status'] === 'failed' ? ' (PDF failed)' : '') ?></td><td><?= $e($r['ai_status']) ?></td></tr>
    <?php endforeach; if (!$reports): ?><tr><td colspan="5" class="text-muted">No reports yet.</td></tr><?php endif; ?></table></div></div>

  <?php if (can('report.manage')): ?>
  <div class="card mb-4"><div class="card-header">Schedules <span class="badge bg-<?= $on('report_schedules_enabled') ? 'success' : 'secondary' ?>">global switch <?= $on('report_schedules_enabled') ? 'on' : 'off' ?></span></div><div class="card-body">
    <table class="table table-sm"><tr><th>Name</th><th>Type</th><th>Cadence</th><th>Next run</th><th>Deliver</th><th>State</th><th></th></tr>
    <?php foreach ($schedules as $s): ?><tr><td><?= $e($s['name']) ?></td><td><?= $e($s['report_type']) ?></td><td><?= $e($s['cadence']) ?> d<?= (int)$s['run_day'] ?> @<?= (int)$s['run_hour'] ?>h</td><td><?= $e($s['next_run_at']) ?></td><td><?= $s['deliver'] ? 'yes' : 'no' ?></td>
      <td><span class="badge bg-<?= $s['enabled'] ? 'success' : 'secondary' ?>"><?= $s['enabled'] ? 'enabled' : 'disabled' ?></span></td>
      <td><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
        <?php if (can('report.deliver') && !$s['enabled']): ?><input type="hidden" name="enabled" value="1"><button name="action" value="toggle_schedule" class="btn btn-sm btn-outline-success">Enable</button>
        <?php elseif ($s['enabled']): ?><button name="action" value="toggle_schedule" class="btn btn-sm btn-outline-secondary">Disable</button><?php endif; ?>
        <button name="action" value="delete_schedule" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete schedule?')">Delete</button></form></td></tr>
    <?php endforeach; ?></table>
    <form method="post" class="row g-2"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="save_schedule">
      <div class="col-md-2"><input name="name" class="form-control" placeholder="Name"></div>
      <div class="col-md-2"><select name="report_type" class="form-select"><option value="customer">Customer</option><option value="site">Site</option><option value="firewall">Firewall</option><?php if ($unrestricted): ?><option value="fleet">Fleet</option><?php endif; ?></select></div>
      <div class="col-md-2"><input name="scope_id" type="number" class="form-control" placeholder="Customer/site/firewall id"></div>
      <div class="col-md-2"><select name="cadence" class="form-select"><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></div>
      <div class="col-md-1"><input name="run_day" type="number" min="1" max="28" value="1" class="form-control" title="weekly 1=Mon..7=Sun; monthly 1-28"></div>
      <div class="col-md-1"><input name="run_hour" type="number" min="0" max="23" value="6" class="form-control" title="local hour"></div>
      <div class="col-md-1 form-check pt-2"><input class="form-check-input" type="checkbox" name="deliver" value="1" id="dl"><label class="form-check-label" for="dl">Email</label></div>
      <div class="col-md-1"><button class="btn btn-outline-primary">Add</button></div>
      <div class="form-text">New schedules are always created disabled.</div></form></div></div>

  <div class="card mb-4"><div class="card-header">Recipients (per customer; customer 0 = internal, for fleet reports)</div><div class="card-body">
    <table class="table table-sm"><tr><th>Customer</th><th>Email</th><th>Name</th><th></th></tr>
    <?php foreach ($recips as $r): ?><tr><td><?= $e($r['cname'] ?? 'internal') ?></td><td><?= $e($r['email']) ?></td><td><?= $e($r['name']) ?></td>
      <td><form method="post"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="customer_id" value="<?= (int)$r['customer_id'] ?>"><button name="action" value="delete_recipient" class="btn btn-sm btn-outline-danger">Remove</button></form></td></tr>
    <?php endforeach; ?></table>
    <form method="post" class="row g-2"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="save_recipient">
      <div class="col-md-3"><select name="customer_id" class="form-select"><?php if ($unrestricted): ?><option value="0">Internal</option><?php endif; foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>"><?= $e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><input type="email" name="email" class="form-control" placeholder="email" required></div>
      <div class="col-md-3"><input name="name" class="form-control" placeholder="name (optional)"></div>
      <div class="col-md-2"><button class="btn btn-outline-primary">Add recipient</button></div></form></div></div>
  <?php endif; ?>

  <?php if (can('report.deliver')): ?>
  <div class="card mb-4"><div class="card-header">Switches and retention (administrators)</div><div class="card-body">
    <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="save_settings">
      <?php foreach (['report_schedules_enabled' => 'Run schedules', 'report_delivery_enabled' => 'Send report emails', 'report_share_enabled' => 'Allow share links'] + (can('ai.manage') ? ['report_ai_enabled' => 'Allow AI narratives'] : []) as $k => $l): ?>
        <div class="col-md-3 form-check form-switch ps-5"><input class="form-check-input" type="checkbox" name="<?= $e($k) ?>" value="1" id="<?= $e($k) ?>" <?= $on($k) ? 'checked' : '' ?>><label class="form-check-label" for="<?= $e($k) ?>"><?= $e($l) ?></label></div>
      <?php endforeach; ?>
      <div class="col-md-3"><label class="form-label">Retention (days, min 30)</label><input type="number" name="report_retention_days" class="form-control" value="<?= (int)report_setting('report_retention_days', 400) ?>"></div>
      <div class="col-md-3"><label class="form-label">Max share link lifetime (hours)</label><input type="number" name="report_share_max_hours" class="form-control" value="<?= (int)report_setting('report_share_max_hours', 168) ?>"></div>
      <div class="col-12"><button class="btn btn-primary">Save</button></div></form></div></div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
