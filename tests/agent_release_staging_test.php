<?php
/**
 * Agent release verification and staging (inc/agent_package.php, 3.81.0).
 *
 * Every artifact failure a manager can meet, each with an actionable message,
 * and the guarantee that a staged package is never replaced. Uses a key made
 * for this run and a scratch package directory: downloads/ is not touched.
 *
 *   DB_NAME=opnsense_fw_test php tests/agent_release_staging_test.php
 */

require_once __DIR__ . '/bootstrap.php';

$work = sys_get_temp_dir() . '/opnmgr-pkgtest-' . bin2hex(random_bytes(4));
mkdir($work, 0700, true);
register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($work)));

$kp      = sodium_crypto_sign_keypair();
$secret  = sodium_crypto_sign_secretkey($kp);
$pub     = base64_encode(sodium_crypto_sign_publickey($kp));
$keys    = $work . '/keys.pub';
file_put_contents($keys, "# test\n{$pub}\n");
putenv('OPNMGR_AGENT_RELEASE_KEYS_FILE=' . $keys);
putenv('OPNMGR_AGENT_RELEASE_PUBKEYS=');
$stage = $work . '/stage';
putenv('OPNMGR_AGENT_PACKAGE_DIR=' . $stage);

require_once TEST_ROOT . '/inc/agent_package.php';

$V   = AGENT_VERSION;
$PKG = agent_package_name($V);
$REC = agent_release_record_name($V);

