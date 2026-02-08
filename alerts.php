<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
requireLogin();
requireAdmin();

// Get all alert settings
$stmt = $DB->query("SELECT setting_name, setting_value FROM alert_settings");
$settings_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Parse settings with defaults
$settings = [
    'email_enabled' => ($settings_raw['email_enabled'] ?? 'false') === 'true',
    'email_from_address' => $settings_raw['email_from_address'] ?? '',
    'email_from_name' => $settings_raw['email_from_name'] ?? 'OpnMgr Alert System',
    'pushover_enabled' => ($settings_raw['pushover_enabled'] ?? 'false') === 'true',
    'pushover_api_token' => $settings_raw['pushover_api_token'] ?? '',
    'alerts_info_enabled' => ($settings_raw['alerts_info_enabled'] ?? 'true') === 'true',
    'alerts_warning_enabled' => ($settings_raw['alerts_warning_enabled'] ?? 'true') === 'true',
    'alerts_critical_enabled' => ($settings_raw['alerts_critical_enabled'] ?? 'true') === 'true',
];

// Get SMTP settings from existing settings table
$stmt = $DB->query("SELECT name, value FROM settings WHERE name LIKE 'smtp_%'");
$smtp_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Handle form submissions
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_email_settings'])) {
        $email_enabled = isset($_POST['email_enabled']) ? 'true' : 'false';
        $email_from = trim($_POST['email_from_address'] ?? '');
        $email_name = trim($_POST['email_from_name'] ?? 'OpnMgr Alert System');
        
        $DB->prepare("UPDATE alert_settings SET setting_value = ? WHERE setting_name = 'email_enabled'")->execute([$email_enabled]);
        $DB->prepare("UPDATE alert_settings SET setting_value = ? WHERE setting_name = 'email_from_address'")->execute([$email_from]);
        $DB->prepare("UPDATE alert_settings SET setting_value = ? WHERE setting_name = 'email_from_name'")->execute([$email_name]);
        
        $notice = 'Email settings saved successfully.';
        header('Location: /alerts.php?notice=' . urlencode($notice));
        exit;
    }
    
    if (isset($_POST['save_pushover_settings'])) {
        $pushover_enabled = isset($_POST['pushover_enabled']) ? 'true' : 'false';
        $pushover_token = trim($_POST['pushover_api_token'] ?? '');
        
        $DB->prepare("UPDATE alert_settings SET setting_value = ? WHERE setting_name = 'pushover_enabled'")->execute([$pushover_enabled]);
        $DB->prepare("UPDATE alert_settings SET setting_value = ? WHERE setting_name = 'pushover_api_token'")->execute([$pushover_token]);
        
        $notice = 'Pushover settings saved successfully.';
        header('Location: /alerts.php?notice=' . urlencode($notice));
        exit;
    }
    
    if (isset($_POST['save_alert_levels'])) {
        $info_enabled = isset($_POST['alerts_info_enabled']) ? 'true' : 'false';
        $warning_enabled = isset($_POST['alerts_warning_enabled']) ? 'true' : 'false';
        $critical_enabled = isset($_POST['alerts_critical_enabled']) ? 'true' : 'false';
        
        $DB->prepare("UPDATE alert_settings SET setting_value = ? WHERE setting_name = 'alerts_info_enabled'")->execute([$info_enabled]);
        $DB->prepare("UPDATE alert_settings SET setting_value = ? WHERE setting_name = 'alerts_warning_enabled'")->execute([$warning_enabled]);
        $DB->prepare("UPDATE alert_settings SET setting_value = ? WHERE setting_name = 'alerts_critical_enabled'")->execute([$critical_enabled]);
        
        $notice = 'Alert level settings saved successfully.';
        header('Location: /alerts.php?notice=' . urlencode($notice));
        exit;
    }
}

// Display notice from redirect
if (isset($_GET['notice'])) {
    $notice = $_GET['notice'];
}

