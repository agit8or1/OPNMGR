<?php
/**
 * Nightly Backup Script v3
 * Directly queues backup commands for all active firewalls
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';

$logfile = '/var/www/opnsense/logs/nightly_backups.log';

function log_message($message) {
    global $logfile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logfile, "[$timestamp] $message\n", FILE_APPEND);
    echo "[$timestamp] $message\n";
}

log_message("=== Starting Nightly Backup Job (v3 - Direct Command Queue) ===");

try {
    // Get all active firewalls that have checked in recently
    $stmt = db()->query("
        SELECT id, hostname, status, last_checkin
        FROM firewalls
        WHERE status = 'online'
        AND last_checkin > DATE_SUB(NOW(), INTERVAL 6 HOUR)
        ORDER BY id
    ");
    
    $firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = count($firewalls);
    $queued = 0;
    $skipped = 0;
    
    log_message("Found $total online firewalls");
    
    foreach ($firewalls as $fw) {
        $firewall_id = $fw['id'];
        $hostname = $fw['hostname'];
        
        log_message("Processing FW #$firewall_id ($hostname)");
        
        // Check if backup command already queued today
        $stmt = db()->prepare("
            SELECT COUNT(*) as count
            FROM firewall_commands
            WHERE firewall_id = ?
            AND description = 'Automated nightly backup'
            AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute([$firewall_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            log_message("  SKIP: Backup command already queued today");
            $skipped++;
            continue;
        }
        
        // Queue backup command
        $backup_command = <<<'BACKUP'
#!/bin/sh
# Automated backup via OPNManager
BACKUP_DIR="/root/backups"
mkdir -p "$BACKUP_DIR"
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="$BACKUP_DIR/config-$DATE.xml"

# Create backup
/usr/local/sbin/opnsense-backup backup > "$BACKUP_FILE" 2>&1

if [ -s "$BACKUP_FILE" ]; then
    # Backup successful - upload to manager
    /usr/local/bin/curl -k -X POST \
        -F "backup=@$BACKUP_FILE" \
        -F "firewall_id=FIREWALL_ID" \
        "https://opn.agit8or.net/api/upload_backup.php"
    
    # Clean up old backups (keep last 7 days)
    find "$BACKUP_DIR" -name "config-*.xml" -mtime +7 -delete
    
    echo "Backup created: $BACKUP_FILE"
else
    echo "ERROR: Backup file is empty"
    exit 1
fi
BACKUP;
        
        // Replace placeholder with actual firewall ID
        $backup_command = str_replace('FIREWALL_ID', $firewall_id, $backup_command);
        
        $stmt = db()->prepare('
            INSERT INTO firewall_commands (firewall_id, command, description, status, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $firewall_id,
            $backup_command,
            'Automated nightly backup',
            'pending'
        ]);
        
        log_message("  SUCCESS: Backup command queued (ID: " . db()->lastInsertId() . ")");
        $queued++;
        
        // Small delay between firewalls
        usleep(100000); // 0.1 seconds
    }
    
    log_message("=== Backup Job Complete ===");
    log_message("Total: $total, Queued: $queued, Skipped: $skipped");
    
    // Log to system_logs table
    $summary = "Nightly backups: $queued queued, $skipped skipped (Total: $total)";
    $stmt = db()->prepare("
        INSERT INTO system_logs (level, category, message, timestamp)
        VALUES ('INFO', 'backup', ?, NOW())
    ");
    $stmt->execute([$summary]);
    
    exit(0);
    
} catch (Exception $e) {
    log_message("FATAL ERROR: " . $e->getMessage());
    log_message("Stack trace: " . $e->getTraceAsString());
    exit(1);
}

