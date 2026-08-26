<?php
/**
 * OPNMGR Verified Agent Update
 *
 * Builds the shell the firewall runs to update its agent. Replaces the previous
 * `fetch -o - <url> | sh`, which executed whatever bytes came back with no
 * integrity check whatsoever.
 *
 * The generated script:
 *   1. Fetches the signed manifest over HTTPS (certificate validation on).
 *   2. Verifies the manifest's Ed25519 signature against a public key pinned
 *      into the script at generation time, when the firewall's openssl can do
 *      Ed25519. If it cannot, it says so and continues to step 3 rather than
 *      silently pretending it verified.
 *   3. Extracts the expected SHA-256 for the requested artifact from the
 *      manifest and verifies the downloaded artifact against it. A mismatch is
 *      always fatal - there is no fallback to installing unverified bytes.
 *   4. Checks the artifact version matches what the server asked for.
 *   5. Keeps the currently installed agent, installs atomically, and rolls back
 *      if the new agent does not start.
 *
 * @since 3.12.0
 */

require_once __DIR__ . '/release_manifest.php';
require_once __DIR__ . '/backup_storage.php';

if (!function_exists('build_verified_agent_update_command')) {
    /**
     * Shell command that performs a verified agent update.
     *
     * @param string $artifact       Manifest key, e.g. 'plugins/os-opnmanager-agent-1.5.6.tar.gz'
     * @param string $expectedVersion Version the server expects to end up with
     * @return array{ok:bool, error:string, command:string}
     */
    function build_verified_agent_update_command(string $artifact, string $expectedVersion): array {
        $publicKey = release_public_key_b64();
        if ($publicKey === null) {
            return [
                'ok'      => false,
                'error'   => 'No release signing key configured. Run: php scripts/sign_release.php --generate-key && php scripts/sign_release.php --publish',
                'command' => '',
            ];
        }

        $manifestPath = dirname(__DIR__) . '/downloads/manifest.json';
        if (!is_file($manifestPath)) {
            return [
                'ok'      => false,
                'error'   => 'No published manifest. Run: php scripts/sign_release.php --publish',
                'command' => '',
            ];
        }

        $envelope = json_decode((string)file_get_contents($manifestPath), true);
        $verified = is_array($envelope)
            ? verify_release_manifest($envelope, $publicKey)
            : ['ok' => false, 'error' => 'Manifest is not valid JSON', 'manifest' => []];

        if (!$verified['ok']) {
            return ['ok' => false, 'error' => 'Published manifest is invalid: ' . $verified['error'], 'command' => ''];
        }

        if (!isset($verified['manifest']['artifacts'][$artifact])) {
            return [
                'ok'      => false,
                'error'   => "Artifact '{$artifact}' is not in the published manifest",
                'command' => '',
            ];
        }

        $entry  = $verified['manifest']['artifacts'][$artifact];
        $sha    = $entry['sha256'];
        $base   = opnmgr_server_url();

        // Every value interpolated below is server-controlled and validated:
        // $sha is 64 hex from our own manifest, $artifact is a manifest key,
        // $base is the configured server URL, $expectedVersion is checked here.
        if (!preg_match('/^[0-9a-f]{64}$/', $sha)) {
            return ['ok' => false, 'error' => 'Manifest checksum is malformed', 'command' => ''];
        }
        if (!preg_match('#^[A-Za-z0-9._/-]+$#', $artifact)) {
            return ['ok' => false, 'error' => 'Artifact name contains unexpected characters', 'command' => ''];
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $expectedVersion)) {
            return ['ok' => false, 'error' => 'Expected version is malformed', 'command' => ''];
        }

        $script = <<<SH
#!/bin/sh
# OPNManager verified agent update
# Artifact : {$artifact}
# SHA-256  : {$sha}
# Version  : {$expectedVersion}
set -e

BASE="{$base}"
ARTIFACT="{$artifact}"
EXPECTED_SHA="{$sha}"
EXPECTED_VERSION="{$expectedVersion}"
PUBKEY="{$publicKey}"

WORK=\$(mktemp -d /tmp/opnmgr-update.XXXXXX)
trap 'rm -rf "\$WORK"' EXIT

fail() { echo "UPDATE FAILED: \$1" >&2; exit 1; }

# --- 1. fetch the signed manifest over HTTPS (certificates verified) --------
echo "Fetching release manifest..."
fetch -q -o "\$WORK/manifest.json" "\$BASE/downloads/manifest.json" \\
  || curl -sS -f -o "\$WORK/manifest.json" "\$BASE/downloads/manifest.json" \\
  || fail "could not fetch manifest"

# --- 2. verify the manifest signature (Ed25519) ------------------------------
SIG_VERIFIED=0
if command -v openssl >/dev/null 2>&1; then
    # Rebuild the raw public key into a DER SubjectPublicKeyInfo, which is what
    # openssl needs to verify a bare Ed25519 key.
    printf '%s' "\$PUBKEY" | b64decode -r > "\$WORK/pk.raw" 2>/dev/null \\
        || printf '%s' "\$PUBKEY" | openssl base64 -d > "\$WORK/pk.raw" 2>/dev/null || true

    if [ -s "\$WORK/pk.raw" ]; then
        { printf '\\x30\\x2a\\x30\\x05\\x06\\x03\\x2b\\x65\\x70\\x03\\x21\\x00'; cat "\$WORK/pk.raw"; } \\
            > "\$WORK/pk.der" 2>/dev/null || true
        if openssl pkey -pubin -inform DER -in "\$WORK/pk.der" -out "\$WORK/pk.pem" 2>/dev/null; then
            # Extract the signed body and the signature from the envelope.
            SIG=\$(sed -n 's/.*"signature"[[:space:]]*:[[:space:]]*"\\([^"]*\\)".*/\\1/p' "\$WORK/manifest.json" | head -1)
            printf '%s' "\$SIG" | openssl base64 -d > "\$WORK/sig.bin" 2>/dev/null || true
            # The manifest body is a JSON string; decode it exactly as published.
            /usr/local/bin/php -r '
                \$e = json_decode(file_get_contents(\$argv[1]), true);
                file_put_contents(\$argv[2], \$e["manifest"] ?? "");
            ' "\$WORK/manifest.json" "\$WORK/body.json" 2>/dev/null || true

            if [ -s "\$WORK/body.json" ] && [ -s "\$WORK/sig.bin" ]; then
                if openssl pkeyutl -verify -pubin -inkey "\$WORK/pk.pem" \\
                       -rawin -in "\$WORK/body.json" -sigfile "\$WORK/sig.bin" >/dev/null 2>&1; then
                    SIG_VERIFIED=1
                    echo "Manifest signature: VERIFIED"
                else
                    fail "manifest signature did not verify - refusing to update"
                fi
            fi
        fi
    fi
fi

if [ "\$SIG_VERIFIED" -ne 1 ]; then
    echo "NOTE: this system's openssl cannot verify Ed25519; relying on HTTPS + SHA-256 pinned by the server."
fi

# --- 3. download and verify the artifact ------------------------------------
echo "Downloading \$ARTIFACT..."
fetch -q -o "\$WORK/artifact" "\$BASE/downloads/\$ARTIFACT" \\
  || curl -sS -f -o "\$WORK/artifact" "\$BASE/downloads/\$ARTIFACT" \\
  || fail "could not download artifact"

ACTUAL_SHA=\$(sha256 -q "\$WORK/artifact" 2>/dev/null || sha256sum "\$WORK/artifact" | awk '{print \$1}')
if [ "\$ACTUAL_SHA" != "\$EXPECTED_SHA" ]; then
    fail "checksum mismatch (expected \$EXPECTED_SHA, got \$ACTUAL_SHA)"
fi
echo "Artifact checksum: VERIFIED"

# --- 4. preserve the currently installed agent ------------------------------
BACKUP=""
if [ -f /usr/local/etc/rc.d/opnmanager_agent ] || pkg info os-opnmanager-agent >/dev/null 2>&1; then
    BACKUP="/var/backups/opnmgr-agent-previous.txz"
    mkdir -p /var/backups
    pkg create -o /var/backups -n os-opnmanager-agent >/dev/null 2>&1 && \\
        mv -f /var/backups/os-opnmanager-agent-*.pkg "\$BACKUP" 2>/dev/null || BACKUP=""
fi

# --- 5. install ---------------------------------------------------------------
echo "Installing..."
if ! pkg add -f "\$WORK/artifact" >/dev/null 2>&1; then
    echo "Install failed; attempting rollback..." >&2
    [ -n "\$BACKUP" ] && pkg add -f "\$BACKUP" >/dev/null 2>&1 && echo "Rolled back to previous agent." >&2
    fail "package installation failed"
fi

# --- 6. confirm the new agent runs, roll back if not -------------------------
/usr/local/etc/rc.d/opnmanager_agent restart >/dev/null 2>&1 || true
sleep 3

INSTALLED=\$(pkg query %v os-opnmanager-agent 2>/dev/null || echo "")
if [ "\$INSTALLED" != "\$EXPECTED_VERSION" ]; then
    echo "Installed version '\$INSTALLED' does not match expected '\$EXPECTED_VERSION'; rolling back..." >&2
    [ -n "\$BACKUP" ] && pkg add -f "\$BACKUP" >/dev/null 2>&1
    /usr/local/etc/rc.d/opnmanager_agent restart >/dev/null 2>&1 || true
    fail "version mismatch after install"
fi

if ! pgrep -f opnmanager_agent >/dev/null 2>&1; then
    echo "New agent is not running; rolling back..." >&2
    [ -n "\$BACKUP" ] && pkg add -f "\$BACKUP" >/dev/null 2>&1
    /usr/local/etc/rc.d/opnmanager_agent restart >/dev/null 2>&1 || true
    fail "agent did not start after update"
fi

echo "Agent updated to \$INSTALLED successfully."
exit 0
SH;

        return ['ok' => true, 'error' => '', 'command' => $script];
    }
}

