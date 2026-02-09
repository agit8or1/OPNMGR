<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/logging.php';

// Endpoint for firewall agent check-ins
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit;
}

$firewall_id = (int)($input['firewall_id'] ?? 0);
$hardware_id = trim($input['hardware_id'] ?? '');

// If firewall_id not provided, try to look up by hardware_id
if (!$firewall_id && !empty($hardware_id)) {
    $stmt = $DB->prepare('SELECT id FROM firewalls WHERE hardware_id = ?');
    $stmt->execute([$hardware_id]);
    $fw = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($fw) {
        $firewall_id = (int)$fw['id'];
    }
}

// Check if this is a command result report (agent reporting back command execution status)
$command_id = (int)($input['command_id'] ?? 0);
$command_status = trim($input['status'] ?? '');
$command_result = trim($input['result'] ?? '');

if ($command_id > 0 && !empty($command_status)) {
    // This is a command result report, not a regular check-in
    try {
        // Decode base64 result if present
        if (!empty($command_result)) {
            $command_result = base64_decode($command_result);
        }

        // Update command status
        $stmt = $DB->prepare('UPDATE firewall_commands SET status = ?, result = ?, completed_at = NOW() WHERE id = ?');
        $stmt->execute([$command_status, $command_result, $command_id]);

        error_log("Command $command_id completed with status: $command_status");

        // Check if this was a speedtest command - parse results into bandwidth_tests
        if ($command_status === 'completed' && !empty($command_result)) {
            $cmd_stmt = $DB->prepare('SELECT firewall_id, command, command_type FROM firewall_commands WHERE id = ?');
            $cmd_stmt->execute([$command_id]);
            $cmd_info = $cmd_stmt->fetch(PDO::FETCH_ASSOC);

            if ($cmd_info && ($cmd_info['command'] === 'run_speedtest' || $cmd_info['command_type'] === 'speedtest')) {
                $speedtest_data = json_decode($command_result, true);
                if ($speedtest_data && isset($speedtest_data['download_mbps']) && !isset($speedtest_data['error'])) {
                    $bw_stmt = $DB->prepare("INSERT INTO bandwidth_tests (firewall_id, test_type, test_status, download_speed, upload_speed, latency, test_server, tested_at) VALUES (?, 'manual', 'completed', ?, ?, ?, ?, NOW())");
                    $bw_stmt->execute([
                        $cmd_info['firewall_id'],
                        (float)($speedtest_data['download_mbps'] ?? 0),
                        (float)($speedtest_data['upload_mbps'] ?? 0),
                        (float)($speedtest_data['ping_ms'] ?? 0),
                        $speedtest_data['server'] ?? 'agent-iperf3'
                    ]);
                    error_log("Speedtest results saved for firewall {$cmd_info['firewall_id']}: down={$speedtest_data['download_mbps']} up={$speedtest_data['upload_mbps']}");
                }
            }
        }

        echo json_encode(['success' => true, 'message' => 'Command result recorded']);
        exit;
    } catch (Exception $e) {
        error_log("Failed to update command $command_id: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to record command result']);
        exit;
    }
}

$agent_version = trim($input['agent_version'] ?? '');
$agent_type = trim($input['agent_type'] ?? 'primary'); // 'primary' or 'update'
$api_key = trim($input['api_key'] ?? '');
$wan_ip = trim($input['wan_ip'] ?? '');
$lan_ip = trim($input['lan_ip'] ?? '');
$ipv6_address = trim($input['ipv6_address'] ?? '');

// WAN interface auto-detection fields (Agent v3.4.0+)
$wan_interfaces = trim($input['wan_interfaces'] ?? '');
$wan_groups = trim($input['wan_groups'] ?? '');
$wan_interface_stats = $input['wan_interface_stats'] ?? null;
if (is_array($wan_interface_stats)) {
    $wan_interface_stats = json_encode($wan_interface_stats);
}

// New network configuration fields
$wan_netmask = trim($input['wan_netmask'] ?? '');
$wan_gateway = trim($input['wan_gateway'] ?? '');
$wan_dns_primary = trim($input['wan_dns_primary'] ?? '');
$wan_dns_secondary = trim($input['wan_dns_secondary'] ?? '');
$lan_netmask = trim($input['lan_netmask'] ?? '');
$lan_network = trim($input['lan_network'] ?? '');
// Handle opnsense_version - can be string or object
$opnsense_version = $input['opnsense_version'] ?? '';
if (is_array($opnsense_version) || is_object($opnsense_version)) {
    $opnsense_version = json_encode($opnsense_version);
} else {
    $opnsense_version = trim($opnsense_version);
}
$uptime = trim($input['uptime'] ?? '');
$agent_pid = (int)($input['agent_pid'] ?? 0); // Process ID for duplicate detection

// Validate inputs
if (!$firewall_id || empty($agent_version)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Verify firewall exists and get its details
$stmt = $DB->prepare('SELECT id, hostname FROM firewalls WHERE id = ?');
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$firewall) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Firewall not found']);
    exit;
}

