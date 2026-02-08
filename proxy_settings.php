<?php
require_once __DIR__ . '/inc/auth.php';
requireLogin();
requireAdmin();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/csrf.php';
require_once __DIR__ . '/inc/logging.php';

$notice = '';

// Load current settings
$rows = $DB->query('SELECT `name`,`value` FROM settings WHERE name IN ("proxy_port_start", "proxy_port_end")')->fetchAll(PDO::FETCH_KEY_PAIR);
$proxy_port_start = $rows['proxy_port_start'] ?? '8100';
$proxy_port_end = $rows['proxy_port_end'] ?? '8199';

// Helper function
function save_setting($DB, $k, $v) {
    $s = $DB->prepare('INSERT INTO settings (`name`,`value`) VALUES (:k,:v) ON DUPLICATE KEY UPDATE `value` = :v2');
    $s->execute([':k' => $k, ':v' => $v, ':v2' => $v]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $notice = 'Bad CSRF';
    } else {
        if (!empty($_POST['save_proxy'])) {
            $new_start = trim($_POST['proxy_port_start'] ?? '8100');
            $new_end = trim($_POST['proxy_port_end'] ?? '8199');
            
            // Validate port ranges
            if (!is_numeric($new_start) || !is_numeric($new_end) || 
                $new_start < 1024 || $new_end > 65535 || 
                $new_start >= $new_end) {
                $notice = 'Invalid port range. Start must be >= 1024, end <= 65535, and start < end.';
            } else {
                save_setting($DB, 'proxy_port_start', $new_start);
                save_setting($DB, 'proxy_port_end', $new_end);
                log_action($DB, $_SESSION['user_id'], 'proxy_settings', 'updated', "Port range changed to {$new_start}-{$new_end}");
                $notice = 'Proxy settings saved successfully.';
                $proxy_port_start = $new_start;
                $proxy_port_end = $new_end;
            }
        }
    }
}

// Get current proxy assignments
$proxy_assignments = $DB->query("
    SELECT 
        id, 
        hostname, 
        proxy_port, 
        CASE WHEN proxy_enabled = 1 THEN 'Enabled' ELSE 'Disabled' END as status
    FROM firewalls 
    WHERE proxy_port IS NOT NULL AND proxy_port != 0 
    ORDER BY proxy_port
")->fetchAll(PDO::FETCH_ASSOC);

$used_ports = count($proxy_assignments);
$total_ports = $proxy_port_end - $proxy_port_start + 1;
$available_ports = $total_ports - $used_ports;

include __DIR__ . '/inc/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">
        <i class="fas fa-network-wired me-2"></i>Proxy Settings
    </h4>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="/">Dashboard</a></li>
            <li class="breadcrumb-item active">Proxy Settings</li>
        </ol>
    </nav>
</div>

<?php if ($notice): ?>
<div class="alert alert-<?php echo strpos($notice, 'success') !== false ? 'success' : 'danger'; ?> alert-dismissible fade show">
    <?php echo htmlspecialchars($notice); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Configuration Card -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-cogs me-2"></i>Port Range Configuration
                </h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Configure the port range used for reverse proxy connections to firewalls. Each firewall gets a unique port within this range.
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="proxy_port_start" class="form-label">Start Port</label>
                                <input type="number" class="form-control" id="proxy_port_start" name="proxy_port_start" 
                                       value="<?php echo htmlspecialchars($proxy_port_start); ?>" 
                                       placeholder="8100" min="1024" max="65535" required>
                                <div class="form-text">Minimum port number (≥ 1024)</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="proxy_port_end" class="form-label">End Port</label>
                                <input type="number" class="form-control" id="proxy_port_end" name="proxy_port_end" 
                                       value="<?php echo htmlspecialchars($proxy_port_end); ?>" 
                                       placeholder="8199" min="1024" max="65535" required>
                                <div class="form-text">Maximum port number (≤ 65535)</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Important:</strong> These ports must be open in your server's firewall for external connections to work.
                    </div>
                    
                    <button type="submit" name="save_proxy" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Statistics Card -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-chart-bar me-2"></i>Port Usage Statistics
                </h5>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-4">
                        <div class="border rounded p-3">
                            <h3 class="text-primary mb-1"><?php echo $total_ports; ?></h3>
                            <small class="text-muted">Total Ports</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border rounded p-3">
                            <h3 class="text-danger mb-1"><?php echo $used_ports; ?></h3>
                            <small class="text-muted">Used Ports</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border rounded p-3">
                            <h3 class="text-success mb-1"><?php echo $available_ports; ?></h3>
                            <small class="text-muted">Available</small>
                        </div>
                    </div>
                </div>
                
                <div class="mt-3">
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span>Port Range Usage</span>
                        <span><?php echo $used_ports; ?>/<?php echo $total_ports; ?></span>
                    </div>
                    <div class="progress">
                        <div class="progress-bar" role="progressbar" 
                             style="width: <?php echo $total_ports > 0 ? ($used_ports / $total_ports) * 100 : 0; ?>%">
                        </div>
                    </div>
                </div>
                
                <div class="mt-3">
                    <p class="mb-1"><strong>Current Range:</strong> <?php echo $proxy_port_start; ?> - <?php echo $proxy_port_end; ?></p>
                    <p class="mb-0 small text-muted">
                        Capacity for <?php echo $total_ports; ?> simultaneous proxy connections
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($proxy_assignments)): ?>
<div class="card mt-4">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="fas fa-list me-2"></i>Current Port Assignments
        </h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Firewall ID</th>
                        <th>Hostname</th>
                        <th>Proxy Port</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($proxy_assignments as $assignment): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($assignment['id']); ?></td>
                        <td><?php echo htmlspecialchars($assignment['hostname']); ?></td>
                        <td>
                            <span class="badge bg-primary"><?php echo htmlspecialchars($assignment['proxy_port']); ?></span>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $assignment['status'] === 'Enabled' ? 'success' : 'secondary'; ?>">
                                <?php echo htmlspecialchars($assignment['status']); ?>
                            </span>
                        </td>
                        <td>
                            <a href="firewall_details.php?id=<?php echo $assignment['id']; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-eye me-1"></i>View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/inc/footer.php'; ?>