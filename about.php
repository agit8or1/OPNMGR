<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/version.php';
requireLogin();

$versionInfo = getVersionInfo();
$systemHealth = getSystemHealth();

// Get system stats
$stmt = $DB->query("SELECT COUNT(*) as total_firewalls FROM firewalls");
$firewall_count = $stmt->fetchColumn();

$stmt = $DB->query("SELECT COUNT(*) as active_agents FROM firewalls WHERE status = 'online' AND last_checkin > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
$active_agents = $stmt->fetchColumn();

$stmt = $DB->query("SELECT COUNT(*) as total_backups FROM backups");
$total_backups = $stmt->fetchColumn();

include __DIR__ . '/inc/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-3">
            <?php include __DIR__ . '/inc/sidebar.php'; ?>
        </div>

        <div class="col-md-9">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-info-circle me-2"></i>About <?= APP_NAME ?>
                    </h3>
                </div>
                <div class="card-body">
                    <!-- Version Information -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h4><i class="fas fa-code-branch me-2"></i>Version Information</h4>
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">Application</th>
                                    <td>
                                        <strong><?= $versionInfo['app']['version'] ?></strong>
                                        <span class="badge bg-success ms-2"><?= $versionInfo['app']['codename'] ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Release Date</th>
                                    <td><?= $versionInfo['app']['date'] ?></td>
                                </tr>
                                <tr>
                                    <th>Agent Version</th>
                                    <td>
                                        <?= $versionInfo['agent']['version'] ?>
                                        <small class="text-muted">(Min: <?= $versionInfo['agent']['min_supported'] ?>)</small>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Update Agent</th>
                                    <td>
                                        <?= $versionInfo['update_agent']['version'] ?>
                                        <span class="badge bg-warning"><?= $versionInfo['update_agent']['status'] ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Database Schema</th>
                                    <td><?= $versionInfo['database']['version'] ?></td>
                                </tr>
                                <tr>
                                    <th>API Version</th>
                                    <td><?= $versionInfo['api']['version'] ?></td>
                                </tr>
                            </table>
                        </div>

                        <div class="col-md-6">
                            <h4><i class="fas fa-heartbeat me-2"></i>System Health</h4>
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">Overall Status</th>
                                    <td>
                                        <?php if ($systemHealth['status'] == 'healthy'): ?>
                                            <span class="badge bg-success"><i class="fas fa-check me-1"></i>Healthy</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i>Issues Detected</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php foreach ($systemHealth['checks'] as $check_name => $check): ?>
                                <tr>
                                    <th><?= ucwords(str_replace('_', ' ', $check_name)) ?></th>
                                    <td>
                                        <?php if ($check['status'] == 'ok'): ?>
                                            <i class="fas fa-check-circle text-success me-1"></i>
                                        <?php elseif ($check['status'] == 'warning'): ?>
                                            <i class="fas fa-exclamation-triangle text-warning me-1"></i>
                                        <?php else: ?>
                                            <i class="fas fa-times-circle text-danger me-1"></i>
                                        <?php endif; ?>
                                        <?= $check['message'] ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    </div>

                    <!-- System Statistics -->
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <h4><i class="fas fa-chart-bar me-2"></i>System Statistics</h4>
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="card bg-primary text-white mb-3">
                                        <div class="card-body text-center">
                                            <h1><?= $firewall_count ?></h1>
                                            <p class="mb-0">Total Firewalls</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-success text-white mb-3">
                                        <div class="card-body text-center">
                                            <h1><?= $active_agents ?></h1>
                                            <p class="mb-0">Active Agents</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-info text-white mb-3">
                                        <div class="card-body text-center">
                                            <h1><?= $total_backups ?></h1>
                                            <p class="mb-0">Total Backups</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-secondary text-white mb-3">
                                        <div class="card-body text-center">
                                            <h1><?= substr(PHP_VERSION, 0, 3) ?></h1>
                                            <p class="mb-0">PHP Version</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Changes -->
                    <div class="row">
                        <div class="col-md-12">
                            <h4><i class="fas fa-history me-2"></i>Recent Changes</h4>
                            <?php foreach (getChangelogEntries(3) as $entry): ?>
                            <div class="card mb-2">
                                <div class="card-header">
                                    <strong>v<?= $entry['version'] ?></strong> - <?= $entry['title'] ?>
                                    <span class="badge bg-<?= $entry['type'] == 'major' ? 'danger' : 'info' ?> float-end">
                                        <?= ucfirst($entry['type']) ?> Release
                                    </span>
                                    <small class="text-muted ms-2"><?= $entry['date'] ?></small>
                                </div>
                                <div class="card-body">
                                    <ul class="mb-0">
                                        <?php foreach ($entry['changes'] as $change): ?>
                                        <li><?= $change ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
