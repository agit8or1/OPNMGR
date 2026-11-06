<?php
/**
 * Connector: Microsoft Teams via a Workflows (Power Automate) webhook
 * ("When a Teams webhook request is received").
 *
 * Office 365 Connectors (*.webhook.office.com) were retired in May 2026 and no
 * longer work; those URLs are rejected. Posts appear as the Workflows bot, so
 * no custom name or icon is sent. The payload is a message carrying one
 * Adaptive Card attachment.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/connector_base.php';

class OpnmgrConnectorTeams implements OpnmgrIntegrationConnector {
    public static function type(): string { return 'teams'; }
    public static function label(): string { return 'Microsoft Teams (Workflows webhook)'; }

    /** True for the retired Office 365 Connector URL shapes. */
    public static function is_retired_url(string $url): bool {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $path = strtolower((string)parse_url($url, PHP_URL_PATH));
        return str_ends_with($host, 'webhook.office.com') || str_ends_with($host, 'webhook.office365.com')
            || (str_ends_with($host, 'outlook.office.com') && str_contains($path, '/webhook'));
    }

    public static function validate(array $config, array $secrets, bool $allowInternal): array {
        $url = (string)($secrets['url'] ?? '');
        if (self::is_retired_url($url)) {
            return ['This is a retired Office 365 Connector URL (webhook.office.com), disabled by Microsoft in May 2026. Create a Workflows webhook ("When a Teams webhook request is received") and use its URL instead.'];
        }
        [$p, $err] = integration_validate_url($url, $allowInternal);
        return $p ? [] : ['Teams Workflows URL: ' . $err];
    }

    /** Whether the host looks like a Power Platform / Logic Apps endpoint (informational warning only). */
    public static function is_known_workflows_host(string $url): bool {
        $h = strtolower((string)parse_url($url, PHP_URL_HOST));
        return (bool)preg_match('/(\.logic\.azure\.com|\.powerplatform\.com|\.powerautomate\.com)$/', $h);
    }

    public static function payload(array $env): array {
        $body = [
            ['type' => 'TextBlock', 'size' => 'Medium', 'weight' => 'Bolder', 'wrap' => true, 'text' => integration_event_title($env)],
        ];
        if (!empty($env['test'])) {
            $body[] = ['type' => 'TextBlock', 'isSubtle' => true, 'wrap' => true, 'text' => 'Test message sent by an operator from OPNManager.'];
        }
        $facts = [];
        foreach (integration_event_facts($env) as $k => $v) { $facts[] = ['title' => $k, 'value' => (string)$v]; }
        $body[] = ['type' => 'FactSet', 'facts' => $facts];
        $card = ['$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'type' => 'AdaptiveCard', 'version' => '1.4', 'body' => $body];
        if (!empty($env['links']['manager'])) {
            $card['actions'] = [['type' => 'Action.OpenUrl', 'title' => 'Open in OPNManager', 'url' => $env['links']['manager']]];
        }
        return ['type' => 'message', 'attachments' => [[
            'contentType' => 'application/vnd.microsoft.card.adaptive', 'contentUrl' => null, 'content' => $card,
        ]]];
    }

    public function deliver(array $dest, array $delivery, array $envelope): array {
        $url = (string)$dest['secrets']['url'];
        if (self::is_retired_url($url)) {
            return integration_result('permanent', ['error' => 'retired Office 365 Connector URL; replace with a Workflows webhook']);
        }
        $resp = integration_http_request($url, [
            'method' => 'POST', 'headers' => ['Content-Type: application/json'],
            'body' => json_encode(self::payload($envelope), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'timeout' => (int)$dest['timeout_seconds'], 'allow_internal' => (bool)$dest['allow_internal'],
        ]);
        return integration_classify_response($resp, false, integration_destination_secrets_list($dest));
    }
}
integration_register_connector('OpnmgrConnectorTeams');
