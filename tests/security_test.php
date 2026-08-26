<?php
/**
 * OPNMGR security regression tests.
 *
 * Covers the agent authentication and authorization guarantees from the P0
 * security work. Run with:  php tests/security_test.php
 *
 * Creates its own throwaway firewalls and commands and removes them again, so
 * it is safe to run against a live database.
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/crypto.php';

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------
final class Fixture {
    public static array $firewalls = [];
    public static array $commands  = [];

    /**
     * Create a throwaway firewall with known credentials.
     *
     * @param bool $confirmed Whether the API key is already pinned
     */
    public static function firewall(string $tag, bool $confirmed = true): array {
        $hw     = hash('md5', 'test-' . $tag . '-' . random_bytes(8));
        $key    = opnmgr_random_secret(32);
        $secret = opnmgr_random_secret(32);
        $host   = '__opnmgr_test_' . $tag . '__';

        db()->prepare('DELETE FROM firewalls WHERE hostname = ?')->execute([$host]);
        db()->prepare(
            'INSERT INTO firewalls (hostname, hardware_id, ip_address, agent_api_key, agent_api_secret,
                                    api_key_confirmed, api_key_issued_at, status)
             VALUES (?,?,?,?,?,?,NOW(),"online")'
        )->execute([$host, $hw, '198.51.100.7', opnmgr_encrypt($key), opnmgr_encrypt($secret),
                    $confirmed ? 1 : 0]);

        $id = (int)db()->lastInsertId();
        self::$firewalls[] = $id;

        return ['id' => $id, 'hardware_id' => $hw, 'api_key' => $key,
                'api_secret' => $secret, 'hostname' => $host];
    }

    public static function command(int $firewallId, string $status = 'sent'): int {
        db()->prepare(
            'INSERT INTO firewall_commands (firewall_id, command, command_type, description, status)
             VALUES (?, "echo test", "shell", "test command", ?)'
        )->execute([$firewallId, $status]);
        $id = (int)db()->lastInsertId();
        self::$commands[] = $id;
        return $id;
    }

    public static function cleanup(): void {
        foreach (self::$commands as $c) {
            db()->prepare('DELETE FROM firewall_commands WHERE id = ?')->execute([$c]);
        }
        foreach (self::$firewalls as $f) {
            foreach (['agent_request_nonces', 'firewall_commands', 'audit_log',
                      'firewall_agents', 'system_logs', 'backups'] as $t) {
                try {
                    db()->prepare("DELETE FROM {$t} WHERE firewall_id = ?")->execute([$f]);
                } catch (Throwable $e) { /* table may not have the column */ }
            }
            db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$f]);
        }
    }
}

/** Build the HMAC headers an upgraded agent would send. */
function sign_headers(string $secret, string $method, string $path, string $body, array $over = []): array {
    $ts    = $over['ts']    ?? (string)time();
    $nonce = $over['nonce'] ?? bin2hex(random_bytes(12));
    $canonical = implode("\n", [$method, $path, $ts, $nonce, hash('sha256', $body)]);
    $sig = $over['sig'] ?? hash_hmac('sha256', $canonical, $secret);
    return [
        'X-OPNMGR-Timestamp' => $ts,
        'X-OPNMGR-Nonce'     => $nonce,
        'X-OPNMGR-Signature' => $sig,
    ];
}

register_shutdown_function([Fixture::class, 'cleanup']);

// ===========================================================================
$fwA = Fixture::firewall('a');
$fwB = Fixture::firewall('b');

$checkinBody = function (array $fw, array $extra = []) {
    return json_encode(array_merge([
        'firewall_id'   => $fw['id'],
        'hardware_id'   => $fw['hardware_id'],
        'api_key'       => $fw['api_key'],
        'agent_version' => '3.12.0-test',
    ], $extra));
};

// ---------------------------------------------------------------------------
T::group('1-2. Check-in credential validation');

$r = call_endpoint('agent_checkin.php', ['body' => $checkinBody($fwA)]);
T::eq(200, $r['status'], 'valid hardware_id + API key is accepted');

$r = call_endpoint('agent_checkin.php', [
    'body' => $checkinBody($fwA, ['api_key' => 'wrong-key-entirely']),
]);
T::eq(401, $r['status'], 'wrong API key rejects check-in');
T::ok(($r['json']['message'] ?? '') === 'Authentication failed',
      'rejection message is generic (leaks no internal detail)');

