#!/usr/bin/env php
<?php
/**
 * Feature Documentation Auto-Updater
 * 
 * Automatically updates all documentation when features or changes are tracked:
 * - README.md (key features section)
 * - FEATURES.md (comprehensive feature catalog)
 * - CHANGELOG.md (version history from change_log table)
 * 
 * Run manually or via cron after feature updates
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';

echo "🔄 OPNManager Documentation Auto-Updater\n";
echo "==========================================\n\n";

// Fetch all features from database
$features = db()->query("
    SELECT * FROM features 
    ORDER BY 
        FIELD(status, 'production', 'development', 'planned', 'deprecated'),
        category, 
        name
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($features)) {
    echo "❌ No features found in database!\n";
    exit(1);
}

echo "📊 Found " . count($features) . " features\n\n";

// Group features by category
$by_category = [];
foreach ($features as $feature) {
    $by_category[$feature['category']][] = $feature;
}

// Count by status
$status_counts = [
    'production' => 0,
    'development' => 0,
    'planned' => 0,
    'deprecated' => 0
];

foreach ($features as $feature) {
    $status_counts[$feature['status']]++;
}

echo "Status Breakdown:\n";
echo "  ✅ Production: {$status_counts['production']}\n";
echo "  🚧 Development: {$status_counts['development']}\n";
echo "  📋 Planned: {$status_counts['planned']}\n";
echo "  ⚠️  Deprecated: {$status_counts['deprecated']}\n\n";

// Generate feature list for README
$readme_features = generateReadmeFeatures($by_category);

// Generate feature matrix
$feature_matrix = generateFeatureMatrix($features);

// Update README.md
$readme_path = __DIR__ . '/../README.md';
if (file_exists($readme_path)) {
    $readme_content = file_get_contents($readme_path);
    
    // Update features section
    $readme_content = preg_replace(
        '/## 🌟 Key Features.*?(?=##)/s',
        "## 🌟 Key Features\n\n" . $readme_features . "\n",
        $readme_content
    );
    
    file_put_contents($readme_path, $readme_content);
    echo "✅ Updated README.md\n";
} else {
    echo "⚠️  README.md not found\n";
}

// Update FEATURES.md matrix section
$features_path = __DIR__ . '/../FEATURES.md';
if (file_exists($features_path)) {
    $features_content = file_get_contents($features_path);
    
    // Update feature matrix
    $features_content = preg_replace(
        '/## 📊 Feature Matrix.*?(?=##)/s',
        "## 📊 Feature Matrix\n\n" . $feature_matrix . "\n",
        $features_content
    );
    
    // Update last updated date
    $features_content = preg_replace(
        '/\*\*Last Updated\*\*:.*?\n/',
        "**Last Updated**: " . date('F j, Y') . "\n",
        $features_content
    );
    
    file_put_contents($features_path, $features_content);
    echo "✅ Updated FEATURES.md\n";
} else {
    echo "⚠️  FEATURES.md not found\n";
}

echo "\n✨ Documentation update complete!\n";

// ============================================================================
// Update CHANGELOG.md
// ============================================================================

echo "\n📝 Generating CHANGELOG.md...\n";

$changelog_content = generateChangelog();
$changelog_path = __DIR__ . '/../CHANGELOG.md';
file_put_contents($changelog_path, $changelog_content);
echo "✅ Updated CHANGELOG.md\n";

echo "\n🎉 All documentation updated successfully!\n";
echo "   - README.md (Key Features)\n";
echo "   - FEATURES.md (Feature Matrix)\n";
echo "   - CHANGELOG.md (Version History)\n\n";

// ============================================================================
// Helper Functions
// ============================================================================

function generateReadmeFeatures($by_category) {
    $output = "";
    
    foreach ($by_category as $category => $features) {
        $output .= "### {$category}\n";
        
        foreach ($features as $feature) {
            if ($feature['status'] === 'production' || $feature['status'] === 'development') {
                $status_icon = $feature['status'] === 'production' ? '✅' : '🚧';
                $output .= "- **{$feature['name']}** {$status_icon}\n";
                $output .= "  - " . $feature['description'] . "\n";
                
                if ($feature['status'] === 'development') {
                    $output .= "  - _Status: In Development_\n";
                }
                $output .= "\n";
            }
        }
    }
    
    return $output;
}

function generateFeatureMatrix($features) {
    $output = "| Feature | Status | Version | Multi-Tenant | API | Agent Required |\n";
    $output .= "|---------|--------|---------|--------------|-----|----------------|\n";
    
    foreach ($features as $feature) {
        $status_icon = match($feature['status']) {
            'production' => '✅ Production',
            'development' => '🚧 Development',
            'planned' => '📋 Planned',
            'deprecated' => '⚠️ Deprecated',
            default => '❓ Unknown'
        };
        
        $multi_tenant = $feature['multi_tenant'] ? '✅' : '❌';
        $api = $feature['api_enabled'] ? '✅' : '❌';
        $agent = $feature['requires_agent'] ? '✅' : '❌';
        $version = $feature['version'] ?: 'TBD';
        
        $output .= "| {$feature['name']} | {$status_icon} | {$version} | {$multi_tenant} | {$api} | {$agent} |\n";
    }
    
    return $output;
}

function generateChangelog() {
    
    $output = "# OPNManager Changelog\n\n";
    $output .= "All notable changes to OPNManager are documented here.\n\n";
    $output .= "**Last Updated**: " . date('F j, Y g:i A') . "\n\n";
    $output .= "---\n\n";
    
    // Get all versions with their changes
    $versions = db()->query("
        SELECT DISTINCT version 
        FROM change_log 
        ORDER BY 
            CAST(SUBSTRING_INDEX(version, '.', 1) AS UNSIGNED) DESC,
            CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(version, '.', 2), '.', -1) AS UNSIGNED) DESC,
            CAST(SUBSTRING_INDEX(version, '.', -1) AS UNSIGNED) DESC
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($versions)) {
        $output .= "_No changelog entries found._\n";
        return $output;
    }
    
    foreach ($versions as $version) {
        // Get all changes for this version
        $stmt = db()->prepare("
            SELECT * FROM change_log 
            WHERE version = ? 
            ORDER BY 
                FIELD(change_type, 'breaking', 'security', 'feature', 'improvement', 'bugfix', 'update_applied'),
                created_at DESC
        ");
        $stmt->execute([$version]);
        $changes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($changes)) continue;
        
        // Version header
        $output .= "## Version {$version}\n";
        
        // Get release date from latest change
        $release_date = date('F j, Y', strtotime($changes[0]['created_at']));
        $output .= "_Released: {$release_date}_\n\n";
        
        // Group changes by type
        $by_type = [
            'breaking' => [],
            'security' => [],
            'feature' => [],
            'improvement' => [],
            'bugfix' => [],
            'update_applied' => []
        ];
        
        foreach ($changes as $change) {
            $by_type[$change['change_type']][] = $change;
        }
        
        // Breaking Changes (most important)
        if (!empty($by_type['breaking'])) {
            $output .= "### ⚠️ BREAKING CHANGES\n\n";
            foreach ($by_type['breaking'] as $change) {
                $output .= formatChangeEntry($change);
            }
            $output .= "\n";
        }
        
        // Security Fixes
        if (!empty($by_type['security'])) {
            $output .= "### 🔒 Security Fixes\n\n";
            foreach ($by_type['security'] as $change) {
                $output .= formatChangeEntry($change);
            }
            $output .= "\n";
        }
        
        // New Features
        if (!empty($by_type['feature'])) {
            $output .= "### ✨ New Features\n\n";
            foreach ($by_type['feature'] as $change) {
                $output .= formatChangeEntry($change);
            }
            $output .= "\n";
        }
        
        // Improvements
        if (!empty($by_type['improvement'])) {
            $output .= "### 🚀 Improvements\n\n";
            foreach ($by_type['improvement'] as $change) {
                $output .= formatChangeEntry($change);
            }
            $output .= "\n";
        }
        
        // Bug Fixes
        if (!empty($by_type['bugfix'])) {
            $output .= "### 🐛 Bug Fixes\n\n";
            foreach ($by_type['bugfix'] as $change) {
                $output .= formatChangeEntry($change);
            }
            $output .= "\n";
        }
        
        // Updates Applied (for deployed instances)
        if (!empty($by_type['update_applied'])) {
            $output .= "### 📦 Updates Applied\n\n";
            foreach ($by_type['update_applied'] as $change) {
                $output .= formatChangeEntry($change);
            }
            $output .= "\n";
        }
        
        $output .= "---\n\n";
    }
    
    return $output;
}

function formatChangeEntry($change) {
    $output = "- **{$change['title']}**";
    
    // Add component badge if available
    if (!empty($change['component'])) {
        $output .= " `{$change['component']}`";
    }
    
    $output .= "\n";
    
    // Add description if available
    if (!empty($change['description'])) {
        $description_lines = explode("\n", trim($change['description']));
        foreach ($description_lines as $line) {
            if (!empty(trim($line))) {
                $output .= "  " . trim($line) . "\n";
            }
        }
    }
    
    // Add author/commit info if available
    $meta = [];
    if (!empty($change['author'])) {
        $meta[] = "by {$change['author']}";
    }
    if (!empty($change['commit_hash'])) {
        $short_hash = substr($change['commit_hash'], 0, 7);
        $meta[] = "commit {$short_hash}";
    }
    
    if (!empty($meta)) {
        $output .= "  _" . implode(', ', $meta) . "_\n";
    }
    
    $output .= "\n";
    
    return $output;
}

