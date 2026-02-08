<?php
require_once __DIR__ . '/inc/auth.php';
requireLogin();
requireAdmin();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/csrf.php';

$notice = '';
$logoUrl = '/assets/img/logo.png';

// load settings
$rows = $DB->query('SELECT `name`,`value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$brand = $rows['brand_name'] ?? 'OPNsense Manager';
$acme_domain = $rows['acme_domain'] ?? '';
$acme_email = $rows['acme_email'] ?? '';
$smtp_host = $rows['smtp_host'] ?? '';
$smtp_port = $rows['smtp_port'] ?? '587';
$smtp_username = $rows['smtp_username'] ?? '';
$smtp_password = $rows['smtp_password'] ?? '';
$smtp_encryption = $rows['smtp_encryption'] ?? 'tls';
$proxy_port_start = $rows['proxy_port_start'] ?? '8100';
$proxy_port_end = $rows['proxy_port_end'] ?? '8199';
$backup_retention_months = $rows['backup_retention_months'] ?? '2';

// helpers
function save_setting($DB,$k,$v){
    $s = $DB->prepare('INSERT INTO settings (`name`,`value`) VALUES (:k,:v) ON DUPLICATE KEY UPDATE `value` = :v2');
    $s->execute([':k' => $k, ':v' => $v, ':v2' => $v]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) { $notice = 'Bad CSRF'; }
  else {
    // Branding save
    if (!empty($_POST['save_brand'])) {
      $brand = trim($_POST['brand'] ?? $brand);
      save_setting($DB,'brand_name',$brand);
      // logo upload
      if (!empty($_FILES['logo']['tmp_name'])) {
        $allowed = ['image/png','image/jpeg','image/svg+xml'];
        if (in_array($_FILES['logo']['type'],$allowed) && $_FILES['logo']['size'] < 2000000) {
          if (!is_dir(__DIR__.'/assets/img')) mkdir(__DIR__.'/assets/img',0755,true);
          move_uploaded_file($_FILES['logo']['tmp_name'], __DIR__.'/assets/img/logo.png');
          chown(__DIR__.'/assets/img/logo.png', 'www-data');
          chmod(__DIR__.'/assets/img/logo.png', 0644);
        } else { $notice = 'Logo must be PNG/JPG/SVG and <2MB'; }
      }
      $notice = $notice ?: 'Branding saved.';
    }

    // ACME actions
    if (!empty($_POST['save_acme'])) {
      $acme_domain = trim($_POST['acme_domain'] ?? '');
      $acme_email = trim($_POST['acme_email'] ?? '');
      save_setting($DB,'acme_domain',$acme_domain);
      save_setting($DB,'acme_email',$acme_email);
      $notice = 'ACME settings saved.';
    }

    // SMTP actions
    if (!empty($_POST['save_smtp'])) {
      $smtp_host = trim($_POST['smtp_host'] ?? '');
      $smtp_port = trim($_POST['smtp_port'] ?? '587');
      $smtp_username = trim($_POST['smtp_username'] ?? '');
      $smtp_password = trim($_POST['smtp_password'] ?? '');
      $smtp_encryption = trim($_POST['smtp_encryption'] ?? 'tls');
      
      save_setting($DB,'smtp_host',$smtp_host);
      save_setting($DB,'smtp_port',$smtp_port);
      save_setting($DB,'smtp_username',$smtp_username);
      save_setting($DB,'smtp_password',$smtp_password);
      save_setting($DB,'smtp_encryption',$smtp_encryption);
      $notice = 'SMTP settings saved.';
    }

    // Proxy settings actions
    if (!empty($_POST['save_proxy'])) {
      $proxy_port_start = trim($_POST['proxy_port_start'] ?? '8100');
      $proxy_port_end = trim($_POST['proxy_port_end'] ?? '8199');
      
      // Validate port ranges
      if (!is_numeric($proxy_port_start) || !is_numeric($proxy_port_end) || 
          $proxy_port_start < 1024 || $proxy_port_end > 65535 || 
          $proxy_port_start >= $proxy_port_end) {
        $notice = 'Invalid port range. Start must be >= 1024, end <= 65535, and start < end.';
      } else {
        save_setting($DB,'proxy_port_start',$proxy_port_start);
        save_setting($DB,'proxy_port_end',$proxy_port_end);
        $notice = 'Proxy settings saved.';
      }
    }
    
    // Backup retention settings actions
    if (!empty($_POST['save_backup_retention'])) {
      $backup_retention_months_input = (int)trim($_POST['backup_retention_months'] ?? '2');
      
      // Validate: between 1 and 6 months
      if ($backup_retention_months_input < 1 || $backup_retention_months_input > 6) {
        $notice = 'Backup retention must be between 1 and 6 months.';
      } else {
        save_setting($DB,'backup_retention_months', $backup_retention_months_input);
        $backup_retention_months = $backup_retention_months_input; // Update the variable
        $notice = "Backup retention set to $backup_retention_months months.";
      }
    }
    
    if (!empty($_POST['acme_request'])) {
      $acme_domain = trim($_POST['acme_domain'] ?? '');
      $acme_email = trim($_POST['acme_email'] ?? '');
      if ($acme_domain === '' || $acme_email === '') { $notice = 'Domain and email required'; }
      else {
        // persist acme settings before attempting certbot
        save_setting($DB,'acme_domain',$acme_domain);
        save_setting($DB,'acme_email',$acme_email);
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

    // Fail2Ban actions
    if (!empty($_POST['fail2ban_action'])) {
      $jail = trim($_POST['jail'] ?? '');
      $ip = trim($_POST['ip'] ?? '');
      $act = $_POST['fail2ban_action'];
      $fbin = trim(shell_exec('command -v fail2ban-client || true'));
      if (empty($fbin)) { $notice = 'fail2ban-client not available'; }
      else {
        if ($act === 'ban' && filter_var($ip, FILTER_VALIDATE_IP)) {
          $cmd = sprintf('%s set %s banip %s', escapeshellcmd($fbin), escapeshellarg($jail), escapeshellarg($ip));
          shell_exec($cmd . ' 2>&1');
          $notice = 'Ban command issued.';
        } elseif ($act === 'unban' && filter_var($ip, FILTER_VALIDATE_IP)) {
          $cmd = sprintf('%s set %s unbanip %s', escapeshellcmd($fbin), escapeshellarg($jail), escapeshellarg($ip));
          shell_exec($cmd . ' 2>&1');
          $notice = 'Unban command issued.';
        } elseif ($act === 'reload') {
          shell_exec(escapeshellcmd($fbin) . ' reload 2>&1');
          $notice = 'fail2ban reload requested.';
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

// Fail2Ban status
$fbin = trim(shell_exec('command -v fail2ban-client || true'));
$jails = [];
if (!empty($fbin)) {
  $s = trim(shell_exec($fbin.' status 2>/dev/null'));
  if (preg_match('/Jail list:\s*(.*)$/m',$s,$m)) {
    $list = array_map('trim',explode(',', $m[1]));
    foreach($list as $j) if ($j) $jails[] = $j;
  }
}

include __DIR__ . '/inc/header.php';
?>
<h4>Settings</h4>
<div class="row g-4">
  <div class="col-md-4">
    <div class="card card-dark h-100">
      <div class="card-body text-center">
        <div class="mb-3">
          <i class="fas fa-palette fa-3x text-primary"></i>
        </div>
        <h5 class="card-title">Branding</h5>
        <p class="card-text text-muted">Customize brand name, colors, and logo</p>
        <a href="branding.php" class="btn btn-primary">
          <i class="fas fa-arrow-right me-2"></i>Configure
        </a>
      </div>
    </div>
  </div>
  
  <div class="col-md-4">
    <div class="card card-dark h-100">
      <div class="card-body text-center">
        <div class="mb-3">
          <i class="fas fa-certificate fa-3x text-success"></i>
        </div>
        <h5 class="card-title">ACME / Certificates</h5>
        <p class="card-text text-muted">Manage SSL certificates with Let's Encrypt</p>
        <a href="acme.php" class="btn btn-success">
          <i class="fas fa-arrow-right me-2"></i>Configure
        </a>
      </div>
    </div>
  </div>
  
    <div class="col-md-4">
    <div class="card card-dark h-100">
      <div class="card-body text-center">
        <div class="mb-3">
          <i class="fas fa-envelope fa-3x text-info"></i>
        </div>
        <h5 class="card-title">SMTP Settings</h5>
        <p class="card-text text-light">Configure email server settings</p>
        <a href="smtp_settings.php" class="btn btn-info">
          <i class="fas fa-arrow-right me-2"></i>Configure
        </a>
      </div>
    </div>
  </div>
  
  <div class="col-md-4">
    <div class="card card-dark h-100">
      <div class="card-body text-center">
        <div class="mb-3">
          <i class="fas fa-bell fa-3x text-warning"></i>
        </div>
        <h5 class="card-title">Alert Settings</h5>
        <p class="card-text text-light">Configure email and Pushover alerts</p>
        <a href="alerts.php" class="btn btn-warning">
          <i class="fas fa-arrow-right me-2"></i>Configure
        </a>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card card-dark h-100">
      <div class="card-body text-center">
        <div class="mb-3">
          <i class="fas fa-shield-alt fa-3x text-danger"></i>
        </div>
        <h5 class="card-title">Fail2Ban</h5>
        <p class="card-text text-muted">Configure brute-force protection and IP blocking</p>
        <a href="fail2ban.php" class="btn btn-danger">
          <i class="fas fa-arrow-right me-2"></i>Configure
        </a>
      </div>
    </div>
  </div>
  
  <div class="col-md-4">
    <div class="card card-dark h-100">
      <div class="card-body text-center">
        <div class="mb-3">
          <i class="fas fa-database fa-3x text-primary"></i>
        </div>
        <h5 class="card-title">Backup Retention</h5>
        <p class="card-text text-light">Manage automated backup retention</p>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#backupRetentionModal">
          <i class="fas fa-arrow-right me-2"></i>Configure
        </button>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card card-dark h-100">
      <div class="card-body text-center">
        <div class="mb-3">
          <i class="fas fa-shield-alt fa-3x text-danger"></i>
        </div>
        <h5 class="card-title">Security Scanner</h5>
        <p class="card-text text-light">Snyk vulnerability scanning and code analysis</p>
        <a href="security_scan.php" class="btn btn-danger">
          <i class="fas fa-arrow-right me-2"></i>Configure
        </a>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card card-dark h-100">
      <div class="card-body text-center">
        <div class="mb-3">
          <i class="fas fa-globe fa-3x text-info"></i>
        </div>
        <h5 class="card-title">GeoIP Blocking</h5>
        <p class="card-text text-muted">Block traffic from specific countries</p>
        <a href="geoip.php" class="btn btn-info">
          <i class="fas fa-arrow-right me-2"></i>Configure
        </a>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card card-dark h-100">
      <div class="card-body text-center">
        <div class="mb-3">
          <i class="fas fa-key fa-3x text-warning"></i>
        </div>
        <h5 class="card-title">License Management</h5>
        <p class="card-text text-muted">View and manage software licenses</p>
        <a href="license.php" class="btn btn-warning">
          <i class="fas fa-arrow-right me-2"></i>View License
        </a>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card card-dark h-100">
      <div class="card-body text-center">
        <div class="mb-3">
          <i class="fas fa-network-wired fa-3x text-success"></i>
        </div>
        <h5 class="card-title">Tunnel Management</h5>
        <p class="card-text text-muted">Manage SSH tunnels and proxy connections</p>
        <a href="proxy_settings.php" class="btn btn-success">
          <i class="fas fa-arrow-right me-2"></i>Configure
        </a>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card card-dark h-100">
      <div class="card-body text-center">
        <div class="mb-3">
          <i class="fas fa-clock fa-3x text-primary"></i>
        </div>
        <h5 class="card-title">General Settings</h5>
        <p class="card-text text-muted">Timezone, preferences, and system settings</p>
        <a href="branding.php" class="btn btn-primary">
          <i class="fas fa-arrow-right me-2"></i>Configure
        </a>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card card-dark h-100">
      <div class="card-body text-center">
        <div class="mb-3">
          <i class="fas fa-database fa-3x text-info"></i>
        </div>
        <h5 class="card-title">System Backup</h5>
        <p class="card-text text-muted">Backup and restore system configuration</p>
        <a href="backups.php" class="btn btn-info">
          <i class="fas fa-arrow-right me-2"></i>Manage Backups
        </a>
      </div>
    </div>
  </div>

</div>
</div>

<style>
.card-dark {
  background-color: #1a1a1a;
  border: 1px solid #333;
  transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
}

.card-dark:hover {
  transform: translateY(-5px);
  box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
}

.card-title {
  color: #fff;
  margin-bottom: 0.5rem;
}

.card-text {
  color: #adb5bd;
}

.btn-primary {
  background-color: #00d4c4;
  border-color: #00d4c4;
}

.btn-primary:hover {
  background-color: #00b8a6;
  border-color: #00b8a6;
}

.btn-success {
  background-color: #28a745;
  border-color: #28a745;
}

.btn-success:hover {
  background-color: #218838;
  border-color: #218838;
}

.btn-warning {
  background-color: #ffc107;
  border-color: #ffc107;
  color: #212529;
}

.btn-warning:hover {
  background-color: #e0a800;
  border-color: #d39e00;
  color: #212529;
}
</style>

<!-- SMTP Configuration Modal -->
<div class="modal fade" id="smtpModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">SMTP Configuration</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <div class="modal-body">
          <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
          <div class="row">
            <div class="col-md-6">
              <div class="mb-3">
                <label for="smtp_host" class="form-label">SMTP Host</label>
                <input type="text" class="form-control" id="smtp_host" name="smtp_host" value="<?php echo htmlspecialchars($smtp_host); ?>" placeholder="smtp.gmail.com">
              </div>
            </div>
            <div class="col-md-6">
              <div class="mb-3">
                <label for="smtp_port" class="form-label">SMTP Port</label>
                <input type="number" class="form-control" id="smtp_port" name="smtp_port" value="<?php echo htmlspecialchars($smtp_port); ?>" placeholder="587">
              </div>
            </div>
          </div>
          <div class="mb-3">
            <label for="smtp_username" class="form-label">SMTP Username</label>
            <input type="text" class="form-control" id="smtp_username" name="smtp_username" value="<?php echo htmlspecialchars($smtp_username); ?>" placeholder="your-email@gmail.com">
          </div>
          <div class="mb-3">
            <label for="smtp_password" class="form-label">SMTP Password</label>
            <input type="password" class="form-control" id="smtp_password" name="smtp_password" value="<?php echo htmlspecialchars($smtp_password); ?>" placeholder="App password or SMTP password">
          </div>
          <div class="mb-3">
            <label for="smtp_encryption" class="form-label">Encryption</label>
            <select class="form-select" id="smtp_encryption" name="smtp_encryption">
              <option value="tls" <?php echo $smtp_encryption === 'tls' ? 'selected' : ''; ?>>TLS</option>
              <option value="ssl" <?php echo $smtp_encryption === 'ssl' ? 'selected' : ''; ?>>SSL</option>
              <option value="none" <?php echo $smtp_encryption === 'none' ? 'selected' : ''; ?>>None</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="save_smtp" class="btn btn-primary">Save SMTP Settings</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Backup Retention Configuration Modal -->
<div class="modal fade" id="backupRetentionModal" tabindex="-1" aria-labelledby="backupRetentionModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-dark text-light">
      <div class="modal-header border-secondary">
        <h5 class="modal-title" id="backupRetentionModalLabel">Backup Retention Configuration</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post">
        <div class="modal-body">
          <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
          <div class="alert alert-info bg-opacity-25">
            <i class="fas fa-info-circle me-2"></i>
            Configure how long to keep firewall configuration backups. Older backups will be automatically deleted during the nightly backup process.
          </div>
          <div class="mb-3">
            <label for="backup_retention_months" class="form-label">Retention Period (Months)</label>
            <input type="number" class="form-control bg-dark text-light border-secondary" id="backup_retention_months" name="backup_retention_months"
                   value="<?php echo htmlspecialchars($backup_retention_months); ?>"
                   min="1" max="6" step="1" required>
            <div class="form-text text-light-emphasis">Keep backups for 1-6 months (default: 2 months)</div>
          </div>
          <div class="alert alert-warning bg-opacity-25">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Note:</strong> Backups older than the retention period will be permanently deleted. This helps manage disk space.
          </div>
        </div>
        <div class="modal-footer border-secondary">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="save_backup_retention" class="btn btn-success">Save Retention Settings</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Debug: Test Bootstrap modal functionality
</script>

<style>
/* Fix modal z-index and display issues */
.modal-backdrop {
    z-index: 1040 !important;
}
.modal {
    z-index: 1050 !important;
}
.modal-dialog {
    z-index: 1060 !important;
}
/* Force modal to display when shown */
.modal.show {
    display: block !important;
    opacity: 1 !important;
}
.modal-content {
    position: relative;
    z-index: 1061 !important;
}
</style>

<?php include __DIR__ . "/inc/footer.php"; ?>

