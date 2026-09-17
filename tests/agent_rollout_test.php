<?php
/**
 * Publishing an agent version must not deploy it.
 *
 * Run with: php tests/agent_rollout_test.php
 *
 * Syncing AGENT_VERSION to production made every firewall fetch and install the
 * new agent on its next check-in, within about two minutes, with no step in
 * between. "Release the agent" and "change every firewall right now" were the
 * same action. On 2026-09-16 that fired twice in one session: 1.6.6 installed
 * itself on fw48 unprompted, and 1.6.7 did the same while the revert was being
 * typed - the download had completed 45 seconds earlier.
 *
 * The property under test is not "a flag exists". It is that the stage is bound
 * to a version, so a stage left at 'fleet' does not deploy the NEXT release the
 * moment it is published. The safe state has to be the one you get by
 * forgetting, or the gate is only as good as somebody's memory.
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

// --- the decision, in isolation from the database ----------------------------
//
// Mirrors agent_rollout_state()/agent_rollout_allows(). Kept as a local model so
// the rule can be exercised across combinations without a live settings table;
// the static checks below assert the shipped code still has this shape.

$allows = function (string $published, string $promotedVersion, string $storedStage, bool $isPilot): bool {
    if (!in_array($storedStage, ['held', 'pilot', 'fleet'], true)) { $storedStage = 'held'; }
    $stage = ($promotedVersion !== $published) ? 'held' : $storedStage;
    return match ($stage) {
        'fleet' => true,
        'pilot' => $isPilot,
        default => false,
    };
};

// The case that actually happened, twice.
check('a newly published version is held even when the stage says fleet',
    !$allows('1.6.7', '1.6.6', 'fleet', false),
    'this is 2026-09-16: publishing 1.6.7 deployed it within one check-in');
check('...and held for a pilot firewall too',
    !$allows('1.6.7', '1.6.6', 'fleet', true),
    'the stage belongs to the old version; it says nothing about this one');

// A fresh installation has promoted nothing.
check('with no version ever promoted, nothing is offered',
    !$allows('1.6.7', '', 'held', false));
check('with no version ever promoted, fleet stage still offers nothing',
    !$allows('1.6.7', '', 'fleet', true),
    'an empty promoted version must never match');

// Deliberate promotion works.
check('promoting the published version to fleet offers it',
    $allows('1.6.7', '1.6.7', 'fleet', false));
check('pilot stage offers it to a pilot',
    $allows('1.6.7', '1.6.7', 'pilot', true));
check('pilot stage holds it from a non-pilot',
    !$allows('1.6.7', '1.6.7', 'pilot', false));
check('held stage holds it from everyone',
    !$allows('1.6.7', '1.6.7', 'held', true));

// Failure modes must be conservative.
check('an unrecognised stage is treated as held',
    !$allows('1.6.7', '1.6.7', 'everyone', true),
    'a typo in a setting must not release to the fleet');
check('pilot stage with nobody marked reaches nobody',
    !$allows('1.6.7', '1.6.7', 'pilot', false));

// --- the shipped code must have that shape -----------------------------------

$rollout = (string)@file_get_contents($root . '/inc/agent_rollout.php');
check('inc/agent_rollout.php exists', $rollout !== '');

check('the stage is compared against the published version',
    (bool)preg_match('/\$superseded\s*=\s*\(\$promoted\s*!==\s*\$published\)/', $rollout),
    'without this the flag is just a mode somebody forgets to reset');
check('a superseded stage degrades to held',
    (bool)preg_match("/\\\$stage\s*=\s*\\\$superseded\s*\?\s*'held'/", $rollout));
check('an unknown stored stage degrades to held',
    (bool)preg_match("/in_array\(\\\$stored, AGENT_ROLLOUT_STAGES, true\)\) \{\s*\\\$stored = 'held'/", $rollout));
check('the default stage is held', str_contains($rollout, "agent_rollout_setting('agent_rollout_stage', 'held')"));
check('promotion always rebinds the version',
    (bool)preg_match("/agent_rollout_promote.*?\\\$published = \(string\) LATEST_AGENT_VERSION.*?'agent_rollout_version', \\\$published/s", $rollout),
    'a promotion that did not name its version would carry into the next release');

// --- the check-in path must consult it ---------------------------------------

$checkin = (string)@file_get_contents($root . '/agent_checkin.php');
check('agent_checkin.php consults the gate', str_contains($checkin, 'agent_rollout_allows($is_pilot)'));
check('the gate sits inside the version-comparison branch',
    (bool)preg_match("/version_compare\(\\\$current_clean, \\\$latest_clean, '<'\).*?agent_rollout_allows/s", $checkin));
check('a held update suppresses the agent-facing offer',
    (bool)preg_match("/if \(!agent_rollout_allows\(\\\$is_pilot\)\).*?'update_available' => false/s", $checkin),
    'update_available is what makes the agent install it');
check('a held update is still reported to the manager',
    str_contains($checkin, "'update_held'      => true"),
    'suppressing the offer must not mean hiding that an update exists');
check('the pilot flag is read from the firewall row',
    str_contains($checkin, 'SELECT hostname, agent_rollout_pilot FROM firewalls WHERE id = ?'));

// --- migration ---------------------------------------------------------------

$migration = (string)@file_get_contents($root . '/database/migrations/0021_staged_agent_rollout.sql');
check('the migration exists', $migration !== '');
check('it defaults the stage to held', str_contains($migration, "'agent_rollout_stage', 'held'"));
check('it promotes no version', (bool)preg_match("/'agent_rollout_version', ''/", $migration),
    'an upgrading installation must not inherit an open gate');
check('it adds the pilot column idempotently',
    str_contains($migration, 'ADD COLUMN IF NOT EXISTS agent_rollout_pilot'));
check('nothing is a pilot by default', str_contains($migration, 'NOT NULL DEFAULT 0'),
    "stage 'pilot' with no pilots must reach nobody, not everybody");

// --- the published schema must match -----------------------------------------

$schema = (string)@file_get_contents($root . '/database/schema.sql');
check('schema.sql carries the pilot column', str_contains($schema, 'agent_rollout_pilot'),
    'regenerate with scripts/generate_schema.sh or fresh installs break');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
