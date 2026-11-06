<?php
/**
 * Device capabilities.
 *
 * What OPNMGR knows about one firewall's platform, how it knows it, and what
 * it will and will not do to that firewall as a result. Every state shown here
 * is the same answer the command gate gives (platform_capabilities()), so the
 * page cannot claim something the dispatcher would refuse.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/platform/platform.php';
require_once __DIR__ . '/inc/scope.php';

require_permission('platform.view');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$fw = $id > 0 ? platform_firewall($id) : null;
if (!$fw || !scope_can_firewall($id)) {
    http_response_code(404);
    include __DIR__ . '/inc/header.php';
    echo '<div class="container-fluid"><div class="alert alert-warning">Firewall not found.</div></div>';
    include __DIR__ . '/inc/footer.php';
    exit;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = '<div class="alert alert-danger">Invalid CSRF token.</div>';
    } elseif (isset($_POST['record_validation'])) {
        if (!can('platform.validate')) {
            $message = '<div class="alert alert-danger">Only administrators can record a lab validation.</div>';
        } else {
            $cap = (string)($_POST['capability'] ?? '');
            $prefix = trim((string)($_POST['version_prefix'] ?? ''));
            $evidence = trim((string)($_POST['evidence'] ?? ''));
            if (!isset(platform_capability_catalog()[$cap]) || $prefix === '' || strlen($evidence) < 20
                || empty($fw['platform_family'])) {
                $message = '<div class="alert alert-warning">Choose a capability and version prefix, and describe what was run and the result (20+ characters). The platform must be verified first.</div>';
            } else {
                db()->prepare(
                    'INSERT INTO platform_validations
                        (platform_family, platform_edition, version_prefix, capability, agent_version, validated_at, evidence, recorded_by)
                     VALUES (?,?,?,?,?,NOW(),?,?)
                     ON DUPLICATE KEY UPDATE agent_version = VALUES(agent_version), validated_at = NOW(),
                                             evidence = VALUES(evidence), recorded_by = VALUES(recorded_by)'
                )->execute([
                    $fw['platform_family'], (string)($fw['platform_edition'] ?? ''), substr($prefix, 0, 32), $cap,
                    substr((string)$fw['agent_version'], 0, 32),
                    substr(sprintf('%s (device %s, agent %s)', $evidence, $fw['hostname'], $fw['agent_version']), 0, 512),
                    $_SESSION['username'] ?? null,
                ]);
                audit_log('platform.validation.recorded', [
                    'object_type' => 'firewall', 'object_id' => (string)$id, 'firewall_id' => $id,
                    'message' => sprintf('Lab validation recorded: %s on %s %s', $cap, platform_summary($fw), $prefix),
                    'metadata' => ['capability' => $cap, 'version_prefix' => $prefix, 'evidence' => $evidence],
                ]);
                platform_validations(true);
                $message = '<div class="alert alert-success">Validation recorded.</div>';
            }
        }
    } elseif (isset($_POST['toggle_lab'])) {
        if (!can('settings.manage')) {
            $message = '<div class="alert alert-danger">Only administrators can designate lab devices.</div>';
        } else {
            $ids = platform_lab_firewall_ids();
            $ids = in_array($id, $ids, true) ? array_values(array_diff($ids, [$id])) : array_merge($ids, [$id]);
            db()->prepare("INSERT INTO settings (`name`,`value`) VALUES ('policy_apply_lab_firewall_ids', ?)
                           ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute([implode(',', $ids)]);
            audit_log('platform.lab_designation', [
                'object_type' => 'firewall', 'object_id' => (string)$id, 'firewall_id' => $id,
                'message' => (in_array($id, $ids, true) ? 'Designated as' : 'Removed as') . ' a lab device: ' . $fw['hostname'],
            ]);
            $message = '<div class="alert alert-success">Lab designation updated.</div>';
        }
    }
    $fw = platform_firewall($id);
}

$caps    = platform_capabilities($fw);
$adapter = platform_adapter($fw);
$isLab   = in_array($id, platform_lab_firewall_ids(), true);
$probe   = json_decode((string)($fw['platform_probe'] ?? ''), true) ?: [];
$pkgs    = json_decode((string)($fw['platform_packages'] ?? ''), true) ?: [];

function cap_badge(string $state): string {
    $cls = match ($state) {
        'supported'   => 'bg-success',
        'untested'    => 'bg-warning text-dark',
        'stale'       => 'bg-warning text-dark',
        'unavailable' => 'bg-danger',
        default       => 'bg-secondary',
    };
    return '<span class="badge ' . $cls . '">' . htmlspecialchars($state) . '</span>';
}

$versionPrefix = preg_match('/^(\d+\.\d+)/', (string)$fw['platform_os_version'], $m) ? $m[1] : '';

include __DIR__ . '/inc/header.php';
?>
<div class="container-fluid">
    <div class="mb-3">
        <h4 class="mb-0"><i class="fas fa-puzzle-piece me-2"></i>Capabilities: <?php echo htmlspecialchars($fw['hostname']); ?></h4>
        <small class="text-muted">
            What OPNManager will do to this firewall, and why. Unknown platforms are read-only; implemented
            but never live-tested operations are "untested" and run only on designated lab devices.
            <a href="/platform_compatibility.php">Fleet compatibility</a>
        </small>
    </div>
    <?php echo $message; ?>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-uppercase text-muted small">Platform</h6>
                <table class="table table-sm mb-0">
                    <tr><th>Product</th><td><?php echo htmlspecialchars(platform_summary($fw)); ?></td></tr>
                    <tr><th>Edition</th><td><?php echo htmlspecialchars($fw['platform_edition'] ?? ($adapter->family() === 'opnsense' ? 'n/a' : 'unknown')); ?></td></tr>
                    <tr><th>FreeBSD</th><td><?php echo htmlspecialchars($fw['platform_base_version'] ?? '—'); ?>
                        <span class="text-muted small">(recorded, never used to identify the product)</span></td></tr>
                    <tr><th>Config schema</th><td><?php echo htmlspecialchars($fw['platform_config_version'] ?? '—'); ?></td></tr>
                    <tr><th>Agent</th><td><?php echo htmlspecialchars(($fw['agent_flavor'] ?? 'unknown') . ' ' . ($fw['agent_version'] ?? '')); ?></td></tr>
                    <tr><th>Detection</th><td><?php echo htmlspecialchars(($fw['platform_detection_source'] ?? 'none')
                        . ' (' . ($fw['platform_detection_confidence'] ?? 'low') . ')'); ?></td></tr>
                    <tr><th>Last verified</th><td><?php echo htmlspecialchars($fw['platform_verified_at'] ?? 'never'); ?></td></tr>
                    <?php if (!empty($fw['platform_detection_conflict'])): ?>
                    <tr><th>Conflict</th><td class="text-danger"><?php echo htmlspecialchars($fw['platform_detection_conflict']); ?></td></tr>
                    <?php endif; ?>
                    <tr><th>Lab device</th><td><?php echo $isLab ? '<span class="badge bg-info text-dark">yes</span> untested writes allowed' : 'no'; ?></td></tr>
                </table>
                <?php if (can('settings.manage')): ?>
                <form method="post" class="mt-2">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <button class="btn btn-sm btn-outline-secondary" name="toggle_lab" value="1"
                        onclick="return confirm('<?php echo $isLab ? 'Remove the lab designation?' : 'Designate this firewall as a lab device? Untested write operations (including template application) will be allowed on it.'; ?>')">
                        <?php echo $isLab ? 'Remove lab designation' : 'Designate as lab device'; ?></button>
                </form>
                <?php endif; ?>
            </div></div>
        </div>
        <div class="col-lg-7">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-uppercase text-muted small">Agent probe</h6>
                <?php if (!$probe): ?>
                    <p class="text-muted small mb-0">No probe received. OPNsense agents before 1.8.0 do not send one;
                    capabilities that depend on a probed mechanism show as unavailable until they do.</p>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-1 mb-2">
                    <?php foreach ($probe as $k => $v): ?>
                        <span class="badge <?php echo $v ? 'bg-success' : 'bg-secondary'; ?>"><?php echo htmlspecialchars($k); ?></span>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($pkgs): ?>
                    <h6 class="text-uppercase text-muted small mt-2">Relevant packages</h6>
                    <div class="small text-muted"><?php echo htmlspecialchars(implode(', ', $pkgs)); ?></div>
                <?php endif; ?>
            </div></div>
        </div>
    </div>

    <div class="card mt-3"><div class="card-body">
        <h6 class="text-uppercase text-muted small">Capabilities</h6>
        <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>Capability</th><th>Kind</th><th>State</th><th>Why</th><th>Evidence</th></tr></thead>
            <tbody>
            <?php foreach ($caps as $key => $c): ?>
                <tr>
                    <td><?php echo htmlspecialchars($c['label']); ?><div class="text-muted small"><code><?php echo htmlspecialchars($key); ?></code></div></td>
                    <td><?php echo $c['class'] === 'write' ? '<span class="badge bg-dark">write</span>' : '<span class="badge bg-light text-dark">read</span>'; ?></td>
                    <td><?php echo cap_badge($c['state']); ?></td>
                    <td class="small"><?php echo htmlspecialchars($c['reason']); ?></td>
                    <td class="small text-muted"><?php echo htmlspecialchars(($c['evidence'] ?? '') . ($c['validated_at'] ? ' — ' . $c['validated_at'] : '')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div></div>

    <?php if (can('platform.validate') && !empty($fw['platform_family'])): ?>
    <div class="card mt-3"><div class="card-body">
        <h6 class="text-uppercase text-muted small">Record a lab validation</h6>
        <p class="small text-muted">Only after actually exercising the operation on this device and checking the result
            on the firewall itself. A validation applies to <?php echo htmlspecialchars($adapter->label($fw['platform_edition'] ?? null)); ?>
            versions starting with the prefix below.</p>
        <form method="post" class="row g-2">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <div class="col-md-3">
                <select name="capability" class="form-select form-select-sm">
                <?php foreach ($caps as $key => $c): if ($c['state'] !== 'untested') continue; ?>
                    <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($c['label']); ?></option>
                <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><input name="version_prefix" class="form-control form-control-sm" value="<?php echo htmlspecialchars($versionPrefix); ?>" placeholder="e.g. 2.7"></div>
            <div class="col-md-5"><input name="evidence" class="form-control form-control-sm" placeholder="What was run, and what was observed on the firewall"></div>
            <div class="col-md-2"><button class="btn btn-sm btn-primary w-100" name="record_validation" value="1">Record</button></div>
        </form>
    </div></div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
