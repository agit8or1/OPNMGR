<?php
/**
 * OPNManager Bootstrap - Agent Endpoints
 *
 * Lightweight bootstrap for agent-facing endpoints (agent_checkin.php, etc.).
 * No session, no auth -- agents authenticate via their own API key / token.
 *
 * Provides:
 *   - BASE_PATH constant
 *   - Config (DB constants)
 *   - Version constants
 *   - Lazy db() singleton
 *   - JSON response helpers
 *
 * Usage:
 *   require_once __DIR__ . '/inc/bootstrap_agent.php';
 *
 * @since 2.3.0
 */

// Prevent double-include (also compatible with the full bootstrap)
if (defined('OPNMGR_AGENT_BOOTSTRAPPED')) {
    return;
}
define('OPNMGR_AGENT_BOOTSTRAPPED', true);

// ---------------------------------------------------------------------------
// 1. Base path
// ---------------------------------------------------------------------------
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// ---------------------------------------------------------------------------
// 2. Configuration (DB constants, security settings, etc.)
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
// 5. JSON response helpers
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
