<?php
/**
 * OPNsense agent package: verification, staging and enrollment readiness.
 *
 * A clean install had nothing to enroll with. `downloads/` is gitignored, so a
 * fresh clone carried no os-opnmanager-agent-<version>.tar.gz, yet Settings and
 * Add Firewall printed the install one-liner anyway; the firewall fetched the
 * installer, the installer fetched a package that was not there, and enrollment
 * died on a 404 at the customer's console. The tarball was also never checked:
 * the installer ran whatever bytes came back.
 *
 * Now:
 *
 *   1. The release workflow builds the package from plugin/os-opnmanager-agent/
 *      (tracked source, reproducible bytes) and signs a small release record
 *      with the project's Ed25519 key. Its public half is pinned in
 *      config/agent_release_keys.pub.
 *   2. scripts/stage_agent_package.php (or the upload on Add Firewall) verifies
 *      that record, the package bytes and the package contents, and only then
 *      copies the package into downloads/plugins/ next to its record.
 *   3. agent_package_readiness() re-verifies on every page that would show an
 *      install command. Not ready means no command - just the reason and what
 *      to run - and a ready command carries the package's SHA-256, which the
 *      installer checks before it installs anything.
 *
 * Nothing here deletes or replaces a staged package. A version that is already
 * staged with different bytes is a conflict for a person to resolve, because a
 * firewall may already be running the bytes that are there.
 *
 * This is separate from inc/release_manifest.php. That manifest is signed by
 * each manager's own key and is what agents verify updates against; this record
 * is signed by the project and is what a manager verifies the package against.
 *
 * @since 3.81.0
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/server_identity.php';

if (!defined('AGENT_RELEASE_FORMAT')) {
    define('AGENT_RELEASE_FORMAT', 'opnmgr-agent-release/1');
}

if (!defined('AGENT_PACKAGE_REQUIRED_FILES')) {
    /** Files without which the installer would leave a half-installed agent. */
    define('AGENT_PACKAGE_REQUIRED_FILES', [
        'etc/inc/plugins.inc.d/opnmanageragent.inc',
        'etc/rc.d/opnmanager_agent',
        'opnsense/mvc/app/controllers/OPNsense/OPNManagerAgent/IndexController.php',
        'opnsense/mvc/app/models/OPNsense/OPNManagerAgent/Menu/Menu.xml',
        'opnsense/mvc/app/models/OPNsense/OPNManagerAgent/ACL/ACL.xml',
        'opnsense/scripts/OPNsense/OPNManagerAgent/agent.sh',
        'opnsense/scripts/OPNsense/OPNManagerAgent/checkin.sh',
        'opnsense/scripts/OPNsense/OPNManagerAgent/watchdog.sh',
        'opnsense/scripts/OPNsense/OPNManagerAgent/health_collect.py',
        'opnsense/service/conf/actions.d/actions_opnmanager_agent.conf',
    ]);
}

if (!function_exists('agent_package_root')) {
    function agent_package_root(): string {
        return dirname(__DIR__);
    }
}

if (!function_exists('agent_package_dir')) {
    /** Where staged packages are served from. Overridable for tests. */
    function agent_package_dir(): string {
        $env = getenv('OPNMGR_AGENT_PACKAGE_DIR');
        return ($env !== false && $env !== '') ? rtrim($env, '/') : agent_package_root() . '/downloads/plugins';
    }
}

if (!function_exists('agent_package_name')) {
    function agent_package_name(string $version): string {
        return 'os-opnmanager-agent-' . $version . '.tar.gz';
    }
}

if (!function_exists('agent_release_record_name')) {
    function agent_release_record_name(string $version): string {
        return 'os-opnmanager-agent-' . $version . '.release.json';
    }
}

if (!function_exists('agent_local_record_name')) {
    function agent_local_record_name(string $version): string {
        return 'os-opnmanager-agent-' . $version . '.local.json';
    }
}

