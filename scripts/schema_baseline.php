<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);
/**
 * Emit the migration seed data and the schema_migrations baseline for
 * database/schema.sql. Called by scripts/generate_schema.sh; not useful alone.
 *
 * It reads a scratch database that holds the published structure with every
 * migration applied, and prints SQL that:
 *
 *   1. inserts every row the migrations seeded (settings, scheduled jobs, ...)
 *      except the reference tables generate_schema.sh already dumps, and
 *   2. records every migration as applied, with the checksum migrate.php itself
 *      stored, so a clean install never re-runs them.
 *
 * Without (2), the first migrate.php after a clean install re-ran every
 * migration and stored whatever defaults those old files carried.
 *
 * Rows are emitted without their auto-increment id or timestamp-default
 * columns, as INSERT IGNORE on their unique key, so the output is stable across
 * regenerations and re-importing the file never overwrites a stored value.
 *
 * The scratch database must be named opnsense_fw_test*: the live settings table
 * holds SMTP and API credentials and must never be written into a public file.
 *
 *   DB_NAME=opnsense_fw_test_schemagen php scripts/schema_baseline.php [skip_table ...]
 *
 * @since 3.80.3
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../inc/bootstrap_agent.php';

$pdo = db();
$db  = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if (strpos($db, 'opnsense_fw_test') !== 0) {
    fwrite(STDERR, "refusing: connected to '{$db}', not a scratch opnsense_fw_test* database\n");
    exit(1);
}

$skip = array_merge(['schema_migrations'], array_slice($argv, 1));

$tables = $pdo->query("SELECT table_name FROM information_schema.tables
                        WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
                        ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);

echo "\n-- -----------------------------------------------------------------------------\n";
echo "-- Data seeded by the migrations\n";
echo "-- -----------------------------------------------------------------------------\n";

foreach ($tables as $table) {
    if (in_array($table, $skip, true)) {
        continue;
    }
    $cols = $pdo->prepare("SELECT column_name, extra, column_default FROM information_schema.columns
                            WHERE table_schema = DATABASE() AND table_name = ?
                            ORDER BY ordinal_position");
    $cols->execute([$table]);
    $keep = [];
    foreach ($cols->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $c = array_change_key_case($c, CASE_LOWER);
        if (stripos((string)$c['extra'], 'auto_increment') !== false) {
            continue;
        }
        if (stripos((string)$c['column_default'], 'current_timestamp') !== false) {
            continue;
        }
        $keep[] = $c['column_name'];
    }
    $quotedCols = implode(', ', array_map(fn($c) => "`{$c}`", $keep));
    // Order by every kept column so the output does not depend on insertion order.
    $rows = $pdo->query("SELECT {$quotedCols} FROM `{$table}` ORDER BY {$quotedCols}")->fetchAll(PDO::FETCH_NUM);
    if (!$rows) {
        continue;
    }
    foreach ($rows as $row) {
        $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row);
        echo "INSERT IGNORE INTO `{$table}` ({$quotedCols}) VALUES (" . implode(', ', $vals) . ");\n";
    }
}

echo "\n-- -----------------------------------------------------------------------------\n";
echo "-- Migration baseline: everything above already includes these migrations.\n";
echo "-- -----------------------------------------------------------------------------\n";

$applied = $pdo->query('SELECT migration, checksum FROM schema_migrations ORDER BY migration')
               ->fetchAll(PDO::FETCH_KEY_PAIR);
$files = array_map('basename', glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: []);
sort($files, SORT_STRING);
if (array_keys($applied) !== $files) {
    fwrite(STDERR, "refusing: the scratch database has not applied exactly the migrations on disk\n");
    exit(1);
}
foreach ($applied as $name => $checksum) {
    echo "INSERT IGNORE INTO `schema_migrations` (`migration`, `checksum`) VALUES ("
        . $pdo->quote($name) . ', ' . $pdo->quote($checksum) . ");\n";
}
