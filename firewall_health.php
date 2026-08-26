<?php
/**
 * Firewall Health.
 *
 * OPNsense-specific health across the fleet: gateways, VPN tunnels, CARP/HA,
 * services and certificate expiry.
 *
 * Only what an agent actually reported is shown. A firewall running an agent
 * older than 1.6.0 reports no health sections and appears as "not reporting"
 * rather than as a wall of false failures.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/firewall_health.php';

require_permission('health.view');

$summary    = health_fleet_summary();
$thresholds = health_thresholds();
$flapping   = health_gateway_flapping();
$expiring   = health_expiring_certificates();
$focus      = (int)($_GET['firewall'] ?? 0);

// Fleet list with a per-firewall health roll-up.
$fleet = [];
try {
    $fleet = db()->query("
        SELECT f.id, f.hostname, f.status, f.agent_version, f.last_checkin,
               f.carp_enabled, f.carp_state, f.carp_sync_status,
               c.name AS customer_name, s.name AS site_name,
               (SELECT COUNT(*) FROM firewall_gateways g
                 WHERE g.firewall_id = f.id AND LOWER(g.status) IN ('down','force_down')) AS gw_down,
               (SELECT COUNT(*) FROM firewall_gateways g WHERE g.firewall_id = f.id) AS gw_total,
               (SELECT COUNT(*) FROM firewall_vpn_tunnels v
                 WHERE v.firewall_id = f.id AND v.enabled = 1
                   AND LOWER(v.status) NOT IN ('up','connected')) AS vpn_down,
               (SELECT COUNT(*) FROM firewall_vpn_tunnels v WHERE v.firewall_id = f.id) AS vpn_total,
               (SELECT COUNT(*) FROM firewall_services sv
                 WHERE sv.firewall_id = f.id AND sv.enabled = 1 AND sv.running = 0) AS svc_stopped,
               (SELECT COUNT(*) FROM firewall_services sv WHERE sv.firewall_id = f.id) AS svc_total,
               (SELECT MIN(ct.days_remaining) FROM firewall_certificates ct
                 WHERE ct.firewall_id = f.id AND ct.days_remaining IS NOT NULL) AS cert_min_days,
               (SELECT COUNT(*) FROM firewall_certificates ct WHERE ct.firewall_id = f.id) AS cert_total
          FROM firewalls f
          LEFT JOIN customers c ON c.id = f.customer_id
          LEFT JOIN sites s ON s.id = f.site_id
         ORDER BY f.hostname
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('firewall_health.php: ' . $e->getMessage());
}

$detail = null;
if ($focus > 0) {
    $stmt = db()->prepare('SELECT id, hostname, agent_version FROM firewalls WHERE id = ?');
    $stmt->execute([$focus]);
    $fw = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($fw) {
        $detail = ['firewall' => $fw, 'health' => health_for_firewall($focus)];
    }
}

/** Colour for a severity word. */
function hb(string $sev): string {
    return match ($sev) {
        'critical', 'expired' => 'danger',
        'high'                => 'danger',
        'warning', 'medium'   => 'warning text-dark',
        'ok'                  => 'success',
        default               => 'secondary',
    };
}

/** Bytes to a short human string. */
function h_bytes(?int $n): string {
    if ($n === null) return '—';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float)$n;
    while ($v >= 1024 && $i < count($units) - 1) { $v /= 1024; $i++; }
    return round($v, $i ? 1 : 0) . ' ' . $units[$i];
}

/** Relative age of a timestamp. */
function h_age(?string $ts): string {
    if (!$ts) return 'never';
    $d = time() - strtotime($ts);
    if ($d < 90)    return 'just now';
    if ($d < 3600)  return (int)($d / 60) . 'm ago';
    if ($d < 86400) return (int)($d / 3600) . 'h ago';
    return (int)($d / 86400) . 'd ago';
}

include __DIR__ . '/inc/header.php';
?>

