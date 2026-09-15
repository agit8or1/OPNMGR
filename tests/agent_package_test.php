<?php
/**
 * The agent's version label must have exactly one source.
 *
 * agent.sh and checkin.sh each carried their own AGENT_VERSION literal, and they
 * drifted: agent.sh said 1.6.2 while checkin.sh said 1.1.7. checkin.sh is what
 * the `checkin` configctl action runs - the GUI's force-check-in button and
 * `configctl opnmanager_agent checkin` both invoke it - and the manager records
 * whatever version the payload reports. So one button press relabelled a current
 * agent as eight releases old, dropping it below the minimum supported version,
 * costing nine points of health score and flagging it as needing an update,
 * until the next scheduled check-in put the real version back.
 *
 * Only agent.sh declares the version now. Everything else reads it from there,
 * and this suite fails if a second literal reappears.
 */

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL: {$what}\n";
    if ($detail !== '') {
        echo "      {$detail}\n";
    }
}

$root      = dirname(__DIR__);
$agentDir  = $root . '/plugin/os-opnmanager-agent/src/opnsense/scripts/OPNsense/OPNManagerAgent';
$agentFile = $agentDir . '/agent.sh';

check('the agent source tree is present', is_dir($agentDir), $agentDir);
check('agent.sh is present', is_file($agentFile));

// ---------------------------------------------------------------------------
// 1. agent.sh declares the version, and it matches the release.
// ---------------------------------------------------------------------------

$agent = is_file($agentFile) ? (string) file_get_contents($agentFile) : '';
$declared = '';
if (preg_match('/^AGENT_VERSION="([^"]+)"/m', $agent, $m)) {
    $declared = $m[1];
}
check('agent.sh declares AGENT_VERSION', $declared !== '');

require_once $root . '/inc/version.php';
check('agent.sh matches AGENT_VERSION in inc/version.php',
    $declared === AGENT_VERSION,
    'agent.sh = ' . var_export($declared, true) . ', inc/version.php = ' . AGENT_VERSION);

// ---------------------------------------------------------------------------
// 2. No other agent script carries its own literal.
// ---------------------------------------------------------------------------

$scripts = is_dir($agentDir) ? (glob($agentDir . '/*.sh') ?: []) : [];
check('there are agent scripts to examine', count($scripts) >= 2, 'found ' . count($scripts));

$offenders = [];
foreach ($scripts as $script) {
    if (realpath($script) === realpath($agentFile)) {
        continue; // agent.sh is the single source
    }
    $body = (string) file_get_contents($script);
    // A literal assignment, as opposed to reading it out of agent.sh.
    if (preg_match('/^AGENT_VERSION=["\']?[0-9]+\.[0-9]+/m', $body)) {
        $offenders[] = basename($script);
    }
}
check('no other agent script hardcodes a version', $offenders === [],
    'hardcoded in: ' . implode(', ', $offenders));

// The guard must not be vacuous: prove the pattern matches a literal when one
// is actually present.
check('the literal pattern works',
    preg_match('/^AGENT_VERSION=["\']?[0-9]+\.[0-9]+/m', "#!/bin/sh\nAGENT_VERSION=\"1.1.7\"\n") === 1);

// ---------------------------------------------------------------------------
// 3. Scripts that need the version read it from agent.sh.
// ---------------------------------------------------------------------------

$checkin = $agentDir . '/checkin.sh';
if (is_file($checkin)) {
    $body = (string) file_get_contents($checkin);
    check('checkin.sh derives the version from agent.sh',
        strpos($body, "grep '^AGENT_VERSION='") !== false);
    check('checkin.sh fails loudly if it cannot read the version',
        preg_match('/if \[ -z "\$AGENT_VERSION" \]/', $body) === 1);
    check('checkin.sh still reports a version in its payload',
        strpos($body, 'agent_version') !== false);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
