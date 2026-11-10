<?php
/**
 * Signing in is not permission. Every page that changes state must check a role
 * before it does.
 *
 * endpoint_authz_test.php proves each endpoint asks *who* is calling. Up to
 * 3.80.0 that was the only check on a large set of endpoints, so a readonly
 * account could create administrators (add_user.php), queue service restarts
 * (api/queue_action.php), start firmware upgrades (api/trigger_update.php) and
 * run shell commands both on firewalls (api/network_diagnostic.php) and on the
 * manager itself (api/run_diagnostic.php).
 *
 * The 3.80.1 version of this test passed any file that mentioned can( or
 * isAdmin( anywhere, and skipped files that signed users in without
 * requireLogin(). Since 3.80.3 tests/lib/role_gate_analysis.php reads PHP
 * tokens and requires each state-changing statement to sit after a role check
 * in its own or an enclosing block, and every page that writes declares its
 * gate in $pinned below: a new endpoint fails until it is added, and changing
 * an existing endpoint's capability is a visible diff here.
 *
 * Static: reads source only, touches no database.
 */

require_once __DIR__ . '/lib/role_gate_analysis.php';

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
exec('cd ' . escapeshellarg($root) . ' && git ls-files --cached --others --exclude-standard "*.php" 2>/dev/null', $files, $status);
check('php files were listed', $status === 0 && count($files) > 100, 'got ' . count($files));

// Pages that write before any role check, deliberately. Each needs a reason.
$openToAllRoles = [
    'profile.php'               => 'a user editing their own profile and password',
    'twofactor_setup.php'       => 'a user enrolling or removing their own second factor',
    'verify2fa.php'             => 'completing sign-in; there is no role yet',
    'api/get_ai_settings.php'   => 'read; inserts a default row on first view',
    'api/get_map_locations.php' => 'read; caches geocoded coordinates',
    'api/health_monitor.php'    => 'read-only status probes of this server',
    'api/system_info.php'       => 'read-only status probes of this server',
];
// Called by a firewall, not a person: no session and no role. Each is gated by
// a single-use enrolment token (tests/enrollment_race_test.php).
$notSessionEndpoints = [
    'agent_enroll.php'          => 'enrolment token',
    'api/enroll.php'            => 'enrolment token',
    'enroll_firewall.php'       => 'enrolment token',
    'enroll_firewall_new.php'   => 'enrolment token',
];

