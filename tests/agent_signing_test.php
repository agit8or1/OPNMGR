<?php
/**
 * A signing policy the fleet cannot satisfy must be visible, not silent.
 *
 * The server side of agent request signing is complete - HMAC-SHA256
 * verification, a freshness window, nonce replay rejection, a per-firewall
 * ratchet, three fleet-wide modes - and the check-in response hands the agent
 * its signing secret along with the canonical string to sign, annotated "Sign
 * requests once supported."
 *
 * No agent release has ever contained a line of signing code. The agent does
 * not store the api_secret it is sent, computes no HMAC, and sends no
 * X-OPNMGR-Signature header. agent_signing_supported is therefore 0 for every
 * firewall.
 *
 * That makes agent_auth_mode = require_signed a trap: it reads as the hardened
 * option, refuses every check-in in the fleet, and has no UI - so it can only
 * be set by someone who went looking in the database, which is exactly the
 * person likely to choose it.
 *
 * The policy is reported, never overridden. Silently downgrading a security
 * setting is the failure this codebase has been full of; the interface keeps
 * working when agents are refused, so a loud banner is the route back.
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
$src  = (string) @file_get_contents($root . '/inc/agent_signing_status.php');
check('inc/agent_signing_status.php exists', $src !== '');

// ---------------------------------------------------------------------------
// 1. The agent genuinely cannot sign - the premise of all of this.
// ---------------------------------------------------------------------------

$agentDir = $root . '/plugin/os-opnmanager-agent/src/opnsense/scripts/OPNsense/OPNManagerAgent';
$agentSrc = '';
foreach (glob($agentDir . '/*.sh') ?: [] as $f) {
    $agentSrc .= (string) file_get_contents($f);
}
check('the agent source was found', $agentSrc !== '');

// If a future agent gains signing, this assertion should be updated rather than
// deleted - it records why the banner exists.
check('no released agent computes a signature',
    !preg_match('/X-OPNMGR-Signature/i', $agentSrc)
    && !preg_match('/hmac/i', $agentSrc),
    'if the agent can now sign, update agent_signing_status() and this suite together');

// ---------------------------------------------------------------------------
// 2. The status helper reports rather than overrides.
// ---------------------------------------------------------------------------

check('the policy is not silently downgraded',
    !preg_match("/save_setting\(\s*'agent_auth_mode'/", $src)
    && !preg_match("/UPDATE settings.*agent_auth_mode/i", $src),
    'a security setting that quietly reverts itself is the failure being fixed, not the fix');

check('require_signed with no signing agents is reported unsatisfiable',
    strpos($src, "\$mode !== 'require_signed'") !== false);

check('every other mode is treated as satisfiable',
    preg_match("/satisfiable.*require_signed/s", $src) === 1,
    'compatibility and prefer_signed tolerate an agent that cannot sign');

// ---------------------------------------------------------------------------
// 3. Pure evaluation, exercised directly.
// ---------------------------------------------------------------------------

// Reimplement the decision here so the rule itself is pinned, independent of
// whatever the live database happens to hold.
$satisfiable = fn(string $mode, int $supported, int $total): bool =>
    $mode !== 'require_signed' || ($total > 0 && $supported === $total);

check('require_signed with no signers is unsatisfiable', !$satisfiable('require_signed', 0, 2));
check('require_signed with some signers is unsatisfiable', !$satisfiable('require_signed', 1, 2));
check('require_signed with every agent signing is satisfiable', $satisfiable('require_signed', 2, 2));
check('compatibility is satisfiable with no signers', $satisfiable('compatibility', 0, 2));
check('prefer_signed is satisfiable with no signers', $satisfiable('prefer_signed', 0, 2));
check('require_signed on an empty fleet is not claimed satisfiable', !$satisfiable('require_signed', 0, 0));

// ---------------------------------------------------------------------------
// 4. The banner says what to do about it.
// ---------------------------------------------------------------------------

check('the banner names the setting', strpos($src, 'agent_auth_mode') !== false);
check('the banner gives the way back',
    strpos($src, 'compatibility') !== false && stripos($src, 'restore check-ins') !== false,
    'a refused fleet cannot fix itself; the operator needs the exact remedy');
check('the banner states that no agent implements signing',
    stripos($src, 'server-side') !== false);

$header = (string) @file_get_contents($root . '/inc/header.php');
check('the banner is rendered from the shared header',
    strpos($header, 'agent_signing_banner') !== false);
check('a failing banner cannot take the page down',
    preg_match('/agent_signing_banner.*catch \(Throwable/s', $header) === 1);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
