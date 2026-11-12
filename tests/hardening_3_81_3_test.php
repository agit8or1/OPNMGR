<?php
/**
 * Pins the 3.81.3 security hardening, mostly by reading the source: each check
 * names a sink or a rule that was exploitable before and must not come back.
 *
 * Run with: php tests/hardening_3_81_3_test.php   (no database needed)
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
$src  = fn(string $rel): string => (string) @file_get_contents($root . '/' . $rel);

// --- firewall-supplied values are escaped where rendered ---------------------
$list = $src('firewalls.php');
foreach (['wan_tooltip', 'lan_tooltip', 'tooltip'] as $v) {
    check("firewalls.php escapes \${$v} in data-tooltip",
        str_contains($list, 'data-tooltip="<?php echo htmlspecialchars($' . $v . ', ENT_QUOTES); ?>"'));
    check("firewalls.php does not echo \${$v} raw",
        !str_contains($list, 'data-tooltip="<?php echo $' . $v . '; ?>"'));
}

$details = $src('firewall_details.php');
check('command output is escaped in the title attribute', !str_contains($details, 'title="${cmd.result}"'));
check('command output is escaped in the output modal', !str_contains($details, '>${output}</pre>'));
check('diagnostic output is escaped', !preg_match('/<span style="color: \$\{color\}">\$\{text\}<\/span>/', $details));
check('escapeHtml tolerates non-strings', str_contains($details, "String(text ?? '')"));

// No PHP value inside a single-quoted JS string in an event handler: the
// browser decodes &#039; back to ' before the handler runs.
$offenders = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) !== '.php' || preg_match('#/(vendor|node_modules|tests|\.archive|\.git)/#', $p)) {
        continue;
    }
    $s = (string) file_get_contents($p);
    if (preg_match('/on[a-z]+="[^"]*\'<\?(php echo|=)\s*htmlspecialchars\(/', $s)) {
        $offenders[] = substr($p, strlen($root) + 1);
    }
}
check('no htmlspecialchars() inside quoted JS in event handlers', !$offenders, implode(', ', $offenders));

// --- check-in validation ------------------------------------------------------
$checkin = $src('agent_checkin.php');
check('check-in validates network fields', str_contains($checkin, '$wan_gateway = $netField('));
check('auto-update passes the package checksum', str_contains($checkin, 'OPNMGR_AGENT_SHA256={$ready[\'sha256\']}'));

// --- tunnels ---------------------------------------------------------------
$nginxTpl = $src('scripts/manage_nginx_tunnel_proxy.php');
check('tunnel listeners bind to loopback', str_contains($nginxTpl, 'listen 127.0.0.1:{$https_port} ssl'));
check('tunnel listeners never bind every interface', !preg_match('/^\s*listen \{\$https_port\}/m', $nginxTpl));
$proxy = $src('tunnel_proxy.php');
check('ownerless tunnel sessions are admin-only', str_contains($proxy, '$session_owner === null ||'));
check('tunnel cookies are not logged', !preg_match('/error_log\([^)]*(Set-Cookie|Sending cookies|Saved cookie)/', $proxy));
foreach (['tunnel_auto_login.php', 'tunnel_direct.php', 'get_firewall_id.php', 'api/setup_reverse_proxy.php', 'ajax/certbot.php'] as $gone) {
    check("{$gone} is removed", !file_exists($root . '/' . $gone));
}

// --- root via sudo ------------------------------------------------------------
$sudoers = $src('deploy/sudoers-opnsense-manager');
check('shipped sudoers has no bash -c', !preg_match('/^[^#]*\/bin\/bash/m', $sudoers));
check('shipped sudoers has no bare kill/truncate/rm', !preg_match('/^[^#]*\/(kill|truncate|rm)\b/m', $sudoers));
foreach (['php', 'api', 'scripts', 'inc'] as $dir) {
    foreach (glob($root . '/' . ($dir === 'php' ? '' : $dir . '/') . '*.php') ?: [] as $f) {
        if (preg_match('/sudo (kill|rm|truncate|mv|ln)\b/', (string) file_get_contents($f))) {
            check(substr($f, strlen($root) + 1) . ' uses the wrapper, not sudo kill/rm/truncate/mv/ln', false);
        }
    }
}
$wrapper = $src('deploy/opnmgr-nginx-wrapper');
check('wrapper validates site names', str_contains($wrapper, '^(tunnel-session|firewall-proxy)-[0-9]{1,10}$'));
check('wrapper only kills ssh', str_contains($wrapper, '[ "$comm" = "ssh" ]'));

// --- authorized_keys -----------------------------------------------------------
require_once $root . '/inc/ssh_authorized_key.php';
$good = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBiN17ZRfM+3/bylcYO/NHmgTnASMGx5YtCMUS5qSEuL opnmgr';
check('a well-formed key yields a command', is_string(opnmgr_authorized_key_command($good)));
check('a key with a newline is refused', opnmgr_authorized_key_command("ssh-rsa AAAA\nssh-rsa BBBB") === null);
check('a key with shell syntax is refused', opnmgr_authorized_key_command('ssh-rsa AAAA $(id)') === null);
check('a key with options is refused', opnmgr_authorized_key_command('command="sh" ssh-rsa AAAA') === null);
check('onboarding no longer ships a built-in key',
    !str_contains($src('api/auto_onboard_firewall.php'), 'AAAAC3NzaC1lZDI1NTE5AAAAIBiN17ZRfM'));

// --- auth ---------------------------------------------------------------------
$bfp = $src('inc/brute_force_protection.php');
check('lockout is not keyed on username OR address', !str_contains($bfp, 'username = ? OR ip_address = ?'));
$v2 = $src('verify2fa.php');
check('verify2fa limits attempts', str_contains($v2, 'PENDING_2FA_MAX_TRIES') && str_contains($v2, 'record_failed_attempt'));
check('a correct password does not clear failures before 2FA',
    !preg_match("/=== '2fa'\) \{\s*\\\$bfp->clear_attempts/", $src('login.php')));
check('MFA exemption uses SCRIPT_NAME', str_contains($src('inc/auth.php'), "basename((string)(\$_SERVER['SCRIPT_NAME'] ?? ''))"));
check('disabling 2FA needs a current code', str_contains($src('twofactor_setup.php'), "\$_POST['disable_code']"));

// --- enrolment ------------------------------------------------------------------
$auth = $src('inc/agent_auth.php');
check('first claim compares the token hardware_id', str_contains($auth, 'agent_first_claim_allowed($firewallId, $hardwareId)'));
check('first-claim bind checks its row count', str_contains($auth, '$bind->rowCount() !== 1'));
foreach (['api/enroll.php', 'enroll_firewall.php', 'enroll_firewall_new.php'] as $f) {
    check("{$f} refuses to rewrite an enrolled firewall", str_contains($src($f), 'http_response_code(409)'));
}
check('reinstall command keeps the key out of the URL and argv',
    !str_contains($auth, 'api_key=$KEY') && str_contains($auth, 'curl -fsS --max-time 30 -H @-'));

// --- web exposure -----------------------------------------------------------
$snippet = $src('deploy/nginx-opnmgr-hardening.conf');
foreach (['/packages/', '/config/', '/sql/', '/download/'] as $loc) {
    check("nginx snippet denies {$loc}", str_contains($snippet, 'location ^~ ' . $loc . ' {'));
}
check('package URLs go through package_builder.php', !str_contains($src('package_builder.php'), '"/packages/{$file}"'));
check('Pushover token is not rendered into the page',
    !str_contains($src('alerts.php'), "value=\"<?php echo htmlspecialchars(\$settings['pushover_api_token']); ?>\""));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
