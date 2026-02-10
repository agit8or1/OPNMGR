#!/usr/bin/env php
<?php
/**
 * Automatic Stale Agent Detection and Reset Script
 *
 * This script monitors for agents that haven't checked in and attempts to:
 * 1. Detect stale agents (no check-in for configurable threshold)
 * 2. Mark them as offline in the database
 * 3. Attempt SSH-based agent restart
 * 4. Queue emergency reset commands for when agent comes back online
 * 5. Send notifications (optional)
 *
 * Usage: php auto_reset_stale_agents.php [--threshold=HOURS] [--dry-run] [--force-firewall-id=ID]
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/logging.php';

// Configuration
$STALE_THRESHOLD_HOURS = 2; // Consider agent stale after 2 hours of no check-in
$OFFLINE_THRESHOLD_HOURS = 24; // Mark as offline after 24 hours
$SSH_KEY_PATH = getenv('HOME') . '/.ssh/opnsense_key';
$SSH_TIMEOUT = 10;

// Parse command line arguments
$options = getopt('', ['threshold:', 'dry-run', 'force-firewall-id:', 'verbose']);
$DRY_RUN = isset($options['dry-run']);
$VERBOSE = isset($options['verbose']) || $DRY_RUN;
$FORCE_FIREWALL_ID = $options['force-firewall-id'] ?? null;

if (isset($options['threshold'])) {
    $STALE_THRESHOLD_HOURS = (int)$options['threshold'];
}

/**
 * Log message to stdout and system log
 */
function log_message($level, $message, $firewall_id = null) {
    global $VERBOSE;

    $timestamp = date('Y-m-d H:i:s');
    $log_line = "[$timestamp] [$level] $message";

    if ($VERBOSE || $level === 'ERROR') {
        echo $log_line . "\n";
    }

    // Log to database
    switch ($level) {
        case 'ERROR':
            log_error('auto_reset', $message, null, $firewall_id);
            break;
        case 'WARNING':
            log_warning('auto_reset', $message, null, $firewall_id);
            break;
        default:
            log_info('auto_reset', $message, null, $firewall_id);
    }
}

/**
 * Get stale agents from database
 */
