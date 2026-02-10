<?php
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();

$notice = '';

// Load settings
$rows = db()->query('SELECT `name`,`value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$ban_time_minutes = $rows['fail2ban_ban_time'] ?? '60';

// Fail2Ban status
$fbin = '/usr/local/sbin/opnmgr-fail2ban-wrapper';
$jails = [];
$service_status = 'Unknown';
$service_running = false;
$debug_info = 'Page loaded at: ' . date('Y-m-d H:i:s') . "
";

if (file_exists($fbin) && is_executable($fbin)) {
  $s = trim(shell_exec(escapeshellcmd($fbin).' status 2>/dev/null'));
  
  if (preg_match('/Jail list:\s*(.*)$/m',$s,$m)) {
    $list = array_map('trim',explode(',', $m[1]));
    foreach($list as $j) if ($j) $jails[] = $j;
    $debug_info .= "Parsed jails: " . implode(', ', $jails) . "\n";
  } else {
    $debug_info .= "Regex failed to match\n";
  }
  
  // Check service status
  $service_check = shell_exec('sudo /usr/local/sbin/opnmgr-service-wrapper fail2ban status 2>/dev/null');
  $service_status = trim($service_check);
  $service_running = ($service_status === 'active');
} else {
  $debug_info .= "fail2ban wrapper not found or not executable: $fbin\n";
  error_log("POST check reached, method: " . $_SERVER['REQUEST_METHOD']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf_valid = csrf_verify($_POST["csrf"] ?? "");
  if (!$csrf_valid) {
    $notice = "Bad CSRF";
  } else {
    $action = $_POST["service_action"];
        if (empty($result)) {
          $notice = 'Fail2Ban service ' . htmlspecialchars($action) . ' command executed successfully.';
          // Refresh service status
          $service_check = shell_exec('sudo /usr/local/sbin/opnmgr-service-wrapper fail2ban status 2>/dev/null');
          $service_status = trim($service_check);
          $service_running = ($service_status === 'active');
        } else {
          $notice = 'Failed to ' . htmlspecialchars($action) . ' service: ' . htmlspecialchars($result);
        }
    
    // Fail2Ban settings
  $debug_info .= "save_fail2ban_settings POST value: '" . ($_POST['save_fail2ban_settings'] ?? 'not set') . "'\n";
    if (!empty($_POST['save_fail2ban_settings'])) {
      $ban_time_minutes = trim($_POST['ban_time_minutes'] ?? '60');
      if (is_numeric($ban_time_minutes) && $ban_time_minutes > 0) {
        $stmt = db()->prepare('INSERT INTO settings (`name`,`value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?');
        $stmt->execute(['fail2ban_ban_time', $ban_time_minutes, $ban_time_minutes]);
        $notice = 'Fail2Ban settings saved successfully.';
        // Reload settings
        $rows = db()->query('SELECT `name`,`value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        $ban_time_minutes = $rows['fail2ban_ban_time'] ?? '60';
      } else {
        $notice = 'Invalid ban time. Must be a positive number.';
      }
    }
    if (!empty($_POST['fail2ban_action'])) {
      $jail = trim($_POST['jail'] ?? '');
      $ip = trim($_POST['ip'] ?? '');
      $act = $_POST['fail2ban_action'];
      $fbin = '/usr/local/sbin/opnmgr-fail2ban-wrapper';
      
      if (!file_exists($fbin) || !is_executable($fbin)) { 
        $notice = 'Fail2Ban wrapper not available'; 
      }
      elseif (empty($jail)) { $notice = 'Please select a jail'; }
      elseif (empty($ip) && in_array($act, ['ban', 'unban'])) { $notice = 'Please enter an IP address'; }
      elseif (!filter_var($ip, FILTER_VALIDATE_IP) && in_array($act, ['ban', 'unban'])) { $notice = 'Invalid IP address format'; }
      else {
        if ($act === 'ban') {
          $cmd = sprintf('%s ban %s %s', escapeshellcmd($fbin), escapeshellarg($jail), escapeshellarg($ip));
          $result = shell_exec($cmd . ' 2>&1');
          if (trim($result) === '1') {
            $notice = 'IP ' . htmlspecialchars($ip) . ' has been banned in jail ' . htmlspecialchars($jail);
          } else {
            $notice = 'Failed to ban IP. Output: ' . htmlspecialchars($result);
          }
        } elseif ($act === 'unban') {
          $cmd = sprintf('%s unban %s %s', escapeshellcmd($fbin), escapeshellarg($jail), escapeshellarg($ip));
          $result = shell_exec($cmd . ' 2>&1');
          if (trim($result) === '1') {
            $notice = 'IP ' . htmlspecialchars($ip) . ' has been unbanned from jail ' . htmlspecialchars($jail);
          } else {
            $notice = 'Failed to unban IP. Output: ' . htmlspecialchars($result);
          }
        } elseif ($act === 'reload') {
          $cmd = sprintf('%s reload', escapeshellcmd($fbin));
          $result = shell_exec($cmd . ' 2>&1');
          $notice = 'Fail2Ban reload requested. Output: ' . htmlspecialchars($result);
        }
      }
    }
    }
}
include __DIR__ . '/inc/header.php';
?>

<style>
.alert-sm { padding: 0.5rem 0.75rem; margin-bottom: 0.5rem; font-size: 0.8rem; }
.badge-sm { font-size: 0.65rem; padding: 0.2rem 0.3rem; }
.btn-xs { padding: 0.2rem 0.3rem; font-size: 0.7rem; line-height: 1; width: 24px; height: 24px; }
.form-control-sm { font-size: 0.75rem; padding: 0.2rem 0.4rem; }
.form-select-sm { font-size: 0.75rem; padding: 0.2rem 0.4rem; }
.card-ghost { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.08); border-radius: 6px; }
</style>

<div class="row">
  <div class="col-md-12">
    <div class="card card-dark p-3">
      <h5 class="text-center">Fail2Ban</h5>
      <?php if (empty($fbin)): ?>
        <div class="alert alert-warning">fail2ban-client not found on the server.</div>
      <?php else: ?>
        <div>
          <div class="d-flex align-items-center gap-2 mb-2">
            <small class="text-light fw-bold mb-0">
              <i class="fas fa-shield-alt me-1"></i>Jails
            </small>
          </div>
          <div class="row g-2">
            <?php foreach($jails as $j): ?>
              <div class="col-md-6 col-lg-4">
                <div class="card card-ghost p-2">
                  <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                      <strong class="text-light" style="font-size: 0.85rem;"><?php echo htmlspecialchars($j); ?></strong>
                      <?php
                        $b = shell_exec(escapeshellcmd($fbin).' status '.escapeshellarg($j).' 2>/dev/null');
                        $banned_count = 0;
                        $ips = [];
                        
                        // Parse banned IP count
                        if (preg_match('/Currently banned:\s*(\d+)/', $b, $count_match)) {
                          $banned_count = (int)$count_match[1];
                        }
                        
                        // Parse banned IP list
                        if (preg_match('/Banned IP list:\s*(.+)$/m', $b, $ip_match)) {
                          $ip_list = trim($ip_match[1]);
                          if (!empty($ip_list)) {
                            $ips = array_map('trim', explode(' ', $ip_list));
                            $ips = array_filter($ips);
                          }
                        }
                        
                        echo '<br><small class="text-muted" style="font-size: 0.7rem;">Banned: ' . $banned_count . '</small>';
                        if (!empty($ips)) {
                          echo '<br><small class="text-warning" style="font-size: 0.65rem;">' . htmlspecialchars(implode(', ', array_slice($ips, 0, 3))) . (count($ips) > 3 ? '...' : '') . '</small>';
                        } else {
                          echo '<br><small class="text-success" style="font-size: 0.65rem;">None</small>';
                        }
                      ?>
                    </div>
                    <div class="d-flex gap-1">
                      <button class="btn btn-outline-light btn-xs" title="Ban IP" onclick="showBanModal('<?php echo htmlspecialchars($j); ?>')">
                        <i class="fas fa-ban"></i>
                      </button>
                      <button class="btn btn-outline-light btn-xs" title="Unban All" onclick="unbanAll('<?php echo htmlspecialchars($j); ?>')">
                        <i class="fas fa-check"></i>
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="d-flex justify-content-center mt-2">
            <button type="button" class="btn btn-outline-light btn-sm" onclick="refreshAllJails()">
              <i class="fas fa-sync-alt me-1"></i>Refresh
            </button>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>



<!-- IP Management Form - Condensed -->
<div class="row mb-2">
  <div class="col-md-12">
    <div class="card card-dark">
      <div class="card-body py-2">
        <div class="d-flex align-items-center gap-2 mb-2">
          <small class="text-light fw-bold mb-0">
            <i class="fas fa-gavel me-1"></i>IP Management
          </small>
        </div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <div class="row g-1">
            <div class="col-md-4">
              <select name="jail" class="form-select form-select-sm" required>
                <option value="">Select Jail</option>
                <?php if (empty($jails)): ?>
                  <option value="" disabled>No jails available</option>
                <?php else: ?>
                  <?php foreach($jails as $j): ?>
                    <option value="<?php echo htmlspecialchars($j); ?>" <?php echo (isset($_POST['jail']) && $_POST['jail'] === $j) ? 'selected' : ''; ?>>
                      <?php echo htmlspecialchars($j); ?>
                    </option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </div>
            <div class="col-md-4">
              <input name="ip" class="form-control form-control-sm" placeholder="192.168.1.100" value="<?php echo htmlspecialchars($_POST['ip'] ?? ''); ?>" pattern="^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$">
            </div>
            <div class="col-md-4">
              <div class="d-flex gap-1">
                <button class="btn btn-warning btn-xs flex-fill" name="fail2ban_action" value="ban" type="submit" title="Ban IP">
                  <i class="fas fa-ban"></i>
                </button>
                <button class="btn btn-success btn-xs flex-fill" name="fail2ban_action" value="unban" type="submit" title="Unban IP">
                  <i class="fas fa-check"></i>
                </button>
                <button class="btn btn-outline-light btn-xs" name="fail2ban_action" value="reload" type="submit" title="Reload">
                  <i class="fas fa-sync-alt"></i>
                </button>
              </div>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Fail2Ban Settings - Condensed -->
<div class="row mb-2">
  <div class="col-md-12">
    <div class="card card-dark">
      <div class="card-body py-2">
        <div class="d-flex align-items-center gap-2 mb-2">
          <small class="text-light fw-bold mb-0">
            <i class="fas fa-cog me-1"></i>Settings
          </small>
        </div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <div class="row g-1">
            <div class="col-md-8">
              <div class="d-flex align-items-end gap-2">
                <div class="flex-grow-1">
                  <input type="number" name="ban_time_minutes" class="form-control form-control-sm" value="<?php echo htmlspecialchars($ban_time_minutes); ?>" min="1" max="10080" placeholder="Ban time (minutes)">
                </div>
              </div>
              <small class="text-light" style="font-size: 0.65rem;">Default ban duration (1-10080 minutes)</small>
            </div>
            <div class="col-md-4 d-flex align-items-start">
              <button class="btn btn-primary btn-xs w-100" name="save_fail2ban_settings" type="submit" value="1">
                <i class="fas fa-save me-1"></i>Save
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if ($notice): ?><div class="alert alert-info alert-sm"><?php echo $notice; ?></div><?php endif; ?>

<!-- Service Status - Condensed -->
<div class="row mt-2">
  <div class="col-md-12">
    <div class="card card-dark">
      <div class="card-body py-2">
        <div class="d-flex justify-content-between align-items-center">
          <div class="d-flex align-items-center gap-2">
            <small class="text-light fw-bold mb-0">
              <i class="fas fa-server me-1"></i>Fail2Ban:
            </small>
            <span class="badge <?php echo $service_running ? 'bg-success' : 'bg-danger'; ?> badge-sm">
              <?php echo htmlspecialchars($service_status); ?>
            </span>
          </div>
          <div class="d-flex gap-1">
            <form method="post" class="d-inline">
              <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
              <input type="hidden" name="service_action" value="start">
              <button type="submit" class="btn btn-success btn-xs" <?php echo $service_running ? 'disabled' : ''; ?> title="Start">
                <i class="fas fa-play"></i>
              </button>
            </form>
            <form method="post" class="d-inline">
              <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
              <input type="hidden" name="service_action" value="stop">
              <button type="submit" class="btn btn-danger btn-xs" <?php echo !$service_running ? 'disabled' : ''; ?> title="Stop">
                <i class="fas fa-stop"></i>
              </button>
            </form>
            <form method="post" class="d-inline">
              <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
              <input type="hidden" name="service_action" value="restart">
              <button type="submit" class="btn btn-warning btn-xs" title="Restart">
                <i class="fas fa-redo"></i>
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>

<script>
function refreshAllJails() {
  // Simple page refresh to update all jail details
  location.reload();
}

function showBanModal(jailName) {
  // For now, just show an alert - could be enhanced to show a proper modal
  const ip = prompt('Enter IP address to ban in jail: ' + jailName);
  if (ip && ip.trim()) {
    banIP(jailName, ip.trim());
  }
}

function banIP(jailName, ip) {
  // Create a form and submit it
  const form = document.createElement('form');
  form.method = 'POST';
  form.style.display = 'none';
  
  const csrfInput = document.createElement('input');
  csrfInput.type = 'hidden';
  csrfInput.name = 'csrf';
  csrfInput.value = '<?php echo htmlspecialchars(csrf_token()); ?>';
  form.appendChild(csrfInput);
  
  const jailInput = document.createElement('input');
  jailInput.type = 'hidden';
  jailInput.name = 'jail';
  jailInput.value = jailName;
  form.appendChild(jailInput);
  
  const ipInput = document.createElement('input');
  ipInput.type = 'hidden';
  ipInput.name = 'ip';
  ipInput.value = ip;
  form.appendChild(ipInput);
  
  const actionInput = document.createElement('input');
  actionInput.type = 'hidden';
  actionInput.name = 'fail2ban_action';
  actionInput.value = 'ban';
  form.appendChild(actionInput);
  
  document.body.appendChild(form);
  form.submit();
}

function unbanAll(jailName) {
  if (confirm('Unban all IPs from jail: ' + jailName + '?')) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf';
    csrfInput.value = '<?php echo htmlspecialchars(csrf_token()); ?>';
    form.appendChild(csrfInput);
    
    const jailInput = document.createElement('input');
    jailInput.type = 'hidden';
    jailInput.name = 'jail';
    jailInput.value = jailName;
    form.appendChild(jailInput);
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'fail2ban_action';
    actionInput.value = 'reload';
    form.appendChild(actionInput);
    
    document.body.appendChild(form);
    form.submit();
  }
}
</script>

<!-- Fail2Ban Modal -->
<div class="modal fade" id="failModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content bg-dark text-light">
    <div class="modal-header">
      <h5 class="modal-title">Fail2Ban Jail Output</h5>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body modal-pre" id="failModalBody">Loading...</div>
  </div>
</div>
<script src="/assets/js/fail2ban_modal.js"></script>
