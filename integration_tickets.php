<?php
/**
 * Ticket mappings: which external ticket belongs to which incident, the
 * ticket's workflow state next to the incident's observed state, and the
 * reconciliation actions for creates whose outcome is unknown.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/alerting.php';
require_once __DIR__ . '/inc/integrations/ui.php';
require_once __DIR__ . '/inc/integrations/tickets.php';

require_permission('integration.view');

$message = '';
$canAct = can('integration.replay');
$username = $_SESSION['username'] ?? 'unknown';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $err = integration_page_guard_post('integration.replay');
    if ($err) { $message = $err; }
    else {
        $mode = ['link' => 'link', 'not_created' => 'not_created', 'abandon' => 'abandon'][$_POST['action'] ?? ''] ?? '';
        $r = $mode ? integration_ticket_reconcile((int)($_POST['id'] ?? 0), $mode, $_POST['external_id'] ?? null, $username) : ['ok' => false, 'error' => 'Unknown action'];
        $message = $r['ok'] ? '<div class="alert alert-success">Mapping reconciled.</div>' : '<div class="alert alert-warning">' . ih($r['error']) . '</div>';
    }
}

$view = $_GET['view'] ?? 'live';
[$sw, $sp] = integration_scope_sql('l.customer_id');
$cond = $view === 'reconcile' ? "l.link_state = 'needs_reconciliation'" : ($view === 'closed' ? "l.link_state IN ('closed','abandoned')" : "l.link_state IN ('creating','active','needs_reconciliation')");
$s = db()->prepare("SELECT l.*, x.name AS dest_name, i.title, i.status AS inc_status, i.owner, f.hostname FROM integration_ticket_links l
                      LEFT JOIN integration_destinations x ON x.id = l.destination_id
                      LEFT JOIN alert_incidents i ON i.id = l.incident_id
                      LEFT JOIN firewalls f ON f.id = l.firewall_id
                     WHERE {$cond} AND {$sw} ORDER BY l.id DESC LIMIT 200");
$s->execute($sp);
$links = $s->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/inc/header.php';
?>
<div class="container-fluid">
    <h4 class="mb-1"><i class="fas fa-ticket-alt me-2"></i>Ticket mappings</h4>
    <p class="text-muted small">One ongoing incident maps to one external ticket per destination. The ticket state and the incident state are tracked separately: closing a ticket does not mark a failing incident healthy.</p>
    <?php integration_nav('integration_tickets.php'); echo $message; ?>
    <div class="mb-3">
        <a class="btn btn-sm btn-<?php echo $view === 'live' ? 'secondary' : 'outline-secondary'; ?>" href="?">Live</a>
        <a class="btn btn-sm btn-<?php echo $view === 'reconcile' ? 'danger' : 'outline-danger'; ?>" href="?view=reconcile">Needs reconciliation</a>
        <a class="btn btn-sm btn-<?php echo $view === 'closed' ? 'secondary' : 'outline-secondary'; ?>" href="?view=closed">Closed</a>
    </div>
    <div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th>Incident</th><th>Destination</th><th>External ticket</th><th>Ticket state</th><th>Incident state</th><th>Mapping</th><th>Last sync</th><th></th></tr></thead><tbody>
        <?php foreach ($links as $l): ?>
            <tr><td><a href="/incidents.php?incident=<?php echo (int)$l['incident_id']; ?>">#<?php echo (int)$l['incident_id']; ?></a> <span class="small text-muted"><?php echo ih($l['hostname'] . ' ' . $l['title']); ?></span></td>
                <td><?php echo ih($l['dest_name']); ?></td>
                <td><?php echo $l['external_url'] ? '<a href="' . ih($l['external_url']) . '" target="_blank" rel="noopener noreferrer">' . ih($l['external_id']) . '</a>' : ih($l['external_id'] ?: '-'); ?>
                    <?php echo $l['external_assignee'] ? '<div class="small text-muted">assignee ' . ih($l['external_assignee']) . '</div>' : ''; ?></td>
                <td><?php echo ih($l['ticket_state']); ?>
                    <?php if ($l['closed_while_incident_active']): ?><span class="badge bg-warning text-dark" title="The ticket was closed but the condition is still present">closed while failing</span><?php endif; ?></td>
                <td><?php echo ih($l['inc_status']); ?></td>
                <td><?php echo $l['link_state'] === 'needs_reconciliation' ? '<span class="badge bg-danger">needs reconciliation</span>' : ih($l['link_state']); ?>
                    <?php echo $l['outcome_unknown'] ? '<div class="small text-muted">create attempted ' . ih($l['create_attempted_at']) . ', outcome unknown</div>' : ''; ?></td>
                <td class="small"><?php echo ih($l['last_synced_at'] ?: '-'); ?></td>
                <td class="text-end">
                    <?php if ($canAct && $l['link_state'] === 'needs_reconciliation'): ?>
                        <form method="post" class="d-flex gap-1 mb-1"><input type="hidden" name="csrf" value="<?php echo ih(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo (int)$l['id']; ?>">
                            <input class="form-control form-control-sm" name="external_id" placeholder="existing ticket id" style="width:9rem">
                            <button class="btn btn-sm btn-outline-primary" name="action" value="link">It exists: link</button></form>
                        <form method="post" class="d-inline" onsubmit="return confirm('Only confirm if you checked the helpdesk and no ticket exists for this incident.');"><input type="hidden" name="csrf" value="<?php echo ih(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo (int)$l['id']; ?>"><button class="btn btn-sm btn-outline-warning" name="action" value="not_created">Not created: retry</button></form>
                        <form method="post" class="d-inline" onsubmit="return confirm('Stop tracking this incident at this destination?');"><input type="hidden" name="csrf" value="<?php echo ih(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo (int)$l['id']; ?>"><button class="btn btn-sm btn-outline-secondary" name="action" value="abandon">Abandon</button></form>
                    <?php endif; ?></td></tr>
        <?php endforeach; if (!$links): ?><tr><td colspan="8" class="text-muted">No mappings.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
