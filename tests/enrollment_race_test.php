<?php
/**
 * An enrolment token enrols exactly one agent, even under concurrent use (3.80.3).
 *
 * The race is made deterministic rather than left to timing: this process
 * holds a row lock on the target firewall while several requests carrying the
 * same token start at once. Code that checks the token, writes the firewall and
 * only then marks the token used lets every request pass the check and queue on
 * that lock, so all of them "succeed". Code that claims the token first lets
 * exactly one through.
 *
 *   tests/lib/make_test_db.sh && DB_NAME=opnsense_fw_test php tests/enrollment_race_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';

$made = ['fw' => [], 'tok' => []];
register_shutdown_function(function () use (&$made) {
    if (db()->inTransaction()) { db()->rollBack(); }
    foreach ($made['tok'] as $t) {
        db()->prepare('DELETE FROM enrollment_tokens WHERE token = ?')->execute([$t]);
    }
    foreach ($made['fw'] as $id) {
        try { db()->prepare('DELETE FROM system_logs WHERE firewall_id = ?')->execute([$id]); } catch (Throwable $e) {}
        db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$id]);
    }
    db()->exec("DELETE FROM firewalls WHERE hostname LIKE '__race_%'");
});

function race_fw(string $tag, string $ip): int {
    global $made;
    db()->prepare('INSERT INTO firewalls (hostname, ip_address, status) VALUES (?, ?, "offline")')
        ->execute(['__race_' . $tag . '__', $ip]);
    return $made['fw'][] = (int)db()->lastInsertId();
}
function race_token(?int $fwId, string $expires = 'DATE_ADD(NOW(), INTERVAL 1 DAY)'): string {
    global $made;
    $t = bin2hex(random_bytes(32));
    db()->prepare("INSERT INTO enrollment_tokens (token, firewall_id, expires_at, used) VALUES (?, ?, {$expires}, 0)")
        ->execute([$t, $fwId]);
    return $made['tok'][] = $t;
}

/** Start one endpoint request per body, all at once; returns decoded responses. */
function parallel_requests(string $script, array $bodies, ?callable $whileRunning = null): array {
    $procs = [];
    foreach ($bodies as $i => $body) {
        $payload = json_encode([
            'script' => TEST_ROOT . '/' . $script,
            'server' => ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/' . $script, 'SCRIPT_NAME' => '/' . $script,
                         'REMOTE_ADDR' => '203.0.113.' . (20 + $i), 'HTTP_HOST' => 'opn.test', 'HTTPS' => 'on'],
            'get' => [], 'post' => [], 'files' => [], 'session' => null,
            'body' => json_encode($body),
        ]);
        $p = proc_open([PHP_BINARY, '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', TEST_ROOT . '/tests/runner.php'],
                       [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, TEST_ROOT);
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $procs[] = [$p, $pipes];
    }
    if ($whileRunning) { $whileRunning(); }
    $out = [];
    foreach ($procs as [$p, $pipes]) {
        $body = stream_get_contents($pipes[1]);
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($p);
        $body = preg_replace('/^__STATUS__:\d+\n/', '', (string)$body);
        $out[] = json_decode($body, true) ?? ['raw' => $body];
    }
    return $out;
}

/** Hold the firewall row while the requests start, so they all reach the race window. */
function hold_firewall_row(int $fwId): callable {
    return function () use ($fwId) {
        usleep(2500000);       // every request is past its token check and waiting
        db()->commit();        // release; the requests proceed in lock order
    };
}
function lock_firewall_row(int $fwId): void {
    db()->beginTransaction();
    db()->query("SELECT id FROM firewalls WHERE id = {$fwId} FOR UPDATE")->fetchAll();
}

$N = 6;

// ===========================================================================
T::group('agent_enroll.php: concurrent use of one token');

$fw  = race_fw('agent', '198.51.100.31');
$tok = race_token($fw);
$hw  = [];
for ($i = 0; $i < $N; $i++) { $hw[] = 'race-hw-' . $i . '-' . bin2hex(random_bytes(3)); }

lock_firewall_row($fw);
$res = parallel_requests('agent_enroll.php',
    array_map(fn($h) => ['token' => $tok, 'hardware_id' => $h, 'hostname' => 'race', 'agent_version' => '1.8.2'], $hw),
    hold_firewall_row($fw));

$winners = array_keys(array_filter($res, fn($r) => ($r['success'] ?? false) === true));
T::eq(1, count($winners), "exactly one of {$N} concurrent enrolments succeeds");
$tokenHw = (string)db()->query("SELECT COALESCE(hardware_id,'') FROM enrollment_tokens WHERE token = '{$tok}'")->fetchColumn();
$fwHw    = (string)db()->query("SELECT COALESCE(hardware_id,'') FROM firewalls WHERE id = {$fw}")->fetchColumn();
T::ok(count($winners) === 1 && $fwHw === $hw[$winners[0]], 'the firewall is bound to the winner, not the last writer');
T::eq($fwHw, $tokenHw, 'the token records the same hardware_id as the firewall');

// ===========================================================================
T::group('agent_enroll.php: single use, expiry and rollback');

$r = call_endpoint('agent_enroll.php', ['body' => ['token' => $tok, 'hardware_id' => 'late-' . bin2hex(random_bytes(3))]]);
T::eq(400, $r['status'], 'a used token is refused');

$expired = race_token($fw, 'DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
$r = call_endpoint('agent_enroll.php', ['body' => ['token' => $expired, 'hardware_id' => 'exp-' . bin2hex(random_bytes(3))]]);
T::eq(400, $r['status'], 'an expired token is refused');

$fw2 = race_fw('rollback', '198.51.100.32');
$tok2 = race_token($fw2);
// hostname is VARCHAR(128): the firewall update fails in strict mode.
$r = call_endpoint('agent_enroll.php', ['body' => ['token' => $tok2, 'hardware_id' => 'rb-1', 'hostname' => str_repeat('h', 300)]]);
$used = (int)db()->query("SELECT used FROM enrollment_tokens WHERE token = '{$tok2}'")->fetchColumn();
T::ok($r['status'] === 500 && $used === 0, "a failed firewall update leaves the token unused ({$r['status']}, used={$used})");
$r = call_endpoint('agent_enroll.php', ['body' => ['token' => $tok2, 'hardware_id' => 'rb-2', 'hostname' => 'ok']]);
T::ok($r['status'] === 200 && ($r['json']['success'] ?? false) === true, 'and it can then be redeemed');

// ===========================================================================
T::group('enroll_firewall_new.php: concurrent use of one token');

$ip  = '198.51.100.33';
$fw3 = race_fw('legacy', $ip);          // matched by ip_address: the update path
$tok3 = race_token(null);
lock_firewall_row($fw3);
$res = parallel_requests('enroll_firewall_new.php',
    array_map(fn($h) => ['token' => $tok3, 'hardware_id' => $h, 'hostname' => '__race_legacy_' . $h, 'ip_address' => $ip, 'wan_ip' => $ip], $hw),
    hold_firewall_row($fw3));
$wins = count(array_filter($res, fn($r) => ($r['success'] ?? false) === true));
T::eq(1, $wins, "exactly one of {$N} concurrent legacy enrolments succeeds");

exit(T::summary());
