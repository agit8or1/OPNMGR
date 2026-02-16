<?php
// Force no caching - CRITICAL for debugging
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/agent_version.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: /firewalls.php');
    exit;
}

try {
    $stmt = db()->prepare('
        SELECT f.*,
               GROUP_CONCAT(t.name SEPARATOR ", ") as tag_names, GROUP_CONCAT(t.color SEPARATOR ", ") as tag_colors
        FROM firewalls f
        LEFT JOIN firewall_tags ft ON f.id = ft.firewall_id
        LEFT JOIN tags t ON ft.tag_id = t.id
        WHERE f.id = ?
        GROUP BY f.id
    ');
    $stmt->execute([$id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Fetch agent details
    $agent_stmt = db()->prepare('SELECT agent_version, status, last_checkin FROM firewall_agents WHERE firewall_id = ? AND agent_type = ? LIMIT 1');
    $agent_stmt->execute([$id, 'primary']);
    $agent = $agent_stmt->fetch(PDO::FETCH_ASSOC);

    // Add agent data to firewall
    if ($agent) {
        $firewall['agent_version'] = $agent['agent_version'];
        $firewall['agent_status'] = $agent['status'];
        $firewall['agent_last_checkin'] = $agent['last_checkin'];
    }

    // Fetch all unique companies for dropdown
    $companies_stmt = db()->query('SELECT DISTINCT customer_group FROM firewalls WHERE customer_group IS NOT NULL AND customer_group != "" ORDER BY customer_group');
    $all_companies = $companies_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch all tags for dropdown
    $tags_stmt = db()->query('SELECT id, name, color FROM tags ORDER BY name');
    $all_tags = $tags_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get both agent types separately
    $stmt = db()->prepare('
        SELECT agent_type, agent_version, last_checkin, status,
               TIMESTAMPDIFF(SECOND, last_checkin, NOW()) as seconds_ago, wan_ip, lan_ip
        FROM firewall_agents
        WHERE firewall_id = ?
        ORDER BY agent_type
    ');
    $stmt->execute([$id]);
    $agents = [];
    while ($agent = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $agents[$agent['agent_type']] = $agent;
    }
} catch (Exception $e) {
    $firewall = null;
    $agents = [];
}

// Fetch WAN interface stats
$wan_stats = [];
try {
    $wan_stmt = db()->prepare('SELECT * FROM firewall_wan_interfaces WHERE firewall_id = ? ORDER BY interface_name');
    $wan_stmt->execute([$id]);
    $wan_stats = $wan_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist yet
}

if (!$firewall) {
    header('Location: /firewalls.php');
    exit;
}

// Get log analysis statistics for this firewall
$log_analysis_stats = [
    'total_analyses' => 0,
    'total_threats' => 0,
    'total_blocks' => 0,
    'total_failed_auth' => 0,
    'avg_anomaly_score' => 0
];

try {
    $stats_query = "SELECT
        COUNT(DISTINCT asr.id) as total_analyses,
        SUM(lar.active_threats IS NOT NULL AND JSON_LENGTH(lar.active_threats) > 0) as total_threats,
        SUM(lar.blocked_attempts) as total_blocks,
        SUM(lar.failed_auth_attempts) as total_failed_auth,
        AVG(lar.anomaly_score) as avg_anomaly_score
    FROM ai_scan_reports asr
    LEFT JOIN log_analysis_results lar ON asr.id = lar.report_id
    WHERE asr.firewall_id = ? AND asr.scan_type = 'config_with_logs' AND asr.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

    $stats_stmt = db()->prepare($stats_query);
    $stats_stmt->execute([$id]);
    $log_analysis_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC) ?: $log_analysis_stats;
} catch (Exception $e) {
    error_log("Error fetching log analysis stats: " . $e->getMessage());
}

// Show success message after redirect
if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $notice = 'Configuration updated successfully. Tags and settings have been saved.';
}

// Handle configuration updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_config'])) {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $notice = 'CSRF verification failed.';
    } else {
        $checkin_interval = (int)($_POST['checkin_interval'] ?? 180);
        $checkin_interval = max(30, min(3600, $checkin_interval));
        $speedtest_interval = (int)($_POST['speedtest_interval_hours'] ?? 4);
        if (!in_array($speedtest_interval, [0, 2, 4, 8, 12, 24])) {
            $speedtest_interval = 4;
        }
        $customer_name = trim($_POST['customer_name'] ?? '');
        $customer_group = trim($_POST['customer_group'] ?? '');

        // Handle tags - support both multi-select array and comma-separated string
        $tags_array = $_POST['tags'] ?? [];
        if (is_array($tags_array)) {
            $tags_list = array_filter(array_map('trim', $tags_array));
        } else {
            $tags_list = array_filter(array_map('trim', explode(',', $tags_array)));
        }

        $allowed_webgui_ips = trim($_POST['allowed_webgui_ips'] ?? '');

        try {
            // Update firewall basic fields
            $stmt = db()->prepare('UPDATE firewalls SET checkin_interval = ?, speedtest_interval_hours = ?, customer_name = ?, customer_group = ?, allowed_webgui_ips = ? WHERE id = ?');
            $stmt->execute([$checkin_interval, $speedtest_interval, $customer_name, $customer_group, $allowed_webgui_ips, $id]);

            // Handle tags - clear existing and insert new ones
            $stmt = db()->prepare('DELETE FROM firewall_tags WHERE firewall_id = ?');
            $stmt->execute([$id]);

            foreach ($tags_list as $tag_name) {
                // Get or create tag
                $stmt = db()->prepare('SELECT id FROM tags WHERE name = ?');
                $stmt->execute([$tag_name]);
                $tag_id = $stmt->fetchColumn();

                if (!$tag_id) {
                    // Create new tag with random color
                    $colors = ['#007bff', '#28a745', '#dc3545', '#ffc107', '#17a2b8', '#6f42c1', '#e83e8c', '#fd7e14'];
                    $color = $colors[array_rand($colors)];
                    $stmt = db()->prepare('INSERT INTO tags (name, color) VALUES (?, ?)');
                    $stmt->execute([$tag_name, $color]);
                    $tag_id = db()->lastInsertId();
                }

                // Link tag to firewall
                $stmt = db()->prepare('INSERT IGNORE INTO firewall_tags (firewall_id, tag_id) VALUES (?, ?)');
                $stmt->execute([$id, $tag_id]);
            }

            error_log("Tags saved for FW$id: " . implode(', ', $tags_list) . " (" . count($tags_list) . " tags)");

            // Queue command to apply web GUI IP lockdown if changed
            if ($allowed_webgui_ips !== ($firewall['allowed_webgui_ips'] ?? '')) {
                require_once __DIR__ . '/scripts/queue_command.php';
                queue_command($id, '/tmp/configure_webgui_access.sh', 'Configure Web GUI IP Access');
            }

            // Log the update
            error_log("Configuration updated for firewall ID $id: checkin_interval=$checkin_interval, speedtest_interval={$speedtest_interval}h, customer_group=$customer_group, tags=" . implode(',', $tags_list) . ", webgui_ips=$allowed_webgui_ips");

            // Redirect to same page to show updated data (Post/Redirect/Get pattern)
            header('Location: /firewall_details.php?id=' . $id . '&updated=1');
            exit;
        } catch (Exception $e) {
            error_log("firewall_details.php error: " . $e->getMessage());
            $notice = 'An internal error occurred while updating configuration.';
        }
    }
}

