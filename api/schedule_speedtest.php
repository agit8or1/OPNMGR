<?php
/**
 * Automatic SpeedTest Scheduler
 * Interval-based per firewall (configurable: 2h, 4h, 8h, 12h, 24h, or disabled)
 *
 * Run via cron: 0 * * * * php /var/www/opnsense/api/schedule_speedtest.php
 * (Runs every hour)
 */
require_once __DIR__ . '/../inc/bootstrap.php';

try {
    // Get all online firewalls with their speedtest interval setting
    $stmt = db()->prepare('
        SELECT id, hostname, speedtest_interval_hours
        FROM firewalls
        WHERE status = "online"
        ORDER BY id
    ');
    $stmt->execute();
    $firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($firewalls)) {
        error_log("[SpeedTest Scheduler] No active firewalls found.");
        exit(0);
    }

    error_log("[SpeedTest Scheduler] Found " . count($firewalls) . " active firewalls");

    foreach ($firewalls as $fw) {
        $fw_id = $fw['id'];
        $interval = (int)($fw['speedtest_interval_hours'] ?? 4);

        // Skip disabled firewalls
        if ($interval <= 0) {
            error_log("[SpeedTest Scheduler] FW$fw_id: Speedtest disabled, skipping");
            continue;
        }

        // Check last completed speedtest time
        $last_stmt = db()->prepare('
            SELECT tested_at
            FROM bandwidth_tests
            WHERE firewall_id = ?
              AND test_status = "completed"
            ORDER BY tested_at DESC
            LIMIT 1
        ');
        $last_stmt->execute([$fw_id]);
        $last_test = $last_stmt->fetch(PDO::FETCH_ASSOC);

        if ($last_test) {
            $last_time = strtotime($last_test['tested_at']);
            $elapsed_hours = (time() - $last_time) / 3600;

            if ($elapsed_hours < $interval) {
                $remaining = round($interval - $elapsed_hours, 1);
                error_log("[SpeedTest Scheduler] FW$fw_id: Last test " . round($elapsed_hours, 1) . "h ago, next in {$remaining}h (interval: {$interval}h)");
                continue;
            }
        }

        // Check for existing pending/sent speedtest command (avoid duplicates)
        $dup_stmt = db()->prepare('
            SELECT id FROM firewall_commands
            WHERE firewall_id = ?
              AND command_type = "speedtest"
              AND status IN ("pending", "sent")
            LIMIT 1
        ');
        $dup_stmt->execute([$fw_id]);

        if ($dup_stmt->fetch()) {
            error_log("[SpeedTest Scheduler] FW$fw_id: Speedtest already queued, skipping");
            continue;
        }

        // Queue the speedtest
        $insert_stmt = db()->prepare('
            INSERT INTO firewall_commands
            (firewall_id, command_type, command, description, status, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ');
        $insert_stmt->execute([
            $fw_id,
            'speedtest',
            'run_speedtest',
            'Automatic scheduled speedtest (every ' . $interval . 'h) - ' . date('Y-m-d H:i:s'),
            'pending'
        ]);

        error_log("[SpeedTest Scheduler] FW$fw_id: Speedtest command queued (interval: {$interval}h)");
    }

    error_log("[SpeedTest Scheduler] Completed successfully");

} catch (Exception $e) {
    error_log("[SpeedTest Scheduler] Error: " . $e->getMessage());
    exit(1);
}
?>
