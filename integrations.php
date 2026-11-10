<?php
/**
 * Integration destinations: email, signed webhook, Slack, Teams (Workflows)
 * and generic ticketing webhook. Configuration is admin-only
 * (integration.manage); operators with integration.view may look.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $err = !csrf_verify($_POST['csrf'] ?? '') ? '<div class="alert alert-danger">Invalid CSRF token.</div>'
         : (!can('integration.manage') ? '<div class="alert alert-danger">Your role does not permit this action.</div>' : '');
    $act = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($err) {
        $message = $err;
    } elseif ($act === 'save') {
        $in = $_POST;
        $in['config'] = (array)($_POST['config'] ?? []);
        $in['secrets'] = (array)($_POST['secrets'] ?? []);
        $in['disabled'] = empty($_POST['enabled']);
        $r = integration_destination_save($in, $id ?: null, $username);
        $message = $r['ok'] ? '<div class="alert alert-success">Destination saved.</div>'
                            : '<div class="alert alert-danger">' . ih($r['error']) . '</div>';
        if ($r['ok']) { $id = $r['id']; $_GET['edit'] = $id; }
    } elseif ($act === 'delete') {
        $message = integration_destination_delete($id, $username)
            ? '<div class="alert alert-success">Destination and its routes deleted; pending deliveries cancelled.</div>'
            : '<div class="alert alert-warning">Destination not found.</div>';
    } elseif ($act === 'accept_ip') {
        integration_destination_accept_ip($id, $username);
        $message = '<div class="alert alert-success">New endpoint address accepted.</div>';
    } elseif ($act === 'legacy_owner') {
        $owner = ($_POST['owner'] ?? '') === 'integrations' ? 'integrations' : 'legacy';
        db()->prepare('INSERT INTO settings (`name`,`value`) VALUES ("integrations_legacy_email_owner",?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)')->execute([$owner]);
        audit_log('integration.legacy_email_owner', ['message' => "Incident email owner set to {$owner}"]);
        $message = '<div class="alert alert-success">Incident email is now sent by: ' . ih($owner === 'legacy' ? 'the existing alert mailer' : 'integration destinations') . '.</div>';
    } elseif ($act === 'test') {
        // Only ever reached by pressing the Test button.
        $d = integration_destination_load($id);
        if ($d && integration_can_see_customer($d['customer_id'] !== null ? (int)$d['customer_id'] : null)) {
            $t = integration_send_test($id, $username);
            $message = $t['ok'] ? '<div class="alert alert-success">Test message delivered (it is labelled as a test).</div>'
                                : '<div class="alert alert-danger">Test failed: ' . ih($t['error']) . '</div>';
        }
    }
}

$destinations = integration_destination_list();
$customers = integration_customers();
$types = integration_connector_types();
$edit = null;
if (!empty($_GET['edit'])) { $edit = integration_destination_load((int)$_GET['edit']); }
if ($edit && !integration_can_see_customer($edit['customer_id'] !== null ? (int)$edit['customer_id'] : null)) { $edit = null; }
$newType = isset($types[$_GET['new'] ?? '']) ? $_GET['new'] : null;
$formType = $edit['type'] ?? $newType;
$legacyOwner = integration_legacy_email_owner();
$masked = $edit ? integration_destination_masked($edit) : [];
$serverUrl = function_exists('opnmgr_server_url') ? opnmgr_server_url() : '';

include __DIR__ . '/inc/header.php';
?>
<div class="container-fluid">
    <h4 class="mb-1"><i class="fas fa-plug me-2"></i>Integrations</h4>
    <p class="text-muted small">Send incident and report events to email, chat and ticketing systems. Nothing is sent until a destination and a routing rule exist.</p>
    <?php integration_nav('integrations.php'); echo $message; ?>
    <?php if (!opnmgr_crypto_available()): ?>
        <div class="alert alert-warning"><?= htmlspecialchars(opnmgr_crypto_status()['message'], ENT_QUOTES) ?> Destinations cannot be saved until this is fixed.</div>
    <?php endif; ?>

    <div class="card mb-3"><div class="card-body">
        <h6>Incident email ownership</h6>
        <p class="small text-muted mb-2">The existing alert mailer emails opted-in admin users. To avoid duplicates, either it keeps doing that (an email destination skips anyone it already mails) or you hand incident email to integration destinations (the existing mailer then stops).</p>
        <form method="post" class="d-flex gap-2 align-items-center">
            <input type="hidden" name="csrf" value="<?php echo ih(csrf_token()); ?>"><input type="hidden" name="action" value="legacy_owner">
            <select name="owner" class="form-select form-select-sm w-auto" <?php echo $canManage ? '' : 'disabled'; ?>>
                <option value="legacy" <?php echo $legacyOwner === 'legacy' ? 'selected' : ''; ?>>Existing alert mailer (default)</option>
                <option value="integrations" <?php echo $legacyOwner === 'integrations' ? 'selected' : ''; ?>>Integration destinations</option>
            </select>
            <?php if ($canManage): ?><button class="btn btn-sm btn-outline-primary">Save</button><?php endif; ?>
        </form>
    </div></div>

    <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between mb-2">
            <h6 class="mb-0">Destinations</h6>
            <?php if ($canManage): ?><div class="dropdown"><button class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown">Add destination</button>
                <ul class="dropdown-menu"><?php foreach ($types as $t => $l): ?><li><a class="dropdown-item" href="?new=<?php echo ih($t); ?>"><?php echo ih($l); ?></a></li><?php endforeach; ?></ul></div><?php endif; ?>
        </div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>Name</th><th>Type</th><th>Customer</th><th>Status</th><th>Endpoint</th><th></th></tr></thead><tbody>
            <?php foreach ($destinations as $d): ?>
                <tr>
                    <td><?php echo ih($d['name']); ?></td>
                    <td><?php echo ih($types[$d['type']] ?? $d['type']); ?></td>
                    <td><?php echo $d['customer_id'] ? ih($customers[$d['customer_id']] ?? '#' . $d['customer_id']) : '<span class="text-muted">all</span>'; ?></td>
                    <td><?php echo $d['enabled'] ? '<span class="badge bg-success">enabled</span>' : '<span class="badge bg-secondary">disabled</span>'; ?>
                        <?php if ($d['paused_until'] && strtotime($d['paused_until']) > time()): ?><span class="badge bg-warning text-dark">rate-limited until <?php echo ih($d['paused_until']); ?></span><?php endif; ?>
                        <?php if ($d['allow_internal']): ?><span class="badge bg-info text-dark">internal allowed</span><?php endif; ?></td>
                    <td class="small">
                        <?php echo $d['last_ip'] ? ih($d['last_ip']) : '<span class="text-muted">not contacted</span>'; ?>
                        <?php if ($d['ip_change_pending']): ?>
                            <span class="badge bg-danger">address changed</span>
                            <?php if ($canManage): ?><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?php echo ih(csrf_token()); ?>"><input type="hidden" name="action" value="accept_ip"><input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>"><button class="btn btn-sm btn-link p-0">accept</button></form><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <?php if ($canManage): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="?edit=<?php echo (int)$d['id']; ?>">Edit</a>
                            <form method="post" class="d-inline" onsubmit="return confirm('Send a clearly labelled TEST message to this destination now?');">
                                <input type="hidden" name="csrf" value="<?php echo ih(csrf_token()); ?>"><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>">
                                <button class="btn btn-sm btn-outline-info">Send test</button></form>
                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this destination, its routes and cancel pending deliveries?');">
                                <input type="hidden" name="csrf" value="<?php echo ih(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger">Delete</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; if (!$destinations): ?><tr><td colspan="6" class="text-muted">No destinations yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div></div>

    <?php if ($canManage && $formType): ?>
    <div class="card mb-3"><div class="card-body">
        <h6><?php echo $edit ? 'Edit' : 'New'; ?> destination: <?php echo ih($types[$formType]); ?></h6>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?php echo ih(csrf_token()); ?>"><input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>"><input type="hidden" name="type" value="<?php echo ih($formType); ?>">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Name</label><input class="form-control" name="name" required maxlength="100" value="<?php echo ih($edit['name'] ?? ''); ?>"></div>
                <div class="col-md-4"><label class="form-label">Limit to customer</label>
                    <select name="customer_id" class="form-select"><option value="">All customers</option>
                    <?php foreach ($customers as $cid => $cn): ?><option value="<?php echo (int)$cid; ?>" <?php echo ($edit['customer_id'] ?? null) == $cid ? 'selected' : ''; ?>><?php echo ih($cn); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4 pt-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="en" <?php echo !$edit || $edit['enabled'] ? 'checked' : ''; ?>><label class="form-check-label" for="en">Enabled</label></div></div>

                <?php foreach (integration_type_fields($formType) as [$grp, $key, $label, $kind, $help]):
                    $val = $grp === 'config' ? ($edit['config'][$key] ?? '') : ''; ?>
                    <div class="col-md-6"><label class="form-label"><?php echo ih($label); ?></label>
                        <?php if ($kind === 'textarea'): ?><textarea class="form-control" rows="2" name="<?php echo $grp; ?>[<?php echo $key; ?>]"><?php echo ih($val); ?></textarea>
                        <?php else: ?><input class="form-control" type="<?php echo $kind === 'secret' ? 'password' : 'text'; ?>" name="<?php echo $grp; ?>[<?php echo $key; ?>]"
                            value="<?php echo $kind === 'secret' ? '' : ih($val); ?>" autocomplete="new-password"
                            placeholder="<?php echo $kind === 'secret' && isset($masked[$key]) ? 'stored: ' . ih($masked[$key]) . ' (leave blank to keep)' : ''; ?>"><?php endif; ?>
                        <?php if ($help): ?><div class="form-text"><?php echo ih($help); ?></div><?php endif; ?></div>
                <?php endforeach; ?>

                <div class="col-12"><hr><h6 class="mb-0">Delivery and safety</h6></div>
                <div class="col-md-3"><label class="form-label">Max attempts</label><input type="number" min="1" max="20" class="form-control" name="max_attempts" value="<?php echo (int)($edit['max_attempts'] ?? 6); ?>"></div>
                <div class="col-md-3"><label class="form-label">Concurrent deliveries</label><input type="number" min="1" max="10" class="form-control" name="concurrency_limit" value="<?php echo (int)($edit['concurrency_limit'] ?? 2); ?>"></div>
                <div class="col-md-3"><label class="form-label">Timeout (s)</label><input type="number" min="2" max="60" class="form-control" name="timeout_seconds" value="<?php echo (int)($edit['timeout_seconds'] ?? 10); ?>"></div>
                <div class="col-md-3"><label class="form-label">If the endpoint address changes</label>
                    <select name="ip_change_policy" class="form-select"><option value="warn" <?php echo ($edit['ip_change_policy'] ?? 'warn') === 'warn' ? 'selected' : ''; ?>>Warn and continue</option><option value="block" <?php echo ($edit['ip_change_policy'] ?? '') === 'block' ? 'selected' : ''; ?>>Hold until accepted</option></select></div>
                <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="allow_internal" value="1" id="ai" <?php echo !empty($edit['allow_internal']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="ai">Allow internal endpoint (private, loopback or link-local addresses; plain http). Only for an internal helpdesk you trust.</label></div></div>

                <?php if ($formType === 'ticket_webhook'): ?>
                <div class="col-12"><hr><h6 class="mb-0">Ticket policy</h6></div>
                <div class="col-md-3"><label class="form-label">When the incident resolves</label><select name="on_resolve" class="form-select">
                    <?php foreach (['resolve' => 'Resolve the ticket', 'comment' => 'Comment only', 'none' => 'Do nothing'] as $v => $l): ?><option value="<?php echo $v; ?>" <?php echo ($edit['on_resolve'] ?? 'resolve') === $v ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="form-label">When the condition recurs</label><select name="on_recurrence" class="form-select">
                    <?php foreach (['new_ticket' => 'Open a new ticket', 'reopen' => 'Reopen the previous ticket'] as $v => $l): ?><option value="<?php echo $v; ?>" <?php echo ($edit['on_recurrence'] ?? 'new_ticket') === $v ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="form-label">Reopen window (min)</label><input type="number" class="form-control" name="reopen_window_minutes" value="<?php echo (int)($edit['reopen_window_minutes'] ?? 1440); ?>"></div>
                <div class="col-md-3"><label class="form-label">Ticket closed while still failing</label><select name="on_external_close" class="form-select">
                    <?php foreach (['note' => 'Record it, leave ticket closed', 'reopen_ticket' => 'Reopen the ticket (max 1/hour)'] as $v => $l): ?><option value="<?php echo $v; ?>" <?php echo ($edit['on_external_close'] ?? 'note') === $v ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><span class="form-label">Accept from the helpdesk (inbound):</span>
                    <?php foreach (['inbound_ack_enabled' => 'acknowledgements', 'inbound_comment_enabled' => 'comments as notes', 'inbound_assign_enabled' => 'assignment as owner'] as $k => $l): ?>
                        <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="<?php echo $k; ?>" value="1" <?php echo !empty($edit[$k]) ? 'checked' : ''; ?>><label class="form-check-label"><?php echo $l; ?></label></div><?php endforeach; ?>
                    <div class="form-text">Closing a ticket never marks a failing incident healthy. Inbound webhook URL: <code><?php echo ih(rtrim($serverUrl, '/') . '/api/integration_inbound.php?destination=' . (int)($edit['id'] ?? 0)); ?></code> (signed with the inbound secret).</div></div>
                <?php endif; ?>
            </div>
            <div class="mt-3"><button class="btn btn-primary">Save destination</button> <a class="btn btn-link" href="/integrations.php">Cancel</a></div>
        </form>
    </div></div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
