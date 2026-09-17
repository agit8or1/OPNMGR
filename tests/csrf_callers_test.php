<?php
/**
 * A page that posts to a CSRF-protected endpoint must send the token.
 *
 * Run with: php tests/csrf_callers_test.php
 *
 * Reported as "/api/repair_agent_ssh.php:1 Failed to load resource: 403". The
 * Repair Agent button sent only firewall_id, so csrf_verify() refused it and the
 * button had never worked. Six more on the same page were broken the same way,
 * and one differently: Reset Agent read its token from
 * document.querySelector('[name="csrf_token"]'), an element that does not exist
 * on that page - the only hidden input there is name="csrf" - so querySelector
 * returned null and .value threw before any request was made.
 *
 * Nothing caught any of this because the failure is silent from the server's
 * point of view: a refused POST looks exactly like an attack being blocked,
 * which is what csrf_verify() is for. Only the browser console showed it.
 *
 * The check is deliberately coarse - does a POST to a protected endpoint have a
 * token anywhere near it - because the precise mechanism varies (form body,
 * JSON field, header) and a stricter rule would reject working code.
 */

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) { $passed++; return; }
    $failed++;
    echo "FAIL: {$what}\n";
    if ($detail !== '') { echo "      {$detail}\n"; }
}

$root = dirname(__DIR__);

/** Endpoints that refuse a POST without a valid token. */
$protected = [];
foreach (glob($root . '/api/*.php') as $file) {
    if (str_contains((string) file_get_contents($file), 'csrf_verify')) {
        $protected[basename($file)] = true;
    }
}
check('some api endpoints verify CSRF', count($protected) > 5,
    'found ' . count($protected));

/** Every page that calls fetch(), scanned for POSTs to those endpoints. */
$pages = array_merge(glob($root . '/*.php'), glob($root . '/js/*.js'));
$broken = [];
$checked = 0;

foreach ($pages as $page) {
    $src = (string) file_get_contents($page);
    if (!str_contains($src, 'fetch(')) { continue; }
    $lines = explode("\n", $src);

    // Each fetch() call with its options object.
    if (!preg_match_all('/fetch\(\s*([\'"`])([^\'"`]+)\1[^)]*?\{(.*?)\n\s*\}\)/s', $src, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        continue;
    }

    foreach ($m as $call) {
        $url   = $call[2][0];
        $opts  = $call[3][0];
        if (!preg_match('/[\'"]POST[\'"]/', $opts)) { continue; }

        $endpoint = basename(parse_url($url, PHP_URL_PATH) ?? '');
        if (!isset($protected[$endpoint])) { continue; }

        $checked++;
        $line = substr_count(substr($src, 0, $call[0][1]), "\n") + 1;

        // Token in the options block, or attached nearby (FormData.append).
        $context = implode("\n", array_slice($lines, max(0, $line - 30), 42));
        if (stripos($opts, 'csrf') === false && stripos($context, 'csrf') === false) {
            $broken[] = sprintf('%s:%d -> %s', basename($page), $line, $endpoint);
        }
    }
}

check('POSTs to protected endpoints were found to check', $checked > 5,
    "only {$checked} found; the scanner may have stopped matching");

check('every POST to a CSRF-protected endpoint sends a token',
    $broken === [],
    $broken === [] ? '' : "a 403 the user sees only in the console:\n      " . implode("\n      ", $broken));

// The specific regressions this was reported for.
$details = (string) @file_get_contents($root . '/firewall_details.php');

check('firewall_details.php defines a page-level token', str_contains($details, "const csrfToken = '<?php echo csrf_token(); ?>'"));

check('Repair Agent sends its token',
    (bool) preg_match('/repair_agent_ssh\.php.*?csrf/s', $details),
    'this is the 403 that was reported');

check('Reset Agent no longer reads a non-existent element',
    !str_contains($details, "document.querySelector('[name=\"csrf_token\"]').value"),
    'that element is not on this page; querySelector returns null and .value throws');

check('no caller reads a csrf_token element that the page never renders',
    !preg_match('/querySelector\([\'"]\[name="csrf_token"\]/', $details)
    || str_contains($details, 'name="csrf_token"'),
    'read the token from the csrfToken constant instead');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
