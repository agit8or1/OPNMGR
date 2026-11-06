<?php
/**
 * Integration routing rules and routing preview.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/alerting.php';
require_once __DIR__ . '/inc/integrations/ui.php';

require_permission('integration.view');

$message = '';
$canManage = can('integration.manage');
$username = $_SESSION['username'] ?? 'unknown';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'preview') {
    $err = integration_page_guard_post('integration.manage');
    $act = $_POST['action'] ?? '';
    if ($err) { $message = $err; }
    elseif ($act === 'save') {
        $r = integration_route_save($_POST, (int)($_POST['id'] ?? 0) ?: null, $username);
        $message = $r['ok'] ? '<div class="alert alert-success">Route saved.</div>' : '<div class="alert alert-danger">' . ih($r['error']) . '</div>';
    } elseif ($act === 'delete') {
        db()->prepare('DELETE FROM integration_routes WHERE id = ?')->execute([(int)$_POST['id']]);
        audit_log('integration.route_deleted', ['object_type' => 'integration_route', 'object_id' => (string)(int)$_POST['id']]);
        $message = '<div class="alert alert-success">Route deleted.</div>';
    }
}

$customers = integration_customers();
$dests = [];
foreach (integration_destination_list() as $d) { $dests[$d['id']] = $d; }
[$w, $p] = integration_scope_sql('customer_id');
// Scoped staff see only routes bound to their customers; global routes are for unrestricted users.
$s = db()->prepare("SELECT * FROM integration_routes WHERE {$w} ORDER BY id"); $s->execute($p);
$routes = $s->fetchAll(PDO::FETCH_ASSOC);
$edit = null;
foreach ($routes as $r) { if (!empty($_GET['edit']) && (int)$_GET['edit'] === (int)$r['id']) { $edit = $r; } }
$editSched = $edit && $edit['schedule_json'] ? (json_decode($edit['schedule_json'], true) ?: []) : [];
$tags = db()->query('SELECT id, name FROM tags ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$sites = db()->query('SELECT id, name, customer_id FROM sites ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$firewalls = [];
[$fw, $fp] = integration_scope_sql('customer_id');
$s = db()->prepare("SELECT id, hostname FROM firewalls WHERE {$fw} ORDER BY hostname"); $s->execute($fp);
$firewalls = $s->fetchAll(PDO::FETCH_KEY_PAIR);

// Preview: where would a sample incident go?
$preview = null;
if (($_POST['action'] ?? '') === 'preview' && csrf_verify($_POST['csrf'] ?? '')) {
    $fid = (int)($_POST['pv_firewall'] ?? 0);
    $f = $fid ? db()->prepare('SELECT id, customer_id, site_id FROM firewalls WHERE id = ?') : null;
    $fwRow = null;
    if ($f) { $f->execute([$fid]); $fwRow = $f->fetch(PDO::FETCH_ASSOC) ?: null; }
    if ($fwRow && integration_can_see_customer($fwRow['customer_id'] !== null ? (int)$fwRow['customer_id'] : null)) {
        $type = array_key_exists($_POST['pv_type'] ?? '', ALERT_TYPES) ? $_POST['pv_type'] : 'gateway.down';
        $sev = in_array($_POST['pv_sev'] ?? '', ['info', 'warning', 'critical'], true) ? $_POST['pv_sev'] : 'critical';
        $evType = array_key_exists($_POST['pv_event'] ?? '', INTEGRATION_EVENT_TYPES) ? $_POST['pv_event'] : 'incident.opened';
        $st = $_POST['pv_status'] ?? 'open';
        $inc = ['id' => 0, 'status' => in_array($st, ['open', 'acknowledged', 'resolved'], true) ? $st : 'open', 'firewall_id' => $fid, 'alert_type' => $type];
        $ev = ['event_type' => $evType, 'customer_id' => $fwRow['customer_id'], 'site_id' => $fwRow['site_id'], 'firewall_id' => $fid, 'incident_id' => null, 'severity' => $sev];
        $ts = !empty($_POST['pv_time']) ? (strtotime($_POST['pv_time'] . ' UTC') ?: time()) : time();
        $tagIds = db()->prepare('SELECT tag_id FROM firewall_tags WHERE firewall_id = ?'); $tagIds->execute([$fid]);
        $preview = integration_routing_decisions($ev, $ts, ['alert_type' => $type, 'tag_ids' => array_map('intval', $tagIds->fetchAll(PDO::FETCH_COLUMN)), 'incident' => $inc]);
        $preview = array_values(array_filter($preview, fn($d) => !$d['destination'] || integration_can_see_customer($d['destination']['customer_id'] !== null ? (int)$d['destination']['customer_id'] : null)));
    }
}

include __DIR__ . '/inc/header.php';
?>
<div class="container-fluid">
    <h4 class="mb-1"><i class="fas fa-random me-2"></i>Routing rules</h4>
    <p class="text-muted small">A rule sends matching events to one destination. Empty filters match everything. Maintenance windows, acknowledgement and suppression still apply after routing.</p>
    <?php integration_nav('integration_routes.php'); echo $message; ?>

    <div class="card mb-3"><div class="card-body">
        <h6>Routes</h6>
        <div class="table-responsive"><table class="table table-sm align-middle">
            <thead><tr><th>Name</th><th>Destination</th><th>Matches</th><th>Schedule</th><th></th></tr></thead><tbody>
            <?php foreach ($routes as $r): $sch = $r['schedule_json'] ? json_decode($r['schedule_json'], true) : null; ?>
                <tr><td><?php echo ih($r['name']); echo $r['enabled'] ? '' : ' <span class="badge bg-secondary">off</span>'; ?></td>
                    <td><?php echo ih($dests[$r['destination_id']]['name'] ?? '#' . $r['destination_id']); ?></td>
                    <td class="small">
                        <?php echo $r['escalate_after_minutes'] !== null ? '<span class="badge bg-warning text-dark">escalate if unacknowledged after ' . (int)$r['escalate_after_minutes'] . ' min</span> ' : ''; ?>
                        events: <?php echo ih($r['event_types'] ?: 'all'); ?> | customer: <?php echo $r['customer_id'] ? ih($customers[$r['customer_id']] ?? $r['customer_id']) : 'all'; ?>
                        | site: <?php echo $r['site_id'] ?: 'all'; ?> | severity: <?php echo ih($r['severities'] ?: 'all'); ?>
                        | types: <?php echo ih($r['incident_types'] ?: 'all'); ?> | tags: <?php echo $r['tag_ids'] ? ih(implode(', ', array_map(fn($i) => $tags[$i] ?? $i, integration_csv($r['tag_ids'])))) : 'all'; ?></td>
                    <td class="small"><?php echo $sch ? ih("{$sch['start']}-{$sch['end']} {$sch['timezone']} (" . $r['schedule_outside'] . ')') : 'always'; ?></td>
                    <td class="text-end text-nowrap"><?php if ($canManage): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="?edit=<?php echo (int)$r['id']; ?>">Edit</a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this route?');"><input type="hidden" name="csrf" value="<?php echo ih(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form>
                    <?php endif; ?></td></tr>
            <?php endforeach; if (!$routes): ?><tr><td colspan="5" class="text-muted">No routes. Events are recorded but not sent anywhere.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div></div>

    <?php if ($canManage): ?>
    <div class="card mb-3"><div class="card-body">
        <h6><?php echo $edit ? 'Edit route' : 'New route'; ?></h6>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo ih(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Name</label><input class="form-control" name="name" required value="<?php echo ih($edit['name'] ?? ''); ?>"></div>
                <div class="col-md-4"><label class="form-label">Destination</label><select name="destination_id" class="form-select" required>
                    <?php foreach ($dests as $d): ?><option value="<?php echo (int)$d['id']; ?>" <?php echo ($edit['destination_id'] ?? 0) == $d['id'] ? 'selected' : ''; ?>><?php echo ih($d['name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4 pt-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="disabled" value="1" <?php echo $edit && !$edit['enabled'] ? 'checked' : ''; ?>><label class="form-check-label">Disabled</label></div></div>
                <div class="col-md-4"><label class="form-label">Customer</label><select name="customer_id" class="form-select"><option value="">Any</option>
                    <?php foreach ($customers as $cid => $cn): ?><option value="<?php echo (int)$cid; ?>" <?php echo ($edit['customer_id'] ?? null) == $cid ? 'selected' : ''; ?>><?php echo ih($cn); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label">Site</label><select name="site_id" class="form-select"><option value="">Any</option>
                    <?php foreach ($sites as $st): ?><option value="<?php echo (int)$st['id']; ?>" <?php echo ($edit['site_id'] ?? null) == $st['id'] ? 'selected' : ''; ?>><?php echo ih($st['name'] . ' (' . ($customers[$st['customer_id']] ?? '#' . $st['customer_id']) . ')'); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label">Device tags (any of)</label><select name="tag_ids[]" class="form-select" multiple size="3">
                    <?php foreach ($tags as $tid => $tn): ?><option value="<?php echo (int)$tid; ?>" <?php echo in_array((string)$tid, integration_csv($edit['tag_ids'] ?? ''), true) ? 'selected' : ''; ?>><?php echo ih($tn); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label">Event types</label><select name="event_types[]" class="form-select" multiple size="6">
                    <?php foreach (INTEGRATION_EVENT_TYPES as $t => $l): if ($t === 'integration.test' || $t === 'incident.escalated') { continue; } ?><option value="<?php echo ih($t); ?>" <?php echo in_array($t, integration_csv($edit['event_types'] ?? ''), true) ? 'selected' : ''; ?>><?php echo ih($l); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label">Severities</label><select name="severities[]" class="form-select" multiple size="3">
                    <?php foreach (['critical', 'warning', 'info'] as $sv): ?><option <?php echo in_array($sv, integration_csv($edit['severities'] ?? ''), true) ? 'selected' : ''; ?>><?php echo $sv; ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label">Incident types</label><select name="incident_types[]" class="form-select" multiple size="6">
                    <?php foreach (ALERT_TYPES as $t => $m): ?><option value="<?php echo ih($t); ?>" <?php echo in_array($t, integration_csv($edit['incident_types'] ?? ''), true) ? 'selected' : ''; ?>><?php echo ih($m['label']); ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><hr><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="sched_enabled" value="1" id="se" <?php echo $editSched ? 'checked' : ''; ?>><label class="form-check-label" for="se">Only within a schedule (e.g. business hours)</label></div></div>
                <div class="col-md-3"><label class="form-label">Timezone</label><input class="form-control" name="sched_timezone" value="<?php echo ih($editSched['timezone'] ?? 'America/New_York'); ?>"></div>
                <div class="col-md-2"><label class="form-label">From</label><input class="form-control" name="sched_start" value="<?php echo ih($editSched['start'] ?? '09:00'); ?>"></div>
                <div class="col-md-2"><label class="form-label">Until</label><input class="form-control" name="sched_end" value="<?php echo ih($editSched['end'] ?? '17:00'); ?>"></div>
                <div class="col-md-3"><label class="form-label">Days</label><div>
                    <?php foreach ([1 => 'Mo', 2 => 'Tu', 3 => 'We', 4 => 'Th', 5 => 'Fr', 6 => 'Sa', 7 => 'Su'] as $n => $l): ?>
                        <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="sched_days[]" value="<?php echo $n; ?>" <?php echo in_array($n, $editSched['days'] ?? [1, 2, 3, 4, 5], true) ? 'checked' : ''; ?>><label class="form-check-label"><?php echo $l; ?></label></div><?php endforeach; ?></div></div>
                <div class="col-md-2"><label class="form-label">Outside schedule</label><select name="schedule_outside" class="form-select"><option value="skip" <?php echo ($edit['schedule_outside'] ?? 'skip') === 'skip' ? 'selected' : ''; ?>>Skip (recorded)</option><option value="defer" <?php echo ($edit['schedule_outside'] ?? '') === 'defer' ? 'selected' : ''; ?>>Defer to next window</option></select></div>
                <div class="col-12"><hr></div>
                <div class="col-md-4"><label class="form-label">Escalation: only if unacknowledged after (minutes)</label><input type="number" min="1" class="form-control" name="escalate_after_minutes" value="<?php echo ih($edit['escalate_after_minutes'] ?? ''); ?>">
                    <div class="form-text">Makes this an escalation route: it fires once for an incident still open and unacknowledged after N minutes, instead of at once. Leave blank for a normal route.</div></div>
            </div>
            <div class="mt-3"><button class="btn btn-primary">Save route</button> <a class="btn btn-link" href="/integration_routes.php">Cancel</a></div>
        </form>
    </div></div>
    <?php endif; ?>

    <div class="card mb-3"><div class="card-body">
        <h6>Routing preview</h6>
        <p class="small text-muted">Shows what would happen to a sample event. Nothing is sent.</p>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="csrf" value="<?php echo ih(csrf_token()); ?>"><input type="hidden" name="action" value="preview">
            <div class="col-md-3"><label class="form-label">Firewall</label><select name="pv_firewall" class="form-select" required>
                <?php foreach ($firewalls as $fid => $hn): ?><option value="<?php echo (int)$fid; ?>" <?php echo (int)($_POST['pv_firewall'] ?? 0) === (int)$fid ? 'selected' : ''; ?>><?php echo ih($hn); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Event</label><select name="pv_event" class="form-select"><?php foreach (INTEGRATION_EVENT_TYPES as $t => $l): if ($t === 'integration.test') { continue; } ?><option value="<?php echo ih($t); ?>" <?php echo ($_POST['pv_event'] ?? '') === $t ? 'selected' : ''; ?>><?php echo ih($l); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Incident type</label><select name="pv_type" class="form-select"><?php foreach (ALERT_TYPES as $t => $m): ?><option value="<?php echo ih($t); ?>" <?php echo ($_POST['pv_type'] ?? '') === $t ? 'selected' : ''; ?>><?php echo ih($m['label']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-1"><label class="form-label">Severity</label><select name="pv_sev" class="form-select"><?php foreach (['critical', 'warning', 'info'] as $sv): ?><option <?php echo ($_POST['pv_sev'] ?? '') === $sv ? 'selected' : ''; ?>><?php echo $sv; ?></option><?php endforeach; ?></select></div>
            <div class="col-md-1"><label class="form-label">Status</label><select name="pv_status" class="form-select"><?php foreach (['open', 'acknowledged', 'resolved'] as $sv): ?><option <?php echo ($_POST['pv_status'] ?? '') === $sv ? 'selected' : ''; ?>><?php echo $sv; ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">At (UTC, optional)</label><input class="form-control" name="pv_time" placeholder="2026-11-09 03:00" value="<?php echo ih($_POST['pv_time'] ?? ''); ?>"></div>
            <div class="col-md-1"><button class="btn btn-outline-primary w-100">Preview</button></div>
        </form>
        <?php if ($preview !== null): ?>
        <table class="table table-sm mt-3 mb-0"><thead><tr><th>Route</th><th>Destination</th><th>Result</th></tr></thead><tbody>
            <?php foreach ($preview as $d): $v = $d['verdict']; ?>
                <tr><td><?php echo ih($d['route']['name']); ?></td><td><?php echo ih($d['destination']['name'] ?? '-'); ?></td>
                    <td><?php if (!$d['matched']): ?><span class="text-muted">not matched: <?php echo ih($d['reason']); ?></span>
                        <?php elseif ($v === 'deliver'): ?><span class="badge bg-success">would send</span>
                        <?php elseif ($v === 'defer'): ?><span class="badge bg-info text-dark">deferred</span> <?php echo ih($d['detail']); ?>
                        <?php elseif ($v === 'suppress'): ?><span class="badge bg-warning text-dark">withheld</span> <?php echo ih($d['detail']); ?>
                        <?php else: ?><span class="text-muted"><?php echo ih($d['reason']); ?></span><?php endif; ?></td></tr>
            <?php endforeach; if (!$preview): ?><tr><td colspan="3" class="text-muted">No routes configured.</td></tr><?php endif; ?>
        </tbody></table>
        <?php endif; ?>
    </div></div>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