if (!function_exists('agent_expected_version')) {
    /** The one agent version this manager installs on new firewalls. */
    function agent_expected_version(): string {
        return AGENT_VERSION;
    }
}

if (!function_exists('agent_release_trusted_keys')) {
    /**
     * Public keys a release record may be signed with.
     *
     * The project key is pinned in config/agent_release_keys.pub. A fork that
     * publishes its own releases adds its key there, or lists extra keys in
     * OPNMGR_AGENT_RELEASE_PUBKEYS (comma separated) without editing tracked
     * files. OPNMGR_AGENT_RELEASE_KEYS_FILE replaces the pinned file (tests).
     *
     * @return string[] base64 Ed25519 public keys
     */
    function agent_release_trusted_keys(): array {
        $file = getenv('OPNMGR_AGENT_RELEASE_KEYS_FILE') ?: (agent_package_root() . '/config/agent_release_keys.pub');
        $keys = [];
        foreach (preg_split('/\R/', (string)@file_get_contents($file)) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $keys[] = $line;
            }
        }
        $extra = getenv('OPNMGR_AGENT_RELEASE_PUBKEYS') ?: ($_ENV['OPNMGR_AGENT_RELEASE_PUBKEYS'] ?? '');
        foreach (explode(',', (string)$extra) as $k) {
            if (trim($k) !== '') {
                $keys[] = trim($k);
            }
        }
        return array_values(array_unique(array_filter($keys, function ($k) {
            $raw = base64_decode($k, true);
            return $raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
        })));
    }
}

if (!function_exists('agent_release_key_id')) {
    function agent_release_key_id(string $publicKeyB64): string {
        return substr(hash('sha256', (string)base64_decode($publicKeyB64, true)), 0, 16);
    }
}

if (!function_exists('agent_package_error')) {
    /**
     * A failure with what the operator should do about it.
     *
     * @return array{ok:false, code:string, error:string, remediation:string[]}
     */
    function agent_package_error(string $code, string $error, array $remediation = []): array {
        return ['ok' => false, 'code' => $code, 'error' => $error, 'remediation' => $remediation];
    }
}

if (!function_exists('agent_package_remediation')) {
    /** The ways to get a good package onto this manager, in order of preference. */
    function agent_package_remediation(?string $version = null): array {
        $v   = $version ?? agent_expected_version();
        $app = defined('APP_VERSION') ? APP_VERSION : '';
        return [
            'Download and verify it: php scripts/stage_agent_package.php',
            sprintf('Offline: copy %s and %s from the v%s release, then run '
                    . 'php scripts/stage_agent_package.php --import <directory>, or upload both files on Add Firewall',
                    agent_package_name($v), agent_release_record_name($v), $app),
            'No release available: php scripts/stage_agent_package.php --build-local builds it from the tracked source',
        ];
    }
}

// ---------------------------------------------------------------------------
// Release records
// ---------------------------------------------------------------------------

