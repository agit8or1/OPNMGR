<?php
require_once __DIR__ . '/inc/firewall_policy.php';
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
$incident = null;
if ($detailId > 0) {
    $stmt = db()->prepare('SELECT * FROM alert_incident_events WHERE incident_id = ? ORDER BY id');
    $stmt->execute([$detailId]);
    $timeline = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // The incident itself, not only its history. "History" showed a list of
    // state changes and never the thing they happened to: no exact times, no
    // object, no address, and a detail line truncated at 120 characters in the
    // table with nowhere to read the rest.
    $stmt = db()->prepare(
        'SELECT i.*, f.hostname, f.wan_ip, f.wan_interface_stats, f.lan_ip,
                f.agent_version, f.status AS firewall_status, c.name AS customer_name
           FROM alert_incidents i
           LEFT JOIN firewalls f ON f.id = i.firewall_id
           LEFT JOIN customers c ON c.id = i.customer_id
          WHERE i.id = ?'
    );
    $stmt->execute([$detailId]);
    $incident = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** A timestamp with both the date and how long ago it was. */
function inc_when(?string $ts): string
{
    if (!$ts) { return '—'; }
    $t = strtotime($ts);
    if (!$t) { return htmlspecialchars($ts); }
    return htmlspecialchars(date('D j M Y, H:i:s', $t)) . ' <span class="text-muted">('
         . htmlspecialchars(inc_age($ts)) . ' ago)</span>';
}

/** How long the incident lasted, when it is over. */
function inc_duration(?string $from, ?string $to): string
{
    if (!$from || !$to) { return '—'; }
    $secs = max(0, strtotime($to) - strtotime($from));
    if ($secs < 60)    { return $secs . ' seconds'; }
    if ($secs < 3600)  { return round($secs / 60) . ' minutes'; }
    if ($secs < 86400) { return round($secs / 3600, 1) . ' hours'; }
    return round($secs / 86400, 1) . ' days';
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
                            <?php // The title is the way in: "History" was a small button at the
                                  // far right of a nine-column row, and the thing most people
                                  // click is the name of the thing. ?>
                            <a href="?incident=<?php echo (int)$i['id']; ?>#incident-detail"
                               class="incident-open"><?php echo htmlspecialchars($i['title']); ?></a>
                            <?php if ($i['detail']): ?>
                                <br><span class="text-muted"><?php echo htmlspecialchars(substr($i['detail'], 0, 120)); ?><?php
                                    echo strlen($i['detail']) > 120 ? '…' : ''; ?></span>
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

    <?php if ($incident): ?>
    <?php
    $meta = json_decode((string) ($incident['metadata'] ?? ''), true);
    if (!is_array($meta)) { $meta = []; }
    // Addresses first: when something is wrong, the address involved is what you
    // reach for. Only firewall.offline recorded any metadata before, so most
    // incidents arrived with nothing to inspect.
    $addressKeys = ['address', 'monitor_ip', 'endpoint', 'peer', 'source_ip', 'ip'];

    // Hoisted rather than built inline. These are agent-supplied strings, and a
    // concatenation that happens to be escaped today is one edit away from not
    // being; tests/injection_guard_test.php rejects the shape for that reason,
    // and it was right to - this block was written with the escaping buried in
    // a ternary.
    $objectKey = (string) ($incident['object_key'] ?? '');
    $lanAddr   = (string) ($incident['lan_ip'] ?? '');
    $wanAddr   = $incident['hostname'] ? (string) firewall_wan_address($incident) : '';
    ?>
    <div class="card mt-3" id="incident-detail">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <span class="badge bg-<?php echo sev_class($incident['severity']); ?> me-2">
                    <?php echo htmlspecialchars($incident['severity']); ?></span>
                <strong>Incident #<?php echo (int) $incident['id']; ?></strong>
                <span class="text-muted ms-2"><?php echo htmlspecialchars($incident['title']); ?></span>
            </div>
            <a class="btn btn-sm btn-outline-secondary" href="incidents.php">Close</a>
        </div>
        <div class="card-body">

            <?php if (!empty($incident['detail'])): ?>
                <p class="mb-3"><?php echo nl2br(htmlspecialchars($incident['detail'])); ?></p>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <table class="table table-sm mb-0">
                        <tbody>
                        <tr><th style="width:38%">Condition</th>
                            <td><code><?php echo htmlspecialchars($incident['alert_type']); ?></code></td></tr>
                        <tr><th>Object</th>
                            <td><?php if ($objectKey !== ''): ?><code><?php echo htmlspecialchars($objectKey); ?></code>
                                <?php else: ?><span class="text-muted">the firewall itself</span><?php endif; ?></td></tr>
                        <tr><th>Firewall</th>
                            <td>
                                <?php if ($incident['hostname']): ?>
                                    <a href="firewall_details.php?id=<?php echo (int) $incident['firewall_id']; ?>">
                                        <?php echo htmlspecialchars($incident['hostname']); ?></a>
                                    <span class="text-muted">(<?php echo htmlspecialchars($incident['firewall_status'] ?? '?'); ?>)</span>
                                <?php else: ?>
                                    <span class="text-muted">no longer exists</span>
                                <?php endif; ?>
                            </td></tr>
                        <tr><th>WAN address</th>
                            <td><?php if ($wanAddr !== ''): ?><code><?php echo htmlspecialchars($wanAddr); ?></code>
                                <?php else: ?><span class="text-muted">&mdash;</span><?php endif; ?></td></tr>
                        <tr><th>LAN address</th>
                            <td><?php if ($lanAddr !== ''): ?><code><?php echo htmlspecialchars($lanAddr); ?></code>
                                <?php else: ?><span class="text-muted">&mdash;</span><?php endif; ?></td></tr>
                        <tr><th>Customer</th>
                            <td><?php echo htmlspecialchars($incident['customer_name'] ?: '—'); ?></td></tr>
                        <tr><th>Status</th>
                            <td><?php echo htmlspecialchars($incident['status']); ?>
                                <?php if ((int) $incident['suppressed'] === 1): ?>
                                    <span class="badge bg-secondary ms-1">notifications held</span>
                                    <div class="small text-muted"><?php echo htmlspecialchars($incident['suppressed_reason'] ?? ''); ?></div>
                                <?php endif; ?></td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="col-md-6">
                    <table class="table table-sm mb-0">
                        <tbody>
                        <tr><th style="width:38%">First seen</th><td><?php echo inc_when($incident['first_seen_at']); ?></td></tr>
                        <tr><th>Last seen</th><td><?php echo inc_when($incident['last_seen_at']); ?></td></tr>
                        <tr><th>Resolved</th>
                            <td><?php echo $incident['resolved_at']
                                    ? inc_when($incident['resolved_at'])
                                    : '<span class="text-warning">still open</span>'; ?></td></tr>
                        <tr><th>Lasted</th>
                            <td><?php echo htmlspecialchars(inc_duration(
                                    $incident['first_seen_at'],
                                    $incident['resolved_at'] ?: $incident['last_seen_at'])); ?>
                                <?php if (!$incident['resolved_at']): ?>
                                    <span class="text-muted">so far</span>
                                <?php endif; ?></td></tr>
                        <tr><th>Seen</th>
                            <td><?php echo (int) $incident['occurrence_count']; ?> time(s)
                                <span class="text-muted">— each evaluation that found it still true</span></td></tr>
                        <tr><th>Notified</th>
                            <td><?php echo (int) $incident['notify_count']; ?> time(s)<?php
                                if ($incident['last_notified_at']) {
                                    echo ', last ' . inc_when($incident['last_notified_at']);
                                } ?></td></tr>
                        <?php if ($incident['acknowledged_at']): ?>
                        <tr><th>Acknowledged</th>
                            <td><?php echo inc_when($incident['acknowledged_at']); ?>
                                <?php if ($incident['acknowledged_by']): ?>
                                    <div class="small">by <?php echo htmlspecialchars($incident['acknowledged_by']); ?></div>
                                <?php endif; ?>
                                <?php if ($incident['acknowledged_note']): ?>
                                    <div class="small text-muted"><?php echo htmlspecialchars($incident['acknowledged_note']); ?></div>
                                <?php endif; ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($meta): ?>
                <h6 class="mt-3 mb-2 small text-uppercase text-muted">What was recorded</h6>
                <table class="table table-sm mb-0">
                    <tbody>
                    <?php
                    uksort($meta, static function ($a, $b) use ($addressKeys) {
                        $ai = in_array($a, $addressKeys, true) ? 0 : 1;
                        $bi = in_array($b, $addressKeys, true) ? 0 : 1;
                        return $ai === $bi ? strcmp($a, $b) : $ai - $bi;
                    });
                    ?>
                    <?php foreach ($meta as $k => $v): ?>
                        <tr>
                            <th style="width:38%"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string) $k))); ?></th>
                            <td><code><?php echo htmlspecialchars(is_scalar($v) ? (string) $v : json_encode($v)); ?></code>
                                <?php if ((string) $k === 'seconds_since_checkin' && is_numeric($v)): ?>
                                    <span class="text-muted">(<?php echo round($v / 60); ?> minutes)</span>
                                <?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="small text-muted mt-3 mb-0">
                    No structured detail was recorded for this incident. Conditions raised
                    before v3.76.0 stored none except <code>firewall.offline</code>.
                </p>
            <?php endif; ?>

            <?php if ($timeline): ?>
                <h6 class="mt-3 mb-2 small text-uppercase text-muted">History</h6>
                <ul class="list-unstyled mb-0 small">
                    <?php foreach ($timeline as $e): ?>
                        <li class="mb-1">
                            <span class="text-muted" style="display:inline-block;width:170px">
                                <?php echo htmlspecialchars($e['occurred_at']); ?></span>
                            <span class="badge bg-secondary"><?php echo htmlspecialchars($e['event']); ?></span>
                            <?php echo htmlspecialchars($e['detail'] ?? ''); ?>
                            <?php if ($e['actor']): ?>
                                <span class="text-muted">by <?php echo htmlspecialchars($e['actor']); ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
