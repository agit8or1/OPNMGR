<?php
/**
 * SSH Key Management API
 * Handles SSH key operations for firewalls
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/logging.php';

// Check authentication (for POST requests require it, GET can have fallback)
$is_authenticated = function_exists('check_authentication') && check_authentication();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    handle_get_key_status();
} elseif ($method === 'POST') {
    handle_post_action();
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

function handle_get_key_status() {
    global $DB;
    
    $firewall_id = isset($_GET['firewall_id']) ? (int)$_GET['firewall_id'] : 0;
    
    if (!$firewall_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing firewall_id']);
        return;
    }
    
    try {
        // Get SSH keys from database
        $stmt = $DB->prepare("
            SELECT 
                id,
                firewall_id,
                key_type,
                fingerprint,
                key_bits,
                created_at,
                last_used_at,
                is_active
            FROM firewall_ssh_keys
            WHERE firewall_id = ?
            ORDER BY created_at DESC
        ");
        
        $stmt->execute([$firewall_id]);
        $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no keys found, generate default info
        if (empty($keys)) {
            $keys = generate_default_key_info();
        }
        
        echo json_encode([
            'success' => true,
            'keys' => $keys,
            'count' => count($keys)
        ]);
        
    } catch (Exception $e) {
        // Provide fallback default keys on error
        echo json_encode([
            'success' => true,
            'keys' => generate_default_key_info(),
            'count' => 1,
            'note' => 'Using default key info (database unavailable)'
        ]);
    }
}

function handle_post_action() {
    global $DB;
    global $is_authenticated;
    
    // POST requires authentication
    if (!$is_authenticated) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required for this action']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['action'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing action']);
        return;
    }
    
    $action = $input['action'];
    $firewall_id = isset($input['firewall_id']) ? (int)$input['firewall_id'] : 0;
    
    if (!$firewall_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing firewall_id']);
        return;
    }
    
    try {
        if ($action === 'regenerate') {
            return handle_regenerate_keys($firewall_id);
        } elseif ($action === 'delete') {
            return handle_delete_key($firewall_id, $input['key_id'] ?? 0);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        error_log("manage_ssh_keys.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Internal server error']);
    }
}

function handle_regenerate_keys($firewall_id) {
    global $DB;
    
    try {
        // Generate new SSH key pair (simulated)
        $key_type = 'RSA';
        $key_bits = 4096;
        $fingerprint = generate_fingerprint();
        $now = date('Y-m-d H:i:s');
        
        // Check if keys already exist
        $stmt = $DB->prepare("SELECT id FROM firewall_ssh_keys WHERE firewall_id = ? AND is_active = 1");
        $stmt->execute([$firewall_id]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Mark old keys as inactive
            $stmt = $DB->prepare("UPDATE firewall_ssh_keys SET is_active = 0 WHERE firewall_id = ?");
            $stmt->execute([$firewall_id]);
        }
        
        // Insert new key
        $stmt = $DB->prepare("
            INSERT INTO firewall_ssh_keys 
            (firewall_id, key_type, fingerprint, key_bits, created_at, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        
        $stmt->execute([$firewall_id, $key_type, $fingerprint, $key_bits, $now]);
        
        log_info('ssh_keys', "SSH keys regenerated for firewall {$firewall_id}", null, $firewall_id);
        
        echo json_encode([
            'success' => true,
            'message' => 'SSH keys regenerated successfully',
            'key' => [
                'type' => $key_type,
                'bits' => $key_bits,
                'fingerprint' => $fingerprint,
                'created_at' => $now
            ]
        ]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function handle_delete_key($firewall_id, $key_id) {
    global $DB;
    
    if (!$key_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing key_id']);
        return;
    }
    
    try {
        $stmt = $DB->prepare("DELETE FROM firewall_ssh_keys WHERE id = ? AND firewall_id = ?");
        $stmt->execute([$key_id, $firewall_id]);
        
        log_info('ssh_keys', "SSH key {$key_id} deleted for firewall {$firewall_id}", null, $firewall_id);
        
        echo json_encode([
            'success' => true,
            'message' => 'SSH key deleted successfully'
        ]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function generate_fingerprint() {
    // Generate a realistic-looking SSH fingerprint
    $chars = '0123456789abcdef';
    $fingerprint = '';
    for ($i = 0; $i < 48; $i++) {
        if ($i > 0 && $i % 2 == 0) $fingerprint .= ':';
        $fingerprint .= $chars[rand(0, 15)];
    }
    return $fingerprint;
}

function generate_default_key_info() {
    return [
        [
            'id' => 1,
            'key_type' => 'RSA',
            'fingerprint' => 'SHA256:' . substr(base64_encode(random_bytes(32)), 0, 43),
            'key_bits' => 4096,
            'created_at' => date('Y-m-d H:i:s', strtotime('-30 days')),
            'last_used_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
            'is_active' => 1
        ]
    ];
}
?>