include __DIR__ . '/inc/header.php';
?>

            <div class="card card-dark">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <small class="text-light fw-bold mb-0">
                            <i class="fas fa-server me-1"></i>Firewall Details: <?php echo htmlspecialchars($firewall['hostname']); ?>
                        </small>
                        <div class="ms-auto">
                            <a href="/firewalls.php" class="btn btn-outline-light btn-sm">
                                <i class="fas fa-arrow-left me-1"></i>Back to Firewalls
                            </a>
                            <a href="firewall_edit.php?id=<?php echo $firewall['id']; ?>" class="btn btn-warning btn-sm ms-1">
                                <i class="fas fa-edit me-1"></i>Edit
                            </a>
                            <button onclick="rebootFirewall()" class="btn btn-danger btn-sm ms-1" id="rebootBtn">
                                <i class="fas fa-power-off me-1"></i>Reboot
                            </button>
                            <button onclick="resetAgent()" class="btn btn-info btn-sm ms-1" id="resetAgentBtn" title="Force agent to restart">
                                <i class="fas fa-sync me-1"></i>Reset Agent
                            </button>
                        </div>
                    </div>
                    
                    <?php if (isset($notice)): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($notice); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Navigation Tabs -->
                    <ul class="nav nav-tabs mb-4" id="firewallTabs" role="tablist" style="border-bottom: 2px solid #3498db;">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab">
                                <i class="fas fa-dashboard me-2"></i>Overview
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="backups-tab" data-bs-toggle="tab" data-bs-target="#backups" type="button" role="tab">
                                <i class="fas fa-save me-2"></i>Backups
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="ai-tab" data-bs-toggle="tab" data-bs-target="#ai" type="button" role="tab">
                                <i class="fas fa-brain me-2"></i>AI Analysis
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="network-tab" data-bs-toggle="tab" data-bs-target="#network" type="button" role="tab">
                                <i class="fas fa-network-wired me-2"></i>Network Tools
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="commands-tab" data-bs-toggle="tab" data-bs-target="#commands" type="button" role="tab">
                                <i class="fas fa-terminal me-2"></i>Command Log
                            </button>
                        </li>

                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button" role="tab">
                                <i class="fas fa-lock me-2"></i>Security
                            </button>
                        </li>
                    
                    <!-- Tab Content -->
                    <div class="tab-content" id="firewallTabsContent">
                        <!-- Overview Tab -->
                        <div class="tab-pane fade show active" id="overview" role="tabpanel">
                    
                    <!-- System Statistics -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card card-ghost p-4 border border-secondary">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="text-white fw-bold mb-0" style="font-size: 1.1rem;">
                                        <i class="fas fa-chart-area me-2"></i>System Statistics
                                    </h6>
                                    <div>
                                        <label for="timeFrameSelect" class="text-white-50 me-2" style="font-size: 0.9rem;">Time Frame:</label>
                                        <select id="timeFrameSelect" class="form-select form-select-sm d-inline-block" style="width: auto; background-color: #1a1a1a; color: #fff; border-color: #444;" onchange="updateCharts()">
                                            <option value="1" selected>24 Hours</option>
                                            <option value="7">1 Week</option>
                                            <option value="30">30 Days</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <!-- Traffic Graph -->
                                    <div class="col-md-6 mb-3">
                                        <div class="p-3 rounded" style="background-color: #1a1a1a !important;">
                                            <h6 class="text-white-50 mb-2" style="font-size: 0.9rem;">
                                                <i class="fas fa-network-wired me-1"></i> WAN Traffic <span id="trafficTimeLabel"></span>
                                            </h6>
                                            <div style="position: relative; height: 200px;">
                                                <canvas id="trafficChart"></canvas>
                                            </div>
                                            <!-- Stats Section -->
                                            <div class="mt-3 pt-3" style="border-top: 1px solid rgba(255,255,255,0.1);">
                                                <div class="row text-center">
                                                    <div class="col-6">
                                                        <div class="mb-2">
                                                            <span class="text-success" style="font-size: 0.75rem; font-weight: 600;">Inbound</span>
                                                        </div>
                                                        <div style="font-size: 0.7rem; color: #cbd5e0;">
                                                            <span class="me-2" style="color: #a0aec0;">Avg: <span id="trafficInAvg" class="text-white" style="font-weight: 500;">--</span></span>
                                                            <span class="me-2" style="color: #a0aec0;">Peak: <span id="trafficInPeak" class="text-white" style="font-weight: 500;">--</span></span>
                                                            <span style="color: #a0aec0;">Low: <span id="trafficInLow" class="text-white" style="font-weight: 500;">--</span></span>
                                                        </div>
                                                    </div>
                                                    <div class="col-6">
                                                        <div class="mb-2">
                                                            <span class="text-primary" style="font-size: 0.75rem; font-weight: 600;">Outbound</span>
                                                        </div>
                                                        <div style="font-size: 0.7rem; color: #cbd5e0;">
                                                            <span class="me-2" style="color: #a0aec0;">Avg: <span id="trafficOutAvg" class="text-white" style="font-weight: 500;">--</span></span>
                                                            <span class="me-2" style="color: #a0aec0;">Peak: <span id="trafficOutPeak" class="text-white" style="font-weight: 500;">--</span></span>
                                                            <span style="color: #a0aec0;">Low: <span id="trafficOutLow" class="text-white" style="font-weight: 500;">--</span></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- CPU Graph -->
                                    <div class="col-md-6 mb-3">
                                        <div class="p-3 rounded" style="background-color: #1a1a1a !important;">
                                            <h6 class="text-white-50 mb-2" style="font-size: 0.9rem;">
                                                <i class="fas fa-microchip me-1"></i> CPU Usage
                                            </h6>
                                            <div style="position: relative; height: 200px;">
                                                <canvas id="cpuChart"></canvas>
                                            </div>
                                            <!-- Stats Section -->
                                            <div class="mt-3 pt-3" style="border-top: 1px solid rgba(255,255,255,0.1);">
                                                <div class="text-center">
                                                    <div style="font-size: 0.7rem; color: #cbd5e0;">
                                                        <span class="me-3" style="color: #a0aec0;">Avg: <span id="cpuAvg" class="text-white" style="font-weight: 500;">--</span></span>
                                                        <span class="me-3" style="color: #a0aec0;">Peak: <span id="cpuPeak" class="text-white" style="font-weight: 500;">--</span></span>
                                                        <span style="color: #a0aec0;">Low: <span id="cpuLow" class="text-white" style="font-weight: 500;">--</span></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Memory Graph -->
                                    <div class="col-md-6 mb-3">
                                        <div class="p-3 rounded" style="background-color: #1a1a1a !important;">
                                            <h6 class="text-white-50 mb-2" style="font-size: 0.9rem;">
                                                <i class="fas fa-memory me-1"></i> Memory Usage
                                            </h6>
                                            <div style="position: relative; height: 200px;">
                                                <canvas id="memoryChart"></canvas>
                                            </div>
                                            <!-- Stats Section -->
                                            <div class="mt-3 pt-3" style="border-top: 1px solid rgba(255,255,255,0.1);">
                                                <div class="text-center">
                                                    <div style="font-size: 0.7rem; color: #cbd5e0;">
                                                        <span class="me-3" style="color: #a0aec0;">Avg: <span id="memoryAvg" class="text-white" style="font-weight: 500;">--</span></span>
                                                        <span class="me-3" style="color: #a0aec0;">Peak: <span id="memoryPeak" class="text-white" style="font-weight: 500;">--</span></span>
                                                        <span style="color: #a0aec0;">Low: <span id="memoryLow" class="text-white" style="font-weight: 500;">--</span></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Disk Graph -->
                                    <div class="col-md-6 mb-3">
                                        <div class="p-3 rounded" style="background-color: #1a1a1a !important;">
                                            <h6 class="text-white-50 mb-2" style="font-size: 0.9rem;">
                                                <i class="fas fa-hdd me-1"></i> Disk Usage
                                            </h6>
                                            <div style="position: relative; height: 200px;">
                                                <canvas id="diskChart"></canvas>
                                            </div>
                                            <!-- Stats Section -->
                                            <div class="mt-3 pt-3" style="border-top: 1px solid rgba(255,255,255,0.1);">
                                                <div class="text-center">
                                                    <div style="font-size: 0.7rem; color: #cbd5e0;">
                                                        <span class="me-3" style="color: #a0aec0;">Avg: <span id="diskAvg" class="text-white" style="font-weight: 500;">--</span></span>
                                                        <span class="me-3" style="color: #a0aec0;">Peak: <span id="diskPeak" class="text-white" style="font-weight: 500;">--</span></span>
                                                        <span style="color: #a0aec0;">Low: <span id="diskLow" class="text-white" style="font-weight: 500;">--</span></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Latency Graph -->
                                    <div class="col-md-6 mb-3">
                                        <div class="p-3 rounded" style="background-color: #1a1a1a !important;">
                                            <h6 class="text-white-50 mb-2" style="font-size: 0.9rem;">
                                                <i class="fas fa-stopwatch me-1"></i> Latency (ms) <span id="latencyTimeLabel" class="text-muted small"></span>
                                            </h6>
                                            <div style="position: relative; height: 200px;">
                                                <canvas id="latencyChart"></canvas>
                                            </div>
                                            <!-- Stats Section -->
                                            <div class="mt-3 pt-3" style="border-top: 1px solid rgba(255,255,255,0.1);">
                                                <div class="text-center">
                                                    <div style="font-size: 0.7rem; color: #cbd5e0;">
                                                        <span class="me-3" style="color: #a0aec0;">Avg: <span id="latencyAvg" class="text-white" style="font-weight: 500;">--</span></span>
                                                        <span class="me-3" style="color: #a0aec0;">Peak: <span id="latencyPeak" class="text-white" style="font-weight: 500;">--</span></span>
                                                        <span style="color: #a0aec0;">Low: <span id="latencyLow" class="text-white" style="font-weight: 500;">--</span></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- SpeedTest Graph -->
                                    <div class="col-md-6 mb-3">
                                        <div class="p-3 rounded" style="background-color: #1a1a1a !important;">
                                            <div style="display: flex; justify-content: space-between; align-items: center; mb-2;">
                                                <h6 class="text-white-50 mb-0" style="font-size: 0.9rem;">
                                                    <i class="fas fa-tachometer-alt me-1"></i> SpeedTest Results (Mbps) <span id="speedtestTimeLabel" class="text-muted small"></span>
                                                </h6>
                                                <button id="speedtestButton" class="btn btn-sm btn-outline-info" onclick="triggerSpeedtest()" style="padding: 0.25rem 0.75rem; font-size: 0.85rem;">
                                                    <i class="fas fa-play me-1"></i> Run Now
                                                </button>
                                            </div>
                                            <div style="position: relative; height: 250px;">
                                                <canvas id="speedtestChart"></canvas>
                                            </div>
                                            <!-- Stats Section -->
                                            <div class="mt-3 pt-3" style="border-top: 1px solid rgba(255,255,255,0.1);">
                                                <div class="row text-center">
                                                    <div class="col-6">
                                                        <div class="mb-2">
                                                            <span class="text-success" style="font-size: 0.75rem; font-weight: 600;">Download</span>
                                                        </div>
                                                        <div style="font-size: 0.7rem; color: #cbd5e0;">
                                                            <span class="me-2" style="color: #a0aec0;">Avg: <span id="downloadAvg" class="text-white" style="font-weight: 500;">--</span></span>
                                                            <span class="me-2" style="color: #a0aec0;">Peak: <span id="downloadPeak" class="text-white" style="font-weight: 500;">--</span></span>
                                                            <span style="color: #a0aec0;">Low: <span id="downloadLow" class="text-white" style="font-weight: 500;">--</span></span>
                                                        </div>
                                                    </div>
                                                    <div class="col-6">
                                                        <div class="mb-2">
                                                            <span class="text-warning" style="font-size: 0.75rem; font-weight: 600;">Upload</span>
                                                        </div>
                                                        <div style="font-size: 0.7rem; color: #cbd5e0;">
                                                            <span class="me-2" style="color: #a0aec0;">Avg: <span id="uploadAvg" class="text-white" style="font-weight: 500;">--</span></span>
                                                            <span class="me-2" style="color: #a0aec0;">Peak: <span id="uploadPeak" class="text-white" style="font-weight: 500;">--</span></span>
                                                            <span style="color: #a0aec0;">Low: <span id="uploadLow" class="text-white" style="font-weight: 500;">--</span></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Basic Information -->
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="card card-ghost p-4 border border-secondary">
                                <h6 class="text-white fw-bold mb-3" style="font-size: 1.1rem;">Basic Information</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-2">
                                            <strong class="text-white">Hostname:</strong>
                                            <span class="text-light"><?php echo htmlspecialchars($firewall['hostname']); ?></span>
                                        </div>
                                        <div class="mb-2">
                                            <strong class="text-white">IP Address:</strong>
                                            <span class="text-light"><?php echo htmlspecialchars($firewall['wan_ip'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div class="mb-2">
                                            <strong class="text-white">Hardware ID:</strong>
                                            <span class="text-light"><?php echo htmlspecialchars($firewall['hardware_id'] ?? 'Not set'); ?></span>
                                        </div>
                                        <div class="mb-2">
                                            <strong class="text-white">Customer:</strong>
                                            <span class="text-light"><?php echo htmlspecialchars($firewall['customer_name'] ?? 'N/A'); ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-2">
                                            <strong class="text-white">Status:</strong>
                                            <?php
                                            $status = $firewall['agent_status'] ?? $firewall['status'] ?? 'unknown';
                                            $status_class = $status === 'online' ? 'text-success' : ($status === 'offline' ? 'text-danger' : 'text-warning');
                                            ?>
                                            <span class="<?php echo $status_class; ?> fw-bold" style="font-size: 1.1rem;"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                                        </div>
                                        <div class="mb-2">
                                            <strong class="text-white">Version:</strong>
                                            <span class="text-light"><?php echo htmlspecialchars($firewall['agent_version'] ?? $firewall['version'] ?? 'N/A'); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Agent Information -->
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="card card-ghost p-4 border border-secondary">
                                <h6 class="text-white fw-bold mb-3" style="font-size: 1.1rem;">Agent Information</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <?php if (isset($agents['primary'])): 
                                            $primary = $agents['primary'];
                                            $status_class = $primary['status'] === 'online' ? 'success' : 'danger';
                                            $status_dot = $primary['status'] === 'online' ? '●' : '○';
                                        ?>
                                        <div class="mb-3">
                                            <strong class="text-white">
                                                <span class="text-<?php echo $status_class; ?>"><?php echo $status_dot; ?></span>
                                                Primary Agent:
                                            </strong><br>
                                            <span class="text-light">Version: <?php echo htmlspecialchars($primary['agent_version']); ?></span>
                                            <?php
                                            $update_available = isUpdateAvailable($primary['agent_version']);
                                            if ($update_available):
                                            ?>
                                                <span class="badge bg-warning text-white ms-2" style="font-size: 0.75rem;">
                                                    <i class="fa fa-arrow-circle-up"></i> Update Available (<?php echo LATEST_AGENT_VERSION; ?>)
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-success ms-2" style="font-size: 0.75rem;">
                                                    <i class="fa fa-check-circle"></i> Up to date
                                                </span>
                                            <?php endif; ?>
                                            <br>
                                            <small class="text-muted">Last checkin: <?php echo $primary['seconds_ago']; ?>s ago</small>
                                            <?php if ($update_available): ?>
                                                <br>
                                                <button class="btn btn-sm btn-warning mt-2" onclick="updateAgent(<?php echo $id; ?>)">
                                                    <i class="fa fa-refresh"></i> Update Agent to <?php echo LATEST_AGENT_VERSION; ?>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <?php else: ?>
                                        <div class="mb-3">
                                            <strong class="text-white"><span class="text-danger">○</span> Primary Agent:</strong><br>
                                            <span class="text-light">Not connected</span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="mb-2">
                                            <strong class="text-white">WAN IP:</strong>
                                            <span class="text-light"><?php echo htmlspecialchars(($agents['primary']['wan_ip'] ?? $firewall['wan_ip']) ?? 'N/A'); ?></span>
                                        </div>
                                        <?php if (!empty($firewall['wan_netmask']) || !empty($firewall['wan_gateway'])): ?>
                                        <div class="mb-2">
                                            <strong class="text-white">WAN Subnet:</strong>
                                            <span class="text-light"><?php echo htmlspecialchars($firewall['wan_netmask'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div class="mb-2">
                                            <strong class="text-white">WAN Gateway:</strong>
                                            <span class="text-light"><?php echo htmlspecialchars($firewall['wan_gateway'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div class="mb-2">
                                            <strong class="text-white">WAN DNS Primary:</strong>
                                            <span class="text-light"><?php echo htmlspecialchars($firewall['wan_dns_primary'] ?? 'N/A'); ?></span>
                                        </div>
                                        <?php if (!empty($firewall['wan_dns_secondary'])): ?>
                                        <div class="mb-2">
                                            <strong class="text-white">WAN DNS Secondary:</strong>
                                            <span class="text-light"><?php echo htmlspecialchars($firewall['wan_dns_secondary']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                        <div class="mb-2">
                                            <strong class="text-white">LAN IP:</strong>
                                            <span class="text-light"><?php echo htmlspecialchars(($agents['primary']['lan_ip'] ?? $firewall['lan_ip'] ?? $firewall['ip_address']) ?? 'N/A'); ?></span>
                                        </div>
                                        <?php if (!empty($firewall['lan_netmask']) || !empty($firewall['lan_network'])): ?>
                                        <div class="mb-2">
                                            <strong class="text-white">LAN Subnet:</strong>
                                            <span class="text-light"><?php echo htmlspecialchars($firewall['lan_netmask'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div class="mb-2">
                                            <strong class="text-white">LAN Network:</strong>
                                            <span class="text-light"><?php echo htmlspecialchars($firewall['lan_network'] ?? 'N/A'); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!empty($wan_stats)): ?>
                                        <div class="mt-3 pt-3" style="border-top: 1px solid rgba(255,255,255,0.1);">
                                            <strong class="text-white d-block mb-2"><i class="fas fa-ethernet me-1"></i> Interface Status:</strong>
                                            <?php foreach ($wan_stats as $iface): ?>
                                            <div class="mb-2 p-2 rounded" style="background: rgba(255,255,255,0.05);">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span class="text-white fw-bold"><?php echo htmlspecialchars($iface['interface_name']); ?></span>
                                                    <span class="badge <?php echo ($iface['status'] === 'active') ? 'bg-success' : 'bg-danger'; ?>">
                                                        <?php echo htmlspecialchars($iface['status']); ?>
                                                    </span>
                                                </div>
                                                <?php if (!empty($iface['ip_address'])): ?>
                                                <small class="text-light"><?php echo htmlspecialchars($iface['ip_address']); ?></small>
                                                <?php endif; ?>
                                                <div class="d-flex justify-content-between mt-1" style="font-size: 0.75rem;">
                                                    <span class="text-success"><i class="fas fa-arrow-down me-1"></i>RX: <?php echo number_format($iface['rx_bytes'] / 1073741824, 2); ?> GB</span>
                                                    <span class="text-primary"><i class="fas fa-arrow-up me-1"></i>TX: <?php echo number_format($iface['tx_bytes'] / 1073741824, 2); ?> GB</span>
                                                </div>
                                                <?php if ($iface['rx_errors'] > 0 || $iface['tx_errors'] > 0): ?>
                                                <div style="font-size: 0.7rem;" class="text-warning mt-1">
                                                    <i class="fas fa-exclamation-triangle me-1"></i>Errors: RX <?php echo number_format($iface['rx_errors']); ?> / TX <?php echo number_format($iface['tx_errors']); ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3" style="background-color: rgba(23,162,184,0.15); padding: 0.75rem; border-radius: 0.25rem; border-left: 3px solid #17a2b8;">
                                            <strong class="text-white"><i class="fas fa-wrench"></i> Agent Maintenance:</strong><br>
                                            <small class="text-light">Fix, update, or reinstall the agent via SSH</small><br>
                                            <button class="btn btn-sm btn-info mt-2" onclick="repairAgent()" id="repairAgentBtn">
                                                <i class="fas fa-tools"></i> Update/Repair Agent
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- On-Demand Secure Tunnel Connection -->
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="card card-ghost p-4 border-success" style="border: 2px solid #28a745;">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <h6 class="text-white fw-bold mb-2" style="font-size: 1.1rem;">
                                            <i class="fas fa-link me-2" style="color: #28a745;"></i>Secure SSH Tunnel Access
                                        </h6>
                                        <p class="text-light mb-0" style="font-size: 0.9rem;">
                                            <i class="fas fa-check me-1" style="color: #28a745;"></i>Encrypted on-demand tunnel to firewall web interface
                                        </p>
                                        <small class="text-muted d-block mt-1">
                                            <i class="fas fa-shield-alt me-1"></i>No exposed ports • Session timeout: 30 minutes • Automatic cleanup
                                        </small>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <?php if (isset($agents['primary']) && $agents['primary']["status"] == "online"): ?>
                                            <button onclick="connectViaOnDemandTunnel(<?php echo $firewall["id"]; ?>)" 
                                               class="btn btn-success btn-lg">
                                                <i class="fas fa-link me-2"></i>Open Firewall
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-secondary btn-lg" disabled title="Primary agent must be online">
                                                <i class="fas fa-unlink me-2"></i>Unavailable
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Configuration -->
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card card-ghost p-4 border border-secondary">
                                <h6 class="text-white fw-bold mb-3" style="font-size: 1.1rem;">Configuration</h6>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="checkin_interval" class="form-label text-white fw-bold">Check-in Interval (seconds)</label>
                                                <input type="number" name="checkin_interval" id="checkin_interval" class="form-control"
                                                       value="<?php echo htmlspecialchars($firewall['checkin_interval'] ?? 180); ?>"
                                                       min="30" max="3600">
                                                <small class="text-light">How often this firewall should check in (30 seconds to 1 hour)</small>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="speedtest_interval_hours" class="form-label text-white fw-bold">Speedtest Interval</label>
                                                <?php $st_interval = (int)($firewall['speedtest_interval_hours'] ?? 4); ?>
                                                <select name="speedtest_interval_hours" id="speedtest_interval_hours" class="form-select">
                                                    <option value="2" <?php echo $st_interval === 2 ? 'selected' : ''; ?>>Every 2 hours</option>
                                                    <option value="4" <?php echo $st_interval === 4 ? 'selected' : ''; ?>>Every 4 hours</option>
                                                    <option value="8" <?php echo $st_interval === 8 ? 'selected' : ''; ?>>Every 8 hours</option>
                                                    <option value="12" <?php echo $st_interval === 12 ? 'selected' : ''; ?>>Every 12 hours</option>
                                                    <option value="24" <?php echo $st_interval === 24 ? 'selected' : ''; ?>>Every 24 hours</option>
                                                    <option value="0" <?php echo $st_interval === 0 ? 'selected' : ''; ?>>Disabled</option>
                                                </select>
                                                <small class="text-light">How often to run automatic speedtests</small>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="customer_name" class="form-label text-white fw-bold">Customer Name</label>
                                                <input type="text" name="customer_name" id="customer_name" class="form-control"
                                                       value="<?php echo htmlspecialchars($firewall['customer_name'] ?? ''); ?>"
                                                       placeholder="Enter customer name">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="customer_group" class="form-label text-white fw-bold">Company</label>
                                                <select name="customer_group" id="customer_group" class="form-select">
                                                    <option value="">-- Select or type new --</option>
                                                    <?php foreach ($all_companies as $company): ?>
                                                        <option value="<?php echo htmlspecialchars($company); ?>"
                                                                <?php echo ($firewall['customer_group'] === $company) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($company); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="text-light">Select existing company or type new one</small>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label text-white fw-bold">Tags</label>
                                                <div class="border border-secondary rounded p-2" style="max-height: 120px; overflow-y: auto;">
                                                    <?php
                                                    $selected_tags = !empty($firewall['tag_names']) ? array_map('trim', explode(',', $firewall['tag_names'])) : [];
                                                    foreach ($all_tags as $tag):
                                                        $is_selected = in_array($tag['name'], $selected_tags);
                                                    ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="tags[]"
                                                               value="<?php echo htmlspecialchars($tag['name']); ?>"
                                                               id="tag_<?php echo $tag['id']; ?>"
                                                               <?php echo $is_selected ? 'checked' : ''; ?>>
                                                        <label class="form-check-label text-light" for="tag_<?php echo $tag['id']; ?>">
                                                            <span class="badge me-1" style="background-color: <?php echo htmlspecialchars($tag['color']); ?>;">&nbsp;</span>
                                                            <?php echo htmlspecialchars($tag['name']); ?>
                                                        </label>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <small class="text-light">Check tags to assign to this firewall</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" id="secure_outbound_toggle" 
                                                           <?php echo ($firewall['secure_outbound_lockdown'] ?? 0) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label text-white fw-bold" for="secure_outbound_toggle">
                                                        <i class="fas fa-lock me-2" style="color: #f39c12;"></i>Secure Outbound Lockdown
                                                    </label>
                                                    <div class="mt-2">
                                                        <small class="text-light d-block mb-2">
                                                            <strong>What it does:</strong>
                                                        </small>
                                                        <small class="text-light d-block">
                                                            ✓ Blocks ALL outbound except HTTP (80) and HTTPS (443)<br>
                                                            ✓ Forces DNS through Unbound on port 53<br>
                                                            ✓ Prevents tunnel/VPN abuse from LAN<br>
                                                            ✓ Logs all blocked traffic for audit trail
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="allowed_webgui_ips" class="form-label text-white fw-bold">
                                                    <i class="fas fa-shield-alt me-2" style="color: #17a2b8;"></i>Web GUI IP Lockdown
                                                </label>
                                                <input type="text" name="allowed_webgui_ips" id="allowed_webgui_ips" class="form-control"
                                                       value="<?php echo htmlspecialchars($firewall['allowed_webgui_ips'] ?? ''); ?>"
                                                       placeholder="192.168.1.100, 10.0.0.50">
                                                <small class="text-light d-block mt-2">
                                                    <strong>Restrict web GUI access to specific IPs</strong><br>
                                                    ✓ Comma-separated IP addresses (e.g., 192.168.1.100, 10.0.0.50)<br>
                                                    ✓ Management platform (184.175.206.229) is <strong>always included</strong><br>
                                                    ✓ Leave empty to allow access from any IP
                                                </small>
                                            </div>
                                        </div>
<script>
function connectViaOnDemandTunnel(firewallId) {
    // Open the on-demand proxy page in a new window
    const connectWindow = window.open(
        "/firewall_proxy_ondemand.php?id=" + firewallId,
        "_blank",
        "width=1400,height=900,resizable=yes,scrollbars=yes"
    );
    
    if (!connectWindow) {
        alert("Popup blocked! Please allow popups for this site.");
    }
}
</script>

                                    </div>
                                    <button type="submit" name="update_config" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i>Update Configuration
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                        </div><!-- End Overview Tab -->
                        
                        <!-- Commands Tab -->
                        <div class="tab-pane fade" id="commands" role="tabpanel">
                    
                    <!-- Command Log Section -->
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="card card-ghost p-4 border border-secondary">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="text-white fw-bold mb-0" style="font-size: 1.1rem;">
                                        <i class="fas fa-terminal me-2"></i>Command Execution Log
                                    </h6>
                                    <div class="d-flex align-items-center">
                                        <label for="commandLogTimezone" class="text-light me-2 small">Timezone:</label>
                                        <select id="commandLogTimezone" class="form-select form-select-sm" style="width: auto; min-width: 120px;" onchange="refreshCommandLogWithTimezone()">
                                            <option value="America/New_York">EST/EDT</option>
                                            <option value="America/Chicago">CST/CDT</option>
                                            <option value="America/Denver">MST/MDT</option>
                                            <option value="America/Los_Angeles">PST/PDT</option>
                                            <option value="UTC">UTC</option>
                                            <option value="Europe/London">GMT/BST</option>
                                            <option value="Europe/Paris">CET/CEST</option>
                                            <option value="Asia/Tokyo">JST</option>
                                            <option value="Australia/Sydney">AEST</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div id="commandLogLoading" class="text-center py-2">
                                    <div class="spinner-border text-light spinner-border-sm" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <span class="text-light ms-2">Loading recent commands...</span>
                                </div>
                                
                                <div id="commandLogContainer" style="display: none;">
                                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                        <table class="table table-dark table-hover table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Time</th>
                                                    <th>Description</th>
                                                    <th>Status</th>
                                                    <th>Duration</th>
                                                    <th class="text-end">Output</th>
                                                </tr>
                                            </thead>
                                            <tbody id="commandLogTableBody">
                                                <!-- Populated by JavaScript -->
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                
                                <div id="noCommands" style="display: none;">
                                    <p class="text-light text-center py-3 mb-0">No recent commands found.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                        </div><!-- End Commands Tab -->
                        
                        <!-- Backups Tab -->
                        <div class="tab-pane fade" id="backups" role="tabpanel">
                    
                    <!-- Backup Management Section -->
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="card card-ghost p-4 border border-secondary">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="text-white fw-bold mb-0" style="font-size: 1.1rem;">
                                        <i class="fas fa-save me-2"></i>Configuration Backups
                                    </h6>
                                    <button type="button" class="btn btn-success btn-sm" onclick="createBackup()">
                                        <i class="fas fa-plus me-1"></i>Create New Backup
                                    </button>
                                </div>
                                
                                <div id="backupsLoading" class="text-center py-4">
                                    <div class="spinner-border text-light" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="text-light mt-2">Loading backups...</p>
                                </div>
                                
                                <div id="backupsContainer" style="display: none;">
                                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                        <table class="table table-dark table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Date Created</th>
                                                    <th>Type</th>
                                                    <th>Size</th>
                                                    <th>Description</th>
                                                    <th class="text-end">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody id="backupsTableBody">
                                                <!-- Populated by JavaScript -->
                                            </tbody>
                                        </table>
                                    </div>
                                    <div id="noBackups" style="display: none;">
                                        <p class="text-light text-center py-4">No backups found. Create your first backup to get started.</p>
                                    </div>
                                </div><!-- End backupsContainer -->
                            </div><!-- End card -->
                        </div><!-- End col -->
                    </div><!-- End row -->
                    
                    </div><!-- End Backups Tab -->
                    
                    <!-- AI Analysis Tab -->
                        <div class="tab-pane fade" id="ai" role="tabpanel" aria-labelledby="ai-tab">
                            
                            <!-- AI Scanning Section -->
                            <div class="card card-ghost p-4 border border-primary mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="text-white fw-bold mb-0" style="font-size: 1.1rem;">
                                        <i class="fas fa-brain me-2 text-primary"></i>AI Security Analysis
                                    </h6>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="runManualScan()">
                                        <i class="fas fa-play me-1"></i>Run Scan Now
                                    </button>
                                </div>

                                <!-- AI Settings Form -->
                                <div class="row mb-3">
                                    <div class="col-md-12">
                                        <div class="form-check form-switch mb-3">
                                            <input class="form-check-input" type="checkbox" id="autoScanEnabled" onchange="saveAISettings()">
                                            <label class="form-check-label text-light" for="autoScanEnabled">
                                                Enable Automatic Scanning
                                            </label>
                                            <small class="d-block text-muted mt-1">AI scans will automatically include configuration and log analysis</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-light">Scan Frequency</label>
                                        <select class="form-select" id="scanFrequency" onchange="saveAISettings()">
                                            <option value="daily">Daily</option>
                                            <option value="weekly">Weekly</option>
                                            <option value="monthly">Monthly</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-light">Preferred AI Provider</label>
                                        <select class="form-select" id="preferredProvider" onchange="saveAISettings()">
                                            <option value="">Use Default</option>
                                            <option value="openai">OpenAI (GPT-4)</option>
                                            <option value="anthropic">Anthropic (Claude)</option>
                                            <option value="gemini">Google Gemini</option>
                                            <option value="ollama">Ollama (Local)</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Last Scan Info -->
                                <div id="lastScanInfo" class="alert alert-dark border-secondary mb-0 mt-3">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <small class="text-muted">Last Scan:</small><br>
                                            <span id="lastScanDate" class="text-light">Never</span>
                                        </div>
                                        <div class="col-md-6 text-end">
                                            <small class="text-muted">Next Scheduled:</small><br>
                                            <span id="nextScanDate" class="text-light">Not scheduled</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Scan Status -->
                                <div id="scanStatus" class="alert alert-info mt-3" style="display: none;">
                                    <div class="d-flex align-items-center">
                                        <div class="spinner-border spinner-border-sm me-2" role="status">
                                            <span class="visually-hidden">Scanning...</span>
                                        </div>
                                        <span id="scanStatusText">Initializing scan...</span>
                                    </div>
                                </div>

                                <!-- Recent Reports -->
                                <div id="recentReports" class="mt-3">
                                    <hr class="border-secondary">
                                    <h6 class="text-light mb-3">Recent Scan Reports</h6>
                                    <div id="noReportsMessage" class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>No AI scans have been performed yet. Click "Run Scan Now" to generate your first security analysis report.
                                    </div>
                                    <div class="table-responsive" style="display: none;">
                                        <table class="table table-dark table-hover table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Grade</th>
                                                    <th>Risk Level</th>
                                                    <th>Findings</th>
                                                    <th class="text-end">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody id="reportsTableBody">
                                                <!-- Populated by JavaScript -->
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- Log Analysis Statistics Section -->
                            <div class="card card-ghost p-4 border border-info mt-4">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="text-white fw-bold mb-0" style="font-size: 1.1rem;">
                                        <i class="fas fa-chart-line me-2 text-info"></i>Log Analysis Statistics (Last 30 Days)
                                    </h6>
                                </div>

                                <?php if ($log_analysis_stats['total_analyses'] > 0): ?>
                                    <div class="row">
                                        <div class="col-md-3 mb-3">
                                            <div class="card border-primary">
                                                <div class="card-body text-center p-3">
                                                    <h6 class="text-muted mb-2" style="font-size: 0.9rem;">Total Analyses</h6>
                                                    <h3 class="text-primary mb-0"><?php echo number_format($log_analysis_stats['total_analyses'] ?? 0); ?></h3>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="card border-danger">
                                                <div class="card-body text-center p-3">
                                                    <h6 class="text-muted mb-2" style="font-size: 0.9rem;">Active Threats</h6>
                                                    <h3 class="text-danger mb-0"><?php echo number_format($log_analysis_stats['total_threats'] ?? 0); ?></h3>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="card border-warning">
                                                <div class="card-body text-center p-3">
                                                    <h6 class="text-muted mb-2" style="font-size: 0.9rem;">Blocked Attempts</h6>
                                                    <h3 class="text-warning mb-0"><?php echo number_format($log_analysis_stats['total_blocks'] ?? 0); ?></h3>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="card border-secondary">
                                                <div class="card-body text-center p-3">
                                                    <h6 class="text-muted mb-2" style="font-size: 0.9rem;">Failed Auth</h6>
                                                    <h3 class="text-secondary mb-0"><?php echo number_format($log_analysis_stats['total_failed_auth'] ?? 0); ?></h3>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="alert alert-dark border-secondary mb-0 mt-2">
                                        <small class="text-muted"><i class="fas fa-info-circle me-2"></i>Statistics based on AI scans performed in the last 30 days. Run AI security analysis to update these metrics.</small>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info mb-0">
                                        <i class="fas fa-info-circle me-2"></i>No log analysis data available yet. Run an AI security scan to generate log analysis statistics.
                                    </div>
                                <?php endif; ?>
                            </div>

                        </div><!-- End AI Tab -->
                        
                        <!-- Network Tools Tab -->
                        <div class="tab-pane fade" id="network" role="tabpanel" aria-labelledby="network-tab">
                            
                            <!-- Network Diagnostic Tools -->
                            <div class="card card-ghost p-3 border border-info mb-4" style="max-width: 100%; overflow-x: auto;">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="text-white fw-bold mb-0" style="font-size: 1.1rem;">
                                        <i class="fas fa-network-wired me-2 text-info"></i>Network Diagnostics
                                    </h6>
                                </div>

                                <div class="row">
                                    <!-- Ping Tool -->
                                    <div class="col-md-4 mb-3">
                                        <div class="card bg-dark border-secondary">
                                            <div class="card-header bg-secondary text-white">
                                                <i class="fas fa-wifi me-2"></i>Ping
                                            </div>
                                            <div class="card-body">
                                                <div class="mb-2">
                                                    <input type="text" class="form-control form-control-sm" id="pingTarget" placeholder="8.8.8.8 or google.com" value="8.8.8.8">
                                                </div>
                                                <div class="mb-2">
                                                    <input type="number" class="form-control form-control-sm" id="pingCount" value="4" min="1" max="10">
                                                    <small class="text-muted">Packet count</small>
                                                </div>
                                                <button class="btn btn-sm btn-primary w-100" onclick="runPing()">
                                                    <i class="fas fa-play me-1"></i>Run Ping
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Traceroute Tool -->
                                    <div class="col-md-4 mb-3">
                                        <div class="card bg-dark border-secondary">
                                            <div class="card-header bg-secondary text-white">
                                                <i class="fas fa-route me-2"></i>Traceroute
                                            </div>
                                            <div class="card-body">
                                                <div class="mb-2">
                                                    <input type="text" class="form-control form-control-sm" id="tracerouteTarget" placeholder="8.8.8.8 or google.com" value="8.8.8.8">
                                                </div>
                                                <div class="mb-2">
                                                    <input type="number" class="form-control form-control-sm" id="tracerouteMaxHops" value="30" min="1" max="50">
                                                    <small class="text-muted">Max hops</small>
                                                </div>
                                                <button class="btn btn-sm btn-success w-100" onclick="runTraceroute()">
                                                    <i class="fas fa-play me-1"></i>Run Traceroute
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- DNS Lookup Tool -->
                                    <div class="col-md-4 mb-3">
                                        <div class="card bg-dark border-secondary">
                                            <div class="card-header bg-secondary text-white">
                                                <i class="fas fa-search me-2"></i>DNS Lookup
                                            </div>
                                            <div class="card-body">
                                                <div class="mb-2">
                                                    <input type="text" class="form-control form-control-sm" id="dnsTarget" placeholder="google.com" value="google.com">
                                                </div>
                                                <div class="mb-2">
                                                    <select class="form-select form-select-sm" id="dnsType">
                                                        <option value="A">A (IPv4)</option>
                                                        <option value="AAAA">AAAA (IPv6)</option>
                                                        <option value="MX">MX (Mail)</option>
                                                        <option value="TXT">TXT</option>
                                                        <option value="NS">NS</option>
                                                        <option value="ANY">ANY</option>
                                                    </select>
                                                </div>
                                                <button class="btn btn-sm btn-info w-100" onclick="runDNSLookup()">
                                                    <i class="fas fa-play me-1"></i>Run Lookup
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Bandwidth Testing -->
                                <div class="card card-ghost p-3 border border-warning mb-4" style="max-width: 100%; overflow-x: auto;">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h6 class="text-white fw-bold mb-0" style="font-size: 1.1rem;">
                                            <i class="fas fa-tachometer-alt me-2 text-warning"></i>Bandwidth Testing
                                        </h6>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <!-- Speed Test button removed - use chart "Run Now" button instead -->
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <div class="card bg-dark border-secondary">
                                                <div class="card-header bg-info text-white">
                                                    <i class="fas fa-chart-line me-2"></i>Test History
                                                </div>
                                                <div class="card-body">
                                                    <p class="small text-muted mb-2">Last 5 bandwidth tests:</p>
                                                    <div id="bandwidthHistory" class="small text-light">
                                                        <i class="fas fa-spinner fa-spin me-2"></i>Loading...
                                                    </div>
                                                    <button class="btn btn-sm btn-outline-info mt-2 w-100" onclick="loadBandwidthHistory()">
                                                        <i class="fas fa-refresh me-1"></i>Refresh
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Results Terminal -->
                                <div class="card bg-dark border-secondary mt-3">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <span><i class="fas fa-terminal me-2"></i>Output</span>
                                        <button class="btn btn-sm btn-outline-light" onclick="clearDiagnosticOutput()">
                                            <i class="fas fa-trash me-1"></i>Clear
                                        </button>
                                    </div>
                                    <div class="card-body p-0">
                                        <pre id="diagnosticOutput" style="background: #1a1d23; color: #00ff00; font-family: 'Courier New', monospace; font-size: 12px; padding: 15px; margin: 0; min-height: 200px; max-height: 400px; overflow-y: auto; white-space: pre-wrap;"></pre>
                                    </div>
                                </div>
                            </div>
                        
                    </div><!-- End Network Tools Tab -->
                        
                    </div><!-- End Tab Content -->

<script>
const firewallId = <?php echo $firewall['id']; ?>;
const csrfToken = '<?php echo csrf_token(); ?>';

// Backup Management Functions

// AI Scanning Functions

// Network Diagnostic Tools Functions
function addDiagnosticOutput(text, type = 'info') {
    const terminal = document.getElementById('diagnosticOutput');
    const timestamp = new Date().toLocaleTimeString();
    const color = type === 'error' ? '#ff5555' : type === 'success' ? '#50fa7b' : '#00ff00';
    terminal.innerHTML += `<span style="color: #6272a4">[${timestamp}]</span> <span style="color: ${color}">${text}</span>\n`;
    terminal.scrollTop = terminal.scrollHeight;
}

function clearDiagnosticOutput() {
    document.getElementById('diagnosticOutput').innerHTML = '';
    addDiagnosticOutput('Terminal cleared', 'info');
}

function runDiagnostic(tool, params) {
    // Clear previous output and show loading indicator
    clearDiagnosticOutput();
    
    // Create loading animation
    const loadingHtml = `
        <div class="text-center" style="padding: 40px;">
            <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div class="mt-3" style="color: #4fc3f7; font-size: 14px;">
                <i class="fas fa-sync-alt fa-spin me-2"></i>Test running, please wait...
            </div>
            <div class="mt-2" style="color: #95a5a6; font-size: 12px;">
                Executing ${tool} on firewall
            </div>
        </div>
    `;
    
    const outputEl = document.getElementById('diagnosticOutput');
    const originalContent = outputEl.innerHTML;
    outputEl.innerHTML = loadingHtml;
    outputEl.style.textAlign = 'center';
    
    fetch('/api/run_diagnostic.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            firewall_id: firewallId,
            tool: tool,
            params: params,
            csrf: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        // Reset text alignment and clear loading
        outputEl.style.textAlign = 'left';
        outputEl.innerHTML = '';
        
        if (data.success) {
            addDiagnosticOutput(`✓ ${tool.toUpperCase()} completed successfully\n`, 'success');
            addDiagnosticOutput(`${data.output}`, 'info');
        } else {
            addDiagnosticOutput(`✗ Error: ${data.error}`, 'error');
        }
    })
    .catch(error => {
        outputEl.style.textAlign = 'left';
        outputEl.innerHTML = '';
        addDiagnosticOutput(`✗ Connection Error: ${error}`, 'error');
    });
}

function runPing() {
    const target = document.getElementById('pingTarget').value;
    const count = document.getElementById('pingCount').value;
    if (!target) {
        addDiagnosticOutput('Please enter a target', 'error');
        return;
    }
    runDiagnostic('ping', {target, count});
}

function runTraceroute() {
    const target = document.getElementById('tracerouteTarget').value;
    const maxHops = document.getElementById('tracerouteMaxHops').value;
    if (!target) {
        addDiagnosticOutput('Please enter a target', 'error');
        return;
    }
    runDiagnostic('traceroute', {target, maxHops});
}

function runDNSLookup() {
    const target = document.getElementById('dnsTarget').value;
    const type = document.getElementById('dnsType').value;
    if (!target) {
        addDiagnosticOutput('Please enter a domain', 'error');
        return;
    }
    runDiagnostic('dns', {target, type});
}

// Bandwidth Testing Functions
function runBandwidthTest() {
    addDiagnosticOutput('Starting bandwidth test...', 'info');
    addDiagnosticOutput('This may take 30-60 seconds depending on connection speed', 'info');
    
    const outputEl = document.getElementById('diagnosticOutput');
    
    // Show loading animation
    const loadingHtml = `
        <div class="text-center" style="padding: 40px;">
            <div class="spinner-border text-warning" role="status" style="width: 3rem; height: 3rem;">
                <span class="visually-hidden">Testing...</span>
            </div>
            <div class="mt-3" style="color: #ffc107; font-size: 14px;">
                <i class="fas fa-tachometer-alt me-2"></i>Running speed test from firewall...
            </div>
        </div>
    `;
    outputEl.style.textAlign = 'center';
    outputEl.innerHTML = loadingHtml;
    
    fetch('/api/run_bandwidth_test.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `firewall_id=${firewallId}&csrf_token=${csrfToken}`
    })
    .then(response => response.json())
    .then(data => {
        outputEl.style.textAlign = 'left';
        outputEl.innerHTML = '';
        
        if (data.success) {
            addDiagnosticOutput('✓ Bandwidth test queued successfully', 'success');
            addDiagnosticOutput('Test ID: ' + data.test_id, 'info');
            addDiagnosticOutput('The test is running on the firewall. Results will appear in the command log.', 'info');
            
            // Refresh bandwidth history after a delay
            setTimeout(() => {
                loadBandwidthHistory();
            }, 5000);
        } else {
            addDiagnosticOutput('✗ Error: ' + data.error, 'error');
        }
    })
    .catch(error => {
        outputEl.style.textAlign = 'left';
        outputEl.innerHTML = '';
        addDiagnosticOutput('✗ Connection Error: ' + error, 'error');
    });
}

function loadBandwidthHistory() {
    const historyEl = document.getElementById('bandwidthHistory');
    historyEl.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading...';
    
    fetch(`/api/get_bandwidth_history.php?firewall_id=${firewallId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.tests.length > 0) {
                let html = '';
                data.tests.forEach(test => {
                    const status = test.test_status;
                    const icon = status === 'completed' ? '✓' : status === 'failed' ? '✗' : '⏳';
                    const color = status === 'completed' ? '#50fa7b' : status === 'failed' ? '#ff5555' : '#ffb86c';
                    
                    if (status === 'completed') {
                        const downloadFormatted = formatSpeed(test.download_speed);
                        const uploadFormatted = formatSpeed(test.upload_speed);
                        html += `<div style="color: ${color}; font-size: 11px; margin-bottom: 3px;">
                            ${icon} ${test.tested_at}: ${downloadFormatted}/${uploadFormatted}
                        </div>`;
                    } else {
                        html += `<div style="color: ${color}; font-size: 11px; margin-bottom: 3px;">
                            ${icon} ${test.tested_at}: ${status}
                        </div>`;
                    }
                });
                historyEl.innerHTML = html;
            } else {
                historyEl.innerHTML = '<span class="text-muted">No tests found</span>';
            }
        })
        .catch(error => {
            historyEl.innerHTML = '<span class="text-danger">Error loading history</span>';
        });
}

function loadAISettings() {
    console.log('loadAISettings() called for firewall:', firewallId);
    fetch(`/api/get_ai_settings.php?firewall_id=${firewallId}`)
        .then(response => response.json())
        .then(data => {
            console.log('AI settings response:', data);
            if (data.success) {
                document.getElementById('autoScanEnabled').checked = data.settings.auto_scan_enabled;
                document.getElementById('scanFrequency').value = data.settings.scan_frequency || 'weekly';
                document.getElementById('preferredProvider').value = data.settings.preferred_provider || '';
                
                if (data.settings.last_scan_at) {
                    document.getElementById('lastScanDate').textContent = new Date(data.settings.last_scan_at).toLocaleString();
                } else {
                    document.getElementById('lastScanDate').textContent = 'Never';
                }
                
                if (data.settings.next_scan_at) {
                    document.getElementById('nextScanDate').textContent = new Date(data.settings.next_scan_at).toLocaleString();
                } else {
                    document.getElementById('nextScanDate').textContent = 'Not scheduled';
                }
                
                loadRecentReports();
            }
        })
        .catch(error => console.error('Error loading AI settings:', error));
}

function saveAISettings() {
    const settings = {
        firewall_id: firewallId,
        auto_scan_enabled: document.getElementById('autoScanEnabled').checked,
        scan_frequency: document.getElementById('scanFrequency').value,
        preferred_provider: document.getElementById('preferredProvider').value,
        csrf: csrfToken
    };
    
    fetch('/api/save_ai_settings.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(settings)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('AI settings saved successfully', 'success');
            loadAISettings(); // Reload to get updated next_scan_at
        } else {
            showToast('Error saving AI settings: ' + (data.error || 'Unknown error'), 'danger');
        }
    })
    .catch(error => {
        console.error('Error saving AI settings:', error);
        showToast('Error saving AI settings', 'danger');
    });
}

function runManualScan() {
    if (!confirm('Run AI security analysis now?\n\nThis will:\n• Scan firewall configuration\n• Analyze firewall logs\n• Generate comprehensive security report\n\nThis may take 30-60 seconds.')) {
        return;
    }

    const preferredProvider = document.getElementById('preferredProvider').value;

    document.getElementById('scanStatus').style.display = 'block';
    document.getElementById('scanStatusText').textContent = 'Initializing scan...';

    fetch('/api/ai_scan.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            firewall_id: firewallId,
            scan_type: 'config_with_logs',
            provider: preferredProvider || null,
            csrf: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        document.getElementById('scanStatus').style.display = 'none';
        
        if (data.success) {
            showToast('AI scan completed successfully!', 'success');
            loadAISettings();
            loadRecentReports();
            
            // Show report in modal or redirect
            if (data.report_id) {
                window.open(`/ai_reports.php?report_id=${data.report_id}`, '_blank');
            }
        } else {
            showToast('Error running AI scan: ' + (data.error || 'Unknown error'), 'danger');
        }
    })
    .catch(error => {
        document.getElementById('scanStatus').style.display = 'none';
        console.error('Error running AI scan:', error);
        showToast('Error running AI scan', 'danger');
    });
}

function loadRecentReports() {
    const timestamp = new Date().getTime();
    fetch(`/api/get_ai_reports.php?firewall_id=${firewallId}&limit=5&_=${timestamp}`, {
        cache: 'no-cache',
        headers: {
            'Cache-Control': 'no-cache, no-store, must-revalidate',
            'Pragma': 'no-cache',
            'Expires': '0'
        }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.reports && data.reports.length > 0) {
                document.getElementById('noReportsMessage').style.display = 'none';
                document.querySelector('#recentReports .table-responsive').style.display = 'block';
                const tbody = document.getElementById('reportsTableBody');
                tbody.innerHTML = '';
                
                data.reports.forEach(report => {
                    const row = document.createElement('tr');
                    const date = new Date(report.created_at);
                    
                    const gradeClass = report.overall_grade && report.overall_grade.startsWith('A') ? 'success' : 
                                     report.overall_grade && report.overall_grade.startsWith('B') ? 'primary' :
                                     report.overall_grade && report.overall_grade.startsWith('C') ? 'warning' : 'danger';
                    
                    const riskClass = report.risk_level === 'low' ? 'success' :
                                    report.risk_level === 'medium' ? 'warning' :
                                    report.risk_level === 'high' ? 'danger' : 'danger';
                    
                    row.innerHTML = `
                        <td>${date.toLocaleDateString()} ${date.toLocaleTimeString()}</td>
                        <td><span class="badge bg-${gradeClass}">${report.overall_grade || 'N/A'}</span></td>
                        <td><span class="badge bg-${riskClass}">${report.risk_level || 'unknown'}</span></td>
                        <td>${report.finding_count || 0} findings</td>
                        <td class="text-end">
                            <a href="/ai_reports.php?report_id=${report.id}" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <button onclick="deleteReport(${report.id}, event)" class="btn btn-sm btn-outline-danger ms-1">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </td>
                    `;
                    tbody.appendChild(row);
                });
            } else {
                document.getElementById('noReportsMessage').style.display = 'block';
                document.querySelector('#recentReports .table-responsive').style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Error loading recent reports:', error);
            document.getElementById('noReportsMessage').style.display = 'block';
            document.querySelector('#recentReports .table-responsive').style.display = 'none';
        });
}

function deleteReport(reportId, event) {
    if (!confirm('Are you sure you want to delete this AI scan report? This action cannot be undone.')) {
        return;
    }

    const formData = new FormData();
    formData.append('report_id', reportId);
    formData.append('csrf', csrfToken);

    fetch('/api/delete_ai_report.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Reload and stay on AI tab by adding hash
            window.location.hash = 'ai-tab';
            window.location.reload(true);
        } else {
            showToast('Error deleting report: ' + (data.error || 'Unknown error'), 'danger');
        }
    })
    .catch(error => {
        console.error('Error deleting report:', error);
        showToast('Error deleting report', 'danger');
    });
}

function showToast(message, type = 'info') {
    // Simple toast notification
    const toast = document.createElement('div');
    toast.className = `alert alert-${type} position-fixed top-0 end-0 m-3`;
    toast.style.zIndex = '9999';
    toast.innerHTML = message;
    document.body.appendChild(toast);

    setTimeout(() => toast.remove(), 3000);
}

function loadBackups() {
    console.log('loadBackups() called for firewall:', firewallId);
    fetch(`/api/get_backups.php?firewall_id=${firewallId}`)
        .then(response => response.json())
        .then(data => {
            console.log('Backups response:', data);
            const loadingEl = document.getElementById('backupsLoading');
            const containerEl = document.getElementById('backupsContainer');
            console.log('backupsLoading element:', loadingEl);
            console.log('backupsContainer element:', containerEl);
            if (loadingEl) loadingEl.style.display = 'none';
            if (containerEl) containerEl.style.display = 'block';
            
            if (data.success && data.backups && data.backups.length > 0) {
                console.log('Processing', data.backups.length, 'backups');
                const tbody = document.getElementById('backupsTableBody');
                tbody.innerHTML = '';
                
                data.backups.forEach(backup => {
                    const row = document.createElement('tr');
                    const date = new Date(backup.created_at);
                    const formattedDate = date.toLocaleString();
                    const fileSize = formatFileSize(backup.file_size);
                    
                    row.innerHTML = `
                        <td>${formattedDate}</td>
                        <td><span class="badge bg-info">${backup.backup_type || 'manual'}</span></td>
                        <td>${fileSize}</td>
                        <td class="text-light small">${backup.description || 'N/A'}</td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-primary me-1" onclick="downloadBackup(${backup.id})" title="Download">
                                <i class="fas fa-download"></i>
                            </button>
                            <button class="btn btn-sm btn-warning me-1" onclick="restoreBackup(${backup.id})" title="Restore">
                                <i class="fas fa-undo"></i>
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="deleteBackup(${backup.id})" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    `;
                    tbody.appendChild(row);
                });
            } else {
                document.getElementById('noBackups').style.display = 'block';
            }
        })
        .catch(error => {
            console.error('Error loading backups:', error);
            document.getElementById('backupsLoading').innerHTML = '<p class="text-danger">Error loading backups. Please refresh the page.</p>';
        });
}

function formatFileSize(bytes) {
    if (!bytes || bytes === 0) return 'N/A';
    const units = ['B', 'KB', 'MB', 'GB'];
    let size = parseInt(bytes);
    let unitIndex = 0;
    while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024;
        unitIndex++;
    }
    return size.toFixed(1) + ' ' + units[unitIndex];
}

function formatSpeed(mbps) {
    const speed = parseFloat(mbps);
    if (speed >= 1000) {
        const gbps = speed / 1000;
        return gbps.toFixed(1) + ' Gbps';
    } else {
        return Math.round(speed) + ' Mbps';
    }
}

function createBackup() {
    if (!confirm('Create a new backup of this firewall\'s configuration?')) {
        return;
    }
    
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Creating...';
    
    fetch('/api/create_backup.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            firewall_id: firewallId,
            csrf: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        
        if (data.success) {
            alert('Backup created successfully!');
                loadBackups();
        } else {
            alert('Error creating backup: ' + (data.message || data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        alert('Error creating backup: ' + error.message);
    });
}

function downloadBackup(backupId) {
    window.location.href = `/api/download_backup.php?id=${backupId}`;
}

function restoreBackup(backupId) {
    if (!confirm('WARNING: This will restore the firewall configuration to this backup.\n\nThe firewall will reload and you may lose connectivity temporarily.\n\nAre you sure you want to continue?')) {
        return;
    }
    
    fetch('/api/restore_backup.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            backup_id: backupId,
            csrf: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Restore command queued successfully!\n\nThe firewall will restore the configuration and reload. This may take a few minutes.');
        } else {
            alert('Error restoring backup: ' + (data.message || data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        alert('Error restoring backup: ' + error.message);
    });
}

function deleteBackup(backupId) {
    if (!confirm('Are you sure you want to delete this backup? This action cannot be undone.')) {
        return;
    }
    
    fetch('/api/delete_backup.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            backup_id: backupId,
            csrf: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Backup deleted successfully!');
                loadBackups();
        } else {
            alert('Error deleting backup: ' + (data.message || data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        alert('Error deleting backup: ' + error.message);
    });
}

// Command Log Functions
function loadCommandLog() {
    const selectedTimezone = document.getElementById('commandLogTimezone').value || 'America/New_York';
    fetch(`/api/get_command_log.php?firewall_id=${firewallId}&timezone=${encodeURIComponent(selectedTimezone)}`)
        .then(response => response.json())
        .then(data => {
            document.getElementById('commandLogLoading').style.display = 'none';
            
            if (data.success && data.commands && data.commands.length > 0) {
                document.getElementById('commandLogContainer').style.display = 'block';
                const tbody = document.getElementById('commandLogTableBody');
                tbody.innerHTML = '';
                
                data.commands.forEach(cmd => {
                    const row = document.createElement('tr');
                    
                    // Calculate duration
                    let duration = 'N/A';
                    if (cmd.sent_at && cmd.completed_at) {
                        const sent = new Date(cmd.sent_at);
                        const completed = new Date(cmd.completed_at);
                        const diff = Math.round((completed - sent) / 1000);
                        duration = diff + 's';
                    }
                    
                    // Status badge
                    let statusBadge = '';
                    switch(cmd.status) {
                        case 'completed':
                            statusBadge = '<span class="badge bg-success">Completed</span>';
                            break;
                        case 'failed':
                            statusBadge = '<span class="badge bg-danger">Failed</span>';
                            break;
                        case 'sent':
                            statusBadge = '<span class="badge bg-info">Running</span>';
                            break;
                        case 'pending':
                            statusBadge = '<span class="badge bg-warning text-white">Pending</span>';
                            break;
                        default:
                            statusBadge = '<span class="badge bg-secondary">' + cmd.status + '</span>';
                    }
                    
                    // Output preview/button
                    let outputButton = '';
                    if (cmd.result && cmd.result.length > 0 && cmd.result !== 'success' && cmd.result !== 'failed') {
                        // Show "View Output" button for substantial output
                        const outputPreview = cmd.result.substring(0, 40).replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        const isTruncated = cmd.result.length > 40 ? '...' : '';
                        outputButton = `
                            <small class="d-inline-block me-2 text-muted" style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${cmd.result}">
                                ${outputPreview}${isTruncated}
                            </small>
                            <button class="btn btn-sm btn-outline-info" onclick="showCommandOutput(${cmd.id}, '${(cmd.description || cmd.command || '').replace(/'/g, "\\'")}')">
                                <i class="fas fa-eye me-1"></i>View
                            </button>
                        `;
                    } else if (cmd.result) {
                        outputButton = `<span class="badge bg-${cmd.result === 'success' ? 'success' : 'warning text-white'}">${cmd.result}</span>`;
                    } else {
                        outputButton = '<small class="text-muted">-</small>';
                    }
                    
                    // Use formatted timestamp from API if available, otherwise format locally
                    const timeStr = cmd.created_at_formatted || new Date(cmd.created_at).toLocaleString();
                    
                    row.innerHTML = `
                        <td><small>${timeStr}</small></td>
                        <td class="text-light small">${cmd.description || 'N/A'}</td>
                        <td>${statusBadge}</td>
                        <td><small class="text-muted">${duration}</small></td>
                        <td class="text-end">${outputButton}</td>
                    `;
                    tbody.appendChild(row);
                });
            } else {
                document.getElementById('noCommands').style.display = 'block';
            }
        })
        .catch(error => {
            console.error('Error loading command log:', error);
            document.getElementById('commandLogLoading').innerHTML = '<p class="text-danger small">Error loading command log.</p>';
        });
}

function refreshCommandLogWithTimezone() {
    // Show loading state
    document.getElementById('commandLogLoading').style.display = 'block';
    document.getElementById('commandLogContainer').style.display = 'none';
    
    // Store timezone preference
    const selectedTimezone = document.getElementById('commandLogTimezone').value;
    localStorage.setItem('preferredTimezone', selectedTimezone);
    
    // Reload command log with new timezone
    loadCommandLog();
}

function showCommandOutput(commandId, description) {
    // Fetch full command details including output
    fetch(`/api/get_command_log.php?firewall_id=${firewallId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.commands) {
                const command = data.commands.find(cmd => cmd.id == commandId);
                if (command && command.result) {
                    const output = command.result.length > 7 ? command.result : 'No detailed output available';
                    
                    // Create modal-like display
                    const modal = document.createElement('div');
                    modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:9999;display:flex;align-items:center;justify-content:center;';
                    modal.onclick = (e) => { if (e.target === modal) modal.remove(); };
                    
                    modal.innerHTML = `
                        <div style="background:#2d3748;border:1px solid #4a5568;border-radius:8px;max-width:80%;max-height:80%;overflow:auto;padding:20px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
                                <h5 style="color:white;margin:0;">Command Output</h5>
                                <button onclick="this.closest('div').parentElement.remove()" style="background:none;border:none;color:#cbd5e0;font-size:24px;cursor:pointer;">&times;</button>
                            </div>
                            <p style="color:#e2e8f0;margin-bottom:10px;"><strong>Command:</strong> ${description}</p>
                            <p style="color:#e2e8f0;margin-bottom:10px;"><strong>Status:</strong> ${command.status}</p>
                            <p style="color:#e2e8f0;margin-bottom:15px;"><strong>Output:</strong></p>
                            <pre style="background:#1a202c;color:#e2e8f0;padding:15px;border-radius:4px;white-space:pre-wrap;word-break:break-all;max-height:400px;overflow:auto;">${output}</pre>
                        </div>
                    `;
                    
                    document.body.appendChild(modal);
                }
            }
        })
        .catch(error => {
            alert('Error fetching command output: ' + error.message);
        });
}

// Load backups and command log on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('🔍 DOM Content Loaded - initializing tabs');

    // Check for hash and activate corresponding tab
    if (window.location.hash === '#ai-tab') {
        const aiTabButton = document.getElementById('ai-tab');
        if (aiTabButton) {
            const tab = new bootstrap.Tab(aiTabButton);
            tab.show();
            // Remove hash after activating tab
            history.replaceState(null, null, ' ');
        }
    }

    // Debug: Check if tab elements exist
    const aiTab = document.getElementById('ai');
    const networkTab = document.getElementById('network');
    console.log('AI tab element:', aiTab);
    console.log('Network tab element:', networkTab);
    
    // Debug: Listen to Bootstrap tab events
    document.querySelectorAll('button[data-bs-toggle="tab"]').forEach(button => {
        button.addEventListener('shown.bs.tab', function (event) {
            console.log('✅ Tab shown:', event.target.getAttribute('data-bs-target'));
            const target = document.querySelector(event.target.getAttribute('data-bs-target'));
            console.log('Target element:', target);
            if (target) {
                const styles = window.getComputedStyle(target);
                console.log('Target display:', styles.display);
                console.log('Target visibility:', styles.visibility);
                console.log('Target opacity:', styles.opacity);
                console.log('Target height:', styles.height);
                console.log('Target overflow:', styles.overflow);
                console.log('Target position:', styles.position);
                console.log('Target classes:', target.className);
                
                // Check first child
                if (target.firstElementChild) {
                    const childStyles = window.getComputedStyle(target.firstElementChild);
                    console.log('First child display:', childStyles.display);
                    console.log('First child opacity:', childStyles.opacity);
                    console.log('First child element:', target.firstElementChild);
                }
                
                // Check actual dimensions
                console.log('🔍 DIMENSIONS:');
                console.log('offsetHeight:', target.offsetHeight);
                console.log('scrollHeight:', target.scrollHeight);
                console.log('clientHeight:', target.clientHeight);
                console.log('offsetWidth:', target.offsetWidth);
                console.log('getBoundingClientRect:', target.getBoundingClientRect());
                
                // Check if it's being rendered
                const rect = target.getBoundingClientRect();
                if (rect.height === 0) {
                    console.error('❌ TAB HAS ZERO HEIGHT! Forcing height...');
                    
                    // Check ENTIRE parent chain
                    let element = target;
                    let depth = 0;
                    console.log('🔍 PARENT CHAIN:');
                    while (element && depth < 10) {
                        console.log(`Level ${depth}:`, element.tagName, element.className, element.id, 'offsetHeight:', element.offsetHeight);
                        element = element.parentElement;
                        depth++;
                    }
                    
                    // Force display
                    target.style.display = 'block !important';
                    target.style.position = 'relative';
                    target.style.minHeight = '600px !important';
                    target.style.height = 'auto !important';
                    target.style.visibility = 'visible !important';
                    target.style.opacity = '1 !important';
                    
                    // Try again after forcing
                    setTimeout(() => {
                        const newRect = target.getBoundingClientRect();
                        console.log('After forcing - height:', newRect.height);
                        console.log('After forcing - offsetHeight:', target.offsetHeight);
                    }, 100);
                }
            }
        });
        button.addEventListener('show.bs.tab', function (event) {
            console.log('🔄 Tab showing:', event.target.getAttribute('data-bs-target'));
        });
    });
    
    // Load saved timezone preference
    const savedTimezone = localStorage.getItem('preferredTimezone');
    if (savedTimezone) {
        const timezoneSelect = document.getElementById('commandLogTimezone');
        if (timezoneSelect) {
            timezoneSelect.value = savedTimezone;
        }
    }
    
    loadBackups();
    loadAISettings();
    loadCommandLog();
    
    // Initialize network tools terminal
    const diagnosticOutput = document.getElementById('diagnosticOutput');
    if (diagnosticOutput && diagnosticOutput.innerHTML.trim() === '') {
        addDiagnosticOutput('Network diagnostic tools ready. Select a tool above to begin.', 'info');
    }
    
    // Load bandwidth test history
    loadBandwidthHistory();
});

// Repair Agent via SSH
function repairAgent() {
    if (!confirm('Update/Repair the agent on this firewall?\n\nThis will:\n• SSH into the firewall\n• Stop any running agent processes\n• Download and install the latest agent\n• Configure and start the agent\n\nNote: This requires SSH access to be configured.\n\nContinue?')) {
        return;
    }

    const btn = document.getElementById('repairAgentBtn');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Starting...';

    // Show progress modal
    showRepairProgress();

    fetch('api/repair_agent_ssh.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'firewall_id=<?php echo $id; ?>'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Start polling for status updates
            pollRepairStatus(data.session_id, btn, originalHtml);
        } else {
            alert('Error: ' + (data.error || 'Unknown error') + '\n\n' + (data.note || ''));
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            hideRepairProgress();
        }
    })
    .catch(error => {
        alert('Error starting repair: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        hideRepairProgress();
    });
}

function pollRepairStatus(sessionId, btn, originalHtml) {
    const interval = setInterval(() => {
        fetch('api/repair_status.php?session_id=' + sessionId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateRepairProgress(data.progress, data.current_step);

                if (data.status === 'complete') {
                    clearInterval(interval);
                    updateRepairProgress(100, 'Repair completed successfully!');
                    setTimeout(() => {
                        hideRepairProgress();
                        alert('✅ Agent repair completed successfully!\n\nThe page will reload to show the updated status.');
                        location.reload();
                    }, 2000);
                } else if (data.status === 'error' || data.status === 'timeout') {
                    clearInterval(interval);
                    hideRepairProgress();
                    alert('❌ Error: ' + (data.error || 'Repair failed'));
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                }
            }
        })
        .catch(error => {
            clearInterval(interval);
            hideRepairProgress();
            alert('Error checking repair status: ' + error.message);
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        });
    }, 2000); // Poll every 2 seconds
}