if (!function_exists('agent_release_sign')) {
    /**
     * Sign a release record for a built package. Used by the release workflow.
     *
     * @return array{ok:bool, error:string, envelope:array}
     */
    function agent_release_sign(string $packagePath, string $secretKey, array $meta = []): array {
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return ['ok' => false, 'error' => 'The signing key is not an Ed25519 secret key', 'envelope' => []];
        }
        $inspect = agent_package_inspect($packagePath);
        if (!$inspect['ok']) {
            return ['ok' => false, 'error' => $inspect['error'], 'envelope' => []];
        }
        $version = $inspect['agent_version'];
        if (basename($packagePath) !== agent_package_name($version)) {
            return ['ok' => false, 'error' => sprintf('%s contains agent %s; it must be named %s',
                basename($packagePath), $version, agent_package_name($version)), 'envelope' => []];
        }

        $body = [
            'platform'            => 'opnsense',
            'package'             => agent_package_name($version),
            'agent_version'       => $version,
            'sha256'              => hash_file('sha256', $packagePath),
            'size'                => filesize($packagePath),
            'min_manager_version' => defined('AGENT_MIN_MANAGER_VERSION') ? AGENT_MIN_MANAGER_VERSION : APP_VERSION,
            'app_version'         => $meta['app_version'] ?? APP_VERSION,
            'source_commit'       => $meta['source_commit'] ?? null,
            'built_at'            => $meta['built_at'] ?? gmdate('c'),
        ];
        $json   = json_encode($body, JSON_UNESCAPED_SLASHES);
        $public = sodium_crypto_sign_publickey_from_secretkey($secretKey);

        return ['ok' => true, 'error' => '', 'envelope' => [
            'format'     => AGENT_RELEASE_FORMAT,
            'algorithm'  => 'Ed25519',
            'public_key' => base64_encode($public),
            'key_id'     => agent_release_key_id(base64_encode($public)),
            'signature'  => base64_encode(sodium_crypto_sign_detached($json, $secretKey)),
            'body'       => $json,
        ]];
    }
}

if (!function_exists('agent_release_verify')) {
    /**
     * Verify a release record's signature and that it describes a package this
     * manager can install. Does not look at the package bytes.
     *
     * @param mixed $envelope Decoded JSON
     * @return array ok + body, or an agent_package_error()
     */
    function agent_release_verify($envelope, ?string $expectedVersion = null): array {
        $expectedVersion = $expectedVersion ?? agent_expected_version();
        $rem = agent_package_remediation($expectedVersion);

        if (!is_array($envelope) || ($envelope['format'] ?? '') !== AGENT_RELEASE_FORMAT) {
            return agent_package_error('release_invalid',
                'The release record is not an OPNManager agent release (wrong or missing format field)', $rem);
        }
        foreach (['public_key', 'signature', 'body'] as $f) {
            if (!is_string($envelope[$f] ?? null) || $envelope[$f] === '') {
                return agent_package_error('release_invalid', "The release record is missing '{$f}'", $rem);
            }
        }

        $trusted = agent_release_trusted_keys();
        if (!$trusted) {
            return agent_package_error('no_trusted_keys',
                'No release signing key is pinned (config/agent_release_keys.pub is missing or empty)',
                ['Restore config/agent_release_keys.pub from the repository, or use --build-local']);
        }
        if (!in_array($envelope['public_key'], $trusted, true)) {
            return agent_package_error('untrusted_key', sprintf(
                'The release record is signed by key %s, which this manager does not trust',
                agent_release_key_id($envelope['public_key'])), array_merge(
                ['If this is your own fork\'s release, add its public key to config/agent_release_keys.pub or OPNMGR_AGENT_RELEASE_PUBKEYS'], $rem));
        }

        $pk  = base64_decode($envelope['public_key'], true);
        $sig = base64_decode($envelope['signature'], true);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($sig, $envelope['body'], $pk)) {
            return agent_package_error('signature_invalid',
                'The release record\'s signature does not verify: it was altered or corrupted after signing', $rem);
        }

        $body = json_decode($envelope['body'], true);
        if (!is_array($body) || !isset($body['agent_version'], $body['sha256'], $body['package'])) {
            return agent_package_error('release_invalid', 'The signed release body is incomplete', $rem);
        }
        return agent_release_check_compat($body, $expectedVersion);
    }
}

