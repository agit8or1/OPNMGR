<?php
/**
 * A pending agent version must be visible, and deploying it must be a choice.
 *
 * Run with: php tests/agent_auto_update_test.php
 *
 * The staged rollout header claimed: "Holding suppresses the agent-facing offer
 * only. The manager still knows an update exists and still shows it, because
 * hiding that would trade one silent surprise for another."
 *
 * It knew, and showed it nowhere. agent_rollout_state() and LATEST_AGENT_VERSION
 * appeared only in agent_checkin.php, scripts/ and tests - so with v1.7.0
 * published and held, two firewalls sat on 1.6.9 and the only way to find out
 * was to run a CLI script. The operator's words: "I havent seen the manage say a
 * new agent is available anywhere."
 *
 * Auto-update then makes the gate optional rather than absolute: on, a published
 * version deploys itself to the fleet; off - the default - it is held until
 * promoted by name.
 */

require_once dirname(__DIR__) . '/inc/agent_rollout.php';

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

// --- the pending version must be visible in the UI ---------------------------

$dash = (string) @file_get_contents($root . '/dashboard.php');
$settings = (string) @file_get_contents($root . '/settings.php');
$css = (string) @file_get_contents($root . '/assets/css/app.css');

check('the fleet table compares the running agent against the published one',
    str_contains($dash, "\$agentNew = (\$agentNow !== '' && \$agentNow !== AGENT_VERSION)"),
    'the version was printed with nothing to compare it to');
check('the newer version is shown next to it',
    str_contains($dash, 'class="agent-upgrade"'));
check('a held rollout says so rather than implying it is coming',
    str_contains($dash, 'agent-held') && str_contains($dash, "\$agentRollout['stage'] === 'held'"),
    '"1.7.0 is available" and "1.7.0 is being offered" are different claims');
check('the rollout state is read once, not per row',
    (bool) preg_match('/\$agentRollout = agent_rollout_state\(\);\s*\?>\s*<\?php foreach/', $dash));
check('the indicator is styled', str_contains($css, '.agent-upgrade') && str_contains($css, '.agent-held'));
check('it is legible in dark mode', str_contains($css, '[data-theme="dark"] .agent-upgrade'));

check('settings reports the published version and stage',
    str_contains($settings, "\$ar_state = agent_rollout_state()")
    && str_contains($settings, 'Published <strong>v'));
check('settings counts the firewalls behind it',
    str_contains($settings, '$ar_behind'));
check('settings says when firewalls could take an update but are not offered it',
    str_contains($settings, 'not being offered it'),
    'that is the state the CLI reported and no page did');

// --- the toggle --------------------------------------------------------------

check('auto-update is selectable in settings',
    str_contains($settings, 'name="agent_auto_promote"') && str_contains($settings, 'save_agent_updates'));
check('changing it is permission-checked',
    (bool) preg_match("/save_agent_updates[\s\S]{0,300}can\('system\.maintenance'\)/", $settings));
check('the form carries CSRF', (bool) preg_match('/save_agent_updates[\s\S]{0,400}csrf/i', $settings)
    || (bool) preg_match('/csrf[\s\S]{0,400}save_agent_updates/i', $settings));
check('both states are explained rather than left to the label',
    str_contains($settings, 'without being asked') && str_contains($settings, 'stay separate acts'));

// --- the rule, exercised without touching the live settings ------------------
//
// The first version of this test switched the real auto-promote setting on.
// Agents check in every two minutes; one did so inside that window and promoted
// v1.7.0 to the entire fleet for real. Nothing installed it before the revert,
// but a test must not be able to deploy software - so the decision is a pure
// function now and this exercises that instead.

$d = agent_rollout_decide('1.7.0', '1.6.9', 'fleet', false);
check('a stage promoted for an older version holds the new one',
    $d['stage'] === 'held' && $d['superseded'] === true && $d['promote'] === false,
    'a forgotten "fleet" must not deploy the next release');

$d = agent_rollout_decide('1.7.0', '1.7.0', 'fleet', false);
check('a stage promoted for this version applies',
    $d['stage'] === 'fleet' && $d['superseded'] === false);

$d = agent_rollout_decide('1.7.0', '1.6.9', 'fleet', true);
check('with auto-update on, a new version promotes to fleet',
    $d['stage'] === 'fleet' && $d['promote'] === true && $d['promoted_version'] === '1.7.0',
    'this is what "agents update themselves" means');

$d = agent_rollout_decide('1.7.0', '1.7.0', 'fleet', true);
check('promoting is idempotent',
    $d['promote'] === false && $d['stage'] === 'fleet',
    'the state is read on every check-in, so it must not re-promote or re-log endlessly');

$d = agent_rollout_decide('1.7.0', '1.6.9', 'pilot', true);
check('auto-update overrides a pilot stage rather than half-deploying',
    $d['stage'] === 'fleet' && $d['promote'] === true);

$d = agent_rollout_decide('', '1.6.9', 'fleet', true);
check('nothing published promotes nothing',
    $d['promote'] === false,
    'promoting an empty version would advertise a package that does not exist');

$d = agent_rollout_decide('1.7.0', '1.6.9', 'nonsense', false);
check('an unrecognised stored stage is treated as held',
    $d['stage'] === 'held');

$d = agent_rollout_decide('1.7.0', '', 'held', false);
check('never promoted means held', $d['stage'] === 'held' && $d['superseded'] === true);

// The live setting is read, never written, by this test.
check('the default on this installation is off',
    !agent_rollout_auto_promote_enabled(),
    'the gate exists because publishing an agent used to change every firewall within two minutes');

$live = agent_rollout_state();
check('reading the live state does not promote anything',
    $live['version'] === (string) LATEST_AGENT_VERSION,
    'with auto-update off, asking what the state is must not change it');

// --- an automatic deployment must still leave a trace ------------------------

$rollout = (string) @file_get_contents($root . '/inc/agent_rollout.php');
check('an auto-promotion is audited',
    (bool) preg_match("/auto-promoted agent[\s\S]{0,400}'auto_promote'\s*=>\s*true/", $rollout),
    'the gate existed so a deployment was never a surprise; an automatic one with no record is exactly that');
check('turning the setting on or off is audited',
    str_contains($rollout, "audit_log('agent.rollout.auto_promote'"));
check('the audit fires only on a change',
    str_contains($rollout, '$was !== $enabled'),
    'writing the same value on every save would bury the real changes');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
