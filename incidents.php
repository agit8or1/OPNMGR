<?php
/**
 * Alert Incidents.
 *
 * One row per ongoing problem, not one per notification. An incident is opened
 * when a condition first becomes true, updated while it persists, and resolved
 * when it clears.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/alerting.php';
require_once __DIR__ . '/inc/maintenance.php';

require_permission('alert.view');

$message    = '';
$can_ack    = can('alert.acknowledge');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = '<div class="alert alert-danger">Invalid CSRF token.</div>';
    } elseif (!$can_ack) {
        $message = '<div class="alert alert-danger">Your role does not permit acknowledging incidents.</div>';
    } elseif (isset($_POST['acknowledge'])) {
        $r = alert_acknowledge((int)$_POST['incident_id'], trim($_POST['note'] ?? ''));
        $message = $r['ok']
            ? '<div class="alert alert-success">Incident acknowledged. It stays open, but stops notifying.</div>'
            : '<div class="alert alert-warning">' . htmlspecialchars($r['error']) . '</div>';
    }
}

$filters = array_filter([
    'status'      => $_GET['status'] ?? '',
    'severity'    => $_GET['severity'] ?? '',
    'alert_type'  => $_GET['type'] ?? '',
    'customer_id' => $_GET['customer'] ?? '',
]);

$incidents  = alert_open_incidents($filters);
$counts     = alert_incident_counts();
$inMaint    = maintenance_firewalls_in_window();
$detailId   = (int)($_GET['incident'] ?? 0);

$timeline = [];
if ($detailId > 0) {
    $stmt = db()->prepare('SELECT * FROM alert_incident_events WHERE incident_id = ? ORDER BY id');
    $stmt->execute([$detailId]);
    $timeline = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$customers = [];
try {
    $customers = db()->query('SELECT id, name FROM customers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* non-fatal */ }

/** Age of a timestamp in compact form. */
function inc_age(?string $ts): string {
    if (!$ts) return '—';
    $d = time() - strtotime($ts);
    if ($d < 90)    return 'just now';
    if ($d < 3600)  return (int)($d / 60) . 'm';
    if ($d < 86400) return (int)($d / 3600) . 'h';
    return (int)($d / 86400) . 'd';
}

function sev_class(string $s): string {
    return match ($s) { 'critical' => 'danger', 'warning' => 'warning text-dark', default => 'info' };
}

include __DIR__ . '/inc/header.php';
?>