if (!function_exists('agent_release_check_compat')) {
    function agent_release_check_compat(array $body, string $expectedVersion): array {
        $rem = agent_package_remediation($expectedVersion);
        if (($body['platform'] ?? '') !== 'opnsense') {
            return agent_package_error('incompatible_platform', sprintf(
                'This is a %s agent package; OPNsense firewalls need the OPNsense agent',
                $body['platform'] ?? 'non-OPNsense'), $rem);
        }
        if (!preg_match('/^\d+\.\d+\.\d+$/', (string)$body['agent_version'])) {
            return agent_package_error('release_invalid', 'The release has no valid agent version', $rem);
        }
        if ($body['agent_version'] !== $expectedVersion) {
            return agent_package_error('incompatible_version', sprintf(
                'The package is agent %s, but this manager (%s) installs agent %s',
                $body['agent_version'], APP_VERSION, $expectedVersion), $rem);
        }
        $min = (string)($body['min_manager_version'] ?? '');
        if ($min !== '' && version_compare(APP_VERSION, $min, '<')) {
            return agent_package_error('incompatible_manager', sprintf(
                'Agent %s needs OPNManager %s or newer; this manager is %s. Upgrade the manager first',
                $body['agent_version'], $min, APP_VERSION), []);
        }
        if (($body['package'] ?? '') !== agent_package_name($expectedVersion)
            || !preg_match('/^[0-9a-f]{64}$/', (string)$body['sha256'])) {
            return agent_package_error('release_invalid', 'The release names an unexpected package or checksum', $rem);
        }
        return ['ok' => true, 'code' => '', 'error' => '', 'remediation' => [], 'body' => $body];
    }
}

// ---------------------------------------------------------------------------
// Package contents
// ---------------------------------------------------------------------------

if (!function_exists('agent_package_inspect')) {
    /**
     * Look inside a package without installing it.
     *
     * Rejects anything the installer could mis-handle: archives that are not
     * gzip'd tar, absolute or parent-relative paths, links and device nodes,
     * a missing required file, an agent.sh that does not declare its version,
     * and the pfSense agent (which has a different layout entirely).
     *
     * @return array ok + agent_version + files, or an agent_package_error()
     */
    function agent_package_inspect(string $path): array {
        $rem = agent_package_remediation();
        if (!is_file($path) || !is_readable($path)) {
            return agent_package_error('not_staged', 'The package file is missing: ' . basename($path), $rem);
        }
        $fh = fopen($path, 'rb');
        $magic = $fh ? fread($fh, 2) : '';
        if ($fh) { fclose($fh); }
        if ($magic !== "\x1f\x8b") {
            return agent_package_error('package_corrupted', basename($path) . ' is not a gzip archive (truncated or the wrong file)', $rem);
        }

        exec('LC_ALL=C tar -tvzf ' . escapeshellarg($path) . ' 2>/dev/null', $lines, $rc);
        if ($rc !== 0) {
            $why = trim((string)shell_exec('LC_ALL=C tar -tzf ' . escapeshellarg($path) . ' 2>&1 >/dev/null | tail -1'));
            return agent_package_error('package_corrupted', basename($path) . ' could not be read as a tar.gz archive ('
                . substr($why, 0, 160) . ')', $rem);
        }
        exec('LC_ALL=C tar -tzf ' . escapeshellarg($path) . ' 2>/dev/null', $names, $rc);
        if ($rc !== 0 || count($names) !== count($lines)) {
            return agent_package_error('package_corrupted', basename($path) . ' could not be listed', $rem);
        }

        $files = [];
        foreach ($names as $i => $name) {
            $type = $lines[$i][0] ?? '?';
            // `tar -C dir .` writes ./etc/...; the installer extracts both forms alike.
            if ($name === './' || $name === '.') {
                continue;
            }
            if (str_starts_with($name, './')) {
                $name = substr($name, 2);
            }
            if (!in_array($type, ['-', 'd'], true)) {
                return agent_package_error('package_invalid', "The package contains a link or special file: {$name}", $rem);
            }
            if ($name === '' || $name[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $name)
                || !preg_match('#^[A-Za-z0-9._/-]+$#', $name)) {
                return agent_package_error('package_invalid', "The package contains an unsafe path: {$name}", $rem);
            }
            if ($type === '-') {
                $files[] = $name;
            }
        }

        if (in_array('opnmgr_agent.sh', array_map('basename', $files), true)
            || preg_grep('#^usr/local/pkg/#', $files)) {
            return agent_package_error('incompatible_platform',
                'This is the pfSense agent package; OPNsense firewalls need os-opnmanager-agent', $rem);
        }

        $missing = array_values(array_diff(AGENT_PACKAGE_REQUIRED_FILES, $files));
        if ($missing) {
            return agent_package_error('package_invalid', 'The package is missing ' . implode(', ', $missing), $rem);
        }

        $member = in_array('./opnsense/scripts/OPNsense/OPNManagerAgent/agent.sh', $names, true)
            ? './opnsense/scripts/OPNsense/OPNManagerAgent/agent.sh' : 'opnsense/scripts/OPNsense/OPNManagerAgent/agent.sh';
        $agentSh = shell_exec('tar -xzOf ' . escapeshellarg($path) . ' ' . escapeshellarg($member) . ' 2>/dev/null');
        if (!is_string($agentSh) || !preg_match('/^AGENT_VERSION="(\d+\.\d+\.\d+)"/m', $agentSh, $m)) {
            return agent_package_error('package_invalid', 'The packaged agent.sh does not declare AGENT_VERSION', $rem);
        }

        return ['ok' => true, 'code' => '', 'error' => '', 'remediation' => [],
                'agent_version' => $m[1], 'files' => $files];
    }
}

