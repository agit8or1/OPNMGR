<?php
/**
 * OPNManager Bootstrap - Web Page Requests
 *
 * Single include for all web-facing pages. Provides:
 *   - BASE_PATH constant
 *   - Config (DB constants, session timeout, etc.)
 *   - Version constants
 *   - Lazy db() singleton
 *   - Secure session handling
 *   - Auth functions (requireLogin, requireAdmin, etc.)
 *   - CSRF helpers
 *   - JSON response helpers
 *
 * Usage (root-level page):
 *   require_once __DIR__ . '/inc/bootstrap.php';
 *
 * Usage (api/ subdirectory page):
 *   require_once __DIR__ . '/../inc/bootstrap.php';
 *
 * @since 2.3.0
 */

// Prevent double-include
if (defined('OPNMGR_BOOTSTRAPPED')) {
    return;
}
define('OPNMGR_BOOTSTRAPPED', true);

// ---------------------------------------------------------------------------
// 1. Base path
// ---------------------------------------------------------------------------
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// ---------------------------------------------------------------------------
// 2. Configuration (DB constants, session timeout, security settings, etc.)
// ---------------------------------------------------------------------------
require_once BASE_PATH . '/config.php';

// ---------------------------------------------------------------------------
// 3. Version constants
// ---------------------------------------------------------------------------
require_once __DIR__ . '/version.php';

// ---------------------------------------------------------------------------
// 4. Lazy database connection singleton
// ---------------------------------------------------------------------------
if (!function_exists('db')) {
    /**
     * Return a shared PDO connection, created on first call.
     *
     * @return PDO
     * @throws PDOException on connection failure (caught and logged)
     */
    function db(): PDO {
        static $pdo = null;
        if ($pdo === null) {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return $pdo;
    }
}

// Legacy compat: expose $DB global so files using `global $DB; $DB->...` keep working
$DB = db();

// ---------------------------------------------------------------------------
// 5. Secure session handling (only if not already started)
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// Regenerate session ID once per session to prevent fixation
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

// ---------------------------------------------------------------------------
// 6. Auth functions (requireLogin, requireAdmin, isLoggedIn, login, etc.)
// ---------------------------------------------------------------------------
require_once __DIR__ . '/auth.php';

// ---------------------------------------------------------------------------
// 7. CSRF helpers
// ---------------------------------------------------------------------------
require_once __DIR__ . '/csrf.php';

// ---------------------------------------------------------------------------
// 8. JSON response helpers
// ---------------------------------------------------------------------------
if (!function_exists('jsonResponse')) {
    /**
     * Send a JSON response with the given HTTP status code and exit.
     *
     * @param mixed $data   Data to encode as JSON
     * @param int   $status HTTP status code (default 200)
     */
    function jsonResponse($data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Send a JSON success response and exit.
     *
     * @param mixed       $data    Payload (placed under "data" key)
     * @param string|null $message Optional message
     */
    function jsonSuccess($data = null, ?string $message = null): void {
        $response = ['success' => true];
        if ($message !== null) {
            $response['message'] = $message;
        }
        if ($data !== null) {
            $response['data'] = $data;
        }
        jsonResponse($response, 200);
    }

    /**
     * Send a JSON error response and exit.
     *
     * @param string $message Error description
     * @param int    $status  HTTP status code (default 400)
     */
    function jsonError(string $message, int $status = 400): void {
        jsonResponse(['success' => false, 'message' => $message], $status);
    }
}
