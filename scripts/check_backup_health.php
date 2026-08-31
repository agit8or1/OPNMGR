<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);
/**
 * Report firewalls whose configuration backups are not actually landing.
 *
 * A backup row is created when the upload is *queued*, not when it arrives. If
 * the upload is then rejected - as every one was between the agent
 * authentication added in 3.12.0 and the builder fix in 3.20.3 - the row stays,
 * the firewall reports the command as completed, and nothing anywhere says the
 * backup does not exist. This installation ran in that state for months and
 * accumulated 170 rows describing backups that were never stored.
 *
 * Counting rows is therefore not a measure of backup coverage. This checks the
 * two things that actually matter: whether a firewall has a recent backup whose
 * file is on disk, and whether any row is claiming a file that is not there.
 *
 * Usage:
 *   php scripts/check_backup_health.php
 *   php scripts/check_backup_health.php --days=3
 *   php scripts/check_backup_health.php --json
 *   php scripts/check_backup_health.php --log     also record the verdict in system_logs
 *
 * Exit codes: 0 = every firewall has a recent verified backup, 1 = a firewall
 * is uncovered, 2 = covered but rows reference missing files.
 *
 * @since 3.20.3
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/backup_storage.php';

$asJson = in_array('--json', $argv, true);
// --log records the verdict in system_logs so a scheduled run surfaces in the
// app rather than only in a file nobody opens - which is how the upload
// breakage this check exists for went unnoticed for months.
$toLog  = in_array('--log', $argv, true);
$days   = 2;
foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = max(1, (int)$m[1]);
    }
}

$firewalls = db()->query(
    'SELECT id, hostname FROM firewalls ORDER BY hostname'
)->fetchAll(PDO::FETCH_ASSOC);

$rows       = [];
$uncovered  = 0;
$unreadable = false;

foreach ($firewalls as $fw) {
    $stmt = db()->prepare(
        'SELECT * FROM backups WHERE firewall_id = ? ORDER BY created_at DESC LIMIT 40'
    );
    $stmt->execute([$fw['id']]);

    $latestVerified = null;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $backup) {
        // A row only counts once its file is actually readable on disk.
        // 'unreadable' means the store exists but this process cannot traverse
        // it - /var/lib/opnmgr/backups is www-data:www-data 0750. That is a
        // permissions problem with the check, not a missing backup.
        $status = resolve_backup_path_status($backup)['status'];
        if ($status === 'ok') {
            $latestVerified = $backup;
            break;
        }
        if ($status === 'unreadable') {
            $unreadable = true;
        }
    }

    $ageDays = $latestVerified
        ? (time() - strtotime($latestVerified['created_at'])) / 86400
        : null;
    $covered = $ageDays !== null && $ageDays <= $days;

    if (!$covered) {
        $uncovered++;
    }

    $rows[] = [
        'firewall'        => $fw['hostname'],
        'covered'         => $covered,
        'latest_verified' => $latestVerified['created_at'] ?? null,
        'age_days'        => $ageDays === null ? null : round($ageDays, 1),
        'file'            => $latestVerified
            ? basename((string)resolve_backup_path($latestVerified))
            : null,
    ];
}

// Rows promising a file that is not there. Not fatal on its own - it is history
// of past failures - but a growing count means uploads are breaking again.
$missing = db()->query(
    'SELECT COUNT(*) FROM backups WHERE storage_path IS NULL'
)->fetchColumn();
// "Failing now" means a row with no file was created AFTER the newest upload
// that did succeed. A plain 7-day window raises a false alarm for a week after
// any fix, because rows from just before it are still inside the window.
$recentMissing = db()->query(
    'SELECT COUNT(*) FROM backups
      WHERE storage_path IS NULL
        AND created_at > COALESCE(
              (SELECT MAX(created_at) FROM backups b2 WHERE b2.storage_path IS NOT NULL),
              \'1970-01-01\')'
)->fetchColumn();

// Without read access the coverage answer would be wrong in the dangerous
// direction - healthy backups reported as missing - so refuse to answer.
if ($unreadable) {
    $me = function_exists('posix_geteuid')
        ? (posix_getpwuid(posix_geteuid())['name'] ?? 'this user')
        : 'this user';
    fwrite(STDERR,
        "Cannot read the backup store as " . $me . ": " . backup_storage_root()
        . " is not traversable.\n"
        . "Coverage cannot be determined. Re-run as the web server user or root:\n"
        . "  sudo -u www-data php scripts/check_backup_health.php\n");
    backup_health_log($toLog, 'ERROR',
        'Backup health check could not read ' . backup_storage_root() . '; coverage unknown');
    exit(3);
}

if ($asJson) {
    echo json_encode([
        'window_days'          => $days,
        'uncovered_firewalls'  => $uncovered,
        'rows_without_file'    => (int)$missing,
        'rows_without_file_7d' => (int)$recentMissing,
        'firewalls'            => $rows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    backup_health_log($toLog, $uncovered > 0 ? 'ERROR' : ($recentMissing > 0 ? 'WARNING' : 'INFO'),
        backup_health_summary($rows, $uncovered, (int)$recentMissing));
    exit($uncovered > 0 ? 1 : ($recentMissing > 0 ? 2 : 0));
}

printf("Verified backup coverage (a backup counts only if its file is on disk), window %dd\n\n", $days);

$w = max(8, ...array_map(fn($r) => strlen($r['firewall']), $rows ?: [['firewall' => '']]));
foreach ($rows as $r) {
    printf("  %-9s %-{$w}s  %s\n",
        $r['covered'] ? 'OK' : 'UNCOVERED',
        $r['firewall'],
        $r['latest_verified'] === null
            ? 'no verified backup on record'
            : sprintf('%s (%s days old)  %s',
                      $r['latest_verified'], $r['age_days'], $r['file']));
}

echo "\n";
printf("%d of %d firewall(s) uncovered.\n", $uncovered, count($rows));

if ($missing > 0) {
    printf("%d backup row(s) reference a file that is not on disk", $missing);
    printf($recentMissing > 0 ? ", %d of them newer than the last successful upload.\n" : ".\n",
           $recentMissing);
    if ($recentMissing > 0) {
        echo "Recent ones mean uploads are failing now - check that the queued command\n";
        echo "carries agent credentials (inc/backup_storage.php build_backup_upload_command).\n";
    } else {
        echo "All of them predate the last successful upload, so uploads are working;\n";
        echo "retention will age these rows out.\n";
    }
}

backup_health_log($toLog, $uncovered > 0 ? 'ERROR' : ($recentMissing > 0 ? 'WARNING' : 'INFO'),
                  backup_health_summary($rows, $uncovered, (int)$recentMissing));

exit($uncovered > 0 ? 1 : ($recentMissing > 0 ? 2 : 0));

/** One-line verdict, for system_logs and for syslog. */
function backup_health_summary(array $rows, int $uncovered, int $recentMissing): string {
    if ($uncovered > 0) {
        $names = array_column(array_filter($rows, fn($r) => !$r['covered']), 'firewall');
        return sprintf('Backup coverage: %d of %d firewall(s) have no recent verified backup (%s)',
                       $uncovered, count($rows), implode(', ', $names));
    }
    if ($recentMissing > 0) {
        return sprintf('Backup coverage: all %d firewall(s) covered, but %d row(s) newer than the '
                     . 'last successful upload reference a missing file - uploads may be failing',
                       count($rows), $recentMissing);
    }
    return sprintf('Backup coverage: all %d firewall(s) have a recent verified backup', count($rows));
}

/** Record the verdict in system_logs when asked; never fatal. */
function backup_health_log(bool $enabled, string $level, string $message): void {
    if (!$enabled) {
        return;
    }
    try {
        db()->prepare(
            "INSERT INTO system_logs (category, message, level, timestamp)
             VALUES ('backup', ?, ?, NOW())"
        )->execute([$message, $level]);
    } catch (Throwable $e) {
        fwrite(STDERR, 'could not write system_logs entry: ' . $e->getMessage() . "\n");
    }
}
