<?php
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get firewall ID from URL
$firewall_id = $_GET['id'] ?? null;
if (!$firewall_id) {
    header('Location: firewalls.php');
    exit;
}

// Fetch firewall data
$stmt = db()->prepare("
    SELECT f.*, 
           pa.last_checkin, pa.agent_version, pa.status as agent_status, pa.wan_ip, pa.ipv6_address, pa.opnsense_version,
           ua.agent_version as update_agent_version
    FROM firewalls f
    LEFT JOIN firewall_agents pa ON f.id = pa.firewall_id AND pa.agent_type = 'primary'
    LEFT JOIN firewall_agents ua ON f.id = ua.firewall_id AND ua.agent_type = 'update'
    WHERE f.id = ?
");
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$firewall) {
    header('Location: firewalls.php');
    exit;
}

// Fetch available tags for dropdown
$available_tags = db()->query("SELECT id, name, color FROM tags ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch customers for customer group dropdown
$customers = db()->query("SELECT id, name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get current firewall's tags as array from firewall_tags junction table
$stmt = db()->prepare("
    SELECT t.id, t.name 
    FROM tags t
    INNER JOIN firewall_tags ft ON t.id = ft.tag_id
    WHERE ft.firewall_id = ?
");
$stmt->execute([$firewall_id]);
$current_tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
$current_tag_ids = array_column($current_tags, 'id');
$current_tag_names = array_column($current_tags, 'name');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    $hostname = trim($_POST['hostname'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $customer_group = trim($_POST['customer_group'] ?? '');
    $tags = trim($_POST['tags'] ?? '');

    if (empty($hostname)) {
        $error = 'Firewall hostname is required';
    } else {
        try {
            // Update firewall basic info (no tag_names column exists)
            $stmt = db()->prepare("
                UPDATE firewalls
                SET hostname = ?, notes = ?, customer_group = ?
                WHERE id = ?
            ");
            $stmt->execute([$hostname, $notes, $customer_group, $firewall_id]);

            // Handle tags - clear existing tags and insert new ones
            if (!empty($tags)) {
                // Delete existing tags for this firewall
                $stmt = db()->prepare("DELETE FROM firewall_tags WHERE firewall_id = ?");
                $stmt->execute([$firewall_id]);

                // Insert new tags
                $tag_names = array_map('trim', explode(',', $tags));
                foreach ($tag_names as $tag_name) {
                    if (!empty($tag_name)) {
                        // Get tag ID from name
                        $stmt = db()->prepare("SELECT id FROM tags WHERE name = ?");
                        $stmt->execute([$tag_name]);
                        $tag_id = $stmt->fetchColumn();
                        
                        if ($tag_id) {
                            // Insert into firewall_tags junction table
                            $stmt = db()->prepare("INSERT IGNORE INTO firewall_tags (firewall_id, tag_id) VALUES (?, ?)");
                            $stmt->execute([$firewall_id, $tag_id]);
                        }
                    }
                }
            } else {
                // If no tags selected, clear all tags for this firewall
                $stmt = db()->prepare("DELETE FROM firewall_tags WHERE firewall_id = ?");
                $stmt->execute([$firewall_id]);
            }

            header('Location: firewall_details.php?id=' . $firewall_id . '&success=1');
            exit;
        } catch (Exception $e) {
            error_log("firewall_edit.php error: " . $e->getMessage());
            $error = 'An internal error occurred while updating the firewall.';
        }
    }
}

// Generate CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$page_title = 'Edit Firewall - ' . htmlspecialchars($firewall['hostname']);
include __DIR__ . '/inc/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="card bg-dark text-white">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-edit"></i> Edit Firewall</h5>
                </div>
                <div class="card-body bg-dark">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="hostname" class="form-label" style="display: block!important; visibility: visible!important; opacity: 1!important; color: #fff!important; font-weight: 500!important; font-size: 1rem!important; margin-bottom: 0.5rem!important;">Firewall Hostname *</label>
                                    <input type="text" class="form-control" id="hostname" name="hostname"
                                           value="<?php echo htmlspecialchars($firewall['hostname']); ?>" required
                                           style="background-color: rgba(255,255,255,0.15)!important; border-color: rgba(138,180,248,0.5)!important; color: #fff!important; font-weight: 500;">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="customer_group" class="form-label" style="display: block!important; visibility: visible!important; opacity: 1!important; color: #fff!important; font-weight: 500!important; font-size: 1rem!important; margin-bottom: 0.5rem!important;">Customer Group</label>
                                    <select class="form-select" id="customer_group" name="customer_group" style="background-color: rgba(255,255,255,0.15)!important; border-color: rgba(138,180,248,0.5)!important; color: #fff!important; font-weight: 500;">
                                        <option value="">-- Select Customer --</option>
                                        <?php foreach ($customers as $customer): ?>
                                            <option value="<?php echo htmlspecialchars($customer['name']); ?>" <?php echo (($firewall["customer_group"] ?? "") === $customer['name']) ? "selected" : ""; ?>><?php echo htmlspecialchars($customer['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                        </div>                        <div class="mb-3">
                            <label for="notes" class="form-label" style="display: block!important; visibility: visible!important; opacity: 1!important; color: #fff!important; font-weight: 500!important; font-size: 1rem!important; margin-bottom: 0.5rem!important;">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"
                                      style="background-color: rgba(255,255,255,0.15)!important; border-color: rgba(138,180,248,0.5)!important; color: #fff!important; font-weight: 500;"><?php echo htmlspecialchars($firewall['notes'] ?? ''); ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="tags" class="form-label" style="display: block!important; visibility: visible!important; opacity: 1!important; color: #fff!important; font-weight: 500!important; font-size: 1rem!important; margin-bottom: 0.5rem!important;">Tags</label>
                            <select class="form-select" id="tags_select" multiple size="4"                                   style="background-color: rgba(255,255,255,0.15)!important; border-color: rgba(138,180,248,0.5)!important; color: #fff!important; font-weight: 500;">                                <?php foreach ($available_tags as $tag): ?>                                    <option value="<?php echo $tag["name"]; ?>" <?php echo in_array($tag["id"], $current_tag_ids) ? "selected" : ""; ?> style="color: <?php echo $tag["color"]; ?>;">● <?php echo htmlspecialchars($tag["name"]); ?></option>                                <?php endforeach; ?>                            </select>                            <input type="hidden" id="tags" name="tags" value="<?php echo htmlspecialchars(implode(', ', $current_tag_names)); ?>">                            <div class="form-text" style="color: #8ab4f8;">Hold Ctrl/Cmd to select multiple tags. Selected tags will be applied to the firewall.</div>
                        </div>

                        <div class="row mt-4">
                            <div class="col-12">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                                <a href="firewall_details.php?id=<?php echo $firewall_id; ?>" class="btn btn-secondary ms-2">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Agent Information (Read-only) -->
            <div class="card mt-4 bg-dark text-white">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0"><i class="fas fa-info-circle"></i> Agent Information</h6>
                </div>
                <div class="card-body bg-dark">
                    <div class="row">
                        <div class="col-md-3">
                            <strong>Status:</strong>
                            <?php
                            $status = $firewall['agent_status'] ?? 'unknown';
                            $status_class = $status === 'online' ? 'text-success' : ($status === 'offline' ? 'text-danger' : 'text-warning');
                            ?>
                            <span class="<?php echo $status_class; ?> ms-2">
                                <i class="fas fa-circle"></i> <?php echo ucfirst($status); ?>
                            </span>
                        </div>
                        <div class="col-md-3">
                            <strong>Primary Agent:</strong>
                            <span class="ms-2"><?php echo htmlspecialchars($firewall['agent_version'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="col-md-3">
                            <strong>Update Agent:</strong>
                            <span class="ms-2"><?php echo htmlspecialchars($firewall['update_agent_version'] ?? 'Not Deployed'); ?></span>
                        </div>
                        <div class="col-md-3">
                            <strong>OPNsense Version:</strong>
                            <span class="ms-2"><?php 
                                // Parse JSON version data
                                $version_data = json_decode($firewall['opnsense_version'] ?? '{}', true);
                                if ($version_data && isset($version_data['product_version'])) {
                                    echo htmlspecialchars($version_data['product_version']);
                                } else {
                                    echo htmlspecialchars($firewall['opnsense_version'] ?? 'N/A');
                                }
                            ?></span>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-4">
                            <strong>WAN IPv4:</strong>
                            <span class="ms-2"><?php echo htmlspecialchars($firewall['wan_ip'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="col-md-4">
                            <strong>WAN IPv6:</strong>
                            <span class="ms-2"><?php echo htmlspecialchars($firewall['ipv6_address'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="col-md-4">
                            <strong>Last Check-in:</strong>
                            <span class="ms-2"><?php echo $firewall['last_checkin'] ? date('Y-m-d H:i:s', strtotime($firewall['last_checkin'])) : 'Never'; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>    // Sync tags multi-select with hidden field for form submission    document.getElementById("tags_select").addEventListener("change", function() {        var selected = Array.from(this.selectedOptions).map(opt => opt.value);        document.getElementById("tags").value = selected.join(", ");    });</script>
<?php include __DIR__ . '/inc/footer.php'; ?>
