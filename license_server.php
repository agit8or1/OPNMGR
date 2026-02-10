<?php
// Development > License Server
require_once __DIR__ . '/inc/bootstrap.php';
require_once 'inc/license_utils.php';
requireLogin();
$page_title = "License Server";

$message = '';
$message_type = '';

// Check if license tables exist, if not, try to create them automatically
$tablesExist = true;
try {
    db()->query("SELECT 1 FROM deployed_instances LIMIT 1");
} catch (PDOException $e) {
    // Try to auto-initialize tables
    try {
        $result = initializeLicenseTables(db());
        if ($result['success']) {
            $message = "License system initialized automatically. You can now create instances.";
            $message_type = 'info';
            $tablesExist = true;
        } else {
            $tablesExist = false;
            $message = "License system could not be initialized. Please contact your administrator.";
            $message_type = 'warning';
        }
    } catch (Exception $ex) {
        $tablesExist = false;
        $message = "License system could not be initialized. Please contact your administrator.";
        $message_type = 'warning';
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablesExist) {
    if (isset($_POST['add_instance'])) {
        // Generate unique instance key
        $instance_key = generateLicenseKey();
        $server_mac = trim($_POST['server_mac'] ?? '');
        
        $stmt = db()->prepare("INSERT INTO deployed_instances (instance_name, instance_key, server_mac, license_tier, max_firewalls, status, license_expires) VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))");
        $expires_days = $_POST['status'] === 'trial' ? 30 : 365;
        $stmt->execute([
            $_POST['instance_name'],
            $instance_key,
            !empty($server_mac) ? $server_mac : null,
            $_POST['license_tier'],
            $_POST['max_firewalls'],
            $_POST['status'],
            $expires_days
        ]);
        
        $instanceId = db()->lastInsertId();
        
        // Generate API credentials
        $apiCreds = generateAPICredentials();
        $apiStmt = db()->prepare("INSERT INTO license_api_keys (instance_id, api_key, api_secret, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 YEAR))");
        $apiStmt->execute([$instanceId, $apiCreds['key'], $apiCreds['secret']]);
        
        // Log activity
        logLicenseActivity($instanceId, "Instance created", "create", "Created license for: " . $_POST['instance_name'], $_SESSION['user_id'] ?? 1, db());
        
        $message = "Instance created successfully!<br><strong>Instance Key:</strong> " . htmlspecialchars($instance_key) . "<br><strong>API Key:</strong> " . htmlspecialchars($apiCreds['key']);
        $message_type = 'success';
    }
    
    if (isset($_POST['update_instance'])) {
        $server_mac = trim($_POST['server_mac'] ?? '');
        $stmt = db()->prepare("UPDATE deployed_instances SET instance_name = ?, license_tier = ?, max_firewalls = ?, status = ?, notes = ?, server_mac = ? WHERE id = ?");
        $stmt->execute([
            $_POST['instance_name'],
            $_POST['license_tier'],
            $_POST['max_firewalls'],
            $_POST['status'],
            $_POST['notes'],
            !empty($server_mac) ? $server_mac : null,
            $_POST['instance_id']
        ]);
        
        $message = "Instance updated successfully!";
        $message_type = 'success';
    }
    
    if (isset($_POST['delete_instance'])) {
        $stmt = db()->prepare("DELETE FROM deployed_instances WHERE id = ?");
        $stmt->execute([$_POST['instance_id']]);
        
        $message = "Instance deleted successfully!";
        $message_type = 'success';
    }
    
    if (isset($_POST['extend_license'])) {
        $stmt = db()->prepare("UPDATE deployed_instances SET license_expires = DATE_ADD(license_expires, INTERVAL ? DAY) WHERE id = ?");
        $stmt->execute([$_POST['extend_days'], $_POST['instance_id']]);
        
        $message = "License extended successfully!";
        $message_type = 'success';
    }
}

include __DIR__ . '/inc/header.php';

// Get all instances only if tables exist
$instances = [];
$tiers = [];
$recent_checkins = [];
$stats = null;

if ($tablesExist) {
    try {
        $instances = db()->query("SELECT * FROM deployed_instances ORDER BY created_at DESC")->fetchAll();
        $tiers = db()->query("SELECT * FROM license_tiers WHERE is_active = 1 ORDER BY max_firewalls ASC")->fetchAll();
        
        // Get recent check-ins
        $recent_checkins = db()->query("
            SELECT lc.*, di.instance_name 
            FROM license_checkins lc 
            JOIN deployed_instances di ON lc.instance_id = di.id 
            ORDER BY lc.checkin_time DESC 
            LIMIT 20
        ")->fetchAll();
        
        // Get statistics
        $stats = db()->query("
            SELECT 
                COUNT(*) as total_instances,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_instances,
                SUM(CASE WHEN status = 'trial' THEN 1 ELSE 0 END) as trial_instances,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_instances,
                SUM(max_firewalls) as total_firewall_capacity,
                SUM(current_firewalls) as total_firewalls_used
            FROM deployed_instances
        ")->fetch();
    } catch (PDOException $e) {
        error_log('License query error: ' . $e->getMessage());
    }
}
$stats = db()->query("
    SELECT 
        COUNT(*) as total_instances,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_instances,
        SUM(CASE WHEN status = 'trial' THEN 1 ELSE 0 END) as trial_instances,
        SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_instances,
        SUM(max_firewalls) as total_firewall_capacity,
        SUM(current_firewalls) as total_firewalls_used
    FROM deployed_instances
")->fetch();
?>

<style>
body {
    background: #1a1d23;
    color: #e0e0e0;
}
.license-container {
    max-width: 1400px;
    margin: 30px auto;
    padding: 0 20px;
}
.license-card {
    background: #2d3139;
    border-radius: 8px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    border: 1px solid #3a3f4b;
}
.license-card h2 {
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
    border-left: 4px solid #3498db;
    padding: 20px;
    border-radius: 6px;
}
.stat-box.success {
    border-left-color: #27ae60;
}
.stat-box.warning {
    border-left-color: #f39c12;
}
.stat-box.danger {
    border-left-color: #e74c3c;
}
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
.instances-table {
    width: 100%;
    margin-top: 15px;
}
.instances-table th {
    background: #1a1d23;
    color: #4fc3f7;
    padding: 12px;
    text-align: left;
    border-bottom: 2px solid #3498db;
    font-size: 14px;
}
.instances-table td {
    padding: 12px;
    border-bottom: 1px solid #3a3f4b;
    font-size: 14px;
}
.instances-table tr:hover {
    background: #363a45;
}
.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}
.status-active { background: #27ae60; color: white; }
.status-trial { background: #f39c12; color: white; }
.status-expired { background: #e74c3c; color: white; }
.status-suspended { background: #95a5a6; color: white; }
.action-btn {
    padding: 6px 12px;
    margin: 0 3px;
    border-radius: 4px;
    text-decoration: none;
    font-size: 12px;
    transition: all 0.3s;
    display: inline-block;
    border: none;
    cursor: pointer;
}
.btn-edit { background: #3498db; color: white; }
.btn-edit:hover { background: #2980b9; }
.btn-delete { background: #e74c3c; color: white; }
.btn-delete:hover { background: #c0392b; }
.btn-extend { background: #27ae60; color: white; }
.btn-extend:hover { background: #229954; }
.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    padding: 10px 24px;
    font-size: 14px;
    font-weight: 600;
    border-radius: 6px;
    color: white;
    cursor: pointer;
    transition: all 0.3s;
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}
.form-group {
    margin-bottom: 20px;
}
.form-group label {
    display: block;
    margin-bottom: 8px;
    color: #81c784;
    font-weight: 600;
}
.form-control {
    width: 100%;
    padding: 10px;
    background: #1a1d23;
    border: 1px solid #3a3f4b;
    border-radius: 4px;
    color: #e0e0e0;
    font-size: 14px;
}
.form-control:focus {
    outline: none;
    border-color: #3498db;
}
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.8);
    z-index: 9999;
}
.modal-content {
    background: #2d3139;
    margin: 50px auto;
    padding: 30px;
    max-width: 600px;
    border-radius: 8px;
    max-height: 90vh;
    overflow-y: auto;
}
.modal-header {
    color: #4fc3f7;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #3498db;
}
.close-modal {
    float: right;
    font-size: 28px;
    cursor: pointer;
    color: #95a5a6;
}
.close-modal:hover {
    color: #e74c3c;
}
.alert {
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
}
.alert-success {
    background: rgba(39, 174, 96, 0.2);
    border: 1px solid #27ae60;
    color: #27ae60;
}
.alert-danger {
    background: rgba(231, 76, 60, 0.2);
    border: 1px solid #e74c3c;
    color: #e74c3c;
}
.instance-key {
    background: #1a1d23;
    padding: 12px;
    border-radius: 4px;
    font-family: 'Courier New', monospace;
    color: #4fc3f7;
    word-break: break-all;
    border: 1px solid #3a3f4b;
    margin: 10px 0;
}
.copy-key-btn {
    background: #27ae60;
    color: white;
    padding: 6px 12px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    margin-top: 5px;
}
.checkins-table {
    width: 100%;
    font-size: 13px;
}
.checkins-table th {
    background: #1a1d23;
    color: #4fc3f7;
    padding: 10px;
    text-align: left;
    border-bottom: 2px solid #3498db;
}
.checkins-table td {
    padding: 10px;
    border-bottom: 1px solid #3a3f4b;
}
.checkins-table tr:hover {
    background: #363a45;
}
</style>

<div class="license-container">
    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    
    <!-- Statistics -->
    <div class="license-card">
        <h2><i class="fa fa-chart-bar me-2"></i> License Statistics</h2>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-label">Total Instances</div>
                <div class="stat-number"><?= $stats['total_instances'] ?? 0 ?></div>
            </div>
            <div class="stat-box success">
                <div class="stat-label">Active Instances</div>
                <div class="stat-number"><?= $stats['active_instances'] ?? 0 ?></div>
            </div>
            <div class="stat-box warning">
                <div class="stat-label">Trial Instances</div>
                <div class="stat-number"><?= $stats['trial_instances'] ?? 0 ?></div>
            </div>
            <div class="stat-box danger">
                <div class="stat-label">Expired</div>
                <div class="stat-number"><?= $stats['expired_instances'] ?? 0 ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Firewall Capacity</div>
                <div class="stat-number"><?= $stats['total_firewall_capacity'] ?? 0 ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Firewalls Used</div>
                <div class="stat-number"><?= $stats['total_firewalls_used'] ?? 0 ?></div>
            </div>
        </div>
    </div>
    
    <!-- Deployed Instances -->
    <div class="license-card">
        <h2><i class="fa fa-server me-2"></i> Deployed Instances</h2>
        <button class="btn-primary" onclick="showAddModal()">
            <i class="fa fa-plus me-2"></i> Add New Instance
        </button>
        
        <table class="instances-table">
            <thead>
                <tr>
                    <th>Instance Name</th>
                    <th>Status</th>
                    <th>Tier</th>
                    <th>Firewalls</th>
                    <th>Expires</th>
                    <th>Last Check-in</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($instances as $instance): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($instance['instance_name']) ?></strong><br>
                            <small style="color: #95a5a6;"><?= htmlspecialchars($instance['fqdn'] ?: $instance['ip_address'] ?: 'Not set') ?></small>
                        </td>
                        <td>
                            <span class="status-badge status-<?= $instance['status'] ?>">
                                <?= strtoupper($instance['status']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($instance['license_tier']) ?></td>
                        <td><?= $instance['current_firewalls'] ?> / <?= $instance['max_firewalls'] ?></td>
                        <td><?= date('Y-m-d', strtotime($instance['license_expires'])) ?></td>
                        <td><?= $instance['last_checkin'] ? date('Y-m-d H:i', strtotime($instance['last_checkin'])) : 'Never' ?></td>
                        <td>
                            <button class="action-btn btn-edit" onclick="showEditModal(<?= $instance['id'] ?>)">
                                <i class="fa fa-edit"></i> Edit
                            </button>
                            <button class="action-btn btn-extend" onclick="showExtendModal(<?= $instance['id'] ?>)">
                                <i class="fa fa-clock"></i> Extend
                            </button>
                            <button class="action-btn btn-delete" onclick="deleteInstance(<?= $instance['id'] ?>)">
                                <i class="fa fa-trash"></i> Delete
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($instances)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: #95a5a6; padding: 30px;">
                            No deployed instances yet. Click "Add New Instance" to get started.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Recent Check-ins -->
    <div class="license-card">
        <h2><i class="fa fa-history me-2"></i> Recent Check-ins</h2>
        <table class="checkins-table">
            <thead>
                <tr>
                    <th>Instance</th>
                    <th>Time</th>
                    <th>IP Address</th>
                    <th>Version</th>
                    <th>Firewalls</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_checkins as $checkin): ?>
                    <tr>
                        <td><?= htmlspecialchars($checkin['instance_name']) ?></td>
                        <td><?= date('Y-m-d H:i:s', strtotime($checkin['checkin_time'])) ?></td>
                        <td><?= htmlspecialchars($checkin['ip_address']) ?></td>
                        <td><?= htmlspecialchars($checkin['version'] ?: 'N/A') ?></td>
                        <td><?= $checkin['firewall_count'] ?? 0 ?></td>
                        <td><?= htmlspecialchars($checkin['status_code']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($recent_checkins)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: #95a5a6; padding: 20px;">
                            No check-ins recorded yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Instance Modal -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
            <h3>Add New Instance</h3>
        </div>
        <form method="POST">
            <div class="form-group">
                <label>Instance Name *</label>
                <input type="text" name="instance_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Server MAC Address (locks to this server)</label>
                <input type="text" name="server_mac" class="form-control" placeholder="AA:BB:CC:DD:EE:FF">
                <small style="color: #95a5a6;">Leave blank to allow on any server</small>
            </div>
            <div class="form-group">
                <label>License Tier *</label>
                <select name="license_tier" class="form-control" required onchange="updateMaxFirewalls(this)">
                    <?php foreach ($tiers as $tier): ?>
                        <option value="<?= htmlspecialchars($tier['tier_name']) ?>" data-max="<?= $tier['max_firewalls'] ?>">
                            <?= htmlspecialchars($tier['tier_name']) ?> (<?= $tier['max_firewalls'] ?> firewalls - $<?= $tier['price_monthly'] ?>/mo)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Max Firewalls *</label>
                <input type="number" name="max_firewalls" id="max_firewalls" class="form-control" value="5" required>
            </div>
            <div class="form-group">
                <label>Status *</label>
                <select name="status" class="form-control" required>
                    <option value="trial">Trial (30 days)</option>
                    <option value="active">Active (365 days)</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>
            <button type="submit" name="add_instance" class="btn-primary">
                <i class="fa fa-plus me-2"></i> Create Instance
            </button>
        </form>
    </div>
</div>

<!-- Edit Instance Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
            <h3>Edit Instance</h3>
        </div>
        <form method="POST">
            <input type="hidden" name="instance_id" id="edit_instance_id">
            <input type="hidden" name="update_instance" value="1">
            <div class="form-group">
                <label>Instance Name *</label>
                <input type="text" name="instance_name" id="edit_instance_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Server MAC Address (locks to this server)</label>
                <input type="text" name="server_mac" id="edit_server_mac" class="form-control" placeholder="AA:BB:CC:DD:EE:FF">
                <small style="color: #95a5a6;">Leave blank to allow on any server</small>
            </div>
            <div class="form-group">
                <label>License Tier *</label>
                <select name="license_tier" id="edit_license_tier" class="form-control" onchange="updateMaxFirewallsEdit(this)" required>
                    <?php foreach ($tiers as $tier): ?>
                        <option value="<?= htmlspecialchars($tier['tier_name']) ?>" data-max="<?= $tier['max_firewalls'] ?>">
                            <?= htmlspecialchars($tier['tier_name']) ?> (<?= $tier['max_firewalls'] ?> firewalls - $<?= $tier['price_monthly'] ?>/mo)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Max Firewalls *</label>
                <input type="number" name="max_firewalls" id="edit_max_firewalls" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Status *</label>
                <select name="status" id="edit_status" class="form-control" required>
                    <option value="trial">Trial (30 days)</option>
                    <option value="active">Active (365 days)</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" id="edit_notes" class="form-control" rows="3"></textarea>
            </div>
            <button type="submit" class="btn-primary">
                <i class="fa fa-save me-2"></i> Save Changes
            </button>
        </form>
    </div>
</div>

<!-- Extend License Modal -->
<div id="extendModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="close-modal" onclick="closeModal('extendModal')">&times;</span>
            <h3>Extend License</h3>
        </div>
        <form method="POST">
            <input type="hidden" name="instance_id" id="extend_instance_id">
            <div class="form-group">
                <label>Extend by (days) *</label>
                <select name="extend_days" class="form-control" required>
                    <option value="30">30 days</option>
                    <option value="90">90 days</option>
                    <option value="180">180 days</option>
                    <option value="365">365 days (1 year)</option>
                </select>
            </div>
            <button type="submit" name="extend_license" class="btn-primary">
                <i class="fa fa-clock me-2"></i> Extend License
            </button>
        </form>
    </div>
</div>

<script>
const instancesData = <?= json_encode($instances) ?>;

function showAddModal() {
    document.getElementById('addModal').style.display = 'block';
}

function showEditModal(id) {
    const instance = instancesData.find(i => i.id === id);
    if (!instance) return;
    
    document.getElementById('edit_instance_id').value = instance.id;
    document.getElementById('edit_instance_name').value = instance.instance_name;
    document.getElementById('edit_server_mac').value = instance.server_mac || '';
    document.getElementById('edit_license_tier').value = instance.license_tier;
    document.getElementById('edit_max_firewalls').value = instance.max_firewalls;
    document.getElementById('edit_status').value = instance.status;
    document.getElementById('edit_notes').value = instance.notes || '';
    
    document.getElementById('editModal').style.display = 'block';
}

function showExtendModal(id) {
    document.getElementById('extend_instance_id').value = id;
    document.getElementById('extendModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function updateMaxFirewalls(select) {
    const maxFirewalls = select.options[select.selectedIndex].getAttribute('data-max');
    document.getElementById('max_firewalls').value = maxFirewalls;
}

function updateMaxFirewallsEdit(select) {
    const maxFirewalls = select.options[select.selectedIndex].getAttribute('data-max');
    document.getElementById('edit_max_firewalls').value = maxFirewalls;
}

function deleteInstance(id) {
    if (confirm('Are you sure you want to delete this instance? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="instance_id" value="${id}">
            <input type="hidden" name="delete_instance" value="1">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Close modals when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

// Initialize max firewalls on page load
document.addEventListener('DOMContentLoaded', function() {
    const tierSelect = document.querySelector('select[name="license_tier"]');
    if (tierSelect) {
        updateMaxFirewalls(tierSelect);
    }
});
</script>

<?php include 'inc/footer.php'; ?>
