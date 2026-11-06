<?php
/**
 * Connector: email, through the existing SMTP transport (settings smtp_*).
 *
 * Duplicate protection: the legacy pipeline (cron/evaluate_alerts.php) mails
 * admin users directly. While legacy owns email (setting
 * integrations_legacy_email_owner = legacy, the default) this connector drops
 * any recipient the legacy pipeline would already have mailed for the same
 * incident event, so nobody gets two copies. Set the owner to "integrations"
 * to migrate: the legacy mail is then skipped and this connector sends.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/connector_base.php';

if (!function_exists('integration_legacy_email_owner')) {
    function integration_legacy_email_owner(): string {
        return integration_setting('integrations_legacy_email_owner', 'legacy') === 'integrations' ? 'integrations' : 'legacy';
    }
}

if (!function_exists('integration_legacy_email_recipients')) {
    /**
     * Addresses the legacy pipeline mails for a severity right now (lower-cased).
     * Empty if legacy email is switched off, so that case never suppresses us.
     */
    function integration_legacy_email_recipients(string $severity): array {
        try {
            $rows = db()->query("SELECT setting_name, setting_value FROM alert_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) { return []; }
        $on = fn($k) => in_array(strtolower((string)($rows[$k] ?? '')), ['1', 'true', 'on', 'yes'], true);
        if (!$on('email_enabled') || !$on("alerts_{$severity}_enabled")) { return []; }
        $out = [];
        try {
            foreach (db()->query("SELECT email, alert_levels FROM users WHERE role='admin' AND email IS NOT NULL AND email != ''") as $u) {
                $levels = explode(',', $u['alert_levels'] ?: 'warning,critical');
                if (in_array($severity, $levels, true)) { $out[] = strtolower(trim($u['email'])); }
            }
        } catch (Throwable $e) { return []; }
        return $out;
    }
}

class OpnmgrConnectorEmail implements OpnmgrIntegrationConnector {
    /** Event types the legacy pipeline also emails for (open-state alerting). */
    const LEGACY_OVERLAP = ['incident.opened', 'incident.reopened', 'incident.severity_changed', 'incident.escalated'];

    public static function type(): string { return 'email'; }
    public static function label(): string { return 'Email (existing SMTP transport)'; }

    public static function recipients(array $config): array {
        $list = preg_split('/[\s,;]+/', (string)($config['recipients'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_map('strtolower', $list)));
    }

    public static function validate(array $config, array $secrets, bool $allowInternal): array {
        $r = self::recipients($config);
        if (!$r) { return ['At least one recipient is required']; }
        $e = [];
        foreach ($r as $a) { if (!filter_var($a, FILTER_VALIDATE_EMAIL)) { $e[] = "Invalid email address: {$a}"; } }
        return $e;
    }

    public function deliver(array $dest, array $delivery, array $envelope): array {
        $to = self::recipients($dest['config']);
        $skipped = [];
        if (integration_legacy_email_owner() === 'legacy' && in_array($envelope['type'], self::LEGACY_OVERLAP, true) && empty($envelope['test'])) {
            $legacy = integration_legacy_email_recipients((string)($envelope['incident']['severity'] ?? ''));
            $skipped = array_values(array_intersect($to, $legacy));
            $to = array_values(array_diff($to, $legacy));
        }
        if (!$to) {
            return integration_result('success', ['error' => 'not sent: all recipients are already mailed by the legacy alert pipeline', 'skipped' => true]);
        }
        $title = integration_event_title($envelope);
        $subject = trim(($dest['config']['subject_prefix'] ?? '') . ' ' . $title);
        $rows = '';
        foreach (integration_event_facts($envelope) as $k => $v) {
            $rows .= '<tr><td><strong>' . htmlspecialchars($k) . '</strong></td><td>' . htmlspecialchars((string)$v) . '</td></tr>';
        }
        $link = $envelope['links']['manager'] ?? '';
        $html = '<h3>' . htmlspecialchars($title) . '</h3>'
              . (!empty($envelope['test']) ? '<p><em>This is a test message sent by an operator from OPNManager.</em></p>' : '')
              . '<table cellpadding="4">' . $rows . '</table>'
              . ($link !== '' ? '<p><a href="' . htmlspecialchars($link) . '">Open in OPNManager</a></p>' : '');
        $text = $title . "\n" . ($link !== '' ? $link : '');

        $sender = $GLOBALS['OPNMGR_INTEGRATION_EMAIL_SENDER'] ?? null;
        $errors = [];
        foreach ($to as $addr) {
            try {
                $res = $sender ? $sender($addr, $subject, $html, $text) : self::smtp($addr, $subject, $html);
                if (empty($res['success'])) { $errors[] = $res['error'] ?? 'send failed'; }
            } catch (Throwable $e) { $errors[] = $e->getMessage(); }
        }
        if ($errors) {
            return integration_result('retry', ['error' => integration_redact_string(implode('; ', array_unique($errors)))]);
        }
        return integration_result('success', ['error' => $skipped ? 'skipped (legacy-owned): ' . implode(',', $skipped) : '']);
    }

    private static function smtp(string $to, string $subject, string $html): array {
        require_once __DIR__ . '/../smtp_mailer.php';
        $s = db()->query("SELECT `name`,`value` FROM settings WHERE `name` LIKE 'smtp_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (['smtp_host', 'smtp_port'] as $k) { if (empty($s[$k])) { return ['success' => false, 'error' => "SMTP setting {$k} missing"]; } }
        $from = $s['smtp_from_email'] ?? ($s['smtp_username'] ?? '');
        if ($from === '') { return ['success' => false, 'error' => 'No from address configured']; }
        return send_smtp_email($s, $to, $subject, $html, $from, $s['smtp_from_name'] ?? 'OpnMgr') ?: ['success' => false, 'error' => 'no result'];
    }
}
integration_register_connector('OpnmgrConnectorEmail');
