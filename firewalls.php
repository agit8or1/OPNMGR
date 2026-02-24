<?php

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . "/inc/timezone_selector.php";
requireLogin();

// Health calculation function - weights sum to exactly 100
function calculateHealthScore($firewall) {
    $health_score = 0;

    // Connectivity Score (30 points)
    $status = 'unknown';
    if (!empty($firewall['agent_last_checkin'])) {
        $checkin_time = strtotime($firewall['agent_last_checkin']);
        $minutes_ago = (time() - $checkin_time) / 60;
        $status = ($minutes_ago <= 10) ? 'online' : 'offline';
    }

    if ($status === 'online') {
        $checkin_time = strtotime($firewall['agent_last_checkin']);
        $minutes_ago = (time() - $checkin_time) / 60;
        if ($minutes_ago <= 5) {
            $health_score += 30;
        } elseif ($minutes_ago <= 15) {
            $health_score += 27;
        } elseif ($minutes_ago <= 60) {
            $health_score += 20;
        } else {
            $health_score += 12;
        }
    }

    // Agent Version Score (20 points)
    if (!empty($firewall['agent_version'])) {
        $agent_version = $firewall['agent_version'];
        if (version_compare($agent_version, AGENT_VERSION, '>=')) {
            $health_score += 20;
        } elseif (version_compare($agent_version, AGENT_MIN_VERSION, '>=')) {
            $health_score += 18;
        } elseif (version_compare($agent_version, '1.0.0', '>=')) {
            $health_score += 14;
        } else {
            $health_score += 8;
        }
    }

    // System Updates Score (20 points)
    if (isset($firewall['updates_available'])) {
        if ($firewall['updates_available'] == 0) {
            $health_score += 20;
        } elseif ($firewall['updates_available'] == 1) {
            $health_score += 10;
        } else {
            $health_score += 8;
        }
    } else {
        $health_score += 8;
    }

    // Uptime Score (15 points)
    if (!empty($firewall['uptime'])) {
        $uptime = $firewall['uptime'];
        if (preg_match('/(\d+)\s*days?/', $uptime, $matches) || preg_match('/up\s+(\d+)/', $uptime, $matches)) {
            $days = (int)$matches[1];
            if ($days >= 7) {
                $health_score += 15;
            } elseif ($days >= 3) {
                $health_score += 12;
            } else {
                $health_score += 8;
            }
        } else {
            $health_score += 5;
        }
    }

    // Configuration Score (15 points)
    $config_score = 0;
    if (!empty($firewall['version'])) $config_score += 5;
    if (!empty($firewall['wan_ip'])) $config_score += 5;
    if (!empty($firewall['lan_ip'])) $config_score += 5;
    $health_score += $config_score;

    return min($health_score, 100);
}

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$tag_filter = $_GET['tag'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort_by = $_GET['sort'] ?? 'hostname';
$sort_order = $_GET['order'] ?? 'ASC';
$page = (int)($_GET['page'] ?? 1);
$per_page = (int)($_GET['per_page'] ?? 25); // Default to 25, allow user selection
$offset = ($page - 1) * $per_page;

// Validate page size (prevent abuse)
if (!in_array($per_page, [10, 25, 50, 100, 200])) {
    $per_page = 25;
}

// Validate sort parameters
$allowed_sorts = ['hostname', 'ip_address', 'customer_name', 'last_checkin', 'agent_last_checkin', 'customer_group', 'status', 'version', 'uptime'];
if (!in_array($sort_by, $allowed_sorts)) {
    $sort_by = 'hostname';
}
if (!in_array($sort_order, ['ASC', 'DESC'])) {
    $sort_order = 'ASC';
}

// Build query for firewalls - use main firewalls table for network data (corrected)
// Only join primary agent to avoid duplicate rows
$query = 'SELECT f.*, fa.agent_version, fa.status as agent_status, fa.last_checkin as agent_last_checkin, fa.opnsense_version FROM firewalls f LEFT JOIN firewall_agents fa ON f.id = fa.firewall_id AND fa.agent_type = \'primary\' WHERE 1=1';
$params = array();

if (!empty($search)) {
    $query .= " AND (f.hostname LIKE ? OR f.ip_address LIKE ? OR f.customer_name LIKE ?)";
    $params[] = "%" . $search . "%";
    $params[] = "%" . $search . "%";
    $params[] = "%" . $search . "%";
}

// Add status filter
if (!empty($status_filter) && $status_filter !== 'all') {
    if ($status_filter === 'online') {
        $query .= " AND fa.last_checkin > DATE_SUB(NOW(), INTERVAL 10 MINUTE)";
    } elseif ($status_filter === 'offline') {
        $query .= " AND (fa.last_checkin IS NULL OR fa.last_checkin <= DATE_SUB(NOW(), INTERVAL 10 MINUTE))";
    } elseif ($status_filter === 'need_updates' || $status_filter === 'needs_update') {
        // Filter for firewalls that need updates (agent-reported, version mismatch, or behind newest known version)
        $query .= " AND (f.updates_available = 1 OR (f.available_version IS NOT NULL AND f.current_version != f.available_version) OR (f.current_version IS NOT NULL AND f.current_version != (SELECT MAX(f2.current_version) FROM firewalls f2 WHERE f2.current_version IS NOT NULL)))";
    }
}

// Add tag filter
if (!empty($tag_filter)) {
    $query .= " AND EXISTS (SELECT 1 FROM firewall_tags ft JOIN tags t ON ft.tag_id = t.id WHERE ft.firewall_id = f.id AND t.name = ?)";
    $params[] = $tag_filter;
}

// Handle special sorting for health (calculated field)
if ($sort_by === 'health') {
    // Get all results first without LIMIT for health calculation
    // Only join primary agent to avoid duplicate rows
    $health_query = 'SELECT f.*, fa.agent_version, fa.status as agent_status, fa.last_checkin as agent_last_checkin, fa.opnsense_version FROM firewalls f LEFT JOIN firewall_agents fa ON f.id = fa.firewall_id AND fa.agent_type = \'primary\' WHERE 1=1';
    $health_params = [];
    
    if (!empty($search)) {
        $health_query .= " AND (f.hostname LIKE ? OR f.ip_address LIKE ? OR f.customer_name LIKE ?)";
        $health_params[] = "%" . $search . "%";
        $health_params[] = "%" . $search . "%";
        $health_params[] = "%" . $search . "%";
    }
    
    if (!empty($status_filter) && $status_filter !== 'all') {
        if ($status_filter === 'online') {
            $health_query .= " AND fa.last_checkin > DATE_SUB(NOW(), INTERVAL 10 MINUTE)";
        } elseif ($status_filter === 'offline') {
            $health_query .= " AND (fa.last_checkin IS NULL OR fa.last_checkin <= DATE_SUB(NOW(), INTERVAL 10 MINUTE))";
        }
    }
    
    if (!empty($tag_filter)) {
        $health_query .= " AND EXISTS (SELECT 1 FROM firewall_tags ft JOIN tags t ON ft.tag_id = t.id WHERE ft.firewall_id = f.id AND t.name = ?)";
        $health_params[] = $tag_filter;
    }
    
    try {
        $stmt = db()->prepare($health_query);
        $stmt->execute($health_params);
        $all_firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate health scores for sorting
        foreach ($all_firewalls as &$fw) {
            $fw['health_score'] = calculateHealthScore($fw);
        }
        
        // Sort by health score
        usort($all_firewalls, function($a, $b) use ($sort_order) {
            if ($sort_order === 'DESC') {
                return $b['health_score'] <=> $a['health_score'];
            } else {
                return $a['health_score'] <=> $b['health_score'];
            }
        });
        
        // Apply pagination
        $firewalls = array_slice($all_firewalls, $offset, $per_page);
        
    } catch (PDOException $e) {
        $firewalls = [];
        error_log("firewalls.php error: " . $e->getMessage());
        $error = 'An internal error occurred.';
    }
} else {
    // Normal sorting for other columns
    $query .= " ORDER BY f." . $sort_by . " " . $sort_order;
    $query .= " LIMIT " . $per_page . " OFFSET " . $offset;

    try {
        $stmt = db()->prepare($query);
        $stmt->execute($params);
        $firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $firewalls = [];
        error_log("firewalls.php error: " . $e->getMessage());
        $error = 'An internal error occurred.';
    }
}

// Determine the latest OPNsense major version across all firewalls for upgrade detection
$latest_major_version = '';
try {
    $ver_stmt = db()->query("SELECT current_version FROM firewalls WHERE current_version IS NOT NULL AND current_version != '' AND current_version != 'Unknown'");
    $all_versions = $ver_stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($all_versions as $ver) {
        // Extract major.minor (e.g., "26.1" from "26.1.1")
        if (version_compare($ver, $latest_major_version, '>')) {
            $latest_major_version = $ver;
        }
    }
} catch (PDOException $e) {
    $latest_major_version = '';
}

// Get all available tags for the filter dropdown
$tags = [];
try {
    $stmt = db()->query("SELECT DISTINCT name FROM tags ORDER BY name");
    $tags = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $tags = [];
}

include __DIR__ . '/inc/header.php';
?>

            <div class="card card-dark">
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                <small class="text-light fw-bold mb-0">
                    <i class="fas fa-network-wired me-1"></i>Firewall Management
                </small>
            </div>

            <!-- Search and Filters -->
                        <!-- Search and Filters -->
            <div class="row mb-3">
                <div class="col-md-2">
                    <input type="text" class="form-control form-control-sm" id="searchInput" 
                           placeholder="Search firewalls..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <select class="form-select form-select-sm" id="tagFilter" onchange="applyFilters()">
                        <option value="">All Tags</option>
                        <?php foreach ($tags as $tag): ?>
                            <option value="<?php echo htmlspecialchars($tag); ?>" <?php echo ($tag_filter === $tag) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tag); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select form-select-sm" id="statusFilter" onchange="applyFilters()">
                        <option value="all" <?php echo ($status_filter === 'all' || $status_filter === '') ? 'selected' : ''; ?>>All Status</option>
                        <option value="online" <?php echo ($status_filter === 'online') ? 'selected' : ''; ?>>Online</option>
                        <option value="offline" <?php echo ($status_filter === 'offline') ? 'selected' : ''; ?>>Offline</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-control form-control-sm" id="sortBy" onchange="applyFilters()">
                        <option value="hostname" <?php echo ($sort_by === 'hostname') ? 'selected' : ''; ?>>Sort by Name</option>
                        <option value="ip_address" <?php echo ($sort_by === 'ip_address') ? 'selected' : ''; ?>>Sort by IP</option>
                        <option value="customer_name" <?php echo ($sort_by === 'customer_name') ? 'selected' : ''; ?>>Sort by Customer</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select form-select-sm" id="autoRefreshSelect" onchange="setAutoRefresh()">
                        <option value="0">No Auto Refresh</option>
                        <option value="60">Refresh 1 min</option>
                        <option value="120">Refresh 2 min</option>
                        <option value="180">Refresh 3 min</option>
                        <option value="300">Refresh 5 min</option>
                        <option value="600">Refresh 10 min</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <button class="btn btn-primary btn-sm w-100" onclick="applyFilters()">
                        <i class="fas fa-search me-1"></i>Search
                    </button>
                </div>
                <div class="col-md-1 text-center">
                    <button class="btn btn-warning btn-sm px-2" onclick="updateAllFirewalls()" title="Update All Firewalls" style="font-size: 0.75rem;">
                        <i class="fas fa-sync-alt me-1" style="font-size: 0.7rem;"></i>Update All
                    </button>
                </div>
            </div>

            <!-- Firewall List -->
            <div class="table-responsive">
                <table class="table table-dark table-hover table-compact">
                    <thead>
                        <tr>
                            <th class="col-name"><a href="?sort=hostname&order=<?php echo ($sort_by === 'hostname' && $sort_order === 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&tag=<?php echo urlencode($tag_filter); ?>" class="text-light text-decoration-none">Name <?php if($sort_by === 'hostname') echo ($sort_order === 'ASC' ? '↑' : '↓'); ?></a></th>
                            <th class="col-ip"><a href="?sort=ip_address&order=<?php echo ($sort_by === 'ip_address' && $sort_order === 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&tag=<?php echo urlencode($tag_filter); ?>" class="text-light text-decoration-none">WAN IP <?php if($sort_by === 'ip_address') echo ($sort_order === 'ASC' ? '↑' : '↓'); ?></a></th>
                            <th class="col-ip">IPv6</th>
                            <th class="col-ip">LAN IP</th>
                            <th class="col-customer"><a href="?sort=customer_name&order=<?php echo ($sort_by === 'customer_name' && $sort_order === 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&tag=<?php echo urlencode($tag_filter); ?>" class="text-light text-decoration-none">Customer <?php if($sort_by === 'customer_name') echo ($sort_order === 'ASC' ? '↑' : '↓'); ?></a></th>
                            <th class="col-version"><a href="?sort=version&order=<?php echo ($sort_by === 'version' && $sort_order === 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&tag=<?php echo urlencode($tag_filter); ?>" class="text-light text-decoration-none">Version <?php if($sort_by === 'version') echo ($sort_order === 'ASC' ? '↑' : '↓'); ?></a></th>
                            <th class="col-tags"><a href="?sort=customer_group&order=<?php echo ($sort_by === 'customer_group' && $sort_order === 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&tag=<?php echo urlencode($tag_filter); ?>" class="text-light text-decoration-none">Tags <?php if($sort_by === 'customer_group') echo ($sort_order === 'ASC' ? '↑' : '↓'); ?></a></th>
                            <th class="col-checkin"><a href="?sort=agent_last_checkin&order=<?php echo ($sort_by === 'agent_last_checkin' && $sort_order === 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&tag=<?php echo urlencode($tag_filter); ?>" class="text-light text-decoration-none">Checkin <?php if($sort_by === 'agent_last_checkin') echo ($sort_order === 'ASC' ? '↑' : '↓'); ?></a></th>
                            <th class="col-uptime"><a href="?sort=uptime&order=<?php echo ($sort_by === 'uptime' && $sort_order === 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&tag=<?php echo urlencode($tag_filter); ?>" class="text-light text-decoration-none">Uptime <?php if($sort_by === 'uptime') echo ($sort_order === 'ASC' ? '↑' : '↓'); ?></a></th>
                            <th class="col-status"><a href="?sort=health&order=<?php echo ($sort_by === 'health' && $sort_order === 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&tag=<?php echo urlencode($tag_filter); ?>" class="text-light text-decoration-none">Health <?php if($sort_by === 'health') echo ($sort_order === 'ASC' ? '↑' : '↓'); ?></a></th>
                            <th class="col-status">Updates</th>
                            <th class="col-status">Status</th>
                            <th class="col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($firewalls as $firewall): ?>
                            <tr id="firewall-row-<?php echo $firewall["id"]; ?>">
                                <!-- Name Column -->
                                <td>
                                    <?php
                                    // Build comprehensive and well-formatted stats tooltip
                                    $stats_tooltip = "🖥️ FIREWALL OVERVIEW\n";
                                    $stats_tooltip .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                                    
                                    // System Information
                                    $stats_tooltip .= "📋 SYSTEM INFO:\n";
                                    $stats_tooltip .= "  • Hostname: " . htmlspecialchars($firewall['hostname']) . "\n";
                                    
                                    // Parse OPNsense version for better display
                                    $opnsense_display = 'Unknown';
                                    if (!empty($firewall['version'])) {
                                        $version_data = json_decode($firewall['version'], true);
                                        if ($version_data && isset($version_data['product_version'])) {
                                            $opnsense_display = $version_data['product_version'];
                                            if (isset($version_data['system_version'])) {
                                                $opnsense_display .= ' (' . $version_data['system_version'] . ')';
                                            }
                                        } else {
                                            $opnsense_display = htmlspecialchars($firewall['version']);
                                        }
                                    }
                                    $stats_tooltip .= "  • OPNsense: " . $opnsense_display . "\n";
                                    $stats_tooltip .= "  • Agent: " . ($firewall['agent_version'] ? 'v' . htmlspecialchars($firewall['agent_version']) : 'Not Available') . "\n";
                                    $stats_tooltip .= "  • Uptime: " . ($firewall['uptime'] ? htmlspecialchars($firewall['uptime']) : 'Unknown') . "\n\n";
                                    
                                    // Network Configuration
                                    $stats_tooltip .= "🌐 NETWORK CONFIG:\n";
                                    $stats_tooltip .= "  • WAN IPv4: " . ($firewall['wan_ip'] ? htmlspecialchars($firewall['wan_ip']) : 'Not Available') . "\n";
                                    $stats_tooltip .= "  • LAN IP: " . ($firewall['lan_ip'] ? htmlspecialchars($firewall['lan_ip']) : 'Not Available') . "\n";
                                    $stats_tooltip .= "  • IPv6: " . ($firewall['ipv6_address'] ? htmlspecialchars($firewall['ipv6_address']) : 'Not Available') . "\n\n";
                                    
                                    // Status Information
                                    $stats_tooltip .= "📊 STATUS:\n";
                                    $last_checkin = $firewall['agent_last_checkin'] ? date('M j, Y H:i:s', strtotime($firewall['agent_last_checkin'])) : 'Never';
                                    $stats_tooltip .= "  • Last Seen: " . $last_checkin . "\n";
                                    $stats_tooltip .= "  • Customer: " . ($firewall['customer_name'] ? htmlspecialchars($firewall['customer_name']) : 'Not Set') . "\n";
                                    
                                    // Hardware info if available
                                    if (!empty($firewall['hardware_id'])) {
                                        $stats_tooltip .= "  • Hardware ID: " . htmlspecialchars($firewall['hardware_id']) . "\n";
                                    }
                                    ?>
                                    <strong class="text-light hover-tooltip" data-tooltip="<?php echo htmlspecialchars($stats_tooltip); ?>"><?php echo htmlspecialchars($firewall["hostname"]); ?></strong>
                                    <?php if (!empty($firewall["agent_version"])): ?>
                                        <br><small class="text-light">v<?php echo htmlspecialchars($firewall["agent_version"]); ?></small>
                                    <?php endif; ?>
                                </td>                                <!-- IPv4 WAN Column -->
                                <!-- IPv4 WAN Column -->
                                <!-- IPv4 WAN Column -->
                                <!-- IPv4 WAN Column -->
                                <td class="text-light">
                                    <?php if (!empty($firewall["wan_ip"]) && filter_var($firewall["wan_ip"], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)): ?>
                                        <?php
                                        $wan_ip = $firewall["wan_ip"];
                                        $wan_tooltip = "🌐 WAN NETWORK INTERFACE\n";
                                        $wan_tooltip .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                                        $wan_tooltip .= "📍 NETWORK DETAILS:\n";
                                        $wan_tooltip .= "  • IPv4 Address: " . htmlspecialchars($wan_ip) . "\n";
                                        
                                        // Determine likely network class and info
                                        $ip_parts = explode('.', $wan_ip);
                                        $first_octet = (int)$ip_parts[0];
                                        
                                        if ($first_octet >= 1 && $first_octet <= 126) {
                                            $wan_tooltip .= "  • Class: A (1.0.0.0 - 126.255.255.255)\n";
                                        } elseif ($first_octet >= 128 && $first_octet <= 191) {
                                            $wan_tooltip .= "  • Class: B (128.0.0.0 - 191.255.255.255)\n";
                                        } elseif ($first_octet >= 192 && $first_octet <= 223) {
                                            $wan_tooltip .= "  • Class: C (192.0.0.0 - 223.255.255.255)\n";
                                        }
                                        
                                        // Add ISP/Provider detection
                                        if (strpos($wan_ip, '73.') === 0) {
                                            $wan_tooltip .= "  • Provider: Likely Comcast/Xfinity range\n";
                                        } elseif (strpos($wan_ip, '24.') === 0) {
                                            $wan_tooltip .= "  • Provider: Likely Charter/Spectrum range\n";
                                        } elseif (strpos($wan_ip, '76.') === 0) {
                                            $wan_tooltip .= "  • Provider: Likely Comcast/Xfinity range\n";
                                        } else {
                                            $wan_tooltip .= "  • Provider: ISP assigned public IP\n";
                                        }
                                        
                                        $wan_tooltip .= "\n🔧 INTERFACE INFO:\n";
                                        $wan_tooltip .= "  • Type: Wide Area Network (Internet)\n";
                                        $wan_tooltip .= "  • Role: External connectivity\n";
                                        $wan_tooltip .= "  • Scope: Public internet routable\n";
                                        
                                        $wan_tooltip .= "\n📊 NETWORK CONFIG:\n";
                                        
                                        // Show real network data if available, otherwise show estimates
                                        if (!empty($firewall['wan_netmask']) || !empty($firewall['wan_gateway'])) {
                                            $wan_tooltip .= "  • Subnet Mask: " . ($firewall['wan_netmask'] ?: 'Not Available') . "\n";
                                            $wan_tooltip .= "  • Gateway: " . ($firewall['wan_gateway'] ?: 'Not Available') . "\n";
                                            $wan_tooltip .= "  • Primary DNS: " . ($firewall['wan_dns_primary'] ?: 'Not Available') . "\n";
                                            if (!empty($firewall['wan_dns_secondary'])) {
                                                $wan_tooltip .= "  • Secondary DNS: " . $firewall['wan_dns_secondary'] . "\n";
                                            }
                                            $wan_tooltip .= "\n✅ CURRENT DATA";
                                        } else {
                                            $wan_tooltip .= "  • Subnet Mask: /24 (estimated)\n";
                                            $wan_tooltip .= "  • Gateway: Likely " . $ip_parts[0] . "." . $ip_parts[1] . "." . $ip_parts[2] . ".1 (estimated)\n";
                                            $wan_tooltip .= "  • DNS: ISP provided (estimated)\n";
                                            $wan_tooltip .= "\n⚠️ ESTIMATED DATA - waiting for real config from agent";
                                        }
                                        ?>
                                        <span class="hover-tooltip" data-tooltip="<?php echo $wan_tooltip; ?>" style="font-family: 'Courier New', monospace;"><?php echo htmlspecialchars($firewall["wan_ip"]); ?></span>
                                    <?php else: ?>
                                        <span class="text-danger" style="animation: pulse 2s infinite;">Awaiting Agent Data</span>
                                    <?php endif; ?>
                                </td>                                <!-- IPv6 WAN Column -->
                                <!-- IPv6 WAN Column -->
                                <td class="text-light">
                                    <?php if (!empty($firewall["ipv6_address"])): ?>
                                        <?php
                                        $ipv6_full = $firewall["ipv6_address"];
                                        // Show more of the IPv6 address - up to 35 characters for better readability
                                        $ipv6_display = strlen($ipv6_full) > 35 ? substr($ipv6_full, 0, 35) . "..." : $ipv6_full;
                                        
                                        // Build comprehensive IPv6 tooltip
                                        $ipv6_tooltip = "🌐 IPv6 ADDRESS DETAILS\n";
                                        $ipv6_tooltip .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                                        $ipv6_tooltip .= "📍 Full Address:\n";
                                        $ipv6_tooltip .= htmlspecialchars($ipv6_full) . "\n\n";
                                        
                                        // Add IPv6 type and scope information
                                        if (strpos($ipv6_full, 'fe80:') === 0) {
                                            $ipv6_tooltip .= "� Type: Link-Local Address\n";
                                            $ipv6_tooltip .= "📡 Scope: Interface-specific (not routable)\n";
                                            $ipv6_tooltip .= "🎯 Usage: Local network communication\n";
                                        } elseif (strpos($ipv6_full, '2001:') === 0) {
                                            $ipv6_tooltip .= "🌍 Type: Global Unicast Address\n";
                                            $ipv6_tooltip .= "📡 Scope: Internet routable\n";
                                            $ipv6_tooltip .= "🎯 Usage: Public internet connectivity\n";
                                        } elseif (strpos($ipv6_full, '2600:') === 0 || strpos($ipv6_full, '2601:') === 0 || strpos($ipv6_full, '2602:') === 0) {
                                            $ipv6_tooltip .= "� Type: ISP Assigned Block\n";
                                            $ipv6_tooltip .= "📡 Scope: Provider allocated range\n";
                                            $ipv6_tooltip .= "🎯 Usage: Customer premise networks\n";
                                        } elseif (strpos($ipv6_full, 'fc00:') === 0 || strpos($ipv6_full, 'fd00:') === 0) {
                                            $ipv6_tooltip .= "🏠 Type: Unique Local Address\n";
                                            $ipv6_tooltip .= "📡 Scope: Private network (RFC 4193)\n";
                                            $ipv6_tooltip .= "🎯 Usage: Internal organization use\n";
                                        } else {
                                            $ipv6_tooltip .= "❓ Type: Other IPv6 Address\n";
                                            $ipv6_tooltip .= "📡 Scope: See RFC specifications\n";
                                        }
                                        
                                        // Add compression info
                                        if (strpos($ipv6_full, '::') !== false) {
                                            $ipv6_tooltip .= "\n💡 NOTATION:\n";
                                            $ipv6_tooltip .= "Uses compressed form (::) - zeros omitted for brevity\n";
                                        }
                                        
                                        // Add technical details
                                        $ipv6_tooltip .= "\n🔧 TECHNICAL:\n";
                                        $ipv6_tooltip .= "• Length: 128 bits (16 bytes)\n";
                                        $ipv6_tooltip .= "• Format: 8 groups of 4 hexadecimal digits\n";
                                        $ipv6_tooltip .= "• Network: " . (strlen($ipv6_full) > 20 ? "Likely /64 subnet" : "Standard allocation");
                                        ?>
                                        <small class="hover-tooltip" data-tooltip="<?php echo $ipv6_tooltip; ?>" style="font-family: 'Courier New', monospace; font-size: 0.7rem;"><?php echo htmlspecialchars($ipv6_display); ?></small>
                                    <?php elseif (!empty($firewall["wan_ip"]) && filter_var($firewall["wan_ip"], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)): ?>
                                        <?php
                                        $ipv6_full = $firewall["wan_ip"];
                                        // Show more of the IPv6 address - up to 35 characters for better readability
                                        $ipv6_display = strlen($ipv6_full) > 35 ? substr($ipv6_full, 0, 35) . "..." : $ipv6_full;
                                        
                                        // Build comprehensive WAN IPv6 tooltip
                                        $ipv6_tooltip = "🌐 IPv6 WAN ADDRESS\n";
                                        $ipv6_tooltip .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                                        $ipv6_tooltip .= "📍 WAN Address:\n";
                                        $ipv6_tooltip .= htmlspecialchars($ipv6_full) . "\n\n";
                                        
                                        // Add IPv6 type information for WAN addresses
                                        if (strpos($ipv6_full, '2001:') === 0) {
                                            $ipv6_tooltip .= "🌍 Type: Global Unicast Address\n";
                                            $ipv6_tooltip .= "📡 Scope: Internet routable\n";
                                            $ipv6_tooltip .= "🎯 Usage: Public internet connectivity\n";
                                        } elseif (strpos($ipv6_full, '2600:') === 0 || strpos($ipv6_full, '2601:') === 0 || strpos($ipv6_full, '2602:') === 0) {
                                            $ipv6_tooltip .= "🏢 Type: ISP Assigned Block\n";
                                            $ipv6_tooltip .= "📡 Scope: Provider allocated range\n";
                                            $ipv6_tooltip .= "🎯 Usage: Customer WAN connection\n";
                                        } else {
                                            $ipv6_tooltip .= "🌐 Type: IPv6 WAN Address\n";
                                            $ipv6_tooltip .= "📡 Scope: External network interface\n";
                                        }
                                        
                                        $ipv6_tooltip .= "\n💼 INTERFACE: Wide Area Network (WAN)\n";
                                        $ipv6_tooltip .= "🔄 Role: External internet connectivity";
                                        ?>
                                        <small class="hover-tooltip" data-tooltip="<?php echo $ipv6_tooltip; ?>" style="font-family: 'Courier New', monospace; font-size: 0.7rem;"><?php echo htmlspecialchars($ipv6_display); ?></small>
                                    <?php else: ?>
                                        <span class="text-secondary" style="opacity: 0.6;">-</span>
                                    <?php endif; ?>
                                </td>                                <!-- LAN IP Column -->
                                                                <!-- LAN IP Column -->
                                <td class="text-light">
                                    <?php if (!empty($firewall["lan_ip"])): ?>
                                        <?php
                                        $lan_tooltip = "🏠 INTERNAL NETWORK\n";
                                        $lan_tooltip .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                                        $lan_tooltip .= "🔗 LAN IP: " . htmlspecialchars($firewall['lan_ip']) . "\n";
                                        
                                        // Show real network data if available, otherwise detect class/type
                                        $lan_ip = $firewall['lan_ip'];
                                        
                                        if (!empty($firewall['lan_netmask']) || !empty($firewall['lan_network'])) {
                                            // Show real network configuration
                                            $lan_tooltip .= "📶 Subnet Mask: " . ($firewall['lan_netmask'] ?: 'Not Available') . "\n";
                                            $lan_tooltip .= "📍 Network: " . ($firewall['lan_network'] ?: 'Not Available') . "\n";
                                            $lan_tooltip .= "✅ CURRENT DATA\n";
                                        } else {
                                            // Fall back to generic RFC range detection
                                            if (strpos($lan_ip, '192.168.') === 0) {
                                                $lan_tooltip .= "📶 Network: Private Class C (RFC 1918)\n";
                                                $lan_tooltip .= "📍 Range: 192.168.0.0/16 (estimated)\n";
                                            } elseif (strpos($lan_ip, '10.') === 0) {
                                                $lan_tooltip .= "📶 Network: Private Class A (RFC 1918)\n";
                                                $lan_tooltip .= "📍 Range: 10.0.0.0/8 (estimated)\n";
                                            } elseif (preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $lan_ip)) {
                                                $lan_tooltip .= "📶 Network: Private Class B (RFC 1918)\n";
                                                $lan_tooltip .= "📍 Range: 172.16.0.0/12 (estimated)\n";
                                            }
                                            $lan_tooltip .= "⚠️ ESTIMATED - waiting for real subnet from agent\n";
                                        }

                                        ?>
                                        <span class="hover-tooltip" data-tooltip="<?php echo $lan_tooltip; ?>" style="font-family: 'Courier New', monospace;"><?php echo htmlspecialchars($firewall["lan_ip"]); ?></span>
                                    <?php elseif (!empty($firewall["ip_address"])): ?>
                                        <?php
                                        $ip_tooltip = "🔗 NETWORK ADDRESS\n";
                                        $ip_tooltip .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                                        $ip_tooltip .= "📍 Address: " . htmlspecialchars($firewall['ip_address']) . "\n";
                                        $ip_tooltip .= "💡 Note: This is the configured management IP";
                                        ?>
                                        <span class="hover-tooltip" data-tooltip="<?php echo $ip_tooltip; ?>" style="font-family: 'Courier New', monospace;"><?php echo htmlspecialchars($firewall["ip_address"]); ?></span>
                                    <?php else: ?>
                                        <span class="text-danger" style="animation: pulse 2s infinite;">Awaiting Agent Data</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Customer Column -->
                                <td>
                                    <?php
                                    $customer_display = $firewall["customer_name"] ?: $firewall["customer_group"] ?: '';
                                    ?>
                                    <?php if (!empty($customer_display)): ?>
                                        <small class="text-light hover-tooltip" data-tooltip="<?php echo !empty($firewall['customer_name']) ? 'Customer: ' . htmlspecialchars($firewall['customer_name']) : ''; ?><?php echo (!empty($firewall['customer_name']) && !empty($firewall['customer_group'])) ? ' | ' : ''; ?><?php echo !empty($firewall['customer_group']) ? 'Company: ' . htmlspecialchars($firewall['customer_group']) : ''; ?>"><?php echo htmlspecialchars($customer_display); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- OPNsense Version Column -->
                                <td>
                                    <?php
                                    // Use version column, fall back to current_version
                                    $fw_version = $firewall["version"];
                                    if (empty($fw_version) || $fw_version === 'Unknown') {
                                        $fw_version = $firewall["current_version"] ?? '';
                                    }
                                    if (empty($fw_version) || $fw_version === 'Unknown') {
                                        $fw_version = '';
                                    }
                                    ?>
                                    <?php if (!empty($fw_version)): ?>
                                        <?php
                                        // Parse version - could be JSON or plain string
                                        $version_display = $fw_version;
                                        $version_data = json_decode($fw_version, true);
                                        
                                        $tooltip = "📦 SOFTWARE VERSIONS\n";
                                        $tooltip .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                                        
                                        if ($version_data && isset($version_data['product_version'])) {
                                            $version_display = $version_data['product_version'];
                                            $tooltip .= "🛡️ OPNsense: " . $version_data['product_version'] . "\n";
                                            
                                            if (isset($version_data['firmware_version'])) {
                                                $tooltip .= "⚙️ Firmware: " . $version_data['firmware_version'] . "\n";
                                            }
                                            if (isset($version_data['system_version'])) {
                                                $tooltip .= "🖥️ FreeBSD: " . $version_data['system_version'] . "\n";
                                            }
                                            if (isset($version_data['architecture'])) {
                                                $tooltip .= "🏗️ Architecture: " . $version_data['architecture'] . "\n";
                                            }
                                        } else {
                                            $tooltip .= "🛡️ OPNsense: " . htmlspecialchars($fw_version) . "\n";
                                        }

                                        if (!empty($firewall['agent_version'])) {
                                            $tooltip .= "🤖 Agent: v" . htmlspecialchars($firewall['agent_version']) . "\n";
                                        }
                                        
                                        // Add update info only if updates are actually available
                                        if ($firewall['updates_available'] == 1 && !empty($firewall['available_version'])) {
                                            $tooltip .= "\n📥 UPDATES:\n";
                                            $tooltip .= "Available: " . htmlspecialchars($firewall['available_version']) . "\n";
                                        }
                                        
                                        if (!empty($firewall['last_update_check'])) {
                                            $tooltip .= "Last Check: " . date('M j, Y H:i', strtotime($firewall['last_update_check']));
                                        }
                                        ?>
                                        <small class="text-light hover-tooltip" data-tooltip="<?php echo $tooltip; ?>"><?php echo htmlspecialchars($version_display); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">Unknown</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Tags Column -->
                                <td>
                                    <?php
                                        // Get tags for this firewall
                                        $stmt = db()->prepare("SELECT t.name, t.color FROM tags t JOIN firewall_tags ft ON t.id = ft.tag_id WHERE ft.firewall_id = ?");
                                        $stmt->execute([$firewall["id"]]);
                                        $firewall_tags = $stmt->fetchAll();
                                        
                                        if (!empty($firewall_tags)):
                                            foreach ($firewall_tags as $tag):
                                                // Calculate text color based on background brightness
                                                $bg_color = $tag["color"];
                                                // Remove # if present
                                                $hex = ltrim($bg_color, '#');
                                                // Convert to RGB
                                                $r = hexdec(substr($hex, 0, 2));
                                                $g = hexdec(substr($hex, 2, 2));
                                                $b = hexdec(substr($hex, 4, 2));
                                                // Calculate perceived brightness (0-255)
                                                $brightness = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
                                                // Use white text for dark backgrounds, black for light
                                                $text_color = $brightness < 128 ? '#ffffff' : '#000000';
                                    ?>
                                        <span class="badge me-1" style="background-color: <?php echo htmlspecialchars($tag["color"]); ?>; color: <?php echo $text_color; ?>;">
                                            <?php echo htmlspecialchars($tag["name"]); ?>
                                        </span>
                                    <?php
                                            endforeach;
                                        endif;
                                        if (empty($firewall_tags)):
                                    ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Last Checkin Column -->
                                <td>
                                    <?php 
                                    // Get both agent check-in times
                                    $agent_stmt = db()->prepare('SELECT agent_type, last_checkin FROM firewall_agents WHERE firewall_id = ?');
                                    $agent_stmt->execute([$firewall['id']]);
                                    $agents = $agent_stmt->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    $primary_checkin = null;
                                    $update_checkin = null;
                                    foreach ($agents as $agent) {
                                        if ($agent['agent_type'] === 'primary') {
                                            $primary_checkin = $agent['last_checkin'];
                                        } elseif ($agent['agent_type'] === 'update') {
                                            $update_checkin = $agent['last_checkin'];
                                        }
                                    }
                                    
                                    // Build dual-agent tooltip
                                    $checkin_tooltip = "🤖 AGENT STATUS REPORT\n";
                                    $checkin_tooltip .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                                    
                                    $checkin_tooltip .= "🎯 PRIMARY AGENT:\n";
                                    if ($primary_checkin) {
                                        $checkin_tooltip .= "  • Last Checkin: " . date('M j, Y H:i:s', strtotime($primary_checkin)) . "\n";
                                        $minutes_ago = (time() - strtotime($primary_checkin)) / 60;
                                        $checkin_tooltip .= "  • Status: " . ($minutes_ago <= 10 ? "🟢 Online" : "🔴 Offline") . "\n";
                                        $checkin_tooltip .= "  • Interval: Every 2 minutes\n";
                                    } else {
                                        $checkin_tooltip .= "  • Status: 🔴 Never checked in\n";
                                    }
                                    
                                    $checkin_tooltip .= "\n🛡️ UPDATE AGENT:\n";
                                    if ($update_checkin) {
                                        $checkin_tooltip .= "  • Last Checkin: " . date('M j, Y H:i:s', strtotime($update_checkin)) . "\n";
                                        $update_minutes_ago = (time() - strtotime($update_checkin)) / 60;
                                        $checkin_tooltip .= "  • Status: " . ($update_minutes_ago <= 30 ? "🟢 Online" : "🔴 Offline") . "\n";
                                        $checkin_tooltip .= "  • Interval: Every 5 minutes\n";
                                    } else {
                                        $checkin_tooltip .= "  • Status: ❌ Not deployed\n";
                                    }
                                    
                                    $checkin_tooltip .= "\n💡 INFO:\n";
                                    $checkin_tooltip .= "Primary agent handles regular operations\n";
                                    $checkin_tooltip .= "Update agent provides failsafe backup";
                                    ?>
                                    
                                    <?php if (!empty($primary_checkin)): ?>
                                        <span class="hover-tooltip" data-tooltip="<?php echo htmlspecialchars($checkin_tooltip); ?>">
                                            <?php echo getRelativeTime($primary_checkin); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted hover-tooltip" data-tooltip="<?php echo htmlspecialchars($checkin_tooltip); ?>">Never</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Uptime Column -->
                                <td>
                                    <?php if (!empty($firewall["uptime"])): ?>
                                        <?php
                                        $uptime_raw = $firewall["uptime"];
                                        $formatted_uptime = '';
                                        
                                        // Handle various uptime formats
                                        if (preg_match('/(\d+)\s*days?\s*(\d+):(\d+)/', $uptime_raw, $matches)) {
                                            // Format: "5 days 12:30" or "1 day 0:45"
                                            $days = $matches[1];
                                            $hours = $matches[2];
                                            $minutes = $matches[3];
                                            $formatted_uptime = $days . 'd ' . $hours . 'h ' . $minutes . 'm';
                                        } elseif (preg_match('/(\d+)\s+hours?,\s+(\d+)\s+minutes?/', $uptime_raw, $matches)) {
                                            // Format: "18 hours, 14 minutes" (NEW FORMAT FROM AGENT)
                                            $hours = $matches[1];
                                            $minutes = $matches[2];
                                            $formatted_uptime = '0d ' . $hours . 'h ' . $minutes . 'm';
                                        } elseif (preg_match('/up\s+(\d+)/', $uptime_raw, $matches)) {
                                            // Handle "up 1" format - assume it means 1 day
                                            $value = $matches[1];
                                            $formatted_uptime = $value . 'd 0h 0m';
                                        } elseif (preg_match('/(\d+):(\d+)/', $uptime_raw, $matches)) {
                                            // Format: "12:30" (hours:minutes)
                                            $hours = $matches[1];
                                            $minutes = $matches[2];
                                            $formatted_uptime = '0d ' . $hours . 'h ' . $minutes . 'm';
                                        } elseif (preg_match('/(\d+)\s*mins?/', $uptime_raw, $matches)) {
                                            // Format: "45 min" or "120 minutes"
                                            $minutes = $matches[1];
                                            $hours = floor($minutes / 60);
                                            $remaining_minutes = $minutes % 60;
                                            $formatted_uptime = '0d ' . $hours . 'h ' . $remaining_minutes . 'm';
                                        } else {
                                            // Fallback for unknown formats
                                            $formatted_uptime = $uptime_raw;
                                        }
                                        
                                        $uptime_tooltip = "System Uptime: " . htmlspecialchars($uptime_raw);
                                        ?>
                                        <small class="text-light hover-tooltip" data-tooltip="<?php echo $uptime_tooltip; ?>" style="font-size: 0.65rem; line-height: 1.1;"><?php echo htmlspecialchars($formatted_uptime); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size: 0.65rem;">Unknown</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Health Column -->
                                <td class="text-center">
                                    <?php
                                    // Determine current status first (needed for health calculation)
                                    if (!empty($firewall["agent_status"])) {
                                        $current_status = $firewall["agent_status"];
                                    } else {
                                        $current_status = "unknown";
                                    }
                                    
                                    // Override with offline if last checkin is too old
                                    if (!empty($firewall["agent_last_checkin"])) {
                                        $checkin = new DateTime($firewall["agent_last_checkin"]);
                                        $now = new DateTime();
                                        $diff = $now->diff($checkin);
                                        if ($diff->days > 0 || $diff->h >= 3) {
                                            $current_status = "offline";
                                        }
                                    } else {
                                        $current_status = "unknown";
                                    }
                                    
                                    // Calculate comprehensive health score (0-100)
                                    // Weights: Connectivity(30) + Agent(20) + Updates(20) + Uptime(15) + Config(15) = 100
                                    $health_score = 0;
                                    $health_issues = [];
                                    $health_details = [];

                                    // Connectivity Score (30 points)
                                    if ($current_status === 'online') {
                                        $checkin_field = !empty($firewall['agent_last_checkin']) ? 'agent_last_checkin' : 'last_checkin';
                                        $checkin_time = strtotime($firewall[$checkin_field]);
                                        $minutes_ago = (time() - $checkin_time) / 60;
                                        if ($minutes_ago <= 5) {
                                            $health_score += 30;
                                            $health_details[] = "✓ Excellent connectivity (last checkin " . round($minutes_ago, 1) . "m ago)";
                                        } elseif ($minutes_ago <= 15) {
                                            $health_score += 27;
                                            $health_details[] = "✓ Good connectivity (last checkin " . round($minutes_ago, 1) . "m ago)";
                                        } elseif ($minutes_ago <= 60) {
                                            $health_score += 20;
                                            $health_details[] = "✓ Acceptable connectivity (" . round($minutes_ago, 1) . "m ago)";
                                        } else {
                                            $health_score += 12;
                                            $health_issues[] = "⚠ Delayed checkin (" . round($minutes_ago/60, 1) . "h ago)";
                                        }
                                    } else {
                                        $health_issues[] = "✗ Firewall offline";
                                    }

                                    // Agent Version Score (20 points)
                                    if (!empty($firewall['agent_version'])) {
                                        $agent_version = $firewall['agent_version'];
                                        if (version_compare($agent_version, AGENT_VERSION, '>=')) {
                                            $health_score += 20;
                                            $health_details[] = "✓ Agent up to date (v" . $agent_version . ")";
                                        } elseif (version_compare($agent_version, AGENT_MIN_VERSION, '>=')) {
                                            $health_score += 18;
                                            $health_details[] = "✓ Agent supported version (v" . $agent_version . ")";
                                        } elseif (version_compare($agent_version, '1.0.0', '>=')) {
                                            $health_score += 14;
                                            $health_issues[] = "⚠ Agent needs update (v" . $agent_version . ")";
                                        } else {
                                            $health_score += 8;
                                            $health_issues[] = "⚠ Agent severely outdated (v" . $agent_version . ")";
                                        }
                                    } else {
                                        $health_issues[] = "✗ No agent version reported";
                                    }

                                    // System Updates Score (20 points)
                                    $fw_upgrade_available = false;
                                    if (!empty($firewall['current_version']) && !empty($latest_major_version) && $firewall['current_version'] !== 'Unknown') {
                                        $fw_cur_parts = explode('.', $firewall['current_version']);
                                        $fw_lat_parts = explode('.', $latest_major_version);
                                        $fw_cur_major = (isset($fw_cur_parts[0]) ? (int)$fw_cur_parts[0] : 0) * 100 + (isset($fw_cur_parts[1]) ? (int)explode('_', $fw_cur_parts[1])[0] : 0);
                                        $fw_lat_major = (isset($fw_lat_parts[0]) ? (int)$fw_lat_parts[0] : 0) * 100 + (isset($fw_lat_parts[1]) ? (int)explode('_', $fw_lat_parts[1])[0] : 0);
                                        if ($fw_lat_major > $fw_cur_major) {
                                            $fw_upgrade_available = true;
                                        }
                                    }

                                    if ($fw_upgrade_available) {
                                        $health_score += 5;
                                        $health_issues[] = "⚠ Major upgrade available (v" . htmlspecialchars($firewall['current_version']) . " → " . htmlspecialchars($latest_major_version) . ")";
                                    } elseif (isset($firewall['updates_available'])) {
                                        if ($firewall['updates_available'] == 0) {
                                            $health_score += 20;
                                            $health_details[] = "✓ System up to date";
                                        } elseif ($firewall['updates_available'] == 1) {
                                            $health_score += 10;
                                            $health_issues[] = "⚠ System updates available";
                                        } else {
                                            $health_score += 8;
                                            $health_issues[] = "⚠ Update status unknown";
                                        }
                                    } else {
                                        $health_score += 8;
                                        $health_issues[] = "⚠ Update check needed";
                                    }

                                    // Uptime Score (15 points)
                                    if (!empty($firewall['uptime'])) {
                                        $uptime = $firewall['uptime'];
                                        if (preg_match('/(\d+)\s*days?/', $uptime, $matches) || preg_match('/up\s+(\d+)/', $uptime, $matches)) {
                                            $days = (int)$matches[1];
                                            if ($days >= 7) {
                                                $health_score += 15;
                                                $health_details[] = "✓ Excellent uptime (" . $days . " days)";
                                            } elseif ($days >= 3) {
                                                $health_score += 12;
                                                $health_details[] = "✓ Good uptime (" . $days . " days)";
                                            } else {
                                                $health_score += 8;
                                                $health_details[] = "⚠ Recent restart (" . $days . " days)";
                                            }
                                        } else {
                                            $health_score += 5;
                                            $health_issues[] = "⚠ Recent restart (< 1 day)";
                                        }
                                    } else {
                                        $health_issues[] = "✗ No uptime data";
                                    }

                                    // Configuration Score (15 points)
                                    // Agent-based management doesn't require OPNsense API creds
                                    $config_score = 0;
                                    if (!empty($firewall['version'])) $config_score += 5;
                                    if (!empty($firewall['wan_ip'])) $config_score += 5;
                                    if (!empty($firewall['lan_ip'])) $config_score += 5;
                                    $health_score += $config_score;

                                    if ($config_score >= 15) {
                                        $health_details[] = "✓ Complete configuration";
                                    } elseif ($config_score >= 10) {
                                        $health_details[] = "✓ Basic configuration";
                                    } else {
                                        $health_issues[] = "⚠ Configuration incomplete";
                                    }
                                    
                                    // Determine health grade and color with adjusted thresholds
                                    if ($health_score >= 85) {
                                        $health_grade = 'A+';
                                        $health_color = 'bg-success text-white';
                                        $health_icon = 'fas fa-heart';
                                    } elseif ($health_score >= 78) {
                                        $health_grade = 'A';
                                        $health_color = 'bg-success text-white';
                                        $health_icon = 'fas fa-thumbs-up';
                                    } elseif ($health_score >= 70) {
                                        $health_grade = 'B+';
                                        $health_color = 'bg-success text-white';
                                        $health_icon = 'fas fa-check-circle';
                                    } elseif ($health_score >= 62) {
                                        $health_grade = 'B';
                                        $health_color = 'bg-info text-white';
                                        $health_icon = 'fas fa-check-circle';
                                    } elseif ($health_score >= 50) {
                                        $health_grade = 'C+';
                                        $health_color = 'bg-warning text-white';
                                        $health_icon = 'fas fa-exclamation-circle';
                                    } elseif ($health_score >= 40) {
                                        $health_grade = 'C';
                                        $health_color = 'bg-warning text-white';
                                        $health_icon = 'fas fa-exclamation-circle';
                                    } else {
                                        $health_grade = 'F';
                                        $health_color = 'bg-danger text-white';
                                        $health_icon = 'fas fa-exclamation-triangle';
                                    }
                                    
                                    // Build enhanced health tooltip
                                    $health_tooltip = "🏥 FIREWALL HEALTH REPORT\n";
                                    $health_tooltip .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                                    $health_tooltip .= "📊 Overall Score: " . $health_score . "/100 (Grade " . $health_grade . ")\n\n";
                                    
                                    // Add status indicator
                                    if ($health_score >= 90) {
                                        $health_tooltip .= "🟢 Status: EXCELLENT - System performing optimally\n";
                                    } elseif ($health_score >= 80) {
                                        $health_tooltip .= "🟡 Status: GOOD - Minor issues detected\n";
                                    } elseif ($health_score >= 70) {
                                        $health_tooltip .= "🟠 Status: FAIR - Attention recommended\n";
                                    } elseif ($health_score >= 60) {
                                        $health_tooltip .= "🔴 Status: POOR - Issues need addressing\n";
                                    } else {
                                        $health_tooltip .= "⚫ Status: CRITICAL - Immediate action required\n";
                                    }
                                    $health_tooltip .= "\n";
                                    
                                    if (!empty($health_details)) {
                                        $health_tooltip .= "✅ HEALTHY COMPONENTS:\n";
                                        foreach ($health_details as $detail) {
                                            $health_tooltip .= "  • " . $detail . "\n";
                                        }
                                        $health_tooltip .= "\n";
                                    }
                                    
                                    if (!empty($health_issues)) {
                                        $health_tooltip .= "⚠️ AREAS FOR IMPROVEMENT:\n";
                                        foreach ($health_issues as $issue) {
                                            $health_tooltip .= "  • " . $issue . "\n";
                                        }
                                        $health_tooltip .= "\n💡 Tip: Address issues above to improve health score";
                                    }
                                    ?>
                                    <span class="badge <?php echo $health_color; ?> hover-tooltip" style="color: #fff !important;" data-tooltip="<?php echo htmlspecialchars(trim($health_tooltip)); ?>">
                                        <i class="<?php echo $health_icon; ?> me-1"></i><?php echo $health_grade; ?>
                                    </span>
                                    <br><small style="font-size: 0.7rem; color: #94a3b8;"><?php echo $health_score; ?>/100</small>
                                </td>
                                <!-- Updates Column -->
                                <td>
                                    <?php if (in_array($firewall["status"], ['updating', 'update_pending'])): ?>
                                        <div class="updating-animation">
                                            <span class="badge bg-primary text-white">
                                                <i class="fas fa-sync-alt fa-spin me-1"></i>Updating...
                                            </span>
                                            <div class="progress mt-1" style="height: 4px; max-width: 120px;">
                                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width: 100%"></div>
                                            </div>
                                            <small class="text-muted d-block mt-1" style="font-size: 0.65rem;">
                                                <?php if ($firewall["status"] === 'update_pending'): ?>
                                                    Waiting for agent...
                                                <?php else: ?>
                                                    Update in progress
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    <?php elseif ($firewall["updates_available"] == 1): ?>
                                        <?php
                                        // Build enhanced update information tooltip
                                        $update_tooltip = "📦 SOFTWARE UPDATES AVAILABLE\n";
                                        $update_tooltip .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                                        
                                        // Parse versions for better display
                                        $current_display = 'Unknown';
                                        $available_display = 'Unknown';
                                        
                                        if (!empty($firewall['current_version'])) {
                                            $current_data = json_decode($firewall['current_version'], true);
                                            if ($current_data && isset($current_data['product_version'])) {
                                                $current_display = $current_data['product_version'];
                                            } else {
                                                $current_display = htmlspecialchars($firewall['current_version']);
                                            }
                                        }
                                        
                                        if (!empty($firewall['available_version'])) {
                                            $available_display = htmlspecialchars($firewall['available_version']);
                                        }
                                        
                                        $update_tooltip .= "📊 VERSION INFO:\n";
                                        $update_tooltip .= "  • Current: " . $current_display . "\n";
                                        $update_tooltip .= "  • Available: " . $available_display . "\n\n";
                                        
                                        $update_tooltip .= "🔍 UPDATE STATUS:\n";
                                        $last_check = $firewall['last_update_check'] ? date('M j, Y H:i:s', strtotime($firewall['last_update_check'])) : 'Never';
                                        $update_tooltip .= "  • Last Check: " . $last_check . "\n";
                                        
                                        // Add update benefits
                                        $update_tooltip .= "\n✨ BENEFITS:\n";
                                        $update_tooltip .= "  • Security patches and fixes\n";
                                        $update_tooltip .= "  • Performance improvements\n";
                                        $update_tooltip .= "  • New features and stability\n\n";
                                        
                                        $update_tooltip .= "🚀 ACTION: Click the sync button below to update";
                                        ?>
                                        <span class="badge bg-warning text-white hover-tooltip" data-tooltip="<?php echo htmlspecialchars($update_tooltip); ?>">
                                            <i class="fas fa-exclamation-triangle me-1"></i>Available
                                        </span>
                                        <button class="btn btn-sm btn-outline-success mt-1" onclick="updateFirewall(<?php echo $firewall['id']; ?>)" title="Update Firewall">
                                            <i class="fas fa-sync-alt"></i>
                                        </button>
                                    <?php elseif ($firewall["last_update_check"] && $firewall["updates_available"] == 0): ?>
                                        <?php
                                        // Check if a major version upgrade is available
                                        $current_ver = $firewall['current_version'] ?: '';
                                        $upgrade_available = false;
                                        if (!empty($current_ver) && !empty($latest_major_version) && $current_ver !== 'Unknown') {
                                            // Compare major.minor branches (e.g., 25.7 vs 26.1)
                                            $cur_parts = explode('.', $current_ver);
                                            $lat_parts = explode('.', $latest_major_version);
                                            $cur_major = (isset($cur_parts[0]) ? (int)$cur_parts[0] : 0) * 100 + (isset($cur_parts[1]) ? (int)explode('_', $cur_parts[1])[0] : 0);
                                            $lat_major = (isset($lat_parts[0]) ? (int)$lat_parts[0] : 0) * 100 + (isset($lat_parts[1]) ? (int)explode('_', $lat_parts[1])[0] : 0);
                                            if ($lat_major > $cur_major) {
                                                $upgrade_available = true;
                                            }
                                        }

                                        if ($upgrade_available):
                                            // Major version upgrade available
                                            $upgrade_tooltip = "⬆️ MAJOR UPGRADE AVAILABLE\n";
                                            $upgrade_tooltip .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                                            $upgrade_tooltip .= "📦 CURRENT: " . htmlspecialchars($current_ver) . "\n";
                                            $upgrade_tooltip .= "📦 LATEST: " . htmlspecialchars($latest_major_version) . "\n\n";
                                            $upgrade_tooltip .= "🔍 STATUS:\n";
                                            $upgrade_tooltip .= "  • Patches current for " . htmlspecialchars(implode('.', array_slice(explode('.', $current_ver), 0, 2))) . " branch\n";
                                            $upgrade_tooltip .= "  • Major upgrade to " . htmlspecialchars(implode('.', array_slice(explode('.', $latest_major_version), 0, 2))) . " available\n\n";
                                            $upgrade_tooltip .= "🚀 ACTION:\n";
                                            $upgrade_tooltip .= "  • Upgrade via OPNsense web UI\n";
                                            $upgrade_tooltip .= "  • Firmware > Updates > Upgrade";
                                        ?>
                                        <span class="badge bg-info text-white hover-tooltip" data-tooltip="<?php echo htmlspecialchars($upgrade_tooltip); ?>">
                                            <i class="fas fa-arrow-circle-up me-1"></i>Upgrade
                                        </span>
                                        <button class="btn btn-sm btn-outline-info mt-1" onclick="updateFirewall(<?php echo $firewall['id']; ?>)" title="Upgrade Firewall to <?php echo htmlspecialchars($latest_major_version); ?>">
                                            <i class="fas fa-arrow-circle-up"></i>
                                        </button>
                                        <?php else:
                                            // Truly up to date
                                            $current_display = htmlspecialchars($current_ver ?: 'Unknown');
                                            $uptodate_tooltip = "✅ SYSTEM UP TO DATE\n";
                                            $uptodate_tooltip .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                                            $uptodate_tooltip .= "📦 CURRENT VERSION:\n";
                                            $uptodate_tooltip .= "  • OPNsense: " . $current_display . "\n\n";
                                            $uptodate_tooltip .= "🔍 UPDATE STATUS:\n";
                                            $last_check = date('M j, Y H:i:s', strtotime($firewall['last_update_check']));
                                            $uptodate_tooltip .= "  • Last Check: " . $last_check . "\n";
                                            $uptodate_tooltip .= "  • Status: No updates available\n\n";
                                            $uptodate_tooltip .= "🎯 SYSTEM STATE:\n";
                                            $uptodate_tooltip .= "  • Running latest stable release\n";
                                            $uptodate_tooltip .= "  • No action required";
                                        ?>
                                        <span class="badge bg-success text-white hover-tooltip" data-tooltip="<?php echo htmlspecialchars($uptodate_tooltip); ?>">
                                            <i class="fas fa-check me-1"></i>Up to date
                                        </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php
                                        // Build enhanced check needed tooltip
                                        $check_tooltip = "❓ UPDATE STATUS UNKNOWN\n";
                                        $check_tooltip .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                                        
                                        // Parse current version for better display
                                        $current_display = 'Unknown';
                                        if (!empty($firewall['current_version'])) {
                                            $version_data = json_decode($firewall['current_version'], true);
                                            if ($version_data && isset($version_data['product_version'])) {
                                                $current_display = $version_data['product_version'];
                                                if (isset($version_data['system_version'])) {
                                                    $current_display .= ' (' . $version_data['system_version'] . ')';
                                                }
                                            } else {
                                                $current_display = htmlspecialchars($firewall['current_version']);
                                            }
                                        }
                                        
                                        $check_tooltip .= "📦 CURRENT VERSION:\n";
                                        $check_tooltip .= "  • OPNsense: " . $current_display . "\n\n";
                                        
                                        $check_tooltip .= "🔍 UPDATE STATUS:\n";
                                        $last_check = $firewall['last_update_check'] ? date('M j, Y H:i:s', strtotime($firewall['last_update_check'])) : 'Never';
                                        $check_tooltip .= "  • Last Check: " . $last_check . "\n";
                                        $check_tooltip .= "  • Status: Unknown (check required)\n\n";
                                        
                                        $check_tooltip .= "🎯 ACTION NEEDED:\n";
                                        $check_tooltip .= "  • Click the search button to check for updates\n";
                                        $check_tooltip .= "  • This will query for available versions\n";
                                        $check_tooltip .= "  • Status will update after check completes";
                                        ?>
                                        <span class="badge bg-warning text-white hover-tooltip" data-tooltip="<?php echo htmlspecialchars($check_tooltip); ?>">
                                            <i class="fas fa-question me-1"></i>Check needed
                                        </span>
                                        <button class="btn btn-sm btn-outline-info mt-1" onclick="checkUpdates(<?php echo $firewall['id']; ?>)" title="Check for Updates">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <!-- Reboot Required - clickable badge (hidden during active updates) -->
                                    <?php if (isset($firewall["reboot_required"]) && $firewall["reboot_required"] == 1
                                        && !in_array($firewall["status"], ['updating', 'update_pending'])): ?>
                                        <div class="reboot-badge-wrapper mt-1">
                                            <span class="badge bg-danger text-white hover-tooltip" style="cursor:pointer;" role="button" onclick="rebootFirewall(<?php echo $firewall['id']; ?>, '<?php echo htmlspecialchars($firewall['hostname'], ENT_QUOTES); ?>')" data-tooltip="Click to reboot this firewall">
                                                <i class="fas fa-power-off me-1"></i>Reboot Required
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Status Column -->
                                <td>
                                    <?php
                                        // Use the agent status if available, otherwise determine from checkin
                                        if (!empty($firewall["agent_status"])) {
                                            $status = $firewall["agent_status"];
                                        } else {
                                            $status = "unknown";
                                        }
                                        
                                        // Override with offline if last checkin is too old
                                        if (!empty($firewall["agent_last_checkin"])) {
                                            $checkin = new DateTime($firewall["agent_last_checkin"]);
                                            $now = new DateTime();
                                            $diff = $now->diff($checkin);
                                            
                                            // Consider offline if not checked in for over 3 hours
                                            if ($diff->days > 0 || $diff->h >= 3) {
                                                $status = "offline";
                                            }
                                        } else {
                                            $status = "unknown";
                                        }
                                        
                                        // Override status for updating firewalls
                                        if (in_array($firewall['status'], ['updating', 'update_pending'])) {
                                            $status = $firewall['status'];
                                        }

                                        // Set badge style based on status
                                        switch($status) {
                                            case "online":
                                                $statusClass = "bg-success text-white";
                                                $statusText = "Online";
                                                break;
                                            case "updating":
                                                $statusClass = "bg-primary text-white";
                                                $statusText = '<i class="fas fa-sync-alt fa-spin me-1"></i>Updating';
                                                break;
                                            case "update_pending":
                                                $statusClass = "bg-info text-white";
                                                $statusText = '<i class="fas fa-clock me-1"></i>Update Queued';
                                                break;
                                            case "offline":
                                                $statusClass = "bg-danger text-white";
                                                $statusText = "Offline";
                                                break;
                                            default:
                                                $statusClass = "bg-warning text-white";
                                                $statusText = "Unknown";
                                        }
                                    ?>
                                    <span class="badge <?php echo $statusClass; ?>">
                                        <?php echo $statusText; ?>
                                    </span>
                                </td>
                                
                                <!-- Actions Column -->
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="viewFirewall(<?php echo $firewall["id"]; ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-success btn-sm" onclick="connectFirewall(<?php echo $firewall["id"]; ?>)" title="Connect to Firewall">
                                            <i class="fas fa-external-link-alt"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="confirmDelete(<?php echo $firewall["id"]; ?>)" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Controls -->
            <?php
            // Calculate pagination data
            $total_query = str_replace('SELECT f.*, fa.agent_version, fa.status as agent_status, fa.last_checkin as agent_last_checkin, fa.opnsense_version', 'SELECT COUNT(*)', $query);
            $total_stmt = db()->prepare($total_query);
            $total_stmt->execute($params);
            $total_firewalls = $total_stmt->fetchColumn();
            $total_pages = ceil($total_firewalls / $per_page);
            $start_item = ($page - 1) * $per_page + 1;
            $end_item = min($page * $per_page, $total_firewalls);
            ?>
            
            <div class="pagination-controls">
                <div class="pagination-info">
                    Showing <?php echo $start_item; ?>-<?php echo $end_item; ?> of <?php echo $total_firewalls; ?> firewalls
                </div>
                
                <div class="d-flex align-items-center gap-3">
                    <div class="page-size-selector">
                        <label for="per_page" class="form-label text-muted me-2" style="font-size: 0.8rem;">Items per page:</label>
                        <select id="per_page" name="per_page" onchange="changePageSize(this.value)">
                            <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10</option>
                            <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100</option>
                            <option value="200" <?php echo $per_page == 200 ? 'selected' : ''; ?>>200</option>
                        </select>
                    </div>
                    
                    <nav aria-label="Firewall pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page-1; ?>&per_page=<?php echo $per_page; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&tag=<?php echo urlencode($tag_filter); ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>">&laquo; Previous</a>
                                </li>
                            <?php endif; ?>
                            
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&per_page=<?php echo $per_page; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&tag=<?php echo urlencode($tag_filter); ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page+1; ?>&per_page=<?php echo $per_page; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&tag=<?php echo urlencode($tag_filter); ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>">Next &raquo;</a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            </div>

            <?php if (empty($firewalls)): ?>
                <div class="text-center mt-4">
                    <p class="text-light">No firewalls found matching your criteria.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Toast notification helper
