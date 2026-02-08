<?php
/**
 * Environment Variable Loader
 * Loads configuration from .env file
 */

if (!function_exists('load_env')) {
    function load_env($file = __DIR__ . '/../.env') {
        if (!file_exists($file)) {
            // Check for .env in parent directory
            $file = dirname(__DIR__) . '/.env';
            if (!file_exists($file)) {
                error_log("WARNING: .env file not found. Using defaults or environment variables.");
                return false;
            }
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Skip comments
            if (str starts_with(trim($line), '#')) {
                continue;
            }

            // Parse KEY=VALUE
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remove quotes if present
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }

                // Only set if not already set in environment
                if (!isset($_ENV[$key])) {
                    $_ENV[$key] = $value;
                    putenv("$key=$value");
                }
            }
        }
        return true;
    }
}

if (!function_exists('env')) {
    /**
     * Get environment variable with optional default
     */
    function env($key, $default = null) {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false) {
            return $default;
        }

        // Convert string booleans
        if (strtolower($value) === 'true') return true;
        if (strtolower($value) === 'false') return false;
        if (strtolower($value) === 'null') return null;

        return $value;
    }
}

// Auto-load .env file when this file is included
load_env();
