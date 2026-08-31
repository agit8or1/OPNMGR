<?php
/**
 * Version consistency check.
 *
 * The VERSION file is the single authoritative source for the application
 * version. For the agent, the authority is the newest RELEASED tarball in
 * downloads/plugins/ - not the in-source agent.sh - because that is the only
 * version a firewall can actually be upgraded to. A source bump that was never
 * packaged is drift, not a release: it makes the UI offer an agent update that
 * cannot be downloaded. Everything else - README badge, inc/version.php
 * constants, the installer's PLUGIN_VERSION, the release manifest, CHANGELOG
 * heading - must agree with those rather than being maintained by hand.
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

// Newest agent tarball that has actually been published for download.
$agentVersion = '';
foreach (glob($root . '/downloads/plugins/os-opnmanager-agent-*.tar.gz') ?: [] as $tarball) {
    if (preg_match('/-(\d+\.\d+\.\d+)\.tar\.gz$/', basename($tarball), $m)
        && ($agentVersion === '' || version_compare($m[1], $agentVersion, '>'))) {
        $agentVersion = $m[1];
    }
}

// The in-source agent script, for the unreleased-bump check below.
$agentScript = $root . '/plugin/os-opnmanager-agent/src/opnsense/scripts/OPNsense/OPNManagerAgent/agent.sh';
$agentSourceVersion = '';
if (is_file($agentScript)
    && preg_match('/^AGENT_VERSION="([^"]+)"/m', (string)file_get_contents($agentScript), $m)) {
    $agentSourceVersion = $m[1];
}

if ($agentVersion === '') {
    fwrite(STDERR, "No released agent tarball found in downloads/plugins/\n");
    exit(1);
}

printf("Authoritative: application %s, agent %s (newest released tarball)\n\n",
       $appVersion, $agentVersion);

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
// APP_VERSION already reads the VERSION file. AGENT_VERSION is the single
// constant for the newest installable agent (LATEST_AGENT_VERSION in
// inc/agent_version.php is just an alias of it) and must match the release.
check(
    $root . '/inc/version.php',
    "/define\('AGENT_VERSION',\s*'([^']+)'\)/",
    $agentVersion,
    'AGENT_VERSION',
    fn(string $c) => preg_replace("/(define\('AGENT_VERSION',\s*')[^']+('\))/", '${1}' . $agentVersion . '${2}', $c) ?: $c,
    $fix, $problems, $fixed
);

// --- installer + release manifest -------------------------------------------
check(
    $root . '/downloads/plugins/install_opnmanager_agent.sh',
    '/^PLUGIN_VERSION="([^"]+)"/m',
    $agentVersion,
    'PLUGIN_VERSION',
    fn(string $c) => preg_replace('/(^PLUGIN_VERSION=")[^"]+(")/m', '${1}' . $agentVersion . '${2}', $c) ?: $c,
    $fix, $problems, $fixed
);

check(
    $root . '/downloads/AGENT_VERSION.txt',
    '/^\s*(\S+)\s*$/',
    $agentVersion,
    'published agent version',
    fn(string $c) => $agentVersion . "\n",
    $fix, $problems, $fixed
);

// --- source vs published tarball --------------------------------------------
// Warning only, never drift: the plugin source legitimately runs ahead of the
// last package between releases. It exists because agent.sh carries the version
// label by hand, so source can sit at a version already published with different
// contents - and the next packaging run would then overwrite a released tarball.
// Whoever cuts that release must bump AGENT_VERSION first.
if ($agentSourceVersion !== '' && $agentSourceVersion === $agentVersion) {
    $tarball = $root . '/downloads/plugins/os-opnmanager-agent-' . $agentVersion . '.tar.gz';
    $sourceDir = $root . '/plugin/os-opnmanager-agent/src';
    if (is_file($tarball) && is_dir($sourceDir)) {
        $prefix = 'phar://' . realpath($tarball) . '/';
        $packaged = [];
        try {
            $phar = new PharData($tarball);
            foreach (new RecursiveIteratorIterator($phar) as $entry) {
                if (!$entry->isFile()) continue;
                $rel = substr($entry->getPathname(), strlen($prefix));
                $packaged[$rel] = md5_file($entry->getPathname());
            }
        } catch (Throwable $e) {
            $packaged = [];
        }

        if ($packaged) {
            $differs = [];
            $rii = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($rii as $file) {
                if (!$file->isFile()) continue;
                $rel = substr($file->getPathname(), strlen($sourceDir) + 1);
                if (strpos($rel, '__pycache__') !== false) continue;
                if (!isset($packaged[$rel])) {
                    $differs[] = $rel . ' (not packaged)';
                } elseif ($packaged[$rel] !== md5_file($file->getPathname())) {
                    $differs[] = $rel . ' (modified)';
                }
            }
            if ($differs) {
                sort($differs);
                printf("  WARNING  %-22s source differs from the published v%s tarball:\n",
                       'plugin/src', $agentVersion);
                foreach ($differs as $d) {
                    printf("           %s\n", $d);
                }
                printf("           Bump AGENT_VERSION before packaging - do not republish v%s.\n",
                       $agentVersion);
            }
        }
    }
}

// --- unreleased source bump -------------------------------------------------
// Never auto-fixed in either direction: the answer is either to package the new
// agent or to revert the bump, and only a human knows which.
if ($agentSourceVersion !== '' && version_compare($agentSourceVersion, $agentVersion, '>')) {
    $problems[] = sprintf(
        'agent.sh: source is v%s but the newest released tarball is v%s - either package v%s or revert the bump',
        $agentSourceVersion, $agentVersion, $agentSourceVersion
    );
    printf("  UNRELEASED agent.sh              AGENT_VERSION = %s (newest release %s)\n",
           $agentSourceVersion, $agentVersion);
} elseif ($agentSourceVersion !== '') {
    printf("  ok       %-22s %s = %s\n", 'agent.sh', 'AGENT_VERSION', $agentSourceVersion);
}

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
