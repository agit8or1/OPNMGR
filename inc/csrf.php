<?php
// CSRF Protection Functions

/**
 * Generate a CSRF token
 */
function csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a CSRF token
 */
function csrf_verify($token) {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Regenerate CSRF token (call after successful form submission)
 */
function csrf_regenerate() {
    unset($_SESSION['csrf_token']);
    return csrf_token();
}
?>
