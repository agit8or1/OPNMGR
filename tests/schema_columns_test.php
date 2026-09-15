<?php
/**
 * Schema/code column contract.
 *
 * Every column named in a literal INSERT or UPDATE must exist in the shipped
 * schema. Nothing else checks this: column names live in SQL strings and array
 * keys, which no linter reads, so a rename or a typo stays invisible until the
 * statement runs — and several of these sat in the tree for a long time:
 *
 *   - inc/alerts.php wrote `recipient_email`, so alert history was never
 *     recorded and repeat-notification suppression silently never engaged.
 *   - firewall_proxy.php wrote `request_body` and read `status_code`, so the
 *     on-demand web proxy threw at both ends.
 *   - api/record_speedtest.php and api/run_speedtest.php wrote a shape that
 *     disagreed with `firewall_speedtest`.
 *   - agent_selfheal_report.php wrote to `agent_selfheal_log`, a table that has
 *     never existed in any schema in this repository.
 *
 * Run with: php tests/schema_columns_test.php
 *
 * @since 3.25.1
 */

require_once __DIR__ . '/bootstrap.php';

/** Collect {table => [columns]} from schema.sql and every migration. */
function schema_columns(string $root): array
{
    $files = [$root . '/database/schema.sql'];
    foreach (['/database/migrations', '/db/migrations', '/sql'] as $dir) {
        if (is_dir($root . $dir)) {
            foreach (glob($root . $dir . '/*.sql') as $f) {
                $files[] = $f;
            }
        }
    }
    sort($files);

    $tables = [];
    $type = 'BIGINT|INT|INTEGER|VARCHAR|VARBINARY|LONGTEXT|MEDIUMTEXT|TINYTEXT|TEXT|'
          . 'TIMESTAMP|DATETIME|DATE|TIME|YEAR|FLOAT|DOUBLE|DECIMAL|NUMERIC|TINYINT|'
          . 'SMALLINT|MEDIUMINT|CHAR|LONGBLOB|MEDIUMBLOB|TINYBLOB|BLOB|ENUM|SET|JSON|'
          . 'BOOLEAN|BOOL|BIT';

    foreach ($files as $file) {
        $sql = (string) file_get_contents($file);

        // CREATE TABLE <name> ( ... )
        if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?\s*\((.*?)\n\s*\)\s*(?:ENGINE|;|DEFAULT)/si',
                           $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $t = strtolower($hit[1]);
                foreach (explode("\n", $hit[2]) as $line) {
                    $line = trim($line);
                    if (preg_match('/^(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FULLTEXT|SPATIAL|FOREIGN|CHECK)\b/i', $line)) {
                        continue;
                    }
                    if (preg_match('/^[`"]?(\w+)[`"]?\s+(?:' . $type . ')\b/i', $line, $c)) {
                        $tables[$t][strtolower($c[1])] = true;
                    }
                }
            }
        }

        // ALTER TABLE <name> ADD [COLUMN] <col>, and CHANGE old new
        if (preg_match_all('/ALTER\s+TABLE\s+[`"]?(\w+)[`"]?\s+(.*?);/si', $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $t = strtolower($hit[1]);
                if (preg_match_all('/ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?[`"](\w+)[`"]/i', $hit[2], $adds)) {
                    foreach ($adds[1] as $c) { $tables[$t][strtolower($c)] = true; }
                }
                if (preg_match_all('/CHANGE\s+(?:COLUMN\s+)?[`"](\w+)[`"]\s+[`"](\w+)[`"]/i', $hit[2], $ch, PREG_SET_ORDER)) {
                    foreach ($ch as $c) { $tables[$t][strtolower($c[2])] = true; }
                }
            }
        }
    }

    return array_map('array_keys', $tables);
}

/**
 * Every PHP source file, relative to $root.
 *
 * Prefers `git ls-files` so generated and ignored files stay out of scope, and
 * falls back to walking the tree when this is not a checkout - a deployed copy
 * has no .git, and the check is just as valid there.
 */
function tracked_php(string $root): array
{
    $out = shell_exec('git -C ' . escapeshellarg($root) . ' ls-files "*.php" 2>/dev/null');
    $files = array_values(array_filter(array_map('trim', explode("\n", (string) $out))));
    if ($files) {
        return $files;
    }

    // Not a git checkout. Walk instead, skipping third-party and captured data.
    $skip = ['/vendor/', '/node_modules/', '/backups/', '/.archive/', '/packages/'];
    $found = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $rel = substr($file->getPathname(), strlen($root) + 1);
        foreach ($skip as $frag) {
            if (str_contains('/' . $rel, $frag)) { continue 2; }
        }
        $found[] = $rel;
    }
    sort($found);
    return $found;
}