<div class="container-fluid">
    <div class="mb-3">
        <h4 class="mb-0"><i class="fas fa-bell me-2"></i>Incidents</h4>
        <small class="text-muted">
            One entry per ongoing problem. Acknowledging stops notifications without closing the incident;
            an incident closes when the condition actually clears.
        </small>
    </div>

    <?php echo $message; ?>

    <div class="row g-2 mb-3">
        <?php foreach ([
            ['Critical',     $counts['critical'],     'danger'],
            ['Warning',      $counts['warning'],      'warning'],
            ['Info',         $counts['info'],         'info'],
            ['Acknowledged', $counts['acknowledged'], 'secondary'],
            ['Suppressed',   $counts['suppressed'],   'secondary'],
        ] as [$label, $n, $colour]): ?>
        <div class="col-6 col-md-2">
            <div class="card h-100"><div class="card-body py-2">
                <div class="text-muted small"><?php echo $label; ?></div>
                <div class="fs-4 text-<?php echo $n > 0 ? $colour : 'success'; ?>"><?php echo (int)$n; ?></div>
            </div></div>
        </div>
        <?php endforeach; ?>
        <div class="col-6 col-md-2">
            <div class="card h-100"><div class="card-body py-2">
                <div class="text-muted small">In maintenance</div>
                <div class="fs-4 text-secondary"><?php echo count($inMaint); ?></div>
            </div></div>
        </div>
    </div>

    <div class="card mb-3"><div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Open &amp; acknowledged</option>
                    <?php foreach (['open','acknowledged','resolved'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo ($_GET['status'] ?? '') === $s ? 'selected' : ''; ?>>
                            <?php echo ucfirst($s); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Severity</label>
                <select name="severity" class="form-select form-select-sm">
                    <option value="">Any</option>
                    <?php foreach (['critical','warning','info'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo ($_GET['severity'] ?? '') === $s ? 'selected' : ''; ?>>
                            <?php echo ucfirst($s); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">Any</option>
                    <?php foreach (ALERT_TYPES as $key => $meta): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>"
                            <?php echo ($_GET['type'] ?? '') === $key ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($meta['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Customer</label>
                <select name="customer" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>"
                            <?php echo ($_GET['customer'] ?? '') === (string)$c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-grid gap-1">
                <button class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i>Filter</button>
                <a class="btn btn-outline-secondary btn-sm" href="incidents.php">Reset</a>
            </div>
        </form>
    </div></div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr>
                    <th>Severity</th><th>Problem</th><th>Firewall</th><th>Customer</th>
                    <th>Open for</th><th>Seen</th><th>Notified</th><th>Status</th><th></th>
                </tr></thead>
                <tbody>
                <?php if (!$incidents): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">
                        No incidents match these filters. That is the good outcome.
                    </td></tr>
                <?php else: foreach ($incidents as $i): ?>
                    <tr>
                        <td><span class="badge bg-<?php echo sev_class($i['severity']); ?>">
                            <?php echo htmlspecialchars($i['severity']); ?></span></td>
                        <td class="small">
                            <?php echo htmlspecialchars($i['title']); ?>
                            <?php if ($i['detail']): ?>
                                <br><span class="text-muted"><?php echo htmlspecialchars(substr($i['detail'], 0, 120)); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($i['hostname']): ?>
                                <a href="firewall_details.php?id=<?php echo (int)$i['firewall_id']; ?>">
                                    <?php echo htmlspecialchars($i['hostname']); ?></a>
                                <?php if (in_array((int)$i['firewall_id'], $inMaint, true)): ?>
                                    <span class="badge bg-secondary ms-1" title="Alerts suppressed">MAINTENANCE</span>
                                <?php endif; ?>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="small text-muted"><?php echo htmlspecialchars($i['customer_name'] ?: '—'); ?></td>
                        <td class="small text-muted"><?php echo inc_age($i['first_seen_at']); ?></td>
                        <td class="small text-muted"><?php echo (int)$i['occurrence_count']; ?>&times;</td>
                        <td class="small text-muted">
                            <?php if ((int)$i['suppressed'] === 1): ?>
                                <span class="badge bg-secondary" title="<?php echo htmlspecialchars($i['suppressed_reason'] ?? ''); ?>">held</span>
                            <?php else: ?>
                                <?php echo (int)$i['notify_count']; ?>&times;
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($i['status'] === 'acknowledged'): ?>
                                <span class="badge bg-secondary" title="<?php echo htmlspecialchars($i['acknowledged_note'] ?? ''); ?>">
                                    ack <?php echo htmlspecialchars($i['acknowledged_by'] ?? ''); ?></span>
                            <?php else: ?>
                                <span class="badge bg-<?php echo sev_class($i['severity']); ?>">open</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary" href="?incident=<?php echo (int)$i['id']; ?>">History</a>
                            <?php if ($can_ack && $i['status'] === 'open'): ?>
                            <button class="btn btn-sm btn-outline-warning" data-bs-toggle="collapse"
                                    data-bs-target="#ack<?php echo (int)$i['id']; ?>">Ack</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($can_ack && $i['status'] === 'open'): ?>
                    <tr class="collapse" id="ack<?php echo (int)$i['id']; ?>">
                        <td colspan="9">
                            <form method="post" class="d-flex gap-2">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                <input type="hidden" name="incident_id" value="<?php echo (int)$i['id']; ?>">
                                <input type="text" name="note" class="form-control form-control-sm"
                                       placeholder="Why are you acknowledging this? (optional)">
                                <button class="btn btn-sm btn-warning text-nowrap" name="acknowledge">Acknowledge</button>
                            </form>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($timeline): ?>
    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong class="small">Incident #<?php echo $detailId; ?> history</strong>
            <a class="btn btn-sm btn-outline-secondary" href="incidents.php">Close</a>
        </div>
        <div class="card-body">
            <ul class="list-unstyled mb-0 small">
                <?php foreach ($timeline as $e): ?>
                    <li class="mb-1">
                        <span class="text-muted" style="display:inline-block;width:150px">
                            <?php echo htmlspecialchars($e['occurred_at']); ?></span>
                        <span class="badge bg-secondary"><?php echo htmlspecialchars($e['event']); ?></span>
                        <?php echo htmlspecialchars($e['detail'] ?? ''); ?>
                        <?php if ($e['actor']): ?>
                            <span class="text-muted">by <?php echo htmlspecialchars($e['actor']); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
