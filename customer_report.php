<?php
/**
 * One generated report: preview, downloads, provenance and share links.
 * @since 3.79.0
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/scope.php';
require_once __DIR__ . '/inc/reports/actions.php';

require_permission('report.view');
$rep = report_fetch_for_user((int)($_GET['id'] ?? 0));   // unknown and out-of-scope look the same
if (!$rep) { http_response_code(404); include __DIR__ . '/inc/header.php'; echo '<div class="container py-4"><div class="alert alert-warning">Report not found.</div></div>'; include __DIR__ . '/inc/footer.php'; exit; }

// Preview frame: the stored, hash-verified HTML under a restrictive CSP.
if (isset($_GET['frame'])) {
    report_audit('report.view', ['object_type' => 'report', 'object_id' => $rep['id'], 'customer_id' => $rep['customer_id'] === null ? null : (int)$rep['customer_id']]);
    report_serve($rep, 'html', true);
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) { $message = '<div class="alert alert-danger">Invalid CSRF token.</div>'; }
    else {
        $_POST['report_id'] = $rep['id'];
        $r = report_action((string)($_POST['action'] ?? ''), $_POST);
        $message = '<div class="alert alert-' . ($r['ok'] ? 'success' : 'danger') . '">' . htmlspecialchars($r['message']) . '</div>';
        if ($r['ok'] && !empty($r['data']['url'])) { $message .= '<div class="alert alert-warning">Share link (shown once): <code>' . htmlspecialchars($r['data']['url']) . '</code></div>'; }
    }
}
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$snap = json_decode($rep['snapshot_json'], true) ?: [];
$shares = db()->prepare('SELECT id, expires_at, revoked_at, created_by, last_accessed_at, access_count FROM report_shares WHERE report_id = ? ORDER BY id DESC'); $shares->execute([$rep['id']]); $shares = $shares->fetchAll(PDO::FETCH_ASSOC);
$dels = db()->prepare('SELECT recipient_address, status, attempts, last_error, sent_at FROM report_deliveries WHERE report_id = ?'); $dels->execute([$rep['id']]); $dels = $dels->fetchAll(PDO::FETCH_ASSOC);
$id = (int)$rep['id']; $csrf = csrf_token();
include __DIR__ . '/inc/header.php';
?>
<div class="container-fluid py-3">
  <a href="/customer_reports.php" class="btn btn-sm btn-outline-secondary mb-2">&larr; Reports</a>
  <h1 class="h4"><?= $e($rep['title']) ?></h1>
  <?= $message ?>
  <div class="mb-3">
    <a class="btn btn-primary btn-sm" href="/api/report_download.php?id=<?= $id ?>&fmt=html">HTML</a>
    <a class="btn btn-outline-primary btn-sm" href="/api/report_download.php?id=<?= $id ?>&fmt=csv">CSV</a>
    <?php if ($rep['pdf_path']): ?><a class="btn btn-outline-primary btn-sm" href="/api/report_download.php?id=<?= $id ?>&fmt=pdf">PDF</a>
    <?php else: ?><span class="text-muted small ms-2">PDF: <?= $e($rep['pdf_status']) ?><?= $rep['pdf_error'] ? ' - ' . $e($rep['pdf_error']) : '' ?></span><?php endif; ?>
  </div>
  <div class="card mb-3"><div class="card-body small">
    Generated <?= $e($rep['generated_at']) ?> by <?= $e($rep['generated_by'] ?? 'system') ?> &middot; timezone <?= $e($rep['timezone']) ?> &middot; AI: <?= $e($rep['ai_status']) ?><br>
    SHA-256 (HTML): <code><?= $e($rep['html_sha256']) ?></code><br>Snapshot SHA-256: <code><?= $e($rep['snapshot_sha256']) ?></code> &middot; metric definitions <?= $e($snap['generator']['metric_definitions'] ?? '?') ?>
    <br>Reports are immutable; every download is re-hashed and refused on mismatch.
  </div></div>
  <iframe src="/customer_report.php?id=<?= $id ?>&frame=1" sandbox="" style="width:100%;height:75vh;background:#fff;border:1px solid #888" title="Report preview"></iframe>

  <?php if (can('report.deliver')): ?>
  <div class="card mt-3"><div class="card-header">Share links</div><div class="card-body">
    <?php if (report_setting('report_share_enabled', '0') !== '1'): ?><p class="text-muted">Share links are disabled. Enable them on the Reports page.</p><?php else: ?>
    <form method="post" class="row g-2 mb-3"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="create_share">
      <div class="col-auto"><input type="number" name="hours" value="72" min="1" class="form-control" style="width:110px" title="hours until expiry"></div><div class="col-auto"><button class="btn btn-outline-primary">Create link</button></div></form>
    <?php endif; ?>
    <table class="table table-sm"><tr><th>#</th><th>Expires</th><th>Status</th><th>Uses</th><th></th></tr>
    <?php foreach ($shares as $s): $live = !$s['revoked_at'] && strtotime($s['expires_at']) > time(); ?>
      <tr><td><?= (int)$s['id'] ?></td><td><?= $e($s['expires_at']) ?></td><td><?= $s['revoked_at'] ? 'revoked' : ($live ? 'active' : 'expired') ?></td><td><?= (int)$s['access_count'] ?></td>
      <td><?php if ($live): ?><form method="post"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="share_id" value="<?= (int)$s['id'] ?>"><button name="action" value="revoke_share" class="btn btn-sm btn-outline-danger">Revoke</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></table>
    <?php if ($dels): ?><h6>Deliveries</h6><table class="table table-sm"><tr><th>Recipient</th><th>Status</th><th>Attempts</th><th>Error</th></tr>
      <?php foreach ($dels as $d): ?><tr><td><?= $e($d['recipient_address']) ?></td><td><?= $e($d['status']) ?></td><td><?= (int)$d['attempts'] ?></td><td><?= $e($d['last_error']) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
  </div></div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
