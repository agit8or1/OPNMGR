<?php
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'CSRF verification failed']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_tag') {
        $tagName = trim($_POST['tag_name'] ?? '');
        $tagColor = trim($_POST['tag_color'] ?? '#007bff');
        
        if (empty($tagName)) {
            echo json_encode(['success' => false, 'message' => 'Tag name is required']);
            exit;
        }

        try {
            // Check if tag already exists
            $stmt = db()->prepare('SELECT COUNT(*) FROM tags WHERE name = ?');
            $stmt->execute([$tagName]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                echo json_encode(['success' => false, 'message' => 'Tag already exists']);
                exit;
            }

            // Add tag
            $stmt = db()->prepare('INSERT INTO tags (name, color) VALUES (?, ?)');
            $stmt->execute([$tagName, $tagColor]);
            $tagId = db()->lastInsertId();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Tag added successfully',
                'tag_name' => $tagName,
                'tag_id' => $tagId
            ]);
        } catch (Exception $e) {
            error_log("manage_tags.php error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Internal server error']);
        }
        
    } elseif ($action === 'edit_tag') {
        $tagId = (int)($_POST['tag_id'] ?? 0);
        $tagName = trim($_POST['tag_name'] ?? '');
        $tagColor = trim($_POST['tag_color'] ?? '#007bff');
        
        if (!$tagId || empty($tagName)) {
            echo json_encode(['success' => false, 'message' => 'Tag ID and name are required']);
            exit;
        }

        try {
            // Check if another tag with this name already exists
            $stmt = db()->prepare('SELECT COUNT(*) FROM tags WHERE name = ? AND id != ?');
            $stmt->execute([$tagName, $tagId]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                echo json_encode(['success' => false, 'message' => 'Tag name already exists']);
                exit;
            }

            // Update tag
            $stmt = db()->prepare('UPDATE tags SET name = ?, color = ? WHERE id = ?');
            $stmt->execute([$tagName, $tagColor, $tagId]);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Tag updated successfully',
                'tag_id' => $tagId,
                'tag_name' => $tagName,
                'tag_color' => $tagColor
            ]);
        } catch (Exception $e) {
            error_log("manage_tags.php error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Internal server error']);
        }
        
    } elseif ($action === 'delete_tag') {
        $tagId = (int)($_POST['tag_id'] ?? 0);
        
        if (!$tagId) {
            echo json_encode(['success' => false, 'message' => 'Tag ID is required']);
            exit;
        }

        try {
            // Check if tag has firewalls
            $stmt = db()->prepare('SELECT COUNT(*) FROM firewall_tags WHERE tag_id = ?');
            $stmt->execute([$tagId]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete tag with existing firewalls']);
                exit;
            }

            // Delete tag
            $stmt = db()->prepare('DELETE FROM tags WHERE id = ?');
            $stmt->execute([$tagId]);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Tag deleted successfully',
                'tag_id' => $tagId
            ]);
        } catch (Exception $e) {
            error_log("manage_tags.php error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Internal server error']);
        }
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>