function showToast(message, type = 'info') {
    // Remove existing toasts
    document.querySelectorAll('.toast-notification').forEach(t => t.remove());
    const toast = document.createElement('div');
    toast.className = 'toast-notification';
    const bgColor = type === 'success' ? '#198754' : type === 'danger' ? '#dc3545' : '#0d6efd';
    const icon = type === 'success' ? 'fa-check-circle' : type === 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle';
    toast.style.cssText = `position:fixed;top:20px;right:20px;z-index:9999;padding:12px 20px;border-radius:8px;color:#fff;background:${bgColor};box-shadow:0 4px 12px rgba(0,0,0,0.3);font-size:0.9rem;max-width:400px;animation:slideIn 0.3s ease;`;
    toast.innerHTML = `<i class="fas ${icon} me-2"></i>${message}`;
    document.body.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity 0.5s'; setTimeout(() => toast.remove(), 500); }, 5000);
}

// Check for updates on a firewall
function checkUpdates(firewallId) {
    const button = event.target.closest('button') || event.target;
    const cell = button.closest('td');
    const originalCellHTML = cell.innerHTML;

    cell.innerHTML = `
        <span class="badge bg-info text-white">
            <i class="fas fa-search fa-pulse me-1"></i>Checking...
        </span>
        <div class="progress mt-1" style="height: 3px; max-width: 120px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated bg-info" style="width: 100%"></div>
        </div>`;

    fetch('/api/check_updates.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            firewall_id: firewallId,
            csrf: "<?php echo csrf_token(); ?>"
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Update check triggered. Status will refresh on next agent check-in.', 'success');
            setTimeout(() => location.reload(), 2000);
        } else {
            cell.innerHTML = originalCellHTML;
            showToast('Error: ' + (data.message || 'Failed to check updates'), 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        cell.innerHTML = originalCellHTML;
        showToast('Error checking updates.', 'danger');
    });
}