$root    = rtrim(TEST_ROOT, '/');
$schema  = schema_columns($root);
$files   = tracked_php($root);
$reserved = ['null', 'now', 'current_timestamp', 'default', 'values'];

T::group('Schema is readable');
T::ok(count($schema) > 50, 'schema defines a plausible number of tables (' . count($schema) . ')');
T::ok(count($files) > 100, 'git lists the PHP sources (' . count($files) . ' files)');
T::ok(in_array('body', $schema['request_queue'] ?? [], true), 'request_queue.body is in the schema');
T::ok(in_array('totp_secret', $schema['users'] ?? [], true), 'users.totp_secret is in the schema');

T::group('Every written column exists');

$problems = [];
foreach ($files as $rel) {
    $src = (string) file_get_contents($root . '/' . $rel);

    // INSERT INTO <table> (a, b, c)
    if (preg_match_all('/INSERT\s+(?:IGNORE\s+)?INTO\s+[`"]?(\w+)[`"]?\s*\(([^)]*)\)/is', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $t = strtolower($hit[1]);
            if (!isset($schema[$t])) { continue; }          // table not modelled here
            if (str_contains($hit[2], '$') || str_contains($hit[2], '{')) { continue; } // dynamic
            foreach (explode(',', $hit[2]) as $col) {
                $col = strtolower(trim(trim($col), "`\" \t\n"));
                if ($col === '' || !preg_match('/^\w+$/', $col) || in_array($col, $reserved, true)) { continue; }
                if (!in_array($col, $schema[$t], true)) {
                    $problems[] = "INSERT {$rel}: {$t}.{$col}";
                }
            }
        }
    }

    // UPDATE <table> SET a = ?, b = ?
    if (preg_match_all('/UPDATE\s+[`"]?(\w+)[`"]?\s+SET\s+(.*?)(?:\bWHERE\b|["\';])/is', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $t = strtolower($hit[1]);
            if (!isset($schema[$t]) || strlen($hit[2]) > 2000) { continue; }
            if (preg_match_all('/(?:^|,)\s*[`"]?(\w+)[`"]?\s*=/', $hit[2], $cols)) {
                foreach ($cols[1] as $col) {
                    $col = strtolower($col);
                    if (in_array($col, $reserved, true)) { continue; }
                    if (!in_array($col, $schema[$t], true)) {
                        $problems[] = "UPDATE {$rel}: {$t}.{$col}";
                    }
                }
            }
        }
    }
}

$problems = array_values(array_unique($problems));
foreach ($problems as $p) {
    fwrite(STDERR, "  offending statement: {$p}\n");
}
T::eq(0, count($problems), 'no INSERT or UPDATE names a column the schema lacks');

T::group('Names that caused past outages stay gone');

$gone = [
    'recipient_email'     => 'alert history writer',
    'recipient_emails'    => 'alert history reader',
    'sent_successfully'   => 'alert history reader',
    'request_body'        => 'proxy request queue writer',
    'two_factor_secret'   => 'profile 2FA badge',
];
foreach ($gone as $name => $where) {
    $hits = [];
    foreach ($files as $rel) {
        // The tests name these on purpose - that is the point of the guard - and
        // inc/version.php carries the in-app changelog, where a release note
        // legitimately says which column name caused which outage.
        if (str_starts_with($rel, 'tests/') || $rel === 'inc/version.php') { continue; }

        $src = (string) file_get_contents($root . '/' . $rel);

        // Ignore the explanatory comments that name these deliberately. The two
        // patterns need different flags: a line comment must stop at the
        // newline, so it must not run under /s, or it swallows the whole file
        // and the check silently passes for every input.
        $src = (string) preg_replace(['~//[^\n]*~', '~/\*.*?\*/~s'], '', $src);

        // A PHP variable that happens to share the name is not a column
        // reference: api/request_queue.php legitimately holds the payload in
        // $request_body while writing it to the `body` column.
        if (preg_match('/(?<!\$)\b' . preg_quote($name, '/') . '\b/', $src)) {
            $hits[] = $rel;
        }
    }
    T::eq([], $hits, "`{$name}` is not used as a column name again ({$where})");
}

exit(T::summary());
