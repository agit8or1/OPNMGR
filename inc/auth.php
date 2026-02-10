<?php
/**
 * OPNManager Authentication Functions
 *
 * When loaded via bootstrap.php the session is already started, db() is
 * available, and config constants are defined.  When loaded standalone
 * (legacy path) this file bootstraps the minimum it needs so that existing
 * pages continue to work without changes.
 *
 * @since 1.0.0
 */

// ── Legacy / standalone mode ───────────────────────────────────────────────
// If bootstrap has NOT been loaded, set up session + DB ourselves so that
// pages still doing `require_once 'inc/auth.php'` keep working.
if (!defined('OPNMGR_BOOTSTRAPPED')) {
    // Session security
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', 1);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_samesite', 'Lax');
        session_start();
    }

    // Regenerate session ID once per session
    if (!isset($_SESSION['initiated'])) {
        session_regenerate_id(true);
        $_SESSION['initiated'] = true;
    }

    // Load config if constants are not yet defined
    if (!defined('DB_HOST')) {
        require_once __DIR__ . '/../config.php';
    }

    // Provide db() if not already defined (e.g. bootstrap not loaded)
    if (!function_exists('db')) {
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
}

// ── Auth helpers ────────────────────────────────────────────────────────────

function isLoggedIn() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    // Enforce session timeout
    $timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        $_SESSION = array();
        session_destroy();
        return false;
    }

    // Update last activity
    $_SESSION['last_activity'] = time();

    return true;
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function requireLogin() {
    if (!isLoggedIn()) {
        // Return JSON 401 for API requests, redirect for page requests
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false ||
            (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Not authenticated']);
            exit;
        }
        header('Location: /login.php');
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: /dashboard.php');
        exit;
    }
}

function getUserById($userId) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

function login($username, $password) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);

        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];

        return true;
    }
    return false;
}

function logout() {
    // Clear all session variables
    $_SESSION = array();

    // Delete the session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }

    // Destroy the session
    session_destroy();

    header('Location: /login.php');
    exit;
}
