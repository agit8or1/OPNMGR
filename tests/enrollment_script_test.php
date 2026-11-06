<?php
/**
 * Enrollment installer generation: token validation, single use, safe shell.
 *
 * Every installer endpoint used to paste the supplied token between double
 * quotes in a root shell script, so `$(...)` in a "token" ran on the firewall
 * that executed the installer. This suite drives all four endpoints with
 * malformed, unknown, expired, used and hostile tokens, spoofs the Host
 * header, and executes a rendered installer's assignments under sh to prove
 * substituted values stay data.
 *
 *   tests/lib/make_test_db.sh && DB_NAME=opnsense_fw_test php tests/enrollment_script_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/enrollment_script.php';

$tmp = sys_get_temp_dir() . '/opnmgr-enroll-test-' . getmypid();
@mkdir($tmp . '/keys', 0700, true);
putenv('OPNMGR_KEY_DIR=' . $tmp . '/keys');   // inherited by endpoint subprocesses
$marker = $tmp . '/PWNED';

$prevUrl = db()->query("SELECT `value` FROM settings WHERE `name` = 'server_url'")->fetchColumn();
db()->prepare("INSERT INTO settings (`name`,`value`) VALUES ('server_url', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
    ->execute(['https://127.0.0.1']);

$tokens = [];
register_shutdown_function(function () use (&$tokens, $tmp, $prevUrl) {
    foreach ($tokens as $t) {
        db()->prepare('DELETE FROM enrollment_tokens WHERE token = ?')->execute([$t]);
    }
    db()->exec("DELETE FROM firewalls WHERE hostname LIKE '__enroll_test_%'");
    if ($prevUrl === false) {
        db()->exec("DELETE FROM settings WHERE `name` = 'server_url'");
    } else {
        db()->prepare("UPDATE settings SET `value` = ? WHERE `name` = 'server_url'")->execute([$prevUrl]);
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

function mk_token(string $expiresSql = 'DATE_ADD(NOW(), INTERVAL 1 HOUR)', int $used = 0): string {
    global $tokens;
    $t = bin2hex(random_bytes(32));
    db()->prepare("INSERT INTO enrollment_tokens (token, expires_at, used) VALUES (?, {$expiresSql}, ?)")->execute([$t, $used]);
    $tokens[] = $t;
    return $t;
}

$endpoints = [
    ['api/get_enroll_script.php', []],
    ['api/get_enroll_script_http.php', []],
    ['api/get_enrollment_script.php', []],
    ['enroll_firewall.php', ['action' => 'download']],
];

$hostile = [
    'command substitution'  => '$(touch ' . $marker . ')',
    'backticks'             => '`touch ' . $marker . '`',
    'quote breakout'        => 'aa"; touch ' . $marker . '; echo "',
    'single quote breakout' => "aa'; touch {$marker}; echo '",
    'newline'               => str_repeat('a', 64) . "\ntouch {$marker}",
    'hex plus payload'      => str_repeat('a', 64) . '$(id)',
    'uppercase hex'         => strtoupper(bin2hex(random_bytes(32))),
    'too short'             => bin2hex(random_bytes(16)),
    'empty'                 => '',
];

// ===========================================================================
T::group('Token format (library)');
foreach ($hostile as $label => $t) {
    $r = enrollment_render_installer($t);
    T::ok(!$r['ok'] && $r['reason'] === 'malformed' && $r['script'] === '', "refused: {$label}");
}
T::ok(!enrollment_render_installer(['array'])['ok'], 'a non-string token is refused');

// ===========================================================================
T::group('Token lifecycle (library)');
$unknown = bin2hex(random_bytes(32));
T::eq('unknown', enrollment_render_installer($unknown)['reason'], 'unknown token refused');
T::eq('expired', enrollment_render_installer(mk_token('DATE_SUB(NOW(), INTERVAL 1 MINUTE)'))['reason'], 'expired token refused');
T::eq('used', enrollment_render_installer(mk_token('DATE_ADD(NOW(), INTERVAL 1 HOUR)', 1))['reason'], 'used token refused');

$live = mk_token();
$key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFixtureKeyFixtureKeyFixtureKeyFixtureKeyAB opnmgr-enrollment';
$r = enrollment_render_installer($live, ['panel_url' => 'https://manager.example.net', 'mgmt_ip' => '198.51.100.1', 'ssh_key' => $key]);
T::ok($r['ok'], 'a live token renders an installer (' . $r['reason'] . ')');
T::ok(str_contains($r['script'], "ENROLLMENT_TOKEN='{$live}'"), 'the token is single-quoted');
T::ok(str_contains($r['script'], "PANEL_URL='https://manager.example.net'"), 'the URL is single-quoted');
T::ok(!preg_match('/^[A-Z_]+="__[A-Z_]+__"$/m', $r['script']), 'no placeholder assignment survives');
T::ok(str_contains($r['script'], '""|__MGMT_SERVER_IP__)'), 'the unrendered-copy guard is kept literal');
T::ok(enrollment_token_state($live)['ok'], 'rendering does not consume the token');

$bad = ['panel_url' => 'https://x$(touch ' . $marker . ').example', 'mgmt_ip' => '198.51.100.1', 'ssh_key' => $key];
T::eq('panel_url', enrollment_render_installer($live, $bad)['reason'], 'a manager URL with shell syntax is refused');
$bad = ['panel_url' => 'https://manager.example.net', 'mgmt_ip' => '198.51.100.1', 'ssh_key' => $key . "'; touch {$marker}; '"];
T::eq('ssh_key', enrollment_render_installer($live, $bad)['reason'], 'an SSH key with shell syntax is refused');
$bad = ['panel_url' => 'https://manager.example.net', 'mgmt_ip' => '1.2.3.4; id', 'ssh_key' => $key];
T::eq('mgmt_ip', enrollment_render_installer($live, $bad)['reason'], 'a non-IP management address is refused');

// The assignments of the rendered script, executed for real.
$head = implode("\n", array_filter(explode("\n", $r['script']), fn($l) => preg_match('/^(PANEL_URL|ENROLLMENT_TOKEN|SERVER_SSH_KEY|MGMT_SERVER_IP)=/', $l)));
file_put_contents($tmp . '/assign.sh', $head . "\nprintf '%s|%s|%s|%s' \"\$PANEL_URL\" \"\$ENROLLMENT_TOKEN\" \"\$MGMT_SERVER_IP\" \"\$SERVER_SSH_KEY\"\n");
$out = (string)shell_exec('sh ' . escapeshellarg($tmp . '/assign.sh'));
T::eq("https://manager.example.net|{$live}|198.51.100.1|{$key}", $out, 'the rendered assignments evaluate to exactly the values');

foreach (["\$(touch {$marker})", "`touch {$marker}`", "a'b\"c\$d\\e", "x'; touch {$marker}; '"] as $v) {
    file_put_contents($tmp . '/q.sh', 'V=' . enrollment_shell_quote($v) . "\nprintf '%s' \"\$V\"\n");
    T::eq($v, (string)shell_exec('sh ' . escapeshellarg($tmp . '/q.sh')), 'shell quoting round-trips: ' . substr($v, 0, 20));
}
T::ok(!file_exists($marker), 'no hostile value ever executed');

// ===========================================================================
T::group('Single-use claim');
$t = mk_token();
T::ok(enrollment_token_claim($t, 'hw-1'), 'a live token can be claimed');
T::ok(!enrollment_token_claim($t, 'hw-2'), 'but only once');
T::eq('used', enrollment_token_state($t)['reason'], 'and is then reported used');
T::ok(!enrollment_token_claim(mk_token('DATE_SUB(NOW(), INTERVAL 1 MINUTE)')), 'an expired token cannot be claimed');
T::ok(!enrollment_token_claim('$(id)'), 'a malformed token cannot be claimed');

// ===========================================================================
T::group('Every installer endpoint (HTTP)');
$liveHttp = mk_token();
foreach ($endpoints as [$script, $extra]) {
    foreach (['command substitution', 'quote breakout', 'too short'] as $label) {
        $res = call_endpoint($script, ['method' => 'GET', 'get' => $extra + ['token' => $hostile[$label]]]);
        T::ok($res['status'] === 400 && !str_contains($res['body'], 'touch'), "{$script}: {$label} refused ({$res['status']})");
    }
    $res = call_endpoint($script, ['method' => 'GET', 'get' => $extra + ['token' => $unknown]]);
    T::eq(404, $res['status'], "{$script}: unknown token refused");
    $res = call_endpoint($script, ['method' => 'GET', 'get' => $extra + ['token' => mk_token('DATE_SUB(NOW(), INTERVAL 1 MINUTE)')]]);
    T::eq(404, $res['status'], "{$script}: expired token refused");
    $res = call_endpoint($script, ['method' => 'GET', 'get' => $extra + ['token' => mk_token('DATE_ADD(NOW(), INTERVAL 1 HOUR)', 1)]]);
    T::eq(404, $res['status'], "{$script}: used token refused");

    $res = call_endpoint($script, ['method' => 'GET', 'get' => $extra + ['token' => $liveHttp],
                                   'headers' => ['Host' => 'evil.example']]);
    T::ok($res['status'] === 200 && str_contains($res['body'], "ENROLLMENT_TOKEN='{$liveHttp}'"),
          "{$script}: a live token gets the validated installer ({$res['status']}) " . substr($res['stderr'] ?? '', 0, 120));
    T::ok(!str_contains($res['body'], 'evil.example') && str_contains($res['body'], "PANEL_URL='https://127.0.0.1'"),
          "{$script}: the manager URL comes from configuration, not the Host header");
}
T::ok(!file_exists($marker), 'no endpoint test executed a payload');

// ===========================================================================
T::group('Enrollment consumes the token once (api/enroll.php)');
$t = mk_token();
$body = ['token' => $t, 'hostname' => '__enroll_test_a', 'hardware_id' => 'mac-02:00:00:00:00:01'];
$a = call_endpoint('api/enroll.php', ['body' => $body]);
T::ok($a['status'] === 200 && ($a['json']['success'] ?? false), 'first enrollment succeeds');
$b = call_endpoint('api/enroll.php', ['body' => ['hostname' => '__enroll_test_b'] + $body]);
T::eq(404, $b['status'], 'the same token is refused the second time');
$c = call_endpoint('api/enroll.php', ['body' => ['token' => mk_token('DATE_ADD(NOW(), INTERVAL 1 HOUR)', 1), 'hostname' => '__enroll_test_c']]);
T::eq(404, $c['status'], 'a token marked used elsewhere is refused (previously only expiry was checked)');
$d = call_endpoint('api/enroll.php', ['body' => ['token' => '$(id)', 'hostname' => '__enroll_test_d']]);
T::eq(400, $d['status'], 'a malformed token is refused');

$t2 = mk_token();
$e = call_endpoint('enroll_firewall.php', ['body' => ['token' => $t2, 'hostname' => '__enroll_test_e', 'ip_address' => '10.0.0.5']]);
T::ok($e['json']['success'] ?? false, 'legacy JSON enrollment succeeds once');
$f = call_endpoint('enroll_firewall.php', ['body' => ['token' => $t2, 'hostname' => '__enroll_test_f', 'ip_address' => '10.0.0.6']]);
T::ok(!($f['json']['success'] ?? true), 'and refuses the token the second time');

exit(T::summary());
