<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/logging.php';
require_once __DIR__ . '/inc/timezone_selector.php';
requireLogin();
requireAdmin();

// Get filter parameters
$level_filter = $_GET['level'] ?? '';
$category_filter = $_GET['category'] ?? '';
$firewall_filter = $_GET['firewall_id'] ?? '';
$days_filter = (int)($_GET['days'] ?? 7);
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;

// Build filters
$filters = [];
if ($level_filter) $filters['level'] = $level_filter;
if ($category_filter) $filters['category'] = $category_filter;
if ($firewall_filter) $filters['firewall_id'] = $firewall_filter;

// Date range filter
$filters['start_date'] = date('Y-m-d H:i:s', strtotime("-$days_filter days"));

// Get logs
$offset = ($page - 1) * $per_page;
$logs = get_logs($filters, $per_page, $offset);

// Get available categories and levels for filters
$stmt = db()->query("SELECT DISTINCT category FROM system_logs ORDER BY category");
$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Friendly names for categories
$category_names = [
    'agent' => 'Agent Events',
    'proxy' => 'Proxy Requests',
    'command' => 'Commands',
    'firewall' => 'Firewall Events',
    'backup' => 'Backups',
    'auth' => 'Authentication',
    'system' => 'System',
    'dashboard' => 'Dashboard',
    'housekeeping' => 'Maintenance'
];

$stmt = db()->query("SELECT DISTINCT level FROM system_logs ORDER BY level");
$levels = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = db()->query("SELECT id, hostname FROM firewalls ORDER BY hostname");
$firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$count_filters = $filters;
$total_logs = db()->prepare("
    SELECT COUNT(*) FROM system_logs sl 
    WHERE timestamp >= ?
    " . ($level_filter ? " AND level = ?" : "") . "
    " . ($category_filter ? " AND category = ?" : "") . "
    " . ($firewall_filter ? " AND firewall_id = ?" : "")
);

$count_params = [$filters['start_date']];
if ($level_filter) $count_params[] = $level_filter;
if ($category_filter) $count_params[] = $category_filter;
if ($firewall_filter) $count_params[] = $firewall_filter;

$total_logs->execute($count_params);
$total_count = $total_logs->fetchColumn();
$total_pages = ceil($total_count / $per_page);

include __DIR__ . '/inc/header.php';
?>