function showRepairProgress() {
    const modal = document.createElement('div');
    modal.id = 'repairProgressModal';
    modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; z-index: 9999;';

    modal.innerHTML = `
        <div style="background: #2c3e50; padding: 2rem; border-radius: 0.5rem; min-width: 400px; max-width: 600px;">
            <h4 style="color: #fff; margin-bottom: 1rem;"><i class="fas fa-tools"></i> Agent Repair in Progress</h4>
            <div style="background: #1a252f; padding: 1rem; border-radius: 0.25rem; margin-bottom: 1rem;">
                <div id="repairProgressBar" style="background: #17a2b8; height: 25px; border-radius: 0.25rem; transition: width 0.3s; width: 0%; display: flex; align-items: center; justify-content: center;">
                    <span id="repairProgressText" style="color: #fff; font-weight: bold; font-size: 0.9rem;">0%</span>
                </div>
            </div>
            <div id="repairCurrentStep" style="color: #adb5bd; font-size: 0.95rem; min-height: 40px;">
                Initializing...
            </div>
        </div>
    `;

    document.body.appendChild(modal);
}

function updateRepairProgress(progress, step) {
    const progressBar = document.getElementById('repairProgressBar');
    const progressText = document.getElementById('repairProgressText');
    const stepText = document.getElementById('repairCurrentStep');

    if (progressBar && progressText && stepText) {
        progressBar.style.width = progress + '%';
        progressText.textContent = progress + '%';
        stepText.textContent = step;
    }
}

