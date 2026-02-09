<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/version.php';
requireLogin();
requireAdmin();

// Get instance configuration
$instance_config = [];
if (file_exists(__DIR__ . '/config/instance.json')) {
    $instance_config = json_decode(file_get_contents(__DIR__ . '/config/instance.json'), true);
}

$customer_name = $instance_config['customer_name'] ?? 'Unknown Customer';
$instance_id = $instance_config['instance_id'] ?? 'unknown';
$main_server = $instance_config['main_server'] ?? 'opn.agit8or.net';

// Handle update actions
$message = '';
$message_type = '';

if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'check_updates':
            // Check for available updates
            $result = check_for_updates($main_server, $instance_id);
            if ($result['success']) {
                $message = "Update check completed. " . count($result['updates']) . " updates available.";
                $message_type = 'success';
            } else {
                $message = "Update check failed: " . $result['error'];
                $message_type = 'danger';
            }
            break;
            
        case 'apply_update':
            $update_id = $_POST['update_id'] ?? '';
            if ($update_id) {
                $result = apply_update($main_server, $instance_id, $update_id);
                if ($result['success']) {
                    $message = "Update applied successfully: " . $update_id;
                    $message_type = 'success';
                } else {
                    $message = "Update failed: " . $result['error'];
                    $message_type = 'danger';
                }
            }
            break;
    }
}

$current_version = APP_VERSION;

// Get available updates
$available_updates = [];
try {
    $update_check = check_for_updates($main_server, $instance_id);
    if ($update_check['success']) {
        $available_updates = $update_check['updates'];
    }
} catch (Exception $e) {
    // Silently handle connection errors
}


include __DIR__ . '/inc/header.php';
include __DIR__ . '/inc/navigation.php';

