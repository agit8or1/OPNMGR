<?php
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json');

// Require authentication for changelog generation
requireLogin();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get current development version
    $stmt = $pdo->query("SELECT version FROM platform_versions WHERE status = 'development' ORDER BY created_at DESC LIMIT 1");
    $dev_version = $stmt->fetchColumn();
    
    if (!$dev_version) {
        // Create a new development version if none exists
        $stmt = $pdo->query("SELECT version FROM platform_versions WHERE status = 'released' ORDER BY created_at DESC LIMIT 1");
        $latest_version = $stmt->fetchColumn() ?: '1.0.0';
        
        // Increment version
        $version_parts = explode('.', $latest_version);
        $version_parts[1] = (int)$version_parts[1] + 1;
        $dev_version = implode('.', $version_parts);
        
        // Insert new development version
        $stmt = $pdo->prepare("INSERT INTO platform_versions (version, status, description, release_date) VALUES (?, 'development', 'Auto-generated development version', CURDATE())");
        $stmt->execute([$dev_version]);
    }
    
    $changes_added = 0;
    
    // Add recently resolved bugs as changelog entries
    $stmt = $pdo->prepare("
        SELECT * FROM bugs 
        WHERE status IN ('resolved', 'closed') 
        AND resolved_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        AND NOT EXISTS (
            SELECT 1 FROM change_log 
            WHERE change_log.title = bugs.title 
            AND change_log.version = ?
        )
    ");
    $stmt->execute([$dev_version]);
    $recent_bugs = $stmt->fetchAll();
    
    foreach ($recent_bugs as $bug) {
        $stmt = $pdo->prepare("
            INSERT INTO change_log (version, change_type, component, title, description, author, created_at) 
            VALUES (?, 'bugfix', ?, ?, ?, 'system', NOW())
        ");
        $stmt->execute([
            $dev_version,
            $bug['component'],
            $bug['title'],
            $bug['description']
        ]);
        $changes_added++;
        
        // Update bug with version_fixed
        $stmt = $pdo->prepare("UPDATE bugs SET version_fixed = ? WHERE id = ?");
        $stmt->execute([$dev_version, $bug['id']]);
    }
    
    // Add recently completed todos as changelog entries
    $stmt = $pdo->prepare("
        SELECT * FROM todos 
        WHERE status = 'completed' 
        AND completed_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        AND NOT EXISTS (
            SELECT 1 FROM change_log 
            WHERE change_log.title = todos.title 
            AND change_log.version = ?
        )
    ");
    $stmt->execute([$dev_version]);
    $recent_todos = $stmt->fetchAll();
    
    foreach ($recent_todos as $todo) {
        $change_type = $todo['category'] === 'feature' ? 'feature' : 'improvement';
        
        $stmt = $pdo->prepare("
            INSERT INTO change_log (version, change_type, component, title, description, author, created_at) 
            VALUES (?, ?, ?, ?, ?, 'system', NOW())
        ");
        $stmt->execute([
            $dev_version,
            $change_type,
            $todo['component'],
            $todo['title'],
            $todo['description']
        ]);
        $changes_added++;
    }
    
    // Update platform version with generated changelog
    if ($changes_added > 0) {
        $stmt = $pdo->prepare("
            UPDATE platform_versions 
            SET changelog = CONCAT(IFNULL(changelog, ''), ?, ?) 
            WHERE version = ?
        ");
        $changelog_entry = "\n\nAuto-generated changelog entry (" . date('Y-m-d H:i:s') . "):\n";
        $changelog_entry .= "- {$changes_added} changes automatically added from recent bug fixes and completed features\n";
        
        $stmt->execute(['', $changelog_entry, $dev_version]);
    }
    
    echo json_encode([
        'success' => true, 
        'message' => "Changelog updated successfully. Added {$changes_added} entries to version {$dev_version}.",
        'version' => $dev_version,
        'changes_added' => $changes_added
    ]);
    
} catch (Exception $e) {
    error_log("Changelog generation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>