<?php
/**
 * Pages must decide who may enter before they start replying.
 *
 * `inc/header.php` begins emitting the page. Once output has started,
 * `header('Location: ...')` cannot take effect, so an auth gate that runs after
 * the include degrades from a redirect into a 200 carrying a truncated page.
 *
 * Measured on the two pages this caught, before they were fixed:
 *
 *   twofactor_setup.php, unauthenticated  200 (should be 302 to /login.php)
 *   users.php, as a technician            200, 13,956 bytes of page shell
 *                                         (should be 302 to /dashboard.php)
 *
 * No data escaped in either case - the gate's exit() still stopped the page
 * before any record rendered - but the access decision produced a broken page
 * instead of a redirect, and a gate that cannot redirect is one refactor away
 * from being a gate that does not stop anything.
 *
 * Run with: php tests/auth_ordering_test.php
 *
 * @since 3.26.2
 */

require_once __DIR__ . '/bootstrap.php';

$root = rtrim(TEST_ROOT, '/');

$out = shell_exec('git -C ' . escapeshellarg($root) . ' ls-files "*.php" 2>/dev/null');
$files = array_values(array_filter(array_map('trim', explode("\n", (string) $out))));
if (!$files) {
    // Not a checkout (a deployed copy); walk instead.
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $rel = substr($f->getPathname(), strlen($root) + 1);
            foreach (['/vendor/', '/node_modules/', '/backups/', '/.archive/'] as $skip) {
                if (str_contains('/' . $rel, $skip)) { continue 2; }
            }
            $files[] = $rel;
        }
    }
    sort($files);
}

T::group('Auth gates run before output');

$gate   = '/\b(isLoggedIn|requireLogin|requireAdmin|requireCapability)\s*\(/';
// Both spellings are in use: require_once 'inc/header.php' and the
// require_once __DIR__ . '/inc/header.php' form. Missing the second one would
// make this check quietly examine almost nothing.
$render = '~require(?:_once)?\s*\(?\s*(?:__DIR__\s*\.\s*)?[\'"][^\'"]*header\.php[\'"]~';

$late = [];
$checked = 0;
foreach ($files as $rel) {
    // inc/ and api/ are includes and JSON endpoints, not rendered pages.
    if (str_starts_with($rel, 'inc/') || str_starts_with($rel, 'tests/')) { continue; }

    $src = (string) file_get_contents($root . '/' . $rel);
    if (!preg_match($render, $src, $mr, PREG_OFFSET_CAPTURE)) { continue; }
    if (!preg_match($gate, $src, $mg, PREG_OFFSET_CAPTURE)) { continue; }

    $checked++;
    if ($mg[0][1] > $mr[0][1]) {
        $lineOut  = substr_count(substr($src, 0, $mr[0][1]), "\n") + 1;
        $lineGate = substr_count(substr($src, 0, $mg[0][1]), "\n") + 1;
        $late[] = "{$rel}: includes inc/header.php at line {$lineOut}, "
                . "but {$mg[1][0]}() is only at line {$lineGate}";
    }
}

T::ok($checked >= 10, "gated pages were actually examined ({$checked})");
foreach ($late as $l) {
    fwrite(STDERR, "  {$l}\n");
}
T::eq(0, count($late), 'no gated page emits the header before its auth gate');

// The two that were wrong, named so a regression is unmistakable.
foreach (['twofactor_setup.php', 'users.php'] as $page) {
    $src = (string) file_get_contents($root . '/' . $page);
    preg_match($render, $src, $mr, PREG_OFFSET_CAPTURE);
    preg_match($gate, $src, $mg, PREG_OFFSET_CAPTURE);
    T::ok(isset($mg[0][1], $mr[0][1]) && $mg[0][1] < $mr[0][1],
          "{$page} authorises before including the header");
}

exit(T::summary());