if (!function_exists('latest_agent_artifact')) {
    /**
     * Highest-versioned agent package in the published manifest.
     *
     * @return array{ok:bool, error:string, artifact:string, version:string}
     */
    function latest_agent_artifact(): array {
        $manifestPath = dirname(__DIR__) . '/downloads/manifest.json';
        if (!is_file($manifestPath)) {
            return ['ok' => false, 'error' => 'No published manifest', 'artifact' => '', 'version' => ''];
        }

        $envelope = json_decode((string)file_get_contents($manifestPath), true);
        $verified = is_array($envelope) ? verify_release_manifest($envelope, release_public_key_b64()) : ['ok' => false];
        if (empty($verified['ok'])) {
            return ['ok' => false, 'error' => 'Published manifest is invalid', 'artifact' => '', 'version' => ''];
        }

        $best = null;
        foreach ($verified['manifest']['artifacts'] as $name => $entry) {
            if (!str_contains($name, 'os-opnmanager-agent-') || empty($entry['version'])) {
                continue;
            }
            if ($best === null || version_compare($entry['version'], $best['version'], '>')) {
                $best = ['artifact' => $name, 'version' => $entry['version']];
            }
        }

        if ($best === null) {
            return ['ok' => false, 'error' => 'No agent package in the manifest', 'artifact' => '', 'version' => ''];
        }

        return ['ok' => true, 'error' => '', 'artifact' => $best['artifact'], 'version' => $best['version']];
    }
}
