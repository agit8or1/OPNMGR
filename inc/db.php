<?php
/**
 * OPNManager Database Connection (Legacy Compatibility Layer)
 *
 * New code should use bootstrap.php and the db() function instead.
 *
 * If loaded after bootstrap.php (or auth.php with db()), this file simply
 * sets the legacy $DB global to the shared singleton so that existing code
 * using `global $DB; $DB->prepare(...)` keeps working unchanged.
 *
 * If loaded standalone (no bootstrap), it creates the connection itself.
 *
 * @deprecated Use db() from bootstrap.php instead of global $DB
 * @since 1.0.0
 */

if (function_exists('db')) {
    // Bootstrap (or auth.php standalone) already provides db() -- reuse it.
    $DB = db();
} else {
    // Standalone legacy include -- set up the connection directly.
    require_once __DIR__ . '/../config.php';

    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
        $DB = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        $DB = null;
    }
}
