<?php
require_once __DIR__ . '/inc/bootstrap_agent.php';
require_once __DIR__ . '/inc/logging.php';
require_once __DIR__ . '/inc/agent_auth.php';
require_once __DIR__ . '/inc/command_results.php';
require_once __DIR__ . '/inc/agent_commands.php';
require_once __DIR__ . '/inc/reboot_state.php';
require_once __DIR__ . '/inc/firewall_health.php';

// Endpoint for firewall agent check-ins
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input. agent_request_input() caches the raw body so that HMAC
// signature verification hashes exactly the bytes we received.
$input = agent_request_input();
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit;
}

// Authenticate once, up front, for every branch of this endpoint.
// Identity resolution (firewall_id or hardware_id lookup), hardware_id pinning,
// API key verification and HMAC signature checking all live in inc/agent_auth.php.
$authenticated_firewall = authenticateAgentRequest($input);
$firewall_id = (int)$authenticated_firewall['id'];
$hardware_id = (string)$authenticated_firewall['hardware_id'];

// Credentials to hand back if this agent has not yet adopted its API key.
$agent_credentials = agent_credentials_payload($authenticated_firewall);

// Check if this is a command result report (agent reporting back command execution status)
$command_id = (int)($input['command_id'] ?? 0);
$command_status = trim($input['status'] ?? '');
$command_result = trim($input['result'] ?? '');

