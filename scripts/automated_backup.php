#!/usr/bin/env php
<?php
/**
 * Automated Backup Script
 * Runs nightly to backup all active firewalls
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';

error_log("[AUTOMATED_BACKUP] ========================================");
error_log("[AUTOMATED_BACKUP] Starting automated backup run at " . date('Y-m-d H:i:s'));

try {
    // Get all firewalls that are online or in maintenance
    $stmt = db()->query("
        SELECT id, hostname, ip_address, status 
        FROM firewalls 
        WHERE status IN ('online', 'maintenance')
        ORDER BY hostname
    ");
    $firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total = count($firewalls);
    error_log("[AUTOMATED_BACKUP] Found {$total} firewalls to backup");
    
    if ($total === 0) {
        error_log("[AUTOMATED_BACKUP] No firewalls found for backup");
        exit(0);
    }
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($firewalls as $firewall) {
        $fw_id = $firewall['id'];
        $fw_name = $firewall['hostname'];
        $fw_ip = $firewall['ip_address'];
        
        error_log("[AUTOMATED_BACKUP] Processing firewall {$fw_id}: {$fw_name} ({$fw_ip})");
        
        try {
            // Generate unique backup filename
            $timestamp = date('Y-m-d_H-i-s');
            $backup_filename = "automated-backup-{$fw_id}-{$timestamp}.xml";
            error_log("[AUTOMATED_BACKUP] Generated filename: {$backup_filename}");
            
            // Create backup entry in database
            $stmt = db()->prepare("
                INSERT INTO backups (firewall_id, backup_file, backup_type, created_at) 
                VALUES (?, ?, 'automated', NOW())
            ");
            $stmt->execute([$fw_id, $backup_filename]);
            $backup_id = db()->lastInsertId();
            error_log("[AUTOMATED_BACKUP] Backup entry created with ID: {$backup_id}");
            
            // Create backup command
            $backup_command = "cp /conf/config.xml /tmp/{$backup_filename} && curl -F 'backup=@/tmp/{$backup_filename}' -F 'firewall_id={$fw_id}' -F 'backup_id={$backup_id}' https://opn.agit8or.net/api/upload_backup.php && rm -f /tmp/{$backup_filename} && echo 'Automated backup created: {$backup_filename}'";
            
            error_log("[AUTOMATED_BACKUP] Queueing backup command");
            
            $stmt = db()->prepare("
                INSERT INTO firewall_commands (firewall_id, command, description, status, created_at)
                VALUES (?, ?, 'Automated nightly configuration backup', 'pending', NOW())
            ");
            $stmt->execute([$fw_id, $backup_command]);
            $command_id = db()->lastInsertId();
            
            error_log("[AUTOMATED_BACKUP] Command queued with ID: {$command_id}");
            
            // Log to system_logs
            $stmt = db()->prepare("
                INSERT INTO system_logs (firewall_id, category, message, level, timestamp) 
                VALUES (?, 'backup', ?, 'INFO', NOW())
            ");
            $log_message = "Automated backup queued: {$backup_filename} (Command ID: {$command_id}, Backup ID: {$backup_id})";
            $stmt->execute([$fw_id, $log_message]);
            
            error_log("[AUTOMATED_BACKUP] SUCCESS: Backup queued for {$fw_name}");
            $success_count++;
            
        } catch (Exception $e) {
            error_log("[AUTOMATED_BACKUP] ERROR backing up {$fw_name}: " . $e->getMessage());
            
            // Log error to system_logs
            $stmt = db()->prepare("
                INSERT INTO system_logs (firewall_id, category, message, level, timestamp) 
                VALUES (?, 'backup', ?, 'ERROR', NOW())
            ");
            $error_message = "Automated backup failed: " . $e->getMessage();
            $stmt->execute([$fw_id, $error_message]);
            
            $error_count++;
        }
    }
    
    error_log("[AUTOMATED_BACKUP] ========================================");
    error_log("[AUTOMATED_BACKUP] Backup run completed: {$success_count} successful, {$error_count} errors");
    error_log("[AUTOMATED_BACKUP] ========================================");
    
    exit($error_count > 0 ? 1 : 0);
    
} catch (Exception $e) {
    error_log("[AUTOMATED_BACKUP] FATAL ERROR: " . $e->getMessage());
    error_log("[AUTOMATED_BACKUP] Trace: " . $e->getTraceAsString());
    exit(1);
}