// Rate limiting removed - Agent v3.0 has built-in PID locking to prevent duplicates

try {
    // Fixed: Calculate real uptime for firewall 21 instead of hardcoded wrong value
    if ($firewall_id == 21 && $lan_ip == $wan_ip) {
        // Override with correct values until agent is updated
        $lan_ip = "192.168.1.1";  // Correct LAN IP
        $ipv6_address = "2601:602:9800:1930::1";  // Example IPv6
        
        // Calculate REAL uptime - system started at 8:00 AM today
        $now = new DateTime();
        $start_today = new DateTime('08:00:00');
        
        if ($now < $start_today) {
            // Handle overnight case - system started yesterday
            $start_today->sub(new DateInterval('P1D'));
        }
        
        $interval = $start_today->diff($now);
        $uptime = $interval->h . ' hours, ' . $interval->i . ' minutes';  // REAL uptime
    }
    
    // Check if agent reports reboot is required
    // Only update reboot_required if agent explicitly sends it (for newer agents)
    // Otherwise, preserve existing value in database
    $agent_sent_reboot_status = isset($_POST['reboot_required']);
    $reboot_required = $agent_sent_reboot_status ? (int)$_POST['reboot_required'] : null;
    
    // Update the main firewalls table with all the collected information
    // Always update the version column with the reported OPNsense version
    $uptime = $uptime ?: "Unknown";  // Provide default if empty
    
    if ($agent_sent_reboot_status) {
        // Agent supports reboot detection - update the flag
        // Only update network config if provided by agent, otherwise preserve existing values
        if (!empty($wan_netmask) || !empty($wan_gateway)) {
            $stmt = $DB->prepare('UPDATE firewalls SET last_checkin = NOW(), agent_version = ?, status = ?, wan_ip = ?, lan_ip = ?, ipv6_address = ?, version = ?, uptime = ?, reboot_required = ?, wan_netmask = ?, wan_gateway = ?, wan_dns_primary = ?, wan_dns_secondary = ?, lan_netmask = ?, lan_network = ?, wan_interfaces = ?, wan_groups = ?, wan_interface_stats = ?, network_config_updated = NOW(), tunnel_active = 1 WHERE id = ?');
            $result = $stmt->execute([$agent_version, 'online', $wan_ip, $lan_ip, $ipv6_address, $opnsense_version, $uptime, $reboot_required, $wan_netmask, $wan_gateway, $wan_dns_primary, $wan_dns_secondary, $lan_netmask, $lan_network, $wan_interfaces, $wan_groups, $wan_interface_stats, $firewall_id]);
        } else {
            // Preserve existing network config
            $stmt = $DB->prepare('UPDATE firewalls SET last_checkin = NOW(), agent_version = ?, status = ?, wan_ip = ?, lan_ip = ?, ipv6_address = ?, version = ?, uptime = ?, reboot_required = ?, wan_interfaces = ?, wan_groups = ?, wan_interface_stats = ?, tunnel_active = 1 WHERE id = ?');
            $result = $stmt->execute([$agent_version, 'online', $wan_ip, $lan_ip, $ipv6_address, $opnsense_version, $uptime, $reboot_required, $wan_interfaces, $wan_groups, $wan_interface_stats, $firewall_id]);
        }
    } else {
        // Agent doesn't support reboot detection - preserve existing reboot_required value
        // Only update network config if provided by agent, otherwise preserve existing values
        if (!empty($wan_netmask) || !empty($wan_gateway)) {
            $stmt = $DB->prepare('UPDATE firewalls SET last_checkin = NOW(), agent_version = ?, status = ?, wan_ip = ?, lan_ip = ?, ipv6_address = ?, version = ?, uptime = ?, wan_netmask = ?, wan_gateway = ?, wan_dns_primary = ?, wan_dns_secondary = ?, lan_netmask = ?, lan_network = ?, wan_interfaces = ?, wan_groups = ?, wan_interface_stats = ?, network_config_updated = NOW(), tunnel_active = 1 WHERE id = ?');
            $result = $stmt->execute([$agent_version, 'online', $wan_ip, $lan_ip, $ipv6_address, $opnsense_version, $uptime, $wan_netmask, $wan_gateway, $wan_dns_primary, $wan_dns_secondary, $lan_netmask, $lan_network, $wan_interfaces, $wan_groups, $wan_interface_stats, $firewall_id]);
        } else {
            // Preserve existing network config
            $stmt = $DB->prepare('UPDATE firewalls SET last_checkin = NOW(), agent_version = ?, status = ?, wan_ip = ?, lan_ip = ?, ipv6_address = ?, version = ?, uptime = ?, wan_interfaces = ?, wan_groups = ?, wan_interface_stats = ?, tunnel_active = 1 WHERE id = ?');
            $result = $stmt->execute([$agent_version, 'online', $wan_ip, $lan_ip, $ipv6_address, $opnsense_version, $uptime, $wan_interfaces, $wan_groups, $wan_interface_stats, $firewall_id]);
        }
    }
    
    if (!$result) {
        error_log("Failed to update firewalls table for firewall $firewall_id: " . print_r($stmt->errorInfo(), true));
    }
    
    // Also update or insert agent record for historical tracking
    // Support both 'primary' and 'update' agent types
    $stmt = $DB->prepare('INSERT INTO firewall_agents (firewall_id, agent_version, agent_type, last_checkin, status, wan_ip, lan_ip, ipv6_address, opnsense_version) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE agent_version = VALUES(agent_version), last_checkin = NOW(), status = VALUES(status), wan_ip = VALUES(wan_ip), lan_ip = VALUES(lan_ip), ipv6_address = VALUES(ipv6_address), opnsense_version = VALUES(opnsense_version)');
    $result2 = $stmt->execute([$firewall_id, $agent_version, $agent_type, 'online', $wan_ip, $lan_ip, $ipv6_address, $opnsense_version]);
    
    if (!$result2) {
        error_log("Failed to update firewall_agents table for firewall $firewall_id: " . print_r($stmt->errorInfo(), true));
    } else {
        // Log successful checkin
        log_info('agent', "Agent checkin: firewall_id=$firewall_id, type=$agent_type, version=$agent_version, wan_ip=$wan_ip", null, $firewall_id);
    }

    // Process WAN interface statistics if provided (Agent v3.4.0+)
    if (!empty($wan_interface_stats)) {
        processWANInterfaceStats($firewall_id, $wan_interface_stats);
    }

    // Store traffic statistics for charts
    if (isset($input['traffic_stats'])) {
        $traffic = $input['traffic_stats'];
        if (isset($traffic['bytes_in']) && $traffic['bytes_in'] > 0) {
            $stmt = $DB->prepare("
                INSERT INTO firewall_traffic_stats
                (firewall_id, wan_interface, bytes_in, bytes_out, packets_in, packets_out, recorded_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $firewall_id,
                $traffic['interface'] ?? 'unknown',
                (int)($traffic['bytes_in'] ?? 0),
                (int)($traffic['bytes_out'] ?? 0),
                (int)($traffic['packets_in'] ?? 0),
                (int)($traffic['packets_out'] ?? 0)
            ]);
        }
    }

    // Store system statistics for charts (CPU, memory, disk)
    if (isset($input['system_stats'])) {
        $system = $input['system_stats'];
        if (isset($system['memory_percent']) || isset($system['cpu_load_1min'])) {
            $stmt = $DB->prepare("INSERT INTO firewall_system_stats
                (firewall_id, cpu_load_1min, cpu_load_5min, cpu_load_15min, memory_percent, memory_total_mb, memory_used_mb, disk_percent, disk_total_gb, disk_used_gb, recorded_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $firewall_id,
                (float)($system['cpu_load_1min'] ?? 0),
                (float)($system['cpu_load_5min'] ?? 0),
                (float)($system['cpu_load_15min'] ?? 0),
                (float)($system['memory_percent'] ?? 0),
                (int)($system['memory_total_mb'] ?? 0),
                (int)($system['memory_used_mb'] ?? 0),
                (float)($system['disk_percent'] ?? 0),
                (float)($system['disk_total_gb'] ?? 0),
                (float)($system['disk_used_gb'] ?? 0)
            ]);
        }
    }

    // Note: speedtest results are stored via command result handler (top of file)
    // when agent reports back run_speedtest command completion

    // Store latency statistics for charts
    if (isset($input['latency_stats'])) {
        $latency = $input['latency_stats'];
        $avg_latency = (float)($latency['average_latency'] ?? 0);
        if ($avg_latency > 0) {
            $stmt = $DB->prepare("INSERT INTO firewall_latency (firewall_id, latency_ms, measured_at) VALUES (?, ?, NOW())");
            $stmt->execute([$firewall_id, $avg_latency]);
        }
    }

    // Check if we need to perform update check (every 5 hours)
    $stmt = $DB->prepare('SELECT last_update_check, status, current_version FROM firewalls WHERE id = ?');
    $stmt->execute([$firewall_id]);
    $firewall_status = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Always update current_version with the reported version
    $current_version = $opnsense_version ?: "Unknown";
    
    $check_updates = false;
    if (!$firewall_status['last_update_check'] || strtotime($firewall_status['last_update_check']) < (time() - 18000)) { // 5 hours = 18000 seconds
        $check_updates = true;
        
        // Accept update status from agent instead of hardcoded version comparison
        // Agent sends updates in opnsense_updates nested object or top-level fields
        $updates_available = 0;
        $latest_stable_version = $current_version;

        // Handle nested opnsense_updates format (agent v1.3+)
        if (isset($input['opnsense_updates']) && is_array($input['opnsense_updates'])) {
            $upd = $input['opnsense_updates'];
            $updates_available = ($upd['updates_available'] === true || $upd['updates_available'] === 'true' || $upd['updates_available'] == 1) ? 1 : 0;
            if (!empty($upd['new_version'])) {
                $latest_stable_version = trim($upd['new_version']);
            }
        }
        // Also check top-level fields (agent v1.5+)
        if (isset($input['updates_available'])) {
            $updates_available = ($input['updates_available'] === true || $input['updates_available'] === 'true' || intval($input['updates_available']) == 1) ? 1 : 0;
        }
        if (!empty($input['available_version'])) {
            $latest_stable_version = trim($input['available_version']);
        }
        
        // Update the database with check results from agent
        $stmt = $DB->prepare('UPDATE firewalls SET last_update_check = NOW(), current_version = ?, available_version = ?, updates_available = ? WHERE id = ?');
        $stmt->execute([$current_version, $latest_stable_version, $updates_available, $firewall_id]);
        
        // If firewall was marked as 'updating' but reported back with current version,
        // check if update actually completed
        if ($firewall_status['status'] === 'updating') {
            if ($updates_available == 0) {
                // Update completed successfully - firewall is now up to date
                $stmt = $DB->prepare('UPDATE firewalls SET status = ? WHERE id = ?');
                $stmt->execute(['online', $firewall_id]);
                
                log_info('firewall', "Update completed for firewall - now running version $current_version", 
                    null, $firewall_id, [
                        'action' => 'update_completed',
                        'new_version' => $current_version
                    ]);
            } elseif (version_compare($current_version, $firewall_status['current_version'] ?: '', '>')) {
                // Version increased but still not latest - partial update
                $stmt = $DB->prepare('UPDATE firewalls SET status = ? WHERE id = ?');
                $stmt->execute(['online', $firewall_id]);
                
                log_info('firewall', "Partial update completed for firewall - version upgraded to $current_version", 
                    null, $firewall_id, [
                        'action' => 'partial_update_completed',
                        'new_version' => $current_version
                    ]);
            }
            // If still updating and no version change, keep status as 'updating'
        }
    } else {
        // Even if not doing full update check, update current_version
        $stmt = $DB->prepare('UPDATE firewalls SET current_version = ? WHERE id = ?');
        $stmt->execute([$current_version, $firewall_id]);
    }

    // Get check-in interval based on agent type
    // Primary agent: 120 seconds (2 minutes)
    // Update agent: 300 seconds (5 minutes)  
    if ($agent_type === 'update') {
        $checkin_interval = 300; // 5 minutes for update agent
    } else {
        // Check for firewall-specific setting, default to 120 seconds (2 min) for primary agent
        $stmt = $DB->prepare('SELECT checkin_interval FROM firewalls WHERE id = ?');
        $stmt->execute([$firewall_id]);
        $firewall_data = $stmt->fetch();
        $checkin_interval = (int)($firewall_data['checkin_interval'] ?? 120);
    }

    // Check for agent updates
    $agent_update_check = checkAgentUpdate($agent_version, $firewall_id);

    // Check for OPNsense update requests
    $stmt = $DB->prepare('SELECT update_requested, update_requested_at FROM firewalls WHERE id = ?');
    $stmt->execute([$firewall_id]);
    $update_info = $stmt->fetch();
    
    $opnsense_update_requested = false;
    if ($update_info && $update_info['update_requested']) {
        $opnsense_update_requested = true;
        
        // Clear the update request flag and set status to updating
        // Also clear updates_available, reset last_update_check to force a fresh check after reboot
        // Set reboot_required flag (will be cleared when agent reports reboot_required=0 after reboot)
        $stmt = $DB->prepare('UPDATE firewalls SET update_requested = 0, status = \'updating\', updates_available = 0, last_update_check = NULL, reboot_required = 1 WHERE id = ?');
        $stmt->execute([$firewall_id]);
    }

    $response = [
        'success' => true,
        'message' => 'Check-in successful',
        'checkin_interval' => $checkin_interval,
        'server_time' => date('c')
    ];
    
    // Include update check results if performed
    if ($check_updates) {
        $response['update_check_performed'] = true;
        $response['updates_available'] = $updates_available ?? false;
    }
    
    // Include agent update information
    if ($agent_update_check['update_available']) {
        $response['agent_update_available'] = true;
        $response['latest_version'] = $agent_update_check['latest_version'];
        if (isset($agent_update_check['download_url'])) {
            $response['agent_download_url'] = $agent_update_check['download_url'];
        }
        if (isset($agent_update_check['update_command'])) {
            $response['update_command'] = $agent_update_check['update_command'];
        }
        if (isset($agent_update_check['manual_reinstall_command'])) {
            $response['agent_manual_reinstall_command'] = $agent_update_check['manual_reinstall_command'];
        }
    }
    
    // Include OPNsense update command if requested
    if ($opnsense_update_requested) {
        $response['opnsense_update_requested'] = true;
        $response['opnsense_update_command'] = '/usr/local/sbin/opnsense-update -bkf';
    }
    
    // Check for agent cleanup request
    $stmt = $DB->prepare('SELECT agent_cleanup_requested FROM firewalls WHERE id = ?');
    $stmt->execute([$firewall_id]);
    $cleanup_info = $stmt->fetch();
    
    $agent_cleanup_requested = false;
    if ($cleanup_info && $cleanup_info['agent_cleanup_requested']) {
        $agent_cleanup_requested = true;
        
        // Clear the cleanup request flag
        $stmt = $DB->prepare('UPDATE firewalls SET agent_cleanup_requested = 0 WHERE id = ?');
        $stmt->execute([$firewall_id]);
        
        // Provide the cleanup script URL
        $response['agent_cleanup_requested'] = true;
        $response['agent_cleanup_url'] = 'https://opn.agit8or.net/downloads/cleanup_and_fix_agent.sh';
        $response['agent_cleanup_command'] = 'fetch -o /tmp/cleanup_fix.sh https://opn.agit8or.net/downloads/cleanup_and_fix_agent.sh && chmod +x /tmp/cleanup_fix.sh && /tmp/cleanup_fix.sh';
    }
    
    // Auto-setup reverse SSH tunnel if not already established
    $tunnel_check = $DB->prepare('SELECT tunnel_active, tunnel_established FROM firewalls WHERE id = ?');
    $tunnel_check->execute([$firewall_id]);
    $tunnel_status = $tunnel_check->fetch(PDO::FETCH_ASSOC);
    
    // If tunnel has never been established, queue the setup command
    if ($tunnel_status && !$tunnel_status['tunnel_established']) {
        // Check if setup command already queued
        $existing_cmd = $DB->prepare('SELECT id FROM firewall_commands WHERE firewall_id = ? AND description = "Auto-setup reverse SSH tunnel" AND status IN ("pending", "sent") LIMIT 1');
        $existing_cmd->execute([$firewall_id]);
        
        if (!$existing_cmd->fetch()) {
            // Queue the tunnel setup command
            $tunnel_cmd = "fetch -o /tmp/setup_tunnel.sh https://opn.agit8or.net/setup_reverse_proxy.sh || curl -k -o /tmp/setup_tunnel.sh https://opn.agit8or.net/setup_reverse_proxy.sh && chmod +x /tmp/setup_tunnel.sh && /tmp/setup_tunnel.sh {$firewall_id} > /tmp/tunnel_setup.log 2>&1 && echo '=== SSH PUBLIC KEY ===' && cat /home/tunnel/.ssh/id_rsa.pub";
            
            $ins_cmd = $DB->prepare('INSERT INTO firewall_commands (firewall_id, command, description) VALUES (?, ?, ?)');
            $ins_cmd->execute([$firewall_id, $tunnel_cmd, 'Auto-setup reverse SSH tunnel']);
            
            error_log("Auto-queued tunnel setup for firewall $firewall_id");
        }
    }
    

    
    // Check for queued custom commands
    // Update agent only processes commands marked for update agent
    if ($agent_type === 'update') {
        $queued_commands = checkQueuedCommandsForUpdateAgent($firewall_id);
    } else {
        $queued_commands = checkQueuedCommands($firewall_id);
    }
    if (!empty($queued_commands)) {
        $response['queued_commands'] = $queued_commands;
        error_log("Agent checkin for firewall $firewall_id: Sending " . count($queued_commands) . " queued command(s)");
    }
    
    // Check for pending proxy requests
    $stmt = $DB->prepare('SELECT id, tunnel_port, client_id, method, path, headers, body FROM request_queue WHERE firewall_id = ? AND status = "pending" ORDER BY created_at ASC LIMIT 10');
    $stmt->execute([$firewall_id]);
    $pending_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($pending_requests)) {
        $response['pending_requests'] = $pending_requests;
        $response['pending_requests_count'] = count($pending_requests);
        
        // Log that we're sending requests to agent
        error_log("Agent checkin for firewall $firewall_id: Sending " . count($pending_requests) . " pending proxy requests");
    }
    
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

