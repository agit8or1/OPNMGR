<?php
/**
 * Report the TLS certificate this server is presenting.
 *
 * Backs the "Test SSL certificates" button in diagnostics.php, which has been
 * fetching this path with no file behind it, so the panel printed a 404 page as
 * though it were a result.
 *
 * Returns plain text: the caller does `response.text()` and appends it.
 *
 * @since 3.27.0
 */

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo "Unauthorized\n";
    exit;
}

$host = parse_url((string) (getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? '')), PHP_URL_HOST)
     ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
$host = preg_replace('/:\d+$/', '', (string) $host);
$port = 443;

// Ask the local listener for its certificate. verify_peer is off on purpose:
// the question is "what is being served", not "does this client trust it" - a
// self-signed certificate is a legitimate answer and should be reported, not
// turned into a connection error.
$ctx = stream_context_create(['ssl' => [
    'capture_peer_cert' => true,
    'verify_peer'       => false,
    'verify_peer_name'  => false,
    'SNI_enabled'       => true,
    'peer_name'         => $host,
]]);

$client = @stream_socket_client(
    "ssl://127.0.0.1:{$port}",
    $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx
);

if ($client === false) {
    echo "Could not open a TLS connection to 127.0.0.1:{$port} ({$errstr})\n";
    echo "If this server only serves plain HTTP, that is expected.\n";
    exit;
}

$params = stream_context_get_params($client);
fclose($client);

$cert = $params['options']['ssl']['peer_certificate'] ?? null;
if (!$cert) {
    echo "Connected, but no certificate was presented.\n";
    exit;
}

$info = openssl_x509_parse($cert);
if (!is_array($info)) {
    echo "Connected, but the certificate could not be parsed.\n";
    exit;
}

$subject = $info['subject']['CN'] ?? '(no CN)';
$issuer  = $info['issuer']['CN'] ?? '(unknown issuer)';
$from    = isset($info['validFrom_time_t']) ? date('Y-m-d', (int) $info['validFrom_time_t']) : '?';
$until   = isset($info['validTo_time_t'])   ? date('Y-m-d', (int) $info['validTo_time_t'])   : '?';
$days    = isset($info['validTo_time_t'])
    ? (int) floor(((int) $info['validTo_time_t'] - time()) / 86400)
    : null;

$names = [];
if (!empty($info['extensions']['subjectAltName'])) {
    foreach (explode(',', (string) $info['extensions']['subjectAltName']) as $n) {
        $n = trim($n);
        if (str_starts_with($n, 'DNS:')) { $names[] = substr($n, 4); }
    }
}

echo "subject:    {$subject}\n";
echo "issuer:     {$issuer}\n";
echo "valid:      {$from} to {$until}\n";
if ($days !== null) {
    echo "expires in: {$days} day" . ($days === 1 ? '' : 's')
       . ($days < 0 ? '  *** ALREADY EXPIRED ***' : ($days <= 30 ? '  *** RENEW SOON ***' : '')) . "\n";
}
if ($names) {
    echo "names:      " . implode(', ', $names) . "\n";
}
$matches = in_array($host, $names, true) || $subject === $host;
echo "matches {$host}: " . ($matches ? 'yes' : 'no') . "\n";
