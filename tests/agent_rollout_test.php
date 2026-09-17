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

// --- the stage change must be auditable --------------------------------------
//
// Promoting is the act that lets a release reach firewalls. It used to happen
// implicitly on publish, with no record anywhere of who released what to whom.

// Matched by pattern rather than by literal: a literal would read as this test
// file requiring inc/audit.php from tests/, and referenced_files_test.php would
// correctly report that as a broken reference.
check('agent_rollout.php pulls in the audit helper',
    (bool)preg_match('/require_once __DIR__ \. .\/audit\.php./', $rollout));
check('a successful promotion is audited',
    (bool)preg_match("/audit_log\('agent\.rollout\.stage', \[\s*'object_type'/", $rollout));
check('a rejected stage is audited as a failure',
    str_contains($rollout, 'rejected unknown rollout stage')
    && (bool)preg_match('/\'success\'  => false/', $rollout),
    'a refused promotion is worth a record too');
check('a failed settings write is audited as a failure',
    str_contains($rollout, 'failed to promote agent'));
check('the entry records the transition, not just the destination',
    str_contains($rollout, "'previous_stage'   => \$before['stored_stage']")
    && str_contains($rollout, "'previous_version' => \$before['promoted_version']"),
    'a destination alone does not say what changed');
check('the entry records how many firewalls it reaches',
    str_contains($rollout, "'reaches'          => \$reach"),
    'the useful question later is who this opened the update to');
check('reach is counted before the write',
    (bool)preg_match('/\$reach\s*=\s*0;.*?foreach \(agent_rollout_targets\(\).*?db\(\)->prepare/s', $rollout));
check('the version is the audited object',
    (bool)preg_match('/\'object_type\' => \'agent_version\',\s*\'object_id\'   => \$published/', $rollout));

$cli = (string)@file_get_contents($root . '/scripts/agent_rollout.php');
check('pilot changes are audited too',
    str_contains($cli, "audit_log('agent.rollout.pilot'"),
    'at stage pilot this is what decides which firewalls a release reaches');
check('the pilot entry names the firewalls', str_contains($cli, "'firewall_ids' => \$ids"));

// --- the published schema must match -----------------------------------------

$schema = (string)@file_get_contents($root . '/database/schema.sql');
check('schema.sql carries the pilot column', str_contains($schema, 'agent_rollout_pilot'),
    'regenerate with scripts/generate_schema.sh or fresh installs break');

// --- the dashboard must state which version is running ------------------------
//
// The published version drives what firewalls are offered, so "which version is
// this" should not require reading a file on the server.

// It lives in the global header, beside the brand. It was first put in the
// dashboard KPI strip, where it rendered correctly and was still missed: small,
// muted, and to the right of a tile grid that wraps. The top of the page means
// the top of the page.
$hdr = (string)@file_get_contents($root . '/inc/header.php');
check('the page header shows the application version',
    str_contains($hdr, 'class="brand-version"') && str_contains($hdr, 'APP_VERSION'));
check('it shows the application version only',
    !str_contains($hdr, 'AGENT_VERSION'),
    'the agent version belongs to the rollout surface, not the page header');
check('the version escapes its output',
    substr_count($hdr, 'htmlspecialchars(APP_VERSION)') >= 1);
check('it sits inside the brand link at the top of every page',
    (bool)preg_match('/header-brand.*?brand-version/s', $hdr));
check('the dashboard no longer carries a second copy',
    !str_contains((string)@file_get_contents($root . '/dashboard.php'), 'dash-version'),
    'two version labels on one page is clutter');
check('brand-version is styled', str_contains(
    (string)@file_get_contents($root . '/assets/css/app.css'), '.brand-version'));
check('APP_VERSION_DATE is not older than the newest changelog entry',
    (function () use ($root): bool {
        $v = (string)@file_get_contents($root . '/inc/version.php');
        if (!preg_match("/APP_VERSION_DATE', '([0-9-]+)'/", $v, $d)) { return false; }
        if (!preg_match("/'date' => '([0-9-]+)'/", $v, $e)) { return false; }
        return $d[1] >= $e[1];
    })(),
    'the date is shown in the dashboard tooltip, so a stale one is visible');

// --- the manager's own configuration lives in one place ----------------------
//
// Settings, AI Analysis, Manager Health, System Update and System Backup are all
// "configure the manager itself" and sat as five peers among ten admin entries.

check('the settings group exists', str_contains($hdr, 'id="settingsGroup"'));
foreach (['ai_settings.php', 'health_monitor.php', 'system_update.php', 'system_backup.php'] as $page) {
    check("{$page} is inside it",
        (bool) preg_match('/id="settingsGroup"[\s\S]{0,2600}' . preg_quote($page, '/') . '/', $hdr));
}
check('none of them remain a top-level admin entry',
    substr_count($hdr, 'href="/ai_settings.php"') === 1
    && substr_count($hdr, 'href="/health_monitor.php"') === 1
    && substr_count($hdr, 'href="/system_backup.php"') === 1,
    'a duplicated entry would appear twice in the menu');

check('the group opens on its own pages, decided server side',
    str_contains($hdr, '$settings_open = in_array(basename'),
    'so it is right before any script runs');
check('children are visually nested', str_contains($hdr, 'sidebar-subitem'));
check('one collapse implementation serves both groups',
    (bool) preg_match("/toggle: 'adminGroupToggle'[\s\S]{0,200}toggle: 'settingsGroupToggle'/", $hdr),
    'two copies would drift');
check('a server-opened group is not closed by a remembered state',
    str_contains($hdr, 'openedByServer'),
    'the group holding the page you are on must never be shut');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
