<?php
/**
 * Every function a shipped file calls must be defined somewhere.
 *
 * PHP resolves calls at runtime, so a call to a function that does not exist is
 * a fatal that only appears when that line is reached. Several had been sitting
 * in live paths for a long time:
 *
 *   - verify2fa.php called clear2FA() on a *correct* code, so two-factor
 *     verification fataled for anyone who typed the right number, while a wrong
 *     one returned a tidy "Invalid code";
 *   - api/manage_tasks.php gated on check_authentication(), fatal on every
 *     request, which is why the scheduled jobs page could never list anything;
 *   - five endpoints and two cron jobs called write_log() or log_action(),
 *     neither of which exists - inc/logging.php provides log_event().
 *
 * A call guarded by function_exists() is fine: that is a deliberate optional
 * dependency, and the suite treats it as such.
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

// Only files that ship. Untracked scratch is not our problem, and scanning it
// produced noise that hid the real findings.
exec('cd ' . escapeshellarg($root) . ' && git ls-files --cached --others --exclude-standard "*.php" 2>/dev/null', $tracked, $status);
check('tracked PHP files were listed', $status === 0 && count($tracked) > 100,
    'got ' . count($tracked));

$defined = [];
$calls   = [];

foreach ($tracked as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) { continue; }
    if (strpos($rel, 'vendor/') === 0) { continue; }

    $src = (string) file_get_contents($path);
    $t   = @token_get_all($src);
    if (!$t) { continue; }

    $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        $tok = $t[$i];
        if (!is_array($tok)) { continue; }

        if ($tok[0] === T_FUNCTION) {
            for ($j = $i + 1; $j < $n && $j < $i + 4; $j++) {
                if (is_array($t[$j]) && $t[$j][0] === T_STRING) {
                    $defined[strtolower($t[$j][1])] = true;
                    break;
                }
                if (is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) { continue; }
                if ($t[$j] === '&') { continue; }
                break;
            }
            continue;
        }

        if ($tok[0] !== T_STRING) { continue; }

        // Followed by '(' - otherwise it is a constant or a type.
        $k = $i + 1;
        while ($k < $n && is_array($t[$k]) && $t[$k][0] === T_WHITESPACE) { $k++; }
        if (!($k < $n && $t[$k] === '(')) { continue; }

        // Not a method, a static call, a definition or a constructor.
        $b = $i - 1;
        while ($b >= 0 && is_array($t[$b]) && $t[$b][0] === T_WHITESPACE) { $b--; }
        if ($b >= 0 && is_array($t[$b])) {
            $bt = $t[$b][0];
            if (in_array($bt, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) { continue; }
            if (defined('T_NULLSAFE_OBJECT_OPERATOR') && $bt === T_NULLSAFE_OBJECT_OPERATOR) { continue; }
        }

        $name = strtolower($tok[1]);

        // function_exists('foo') declares foo optional; record it as allowed.
        $guarded = false;
        for ($g = max(0, $i - 60); $g < $i; $g++) {
            if (is_array($t[$g]) && $t[$g][0] === T_STRING
                && strtolower($t[$g][1]) === 'function_exists') {
                for ($h = $g; $h < $i && $h < $g + 6; $h++) {
                    if (is_array($t[$h]) && $t[$h][0] === T_CONSTANT_ENCAPSED_STRING
                        && strtolower(trim($t[$h][1], "'\"")) === $name) {
                        $guarded = true;
                        break 2;
                    }
                }
            }
        }
        if ($guarded) { continue; }

        $calls[$name][] = $rel . ':' . $tok[2];
    }
}

check('definitions were found', count($defined) > 200, count($defined) . ' found');
check('calls were found', count($calls) > 200, count($calls) . ' distinct names');

$builtin = array_flip(array_map('strtolower', get_defined_functions()['internal']));

// Language constructs the tokenizer reports as T_STRING calls.
$constructs = array_flip([
    'isset', 'unset', 'empty', 'list', 'array', 'echo', 'print', 'exit', 'die',
    'include', 'include_once', 'require', 'require_once', 'eval', 'match', 'fn',
    'new', 'clone', 'return', 'if', 'for', 'foreach', 'while', 'switch', 'catch',
    'function', 'use', 'and', 'or', 'xor', 'elseif', 'else', 'do', 'try',
    'declare', 'yield', 'global', 'static', 'int', 'float', 'string', 'bool',
    'void', 'callable', 'iterable', 'object', 'mixed', 'never', 'null', 'true', 'false',
]);

// Functions provided only by a particular SAPI or extension. Each is called
// behind a function_exists() guard at its call site; listed so the intent is
// explicit rather than silently tolerated.
$sapi = array_flip(['getallheaders', 'apache_setenv', 'apache_request_headers']);

$missing = [];
foreach ($calls as $name => $where) {
    if (isset($defined[$name]) || isset($builtin[$name])
        || isset($constructs[$name]) || isset($sapi[$name])) {
        continue;
    }
    $missing[$name] = array_values(array_unique($where));
}

ksort($missing);
$detail = [];
foreach ($missing as $name => $where) {
    $detail[] = $name . '() at ' . implode(', ', array_slice($where, 0, 3));
}

check('no shipped file calls an undefined function', $missing === [],
    implode("\n      ", $detail));

// The scan must not be vacuous: it has to see a function it knows exists.
check('the scanner sees real definitions', isset($defined['log_event']));
check('the scanner resolves builtins', isset($builtin['count']));

// ---------------------------------------------------------------------------
// Reaching a firewall must not depend on that firewall's certificate.
// ---------------------------------------------------------------------------
//
// Managed firewalls routinely carry self-signed or expired certificates. That
// is something to report, not a reason to lose the ability to manage the box,
// so no connection path may verify the peer.

$connectors = ['tunnel_proxy.php', 'inc/opnsense_api.php', 'scripts/create_opnmgr_alias.php'];
foreach ($connectors as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) { continue; }
    $src = (string) file_get_contents($path);

    check("{$rel} does not require a valid firewall certificate",
        !preg_match('/CURLOPT_SSL_VERIFYPEER\s*(=>|,)\s*true/i', $src)
        && !preg_match('/verify_ssl\s*=\s*true/i', $src),
        'a firewall with an expired certificate must still be reachable');
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
