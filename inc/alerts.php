<?php
/**
 * Alert System Library
 * Handles sending email alerts and logging to alert_history
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/smtp_mailer.php';

/**
 * Send an email alert to all admin users
 * 
 * @param string $level Alert level: 'critical', 'warning', or 'info'
 * @param string $type Alert type: 'firewall_offline', 'backup_failed', etc.
 * @param string $subject Email subject
 * @param string $message_html HTML message body
 * @param int $firewall_id Firewall ID (optional)
 * @return array ['success' => bool, 'sent' => int, 'errors' => array]
 */
function send_email_alert($level, $type, $subject, $message_html, $firewall_id = null) {
    global $DB;
    
    $results = [
        'success' => false,
        'sent' => 0,
        'errors' => []
    ];
    
    try {
        // Get email settings
        // Get alert settings (email_enabled, alert level enables)
        $stmt = $DB->query("SELECT setting_name, setting_value FROM alert_settings");
        $alert_settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $alert_settings[$row['setting_name']] = $row['setting_value'];
        }
        
        // Check if email is enabled in alert settings
        if (empty($alert_settings['email_enabled']) || !in_array(strtolower($alert_settings['email_enabled']), ['1', 'true', 'on', 'yes'])) {
            $results['errors'][] = 'Email alerts are not enabled';
            return $results;
        }
        
        // Check if this alert level is enabled
        $level_key = "alerts_{$level}_enabled";
        if (empty($alert_settings[$level_key]) || !in_array(strtolower($alert_settings[$level_key]), ['1', 'true', 'on', 'yes'])) {
            $results['errors'][] = "Alert level '$level' is not enabled";
            return $results;
        }
        
        // Get SMTP settings from settings table
        $smtp_stmt = $DB->query("SELECT `name`, `value` FROM settings WHERE `name` LIKE 'smtp_%'");
        $smtp_settings = [];
        while ($row = $smtp_stmt->fetch(PDO::FETCH_ASSOC)) {
            $smtp_settings[$row['name']] = $row['value'];
        }
        
        // Validate required SMTP settings
        $required = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password'];
        foreach ($required as $key) {
            if (empty($smtp_settings[$key])) {
                $results['errors'][] = "Missing required SMTP setting: $key";
                return $results;
            }
        }
        
        // Get from address (use alert_settings first, fallback to smtp_settings)
        $from_address = $alert_settings['email_from_address'] ?? $smtp_settings['smtp_from_email'] ?? $smtp_settings['smtp_username'];
        $from_name = $alert_settings['email_from_name'] ?? $smtp_settings['smtp_from_name'] ?? 'OpnMgr Alert System';
        
        if (empty($from_address)) {
            $results['errors'][] = "No from address configured";
            return $results;
        }
        
        // Get all admin users with email addresses who want to receive this alert level
        $stmt = $DB->query("SELECT id, username, email, first_name, last_name, alert_levels FROM users WHERE role = 'admin' AND email IS NOT NULL AND email != ''");
        $all_recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Filter recipients by alert level preference
        $recipients = [];
        foreach ($all_recipients as $recipient) {
            // If alert_levels is empty or null, default to warning,critical
            $user_levels = $recipient['alert_levels'] ?: 'warning,critical';
            $user_levels_array = explode(',', $user_levels);
            
            // Check if user wants this alert level
            if (in_array($level, $user_levels_array)) {
                $recipients[] = $recipient;
            }
        }
        
        if (empty($recipients)) {
            $results['errors'][] = "No admin users have opted in to receive '$level' alerts";
            return $results;
        }
        
        // Send to each admin user
        foreach ($recipients as $recipient) {
            try {
                $to = $recipient['email'];
                
                // Create plain text version
                $plain_text = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $message_html));
                
                // Send email using SMTP
                $email_result = send_smtp_email($smtp_settings, $to, $subject, $message_html, $from_address, $from_name);
                
                if ($email_result['success']) {
                    $results['sent']++;
                    
                    // Log to alert_history
                    $stmt = $DB->prepare("INSERT INTO alert_history (firewall_id, alert_level, alert_type, subject, message, recipient_email, sent_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$firewall_id, $level, $type, $subject, $plain_text, $to]);
                } else {
                    $results['errors'][] = "Failed to send to {$to}: {$email_result['error']}";
                }
            } catch (Exception $e) {
                $results['errors'][] = "Error sending to {$recipient['email']}: {$e->getMessage()}";
            }
        }
        
        $results['success'] = $results['sent'] > 0;
        
    } catch (Exception $e) {
        $results['errors'][] = "System error: {$e->getMessage()}";
    }
    
    return $results;
}

