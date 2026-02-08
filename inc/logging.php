<?php
require_once __DIR__ . '/db.php';

/**
 * Log a system event
 * 
 * @param string $level Log level (INFO, WARNING, ERROR, DEBUG)
 * @param string $category Category of the log (auth, firewall, system, etc.)
 * @param string $message Log message
 * @param int|null $user_id User ID if applicable
 * @param int|null $firewall_id Firewall ID if applicable
 * @param array|null $additional_data Additional data to store as JSON
 * @param string|null $ip_address IP address, auto-detected if not provided
 */
function log_event($level, $category, $message, $user_id = null, $firewall_id = null, $additional_data = null, $ip_address = null) {
    global $DB;
    
    if (!$DB) {
        error_log("Cannot log event - database connection not available: $message");
        return false;
    }
    
    // Auto-detect IP address if not provided
    if ($ip_address === null) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? 'unknown';
    }
    
    try {
        $stmt = $DB->prepare("
            INSERT INTO system_logs (level, category, message, user_id, firewall_id, additional_data, ip_address) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $json_data = $additional_data ? json_encode($additional_data) : null;
        
        $stmt->execute([
            $level,
            $category,
            $message,
            $user_id,
            $firewall_id,
            $json_data,
            $ip_address
        ]);
        
        return $DB->lastInsertId();
    } catch (Exception $e) {
        error_log("Failed to log event: " . $e->getMessage());
        return false;
    }
}

/**
 * Convenience functions for different log levels
 */
function log_info($category, $message, $user_id = null, $firewall_id = null, $additional_data = null) {
    return log_event('INFO', $category, $message, $user_id, $firewall_id, $additional_data);
}

function log_warning($category, $message, $user_id = null, $firewall_id = null, $additional_data = null) {
    return log_event('WARNING', $category, $message, $user_id, $firewall_id, $additional_data);
}

function log_error($category, $message, $user_id = null, $firewall_id = null, $additional_data = null) {
    return log_event('ERROR', $category, $message, $user_id, $firewall_id, $additional_data);
}

function log_debug($category, $message, $user_id = null, $firewall_id = null, $additional_data = null) {
    return log_event('DEBUG', $category, $message, $user_id, $firewall_id, $additional_data);
}

/**
 * Get system logs with filtering
 * 
 * @param array $filters Available filters: level, category, firewall_id, user_id, start_date, end_date
 * @param int $limit Number of records to return
 * @param int $offset Offset for pagination
 * @return array Array of log records
 */
function get_logs($filters = [], $limit = 100, $offset = 0) {
    global $DB;
    
    if (!$DB) {
        return [];
    }
    
    $where_clauses = [];
    $params = [];
    
    if (!empty($filters['level'])) {
        $where_clauses[] = "level = ?";
        $params[] = $filters['level'];
    }
    
    if (!empty($filters['category'])) {
        $where_clauses[] = "category = ?";
        $params[] = $filters['category'];
    }
    
    if (!empty($filters['firewall_id'])) {
        $where_clauses[] = "firewall_id = ?";
        $params[] = $filters['firewall_id'];
    }
    
    if (!empty($filters['user_id'])) {
        $where_clauses[] = "user_id = ?";
        $params[] = $filters['user_id'];
    }
    
    if (!empty($filters['start_date'])) {
        $where_clauses[] = "timestamp >= ?";
        $params[] = $filters['start_date'];
    }
    
    if (!empty($filters['end_date'])) {
        $where_clauses[] = "timestamp <= ?";
        $params[] = $filters['end_date'];
    }
    
    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    
    try {
        $sql = "
            SELECT sl.*, f.hostname as firewall_hostname 
            FROM system_logs sl 
            LEFT JOIN firewalls f ON sl.firewall_id = f.id 
            $where_sql 
            ORDER BY sl.timestamp DESC 
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        
        $stmt = $DB->prepare($sql);
		$stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Failed to retrieve logs: " . $e->getMessage());
        return [];
    }
}

/**
 * Clean up old logs (older than specified days)
 * 
 * @param int $days Number of days to keep logs
 * @return int Number of deleted records
 */
function cleanup_old_logs($days = 30) {
    global $DB;
    
    if (!$DB) {
        return 0;
    }
    
    try {
        $stmt = $DB->prepare("DELETE FROM system_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$days]);
        
        $deleted_count = $stmt->rowCount();
        
        if ($deleted_count > 0) {
            log_info('system', "Cleaned up $deleted_count old log records (older than $days days)");
        }
        
        return $deleted_count;
    } catch (Exception $e) {
        error_log("Failed to cleanup old logs: " . $e->getMessage());
        return 0;
    }
}
?>