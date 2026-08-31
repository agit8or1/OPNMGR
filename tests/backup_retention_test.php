<?php
/**
 * Backup retention window enforcement.
 *
 * Retention was configurable from 3.12.0 and enforced by nothing: no code read
 * backup_retention_days, and the settings UI wrote a months/count scheme that
 * nothing read either. These cover the pruner that now applies it, and in
 * particular the floor that stops an age-only sweep from deleting every copy of
 * a stale firewall's configuration.
 *
 * Run with: php tests/backup_retention_test.php
 * Creates and removes its own fixtures.
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/backup_storage.php';

// Every destructive call below is scoped to this test's own firewall ids.
// An unscoped prune_expired_backups(true, ...) here runs against whatever
// database the environment points at - which on a developer machine is the
// live one - and deletes real backups. Do not remove the $fwA/$fwB arguments.

$fwA = 0; $fwB = 0; $tmpDir = '';
register_shutdown_function(function () use (&$fwA, &$fwB, &$tmpDir) {
    foreach ([$fwA, $fwB] as $id) {
        if (!$id) continue;
        try { db()->prepare('DELETE FROM backups WHERE firewall_id = ?')->execute([$id]); } catch (Throwable $e) {}
        try { db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$id]); } catch (Throwable $e) {}
    }
    if ($tmpDir && is_dir($tmpDir)) {
        foreach (glob("$tmpDir/*") as $f) { @unlink($f); }
        @rmdir($tmpDir);
    }
});

function mk_fw(string $name): int {
    db()->prepare('INSERT INTO firewalls (hostname, ip_address, hardware_id, status) VALUES (?,?,?,"online")')
        ->execute([$name, '198.51.100.33', hash('md5', $name . random_bytes(6))]);
    return (int)db()->lastInsertId();
}

$fwA = mk_fw('__test_fw_retain_a__');
$fwB = mk_fw('__test_fw_retain_b__');

$tmpDir = sys_get_temp_dir() . '/opnmgr_retention_' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0700, true);

/**
 * Insert a backup row $daysAgo old, optionally with real bytes on disk.
 */
function mk_backup(int $fwId, int $daysAgo, bool $withFile = true): array {
    global $tmpDir;
    $path = '';
    if ($withFile) {
        $path = $tmpDir . '/bk_' . $fwId . '_' . $daysAgo . '_' . bin2hex(random_bytes(3)) . '.xml';
        file_put_contents($path, "<opnsense><test/></opnsense>\n");
    }
    db()->prepare(
        "INSERT INTO backups (firewall_id, backup_file, description, created_at, backup_type, file_size, storage_path)
         VALUES (?, ?, 'retention test', DATE_SUB(NOW(), INTERVAL ? DAY), 'automated', 100, ?)"
    )->execute([$fwId, basename($path ?: 'absent.xml'), $daysAgo, $path ?: null]);
    return ['id' => (int)db()->lastInsertId(), 'path' => $path];
}

function row_exists(int $id): bool {
    $s = db()->prepare('SELECT 1 FROM backups WHERE id = ?');
    $s->execute([$id]);
    return (bool)$s->fetchColumn();
}

// ---------------------------------------------------------------------------
T::group('Reading the retention window');

$days = backup_retention_days();
T::ok($days >= 0 && $days <= 3650, 'configured window is within bounds');
T::ok(backup_retention_floor() >= 1, 'floor is at least one backup');

// ---------------------------------------------------------------------------
T::group('Selecting expired backups');

// fwA: plenty of recent backups plus old ones. With a floor of 2 the two newest
// survive and the genuinely old ones past that are candidates.
$recentA = [mk_backup($fwA, 1), mk_backup($fwA, 2), mk_backup($fwA, 3)];
$oldA    = [mk_backup($fwA, 200), mk_backup($fwA, 300)];

$expired = find_expired_backups(90, 2);
$ids = array_column($expired, 'id');

foreach ($oldA as $b) {
    T::ok(in_array($b['id'], $ids), "backup {$b['id']} beyond the window and the floor is expired");
}
foreach ($recentA as $b) {
    T::ok(!in_array($b['id'], $ids), "recent backup {$b['id']} is never expired");
}

// ---------------------------------------------------------------------------
T::group('The floor protects a stale firewall');

// fwB stopped checking in months ago: every backup it has is older than the
// window. An age-only rule would delete all of them.
$staleB = [mk_backup($fwB, 150), mk_backup($fwB, 160), mk_backup($fwB, 170), mk_backup($fwB, 400)];

$expiredB = array_filter(find_expired_backups(90, 3), fn($r) => (int)$r['firewall_id'] === $fwB);
T::eq(1, count($expiredB), 'only the backups beyond the floor expire, not all of them');

$survivorIds = array_diff(array_column($staleB, 'id'), array_column($expiredB, 'id'));
T::eq(3, count($survivorIds), 'the three newest backups survive despite being outside the window');
T::ok(!in_array($staleB[3]['id'], $survivorIds), 'the oldest is the one that goes');

// ---------------------------------------------------------------------------
T::group('Retention disabled');

T::eq([], find_expired_backups(0, 3), 'a window of 0 expires nothing');
$off = prune_expired_backups(true, 0, 3, $fwA);
T::eq(0, $off['candidates'], 'disabled retention prunes nothing even with --apply');

// ---------------------------------------------------------------------------
T::group('Dry run deletes nothing');

$before = prune_expired_backups(false, 90, 2, $fwA);
T::ok($before['candidates'] > 0, 'dry run finds candidates');
T::eq(0, $before['deleted'], 'dry run deletes no rows');
T::eq(false, $before['applied'], 'dry run reports itself as such');
foreach ($oldA as $b) {
    T::ok(row_exists($b['id']), "dry run left backup {$b['id']} in place");
    T::ok(is_file($b['path']), "dry run left the bytes of {$b['id']} on disk");
}

// ---------------------------------------------------------------------------
T::group('Applying the policy');

$paths = array_column($oldA, 'path');
$after = prune_expired_backups(true, 90, 2, $fwA);

T::ok($after['deleted'] > 0, 'apply deletes rows');
foreach ($oldA as $b) {
    T::ok(!row_exists($b['id']), "backup {$b['id']} row removed");
}
foreach ($paths as $p) {
    T::ok(!is_file($p), 'the bytes were removed with the row, not orphaned');
}
foreach ($recentA as $b) {
    T::ok(row_exists($b['id']), "recent backup {$b['id']} untouched");
}

// A second run is a no-op rather than an error.
$again = prune_expired_backups(true, 90, 2, $fwA);
$againA = array_filter(find_expired_backups(90, 2), fn($r) => (int)$r['firewall_id'] === $fwA);
T::eq(0, count($againA), 'pruning is idempotent for this firewall');

// ---------------------------------------------------------------------------
T::group('A row whose file already vanished');

$ghost = mk_backup($fwA, 500, false);
$rep = prune_expired_backups(true, 90, 2, $fwA);
T::ok(!row_exists($ghost['id']), 'a row with no file is still cleaned up');
T::ok($rep['files_missing'] >= 1, 'and is counted as missing rather than removed');

exit(T::summary());
