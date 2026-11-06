<?php
/**
 * Integrations: shared helpers for the operator screens.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/delivery.php';

if (!function_exists('ih')) {
    function ih($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('integration_status_badge')) {
    function integration_status_badge(string $s): string {
        $c = ['succeeded' => 'success', 'pending' => 'secondary', 'leased' => 'info', 'sending' => 'info', 'retry' => 'warning text-dark',
              'dead' => 'danger', 'needs_reconciliation' => 'danger', 'suppressed' => 'secondary', 'cancelled' => 'dark'][$s] ?? 'secondary';
        return '<span class="badge bg-' . $c . '">' . ih(str_replace('_', ' ', $s)) . '</span>';
    }
}

if (!function_exists('integration_nav')) {
    function integration_nav(string $active): void {
        $tabs = ['integrations.php' => 'Destinations', 'integration_routes.php' => 'Routing rules',
                 'integration_deliveries.php' => 'Delivery history', 'integration_tickets.php' => 'Ticket mappings'];
        echo '<ul class="nav nav-tabs mb-3">';
        foreach ($tabs as $f => $l) {
            echo '<li class="nav-item"><a class="nav-link' . ($f === $active ? ' active' : '') . '" href="/' . $f . '">' . ih($l) . '</a></li>';
        }
        echo '</ul>';
    }
}

if (!function_exists('integration_customers')) {
    /** Customers the current user may see: id => name. */
    function integration_customers(): array {
        [$w, $p] = integration_scope_sql('id');
        $s = db()->prepare("SELECT id, name FROM customers WHERE {$w} ORDER BY name");
        $s->execute($p);
        return $s->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}

if (!function_exists('integration_type_fields')) {
    /** Per-type form fields: [group, key, label, kind(text|secret|textarea), help]. */
    function integration_type_fields(string $type): array {
        return match ($type) {
            'email' => [['config', 'recipients', 'Recipients', 'textarea', 'Comma or newline separated. Uses the SMTP settings already configured.'],
                        ['config', 'subject_prefix', 'Subject prefix', 'text', '']],
            'webhook' => [['secrets', 'url', 'Webhook URL', 'secret', 'https endpoint that receives the signed JSON.'],
                          ['secrets', 'signing_secret', 'Signing secret', 'secret', 'At least 16 characters. Used for X-OPNManager-Signature.']],
            'slack' => [['secrets', 'url', 'Incoming webhook URL', 'secret', 'https://hooks.slack.com/services/...']],
            'teams' => [['secrets', 'url', 'Workflows webhook URL', 'secret', 'From a Power Automate flow "When a Teams webhook request is received". Office 365 connector URLs (webhook.office.com) no longer work.']],
            'ticket_webhook' => [
                ['secrets', 'url', 'Create URL (POST)', 'secret', ''],
                ['config', 'external_id_path', 'Ticket id JSON path', 'text', 'Where the create response holds the ticket id, e.g. data.id'],
                ['config', 'external_url_path', 'Ticket URL JSON path', 'text', 'Optional'],
                ['secrets', 'update_url', 'Update URL template', 'secret', 'May contain {external_id}. Also used for resolve/reopen/comment unless those are set.'],
                ['config', 'update_method', 'Update method', 'text', 'PATCH (default), PUT or POST'],
                ['secrets', 'resolve_url', 'Resolve URL template', 'secret', 'Optional'],
                ['secrets', 'reopen_url', 'Reopen URL template', 'secret', 'Optional'],
                ['secrets', 'comment_url', 'Comment URL template', 'secret', 'Optional'],
                ['secrets', 'lookup_url', 'Lookup-by-idempotency-key URL', 'secret', 'Optional, e.g. https://desk/api/tickets?ref={idempotency_key}. Lets an uncertain create be resolved automatically.'],
                ['config', 'lookup_id_path', 'Lookup id JSON path', 'text', 'Defaults to the ticket id path'],
                ['config', 'auth_header', 'Auth header name', 'text', 'Default Authorization'],
                ['secrets', 'auth_value', 'Auth header value', 'secret', 'e.g. Bearer <token>'],
                ['secrets', 'signing_secret', 'Request signing secret', 'secret', 'Optional: sign requests like the webhook connector'],
                ['secrets', 'inbound_secret', 'Inbound secret', 'secret', 'Required to accept webhooks from the helpdesk'],
                ['secrets', 'poll_url', 'Status poll URL template', 'secret', 'Optional, for installs that cannot receive webhooks: GET, with {external_id}'],
                ['config', 'poll_status_path', 'Poll status JSON path', 'text', ''],
                ['config', 'poll_closed_values', 'Statuses meaning closed', 'text', 'comma separated'],
                ['config', 'poll_ack_values', 'Statuses meaning acknowledged', 'text', 'comma separated'],
                ['config', 'poll_assignee_path', 'Poll assignee JSON path', 'text', 'Optional'],
            ],
            default => [],
        };
    }
}

if (!function_exists('integration_page_guard_post')) {
    /** CSRF + permission check for a POST; returns an error HTML string or ''. */
    function integration_page_guard_post(string $permission): string {
        if (!csrf_verify($_POST['csrf'] ?? '')) { return '<div class="alert alert-danger">Invalid CSRF token.</div>'; }
        if (!can($permission)) { return '<div class="alert alert-danger">Your role does not permit this action.</div>'; }
        return '';
    }
}
