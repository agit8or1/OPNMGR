<?php
require_once __DIR__ . '/inc/auth.php';
requireLogin();
requireAdmin();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/csrf.php';

$notice = '';
$logoUrl = '/assets/img/logo.png';

// Check for notice in URL parameter (from redirects)
if (!empty($_GET['notice'])) {
  $notice = $_GET['notice'];
}

// load settings
$rows = $DB->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$brand = $rows['brand_name'] ?? 'OPNsense Manager';
$manager_fqdn = $rows['manager_fqdn'] ?? 'opn.agit8or.net';
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
$backup_retention_type = $rows['backup_retention_type'] ?? 'count';
$backup_min_keep = $rows['backup_min_keep'] ?? '30';
$backup_max_keep = $rows['backup_max_keep'] ?? '90';

// helpers
function save_setting($DB,$k,$v){
    $s = $DB->prepare('INSERT INTO settings (`name`,`value`) VALUES (:k,:v) ON DUPLICATE KEY UPDATE `value` = :v2');
    $s->execute([':k' => $k, ':v' => $v, ':v2' => $v]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) { $notice = 'Bad CSRF'; }
  else {
    // General Settings (Timezone and FQDN)
    if (!empty($_POST['save_general'])) {
      $timezone = trim($_POST['timezone'] ?? 'UTC');
      $manager_fqdn_input = trim($_POST['manager_fqdn'] ?? '');

      $errors = [];

      // Validate timezone
      if (!in_array($timezone, timezone_identifiers_list())) {
        $errors[] = 'Invalid timezone selected.';
      }

      // Validate FQDN (basic validation)
      if (!empty($manager_fqdn_input)) {
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/', $manager_fqdn_input)) {
          $errors[] = 'Invalid FQDN format.';
        }
      }

      if (empty($errors)) {
        $_SESSION['display_timezone'] = $timezone;
        save_setting($DB, 'system_timezone', $timezone);

        if (!empty($manager_fqdn_input)) {
          save_setting($DB, 'manager_fqdn', $manager_fqdn_input);
          $manager_fqdn = $manager_fqdn_input;
        }

        $notice = 'General settings saved.';
      } else {
        $notice = implode(' ', $errors);
      }
    }
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
      error_log("BACKUP RETENTION SAVE: POST data: " . json_encode($_POST));
      $retention_type = trim($_POST['backup_retention_type'] ?? 'count');
      error_log("BACKUP RETENTION: type=$retention_type");
      
      if ($retention_type === 'time') {
        $backup_retention_months_input = (int)trim($_POST['backup_retention_months'] ?? '2');
        
        // Validate: between 1 and 6 months
        if ($backup_retention_months_input < 1 || $backup_retention_months_input > 6) {
          $notice = 'Backup retention must be between 1 and 6 months.';
        } else {
          save_setting($DB,'backup_retention_type', 'time');
          save_setting($DB,'backup_retention_months', $backup_retention_months_input);
          $backup_retention_type = 'time';
          $backup_retention_months = $backup_retention_months_input;
          header('Location: /settings.php?notice=' . urlencode("Backup retention set to $backup_retention_months_input months."));
          exit;
        }
      } else {
        // Count-based retention
        $min_keep = (int)trim($_POST['backup_min_keep'] ?? '30');
        $max_keep = (int)trim($_POST['backup_max_keep'] ?? '90');
        
        // Validate
        if ($min_keep < 10 || $min_keep > 100) {
          $notice = 'Minimum backups must be between 10 and 100.';
        } elseif ($max_keep < 20 || $max_keep > 200) {
          $notice = 'Maximum backups must be between 20 and 200.';
        } elseif ($min_keep >= $max_keep) {
          $notice = 'Minimum must be less than maximum.';
        } else {
          save_setting($DB,'backup_retention_type', 'count');
          save_setting($DB,'backup_min_keep', $min_keep);
          save_setting($DB,'backup_max_keep', $max_keep);
          $backup_retention_type = 'count';
          $backup_min_keep = $min_keep;
          $backup_max_keep = $max_keep;
          header('Location: /settings.php?notice=' . urlencode("Backup retention set to keep last $min_keep-$max_keep backups per firewall."));
          exit;
        }
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
<div class="row g-2">
  <!-- ACME / Certificates -->
  <div class="col-md-3">
    <div class="card card-dark h-100">
      <div class="card-body text-center p-2">
        <div class="mb-2">
          <i class="fas fa-certificate fa-2x text-success"></i>
        </div>
        <h6 class="card-title">ACME / Certificates</h6>
        <p class="card-text text-muted small">SSL certificates</p>
        <a href="acme.php" class="btn btn-success btn-sm w-100">
          <i class="fas fa-cog me-1"></i>Configure
        </a>
      </div>
    </div>
  </div>

  <!-- Alert Settings -->
  <div class="col-md-3">
    <div class="card card-dark h-100">
      <div class="card-body text-center p-2">
        <div class="mb-2">
          <i class="fas fa-bell fa-2x text-warning"></i>
        </div>
        <h6 class="card-title">Alert Settings</h6>
        <p class="card-text text-muted small">Email alerts</p>
        <a href="alerts.php" class="btn btn-warning btn-sm w-100">
          <i class="fas fa-cog me-1"></i>Configure
        </a>
      </div>
    </div>
  </div>

  <!-- Backup Retention -->
  <div class="col-md-3">
    <div class="card card-dark h-100">
      <div class="card-body text-center p-2">
        <div class="mb-2">
          <i class="fas fa-database fa-2x text-primary"></i>
        </div>
        <h6 class="card-title">Backup Retention</h6>
        <p class="card-text text-muted small">Backup settings</p>
        <button class="btn btn-primary btn-sm w-100" data-bs-toggle="modal" data-bs-target="#backupRetentionModal">
          <i class="fas fa-cog me-1"></i>Configure
        </button>
      </div>
    </div>
  </div>

  <!-- Branding -->
  <div class="col-md-3">
    <div class="card card-dark h-100">
      <div class="card-body text-center p-2">
        <div class="mb-2">
          <i class="fas fa-palette fa-2x text-info"></i>
        </div>
        <h6 class="card-title">Branding</h6>
        <p class="card-text text-muted small">Colors & logo</p>
        <a href="branding.php" class="btn btn-info btn-sm w-100">
          <i class="fas fa-cog me-1"></i>Configure
        </a>
      </div>
    </div>
  </div>

  <!-- Fail2Ban -->
  <div class="col-md-3">
    <div class="card card-dark h-100">
      <div class="card-body text-center p-2">
        <div class="mb-2">
          <i class="fas fa-shield-alt fa-2x text-danger"></i>
        </div>
        <h6 class="card-title">Fail2Ban</h6>
        <p class="card-text text-muted small">IP blocking</p>
        <a href="fail2ban.php" class="btn btn-danger btn-sm w-100">
          <i class="fas fa-cog me-1"></i>Configure
        </a>
      </div>
    </div>
  </div>

  <!-- GeoIP Blocking -->
  <div class="col-md-3">
    <div class="card card-dark h-100">
      <div class="card-body text-center p-2">
        <div class="mb-2">
          <i class="fas fa-globe fa-2x text-danger"></i>
        </div>
        <h6 class="card-title">GeoIP Blocking</h6>
        <p class="card-text text-muted small">Country-based blocking</p>
        <a href="geoip_blocking.php" class="btn btn-danger btn-sm w-100">
          <i class="fas fa-ban me-1"></i>Configure
        </a>
      </div>
    </div>
  </div>

  <!-- General Settings -->
  <div class="col-md-3">
    <div class="card card-dark h-100">
      <div class="card-body text-center p-2">
        <div class="mb-2">
          <i class="fas fa-cog fa-2x text-secondary"></i>
        </div>
        <h6 class="card-title">General Settings</h6>
        <p class="card-text text-muted small">Timezone & preferences</p>
        <a href="#generalSettings" data-bs-toggle="modal" class="btn btn-secondary btn-sm w-100">
          <i class="fas fa-cog me-1"></i>Configure
        </a>
      </div>
    </div>
  </div>

  <!-- SMTP Settings -->
  <div class="col-md-3">
    <div class="card card-dark h-100">
      <div class="card-body text-center p-2">
        <div class="mb-2">
          <i class="fas fa-envelope fa-2x text-info"></i>
        </div>
        <h6 class="card-title">SMTP Settings</h6>
        <p class="card-text text-muted small">Email server</p>
        <a href="smtp_settings.php" class="btn btn-info btn-sm w-100">
          <i class="fas fa-cog me-1"></i>Configure
        </a>
      </div>
    </div>
  </div>

  <!-- System Backup & Restore -->
  <div class="col-md-3">
    <div class="card card-dark h-100">
      <div class="card-body text-center p-2">
        <div class="mb-2">
          <i class="fas fa-archive fa-2x text-success"></i>
        </div>
        <h6 class="card-title">System Backup</h6>
        <p class="card-text text-muted small">Backup & restore</p>
        <a href="system_backup.php" class="btn btn-success btn-sm w-100">
          <i class="fas fa-cog me-1"></i>Configure
        </a>
      </div>
    </div>
  </div>

  <!-- Tunnel Management -->
  <div class="col-md-3">
    <div class="card card-dark h-100">
      <div class="card-body text-center p-2">
        <div class="mb-2">
          <i class="fas fa-network-wired fa-2x text-info"></i>
        </div>
        <h6 class="card-title">Tunnel Management</h6>
        <p class="card-text text-muted small">SSH tunnels</p>
        <button class="btn btn-info btn-sm w-100" data-bs-toggle="modal" data-bs-target="#tunnelManagementModal" onclick="loadTunnelData()">
          <i class="fas fa-network-wired me-1"></i>Manage
        </button>
      </div>
    </div>
  </div>

  <!-- Security Scanner (Snyk) -->
  <div class="col-md-3">
    <div class="card card-dark h-100">
      <div class="card-body text-center p-2">
        <div class="mb-2">
          <i class="fas fa-shield-alt fa-2x text-danger"></i>
        </div>
        <h6 class="card-title">Security Scanner</h6>
        <p class="card-text text-muted small">Snyk vulnerability scanning</p>
        <a href="security_scan.php" class="btn btn-danger btn-sm w-100">
          <i class="fas fa-shield-alt me-1"></i>Open Dashboard
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
  font-size: 0.95rem;
}

.card-text {
  color: #adb5bd;
  font-size: 0.85rem;
}

.card-dark .btn {
  padding: 0.4rem 0.8rem;
  font-size: 0.9rem;
  border-radius: 0.25rem;
  transition: all 0.2s ease;
}

.card-dark .btn-sm {
  padding: 0.3rem 0.6rem;
  font-size: 0.85rem;
}

.card-dark .btn:hover {
  transform: scale(1.1);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
}

.card-dark .btn-success:hover {
  background-color: #00c853 !important;
}

.card-dark .btn-warning:hover {
  background-color: #ffb300 !important;
}

.card-dark .btn-primary:hover {
  background-color: #00d4d4 !important;
}

.card-dark .btn-info:hover {
  background-color: #00bcd4 !important;
}

.card-dark .btn-danger:hover {
  background-color: #ff5252 !important;
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

<!-- General Settings Modal -->
<div class="modal fade" id="generalSettings" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content bg-dark border-secondary">
      <div class="modal-header border-secondary">
        <h5 class="modal-title text-light">
          <i class="fas fa-cog me-2 text-info"></i>General Settings
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <div class="modal-body">
          <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
          <div class="mb-4">
            <label for="timezone" class="form-label text-light fw-bold">
              <i class="fas fa-globe me-2 text-info"></i>Display Timezone
            </label>
            <select id="timezone" name="timezone" class="form-select bg-secondary text-light border-secondary" style="color: #fff !important;">
              <?php
              $current_tz = $_SESSION['display_timezone'] ?? 'America/New_York';
              foreach (timezone_identifiers_list() as $tz) {
                $selected = ($tz === $current_tz) ? 'selected' : '';
                echo "<option value=\"$tz\" $selected>$tz</option>";
              }
              ?>
            </select>
            <small class="form-text text-muted d-block mt-2">Select your timezone for displaying times throughout the system</small>
          </div>
          <div class="mb-4">
            <label for="manager_fqdn" class="form-label text-light fw-bold">
              <i class="fas fa-server me-2 text-info"></i>Manager FQDN
            </label>
            <input type="text" id="manager_fqdn" name="manager_fqdn" class="form-control bg-secondary text-light border-secondary"
                   value="<?php echo htmlspecialchars($manager_fqdn); ?>"
                   placeholder="opn.agit8or.net">
            <small class="form-text text-muted d-block mt-2">
              Fully Qualified Domain Name for this manager. Used in agent install commands:<br>
              <code class="text-light bg-dark px-2 py-1 rounded">fetch -o - https://<?php echo htmlspecialchars($manager_fqdn); ?>/downloads/plugins/install_opnmanager_agent.sh | sh</code>
            </small>
          </div>
        </div>
        <div class="modal-footer border-secondary">
          <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">
            <i class="fas fa-times me-2"></i>Cancel
          </button>
          <button type="submit" name="save_general" class="btn btn-info">
            <i class="fas fa-save me-2"></i>Save Settings
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

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
            Configure backup retention policy. Choose between time-based (keep for X months) or count-based (keep last N backups per firewall).
          </div>
          
          <div class="mb-3">
            <label class="form-label">Retention Type</label>
            <select class="form-control bg-dark text-light border-secondary" name="backup_retention_type" id="backup_retention_type" onchange="toggleRetentionType()">
              <option value="count" <?php echo $backup_retention_type === 'count' ? 'selected' : ''; ?>>Count-Based (Keep Last N Backups)</option>
              <option value="time" <?php echo $backup_retention_type === 'time' ? 'selected' : ''; ?>>Time-Based (Keep for X Months)</option>
            </select>
          </div>
          
          <div id="count_retention" style="display: <?php echo $backup_retention_type === 'count' ? 'block' : 'none'; ?>;">
            <div class="mb-3">
              <label for="backup_min_keep" class="form-label">Minimum Backups to Keep</label>
              <input type="number" class="form-control bg-dark text-light border-secondary" id="backup_min_keep" name="backup_min_keep"
                     value="<?php echo htmlspecialchars($backup_min_keep); ?>"
                     min="10" max="100" step="1">
              <div class="form-text text-light-emphasis">Always keep at least this many backups per firewall (10-100)</div>
            </div>
            <div class="mb-3">
              <label for="backup_max_keep" class="form-label">Maximum Backups to Keep</label>
              <input type="number" class="form-control bg-dark text-light border-secondary" id="backup_max_keep" name="backup_max_keep"
                     value="<?php echo htmlspecialchars($backup_max_keep); ?>"
                     min="20" max="200" step="1">
              <div class="form-text text-light-emphasis">Delete oldest backups when count exceeds this (20-200)</div>
            </div>
          </div>
          
          <div id="time_retention" style="display: <?php echo $backup_retention_type === 'time' ? 'block' : 'none'; ?>;">
            <div class="mb-3">
              <label for="backup_retention_months" class="form-label">Retention Period (Months)</label>
              <input type="number" class="form-control bg-dark text-light border-secondary" id="backup_retention_months" name="backup_retention_months"
                     value="<?php echo htmlspecialchars($backup_retention_months); ?>"
                     min="1" max="6" step="1">
              <div class="form-text text-light-emphasis">Keep backups for 1-6 months</div>
            </div>
          </div>
          
          <div class="alert alert-warning bg-opacity-25">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Note:</strong> Old backups will be permanently deleted during nightly cleanup. This helps manage disk space.
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
function toggleRetentionType() {
  const type = document.getElementById('backup_retention_type').value;
  document.getElementById('count_retention').style.display = type === 'count' ? 'block' : 'none';
  document.getElementById('time_retention').style.display = type === 'time' ? 'block' : 'none';
}

// Ensure correct fields are shown when modal opens
document.addEventListener('DOMContentLoaded', function() {
  const modal = document.getElementById('backupRetentionModal');
  if (modal) {
    modal.addEventListener('shown.bs.modal', function() {
      toggleRetentionType();
    });
  }
});
</script>

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

<!-- Tunnel Management Modal -->
<div class="modal fade" id="tunnelManagementModal" tabindex="-1" aria-labelledby="tunnelManagementModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content bg-dark text-light">
      <div class="modal-header border-secondary">
        <h5 class="modal-title" id="tunnelManagementModalLabel">
          <i class="fas fa-network-wired me-2"></i>Tunnel Management
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info bg-opacity-25 mb-3">
          <i class="fas fa-info-circle me-2"></i>
          <strong>Tunnel System Status:</strong> This interface shows all SSH tunnels (active and zombie) and their nginx HTTPS proxy configurations.
          Use the Master Reset to clean up everything if tunnels get stuck.
        </div>
        
        <!-- Summary Cards -->
        <div class="row g-3 mb-4">
          <div class="col-md-3">
            <div class="card bg-secondary">
              <div class="card-body text-center">
                <h3 id="summary-total" class="mb-0">-</h3>
                <small class="text-muted">Total Sessions</small>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="card bg-success">
              <div class="card-body text-center">
                <h3 id="summary-active" class="mb-0">-</h3>
                <small>Active Tunnels</small>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="card bg-danger">
              <div class="card-body text-center">
                <h3 id="summary-zombies" class="mb-0">-</h3>
                <small>Zombie Tunnels</small>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="card bg-warning">
              <div class="card-body text-center">
                <h3 id="summary-incomplete" class="mb-0">-</h3>
                <small>Incomplete</small>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="d-flex gap-2 mb-3">
          <button class="btn btn-danger" onclick="resetAllTunnels()">
            <i class="fas fa-power-off me-2"></i>Master Reset (Kill All)
          </button>
          <button class="btn btn-warning" onclick="cleanupZombies()">
            <i class="fas fa-broom me-2"></i>Cleanup Zombies Only
          </button>
          <button class="btn btn-primary" onclick="loadTunnelData()">
            <i class="fas fa-sync me-2"></i>Refresh
          </button>
        </div>
        
        <!-- Tunnels Table -->
        <div class="table-responsive">
          <table class="table table-dark table-striped table-hover">
            <thead>
              <tr>
                <th>Session</th>
                <th>Firewall</th>
                <th>Ports</th>
                <th>Status</th>
                <th>Age</th>
                <th>SSH</th>
                <th>Nginx</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="tunnels-table-body">
              <tr>
                <td colspan="8" class="text-center">
                  <i class="fas fa-spinner fa-spin me-2"></i>Loading...
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

</div>

<script>
let tunnelRefreshInterval;

function loadTunnelData() {
    fetch('/api/tunnel_reset.php?action=status')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateTunnelDisplay(data.data);
            } else {
                console.error('Error loading tunnel data:', data.error);
                showError('Failed to load tunnel data');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showError('Network error loading tunnel data');
        });
}

