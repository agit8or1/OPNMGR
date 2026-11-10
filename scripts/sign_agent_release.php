<?php
/**
 * Sign an OPNsense agent package for release.
 *
 *   php scripts/sign_agent_release.php --generate-key <secret-file>
 *       Create a release signing key. Writes the base64 secret to <secret-file>
 *       (mode 0600) and prints the public key for config/agent_release_keys.pub.
 *
 *   OPNMGR_AGENT_RELEASE_KEY=<base64 secret> \
 *   php scripts/sign_agent_release.php --sign <package.tar.gz> [--out <record.json>]
 *       Inspect the package and write its signed release record (default:
 *       os-opnmanager-agent-<version>.release.json beside the package).
 *
 *   php scripts/sign_agent_release.php --verify <package.tar.gz> [<record.json>]
 *       Verify a package and record exactly as a manager would before staging.
 *
 * The release workflow runs --sign with the key from the repository secret
 * OPNMGR_AGENT_RELEASE_KEY and then --verify. No database or .env is needed.
 *
 * @since 3.81.0
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("sign_agent_release.php may only be run from the command line\n");
}

require_once __DIR__ . '/../inc/agent_package.php';

$args = $argv;
array_shift($args);
$mode = $args[0] ?? '';

function opt(array $args, string $name): ?string {
    $i = array_search($name, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? $args[$i + 1] : null;
}

function fail(string $msg, array $steps = []): void {
    fwrite(STDERR, "ERROR: {$msg}\n");
    foreach ($steps as $s) {
        fwrite(STDERR, "  - {$s}\n");
    }
    exit(1);
}

if ($mode === '--generate-key') {
    $out = $args[1] ?? '';
    if ($out === '') {
        fail('usage: --generate-key <secret-file>');
    }
    if (file_exists($out)) {
        fail("{$out} exists; refusing to overwrite a signing key");
    }
    $pair = sodium_crypto_sign_keypair();
    $old = umask(0077);
    $ok = file_put_contents($out, base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n");
    umask($old);
    if ($ok === false) {
        fail("cannot write {$out}");
    }
    chmod($out, 0600);
    $pub = base64_encode(sodium_crypto_sign_publickey($pair));
    echo "Secret key written to {$out} (0600). Store it as the repository secret OPNMGR_AGENT_RELEASE_KEY.\n";
    echo "Public key (key id " . agent_release_key_id($pub) . ") for config/agent_release_keys.pub:\n{$pub}\n";
    exit(0);
}

if ($mode === '--sign') {
    $pkg = $args[1] ?? '';
    if ($pkg === '' || !is_file($pkg)) {
        fail('usage: --sign <package.tar.gz> [--out <record.json>]');
    }
    $raw = trim((string)(getenv('OPNMGR_AGENT_RELEASE_KEY') ?: ''));
    $secret = $raw !== '' ? base64_decode($raw, true) : false;
    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fail('OPNMGR_AGENT_RELEASE_KEY is not set to a base64 Ed25519 secret key', [
            'In GitHub: Settings > Secrets and variables > Actions > OPNMGR_AGENT_RELEASE_KEY',
            'Create one with: php scripts/sign_agent_release.php --generate-key <file>',
        ]);
    }
    $commit = getenv('GITHUB_SHA') ?: trim((string)@shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' rev-parse HEAD 2>/dev/null'));
    $r = agent_release_sign($pkg, $secret, ['source_commit' => $commit ?: null]);
    if (!$r['ok']) {
        fail($r['error']);
    }
    $pub = $r['envelope']['public_key'];
    if (!in_array($pub, agent_release_trusted_keys(), true)) {
        fail('the signing key (id ' . agent_release_key_id($pub) . ') is not pinned in config/agent_release_keys.pub', [
            'Managers would refuse this release. Pin the key, or sign with the pinned one.',
        ]);
    }
    $body = json_decode($r['envelope']['body'], true);
    $out = opt($args, '--out') ?? (dirname($pkg) . '/' . agent_release_record_name($body['agent_version']));
    if (file_put_contents($out, json_encode($r['envelope'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
        fail("cannot write {$out}");
    }
    printf("Signed %s\n  agent    %s\n  sha256   %s\n  key id   %s\n  record   %s\n",
        basename($pkg), $body['agent_version'], $body['sha256'], $r['envelope']['key_id'], $out);
    exit(0);
}

if ($mode === '--verify') {
    $pkg = $args[1] ?? '';
    $inspect = agent_package_inspect($pkg);
    $version = $inspect['ok'] ? $inspect['agent_version'] : agent_expected_version();
    $rec = $args[2] ?? (dirname($pkg) . '/' . agent_release_record_name($version));
    $raw = @file_get_contents($rec);
    if ($raw === false) {
        fail('release record not found: ' . $rec);
    }
    $v = agent_package_verify_pair($pkg, json_decode($raw, true));
    if (!$v['ok']) {
        fail("[{$v['code']}] {$v['error']}", $v['remediation']);
    }
    printf("OK  agent %s  sha256 %s\n", $v['body']['agent_version'], $v['body']['sha256']);
    exit(0);
}

fwrite(STDERR, "usage: sign_agent_release.php --generate-key <file> | --sign <pkg> [--out f] | --verify <pkg> [record]\n");
exit(2);
