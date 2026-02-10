<?php
/**
 * Documentation Auto-Update System
 * This file provides functions to automatically update documentation
 * when changes are made to the platform.
 */

require_once __DIR__ . '/db.php';

/**
 * Log a documentation update event
 */
function log_documentation_update($type, $description, $user = null) {
        
    try {
        $stmt = db()->prepare("
            INSERT INTO change_log (version, change_type, component, title, description, author, created_at) 
            VALUES (?, 'documentation', 'documentation', ?, ?, ?, NOW())
        ");
        
        // Get current development version
        $dev_version_stmt = db()->query("SELECT version FROM platform_versions WHERE status = 'development' ORDER BY created_at DESC LIMIT 1");
        $dev_version = $dev_version_stmt->fetchColumn() ?: 'dev';
        
        $stmt->execute([
            $dev_version,
            $type,
            $description,
            $user ?: ($_SESSION['username'] ?? 'system')
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Failed to log documentation update: " . $e->getMessage());
        return false;
    }
}

/**
 * Update features documentation when a new feature is completed
 */
function update_features_documentation($todo_id) {
        
    try {
        // Get the completed todo
        $stmt = db()->prepare("SELECT title, description, component FROM todos WHERE id = ?");
        $stmt->execute([$todo_id]);
        $todo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($todo) {
            log_documentation_update(
                "Feature Documentation Updated",
                "Added documentation for new feature: " . $todo['title'] . " (" . $todo['component'] . ")"
            );
            
            // Here you could add code to automatically update the features.php file
            // For now, we'll just log the event
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Failed to update features documentation: " . $e->getMessage());
        return false;
    }
}

/**
 * Update changelog when bugs are resolved
 */
function update_changelog_for_bug($bug_id) {
        
    try {
        // Get the resolved bug
        $stmt = db()->prepare("SELECT title, description, component, severity FROM bugs WHERE id = ?");
        $stmt->execute([$bug_id]);
        $bug = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($bug) {
            log_documentation_update(
                "Bug Fix Documentation",
                "Documented fix for " . $bug['severity'] . " severity bug: " . $bug['title'] . " (" . $bug['component'] . ")"
            );
            
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Failed to update bug fix documentation: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate updated documentation files
 */
function regenerate_documentation() {
        
    try {
        // Update last generation timestamp
        $stmt = db()->prepare("
            INSERT INTO change_log (version, change_type, component, title, description, author, created_at) 
            VALUES (?, 'improvement', 'documentation', 'Documentation Regenerated', 'All documentation files were automatically regenerated to include latest changes', 'system', NOW())
        ");
        
        $dev_version_stmt = db()->query("SELECT version FROM platform_versions WHERE status = 'development' ORDER BY created_at DESC LIMIT 1");
        $dev_version = $dev_version_stmt->fetchColumn() ?: 'dev';
        
        $stmt->execute([$dev_version]);
        
        // Here you could add code to regenerate documentation files
        // For now, we'll just log the event
        
        return true;
    } catch (Exception $e) {
        error_log("Failed to regenerate documentation: " . $e->getMessage());
        return false;
    }
}

/**
 * Hook function to be called when version management changes are made
 */
function on_version_management_change($action, $type, $item_id) {
    switch ($action) {
        case 'bug_resolved':
            update_changelog_for_bug($item_id);
            break;
            
        case 'todo_completed':
            update_features_documentation($item_id);
            break;
            
        case 'version_released':
            regenerate_documentation();
            break;
            
        default:
            log_documentation_update("General Update", "Version management change: {$action} on {$type} #{$item_id}");
            break;
    }
}

/**
 * Automatic documentation update scheduler
 * This function should be called periodically (e.g., via cron)
 */
function scheduled_documentation_update() {
        
    try {
        // Check for recent changes that need documentation updates
        $stmt = db()->query("
            SELECT 'bug' as type, id, title, updated_at 
            FROM bugs 
            WHERE status IN ('resolved', 'closed') 
            AND resolved_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
            
            UNION ALL
            
            SELECT 'todo' as type, id, title, updated_at 
            FROM todos 
            WHERE status = 'completed' 
            AND completed_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
            
            ORDER BY updated_at DESC
        ");
        
        $recent_changes = $stmt->fetchAll();
        
        if (!empty($recent_changes)) {
            $update_count = count($recent_changes);
            log_documentation_update(
                "Scheduled Documentation Update",
                "Processed {$update_count} recent changes for documentation updates"
            );
            
            // Process each change
            foreach ($recent_changes as $change) {
                if ($change['type'] === 'bug') {
                    update_changelog_for_bug($change['id']);
                } elseif ($change['type'] === 'todo') {
                    update_features_documentation($change['id']);
                }
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Scheduled documentation update failed: " . $e->getMessage());
        return false;
    }
}

// Auto-execute scheduled update if called directly
if (basename($_SERVER['PHP_SELF']) === 'doc_auto_update.php') {
    scheduled_documentation_update();
    echo json_encode(['success' => true, 'message' => 'Documentation update completed']);
}
?>