// Helper functions
function check_for_updates($main_server, $instance_id) {
    global $DB;
    
    try {
        $current_version = get_current_version();
        
        // Get available updates from database directly (same server)
        $stmt = $DB->prepare("
            SELECT version, description, created_at as release_date 
            FROM platform_versions 
            WHERE status = 'released' 
            AND version > ? 
            ORDER BY created_at ASC
        ");
        $stmt->execute([$current_version]);
        $updates = $stmt->fetchAll();
        
        // Format updates for response
        $available_updates = [];
        $sequential_order = 1;
        
        foreach ($updates as $update) {
            $available_updates[] = [
                'id' => 'update_' . str_replace('.', '_', $update['version']),
                'version' => $update['version'],
                'description' => $update['description'],
                'release_date' => date('M j, Y', strtotime($update['release_date'])),
                'size' => '2.5 MB', // Mock size for now
                'requires_restart' => false,
                'dependencies' => [],
                'sequential_order' => $sequential_order++
            ];
        }
        
        return [
            'success' => true,
            'updates' => $available_updates,
            'current_version' => $current_version,
            'latest_version' => !empty($available_updates) ? end($available_updates)['version'] : $current_version
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function apply_update($main_server, $instance_id, $update_id) {
    global $DB;
    
    try {
        // Extract version from update_id (format: update_X_Y_Z)
        $version_parts = explode('_', $update_id);
        if (count($version_parts) >= 2) {
            array_shift($version_parts); // Remove 'update'
            $version = implode('.', $version_parts);
        } else {
            throw new Exception('Invalid update ID format');
        }
        
        // Get update information from database
        $stmt = $DB->prepare("
            SELECT version, description, changelog 
            FROM platform_versions 
            WHERE version = ? AND status = 'released'
        ");
        $stmt->execute([$version]);
        $update_info = $stmt->fetch();
        
        if (!$update_info) {
            return ['success' => false, 'error' => 'Update not found'];
        }
        
        // Create update package
        $update_package = [
            'id' => $update_id,
            'version' => $update_info['version'],
            'description' => $update_info['description'],
            'files' => [
                // Example: Add a simple update marker file
                [
                    'path' => 'updates/applied/' . $update_id . '.txt',
                    'content' => base64_encode("Update {$update_info['version']} applied on " . date('Y-m-d H:i:s')),
                    'permissions' => '644'
                ]
            ],
            'sql' => [
                // Mark this update as applied in the database
                "INSERT INTO change_log (version, change_type, component, title, description, author, created_at) VALUES ('{$update_info['version']}', 'update_applied', 'system', 'Platform Update', '{$update_info['description']}', 'system', NOW()) ON DUPLICATE KEY UPDATE description = VALUES(description)"
            ],
            'requires_restart' => false
        ];
        
        // Apply the update directly
        return execute_update($update_package);
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function execute_update($update_data) {
    global $DB;
    
    try {
        // Log update start
        $stmt = $DB->prepare("
            INSERT INTO change_log (version, change_type, component, title, description, author, created_at) 
            VALUES (?, 'update_applied', 'system', ?, ?, 'system', NOW())
        ");
        $stmt->execute([
            $update_data['version'],
            "Update Applied: " . $update_data['version'],
            "Applied update from main server: " . $update_data['description']
        ]);
        
        // Execute update files
        foreach ($update_data['files'] as $file) {
            $file_path = __DIR__ . '/' . $file['path'];
            $file_dir = dirname($file_path);
            
            // Create directory if it doesn't exist
            if (!is_dir($file_dir)) {
                mkdir($file_dir, 0755, true);
            }
            
            // Write file content
            file_put_contents($file_path, base64_decode($file['content']));
            
            // Set permissions if specified
            if (isset($file['permissions'])) {
                chmod($file_path, octdec($file['permissions']));
            }
        }
        
        // Execute SQL updates if any
        if (isset($update_data['sql']) && !empty($update_data['sql'])) {
            foreach ($update_data['sql'] as $sql_statement) {
                $DB->exec($sql_statement);
            }
        }
        
        // Update platform version
        $stmt = $DB->prepare("
            INSERT INTO platform_versions (version, status, description, release_date) 
            VALUES (?, 'released', ?, CURDATE())
            ON DUPLICATE KEY UPDATE description = VALUES(description)
        ");
        $stmt->execute([$update_data['version'], $update_data['description']]);
        
        return ['success' => true, 'message' => 'Update applied successfully'];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function get_current_version() {
    global $DB;
    $stmt = $DB->query("SELECT version FROM platform_versions WHERE status = 'released' ORDER BY created_at DESC LIMIT 1");
    return $stmt->fetchColumn() ?: '1.0.0';
}
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="container-fluid">
    <div class="row">
        <!-- Administration Sidebar -->
        <div class="col-md-3">
            <?php include __DIR__ . '/inc/sidebar.php'; ?>
        </div>

        <!-- Main Content -->
        <div class="col-md-9">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-download me-2"></i>Platform Updates
                    </h3>
                    <div class="card-tools">
                        <span class="badge bg-primary">Current Version: <?php echo htmlspecialchars($current_version); ?></span>
                        <span class="badge bg-info">Instance: <?php echo htmlspecialchars($customer_name); ?></span>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Instance Information -->
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="card bg-info">
                                <div class="card-body text-center">
                                    <h4><?php echo htmlspecialchars($current_version); ?></h4>
                                    <p>Current Version</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card bg-primary">
                                <div class="card-body text-center">
                                    <h4><?php echo count($available_updates); ?></h4>
                                    <p>Available Updates</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card bg-secondary">
                                <div class="card-body text-center">
                                    <h4><?php echo htmlspecialchars($main_server); ?></h4>
                                    <p>Main Server</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Update Actions -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="action" value="check_updates">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="fas fa-sync me-1"></i>Check for Updates
                                </button>
                            </form>
                            <small class="text-muted">Last checked: <span id="lastChecked">Click to check</span></small>
                        </div>
                    </div>

                    <!-- Available Updates -->
                    <?php if (!empty($available_updates)): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-download me-2"></i>Available Updates
                                <span class="badge bg-warning"><?php echo count($available_updates); ?> updates</span>
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Important:</strong> Updates must be applied in sequence. Please apply them in the order shown below.
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-dark table-hover">
                                    <thead>
                                        <tr>
                                            <th>Version</th>
                                            <th>Description</th>
                                            <th>Release Date</th>
                                            <th>Size</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($available_updates as $index => $update): ?>
                                        <tr class="<?php echo $index === 0 ? 'table-warning' : ''; ?>">
                                            <td>
                                                <strong><?php echo htmlspecialchars($update['version']); ?></strong>
                                                <?php if ($index === 0): ?>
                                                <span class="badge bg-warning text-white ms-1">Next</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($update['description']); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($update['release_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($update['size'] ?? 'Unknown'); ?></td>
                                            <td>
                                                <?php if ($index === 0): ?>
                                                <form method="post" style="display: inline;">
                                                    <input type="hidden" name="action" value="apply_update">
                                                    <input type="hidden" name="update_id" value="<?php echo htmlspecialchars($update['id']); ?>">
                                                    <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Apply update <?php echo htmlspecialchars($update['version']); ?>? This action cannot be undone.')">
                                                        <i class="fas fa-download me-1"></i>Apply Update
                                                    </button>
                                                </form>
                                                <?php else: ?>
                                                <button class="btn btn-secondary btn-sm" disabled>
                                                    <i class="fas fa-lock me-1"></i>Waiting
                                                </button>
                                                <small class="text-muted d-block">Apply previous updates first</small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <strong>System Up to Date:</strong> No updates are currently available.
                    </div>
                    <?php endif; ?>

                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h6><i class="fas fa-cog me-2"></i>Instance Configuration</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-dark table-sm">
                                        <tr>
                                            <th>Customer Name:</th>
                                            <td><?php echo htmlspecialchars($customer_name); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Instance ID:</th>
                                            <td><?php echo htmlspecialchars($instance_id); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Main Server:</th>
                                            <td><?php echo htmlspecialchars($main_server); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Update URL:</th>
                                            <td>https://<?php echo htmlspecialchars($main_server); ?>/api/updates/</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-update last checked time
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('lastChecked').textContent = new Date().toLocaleString();
});

// Auto-refresh available updates every 5 minutes
setInterval(function() {
    // Could add automatic update checking here if desired
}, 5 * 60 * 1000);
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>