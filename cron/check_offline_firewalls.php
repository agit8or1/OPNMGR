#!/usr/bin/env php
<?php
/**
 * Check for offline firewalls and send alerts
 * Run every minute via cron
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/alerts.php';

$log_file = __DIR__ . '/check_offline_firewalls.log';

function log_message($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

log_message("Starting offline firewall check...");

try {
    // Get alert settings
    $settings_stmt = $DB->query("SELECT setting_name, setting_value FROM alert_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Check if email alerts and critical alerts are enabled
    if (empty($settings['email_enabled']) || ($settings['email_enabled'] !== '1' && $settings['email_enabled'] !== 'true')) {
        log_message("Email alerts are disabled in settings - exiting");
        exit(0);
    }
    
    if (empty($settings['alerts_critical_enabled']) || ($settings['alerts_critical_enabled'] !== '1' && $settings['alerts_critical_enabled'] !== 'true')) {
        log_message("Critical alerts are disabled in settings - exiting");
        exit(0);
    }
    
    log_message("Alert settings OK - proceeding with checks");
    
    // Get all firewalls with alerts enabled
    $stmt = $DB->query("
        SELECT 
            id,
            hostname,
            customer_name,
            last_checkin,
            alerts_enabled,
            offline_alert_threshold,
            TIMESTAMPDIFF(SECOND, last_checkin, NOW()) as seconds_offline
        FROM firewalls
        WHERE alerts_enabled = 1
          AND last_checkin IS NOT NULL
    ");
    
    $firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    log_message("Found " . count($firewalls) . " firewalls with alerts enabled");
    
    $alerts_sent = 0;
    $alerts_skipped = 0;
    
    foreach ($firewalls as $firewall) {
        $threshold = (int)$firewall['offline_alert_threshold'];
        $seconds_offline = (int)$firewall['seconds_offline'];
        
        // Check if firewall is offline longer than threshold
        if ($seconds_offline > $threshold) {
            log_message("Firewall '{$firewall['hostname']}' (ID: {$firewall['id']}) is offline for {$seconds_offline}s (threshold: {$threshold}s)");
            
            // Check if we already sent an alert recently (prevent spam)
            if (was_alert_recently_sent($firewall['id'], 'firewall_offline', 60)) {
                log_message("  - Skipping: Alert already sent within last 60 minutes");
                $alerts_skipped++;
                continue;
            }
            
            // Prepare alert details
            $minutes_offline = round($seconds_offline / 60);
            $last_seen = date('Y-m-d H:i:s', strtotime($firewall['last_checkin']));
            
            $subject = "🚨 CRITICAL: Firewall Offline - {$firewall['hostname']}";
            
            $details = [
                'Hostname' => $firewall['hostname'],
                'Customer' => $firewall['customer_name'] ?? 'N/A',
                'Status' => 'OFFLINE',
                'Time Offline' => "{$minutes_offline} minutes ({$seconds_offline} seconds)",
                'Last Check-in' => $last_seen,
                'Alert Threshold' => round($threshold / 60) . " minutes",
                'Firewall ID' => $firewall['id']
            ];
            
            $message = "The firewall <strong>{$firewall['hostname']}</strong> has been offline for <strong>{$minutes_offline} minutes</strong> and has exceeded the configured alert threshold.";
            $message .= "<br><br>This firewall has not checked in since <strong>$last_seen</strong>.";
            $message .= "<br><br><strong>Recommended Actions:</strong>";
            $message .= "<ul>";
            $message .= "<li>Check if the firewall is powered on and connected to the network</li>";
            $message .= "<li>Verify internet connectivity at the firewall location</li>";
            $message .= "<li>Check if the OPNManager agent service is running</li>";
            $message .= "<li>Review firewall logs for any errors or issues</li>";
            $message .= "</ul>";
            
            $html = generate_alert_email_html(
                'critical',
                'Firewall Offline Alert',
                $message,
                $firewall['hostname'],
                $details
            );
            
            // Send alert
            $result = send_email_alert(
                'critical',
                'firewall_offline',
                $subject,
                $html,
                $firewall['id']
            );
            
            if ($result['success']) {
                log_message("  - SUCCESS: Alert sent to {$result['sent']} recipient(s)");
                $alerts_sent++;
            } else {
                log_message("  - FAILED: " . implode(', ', $result['errors']));
            }
        }
    }
    
    log_message("Offline check complete. Sent: $alerts_sent, Skipped: $alerts_skipped");
    
} catch (Exception $e) {
    log_message("ERROR: " . $e->getMessage());
    error_log("Offline firewall check error: " . $e->getMessage());
}

log_message("---");
