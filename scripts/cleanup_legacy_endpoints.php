<?php
/**
 * Remove obsolete web-root endpoints from a deployed installation.
 *
 * These are one-off incident-response scripts from 2025-2026 that were left in
 * the document root. They are unreferenced, several carried hardcoded
 * authentication keys committed to a public repository, and each one serves a
 * shell script that a firewall executes as root.
 *
 * Most match the *_fix.php pattern in .gitignore, so they were never tracked by
 * git and a pull-based deployment will not remove them. This script does.
 *
 * Idempotent: safe to run on every upgrade.
 *
 * Usage:
 *   php scripts/cleanup_legacy_endpoints.php [--dry-run] [--root=/var/www/opnsense]
 *
 * @since 3.12.0
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cleanup_legacy_endpoints.php may only be run from the command line\n");
}

/**
 * Obsolete endpoints, with why each one goes.
 */
const LEGACY_ENDPOINTS = [
    'emergency_fix.php'          => 'unauthenticated root-executing repair script (Feb 2026 incident)',
    'bulletproof_fix.php'        => 'unauthenticated root-executing repair script',
    'working_fix.php'            => 'unauthenticated root-executing repair script',
    'final_fix.php'              => 'unauthenticated root-executing repair script',
    'comprehensive_fix.php'      => 'unauthenticated root-executing repair script',
    'freebsd_native.php'         => 'unauthenticated agent-replacement script',
    'agent_diagnostic.php'       => 'unauthenticated diagnostic script',
    'download_fix.php'           => 'unauthenticated agent-replacement script',
    'setup_agent_cron.php'       => 'hardcoded auth key "setup_agent_cron_2025"',
    'fix_agent_syntax.php'       => 'hardcoded auth key "fix_agent_syntax_2025"',
    'fix_agent_version.php'      => 'hardcoded auth key "fix_agent_version_2025"',
    'emergency_agent_update.php' => 'superseded by the authenticated agent update endpoint',
    'agent_checkin_debug.php'    => 'debug endpoint',
];

$dryRun = in_array('--dry-run', $argv, true);
$root   = '/var/www/opnsense';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--root=')) {
        $root = rtrim(substr($arg, 7), '/');
    }
}

if (!is_dir($root)) {
    fwrite(STDERR, "Document root not found: {$root}\n");
    exit(1);
}

$removed = 0;
$absent  = 0;

foreach (LEGACY_ENDPOINTS as $file => $reason) {
    $path = $root . '/' . $file;
    if (!file_exists($path)) {
        $absent++;
        continue;
    }
    if ($dryRun) {
        echo "would remove {$path}  ({$reason})\n";
        $removed++;
        continue;
    }
    if (@unlink($path)) {
        echo "removed {$path}  ({$reason})\n";
        $removed++;
    } else {
        fwrite(STDERR, "FAILED to remove {$path} - check permissions\n");
    }
}

printf("\n%s%d removed, %d already absent.\n", $dryRun ? '[dry run] ' : '', $removed, $absent);
exit(0);
