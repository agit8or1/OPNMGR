<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);
/**
 * OPNMGR Database Migration Runner
 *
 * Applies pending SQL migrations from database/migrations/ in filename order,
 * recording each applied file in the `schema_migrations` table so that
 * re-running is a no-op.
 *
 * Migrations MUST be written idempotently (see database/migrations/README.md)
 * so that a partially-applied migration can be safely re-run.
 *
 * Usage:
 *   php scripts/migrate.php            # apply all pending migrations
 *   php scripts/migrate.php --status   # list applied / pending, apply nothing
 *   php scripts/migrate.php --dry-run  # show what would run, apply nothing
 *
 * Exit codes: 0 = success, 1 = failure.
 *
 * @since 3.12.0
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("migrate.php may only be run from the command line\n");
}

require_once __DIR__ . '/../inc/bootstrap_agent.php';

$migrationDir = dirname(__DIR__) . '/database/migrations';
$status  = in_array('--status', $argv, true);
$dryRun  = in_array('--dry-run', $argv, true);

$pdo = db();

// ---------------------------------------------------------------------------
// Bookkeeping table
// ---------------------------------------------------------------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        id          INT(11) NOT NULL AUTO_INCREMENT,
        migration   VARCHAR(255) NOT NULL,
        checksum    CHAR(64) NOT NULL,
        applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_migration (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$applied = $pdo->query('SELECT migration, checksum FROM schema_migrations')
               ->fetchAll(PDO::FETCH_KEY_PAIR);

$files = glob($migrationDir . '/*.sql') ?: [];
sort($files, SORT_STRING);

if (!$files) {
    echo "No migration files found in {$migrationDir}\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Status / dry-run reporting
// ---------------------------------------------------------------------------
$pending = [];
foreach ($files as $file) {
    $name     = basename($file);
    $checksum = hash('sha256', file_get_contents($file));

    if (isset($applied[$name])) {
        if ($status) {
            $drift = $applied[$name] === $checksum ? '' : '  [WARNING: file changed since it was applied]';
            echo "  applied  {$name}{$drift}\n";
        }
        continue;
    }
    $pending[] = ['file' => $file, 'name' => $name, 'checksum' => $checksum];
    if ($status || $dryRun) {
        echo "  PENDING  {$name}\n";
    }
}

if ($status || $dryRun) {
    printf("\n%d applied, %d pending\n", count($applied), count($pending));
    exit(0);
}

if (!$pending) {
    echo "Database is up to date (" . count($applied) . " migrations applied).\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Apply
// ---------------------------------------------------------------------------
$record = $pdo->prepare(
    'INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE checksum = VALUES(checksum)'
);

foreach ($pending as $m) {
    echo "Applying {$m['name']} ... ";
    $sql = file_get_contents($m['file']);

    try {
        // Migrations manage their own transactional boundaries where needed.
        // MySQL DDL is implicitly committed, so we do not wrap the whole file.
        foreach (splitSqlStatements($sql) as $statement) {
            // query() rather than exec() so that statements which return a
            // rowset (a trailing verification SELECT, SHOW, etc.) can have
            // their cursor drained -- an undrained result set makes every
            // later statement fail with "unbuffered queries are active".
            $result = $pdo->query($statement);
            if ($result instanceof PDOStatement) {
                do {
                    $result->fetchAll();
                } while ($result->nextRowset());
                $result->closeCursor();
            }
        }
        $record->execute([$m['name'], $m['checksum']]);
        echo "ok\n";
    } catch (Throwable $e) {
        echo "FAILED\n";
        fwrite(STDERR, "\nMigration {$m['name']} failed: " . $e->getMessage() . "\n");
        fwrite(STDERR, "Database left at the last successfully applied migration.\n");
        exit(1);
    }
}

echo "\nDone. " . count($pending) . " migration(s) applied.\n";
exit(0);

/**
 * Split a migration file into individual statements.
 *
 * Handles `DELIMITER $$ ... $$ DELIMITER ;` blocks (used for stored procedures,
 * which is how we express conditional DDL on MySQL/MariaDB), line comments and
 * quoted strings containing semicolons.
 *
 * @param string $sql Raw migration file contents
 * @return string[]   Non-empty statements, in order
 */
function splitSqlStatements(string $sql): array {
    $statements = [];
    $buffer     = '';
    $delimiter  = ';';
    $inString   = false;
    $stringChar = '';
    $len        = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $char = $sql[$i];

        // Quoted string handling (with backslash escapes)
        if ($inString) {
            $buffer .= $char;
            if ($char === '\\' && $i + 1 < $len) {
                $buffer .= $sql[++$i];
            } elseif ($char === $stringChar) {
                $inString = false;
            }
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $inString   = true;
            $stringChar = $char;
            $buffer    .= $char;
            continue;
        }

        // Line comments: -- ... and # ...
        if (($char === '-' && substr($sql, $i, 3) === '-- ') || $char === '#') {
            $eol     = strpos($sql, "\n", $i);
            $i       = $eol === false ? $len : $eol;
            $buffer .= "\n";
            continue;
        }

        // Block comments (but not MySQL executable comments /*! ... */)
        if ($char === '/' && substr($sql, $i, 2) === '/*' && substr($sql, $i, 3) !== '/*!') {
            $end     = strpos($sql, '*/', $i);
            $i       = $end === false ? $len : $end + 1;
            $buffer .= ' ';
            continue;
        }

        // DELIMITER directive (only valid at the start of a line)
        if (($char === 'D' || $char === 'd')
            && ($i === 0 || $sql[$i - 1] === "\n")
            && preg_match('/^DELIMITER[ \t]+(\S+)/i', substr($sql, $i, 64), $m)) {
            if (trim($buffer) !== '') {
                $statements[] = trim($buffer);
                $buffer = '';
            }
            $delimiter = $m[1];
            $eol       = strpos($sql, "\n", $i);
            $i         = $eol === false ? $len : $eol;
            continue;
        }

        // Statement terminator
        if (substr($sql, $i, strlen($delimiter)) === $delimiter) {
            if (trim($buffer) !== '') {
                $statements[] = trim($buffer);
            }
            $buffer = '';
            $i     += strlen($delimiter) - 1;
            continue;
        }

        $buffer .= $char;
    }

    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return array_values(array_filter($statements, fn($s) => trim($s) !== ''));
}
