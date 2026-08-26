<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);
/**
 * Encrypt stored secrets in place.
 *
 * Walks the known credential columns and encrypts any value still held as
 * plaintext. Values already carrying the enc:v1: envelope are skipped, so this
 * is idempotent and safe to run on every upgrade.
 *
 * Requires OPNMGR_MASTER_KEY in .env (scripts/generate_master_key.php). Without
 * it the script refuses to run rather than writing values it could not read
 * back.
 *
 * Usage:
 *   php scripts/encrypt_secrets.php [--dry-run]
 *
 * @since 3.12.0
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("encrypt_secrets.php may only be run from the command line\n");
}

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/secrets.php';

$dryRun = in_array('--dry-run', $argv, true);

if (!opnmgr_crypto_available()) {
    fwrite(STDERR,
        "OPNMGR_MASTER_KEY is not configured (or libsodium is missing).\n" .
        "Run: php scripts/generate_master_key.php\n");
    exit(1);
}

$pdo = db();
$total = 0;

/**
 * Encrypt a single column across a table, skipping already-encrypted rows.
 */
function encrypt_column(PDO $pdo, string $table, string $idCol, string $col, bool $dryRun): int {
    $rows = $pdo->query("SELECT `{$idCol}` AS id, `{$col}` AS v FROM `{$table}`
                          WHERE `{$col}` IS NOT NULL AND `{$col}` <> ''")
                ->fetchAll(PDO::FETCH_ASSOC);

    $n = 0;
    foreach ($rows as $row) {
        if (opnmgr_is_encrypted($row['v'])) {
            continue;
        }
        $n++;
        if ($dryRun) {
            printf("  would encrypt %s.%s id=%s (%d bytes)\n", $table, $col, $row['id'], strlen($row['v']));
            continue;
        }
        $stmt = $pdo->prepare("UPDATE `{$table}` SET `{$col}` = ? WHERE `{$idCol}` = ?");
        $stmt->execute([opnmgr_encrypt($row['v']), $row['id']]);
        printf("  encrypted %s.%s id=%s\n", $table, $col, $row['id']);
    }
    return $n;
}

echo "Encrypting stored secrets" . ($dryRun ? " [dry run]" : "") . "\n\n";

// --- firewalls -------------------------------------------------------------
foreach (['ssh_private_key', 'agent_api_key', 'agent_api_secret', 'api_secret'] as $col) {
    try {
        $total += encrypt_column($pdo, 'firewalls', 'id', $col, $dryRun);
    } catch (Throwable $e) {
        fwrite(STDERR, "  skipped firewalls.{$col}: " . $e->getMessage() . "\n");
    }
}

// --- AI provider keys ------------------------------------------------------
try {
    $total += encrypt_column($pdo, 'ai_settings', 'id', 'api_key', $dryRun);
} catch (Throwable $e) {
    fwrite(STDERR, "  skipped ai_settings.api_key: " . $e->getMessage() . "\n");
}

// --- settings (named credential keys only) ---------------------------------
$names = opnmgr_secret_settings();
$in    = implode(',', array_fill(0, count($names), '?'));
$stmt  = $pdo->prepare("SELECT `name`, `value` FROM settings WHERE `name` IN ({$in}) AND `value` <> ''");
$stmt->execute($names);

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (opnmgr_is_encrypted($row['value'])) {
        continue;
    }
    $total++;
    if ($dryRun) {
        printf("  would encrypt settings.%s\n", $row['name']);
        continue;
    }
    $pdo->prepare('UPDATE settings SET `value` = ? WHERE `name` = ?')
        ->execute([opnmgr_encrypt($row['value']), $row['name']]);
    printf("  encrypted settings.%s\n", $row['name']);
}

// --- user MFA recovery codes ------------------------------------------------
try {
    $total += encrypt_column($pdo, 'users', 'id', 'recovery_codes', $dryRun);
} catch (Throwable $e) {
    fwrite(STDERR, "  skipped users.recovery_codes: " . $e->getMessage() . "\n");
}
try {
    $total += encrypt_column($pdo, 'users', 'id', 'totp_secret', $dryRun);
} catch (Throwable $e) {
    fwrite(STDERR, "  skipped users.totp_secret: " . $e->getMessage() . "\n");
}

printf("\n%s%d value(s) %s.\n",
    $dryRun ? '[dry run] ' : '',
    $total,
    $dryRun ? 'would be encrypted' : 'encrypted');

if (!$dryRun && $total > 0) {
    echo "\nBack up OPNMGR_MASTER_KEY from .env. These values cannot be recovered without it.\n";
}
exit(0);
