<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);
/**
 * Generate the release signing key and publish a signed artifact manifest.
 *
 * Usage:
 *   php scripts/sign_release.php --generate-key   # one-time, appends to .env
 *   php scripts/sign_release.php --publish        # write downloads/manifest.json
 *   php scripts/sign_release.php --verify         # check the published manifest
 *   php scripts/sign_release.php --public-key     # print the pinnable public key
 *
 * The secret key never leaves .env. The public key is meant to be published and
 * is pinned into the agent installer so an agent will not accept a manifest
 * signed by anything else.
 *
 * @since 3.12.0
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("sign_release.php may only be run from the command line\n");
}

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/release_manifest.php';

$root         = dirname(__DIR__);
$downloadsDir = $root . '/downloads';
$manifestPath = $downloadsDir . '/manifest.json';

// ---------------------------------------------------------------------------
if (in_array('--generate-key', $argv, true)) {
    $envFile = $root . '/.env';
    if (!file_exists($envFile)) {
        fwrite(STDERR, "No .env at {$envFile}\n");
        exit(1);
    }
    $contents = file_get_contents($envFile);
    if (preg_match('/^\s*OPNMGR_RELEASE_SIGNING_KEY\s*=\s*\S+/m', $contents)) {
        echo "OPNMGR_RELEASE_SIGNING_KEY is already set - leaving it unchanged.\n";
        echo "Rotating it would invalidate the public key pinned in deployed agents.\n";
        echo "Public key: " . (release_public_key_b64() ?? '(unreadable)') . "\n";
        exit(0);
    }

    $pair   = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = sodium_crypto_sign_publickey($pair);

    $block = "\n# Release signing key (OPNMGR >= 3.12.0)\n"
           . "# Ed25519 secret key used to sign the agent update manifest.\n"
           . "# Back this up. Agents pin the matching public key:\n"
           . "#   " . base64_encode($public) . "\n"
           . 'OPNMGR_RELEASE_SIGNING_KEY=' . base64_encode($secret) . "\n";

    if (file_put_contents($envFile, $block, FILE_APPEND | LOCK_EX) === false) {
        fwrite(STDERR, "Failed to write {$envFile}\n");
        exit(1);
    }
    @chmod($envFile, 0640);

    echo "Generated release signing key and appended it to .env\n";
    echo "Public key (pin this in agents): " . base64_encode($public) . "\n";
    exit(0);
}

// ---------------------------------------------------------------------------
if (in_array('--public-key', $argv, true)) {
    $pk = release_public_key_b64();
    if ($pk === null) {
        fwrite(STDERR, "No signing key configured. Run --generate-key first.\n");
        exit(1);
    }
    echo $pk . "\n";
    exit(0);
}

// ---------------------------------------------------------------------------
if (in_array('--verify', $argv, true)) {
    if (!is_file($manifestPath)) {
        fwrite(STDERR, "No manifest at {$manifestPath}. Run --publish first.\n");
        exit(1);
    }
    $envelope = json_decode(file_get_contents($manifestPath), true);
    if (!is_array($envelope)) {
        fwrite(STDERR, "Manifest is not valid JSON\n");
        exit(1);
    }

    $result = verify_release_manifest($envelope, release_public_key_b64());
    if (!$result['ok']) {
        fwrite(STDERR, "INVALID: {$result['error']}\n");
        exit(1);
    }

    echo "Manifest signature is valid.\n";
    $bad = 0;
    foreach ($result['manifest']['artifacts'] as $name => $a) {
        $path = $root . '/' . $a['path'];
        if (!is_file($path)) {
            printf("  MISSING  %s\n", $name);
            $bad++;
            continue;
        }
        $actual = hash_file('sha256', $path);
        $ok = hash_equals($a['sha256'], $actual);
        printf("  %-8s %-48s %s\n", $ok ? 'ok' : 'MISMATCH', $name, substr($a['sha256'], 0, 16));
        if (!$ok) {
            $bad++;
        }
    }
    exit($bad === 0 ? 0 : 1);
}

// ---------------------------------------------------------------------------
// Default: publish
if (!is_dir($downloadsDir)) {
    fwrite(STDERR, "No downloads directory at {$downloadsDir}\n");
    exit(1);
}

$manifest = build_release_manifest($downloadsDir);

if (empty($manifest['artifacts'])) {
    fwrite(STDERR, "No publishable artifacts found under {$downloadsDir}\n");
    exit(1);
}

$signed = sign_release_manifest($manifest);
if (!$signed['ok']) {
    fwrite(STDERR, $signed['error'] . "\n");
    exit(1);
}

if (file_put_contents($manifestPath, json_encode($signed['envelope'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
    fwrite(STDERR, "Failed to write {$manifestPath}\n");
    exit(1);
}
@chmod($manifestPath, 0644);

printf("Published %s with %d artifact(s):\n", $manifestPath, count($manifest['artifacts']));
foreach ($manifest['artifacts'] as $name => $a) {
    printf("  %-48s %s  %s\n", $name, substr($a['sha256'], 0, 16), $a['version'] ?? '-');
}
echo "\nPublic key: " . release_public_key_b64() . "\n";
exit(0);