if ($command_id > 0 && !empty($command_status)) {
    // This is a command result report, not a regular check-in.
    try {
        // Ownership, terminal-state and status validation live in one place so
        // that agent_checkin.php and api/command_result.php cannot drift apart.
        $accepted = record_agent_command_result(
            $firewall_id,
            $command_id,
            $command_status,
            $command_result,
            ['result_is_base64' => true]
        );

        if (!$accepted['ok']) {
            http_response_code($accepted['status']);
            echo json_encode(['success' => false, 'message' => $accepted['message']]);
            exit;
        }

        $command_result = $accepted['result'];
        $command_status = $accepted['normalized_status'];

        // Check if this was a speedtest command - parse results into bandwidth_tests
        if ($command_status === 'completed' && !empty($command_result)) {
            // Reuse the row record_agent_command_result() already verified as
            // belonging to this firewall rather than re-querying by id alone.
            $cmd_info = $accepted['command'];

            if ($cmd_info && ($cmd_info['command'] === 'run_speedtest' || $cmd_info['command_type'] === 'speedtest')) {
                $speedtest_data = json_decode($command_result, true);
                if ($speedtest_data && isset($speedtest_data['download_mbps']) && !isset($speedtest_data['error'])) {
                    $bw_stmt = db()->prepare("INSERT INTO bandwidth_tests (firewall_id, test_type, test_status, download_speed, upload_speed, latency, test_server, tested_at) VALUES (?, 'manual', 'completed', ?, ?, ?, ?, NOW())");
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

        // Interpret the real outcome of an OS update.
        //
        // The agent hardcodes "status":"completed" for every command it runs, so
        // the reported status says only that the agent finished executing - not
        // that the upgrade succeeded. install_updates echoes its own exit code
        // for exactly this reason; read it rather than assuming success.
        $cmd_row = $accepted['command'] ?? null;
        if ($cmd_row && ($cmd_row['action'] ?? '') === 'install_updates') {
            $outcome = interpret_update_result((string)$command_result);

            if (!$outcome['known']) {
                $verdict = 'unconfirmed';
            } else {
                $verdict = $outcome['ok'] ? 'success' : 'failed';
            }

            db()->prepare(
                'UPDATE firewalls SET last_update_result = ?, last_update_attempt_at = NOW() WHERE id = ?'
            )->execute([$verdict, $firewall_id]);

            // Reflect a genuine failure in the command row too, so the history
            // does not show a green "completed" for an upgrade that failed.
            if ($outcome['known'] && !$outcome['ok']) {
                db()->prepare("UPDATE firewall_commands SET status = 'failed' WHERE id = ?")
                    ->execute([$command_id]);
            }

            // Force a fresh update check instead of asserting what is now
            // installed. The agent reports the real version on its next
            // check-in; guessing here is what previously left the server
            // claiming "no updates available" for an upgrade that never ran.
            db()->prepare('UPDATE firewalls SET last_update_check = NULL WHERE id = ?')
                ->execute([$firewall_id]);

            // A successful install means the new base/kernel is on disk but not
            // yet running, so re-derive the reboot state immediately instead of
            // waiting for the next check-in.
            try {
                recompute_reboot_required($firewall_id);
            } catch (Throwable $e) {
                error_log("Reboot state recompute failed after update for firewall $firewall_id: " . $e->getMessage());
            }

            error_log("Update result for firewall $firewall_id (command $command_id): $verdict"
                . ($outcome['known'] ? " (exit {$outcome['exit_code']})" : ' (no exit marker)'));
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
$wan_ip_raw = trim($input['wan_ip'] ?? '');
$lan_ip_raw = trim($input['lan_ip'] ?? '');
$ipv6_raw = trim($input['ipv6_address'] ?? '');

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
if (empty($agent_version)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Identity, hardware_id pinning, API key and HMAC signature were all verified by
// authenticateAgentRequest() at the top of this file. $firewall is the row it
// returned, so no endpoint-local credential comparison happens here any more.
$firewall = $authenticated_firewall;

// Rate limiting removed - Agent v3.0 has built-in PID locking to prevent duplicates

try {
    // Check if agent reports reboot is required
    // Only update reboot_required if agent explicitly sends it (for newer agents)
    // Otherwise, preserve existing value in database
    // FIXED: Use $input (JSON body) instead of $_POST (which is empty for JSON requests)
    $agent_sent_reboot_status = isset($input['reboot_required']);
    $reboot_required = $agent_sent_reboot_status ? (int)$input['reboot_required'] : null;

    // When reboot_required transitions from 1→0 (firewall rebooted), force update check
    if ($agent_sent_reboot_status && $reboot_required === 0) {
        $prev_reboot = db()->prepare('SELECT reboot_required FROM firewalls WHERE id = ?');
        $prev_reboot->execute([$firewall_id]);
        $prev_reboot_val = (int)$prev_reboot->fetchColumn();
        if ($prev_reboot_val === 1) {
            // Firewall just rebooted - force fresh update check
            $force_check = db()->prepare('UPDATE firewalls SET last_update_check = NULL WHERE id = ?');
            $force_check->execute([$firewall_id]);
            error_log("Firewall $firewall_id rebooted (reboot_required 1→0), forcing update check");
        }
    }
    
    // Update the main firewalls table with all the collected information
    // If agent sends empty values, preserve existing data in DB
    // This prevents good data from being overwritten when agent restarts or has collection issues
    $preserve_stmt = db()->prepare('SELECT wan_ip, lan_ip, ipv6_address, version, uptime FROM firewalls WHERE id = ?');
    $preserve_stmt->execute([$firewall_id]);
    $existing = $preserve_stmt->fetch(PDO::FETCH_ASSOC);

    $wan_ip = !empty($wan_ip_raw) ? $wan_ip_raw : ($existing['wan_ip'] ?? '');
    $lan_ip = !empty($lan_ip_raw) ? $lan_ip_raw : ($existing['lan_ip'] ?? '');
    $ipv6_address = !empty($ipv6_raw) ? $ipv6_raw : ($existing['ipv6_address'] ?? '');

    if (empty($opnsense_version)) {
        $opnsense_version = $existing['version'] ?? '';
    }

    if (empty($uptime) || $uptime === 'Unknown') {
        $uptime = $existing['uptime'] ?? 'Unknown';
    }
    $uptime = $uptime ?: 'Unknown';
    
    // Preserve 'updating'/'update_pending' status during check-in - let the update
    // detection logic (below) handle the status transition properly
    // Read the current status before writing it back. $firewall_status is not
    // populated until the update-check block much further down, so this line
    // was reading an undefined variable and always falling through to 'online',
    // clobbering an in-progress 'updating'/'update_pending' state on every
    // check-in.
    $status_stmt = db()->prepare('SELECT status FROM firewalls WHERE id = ?');
    $status_stmt->execute([$firewall_id]);
    $current_status = (string)($status_stmt->fetchColumn() ?: '');

    $checkin_status = in_array($current_status, ['updating', 'update_pending'], true)
        ? $current_status
        : 'online';

    if ($agent_sent_reboot_status) {
        // Agent supports reboot detection - update the flag
        // Only update network config if provided by agent, otherwise preserve existing values
        if (!empty($wan_netmask) || !empty($wan_gateway)) {
            $stmt = db()->prepare('UPDATE firewalls SET last_checkin = NOW(), agent_version = ?, status = ?, wan_ip = ?, lan_ip = ?, ipv6_address = ?, version = ?, uptime = ?, reboot_required = ?, wan_netmask = ?, wan_gateway = ?, wan_dns_primary = ?, wan_dns_secondary = ?, lan_netmask = ?, lan_network = ?, wan_interfaces = ?, wan_groups = ?, wan_interface_stats = ?, network_config_updated = NOW(), tunnel_active = 1 WHERE id = ?');
            $result = $stmt->execute([$agent_version, $checkin_status, $wan_ip, $lan_ip, $ipv6_address, $opnsense_version, $uptime, $reboot_required, $wan_netmask, $wan_gateway, $wan_dns_primary, $wan_dns_secondary, $lan_netmask, $lan_network, $wan_interfaces, $wan_groups, $wan_interface_stats, $firewall_id]);
        } else {
            // Preserve existing network config
            $stmt = db()->prepare('UPDATE firewalls SET last_checkin = NOW(), agent_version = ?, status = ?, wan_ip = ?, lan_ip = ?, ipv6_address = ?, version = ?, uptime = ?, reboot_required = ?, wan_interfaces = ?, wan_groups = ?, wan_interface_stats = ?, tunnel_active = 1 WHERE id = ?');
            $result = $stmt->execute([$agent_version, $checkin_status, $wan_ip, $lan_ip, $ipv6_address, $opnsense_version, $uptime, $reboot_required, $wan_interfaces, $wan_groups, $wan_interface_stats, $firewall_id]);
        }
    } else {
        // Agent doesn't support reboot detection - preserve existing reboot_required value
        // Only update network config if provided by agent, otherwise preserve existing values
        if (!empty($wan_netmask) || !empty($wan_gateway)) {
            $stmt = db()->prepare('UPDATE firewalls SET last_checkin = NOW(), agent_version = ?, status = ?, wan_ip = ?, lan_ip = ?, ipv6_address = ?, version = ?, uptime = ?, wan_netmask = ?, wan_gateway = ?, wan_dns_primary = ?, wan_dns_secondary = ?, lan_netmask = ?, lan_network = ?, wan_interfaces = ?, wan_groups = ?, wan_interface_stats = ?, network_config_updated = NOW(), tunnel_active = 1 WHERE id = ?');
            $result = $stmt->execute([$agent_version, $checkin_status, $wan_ip, $lan_ip, $ipv6_address, $opnsense_version, $uptime, $wan_netmask, $wan_gateway, $wan_dns_primary, $wan_dns_secondary, $lan_netmask, $lan_network, $wan_interfaces, $wan_groups, $wan_interface_stats, $firewall_id]);
        } else {
            // Preserve existing network config
            $stmt = db()->prepare('UPDATE firewalls SET last_checkin = NOW(), agent_version = ?, status = ?, wan_ip = ?, lan_ip = ?, ipv6_address = ?, version = ?, uptime = ?, wan_interfaces = ?, wan_groups = ?, wan_interface_stats = ?, tunnel_active = 1 WHERE id = ?');
            $result = $stmt->execute([$agent_version, $checkin_status, $wan_ip, $lan_ip, $ipv6_address, $opnsense_version, $uptime, $wan_interfaces, $wan_groups, $wan_interface_stats, $firewall_id]);
        }
    }
    
    if (!$result) {
        error_log("Failed to update firewalls table for firewall $firewall_id: " . print_r($stmt->errorInfo(), true));
    }

    // Derive reboot_required from the uptime just reported rather than leaving
    // it frozen. The agent has never sent a reboot flag, so this column was
    // previously writable only by code that guessed - which left one firewall
    // asserting "reboot required" continuously from March through many actual
    // reboots, and another reporting no reboot needed while running a kernel it
    // had not booted into. Never allowed to break a check-in.
    try {
        $reboot_verdict = recompute_reboot_required($firewall_id);
        if ($reboot_verdict['changed']) {
            error_log("Firewall $firewall_id reboot_required -> {$reboot_verdict['state']} ({$reboot_verdict['reason']})");
        }
    } catch (Throwable $e) {
        error_log("Reboot state recompute failed for firewall $firewall_id: " . $e->getMessage());
    }
    
    // Also update or insert agent record for historical tracking
    // Support both 'primary' and 'update' agent types
    $stmt = db()->prepare('INSERT INTO firewall_agents (firewall_id, agent_version, agent_type, last_checkin, status, wan_ip, lan_ip, ipv6_address, opnsense_version) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE agent_version = VALUES(agent_version), last_checkin = NOW(), status = VALUES(status), wan_ip = VALUES(wan_ip), lan_ip = VALUES(lan_ip), ipv6_address = VALUES(ipv6_address), opnsense_version = VALUES(opnsense_version)');
    $result2 = $stmt->execute([$firewall_id, $agent_version, $agent_type, 'online', $wan_ip, $lan_ip, $ipv6_address, $opnsense_version]);
    
    if (!$result2) {
        error_log("Failed to update firewall_agents table for firewall $firewall_id: " . print_r($stmt->errorInfo(), true));
    } else {
        // Log successful checkin
        log_info('agent', "Agent checkin: firewall_id=$firewall_id, type=$agent_type, version=$agent_version, wan_ip=$wan_ip", null, $firewall_id);
    }

    // OPNsense health telemetry (Agent v1.6.0+): gateways, VPN tunnels, CARP,
    // services and certificates. Absent for older agents, which simply do not
    // populate those views rather than being treated as "everything is down".
    if (isset($input['health']) && is_array($input['health'])) {
        try {
            $health_stored = health_ingest($firewall_id, $input['health']);
            if ($health_stored) {
                error_log('Health ingested for firewall ' . $firewall_id . ': '
                          . json_encode($health_stored));
            }
        } catch (Throwable $e) {
            // Health is supplementary; never fail a check-in over it.
            error_log('Health ingest failed for firewall ' . $firewall_id . ': ' . $e->getMessage());
        }
    }

    // Process WAN interface statistics if provided (Agent v3.4.0+)
    if (!empty($wan_interface_stats)) {
        processWANInterfaceStats($firewall_id, $wan_interface_stats);
    }

    // Store traffic statistics for charts
    if (isset($input['traffic_stats'])) {
        $traffic = $input['traffic_stats'];
        if (isset($traffic['bytes_in']) && $traffic['bytes_in'] > 0) {
            $stmt = db()->prepare("
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
            $stmt = db()->prepare("INSERT INTO firewall_system_stats
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
            $stmt = db()->prepare("INSERT INTO firewall_latency (firewall_id, latency_ms, measured_at) VALUES (?, ?, NOW())");
            $stmt->execute([$firewall_id, $avg_latency]);
        }
    }

    // Check if we need to perform update check (every 5 hours)
    $stmt = db()->prepare('SELECT last_update_check, status, current_version FROM firewalls WHERE id = ?');
    $stmt->execute([$firewall_id]);
    $firewall_status = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Use reported version, or preserve existing current_version from DB
    $current_version = $opnsense_version;
    if (empty($current_version) || $current_version === 'Unknown') {
        $current_version = $firewall_status['current_version'] ?? '';
    }
    if (empty($current_version)) {
        $current_version = 'Unknown';
    }
    
    $check_updates = false;
    $is_updating = in_array($firewall_status['status'], ['updating', 'update_pending']);
    if ($is_updating || !$firewall_status['last_update_check'] || strtotime($firewall_status['last_update_check']) < (time() - 18000)) { // 5 hours = 18000 seconds
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

        // Sanity check: if current and available versions match, no updates needed
        if ($updates_available == 1 && !empty($current_version) && $current_version !== 'Unknown'
            && !empty($latest_stable_version) && $current_version === $latest_stable_version) {
            $updates_available = 0;
            error_log("Firewall $firewall_id: Cleared stale updates_available (current=$current_version matches available=$latest_stable_version)");
        }

        // Update the database with check results from agent
        $stmt = db()->prepare('UPDATE firewalls SET last_update_check = NOW(), current_version = ?, available_version = ?, updates_available = ? WHERE id = ?');
        $stmt->execute([$current_version, $latest_stable_version, $updates_available, $firewall_id]);
        
        // If firewall was marked as 'updating' but reported back with current version,
        // check if update actually completed
        if (in_array($firewall_status['status'], ['updating', 'update_pending'])) {
            // Check for stuck update timeout (15 minutes)
            $update_requested_at_stmt = db()->prepare('SELECT update_requested_at FROM firewalls WHERE id = ?');
            $update_requested_at_stmt->execute([$firewall_id]);
            $update_requested_at = $update_requested_at_stmt->fetchColumn();
            $update_age_minutes = $update_requested_at ? (time() - strtotime($update_requested_at)) / 60 : 999;

            if ($updates_available == 0) {
                // Update completed (or was already up to date) - return to online
                // reboot_required is deliberately not touched here. This branch fires when
                // a firewall reappears after an update, which says nothing about whether
                // it has actually booted into the new base/kernel - clearing the flag
                // here reported "no reboot needed" for a box still running the old one.
                // recompute_reboot_required() decides it from uptime instead.
                $stmt = db()->prepare('UPDATE firewalls SET status = ? WHERE id = ?');
                $stmt->execute(['online', $firewall_id]);

                log_info('firewall', "Update completed for firewall - now running version $current_version",
                    null, $firewall_id, [
                        'action' => 'update_completed',
                        'new_version' => $current_version
                    ]);
            } elseif (version_compare($current_version, $firewall_status['current_version'] ?: '', '>')) {
                // Version increased but still not latest - partial update
                $stmt = db()->prepare('UPDATE firewalls SET status = ? WHERE id = ?');
                $stmt->execute(['online', $firewall_id]);

                log_info('firewall', "Partial update completed for firewall - version upgraded to $current_version",
                    null, $firewall_id, [
                        'action' => 'partial_update_completed',
                        'new_version' => $current_version
                    ]);
            } elseif ($update_age_minutes > 5) {
                // Agent is checking in while status is 'updating' and 5+ minutes have passed.
                // The update either completed (after reboot) or failed - recover to online.
                // opnsense-update -bkf may leave minor patches still available, so
                // updates_available can still be 1 even after a successful base update.
                // reboot_required is deliberately not touched here. This branch fires when
                // a firewall reappears after an update, which says nothing about whether
                // it has actually booted into the new base/kernel - clearing the flag
                // here reported "no reboot needed" for a box still running the old one.
                // recompute_reboot_required() decides it from uptime instead.
                $stmt = db()->prepare('UPDATE firewalls SET status = ? WHERE id = ?');
                $stmt->execute(['online', $firewall_id]);

                log_info('firewall', "Update completed for firewall after {$update_age_minutes}min - version $current_version (minor patches may still be available)",
                    null, $firewall_id, [
                        'action' => 'update_timeout_recovery',
                        'version' => $current_version,
                        'updates_still_available' => $updates_available
                    ]);
            }
        }
    } else {
        // Even if not doing full update check, update current_version
        $stmt = db()->prepare('UPDATE firewalls SET current_version = ? WHERE id = ?');
        $stmt->execute([$current_version, $firewall_id]);
    }

    // Get check-in interval based on agent type
    // Primary agent: 120 seconds (2 minutes)
    // Update agent: 300 seconds (5 minutes)  
    if ($agent_type === 'update') {
        $checkin_interval = 300; // 5 minutes for update agent
    } else {
        // Check for firewall-specific setting, default to 120 seconds (2 min) for primary agent
        $stmt = db()->prepare('SELECT checkin_interval FROM firewalls WHERE id = ?');
        $stmt->execute([$firewall_id]);
        $firewall_data = $stmt->fetch();
        $checkin_interval = (int)($firewall_data['checkin_interval'] ?? 120);
    }

    // Check for agent updates
    $agent_update_check = checkAgentUpdate($agent_version, $firewall_id);

    // Check for OPNsense update requests
    $stmt = db()->prepare('SELECT update_requested, update_requested_at FROM firewalls WHERE id = ?');
    $stmt->execute([$firewall_id]);
    $update_info = $stmt->fetch();
    
    $opnsense_update_requested = false;
    if ($update_info && $update_info['update_requested']) {
        $opnsense_update_requested = true;
        
        // Clear the request flag so it is delivered once, and mark the firewall
        // as updating.
        //
        // Deliberately no longer sets updates_available = 0 or
        // reboot_required = 1 here. Those asserted an outcome at the moment the
        // request was handed to the agent, before anything had run - so a
        // request that never executed still left the server reporting "no
        // updates available, reboot required", which is worse than saying
        // nothing. Both are now set from what the agent actually reports.
        $stmt = db()->prepare(
            'UPDATE firewalls
                SET update_requested = 0, status = \'updating\',
                    last_update_check = NULL, last_update_attempt_at = NOW()
              WHERE id = ?'
        );
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
        $response['opnsense_update_command'] = '/usr/local/sbin/opnsense-update -bkp';
    }
    
    // Check for agent cleanup request
    $stmt = db()->prepare('SELECT agent_cleanup_requested FROM firewalls WHERE id = ?');
    $stmt->execute([$firewall_id]);
    $cleanup_info = $stmt->fetch();
    
    $agent_cleanup_requested = false;
    if ($cleanup_info && $cleanup_info['agent_cleanup_requested']) {
        $agent_cleanup_requested = true;
        
        // Clear the cleanup request flag
        $stmt = db()->prepare('UPDATE firewalls SET agent_cleanup_requested = 0 WHERE id = ?');
        $stmt->execute([$firewall_id]);
        
        // Provide the cleanup script URL
        $response['agent_cleanup_requested'] = true;
        $response['agent_cleanup_url'] = 'https://opn.agit8or.net/downloads/cleanup_and_fix_agent.sh';
        $response['agent_cleanup_command'] = 'fetch -o /tmp/cleanup_fix.sh https://opn.agit8or.net/downloads/cleanup_and_fix_agent.sh && chmod +x /tmp/cleanup_fix.sh && /tmp/cleanup_fix.sh';
    }
    
    // Auto-setup reverse SSH tunnel if not already established
    $tunnel_check = db()->prepare('SELECT tunnel_active, tunnel_established FROM firewalls WHERE id = ?');
    $tunnel_check->execute([$firewall_id]);
    $tunnel_status = $tunnel_check->fetch(PDO::FETCH_ASSOC);
    
    // If tunnel has never been established, queue the setup command
    if ($tunnel_status && !$tunnel_status['tunnel_established']) {
        // Check if setup command already queued
        $existing_cmd = db()->prepare('SELECT id FROM firewall_commands WHERE firewall_id = ? AND description = "Auto-setup reverse SSH tunnel" AND status IN ("pending", "sent") LIMIT 1');
        $existing_cmd->execute([$firewall_id]);
        
        if (!$existing_cmd->fetch()) {
            // Queue the tunnel setup command
            $tunnel_cmd = "fetch -o /tmp/setup_tunnel.sh https://opn.agit8or.net/setup_reverse_proxy.sh || curl -k -o /tmp/setup_tunnel.sh https://opn.agit8or.net/setup_reverse_proxy.sh && chmod +x /tmp/setup_tunnel.sh && /tmp/setup_tunnel.sh {$firewall_id} > /tmp/tunnel_setup.log 2>&1 && echo '=== SSH PUBLIC KEY ===' && cat /home/tunnel/.ssh/id_rsa.pub";
            
            $ins_cmd = db()->prepare('INSERT INTO firewall_commands (firewall_id, command, description) VALUES (?, ?, ?)');
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
    $stmt = db()->prepare('SELECT id, tunnel_port, client_id, method, path, headers, body FROM request_queue WHERE firewall_id = ? AND status = "pending" ORDER BY created_at ASC LIMIT 10');
    $stmt->execute([$firewall_id]);
    $pending_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($pending_requests)) {
        $response['pending_requests'] = $pending_requests;
        $response['pending_requests_count'] = count($pending_requests);
        
        // Log that we're sending requests to agent
        error_log("Agent checkin for firewall $firewall_id: Sending " . count($pending_requests) . " pending proxy requests");
    }

    // Hand the agent its API key and signing secret if it has not adopted them
    // yet. This is what lets an already-deployed fleet upgrade from
    // hardware_id-only authentication without a flag day. Once the agent sends
    // the key back, authentication for this firewall fails closed without it.
    if (!empty($agent_credentials)) {
        $response = array_merge($response, $agent_credentials);
    }

    // Tell the agent what the server expects, so it can self-configure.
    $response['auth_policy'] = [
        'mode'                => agent_auth_mode(),
        'signature_window'    => (int)agent_auth_setting('agent_signature_window', '300'),
        'api_key_required'    => (bool)($firewall['_auth']['confirmed'] ?? false),
        'signature_required'  => agent_auth_mode() === 'require_signed'
            || (agent_auth_mode() === 'prefer_signed' && (int)($firewall['agent_signing_supported'] ?? 0) === 1),
    ];

    echo json_encode($response);

} catch (Exception $e) {
    error_log("agent_checkin.php error for firewall_id=$firewall_id: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}

/**
 * Check if agent update is available
 */
function checkAgentUpdate($current_agent_version, $firewall_id) {
    // Use centralized version constant from inc/agent_version.php
    require_once __DIR__ . '/inc/agent_version.php';
    $latest_agent_version = LATEST_AGENT_VERSION;
    
    // Clean version strings for comparison
    $current_clean = preg_replace('/[^0-9.]/', '', $current_agent_version);
    $latest_clean = preg_replace('/[^0-9.]/', '', $latest_agent_version);
    
    // Get firewall hostname for self-healing
    $stmt = db()->prepare('SELECT hostname FROM firewalls WHERE id = ?');
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
                'manual_reinstall_command' => buildAgentReinstallCommand($server_name, $firewall_id)
            ];
        } else {
            // Normal update for other versions (v2.x standalone agents)
            return [
                'update_available' => true,
                'latest_version' => $latest_agent_version,
                'download_url' => "https://{$server_name}/download_tunnel_agent.php?firewall_id={$firewall_id}",
                'update_command' => 'fetch -o /tmp/update_agent.sh ' . "https://{$server_name}/download/update_agent.sh" . ' && chmod +x /tmp/update_agent.sh && nohup /tmp/update_agent.sh ' . "https://{$server_name}/download_tunnel_agent.php?firewall_id={$firewall_id}" . ' > /dev/null 2>&1 &',
                'manual_reinstall_command' => buildAgentReinstallCommand($server_name, $firewall_id)
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
    try {
        // Reset commands stuck in 'sent' status for more than 10 minutes back to 'pending'
        $timeout_stmt = db()->prepare("UPDATE firewall_commands SET status = 'pending', sent_at = NULL WHERE firewall_id = ? AND status = 'sent' AND sent_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $timeout_stmt->execute([$firewall_id]);
        $reset_count = $timeout_stmt->rowCount();
        if ($reset_count > 0) {
            error_log("Reset $reset_count stuck command(s) for firewall $firewall_id back to pending");
        }
        
        // Get pending commands
        $stmt = db()->prepare('SELECT id, command, description FROM firewall_commands WHERE firewall_id = ? AND status = "pending" ORDER BY created_at ASC LIMIT 5');
        $stmt->execute([$firewall_id]);
        $commands = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($commands)) {
            // Mark commands as sent
            $command_ids = array_column($commands, 'id');
            $placeholders = str_repeat('?,', count($command_ids) - 1) . '?';
            $update_stmt = db()->prepare("UPDATE firewall_commands SET status = 'sent', sent_at = NOW() WHERE id IN ($placeholders)");
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
    try {
        // Reset commands stuck in 'sent' status for more than 10 minutes back to 'pending'
        $timeout_stmt = db()->prepare("UPDATE firewall_commands SET status = 'pending', sent_at = NULL WHERE firewall_id = ? AND status = 'sent' AND is_update_command = 1 AND sent_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $timeout_stmt->execute([$firewall_id]);
        $reset_count = $timeout_stmt->rowCount();
        if ($reset_count > 0) {
            error_log("Reset $reset_count stuck update agent command(s) for firewall $firewall_id back to pending");
        }
        
        // Get pending commands marked for update agent
        $stmt = db()->prepare('SELECT id, command, description FROM firewall_commands WHERE firewall_id = ? AND status = "pending" AND is_update_command = 1 ORDER BY created_at ASC LIMIT 5');
        $stmt->execute([$firewall_id]);
        $commands = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($commands)) {
            // Mark commands as sent
            $command_ids = array_column($commands, 'id');
            $placeholders = str_repeat('?,', count($command_ids) - 1) . '?';
            $update_stmt = db()->prepare("UPDATE firewall_commands SET status = 'sent', sent_at = NOW() WHERE id IN ($placeholders)");
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
            $stmt = db()->prepare('
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
