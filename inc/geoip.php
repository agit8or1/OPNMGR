<?php
/**
 * GeoIP Lookup Helper
 * Uses free ip-api.com service for IP geolocation
 * Rate limit: 45 requests per minute
 */

/**
 * Lookup location for an IP address
 *
 * @param string $ip_address IP address to lookup
 * @return array|false Array with lat, lon, city, country or false on failure
 */
function geoip_lookup($ip_address) {
    // Skip private/local IPs
    if (empty($ip_address) ||
        filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }

    // Use ip-api.com free service
    $url = "http://ip-api.com/json/{$ip_address}?fields=status,message,country,city,lat,lon,isp";

    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'user_agent' => 'OPNManager/2.3'
        ]
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        error_log("GeoIP: Failed to fetch location for IP: {$ip_address}");
        return false;
    }

    $data = json_decode($response, true);

    if (!$data || $data['status'] !== 'success') {
        error_log("GeoIP: Lookup failed for IP {$ip_address}: " . ($data['message'] ?? 'Unknown error'));
        return false;
    }

    return [
        'latitude' => (float)$data['lat'],
        'longitude' => (float)$data['lon'],
        'city' => $data['city'] ?? '',
        'country' => $data['country'] ?? '',
        'isp' => $data['isp'] ?? ''
    ];
}

/**
 * Update firewall location from WAN IP
 *
 * @param PDO $db Database connection
 * @param int $firewall_id Firewall ID
 * @return bool Success
 */
function geoip_update_firewall_location($db, $firewall_id) {
    try {
        // Get firewall WAN IP
        $stmt = $db->prepare("SELECT wan_ip, hostname FROM firewalls WHERE id = ?");
        $stmt->execute([$firewall_id]);
        $firewall = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$firewall || empty($firewall['wan_ip'])) {
            return false;
        }

        $location = geoip_lookup($firewall['wan_ip']);

        if (!$location) {
            return false;
        }

        // Update firewall location
        $stmt = $db->prepare("
            UPDATE firewalls
            SET latitude = ?,
                longitude = ?,
                notes = CONCAT(COALESCE(notes, ''), '\nGeoIP: ', ?, ', ', ?, ' (', ?, ')')
            WHERE id = ?
        ");

        $stmt->execute([
            $location['latitude'],
            $location['longitude'],
            $location['city'],
            $location['country'],
            $location['isp'],
            $firewall_id
        ]);

        error_log("GeoIP: Updated location for firewall {$firewall_id} ({$firewall['hostname']}): {$location['city']}, {$location['country']} ({$location['latitude']}, {$location['longitude']})");

        return true;
    } catch (Exception $e) {
        error_log("GeoIP: Error updating firewall {$firewall_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Update all firewall locations from their WAN IPs
 *
 * @param PDO $db Database connection
 * @return array Stats about updates
 */
function geoip_update_all_firewalls($db) {
    $stats = ['success' => 0, 'failed' => 0, 'skipped' => 0];

    try {
        $stmt = $db->query("SELECT id, wan_ip FROM firewalls WHERE wan_ip IS NOT NULL AND wan_ip != ''");
        $firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($firewalls as $fw) {
            // Rate limiting: sleep 1.5 seconds between requests (max 40/min to stay under 45/min limit)
            if ($stats['success'] > 0) {
                usleep(1500000); // 1.5 seconds
            }

            if (geoip_update_firewall_location($db, $fw['id'])) {
                $stats['success']++;
            } else {
                $stats['failed']++;
            }
        }
    } catch (Exception $e) {
        error_log("GeoIP: Error in batch update: " . $e->getMessage());
    }

    return $stats;
}

/**
 * Get server's public IP and location
 *
 * @return array|false Location data or false
 */
function geoip_get_server_location() {
    // Get server's public IP
    $public_ip = @file_get_contents('https://api.ipify.org');

    if (!$public_ip) {
        error_log("GeoIP: Failed to get server public IP");
        return false;
    }

    $location = geoip_lookup($public_ip);

    if ($location) {
        $location['ip'] = $public_ip;
    }

    return $location;
}
?>
