<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);
/**
 * Report rows that reference a firewall which no longer exists.
 *
 * Every one of these tables has a foreign key to `firewalls`, so InnoDB makes
 * this state impossible through the application. It becomes possible when
 * `foreign_key_checks` is 0 - a restore, an import, a manual bulk load - and
 * once it happens nothing notices: the orphans are invisible to every page,
 * they just accumulate. On 2026-08-31 this installation was carrying ~65,000
 * of them for a single firewall deleted months earlier.
 *
 * Migration 0015 revalidates the constraints, but no constraint definition can
 * prevent a future restore from bypassing them again. This is the recurring
 * guard: run it after any restore or import, and from cron.
 *
 * Usage:
 *   php scripts/check_referential_integrity.php
 *   php scripts/check_referential_integrity.php --json
 *   php scripts/check_referential_integrity.php --all   also tables with no FK
 *
 * Exit codes: 0 = clean, 1 = orphans found.
 *
 * @since 3.20.1
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';

$asJson = in_array('--json', $argv, true);
$all    = in_array('--all', $argv, true);

$pdo = db();

// Tables whose orphans are expected and must not be reported as a defect:
// history that is supposed to outlive the object it describes.
//
// `audit_log` qualifies because it carries no foreign key at all - retaining the
// record of a deleted firewall is the deliberate design. `ssh_access_sessions`
// was listed here too and should not have been: its FK is ON DELETE CASCADE and
// firewall_id is NOT NULL, so the schema is explicit that those rows die with
// the firewall. Treating them as history contradicted the constraint and would
// have blocked migration 0015 forever.
$historyTables = ['audit_log'];

$constraints = $pdo->query(
    "SELECT k.TABLE_NAME AS t, k.COLUMN_NAME AS c, rc.DELETE_RULE AS rule
       FROM information_schema.REFERENTIAL_CONSTRAINTS rc
       JOIN information_schema.KEY_COLUMN_USAGE k
         ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
        AND k.CONSTRAINT_NAME   = rc.CONSTRAINT_NAME
      WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
        AND rc.REFERENCED_TABLE_NAME = 'firewalls'
        AND k.REFERENCED_COLUMN_NAME = 'id'
      ORDER BY k.TABLE_NAME"
)->fetchAll(PDO::FETCH_ASSOC);

// --all additionally covers tables carrying a firewall_id with no constraint at
// all, where orphans are possible through ordinary use rather than only a
// restore. Reported separately: some of these are history by design.
if ($all) {
    $known = array_column($constraints, 't');
    $extra = $pdo->query(
        "SELECT c.TABLE_NAME AS t, 'firewall_id' AS c, '(no FK)' AS rule
           FROM information_schema.COLUMNS c
           JOIN information_schema.TABLES tb
             ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME
          WHERE c.TABLE_SCHEMA = DATABASE()
            AND c.COLUMN_NAME  = 'firewall_id'
            AND tb.TABLE_TYPE  = 'BASE TABLE'
          ORDER BY c.TABLE_NAME"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($extra as $row) {
        if (!in_array($row['t'], $known, true)) {
            $constraints[] = $row;
        }
    }
}

$findings = [];
$total    = 0;

foreach ($constraints as $row) {
    $table  = $row['t'];
    $column = $row['c'];

    // A NULL is legitimate - that is exactly what ON DELETE SET NULL leaves
    // behind - so only a non-NULL value with no surviving parent is an orphan.
    $sql = sprintf(
        'SELECT COUNT(*) AS n,
                GROUP_CONCAT(DISTINCT x.`%1$s` ORDER BY x.`%1$s` SEPARATOR ",") AS ids
           FROM `%2$s` x
           LEFT JOIN firewalls f ON f.id = x.`%1$s`
          WHERE f.id IS NULL AND x.`%1$s` IS NOT NULL',
        $column, $table
    );

    try {
        $res = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $findings[] = ['table' => $table, 'column' => $column, 'rule' => $row['rule'],
                       'rows' => null, 'firewall_ids' => null,
                       'note' => 'could not be checked: ' . $e->getMessage()];
        continue;
    }

    if ((int)$res['n'] === 0) {
        continue;
    }

    $findings[] = [
        'table'        => $table,
        'column'       => $column,
        'rule'         => $row['rule'],
        'rows'         => (int)$res['n'],
        'firewall_ids' => $res['ids'],
        'note'         => in_array($table, $historyTables, true)
            ? 'history: expected to outlive the firewall, not a defect'
            : '',
    ];

    if (!in_array($table, $historyTables, true)) {
        $total += (int)$res['n'];
    }
}

if ($asJson) {
    echo json_encode(['orphans' => $findings, 'actionable_rows' => $total],
                     JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit($total > 0 ? 1 : 0);
}

printf("Checked %d table(s) referencing firewalls.\n\n", count($constraints));

if (!$findings) {
    echo "No orphaned rows. Referential integrity is intact.\n";
    exit(0);
}

$w = max(array_map(fn($f) => strlen($f['table'] . '.' . $f['column']), $findings));
foreach ($findings as $f) {
    printf("  %-{$w}s  %7s row(s)  firewall_id(s): %s\n",
           $f['table'] . '.' . $f['column'],
           $f['rows'] === null ? '?' : $f['rows'],
           $f['firewall_ids'] ?? '?');
    if ($f['note'] !== '') {
        printf("  %-{$w}s  -> %s\n", '', $f['note']);
    }
}

echo "\n";
if ($total === 0) {
    echo "Only history tables hold orphans, which is expected. Nothing to do.\n";
    exit(0);
}

printf("%d actionable orphaned row(s).\n\n", $total);
echo "These cannot be produced through the application - every table above has a\n";
echo "foreign key. Something wrote with foreign_key_checks disabled, most likely a\n";
echo "restore or import. Back the rows up before deleting, then re-run\n";
echo "scripts/migrate.php so migration 0015 can revalidate the constraints.\n";
exit(1);