$r = call_endpoint('agent_checkin.php', [
    'body' => $checkinBody($fwA, ['hardware_id' => str_repeat('0', 32)]),
]);
T::eq(403, $r['status'], 'wrong hardware ID rejects check-in');

$r = call_endpoint('agent_checkin.php', [
    'body' => json_encode(['firewall_id' => $fwA['id'], 'agent_version' => '3.12.0-test']),
]);
T::eq(401, $r['status'], 'missing hardware ID rejects check-in');

$r = call_endpoint('agent_checkin.php', [
    'body' => $checkinBody($fwA, ['api_key' => '']),
]);
T::eq(401, $r['status'], 'confirmed firewall may not downgrade to no API key');

// ---------------------------------------------------------------------------
T::group('3-4. Command result authorization');

$cmdA = Fixture::command($fwA['id']);
$cmdB = Fixture::command($fwB['id']);

$r = call_endpoint('api/command_result.php', ['body' => json_encode([
    'firewall_id' => $fwA['id'], 'hardware_id' => $fwA['hardware_id'],
    'api_key' => 'not-the-key', 'command_id' => $cmdA, 'result' => 'success',
])]);
T::eq(401, $r['status'], 'command result with wrong API key is rejected');

$r = call_endpoint('api/command_result.php', ['body' => json_encode([
    'firewall_id' => $fwA['id'], 'hardware_id' => $fwA['hardware_id'],
    'api_key' => $fwA['api_key'], 'command_id' => $cmdB, 'result' => 'success',
    'output' => base64_encode('pwned'),
])]);
T::eq(403, $r['status'], 'firewall A cannot submit a result for firewall B\'s command');

$row = db()->prepare('SELECT status, result FROM firewall_commands WHERE id = ?');
$row->execute([$cmdB]);
$after = $row->fetch(PDO::FETCH_ASSOC);
T::eq('sent', $after['status'], 'firewall B\'s command status was not modified');
T::ok(empty($after['result']), 'firewall B\'s command result was not overwritten');

$r = call_endpoint('api/command_result.php', ['body' => json_encode([
    'firewall_id' => $fwA['id'], 'hardware_id' => $fwA['hardware_id'],
    'api_key' => $fwA['api_key'], 'command_id' => $cmdA, 'result' => 'success',
    'output' => base64_encode('all good'),
])]);
T::eq(200, $r['status'], 'firewall A can report its own command');

$r = call_endpoint('api/command_result.php', ['body' => json_encode([
    'firewall_id' => $fwA['id'], 'hardware_id' => $fwA['hardware_id'],
    'api_key' => $fwA['api_key'], 'command_id' => $cmdA, 'result' => 'failed',
    'output' => base64_encode('overwrite attempt'),
])]);
T::eq(409, $r['status'], 'a completed command rejects a second result');

// ---------------------------------------------------------------------------
T::group('8-10. Signed request handling');

$body = $checkinBody($fwA);
$path = '/agent_checkin.php';

$r = call_endpoint('agent_checkin.php', [
    'body'    => $body,
    'headers' => sign_headers($fwA['api_secret'], 'POST', $path, $body),
]);
T::eq(200, $r['status'], 'correctly signed request is accepted');

$r = call_endpoint('agent_checkin.php', [
    'body'    => $body,
    'headers' => sign_headers($fwA['api_secret'], 'POST', $path, $body,
                              ['sig' => str_repeat('a', 64)]),
]);
T::eq(401, $r['status'], 'signed request with bad signature is rejected');

$replay = sign_headers($fwA['api_secret'], 'POST', $path, $body);
$r1 = call_endpoint('agent_checkin.php', ['body' => $body, 'headers' => $replay]);
$r2 = call_endpoint('agent_checkin.php', ['body' => $body, 'headers' => $replay]);
T::eq(200, $r1['status'], 'first use of a nonce succeeds');
T::eq(401, $r2['status'], 'replayed nonce is rejected');

$r = call_endpoint('agent_checkin.php', [
    'body'    => $body,
    'headers' => sign_headers($fwA['api_secret'], 'POST', $path, $body,
                              ['ts' => (string)(time() - 4000)]),
]);
T::eq(401, $r['status'], 'expired request timestamp is rejected');