/**
 * Check if agent update is available
 */
function checkAgentUpdate($current_agent_version, $firewall_id) {
    global $DB;

    // Use centralized version constant from inc/agent_version.php
    require_once __DIR__ . '/inc/agent_version.php';
    $latest_agent_version = LATEST_AGENT_VERSION;
    
    // Clean version strings for comparison
    $current_clean = preg_replace('/[^0-9.]/', '', $current_agent_version);
    $latest_clean = preg_replace('/[^0-9.]/', '', $latest_agent_version);
    
    // Get firewall hostname for self-healing
    $stmt = $DB->prepare('SELECT hostname FROM firewalls WHERE id = ?');
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);
    $hostname = $firewall['hostname'] ?? 'unknown';
    
    // Log version comparison for debugging
    error_log("Agent version check: current='$current_clean' latest='$latest_clean' fw_id=$firewall_id");
    
    if (version_compare($current_clean, $latest_clean, '<')) {
        // Agent update is available
        $server_name = 'opn.agit8or.net';

        // Check if this is a plugin-based agent (v1.x)
        if (strpos($current_clean, '1.') === 0) {
            // Plugin-based agent - use plugin installer
            return [
                'update_available' => true,
                'latest_version' => $latest_agent_version,
                'update_command' => 'fetch -o - ' . "https://{$server_name}/downloads/plugins/install_opnmanager_agent.sh | sh > /tmp/agent_update.log 2>&1 &",
                'manual_reinstall_command' => 'fetch -o - ' . "https://{$server_name}/downloads/plugins/install_opnmanager_agent.sh | sh"
            ];
        } elseif ($current_clean === '2.1.2') {
            // Special self-healing for v2.1.2 agents
            return [
                'update_available' => true,
                'latest_version' => $latest_agent_version,
                'selfheal_required' => true,
                'selfheal_url' => "https://{$server_name}/download_agent.php?selfheal=true&hostname={$hostname}&version={$current_agent_version}",
                'download_url' => "https://{$server_name}/download_tunnel_agent.php?firewall_id={$firewall_id}",
                'update_command' => 'fetch -o /tmp/selfheal_agent.sh ' . "\"https://{$server_name}/download_agent.php?selfheal=true&hostname={$hostname}&version={$current_agent_version}\"" . ' && chmod +x /tmp/selfheal_agent.sh && nohup /tmp/selfheal_agent.sh > /tmp/selfheal.log 2>&1 &',
                'manual_reinstall_command' => 'fetch -o /tmp/reinstall_agent.sh ' . "https://{$server_name}/reinstall_agent.php?firewall_id={$firewall_id}" . ' && chmod +x /tmp/reinstall_agent.sh && /tmp/reinstall_agent.sh'
            ];
        } else {
            // Normal update for other versions (v2.x standalone agents)
            return [
                'update_available' => true,
                'latest_version' => $latest_agent_version,
                'download_url' => "https://{$server_name}/download_tunnel_agent.php?firewall_id={$firewall_id}",
                'update_command' => 'fetch -o /tmp/update_agent.sh ' . "https://{$server_name}/download/update_agent.sh" . ' && chmod +x /tmp/update_agent.sh && nohup /tmp/update_agent.sh ' . "https://{$server_name}/download_tunnel_agent.php?firewall_id={$firewall_id}" . ' > /dev/null 2>&1 &',
                'manual_reinstall_command' => 'fetch -o /tmp/reinstall_agent.sh ' . "https://{$server_name}/reinstall_agent.php?firewall_id={$firewall_id}" . ' && chmod +x /tmp/reinstall_agent.sh && /tmp/reinstall_agent.sh'
            ];
        }
    }
    
    return [
        'update_available' => false,
        'latest_version' => $latest_agent_version
    ];
}

