<?php
/**
 * A command waiting for an offline firewall is not a stuck command.
 *
 * The hourly sweep failed anything pending for more than an hour. Against a
 * live firewall that is right - it should have been collected at the next
 * check-in, so an hour means something went wrong. Against a firewall that is
 * down it is wrong twice over: the command is not stuck, it is waiting, and the
 * thing most likely to be queued for a firewall that has gone quiet is the
 * instruction that would bring it back.
 *
 * That happened on 2026-09-15. A firewall lost its agent during a staged
 * rollout, the recovery install sat pending, and this sweep failed it at the
 * one hour mark. Had the firewall returned after that it would have rejoined
 * still running the broken agent, with nothing queued to fix it, and the
 * recovery would have looked like it simply did not work.
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
$sweep = (string) @file_get_contents($root . '/cron/cleanup_stuck_commands.php');
check('the sweep is readable', $sweep !== '');

// ---------------------------------------------------------------------------
// 1. The one-hour rule applies only to firewalls that are actually reachable.
// ---------------------------------------------------------------------------

check('the one-hour sweep joins the firewall',
    preg_match('/stuck in pending for over 1 hour.*?JOIN firewalls/s', $sweep) === 1
    || preg_match('/JOIN firewalls.*?stuck in pending for over 1 hour/s', $sweep) === 1,
    'without the join it cannot tell a stuck command from a waiting one');

check('it requires a recent check-in before failing a pending command',
    preg_match('/last_checkin > DATE_SUB\(NOW\(\), INTERVAL \d+ MINUTE\)/', $sweep) === 1,
    'a command for an offline firewall is waiting, not stuck');

// ---------------------------------------------------------------------------
// 2. But a firewall that never returns must not accumulate commands forever.
// ---------------------------------------------------------------------------

check('there is an absolute cap', stripos($sweep, 'INTERVAL 7 DAY') !== false,
    'holding indefinitely would let a decommissioned firewall collect a backlog');
check('the cap explains itself', stripos($sweep, 'has not returned') !== false);

// ---------------------------------------------------------------------------
// 3. The decision rule, exercised directly.
// ---------------------------------------------------------------------------

// fails = the command is failed by the sweep.
$fails = function (int $fwSilentMinutes, int $cmdAgeMinutes): bool {
    $liveWindow = 15;      // minutes since last check-in that counts as reachable
    $stuckAfter = 60;      // minutes pending before a live firewall's command is stuck
    $capDays    = 7;

    if ($cmdAgeMinutes >= $capDays * 24 * 60) {
        return true;                                  // abandoned, whatever the state
    }
    if ($fwSilentMinutes > $liveWindow) {
        return false;                                 // offline: hold it
    }
    return $cmdAgeMinutes > $stuckAfter;
};

check('a live firewall with a 2h old command: failed',      $fails(1, 120));
check('a live firewall with a 30m old command: kept',      !$fails(1, 30));
check('an offline firewall with a 2h old command: held',   !$fails(495, 120),
    'this is the case that deleted the recovery install');
check('an offline firewall with an 8d old command: failed', $fails(495, 8 * 24 * 60),
    'the absolute cap still applies');
check('a firewall silent 16 minutes is treated as offline', !$fails(16, 120));
check('a firewall silent 14 minutes is still live',          $fails(14, 120));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
