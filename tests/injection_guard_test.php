<?php
/**
 * Two properties that currently hold, kept holding.
 *
 * Unlike most suites added this session, this one did not come from a bug. The
 * audit that produced it found nothing: every query reaching user input uses a
 * prepared statement with placeholders, and every field an agent supplies is
 * escaped wherever it is rendered. That is worth pinning precisely because it
 * is easy to lose one line at a time.
 *
 * Both checks exist because the data crosses a trust boundary. A firewall's
 * check-in payload is not trusted input: the box is owned by a customer, may be
 * compromised, and what it reports is displayed in an administrator's browser.
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
exec('cd ' . escapeshellarg($root) . ' && git ls-files --cached --others --exclude-standard "*.php" 2>/dev/null', $files, $st);
check('PHP files were listed', $st === 0 && count($files) > 100, count($files) . ' found');

$strip = fn(string $s): string => preg_replace(['~//[^\n]*~', '~/\*.*?\*/~s'], '', $s);

// ---------------------------------------------------------------------------
// 1. No query built by interpolation, outside a reviewed few.
// ---------------------------------------------------------------------------
//
// prepare() with placeholders is the rule. These five interpolate, and each was
// read: the value is either an int clamped to a range, a ternary between two
// literal strings, or a table name from a hardcoded list. Removing an entry is
// fine; adding one means someone should look at it.
$reviewed = [
    'inc/bulk_ops.php',          // LIMIT {$limit} - int, clamped to 1..200
    'inc/customers.php',         // {$where} - ternary between two literals
    'inc/maintenance.php',       // {$where} - ternary between two literals
    'scripts/encrypt_secrets.php', // table/column from hardcoded call sites
    'scripts/demo_fixture.php',  // table names from a hardcoded array
];

$interpolated = [];
$sqlExamined = 0;

foreach ($files as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path) || strpos($rel, 'tests/') === 0) { continue; }

    $code = $strip((string) file_get_contents($path));
    if (!preg_match_all('/->\s*(query|exec)\s*\(\s*(["\'])(.*?)\2/s', $code, $m, PREG_SET_ORDER)) { continue; }

    foreach ($m as $hit) {
        $sqlExamined++;
        $sql = $hit[3];
        if (strpos($sql, '$') === false) { continue; }
        if (!preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $sql)) { continue; }
        if (in_array($rel, $reviewed, true)) { continue; }
        $interpolated[] = $rel . ': ' . trim(preg_replace('/\s+/', ' ', substr($sql, 0, 90)));
    }
}

check('direct queries were found to examine', $sqlExamined > 20, "{$sqlExamined} examined");
check('no unreviewed query is built by interpolation', $interpolated === [],
    implode("\n      ", array_unique($interpolated)));

// ---------------------------------------------------------------------------
// 2. Nothing a firewall reports is rendered unescaped.
// ---------------------------------------------------------------------------
//
// These columns are written straight from the agent's check-in payload. The
// firewall is a customer's box: if one is compromised, what it reports must not
// become script in an administrator's browser.
$agentFields = [
    'version', 'uptime', 'wan_ip', 'lan_ip', 'ipv6_address', 'wan_gateway',
    'wan_netmask', 'wan_dns_primary', 'wan_dns_secondary', 'lan_network',
    'lan_netmask', 'wan_interfaces', 'wan_groups', 'opnsense_version',
    'agent_version',
];

// about.php renders the in-app changelog array from inc/version.php, and
// generate_pdf.php renders platform_versions - neither is agent-supplied, and
// both use a key that happens to be named 'version'.
$notAgentData = ['about.php', 'generate_pdf.php'];

$unescaped = [];
$outExamined = 0;

foreach ($files as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) { continue; }
    if (preg_match('#^(tests/|scripts/)#', $rel) || in_array($rel, $notAgentData, true)) { continue; }

    foreach (explode("\n", (string) file_get_contents($path)) as $i => $line) {
        if (!preg_match('/<\?=|echo\b/', $line)) { continue; }
        $outExamined++;
        if (preg_match('/htmlspecialchars|htmlentities|json_encode|\bh\(/', $line)) { continue; }

        foreach ($agentFields as $f) {
            if (preg_match('/\$\w+\[[\'"]' . preg_quote($f, '/') . '[\'"]\]/', $line)) {
                $unescaped[] = $rel . ':' . ($i + 1) . ' ' . trim(substr($line, 0, 80));
                break;
            }
        }
    }
}

check('output statements were found to examine', $outExamined > 500, "{$outExamined} examined");
check('no agent-supplied field is rendered unescaped', $unescaped === [],
    implode("\n      ", array_slice($unescaped, 0, 8)));

// The scan must be able to see a violation, or it proves nothing.
$probe = '<?php echo $fw[\'wan_ip\']; ?>';
$sees = preg_match('/\$\w+\[[\'"]wan_ip[\'"]\]/', $probe) === 1
     && !preg_match('/htmlspecialchars/', $probe);
check('the output scan recognises a violation', $sees);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