/**
 * Generate HTML email template for alerts
 */
function generate_alert_email_html($level, $title, $message, $firewall_name = null, $details = []) {
    // Color scheme based on alert level
    $colors = [
        'critical' => ['bg' => '#dc3545', 'border' => '#b02a37', 'icon' => '🚨'],
        'warning' => ['bg' => '#ffc107', 'border' => '#d39e00', 'icon' => '⚠️'],
        'info' => ['bg' => '#17a2b8', 'border' => '#117a8b', 'icon' => 'ℹ️']
    ];
    
    $color = $colors[$level] ?? $colors['info'];
    $level_display = strtoupper($level);
    
    $html = "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; background: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; background: white; }
        .header { background: {$color['bg']}; color: white; padding: 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        .alert-box { background: #f8f9fa; border-left: 4px solid {$color['border']}; padding: 15px; margin: 20px 0; }
        .alert-box h3 { margin: 0 0 10px 0; color: {$color['bg']}; }
        .details { background: #f8f9fa; padding: 15px; border-radius: 4px; margin: 20px 0; }
        .details table { width: 100%; border-collapse: collapse; }
        .details td { padding: 8px; border-bottom: 1px solid #dee2e6; }
        .details td:first-child { font-weight: bold; width: 40%; color: #6c757d; }
        .footer { background: #343a40; color: #adb5bd; padding: 20px; text-align: center; font-size: 12px; }
        .btn { display: inline-block; padding: 10px 20px; background: {$color['bg']}; color: white; text-decoration: none; border-radius: 4px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>{$color['icon']} OPNManager Alert</h1>
            <p style='margin: 5px 0 0 0; opacity: 0.9;'>$level_display - " . date('Y-m-d H:i:s T') . "</p>
        </div>
        <div class='content'>
            <div class='alert-box'>
                <h3>$title</h3>
                <p>$message</p>
            </div>";
    
    if ($firewall_name) {
        $html .= "<p><strong>Firewall:</strong> $firewall_name</p>";
    }
    
    if (!empty($details)) {
        $html .= "<div class='details'><table>";
        foreach ($details as $key => $value) {
            $html .= "<tr><td>" . htmlspecialchars($key) . "</td><td>" . htmlspecialchars($value) . "</td></tr>";
        }
        $html .= "</table></div>";
    }
    
    $html .= "
            <p style='margin-top: 30px;'>
                <a href='https://" . ($_SERVER['HTTP_HOST'] ?? 'opnmgr.local') . "/firewalls.php' class='btn'>View Dashboard</a>
            </p>
            <p style='color: #6c757d; font-size: 14px; margin-top: 30px;'>
                <em>This is an automated alert from your OPNManager system. You are receiving this because you are an administrator with alert notifications enabled.</em>
            </p>
        </div>
        <div class='footer'>
            <p>OPNManager Firewall Management System</p>
            <p style='margin: 5px 0;'>© " . date('Y') . " - Automated Alert System</p>
        </div>
    </div>
</body>
</html>";
    
    return $html;
}

/**
 * Get count of recent alerts for a firewall
 */
function get_recent_alert_count($firewall_id, $hours = 24) {
    global $DB;
    $stmt = $DB->prepare("SELECT COUNT(*) FROM alert_history WHERE firewall_id = ? AND sent_at > DATE_SUB(NOW(), INTERVAL ? HOUR)");
    $stmt->execute([$firewall_id, $hours]);
    return (int)$stmt->fetchColumn();
}

/**
 * Check if alert was recently sent (prevent spam)
 */
function was_alert_recently_sent($firewall_id, $alert_type, $minutes = 60) {
    global $DB;
    $stmt = $DB->prepare("SELECT COUNT(*) FROM alert_history WHERE firewall_id = ? AND alert_type = ? AND sent_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
    $stmt->execute([$firewall_id, $alert_type, $minutes]);
    return (int)$stmt->fetchColumn() > 0;
}
