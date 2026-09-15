<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);

/**
 * Compare a deployed tree against this repository.
 *
 * Deployment here is a file copy from a working tree, not a checkout, and that
 * has gone wrong in ways nothing caught. A `cp` list once flattened api/ files
 * into the production root. A stale copy of check_agent_install.php sat at the
 * root carrying a hostname that had been removed from the repository two
 * releases earlier - invisible to tests/server_identity_test.php, which scans
 * the repository and never sees what is actually being served. Old page
 * backups, debug scripts and SQL dumps accumulated for a year.
 *
 * Three things are worth knowing about a deployment:
 *
 *   STALE    a tracked file whose deployed copy differs - the server is not
 *            running the code in the repository
 *   MISSING  a tracked file absent from the deployment
 *   EXTRA    a deployed file that is not tracked and is not runtime data
 *
 * STALE and MISSING are failures. EXTRA is reported but does not fail, because
 * a deployment legitimately holds things a repository does not: release
 * artifacts, keys, logs, backups, vendor code.
 *
 * Usage:
 *   php scripts/check_production_drift.php [--path=/var/www/opnsense] [--extras]
 *
 * @since 3.34.0
 */

$root = dirname(__DIR__);
$path = '/var/www/opnsense';
$showExtras = in_array('--extras', $argv, true);

foreach ($argv as $arg) {
    if (strpos($arg, '--path=') === 0) {
        $path = rtrim(substr($arg, 7), '/');
    }
}

if (!is_dir($path)) {
    fwrite(STDERR, "No deployment at {$path}\n");
    exit(2);
}

if (realpath($path) === realpath($root)) {
    fwrite(STDERR, "Refusing to compare the repository with itself.\n");
    exit(2);
}

/**
 * Paths a deployment legitimately carries that the repository does not.
 *
 * Deliberately specific. A broad rule here is how a stale copy of a real file
 * stays hidden: this must not excuse anything that looks like application code
 * sitting where it does not belong.
 */
$runtimeOnly = [
    // Dependency trees, at any depth: scripts/ has its own node_modules, and a
    // root-anchored pattern let 4,420 of its files through as "extras".
    '#(^|/)vendor/#',        // composer install output
    '#(^|/)node_modules/#',
    '#^downloads/#',         // release artifacts, deliberately gitignored
    '#^logs?/#',
    '#^keys/#',              // per-firewall SSH keys
    '#^backups/#',
    '#^uploads/#',
    '#^cache/#',
    '#^\.git#',
    '#^\.env#',
    '#(^|/)\.htaccess$#',
    '#(^|/)__pycache__/#',  // Python build output
];

exec('cd ' . escapeshellarg($root) . ' && git ls-files 2>/dev/null', $tracked, $status);
if ($status !== 0 || !$tracked) {
    fwrite(STDERR, "Could not list tracked files.\n");
    exit(2);
}

$stale = [];
$missing = [];

foreach ($tracked as $rel) {
    $repoFile = $root . '/' . $rel;
    $deployed = $path . '/' . $rel;

    if (!is_file($repoFile)) {
        continue;
    }
    if (!is_file($deployed)) {
        $missing[] = $rel;
        continue;
    }
    if (md5_file($repoFile) !== md5_file($deployed)) {
        $stale[] = $rel;
    }
}

// Everything deployed, so extras can be found.
$extras = [];
$trackedSet = array_flip($tracked);

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($rii as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $rel = substr($file->getPathname(), strlen($path) + 1);

    if (isset($trackedSet[$rel])) {
        continue;
    }

    foreach ($runtimeOnly as $pattern) {
        if (preg_match($pattern, $rel)) {
            continue 2;
        }
    }

    $extras[] = $rel;
}

sort($stale);
sort($missing);
sort($extras);

printf("Deployment: %s\n", $path);
printf("Repository: %s\n\n", $root);

if ($stale) {
    printf("STALE - deployed copy differs from the repository (%d):\n", count($stale));
    foreach ($stale as $f) {
        printf("  %s\n", $f);
    }
    echo "\n";
}

if ($missing) {
    printf("MISSING - tracked but not deployed (%d):\n", count($missing));
    foreach ($missing as $f) {
        printf("  %s\n", $f);
    }
    echo "\n";
}

if ($extras) {
    printf("EXTRA - deployed but not tracked (%d)%s:\n",
        count($extras), $showExtras ? '' : ', pass --extras to list');
    if ($showExtras) {
        foreach ($extras as $f) {
            printf("  %s\n", $f);
        }
    }
    echo "\n";
}

if (!$stale && !$missing) {
    printf("The deployment matches the repository.%s\n",
        $extras ? sprintf(' %d untracked file(s) present.', count($extras)) : '');
    exit(0);
}

printf("%d stale, %d missing.\n", count($stale), count($missing));
exit(1);