// ---------------------------------------------------------------------------
// Staging
// ---------------------------------------------------------------------------

if (!function_exists('agent_package_verify_pair')) {
    /**
     * Verify a package against its signed release record, fully: signature,
     * compatibility, size, checksum and contents.
     */
    function agent_package_verify_pair(string $packagePath, $envelope, ?string $expectedVersion = null): array {
        $expectedVersion = $expectedVersion ?? agent_expected_version();
        $rem = agent_package_remediation($expectedVersion);

        $rel = agent_release_verify($envelope, $expectedVersion);
        if (!$rel['ok']) {
            return $rel;
        }
        $body = $rel['body'];
        if (!is_file($packagePath)) {
            return agent_package_error('not_staged', 'The package file is missing: ' . $body['package'], $rem);
        }
        if (isset($body['size']) && (int)$body['size'] !== filesize($packagePath)) {
            return agent_package_error('package_corrupted', sprintf(
                '%s is %d bytes; the signed release says %d. It is truncated or a different file',
                basename($packagePath), filesize($packagePath), (int)$body['size']), $rem);
        }
        if (!hash_equals($body['sha256'], hash_file('sha256', $packagePath))) {
            return agent_package_error('package_corrupted', sprintf(
                '%s does not match the signed checksum; it was altered or damaged in transit',
                basename($packagePath)), $rem);
        }
        $inspect = agent_package_inspect($packagePath);
        if (!$inspect['ok']) {
            return $inspect;
        }
        if ($inspect['agent_version'] !== $body['agent_version']) {
            return agent_package_error('package_invalid', sprintf(
                'The signed release says agent %s but the packaged agent.sh declares %s',
                $body['agent_version'], $inspect['agent_version']), $rem);
        }
        return ['ok' => true, 'code' => '', 'error' => '', 'remediation' => [], 'body' => $body];
    }
}

