<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/customers.php';
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
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $site_id     = (int)($_POST['site_id'] ?? 0);
    $tags_array = $_POST['tags'] ?? [];
    $tags_list = is_array($tags_array) ? array_filter(array_map('trim', $tags_array)) : array_filter(array_map('trim', explode(',', $tags_array)));

    if (empty($hostname)) {
        $error = 'Firewall hostname is required';
    } else {
        try {
            // Update firewall basic info (no tag_names column exists)
            $stmt = db()->prepare("
                UPDATE firewalls
                SET hostname = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$hostname, $notes, $firewall_id]);

            // Customer/site assignment goes through the service layer, which
            // writes customer_id/site_id, keeps the legacy customer_name and
            // customer_group strings in step for the pages that still read
            // them, and rejects a site belonging to a different customer.
            $assign = save_firewall_assignment(
                $firewall_id,
                $customer_id ?: null,
                $site_id ?: null
            );
            if (!$assign['ok']) {
                $error = $assign['error'];
                throw new RuntimeException($assign['error']);
            }

            // Handle tags - clear existing and insert checked ones
            $stmt = db()->prepare("DELETE FROM firewall_tags WHERE firewall_id = ?");
            $stmt->execute([$firewall_id]);

            foreach ($tags_list as $tag_name) {
                $stmt = db()->prepare("SELECT id FROM tags WHERE name = ?");
                $stmt->execute([$tag_name]);
                $tag_id = $stmt->fetchColumn();

                if ($tag_id) {
                    $stmt = db()->prepare("INSERT IGNORE INTO firewall_tags (firewall_id, tag_id) VALUES (?, ?)");
                    $stmt->execute([$firewall_id, $tag_id]);
                }
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
            <div class="card">
                <div class="card-header bg-primary">
                    <h5 class="mb-0"><i class="fas fa-edit"></i> Edit Firewall</h5>
                </div>
                <div class="card-body">
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
                                <?php
                                $fw_customer_id = (int)($firewall['customer_id'] ?? 0);
                                $fw_site_id     = (int)($firewall['site_id'] ?? 0);
                                $all_sites      = site_list();
                                $sel_style = 'background-color: rgba(255,255,255,0.15)!important; border-color: rgba(138,180,248,0.5)!important; color: #fff!important; font-weight: 500;';
                                $lbl_style = 'display: block!important; visibility: visible!important; opacity: 1!important; color: #fff!important; font-weight: 500!important; font-size: 1rem!important; margin-bottom: 0.5rem!important;';
                                ?>
                                <div class="mb-3">
                                    <label for="customer_id" class="form-label" style="<?php echo $lbl_style; ?>">Customer</label>
                                    <select class="form-select" id="customer_id" name="customer_id" style="<?php echo $sel_style; ?>">
                                        <option value="">-- Unassigned --</option>
                                        <?php foreach (customer_list() as $customer): ?>
                                            <option value="<?php echo (int)$customer['id']; ?>"
                                                <?php echo $fw_customer_id === (int)$customer['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($customer['name']); ?>
                                                <?php echo $customer['is_active'] ? '' : ' (inactive)'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="site_id" class="form-label" style="<?php echo $lbl_style; ?>">Site <span class="text-muted" style="font-weight:400">(optional)</span></label>
                                    <select class="form-select" id="site_id" name="site_id" style="<?php echo $sel_style; ?>">
                                        <option value="">-- None --</option>
                                        <?php foreach ($all_sites as $st): ?>
                                            <option value="<?php echo (int)$st['id']; ?>"
                                                    data-customer="<?php echo (int)$st['customer_id']; ?>"
                                                <?php echo $fw_site_id === (int)$st['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($st['customer_name'] . ' / ' . $st['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text" style="color:#9aa0a6">Sites are managed on the Customers page.</div>
                                </div>
                                <script>
                                // Only offer sites belonging to the selected customer, so an
                                // impossible pairing cannot be submitted in the first place.
                                (function () {
                                  var cust = document.getElementById('customer_id');
                                  var site = document.getElementById('site_id');
                                  if (!cust || !site) return;
                                  function sync() {
                                    var id = cust.value;
                                    Array.prototype.forEach.call(site.options, function (o) {
                                      if (!o.value) return;
                                      var match = !id || o.dataset.customer === id;
                                      o.hidden = !match;
                                      if (!match && o.selected) { site.value = ''; }
                                    });
                                  }
                                  cust.addEventListener('change', sync);
                                  sync();
                                })();
                                </script>
                        </div>                        <div class="mb-3">
                            <label for="notes" class="form-label" style="display: block!important; visibility: visible!important; opacity: 1!important; color: #fff!important; font-weight: 500!important; font-size: 1rem!important; margin-bottom: 0.5rem!important;">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"
                                      style="background-color: rgba(255,255,255,0.15)!important; border-color: rgba(138,180,248,0.5)!important; color: #fff!important; font-weight: 500;"><?php echo htmlspecialchars($firewall['notes'] ?? ''); ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" style="display: block!important; visibility: visible!important; opacity: 1!important; color: #fff!important; font-weight: 500!important; font-size: 1rem!important; margin-bottom: 0.5rem!important;">Tags</label>
                            <div class="border rounded p-2" style="border-color: rgba(138,180,248,0.5)!important; max-height: 150px; overflow-y: auto;">
                                <?php foreach ($available_tags as $tag): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="tags[]"
                                           value="<?php echo htmlspecialchars($tag['name']); ?>"
                                           id="tag_<?php echo $tag['id']; ?>"
                                           <?php echo in_array($tag['id'], $current_tag_ids) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="tag_<?php echo $tag['id']; ?>">
                                        <span class="badge me-1" style="background-color: <?php echo htmlspecialchars($tag['color']); ?>;">&nbsp;</span>
                                        <?php echo htmlspecialchars($tag['name']); ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text" style="color: #8ab4f8;">Check tags to assign to this firewall.</div>
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
            <div class="card mt-4">
                <div class="card-header bg-info">
                    <h6 class="mb-0"><i class="fas fa-info-circle"></i> Agent Information</h6>
                </div>
                <div class="card-body">
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

<?php include __DIR__ . '/inc/footer.php'; ?>
