<?php
/**
 * A migration must survive being run on a schema that already has its change.
 *
 * Run with: php tests/migration_idempotence_test.php
 *
 * database/schema.sql carries every table and column as they stand, and ships
 * with an empty schema_migrations table. A fresh install therefore loads the
 * schema and then runs every migration against it, so a migration that is not
 * idempotent fails the install.
 *
 * 0022_ai_scan_token_usage.sql was written as a plain ALTER TABLE ... ADD COLUMN
 * and broke two CI jobs with "Duplicate column name 'prompt_tokens'" - both the
 * clean-install path and the upgrade path run migrations, so both went red.
 * Every other ALTER in the directory already used IF NOT EXISTS; this one was
 * the exception.
 */

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) { $passed++; return; }
    $failed++;
    echo "FAIL: {$what}\n";
    if ($detail !== '') { echo "      {$detail}\n"; }
}

$root = dirname(__DIR__);
$dir  = $root . '/database/migrations';
$files = glob($dir . '/*.sql') ?: [];

check('migrations are present', count($files) > 0);

foreach ($files as $file) {
    $name = basename($file);
    $sql  = (string) @file_get_contents($file);

    // Strip comments: a migration explaining why it uses IF NOT EXISTS must not
    // satisfy the check by talking about it.
    $code = implode("\n", array_filter(explode("\n", $sql), static function (string $l): bool {
        $t = ltrim($l);
        return $t !== '' && !str_starts_with($t, '--') && !str_starts_with($t, '#');
    }));

    if (preg_match_all('/\bADD\s+COLUMN\s+(?!IF\s+NOT\s+EXISTS)`?([a-z_]+)`?/i', $code, $m)) {
        check("{$name}: every ADD COLUMN is guarded", false,
            'unguarded: ' . implode(', ', $m[1]) . ' - a fresh install runs this against a schema that already has it');
    } else {
        check("{$name}: every ADD COLUMN is guarded", true);
    }

    if (preg_match_all('/\bADD\s+(?:INDEX|KEY)\s+(?!IF\s+NOT\s+EXISTS)`?([a-z_]+)`?/i', $code, $m)) {
        check("{$name}: every ADD INDEX is guarded", false,
            'unguarded: ' . implode(', ', $m[1]));
    } else {
        check("{$name}: every ADD INDEX is guarded", true);
    }

    if (preg_match('/\bCREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', $code)) {
        check("{$name}: CREATE TABLE is guarded", false,
            'CREATE TABLE without IF NOT EXISTS fails on a schema that already has the table');
    } else {
        check("{$name}: CREATE TABLE is guarded", true);
    }

    if (preg_match('/\bDROP\s+TABLE\s+(?!IF\s+EXISTS)/i', $code)) {
        check("{$name}: DROP TABLE is guarded", false);
    } else {
        check("{$name}: DROP TABLE is guarded", true);
    }
}

// The specific regression.
$m22 = (string) @file_get_contents($dir . '/0022_ai_scan_token_usage.sql');
check('0022 guards its token columns',
    substr_count($m22, 'ADD COLUMN IF NOT EXISTS') === 3,
    'this is the migration that broke CI');
check('0022 records why it must be idempotent',
    str_contains($m22, 'ships with an empty schema_migrations table'),
    'the next person writing a migration should not have to rediscover it');

// And the schema those migrations run against must actually contain the change,
// which is what makes them no-ops on a fresh install.
$schema = (string) @file_get_contents($root . '/database/schema.sql');
foreach (['prompt_tokens', 'completion_tokens', 'total_tokens', 'alert_policies'] as $needle) {
    check("database/schema.sql carries {$needle}", str_contains($schema, $needle),
        'a fresh install would otherwise lack it until the migration ran');
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
