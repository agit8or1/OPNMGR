<?php
/**
 * Integrations: the connector interface, the registry and shared helpers.
 *
 * A connector turns one envelope into one outbound call and reports the
 * outcome in a fixed vocabulary; everything else (retries, backoff, dead
 * letters, rate limits, idempotency) belongs to the pipeline, not to the
 * connector. New providers are added by implementing the interface and
 * calling integration_register_connector(). Shipped: email, signed webhook,
 * Slack, Teams Workflows and a generic ticketing webhook. There are no native
 * PSA connectors (Zammad, GLPI, HaloPSA, ConnectWise, Autotask); the generic
 * ticketing webhook is the extension point for those.
 *
 * Result of deliver():
 *   outcome      success | retry | permanent | ambiguous | rate_limited
 *   http         int|null
 *   error        redacted string
 *   retry_after  int|null seconds
 *   ip           resolved/pinned IP
 *   external     ['id'=>,'url'=>,'state'=>]|null  (ticketing only)
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/http.php';

if (!interface_exists('OpnmgrIntegrationConnector')) {
    interface OpnmgrIntegrationConnector {
        public static function type(): string;
        public static function label(): string;
        /** @return string[] validation errors for the given settings */
        public static function validate(array $config, array $secrets, bool $allowInternal): array;
        /** @param array $dest destination row with decoded 'config' and 'secrets' */
        public function deliver(array $dest, array $delivery, array $envelope): array;
    }
}

if (!function_exists('integration_register_connector')) {
    function integration_register_connector(string $class): void {
        $GLOBALS['OPNMGR_INTEGRATION_CONNECTORS'][$class::type()] = $class;
    }
}

if (!function_exists('integration_connector_for')) {
    function integration_connector_for(string $type): ?OpnmgrIntegrationConnector {
        integration_load_connectors();
        $c = $GLOBALS['OPNMGR_INTEGRATION_CONNECTORS'][$type] ?? null;
        return $c ? new $c() : null;
    }
}

if (!function_exists('integration_connector_types')) {
    /** @return array<string,string> type => label */
    function integration_connector_types(): array {
        integration_load_connectors();
        $o = [];
        foreach ($GLOBALS['OPNMGR_INTEGRATION_CONNECTORS'] ?? [] as $t => $c) { $o[$t] = $c::label(); }
        return $o;
    }
}

if (!function_exists('integration_load_connectors')) {
    function integration_load_connectors(): void {
        static $done = false;
        if ($done) { return; }
        $done = true;
        foreach (['email', 'webhook', 'slack', 'teams', 'ticket_webhook'] as $f) {
            require_once __DIR__ . '/connector_' . $f . '.php';
        }
    }
}

if (!function_exists('integration_result')) {
    function integration_result(string $outcome, array $extra = []): array {
        return $extra + ['outcome' => $outcome, 'http' => null, 'error' => '', 'retry_after' => null, 'ip' => '', 'external' => null];
    }
}

if (!function_exists('integration_classify_response')) {
    /**
     * Map an HTTP exchange to an outcome.
     *
     * @param bool $mutating true for calls that are unsafe to blindly repeat (ticket creation): a
     *                       failure after the request left us becomes "ambiguous" instead of "retry".
     */
    function integration_classify_response(array $resp, bool $mutating = false, array $secrets = []): array {
        $base = ['http' => $resp['status'] ?: null, 'ip' => $resp['ip'],
                 'retry_after' => $resp['retry_after']];
        $err = integration_redact_string((string)$resp['error'], $secrets);
        if ($resp['blocked']) { return integration_result('permanent', $base + ['error' => $err]); }
        if ($resp['ok']) { return integration_result('success', $base); }
        $st = $resp['status'];
        if ($st === 429) {
            return integration_result('rate_limited', $base + ['error' => 'rate limited (HTTP 429)',
                'retry_after' => $resp['retry_after'] ?? 60]);
        }
        if ($st >= 300 && $st < 400) { return integration_result('permanent', $base + ['error' => $err]); }
        if ($st >= 400 && $st < 500 && $st !== 408) {
            $snippet = integration_redact_string(substr(trim($resp['body']), 0, 120), $secrets);
            return integration_result('permanent', $base + ['error' => "HTTP {$st}" . ($snippet !== '' ? ": {$snippet}" : '')]);
        }
        // 408, 5xx, timeouts, resets, DNS/connect failures.
        $why = $st ? "HTTP {$st}" : ($err !== '' ? $err : 'no response');
        if ($mutating && $resp['sent']) {
            return integration_result('ambiguous', $base + ['error' => $why]);
        }
        return integration_result('retry', $base + ['error' => $why]);
    }
}

if (!function_exists('integration_event_title')) {
    /** One-line, already-sanitised title for chat/email/ticket subject. */
    function integration_event_title(array $env): string {
        $sev = strtoupper((string)($env['incident']['severity'] ?? $env['severity'] ?? ''));
        $label = INTEGRATION_EVENT_TYPES[$env['type']] ?? $env['type'];
        $prefix = !empty($env['test']) ? '[TEST] ' : '';
        $lead = $sev !== '' ? "[{$sev}] " : '';
        $fw = $env['firewall']['hostname'] ?? '';
        $title = $env['summary'] ?? $label;
        return $prefix . $lead . $title . ($fw !== '' && !str_contains($title, $fw) ? " ({$fw})" : '');
    }
}

if (!function_exists('integration_event_facts')) {
    /** Ordered label => value facts for human-facing messages. */
    function integration_event_facts(array $env): array {
        $f = ['Event' => INTEGRATION_EVENT_TYPES[$env['type']] ?? $env['type']];
        if (!empty($env['customer']['name'])) { $f['Customer'] = $env['customer']['name']; }
        if (!empty($env['site']['name']))     { $f['Site'] = $env['site']['name']; }
        if (!empty($env['firewall']['hostname'])) { $f['Firewall'] = $env['firewall']['hostname']; }
        if (!empty($env['platform']['family']))   { $f['Platform'] = trim($env['platform']['family'] . ' ' . ($env['platform']['edition'] ?? '')); }
        if (!empty($env['incident']['severity'])) { $f['Severity'] = $env['incident']['severity']; }
        if (!empty($env['incident']['status']))   { $f['Status'] = $env['incident']['status']; }
        $f['Time (UTC)'] = $env['occurred_at'];
        return $f;
    }
}

if (!function_exists('integration_destination_secrets_list')) {
    /** Every secret string of a destination, for exact-match redaction of errors. */
    function integration_destination_secrets_list(array $dest): array {
        $o = [];
        foreach (($dest['secrets'] ?? []) as $v) { if (is_string($v) && $v !== '') { $o[] = $v; } }
        return $o;
    }
}
