#!/usr/bin/env php
<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);
/**
 * Create the first OPNManager administrator account.
 *
 * Run once after importing database/schema.sql and configuring .env:
 *
 *     php scripts/create_admin.php
 *
 * Credentials may also be supplied non-interactively:
 *
 *     php scripts/create_admin.php <username> <email> <password>
 *
 * @since 3.11.5
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../config.php';

// Install-time master key (3.80.1). Creating the first admin is the one step
// every install runs, so it generates the key when it is missing. The script
// appends only and never replaces an existing key.
//
// Since 3.80.3 it stops if the result is not a usable key (generation failed,
// the existing key is malformed, or sodium is missing): OPNManager refuses to
// store secrets without one, so an administrator created now could not enrol
// in the two-factor authentication their account requires.
if (trim((string)(getenv('OPNMGR_MASTER_KEY') ?: ($_ENV['OPNMGR_MASTER_KEY'] ?? ''))) === '') {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/generate_master_key.php'), $keyRc);
    if ($keyRc !== 0) {
        fwrite(STDERR, "ERROR: could not generate OPNMGR_MASTER_KEY. Fix the problem above and run scripts/generate_master_key.php, then re-run this script.\n");
        exit(1);
    }
}
// Re-read .env in a fresh process: a key appended just now is not in this
// process's environment.
$keyCheck = 'require ' . var_export(__DIR__ . '/../config.php', true) . '; require ' . var_export(__DIR__ . '/../inc/crypto.php', true) . ';'
          . ' $s = opnmgr_crypto_status(); if (!$s["ok"]) { fwrite(STDERR, $s["message"] . "\n"); exit(1); }';
passthru(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($keyCheck), $keyRc);
if ($keyRc !== 0) {
    fwrite(STDERR, "ERROR: OPNMGR_MASTER_KEY is not usable (see above). No administrator was created.\n");
    exit(1);
}

/**
 * Read a line from stdin, optionally hiding the typed characters.
 */
function prompt(string $label, bool $hidden = false): string
{
    fwrite(STDOUT, $label);

    if ($hidden && stripos(PHP_OS_FAMILY, 'Windows') === false) {
        // Turn off terminal echo so the password is not shown while typing.
        shell_exec('stty -echo');
        $value = rtrim((string) fgets(STDIN), "\r\n");
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
        return $value;
    }

    return rtrim((string) fgets(STDIN), "\r\n");
}

try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
    $db = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    exit("Database connection failed: {$e->getMessage()}\n"
        . "Check DB_HOST, DB_NAME, DB_USER and DB_PASS in your .env file.\n");
}

$username = $argv[1] ?? '';
$email    = $argv[2] ?? '';
$password = $argv[3] ?? '';
$interactive = ($username === '' || $password === '');

if ($interactive) {
    fwrite(STDOUT, "OPNManager - create administrator account\n\n");
    $username = prompt('Username: ');
    $email    = prompt('Email:    ');
    $password = prompt('Password: ', true);
    $confirm  = prompt('Confirm:  ', true);

    if ($password !== $confirm) {
        exit("Passwords do not match.\n");
    }
}

if ($username === '' || $password === '') {
    exit("Usage: php scripts/create_admin.php [<username> <email> <password>]\n");
}

if (strlen($password) < 12) {
    exit("Password must be at least 12 characters.\n");
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("'{$email}' is not a valid email address.\n");
}

$stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
$stmt->execute([$username]);
if ($stmt->fetch()) {
    exit("A user named '{$username}' already exists.\n");
}

$stmt = $db->prepare(
    "INSERT INTO users (username, password, email, role, created_at)
     VALUES (?, ?, ?, 'admin', NOW())"
);
$stmt->execute([
    $username,
    password_hash($password, PASSWORD_DEFAULT),
    $email !== '' ? $email : null,
]);

fwrite(STDOUT, "Created administrator '{$username}' (id " . $db->lastInsertId() . ").\n");
fwrite(STDOUT, "You can now sign in at your OPNManager URL.\n");