function hideRepairProgress() {
    const modal = document.getElementById('repairProgressModal');
    if (modal) {
        modal.remove();
    }
}

// Reboot firewall
function rebootFirewall() {
    if (!confirm('⚠️ WARNING: This will reboot the firewall!\n\nThe firewall will be offline for 1-3 minutes.\n\nContinue?')) {
        return;
    }
    
    const btn = document.getElementById('rebootBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Rebooting...';
    
    fetch('api/reboot_firewall.php?id=<?php echo $id; ?>')
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Reboot command sent!\n\nThe firewall is rebooting now.\nIt will be offline for 1-3 minutes.\n\nMonitor the status on this page.');
            setTimeout(() => location.reload(), 3000);
        } else {
            alert('❌ Error: ' + data.error);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-power-off me-1"></i>Reboot';
        }
    })
    .catch(error => {
        alert('❌ Error sending reboot command: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-power-off me-1"></i>Reboot';
    });
}

function resetAgent() {
    if (!confirm('⚠️ This will force restart the agent.\n\nThe firewall status may briefly be unavailable.\n\nContinue?')) {
        return;
    }

    const btn = document.getElementById('resetAgentBtn');
    btn.disabled = true;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Resetting...';

    fetch('api/reset_agent.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': document.querySelector('[name="csrf_token"]').value
        },
        body: 'firewall_id=<?php echo $id; ?>'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Agent reset command queued!\n\nThe agent will restart within 1-2 minutes.');
            setTimeout(() => {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }, 2000);
        } else {
            alert('❌ Error: ' + (data.error || 'Unknown error'));
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    })
    .catch(error => {
        alert('❌ Error sending reset command: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    });
}

function updateAgent(firewallId) {
    if (!confirm('🔄 Update the agent to the latest version?\n\nThis will:\n• Download version <?php echo LATEST_AGENT_VERSION; ?>\n• Install the update\n• Restart the agent\n\nThe update will complete within 2-3 minutes.\n\nContinue?')) {
        return;
    }

    // Show loading state
    const btn = event.target;
    btn.disabled = true;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Updating...';

    fetch('/api/trigger_agent_update.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({firewall_id: firewallId})
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Agent update queued!\n\nThe firewall will update to version <?php echo LATEST_AGENT_VERSION; ?> within 2-3 minutes.\n\nThe page will reload to show the new version.');
            setTimeout(() => {
                window.location.reload();
            }, 3000);
        } else {
            alert('❌ Error: ' + (data.message || 'Unknown error'));
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    })
    .catch(error => {
        alert('❌ Error queuing update: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    });
}

function generateEnrollmentKey() {
    const btn = document.getElementById('enrollKeyBtn');
    btn.disabled = true;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Generating...';

    fetch('api/enrollment_key.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({firewall_id: <?php echo $id; ?>})
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalHtml;

        if (data.success) {
            // Show modal with enrollment key
            showEnrollmentKeyModal(data.enrollment_key, data.expires_at);
        } else {
            alert('❌ Error: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        alert('❌ Error generating enrollment key: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    });
}

function showEnrollmentKeyModal(key, expiresAt) {
    // Create modal if it doesn't exist
    let modal = document.getElementById('enrollmentKeyModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'enrollmentKeyModal';
        modal.className = 'modal fade';
        modal.innerHTML = `
            <div class="modal-dialog modal-lg">
                <div class="modal-content bg-dark text-light">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title"><i class="fas fa-key me-2"></i>Enrollment Key</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-3">Copy this enrollment key and paste it into the OPNManager Agent plugin on your OPNsense firewall:</p>
                        <div class="mb-3">
                            <label class="form-label">Enrollment Key:</label>
                            <div class="input-group">
                                <input type="text" id="enrollmentKeyValue" class="form-control bg-secondary text-light font-monospace" readonly>
                                <button class="btn btn-primary" onclick="copyEnrollmentKey()">
                                    <i class="fas fa-copy me-1"></i>Copy
                                </button>
                            </div>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            This key expires on: <strong id="enrollmentKeyExpires"></strong>
                        </div>
                        <div class="alert alert-secondary">
                            <h6><i class="fas fa-list-ol me-2"></i>Instructions:</h6>
                            <ol class="mb-0">
                                <li>On OPNsense, go to <strong>Services → OPNManager Agent</strong></li>
                                <li>Paste this enrollment key in the <strong>Quick Enrollment</strong> section</li>
                                <li>Click <strong>Enroll</strong> to automatically configure the agent</li>
                            </ol>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    // Update values
    document.getElementById('enrollmentKeyValue').value = key;
    document.getElementById('enrollmentKeyExpires').textContent = new Date(expiresAt).toLocaleString();

    // Show modal
    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();
}

function copyEnrollmentKey() {
    const input = document.getElementById('enrollmentKeyValue');
    input.select();
    document.execCommand('copy');

    // Show feedback
    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check me-1"></i>Copied!';
    btn.classList.remove('btn-primary');
    btn.classList.add('btn-success');

    setTimeout(() => {
        btn.innerHTML = originalHtml;
        btn.classList.remove('btn-success');
        btn.classList.add('btn-primary');
    }, 2000);
}
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Dark background plugin for Chart.js
const darkBackgroundPlugin = {
    id: 'darkBackground',
    beforeDraw: (chart) => {
        const ctx = chart.ctx;
        ctx.save();
        ctx.fillStyle = '#1a1a1a';
        ctx.fillRect(0, 0, chart.width, chart.height);
        ctx.restore();
    }
};

// Store chart instances globally
let trafficChart = null;
let cpuChart = null;
let memoryChart = null;
let diskChart = null;
let latencyChart = null;
let speedtestChart = null;

// Update time label
function updateTimeLabel(days) {
    // Time label already shown in dropdown selector - don't duplicate it
}

// Initialize or update charts
function updateCharts() {
    console.log('updateCharts() called');
    const days = document.getElementById('timeFrameSelect')?.value || 1;
    console.log('Selected days:', days);
    updateTimeLabel(days);
    
    // Update traffic chart
    console.log('Calling updateTrafficChart...');
    updateTrafficChart(days);
    
    // Update system stats charts
    console.log('Calling updateSystemCharts...');
    updateSystemCharts();
}

// Update traffic chart
function updateTrafficChart(days) {
    
    // Traffic Chart
    const trafficCtx = document.getElementById('trafficChart');
    console.log('Traffic canvas element:', trafficCtx);
    if (trafficCtx) {
        const url = `/api/get_traffic_stats.php?firewall_id=<?php echo $firewall['id']; ?>&days=${days}`;
        console.log('Fetching traffic data from:', url);
        fetch(url, {credentials: 'include'})
            .then(r => {
                console.log('Traffic response status:', r.status);
                return r.json();
            })
            .then(data => {
                console.log('Traffic data received:', data);
                console.log('Traffic labels:', data.labels);
                console.log('Traffic inbound:', data.inbound);
                console.log('Traffic outbound:', data.outbound);
                
                if (!data.success) {
                    const ctx = trafficCtx.getContext('2d');
                    ctx.fillStyle = '#888';
                    ctx.font = '14px Arial';
                    ctx.fillText('Error loading data', 50, 100);
                    return;
                }
                
                // Destroy existing chart if it exists
                if (trafficChart) {
                    console.log('Destroying existing traffic chart');
                    trafficChart.destroy();
                }
                
                console.log('Creating new traffic chart with', data.labels.length, 'data points');
                
                trafficChart = new Chart(trafficCtx, {
                    type: 'line',
                    data: {
                        labels: data.labels || [],
                        datasets: [{
                            label: `Inbound (${data.unit || 'Mb/s'})`,
                            data: data.inbound || [],
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0,
                            pointRadius: 0,
                            pointHoverRadius: 4
                        }, {
                            label: `Outbound (${data.unit || 'Mb/s'})`,
                            data: data.outbound || [],
                            borderColor: '#667eea',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0,
                            pointRadius: 0,
                            pointHoverRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        devicePixelRatio: window.devicePixelRatio || 2,
                        plugins: { 
                            legend: { labels: { color: '#fff' } }
                        },
                        scales: {
                            y: { 
                                beginAtZero: true,
                                title: { display: true, text: data.unit || 'Mb/s', color: '#fff' },
                                ticks: { color: '#aaa' }, 
                                grid: { color: 'rgba(255,255,255,0.05)' }
                            },
                            x: { 
                                ticks: { color: '#aaa' }, 
                                grid: { color: 'rgba(255,255,255,0.05)' }
                            }
                        }
                    },
                    plugins: [darkBackgroundPlugin]
                });

                // Update traffic stats
                const inStats = calculateStats(data.inbound || []);
                const outStats = calculateStats(data.outbound || []);
                const unit = data.unit || 'Mb/s';

                document.getElementById('trafficInAvg').textContent = inStats.avg + ' ' + unit;
                document.getElementById('trafficInPeak').textContent = inStats.peak + ' ' + unit;
                document.getElementById('trafficInLow').textContent = inStats.low + ' ' + unit;
                document.getElementById('trafficOutAvg').textContent = outStats.avg + ' ' + unit;
                document.getElementById('trafficOutPeak').textContent = outStats.peak + ' ' + unit;
                document.getElementById('trafficOutLow').textContent = outStats.low + ' ' + unit;

                // Disable anti-aliasing for crisp lines
                const ctx = trafficCtx.getContext('2d');
                ctx.imageSmoothingEnabled = false;
            })
            .catch(err => {
                console.error('Traffic chart error:', err);
                const ctx = trafficCtx.getContext('2d');
                ctx.fillStyle = '#888';
                ctx.font = '14px Arial';
                ctx.fillText('No data available', 50, 100);
            });
    }
    
    // Update system stats charts too
    if (typeof updateSystemCharts === 'function') {
        updateSystemCharts();
    }
}

// Helper function to calculate stats
function calculateStats(dataArray) {
    if (!dataArray || dataArray.length === 0) {
        return { avg: 0, peak: 0, low: 0 };
    }
    const validData = dataArray.filter(v => v !== null && v !== undefined);
    if (validData.length === 0) {
        return { avg: 0, peak: 0, low: 0 };
    }
    const sum = validData.reduce((a, b) => a + b, 0);
    return {
        avg: (sum / validData.length).toFixed(2),
        peak: Math.max(...validData).toFixed(2),
        low: Math.min(...validData).toFixed(2)
    };
}

// Fetch and update system stats charts
function updateSystemCharts() {
    const days = document.getElementById('timeFrameSelect')?.value || 1;
    const firewallId = <?php echo $firewall['id']; ?>;

    // CPU Chart
    fetch(`/api/get_system_stats.php?firewall_id=${firewallId}&days=${days}&metric=cpu`, {credentials: 'include'})
        .then(r => r.json())
        .then(data => {
            console.log('CPU data received:', data);
            console.log('CPU labels:', data.labels, 'load_1min:', data.load_1min);
            if (data.success && cpuChart) {
                cpuChart.data.labels = data.labels || [];
                cpuChart.data.datasets = [{
                    label: '1 min avg',
                    data: data.load_1min || [],
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0,
                    pointRadius: 0,
                    pointHoverRadius: 4
                }];
                cpuChart.update();
                console.log('CPU chart updated with', data.labels.length, 'data points');

                // Update stats
                const cpuStats = calculateStats(data.load_1min || []);
                document.getElementById('cpuAvg').textContent = cpuStats.avg;
                document.getElementById('cpuPeak').textContent = cpuStats.peak;
                document.getElementById('cpuLow').textContent = cpuStats.low;
            }
        })
        .catch(err => console.error('CPU chart error:', err));
    
    // Memory Chart
    fetch(`/api/get_system_stats.php?firewall_id=${firewallId}&days=${days}&metric=memory`, {credentials: 'include'})
        .then(r => r.json())
        .then(data => {
            console.log('Memory data received:', data);
            console.log('Memory labels:', data.labels, 'usage:', data.usage);
            if (data.success && memoryChart) {
                memoryChart.data.labels = data.labels || [];
                memoryChart.data.datasets = [{
                    label: 'Memory Usage %',
                    data: data.usage || [],
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0,
                    pointRadius: 0,
                    pointHoverRadius: 4
                }];
                memoryChart.update();
                console.log('Memory chart updated with', data.labels.length, 'data points');

                // Update stats
                const memStats = calculateStats(data.usage || []);
                document.getElementById('memoryAvg').textContent = memStats.avg + '%';
                document.getElementById('memoryPeak').textContent = memStats.peak + '%';
                document.getElementById('memoryLow').textContent = memStats.low + '%';
            }
        })
        .catch(err => console.error('Memory chart error:', err));
    
    // Disk Chart
    fetch(`/api/get_system_stats.php?firewall_id=${firewallId}&days=${days}&metric=disk`, {credentials: 'include'})
        .then(r => r.json())
        .then(data => {
            console.log('Disk data received:', data);
            console.log('Disk labels:', data.labels, 'usage:', data.usage);
            if (data.success && diskChart) {
                diskChart.data.labels = data.labels || [];
                diskChart.data.datasets = [{
                    label: 'Disk Usage %',
                    data: data.usage || [],
                    borderColor: '#ec4899',
                    backgroundColor: 'rgba(236, 72, 153, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0,
                    pointRadius: 0,
                    pointHoverRadius: 4
                }];
                diskChart.update();

                // Update stats
                const diskStats = calculateStats(data.usage || []);
                document.getElementById('diskAvg').textContent = diskStats.avg + '%';
                document.getElementById('diskPeak').textContent = diskStats.peak + '%';
                document.getElementById('diskLow').textContent = diskStats.low + '%';
            }
        })
        .catch(err => console.error('Disk chart error:', err));
    
    // Latency Chart
    fetch(`/api/get_latency_stats.php?firewall_id=${firewallId}&days=${days}`, {credentials: 'include'})
        .then(r => {
            console.log('Latency response status:', r.status);
            return r.json();
        })
        .then(data => {
            console.log('Latency data received:', data);
            if (data.success && latencyChart) {
                console.log('Updating latency chart with', data.labels.length, 'labels and', data.latency.length, 'data points');
                latencyChart.data.labels = data.labels || [];
                latencyChart.data.datasets = [{
                    label: 'Latency (ms)',
                    data: data.latency || [],
                    borderColor: '#06b6d4',
                    backgroundColor: 'rgba(6, 182, 212, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0,
                    pointRadius: 0,
                    pointHoverRadius: 4
                }];
                latencyChart.update();

                // Update stats
                const latStats = calculateStats(data.latency || []);
                document.getElementById('latencyAvg').textContent = latStats.avg + ' ms';
                document.getElementById('latencyPeak').textContent = latStats.peak + ' ms';
                document.getElementById('latencyLow').textContent = latStats.low + ' ms';
            } else if (!data.success) {
                console.error('Latency API error:', data.error);
            } else {
                console.error('latencyChart not initialized');
            }
        })
        .catch(err => console.error('Latency chart error:', err));
    
    // SpeedTest Chart
    fetch(`/api/get_speedtest_results.php?firewall_id=${firewallId}&days=${days}`)
        .then(r => {
            console.log('SpeedTest response status:', r.status);
            return r.json();
        })
        .then(data => {
            console.log('SpeedTest data received:', data);
            if (data.success && speedtestChart) {
                // Limit to last 20 data points for cleaner display
                const maxPoints = 20;
                const labels = (data.labels || []).slice(-maxPoints);
                const downloadData = (data.download || []).slice(-maxPoints);
                const uploadData = (data.upload || []).slice(-maxPoints);

                console.log('Updating speedtest chart with', labels.length, 'data points');
                speedtestChart.data.labels = labels;
                speedtestChart.data.datasets = [
                    {
                        label: 'Download (Mbps)',
                        data: downloadData,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 4,
                        pointHoverRadius: 7,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Upload (Mbps)',
                        data: uploadData,
                        borderColor: '#f97316',
                        backgroundColor: 'rgba(249, 115, 22, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 4,
                        pointHoverRadius: 7,
                        yAxisID: 'y'
                    }
                ];
                speedtestChart.update('none'); // Use 'none' mode for instant update without animation

                // Update stats
                if (data.stats) {
                    document.getElementById('downloadAvg').textContent = data.stats.download.avg + ' Mbps';
                    document.getElementById('downloadPeak').textContent = data.stats.download.peak + ' Mbps';
                    document.getElementById('downloadLow').textContent = data.stats.download.low + ' Mbps';
                    document.getElementById('uploadAvg').textContent = data.stats.upload.avg + ' Mbps';
                    document.getElementById('uploadPeak').textContent = data.stats.upload.peak + ' Mbps';
                    document.getElementById('uploadLow').textContent = data.stats.upload.low + ' Mbps';
                }
            } else if (!data.success) {
                console.error('SpeedTest API error:', data.error);
            } else {
                console.error('speedtestChart not initialized');
            }
        })
        .catch(err => console.error('SpeedTest chart error:', err));
}

// Update speedtest chart with fresh data
function updateSpeedtestChart() {
    const firewallId = new URLSearchParams(window.location.search).get('id') || '<?php echo $firewall['id']; ?>';
    const days = 7; // Default to 7 days

    console.log('Updating speedtest chart for firewall', firewallId);

    fetch(`/api/get_speedtest_results.php?firewall_id=${firewallId}&days=${days}`)
        .then(r => {
            console.log('SpeedTest response status:', r.status);
            return r.json();
        })
        .then(data => {
            console.log('SpeedTest data received:', data);
            if (data.success && speedtestChart) {
                // Limit to last 20 data points for cleaner display
                const maxPoints = 20;
                const labels = (data.labels || []).slice(-maxPoints);
                const downloadData = (data.download || []).slice(-maxPoints);
                const uploadData = (data.upload || []).slice(-maxPoints);

                console.log('Updating speedtest chart with', labels.length, 'data points');
                speedtestChart.data.labels = labels;
                speedtestChart.data.datasets = [
                    {
                        label: 'Download (Mbps)',
                        data: downloadData,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 4,
                        pointHoverRadius: 7,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Upload (Mbps)',
                        data: uploadData,
                        borderColor: '#f97316',
                        backgroundColor: 'rgba(249, 115, 22, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 4,
                        pointHoverRadius: 7,
                        yAxisID: 'y'
                    }
                ];
                speedtestChart.update('none'); // Use 'none' mode for instant update without animation

                // Update stats
                if (data.stats) {
                    document.getElementById('downloadAvg').textContent = data.stats.download.avg + ' Mbps';
                    document.getElementById('downloadPeak').textContent = data.stats.download.peak + ' Mbps';
                    document.getElementById('downloadLow').textContent = data.stats.download.low + ' Mbps';
                    document.getElementById('uploadAvg').textContent = data.stats.upload.avg + ' Mbps';
                    document.getElementById('uploadPeak').textContent = data.stats.upload.peak + ' Mbps';
                    document.getElementById('uploadLow').textContent = data.stats.upload.low + ' Mbps';
                }
            } else if (!data.success) {
                console.error('SpeedTest API error:', data.error);
            } else {
                console.error('speedtestChart not initialized');
            }
        })
        .catch(err => console.error('SpeedTest chart error:', err));
}

// Trigger speedtest on-demand
function triggerSpeedtest() {
    const button = document.getElementById('speedtestButton');
    const firewallId = new URLSearchParams(window.location.search).get('id') || '<?php echo $firewall['id']; ?>';

    if (!firewallId) {
        alert('Firewall ID not found');
        return;
    }

    // Disable button and show loading state
    button.disabled = true;
    const originalHtml = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Running...';

    fetch(`/api/trigger_speedtest.php?firewall_id=${firewallId}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            button.innerHTML = '<i class="fas fa-check me-1"></i> Queued!';

            // Show info message
            console.log('Speedtest queued successfully. Will refresh results in 20 seconds...');

            // Auto-refresh chart after 20 seconds (enough time for agent to run test)
            setTimeout(() => {
                button.innerHTML = '<i class="fas fa-sync fa-spin me-1"></i> Updating...';
                updateSpeedtestChart(); // Refresh the chart data
                setTimeout(() => {
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                }, 2000);
            }, 20000);
        } else {
            alert('Error: ' + (data.error || 'Failed to queue speedtest'));
            button.disabled = false;
            button.innerHTML = originalHtml;
        }
    })
    .catch(err => {
        console.error('Speedtest trigger error:', err);
        alert('Error triggering speedtest: ' + err.message);
        button.disabled = false;
        button.innerHTML = originalHtml;
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM Content Loaded');
    console.log('Chart.js available:', typeof Chart !== 'undefined');
    console.log('Traffic canvas:', document.getElementById('trafficChart'));
    console.log('CPU canvas:', document.getElementById('cpuChart'));
    
    updateCharts(); // This now handles the traffic chart
    
    // Initialize CPU Chart
    const cpuCtx = document.getElementById('cpuChart');
    if (cpuCtx) {
        cpuChart = new Chart(cpuCtx, {
            type: 'line',
            data: { labels: [], datasets: [{ label: 'Loading...', data: [], borderColor: '#f59e0b', backgroundColor: 'rgba(245, 158, 11, 0.1)' }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                devicePixelRatio: window.devicePixelRatio || 2,
                plugins: { legend: { labels: { color: '#fff' } } },
                scales: {
                    y: { 
                        title: { display: true, text: 'Load Average', color: '#fff' },
                        ticks: { color: '#aaa' }, 
                        grid: { color: 'rgba(255,255,255,0.05)' }
                    },
                    x: { ticks: { color: '#aaa' }, grid: { color: 'rgba(255,255,255,0.05)' } }
                }
            },
            plugins: [darkBackgroundPlugin]
        });
    }
    
    // Initialize Memory Chart
    const memCtx = document.getElementById('memoryChart');
    if (memCtx) {
        memoryChart = new Chart(memCtx, {
            type: 'line',
            data: { labels: [], datasets: [{ label: 'Loading...', data: [], borderColor: '#8b5cf6', backgroundColor: 'rgba(139, 92, 246, 0.1)' }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                devicePixelRatio: window.devicePixelRatio || 2,
                plugins: { legend: { labels: { color: '#fff' } } },
                scales: {
                    y: {
                        beginAtZero: true,
                        grace: '10%',
                        title: { display: true, text: 'Usage %', color: '#fff' },
                        ticks: { color: '#aaa' },
                        grid: { color: 'rgba(255,255,255,0.05)' }
                    },
                    x: { ticks: { color: '#aaa' }, grid: { color: 'rgba(255,255,255,0.05)' } }
                }
            },
            plugins: [darkBackgroundPlugin]
        });
    }
    
    // Initialize Disk Chart
    const diskCtx = document.getElementById('diskChart');
    if (diskCtx) {
        diskChart = new Chart(diskCtx, {
            type: 'line',
            data: { labels: [], datasets: [{ label: 'Loading...', data: [], borderColor: '#ec4899', backgroundColor: 'rgba(236, 72, 153, 0.1)' }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                devicePixelRatio: window.devicePixelRatio || 2,
                plugins: { legend: { labels: { color: '#fff' } } },
                scales: {
                    y: {
                        beginAtZero: true,
                        grace: '10%',
                        title: { display: true, text: 'Usage %', color: '#fff' },
                        ticks: { color: '#aaa' },
                        grid: { color: 'rgba(255,255,255,0.05)' }
                    },
                    x: { ticks: { color: '#aaa' }, grid: { color: 'rgba(255,255,255,0.05)' } }
                }
            },
            plugins: [darkBackgroundPlugin]
        });
    }
    
    // Initialize Latency Chart
    const latencyCtx = document.getElementById('latencyChart');
    if (latencyCtx) {
        latencyChart = new Chart(latencyCtx, {
            type: 'line',
            data: { labels: [], datasets: [{ label: 'Latency (ms)', data: [], borderColor: '#06b6d4', backgroundColor: 'rgba(6, 182, 212, 0.1)' }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                devicePixelRatio: window.devicePixelRatio || 2,
                plugins: { legend: { labels: { color: '#fff' } } },
                scales: {
                    y: { 
                        beginAtZero: true,
                        title: { display: true, text: 'Latency (ms)', color: '#fff' },
                        ticks: { color: '#aaa' }, 
                        grid: { color: 'rgba(255,255,255,0.05)' }
                    },
                    x: { ticks: { color: '#aaa' }, grid: { color: 'rgba(255,255,255,0.05)' } }
                }
            },
            plugins: [darkBackgroundPlugin]
        });
    }
    
    // Initialize SpeedTest Chart
    const speedtestCtx = document.getElementById('speedtestChart');
    if (speedtestCtx) {
        speedtestChart = new Chart(speedtestCtx, {
            type: 'line',
            data: { labels: [], datasets: [
                { label: 'Download (Mbps)', data: [], borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.1)', tension: 0.3, pointRadius: 4, pointHoverRadius: 7, borderWidth: 2, yAxisID: 'y' },
                { label: 'Upload (Mbps)', data: [], borderColor: '#f97316', backgroundColor: 'rgba(249, 115, 22, 0.1)', tension: 0.3, pointRadius: 4, pointHoverRadius: 7, borderWidth: 2, yAxisID: 'y' }
            ] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        labels: {
                            color: '#fff',
                            font: { size: 13, weight: '500' },
                            padding: 15,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.9)',
                        titleColor: '#fff',
                        titleFont: { size: 13, weight: 'bold' },
                        bodyColor: '#fff',
                        bodyFont: { size: 12 },
                        padding: 12,
                        borderColor: '#666',
                        borderWidth: 1,
                        displayColors: true
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Speed (Mbps)', color: '#fff', font: { size: 12, weight: '500' } },
                        ticks: {
                            color: '#bbb',
                            font: { size: 11 },
                            padding: 8
                        },
                        grid: { color: 'rgba(255,255,255,0.1)', drawBorder: false, lineWidth: 1 }
                    },
                    x: {
                        ticks: {
                            color: '#bbb',
                            font: { size: 11 },
                            maxRotation: 45,
                            minRotation: 45,
                            padding: 5
                        },
                        grid: { color: 'rgba(255,255,255,0.05)', drawBorder: false }
                    }
                }
            },
            plugins: [darkBackgroundPlugin]
        });
    }
    
    // Load system stats data
    updateSystemCharts();
});
</script>

<style>
/* Tab Styling */
#firewallTabs {
    flex-wrap: wrap !important;
}

#firewallTabs .nav-link {
    color: #95a5a6;
    background: #2c3e50;
    border: 1px solid #34495e;
    margin-right: 10px;
    margin-bottom: 8px;
    padding: 10px 15px !important;
    border-radius: 5px 5px 0 0;
    transition: all 0.3s ease;
}

#firewallTabs .nav-link:hover {
    color: #ecf0f1;
    background: #34495e;
    border-color: #3498db;
}

#firewallTabs .nav-link.active {
    color: #fff;
    background: #3498db;
    border-color: #3498db;
    font-weight: bold;
}

#firewallTabs .nav-link i {
    opacity: 0.8;
}

#firewallTabs .nav-link.active i {
    opacity: 1;
}

