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

    if ($action === 'add_tag') {
        $tagName = trim($_POST['tag_name'] ?? '');
        $tagColor = trim($_POST['tag_color'] ?? '#007bff');
        
        if (empty($tagName)) {
            echo json_encode(['success' => false, 'message' => 'Tag name is required']);
            exit;
        }

        try {
            // Check if tag already exists
            $stmt = $DB->prepare('SELECT COUNT(*) FROM tags WHERE name = ?');
            $stmt->execute([$tagName]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                echo json_encode(['success' => false, 'message' => 'Tag already exists']);
                exit;
            }

            // Add tag
            $stmt = $DB->prepare('INSERT INTO tags (name, color) VALUES (?, ?)');
            $stmt->execute([$tagName, $tagColor]);
            $tagId = $DB->lastInsertId();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Tag added successfully',
                'tag_name' => $tagName,
                'tag_id' => $tagId
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error adding tag: ' . $e->getMessage()]);
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
            $stmt = $DB->prepare('SELECT COUNT(*) FROM tags WHERE name = ? AND id != ?');
            $stmt->execute([$tagName, $tagId]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                echo json_encode(['success' => false, 'message' => 'Tag name already exists']);
                exit;
            }

            // Update tag
            $stmt = $DB->prepare('UPDATE tags SET name = ?, color = ? WHERE id = ?');
            $stmt->execute([$tagName, $tagColor, $tagId]);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Tag updated successfully',
                'tag_id' => $tagId,
                'tag_name' => $tagName,
                'tag_color' => $tagColor
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error updating tag: ' . $e->getMessage()]);
        }
        
    } elseif ($action === 'delete_tag') {
        $tagId = (int)($_POST['tag_id'] ?? 0);
        
        if (!$tagId) {
            echo json_encode(['success' => false, 'message' => 'Tag ID is required']);
            exit;
        }

        try {
            // Check if tag has firewalls
            $stmt = $DB->prepare('SELECT COUNT(*) FROM firewall_tags WHERE tag_id = ?');
            $stmt->execute([$tagId]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete tag with existing firewalls']);
                exit;
            }

            // Delete tag
            $stmt = $DB->prepare('DELETE FROM tags WHERE id = ?');
            $stmt->execute([$tagId]);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Tag deleted successfully',
                'tag_id' => $tagId
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error deleting tag: ' . $e->getMessage()]);
        }
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>
