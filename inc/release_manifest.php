<?php
/**
 * OPNMGR Signed Release Manifest
 *
 * Agent updates are fetched by a firewall and executed as root. Before this
 * module the update command was:
 *
 *     fetch -o - https://<server>/downloads/plugins/install_agent.sh | sh
 *
 * i.e. whatever bytes the server returned were executed, with no integrity
 * check at all. Anyone able to write to the downloads directory - or to sit
 * between the firewall and the server with a trusted-enough certificate - got
 * root on every managed firewall.
 *
 * The chain is now:
 *
 *   1. The operator publishes a manifest listing each artifact with its
 *      SHA-256 and version.
 *   2. The manifest is signed with an Ed25519 key whose secret half lives in
 *      .env (OPNMGR_RELEASE_SIGNING_KEY), never in the database or webroot.
 *   3. The agent fetches the manifest, verifies the signature against a public
 *      key pinned at install time, then verifies the artifact's SHA-256 against
 *      the manifest before installing.
 *   4. Installation is atomic and keeps the previous agent, so a failed update
 *      rolls back rather than leaving a firewall unmanaged.
 *
 * Verification failure is always fatal - the agent must never fall back to
 * installing an unverified artifact.
 *
 * @since 3.12.0
 */

require_once __DIR__ . '/crypto.php';

if (!defined('RELEASE_MANIFEST_VERSION')) {
    define('RELEASE_MANIFEST_VERSION', 1);
}

if (!function_exists('release_signing_keypair')) {
    /**
     * Ed25519 keypair used to sign release manifests.
     *
     * @return array{secret:string|null, public:string|null}
     */
    function release_signing_keypair(): array {
        $raw = getenv('OPNMGR_RELEASE_SIGNING_KEY') ?: ($_ENV['OPNMGR_RELEASE_SIGNING_KEY'] ?? '');
        $raw = is_string($raw) ? trim($raw) : '';

        if ($raw === '' || !extension_loaded('sodium')) {
            return ['secret' => null, 'public' => null];
        }

        $decoded = base64_decode($raw, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            error_log('OPNMGR: OPNMGR_RELEASE_SIGNING_KEY is not a valid Ed25519 secret key.');
            return ['secret' => null, 'public' => null];
        }

        return [
            'secret' => $decoded,
            'public' => sodium_crypto_sign_publickey_from_secretkey($decoded),
        ];
    }
}

if (!function_exists('release_public_key_b64')) {
    /**
     * Base64 public key agents pin. Safe to publish.
     */
    function release_public_key_b64(): ?string {
        $kp = release_signing_keypair();
        return $kp['public'] !== null ? base64_encode($kp['public']) : null;
    }
}

if (!function_exists('build_release_manifest')) {
    /**
     * Enumerate publishable artifacts and hash them.
     *
     * @param string $downloadsDir Directory holding release artifacts
     * @return array Manifest body (unsigned)
     */
    function build_release_manifest(string $downloadsDir): array {
        $artifacts = [];

        $patterns = [
            $downloadsDir . '/agent.sh',
            $downloadsDir . '/plugins/install_opnmanager_agent.sh',
            $downloadsDir . '/plugins/os-opnmanager-agent-*.tar.gz',
        ];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $relative = ltrim(str_replace($downloadsDir, '', $path), '/');
                $artifacts[$relative] = [
                    'path'    => 'downloads/' . $relative,
                    'sha256'  => hash_file('sha256', $path),
                    'size'    => filesize($path),
                    'version' => release_artifact_version($path),
                ];
            }
        }

        ksort($artifacts);

        return [
            'manifest_version' => RELEASE_MANIFEST_VERSION,
            'generated_at'     => gmdate('c'),
            'server_version'   => defined('APP_VERSION') ? APP_VERSION : 'unknown',
            'agent_version'    => trim(@file_get_contents($downloadsDir . '/AGENT_VERSION.txt') ?: '') ?: null,
            'artifacts'        => $artifacts,
        ];
    }
}

if (!function_exists('release_artifact_version')) {
    /**
     * Best-effort version for an artifact, from its filename or contents.
     */
    function release_artifact_version(string $path): ?string {
        if (preg_match('/-(\d+\.\d+\.\d+)\.tar\.gz$/', basename($path), $m)) {
            return $m[1];
        }
        $head = @file_get_contents($path, false, null, 0, 4096);
        if ($head !== false && preg_match('/AGENT_VERSION="?([0-9]+\.[0-9]+\.[0-9]+)"?/', $head, $m)) {
            return $m[1];
        }
        return null;
    }
}

if (!function_exists('sign_release_manifest')) {
    /**
     * Produce a signed manifest envelope.
     *
     * The signature covers the canonical JSON encoding of the manifest body, so
     * the agent verifies exactly the bytes it will parse.
     *
     * @return array{ok:bool, error:string, envelope:array}
     */
    function sign_release_manifest(array $manifest): array {
        $kp = release_signing_keypair();
        if ($kp['secret'] === null) {
            return [
                'ok'    => false,
                'error' => 'No release signing key configured. Run: php scripts/sign_release.php --generate-key',
                'envelope' => [],
            ];
        }

        $body = json_encode($manifest, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return ['ok' => false, 'error' => 'Could not encode manifest', 'envelope' => []];
        }

        $signature = sodium_crypto_sign_detached($body, $kp['secret']);

        return [
            'ok'    => true,
            'error' => '',
            'envelope' => [
                'algorithm'  => 'Ed25519',
                'public_key' => base64_encode($kp['public']),
                'signature'  => base64_encode($signature),
                'manifest'   => $body,
            ],
        ];
    }
}

if (!function_exists('verify_release_manifest')) {
    /**
     * Verify a signed manifest envelope.
     *
     * @param array       $envelope
     * @param string|null $expectedPublicKeyB64 Pinned key; when supplied the
     *                    envelope's own key must match it, otherwise an
     *                    attacker could simply re-sign with their own key.
     * @return array{ok:bool, error:string, manifest:array}
     */
    function verify_release_manifest(array $envelope, ?string $expectedPublicKeyB64 = null): array {
        foreach (['public_key', 'signature', 'manifest'] as $field) {
            if (empty($envelope[$field])) {
                return ['ok' => false, 'error' => "Manifest envelope is missing '{$field}'", 'manifest' => []];
            }
        }

        if ($expectedPublicKeyB64 !== null
            && !hash_equals($expectedPublicKeyB64, (string)$envelope['public_key'])) {
            return ['ok' => false, 'error' => 'Manifest was signed by an unexpected key', 'manifest' => []];
        }

        $pk  = base64_decode($envelope['public_key'], true);
        $sig = base64_decode($envelope['signature'], true);

        if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return ['ok' => false, 'error' => 'Malformed key or signature', 'manifest' => []];
        }

        if (!sodium_crypto_sign_verify_detached($sig, (string)$envelope['manifest'], $pk)) {
            return ['ok' => false, 'error' => 'Manifest signature is not valid', 'manifest' => []];
        }

        $manifest = json_decode((string)$envelope['manifest'], true);
        if (!is_array($manifest)) {
            return ['ok' => false, 'error' => 'Manifest body is not valid JSON', 'manifest' => []];
        }

        return ['ok' => true, 'error' => '', 'manifest' => $manifest];
    }
}