// Signature must cover the body: reuse valid headers with a mutated body.
$tampered = $checkinBody($fwA, ['agent_version' => '9.9.9-tampered']);
$hdrs     = sign_headers($fwA['api_secret'], 'POST', $path, $body);
$r = call_endpoint('agent_checkin.php', ['body' => $tampered, 'headers' => $hdrs]);
T::eq(401, $r['status'], 'signature covers the request body (tampering rejected)');

// ---------------------------------------------------------------------------
T::group('Backward compatibility (installed agents must not break)');

$legacy = Fixture::firewall('legacy', false);
db()->prepare('UPDATE firewalls SET agent_api_key = NULL, agent_api_secret = NULL, api_key_confirmed = 0 WHERE id = ?')
    ->execute([$legacy['id']]);

$r = call_endpoint('agent_checkin.php', ['body' => json_encode([
    'firewall_id' => $legacy['id'], 'hardware_id' => $legacy['hardware_id'],
    'agent_version' => '1.5.6',
])]);
T::eq(200, $r['status'], 'pre-upgrade agent with only hardware_id still checks in');
T::ok(!empty($r['json']['agent_credentials']['api_key']),
      'server provisions an API key to the legacy agent in the response');
T::ok(!empty($r['json']['agent_credentials']['api_secret']),
      'server provisions a signing secret to the legacy agent');

$issued = $r['json']['agent_credentials']['api_key'] ?? '';

$stored = db()->prepare('SELECT agent_api_key FROM firewalls WHERE id = ?');
$stored->execute([$legacy['id']]);
$storedKey = $stored->fetchColumn();
T::ok(str_starts_with((string)$storedKey, 'enc:v1:'),
      'provisioned API key is encrypted at rest');
T::eq($issued, opnmgr_decrypt($storedKey), 'stored ciphertext decrypts to the issued key');

$r = call_endpoint('agent_checkin.php', ['body' => json_encode([
    'firewall_id' => $legacy['id'], 'hardware_id' => $legacy['hardware_id'],
    'api_key' => $issued, 'agent_version' => '3.12.0',
])]);
T::eq(200, $r['status'], 'agent presenting its new key is accepted');

$conf = db()->prepare('SELECT api_key_confirmed FROM firewalls WHERE id = ?');
$conf->execute([$legacy['id']]);
T::eq(1, (int)$conf->fetchColumn(), 'presenting the key pins it (auth now fails closed)');

$r = call_endpoint('agent_checkin.php', ['body' => json_encode([
    'firewall_id' => $legacy['id'], 'hardware_id' => $legacy['hardware_id'],
    'agent_version' => '1.5.6',
])]);
T::eq(401, $r['status'], 'after pinning, a downgrade to hardware_id-only is rejected');

// ---------------------------------------------------------------------------
T::group('Backup upload (arbitrary file write / RCE)');

$xml = '<?xml version="1.0"?><opnsense><system><hostname>t</hostname></system></opnsense>';
$tmpXml = sys_get_temp_dir() . '/opnmgr_test_good.xml';
$tmpPhp = sys_get_temp_dir() . '/opnmgr_test_shell.php';
file_put_contents($tmpXml, $xml);
file_put_contents($tmpPhp, '<?php system($_GET["c"]); ?>');

$r = call_endpoint('api/upload_backup.php', [
    'post'  => ['firewall_id' => $fwA['id'], 'hardware_id' => $fwA['hardware_id'],
                'api_key' => $fwA['api_key']],
    'files' => ['backup_file' => ['name' => 'shell.php', 'tmp_name' => $tmpPhp,
                                  'error' => UPLOAD_ERR_OK, 'size' => filesize($tmpPhp)]],
]);
T::eq(422, $r['status'], 'a PHP payload is rejected as not a valid config backup');
T::ok(!file_exists('/var/www/opnsense/backups/shell.php'),
      'no file was written into the web-root backups directory');

$r = call_endpoint('api/upload_backup.php', [
    'post'  => ['firewall_id' => $fwA['id'], 'hardware_id' => $fwA['hardware_id'],
                'api_key' => 'wrong'],
    'files' => ['backup_file' => ['name' => 'x.xml', 'tmp_name' => $tmpXml,
                                  'error' => UPLOAD_ERR_OK, 'size' => filesize($tmpXml)]],
]);
T::eq(401, $r['status'], 'backup upload with a bad API key is rejected');

@unlink($tmpXml); @unlink($tmpPhp);

exit(T::summary());
