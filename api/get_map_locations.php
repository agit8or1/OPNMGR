<?php
require_once __DIR__ . '/../inc/bootstrap.php';

requireLogin();

/**
 * Get Map Locations API
 * Returns server and firewall locations for map display
 * Uses GeoIP lookups for automatic location detection
 */
require_once __DIR__ . '/../inc/geoip.php';

header('Content-Type: application/json');

try {
    // Get server location using GeoIP
    $server_geoip = geoip_get_server_location();

    $server_location = [
        'type' => 'server',
        'name' => 'Management Server',
        'hostname' => 'opn.agit8or.net',
        'latitude' => $server_geoip ? $server_geoip['latitude'] : 39.0997,
        'longitude' => $server_geoip ? $server_geoip['longitude'] : -94.5786,
        'city' => $server_geoip['city'] ?? '',
        'country' => $server_geoip['country'] ?? '',
        'ip' => $server_geoip['ip'] ?? '',
        'status' => 'online'
    ];

    // Get all firewalls with WAN IPs OR stored location (to show offline firewalls)
    $stmt = db()->query("
        SELECT
            id,
            hostname,
            customer_name,
            status,
            latitude,
            longitude,
            wan_ip,
            last_checkin
        FROM firewalls
        WHERE (wan_ip IS NOT NULL AND wan_ip != '')
           OR (latitude IS NOT NULL AND latitude != 0)
           OR (longitude IS NOT NULL AND longitude != 0)
        ORDER BY hostname ASC
    ");

    $firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format firewall data with GeoIP fallback
    $firewall_locations = [];
    foreach ($firewalls as $fw) {
        $lat = (float)$fw['latitude'];
        $lon = (float)$fw['longitude'];

        $city = '';
        $country = '';

        // If no stored location and has WAN IP, use GeoIP lookup
        if (($lat == 0 && $lon == 0) && !empty($fw['wan_ip'])) {
            $geoip = geoip_lookup($fw['wan_ip']);
            if ($geoip) {
                $lat = $geoip['latitude'];
                $lon = $geoip['longitude'];
                $city = $geoip['city'];
                $country = $geoip['country'];

                // Store in database for future use
                $update = db()->prepare("UPDATE firewalls SET latitude = ?, longitude = ? WHERE id = ?");
                $update->execute([$lat, $lon, $fw['id']]);
            }
        } else if ($lat != 0 || $lon != 0) {
            // We have stored location, try to get city/country from GeoIP for display (only if online)
            if (!empty($fw['wan_ip'])) {
                $geoip = geoip_lookup($fw['wan_ip']);
                if ($geoip) {
                    $city = $geoip['city'];
                    $country = $geoip['country'];
                }
            }
        }

        // Only include if we have valid coordinates
        if ($lat != 0 || $lon != 0) {
            $firewall_locations[] = [
                'type' => 'firewall',
                'id' => (int)$fw['id'],
                'name' => $fw['customer_name'] ?: $fw['hostname'],
                'hostname' => $fw['hostname'],
                'latitude' => $lat,
                'longitude' => $lon,
                'city' => $city,
                'country' => $country,
                'status' => $fw['status'],
                'wan_ip' => $fw['wan_ip'],
                'last_checkin' => $fw['last_checkin']
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'server' => $server_location,
        'firewalls' => $firewall_locations,
        'total_locations' => count($firewall_locations) + 1
    ]);

} catch (Exception $e) {
    error_log("get_map_locations.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load map locations'
    ]);
}
?>
