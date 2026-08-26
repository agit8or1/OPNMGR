<?php
/**
 * Generate the OPNMGR secret-encryption master key.
 *
 * Appends OPNMGR_MASTER_KEY to .env if it is not already present. The existing
 * .env is never rewritten or truncated - if the key is already set the script
 * reports that and exits successfully, so it is safe to run from an installer
 * or upgrade script unconditionally.
 *
 * Usage: php scripts/generate_master_key.php [--print]
 *
 * @since 3.12.0
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("generate_master_key.php may only be run from the command line\n");
}

$envFile = dirname(__DIR__) . '/.env';

if (!file_exists($envFile)) {
    fwrite(STDERR, "No .env file at {$envFile}. Copy .env.example to .env first.\n");
    exit(1);
}

$contents = file_get_contents($envFile);

if (preg_match('/^\s*OPNMGR_MASTER_KEY\s*=\s*(\S+)/m', $contents, $m)) {
    echo "OPNMGR_MASTER_KEY is already set in .env - leaving it unchanged.\n";
    echo "Rotating this key would make every already-encrypted secret unreadable.\n";
    exit(0);
}

$key = base64_encode(random_bytes(32));

// Append only - never rewrite the operator's .env.
$block = "\n# Secret encryption master key (OPNMGR >= 3.12.0)\n"
       . "# Encrypts firewall API keys/secrets, SSH private keys and integration\n"
       . "# credentials at rest. Back this up: losing it makes those secrets\n"
       . "# unrecoverable. Do NOT change it once secrets have been encrypted.\n"
       . "OPNMGR_MASTER_KEY={$key}\n";

if (file_put_contents($envFile, $block, FILE_APPEND | LOCK_EX) === false) {
    fwrite(STDERR, "Failed to write to {$envFile}\n");
    exit(1);
}

@chmod($envFile, 0640);

echo "Generated OPNMGR_MASTER_KEY and appended it to .env\n";
if (in_array('--print', $argv, true)) {
    echo "OPNMGR_MASTER_KEY={$key}\n";
}
echo "Back up this value; encrypted secrets cannot be recovered without it.\n";
exit(0);