if (!function_exists('agent_package_install_file')) {
    /**
     * Copy a verified file into the package directory without ever replacing
     * different bytes. Atomic: written beside the target, then renamed.
     *
     * @return array{ok:bool, code:string, error:string, remediation:array, changed:bool}
     */
    function agent_package_install_file(string $source, string $destName, ?string $dir = null): array {
        $dir  = $dir ?? agent_package_dir();
        $dest = $dir . '/' . $destName;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return agent_package_error('stage_failed', "Cannot create {$dir}", ['Check the directory is writable by the user running this']) + ['changed' => false];
        }
        if (is_file($dest)) {
            if (hash_equals(hash_file('sha256', $dest), hash_file('sha256', $source))) {
                return ['ok' => true, 'code' => '', 'error' => '', 'remediation' => [], 'changed' => false];
            }
            return agent_package_error('conflict', sprintf(
                '%s already exists on this manager with different contents. It was left in place: '
                . 'firewalls may already run those bytes', $destName), [
                'Compare it with the release (sha256sum ' . $dest . ') before deciding anything',
                'If it is known bad and was never installed anywhere, move it aside by hand and stage again',
            ]) + ['changed' => false];
        }
        $tmp = $dest . '.staging-' . bin2hex(random_bytes(4));
        if (!@copy($source, $tmp)) {
            return agent_package_error('stage_failed', "Cannot write {$dest}", ['Check the directory is writable by the user running this']) + ['changed' => false];
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            return agent_package_error('stage_failed', "Cannot move the package into {$dir}", []) + ['changed' => false];
        }
        return ['ok' => true, 'code' => '', 'error' => '', 'remediation' => [], 'changed' => true];
    }
}

if (!function_exists('agent_package_stage_release')) {
    /**
     * Verify a package and its release record, then stage both.
     *
     * The record is staged first and only once the package itself is in
     * place is the pair complete; readiness re-verifies the pair, so an
     * interruption between the two can only ever look "not staged".
     */
    function agent_package_stage_release(string $packagePath, string $recordPath, ?string $dir = null): array {
        $raw = @file_get_contents($recordPath);
        if ($raw === false) {
            return agent_package_error('release_missing', 'The signed release record is missing: ' . basename($recordPath),
                agent_package_remediation());
        }
        $envelope = json_decode($raw, true);
        $v = agent_package_verify_pair($packagePath, $envelope);
        if (!$v['ok']) {
            return $v;
        }
        $version = $v['body']['agent_version'];

        $pkg = agent_package_install_file($packagePath, agent_package_name($version), $dir);
        if (!$pkg['ok']) {
            return $pkg;
        }
        $rec = agent_package_install_file($recordPath, agent_release_record_name($version), $dir);
        if (!$rec['ok'] && $rec['code'] === 'conflict') {
            // Same package bytes, a different (but valid) record - a re-signed
            // release. The staged record is just as valid; keep it.
            $rec = ['ok' => true, 'changed' => false];
        }
        if (!$rec['ok']) {
            return $rec;
        }
        return ['ok' => true, 'code' => '', 'error' => '', 'remediation' => [], 'version' => $version,
                'sha256' => $v['body']['sha256'], 'source' => 'signed-release',
                'changed' => $pkg['changed'] || $rec['changed']];
    }
}