function get_stale_agents($threshold_hours, $force_firewall_id = null) {
    if ($force_firewall_id) {
        $sql = "SELECT id, hostname, wan_ip, status, last_checkin, agent_version,
                       TIMESTAMPDIFF(HOUR, last_checkin, NOW()) as hours_since_checkin
                FROM firewalls
                WHERE id = :firewall_id";
        $stmt = db()->prepare($sql);
        $stmt->execute(['firewall_id' => $force_firewall_id]);
    } else {
        $sql = "SELECT id, hostname, wan_ip, status, last_checkin, agent_version,
                       TIMESTAMPDIFF(HOUR, last_checkin, NOW()) as hours_since_checkin
                FROM firewalls
                WHERE last_checkin < DATE_SUB(NOW(), INTERVAL :threshold HOUR)
                  AND (status = 'online' OR status IS NULL)
                ORDER BY hours_since_checkin DESC";
        $stmt = db()->prepare($sql);
        $stmt->execute(['threshold' => $threshold_hours]);
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Mark firewall as offline in database
 */
function mark_as_offline($firewall_id) {
    $sql = "UPDATE firewalls SET status = 'offline' WHERE id = :id";
    $stmt = db()->prepare($sql);
    return $stmt->execute(['id' => $firewall_id]);
}

/**
 * Queue emergency reset command for firewall
 */
function queue_emergency_reset($firewall_id) {
    // Generate emergency reset command
    $reset_command = 'killall -9 tunnel_agent opnsense_agent 2>/dev/null; ' .
                     'ps aux | grep -i agent | grep -v grep | awk \'{print $2}\' | xargs kill -9 2>/dev/null; ' .
                     'rm -rf /tmp/*agent* /usr/local/bin/*agent* /usr/local/opnsense_agent 2>/dev/null; ' .
                     'sleep 5; ' .
                     'fetch -q -T 30 -o /tmp/reinstall_agent.sh https://opn.agit8or.net/reinstall_agent.php?firewall_id=' . $firewall_id . ' && ' .
                     'chmod +x /tmp/reinstall_agent.sh && ' .
                     'sh /tmp/reinstall_agent.sh > /tmp/agent_reinstall.log 2>&1 &';

    // Check if command already queued
    $check_sql = "SELECT id FROM firewall_commands
                  WHERE firewall_id = :firewall_id
                    AND command_type = 'emergency_reset'
                    AND status IN ('pending', 'queued')
                  ORDER BY created_at DESC LIMIT 1";
    $stmt = db()->prepare($check_sql);
    $stmt->execute(['firewall_id' => $firewall_id]);

    if ($stmt->fetch()) {
        log_message('INFO', "Emergency reset command already queued for firewall $firewall_id");
        return true;
    }

    // Queue new command
    $sql = "INSERT INTO firewall_commands (firewall_id, command, command_type, status, description, created_at)
            VALUES (:firewall_id, :command, 'emergency_reset', 'pending', 'Auto-reset for stale agent', NOW())";
    $stmt = db()->prepare($sql);

    return $stmt->execute([
        'firewall_id' => $firewall_id,
        'command' => $reset_command
    ]);
}

/**
 * Attempt to reset agent via SSH
 */
function ssh_reset_agent($hostname, $wan_ip, $firewall_id, $ssh_key_path, $timeout) {
    global $DRY_RUN;

    if ($DRY_RUN) {
        log_message('INFO', "DRY RUN: Would attempt SSH reset for $hostname ($wan_ip)", $firewall_id);
        return false;
    }

    // Try hostname first, then IP
    $targets = [];
    if ($hostname) {
        $targets[] = $hostname;
    }
    if ($wan_ip) {
        $targets[] = $wan_ip;
    }

    foreach ($targets as $target) {
        log_message('INFO', "Attempting SSH connection to $target...", $firewall_id);

        // Build SSH command
        $ssh_opts = "-i '$ssh_key_path' -o BatchMode=yes -o ConnectTimeout=$timeout -o StrictHostKeyChecking=no";

        // Download and execute reinstall script
        $reset_script = "fetch -q -T 30 -o /tmp/reinstall_agent.sh 'https://opn.agit8or.net/reinstall_agent.php?firewall_id=$firewall_id' && chmod +x /tmp/reinstall_agent.sh && nohup sh /tmp/reinstall_agent.sh > /tmp/agent_reinstall.log 2>&1 &";

        $ssh_command = "ssh $ssh_opts root@$target '$reset_script' 2>&1";

        exec($ssh_command, $output, $return_code);

        if ($return_code === 0) {
            log_message('INFO', "Successfully executed SSH reset on $target", $firewall_id);
            return true;
        } else {
            log_message('WARNING', "SSH reset failed for $target: " . implode(' ', $output), $firewall_id);
        }
    }

    return false;
}

/**
 * Main execution
 */
function main() {
    global $STALE_THRESHOLD_HOURS, $OFFLINE_THRESHOLD_HOURS,
           $SSH_KEY_PATH, $SSH_TIMEOUT, $DRY_RUN, $FORCE_FIREWALL_ID;

    log_message('INFO', "=== Starting Stale Agent Auto-Reset Script ===");
    log_message('INFO', "Threshold: $STALE_THRESHOLD_HOURS hours | Dry Run: " . ($DRY_RUN ? 'YES' : 'NO'));

    // Get stale agents
    $stale_agents = get_stale_agents($STALE_THRESHOLD_HOURS, $FORCE_FIREWALL_ID);

    if (empty($stale_agents)) {
        log_message('INFO', "No stale agents found");
        return;
    }

    log_message('INFO', "Found " . count($stale_agents) . " stale agent(s)");

    foreach ($stale_agents as $agent) {
        $firewall_id = $agent['id'];
        $hostname = $agent['hostname'];
        $wan_ip = $agent['wan_ip'];
        $hours_stale = $agent['hours_since_checkin'];

        log_message('WARNING', "Stale agent detected: $hostname (ID: $firewall_id) - Last check-in: {$hours_stale}h ago", $firewall_id);

        // Mark as offline if past offline threshold
        if ($hours_stale >= $OFFLINE_THRESHOLD_HOURS) {
            if (!$DRY_RUN) {
                if (mark_as_offline($firewall_id)) {
                    log_message('INFO', "Marked firewall $firewall_id as offline", $firewall_id);
                }
            } else {
                log_message('INFO', "DRY RUN: Would mark firewall $firewall_id as offline", $firewall_id);
            }
        }

        // Queue emergency reset command (for when agent comes back online)
        if (!$DRY_RUN) {
            if (queue_emergency_reset($firewall_id)) {
                log_message('INFO', "Queued emergency reset command for firewall $firewall_id", $firewall_id);
            }
        } else {
            log_message('INFO', "DRY RUN: Would queue emergency reset for firewall $firewall_id", $firewall_id);
        }

        // Attempt SSH reset if we have connection info
        if (file_exists($SSH_KEY_PATH) && ($hostname || $wan_ip)) {
            $ssh_success = ssh_reset_agent($hostname, $wan_ip, $firewall_id, $SSH_KEY_PATH, $SSH_TIMEOUT);

            if ($ssh_success) {
                log_message('INFO', "SSH reset successful for $hostname", $firewall_id);
            } else {
                log_message('ERROR', "Failed to reset agent via SSH for $hostname. Emergency command queued.", $firewall_id);
            }
        } else {
            log_message('WARNING', "Cannot attempt SSH reset - missing key or connection info", $firewall_id);
        }

        echo "\n";
    }

    log_message('INFO', "=== Stale Agent Auto-Reset Script Complete ===");
}

// Run the script
try {
    main();
    exit(0);
} catch (Exception $e) {
    log_message('ERROR', "Fatal error: " . $e->getMessage());
    exit(1);
}
?>
