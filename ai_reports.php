<?php
// AI Security Reports
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();

// Function to decode serialized array data
function decodeReportField($value) {
    if (empty($value)) return '';
    if ($value === 'Array') return '';
    if (is_string($value) && strpos($value, 'a:') === 0) {
        $decoded = @unserialize($value);
        if (is_array($decoded)) {
            return implode("\n• ", array_filter($decoded));
        }
    }
    return $value;
}

$page_title = "AI Security Reports";
include 'inc/header.php';

$firewall_id = $_GET['firewall_id'] ?? null;
$report_id = $_GET['report_id'] ?? null;

// Get firewall list for filter
$firewalls = db()->query("SELECT id, hostname FROM firewalls ORDER BY hostname ASC")->fetchAll();

// Build query
$where = [];
$params = [];

if ($firewall_id) {
    $where[] = "r.firewall_id = ?";
    $params[] = $firewall_id;
}

$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Get all reports
$reports = db()->prepare("
    SELECT r.*, f.hostname as firewall_name, f.ip_address,
           (SELECT COUNT(*) FROM ai_scan_findings WHERE report_id = r.id) as findings_count
    FROM ai_scan_reports r
    JOIN firewalls f ON r.firewall_id = f.id
    {$where_clause}
    ORDER BY r.created_at DESC
    LIMIT 50
");
$reports->execute($params);
$all_reports = $reports->fetchAll();

// Get specific report if requested
$current_report = null;
$report_findings = [];
if ($report_id) {
    $stmt = db()->prepare("
        SELECT r.*, f.hostname as firewall_name, f.ip_address
        FROM ai_scan_reports r
        JOIN firewalls f ON r.firewall_id = f.id
        WHERE r.id = ?
    ");
    $stmt->execute([$report_id]);
    $current_report = $stmt->fetch();
    
    if ($current_report) {
        // Fetch findings grouped by source
        $stmt = db()->prepare("SELECT * FROM ai_scan_findings WHERE report_id = ? AND source = 'config' ORDER BY FIELD(severity, 'critical', 'high', 'medium', 'low', 'info')");
        $stmt->execute([$report_id]);
        $config_findings = $stmt->fetchAll();

        $stmt = db()->prepare("SELECT * FROM ai_scan_findings WHERE report_id = ? AND source = 'logs' ORDER BY FIELD(severity, 'critical', 'high', 'medium', 'low', 'info')");
        $stmt->execute([$report_id]);
        $log_findings = $stmt->fetchAll();

        $report_findings = array_merge($config_findings, $log_findings);  // For backward compatibility
    }
}

// Get statistics
$stats = db()->query("
    SELECT 
        COUNT(*) as total_scans,
        AVG(security_score) as avg_score,
        SUM(CASE WHEN risk_level = 'critical' THEN 1 ELSE 0 END) as critical_count,
        SUM(CASE WHEN risk_level = 'high' THEN 1 ELSE 0 END) as high_count,
        SUM(CASE WHEN risk_level = 'medium' THEN 1 ELSE 0 END) as medium_count,
        SUM(CASE WHEN risk_level = 'low' THEN 1 ELSE 0 END) as low_count
    FROM ai_scan_reports
")->fetch();
?>

<style>
body {
    background: #1a1d23;
    color: #e0e0e0;
}
.reports-container {
    max-width: 1600px;
    margin: 30px auto;
    padding: 0 20px;
}
.reports-card {
    background: #2d3139;
    border-radius: 8px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    border: 1px solid #3a3f4b;
}
.reports-card h2 {
    color: #4fc3f7;
    border-bottom: 3px solid #3498db;
    padding-bottom: 10px;
    margin-bottom: 20px;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}
.stat-box {
    background: #1a1d23;
    padding: 20px;
    border-radius: 6px;
    border-left: 4px solid #3498db;
}
.stat-box.critical { border-left-color: #e74c3c; }
.stat-box.high { border-left-color: #e67e22; }
.stat-box.medium { border-left-color: #f39c12; }
.stat-box.low { border-left-color: #27ae60; }
.stat-number {
    font-size: 32px;
    font-weight: bold;
    color: #4fc3f7;
    margin: 10px 0;
}
.stat-label {
    color: #95a5a6;
    font-size: 14px;
    text-transform: uppercase;
}
.reports-table {
    width: 100%;
    margin-top: 15px;
}
.reports-table th {
    background: #1a1d23;
    color: #4fc3f7;
    padding: 12px;
    text-align: left;
    border-bottom: 2px solid #3498db;
    font-size: 14px;
}
.reports-table td {
    padding: 12px;
    border-bottom: 1px solid #3a3f4b;
    font-size: 14px;
}
.reports-table tr:hover {
    background: #363a45;
    cursor: pointer;
}
.grade-badge {
    display: inline-block;
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 18px;
    font-weight: bold;
    min-width: 50px;
    text-align: center;
}
.grade-A, .grade-A\+ { background: #27ae60; color: white; }
.grade-B { background: #3498db; color: white; }
.grade-C { background: #f39c12; color: white; }
.grade-D { background: #e67e22; color: white; }
.grade-F { background: #e74c3c; color: white; }
.risk-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}
.risk-low { background: #27ae60; color: white; }
.risk-medium { background: #f39c12; color: white; }
.risk-high { background: #e67e22; color: white; }
.risk-critical { background: #e74c3c; color: white; }
.severity-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}
.severity-info { background: #3498db; color: white; }
.severity-low { background: #27ae60; color: white; }
.severity-medium { background: #f39c12; color: white; }
.severity-high { background: #e67e22; color: white; }
.severity-critical { background: #e74c3c; color: white; }
.score-bar {
    width: 100%;
    height: 30px;
    background: #1a1d23;
    border-radius: 15px;
    overflow: hidden;
    position: relative;
    margin: 10px 0;
}
.score-fill {
    height: 100%;
    border-radius: 15px;
    transition: width 0.5s;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    color: white;
}
.score-90 { background: linear-gradient(90deg, #27ae60, #2ecc71); }
.score-80 { background: linear-gradient(90deg, #3498db, #5dade2); }
.score-70 { background: linear-gradient(90deg, #f39c12, #f5b041); }
.score-60 { background: linear-gradient(90deg, #e67e22, #eb984e); }
.score-low { background: linear-gradient(90deg, #e74c3c, #ec7063); }
.report-detail {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 25px;
}
.report-sidebar {
    background: #1a1d23;
    padding: 20px;
    border-radius: 8px;
    border: 1px solid #3a3f4b;
}
.report-main {
    background: #1a1d23;
    padding: 25px;
    border-radius: 8px;
    border: 1px solid #3a3f4b;
}
.section-title {
    color: #81c784;
    font-size: 18px;
    margin: 25px 0 15px 0;
    padding-bottom: 8px;
    border-bottom: 2px solid #3a3f4b;
}
.finding-card {
    background: #2d3139;
    padding: 15px;
    margin-bottom: 15px;
    border-radius: 6px;
    border-left: 4px solid #3498db;
}
.finding-card.critical { border-left-color: #e74c3c; }
.finding-card.high { border-left-color: #e67e22; }
.finding-card.medium { border-left-color: #f39c12; }
.finding-card.low { border-left-color: #27ae60; }
.finding-title {
    color: #4fc3f7;
    font-weight: 600;
    margin-bottom: 8px;
    font-size: 15px;
}
.finding-description {
    color: #95a5a6;
    margin-bottom: 10px;
    font-size: 14px;
    line-height: 1.5;
}
.finding-recommendation {
    background: rgba(52, 152, 219, 0.1);
    padding: 10px;
    border-radius: 4px;
    margin-top: 10px;
    font-size: 13px;
}
.btn {
    padding: 10px 20px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.3s;
    text-decoration: none;
    display: inline-block;
}
.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}
.filter-bar {
    display: flex;
    gap: 15px;
    margin-bottom: 20px;
    align-items: center;
}
.form-control {
    padding: 10px;
    background: #1a1d23;
    border: 1px solid #3a3f4b;
    border-radius: 4px;
    color: #e0e0e0;
    font-size: 14px;
}
.back-link {
    color: #3498db;
    text-decoration: none;
    font-size: 14px;
}
.back-link:hover {
    color: #5dade2;
}
.finding-card { background: #2d3139; padding: 20px; margin-bottom: 20px; border-radius: 8px; border-left: 5px solid #3498db; box-shadow: 0 2px 4px rgba(0,0,0,0.2); transition: box-shadow 0.3s; } .finding-card:hover { box-shadow: 0 4px 8px rgba(0,0,0,0.3); } .finding-card.critical { border-left-color: #e74c3c; background: rgba(231, 76, 60, 0.05); } .finding-card.high { border-left-color: #e67e22; background: rgba(230, 126, 34, 0.05); } .finding-card.medium { border-left-color: #f39c12; background: rgba(243, 156, 18, 0.05); } .finding-card.low { border-left-color: #27ae60; background: rgba(39, 174, 96, 0.05); } .finding-title { color: #4fc3f7; font-weight: 600; margin-bottom: 12px; font-size: 16px; } .finding-description { color: #ccc; margin-bottom: 12px; font-size: 14px; line-height: 1.6; } .finding-category { color: #81c784; font-size: 12px; margin-bottom: 10px; padding: 4px 8px; background: rgba(129, 199, 132, 0.1); border-radius: 3px; display: inline-block; } .finding-recommendation { background: rgba(52, 152, 219, 0.08); padding: 12px; border-radius: 4px; margin-top: 12px; font-size: 13px; color: #ccc; border-left: 3px solid #3498db; line-height: 1.5; } .finding-affected-rules { background: #1a1d23; padding: 12px; border-radius: 4px; margin-top: 12px; border-left: 3px solid #ff5555; font-family: "Courier New", monospace; font-size: 12px; color: #e0e0e0; line-height: 1.4; overflow-x: auto; } .finding-affected-rules strong { color: #ff5555; display: block; margin-bottom: 8px; } .report-actions { display: flex; gap: 10px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #3a3f4b; } .btn-delete-report { background: #e74c3c; color: white; padding: 8px 16px; border-radius: 4px; border: none; cursor: pointer; font-size: 13px; transition: all 0.3s; } .btn-delete-report:hover { background: #c0392b; transform: translateY(-1px); box-shadow: 0 2px 6px rgba(231, 76, 60, 0.3); }

/* Proper formatted CSS below */

.finding-card {
    background: #2d3139;
    padding: 20px;
    margin-bottom: 20px;
    border-radius: 8px;
    border-left: 5px solid #3498db;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}
.finding-card.critical { border-left-color: #e74c3c; background: rgba(231, 76, 60, 0.05); }
.finding-card.high { border-left-color: #e67e22; background: rgba(230, 126, 34, 0.05); }
.finding-card.medium { border-left-color: #f39c12; background: rgba(243, 156, 18, 0.05); }
.finding-card.low { border-left-color: #27ae60; background: rgba(39, 174, 96, 0.05); }

.finding-description {
    color: #ccc;
    margin-bottom: 12px;
    font-size: 14px;
    line-height: 1.6;
}

.finding-affected-rules {
    background: #1a1d23;
    padding: 12px;
    border-radius: 4px;
    margin-top: 12px;
    border-left: 3px solid #ff5555;
    font-family: 'Courier New', monospace;
    font-size: 12px;
    color: #e0e0e0;
    line-height: 1.4;
}

.report-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #3a3f4b;
}

.btn-delete-report {
    background: #e74c3c;
    color: white;
    padding: 8px 16px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    font-size: 13px;
}

.btn-delete-report:hover {
    background: #c0392b;
}

</style>

<div class="reports-container">
    <input type="hidden" id="csrf_token" name="csrf" value="<?= csrf_token(); ?>">
    <?php if (!$current_report): ?>
        <!-- Reports List View -->
        <div class="reports-card">
            <h2><i class="fa fa-chart-line me-2"></i> AI Security Reports</h2>
            
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-label">Total Scans</div>
                    <div class="stat-number"><?= $stats['total_scans'] ?? 0 ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Average Score</div>
                    <div class="stat-number"><?= round($stats['avg_score'] ?? 0) ?></div>
                </div>
                <div class="stat-box critical">
                    <div class="stat-label">Critical Risk</div>
                    <div class="stat-number"><?= $stats['critical_count'] ?? 0 ?></div>
                </div>
                <div class="stat-box high">
                    <div class="stat-label">High Risk</div>
                    <div class="stat-number"><?= $stats['high_count'] ?? 0 ?></div>
                </div>
                <div class="stat-box medium">
                    <div class="stat-label">Medium Risk</div>
                    <div class="stat-number"><?= $stats['medium_count'] ?? 0 ?></div>
                </div>
                <div class="stat-box low">
                    <div class="stat-label">Low Risk</div>
                    <div class="stat-number"><?= $stats['low_count'] ?? 0 ?></div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filter-bar">
                <label style="color: #81c784;">Filter by Firewall:</label>
                <select class="form-control" style="max-width: 300px;" onchange="window.location.href='?firewall_id='+this.value">
                    <option value="">All Firewalls</option>
                    <?php foreach ($firewalls as $fw): ?>
                        <option value="<?= $fw['id'] ?>" <?= $firewall_id == $fw['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($fw['hostname']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($firewall_id): ?>
                    <a href="?" class="btn btn-primary">Clear Filter</a>
                <?php endif; ?>
            </div>
            
            <!-- Reports Table -->
            <table class="reports-table">
                <thead>
                    <tr>
                        <th>Firewall</th>
                        <th>Scan Date</th>
                        <th>Grade</th>
                        <th>Score</th>
                        <th>Risk Level</th>
                        <th>Findings</th>
                        <th>Provider</th>
                        <th>Duration</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_reports as $report): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($report['firewall_name']) ?></strong><br>
                                <small style="color: #95a5a6;"><?= htmlspecialchars($report['ip_address']) ?></small>
                            </td>
                            <td><?= date('Y-m-d H:i', strtotime($report['created_at'])) ?></td>
                            <td><span class="grade-badge grade-<?= $report['overall_grade'] ?>"><?= htmlspecialchars($report['overall_grade']) ?></span></td>
                            <td>
                                <?php
                                $score = $report['security_score'];
                                $score_class = $score >= 90 ? 'score-90' : ($score >= 80 ? 'score-80' : ($score >= 70 ? 'score-70' : ($score >= 60 ? 'score-60' : 'score-low')));
                                ?>
                                <div class="score-bar">
                                    <div class="score-fill <?= $score_class ?>" style="width: <?= $score ?>%">
                                        <?= $score ?>
                                    </div>
                                </div>
                            </td>
                            <td><span class="risk-badge risk-<?= $report['risk_level'] ?>"><?= strtoupper($report['risk_level']) ?></span></td>
                            <td><?= $report['findings_count'] ?></td>
                            <td><?= htmlspecialchars(ucfirst($report['provider'])) ?></td>
                            <td><?= round($report['scan_duration'] / 1000, 1) ?>s</td>
                            <td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="window.location.href='?report_id=<?= $report['id'] ?>'" style="margin-right: 8px;">
                                    <i class="fa fa-eye me-1"></i> View
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="deleteReport(<?= $report['id'] ?>, event)">
                                    <i class="fa fa-trash me-1"></i> Delete
                                </button>
                            </td>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($all_reports)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; color: #95a5a6; padding: 30px;">
                                No scan reports found. Configure AI providers and run scans from firewall detail pages.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
    <?php else: ?>
        <!-- Report Detail View -->
        <div class="reports-card">
            <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
                <a href="?" class="back-link"><i class="fa fa-arrow-left me-2"></i> Back to Reports</a>
                <button class="btn btn-sm btn-danger" onclick="deleteReport(<?= $current_report['id'] ?>, event)">
                    <i class="fa fa-trash me-2"></i> Delete This Report
                </button>
            </div>
            
            <h2><i class="fa fa-file-alt me-2"></i> Security Report: <?= htmlspecialchars($current_report['firewall_name']) ?></h2>
            
            <div class="report-detail">
                <!-- Sidebar with key metrics -->
                <div class="report-sidebar">
                    <h3 style="color: #4fc3f7; margin-top: 0;">Report Summary</h3>
                    
                    <div style="text-align: center; margin: 20px 0;">
                        <span class="grade-badge grade-<?= $current_report['overall_grade'] ?>" style="font-size: 48px; padding: 20px 30px;">
                            <?= htmlspecialchars($current_report['overall_grade']) ?>
                        </span>
                    </div>
                    
                    <div style="margin: 20px 0;">
                        <div style="color: #95a5a6; margin-bottom: 5px;">Security Score</div>
                        <?php
                        $score = $current_report['security_score'];
                        $score_class = $score >= 90 ? 'score-90' : ($score >= 80 ? 'score-80' : ($score >= 70 ? 'score-70' : ($score >= 60 ? 'score-60' : 'score-low')));
                        ?>
                        <div class="score-bar" style="height: 40px;">
                            <div class="score-fill <?= $score_class ?>" style="width: <?= $score ?>%; font-size: 18px;">
                                <?= $score ?>/100
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin: 20px 0;">
                        <div style="color: #95a5a6; margin-bottom: 5px;">Risk Level</div>
                        <span class="risk-badge risk-<?= $current_report['risk_level'] ?>" style="font-size: 16px; padding: 8px 16px;">
                            <?= strtoupper($current_report['risk_level']) ?>
                        </span>
                    </div>
                    
                    <hr style="border-color: #3a3f4b; margin: 20px 0;">
                    
                    <!-- Grade Explanation -->
                    <div style="background: #1a1d23; padding: 15px; border-radius: 6px; margin-bottom: 20px; font-size: 13px;">
                        <div style="color: #81c784; font-weight: 600; margin-bottom: 10px;">Grade Scale</div>
                        <div style="color: #95a5a6; line-height: 1.8;">
                            <div><span style="color: #27ae60; font-weight: 600;">A:</span> Excellent (90-100) - Well-configured</div>
                            <div><span style="color: #f39c12; font-weight: 600;">B:</span> Good (80-89) - Minor issues</div>
                            <div><span style="color: #e67e22; font-weight: 600;">C:</span> Fair (70-79) - Needs attention</div>
                            <div><span style="color: #e74c3c; font-weight: 600;">D:</span> Poor (60-69) - Significant risk</div>
                            <div><span style="color: #c0392b; font-weight: 600;">F:</span> Failing (&lt;60) - Critical issues</div>
                        </div>
                    </div>
                    
                    <div style="font-size: 13px; color: #95a5a6;">
                        <div style="margin: 10px 0;"><strong>Scan Date:</strong><br><?= date('F j, Y g:i A', strtotime($current_report['created_at'])) ?></div>
                        <div style="margin: 10px 0;"><strong>Provider:</strong><br><?= htmlspecialchars(ucfirst($current_report['provider'])) ?></div>
                        <div style="margin: 10px 0;"><strong>Model:</strong><br><?= htmlspecialchars($current_report['model']) ?></div>
                        <div style="margin: 10px 0;"><strong>Scan Type:</strong><br><?= ucwords(str_replace('_', ' ', $current_report['scan_type'])) ?></div>
                        <div style="margin: 10px 0;"><strong>Findings:</strong><br><?= count($report_findings) ?> issues detected</div>
                    </div>
                </div>
                
                <!-- Main content -->
                <div class="report-main">
                    <!-- Summary -->
                    <?php if ($current_report['summary']): ?>
                        <div class="section-title"><i class="fa fa-info-circle me-2"></i> Executive Summary</div>
                        <div style="color: #e0e0e0; line-height: 1.6; margin-bottom: 20px;">
                            <?= nl2br(htmlspecialchars(decodeReportField($current_report['summary']))) ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Key Concerns -->
                    <?php if ($current_report['concerns']): ?>
                        <div class="section-title"><i class="fa fa-exclamation-triangle me-2"></i> Key Concerns</div>
                        <div style="color: #e0e0e0; line-height: 1.6; margin-bottom: 20px;">
                            <?= nl2br(htmlspecialchars(decodeReportField($current_report['concerns']))) ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Configuration Findings -->
                    <?php if (!empty($config_findings)): ?>
                        <div class="section-title"><i class="fa fa-cog me-2"></i> Configuration Analysis</div>
                        <div style="color: #95a5a6; margin-bottom: 15px; font-size: 14px;">
                            Issues, warnings, and recommendations from firewall configuration analysis
                        </div>
                        <?php foreach ($config_findings as $finding): ?>
                            <div class="finding-card <?= $finding['severity'] ?>">
                                <div style="display: flex; justify-content: space-between; align-items: start;">
                                    <div class="finding-title"><?= htmlspecialchars($finding['title']) ?></div>
                                    <span class="severity-badge severity-<?= $finding['severity'] ?>">
                                        <?= $finding['severity'] ?>
                                    </span>
                                </div>
                                <?php if ($finding['category']): ?>
                                    <div style="color: #81c784; font-size: 12px; margin-bottom: 8px;">
                                        Category: <?= htmlspecialchars($finding['category']) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="finding-description">
                                    <?= nl2br(htmlspecialchars($finding['description'])) ?>
                                </div>
                                <?php if ($finding['recommendation']): ?>
                                    <div class="finding-recommendation">
                                        <strong style="color: #4fc3f7;">Recommendation:</strong><br>
                                        <?= nl2br(htmlspecialchars($finding['recommendation'])) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($finding['affected_rules']): ?>
                                    <div style="margin-top: 12px; padding: 10px; background: #1a1d23; border-left: 3px solid #ff5555; border-radius: 4px;">
                                        <strong style="color: #ff5555;">Affected Rules/Configuration:</strong><br>
                                        <div style="font-family: 'Courier New', monospace; font-size: 12px; margin-top: 8px; color: #e0e0e0;">
                                            <?= nl2br(htmlspecialchars($finding['affected_rules'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Log Analysis Findings -->
                    <?php if (!empty($log_findings)): ?>
                        <div class="section-title" style="margin-top: 30px;"><i class="fa fa-file-text me-2"></i> Log Analysis</div>
                        <div style="color: #95a5a6; margin-bottom: 15px; font-size: 14px;">
                            Security issues and concerns detected from firewall log analysis
                        </div>
                        <?php foreach ($log_findings as $finding): ?>
                            <div class="finding-card <?= $finding['severity'] ?>">
                                <div style="display: flex; justify-content: space-between; align-items: start;">
                                    <div class="finding-title"><?= htmlspecialchars($finding['title']) ?></div>
                                    <span class="severity-badge severity-<?= $finding['severity'] ?>">
                                        <?= $finding['severity'] ?>
                                    </span>
                                </div>
                                <?php if ($finding['category']): ?>
                                    <div style="color: #81c784; font-size: 12px; margin-bottom: 8px;">
                                        Category: <?= htmlspecialchars($finding['category']) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="finding-description">
                                    <?= nl2br(htmlspecialchars($finding['description'])) ?>
                                </div>
                                <?php if ($finding['recommendation']): ?>
                                    <div class="finding-recommendation">
                                        <strong style="color: #4fc3f7;">Recommendation:</strong><br>
                                        <?= nl2br(htmlspecialchars($finding['recommendation'])) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($finding['affected_rules']): ?>
                                    <div style="margin-top: 12px; padding: 10px; background: #1a1d23; border-left: 3px solid #ff5555; border-radius: 4px;">
                                        <strong style="color: #ff5555;">Affected Rules/Configuration:</strong><br>
                                        <div style="font-family: 'Courier New', monospace; font-size: 12px; margin-top: 8px; color: #e0e0e0;">
                                            <?= nl2br(htmlspecialchars($finding['affected_rules'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php elseif ($current_report['scan_type'] === 'config_with_logs'): ?>
                        <div class="section-title" style="margin-top: 30px;"><i class="fa fa-file-text me-2"></i> Log Analysis</div>
                        <div style="padding: 20px; background: #1a1d23; border-radius: 6px; border: 1px solid #3a3f4b; color: #81c784;">
                            <i class="fa fa-check-circle me-2"></i> No security issues detected in firewall logs. All logs analyzed and no suspicious activity found.
                        </div>
                    <?php endif; ?>

                    <!-- Show message if no findings at all -->
                    <?php if (empty($config_findings) && empty($log_findings)): ?>
                        <div class="section-title"><i class="fa fa-check-circle me-2"></i> Analysis Results</div>
                        <div style="padding: 20px; background: #1a1d23; border-radius: 6px; border: 1px solid #3a3f4b; color: #81c784;">
                            <i class="fa fa-shield-alt me-2"></i> No significant security issues detected. Firewall configuration appears to be well-secured.
                        </div>
                    <?php endif; ?>
                    
                    <!-- Recommendations -->
                    <?php if ($current_report['recommendations']): ?>
                        <div class="section-title"><i class="fa fa-lightbulb me-2"></i> Recommendations</div>
                        <div style="color: #e0e0e0; line-height: 1.6; margin-bottom: 20px;">
                            <?= nl2br(htmlspecialchars(decodeReportField($current_report['recommendations']))) ?>
                        </div>
                    <?php endif; ?>
                    <!-- Delete Report Action -->
                    <div class="report-actions">
                        <button class="btn-delete-report" onclick="deleteReport(<?php echo $current_report['id']; ?>)">
                            <i class="fa fa-trash me-2"></i>Delete This Report
                        </button>
                    </div>

                    <script>
                    function deleteReport(reportId) {
                        if (!confirm('Are you sure you want to delete this report? This cannot be undone.')) {
                            return;
                        }
                        
                        const formData = new FormData();
                        formData.append('report_id', reportId);
                        formData.append('csrf', document.getElementById('csrf_token').value);
                        
                        fetch('/api/delete_ai_report.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert('Report deleted successfully');
                                window.location.href = '/ai_reports.php';
                            } else {
                                alert('Error: ' + (data.error || 'Unknown error'));
                            }
                        })
                        .catch(error => {
                            console.error('Delete error:', error);
                            alert('Failed to delete report: ' + error.message);
                        });
                    }
                    </script>
                    
                    <!-- Improvements -->
                    <?php if ($current_report['improvements']): ?>
                        <div class="section-title"><i class="fa fa-arrow-up me-2"></i> Improvement Opportunities</div>
                        <div style="color: #e0e0e0; line-height: 1.6; margin-bottom: 20px;">
                            <?= nl2br(htmlspecialchars(decodeReportField($current_report['improvements']))) ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Full Report (Detailed Analysis) -->
                    <?php if ($current_report['full_report']): ?>
                        <div class="section-title"><i class="fa fa-file-text me-2"></i> Detailed Analysis Report</div>
                        <div style="background: #1a1d23; padding: 20px; border-radius: 6px; border: 1px solid #3a3f4b; font-size: 14px; color: #e0e0e0; max-height: 600px; overflow-y: auto; line-height: 1.6; white-space: pre-wrap; word-wrap: break-word;">
                            <?php
                            // Format the report for better readability
                            $report = $current_report['full_report'];

                            // Convert markdown-like headers to HTML
                            $report = preg_replace('/^### (.+)$/m', '<h4 style="color: #81c784; margin-top: 20px; margin-bottom: 10px;">$1</h4>', $report);
                            $report = preg_replace('/^## (.+)$/m', '<h3 style="color: #4fc3f7; margin-top: 25px; margin-bottom: 15px; border-bottom: 2px solid #3a3f4b; padding-bottom: 8px;">$1</h3>', $report);
                            $report = preg_replace('/^# (.+)$/m', '<h2 style="color: #f5b041; margin-top: 30px; margin-bottom: 20px;">$1</h2>', $report);

                            // Convert bullet points
                            $report = preg_replace('/^- (.+)$/m', '<li style="margin-left: 20px; margin-bottom: 8px;">$1</li>', $report);
                            $report = preg_replace('/^\* (.+)$/m', '<li style="margin-left: 20px; margin-bottom: 8px;">$1</li>', $report);

                            // Wrap consecutive <li> tags in <ul>
                            $report = preg_replace('/(<li[^>]*>.*?<\/li>\s*)+/s', '<ul style="margin-bottom: 15px;">$0</ul>', $report);

                            // Bold text
                            $report = preg_replace('/\*\*(.+?)\*\*/', '<strong style="color: #f0f4f8;">$1</strong>', $report);

                            echo $report;
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function deleteReport(reportId, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    
    if (!confirm('Are you sure you want to delete this report? This action cannot be undone.')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('report_id', reportId);
    
    const csrfEl = document.getElementById('csrf_token');
    if (csrfEl) {
        formData.append('csrf', csrfEl.value);
    } else {
        console.warn('CSRF token not found');
    }
    
    fetch('/api/delete_ai_report.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Report deleted successfully');
            window.location.href = '?';
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Delete error:', error);
        alert('Failed to delete report: ' + error.message);
    });
}
</script>

<?php include 'inc/footer.php'; ?>
