<?php
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();

$message = '';

// Handle edit request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_firewall'])) {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = '<div class="alert alert-danger">CSRF verification failed.</div>';
    } else {
        $edit_id = (int)$_POST['firewall_id'];
        $hostname = trim($_POST['hostname'] ?? '');
        $ip_address = trim($_POST['ip_address'] ?? '');
        $wan_ip = trim($_POST['wan_ip'] ?? '');
        $customer_name = trim($_POST['customer_name'] ?? '');
        $tags = $_POST['tags'] ?? [];
        
        try {
            // Update firewall basic info
            $stmt = db()->prepare('UPDATE firewalls SET hostname = ?, ip_address = ?, wan_ip = ?, customer_name = ? WHERE id = ?');
            $stmt->execute([$hostname, $ip_address, $wan_ip, $customer_name, $edit_id]);
            
            // Update tags
            $stmt = db()->prepare('DELETE FROM firewall_tags WHERE firewall_id = ?');
            $stmt->execute([$edit_id]);
            
            if (!empty($tags)) {
                foreach ($tags as $tag_id) {
                    $stmt = db()->prepare('INSERT INTO firewall_tags (firewall_id, tag_id) VALUES (?, ?)');
                    $stmt->execute([$edit_id, $tag_id]);
                }
            }
            
            $message = '<div class="alert alert-success">Firewall updated successfully!</div>';
        } catch (Exception $e) {
            error_log("firewall_view.php error: " . $e->getMessage());
            $message = '<div class="alert alert-danger">An internal error occurred while updating the firewall.</div>';
        }
    }
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: /firewalls.php');
    exit;
}

