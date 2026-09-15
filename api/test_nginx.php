<?php
/**
 * Validate the web server configuration.
 *
 * Backs the "Test nginx configuration" button in diagnostics.php, which has
 * been fetching this path with no file behind it.
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

if (!function_exists('exec')) {
    echo "Configuration testing is unavailable: exec() is disabled on this server.\n";
    exit;
}

/** First readable binary from a list of candidates. */
$which = static function (array $names): ?string {
    foreach ($names as $n) {
        $out = [];
        @exec('command -v ' . escapeshellarg($n) . ' 2>/dev/null', $out);
        if (!empty($out[0]) && is_executable(trim($out[0]))) {
            return trim($out[0]);
        }
    }
    return null;
};

$nginx = $which(['nginx', '/usr/sbin/nginx']);
if ($nginx !== null) {
    $out = [];
    $rc  = 0;
    @exec(escapeshellarg($nginx) . ' -t 2>&1', $out, $rc);
    echo "nginx -t (exit {$rc})\n";
    echo $out ? implode("\n", $out) . "\n" : "(no output)\n";
    if ($rc !== 0) {
        echo "\nnginx reports the configuration is not valid. Do not reload until this is fixed.\n";
    }
    exit;
}

// Not every deployment runs nginx - the install guide offers Apache too.
$apache = $which(['apachectl', 'apache2ctl', '/usr/sbin/apache2ctl']);
if ($apache !== null) {
    $out = [];
    $rc  = 0;
    @exec(escapeshellarg($apache) . ' configtest 2>&1', $out, $rc);
    echo "apachectl configtest (exit {$rc})\n";
    echo $out ? implode("\n", $out) . "\n" : "(no output)\n";
    exit;
}

echo "Neither nginx nor apachectl was found on this server, so there is no\n";
echo "configuration to test from here.\n";