function updateTunnelDisplay(data) {
    // Update summary cards
    const total = data.ssh_tunnels.length + data.db_sessions.filter(s => {
        return !data.ssh_tunnels.find(t => t.session_id === s.id);
    }).length;
    
    document.getElementById('summary-total').textContent = total;
    document.getElementById('summary-active').textContent = data.db_sessions.length;
    document.getElementById('summary-zombies').textContent = data.zombie_count;
    document.getElementById('summary-incomplete').textContent = 
        data.ssh_tunnels.filter(t => !t.is_zombie && !data.nginx_listening.includes(String(parseInt(t.port) - 1))).length;
    
    // Build combined tunnel list
    const tbody = document.getElementById('tunnels-table-body');
    
    if (data.ssh_tunnels.length === 0 && data.db_sessions.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-light"><i class="fas fa-check-circle text-success me-2"></i>No active tunnels - system clean</td></tr>';
        return;
    }
    
    // Create map of session IDs to data
    const sessionMap = new Map();
    
    // Add SSH tunnels
    data.ssh_tunnels.forEach(tunnel => {
        const sessionId = tunnel.session_id || 'unknown';
        const httpsPort = parseInt(tunnel.port) - 1;
        const hasNginx = data.nginx_listening.includes(String(httpsPort));
        
        if (!sessionMap.has(sessionId)) {
            sessionMap.set(sessionId, {
                session_id: sessionId,
                firewall_id: tunnel.firewall_id,
                tunnel_port: tunnel.port,
                https_port: httpsPort,
                status: tunnel.status,
                created_at: tunnel.created_at,
                is_zombie: tunnel.is_zombie,
                has_ssh: true,
                has_nginx: hasNginx,
                ssh_pid: tunnel.pid
            });
        }
    });
    
    // Add DB sessions without SSH tunnels
    data.db_sessions.forEach(session => {
        if (!sessionMap.has(session.id)) {
            const httpsPort = parseInt(session.tunnel_port) - 1;
            const hasNginx = data.nginx_listening.includes(String(httpsPort));
            
            sessionMap.set(session.id, {
                session_id: session.id,
                firewall_id: session.firewall_id,
                tunnel_port: session.tunnel_port,
                https_port: httpsPort,
                status: session.status,
                created_at: session.created_at,
                is_zombie: false,
                has_ssh: false,
                has_nginx: hasNginx,
                ssh_pid: null
            });
        }
    });
    
    // Convert to array and sort
    const tunnels = Array.from(sessionMap.values()).sort((a, b) => b.session_id - a.session_id);
    
    tbody.innerHTML = tunnels.map(tunnel => {
        // Calculate age
        const age = tunnel.created_at ? 
            Math.round((new Date() - new Date(tunnel.created_at)) / 60000) : '?';
        
        // Status badge
        let statusBadge;
        if (tunnel.is_zombie) {
            statusBadge = '<span class="badge bg-danger"><i class="fas fa-skull me-1"></i>ZOMBIE</span>';
        } else if (tunnel.status === 'active') {
            if (!tunnel.has_ssh || !tunnel.has_nginx) {
                statusBadge = '<span class="badge bg-warning"><i class="fas fa-exclamation-triangle me-1"></i>INCOMPLETE</span>';
            } else {
                statusBadge = '<span class="badge bg-success"><i class="fas fa-check me-1"></i>ACTIVE</span>';
            }
        } else {
            statusBadge = '<span class="badge bg-secondary">CLOSED</span>';
        }
        
        // SSH indicator
        const sshIcon = tunnel.has_ssh
            ? `<span class="text-success" title="PID: ${tunnel.ssh_pid}"><i class="fas fa-check-circle"></i></span>` 
            : '<span class="text-danger"><i class="fas fa-times-circle"></i></span>';
        
        // Nginx indicator  
        const nginxIcon = tunnel.has_nginx
            ? '<span class="text-success"><i class="fas fa-check-circle"></i></span>' 
            : '<span class="text-danger"><i class="fas fa-times-circle"></i></span>';
        
        // Action button
        let actions = '';
        if (tunnel.has_ssh) {
            actions += `<button class="btn btn-sm btn-danger me-1" onclick="killTunnel(${tunnel.ssh_pid})" title="Kill SSH tunnel">
                         <i class="fas fa-skull-crossbones"></i>
                       </button>`;
        }
        
        const rowClass = tunnel.is_zombie ? 'table-danger' : 
                        (!tunnel.has_ssh || !tunnel.has_nginx) && tunnel.status === 'active' ? 'table-warning' : '';
        
        return `
            <tr class="${rowClass}">
                <td><span class="badge bg-secondary">#${tunnel.session_id}</span></td>
                <td>
                    <div class="fw-bold">FW ${tunnel.firewall_id || '?'}</div>
                </td>
                <td>
                    <div><span class="badge bg-info">SSH: ${tunnel.tunnel_port}</span></div>
                    <div><span class="badge bg-primary">HTTPS: ${tunnel.https_port}</span></div>
                </td>
                <td>${statusBadge}</td>
                <td>${age} min</td>
                <td class="text-center">${sshIcon}</td>
                <td class="text-center">${nginxIcon}</td>
                <td>${actions || '<span class="text-muted">-</span>'}</td>
            </tr>
        `;
    }).join('');
}