<div class="container-fluid">
    <div class="mb-3">
        <h4 class="mb-0"><i class="fas fa-heart-pulse me-2"></i>Firewall Health</h4>
        <small class="text-muted">
            Gateways, VPN tunnels, CARP/HA, services and certificate expiry, as reported by the agent.
        </small>
    </div>

    <div class="row g-2 mb-3">
        <?php foreach ([
            ['Gateways down',     $summary['gateways_down'],    'danger',          'gateway'],
            ['Gateways degraded', $summary['gateways_warn'],    'warning',         'gateway'],
            ['VPN tunnels down',  $summary['vpn_down'],         'danger',          'vpn'],
            ['Services stopped',  $summary['services_stopped'], 'warning',         'service'],
            ['Certs expired',     $summary['certs_expired'],    'danger',          'cert'],
            ['Certs expiring',    $summary['certs_expiring'],   'warning',         'cert'],
            ['CARP not settled',  $summary['carp_init'],        'secondary',       'carp'],
        ] as [$label, $n, $colour, $_k]): ?>
        <div class="col-6 col-md-3 col-xl">
            <div class="card h-100"><div class="card-body py-2">
                <div class="text-muted small"><?php echo $label; ?></div>
                <div class="fs-4 text-<?php echo $n > 0 ? $colour : 'success'; ?>"><?php echo (int)$n; ?></div>
            </div></div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($flapping): ?>
    <div class="alert alert-warning">
        <strong><i class="fas fa-wave-square me-1"></i>Flapping gateways</strong>
        <div class="small mt-1">A gateway changing state repeatedly is often more urgent than one cleanly down.</div>
        <ul class="mb-0 small mt-2">
            <?php foreach ($flapping as $f): ?>
                <li>
                    <?php echo htmlspecialchars($f['hostname']); ?> &middot;
                    <?php echo htmlspecialchars($f['gateway_name']); ?> &mdash;
                    <?php echo (int)$f['transitions']; ?> transitions in 24h
                    (last <?php echo htmlspecialchars(h_age($f['last_change'])); ?>)
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($expiring): ?>
    <div class="card mb-3">
        <div class="card-header py-2">
            <strong class="small">Certificates expiring within <?php echo (int)$thresholds['cert_medium']; ?> days</strong>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr>
                    <th>Firewall</th><th>Customer</th><th>Certificate</th><th>Issuer</th>
                    <th>Expires</th><th>Days</th>
                </tr></thead>
                <tbody>
                <?php foreach ($expiring as $c):
                    $sev = health_cert_severity($c['days_remaining'] === null ? null : (int)$c['days_remaining']); ?>
                    <tr>
                        <td class="small"><?php echo htmlspecialchars($c['hostname']); ?></td>
                        <td class="small text-muted"><?php echo htmlspecialchars($c['customer_name'] ?: '—'); ?></td>
                        <td class="small"><?php echo htmlspecialchars($c['name'] ?: $c['refid']); ?></td>
                        <td class="small text-muted"><?php echo htmlspecialchars(substr((string)$c['issuer'], 0, 48)); ?></td>
                        <td class="small text-muted"><?php echo htmlspecialchars((string)$c['not_after']); ?></td>
                        <td><span class="badge bg-<?php echo hb($sev); ?>">
                            <?php echo (int)$c['days_remaining'] < 0
                                ? 'expired ' . abs((int)$c['days_remaining']) . 'd ago'
                                : (int)$c['days_remaining'] . 'd'; ?>
                        </span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header py-2"><strong class="small">Fleet</strong></div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr>
                    <th>Firewall</th><th>Customer / Site</th><th>Gateways</th><th>VPN</th>
                    <th>Services</th><th>Certificates</th><th>HA</th><th>Agent</th><th></th>
                </tr></thead>
                <tbody>
                <?php if (!$fleet): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No firewalls.</td></tr>
                <?php else: foreach ($fleet as $row):
                    $reports = ((int)$row['gw_total'] + (int)$row['vpn_total'] + (int)$row['svc_total'] + (int)$row['cert_total']) > 0;
                    $certSev = health_cert_severity($row['cert_min_days'] === null ? null : (int)$row['cert_min_days']);
                ?>
                    <tr>
                        <td>
                            <span class="badge bg-<?php echo $row['status'] === 'online' ? 'success' : 'danger'; ?> me-1">&nbsp;</span>
                            <a href="firewall_details.php?id=<?php echo (int)$row['id']; ?>">
                                <?php echo htmlspecialchars($row['hostname']); ?>
                            </a>
                        </td>
                        <td class="small text-muted">
                            <?php echo htmlspecialchars($row['customer_name'] ?: 'Unassigned'); ?>
                            <?php if ($row['site_name']): ?>/ <?php echo htmlspecialchars($row['site_name']); ?><?php endif; ?>
                        </td>
                        <?php if (!$reports): ?>
                            <td colspan="5" class="small text-muted">
                                Not reporting health &mdash; agent
                                <?php echo htmlspecialchars($row['agent_version'] ?: 'unknown'); ?>
                                (requires 1.6.0+)
                            </td>
                        <?php else: ?>
                            <td class="small">
                                <?php if ((int)$row['gw_down'] > 0): ?>
                                    <span class="badge bg-danger"><?php echo (int)$row['gw_down']; ?> down</span>
                                <?php elseif ((int)$row['gw_total'] > 0): ?>
                                    <span class="badge bg-success"><?php echo (int)$row['gw_total']; ?> up</span>
                                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                            </td>
                            <td class="small">
                                <?php if ((int)$row['vpn_down'] > 0): ?>
                                    <span class="badge bg-danger"><?php echo (int)$row['vpn_down']; ?> down</span>
                                <?php elseif ((int)$row['vpn_total'] > 0): ?>
                                    <span class="badge bg-success"><?php echo (int)$row['vpn_total']; ?> up</span>
                                <?php else: ?><span class="text-muted">none</span><?php endif; ?>
                            </td>
                            <td class="small">
                                <?php if ((int)$row['svc_stopped'] > 0): ?>
                                    <span class="badge bg-warning text-dark"><?php echo (int)$row['svc_stopped']; ?> stopped</span>
                                <?php elseif ((int)$row['svc_total'] > 0): ?>
                                    <span class="badge bg-success"><?php echo (int)$row['svc_total']; ?> ok</span>
                                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                            </td>
                            <td class="small">
                                <?php if ($row['cert_min_days'] === null): ?>
                                    <span class="text-muted">—</span>
                                <?php else: ?>
                                    <span class="badge bg-<?php echo hb($certSev); ?>">
                                        <?php echo (int)$row['cert_min_days'] < 0 ? 'expired' : (int)$row['cert_min_days'] . 'd'; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?php if ($row['carp_enabled']): ?>
                                    <span class="badge bg-<?php echo $row['carp_state'] === 'MASTER' ? 'primary'
                                        : ($row['carp_state'] === 'BACKUP' ? 'secondary' : 'warning text-dark'); ?>">
                                        <?php echo htmlspecialchars($row['carp_state'] ?: 'INIT'); ?>
                                    </span>
                                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td class="small text-muted"><?php echo htmlspecialchars($row['agent_version'] ?: '—'); ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="?firewall=<?php echo (int)$row['id']; ?>">Detail</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($detail): $h = $detail['health']; ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong><?php echo htmlspecialchars($detail['firewall']['hostname']); ?></strong>
            <a class="btn btn-sm btn-outline-secondary" href="firewall_health.php">Close</a>
        </div>
        <div class="card-body">
            <?php if (!$h['gateways'] && !$h['vpn'] && !$h['services'] && !$h['certificates'] && !$h['carp']): ?>
                <p class="text-muted mb-0">
                    This firewall has not reported health telemetry. It requires agent 1.6.0 or later
                    (currently <?php echo htmlspecialchars($detail['firewall']['agent_version'] ?: 'unknown'); ?>).
                </p>
            <?php endif; ?>

            <?php if ($h['gateways']): ?>
            <h6 class="small text-uppercase text-muted">Gateways</h6>
            <div class="table-responsive mb-3">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Name</th><th>Interface</th><th>Address</th><th>Status</th>
                               <th>Latency</th><th>Loss</th><th>Default</th></tr></thead>
                    <tbody>
                    <?php foreach ($h['gateways'] as $g): $sev = health_gateway_severity($g); ?>
                        <tr>
                            <td class="small"><?php echo htmlspecialchars($g['name']); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars($g['interface'] ?: '—'); ?></td>
                            <td class="small font-monospace"><?php echo htmlspecialchars($g['address'] ?: '—'); ?></td>
                            <td><span class="badge bg-<?php echo hb($sev); ?>"
                                      title="<?php echo htmlspecialchars($g['status']); ?>">
                                    <?php echo htmlspecialchars(health_gateway_label($g['status'])); ?></span></td>
                            <td class="small"><?php echo $g['latency_ms'] !== null ? htmlspecialchars($g['latency_ms']) . ' ms' : '—'; ?></td>
                            <td class="small"><?php echo $g['loss_percent'] !== null ? htmlspecialchars($g['loss_percent']) . ' %' : '—'; ?></td>
                            <td class="small"><?php echo $g['is_default'] ? '<i class="fas fa-check text-success"></i>' : ''; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if ($h['vpn']): ?>
            <h6 class="small text-uppercase text-muted">VPN tunnels</h6>
            <div class="table-responsive mb-3">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Type</th><th>Name</th><th>Peer / endpoint</th><th>Status</th>
                               <th>Handshake</th><th>RX</th><th>TX</th></tr></thead>
                    <tbody>
                    <?php foreach ($h['vpn'] as $v): ?>
                        <tr>
                            <td class="small"><span class="badge bg-secondary"><?php echo htmlspecialchars($v['vpn_type']); ?></span></td>
                            <td class="small"><?php echo htmlspecialchars($v['name']); ?></td>
                            <td class="small text-muted">
                                <?php echo htmlspecialchars($v['endpoint'] ?: $v['peer'] ?: '—'); ?>
                            </td>
                            <td><span class="badge bg-<?php echo in_array(strtolower((string)$v['status']), ['up','connected'], true) ? 'success' : 'danger'; ?>">
                                <?php echo htmlspecialchars($v['status']); ?></span></td>
                            <td class="small text-muted"><?php echo htmlspecialchars(h_age($v['latest_handshake'])); ?></td>
                            <td class="small"><?php echo h_bytes($v['rx_bytes'] === null ? null : (int)$v['rx_bytes']); ?></td>
                            <td class="small"><?php echo h_bytes($v['tx_bytes'] === null ? null : (int)$v['tx_bytes']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="row">
                <?php if ($h['services']): ?>
                <div class="col-md-6 mb-3">
                    <h6 class="small text-uppercase text-muted">Services</h6>
                    <table class="table table-sm mb-0">
                        <tbody>
                        <?php foreach ($h['services'] as $s): ?>
                            <tr>
                                <td class="small"><?php echo htmlspecialchars($s['name']); ?>
                                    <span class="text-muted"><?php echo htmlspecialchars($s['description'] ?: ''); ?></span></td>
                                <td class="text-end">
                                    <span class="badge bg-<?php echo $s['running'] ? 'success' : 'warning text-dark'; ?>">
                                        <?php echo $s['running'] ? 'running' : 'stopped'; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php if ($h['carp']): ?>
                <div class="col-md-6 mb-3">
                    <h6 class="small text-uppercase text-muted">CARP / HA</h6>
                    <table class="table table-sm mb-0">
                        <thead><tr><th>VHID</th><th>Interface</th><th>Address</th><th>State</th></tr></thead>
                        <tbody>
                        <?php foreach ($h['carp'] as $c): ?>
                            <tr>
                                <td class="small"><?php echo htmlspecialchars($c['vhid']); ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($c['interface'] ?: '—'); ?></td>
                                <td class="small font-monospace"><?php echo htmlspecialchars($c['address'] ?: '—'); ?></td>
                                <td><span class="badge bg-<?php echo $c['state'] === 'MASTER' ? 'primary'
                                    : ($c['state'] === 'BACKUP' ? 'secondary' : 'warning text-dark'); ?>">
                                    <?php echo htmlspecialchars($c['state']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($h['certificates']): ?>
            <h6 class="small text-uppercase text-muted">Certificates</h6>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Name</th><th>Type</th><th>Issuer</th><th>Expires</th><th>Days remaining</th></tr></thead>
                    <tbody>
                    <?php foreach ($h['certificates'] as $c):
                        $sev = health_cert_severity($c['days_remaining'] === null ? null : (int)$c['days_remaining']); ?>
                        <tr>
                            <td class="small"><?php echo htmlspecialchars($c['name'] ?: $c['refid']); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars($c['cert_type'] ?: '—'); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars(substr((string)$c['issuer'], 0, 60) ?: '—'); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars($c['not_after'] ?: '—'); ?></td>
                            <td>
                                <?php if ($c['days_remaining'] === null): ?>
                                    <span class="text-muted">unknown</span>
                                <?php else: ?>
                                    <span class="badge bg-<?php echo hb($sev); ?>">
                                        <?php echo (int)$c['days_remaining'] < 0
                                            ? 'expired ' . abs((int)$c['days_remaining']) . 'd ago'
                                            : (int)$c['days_remaining'] . ' days'; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small text-muted mt-2 mb-0">
                Certificate metadata only. Private key material is never collected or stored.
            </p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
