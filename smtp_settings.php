<?php
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();
requireAdmin();

$notice = '';

// Load SMTP settings
try {
    $rows = db()->query('SELECT `name`,`value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $rows = [];
}
$smtp_host = $rows['smtp_host'] ?? '';
$smtp_port = $rows['smtp_port'] ?? '587';
$smtp_username = $rows['smtp_username'] ?? '';
$smtp_password = $rows['smtp_password'] ?? '';
$smtp_encryption = $rows['smtp_encryption'] ?? 'tls';
$smtp_from_email = $rows['smtp_from_email'] ?? '';
$smtp_from_name = $rows['smtp_from_name'] ?? 'OPNsense Manager';

// Helper function to save settings
function save_setting($k, $v) {
    $s = db()->prepare('INSERT INTO settings (`name`,`value`) VALUES (:k,:v) ON DUPLICATE KEY UPDATE `value` = :v2');
    $s->execute([':k' => $k, ':v' => $v, ':v2' => $v]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $notice = '<div class="alert alert-danger">CSRF verification failed.</div>';
    } else {
        // Save SMTP settings
        if (!empty($_POST['save_smtp'])) {
            $smtp_host = trim($_POST['smtp_host'] ?? '');
            $smtp_port = trim($_POST['smtp_port'] ?? '587');
            $smtp_username = trim($_POST['smtp_username'] ?? '');
            $smtp_password = $_POST['smtp_password'] ?? '';
            $smtp_encryption = $_POST['smtp_encryption'] ?? 'tls';
            $smtp_from_email = trim($_POST['smtp_from_email'] ?? '');
            $smtp_from_name = trim($_POST['smtp_from_name'] ?? 'OPNsense Manager');

            // Validate inputs
            if (empty($smtp_host)) {
                $notice = '<div class="alert alert-danger">SMTP host is required.</div>';
            } elseif (!is_numeric($smtp_port) || $smtp_port < 1 || $smtp_port > 65535) {
                $notice = '<div class="alert alert-danger">Invalid SMTP port.</div>';
            } elseif (!empty($smtp_from_email) && !filter_var($smtp_from_email, FILTER_VALIDATE_EMAIL)) {
                $notice = '<div class="alert alert-danger">Invalid from email address.</div>';
            } else {
                // Save settings
                try {
                    save_setting('smtp_host', $smtp_host);
                    save_setting('smtp_port', $smtp_port);
                    save_setting('smtp_username', $smtp_username);
                    save_setting('smtp_password', $smtp_password);
                    save_setting('smtp_encryption', $smtp_encryption);
                    save_setting('smtp_from_email', $smtp_from_email);
                    save_setting('smtp_from_name', $smtp_from_name);
                    
                    $notice = '<div class="alert alert-success">SMTP settings saved successfully!</div>';
                    // Reload settings from database
                    $rows = db()->query('SELECT `name`,`value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
                    $smtp_host = $rows['smtp_host'] ?? '';
                    $smtp_port = $rows['smtp_port'] ?? '587';
                    $smtp_username = $rows['smtp_username'] ?? '';
                    $smtp_password = $rows['smtp_password'] ?? '';
                    $smtp_encryption = $rows['smtp_encryption'] ?? 'tls';
                    $smtp_from_email = $rows['smtp_from_email'] ?? '';
                    $smtp_from_name = $rows['smtp_from_name'] ?? 'OPNsense Manager';
                } catch (Exception $e) {
                    error_log("smtp_settings.php error: " . $e->getMessage());
                    $notice = '<div class="alert alert-danger">An internal error occurred while saving settings.</div>';
                }
            }
        }

        // Test SMTP connection
        if (!empty($_POST['test_smtp'])) {
            $test_result = test_smtp_connection();
            if ($test_result === true) {
                $notice = '<div class="alert alert-success">SMTP connection test successful!</div>';
            } else {
                $notice = '<div class="alert alert-warning">SMTP connection test: ' . htmlspecialchars($test_result) . '</div>';
            }
        }
    }
}

function test_smtp_connection() {
    global $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption;

    if (empty($smtp_host) || empty($smtp_port)) {
        return "SMTP host and port are required for testing.";
    }

    // Simple connection test
    $errno = 0;
    $errstr = '';

    if ($smtp_encryption === 'ssl') {
        $socket = @fsockopen('ssl://' . $smtp_host, $smtp_port, $errno, $errstr, 5);
    } else {
        $socket = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, 5);
    }

    if (!$socket) {
        return "Connection failed: $errstr ($errno)";
    }

    fclose($socket);
    return true;
}

include __DIR__ . '/inc/header.php';
?>

<div class="row">
    <div class="col-md-12">
        <div class="card card-dark">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <small class="text-light fw-bold mb-0">
                        <i class="fas fa-envelope me-1"></i>SMTP Configuration
                    </small>
                </div>

                <?php if ($notice): echo $notice; endif; ?>

                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label text-light fw-bold" style="font-size: 0.85rem;">SMTP Host</label>
                            <input type="text" name="smtp_host" class="form-control form-control-sm" value="<?php echo htmlspecialchars($smtp_host); ?>" placeholder="smtp.gmail.com" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-light fw-bold" style="font-size: 0.85rem;">Port</label>
                            <input type="number" name="smtp_port" class="form-control form-control-sm" value="<?php echo htmlspecialchars($smtp_port); ?>" min="1" max="65535" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-light fw-bold" style="font-size: 0.85rem;">Encryption</label>
                            <select name="smtp_encryption" class="form-select form-select-sm">
                                <option value="tls" <?php echo ($smtp_encryption === 'tls') ? 'selected' : ''; ?>>TLS</option>
                                <option value="ssl" <?php echo ($smtp_encryption === 'ssl') ? 'selected' : ''; ?>>SSL</option>
                                <option value="none" <?php echo ($smtp_encryption === 'none') ? 'selected' : ''; ?>>None</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mt-1">
                        <div class="col-md-6">
                            <label class="form-label text-light fw-bold" style="font-size: 0.85rem;">Username</label>
                            <input type="text" name="smtp_username" class="form-control form-control-sm" value="<?php echo htmlspecialchars($smtp_username); ?>" placeholder="your-email@gmail.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light fw-bold" style="font-size: 0.85rem;">Password</label>
                            <input type="password" name="smtp_password" class="form-control form-control-sm" value="<?php echo htmlspecialchars($smtp_password); ?>" placeholder="Your SMTP password">
                        </div>
                    </div>

                    <div class="row g-2 mt-1">
                        <div class="col-md-6">
                            <label class="form-label text-light fw-bold" style="font-size: 0.85rem;">From Email</label>
                            <input type="email" name="smtp_from_email" class="form-control form-control-sm" value="<?php echo htmlspecialchars($smtp_from_email); ?>" placeholder="noreply@yourdomain.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light fw-bold" style="font-size: 0.85rem;">From Name</label>
                            <input type="text" name="smtp_from_name" class="form-control form-control-sm" value="<?php echo htmlspecialchars($smtp_from_name); ?>" placeholder="OPNsense Manager">
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" name="save_smtp" value="1" class="btn btn-primary btn-sm">
                            <i class="fas fa-save me-1"></i>Save SMTP Settings
                        </button>
                        <button type="submit" name="test_smtp" value="1" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-flask me-1"></i>Test Connection
                        </button>
                    </div>
                </form>

                <div class="mt-4">
                    <h6 class="text-light fw-bold" style="font-size: 0.9rem;">
                        <i class="fas fa-info-circle me-2"></i>SMTP Configuration Guide
                    </h6>
                    <div class="alert alert-dark" style="font-size: 0.8rem;">
                        <strong>Gmail:</strong> smtp.gmail.com:587 (TLS) - Enable 2FA and use App Password<br>
                        <strong>Outlook:</strong> smtp-mail.outlook.com:587 (TLS)<br>
                        <strong>Yahoo:</strong> smtp.mail.yahoo.com:587 (TLS)<br>
                        <strong>Custom SMTP:</strong> Contact your email provider for settings
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
