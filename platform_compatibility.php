<?php
/**
 * Fleet compatibility.
 *
 * Two views of the same rules:
 *   - the fleet: every firewall's platform and the state of each capability
 *   - the matrix: what OPNMGR implements per platform, independent of devices
 *
 * Unsupported, unavailable, stale and untested are shown as themselves. A
 * blank or green cell is never used for "we don't know".
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/platform/platform.php';
require_once __DIR__ . '/inc/scope.php';

require_permission('platform.view');

$filterPlatform = (string)($_GET['platform'] ?? '');
[$scopeWhere, $scopeParams] = scope_sql('f.customer_id');
$stmt = db()->prepare(
    "SELECT f.*, c.name AS customer_name FROM firewalls f
       LEFT JOIN customers c ON c.id = f.customer_id
      WHERE $scopeWhere ORDER BY c.name, f.hostname"
);
$stmt->execute($scopeParams);
$firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);

$catalog = platform_capability_catalog();
$fleetRows = [];
$counts = [];
foreach ($firewalls as $fw) {
    $family = platform_adapter($fw)->family();
    $key = $family === 'pfsense' ? 'pfsense-' . ($fw['platform_edition'] ?: 'unknown') : $family;
    $counts[$key] = ($counts[$key] ?? 0) + 1;
    if ($filterPlatform !== '' && $filterPlatform !== $key) {
        continue;
    }
    $fleetRows[] = ['fw' => $fw, 'caps' => platform_capabilities($fw), 'key' => $key];
}

function state_cell(string $state, string $reason): string {
    $map = [
        'supported'   => ['bg-success', '✓'],
        'untested'    => ['bg-warning text-dark', 'U'],
        'stale'       => ['bg-warning text-dark', 'S'],
        'unavailable' => ['bg-danger', '!'],
        'unsupported' => ['bg-secondary', '—'],
    ];
    [$cls, $glyph] = $map[$state] ?? ['bg-secondary', '?'];
    return '<span class="badge ' . $cls . '" title="' . htmlspecialchars($state . ': ' . $reason) . '">' . $glyph . '</span>';
}

// Static implementation matrix, from the adapters themselves.
$matrixPlatforms = [
    'OPNsense'     => ['platform_family' => 'opnsense', 'platform_os_version' => '', 'id' => 0],
    'pfSense CE'   => ['platform_family' => 'pfsense', 'platform_edition' => 'community', 'platform_os_version' => '', 'id' => 0],
    'pfSense Plus' => ['platform_family' => 'pfsense', 'platform_edition' => 'plus', 'platform_os_version' => '', 'id' => 0],
    'Unknown'      => ['platform_family' => null, 'id' => 0],
];

include __DIR__ . '/inc/header.php';
?>
<div class="container-fluid">
    <div class="mb-3">
        <h4 class="mb-0"><i class="fas fa-puzzle-piece me-2"></i>Fleet compatibility</h4>
        <small class="text-muted">
            ✓ supported &nbsp; U untested (implemented, not yet live-tested on that platform/version — lab devices only for writes)
            &nbsp; S stale verification &nbsp; ! unavailable (dependency or probe missing) &nbsp; — unsupported.
            Hover a cell for the reason.
        </small>
    </div>

    <div class="mb-3 d-flex flex-wrap gap-2">
        <a class="btn btn-sm <?php echo $filterPlatform === '' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?">All (<?php echo count($firewalls); ?>)</a>
        <?php foreach ($counts as $k => $n): ?>
            <a class="btn btn-sm <?php echo $filterPlatform === $k ? 'btn-primary' : 'btn-outline-secondary'; ?>"
               href="?platform=<?php echo urlencode($k); ?>"><?php echo htmlspecialchars($k); ?> (<?php echo (int)$n; ?>)</a>
        <?php endforeach; ?>
    </div>

    <div class="card mb-3"><div class="card-body">
        <h6 class="text-uppercase text-muted small">Fleet</h6>
        <div class="table-responsive">
        <table class="table table-sm align-middle small">
            <thead><tr>
                <th>Firewall</th><th>Customer</th><th>Platform</th><th>Verified</th>
                <?php foreach ($catalog as $k => $meta): ?>
                    <th class="text-center" title="<?php echo htmlspecialchars($meta['label']); ?>" style="writing-mode:vertical-rl;transform:rotate(180deg);white-space:nowrap">
                        <?php echo htmlspecialchars($meta['label']); ?></th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php if (!$fleetRows): ?>
                <tr><td colspan="<?php echo 4 + count($catalog); ?>" class="text-muted">No firewalls.</td></tr>
            <?php endif; ?>
            <?php foreach ($fleetRows as $r): $fw = $r['fw']; ?>
                <tr>
                    <td><a href="/firewall_capabilities.php?id=<?php echo (int)$fw['id']; ?>"><?php echo htmlspecialchars($fw['hostname']); ?></a></td>
                    <td><?php echo htmlspecialchars($fw['customer_name'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars(platform_summary($fw)); ?>
                        <span class="text-muted">(<?php echo htmlspecialchars($fw['platform_detection_confidence'] ?? 'low'); ?>)</span></td>
                    <td><?php echo htmlspecialchars($fw['platform_verified_at'] ?? 'never'); ?></td>
                    <?php foreach ($catalog as $k => $meta): ?>
                        <td class="text-center"><?php echo state_cell($r['caps'][$k]['state'], $r['caps'][$k]['reason']); ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div></div>

    <div class="card"><div class="card-body">
        <h6 class="text-uppercase text-muted small">Implementation matrix (independent of any device)</h6>
        <p class="small text-muted">Supported here means implemented and validated; on a device it can still be
            unavailable (missing probe/dependency) or stale. pfSense columns show what is implemented — none of it has
            been exercised on a live pfSense device by this project unless a lab validation is recorded.</p>
        <div class="table-responsive">
        <table class="table table-sm small">
            <thead><tr><th>Capability</th><?php foreach ($matrixPlatforms as $label => $_): ?><th><?php echo $label; ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($catalog as $cap => $meta): ?>
                <tr>
                    <td><?php echo htmlspecialchars($meta['label']); ?> <span class="text-muted">(<?php echo $meta['class']; ?>)</span></td>
                    <?php foreach ($matrixPlatforms as $label => $fake):
                        $a = platform_adapter($fake);
                        if ($a->family() === 'unknown') {
                            $readOk = in_array($cap, ['telemetry.checkin', 'config.backup', 'diagnostics.network'], true);
                            echo '<td>' . ($readOk ? 'read-only' : '<span class="text-muted">blocked until verified</span>') . '</td>';
                            continue;
                        }
                        $impl = $a->capabilityImplementation($cap, $fake);
                        if (!$impl['implemented']) {
                            echo '<td><span class="badge bg-secondary" title="' . htmlspecialchars($impl['reason']) . '">not implemented</span></td>';
                        } elseif ($a->family() === 'opnsense' && in_array($cap, platform_opnsense_baseline(), true)) {
                            echo '<td><span class="badge bg-success">supported</span></td>';
                        } else {
                            $needs = isset($impl['requires_probe']) ? ' (needs probe: ' . $impl['requires_probe'] . ')' : '';
                            echo '<td><span class="badge bg-warning text-dark" title="' . htmlspecialchars($impl['reason']) . '">implemented, untested</span>'
                               . '<span class="text-muted">' . htmlspecialchars($needs) . '</span></td>';
                        }
                    endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div></div>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