if (!function_exists('agent_package_stage_local')) {
    /**
     * Stage a package built on this manager from its own tracked source.
     *
     * Trust comes from the source tree rather than a signature, so the record
     * says so. Also used to adopt a package that was already on the manager
     * before 3.81.0 (no record) when it is byte-identical to a fresh build -
     * the build is reproducible, so identical bytes mean identical source.
     */
    function agent_package_stage_local(string $builtPath, array $meta = [], ?string $dir = null): array {
        $dir = $dir ?? agent_package_dir();
        $inspect = agent_package_inspect($builtPath);
        if (!$inspect['ok']) {
            return $inspect;
        }
        $version = $inspect['agent_version'];
        if ($version !== agent_expected_version()) {
            return agent_package_error('incompatible_version', sprintf(
                'The source tree builds agent %s, but this manager installs %s. The checkout and inc/version.php disagree',
                $version, agent_expected_version()), []);
        }
        $sha = hash_file('sha256', $builtPath);
        $pkg = agent_package_install_file($builtPath, agent_package_name($version), $dir);
        if (!$pkg['ok']) {
            return $pkg;
        }
        $record = [
            'format'        => AGENT_RELEASE_FORMAT . '+local',
            'source'        => $meta['source'] ?? 'local-build',
            'agent_version' => $version,
            'package'       => agent_package_name($version),
            'sha256'        => $sha,
            'size'          => filesize($builtPath),
            'source_commit' => $meta['source_commit'] ?? null,
            'built_at'      => gmdate('c'),
        ];
        $recPath = $dir . '/' . agent_local_record_name($version);
        if (@file_put_contents($recPath, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
            return agent_package_error('stage_failed', "Cannot write {$recPath}", []);
        }
        @chmod($recPath, 0644);
        return ['ok' => true, 'code' => '', 'error' => '', 'remediation' => [], 'version' => $version,
                'sha256' => $sha, 'source' => $record['source'], 'changed' => $pkg['changed']];
    }
}

// ---------------------------------------------------------------------------
// Readiness and install commands
// ---------------------------------------------------------------------------

if (!function_exists('agent_package_readiness')) {
    /**
     * Whether a new firewall can be enrolled right now, and if not, why.
     *
     * Re-verifies the staged pair each time (one signature check and one
     * SHA-256 of a ~35 KB file), so a package replaced or damaged after
     * staging is caught before a command pointing at it is shown.
     *
     * @return array ok + version + sha256 + source, or an agent_package_error()
     */
    function agent_package_readiness(?string $dir = null): array {
        $dir     = $dir ?? agent_package_dir();
        $version = agent_expected_version();
        $pkgPath = $dir . '/' . agent_package_name($version);
        $relPath = $dir . '/' . agent_release_record_name($version);
        $locPath = $dir . '/' . agent_local_record_name($version);
        $rem     = agent_package_remediation($version);

        if (!is_file($pkgPath)) {
            return agent_package_error('not_staged', sprintf(
                'Agent %s is not staged on this manager, so a firewall running the install command would fail to download it',
                $version), $rem);
        }

        if (is_file($relPath)) {
            $v = agent_package_verify_pair_quick($pkgPath, json_decode((string)file_get_contents($relPath), true), $version);
            if (!$v['ok']) {
                return $v;
            }
            return ['ok' => true, 'code' => '', 'error' => '', 'remediation' => [], 'version' => $version,
                    'sha256' => $v['body']['sha256'], 'source' => 'signed-release',
                    'package' => agent_package_name($version)];
        }

        if (is_file($locPath)) {
            $rec = json_decode((string)file_get_contents($locPath), true);
            $sha = hash_file('sha256', $pkgPath);
            if (!is_array($rec) || ($rec['agent_version'] ?? '') !== $version
                || !hash_equals((string)($rec['sha256'] ?? ''), $sha)) {
                return agent_package_error('package_corrupted', sprintf(
                    '%s no longer matches the checksum recorded when it was built here', agent_package_name($version)), $rem);
            }
            return ['ok' => true, 'code' => '', 'error' => '', 'remediation' => [], 'version' => $version,
                    'sha256' => $sha, 'source' => (string)($rec['source'] ?? 'local-build'),
                    'package' => agent_package_name($version)];
        }

        return agent_package_error('unverified', sprintf(
            '%s is present but has never been verified (no signed release record). It predates 3.81.0 or was copied in by hand',
            agent_package_name($version)), [
            'php scripts/stage_agent_package.php adopts it if it is byte-identical to the tracked source, or verifies a signed release',
        ]);
    }
}

if (!function_exists('agent_package_verify_pair_quick')) {
    /** Signature, compatibility and checksum - the contents were inspected at staging. */
    function agent_package_verify_pair_quick(string $pkgPath, $envelope, string $version): array {
        $rel = agent_release_verify($envelope, $version);
        if (!$rel['ok']) {
            return $rel;
        }
        if (!hash_equals($rel['body']['sha256'], hash_file('sha256', $pkgPath))) {
            return agent_package_error('package_corrupted', sprintf(
                '%s does not match its signed checksum: it was changed after it was staged', agent_package_name($version)),
                agent_package_remediation($version));
        }
        return $rel;
    }
}

if (!function_exists('agent_install_command')) {
    /**
     * The install one-liner, or the reason there is none.
     *
     * @param array $opts hardware_id (32 hex) to rejoin an existing record;
     *                    multiline for the console layout used on firewall pages
     * @return array{ok:bool, command:string, code:string, error:string, remediation:string[], readiness:array}
     */
    function agent_install_command(array $opts = []): array {
        $base = opnmgr_server_url();
        if ($base === '') {
            return ['ok' => false, 'command' => '', 'code' => 'manager_url',
                    'error' => "This manager's URL is not configured, so the command would point nowhere",
                    'remediation' => ['Set Settings > General > Manager FQDN, or APP_URL in .env'], 'readiness' => []];
        }
        $ready = agent_package_readiness();
        if (!$ready['ok']) {
            return ['ok' => false, 'command' => '', 'code' => $ready['code'], 'error' => $ready['error'],
                    'remediation' => $ready['remediation'], 'readiness' => $ready];
        }

        $env = ['OPNMGR_BASE_URL=' . $base, 'OPNMGR_AGENT_SHA256=' . $ready['sha256']];
        $hw  = (string)($opts['hardware_id'] ?? '');
        if (preg_match('/^[0-9a-f]{32}$/', $hw)) {
            $env[] = 'OPNMGR_HARDWARE_ID=' . $hw;
        }
        $fetch = 'fetch -o - ' . $base . '/downloads/plugins/install_opnmanager_agent.sh';
        $cmd = !empty($opts['multiline'])
            ? $fetch . " \\\n  | env " . implode(" \\\n        ", $env) . ' sh'
            : $fetch . ' | env ' . implode(' ', $env) . ' sh';

        return ['ok' => true, 'command' => $cmd, 'code' => '', 'error' => '', 'remediation' => [], 'readiness' => $ready];
    }
}

if (!function_exists('agent_install_unavailable_html')) {
    /** The alert shown in place of an install command. */
    function agent_install_unavailable_html(array $r): string {
        $h = '<div class="alert alert-warning mb-2" role="alert"><strong><i class="fas fa-exclamation-triangle me-1"></i>'
           . 'No install command yet.</strong> ' . htmlspecialchars($r['error']) . '.';
        if (!empty($r['remediation'])) {
            $h .= '<ul class="mb-0 mt-1 small">';
            foreach ($r['remediation'] as $step) {
                $h .= '<li>' . htmlspecialchars($step) . '</li>';
            }
            $h .= '</ul>';
        }
        return $h . '</div>';
    }
}

if (!function_exists('agent_package_republish_manifest')) {
    /**
     * Re-sign downloads/manifest.json with this manager's own key so agent
     * updates can verify a newly staged package. Enrollment does not need it.
     *
     * Skipped when packages are staged somewhere other than downloads/plugins
     * (tests, the release workflow's staging check).
     *
     * @return array{ok:bool, skipped:bool, message:string}
     */
    function agent_package_republish_manifest(): array {
        if (getenv('OPNMGR_AGENT_PACKAGE_DIR') !== false) {
            return ['ok' => true, 'skipped' => true, 'message' => 'package directory overridden; manifest left alone'];
        }
        require_once __DIR__ . '/release_manifest.php';
        $downloads = agent_package_root() . '/downloads';
        $signed = sign_release_manifest(build_release_manifest($downloads));
        if (!$signed['ok']) {
            return ['ok' => false, 'skipped' => false, 'message' => $signed['error']];
        }
        $json = json_encode($signed['envelope'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($downloads . '/manifest.json', $json, LOCK_EX) === false) {
            return ['ok' => false, 'skipped' => false, 'message' => 'cannot write downloads/manifest.json'];
        }
        @chmod($downloads . '/manifest.json', 0644);
        return ['ok' => true, 'skipped' => false, 'message' => 'downloads/manifest.json re-signed'];
    }
}