// Reboot a firewall
function rebootFirewall(firewallId, hostname) {
    if (!confirm(`Are you sure you want to reboot ${hostname}?\n\nThe firewall will go offline for 1-3 minutes during reboot.`)) {
        return;
    }

    const button = event.target.closest('button') || event.target;
    const badge = button.closest('td').querySelector('.reboot-badge-wrapper');
    const originalHTML = badge ? badge.innerHTML : '';

    if (badge) {
        badge.innerHTML = `
            <span class="badge bg-warning text-white">
                <i class="fas fa-sync-alt fa-spin me-1"></i>Rebooting...
            </span>`;
    }

    fetch('/api/reboot_firewall.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            firewall_id: firewallId,
            csrf: "<?php echo csrf_token(); ?>"
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Reboot queued. Firewall will reboot within ~3 minutes.', 'success');
            setTimeout(() => location.reload(), 2000);
        } else {
            if (badge) badge.innerHTML = originalHTML;
            showToast('Error: ' + (data.message || 'Failed to queue reboot'), 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (badge) badge.innerHTML = originalHTML;
        showToast('Error sending reboot command.', 'danger');
    });
}

function applyFilters() {
    const search = document.getElementById('searchInput').value;
    const status = document.getElementById('statusFilter').value;
    const tag = document.getElementById('tagFilter').value;
    const sortBy = document.getElementById('sortBy').value;
    
    let url = 'firewalls.php?';
    if (search) url += 'search=' + encodeURIComponent(search) + '&';
    if (status) url += 'status=' + encodeURIComponent(status) + '&';
    if (tag) url += 'tag=' + encodeURIComponent(tag) + '&';
    url += 'sort=' + sortBy;
    
    window.location.href = url;
}

