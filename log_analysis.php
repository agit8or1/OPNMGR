<?php
/**
 * Log Analysis Dashboard
 * View and manage firewall log analysis results
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once 'inc/db.php';
require_once 'inc/auth.php';
requireLogin();

$page_title = "Log Analysis";
include 'inc/header.php';

// Add custom CSS for dark mode contrast
echo '<style>
.text-primary { color: #4fc3f7 !important; }
.text-info { color: #5dade2 !important; }
.text-warning { color: #f5b041 !important; }
.text-danger { color: #ec7063 !important; }
.text-success { color: #82e0aa !important; }
.text-secondary { color: #d5d8dc !important; }
.text-white { color: #e0e0e0 !important; }
.card { background: #1a1d23 !important; border-color: #3a3f4b !important; }
.card-header { background: #1a1d23 !important; border-color: #3a3f4b !important; color: #e0e0e0 !important; }
.card-body { color: #e0e0e0; }
.border-primary { border-color: #4fc3f7 !important; }
.border-info { border-color: #5dade2 !important; }
.border-warning { border-color: #f5b041 !important; }
.border-danger { border-color: #ec7063 !important; }
.bg-dark { background: #0d0f12 !important; }
.text-muted { color: #95a5a6 !important; }
</style>';

// Get filter parameters
$firewall_filter = $_GET['firewall_id'] ?? null;
$threat_level = $_GET['threat_level'] ?? null;
$date_range = $_GET['date_range'] ?? '7'; // days

// Build query
$query = "SELECT 
    lar.*,
    f.hostname as firewall_name,
    asr.overall_grade,
    asr.security_score,
    asr.scan_type,
    asr.created_at as scan_date
FROM log_analysis_results lar
JOIN ai_scan_reports asr ON lar.report_id = asr.id
JOIN firewalls f ON asr.firewall_id = f.id
WHERE asr.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";

$params = [$date_range];

if ($firewall_filter) {
    $query .= " AND asr.firewall_id = ?";
    $params[] = $firewall_filter;
}

if ($threat_level) {
    $query .= " AND lar.threat_level = ?";
    $params[] = $threat_level;
}

$query .= " ORDER BY asr.created_at DESC";

$stmt = $DB->prepare($query);
$stmt->execute($params);
$analyses = $stmt->fetchAll();

// Get statistics
$stats_query = "SELECT 
    COUNT(DISTINCT lar.id) as total_analyses,
    COUNT(DISTINCT asr.firewall_id) as firewalls_analyzed,
    SUM(lar.active_threats IS NOT NULL AND JSON_LENGTH(lar.active_threats) > 0) as total_threats,
    SUM(lar.blocked_attempts) as total_blocks,
    SUM(lar.failed_auth_attempts) as total_failed_auth,
    AVG(lar.anomaly_score) as avg_anomaly_score
FROM log_analysis_results lar
JOIN ai_scan_reports asr ON lar.report_id = asr.id
WHERE asr.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";

$stats_stmt = $DB->prepare($stats_query);
$stats_stmt->execute([$date_range]);
$stats = $stats_stmt->fetch();

// Get threat level breakdown
$threat_breakdown = $DB->prepare("
    SELECT threat_level, COUNT(*) as count
    FROM log_analysis_results lar
    JOIN ai_scan_reports asr ON lar.report_id = asr.id
    WHERE asr.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY threat_level
");
$threat_breakdown->execute([$date_range]);
$threat_levels = $threat_breakdown->fetchAll();

// Get firewall list for filter
$firewalls = $DB->query("SELECT id, hostname FROM firewalls ORDER BY hostname")->fetchAll();
?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2><i class="fas fa-chart-line"></i> Log Analysis Dashboard</h2>
            <p class="text-muted">Real-time threat detection and log monitoring</p>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card border-primary">
                <div class="card-body text-center">
                    <h5 class="text-muted">Total Analyses</h5>
                    <h2 class="text-primary"><?php echo number_format($stats['total_analyses'] ?? 0); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-info">
                <div class="card-body text-center">
                    <h5 class="text-muted">Firewalls</h5>
                    <h2 class="text-info"><?php echo number_format($stats['firewalls_analyzed'] ?? 0); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-danger">
                <div class="card-body text-center">
                    <h5 class="text-muted">Active Threats</h5>
                    <h2 class="text-danger"><?php echo number_format($stats['total_threats'] ?? 0); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-warning">
                <div class="card-body text-center">
                    <h5 class="text-muted">Blocked Attempts</h5>
                    <h2 class="text-warning"><?php echo number_format($stats['total_blocks'] ?? 0); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-secondary">
                <div class="card-body text-center">
                    <h5 class="text-muted">Failed Auth</h5>
                    <h2 class="text-secondary"><?php echo number_format($stats['total_failed_auth'] ?? 0); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-dark">
                <div class="card-body text-center">
                    <h5 class="text-muted">Anomaly Score</h5>
                    <h2 class="text-white"><?php echo number_format($stats['avg_anomaly_score'] ?? 0); ?></h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Threat Level Breakdown -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-dark text-white">
                    <i class="fas fa-exclamation-triangle"></i> Threat Level Distribution
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <?php
                        $threat_colors = [
                            'none' => 'success',
                            'low' => 'info',
                            'medium' => 'warning',
                            'high' => 'orange',
                            'critical' => 'danger'
                        ];
                        
                        $threat_counts = array_fill_keys(['none', 'low', 'medium', 'high', 'critical'], 0);
                        foreach ($threat_levels as $level) {
                            $threat_counts[$level['threat_level']] = $level['count'];
                        }
                        
                        foreach ($threat_counts as $level => $count):
                            $color = $threat_colors[$level];
                        ?>
                        <div class="col">
                            <div class="badge badge-<?php echo $color; ?> p-3" style="font-size: 1.2em; width: 100%;">
                                <div><?php echo strtoupper($level); ?></div>
                                <div style="font-size: 1.5em; font-weight: bold;"><?php echo $count; ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="row mb-3">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" class="form-inline">
                        <label class="mr-2">Filter:</label>
                        <select name="firewall_id" class="form-control mr-2">
                            <option value="">All Firewalls</option>
                            <?php foreach ($firewalls as $fw): ?>
                                <option value="<?php echo $fw['id']; ?>" <?php echo $firewall_filter == $fw['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($fw['hostname']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select name="threat_level" class="form-control mr-2">
                            <option value="">All Threat Levels</option>
                            <option value="critical" <?php echo $threat_level == 'critical' ? 'selected' : ''; ?>>Critical</option>
                            <option value="high" <?php echo $threat_level == 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="medium" <?php echo $threat_level == 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="low" <?php echo $threat_level == 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="none" <?php echo $threat_level == 'none' ? 'selected' : ''; ?>>None</option>
                        </select>
                        
                        <select name="date_range" class="form-control mr-2">
                            <option value="1" <?php echo $date_range == '1' ? 'selected' : ''; ?>>Last 24 Hours</option>
                            <option value="7" <?php echo $date_range == '7' ? 'selected' : ''; ?>>Last 7 Days</option>
                            <option value="30" <?php echo $date_range == '30' ? 'selected' : ''; ?>>Last 30 Days</option>
                            <option value="90" <?php echo $date_range == '90' ? 'selected' : ''; ?>>Last 90 Days</option>
                        </select>
                        
                        <button type="submit" class="btn btn-primary mr-2">Apply</button>
                        <a href="log_analysis.php" class="btn btn-secondary">Reset</a>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Analysis Results -->
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-list"></i> Recent Log Analyses (<?php echo count($analyses); ?>)
                </div>
                <div class="card-body p-0">
                    <?php if (empty($analyses)): ?>
                        <div class="p-4 text-center text-muted">
                            <i class="fas fa-info-circle fa-3x mb-3"></i>
                            <p>No log analyses found for the selected filters.</p>
                            <p>Run an AI scan with "config_with_logs" option to analyze firewall logs.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Firewall</th>
                                        <th>Log Type</th>
                                        <th>Scan Date</th>
                                        <th>Lines Analyzed</th>
                                        <th>Threat Level</th>
                                        <th>Threats</th>
                                        <th>Blocked</th>
                                        <th>Failed Auth</th>
                                        <th>Anomaly Score</th>
                                        <th>Grade</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($analyses as $analysis): 
                                        $threat_color = $threat_colors[$analysis['threat_level']];
                                        $active_threats = json_decode($analysis['active_threats'], true) ?? [];
                                        $threat_count = count($active_threats);
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($analysis['firewall_name']); ?></strong></td>
                                        <td><span class="badge badge-secondary"><?php echo strtoupper($analysis['log_type']); ?></span></td>
                                        <td><?php echo date('M d, Y H:i', strtotime($analysis['scan_date'])); ?></td>
                                        <td><?php echo number_format($analysis['lines_analyzed']); ?></td>
                                        <td><span class="badge badge-<?php echo $threat_color; ?>"><?php echo strtoupper($analysis['threat_level']); ?></span></td>
                                        <td><?php echo $threat_count; ?></td>
                                        <td><?php echo number_format($analysis['blocked_attempts']); ?></td>
                                        <td><?php echo number_format($analysis['failed_auth_attempts']); ?></td>
                                        <td><?php echo $analysis['anomaly_score']; ?></td>
                                        <td><span class="badge badge-info"><?php echo $analysis['overall_grade']; ?></span></td>
                                        <td>
                                            <a href="ai_reports.php?report_id=<?php echo $analysis['report_id']; ?>" class="btn btn-sm btn-primary">
                                                <i class="fas fa-eye"></i> View Report
                                            </a>
                                            <a href="threat_details.php?analysis_id=<?php echo $analysis['id']; ?>" class="btn btn-sm btn-warning">
                                                <i class="fas fa-shield-alt"></i> Threats
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'inc/footer.php'; ?>