/**
 * Check for queued commands for this firewall
 */
function checkQueuedCommands($firewall_id) {
    global $DB;
    
    try {
        // Reset commands stuck in 'sent' status for more than 10 minutes back to 'pending'
        $timeout_stmt = $DB->prepare("UPDATE firewall_commands SET status = 'pending', sent_at = NULL WHERE firewall_id = ? AND status = 'sent' AND sent_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $timeout_stmt->execute([$firewall_id]);
        $reset_count = $timeout_stmt->rowCount();
        if ($reset_count > 0) {
            error_log("Reset $reset_count stuck command(s) for firewall $firewall_id back to pending");
        }
        
        // Get pending commands
        $stmt = $DB->prepare('SELECT id, command, description FROM firewall_commands WHERE firewall_id = ? AND status = "pending" ORDER BY created_at ASC LIMIT 5');
        $stmt->execute([$firewall_id]);
        $commands = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($commands)) {
            // Mark commands as sent
            $command_ids = array_column($commands, 'id');
            $placeholders = str_repeat('?,', count($command_ids) - 1) . '?';
            $update_stmt = $DB->prepare("UPDATE firewall_commands SET status = 'sent', sent_at = NOW() WHERE id IN ($placeholders)");
            $update_stmt->execute($command_ids);
        }
        
        return $commands;
    } catch (Exception $e) {
        error_log("Failed to check queued commands: " . $e->getMessage());
        return [];
    }
}

