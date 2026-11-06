<?php
/**
 * Integration delivery history, dead-letter queue and manual replay.
 * Rows are filtered to the customers the user may see.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/alerting.php';
require_once __DIR__ . '/inc/integrations/ui.php';

require_permission('integration.view');

$message = '';
$canReplay = can('integration.replay');
$username = $_SESSION['username'] ?? 'unknown';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $err = integration_page_guard_post('integration.replay');
    if ($err) { $message = $err; }
    elseif (($_POST['action'] ?? '') === 'replay') {
        $r = integration_replay((int)($_POST['id'] ?? 0), $username);
        $message = $r['ok'] ? '<div class="alert alert-success">Delivery queued again. The dispatcher sends it on its next run.</div>'
                            : '<div class="alert alert-warning">' . ih($r['error']) . '</div>';
    }
}

$view = $_GET['view'] ?? 'all';
$where = []; $params = [];
[$sw, $sp] = integration_scope_sql('d.customer_id');
$where[] = $sw; $params = array_merge($params, $sp);
if ($view === 'dead') { $where[] = "d.status IN ('dead','needs_reconciliation')"; }
elseif (!empty($_GET['status']) && preg_match('/^[a-z_]+$/', $_GET['status'])) { $where[] = 'd.status = ?'; $params[] = $_GET['status']; }
if (!empty($_GET['destination'])) { $where[] = 'd.destination_id = ?'; $params[] = (int)$_GET['destination']; }
$sql = 'SELECT d.*, x.name AS dest_name, c.name AS customer_name FROM integration_deliveries d
          LEFT JOIN integration_destinations x ON x.id = d.destination_id
          LEFT JOIN customers c ON c.id = d.customer_id
         WHERE ' . implode(' AND ', $where) . ' ORDER BY d.id DESC LIMIT 200';
$s = db()->prepare($sql); $s->execute($params);
$rows = $s->fetchAll(PDO::FETCH_ASSOC);

$detail = null; $attempts = [];
if (!empty($_GET['id'])) {
    foreach ($rows as $r) { if ((int)$r['id'] === (int)$_GET['id']) { $detail = $r; } }
    if (!$detail) {
        $q = db()->prepare('SELECT d.*, x.name AS dest_name FROM integration_deliveries d LEFT JOIN integration_destinations x ON x.id=d.destination_id WHERE d.id=? AND ' . $sw);
        $q->execute(array_merge([(int)$_GET['id']], $sp)); $detail = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($detail) {
        $q = db()->prepare('SELECT * FROM integration_delivery_attempts WHERE delivery_id = ? ORDER BY attempt_no'); $q->execute([$detail['id']]);
        $attempts = $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
$counts = [];
$q = db()->prepare("SELECT d.status, COUNT(*) n FROM integration_deliveries d WHERE {$sw} GROUP BY d.status"); $q->execute($sp);
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $counts[$r['status']] = (int)$r['n']; }

$dlvRows = $rows; // header.php reuses common variable names
include __DIR__ . '/inc/header.php';
?>
<div class="container-fluid">
    <h4 class="mb-1"><i class="fas fa-paper-plane me-2"></i>Delivery history</h4>
    <p class="text-muted small">Every attempt to send an event, including ones withheld by maintenance or acknowledgement. Errors are redacted.</p>
    <?php integration_nav('integration_deliveries.php'); echo $message; ?>
    <div class="mb-3">
        <a class="btn btn-sm <?php echo $view === 'dead' ? 'btn-danger' : 'btn-outline-danger'; ?>" href="?view=dead">Dead letters / needs reconciliation (<?php echo ($counts['dead'] ?? 0) + ($counts['needs_reconciliation'] ?? 0); ?>)</a>
        <a class="btn btn-sm <?php echo $view !== 'dead' ? 'btn-secondary' : 'btn-outline-secondary'; ?>" href="?">All</a>
        <?php foreach (['retry', 'pending', 'succeeded', 'suppressed'] as $st): ?>
            <a class="btn btn-sm btn-outline-secondary" href="?status=<?php echo $st; ?>"><?php echo $st; ?> (<?php echo $counts[$st] ?? 0; ?>)</a><?php endforeach; ?>
    </div>

    <?php if ($detail): ?>
    <div class="card mb-3"><div class="card-body">
        <h6>Delivery #<?php echo (int)$detail['id']; ?> <?php echo integration_status_badge($detail['status']); ?></h6>
        <dl class="row small mb-2">
            <dt class="col-sm-3">Destination</dt><dd class="col-sm-9"><?php echo ih($detail['dest_name']); ?></dd>
            <dt class="col-sm-3">Event</dt><dd class="col-sm-9"><?php echo ih($detail['event_type']); ?> <span class="text-muted"><?php echo ih($detail['event_id']); ?></span></dd>
            <dt class="col-sm-3">Idempotency key</dt><dd class="col-sm-9"><code><?php echo ih($detail['idempotency_key']); ?></code></dd>
            <dt class="col-sm-3">Resolved address</dt><dd class="col-sm-9"><?php echo ih($detail['resolved_ip'] ?: '-'); ?></dd>
            <dt class="col-sm-3">Last error</dt><dd class="col-sm-9"><?php echo ih($detail['last_error'] ?: $detail['suppress_reason'] ?: '-'); ?></dd>
        </dl>
        <table class="table table-sm mb-0"><thead><tr><th>#</th><th>Started</th><th>ms</th><th>Outcome</th><th>HTTP</th><th>Error</th></tr></thead><tbody>
        <?php foreach ($attempts as $a): ?><tr><td><?php echo (int)$a['attempt_no']; ?></td><td><?php echo ih($a['started_at']); ?></td><td><?php echo ih($a['duration_ms']); ?></td><td><?php echo ih($a['outcome']); ?></td><td><?php echo ih($a['http_status']); ?></td><td class="small"><?php echo ih($a['error']); ?></td></tr><?php endforeach;
        if (!$attempts): ?><tr><td colspan="6" class="text-muted">No attempts yet.</td></tr><?php endif; ?></tbody></table>
    </div></div>
    <?php endif; ?>

    <div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th>#</th><th>When</th><th>Event</th><th>Destination</th><th>Customer</th><th>Status</th><th>Tries</th><th>Next / error</th><th></th></tr></thead><tbody>
        <?php foreach ($dlvRows as $dl): ?>
            <tr><td><a href="?id=<?php echo (int)$dl['id']; ?>"><?php echo (int)$dl['id']; ?></a></td><td class="small"><?php echo ih($dl['created_at']); ?></td>
                <td class="small"><?php echo ih($dl['event_type']); echo $dl['is_test'] ? ' <span class="badge bg-info text-dark">test</span>' : ''; ?></td>
                <td><?php echo ih($dl['dest_name']); ?></td><td><?php echo ih($dl['customer_name'] ?? '-'); ?></td>
                <td><?php echo integration_status_badge($dl['status']); ?></td><td><?php echo (int)$dl['attempts'] . '/' . (int)$dl['max_attempts']; ?></td>
                <td class="small"><?php echo in_array($dl['status'], ['pending', 'retry'], true) ? 'next ' . ih($dl['next_attempt_at']) : ih($dl['last_error'] ?: $dl['suppress_reason']); ?></td>
                <td class="text-end"><?php if ($canReplay && in_array($dl['status'], ['dead', 'cancelled', 'succeeded', 'suppressed'], true) && !($dl['action'] === 'ticket' && in_array($dl['status'], ['succeeded', 'suppressed'], true))): ?>
                    <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?php echo ih(csrf_token()); ?>"><input type="hidden" name="action" value="replay"><input type="hidden" name="id" value="<?php echo (int)$dl['id']; ?>"><button class="btn btn-sm btn-outline-primary">Replay</button></form>
                <?php elseif ($dl['status'] === 'needs_reconciliation'): ?><a class="btn btn-sm btn-outline-danger" href="/integration_tickets.php?view=reconcile">Reconcile</a><?php endif; ?></td></tr>
        <?php endforeach; if (!$dlvRows): ?><tr><td colspan="9" class="text-muted">Nothing here.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