<div class="row">
    <div class="col-md-12">
        <div class="card card-dark">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                    <small class="text-light fw-bold mb-0">
                        <i class="fas fa-list-alt me-1"></i>System Logs
                    </small>
                    <div class="ms-auto">
                        <button type="button" id="autoRefreshBtn" class="btn btn-outline-info btn-sm me-2" onclick="toggleAutoRefresh()">
                            <i class="fas fa-sync-alt me-1"></i>Auto-Refresh: <span id="refreshStatus">OFF</span>
                        </button>
                        <button type="button" class="btn btn-outline-warning btn-sm me-2" onclick="cleanupLogs()">
                            <i class="fas fa-broom me-1"></i>Cleanup Old Logs
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm me-2" onclick="clearAllLogs()">
                            <i class="fas fa-trash me-1"></i>Clear All Logs
                        </button>
                        <a href="/dashboard.php" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                        </a>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card card-ghost mb-3">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-2">
                                <label for="level" class="form-label text-light">Level</label>
                                <select class="form-select bg-dark text-light border-secondary" name="level" id="level">
                                    <option value="">All Levels</option>
                                    <?php foreach ($levels as $level): ?>
                                        <option value="<?php echo htmlspecialchars($level); ?>" 
                                                <?php echo $level_filter === $level ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($level); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="category" class="form-label text-light">Category</label>
                                <select class="form-select bg-dark text-light border-secondary" name="category" id="category">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $category): 
                                        $display_name = $category_names[$category] ?? ucfirst($category);
                                    ?>
                                        <option value="<?php echo htmlspecialchars($category); ?>" 
                                                <?php echo $category_filter === $category ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($display_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="firewall_id" class="form-label text-light">Firewall</label>
                                <select class="form-select bg-dark text-light border-secondary" name="firewall_id" id="firewall_id">
                                    <option value="">All Firewalls</option>
                                    <?php foreach ($firewalls as $firewall): ?>
                                        <option value="<?php echo $firewall['id']; ?>" 
                                                <?php echo $firewall_filter == $firewall['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($firewall['hostname']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="days" class="form-label text-light">Time Range</label>
                                <select class="form-select bg-dark text-light border-secondary" name="days" id="days">
                                    <option value="1" <?php echo $days_filter === 1 ? 'selected' : ''; ?>>Last 24 hours</option>
                                    <option value="7" <?php echo $days_filter === 7 ? 'selected' : ''; ?>>Last 7 days</option>
                                    <option value="30" <?php echo $days_filter === 30 ? 'selected' : ''; ?>>Last 30 days</option>
                                    <option value="90" <?php echo $days_filter === 90 ? 'selected' : ''; ?>>Last 90 days</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label text-light">&nbsp;</label>
                                <div>
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="fas fa-search me-1"></i>Filter
                                    </button>
                                    <a href="/logs.php" class="btn btn-secondary btn-sm ms-1">
                                        <i class="fas fa-times me-1"></i>Clear
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Results Summary -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <small class="text-light">
                        Showing <?php echo number_format(count($logs)); ?> of <?php echo number_format($total_count); ?> log entries
                        <?php if ($days_filter): ?>
                            (last <?php echo $days_filter; ?> days)
                        <?php endif; ?>
                    </small>
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Log pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link bg-dark text-light border-secondary" 
                                           href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Previous</a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link bg-dark text-light border-secondary" 
                                           href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link bg-dark text-light border-secondary" 
                                           href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>

                <!-- Logs Table -->
                <div class="table-responsive">
                    <table class="table table-dark table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Level</th>
                                <th>Category</th>
                                <th>Message</th>
                                <th>Firewall</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No logs found for the selected criteria</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td class="text-light">
                                            <small><?php echo convertToDisplayTimezone($log['timestamp'], 'M j, Y H:i:s'); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge <?php 
                                                echo match($log['level']) {
                                                    'ERROR' => 'bg-danger',
                                                    'WARNING' => 'bg-warning text-white',
                                                    'INFO' => 'bg-info text-white',
                                                    'DEBUG' => 'bg-secondary',
                                                    default => 'bg-light text-white'
                                                };
                                            ?>">
                                                <?php echo htmlspecialchars($log['level']); ?>
                                            </span>
                                        </td>
                                        <td class="text-light">
                                            <small><?php echo htmlspecialchars($log['category']); ?></small>
                                        </td>
                                        <td class="text-light">
                                            <?php echo htmlspecialchars($log['message']); ?>
                                            <?php if (!empty($log['additional_data'])): ?>
                                                <button class="btn btn-sm btn-outline-info ms-2" 
                                                        onclick="showAdditionalData('<?php echo htmlspecialchars(addslashes($log['additional_data'])); ?>')">
                                                    <i class="fas fa-info-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-light">
                                            <?php if ($log['firewall_hostname']): ?>
                                                <small><?php echo htmlspecialchars($log['firewall_hostname']); ?></small>
                                            <?php else: ?>
                                                <small class="text-muted">-</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-light">
                                            <small><?php echo htmlspecialchars($log['ip_address']); ?></small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Additional Data Modal -->
<div class="modal fade" id="additionalDataModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header bg-dark border-secondary">
                <h5 class="modal-title text-light">Additional Data</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-dark">
                <pre id="additionalDataContent" class="text-light bg-secondary p-3 rounded"></pre>
            </div>
        </div>
    </div>
</div>

<script>
let autoRefreshInterval = null;
let refreshIntervalSeconds = 10; // Changed from 5 to 10 seconds

function toggleAutoRefresh() {
    if (autoRefreshInterval) {
        // Turn off auto-refresh
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
        localStorage.setItem('logsAutoRefresh', 'false');
        document.getElementById('refreshStatus').textContent = 'OFF';
        document.getElementById('autoRefreshBtn').classList.remove('btn-success');
        document.getElementById('autoRefreshBtn').classList.add('btn-outline-info');
    } else {
        // Turn on auto-refresh
        localStorage.setItem('logsAutoRefresh', 'true');
        autoRefreshInterval = setInterval(() => {
            location.reload();
        }, refreshIntervalSeconds * 1000);
        document.getElementById('refreshStatus').textContent = `ON (${refreshIntervalSeconds}s)`;
        document.getElementById('autoRefreshBtn').classList.remove('btn-outline-info');
        document.getElementById('autoRefreshBtn').classList.add('btn-success');
    }
}

// Check if auto-refresh was enabled and restart it on page load
document.addEventListener('DOMContentLoaded', function() {
    if (localStorage.getItem('logsAutoRefresh') === 'true') {
        // Auto-start the refresh
        autoRefreshInterval = setInterval(() => {
            location.reload();
        }, refreshIntervalSeconds * 1000);
        document.getElementById('refreshStatus').textContent = `ON (${refreshIntervalSeconds}s)`;
        document.getElementById('autoRefreshBtn').classList.remove('btn-outline-info');
        document.getElementById('autoRefreshBtn').classList.add('btn-success');
    }
});

function showAdditionalData(data) {
    document.getElementById('additionalDataContent').textContent = JSON.stringify(JSON.parse(data), null, 2);
    new bootstrap.Modal(document.getElementById('additionalDataModal')).show();
}

function cleanupLogs() {
    if (confirm('This will permanently delete logs older than 30 days. Are you sure?')) {
        fetch('/api/cleanup_logs.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(`Successfully cleaned up ${data.deleted_count} old log entries.`);
                location.reload();
            } else {
                alert('Error cleaning up logs: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error cleaning up logs: ' + error.message);
        });
    }
}

function clearAllLogs() {
    if (confirm('This will permanently delete ALL logs. This action cannot be undone. Are you sure?')) {
        if (confirm('Are you absolutely sure? This will delete all system logs permanently.')) {
            fetch('/api/clear_all_logs.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`Successfully cleared ${data.deleted_count} log entries.`);
                    location.reload();
                } else {
                    alert('Error clearing logs: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error clearing logs: ' + error.message);
            });
        }
    }
}
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