function changePageSize(pageSize) {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('per_page', pageSize);
    urlParams.set('page', 1); // Reset to first page when changing page size
    window.location.href = window.location.pathname + '?' + urlParams.toString();
}

function filterTable() {
    const searchValue = document.getElementById("searchInput").value.toLowerCase();
    const statusFilter = document.getElementById("statusFilter").value;
    const table = document.querySelector("table tbody");
    const rows = table.querySelectorAll("tr");

    rows.forEach(row => {
        const cells = row.querySelectorAll("td");
        if (cells.length === 0) return;

        const hostname = cells[0].textContent.toLowerCase();
        const lanIp = cells[1].textContent.toLowerCase();
        const customer = cells[4].textContent.toLowerCase();
        const status = row.querySelector(".badge").textContent.toLowerCase();

        const matchesSearch = searchValue === "" || 
            hostname.includes(searchValue) || 
            lanIp.includes(searchValue) || 
            customer.includes(searchValue);

        const matchesStatus = statusFilter === "" || 
            (statusFilter === "online" && status.includes("online")) || 
            (statusFilter === "offline" && status.includes("offline"));

        if (matchesSearch && matchesStatus) {
            row.style.display = "";
        } else {
            row.style.display = "none";
        }
    });
}

document.getElementById("searchInput").addEventListener("keypress", function(e) {
    if (e.key === "Enter") {
        applyFilters();
    }
});