/* Tab Content Animation */
.tab-content {
    min-height: auto !important;
    padding: 0 !important;
    width: 100% !important;
}

.tab-pane {
    animation: fadeIn 0.3s ease-in;
    padding: 0 !important;
    margin: 0 !important;
    min-height: auto;
    width: 100% !important;
    display: none !important;
}

.tab-pane.active {
    display: block !important;
    opacity: 1 !important;
    visibility: visible !important;
}

.tab-pane.show {
    display: block !important;
    opacity: 1 !important;
}

.tab-pane.fade {
    opacity: 1 !important;
    transition: none !important;
}

.tab-pane.active, .tab-pane.show {
    display: block !important;
}

/* Fix spacing for all tabs */
#overview, #backups, #ai, #network, #commands, #security {
    min-height: auto !important;
    padding: 0 !important;
    margin: 0 !important;
    width: 100% !important;
    overflow-x: hidden !important;
}

/* Card adjustments */
.tab-pane .card {
    width: 100% !important;
    overflow-x: hidden !important;
    margin: 0 0 1.5rem 0 !important;
}

.tab-pane .row {
    width: 100% !important;
    margin: 0 !important;
}

#ai > *, #network > * {
    display: block !important;
    visibility: visible !important;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Command Log Table Styling */
#commandLogTableBody tr {
    border-bottom: 1px solid #3a4149;
    transition: background-color 0.2s ease;
}

