#!/usr/bin/env php
<?php
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
