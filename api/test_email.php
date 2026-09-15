<?php
require_once __DIR__ . '/../inc/bootstrap.php';

require_once __DIR__ . '/../inc/smtp_mailer.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

// CSRF validation
$csrf_token = $input['csrf'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!csrf_verify($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$test_email = trim($input['test_email'] ?? '');
if (empty($test_email) || !filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid email address']);
    exit;
}

try {
    $stmt = db()->query("SELECT setting_name, setting_value FROM alert_settings WHERE setting_name LIKE 'email_%'");
    $alert_settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $alert_settings[$row['setting_name']] = $row['setting_value'];
    }
    
    if (empty($alert_settings['email_enabled']) || !in_array(strtolower($alert_settings['email_enabled']), ['1', 'true', 'on', 'yes'])) {
        echo json_encode(['success' => false, 'error' => 'Email alerts not enabled']);
        exit;
    }
    
    $smtp_stmt = db()->query("SELECT `name`, `value` FROM settings WHERE `name` LIKE 'smtp_%'");
    $smtp_settings = [];
    while ($row = $smtp_stmt->fetch(PDO::FETCH_ASSOC)) {
        $smtp_settings[$row['name']] = $row['value'];
    }
    
    $required = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password'];
    foreach ($required as $key) {
        if (empty($smtp_settings[$key])) {
            echo json_encode(['success' => false, 'error' => "Missing SMTP: $key"]);
            exit;
        }
    }
    
    $from_address = $alert_settings['email_from_address'] ?? $smtp_settings['smtp_username'];
    $from_name = $alert_settings['email_from_name'] ?? 'OpnMgr Alerts';
    $subject = 'OpnMgr Test Email';
    
    $message = "<!DOCTYPE html><html><body style='font-family:Arial;'><div style='max-width:600px;margin:0 auto;padding:20px;'><div style='background:#667eea;color:white;padding:20px;border-radius:5px 5px 0 0;'><h2>OpnMgr Alert System</h2></div><div style='background:#f8f9fa;padding:20px;border:1px solid #ddd;'><div style='background:#d1ecf1;padding:15px;border-radius:4px;'><strong>Success!</strong> SMTP is configured correctly.</div><p>Host: {$smtp_settings['smtp_host']}:{$smtp_settings['smtp_port']}</p><p>From: $from_name &lt;$from_address&gt;</p></div></div></body></html>";
    
    $result = send_smtp_email($smtp_settings, $test_email, $subject, $message, $from_address, $from_name);
    
    if ($result['success']) {
        // `alert_history` has recipients_count / notification_method / status;
        // there is no recipient_email column. Writing one threw, and because the
        // throw was caught by the handler below, a test email that had already
        // been delivered was reported to the operator as a failure.
        try {
            $stmt = db()->prepare(
                "INSERT INTO alert_history
                    (alert_level, alert_type, subject, message, recipients_count,
                     notification_method, status, sent_at)
                 VALUES ('info', 'test_email', ?, ?, 1, 'email', 'sent', NOW())"
            );
            $stmt->execute([$subject, strip_tags($message)]);
        } catch (Exception $e) {
            // Recording history must never turn a delivered email into a failure.
            error_log('test_email.php could not record alert history: ' . $e->getMessage());
        }
        echo json_encode(['success' => true, 'message' => 'Test email sent to ' . $test_email]);
    } else {
        echo json_encode(['success' => false, 'error' => $result['error']]);
    }
} catch (Exception $e) {
    error_log("Test email error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
