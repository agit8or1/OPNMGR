#!/usr/bin/env php
<?php
/**
 * Release Documentation Updater
 * 
 * Automatically updates:
 * - Version number in inc/version.php
 * - Changelog entries in database
 * - Documentation pages in database
 * 
 * Usage:
 *   php update_release_docs.php <version> "<description>"
 *   Example: php update_release_docs.php 2.2.3 "Fixed tunnel race condition and session conflicts"
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from command line\n");
}

// Load database connection
require_once __DIR__ . '/../inc/bootstrap_agent.php';

$version = $argv[1] ?? null;
$description = $argv[2] ?? '';

if (!$version) {
    echo "Usage: php update_release_docs.php <version> \"<description>\"\n";
    echo "Example: php update_release_docs.php 2.2.3 \"Fixed tunnel race condition\"\n";
    exit(1);
}

// Validate version format (x.y.z)
if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    die("Error: Version must be in format x.y.z (e.g., 2.2.3)\n");
}

$date = date('Y-m-d');
$dateStr = date('F j, Y');
$datetime = date('Y-m-d H:i:s');

echo "════════════════════════════════════════════════════════════════\n";
echo "  OPNManager Release Documentation Updater\n";
echo "════════════════════════════════════════════════════════════════\n";
echo "Version: {$version}\n";
echo "Date: {$dateStr}\n";
echo "Description: {$description}\n";
echo "────────────────────────────────────────────────────────────────\n\n";

// 1. Update inc/version.php
echo "[1/3] Updating inc/version.php...\n";
$version_file = __DIR__ . '/../inc/version.php';
if (!file_exists($version_file)) {
    die("Error: version.php not found at {$version_file}\n");
}

$version_content = file_get_contents($version_file);
$version_content = preg_replace(
    "/define\('APP_VERSION', '[\d\.]+'\);/",
    "define('APP_VERSION', '{$version}');",
    $version_content
);
$version_content = preg_replace(
    "/define\('APP_VERSION_DATE', '[\d\-]+'\);/",
    "define('APP_VERSION_DATE', '{$date}');",
    $version_content
);
file_put_contents($version_file, $version_content);
echo "   ✓ Updated APP_VERSION constant to {$version}\n";
echo "   ✓ Updated APP_VERSION_DATE to {$date}\n\n";

// 2. Add to changelog database
echo "[2/3] Adding changelog entry to database...\n";
try {
    // Format changes as bullet points
    $changes = $description;
    
    // Insert changelog entry
    $stmt = db()->prepare("
        INSERT INTO changelog_entries (version, release_date, description, changes, updated_by)
        VALUES (:version, :date, :description, :changes, :updated_by)
    ");
    $stmt->execute([
        'version' => $version,
        'date' => $date,
        'description' => $description,
        'changes' => $changes,
        'updated_by' => "release_v{$version}"
    ]);
    
    echo "   ✓ Added changelog entry for version {$version}\n\n";
} catch (PDOException $e) {
    echo "   ✗ Error adding changelog: " . $e->getMessage() . "\n\n";
}

// 3. Update documentation pages in database
echo "[3/3] Updating documentation database...\n";

try {
    // Update 'about' page with new version info
    $aboutContent = db()->query("SELECT content FROM documentation_pages WHERE page_key='about'")->fetchColumn();
    if ($aboutContent) {
        // Update version number in about page
        $aboutContent = preg_replace(
            '/<div class="version-number">v[0-9\.]+<\/div>/',
            '<div class="version-number">v' . $version . '</div>',
            $aboutContent
        );
        
        // Update release date (look for "Released:" label followed by date)
        $aboutContent = preg_replace(
            '/(<div class="version-label">Released:<\/div>.*?<div class="version-number">)[^<]+(<\/div>)/s',
            '$1' . $dateStr . '$2',
            $aboutContent
        );
        
        $stmt = db()->prepare("UPDATE documentation_pages SET content = :content, last_updated = NOW(), updated_by = :updated_by WHERE page_key = 'about'");
        $stmt->execute([
            'content' => $aboutContent,
            'updated_by' => "release_v{$version}"
        ]);
        echo "   ✓ Updated about page with version {$version}\n";
    }
    
    // Update 'features' page with release notice
    $featuresContent = db()->query("SELECT content FROM documentation_pages WHERE page_key='features'")->fetchColumn();
    if ($featuresContent && !strpos($featuresContent, "Released: {$dateStr}")) {
        $releaseNotice = "<div class='alert alert-info'><strong>Latest Release:</strong> Version {$version} released on {$dateStr}</div>\n\n";
        $featuresContent = $releaseNotice . $featuresContent;
        
        $stmt = db()->prepare("UPDATE documentation_pages SET content = :content, last_updated = NOW(), updated_by = :updated_by WHERE page_key = 'features'");
        $stmt->execute([
            'content' => $featuresContent,
            'updated_by' => "release_v{$version}"
        ]);
        echo "   ✓ Updated features page with release notice\n";
    }
    
    echo "   ✓ Documentation database updated\n\n";
    
} catch (PDOException $e) {
    echo "   ✗ Error updating documentation: " . $e->getMessage() . "\n\n";
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "                    UPDATE COMPLETE                             \n";
echo "═══════════════════════════════════════════════════════════════\n\n";
echo "Summary:\n";
echo "  • Version updated to: {$version}\n";
echo "  • Release date: {$date}\n";
echo "  • Changelog entry added to database\n";
echo "  • Documentation pages updated\n\n";
echo "Next Steps:\n";
echo "  1. Review changes at /changelog.php\n";
echo "  2. Update user documentation if needed at /doc_viewer.php?page=documentation\n";
echo "  3. Test all features before deployment\n";
echo "  4. Commit changes: git add -A && git commit -m 'Release v{$version}'\n";
echo "  5. Tag release: git tag -a v{$version} -m 'Version {$version}'\n\n";
exit(0);
