<?php
/**
 * Dependency audit for CI: Composer plus every npm lockfile.
 *
 *   php scripts/ci/dependency_audit.php [--json]
 *
 * Exit codes:
 *   0  every scanner ran and reported no advisories
 *   1  at least one advisory was reported (listed, with the fix if known)
 *   2  a scanner could not run or returned output it could not have meant
 *      (missing tool, registry/network failure, malformed JSON). Never read
 *      as "clean" - before this, `composer audit || true` turned both
 *      findings and failures into a green tick.
 *
 * Abandoned Composer packages are reported but do not fail the build.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__, 2);
$results = [];

/** Run a command; return [exitCode, stdout, stderr]. */
function run_cmd(array $cmd, string $cwd): array {
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($p)) {
        return [127, '', 'could not start ' . $cmd[0]];
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), (string)$out, (string)$err];
}

function have(string $bin): bool {
    return trim((string)shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null')) !== '';
}

// --- Composer ---------------------------------------------------------------
if (is_file("$root/composer.lock")) {
    if (!have('composer')) {
        $results[] = ['scanner' => 'composer', 'target' => 'composer.lock', 'status' => 'error', 'detail' => 'composer not installed'];
    } else {
        [$rc, $out, $err] = run_cmd(['composer', 'audit', '--format=json', '--no-interaction', '--locked', '--abandoned=report'], $root);
        $j = json_decode($out, true);
        if (!is_array($j) || !array_key_exists('advisories', $j)) {
            $results[] = ['scanner' => 'composer', 'target' => 'composer.lock', 'status' => 'error',
                          'detail' => 'unparseable output (exit ' . $rc . '): ' . substr(trim($err ?: $out), 0, 300)];
        } else {
            $findings = [];
            foreach ($j['advisories'] as $pkg => $list) {
                foreach ($list as $a) {
                    $findings[] = sprintf('%s %s: %s (%s) %s', $pkg, $a['affectedVersions'] ?? '', $a['title'] ?? '',
                                          $a['cve'] ?? ($a['advisoryId'] ?? ''), $a['link'] ?? '');
                }
            }
            $abandoned = array_keys((array)($j['abandoned'] ?? []));
            $results[] = ['scanner' => 'composer', 'target' => 'composer.lock',
                          'status' => $findings ? 'findings' : 'clean', 'findings' => $findings,
                          'warnings' => array_map(fn($p) => "abandoned: {$p}", $abandoned)];
        }
    }
}

// --- npm (every lockfile we own) --------------------------------------------
foreach (['.', 'scripts'] as $dir) {
    if (!is_file("$root/$dir/package-lock.json")) {
        continue;
    }
    $target = ($dir === '.' ? '' : "$dir/") . 'package-lock.json';
    if (!have('npm')) {
        $results[] = ['scanner' => 'npm', 'target' => $target, 'status' => 'error', 'detail' => 'npm not installed'];
        continue;
    }
    [$rc, $out, $err] = run_cmd(['npm', 'audit', '--json', '--package-lock-only'], "$root/$dir");
    $j = json_decode($out, true);
    if (!is_array($j) || isset($j['error']) || !isset($j['metadata']['vulnerabilities'])) {
        $detail = is_array($j) && isset($j['error']) ? json_encode($j['error']) : substr(trim($err ?: $out), 0, 300);
        $results[] = ['scanner' => 'npm', 'target' => $target, 'status' => 'error', 'detail' => "exit {$rc}: {$detail}"];
        continue;
    }
    $findings = [];
    foreach ((array)($j['vulnerabilities'] ?? []) as $name => $v) {
        $fix = $v['fixAvailable'] ?? false;
        $fixText = $fix === true ? 'fix: npm audit fix'
            : (is_array($fix) ? sprintf('fix: %s@%s%s', $fix['name'], $fix['version'], !empty($fix['isSemVerMajor']) ? ' (major)' : '')
                              : 'no fix available');
        $findings[] = sprintf('%s [%s] %s - %s', $name, $v['severity'] ?? '?', $v['range'] ?? '', $fixText);
    }
    $results[] = ['scanner' => 'npm', 'target' => $target, 'status' => $findings ? 'findings' : 'clean', 'findings' => $findings];
}

// --- report -----------------------------------------------------------------
$exit = 0;
foreach ($results as $r) {
    if ($r['status'] === 'error') { $exit = 2; }
    elseif ($r['status'] === 'findings' && $exit === 0) { $exit = 1; }
}

if (in_array('--json', $argv, true)) {
    echo json_encode(['exit' => $exit, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit($exit);
}
if (!$results) {
    echo "ERROR: no lockfiles found to audit\n";
    exit(2);
}
foreach ($results as $r) {
    printf("%-8s %-28s %s\n", $r['scanner'], $r['target'], strtoupper($r['status']));
    foreach ($r['findings'] ?? [] as $f) { echo "         - {$f}\n"; }
    foreach ($r['warnings'] ?? [] as $w) { echo "         ~ {$w}\n"; }
    if (isset($r['detail'])) { echo "         ! {$r['detail']}\n"; }
}
echo match ($exit) {
    0 => "\nAll dependency scanners ran: no known advisories.\n",
    1 => "\nAdvisories found (exit 1). Upgrade the parent package where a fix exists; document anything accepted in docs/SECURITY_DEPENDENCIES.md.\n",
    2 => "\nA scanner did not complete (exit 2). This is NOT a clean result.\n",
};
exit($exit);
