<?php
require_once __DIR__ . '/inc/auth.php';
requireLogin();
requireAdmin();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/csrf.php';

$notice = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $notice = 'Bad CSRF';
    } else {
        // Add country block
        if (!empty($_POST['add_block'])) {
            $country_code = strtoupper(trim($_POST['country_code'] ?? ''));
            $country_name = trim($_POST['country_name'] ?? '');
            $action = $_POST['action'] ?? 'block';
            $description = trim($_POST['description'] ?? '');

            if (strlen($country_code) === 2 && !empty($country_name)) {
                try {
                    $stmt = $DB->prepare('INSERT INTO geoip_blocks (country_code, country_name, action, description, enabled) VALUES (?, ?, ?, ?, 1)');
                    $stmt->execute([$country_code, $country_name, $action, $description]);
                    $notice = 'Country block added successfully.';
                } catch (PDOException $e) {
                    if ($e->getCode() == 23000) {
                        $notice = 'This country is already in the list.';
                    } else {
                        error_log("geoip_blocking.php error: " . $e->getMessage());
                        $notice = 'An internal error occurred.';
                    }
                }
            } else {
                $notice = 'Invalid country code or name.';
            }
        }

        // Toggle enabled status
        if (!empty($_POST['toggle_block'])) {
            $id = (int)$_POST['block_id'];
            $stmt = $DB->prepare('UPDATE geoip_blocks SET enabled = NOT enabled WHERE id = ?');
            $stmt->execute([$id]);
            $notice = 'Block status updated.';
        }

        // Delete block
        if (!empty($_POST['delete_block'])) {
            $id = (int)$_POST['block_id'];
            $stmt = $DB->prepare('DELETE FROM geoip_blocks WHERE id = ?');
            $stmt->execute([$id]);
            $notice = 'Country block removed.';
        }
    }
}

// Load existing blocks
$blocks = $DB->query('SELECT * FROM geoip_blocks ORDER BY country_name ASC')->fetchAll(PDO::FETCH_ASSOC);

// Common countries list
$common_countries = [
    'CN' => 'China',
    'RU' => 'Russia',
    'KP' => 'North Korea',
    'IR' => 'Iran',
    'IN' => 'India',
    'BR' => 'Brazil',
    'VN' => 'Vietnam',
    'BD' => 'Bangladesh',
    'PK' => 'Pakistan',
    'UA' => 'Ukraine',
    'TR' => 'Turkey',
    'ID' => 'Indonesia',
    'TH' => 'Thailand',
    'PH' => 'Philippines',
    'RO' => 'Romania',
    'BG' => 'Bulgaria',
    'MX' => 'Mexico',
    'CO' => 'Colombia',
    'AR' => 'Argentina',
    'ZA' => 'South Africa',
    'EG' => 'Egypt',
    'NG' => 'Nigeria',
    'KE' => 'Kenya',
    'MA' => 'Morocco',
    'US' => 'United States',
    'CA' => 'Canada',
    'GB' => 'United Kingdom',
    'DE' => 'Germany',
    'FR' => 'France',
    'IT' => 'Italy',
    'ES' => 'Spain',
    'NL' => 'Netherlands',
    'AU' => 'Australia',
    'JP' => 'Japan',
    'KR' => 'South Korea',
    'SG' => 'Singapore',
];

include __DIR__ . '/inc/header.php';
?>

<style>
.card-dark {
  background-color: #1a1a1a;
  border: 1px solid #333;
}

.badge-enabled {
  background-color: #28a745;
}

.badge-disabled {
  background-color: #6c757d;
}

.country-flag {
  font-size: 1.5rem;
  margin-right: 0.5rem;
}

.quick-add-btn {
  margin: 2px;
  font-size: 0.8rem;
  padding: 0.25rem 0.5rem;
}
</style>