try {
    $stmt = db()->prepare('
        SELECT f.*, 
               GROUP_CONCAT(t.name SEPARATOR ", ") as tag_names, 
               GROUP_CONCAT(t.color SEPARATOR ", ") as tag_colors,
               GROUP_CONCAT(t.id SEPARATOR ",") as tag_ids,
               CASE 
                   WHEN f.last_checkin IS NULL THEN "unknown"
                   WHEN f.last_checkin < DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN "offline"
                   ELSE "online"
               END as current_status
        FROM firewalls f
        LEFT JOIN firewall_tags ft ON f.id = ft.firewall_id
        LEFT JOIN tags t ON ft.tag_id = t.id
        WHERE f.id = ?
        GROUP BY f.id
    ');
    $stmt->execute([$id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get all available tags and customers for dropdowns
    $stmt = db()->query('SELECT id, name, color FROM tags ORDER BY name');
    $all_tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = db()->query('SELECT id, name FROM customers ORDER BY name');
    $all_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $firewall = null;
    $all_tags = [];
    $all_customers = [];
}

if (!$firewall) {
    header('Location: /firewalls.php');
    exit;
}

include __DIR__ . '/inc/header.php';
?>

<div class="row">
    <div class="col-md-12">
        <div class="card card-dark">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <small class="text-light fw-bold mb-0">
                        <i class="fas fa-network-wired me-1"></i>Firewall Details - <?php echo htmlspecialchars($firewall['hostname']); ?>
                    </small>
                    <div class="ms-auto">
                        <button type="button" class="btn btn-primary btn-sm me-2" onclick="toggleEditMode()">
                            <i class="fas fa-edit me-1"></i>Edit
                        </button>
                        <button type="button" class="btn btn-danger btn-sm me-2" onclick="uninstallAgent()">
                            <i class="fas fa-trash me-1"></i>Uninstall Agent
                        </button>
                        <a href="/firewalls.php" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Back to Firewalls
                        </a>
                    </div>
                </div>

                <?php echo $message; ?>

                <div class="row">
                    <div class="col-md-6">
                        <div class="card card-ghost p-3">
                            <h6 class="text-light mb-3">Basic Information</h6>
                            <div class="mb-2">
                                <strong class="text-light">Hostname:</strong>
                                <span class="text-muted"><?php echo htmlspecialchars($firewall['hostname']); ?></span>
                            </div>
                            <div class="mb-2">
                                <strong class="text-light">IP Address:</strong>
                                <span class="text-muted"><?php echo htmlspecialchars($firewall['ip_address']); ?></span>
                            </div>
                            <div class="mb-2">
                                <strong class="text-light">Status:</strong>
                                <span class="badge <?php 
                                    $status = $firewall['current_status'] ?? 'unknown';
                                    echo $status === 'online' ? 'bg-success' : ($status === 'offline' ? 'bg-danger' : 'bg-warning');
                                ?>">
                                    <?php echo htmlspecialchars($status); ?>
                                </span>
                                <?php if (!empty($firewall['last_checkin'])): ?>
                                    <small class="text-muted">
                                        Last checkin: <?php echo date('M j, Y H:i:s', strtotime($firewall['last_checkin'])); ?>
                                    </small>
                                <?php endif; ?>
                                <?php if (!empty($firewall['agent_version'])): ?>
                                    <small class="text-muted">
                                        Agent version: <?php echo htmlspecialchars($firewall['agent_version']); ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($firewall['enrolled_at'])): ?>
                            <div class="mb-2">
                                <strong class="text-light">Enrolled:</strong>
                                <span class="text-muted"><?php echo htmlspecialchars($firewall['enrolled_at']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card card-ghost p-3">
                            <h6 class="text-light mb-3">Customer Information</h6>
                            <?php if (!empty($firewall['customer_name'])): ?>
                            <div class="mb-2">
                                <strong class="text-light">Customer Name:</strong>
                                <span class="text-muted"><?php echo htmlspecialchars($firewall['customer_name']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($firewall['wan_ip'])): ?>
                            <div class="mb-2">
                                <strong class="text-light">WAN IP:</strong>
                                <span class="text-muted"><?php echo htmlspecialchars($firewall['wan_ip']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($firewall['tag_names'])): ?>
                            <div class="mb-2">
                                <strong class="text-light">Tags:</strong><br>
                                <?php 
                                $tag_names = explode(', ', $firewall['tag_names']);
                                $tag_colors = explode(', ', $firewall['tag_colors']);
                                foreach ($tag_names as $index => $tag_name): 
                                ?>
                                    <span class="badge me-1" style="background-color: <?php echo htmlspecialchars($tag_colors[$index]); ?>; color: white;">
                                        <?php echo htmlspecialchars($tag_name); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($firewall['reverse_proxy_url'])): ?>
                            <div class="mb-2">
                                <strong class="text-light">Reverse Proxy URL:</strong>
                                <span class="text-muted"><?php echo htmlspecialchars($firewall['reverse_proxy_url']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Backup Management Section -->
                <div class="row mt-3">
                    <div class="col-md-12">
                        <div class="card card-ghost p-3">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="text-light mb-0">
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
                                <p class="text-muted mt-2">Loading backups...</p>
                            </div>
                            
                            <div id="backupsContainer" style="display: none;">
                                <div class="table-responsive">
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
                                    <p class="text-muted text-center py-4">No backups found. Create your first backup to get started.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editFirewallModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header bg-dark border-secondary">
                <h5 class="modal-title text-light">Edit Firewall</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body bg-dark">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="firewall_id" value="<?php echo $firewall['id']; ?>">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="hostname" class="form-label text-light fw-bold">Hostname</label>
                                <input type="text" class="form-control bg-dark text-light border-secondary" id="hostname" name="hostname" value="<?php echo htmlspecialchars($firewall['hostname']); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="ip_address" class="form-label text-light fw-bold">IP Address</label>
                                <input type="text" class="form-control bg-dark text-light border-secondary" id="ip_address" name="ip_address" value="<?php echo htmlspecialchars($firewall['ip_address']); ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="wan_ip" class="form-label text-light fw-bold">WAN IP Address</label>
                                <input type="text" class="form-control bg-dark text-light border-secondary" id="wan_ip" name="wan_ip" value="<?php echo htmlspecialchars($firewall['wan_ip'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="customer_name" class="form-label text-light fw-bold">Customer</label>
                                <select class="form-select bg-dark text-light border-secondary" id="customer_name" name="customer_name">
                                    <option value="">Select Customer</option>
                                    <?php foreach ($all_customers as $customer): ?>
                                        <option value="<?php echo htmlspecialchars($customer['name']); ?>" 
                                                <?php echo ($firewall['customer_name'] === $customer['name']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($customer['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="tags" class="form-label text-light fw-bold">Tags</label>
                        <select multiple class="form-select bg-dark text-light border-secondary" id="tags" name="tags[]" size="5">
                            <?php 
                            $selected_tag_ids = !empty($firewall['tag_ids']) ? explode(',', $firewall['tag_ids']) : [];
                            foreach ($all_tags as $tag): 
                            ?>
                                <option value="<?php echo $tag['id']; ?>" 
                                        style="background-color: <?php echo htmlspecialchars($tag['color']); ?>; color: white;"
                                        <?php echo in_array($tag['id'], $selected_tag_ids) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tag['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Hold Ctrl/Cmd to select multiple tags</small>
                    </div>
                </div>
                <div class="modal-footer bg-dark border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_firewall" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const firewallId = <?php echo $firewall['id']; ?>;
const csrfToken = '<?php echo csrf_token(); ?>';

function toggleEditMode() {
    new bootstrap.Modal(document.getElementById('editFirewallModal')).show();
}

function uninstallAgent() {
    if (confirm("Are you sure you want to uninstall the agent? This will stop monitoring but keep the firewall entry in the system.")) {
        const uninstallCommand = `curl -k -s https://opn.agit8or.net/uninstall_agent.php?firewall_id=${firewallId} | sh`;
        
        // Show the command to the user
        const modal = document.createElement("div");
        modal.innerHTML = `
            <div class="modal fade" id="uninstallModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content bg-dark text-light">
                        <div class="modal-header">
                            <h5 class="modal-title">Uninstall Agent Command</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p>Run this command on your firewall to uninstall the agent:</p>
                            <div class="bg-secondary p-3 rounded">
                                <code>${uninstallCommand}</code>
                            </div>
                            <p class="mt-3 text-warning"><small>This will stop the agent but keep the firewall entry for future re-enrollment.</small></p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="button" class="btn btn-primary" onclick="copyToClipboard('${uninstallCommand}')">Copy Command</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        new bootstrap.Modal(document.getElementById("uninstallModal")).show();
    }
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        alert("Command copied to clipboard!");
    });
}

// Backup Management Functions
function loadBackups() {
    fetch(`/api/get_backups.php?firewall_id=${firewallId}`)
        .then(response => response.json())
        .then(data => {
            document.getElementById('backupsLoading').style.display = 'none';
            document.getElementById('backupsContainer').style.display = 'block';
            
            if (data.success && data.backups && data.backups.length > 0) {
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
                        <td class="text-muted small">${backup.description || 'N/A'}</td>
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

// Load backups on page load
document.addEventListener('DOMContentLoaded', function() {
    loadBackups();
});
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
