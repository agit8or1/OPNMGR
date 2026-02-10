<?php
/**
 * Updates for agent_checkin.php to support Agent v3.4.0
 *
 * Add these code snippets to your existing agent_checkin.php file
 */

// ============================================================================
// SECTION 1: Add after line 28 (after $ipv6_address extraction)
// ============================================================================

// New WAN interface fields (v3.4.0)
$wan_interfaces = trim($input['wan_interfaces'] ?? '');
$wan_groups = trim($input['wan_groups'] ?? '');
$wan_interface_stats = $input['wan_interface_stats'] ?? null;

// ============================================================================
// SECTION 2: Update the main UPDATE query (around line 100-117)
// Replace the existing UPDATE statement with this enhanced version
// ============================================================================

if ($agent_sent_reboot_status) {
    // Agent supports reboot detection - update the flag
    if (!empty($wan_netmask) || !empty($wan_gateway)) {
        $stmt = db()->prepare('UPDATE firewalls SET
            last_checkin = NOW(),
            agent_version = ?,
            status = ?,
            wan_ip = ?,
            lan_ip = ?,
            ipv6_address = ?,
            version = ?,
            uptime = ?,
            reboot_required = ?,
            wan_netmask = ?,
            wan_gateway = ?,
            wan_dns_primary = ?,
            wan_dns_secondary = ?,
            lan_netmask = ?,
            lan_network = ?,
            wan_interfaces = ?,  -- NEW
            wan_groups = ?,  -- NEW
            wan_interface_stats = ?,  -- NEW
            network_config_updated = NOW(),
            tunnel_active = 1
        WHERE id = ?');

        $result = $stmt->execute([
            $agent_version,
            'online',
            $wan_ip,
            $lan_ip,
            $ipv6_address,
            $opnsense_version,
            $uptime,
            $reboot_required,
            $wan_netmask,
            $wan_gateway,
            $wan_dns_primary,
            $wan_dns_secondary,
            $lan_netmask,
            $lan_network,
            $wan_interfaces,  // NEW
            $wan_groups,  // NEW
            json_encode($wan_interface_stats),  // NEW
            $firewall_id
        ]);
    } else {
        // Preserve existing network config but update WAN interface info
        $stmt = db()->prepare('UPDATE firewalls SET
            last_checkin = NOW(),
            agent_version = ?,
            status = ?,
            wan_ip = ?,
            lan_ip = ?,
            ipv6_address = ?,
            version = ?,
            uptime = ?,
            reboot_required = ?,
            wan_interfaces = ?,  -- NEW
            wan_groups = ?,  -- NEW
            wan_interface_stats = ?,  -- NEW
            tunnel_active = 1
        WHERE id = ?');

        $result = $stmt->execute([
            $agent_version,
            'online',
            $wan_ip,
            $lan_ip,
            $ipv6_address,
            $opnsense_version,
            $uptime,
            $reboot_required,
            $wan_interfaces,  // NEW
            $wan_groups,  // NEW
            json_encode($wan_interface_stats),  // NEW
            $firewall_id
        ]);
    }
}

// ============================================================================
// SECTION 3: Add new function to process WAN interface stats (add at end)
// ============================================================================

/**
 * Process and store detailed WAN interface statistics
 */
function processWANInterfaceStats($firewall_id, $wan_interface_stats) {
    if (empty($wan_interface_stats) || !is_array($wan_interface_stats)) {
        return;
    }

    try {
        // Process each interface
        foreach ($wan_interface_stats as $stat) {
            if (empty($stat['interface'])) {
                continue;
            }

            $interface_name = $stat['interface'];
            $status = $stat['status'] ?? 'unknown';
            $ip_address = $stat['ip'] ?? null;
            $media = $stat['media'] ?? null;
            $rx_packets = (int)($stat['rx_packets'] ?? 0);
            $rx_errors = (int)($stat['rx_errors'] ?? 0);
            $rx_bytes = (int)($stat['rx_bytes'] ?? 0);
            $tx_packets = (int)($stat['tx_packets'] ?? 0);
            $tx_errors = (int)($stat['tx_errors'] ?? 0);
            $tx_bytes = (int)($stat['tx_bytes'] ?? 0);

            // Insert or update interface stats
            $stmt = db()->prepare('
                INSERT INTO firewall_wan_interfaces
                (firewall_id, interface_name, status, ip_address, media,
                 rx_packets, rx_errors, rx_bytes, tx_packets, tx_errors, tx_bytes, last_updated)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    ip_address = VALUES(ip_address),
                    media = VALUES(media),
                    rx_packets = VALUES(rx_packets),
                    rx_errors = VALUES(rx_errors),
                    rx_bytes = VALUES(rx_bytes),
                    tx_packets = VALUES(tx_packets),
                    tx_errors = VALUES(tx_errors),
                    tx_bytes = VALUES(tx_bytes),
                    last_updated = NOW()
            ');

            $stmt->execute([
                $firewall_id,
                $interface_name,
                $status,
                $ip_address,
                $media,
                $rx_packets,
                $rx_errors,
                $rx_bytes,
                $tx_packets,
                $tx_errors,
                $tx_bytes
            ]);
        }

        // Log the update
        error_log("Updated WAN interface stats for firewall $firewall_id: " . count($wan_interface_stats) . " interface(s)");

    } catch (Exception $e) {
        error_log("Failed to process WAN interface stats for firewall $firewall_id: " . $e->getMessage());
    }
}

// ============================================================================
// SECTION 4: Call the processing function after successful checkin (around line 135)
// ============================================================================

// After the firewall_agents table insert/update, add this:
if (!empty($wan_interface_stats) && is_array($wan_interface_stats)) {
    processWANInterfaceStats($firewall_id, $wan_interface_stats);
}

?>
