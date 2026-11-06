<?php
/**
 * Connector: generic ticketing webhook.
 *
 * Talks to any helpdesk that can be driven by JSON over HTTP. It is NOT a
 * native integration with any product; the operator supplies the URLs and the
 * JSON path where the ticket id appears in the create response.
 *
 * Secrets (encrypted): url (create), update_url, resolve_url, reopen_url,
 *   comment_url, lookup_url, poll_url, auth_value, signing_secret, inbound_secret.
 *   URL templates may use {external_id}, {idempotency_key}, {incident_id}
 *   (never in the host).
 * Config: external_id_path (required), external_url_path, update_method
 *   (PATCH|PUT|POST), auth_header (name, default Authorization),
 *   lookup_id_path, poll_status_path, poll_closed_values, poll_ack_values,
 *   poll_assignee_path.
 *
 * Every request carries Idempotency-Key / X-OPNManager-Idempotency-Key. A
 * create whose outcome is unknown is never repeated blindly: the pipeline
 * looks it up by that key (lookup_url) or parks the mapping for an operator.
 *
 * The orchestration (which call, when) is in tickets.php.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/connector_base.php';
require_once __DIR__ . '/connector_webhook.php';

class OpnmgrConnectorTicketWebhook implements OpnmgrIntegrationConnector {
    public static function type(): string { return 'ticket_webhook'; }
    public static function label(): string { return 'Generic ticketing webhook'; }

    const URL_KEYS = ['url', 'update_url', 'resolve_url', 'reopen_url', 'comment_url', 'lookup_url', 'poll_url'];

    public static function validate(array $config, array $secrets, bool $allowInternal): array {
        $e = [];
        if (empty($secrets['url'])) { $e[] = 'Create URL is required'; }
        if (empty($config['external_id_path'])) { $e[] = 'External ticket id JSON path is required (e.g. data.id)'; }
        foreach (self::URL_KEYS as $k) {
            if (empty($secrets[$k])) { continue; }
            $probe = preg_replace('/\{(external_id|idempotency_key|incident_id)\}/', 'x', (string)$secrets[$k]);
            [$p, $err] = integration_validate_url($probe, $allowInternal);
            if (!$p) { $e[] = "{$k}: {$err}"; continue; }
            if (preg_match('/\{[^}]*\}/', (string)parse_url((string)$secrets[$k], PHP_URL_HOST) ?: '')
                || preg_match('#^https?://[^/]*[{}]#i', (string)$secrets[$k])) {
                $e[] = "{$k}: placeholders are not allowed in the host";
            }
        }
        if (!in_array($config['update_method'] ?? 'PATCH', ['PATCH', 'PUT', 'POST'], true)) { $e[] = 'Update method must be PATCH, PUT or POST'; }
        if (!empty($config['poll_url']) || !empty($secrets['poll_url'])) {
            if (empty($config['poll_status_path'])) { $e[] = 'Polling needs a status JSON path'; }
        }
        return $e;
    }

    public static function expand(string $tpl, array $vars): string {
        return preg_replace_callback('/\{(external_id|idempotency_key|incident_id)\}/',
            fn($m) => rawurlencode((string)($vars[$m[1]] ?? '')), $tpl);
    }

    public function supportsLookup(array $dest): bool { return !empty($dest['secrets']['lookup_url']); }

    private function headers(array $dest, string $idem, string $body): array {
        $h = ['Content-Type: application/json', 'Accept: application/json',
              'Idempotency-Key: ' . $idem, 'X-OPNManager-Idempotency-Key: ' . $idem];
        if (!empty($dest['secrets']['auth_value'])) {
            $h[] = ($dest['config']['auth_header'] ?? 'Authorization') . ': ' . $dest['secrets']['auth_value'];
        }
        if (!empty($dest['secrets']['signing_secret'])) {
            $ts = (string)time();
            $h[] = 'X-OPNManager-Timestamp: ' . $ts;
            $h[] = 'X-OPNManager-Signature: ' . integration_sign((string)$dest['secrets']['signing_secret'], $ts, $body);
        }
        return $h;
    }

    /**
     * Perform a ticket operation. $op: create|update|resolve|reopen|comment|poll|lookup
     * Returns a classified result; on success 'json' holds the decoded body.
     */
    public function call(string $op, array $dest, array $vars, ?array $payload, string $idem): array {
        $s = $dest['secrets'];
        $key = ['create' => 'url', 'update' => 'update_url', 'resolve' => 'resolve_url', 'reopen' => 'reopen_url',
                'comment' => 'comment_url', 'poll' => 'poll_url', 'lookup' => 'lookup_url'][$op];
        $tpl = $s[$key] ?? ($op === 'resolve' || $op === 'reopen' || $op === 'comment' ? ($s['update_url'] ?? '') : '');
        if ($tpl === '') { return integration_result('permanent', ['error' => "no URL configured for '{$op}'"]); }
        $url = self::expand($tpl, $vars);
        $isGet = in_array($op, ['poll', 'lookup'], true);
        $method = $isGet ? 'GET' : ($op === 'create' ? 'POST' : ($dest['config']['update_method'] ?? 'PATCH'));
        $body = $isGet ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $resp = integration_http_request($url, [
            'method' => $method, 'headers' => $this->headers($dest, $idem, (string)$body), 'body' => $body,
            'timeout' => (int)$dest['timeout_seconds'], 'allow_internal' => (bool)$dest['allow_internal'],
        ]);
        $res = integration_classify_response($resp, $op === 'create', integration_destination_secrets_list($dest));
        $res['json'] = json_decode($resp['body'], true);
        $res['raw_status'] = $resp['status'];
        return $res;
    }

    /** Extract id/url from a decoded response using the configured paths. */
    public function extract(array $dest, $json, string $idPathKey = 'external_id_path'): array {
        $id = integration_json_path($json, (string)($dest['config'][$idPathKey] ?? $dest['config']['external_id_path'] ?? ''));
        $url = integration_json_path($json, (string)($dest['config']['external_url_path'] ?? ''));
        return [
            'id'  => (is_scalar($id) && (string)$id !== '') ? substr((string)$id, 0, 191) : null,
            'url' => (is_string($url) && preg_match('#^https?://#i', $url)) ? substr($url, 0, 500) : null,
        ];
    }

    public function deliver(array $dest, array $delivery, array $envelope): array {
        require_once __DIR__ . '/tickets.php';
        return integration_ticket_deliver($this, $dest, $delivery, $envelope);
    }
}
integration_register_connector('OpnmgrConnectorTicketWebhook');
