<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
requireLogin();
requireAdmin();

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Filters
$level_filter = $_GET['level'] ?? '';
$firewall_filter = $_GET['firewall'] ?? '';

// Build query
$where_clauses = [];
$params = [];

if ($level_filter && in_array($level_filter, ['info', 'warning', 'critical'])) {
    $where_clauses[] = "alert_level = ?";
    $params[] = $level_filter;
}

if ($firewall_filter && is_numeric($firewall_filter)) {
    $where_clauses[] = "firewall_id = ?";
    $params[] = (int)$firewall_filter;
}

$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get total count
$count_sql = "SELECT COUNT(*) FROM alert_history $where_sql";
$count_stmt = $DB->prepare($count_sql);
$count_stmt->execute($params);
$total_alerts = $count_stmt->fetchColumn();
$total_pages = ceil($total_alerts / $per_page);

// Get alerts with firewall names
$sql = "
    SELECT 
        ah.*,
        f.hostname as firewall_hostname
    FROM alert_history ah
    LEFT JOIN firewalls f ON ah.firewall_id = f.id
    $where_sql
    ORDER BY ah.sent_at DESC
    LIMIT $per_page OFFSET $offset
";

$stmt = $DB->prepare($sql);
$stmt->execute($params);
$alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get firewalls for filter dropdown
$firewalls = $DB->query("SELECT id, hostname FROM firewalls ORDER BY hostname")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/inc/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="text-light">
            <i class="fas fa-history me-2"></i>Alert History
        </h2>
        <a href="/alerts.php" class="btn btn-outline-primary">
            <i class="fas fa-cog me-2"></i>Alert Settings
        </a>
    </div>

    <!-- Filters -->
    <div class="card card-dark mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label text-light">Alert Level</label>
                    <select name="level" class="form-select bg-dark text-light border-secondary">
                        <option value="">All Levels</option>
                        <option value="info" <?php echo $level_filter === 'info' ? 'selected' : ''; ?>>Info</option>
                        <option value="warning" <?php echo $level_filter === 'warning' ? 'selected' : ''; ?>>Warning</option>
                        <option value="critical" <?php echo $level_filter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label text-light">Firewall</label>
                    <select name="firewall" class="form-select bg-dark text-light border-secondary">
                        <option value="">All Firewalls</option>
                        <?php foreach ($firewalls as $fw): ?>
                            <option value="<?php echo $fw['id']; ?>" <?php echo $firewall_filter == $fw['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($fw['hostname']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                    <a href="/alert_history.php" class="btn btn-outline-secondary">
                        <i class="fas fa-times me-1"></i>Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Stats -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card card-dark">
                <div class="card-body">
                    <div class="d-flex justify-content-around text-center">
                        <div>
                            <h4 class="text-light mb-0"><?php echo number_format($total_alerts); ?></h4>
                            <small class="text-muted">Total Alerts</small>
                        </div>
                        <div class="vr"></div>
                        <?php
                        $level_counts = $DB->query("
                            SELECT alert_level, COUNT(*) as count 
                            FROM alert_history 
                            GROUP BY alert_level
                        ")->fetchAll(PDO::FETCH_KEY_PAIR);
                        ?>
                        <div>
                            <h4 class="text-info mb-0"><?php echo number_format($level_counts['info'] ?? 0); ?></h4>
                            <small class="text-muted">Info</small>
                        </div>
                        <div class="vr"></div>
                        <div>
                            <h4 class="text-warning mb-0"><?php echo number_format($level_counts['warning'] ?? 0); ?></h4>
                            <small class="text-muted">Warnings</small>
                        </div>
                        <div class="vr"></div>
                        <div>
                            <h4 class="text-danger mb-0"><?php echo number_format($level_counts['critical'] ?? 0); ?></h4>
                            <small class="text-muted">Critical</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert History Table -->
    <div class="card card-dark">
        <div class="card-body">
            <?php if (empty($alerts)): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle me-2"></i>
                    No alerts found matching your criteria.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-hover">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Level</th>
                                <th>Firewall</th>
                                <th>Message</th>
                                <th>Recipients</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alerts as $alert): 
                                $level_class = [
                                    'info' => 'text-info',
                                    'warning' => 'text-warning',
                                    'critical' => 'text-danger'
                                ][$alert['alert_level']] ?? 'text-secondary';
                                
                                $level_icon = [
                                    'info' => 'info-circle',
                                    'warning' => 'exclamation-triangle',
                                    'critical' => 'exclamation-circle'
                                ][$alert['alert_level']] ?? 'circle';
                            ?>
                                <tr>
                                    <td class="text-light">
                                        <?php echo date('M j, Y g:i A', strtotime($alert['sent_at'])); ?>
                                        <br>
                                        <small class="text-muted"><?php echo date('D', strtotime($alert['sent_at'])); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-dark border <?php echo str_replace('text-', 'border-', $level_class); ?> <?php echo $level_class; ?>">
                                            <i class="fas fa-<?php echo $level_icon; ?> me-1"></i>
                                            <?php echo ucfirst($alert['alert_level']); ?>
                                        </span>
                                    </td>
                                    <td class="text-light">
                                        <?php if ($alert['firewall_id']): ?>
                                            <a href="/firewall_details.php?id=<?php echo $alert['firewall_id']; ?>" class="text-decoration-none text-info">
                                                <?php echo htmlspecialchars($alert['firewall_hostname'] ?? 'Unknown'); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">System</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-light">
                                        <?php echo nl2br(htmlspecialchars($alert['message'])); ?>
                                    </td>
                                    <td class="text-light">
                                        <small class="text-muted">
                                            <?php 
                                            $recipients = explode(',', $alert['recipient_emails'] ?? '');
                                            echo count(array_filter($recipients)) . ' recipient(s)';
                                            ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($alert['sent_successfully']): ?>
                                            <span class="badge bg-success">
                                                <i class="fas fa-check me-1"></i>Sent
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger" title="<?php echo htmlspecialchars($alert['error_message'] ?? 'Unknown error'); ?>">
                                                <i class="fas fa-times me-1"></i>Failed
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Alert history pagination">
                        <ul class="pagination justify-content-center mt-4">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link bg-dark text-light border-secondary" href="?page=<?php echo $page - 1; ?><?php echo $level_filter ? "&level=$level_filter" : ''; ?><?php echo $firewall_filter ? "&firewall=$firewall_filter" : ''; ?>">
                                        Previous
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link bg-dark text-light border-secondary" href="?page=<?php echo $i; ?><?php echo $level_filter ? "&level=$level_filter" : ''; ?><?php echo $firewall_filter ? "&firewall=$firewall_filter" : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link bg-dark text-light border-secondary" href="?page=<?php echo $page + 1; ?><?php echo $level_filter ? "&level=$level_filter" : ''; ?><?php echo $firewall_filter ? "&firewall=$firewall_filter" : ''; ?>">
                                        Next
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
