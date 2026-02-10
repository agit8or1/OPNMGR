<?php
/**
 * Documentation Helper Functions
 * Load and display documentation content from database
 */

// Load version constants if not already loaded
if (!defined('APP_VERSION')) {
    require_once __DIR__ . '/version.php';
}

/**
 * Get documentation page content from database
 * @param string $pageKey Unique page identifier
 * @return array|null Page data or null if not found
 */
function getDocumentationPage($pageKey) {
    global $DB;
    
    try {
        $stmt = $DB->prepare("
            SELECT page_key, title, content, category, display_order, last_updated, updated_by
            FROM documentation_pages
            WHERE page_key = :page_key
            LIMIT 1
        ");
        $stmt->execute(['page_key' => $pageKey]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($page) {
            // Replace version placeholders dynamically
            if (defined('APP_VERSION')) {
                $page['content'] = str_replace('{{APP_VERSION}}', APP_VERSION, $page['content']);
                $page['content'] = preg_replace('/(OPNManager v)[0-9.]+/', '$1' . APP_VERSION, $page['content']);
            }
            if (defined('APP_VERSION_DATE')) {
                $page['content'] = str_replace('{{APP_VERSION_DATE}}', APP_VERSION_DATE, $page['content']);
            }
            if (defined('AGENT_VERSION')) {
                $page['content'] = str_replace('{{AGENT_VERSION}}', AGENT_VERSION, $page['content']);
            }
        }
        
        return $page;
    } catch (PDOException $e) {
        error_log("Error loading documentation page '$pageKey': " . $e->getMessage());
        return null;
    }
}

/**
 * Get all documentation pages in a category
 * @param string $category Category name
 * @return array Array of page data
 */
function getDocumentationByCategory($category) {
    global $DB;
    
    try {
        $stmt = $DB->prepare("
            SELECT page_key, title, content, category, display_order, last_updated
            FROM documentation_pages
            WHERE category = :category
            ORDER BY display_order ASC, title ASC
        ");
        $stmt->execute(['category' => $category]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error loading documentation category '$category': " . $e->getMessage());
        return [];
    }
}

/**
 * Update documentation page content
 * @param string $pageKey Page identifier
 * @param string $content New content
 * @param string $updatedBy Username of person updating
 * @return bool Success status
 */
function updateDocumentationPage($pageKey, $content, $updatedBy = 'system') {
    global $DB;
    
    try {
        $stmt = $DB->prepare("
            UPDATE documentation_pages
            SET content = :content, updated_by = :updated_by
            WHERE page_key = :page_key
        ");
        return $stmt->execute([
            'content' => $content,
            'updated_by' => $updatedBy,
            'page_key' => $pageKey
        ]);
    } catch (PDOException $e) {
        error_log("Error updating documentation page '$pageKey': " . $e->getMessage());
        return false;
    }
}

/**
 * Render documentation page with standard layout
 * @param string $pageKey Page identifier
 * @param array $additionalData Additional data to display above content
 */
function renderDocumentationPage($pageKey, $additionalData = []) {
    $page = getDocumentationPage($pageKey);
    
    if (!$page) {
        echo '<div class="alert alert-danger">';
        echo '<i class="fas fa-exclamation-triangle me-2"></i>';
        echo 'Documentation page not found. Please contact administrator.';
        echo '</div>';
        return;
    }
    
    // Display additional data if provided (like version info, stats, etc.)
    if (!empty($additionalData)) {
        echo $additionalData;
    }
    
    // Display main documentation content
    echo '<div class="documentation-content">';
    echo $page['content'];
    echo '</div>';
    
    // Display last updated info
    if (!empty($page['last_updated'])) {
        echo '<div class="text-muted mt-4 pt-3 border-top">';
        echo '<small><i class="fas fa-clock me-1"></i> Last updated: ' . date('F j, Y g:i A', strtotime($page['last_updated']));
        if (!empty($page['updated_by'])) {
            echo ' by ' . htmlspecialchars($page['updated_by']);
        }
        echo '</small>';
        echo '</div>';
    }
}
