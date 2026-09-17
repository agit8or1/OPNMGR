<?php
/**
 * Universal Documentation Viewer
 * Loads and displays documentation pages from database
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/documentation_helper.php';
require_once __DIR__ . '/vendor/autoload.php';
requireLogin();

// Get page key from URL parameter
$pageKey = $_GET['page'] ?? 'documentation';

// Validate page key (prevent SQL injection even though we use prepared statements)
if (!preg_match('/^[a-z_]+$/', $pageKey)) {
    $pageKey = 'documentation';
}

// Load page data
$page = getDocumentationPage($pageKey);

if (!$page) {
    // Fallback if page not found
    $page = [
        'title' => 'Page Not Found',
        'content' => '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i>The requested documentation page could not be found.</div>',
        'category' => 'general'
    ];
}

$page_title = $page['title'];
include __DIR__ . '/inc/header.php';
?>

<?php
// The layout reserved a col-md-3 for inc/sidebar.php, which has been disabled
// for some time - its entire body is a display:none div. An empty quarter-width
// column still occupies its quarter, so every documentation page rendered in the
// right three-quarters of the window and read as pushed to one side.
//
// Centred with a readable measure instead. Documentation set to the full width
// of a wide monitor is its own kind of unreadable.
?>
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-12" style="max-width: 1140px;">
            <?php // Theme variables rather than the fixed slate palette this carried: on
     // the light theme it rendered a dark card on a pale page, and the muted
     // intro line came out near-black on near-black. ?>
            <div class="card" style="background: var(--bg-surface); border: 1px solid var(--border);">
                <div class="card-header" style="background: var(--bg-surface-2, var(--bg-surface)); border-bottom: 2px solid var(--accent);">
                    <h3 class="card-title">
                        <?php
                        // Icon based on category
                        $icon = match($page['category']) {
                            'general' => 'fa-info-circle',
                            'user' => 'fa-book',
                            'development' => 'fa-code',
                            default => 'fa-file-alt'
                        };
                        ?>
                        <i class="fas <?= $icon ?> me-2"></i><?= htmlspecialchars($page['title']) ?>
                    </h3>
                </div>
                <div class="card-body" style="background: var(--bg-surface); color: var(--text-primary);">
                    <?php
                    // Additional data for specific pages
                    if ($pageKey === 'about') {
                        // Include version info and stats for About page
                        $versionInfo = getVersionInfo();
                        $systemHealthData = getSystemHealth();
                        
                        // Calculate health score and color
                        $activeAgentCount = 0;
                        $totalFirewalls = 0;
                        $healthScore = 0;
                        $healthColor = 'danger';
                        
                        // Check if database connection is available
                        try { $dbReady = (bool)db(); } catch (Exception $e) { $dbReady = false; }
                        if ($dbReady) {
                            try {
                                $stmt = db()->query("SELECT COUNT(*) FROM firewalls");
                                $totalFirewalls = $stmt->fetchColumn();
                                
                                $stmt = db()->query("SELECT COUNT(*) FROM firewalls WHERE status = 'online' AND last_checkin > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
                                $activeAgentCount = $stmt->fetchColumn();
                                
                                // Calculate health percentage
                                $healthScore = ($totalFirewalls > 0) ? ($activeAgentCount / $totalFirewalls) * 100 : 0;
                            } catch (PDOException $e) {
                                error_log('Health score calculation failed: ' . $e->getMessage());
                                $healthScore = 0;
                            }
                        }
                        
                        // Determine health color based on score
                        $healthColor = $healthScore >= 90 ? 'success' : ($healthScore >= 70 ? 'warning' : 'danger');
                        
                        $systemHealth = [
                            'color' => $healthColor,
                            'score' => $healthScore
                        ];
                        
                        $firewall_count = $totalFirewalls;
                        $active_agents = $activeAgentCount;
                        ?>
                        
                        <!-- Version Information Card -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="card border-primary">
                                    <div class="card-body">
                                        <h5 class="card-title text-primary"><i class="fas fa-code-branch me-2"></i>Application Versions</h5>
                                        <table class="table table-sm table-dark mb-0">
                                            <tr>
                                                <th width="40%">Application</th>
                                                <td>
                                                    <span class="badge bg-success"><?= $versionInfo['app']['version'] ?></span>
                                                    <small class="text-secondary ms-2"><?= $versionInfo['app']['codename'] ?></small>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Release Date</th>
                                                <td><?= $versionInfo['app']['date'] ?></td>
                                            </tr>
                                            <tr>
                                                <th>Agent Version</th>
                                                <td>
                                                    <span class="badge bg-info"><?= $versionInfo['agent']['version'] ?? 'N/A' ?></span>
                                                    <small class="text-secondary ms-2">(Released: <?= $versionInfo['agent']['date'] ?? 'N/A' ?>)</small>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Agent Min Supported</th>
                                                <td><?= $versionInfo['agent']['min_supported'] ?? 'N/A' ?></td>
                                            </tr>
                                            <tr>
                                                <th>Tunnel Proxy</th>
                                                <td><span class="badge bg-info"><?= $versionInfo['tunnel_proxy']['version'] ?? 'N/A' ?></span></td>
                                            </tr>
                                            <tr>
                                                <th>Database Schema</th>
                                                <td><span class="badge bg-secondary"><?= $versionInfo['database']['version'] ?? 'N/A' ?></span></td>
                                            </tr>
                                            <tr>
                                                <th>API Version</th>
                                                <td><span class="badge bg-secondary"><?= $versionInfo['api']['version'] ?? 'N/A' ?></span></td>
                                            </tr>
                                        </table>

                                        <h6 class="card-subtitle mt-3 mb-2 text-primary"><i class="fas fa-code me-2"></i>Dependencies</h6>
                                        <table class="table table-sm table-dark mb-0">
                                            <tr>
                                                <th width="40%">PHP Version</th>
                                                <td>
                                                    <span class="badge bg-primary"><?= $versionInfo['dependencies']['php_current'] ?? PHP_VERSION ?></span>
                                                    <small class="text-secondary ms-2">(Min: <?= $versionInfo['dependencies']['php_min'] ?? '8.0' ?>)</small>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Bootstrap</th>
                                                <td><?= $versionInfo['dependencies']['bootstrap'] ?? '5.3.3' ?></td>
                                            </tr>
                                            <tr>
                                                <th>jQuery</th>
                                                <td><?= $versionInfo['dependencies']['jquery'] ?? '3.7.0' ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card border-success">
                                    <div class="card-body">
                                        <h5 class="card-title text-success"><i class="fas fa-chart-line me-2"></i>System Status</h5>
                                        <table class="table table-sm table-dark mb-0">
                                            <tr>
                                                <th width="40%">Total Firewalls</th>
                                                <td><span class="badge bg-primary"><?= $firewall_count ?></span></td>
                                            </tr>
                                            <tr>
                                                <th>Active Agents</th>
                                                <td><span class="badge bg-success"><?= $active_agents ?></span></td>
                                            </tr>
                                            <tr>
                                                <th>Health Score</th>
                                                <td>
                                                    <span class="badge bg-<?= $systemHealth['color'] ?>">
                                                        <?= number_format($systemHealth['score'], 1) ?>%
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <hr class="my-4">
                        <?php
                    }
                    ?>
                    
                    <!-- Main Documentation Content -->
                    <div class="documentation-content">
                        <?php
                        // Parse markdown to HTML
                        $Parsedown = new Parsedown();
                        echo $Parsedown->text($page['content']);
                        ?>
                    </div>
                    
                    <!-- Last Updated Info -->
                    <?php if (!empty($page['last_updated'])): ?>
                        <div class="text-muted mt-4 pt-3 border-top">
                            <small>
                                <i class="fas fa-clock me-1"></i> 
                                Last updated: <?= date('F j, Y g:i A', strtotime($page['last_updated'])) ?>
                                <?php if (!empty($page['updated_by'])): ?>
                                    by <?= htmlspecialchars($page['updated_by']) ?>
                                <?php endif; ?>
                            </small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.documentation-content h2 {
    color: var(--accent);
    border-bottom: 2px solid var(--accent);
    padding-bottom: 10px;
    margin-top: 30px;
    margin-bottom: 20px;
}
.documentation-content h3 {
    color: #81c784;
    margin-top: 25px;
    margin-bottom: 15px;
}
.documentation-content h4 {
    color: #ffb74d;
    margin-top: 20px;
    margin-bottom: 10px;
}
.documentation-content ul, .documentation-content ol {
    margin-left: 20px;
    margin-bottom: 15px;
}
.documentation-content li {
    margin-bottom: 8px;
}
.documentation-content p {
    margin-bottom: 15px;
    line-height: 1.6;
    color: var(--text-primary);
}
.documentation-content .lead {
    font-size: 1.15rem;
    font-weight: 300;
    color: var(--text-primary);
}
.documentation-content code {
    background: var(--bg-surface);
    padding: 2px 6px;
    border-radius: 3px;
    color: var(--accent);
    font-weight: 500;
}
.documentation-content table {
    width: 100%;
    margin: 20px 0;
}
.documentation-content strong, .documentation-content b {
    color: var(--text-primary);
}
.documentation-content a {
    color: var(--accent);
    text-decoration: none;
    border-bottom: 1px dotted var(--accent);
}
.documentation-content a:hover {
    color: #81c784;
    border-bottom-color: #81c784;
}
</style>

<?php include __DIR__ . '/inc/footer.php'; ?>
