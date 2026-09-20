<?php
/**
 * Alerting must be configurable below the level of a whole firewall.
 *
 * Run with: php tests/alert_policy_test.php
 *
 * `firewalls.alerts_enabled` silenced a firewall entirely and there was nothing
 * between that and receiving every condition for every object. A tunnel that is
 * down by design, a circuit whose latency is what it is, a certificate nobody
 * intends to renew - each could only be stopped by muting the firewall, which
 * then hid the conditions that did matter.
 *
 * A policy row is an override resolved most-specific-first. No row means alert,
 * exactly as before, so the feature is inert until someone uses it.
 */

require_once dirname(__DIR__) . '/inc/alert_policy.php';

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

// The suite writes to the live database, so record what is there and put it
// back. A test that does not hand the installation back as it found it is a
// defect in the installation.
$before = db()->query('SELECT * FROM alert_policies')->fetchAll(PDO::FETCH_ASSOC);
db()->query('DELETE FROM alert_policies');
alert_policy_flush_cache();

$FW = 999001;        // ids that belong to no firewall
$OTHER = 999002;

// --- absence means alert ------------------------------------------------------

check('with nothing stored, a condition is allowed',
    alert_policy_allows('vpn.down', $FW, 'tunnel-a'),
    'the policy table only ever takes alerting away');
check('an unknown condition is allowed too',
    alert_policy_allows('something.new', $FW, null));

// --- one object, not the firewall --------------------------------------------

alert_policy_set('vpn.down', $FW, 'tunnel-a', false, null, 'down by design');
check('the named object is muted', !alert_policy_allows('vpn.down', $FW, 'tunnel-a'));
check('a sibling object is unaffected', alert_policy_allows('vpn.down', $FW, 'tunnel-b'),
    'this is the whole point: selectable per tunnel');
check('the same object on another firewall is unaffected',
    alert_policy_allows('vpn.down', $OTHER, 'tunnel-a'));
check('the firewall-wide condition is unaffected',
    alert_policy_allows('vpn.down', $FW, null));

// --- the firewall, not the fleet ---------------------------------------------

alert_policy_set('cert.expiring', $FW, null, false);
check('the condition is muted for this firewall', !alert_policy_allows('cert.expiring', $FW, 'cert-1'));
check('and for every object on it', !alert_policy_allows('cert.expiring', $FW, 'cert-2'));
check('but not for another firewall', alert_policy_allows('cert.expiring', $OTHER, 'cert-1'));

// --- specificity ordering ----------------------------------------------------

alert_policy_set('service.stopped', 0, '', false);            // nobody, anywhere
check('a global mute applies to an arbitrary firewall',
    !alert_policy_allows('service.stopped', $OTHER, 'sshd'));
alert_policy_set('service.stopped', $FW, '', true);           // except this one
check('a firewall row overrides the global row',
    alert_policy_allows('service.stopped', $FW, 'sshd'),
    'most specific wins, or per-firewall configuration is impossible');
alert_policy_set('service.stopped', $FW, 'sshd', false);      // except this object
check('an object row overrides the firewall row',
    !alert_policy_allows('service.stopped', $FW, 'sshd'));
check('a sibling still follows the firewall row',
    alert_policy_allows('service.stopped', $FW, 'ntpd'));

// --- clearing returns a scope to what it inherits ----------------------------

alert_policy_clear('service.stopped', $FW, 'sshd');
check('clearing an object row falls back to the firewall row',
    alert_policy_allows('service.stopped', $FW, 'sshd'));
alert_policy_clear('service.stopped', $FW, '');
check('clearing the firewall row falls back to the global row',
    !alert_policy_allows('service.stopped', $FW, 'sshd'));

// --- thresholds are per scope ------------------------------------------------

check('a missing threshold yields the default',
    alert_policy_threshold('cpu.high', $FW, null, 80) === 80);
alert_policy_set('cpu.high', $FW, null, true, '95');
check('a stored threshold overrides the default',
    (float) alert_policy_threshold('cpu.high', $FW, null, 80) === 95.0,
    'a 100 Mbit branch and a gigabit circuit are not slow at the same number');