include __DIR__ . '/inc/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="text-light">
                    <i class="fas fa-bell me-2"></i>Alert System Configuration
                </h2>
                <a href="/dashboard.php" class="btn btn-outline-light">
                    <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                </a>
            </div>

            <?php if ($notice): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($notice); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- Email Settings Card -->
                <div class="col-md-6 mb-4">
                    <div class="card card-dark h-100">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-envelope me-2"></i>Email Alerts
                            </h5>
                        </div>
                        <div class="card-body">
                            <p class="text-light mb-3">
                                Configure email notifications for system alerts. Email settings use the SMTP configuration from Settings page.
                            </p>
                            
                            <?php if (empty($smtp_settings['smtp_host'])): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    SMTP not configured. Please configure SMTP settings first.
                                    <a href="/smtp_settings.php" class="alert-link">Configure SMTP</a>
                                </div>
                            <?php endif; ?>
                            
                            <form method="post">
                                <div class="mb-3 form-check form-switch">
                                    <input type="checkbox" class="form-check-input" id="email_enabled" name="email_enabled" 
                                           <?php echo $settings['email_enabled'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label text-light" for="email_enabled">
                                        Enable Email Alerts
                                    </label>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="email_from_address" class="form-label text-light">From Email Address</label>
                                    <input type="email" class="form-control bg-dark text-light border-secondary" 
                                           id="email_from_address" name="email_from_address" 
                                           value="<?php echo htmlspecialchars($settings['email_from_address']); ?>"
                                           placeholder="alerts@example.com">
                                    <small class="text-muted">Email address alerts will be sent from</small>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="email_from_name" class="form-label text-light">From Name</label>
                                    <input type="text" class="form-control bg-dark text-light border-secondary" 
                                           id="email_from_name" name="email_from_name" 
                                           value="<?php echo htmlspecialchars($settings['email_from_name']); ?>"
                                           placeholder="OpnMgr Alert System">
                                    <small class="text-muted">Display name for alert emails</small>
                                </div>
                                
                                <div class="d-flex gap-2">
                                    <button type="submit" name="save_email_settings" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i>Save Email Settings
                                    </button>
                                    <button type="button" class="btn btn-outline-info" onclick="testEmail()">
                                        <i class="fas fa-paper-plane me-1"></i>Send Test Email
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Pushover Settings Card -->
                <div class="col-md-6 mb-4">
                    <div class="card card-dark h-100">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-mobile-alt me-2"></i>Pushover Alerts
                            </h5>
                        </div>
                        <div class="card-body">
                            <p class="text-light mb-3">
                                Configure Pushover push notifications for mobile alerts. 
                                <a href="https://pushover.net/" target="_blank" class="text-info">Get Pushover API Token</a>
                            </p>
                            
                            <form method="post">
                                <div class="mb-3 form-check form-switch">
                                    <input type="checkbox" class="form-check-input" id="pushover_enabled" name="pushover_enabled" 
                                           <?php echo $settings['pushover_enabled'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label text-light" for="pushover_enabled">
                                        Enable Pushover Alerts
                                    </label>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="pushover_api_token" class="form-label text-light">
                                        Pushover API Token
                                        <i class="fas fa-info-circle text-info" title="Get from pushover.net/apps"></i>
                                    </label>
                                    <input type="text" class="form-control bg-dark text-light border-secondary font-monospace" 
                                           id="pushover_api_token" name="pushover_api_token" 
                                           value="<?php echo htmlspecialchars($settings['pushover_api_token']); ?>"
                                           placeholder="azGDORePK8gMaC0QOYAMyEEuzJnyUi"
                                           maxlength="30">
                                    <small class="text-muted">Your application's API token from Pushover</small>
                                </div>
                                
                                <div class="alert alert-info">
                                    <strong>Priority Levels:</strong>
                                    <ul class="mb-0 mt-2">
                                        <li><strong>Info:</strong> Normal priority (0)</li>
                                        <li><strong>Warning:</strong> Normal priority (0)</li>
                                        <li><strong>Critical:</strong> High priority (1, bypasses quiet hours)</li>
                                    </ul>
                                </div>
                                
                                <div class="d-flex gap-2">
                                    <button type="submit" name="save_pushover_settings" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i>Save Pushover Settings
                                    </button>
                                    <button type="button" class="btn btn-outline-info" onclick="testPushover()">
                                        <i class="fas fa-paper-plane me-1"></i>Send Test Push
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Alert Levels Card -->
                <div class="col-md-6 mb-4">
                    <div class="card card-dark h-100">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-layer-group me-2"></i>Alert Levels
                            </h5>
                        </div>
                        <div class="card-body">
                            <p class="text-light mb-3">
                                Enable or disable specific alert levels globally.
                            </p>
                            
                            <form method="post">
                                <div class="mb-3 form-check form-switch">
                                    <input type="checkbox" class="form-check-input" id="alerts_info_enabled" name="alerts_info_enabled" 
                                           <?php echo $settings['alerts_info_enabled'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label text-light" for="alerts_info_enabled">
                                        <span class="badge bg-info me-2">INFO</span>
                                        Informational Alerts
                                    </label>
                                    <small class="d-block text-muted ms-4">Backup completed, agent check-ins, routine operations</small>
                                </div>
                                
                                <div class="mb-3 form-check form-switch">
                                    <input type="checkbox" class="form-check-input" id="alerts_warning_enabled" name="alerts_warning_enabled" 
                                           <?php echo $settings['alerts_warning_enabled'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label text-light" for="alerts_warning_enabled">
                                        <span class="badge bg-warning text-dark me-2">WARNING</span>
                                        Warning Alerts
                                    </label>
                                    <small class="d-block text-muted ms-4">Backup failures, delayed check-ins, certificate expiring</small>
                                </div>
                                
                                <div class="mb-3 form-check form-switch">
                                    <input type="checkbox" class="form-check-input" id="alerts_critical_enabled" name="alerts_critical_enabled" 
                                           <?php echo $settings['alerts_critical_enabled'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label text-light" for="alerts_critical_enabled">
                                        <span class="badge bg-danger me-2">CRITICAL</span>
                                        Critical Alerts
                                    </label>
                                    <small class="d-block text-muted ms-4">Firewall offline, agent timeout, security events</small>
                                </div>
                                
                                <button type="submit" name="save_alert_levels" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i>Save Alert Levels
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Alert Recipients Card -->
                <div class="col-md-6 mb-4">
                    <div class="card card-dark h-100">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-users me-2"></i>Alert Recipients
                            </h5>
                        </div>
                        <div class="card-body">
                            <p class="text-light mb-3">
                                Alerts are automatically sent to all administrator users with configured email addresses.
                            </p>
                            
                            <?php
                            $stmt = $DB->query("
                                SELECT COUNT(*) as count
                                FROM users 
                                WHERE role = 'admin' AND email IS NOT NULL AND email != ''
                            ");
                            $admin_count = $stmt->fetchColumn();
                            ?>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong><?php echo $admin_count; ?></strong> administrator(s) with email addresses will receive alerts.
                            </div>
                            
                            <div class="list-group list-group-flush">
                                <?php
                                $stmt = $DB->query("
                                    SELECT username, email, first_name, last_name
                                    FROM users 
                                    WHERE role = 'admin' AND email IS NOT NULL AND email != ''
                                    ORDER BY username
                                ");
                                $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                if (empty($admins)): ?>
                                    <div class="list-group-item bg-dark border-secondary text-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        No administrators have email addresses configured. Please update user profiles to enable alerts.
                                    </div>
                                <?php else:
                                    foreach ($admins as $admin): 
                                        $display_name = trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''));
                                        if (empty($display_name)) {
                                            $display_name = $admin['username'];
                                        }
                                ?>
                                    <div class="list-group-item bg-dark border-secondary">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <i class="fas fa-user-shield me-2 text-primary"></i>
                                                <strong class="text-light"><?php echo htmlspecialchars($display_name); ?></strong>
                                                <br>
                                                <small class="text-muted ms-4"><?php echo htmlspecialchars($admin['email']); ?></small>
                                            </div>
                                            <span class="badge bg-success">Active</span>
                                        </div>
                                    </div>
                                <?php 
                                    endforeach;
                                endif; 
                                ?>
                            </div>
                            
                            <div class="mt-3 text-center">
                                <a href="/users.php" class="btn btn-outline-light">
                                    <i class="fas fa-user-cog me-1"></i>Manage Users
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alert History Card -->
            <div class="row">
                <div class="col-12">
                    <div class="card card-dark">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-history me-2"></i>Recent Alert History
                            </h5>
                            <a href="/alert_history.php" class="btn btn-sm btn-outline-light">
                                <i class="fas fa-list me-1"></i>View Full History
                            </a>
                        </div>
                        <div class="card-body">
                            <?php
                            $stmt = $DB->query("
                                SELECT ah.*, f.hostname 
                                FROM alert_history ah
                                LEFT JOIN firewalls f ON ah.firewall_id = f.id
                                ORDER BY ah.sent_at DESC
                                LIMIT 10
                            ");
                            $recent_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (empty($recent_alerts)): ?>
                                <p class="text-muted text-center mb-0">No alerts sent yet.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-dark table-hover">
                                        <thead>
                                            <tr>
                                                <th>Time</th>
                                                <th>Level</th>
                                                <th>Type</th>
                                                <th>Firewall</th>
                                                <th>Subject</th>
                                                <th>Recipients</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_alerts as $alert): ?>
                                                <tr>
                                                    <td><?php echo date('M j, g:i A', strtotime($alert['sent_at'])); ?></td>
                                                    <td>
                                                        <?php
                                                        $badge_class = [
                                                            'info' => 'bg-info',
                                                            'warning' => 'bg-warning text-dark',
                                                            'critical' => 'bg-danger'
                                                        ][$alert['alert_level']];
                                                        ?>
                                                        <span class="badge <?php echo $badge_class; ?>">
                                                            <?php echo strtoupper($alert['alert_level']); ?>
                                                        </span>
                                                    </td>
                                                    <td><small><?php echo htmlspecialchars($alert['alert_type']); ?></small></td>
                                                    <td><?php echo $alert['hostname'] ? htmlspecialchars($alert['hostname']) : '-'; ?></td>
                                                    <td><?php echo htmlspecialchars($alert['subject']); ?></td>
                                                    <td><span class="badge bg-secondary"><?php echo $alert['recipients_count']; ?></span></td>
                                                    <td>
                                                        <?php if ($alert['status'] === 'sent'): ?>
                                                            <span class="text-success"><i class="fas fa-check-circle"></i></span>
                                                        <?php elseif ($alert['status'] === 'failed'): ?>
                                                            <span class="text-danger"><i class="fas fa-times-circle"></i></span>
                                                        <?php else: ?>
                                                            <span class="text-warning"><i class="fas fa-exclamation-circle"></i></span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function testEmail() {
    const testEmail = prompt('Enter email address to send test alert to:', '<?php echo htmlspecialchars($settings['email_from_address']); ?>');
    if (!testEmail) {
        return;
    }
    
    // Basic email validation
    if (!testEmail.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        alert('✗ Invalid email address format');
        return;
    }
    
    fetch('/api/test_email.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({test_email: testEmail})
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✓ Test email sent successfully to ' + testEmail + '!\n\n' + (data.message || 'Check your inbox.'));
        } else {
            alert('✗ Failed to send test email:\n\n' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        alert('✗ Error sending test email:\n\n' + error.message);
    });
}

function testPushover() {
    const testUserKey = prompt('Enter Pushover user key to send test alert to:', '<?php echo htmlspecialchars($settings['pushover_api_token']); ?>');
    if (!testUserKey) {
        return;
    }
    
    // Basic validation (30 chars alphanumeric)
    if (!testUserKey.match(/^[a-zA-Z0-9]{30}$/)) {
        alert('✗ Invalid Pushover user key format (should be 30 alphanumeric characters)');
        return;
    }
    
    fetch('/api/test_pushover.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({test_user_key: testUserKey})
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✓ Test push notification sent successfully!\n\n' + (data.message || 'Check your Pushover app.'));
        } else {
            alert('✗ Failed to send test notification:\n\n' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        alert('✗ Error sending test notification:\n\n' + error.message);
    });
}
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
