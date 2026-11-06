<?php
/**
 * Connector: Slack incoming webhook (https://hooks.slack.com/services/...).
 * POSTs {"text": ..., "blocks": [...]}. 2xx = delivered; 429 + Retry-After is
 * honoured by the pipeline; other 4xx (invalid_payload, no_text, ...) are
 * permanent.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/connector_base.php';

class OpnmgrConnectorSlack implements OpnmgrIntegrationConnector {
    public static function type(): string { return 'slack'; }
    public static function label(): string { return 'Slack incoming webhook'; }

    public static function validate(array $config, array $secrets, bool $allowInternal): array {
        $url = (string)($secrets['url'] ?? '');
        [$p, $err] = integration_validate_url($url, $allowInternal);
        if (!$p) { return ['Slack webhook URL: ' . $err]; }
        if (!$allowInternal && !preg_match('/(^|\.)hooks\.slack(-gov)?\.com$/i', $p['host'])) {
            return ['Slack webhook URLs are on hooks.slack.com'];
        }
        return [];
    }

    public static function payload(array $env): array {
        // Slack parses &, < and > in text; escape so summaries cannot inject links or mentions.
        $title = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], integration_event_title($env));
        $fields = [];
        foreach (integration_event_facts($env) as $k => $v) {
            $fields[] = ['type' => 'mrkdwn', 'text' => '*' . $k . '*' . "\n" . str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], (string)$v)];
        }
        $blocks = [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*' . $title . '*']],
            ['type' => 'section', 'fields' => array_slice($fields, 0, 10)],
        ];
        if (!empty($env['test'])) {
            $blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => 'Test message sent by an operator from OPNManager.']]];
        }
        if (!empty($env['links']['manager'])) {
            $blocks[] = ['type' => 'actions', 'elements' => [[
                'type' => 'button', 'text' => ['type' => 'plain_text', 'text' => 'Open in OPNManager'], 'url' => $env['links']['manager'],
            ]]];
        }
        return ['text' => $title, 'blocks' => $blocks];
    }

    public function deliver(array $dest, array $delivery, array $envelope): array {
        $resp = integration_http_request((string)$dest['secrets']['url'], [
            'method' => 'POST', 'headers' => ['Content-Type: application/json'],
            'body' => json_encode(self::payload($envelope), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'timeout' => (int)$dest['timeout_seconds'], 'allow_internal' => (bool)$dest['allow_internal'],
        ]);
        return integration_classify_response($resp, false, integration_destination_secrets_list($dest));
    }
}
integration_register_connector('OpnmgrConnectorSlack');