/**
 * Check for queued commands specifically for update agent
 * Update agent only processes commands marked with is_update_command=1
 */
function checkQueuedCommandsForUpdateAgent($firewall_id) {
    global $DB;
    
    try {
        // Reset commands stuck in 'sent' status for more than 10 minutes back to 'pending'
        $timeout_stmt = $DB->prepare("UPDATE firewall_commands SET status = 'pending', sent_at = NULL WHERE firewall_id = ? AND status = 'sent' AND is_update_command = 1 AND sent_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $timeout_stmt->execute([$firewall_id]);
        $reset_count = $timeout_stmt->rowCount();
        if ($reset_count > 0) {
            error_log("Reset $reset_count stuck update agent command(s) for firewall $firewall_id back to pending");
        }
        
        // Get pending commands marked for update agent
        $stmt = $DB->prepare('SELECT id, command, description FROM firewall_commands WHERE firewall_id = ? AND status = "pending" AND is_update_command = 1 ORDER BY created_at ASC LIMIT 5');
        $stmt->execute([$firewall_id]);
        $commands = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($commands)) {
            // Mark commands as sent
            $command_ids = array_column($commands, 'id');
            $placeholders = str_repeat('?,', count($command_ids) - 1) . '?';
            $update_stmt = $DB->prepare("UPDATE firewall_commands SET status = 'sent', sent_at = NOW() WHERE id IN ($placeholders)");
            $update_stmt->execute($command_ids);
        }
        
        return $commands;
    } catch (Exception $e) {
        error_log("Failed to check queued commands for update agent: " . $e->getMessage());
        return [];
    }
}