/** Build from the tracked source into $dir. */
function build_into(string $dir): string {
    exec('OUT_DIR=' . escapeshellarg($dir) . ' sh ' . escapeshellarg(TEST_ROOT . '/scripts/build_agent_package.sh') . ' 2>&1', $o, $rc);
    return $rc === 0 ? $dir . '/' . agent_package_name(AGENT_VERSION) : '';
}
function write_record(string $path, array $env): void {
    file_put_contents($path, json_encode($env, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
/** Sign an arbitrary body, for records the real signer would never produce. */
function sign_body(array $body, string $secret): array {
    $json = json_encode($body, JSON_UNESCAPED_SLASHES);
    $pk = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
    return ['format' => AGENT_RELEASE_FORMAT, 'algorithm' => 'Ed25519', 'public_key' => $pk,
            'key_id' => agent_release_key_id($pk), 'signature' => base64_encode(sodium_crypto_sign_detached($json, $secret)),
            'body' => $json];
}
/** Repack a directory tree as a tar.gz with explicit entries. */
function pack_tree(string $src, string $out): void {
    exec('cd ' . escapeshellarg($src) . ' && tar czf ' . escapeshellarg($out) . ' --owner=0 --group=0 . 2>&1');
}
function stage_cli(string $args): array {
    exec('php ' . escapeshellarg(TEST_ROOT . '/scripts/stage_agent_package.php') . ' ' . $args . ' 2>&1', $o, $rc);
    return ['rc' => $rc, 'out' => implode("\n", $o)];
}

$rel = $work . '/release';
$pkg = build_into($rel);
T::ok($pkg !== '' && is_file($pkg), 'the agent builds from tracked source');
$signed = agent_release_sign($pkg, $secret);
T::ok($signed['ok'], 'and signs');
write_record("{$rel}/{$REC}", $signed['envelope']);
$goodSha = hash_file('sha256', $pkg);

// ===========================================================================
T::group('Missing');

$r = agent_package_readiness();
T::eq('not_staged', $r['code'], 'nothing staged: not_staged');
T::ok(str_contains($r['error'], "Agent {$V} is not staged"), 'the message names the version');
T::ok(count($r['remediation']) === 3 && str_contains(implode(' ', $r['remediation']), '--import'),
      'and gives the online, offline and build-local remedies');
$cmd = agent_install_command();
T::ok(!$cmd['ok'] && $cmd['command'] === '', 'no install command is produced');

$noRec = $work . '/norec';
mkdir($noRec);
copy($pkg, "{$noRec}/{$PKG}");
$r = stage_cli('--import ' . escapeshellarg($noRec));
T::ok($r['rc'] === 1 && str_contains($r['out'], 'release_missing'), 'import without the signed record: release_missing');
T::ok(str_contains($r['out'], 'Copy it from the same release'), 'and says where to get it');

$wrongDir = $work . '/wrongver';
mkdir($wrongDir);
copy($pkg, "{$wrongDir}/os-opnmanager-agent-1.0.0.tar.gz");
$r = stage_cli('--import ' . escapeshellarg($wrongDir));
T::ok($r['rc'] === 1 && str_contains($r['out'], 'incompatible_version') && str_contains($r['out'], "installs agent {$V}"),
      'a directory holding only another version says which version is needed');

// ===========================================================================
T::group('Corrupted');

$cor = $work . '/corrupt.tar.gz';
copy($pkg, $cor);
$fh = fopen($cor, 'r+'); fseek($fh, 300); fwrite($fh, 'XX'); fclose($fh);
$r = agent_package_verify_pair($cor, $signed['envelope']);
T::eq('package_corrupted', $r['code'], 'a flipped byte: package_corrupted');
T::ok(str_contains($r['error'], 'altered or damaged'), 'explained as altered or damaged');

$trunc = $work . '/trunc.tar.gz';
file_put_contents($trunc, substr((string)file_get_contents($pkg), 0, 1000));
$r = agent_package_verify_pair($trunc, $signed['envelope']);
T::ok($r['code'] === 'package_corrupted' && str_contains($r['error'], 'truncated'), 'a truncated download: package_corrupted, says truncated');

$html = $work . '/html.tar.gz';
file_put_contents($html, "<html>404 Not Found</html>");
T::eq('package_corrupted', agent_package_inspect($html)['code'], 'an HTML error page saved as the package is not a gzip archive');

$tamper = $signed['envelope'];
$tamper['body'] = str_replace($goodSha, str_repeat('a', 64), $tamper['body']);
T::eq('signature_invalid', agent_release_verify($tamper)['code'], 'an edited release record: signature_invalid');

$junk = agent_release_verify(['hello' => 'world']);
T::eq('release_invalid', $junk['code'], 'something that is not a release record: release_invalid');

$other = sodium_crypto_sign_keypair();
$foreign = agent_release_sign($pkg, sodium_crypto_sign_secretkey($other));
$r = agent_release_verify($foreign['envelope']);
T::eq('untrusted_key', $r['code'], 'signed by a key that is not pinned: untrusted_key');
T::ok(str_contains(implode(' ', $r['remediation']), 'OPNMGR_AGENT_RELEASE_PUBKEYS'), 'with how a fork pins its own key');

// ===========================================================================
T::group('Incompatible');

$body = json_decode($signed['envelope']['body'], true);
$r = agent_release_verify(sign_body(array_merge($body, ['agent_version' => '1.8.1', 'package' => 'os-opnmanager-agent-1.8.1.tar.gz']), $secret));
T::eq('incompatible_version', $r['code'], 'another agent version: incompatible_version');
T::ok(str_contains($r['error'], 'is agent 1.8.1') && str_contains($r['error'], "installs agent {$V}"), 'naming both versions');

$r = agent_release_verify(sign_body(array_merge($body, ['min_manager_version' => '99.0.0']), $secret));
T::eq('incompatible_manager', $r['code'], 'needs a newer manager: incompatible_manager');
T::ok(str_contains($r['error'], 'Upgrade the manager first'), 'and says to upgrade the manager');

$r = agent_release_verify(sign_body(array_merge($body, ['platform' => 'pfsense']), $secret));
T::eq('incompatible_platform', $r['code'], 'a pfSense release record: incompatible_platform');

$pf = $work . '/pf';
mkdir($pf . '/usr/local/pkg', 0755, true);
file_put_contents($pf . '/opnmgr_agent.sh', "AGENT_VERSION=\"{$V}\"\n");
file_put_contents($pf . '/usr/local/pkg/x.inc', '');
pack_tree($pf, $work . '/pf.tar.gz');
T::eq('incompatible_platform', agent_package_inspect($work . '/pf.tar.gz')['code'], 'the pfSense package layout: incompatible_platform');

// A package whose agent.sh declares another version, signed as if it were current.
$tree = $work . '/tree';
mkdir($tree);
exec('tar xzf ' . escapeshellarg($pkg) . ' -C ' . escapeshellarg($tree));
$agentSh = $tree . '/opnsense/scripts/OPNsense/OPNManagerAgent/agent.sh';
file_put_contents($agentSh, preg_replace('/^AGENT_VERSION="[^"]+"/m', 'AGENT_VERSION="1.7.0"', (string)file_get_contents($agentSh)));
$mis = $work . '/mis/' . $PKG;
mkdir(dirname($mis));
pack_tree($tree, $mis);
$misRec = sign_body(array_merge($body, ['sha256' => hash_file('sha256', $mis), 'size' => filesize($mis)]), $secret);
$r = agent_package_verify_pair($mis, $misRec);
T::ok($r['code'] === 'package_invalid' && str_contains($r['error'], 'declares 1.7.0'), 'a record that lies about the packaged agent: package_invalid');

unlink($tree . '/etc/rc.d/opnmanager_agent');
pack_tree($tree, $work . '/missing.tar.gz');
$r = agent_package_inspect($work . '/missing.tar.gz');
T::ok($r['code'] === 'package_invalid' && str_contains($r['error'], 'etc/rc.d/opnmanager_agent'), 'a missing required file is named');

$evil = $work . '/evil';
mkdir($evil);
exec('tar xzf ' . escapeshellarg($pkg) . ' -C ' . escapeshellarg($evil));
symlink('/etc/passwd', $evil . '/etc/link');
pack_tree($evil, $work . '/link.tar.gz');
T::eq('package_invalid', agent_package_inspect($work . '/link.tar.gz')['code'], 'a symlink inside the package: package_invalid');
exec('python3 -I -c ' . escapeshellarg('import tarfile,io,sys
t=tarfile.open(sys.argv[1],"w:gz"); d=b"x"; i=tarfile.TarInfo("etc/../../tmp/owned"); i.size=len(d); t.addfile(i,io.BytesIO(d)); t.close()')
    . ' ' . escapeshellarg($work . '/dots.tar.gz'));
T::eq('package_invalid', agent_package_inspect($work . '/dots.tar.gz')['code'], 'a ../ path inside the package: package_invalid');

// ===========================================================================
T::group('Staging, offline import and readiness');

$r = stage_cli('--import ' . escapeshellarg($rel));
T::eq(0, $r['rc'], 'offline --import of the release directory: ' . substr($r['out'], 0, 50));
T::ok(str_contains($r['out'], 'READY') && str_contains($r['out'], $goodSha), 'reports the staged checksum');
T::ok(str_contains($r['out'], 'Enrollment commands are now shown'), 'and what changed for the operator');
$ready = agent_package_readiness();
T::ok($ready['ok'] && $ready['source'] === 'signed-release' && $ready['sha256'] === $goodSha, 'readiness: signed release, right checksum');
T::eq(0, stage_cli('--status')['rc'], '--status exits 0 when ready');

$again = agent_package_stage_release($pkg, "{$rel}/{$REC}");
T::ok($again['ok'] && !$again['changed'], 'staging the same release again is a no-op');

// Preserve: different bytes under the same version are never swapped in.
$before = hash_file('sha256', "{$stage}/{$PKG}");
$alt = $work . '/alt';
mkdir($alt);
$altTree = $work . '/alttree';
mkdir($altTree);
exec('tar xzf ' . escapeshellarg($pkg) . ' -C ' . escapeshellarg($altTree));
file_put_contents($altTree . '/opnsense/scripts/OPNsense/OPNManagerAgent/watchdog.sh', "\n# rebuilt\n", FILE_APPEND);
pack_tree($altTree, "{$alt}/{$PKG}");
write_record("{$alt}/{$REC}", agent_release_sign("{$alt}/{$PKG}", $secret)['envelope']);
$r = agent_package_stage_release("{$alt}/{$PKG}", "{$alt}/{$REC}");
T::eq('conflict', $r['code'], 'a validly signed but different package for a staged version: conflict');
T::eq($before, hash_file('sha256', "{$stage}/{$PKG}"), 'and the staged package is untouched');
T::ok(str_contains($r['error'], 'firewalls may already run those bytes'), 'with the reason it was left alone');

// Damage after staging is caught by readiness, and the command disappears.
$fh = fopen("{$stage}/{$PKG}", 'r+'); fseek($fh, 500); fwrite($fh, 'Z'); fclose($fh);
$r = agent_package_readiness();
T::ok($r['code'] === 'package_corrupted' && str_contains($r['error'], 'changed after it was staged'), 'a package altered after staging: package_corrupted');
T::eq(1, stage_cli('--status')['rc'], '--status exits 1');
$r = stage_cli('');
T::ok($r['rc'] === 1 && str_contains($r['out'], 'package_corrupted'), 'the default run refuses to paper over a damaged staged package');
T::ok(is_file("{$stage}/{$PKG}"), 'and does not delete it');

// ===========================================================================
T::group('Packages from before 3.81.0 (no record)');

exec('rm -rf ' . escapeshellarg($stage));
mkdir($stage);
copy($pkg, "{$stage}/{$PKG}");
T::eq('unverified', agent_package_readiness()['code'], 'an unrecorded package is not trusted: unverified');
$r = stage_cli('');
T::eq(0, $r['rc'], 'the default run adopts it when its contents match the tracked source');
T::ok(str_contains($r['out'], 'adopted-matches-source'), 'and records how it was verified');
T::eq($goodSha, hash_file('sha256', "{$stage}/{$PKG}"), 'without rewriting it');

// Built before 3.81.1 on a checkout with umask 002: same files, group-writable
// modes, so different bytes. Its contents still match the source.
exec('rm -rf ' . escapeshellarg($stage));
mkdir($stage);
$legacyTree = $work . '/legacytree';
mkdir($legacyTree);
exec('tar xzf ' . escapeshellarg($pkg) . ' -C ' . escapeshellarg($legacyTree) . ' && chmod -R g+w ' . escapeshellarg($legacyTree));
exec('cd ' . escapeshellarg($legacyTree) . ' && find . -mindepth 1 | sed "s|^./||" | LC_ALL=C sort > ../legacy.list && tar cf - --owner=0 --group=0 --numeric-owner --mtime=@0 --format=gnu --no-recursion -T ../legacy.list | gzip -n > ' . escapeshellarg("{$stage}/{$PKG}"));
$legacySha = hash_file('sha256', "{$stage}/{$PKG}");
T::ok($legacySha !== $goodSha, 'a pre-3.81.1 build (group-writable modes) has different bytes');
T::eq(agent_package_content_digest($pkg), agent_package_content_digest("{$stage}/{$PKG}"), 'but the same contents');
$r = stage_cli('');
T::ok($r['rc'] === 0 && str_contains($r['out'], 'adopted-matches-source'), 'so it is adopted');
T::eq($legacySha, hash_file('sha256', "{$stage}/{$PKG}"), 'byte for byte as it was: firewalls keep the package they installed');
T::eq($legacySha, agent_package_readiness()['sha256'] ?? null, 'and the install command carries its checksum');

$built = (string)shell_exec('tar tvzf ' . escapeshellarg($pkg));
T::ok(!preg_match('/^.....w/m', $built) && !preg_match('/^........w/m', $built), 'the build has no group- or other-writable entries');
T::ok(str_contains($built, 'opnsense/service/templates/OPNsense/OPNManagerAgent/'), 'and includes the empty templates tree');

exec('rm -rf ' . escapeshellarg($stage));
mkdir($stage);
copy("{$alt}/{$PKG}", "{$stage}/{$PKG}");
$r = stage_cli('');
T::ok($r['rc'] === 1 && str_contains($r['out'], 'differs from what the tracked source builds'), 'a different unrecorded package is reported, not adopted');
T::eq(hash_file('sha256', "{$alt}/{$PKG}"), hash_file('sha256', "{$stage}/{$PKG}"), 'and left exactly as it was');

exec('rm -rf ' . escapeshellarg($stage));
$r = stage_cli('--build-local');
T::ok($r['rc'] === 0 && str_contains($r['out'], 'local-build'), '--build-local stages a build of the tracked source');
T::eq('local-build', agent_package_readiness()['source'] ?? null, 'and readiness says so');

// ===========================================================================
T::group('Installer');

$installer = TEST_ROOT . '/downloads/plugins/install_opnmanager_agent.sh';
$bin = $work . '/bin';
mkdir($bin);
// fetch(1) stand-in that serves files from the staging directory.
file_put_contents($bin . '/fetch', "#!/bin/sh\nout=-\nwhile [ \$# -gt 0 ]; do case \"\$1\" in -o) out=\"\$2\"; shift 2;; -q) shift;; *) url=\"\$1\"; shift;; esac; done\n"
    . "f=\"{$stage}/\$(basename \"\$url\")\"; [ -f \"\$f\" ] || exit 1; if [ \"\$out\" = - ]; then cat \"\$f\"; else cp \"\$f\" \"\$out\"; fi\n");
chmod($bin . '/fetch', 0755);
$run = function (array $env) use ($installer, $bin, $work): array {
    $e = 'env -i PATH=' . escapeshellarg($bin . ':/usr/bin:/bin') . ' TMPDIR=' . escapeshellarg($work) . ' OPNMGR_VERIFY_ONLY=1 OPNMGR_BASE_URL=https://m.test';
    foreach ($env as $k => $v) { $e .= ' ' . $k . '=' . escapeshellarg($v); }
    exec($e . ' sh ' . escapeshellarg($installer) . ' 2>&1', $o, $rc);
    return ['rc' => $rc, 'out' => implode("\n", $o)];
};
$sha = hash_file('sha256', "{$stage}/{$PKG}");
$r = $run(['OPNMGR_AGENT_SHA256' => $sha]);
T::ok($r['rc'] === 0 && str_contains($r['out'], "Verified agent {$V}"), 'verify-only with the right checksum succeeds');
$r = $run(['OPNMGR_AGENT_SHA256' => 'nothex']);
T::ok($r['rc'] === 1 && str_contains($r['out'], 'not a SHA-256'), 'a malformed checksum is refused');
$r = $run([]);
T::ok($r['rc'] === 0 && str_contains($r['out'], 'not checksum-verified'), 'an old-style command still works but warns');
unlink("{$stage}/{$PKG}");
$r = $run(['OPNMGR_AGENT_SHA256' => $sha]);
T::ok($r['rc'] === 1 && str_contains($r['out'], 'stage_agent_package.php --status'), 'a package missing on the manager points at the manager-side fix');

// ===========================================================================
T::group('Upload endpoint is administrator-only');

$r = call_endpoint('api/agent_package_import.php', ['method' => 'POST']);
T::ok(in_array($r['status'], [302, 401, 403], true) || str_contains($r['body'], 'login'), 'signed out: refused');
$fw = (string)file_get_contents(TEST_ROOT . '/api/agent_package_import.php');
T::ok(str_contains($fw, "require_permission('settings.manage')") && str_contains($fw, 'csrf_verify'), 'gated on settings.manage and CSRF');
T::ok(str_contains($fw, 'agent_package_stage_release('), 'and stages only through the verifying path');

exit(T::summary());
