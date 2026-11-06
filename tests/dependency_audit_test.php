<?php
/**
 * scripts/ci/dependency_audit.php tells clean, findings and scanner failure
 * apart. composer/npm are replaced by stubs, so no registry is contacted.
 */
$passed = 0; $failed = 0;
function check(string $what, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; return; }
    $failed++; echo "FAIL: {$what}\n"; if ($detail !== '') { echo "      {$detail}\n"; }
}
$root = dirname(__DIR__);
$bin = sys_get_temp_dir() . '/opnmgr-depaudit-' . getmypid();
@mkdir($bin, 0700, true);

function stubs(string $bin, string $composerOut, int $composerRc, string $npmOut, int $npmRc): void {
    file_put_contents("$bin/composer", "#!/bin/sh\ncat <<'J'\n{$composerOut}\nJ\nexit {$composerRc}\n");
    file_put_contents("$bin/npm", "#!/bin/sh\ncat <<'J'\n{$npmOut}\nJ\nexit {$npmRc}\n");
    chmod("$bin/composer", 0700); chmod("$bin/npm", 0700);
}
function audit(string $bin): array {
    global $root;
    $out = []; $rc = 0;
    exec('PATH=' . escapeshellarg($bin) . ':/usr/bin:/bin ' . escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg("$root/scripts/ci/dependency_audit.php") . ' --json 2>&1', $out, $rc);
    return [$rc, json_decode(implode("\n", $out), true)];
}

$cleanC = '{"advisories": [], "abandoned": []}';
$cleanN = '{"vulnerabilities": {}, "metadata": {"vulnerabilities": {"total": 0}}}';

stubs($bin, $cleanC, 0, $cleanN, 0);
[$rc, $j] = audit($bin);
check('all clean exits 0', $rc === 0, json_encode($j));

stubs($bin, '{"advisories": {"vendor/pkg": [{"title": "RCE", "cve": "CVE-2099-1", "affectedVersions": "<2"}]}, "abandoned": []}', 1, $cleanN, 0);
[$rc, $j] = audit($bin);
check('a Composer advisory exits 1', $rc === 1);
check('and is listed', str_contains(json_encode($j), 'CVE-2099-1'));

stubs($bin, $cleanC, 0, '{"vulnerabilities": {"basic-ftp": {"severity": "high", "range": "<=6.2.0", "fixAvailable": {"name": "puppeteer", "version": "25.16.0", "isSemVerMajor": true}}}, "metadata": {"vulnerabilities": {"total": 1}}}', 1);
[$rc, $j] = audit($bin);
check('an npm advisory exits 1', $rc === 1);
check('with the parent-package fix named', str_contains(json_encode($j), 'puppeteer@25.16.0 (major)'));

stubs($bin, 'Could not connect to packagist', 100, $cleanN, 0);
[$rc, $j] = audit($bin);
check('Composer output that is not JSON is a scanner error (2), not clean', $rc === 2);

stubs($bin, $cleanC, 0, '{"error": {"code": "ENOAUDIT", "summary": "registry unreachable"}}', 1);
[$rc, $j] = audit($bin);
check('an npm registry error is a scanner error (2)', $rc === 2);

stubs($bin, '{"advisories": {"vendor/pkg": [{"title": "x"}]}, "abandoned": []}', 1, '{"error": {"code": "E"}}', 1);
[$rc, $j] = audit($bin);
check('a scanner error outranks findings', $rc === 2);

stubs($bin, '{"advisories": [], "abandoned": {"old/pkg": null}}', 0, $cleanN, 0);
[$rc, $j] = audit($bin);
check('an abandoned package is reported but does not fail', $rc === 0 && str_contains(json_encode($j, JSON_UNESCAPED_SLASHES), 'abandoned: old/pkg'));

exec('rm -rf ' . escapeshellarg($bin));
echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
