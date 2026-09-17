<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);

/**
 * Drive the staged agent rollout.
 *
 * Publishing an agent version used to be deploying it. Syncing AGENT_VERSION to
 * production made every firewall fetch and install the new agent on its next
 * check-in, within about two minutes, with no step in between - so "release the
 * agent" and "change every firewall right now" were one action. On 2026-09-16
 * that fired twice in a session: 1.6.6 installed itself on fw48 unprompted, and
 * 1.6.7 did the same while the revert was being typed, the download having
 * completed 45 seconds earlier.
 *
 * The stage is bound to a version. Publish, then promote - and a version nobody
 * has promoted goes nowhere.
 *
 * Usage:
 *   php scripts/agent_rollout.php                      show the current state
 *   php scripts/agent_rollout.php --pilot 48,51        mark pilot firewalls
 *   php scripts/agent_rollout.php --unpilot 51         unmark them
 *   php scripts/agent_rollout.php --stage pilot        what it would do
 *   php scripts/agent_rollout.php --stage pilot --apply do it
 *   php scripts/agent_rollout.php --stage fleet --apply promote to everybody
 *   php scripts/agent_rollout.php --stage held --apply  stop offering it
 *
 * @since 3.51.0
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/agent_rollout.php';

$opts     = getopt('', ['stage:', 'pilot:', 'unpilot:', 'apply', 'help']);
$apply    = isset($opts['apply']);

if (isset($opts['help'])) {
    $doc = file_get_contents(__FILE__);
    if (preg_match('/ \* Usage:\n(.*?)\n \*\n/s', $doc, $m)) {
        echo preg_replace('/^ \* ?/m', '', $m[1]) . "\n";
    }
    exit(0);
}

function fail(string $msg): void
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(1);
}

/** Mark or unmark pilots. */
function set_pilots(string $csv, int $value): void
{
    $ids = array_values(array_filter(array_map('intval', explode(',', $csv))));
    if (!$ids) { fail('no firewall ids given'); }

    $in   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("UPDATE firewalls SET agent_rollout_pilot = ? WHERE id IN ({$in})");
    $stmt->execute(array_merge([$value], $ids));

    printf("%s pilot on %d firewall(s): %s\n",
        $value ? 'Set' : 'Cleared', $stmt->rowCount(), implode(', ', $ids));
}

if (isset($opts['pilot']))   { set_pilots((string) $opts['pilot'], 1); }
if (isset($opts['unpilot'])) { set_pilots((string) $opts['unpilot'], 0); }

// --- promotion ---------------------------------------------------------------

if (isset($opts['stage'])) {
    $stage = (string) $opts['stage'];
    if (!in_array($stage, AGENT_ROLLOUT_STAGES, true)) {
        fail("unknown stage '{$stage}' (expected: " . implode(', ', AGENT_ROLLOUT_STAGES) . ')');
    }

    $published = (string) LATEST_AGENT_VERSION;

    // Show who this moves before moving it. A promotion to 'fleet' is the thing
    // that used to happen implicitly, so it should never be a surprise.
    $targets = agent_rollout_targets();
    $would   = [];
    foreach ($targets as $t) {
        $offered = $stage === 'fleet' || ($stage === 'pilot' && $t['pilot']);
        if ($offered) { $would[] = $t; }
    }

    printf("Stage '%s' for agent %s would offer the update to %d of %d firewall(s) behind it:\n",
        $stage, $published, count($would), count($targets));
    foreach ($targets as $t) {
        $offered = $stage === 'fleet' || ($stage === 'pilot' && $t['pilot']);
        printf("  %-6s fw%-4d %-24s %-8s %s\n",
            $offered ? 'OFFER' : 'hold', $t['id'], $t['hostname'], $t['agent_version'],
            $t['pilot'] ? '(pilot)' : '');
    }

    if ($stage === 'pilot' && !$would && $targets) {
        echo "\nNote: no firewall is marked as a pilot, so this offers the update to nobody.\n";
        echo "      Mark one with --pilot <id> first.\n";
    }

    if (!$apply) {
        echo "\nNothing changed. Re-run with --apply to promote.\n";
        exit(0);
    }

    $result = agent_rollout_promote($stage);
    if (!$result['ok']) { fail($result['message']); }
    echo "\n" . $result['message'] . "\n";
    if ($would) {
        echo "They will take it on their next check-in.\n";
    }
    exit(0);
}

// --- status ------------------------------------------------------------------

$state = agent_rollout_state();

printf("Published agent version : %s\n", $state['version']);
printf("Rollout stage           : %s\n", $state['stage']);

if ($state['superseded']) {
    printf("  stored stage '%s' was promoted for %s, so %s is held.\n",
        $state['stored_stage'],
        $state['promoted_version'] === '' ? '(no version)' : $state['promoted_version'],
        $state['version']);
    echo "  Publishing does not deploy: promote this version by name to release it.\n";
}

$targets = agent_rollout_targets();
if (!$targets) {
    echo "\nNo firewall is behind the published version.\n";
    exit(0);
}

printf("\n%d firewall(s) behind %s:\n", count($targets), $state['version']);
foreach ($targets as $t) {
    printf("  %-6s fw%-4d %-24s %-8s %s\n",
        $t['offered'] ? 'OFFER' : 'held', $t['id'], $t['hostname'], $t['agent_version'],
        $t['pilot'] ? '(pilot)' : '');
}

if ($state['stage'] === 'held') {
    echo "\nNothing is being offered. To release:\n";
    echo "  php scripts/agent_rollout.php --pilot <id> --stage pilot --apply\n";
    echo "  php scripts/agent_rollout.php --stage fleet --apply\n";
}