document.getElementById("searchInput").addEventListener("input", function() {
    filterTable();
});

function viewFirewall(id) {
    window.location.href = '/firewall_details.php?id=' + id;
}

function confirmDelete(id) {
    if (confirm("Are you sure you want to delete this firewall? This action cannot be undone.")) {
        deleteFirewall(id);
    }
}

function connectFirewall(id) {
    window.open(`firewall_proxy_ondemand.php?id=${id}`, '_blank', 'width=800,height=600,resizable=yes,scrollbars=yes');
}

function deleteFirewall(id) {
    const formData = new FormData();
    formData.append("delete_id", id);
    formData.append("csrf", "<?php echo csrf_token(); ?>");
    
    fetch("/api/delete_firewall.php", {
        method: "POST",
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('firewall-row-' + id).remove();
        } else {
            alert('Error deleting firewall: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error deleting firewall');
    });
}

function updateAllFirewalls() {
    if (!confirm("This will trigger updates on all firewalls. This may take several minutes. Continue?")) {
        return;
    }
    
    const button = event.target;
    const originalText = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Updating...';
    
    fetch('/api/update_all_firewalls.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            csrf: "<?php echo csrf_token(); ?>"
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`Update initiated for ${data.count} firewalls. Check individual firewall status for progress.`);
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to initiate updates'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error initiating updates');
    })
    .finally(() => {
        button.disabled = false;
        button.innerHTML = originalText;
    });
}

