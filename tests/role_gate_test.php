<?php
/**
 * Signing in is not permission. Every page that changes state must check a role.
 *
 * endpoint_authz_test.php proves each endpoint asks *who* is calling. Up to
 * 3.80.0 that was the only check on a large set of endpoints, so a readonly
 * account could create administrators (add_user.php), queue service restarts
 * (api/queue_action.php), start firmware upgrades (api/trigger_update.php) and
 * run shell commands both on firewalls (api/network_diagnostic.php) and on the
 * manager itself (api/run_diagnostic.php). This test requires a role check
 * (require_permission / requireAdmin / isAdmin / can) wherever a page signs the
 * caller in with requireLogin() and then writes, queues or executes.
 *
 * Static: reads source only, touches no database.
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
exec('cd ' . escapeshellarg($root) . ' && git ls-files "*.php" 2>/dev/null', $files, $status);
check('php files were listed', $status === 0 && count($files) > 100, 'got ' . count($files));

// Pages that sign the caller in and then write, but are deliberately open to
// every role. Each needs a reason.
$openToAllRoles = [
    'profile.php'               => 'a user editing their own profile and password',
    'api/get_ai_settings.php'   => 'read; inserts a default row on first view',
    'api/get_map_locations.php' => 'read; caches geocoded coordinates',
    'api/health_monitor.php'    => 'read-only status probes of this server',
    'api/system_info.php'       => 'read-only status probes of this server',
    'api/report_manage.php'     => 'each action enforces its capability in inc/reports/actions.php',
];

$writes = '/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|REPLACE\s+INTO|queue_firewall_command'
        . '|shell_exec|proc_open|passthru)\b|\bexec\s*\(/i';
$role   = '/\b(require_permission|requireAdmin|isAdmin|can)\s*\(/';

$missing  = [];
$examined = 0;
foreach ($files as $rel) {
    if (preg_match('~^(tests|inc|scripts|cron|vendor|database|plugin|agent)/~', $rel)) { continue; }
    $code = preg_replace(['~//[^\n]*~', '~/\*.*?\*/~s'], '', (string) file_get_contents("$root/$rel"));
    if (!preg_match('/\brequireLogin\s*\(/', $code) && !preg_match($role, $code)) { continue; }
    if (!preg_match($writes, $code)) { continue; }
    $examined++;
    if (isset($openToAllRoles[$rel]) || preg_match($role, $code)) { continue; }
    $missing[] = $rel;
}

check('state-changing signed-in pages were found', $examined > 40, "{$examined} examined");
sort($missing);
check('every state-changing page checks a role, not only a login', $missing === [],
    implode("\n      ", $missing));

// ---------------------------------------------------------------------------
// The ones that were open, pinned by name and capability.
// ---------------------------------------------------------------------------
$pinned = [
    'add_user.php'                => "require_permission('user.manage')",
    'api/trigger_update.php'      => "require_permission('update.install')",
    'api/network_diagnostic.php'  => "require_permission('command.diagnostic')",
    'api/run_diagnostic.php'      => "require_permission('command.diagnostic')",
    'api/save_ai_settings.php'    => "require_permission('firewall.manage')",
    'ajax/certbot.php'            => "require_permission('security.manage')",
    'branding.php'                => "require_permission('settings.manage')",
];
foreach ($pinned as $rel => $needle) {
    check("{$rel} requires {$needle}", str_contains((string) @file_get_contents("$root/$rel"), $needle));
}

$qa = (string) file_get_contents("$root/api/queue_action.php");
check('queue_action gates on the risk-mapped capability',
    str_contains($qa, "require_permission(risk_to_capability("));

$tu = (string) file_get_contents("$root/api/trigger_update.php");
check('trigger_update writes an audit entry', str_contains($tu, "audit_log('update.trigger'"));

// network_diagnostic must not build shell text from the request itself.
$nd = (string) file_get_contents("$root/api/network_diagnostic.php");
check('network_diagnostic builds commands through the structured catalogue',
    str_contains($nd, 'build_structured_command(') && !str_contains($nd, 'TARGET="$target"'));

// run_diagnostic must pass the remote command to ssh as one escaped argument.
$rd = (string) file_get_contents("$root/api/run_diagnostic.php");
check('run_diagnostic escapes the whole remote command',
    str_contains($rd, 'escapeshellarg($command)') && !str_contains($rd, '"%s; exit"'));

// raw shell must fail closed when its setting cannot be read.
$ac = (string) file_get_contents("$root/inc/agent_commands.php");
check('raw_commands_enabled fails closed on a settings error',
    !str_contains($ac, 'return true; // fail open'));

// The validator network_diagnostic used to rely on accepts shell syntax.
check('FILTER_VALIDATE_DOMAIN alone is not a hostname check (sanity)',
    filter_var('a";reboot;"', FILTER_VALIDATE_DOMAIN) !== false);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