// Every page that writes, queues or executes, and the gate(s) it declares, in
// order: require_permission(...) / requireAdmin() / an if (!can(...)) exit
// guard; 'agent-auth' for agent endpoints; 'cli-only' for files that refuse web
// requests; 'web: ...' for a gate that applies to web requests of a CLI script.
$pinned = [
    'acme.php'                                   => ['require_permission(\'security.manage\')'],
    'add_firewall.php'                           => ['requireAdmin()'],
    'add_firewall_page.php'                      => ['requireAdmin()'],
    'add_user.php'                               => ['require_permission(\'user.manage\')'],
    'admin/reset_agent.php'                      => ['requireAdmin()'],
    'agent_checkin.php'                          => ['agent-auth'],
    'ai_settings.php'                            => ['require_permission(\'ai.manage\')'],
    'ajax/certbot.php'                           => ['require_permission(\'security.manage\')'],
    'ajax/fail2ban.php'                          => ['require_permission(\'security.manage\')'],
    'alerts.php'                                 => ['requireAdmin()'],
    'api/admin_queue.php'                        => ['requireAdmin()'],
    'api/agent_ping_data.php'                    => ['agent-auth'],
    'api/agent_speedtest_result.php'             => ['agent-auth'],
    'api/ai_scan.php'                            => ['require_permission(\'firewall.manage\')'],
    'api/apply_secure_lockdown.php'              => ['require_permission(\'security.manage\')'],
    'api/auto_onboard_firewall.php'              => ['web: requireAdmin()', 'requireAdmin()'],
    'api/cancel_command.php'                     => ['require_permission(\'command.cancel\')'],
    'api/change_release_track.php'               => ['requireAdmin()'],
    'api/check_firewall.php'                     => ['agent-auth'],
    'api/check_firewall_health.php'              => ['require_permission(\'agent.restart\')'],
    'api/check_updater_commands.php'             => ['agent-auth'],
    'api/check_updates.php'                      => ['requireAdmin()'],
    'api/clear_all_logs.php'                     => ['requireAdmin()'],
    'api/clear_update_flag.php'                  => ['agent-auth'],
    'api/create_backup.php'                      => ['require_permission(\'backup.create\')'],
    'api/create_firewall_for_enrollment.php'     => ['if (!isAdmin()) exit'],
    'api/delete_ai_report.php'                   => ['requireAdmin()'],
    'api/delete_backup.php'                      => ['requireAdmin()'],
    'api/delete_firewall.php'                    => ['requireAdmin()'],
    'api/download_restore_config.php'            => ['agent-auth'],
    'api/enrollment_key.php'                     => ['require_permission(\'firewall.enroll\')'],
    'api/geoip_api.php'                          => ['require_permission(in_array($action, [\'add\', \'toggle\', \'delete\'], true) ? \'firewall.manage\' : \'firewall.view\')'],
    'api/network_diagnostic.php'                 => ['require_permission(\'command.diagnostic\')'],
    'api/policy_artifact.php'                    => ['agent-auth'],
    'api/queue_action.php'                       => ['require_permission(\'command.view\')', 'require_permission(risk_to_capability((string)$built[\'risk\']), [ \'firewall_id\' => $firewall_id, \'object_type\' => \'firewall\', \'object_id\' => (string)$firewall_id, ])'],
    'api/queue_command.php'                      => ['if (!isAdmin()) exit'],
    'api/queue_management.php'                   => ['require_permission(in_array($action, [\'cleanup_old_requests\', \'clear_completed_requests\'], true) ? \'system.maintenance\' : \'dashboard.view\')'],
    'api/queue_os_update.php'                    => ['requireAdmin()'],
    'api/queue_updater_command.php'              => ['agent-auth'],
    'api/reboot_firewall.php'                    => ['requireAdmin()'],
    'api/record_latency.php'                     => ['agent-auth'],
    'api/remove_reverse_proxy.php'               => ['require_permission(\'tunnel.close\')'],
    'api/repair_agent_ssh.php'                   => ['require_permission(\'agent.update\')'],
    'api/report_updater_completion.php'          => ['agent-auth'],
    'api/request_queue.php'                      => ['require_permission($action === \'get_response\' ? \'firewall.view\' : \'firewall.manage\')'],
    'api/reset_agent.php'                        => ['requireAdmin()'],
    'api/run_bandwidth_test.php'                 => ['require_permission(\'command.diagnostic\')'],
    'api/run_diagnostic.php'                     => ['require_permission(\'command.diagnostic\')'],
    'api/save_ai_settings.php'                   => ['require_permission(\'firewall.manage\')'],
    'api/schedule_speedtest.php'                 => ['web: require_permission(\'command.diagnostic\')', 'require_permission(\'command.diagnostic\')'],
    'api/set_firewall_location.php'              => ['require_permission(\'firewall.manage\')'],
    'api/setup_reverse_proxy.php'                => ['require_permission(\'tunnel.open\')'],
    'api/ssh_install_agent.php'                  => ['require_permission(\'agent.update\')'],
    'api/start_ssh_tunnel.php'                   => ['require_permission(\'tunnel.open\')'],
    'api/system_update_stream.php'               => ['requireAdmin()'],
    'api/test_email.php'                         => ['require_permission(\'settings.manage\')'],
    'api/test_nginx.php'                         => ['require_permission(\'system.maintenance\')'],
    'api/toggle_alerts.php'                      => ['require_permission(\'settings.manage\')'],
    'api/trigger_agent_update.php'               => ['requireAdmin()'],
    'api/trigger_speedtest.php'                  => ['require_permission(\'command.diagnostic\')'],
    'api/trigger_update.php'                     => ['require_permission(\'update.install\')'],
    'api/tunnel_close.php'                       => ['require_permission(\'tunnel.close\')'],
    'api/tunnel_connect.php'                     => ['agent-auth'],
    'api/tunnel_health_check.php'                => ['requireAdmin()'],
    'api/tunnel_keep_alive.php'                  => ['agent-auth'],
    'api/tunnel_management.php'                  => ['require_permission(in_array($action, [\'reset_all\', \'kill_tunnel\', \'cleanup_zombies\'], true) ? \'tunnel.close\' : \'firewall.view\')'],
    'api/tunnel_reset.php'                       => ['require_permission($action === \'status\' ? \'firewall.view\' : \'tunnel.close\')'],
    'api/update_all_firewalls.php'               => ['requireAdmin()'],
    'api/update_complete.php'                    => ['agent-auth'],
    'api/update_docs.php'                        => ['require_permission(\'system.maintenance\')'],
    'api/update_firewall.php'                    => ['require_permission(\'update.install\')'],
    'api/update_status.php'                      => ['agent-auth'],
    'api/update_tunnel_status.php'               => ['agent-auth'],
    'api/updates/download.php'                   => ['require_permission(\'system.maintenance\')'],
    'api/upload_diagnostics.php'                 => ['agent-auth'],
    'api/wake_agent.php'                         => ['require_permission(\'command.diagnostic\')'],
    'api/wake_firewall.php'                      => ['require_permission(\'command.diagnostic\')'],
    'approved_commands.php'                      => ['requireAdmin()'],
    'branding.php'                               => ['require_permission(\'settings.manage\')'],
    'cleanup_agents.php'                         => ['cli-only'],
    'customers.php'                              => ['require_permission(\'customer.view\')'],
    'edit_user.php'                              => ['require_permission(\'user.manage\')'],
    'fail2ban.php'                               => ['require_permission(\'security.manage\')'],
    'firewall_capabilities.php'                  => ['require_permission(\'platform.view\')'],
    'firewall_details.php'                       => ['require_permission(\'firewall.manage\')'],
    'firewall_edit.php'                          => ['require_permission(\'firewall.manage\')'],
    'firewall_proxy.php'                         => ['requireAdmin()'],
    'firewall_view.php'                          => ['require_permission(\'firewall.manage\')'],
    'generate_enrollment_token.php'              => ['requireAdmin()'],
    'geoip_blocking.php'                         => ['requireAdmin()'],
    'integration_routes.php'                     => ['require_permission(\'integration.view\')'],
    'integrations.php'                           => ['require_permission(\'integration.view\')'],
    'manage_groups.php'                          => ['requireAdmin()'],
    'manage_tags.php'                            => ['requireAdmin()'],
    'nginx_logs.php'                             => ['requireAdmin()'],
    'package_builder.php'                        => ['require_permission(\'system.maintenance\')'],
    'proxy_settings.php'                         => ['requireAdmin()'],
    'security_scan.php'                          => ['requireAdmin()'],
    'settings.php'                               => ['requireAdmin()'],
    'snyk_scan_runner.php'                       => ['cli-only'],
    'start_tunnel_async.php'                     => ['requireAdmin()'],
    'system_backup.php'                          => ['requireAdmin()'],
    'system_update.php'                          => ['requireAdmin()'],
    'tunnel_daemon.php'                          => ['cli-only'],
    'tunnel_proxy.php'                           => ['if (!$token_ok && $session_owner !== null && (int)$session_owner !== (int)($_SESSION[\'user_id\'] ?? 0) && !isAdmin()) exit'],
    'update_docs_trigger.php'                    => ['if (!isAdmin()) exit'],
    'updates.php'                                => ['requireAdmin()'],
    'users.php'                                  => ['requireAdmin()'],
];