function updateFirewall(firewallId) {
    if (!confirm("This will trigger an OPNsense update on this firewall. The firewall will update automatically and reboot if necessary.\n\nContinue?")) {
        return;
    }

    const button = event.target.closest('button') || event.target;
    const cell = button.closest('td');
    const originalCellHTML = cell.innerHTML;

    // Show updating animation immediately in the cell
    cell.innerHTML = `
        <div class="updating-animation">
            <span class="badge bg-primary text-white">
                <i class="fas fa-sync-alt fa-spin me-1"></i>Updating...
            </span>
            <div class="progress mt-1" style="height: 4px; max-width: 120px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width: 100%"></div>
            </div>
            <small class="text-muted d-block mt-1" style="font-size: 0.65rem;">Sending update request...</small>
        </div>`;

    fetch('/api/update_firewall.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            firewall_id: firewallId,
            csrf: "<?php echo csrf_token(); ?>"
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update the small text to show success
            const small = cell.querySelector('small');
            if (small) small.textContent = 'Waiting for agent pickup...';
            showToast('Update queued successfully. The firewall will update on next agent check-in.', 'success');
            // Reload after 2 seconds to show updated status
            setTimeout(() => location.reload(), 2000);
        } else {
            cell.innerHTML = originalCellHTML;
            showToast('Error: ' + (data.message || 'Failed to initiate update'), 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        cell.innerHTML = originalCellHTML;
        showToast('Error initiating update. Check console for details.', 'danger');
    });
}

