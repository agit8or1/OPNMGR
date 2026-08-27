<?php
/**
 * Version consistency check.
 *
 * The VERSION file is the single authoritative source for the application
 * version, and the agent's own AGENT_VERSION line is authoritative for the
 * agent. Everything else - README badge, inc/version.php constants, CHANGELOG
 * heading, plugin metadata - must agree with those rather than being maintained
 * by hand and drifting.
 *
 * Usage:
 *   php scripts/check_versions.php          # report, exit 1 on drift
 *   php scripts/check_versions.php --fix    # rewrite derived references
 *
 * Runs in CI so drift fails the build rather than being noticed months later.
 *
 * @since 3.17.0
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("check_versions.php may only be run from the command line\n");
}

$root = dirname(__DIR__);
$fix  = in_array('--fix', $argv, true);

// --- authoritative sources --------------------------------------------------
$appVersion = trim((string)@file_get_contents($root . '/VERSION'));
if ($appVersion === '') {
    fwrite(STDERR, "VERSION file is missing or empty\n");
    exit(1);
}

$agentScript = $root . '/plugin/os-opnmanager-agent/src/opnsense/scripts/OPNsense/OPNManagerAgent/agent.sh';
$agentVersion = '';
if (is_file($agentScript)) {
    if (preg_match('/^AGENT_VERSION="([^"]+)"/m', (string)file_get_contents($agentScript), $m)) {
        $agentVersion = $m[1];
    }
}

printf("Authoritative: application %s, agent %s\n\n", $appVersion, $agentVersion ?: '(unknown)');

$problems = [];
$fixed    = [];

/**
 * Check one derived reference, optionally rewriting it.
 */
function check(string $file, string $pattern, string $expected, string $label,
               callable $replace, bool $fix, array &$problems, array &$fixed): void {
    if (!is_file($file)) {
        return;
    }
    $content = (string)file_get_contents($file);

    if (!preg_match($pattern, $content, $m)) {
        $problems[] = sprintf('%s: could not find %s', basename($file), $label);
        return;
    }

    if ($m[1] === $expected) {
        printf("  ok       %-22s %s = %s\n", basename($file), $label, $m[1]);
        return;
    }

    if ($fix) {
        $updated = $replace($content);
        if ($updated !== $content) {
            file_put_contents($file, $updated);
            $fixed[] = sprintf('%s: %s %s -> %s', basename($file), $label, $m[1], $expected);
            printf("  FIXED    %-22s %s %s -> %s\n", basename($file), $label, $m[1], $expected);
            return;
        }
    }

    $problems[] = sprintf('%s: %s is %s, expected %s', basename($file), $label, $m[1], $expected);
    printf("  DRIFT    %-22s %s = %s (expected %s)\n", basename($file), $label, $m[1], $expected);
}

// --- README badge -----------------------------------------------------------
check(
    $root . '/README.md',
    '/badge\/version-([0-9][^-]*)-blue/',
    $appVersion,
    'version badge',
    fn(string $c) => preg_replace('/(badge\/version-)[0-9][^-]*(-blue)/', '${1}' . $appVersion . '${2}', $c)
                   ?: $c,
    $fix, $problems, $fixed
);

check(
    $root . '/README.md',
    '/\*\*Agent\*\*: v([0-9][0-9.]*)/',
    $agentVersion,
    'agent version',
    fn(string $c) => preg_replace('/(\*\*Agent\*\*: v)[0-9][0-9.]*/', '${1}' . $agentVersion, $c) ?: $c,
    $fix, $problems, $fixed
);

check(
    $root . '/README.md',
    '/\[!\[v([0-9][0-9.]*)\]/',
    $appVersion,
    'version link text',
    fn(string $c) => preg_replace('/(\[!\[v)[0-9][0-9.]*(\])/', '${1}' . $appVersion . '${2}', $c) ?: $c,
    $fix, $problems, $fixed
);

// --- inc/version.php --------------------------------------------------------
// APP_VERSION already reads the VERSION file; AGENT_VERSION is a constant and
// must be kept in step with the agent script.
check(
    $root . '/inc/version.php',
    "/define\('AGENT_VERSION',\s*'([^']+)'\)/",
    $agentVersion,
    'AGENT_VERSION',
    fn(string $c) => preg_replace("/(define\('AGENT_VERSION',\s*')[^']+('\))/", '${1}' . $agentVersion . '${2}', $c) ?: $c,
    $fix, $problems, $fixed
);

// --- CHANGELOG --------------------------------------------------------------
check(
    $root . '/CHANGELOG.md',
    '/^## Version ([0-9][0-9.]*)/m',
    $appVersion,
    'newest entry',
    fn(string $c) => $c,   // never rewritten: a missing entry is a real omission
    false, $problems, $fixed
);

// ---------------------------------------------------------------------------
echo "\n";

if ($fixed) {
    printf("%d reference(s) updated.\n", count($fixed));
}

if ($problems) {
    echo "Version drift:\n";
    foreach ($problems as $p) {
        echo "  - {$p}\n";
    }
    echo "\nRun with --fix to rewrite the derived references.\n";
    echo "A missing CHANGELOG entry is never auto-fixed: write it.\n";
    exit(1);
}

echo "All version references agree.\n";
exit(0);
