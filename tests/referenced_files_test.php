<?php
/**
 * Every internal path the product references must exist.
 *
 * This class of defect kept recurring and was invisible to every other check:
 *
 *  - api/test_email.php, api/run_bandwidth_test.php, api/test_ssl.php,
 *    api/test_nginx.php and api/test_pushover.php are all called by the shipped
 *    UI, and all were absent from the repository. Unanchored `test_*` and
 *    `*_test.*` rules in .gitignore matched at every depth, so nobody could
 *    have committed them either.
 *  - firewall_details.php did require_once on scripts/queue_command.php, a file
 *    that has never existed, so saving a firewall with a changed Web GUI IP list
 *    was a fatal error.
 *  - scripts/install_snyk.sh was deleted by a bulk "remove unused files" commit
 *    while security_scan.php still exec'd it.
 *
 * Run with: php tests/referenced_files_test.php
 *
 * @since 3.27.0
 */

require_once __DIR__ . '/bootstrap.php';

$root = rtrim(TEST_ROOT, '/');

/** Everything on disk, relative to the root. */
$present = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $f) {
    if (!$f->isFile()) { continue; }
    $rel = substr($f->getPathname(), strlen($root) + 1);
    // vendor/ and node_modules/ are not scanned as sources, but they very much
    // count as present: require __DIR__ . '/vendor/autoload.php' is satisfied.
    foreach (['backups/', '.archive/', '.git/'] as $skip) {
        if (str_starts_with($rel, $skip)) { continue 2; }
    }
    $present[$rel] = true;
}

$out = shell_exec('git -C ' . escapeshellarg($root) . ' ls-files "*.php" "*.js" 2>/dev/null');
$sources = array_values(array_filter(array_map('trim', explode("\n", (string) $out))));
if (!$sources) {
    $sources = array_values(array_filter(array_keys($present),
        static fn($f) => str_ends_with($f, '.php') || str_ends_with($f, '.js')));
}

T::group('Referenced files exist');
T::ok(count($sources) > 100, 'sources were found to scan (' . count($sources) . ')');

// 1. URLs the browser will request: fetch(), href, src, form action, redirects.
$urlPat = '~(?:fetch|action|href|src|window\.location(?:\.href)?\s*=|location\.href\s*=)'
        . '\s*[=(]?\s*[\'"](/[A-Za-z0-9_./-]+\.(?:php|sh|js|css))(?:\?[^\'"]*)?[\'"]~';

// 2. Paths the server will open: require/include of a built path, and exec of a
//    script under the application directory.
$phpPat = '~(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*[\'"](/[A-Za-z0-9_./-]+\.php)[\'"]~';
$shPat  = '~__DIR__\s*\.\s*[\'"](/[A-Za-z0-9_./-]+\.sh)[\'"]~';

$missing = [];
foreach ($sources as $rel) {
    $src = (string) file_get_contents($root . '/' . $rel);
    $dir = trim(dirname($rel), '.');

    foreach ([$urlPat => null, $phpPat => $dir, $shPat => $dir] as $pat => $base) {
        if (!preg_match_all($pat, $src, $m, PREG_OFFSET_CAPTURE)) { continue; }
        foreach ($m[1] as $hit) {
            $target = ltrim($hit[0], '/');
            // __DIR__-relative paths resolve against the including file's
            // directory and may climb with '..', so normalise rather than
            // concatenate - otherwise every api/ file "references" a missing
            // ../inc/bootstrap.php.
            $candidates = [$target];
            if ($base !== null && $base !== '') {
                $parts = [];
                foreach (explode('/', $base . '/' . $target) as $seg) {
                    if ($seg === '' || $seg === '.') { continue; }
                    if ($seg === '..') { array_pop($parts); continue; }
                    $parts[] = $seg;
                }
                $candidates[] = implode('/', $parts);
            }
            $found = false;
            foreach ($candidates as $c) {
                if (isset($present[$c])) { $found = true; break; }
            }
            if ($found) { continue; }
            $line = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
            $missing[] = "{$rel}:{$line} -> /{$target}";
        }
    }
}

$missing = array_values(array_unique($missing));
foreach ($missing as $m) {
    fwrite(STDERR, "  missing: {$m}\n");
}
T::eq(0, count($missing), 'no source references a file that is absent from the tree');

T::group('Ignore rules do not exclude product files');

// The rules that caused this are anchored now. If an unanchored form comes
// back, these endpoints vanish from a clone again.
$shipped = [
    'api/test_email.php', 'api/test_ssl.php', 'api/test_nginx.php',
    'api/test_pushover.php', 'api/run_bandwidth_test.php',
    'tests/schema_columns_test.php',
    // Real documentation that *_GUIDE.md and *_IMPLEMENTATION.md swallowed
    // while unanchored. Those rules exist for session notes in the project
    // root; at every depth they took docs/ with them.
    'docs/AGENT_RECOVERY_GUIDE.md',
    'docs/AI_LOG_ANALYSIS_IMPLEMENTATION.md',
];
foreach ($shipped as $f) {
    $rc = 1;
    $o  = [];
    @exec('git -C ' . escapeshellarg($root) . ' check-ignore -q ' . escapeshellarg($f) . ' 2>/dev/null', $o, $rc);
    T::ok($rc !== 0, "{$f} is not excluded by .gitignore");
}

// The rules those files fell foul of must stay anchored. An unanchored form
// matches at every depth, which is how both product endpoints and docs have
// gone missing from a clone before.
$gitignore = @file_get_contents($root . '/.gitignore');
T::ok(is_string($gitignore) && $gitignore !== '', '.gitignore is readable');

if (is_string($gitignore)) {
    $mustBeAnchored = [
        '*_GUIDE.md', '*_IMPLEMENTATION.md', '*_SUMMARY.md',
        '*_COMPLETE.md', '*_FIXES.md', '*_STATUS.md',
        '*_REQUIREMENTS.md', '*_DEPLOYMENT.md', '*_CHANGELOG.md',
        'KNOWLEDGE_BASE.md',
    ];
    foreach ($mustBeAnchored as $rule) {
        $unanchored = preg_match('/^' . preg_quote($rule, '/') . '$/m', $gitignore) === 1;
        T::ok(!$unanchored, "'{$rule}' is anchored to the repository root");
    }
}

exit(T::summary());
