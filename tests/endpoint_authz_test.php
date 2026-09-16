<?php
/**
 * Every endpoint that reads or changes managed data must decide who is asking.
 *
 * api/manage_ssh_keys.php gated on check_authentication(), a function defined
 * nowhere, so the expression was always false. That broke in both directions at
 * once: POST failed closed and had never worked, while GET was not gated at all
 * and returned SSH key fingerprints, types, bit sizes and timestamps for any
 * firewall id to an unauthenticated caller. api/updates/download.php took an
 * instance_id it never validated and returned files and SQL to apply.
 *
 * Agent endpoints authenticate differently - a hardware id and api key in the
 * payload, not a session - so they satisfy this by their own means.
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
exec('cd ' . escapeshellarg($root) . ' && git ls-files "api/*.php" 2>/dev/null', $endpoints, $status);
check('api endpoints were listed', $status === 0 && count($endpoints) > 50,
    'got ' . count($endpoints));

// A session check, the RBAC helper, or an agent credential.
// A direct $_SESSION['user_id'] test is the older idiom and is still a real
// check; several endpoints use it rather than the helpers.
$session = '/\b(requireLogin|requireAdmin|require_permission|isLoggedIn)\b'
         . '|\$_SESSION\s*\[\s*[\'"]user_id[\'"]\s*\]/';
// authenticateAgentRequest() is the agent entry point; enrolment is gated by a
// single-use token rather than a session, by design.
// Enrolment is gated by a single-use token checked against enrollment_tokens
// with an expiry, rather than by a session - that is the point of enrolment.
$agent   = '/\b(authenticateAgentRequest|agent_verify_signature|verify_agent'
         . '|agent_authenticate|validate_agent)\b'
         . '|FROM\s+enrollment_tokens/i';
$touches = '/\b(INSERT\s+INTO|UPDATE\s+\w|DELETE\s+FROM|db\(\)->(?:query|prepare)|queue_firewall_command|queue_command)\b/i';

$unguarded = [];
$examined  = 0;

foreach ($endpoints as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) { continue; }

    $src  = (string) file_get_contents($path);
    // Comments must not count as a check - the files describe these bugs.
    $code = preg_replace(['~//[^\n]*~', '~/\*.*?\*/~s'], '', $src);

    if (!preg_match($touches, $code)) { continue; }   // touches no data
    $examined++;

    if (preg_match($session, $code) || preg_match($agent, $code)) { continue; }
    $unguarded[] = $rel;
}

// Guard against the guard being vacuous.
check('endpoints touching data were found', $examined > 30, "{$examined} examined");

sort($unguarded);
check('every data-touching endpoint authenticates its caller',
    $unguarded === [],
    implode("\n      ", $unguarded));

// ---------------------------------------------------------------------------
// The two that were actually open, pinned by name.
// ---------------------------------------------------------------------------

$ssh = (string) @file_get_contents($root . '/api/manage_ssh_keys.php');
if ($ssh !== '') {
    $code = preg_replace(['~//[^\n]*~', '~/\*.*?\*/~s'], '', $ssh);
    check('manage_ssh_keys.php no longer gates on check_authentication()',
        strpos($code, 'check_authentication(') === false,
        'it is defined nowhere, so the expression was always false');
    check('reading key metadata requires firewall.view',
        strpos($code, "require_permission('firewall.view')") !== false,
        'this returned SSH fingerprints to anyone who could reach the server');
    check('changing a key requires firewall.manage',
        strpos($code, "require_permission('firewall.manage')") !== false);
}

$dl = (string) @file_get_contents($root . '/api/updates/download.php');
if ($dl !== '') {
    $code = preg_replace(['~//[^\n]*~', '~/\*.*?\*/~s'], '', $dl);
    check('updates/download.php requires authorisation',
        strpos($code, 'require_permission(') !== false,
        'it returned files and SQL to apply, to anyone, on an unvalidated instance_id');
}

// ---------------------------------------------------------------------------
// Anything a browser session can be made to submit needs a CSRF token.
// ---------------------------------------------------------------------------
//
// twofactor_setup.php accepted disable_2fa with no token, so an operator who
// loaded an attacker's page had their second factor stripped from a form they
// never saw. alerts.php accepted new notification settings the same way -
// redirecting alerts elsewhere, or switching them off, was one cross-site
// request. api/request_queue.php accepted an arbitrary method, path, headers
// and body to proxy at a managed firewall.

exec('cd ' . escapeshellarg($root) . ' && git ls-files --cached --others --exclude-standard "api/*.php" "*.php" 2>/dev/null', $all, $st);

$writes  = '/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|queue_firewall_command|queue_command)\b/i';
$csrfRe  = '/\b(csrf_verify|check_csrf|verify_csrf)\b/';
$machine = '/\b(authenticateAgentRequest|agent_verify_signature|validate_agent)\b|FROM\s+enrollment_tokens/i';
$browser = '/\b(requireLogin|requireAdmin|require_permission|isLoggedIn)\b'
         . '|\$_SESSION\s*\[\s*[\'"]user_id[\'"]\s*\]/';

$noCsrf = [];
$csrfExamined = 0;

foreach ($all as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) { continue; }
    if (preg_match('#^(inc/|tests/|scripts/|cron/|plugin/|development/)#', $rel)) { continue; }

    $src  = (string) file_get_contents($path);
    $code = preg_replace(['~//[^\n]*~', '~/\*.*?\*/~s'], '', $src);

    if (!preg_match($writes, $code)) { continue; }
    if (preg_match($machine, $code)) { continue; }   // no browser, no cookie
    if (!preg_match($browser, $code)) { continue; }  // covered by the authz check above
    if (strpos($code, '$_POST') === false && strpos($code, 'php://input') === false) { continue; }

    $csrfExamined++;
    if (!preg_match($csrfRe, $code)) { $noCsrf[] = $rel; }
}

check('browser-driven writers were found', $csrfExamined > 30, "{$csrfExamined} examined");

sort($noCsrf);
check('every browser-driven writer verifies a CSRF token', $noCsrf === [],
    implode("\n      ", $noCsrf));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
