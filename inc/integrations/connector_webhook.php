<?php
/**
 * Connector: generic signed webhook.
 *
 * POST application/json (the versioned envelope plus a "delivery" object).
 * Headers:
 *   X-OPNManager-Event            event type
 *   X-OPNManager-Event-Id         stable event id
 *   X-OPNManager-Delivery-Id      delivery row id
 *   X-OPNManager-Idempotency-Key  stable per (destination, event); also sent as Idempotency-Key
 *   X-OPNManager-Timestamp        unix seconds
 *   X-OPNManager-Signature        v1=<hex HMAC-SHA256(secret, timestamp . "." . raw body)>
 * Receivers must verify the signature over the raw body, reject timestamps
 * outside their window and de-duplicate on the idempotency key.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/connector_base.php';

if (!function_exists('integration_sign')) {
    /** v1 signature: HMAC-SHA256 over "<timestamp>.<body>". */
    function integration_sign(string $secret, string $timestamp, string $body): string {
        return 'v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }
}

class OpnmgrConnectorWebhook implements OpnmgrIntegrationConnector {
    public static function type(): string { return 'webhook'; }
    public static function label(): string { return 'Generic signed webhook'; }

    public static function validate(array $config, array $secrets, bool $allowInternal): array {
        $url = (string)($secrets['url'] ?? '');
        if ($url === '') { return ['Webhook URL is required']; }
        [$p, $err] = integration_validate_url($url, $allowInternal);
        $e = $p ? [] : ['Webhook URL: ' . $err];
        if (empty($secrets['signing_secret']) || strlen((string)$secrets['signing_secret']) < 16) {
            $e[] = 'A signing secret of at least 16 characters is required';
        }
        return $e;
    }

    public function deliver(array $dest, array $delivery, array $envelope): array {
        $envelope['delivery'] = ['id' => (int)$delivery['id'], 'idempotency_key' => $delivery['idempotency_key'], 'attempt' => (int)$delivery['attempts'] + 1];
        $body = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ts = (string)time();
        $headers = [
            'Content-Type: application/json',
            'X-OPNManager-Event: ' . $envelope['type'],
            'X-OPNManager-Event-Id: ' . $envelope['id'],
            'X-OPNManager-Delivery-Id: ' . $delivery['id'],
            'X-OPNManager-Idempotency-Key: ' . $delivery['idempotency_key'],
            'Idempotency-Key: ' . $delivery['idempotency_key'],
            'X-OPNManager-Timestamp: ' . $ts,
            'X-OPNManager-Signature: ' . integration_sign((string)$dest['secrets']['signing_secret'], $ts, $body),
        ];
        $resp = integration_http_request((string)$dest['secrets']['url'], [
            'method' => 'POST', 'headers' => $headers, 'body' => $body,
            'timeout' => (int)$dest['timeout_seconds'], 'allow_internal' => (bool)$dest['allow_internal'],
        ]);
        return integration_classify_response($resp, false, integration_destination_secrets_list($dest));
    }
}
integration_register_connector('OpnmgrConnectorWebhook');
