<?php
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();

$notice = '';

// load settings
$rows = db()->query('SELECT `name`,`value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$acme_domain = $rows['acme_domain'] ?? '';
$acme_email = $rows['acme_email'] ?? '';

// helpers
function save_setting($k,$v){
    $s = db()->prepare('INSERT INTO settings (`name`,`value`) VALUES (:k,:v) ON DUPLICATE KEY UPDATE `value` = :v2');
    $s->execute([':k' => $k, ':v' => $v, ':v2' => $v]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) { $notice = 'Bad CSRF'; }
  else {
    // ACME actions
    if (!empty($_POST['save_acme'])) {
      $acme_domain = trim($_POST['acme_domain'] ?? '');
      $acme_email = trim($_POST['acme_email'] ?? '');
      save_setting('acme_domain',$acme_domain);
      save_setting('acme_email',$acme_email);
      $notice = 'ACME settings saved.';
    }
    if (!empty($_POST['acme_request'])) {
      $acme_domain = trim($_POST['acme_domain'] ?? '');
      $acme_email = trim($_POST['acme_email'] ?? '');
      if ($acme_domain === '' || $acme_email === '') { $notice = 'Domain and email required'; }
      else {
        // persist acme settings before attempting certbot
        save_setting('acme_domain',$acme_domain);
        save_setting('acme_email',$acme_email);
        // attempt certbot via restricted wrapper (uses sudo)
        $wrapper = '/usr/local/sbin/opnmgr-certbot-wrapper';
        if (!is_executable($wrapper)) { $notice = 'Certbot wrapper not available'; }
        else {
          // use dry-run to be safe from the web UI; operator can run full via UI if desired
          $run = sprintf('sudo %s %s %s', escapeshellcmd($wrapper), escapeshellarg($acme_domain), escapeshellarg($acme_email));
          $out = shell_exec($run . ' 2>&1');
          $notice = 'Certbot run: '.htmlspecialchars(substr($out,0,800));
        }
      }
    }
  }
}

// Gather ACME cert info
$cert_info = null;
if (!empty($acme_domain)) {
  $candidates = [
    '/etc/letsencrypt/live/'.$acme_domain.'/fullchain.pem',
    '/var/log/opnmgr/config/live/'.$acme_domain.'/fullchain.pem',
    '/var/log/opnmgr/config/live/'.str_replace('www.','',$acme_domain).'/fullchain.pem'
  ];
  foreach ($candidates as $rawpath) {
    if (file_exists($rawpath)) {
      $end = trim(shell_exec('openssl x509 -enddate -noout -in '.escapeshellarg($rawpath).' 2>/dev/null'));
      if (preg_match('/=([\d\w:\- ]+)$/',$end,$m)) { $cert_info = $m[1]; break; }
    }
  }
}

include __DIR__ . '/inc/header.php';
?>
<div class="d-flex align-items-center mb-4">
  <a href="settings.php" class="btn btn-outline-light me-3">
    <i class="fas fa-arrow-left me-2"></i>Back to Settings
  </a>
  <h4 class="mb-0">ACME / Certificates</h4>
</div>
<?php if ($notice): ?><div class="alert alert-info"><?php echo $notice; ?></div><?php endif; ?>
<div class="row">
  <div class="col-md-12">
    <div class="card card-dark p-3">
      <h5>ACME / Certificates</h5>
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <div class="mb-2">
          <label class="form-label">Domain</label>
          <input name="acme_domain" class="form-control" value="<?php echo htmlspecialchars($acme_domain); ?>">
        </div>
        <div class="mb-2">
          <label class="form-label">Email</label>
          <input name="acme_email" class="form-control" value="<?php echo htmlspecialchars($acme_email); ?>">
        </div>
        <div class="d-flex gap-2 align-items-center">
          <button class="btn btn-primary" name="save_acme" value="1">Save</button>
          <button class="btn btn-success" name="acme_request" value="1">Request Certificate</button>
          <a href="/ajax/certbot_log.php" class="btn btn-sm btn-warning">View Log</a>
        </div>
      </form>
      <?php if ($cert_info): ?>
        <div class="mt-2">Certificate expires at: <strong><?php echo htmlspecialchars($cert_info); ?></strong></div>
      <?php else: ?>
        <div class="mt-2 text-muted">No certificate found for this domain.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
