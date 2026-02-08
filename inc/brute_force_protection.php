<?php
/**
 * Brute Force Protection System
 * Tracks failed login attempts and implements account lockout
 */

require_once __DIR__ . '/../config.php';

class BruteForceProtection {
    private $pdo;
    private $max_attempts;
    private $lockout_time;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->max_attempts = defined('MAX_LOGIN_ATTEMPTS') ? MAX_LOGIN_ATTEMPTS : 5;
        $this->lockout_time = defined('LOGIN_LOCKOUT_TIME') ? LOGIN_LOCKOUT_TIME : 900; // 15 minutes
    }

    /**
     * Check if an IP or username is currently locked out
     */
    public function is_locked_out($username, $ip_address) {
        // Create table if it doesn't exist
        $this->create_table_if_not_exists();

        $stmt = $this->pdo->prepare("
            SELECT failed_attempts, last_failed, locked_until
            FROM login_attempts
            WHERE (username = ? OR ip_address = ?)
            AND locked_until > NOW()
            LIMIT 1
        ");
        $stmt->execute([$username, $ip_address]);
        $result = $stmt->fetch();

        if ($result) {
            $remaining_time = strtotime($result['locked_until']) - time();
            return [
                'locked' => true,
                'remaining_seconds' => $remaining_time,
                'remaining_minutes' => ceil($remaining_time / 60)
            ];
        }

        return ['locked' => false];
    }

    /**
     * Record a failed login attempt
     */
    public function record_failed_attempt($username, $ip_address) {
        $this->create_table_if_not_exists();

        // Check current attempts
        $stmt = $this->pdo->prepare("
            SELECT failed_attempts, last_failed
            FROM login_attempts
            WHERE username = ? OR ip_address = ?
            ORDER BY last_failed DESC
            LIMIT 1
        ");
        $stmt->execute([$username, $ip_address]);
        $current = $stmt->fetch();

        $attempts = 1;
        $locked_until = null;

        if ($current) {
            $attempts = $current['failed_attempts'] + 1;

            // Reset counter if last attempt was more than lockout time ago
            $time_since_last = time() - strtotime($current['last_failed']);
            if ($time_since_last > $this->lockout_time) {
                $attempts = 1;
            }
        }

        // Lock account if max attempts reached
        if ($attempts >= $this->max_attempts) {
            $locked_until = date('Y-m-d H:i:s', time() + $this->lockout_time);

            // Log security event
            error_log("SECURITY: Account lockout triggered for username: {$username}, IP: {$ip_address}, Attempts: {$attempts}");
        }

        // Insert or update attempt record
        $stmt = $this->pdo->prepare("
            INSERT INTO login_attempts (username, ip_address, failed_attempts, last_failed, locked_until)
            VALUES (?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
                failed_attempts = ?,
                last_failed = NOW(),
                locked_until = ?
        ");
        $stmt->execute([
            $username,
            $ip_address,
            $attempts,
            $locked_until,
            $attempts,
            $locked_until
        ]);

        return [
            'attempts' => $attempts,
            'locked' => ($attempts >= $this->max_attempts),
            'locked_until' => $locked_until
        ];
    }

    /**
     * Clear failed attempts after successful login
     */
    public function clear_attempts($username, $ip_address) {
        $this->create_table_if_not_exists();

        $stmt = $this->pdo->prepare("
            DELETE FROM login_attempts
            WHERE username = ? OR ip_address = ?
        ");
        $stmt->execute([$username, $ip_address]);
    }

    /**
     * Create the login_attempts table if it doesn't exist
     */
    private function create_table_if_not_exists() {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS login_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(255) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                failed_attempts INT DEFAULT 1,
                last_failed DATETIME NOT NULL,
                locked_until DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_attempt (username, ip_address),
                INDEX idx_locked_until (locked_until),
                INDEX idx_ip_address (ip_address),
                INDEX idx_username (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * Get statistics about failed login attempts
     */
    public function get_statistics() {
        $this->create_table_if_not_exists();

        $stats = [];

        // Total locked accounts
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as count
            FROM login_attempts
            WHERE locked_until > NOW()
        ");
        $stats['locked_accounts'] = $stmt->fetchColumn();

        // Total failed attempts in last 24 hours
        $stmt = $this->pdo->query("
            SELECT SUM(failed_attempts) as total
            FROM login_attempts
            WHERE last_failed > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stats['failed_attempts_24h'] = $stmt->fetchColumn() ?: 0;

        // Top attacked usernames
        $stmt = $this->pdo->query("
            SELECT username, SUM(failed_attempts) as total_attempts
            FROM login_attempts
            WHERE last_failed > DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY username
            ORDER BY total_attempts DESC
            LIMIT 10
        ");
        $stats['top_attacked_usernames'] = $stmt->fetchAll();

        // Top attacking IPs
        $stmt = $this->pdo->query("
            SELECT ip_address, SUM(failed_attempts) as total_attempts
            FROM login_attempts
            WHERE last_failed > DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY ip_address
            ORDER BY total_attempts DESC
            LIMIT 10
        ");
        $stats['top_attacking_ips'] = $stmt->fetchAll();

        return $stats;
    }

    /**
     * Cleanup old records (run this periodically)
     */
    public function cleanup_old_records($days = 30) {
        $this->create_table_if_not_exists();

        $stmt = $this->pdo->prepare("
            DELETE FROM login_attempts
            WHERE last_failed < DATE_SUB(NOW(), INTERVAL ? DAY)
            AND (locked_until IS NULL OR locked_until < NOW())
        ");
        $stmt->execute([$days]);

        return $stmt->rowCount();
    }
}
