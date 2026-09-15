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

/**
 * Session timeout settings, administrator-configurable.
 *
 * Falls back to the compiled defaults when the settings table is unavailable,
 * so a database hiccup cannot accidentally grant unlimited sessions.
 *
 * @return array{idle:int, absolute:int}
 */
function sessionTimeouts(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $idle     = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600;
    $absolute = 43200; // 12 hours

    try {
        $stmt = db()->query(
            "SELECT `name`,`value` FROM settings
              WHERE `name` IN ('session_idle_timeout','session_absolute_timeout')"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        if (!empty($rows['session_idle_timeout']) && (int)$rows['session_idle_timeout'] >= 60) {
            $idle = (int)$rows['session_idle_timeout'];
        }
        if (!empty($rows['session_absolute_timeout']) && (int)$rows['session_absolute_timeout'] >= 300) {
            $absolute = (int)$rows['session_absolute_timeout'];
        }
    } catch (Throwable $e) {
        // keep the defaults
    }

    $cache = ['idle' => $idle, 'absolute' => $absolute];
    return $cache;
}

/**
 * Tear down the current session completely.
 */
function destroySession(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies') && isset($_COOKIE[session_name()])) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function isLoggedIn() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    $timeouts = sessionTimeouts();
    $now      = time();

    // Idle timeout
    if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > $timeouts['idle']) {
        destroySession();
        return false;
    }

    // Absolute timeout: a session cannot be kept alive indefinitely by activity.
    $started = $_SESSION['login_time'] ?? $_SESSION['created_at'] ?? $now;
    if (($now - $started) > $timeouts['absolute']) {
        destroySession();
        return false;
    }

    // Bind the session to the user agent it was issued to. The values were
    // already being recorded at login but never checked, so a stolen cookie was
    // usable from anywhere. Deliberately not binding to IP: mobile and
    // multi-WAN clients legitimately change address mid-session.
    if (isset($_SESSION['user_agent'])
        && !hash_equals($_SESSION['user_agent'], (string)($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
        error_log('SECURITY: session user-agent mismatch for user_id=' . $_SESSION['user_id'] . ' - session destroyed');
        destroySession();
        return false;
    }

    // Deactivating an account has to end the sessions it already has, or
    // "disable this user" means "disable them at their next login", which is
    // not what an operator reaching for it needs. Re-checked on a short
    // interval rather than every request: one query a minute per active
    // session, bounding both the cost and how long a disabled account keeps
    // working.
    $activeChecked = $_SESSION['active_checked_at'] ?? 0;
    if (($now - $activeChecked) > 60) {
        try {
            $stmt = db()->prepare('SELECT is_active FROM users WHERE id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // A deleted account ends the session too.
            if ($row === false || (array_key_exists('is_active', $row) && (int) $row['is_active'] === 0)) {
                error_log('SECURITY: session for deactivated or removed user_id='
                    . $_SESSION['user_id'] . ' - session destroyed');
                destroySession();
                return false;
            }
            $_SESSION['active_checked_at'] = $now;
        } catch (Throwable $e) {
            // A database blip must not log everyone out; the check retries on
            // the next request.
            error_log('OPNMGR: could not verify account status: ' . $e->getMessage());
        }
    }

    // Periodically roll the session id so a long-lived session does not keep
    // the same identifier for its whole life.
    if (!isset($_SESSION['last_regenerated']) || ($now - $_SESSION['last_regenerated']) > 900) {
        session_regenerate_id(true);
        $_SESSION['last_regenerated'] = $now;
    }

    $_SESSION['last_activity'] = $now;

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

    // users.is_active has existed in the schema without a single line reading
    // it. Setting it to 0 looked like disabling an account and did nothing at
    // all - the account kept logging in. It is enforced here, so the column
    // means what an operator would assume it means.
    //
    // Checked after password verification so a wrong password and a disabled
    // account are indistinguishable from outside.
    if ($user && password_verify($password, $user['password'])
        && array_key_exists('is_active', $user) && (int) $user['is_active'] === 0) {

        if (function_exists('audit_log')) {
            audit_log('auth.login', [
                'success'     => false,
                'object_type' => 'user',
                'object_id'   => (string) $user['id'],
                'username'    => $username,
                'message'     => 'Login refused: account is deactivated',
            ]);
        }
        return false;
    }

    if ($user && password_verify($password, $user['password'])) {
        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);

        // A password alone used to be a full session even for an account with
        // two-factor enrolled: nothing here read totp_secret, nothing sent the
        // browser to verify2fa.php, and that page was unreachable from the
        // login flow. So an account showing "2FA enabled" on its profile was
        // protected by a password and nothing else - the badge asserted a
        // control that did not exist.
        //
        // The second factor is now held here. No user_id is set, so
        // isLoggedIn() is false and every requireLogin() page refuses, until
        // verify2fa.php confirms the code and promotes the session.
        if (!empty($user['totp_secret'])) {
            $_SESSION['pending_2fa_user_id'] = $user['id'];
            $_SESSION['pending_2fa_started'] = time();
            $_SESSION['pending_2fa_ip']      = $_SERVER['REMOTE_ADDR'] ?? '';

            if (function_exists('audit_log')) {
                audit_log('auth.login.2fa_required', [
                    'success'     => true,
                    'object_type' => 'user',
                    'object_id'   => (string) $user['id'],
                    'message'     => 'Password accepted; awaiting second factor',
                ]);
            }

            return '2fa';
        }

        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['login_time'] = time();
        $_SESSION['created_at'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['last_regenerated'] = time();
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['user_agent'] = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

        // Transparently upgrade weaker legacy hashes on successful login.
        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            try {
                $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            } catch (Throwable $e) {
                error_log('OPNMGR: password rehash failed for user ' . $user['id'] . ': ' . $e->getMessage());
            }
        }

        try {
            db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
        } catch (Throwable $e) {
            // non-fatal
        }

        if (function_exists('audit_log')) {
            audit_log('auth.login', [
                'success'     => true,
                'object_type' => 'user',
                'object_id'   => (string)$user['id'],
                'message'     => 'Successful login',
            ]);
        }

        return true;
    }

    if (function_exists('audit_log')) {
        audit_log('auth.login', [
            'success'  => false,
            'username' => $username,
            'user_id'  => null,
            'actor_type' => 'anonymous',
            'message'  => 'Failed login attempt',
        ]);
    }

    return false;
}

function logout() {
    if (function_exists('audit_log') && isset($_SESSION['user_id'])) {
        audit_log('auth.logout', [
            'success'     => true,
            'object_type' => 'user',
            'object_id'   => (string)$_SESSION['user_id'],
            'message'     => 'User logged out',
        ]);
    }

    destroySession();

    header('Location: /login.php');
    exit;
}