check('another firewall keeps the default',
    alert_policy_threshold('cpu.high', $OTHER, null, 80) === 80);

// --- conditions that must stay silent until asked for ------------------------

check('speed and latency are opt-in',
    alert_policy_is_opt_in('speedtest.slow') && alert_policy_is_opt_in('latency.high'),
    'there is no defensible global figure for either');
check('an established condition is not opt-in',
    !alert_policy_is_opt_in('firewall.offline'));
check('an opt-in condition with no row stays silent',
    alert_policy_opt_in_threshold('latency.high', $FW) === null);
alert_policy_set('latency.high', $FW, null, true, null);
check('enabled without a threshold is still silent',
    alert_policy_opt_in_threshold('latency.high', $FW) === null,
    '"alert when latency is high" is not a number');
alert_policy_set('latency.high', $FW, null, true, '150');
check('enabled with a threshold reports it',
    alert_policy_opt_in_threshold('latency.high', $FW) === 150.0);
alert_policy_set('latency.high', $FW, null, false, '150');
check('muted with a threshold stays silent',
    alert_policy_opt_in_threshold('latency.high', $FW) === null);

// --- the cache must not outlive a write --------------------------------------

alert_policy_set('disk.high', $FW, null, false);
check('a write is visible immediately',
    !alert_policy_allows('disk.high', $FW, null),
    'a cached read after a write would show the old policy');

// --- restore ------------------------------------------------------------------

db()->query('DELETE FROM alert_policies');
foreach ($before as $row) {
    alert_policy_set($row['alert_type'], (int) $row['firewall_id'], $row['object_key'],
                     (int) $row['enabled'] === 1, $row['threshold'], $row['note']);
}
alert_policy_flush_cache();
$after = db()->query('SELECT COUNT(*) FROM alert_policies')->fetchColumn();
check('the installation is left as it was found', (int) $after === count($before),
    sprintf('had %d row(s), now %d', count($before), (int) $after));

// --- enforcement lives at the one chokepoint ---------------------------------

$eval = (string) @file_get_contents($root . '/cron/evaluate_alerts.php');
check('policy is enforced inside raise()',
    (bool) preg_match('/function raise\([^)]*\)[^{]*\{[\s\S]{0,900}alert_policy_allows\(/', $eval),
    'enforcing per condition means a new condition can forget to');
check('a muted condition closes what it already opened',
    (bool) preg_match("/alert_policy_allows[\s\S]{0,700}resolve\(\\\$type, \\\$fwId, \\\$key, 'muted by alert policy'\)/", $eval),
    'otherwise muting leaves an incident open forever: nothing re-raises it, so nothing resolves it');
check('muted conditions are counted and reported',
    str_contains($eval, '$suppressed++') && str_contains($eval, 'muted by policy'));

// --- the new conditions must not fire on stale or thin data ------------------

check('a slow-link alert needs a recent test',
    str_contains($eval, 'test_date >= (NOW() - INTERVAL 7 DAY)'),
    'this installation stopped collecting speed tests in December; an average over all of it describes a circuit from nine months ago');
check('no recent test is not reported as a slow link',
    str_contains($eval, 'no speed test in the last 7 days'),
    'that would be an alert about the collector, not the circuit');
check('latency is averaged, not sampled',
    (bool) preg_match('/AVG\(latency_ms\)[\s\S]{0,400}samples\'\] >= 3/', $eval),
    'one spike during a backup is not an incident');

// --- the catalogue must cover what the evaluator can raise -------------------

preg_match_all("/raise\('([a-z_]+\.[a-z_]+)'/", $eval, $m);
$raisable = array_unique($m[1]);
$catalogue = array_keys(alert_policy_catalogue());
foreach ($raisable as $type) {
    check("{$type} is configurable", in_array($type, $catalogue, true),
        'a condition absent from the catalogue cannot be turned off in the UI');
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
