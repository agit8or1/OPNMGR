<?php
/**
 * Verify and stage the OPNsense agent package this manager installs.
 *
 * Run once after installing or upgrading the manager, before enrolling a
 * firewall. Until a verified package is staged, Settings and Add Firewall show
 * why instead of an install command that would fail on the firewall.
 *
 *   php scripts/stage_agent_package.php
 *       Make enrollment ready. Already staged: re-verifies and stops. A package
 *       already on disk with no record (installs before 3.81.0) is adopted, as is,
 *       if its contents match a fresh build of the tracked source. Otherwise the
 *       package and its signed record are downloaded from this version's GitHub
 *       release and verified.
 *
 *   php scripts/stage_agent_package.php --import <dir | package.tar.gz> [record.json]
 *       Offline: verify and stage files you copied here yourself. A directory is
 *       searched for os-opnmanager-agent-<version>.tar.gz and its .release.json.
 *
 *   php scripts/stage_agent_package.php --build-local
 *       Build the package from plugin/os-opnmanager-agent/ on this manager and
 *       stage it, trusting the checkout rather than a release signature.
 *
 *   php scripts/stage_agent_package.php --status
 *       Report readiness and change nothing. Exit 0 ready, 1 not ready.
 *
 * Options: --release-url <base> (or OPNMGR_AGENT_RELEASE_URL) to download from a
 * mirror; --no-manifest to skip re-signing downloads/manifest.json afterwards.
 *
 * Existing packages are never replaced or deleted, and nothing here touches the
 * database, enrolled firewalls, their credentials or the signing keys.
 *
 * @since 3.81.0
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("stage_agent_package.php may only be run from the command line\n");
}

require_once __DIR__ . '/../inc/agent_package.php';

$args = $argv;
array_shift($args);
$has = fn(string $f) => in_array($f, $args, true);
$opt = function (string $name) use ($args): ?string {
    $i = array_search($name, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? $args[$i + 1] : null;
};

$version = agent_expected_version();
$dir     = agent_package_dir();
$root    = agent_package_root();

/** Print a failure with its remediation and exit. */
function stage_fail(array $r, int $code = 1): void {
    fwrite(STDERR, "NOT READY [{$r['code']}]: {$r['error']}\n");
    if (!empty($r['remediation'])) {
        fwrite(STDERR, "What to do:\n");
        foreach ($r['remediation'] as $step) {
            fwrite(STDERR, "  - {$step}\n");
        }
    }
    exit($code);
}

/** Report success and re-sign the local agent update manifest. */
function stage_done(array $r, bool $republish): void {
    printf("READY: agent %s staged (%s)\n  sha256 %s\n", $r['version'], $r['source'], $r['sha256']);
    if (!empty($r['changed']) && $republish) {
        $m = agent_package_republish_manifest();
        echo $m['ok']
            ? "  {$m['message']}, so agent updates can verify this package too\n"
            : "  NOTE: downloads/manifest.json was not re-signed ({$m['message']}).\n"
              . "        Enrollment does not need it; agent updates do. Run: php scripts/sign_release.php --publish\n";
    }
    echo "Enrollment commands are now shown on Settings and Add Firewall.\n";
    exit(0);
}

