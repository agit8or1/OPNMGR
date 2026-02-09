<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/csrf.php';
requireLogin();
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'CSRF verification failed']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_group') {
        $groupName = trim($_POST['group_name'] ?? '');
        
        if (empty($groupName)) {
            echo json_encode(['success' => false, 'message' => 'Group name is required']);
            exit;
        }

        try {
            // Check if group already exists
            $stmt = $DB->prepare('SELECT COUNT(*) FROM firewalls WHERE customer_group = ?');
            $stmt->execute([$groupName]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                echo json_encode(['success' => false, 'message' => 'Group already exists']);
                exit;
            }

            // Add a dummy firewall with this group to create the group
            $stmt = $DB->prepare('INSERT INTO firewalls (hostname, ip_address, customer_group, status) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id=id');
            $stmt->execute(['temp-' . time(), '127.0.0.1', $groupName, 'temp']);
            
            // Delete the temp firewall
            $stmt = $DB->prepare('DELETE FROM firewalls WHERE hostname LIKE ?');
            $stmt->execute(['temp-%']);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Group added successfully',
                'group_name' => $groupName,
                'group_id' => md5($groupName)
            ]);
        } catch (Exception $e) {
            error_log("manage_groups.php error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Internal server error']);
        }
        
    } elseif ($action === 'delete_group') {
        $groupName = trim($_POST['group_name'] ?? '');
        
        if (empty($groupName)) {
            echo json_encode(['success' => false, 'message' => 'Group name is required']);
            exit;
        }

        try {
            // Check if group has firewalls
            $stmt = $DB->prepare('SELECT COUNT(*) FROM firewalls WHERE customer_group = ?');
            $stmt->execute([$groupName]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete group with existing firewalls']);
                exit;
            }

            // Group deletion is implicit - just return success
            echo json_encode([
                'success' => true, 
                'message' => 'Group deleted successfully',
                'group_id' => md5($groupName)
            ]);
        } catch (Exception $e) {
            error_log("manage_groups.php error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Internal server error']);
        }
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>