#commandLogTableBody tr:hover {
    background-color: #353a42;
}

#commandLogTableBody td {
    vertical-align: middle;
    padding: 10px 8px;
    font-size: 0.9rem;
}

#commandLogTableBody td:first-child {
    color: #a0aec0;
    white-space: nowrap;
}

#commandLogTableBody td:nth-child(2) {
    color: #e2e8f0;
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

#commandLogTableBody .badge {
    font-size: 0.8rem;
    padding: 4px 8px;
}

#commandLogTableBody .btn-outline-info {
    padding: 4px 8px;
    font-size: 0.75rem;
}

#commandLogTableBody .btn-outline-info:hover {
    background-color: #3182ce;
    border-color: #3182ce;
}

.table-responsive {
    border-radius: 4px;
    border: 1px solid #3a4149;
}

.table.table-dark thead {
    background-color: #2d3748;
    border-bottom: 2px solid #4a5568;
}

.table.table-dark thead th {
    color: #cbd5e0;
    font-size: 0.85rem;
    font-weight: 600;
    padding: 12px 8px;
    letter-spacing: 0.5px;
}
</style>

<script>
// Set the current firewall ID for use in all scripts
const currentFirewallId = <?php echo (int)$firewall['id']; ?>;

// Handle Secure Outbound Lockdown toggle
document.getElementById('secure_outbound_toggle').addEventListener('change', function() {
    const isEnabled = this.checked;
    const firewallId = <?php echo $firewall['id']; ?>;
    
    // Show confirmation dialog
    const action = isEnabled ? 'enable' : 'disable';
    const confirmMessage = isEnabled 
        ? 'This will block ALL outbound traffic except HTTP/HTTPS and force DNS through Unbound.\n\nAre you sure you want to enable Secure Outbound Lockdown?'
        : 'This will disable Secure Outbound Lockdown and restore normal outbound rules.\n\nAre you sure?';
    
    if (!confirm(confirmMessage)) {
        // Revert toggle if user cancels
        this.checked = !isEnabled;
        return;
    }
    
    // Disable toggle while processing
    this.disabled = true;
    const originalText = this.nextElementSibling.textContent;
    this.nextElementSibling.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Applying configuration...';
    
    // Send configuration to server
    const formData = new FormData();
    formData.append('firewall_id', firewallId);
    formData.append('enable', isEnabled ? 1 : 0);
    
    fetch('/api/apply_secure_lockdown.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success alert-dismissible fade show mt-3';
            alertDiv.setAttribute('role', 'alert');
            alertDiv.innerHTML = `
                <i class="fas fa-check-circle me-2"></i>
                <strong>Success!</strong> ${data.message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.querySelector('.card.card-ghost').insertAdjacentElement('afterend', alertDiv);
            
            // Auto-dismiss after 5 seconds
            setTimeout(() => alertDiv.remove(), 5000);
            
            // Update UI to reflect status
            if (isEnabled) {
                document.getElementById('secure_outbound_toggle').classList.add('border-success');
            } else {
                document.getElementById('secure_outbound_toggle').classList.remove('border-success');
            }
        } else {
            throw new Error(data.error || 'Unknown error occurred');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to apply configuration: ' + error.message);
        // Revert toggle on error
        this.checked = !isEnabled;
    })
    .finally(() => {
        // Re-enable toggle
        this.disabled = false;
        this.nextElementSibling.textContent = originalText;
        });
});

// ============ SSH KEY MANAGEMENT ============
async function loadSSHKeys() {
    console.log('[SSH Keys] loadSSHKeys() called for firewall:', currentFirewallId);
    try {
        const container = document.getElementById('sshKeysList');
        console.log('[SSH Keys] Container element:', container);
        if (!container) {
            console.warn('[SSH Keys] sshKeysList container not found');
            return;
        }
        
        console.log('[SSH Keys] Fetching from /api/manage_ssh_keys.php?firewall_id=' + currentFirewallId);
        const response = await fetch(`/api/manage_ssh_keys.php?firewall_id=${currentFirewallId}`);
        
        if (!response.ok) {
            console.error(`[SSH Keys] API returned status: ${response.status}`);
            container.innerHTML = '<p class="text-warning">SSH keys service unavailable</p>';
            return;
        }
        
        const data = await response.json();
        console.log('[SSH Keys] API response:', data);
        
        if (!data || !data.success) {
            console.error('[SSH Keys] API returned error:', data);
            container.innerHTML = '<p class="text-warning">Unable to load SSH keys</p>';
            return;
        }
        
        if (data.keys && data.keys.length > 0) {
            console.log('[SSH Keys] Found ' + data.keys.length + ' keys');
            container.innerHTML = '';
            data.keys.forEach(key => {
                const keyDiv = document.createElement('div');
                keyDiv.className = 'card bg-dark border-secondary mb-2';
                // Obfuscate the fingerprint - show first 8 and last 8 chars with asterisks in between
                const fp = key.fingerprint;
                const obfuscatedFp = fp.substring(0, 8) + '*'.repeat(Math.max(0, fp.length - 16)) + fp.substring(Math.max(8, fp.length - 8));
                keyDiv.innerHTML = `
                    <div class="card-body p-2">
                        <div class="row align-items-center">
                            <div class="col-md-9">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <small class="text-white">
                                            <i class="fas fa-certificate text-success me-2"></i>
                                            ${escapeHtml(key.key_type)} (${key.key_bits}b)
                                        </small>
                                    </div>
                                    <span class="badge ${key.is_active ? 'bg-success' : 'bg-secondary'} ms-2">${key.is_active ? 'ACTIVE' : 'INACTIVE'}</span>
                                </div>
                                <code style="color: #4fc3f7; font-size: 0.75rem; font-weight: 500;" class="d-block mt-1" title="Fingerprint (obfuscated for security)">${escapeHtml(obfuscatedFp)}</code>
                                <small class="text-muted d-block" style="font-size: 0.7rem;">
                                    Created: ${key.created_at ? new Date(key.created_at).toLocaleDateString() : 'Unknown'}
                                </small>
                            </div>
                            <div class="col-md-3 text-end">
                                <button class="btn btn-danger btn-sm" onclick="deleteSSHKey(${key.id})" title="Delete key">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                `;
                container.appendChild(keyDiv);
            });
        } else {
            console.log('[SSH Keys] No SSH keys found');
            container.innerHTML = '<p class="text-muted">No SSH keys found. Click "Regenerate Keys" to create one.</p>';
        }
    } catch (error) {
        console.error('[SSH Keys] Error loading SSH keys:', error);
        const container = document.getElementById('sshKeysList');
        if (container) {
            container.innerHTML = '<p class="text-info">SSH keys unavailable - please check your connection</p>';
        }
    }
}

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
}

function initializeSSHKeys() {
    console.log('[SSH Keys] initializeSSHKeys() called');
    if (document.readyState === 'loading') {
        console.log('[SSH Keys] DOM still loading, deferring...');
        document.addEventListener('DOMContentLoaded', function() {
            console.log('[SSH Keys] DOMContentLoaded fired, now loading SSH keys');
            loadSSHKeys();
            attachSecurityTabListener();
        });
    } else {
        console.log('[SSH Keys] DOM already loaded, loading SSH keys immediately');
        loadSSHKeys();
        attachSecurityTabListener();
    }
}

function attachSecurityTabListener() {
    const securityTab = document.getElementById('security-tab');
    console.log('[SSH Keys] Security tab element:', securityTab);
    if (securityTab) {
        securityTab.addEventListener('shown.bs.tab', function() {
            console.log('[SSH Keys] Security tab shown - reloading SSH Keys');
            try {
                if (typeof loadSSHKeys === 'function') {
                    loadSSHKeys();
                }
            } catch (error) {
                console.error('[SSH Keys] Error reloading SSH Keys:', error);
            }
        });
    }
}

async function regenerateSSHKeys() {
    if (!confirm('Are you sure you want to regenerate SSH keys? This will disable the current key pair.')) {
        return;
    }
    try {
        const response = await fetch('/api/manage_ssh_keys.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'regenerate',
                firewall_id: currentFirewallId
            })
        });
        const data = await response.json();
        if (data.success) {
            alert('✓ SSH keys regenerated successfully!\n\nKey Type: ' + data.key.type + '\nBits: ' + data.key.bits + '\nFingerprint: ' + data.key.fingerprint);
            loadSSHKeys();
        } else {
            alert('Error: ' + (data.message || 'Failed to regenerate SSH keys'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error: ' + error.message);
    }
}

async function deleteSSHKey(keyId) {
    if (!confirm('Are you sure you want to delete this SSH key?')) {
        return;
    }
    try {
        const response = await fetch('/api/manage_ssh_keys.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'delete',
                firewall_id: currentFirewallId,
                key_id: keyId
            })
        });
        const data = await response.json();
        if (data.success) {
            alert('✓ SSH key deleted successfully');
            loadSSHKeys();
        } else {
            alert('Error: ' + (data.message || 'Failed to delete SSH key'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error: ' + error.message);
    }
}

console.log('[SSH Keys] Script loaded, calling initializeSSHKeys');
initializeSSHKeys();

</script>

                    <!-- Security Tab -->
                    <!-- Security Tab -->
                    <div class="tab-pane fade" id="security" role="tabpanel" aria-labelledby="security-tab">
                    
                        <!-- SSH Key Management Section -->
                        <div class="card card-ghost p-3 border border-success mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="text-white fw-bold mb-0" style="font-size: 1rem;">
                                    <i class="fas fa-key me-2 text-success"></i>SSH Keys
                                </h6>
                                <button type="button" class="btn btn-success btn-sm" onclick="regenerateSSHKeys()" title="Generate new SSH key pair">
                                    <i class="fas fa-sync me-1"></i>Regenerate
                                </button>
                            </div>
                            
                            <!-- SSH Keys List -->
                            <div id="sshKeysList" class="mt-2">
                                <p class="text-muted small">Loading SSH keys...</p>
                            </div>
                        </div>
                        
                        <!-- Security Audit Section -->
                        <div class="card card-ghost p-3 border border-warning mb-3">
                            <h6 class="text-white fw-bold mb-2" style="font-size: 1rem;">
                                <i class="fas fa-shield-alt me-2 text-warning"></i>Security Status
                            </h6>
                            
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="card bg-dark border-secondary">
                                        <div class="card-body p-2">
                                            <small class="text-light d-block">SSH Access</small>
                                            <span class="text-success small"><i class="fas fa-check-circle me-1"></i>Enabled</span>
                                            <small class="text-muted d-block" style="font-size: 0.75rem;">Port 22</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card bg-dark border-secondary">
                                        <div class="card-body p-2">
                                            <small class="text-light d-block">API Authentication</small>
                                            <span class="text-success small"><i class="fas fa-check-circle me-1"></i>Enabled</span>
                                            <small class="text-muted d-block" style="font-size: 0.75rem;">Token-based auth</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div><!-- End Security Tab -->
                    
                    </div><!-- End tab-content -->

<?php include __DIR__ . '/inc/footer.php'; ?>