/** Build the tracked source into a scratch directory. */
function stage_build_local(string $root, string $version): array {
    $tmp = sys_get_temp_dir() . '/opnmgr-agent-build-' . bin2hex(random_bytes(6));
    mkdir($tmp, 0700);
    exec('OUT_DIR=' . escapeshellarg($tmp) . ' sh ' . escapeshellarg($root . '/scripts/build_agent_package.sh') . ' 2>&1', $out, $rc);
    $path = $tmp . '/' . agent_package_name($version);
    if ($rc !== 0 || !is_file($path)) {
        return agent_package_error('build_failed', 'Building the agent from plugin/os-opnmanager-agent/ failed: '
            . trim(implode(' ', array_slice($out, -2))), ['Check the checkout is complete: git status plugin/']);
    }
    return ['ok' => true, 'path' => $path, 'tmp' => $tmp,
            'commit' => trim((string)@shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>/dev/null')) ?: null];
}

function stage_cleanup(?string $tmp): void {
    if ($tmp && is_dir($tmp)) {
        foreach (glob($tmp . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($tmp);
    }
}

/** Download one file with certificate checks and a size cap. */
function stage_download(string $url, string $dest): array {
    $ctx = stream_context_create([
        'http' => ['timeout' => 30, 'follow_location' => 1, 'max_redirects' => 5, 'ignore_errors' => true,
                   'header' => "User-Agent: OPNManager-stage-agent\r\n"],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $in = @fopen($url, 'rb', false, $ctx);
    if (!$in) {
        $e = error_get_last()['message'] ?? 'connection failed';
        return ['ok' => false, 'error' => "cannot reach {$url} ({$e})", 'status' => 0];
    }
    $meta = stream_get_meta_data($in);
    $status = 0;
    foreach ($meta['wrapper_data'] ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int)$m[1]; }
    }
    if ($status !== 200) {
        fclose($in);
        return ['ok' => false, 'error' => "{$url} returned HTTP {$status}", 'status' => $status];
    }
    $data = stream_get_contents($in, 5 * 1024 * 1024 + 1);
    fclose($in);
    if ($data === false || strlen($data) > 5 * 1024 * 1024) {
        return ['ok' => false, 'error' => "{$url} is larger than an agent package can be", 'status' => $status];
    }
    file_put_contents($dest, $data);
    return ['ok' => true, 'error' => '', 'status' => $status];
}

$republish = !$has('--no-manifest') && getenv('OPNMGR_AGENT_PACKAGE_DIR') === false;

// ---------------------------------------------------------------------------
if ($has('--status')) {
    $r = agent_package_readiness();
    if ($r['ok']) {
        printf("READY: agent %s (%s)\n  sha256 %s\n", $r['version'], $r['source'], $r['sha256']);
        exit(0);
    }
    stage_fail($r);
}

// ---------------------------------------------------------------------------
if (($src = $opt('--import')) !== null) {
    $pkg = $src;
    $rec = $args[array_search('--import', $args, true) + 2] ?? null;
    if ($rec !== null && str_starts_with($rec, '--')) {
        $rec = null;
    }
    if (is_dir($src)) {
        $pkg = rtrim($src, '/') . '/' . agent_package_name($version);
        if (!is_file($pkg)) {
            $found = array_map('basename', glob(rtrim($src, '/') . '/os-opnmanager-agent-*.tar.gz') ?: []);
            stage_fail(agent_package_error($found ? 'incompatible_version' : 'not_staged', $found
                ? sprintf('%s holds %s, but this manager (%s) installs agent %s', $src, implode(', ', $found), APP_VERSION, $version)
                : sprintf('%s does not contain %s', $src, agent_package_name($version)),
                agent_package_remediation($version)));
        }
    }
    $rec = $rec ?? (dirname($pkg) . '/' . agent_release_record_name($version));
    if (!is_file($rec)) {
        stage_fail(agent_package_error('release_missing', sprintf(
            'No signed release record beside the package (looked for %s). Copy it from the same release',
            basename($rec)), agent_package_remediation($version)));
    }
    $r = agent_package_stage_release($pkg, $rec);
    $r['ok'] ? stage_done($r, $republish) : stage_fail($r);
}

// ---------------------------------------------------------------------------
if ($has('--build-local')) {
    $b = stage_build_local($root, $version);
    if (!$b['ok']) {
        stage_fail($b);
    }
    $r = agent_package_stage_local($b['path'], ['source' => 'local-build', 'source_commit' => $b['commit']]);
    stage_cleanup($b['tmp']);
    $r['ok'] ? stage_done($r, $republish) : stage_fail($r);
}

// ---------------------------------------------------------------------------
// Default: make it ready by the least surprising route.
$ready = agent_package_readiness();
if ($ready['ok']) {
    printf("READY: agent %s already staged and verified (%s)\n  sha256 %s\n", $ready['version'], $ready['source'], $ready['sha256']);
    exit(0);
}
if (in_array($ready['code'], ['package_corrupted', 'signature_invalid', 'untrusted_key', 'release_invalid',
                              'incompatible_version', 'incompatible_platform', 'incompatible_manager'], true)) {
    // Something is staged and it is wrong. Do not paper over it.
    stage_fail($ready);
}

if ($ready['code'] === 'unverified') {
    echo "Found " . agent_package_name($version) . " with no record; comparing its contents with a build of the tracked source...\n";
    $b = stage_build_local($root, $version);
    if (!$b['ok']) {
        stage_fail($b);
    }
    $existing = $dir . '/' . agent_package_name($version);
    // Contents, not bytes: packages built before 3.81.1 carry the building
    // checkout's permission bits, which no other checkout reproduces.
    $have = agent_package_content_digest($existing);
    if ($have !== null && hash_equals($have, (string)agent_package_content_digest($b['path']))) {
        $r = agent_package_stage_local($existing, ['source' => 'adopted-matches-source', 'source_commit' => $b['commit']]);
        stage_cleanup($b['tmp']);
        $r['ok'] ? stage_done($r, false) : stage_fail($r);
    }
    stage_cleanup($b['tmp']);
    stage_fail(agent_package_error('unverified', sprintf(
        '%s differs from what the tracked source builds, so it cannot be verified. It was left in place',
        agent_package_name($version)), [
        'If this manager is not on a release checkout, that explains it; check git status',
        'Firewalls may already run these bytes. To replace it, move it aside by hand, then run this again',
    ]));
}

// Nothing staged: fetch this version's release.
$base = rtrim($opt('--release-url') ?? (getenv('OPNMGR_AGENT_RELEASE_URL') ?: 'https://github.com/agit8or1/OPNMGR/releases/download/v' . APP_VERSION), '/');
$tmp = sys_get_temp_dir() . '/opnmgr-agent-fetch-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
$pkg = $tmp . '/' . agent_package_name($version);
$rec = $tmp . '/' . agent_release_record_name($version);
echo "Downloading agent {$version} from {$base}/ ...\n";
foreach ([[$base . '/' . agent_package_name($version), $pkg], [$base . '/' . agent_release_record_name($version), $rec]] as [$url, $dest]) {
    $d = stage_download($url, $dest);
    if (!$d['ok']) {
        stage_cleanup($tmp);
        stage_fail(agent_package_error($d['status'] === 404 ? 'release_missing' : 'download_failed',
            'Could not download the release: ' . $d['error'], [
                'Offline or behind a proxy: download both files elsewhere and run --import <directory>, or upload them on Add Firewall',
                'No release published for v' . APP_VERSION . ' (e.g. running from an unreleased commit): --build-local',
            ]));
    }
}
$r = agent_package_stage_release($pkg, $rec);
stage_cleanup($tmp);
$r['ok'] ? stage_done($r, $republish) : stage_fail($r);