<div class="container-fluid">
    <div class="row mb-3">
        <div class="col-md-12">
            <h4><i class="fas fa-globe me-2"></i>GeoIP Blocking for OPNManager</h4>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Important:</strong> This GeoIP blocking is for <strong>OPNManager access only</strong>, NOT for your firewall clients.
                This controls which countries can access this management panel.
            </div>
        </div>
    </div>

    <?php if ($notice): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($notice); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Add Country Block Form -->
        <div class="col-md-4">
            <div class="card card-dark">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-plus-circle me-2"></i>Add Country Block</h5>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">

                        <div class="mb-3">
                            <label for="country_select" class="form-label">Quick Select</label>
                            <select id="country_select" class="form-select bg-dark text-light border-secondary" onchange="selectCountry(this.value)">
                                <option value="">-- Select a country --</option>
                                <?php foreach ($common_countries as $code => $name): ?>
                                    <option value="<?php echo $code . '|' . $name; ?>"><?php echo htmlspecialchars($name); ?> (<?php echo $code; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="country_code" class="form-label">Country Code (ISO 3166-1 alpha-2)</label>
                            <input type="text" class="form-control bg-dark text-light border-secondary" id="country_code" name="country_code"
                                   placeholder="e.g., CN" maxlength="2" required style="text-transform: uppercase;">
                            <small class="form-text text-muted">2-letter country code (e.g., CN for China, RU for Russia)</small>
                        </div>

                        <div class="mb-3">
                            <label for="country_name" class="form-label">Country Name</label>
                            <input type="text" class="form-control bg-dark text-light border-secondary" id="country_name" name="country_name"
                                   placeholder="e.g., China" required>
                        </div>

                        <div class="mb-3">
                            <label for="action" class="form-label">Action</label>
                            <select class="form-select bg-dark text-light border-secondary" id="action" name="action">
                                <option value="block">Block (Deny traffic)</option>
                                <option value="allow">Allow (Whitelist)</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description (Optional)</label>
                            <textarea class="form-control bg-dark text-light border-secondary" id="description" name="description"
                                      rows="2" placeholder="Reason for this block..."></textarea>
                        </div>

                        <button type="submit" name="add_block" class="btn btn-danger w-100">
                            <i class="fas fa-ban me-2"></i>Add Block
                        </button>
                    </form>

                    <hr class="my-3">

                    <div>
                        <h6 class="mb-2">Quick Add Common Blocks</h6>
                        <div class="d-flex flex-wrap">
                            <?php
                            $quick_blocks = ['CN' => 'China', 'RU' => 'Russia', 'KP' => 'N. Korea', 'IR' => 'Iran', 'IN' => 'India', 'VN' => 'Vietnam'];
                            foreach ($quick_blocks as $code => $name):
                            ?>
                                <button type="button" class="btn btn-sm btn-outline-danger quick-add-btn"
                                        onclick="quickAdd('<?php echo $code; ?>', '<?php echo $name; ?>')">
                                    <?php echo $name; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Current Blocks List -->
        <div class="col-md-8">
            <div class="card card-dark">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-list me-2"></i>Active Country Blocks (<?php echo count($blocks); ?>)</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($blocks)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>No country blocks configured. Add countries to block using the form.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-dark table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Country</th>
                                        <th>Code</th>
                                        <th>Action</th>
                                        <th>Status</th>
                                        <th>Description</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($blocks as $block): ?>
                                        <tr class="<?php echo $block['enabled'] ? '' : 'table-secondary'; ?>">
                                            <td>
                                                <strong><?php echo htmlspecialchars($block['country_name']); ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($block['country_code']); ?></span>
                                            </td>
                                            <td>
                                                <?php if ($block['action'] === 'block'): ?>
                                                    <span class="badge bg-danger"><i class="fas fa-ban me-1"></i>Block</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success"><i class="fas fa-check me-1"></i>Allow</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($block['enabled']): ?>
                                                    <span class="badge badge-enabled"><i class="fas fa-check-circle me-1"></i>Enabled</span>
                                                <?php else: ?>
                                                    <span class="badge badge-disabled"><i class="fas fa-pause-circle me-1"></i>Disabled</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?php echo htmlspecialchars($block['description'] ?: '-'); ?></small>
                                            </td>
                                            <td>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                                                    <input type="hidden" name="block_id" value="<?php echo $block['id']; ?>">
                                                    <button type="submit" name="toggle_block" class="btn btn-sm btn-warning"
                                                            title="<?php echo $block['enabled'] ? 'Disable' : 'Enable'; ?>">
                                                        <i class="fas fa-<?php echo $block['enabled'] ? 'pause' : 'play'; ?>"></i>
                                                    </button>
                                                </form>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this country block?');">
                                                    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                                                    <input type="hidden" name="block_id" value="<?php echo $block['id']; ?>">
                                                    <button type="submit" name="delete_block" class="btn btn-sm btn-danger" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Information Card -->
            <div class="card card-dark mt-3">
                <div class="card-body">
                    <h6><i class="fas fa-info-circle me-2"></i>How GeoIP Blocking Works for OPNManager</h6>
                    <ul class="mb-0" style="color: #e0e0e0;">
                        <li><strong>OPNManager Access Only:</strong> These rules control access to THIS management panel, NOT your firewall clients</li>
                        <li><strong>Geolocation:</strong> Uses IP address geolocation databases to identify traffic origin</li>
                        <li><strong>Enforcement:</strong> Blocks are enforced on the OPNManager server using iptables/nftables</li>
                        <li><strong>Block Action:</strong> Denies access to OPNManager from specified countries</li>
                        <li><strong>Allow Action:</strong> Whitelists a country (overrides global blocks)</li>
                        <li><strong>Firewall GeoIP:</strong> To block countries on your OPNsense firewalls, use the firewall's own GeoIP settings</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function selectCountry(value) {
    if (value) {
        const [code, name] = value.split('|');
        document.getElementById('country_code').value = code;
        document.getElementById('country_name').value = name;
    }
}

function quickAdd(code, name) {
    document.getElementById('country_code').value = code;
    document.getElementById('country_name').value = name;
    document.getElementById('action').value = 'block';
    document.getElementById('description').value = 'Common threat source - automatically added';

    // Scroll to form
    document.getElementById('country_code').focus();
}
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
