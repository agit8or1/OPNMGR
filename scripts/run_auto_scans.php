#!/usr/bin/env php
<?php
/**
 * Automatic AI Scan Scheduler
 * Runs AI security scans for firewalls with auto_scan_enabled
 *
 * This script should be run via cron (e.g., nightly at 2 AM)
 */

// Set up environment
ini_set('display_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('America/New_York');

// Change to web root directory
chdir(dirname(__DIR__));

require_once __DIR__ . '/../inc/bootstrap_agent.php';
// AI provider settings are loaded from database, no separate file needed

$log_file = '/var/log/opnsense_auto_scans.log';

function log_message($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
    echo "[$timestamp] $message\n";
}

log_message("========== Starting automatic AI scan scheduler ==========");

try {
    // Find firewalls that need scanning
    $query = "SELECT
        fas.firewall_id,
        fas.scan_frequency,
        fas.preferred_provider,
        f.hostname,
        f.ip_address
    FROM firewall_ai_settings fas
    JOIN firewalls f ON fas.firewall_id = f.id
    WHERE fas.auto_scan_enabled = 1
    AND (fas.next_scan_at IS NULL OR fas.next_scan_at <= NOW())
    AND f.status = 'online'";

    $stmt = db()->query($query);
    $firewalls_to_scan = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($firewalls_to_scan)) {
        log_message("No firewalls scheduled for automatic scanning");
        exit(0);
    }

    log_message("Found " . count($firewalls_to_scan) . " firewall(s) scheduled for scanning");

    foreach ($firewalls_to_scan as $fw) {
        $firewall_id = $fw['firewall_id'];
        $hostname = $fw['hostname'];
        $frequency = $fw['scan_frequency'];
        $provider = $fw['preferred_provider'];

        log_message("Processing firewall: $hostname (ID: $firewall_id, Frequency: $frequency)");

        try {
            // Fetch firewall details
            $fw_stmt = db()->prepare('SELECT * FROM firewalls WHERE id = ?');
            $fw_stmt->execute([$firewall_id]);
            $firewall = $fw_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$firewall) {
                log_message("ERROR: Firewall $firewall_id not found");
                continue;
            }

            // Include the AI scan library
            require_once __DIR__ . '/../api/ai_scan.php';

            // Run the scan (always with logs)
            log_message("Starting AI scan for $hostname...");

            $scan_result = performAIScan(
                $firewall,
                'config_with_logs',
                $provider ?: null
            );

            if ($scan_result['success']) {
                log_message("SUCCESS: AI scan completed for $hostname (Report ID: {$scan_result['report_id']})");

                // Calculate next scan time based on frequency
                $interval_map = [
                    'daily' => '1 DAY',
                    'weekly' => '7 DAY',
                    'monthly' => '30 DAY'
                ];

                $interval = $interval_map[$frequency] ?? '7 DAY';

                // Update next_scan_at and last_scan_at
                $update_stmt = db()->prepare("
                    UPDATE firewall_ai_settings
                    SET last_scan_at = NOW(),
                        next_scan_at = DATE_ADD(NOW(), INTERVAL $interval)
                    WHERE firewall_id = ?
                ");
                $update_stmt->execute([$firewall_id]);

                log_message("Next scan for $hostname scheduled for: " . date('Y-m-d H:i:s', strtotime("+$interval")));

            } else {
                $error = $scan_result['error'] ?? 'Unknown error';
                log_message("ERROR: AI scan failed for $hostname: $error");

                // Schedule retry for tomorrow
                $retry_stmt = db()->prepare("
                    UPDATE firewall_ai_settings
                    SET next_scan_at = DATE_ADD(NOW(), INTERVAL 1 DAY)
                    WHERE firewall_id = ?
                ");
                $retry_stmt->execute([$firewall_id]);

                log_message("Retry scheduled for $hostname in 24 hours");
            }

        } catch (Exception $e) {
            log_message("ERROR processing firewall $hostname: " . $e->getMessage());
            log_message("Stack trace: " . $e->getTraceAsString());

            // Schedule retry for tomorrow on exception
            try {
                $retry_stmt = db()->prepare("
                    UPDATE firewall_ai_settings
                    SET next_scan_at = DATE_ADD(NOW(), INTERVAL 1 DAY)
                    WHERE firewall_id = ?
                ");
                $retry_stmt->execute([$firewall_id]);
            } catch (Exception $retry_error) {
                log_message("ERROR: Failed to schedule retry: " . $retry_error->getMessage());
            }
        }

        // Add delay between scans to avoid overloading
        if (count($firewalls_to_scan) > 1) {
            log_message("Waiting 60 seconds before next scan...");
            sleep(60);
        }
    }

    log_message("========== Automatic AI scan scheduler completed ==========");

} catch (Exception $e) {
    log_message("FATAL ERROR: " . $e->getMessage());
    log_message("Stack trace: " . $e->getTraceAsString());
    exit(1);
}

exit(0);
?>