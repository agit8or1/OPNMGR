<?php
// Load environment variables from .env file
require_once __DIR__ . '/inc/env.php';

// Database configuration - MUST be set in .env file
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'opnsense_fw'));
define('DB_USER', env('DB_USER', 'opnsense_user'));
define('DB_PASS', env('DB_PASS'));

// Enforce that critical configuration is set
if (empty(DB_PASS)) {
    error_log('CRITICAL: DB_PASS not configured in .env file');
    die('Configuration error: Please set up your .env file with database credentials. Copy .env.example to .env and configure it.');
}

// Security configuration
define('SESSION_TIMEOUT', 3600); // 1 hour
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes

// Application configuration
define('APP_NAME', $_ENV['APP_NAME'] ?? 'OPNsense Manager');
define('APP_VERSION', '1.0.0');
define('UPLOAD_MAX_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['image/png', 'image/jpeg', 'image/svg+xml']);

// Email configuration (can be overridden by database settings)
define('SMTP_HOST', $_ENV['SMTP_HOST'] ?? '');
define('SMTP_PORT', $_ENV['SMTP_PORT'] ?? 587);
define('SMTP_USERNAME', $_ENV['SMTP_USERNAME'] ?? '');
define('SMTP_PASSWORD', $_ENV['SMTP_PASSWORD'] ?? '');
define('SMTP_ENCRYPTION', $_ENV['SMTP_ENCRYPTION'] ?? 'tls');
?>