$examined = 0;
$found    = [];
foreach ($files as $rel) {
    if (preg_match('~^(tests|inc|scripts|cron|vendor|database|plugin|agent|development|node_modules|backups)/~', $rel)) { continue; }
    if (!is_file("$root/$rel")) { continue; }
    $r = rg_analyze((string) file_get_contents("$root/$rel"));
    if (!$r['writes']) { continue; }
    $examined++;
    $found[$rel] = true;

    if (isset($openToAllRoles[$rel]) || isset($notSessionEndpoints[$rel])) {
        continue;
    }
    check("{$rel}: every state-changing statement follows a role check",
        $r['ungated'] === [], 'ungated write(s) at line ' . implode(', ', $r['ungated']));
    $gates = array_values(array_unique($r['gates']));
    if (!isset($pinned[$rel])) {
        check("{$rel} is pinned with its gate", false,
            'add to $pinned: ' . var_export($rel, true) . ' => ' . var_export($gates, true));
        continue;
    }
    check("{$rel} declares " . implode(' + ', $pinned[$rel]), $gates === $pinned[$rel],
        'found ' . implode(' + ', $gates ?: ['no gate']));
}

check('state-changing pages were found', $examined > 100, "{$examined} examined");
foreach (array_merge(array_keys($pinned), array_keys($openToAllRoles), array_keys($notSessionEndpoints)) as $rel) {
    check("{$rel} is listed and still writes", isset($found[$rel]),
        'remove it from the list in tests/role_gate_test.php');
}

// ---------------------------------------------------------------------------
// The analysis itself: shapes that must fail, so a regression in it is caught.
// ---------------------------------------------------------------------------
$shape = fn(string $php) => rg_analyze("<?php\n" . $php)['ungated'] !== [];
check('analysis: a gate in one branch does not cover another branch',
    $shape("if (\$a) { require_permission('x'); }\nif (\$b) { db()->prepare('UPDATE settings SET value = 1'); }"));
check('analysis: an elseif notice is not a gate',
    $shape("if (\$a) { \$n = 1; } elseif (!can('x')) { \$n = 2; }\nif (\$p) { db()->prepare('DELETE FROM settings'); }"));
check('analysis: a gate after the write does not count',
    $shape("shell_exec('id');\nrequire_permission('x');"));
check('analysis: a session check alone is not a gate',
    $shape("if (!isset(\$_SESSION['user_id'])) { exit; }\nqueue_firewall_command(1, 'x');"));
check('analysis: a write hidden in a local function is found at its call',
    $shape("function w() { db()->prepare('INSERT INTO settings (name) VALUES (1)'); }\nw();"));
check('analysis: a gate opening the block that writes passes',
    !$shape("if (\$_SERVER['REQUEST_METHOD'] === 'POST') { require_permission('x'); db()->prepare('UPDATE settings SET value = 1'); }"));
check('analysis: a deny-and-exit guard passes',
    !$shape("if (!can('x')) { http_response_code(403); exit; }\nexec('id');"));

// ---------------------------------------------------------------------------
// Specific fixes, pinned by behaviour.
// ---------------------------------------------------------------------------
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