// Auto-refresh functionality
let autoRefreshInterval = null;

function setAutoRefresh() {
    const select = document.getElementById('autoRefreshSelect');
    const seconds = parseInt(select.value);
    
    // Clear existing interval
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
    
    // Set new interval if selected
    if (seconds > 0) {
        autoRefreshInterval = setInterval(() => {
            location.reload();
        }, seconds * 1000);
        
        // Store preference in localStorage
        localStorage.setItem('firewallsAutoRefresh', seconds);
    } else {
        localStorage.removeItem('firewallsAutoRefresh');
    }
}

// Load auto-refresh preference on page load
document.addEventListener('DOMContentLoaded', function() {
    const savedRefresh = localStorage.getItem('firewallsAutoRefresh');
    if (savedRefresh) {
        const select = document.getElementById('autoRefreshSelect');
        select.value = savedRefresh;
        setAutoRefresh();
    }
});

// Better tooltip positioning
document.addEventListener('DOMContentLoaded', function() {
    const tooltips = document.querySelectorAll('.hover-tooltip');
    
    tooltips.forEach(tooltip => {
        tooltip.addEventListener('mouseenter', function(e) {
            const tooltipText = this.getAttribute('data-tooltip');
            if (!tooltipText) return;
            
            // Create tooltip element
            const tooltipEl = document.createElement('div');
            tooltipEl.className = 'custom-tooltip';
            tooltipEl.textContent = tooltipText;
            tooltipEl.style.cssText = `
                position: fixed;
                background: linear-gradient(135deg, rgba(0,0,0,0.95) 0%, rgba(20,20,20,0.95) 100%);
                color: #ffffff;
                padding: 0.75rem;
                border-radius: 8px;
                font-size: 0.75rem;
                font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
                line-height: 1.4;
                z-index: 10000;
                box-shadow: 0 8px 24px rgba(0,0,0,0.5), 0 0 0 1px rgba(255,255,255,0.1);
                max-width: 350px;
                min-width: 200px;
                word-wrap: break-word;
                white-space: pre-line;
                pointer-events: none;
                opacity: 0;
                transition: opacity 0.3s ease;
                border: 1px solid rgba(255,255,255,0.1);
                backdrop-filter: blur(10px);
            `;
            
            document.body.appendChild(tooltipEl);
            
            // Position tooltip
            const rect = this.getBoundingClientRect();
            const tooltipRect = tooltipEl.getBoundingClientRect();
            
            let left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);
            let top = rect.top - tooltipRect.height - 8;
            
            // Keep tooltip within viewport
            if (left < 10) left = 10;
            if (left + tooltipRect.width > window.innerWidth - 10) {
                left = window.innerWidth - tooltipRect.width - 10;
            }
            if (top < 10) {
                top = rect.bottom + 8; // Show below if no room above
            }
            
            tooltipEl.style.left = left + 'px';
            tooltipEl.style.top = top + 'px';
            tooltipEl.style.opacity = '1';
            
            // Store reference for cleanup
            this._customTooltip = tooltipEl;
        });
        
        tooltip.addEventListener('mouseleave', function() {
            if (this._customTooltip) {
                this._customTooltip.remove();
                this._customTooltip = null;
            }
        });
    });
});
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