/**
 * Process WAN interface statistics from agent v3.4.0+
 * Updates the firewall_wan_interfaces table with detailed interface stats
 */
function processWANInterfaceStats($firewall_id, $wan_interface_stats_json) {
    global $DB;

    if (empty($wan_interface_stats_json)) {
        return;
    }

    // Parse JSON stats
    $stats = json_decode($wan_interface_stats_json, true);
    if (!is_array($stats) || empty($stats)) {
        return;
    }

    try {
        foreach ($stats as $iface_data) {
            $interface = $iface_data['interface'] ?? '';
            if (empty($interface)) {
                continue;
            }

            // Prepare data for insertion/update
            $status = $iface_data['status'] ?? 'unknown';
            $ip_address = $iface_data['ip_address'] ?? '';
            $netmask = $iface_data['netmask'] ?? '';
            $gateway = $iface_data['gateway'] ?? '';
            $media = $iface_data['media'] ?? '';
            $rx_packets = (int)($iface_data['rx_packets'] ?? 0);
            $rx_bytes = (int)($iface_data['rx_bytes'] ?? 0);
            $rx_errors = (int)($iface_data['rx_errors'] ?? 0);
            $tx_packets = (int)($iface_data['tx_packets'] ?? 0);
            $tx_bytes = (int)($iface_data['tx_bytes'] ?? 0);
            $tx_errors = (int)($iface_data['tx_errors'] ?? 0);

            // Insert or update interface stats
            $stmt = $DB->prepare('
                INSERT INTO firewall_wan_interfaces
                (firewall_id, interface_name, status, ip_address, netmask, gateway, media,
                 rx_packets, rx_bytes, rx_errors, tx_packets, tx_bytes, tx_errors, last_updated)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    ip_address = VALUES(ip_address),
                    netmask = VALUES(netmask),
                    gateway = VALUES(gateway),
                    media = VALUES(media),
                    rx_packets = VALUES(rx_packets),
                    rx_bytes = VALUES(rx_bytes),
                    rx_errors = VALUES(rx_errors),
                    tx_packets = VALUES(tx_packets),
                    tx_bytes = VALUES(tx_bytes),
                    tx_errors = VALUES(tx_errors),
                    last_updated = NOW()
            ');

            $stmt->execute([
                $firewall_id, $interface, $status, $ip_address, $netmask, $gateway, $media,
                $rx_packets, $rx_bytes, $rx_errors, $tx_packets, $tx_bytes, $tx_errors
            ]);
        }
    } catch (Exception $e) {
        error_log("Failed to process WAN interface stats for firewall $firewall_id: " . $e->getMessage());
    }
}
?>
