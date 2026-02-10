<?php
/**
 * Automatic SpeedTest Scheduler
 * Runs 24/7 - Each firewall gets a random time each day
 *
 * Run via cron: 0 * * * * php /var/www/opnsense/api/schedule_speedtest.php
 * (Runs every hour)
 */
// Current time
require_once __DIR__ . '/../inc/bootstrap.php';

$current_hour = (int)date('H');
$current_minute = (int)date('i');
$current_date = date('Y-m-d');

// Speedtest runs 24/7 - no time restriction
$in_speedtest_window = true;

try {
    // Get all online firewalls
    $stmt = db()->prepare('SELECT id FROM firewalls WHERE status = "online" ORDER BY id');
    $stmt->execute();
    $firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($firewalls)) {
        error_log("[SpeedTest Scheduler] No active firewalls found.");
        exit(0);
    }
    
    error_log("[SpeedTest Scheduler] Found " . count($firewalls) . " active firewalls");
    
    // For each firewall, check if it should run speedtest
    foreach ($firewalls as $fw) {
        $fw_id = $fw['id'];
        
        // Generate random time for this firewall if not already set for today
        $scheduled_time_stmt = db()->prepare('
            SELECT scheduled_speedtest_time, last_speedtest_date
            FROM firewalls 
            WHERE id = ?
        ');
        $scheduled_time_stmt->execute([$fw_id]);
        $fw_schedule = $scheduled_time_stmt->fetch(PDO::FETCH_ASSOC);
        
        $last_speedtest_date = $fw_schedule['last_speedtest_date'] ?? null;
        $scheduled_time = $fw_schedule['scheduled_speedtest_time'] ?? null;
        
        // If we haven't scheduled today, generate a random time
        if ($last_speedtest_date !== $current_date) {
            // Random time across all 24 hours
            $random_hour = rand(0, 23);
            $random_minute = rand(0, 59);

            $scheduled_time = sprintf('%02d:%02d', $random_hour, $random_minute);
            
            // Update firewall with new scheduled time and today's date
            $update_stmt = db()->prepare('
                UPDATE firewalls 
                SET scheduled_speedtest_time = ?, last_speedtest_date = ?
                WHERE id = ?
            ');
            $update_stmt->execute([$scheduled_time, $current_date, $fw_id]);
            
            error_log("[SpeedTest Scheduler] FW$fw_id: Scheduled speedtest for today at $scheduled_time");
        }
        
        // Check if it's time to run
        if ($scheduled_time) {
            list($sched_hour, $sched_minute) = explode(':', $scheduled_time);
            $sched_hour = (int)$sched_hour;
            $sched_minute = (int)$sched_minute;
            
            // Run if we're in the scheduled hour (cron runs hourly at :00)
            if ($current_hour === $sched_hour) {
                // Check if already queued today
                $check_stmt = db()->prepare('
                    SELECT id FROM firewall_commands 
                    WHERE firewall_id = ? 
                    AND command_type = "speedtest"
                    AND DATE(created_at) = ?
                    AND status != "cancelled"
                    LIMIT 1
                ');
                $check_stmt->execute([$fw_id, $current_date]);
                $existing_command = $check_stmt->fetch();
                
                if (!$existing_command) {
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
                        'Automatic scheduled speedtest - ' . date('Y-m-d H:i:s'),
                        'pending'
                    ]);
                    
                    error_log("[SpeedTest Scheduler] FW$fw_id: Speedtest command queued at $current_hour:$current_minute");
                } else {
                    error_log("[SpeedTest Scheduler] FW$fw_id: Speedtest already queued for today");
                }
            }
        }
    }
    
    error_log("[SpeedTest Scheduler] Completed successfully");
    
} catch (Exception $e) {
    error_log("[SpeedTest Scheduler] Error: " . $e->getMessage());
    exit(1);
}
?>
