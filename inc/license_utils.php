<?php
/**
 * License Management Utilities
 * Handles license key generation, validation, and management
 */

/**
 * Generate a secure license key
 * Format: LIC-XXXX-XXXX-XXXX-XXXX (random hexadecimal)
 */
function generateLicenseKey() {
    $random = bin2hex(random_bytes(16));
    return 'LIC-' . 
        strtoupper(substr($random, 0, 4)) . '-' .
        strtoupper(substr($random, 4, 4)) . '-' .
        strtoupper(substr($random, 8, 4)) . '-' .
        strtoupper(substr($random, 12, 4));
}

/**
 * Generate API key and secret for license authentication
 */
function generateAPICredentials() {
    return [
        'key' => 'API_' . bin2hex(random_bytes(16)),
        'secret' => bin2hex(random_bytes(32))
    ];
}

/**
 * Validate license key format
 */
function isValidLicenseKey($key) {
    return preg_match('/^LIC-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}$/', $key);
}

/**
 * Check license status
 */
function checkLicenseStatus($instanceId, $DB) {
    $stmt = $DB->prepare('
        SELECT 
            id, status, license_expires, max_firewalls, current_firewalls,
            CASE 
                WHEN status = "expired" THEN "expired"
                WHEN license_expires < NOW() THEN "expired"
                WHEN status = "suspended" THEN "suspended"
                ELSE "active"
            END as current_status
        FROM deployed_instances 
        WHERE id = ?
    ');
    $stmt->execute([$instanceId]);
    return $stmt->fetch();
}

/**
 * Record license check-in
 */
function recordLicenseCheckIn($instanceId, $instanceKey, $firewallCount, $status, $DB) {
    $stmt = $DB->prepare('
        INSERT INTO license_checkins 
        (instance_id, instance_key, firewall_count, status, ip_address, user_agent, checkin_time)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ');
    
    $stmt->execute([
        $instanceId,
        $instanceKey,
        $firewallCount,
        $status,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);
    
    // Update last check-in timestamp
    $update = $DB->prepare('UPDATE deployed_instances SET last_checkin = NOW() WHERE id = ?');
    $update->execute([$instanceId]);
    
    return true;
}

/**
 * Log license activity
 */
function logLicenseActivity($instanceId, $action, $actionType, $details, $userId, $DB) {
    $stmt = $DB->prepare('
        INSERT INTO license_activity_log 
        (instance_id, action, action_type, details, user_id, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ');
    
    $stmt->execute([
        $instanceId,
        $action,
        $actionType,
        $details,
        $userId ?? null
    ]);
    
    return true;
}

/**
 * Get license statistics
 */
function getLicenseStats($DB) {
    $stmt = $DB->query('
        SELECT 
            COUNT(*) as total_instances,
            SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active_instances,
            SUM(CASE WHEN status = "trial" THEN 1 ELSE 0 END) as trial_instances,
            SUM(CASE WHEN status = "suspended" THEN 1 ELSE 0 END) as suspended_instances,
            SUM(CASE WHEN license_expires < NOW() THEN 1 ELSE 0 END) as expired_instances,
            SUM(max_firewalls) as total_firewall_capacity,
            SUM(current_firewalls) as total_firewalls_used
        FROM deployed_instances
    ');
    
    return $stmt->fetch();
}

/**
 * Export license key (for customer download)
 */
function exportLicenseKey($instanceId, $DB) {
    $stmt = $DB->prepare('
        SELECT 
            instance_name, instance_key, license_tier, max_firewalls,
            license_expires, status, created_at
        FROM deployed_instances
        WHERE id = ?
    ');
    $stmt->execute([$instanceId]);
    $license = $stmt->fetch();
    
    if (!$license) {
        return null;
    }
    
    // Get API credentials
    $apiStmt = $DB->prepare('
        SELECT api_key, api_secret FROM license_api_keys 
        WHERE instance_id = ? AND is_active = 1 
        LIMIT 1
    ');
    $apiStmt->execute([$instanceId]);
    $api = $apiStmt->fetch();
    
    return array_merge($license, ['api_credentials' => $api]);
}

/**
 * Initialize license tables
 */
function initializeLicenseTables($DB) {
    try {
        // Create tables
        $sql = file_get_contents(__DIR__ . '/../db/migrations/create_license_tables.sql');
        $statements = array_filter(array_map('trim', explode(';', $sql)), function($s) {
            return !empty($s) && !str_starts_with($s, '--');
        });
        
        foreach ($statements as $statement) {
            $DB->exec($statement . ';');
        }
        
        return ['success' => true, 'message' => 'License tables initialized successfully'];
    } catch (PDOException $e) {
        error_log('License table initialization error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Internal server error'];
    }
}
?>
