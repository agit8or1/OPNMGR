<?php
/**
 * A certificate nothing uses is not an incident.
 *
 * Run with: php tests/certificate_alert_test.php
 *
 * OPNsense keeps every certificate ever created in config.xml: the self-signed
 * one generated at install, anything ACME has since superseded, every CA in the
 * chain. All of them were reported identically and alerted on identically.
 *
 * On 2026-09-17 that produced a CRITICAL "Certificate Web GUI TLS certificate
 * expires in 4 days" on a firewall whose web interface was serving a Let's
 * Encrypt certificate with 89 days left. The expiring certificate was an orphan
 * left behind when the box was rebuilt the day before. The alert was true about
 * a certificate and false about the firewall.
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

$root  = dirname(__DIR__);
$agent = (string) @file_get_contents($root . '/plugin/os-opnmanager-agent/src/opnsense/scripts/OPNsense/OPNManagerAgent/health_collect.py');
$eval  = (string) @file_get_contents($root . '/cron/evaluate_alerts.php');

check('the health collector is readable', $agent !== '');
check('cron/evaluate_alerts.php is readable', $eval !== '');

// --- the agent must say whether anything references the certificate ----------

check('the collector reports in_use', str_contains($agent, '"in_use"'));
check('it decides by reference, not by name',
    str_contains($agent, 'referenced') && str_contains($agent, 'all_refids'),
    'matching on the refid value catches consumers we have never heard of');
check('it excludes the certificate\'s own element from the search',
    str_contains($agent, 'cert_nodes'),
    'a refid always appears inside its own <cert>, which would mark everything in use');
check('it still never reads a private key',
    !preg_match('/findtext\("prv"\)/', $agent),
    'the collector reads <crt> only, and that must stay true');

// --- the evaluator must only suppress what is positively unused --------------

check('an unused certificate resolves rather than alerts',
    (bool) preg_match("/\\\$cert\['in_use'\] \?\? null\) === 'no'/", $eval));
check('it resolves both expiry alert types',
    (bool) preg_match("/=== 'no'\)[\s\S]{0,320}resolve\('cert\.expiring'[\s\S]{0,200}resolve\('cert\.expired'/", $eval));

// The distinction that matters: NULL is not 'no'.
check('an agent too old to report in_use still gets certificate alerts',
    !preg_match("/in_use'\] \?\? 'no'/", $eval)
    && !preg_match("/in_use'\] !== 'yes'/", $eval),
    'suppressing on anything other than an explicit "no" silently drops alerting for older agents');

// --- the shipped package must contain the change -----------------------------

$version = null;
if (preg_match("/define\('AGENT_VERSION',\s*'([^']+)'\)/", (string)@file_get_contents($root . '/inc/version.php'), $m)) {
    $version = $m[1];
}
check('AGENT_VERSION is readable', $version !== null);
if ($version !== null) {
    $tarball = $root . "/downloads/plugins/os-opnmanager-agent-{$version}.tar.gz";
    check("package {$version} exists", is_file($tarball));
    if (is_file($tarball)) {
        $collector = (string) shell_exec(
            'tar xzOf ' . escapeshellarg($tarball)
            . ' opnsense/scripts/OPNsense/OPNManagerAgent/health_collect.py 2>/dev/null'
        );
        check('the published package reports in_use', str_contains($collector, '"in_use"'),
            'the fix is only real once it is in the package a firewall installs');
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