function resetAllTunnels() {
    if (!confirm('⚠️ MASTER RESET - This will:\n\n' +
                 '• Kill ALL SSH tunnels (via kill -9)\n' +
                 '• Remove ALL nginx tunnel configs\n' +
                 '• Close ALL sessions in database\n' +
                 '• Clear firewall tunnel ports\n\n' +
                 '⚠️ Users will be disconnected from firewall UIs!\n\n' +
                 'Continue?')) {
        return;
    }
    
    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Resetting...';
    
    fetch('/api/tunnel_reset.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=reset_all'
    })
        .then(response => response.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            
            if (data.success) {
                const log = data.log.join('\n');
                alert('✓ MASTER RESET COMPLETE\n\n' + log);
                loadTunnelData();
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to reset tunnels');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        });
}

function cleanupZombies() {
    if (!confirm('Clean up zombie tunnels only?\n\n' +
                 'This will kill SSH tunnels that have no active session in the database.')) {
        return;
    }
    
    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Cleaning...';
    
    // Get current status first
    fetch('/api/tunnel_reset.php?action=status')
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error('Failed to get tunnel status');
            }
            
            const zombies = data.data.ssh_tunnels.filter(t => t.is_zombie);
            if (zombies.length === 0) {
                alert('✓ No zombie tunnels found! System is clean.');
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                return;
            }
            
            // Kill each zombie
            let killed = 0;
            const promises = zombies.map(tunnel => 
                fetch('/api/tunnel_reset.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=kill_tunnel&pid=${tunnel.pid}`
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) killed++;
                    return result;
                })
            );
            
            Promise.all(promises).then(() => {
                alert(`✓ Zombie Cleanup Complete\n\nKilled ${killed} of ${zombies.length} zombie tunnels`);
                loadTunnelData();
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            });
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to cleanup zombies: ' + error.message);
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        });
}

function killTunnel(pid) {
    if (!confirm(`Kill SSH tunnel (PID ${pid})?`)) {
        return;
    }
    
    fetch('/api/tunnel_reset.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=kill_tunnel&pid=${pid}`
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showSuccess('Tunnel killed successfully');
                loadTunnelData();
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Network error');
        });
}


// Auto-refresh when modal is open
document.getElementById('tunnelManagementModal')?.addEventListener('shown.bs.modal', function() {
    loadTunnelData();
    tunnelRefreshInterval = setInterval(loadTunnelData, 5000);
});

document.getElementById('tunnelManagementModal')?.addEventListener('hidden.bs.modal', function() {
    if (tunnelRefreshInterval) {
        clearInterval(tunnelRefreshInterval);
        tunnelRefreshInterval = null;
    }
});

function killTunnel(sessionId) {
    if (!confirm(`Kill tunnel for session ${sessionId}?`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('session_id', sessionId);
    
    fetch('/api/tunnel_management.php?action=kill_tunnel', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadTunnelData();
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to kill tunnel');
        });
}
</script>

<?php include __DIR__ . "/inc/footer.php"; ?>
                html += 'No active licenses found.';
