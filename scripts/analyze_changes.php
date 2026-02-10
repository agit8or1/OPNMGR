#!/usr/bin/env php
<?php
/**
 * Analyze Recent Changes and Generate Release Notes
 * This script looks at git commits, file changes, and patterns to suggest release notes
 */

function analyzeGitCommits($limit = 20) {
    $commits = [];
    $cmd = "cd /var/www/opnsense && git log --oneline --no-merges -n {$limit} 2>/dev/null";
    exec($cmd, $output, $returnCode);
    
    if ($returnCode === 0 && !empty($output)) {
        foreach ($output as $line) {
            if (preg_match('/^[a-f0-9]+\s+(.+)$/i', $line, $matches)) {
                $commits[] = $matches[1];
            }
        }
    }
    
    return $commits;
}

function analyzeRecentFiles() {
    $changes = [];
    $cmd = "cd /var/www/opnsense && find . -name '*.php' -mtime -7 -type f 2>/dev/null | grep -v 'vendor\|node_modules\|History' | head -30";
    exec($cmd, $files);
    
    foreach ($files as $file) {
        $basename = basename($file);
        $changes[] = $basename;
    }
    
    return $changes;
}

function categorizeChanges($commits, $files) {
    $categories = [
        'features' => [],
        'fixes' => [],
        'improvements' => [],
        'security' => [],
        'documentation' => []
    ];
    
    // Patterns for each category
    $patterns = [
        'features' => [
            'add' => '/\b(add|added|new|create|implement)\b/i',
            'files' => ['add_', 'create_', 'new_']
        ],
        'fixes' => [
            'keywords' => '/\b(fix|fixed|bug|resolve|patch|correct)\b/i',
            'files' => ['fix_', 'repair_']
        ],
        'improvements' => [
            'keywords' => '/\b(improve|enhanced|update|upgrade|optimize|refactor)\b/i',
            'files' => ['improve_', 'enhance_', 'update_']
        ],
        'security' => [
            'keywords' => '/\b(security|auth|authentication|permission|xss|sql injection|csrf)\b/i',
            'files' => ['auth', 'security', '2fa', 'twofactor']
        ],
        'documentation' => [
            'keywords' => '/\b(doc|documentation|readme|guide|manual)\b/i',
            'files' => ['doc', 'readme', 'guide', '.md']
        ]
    ];
    
    // Analyze commits
    foreach ($commits as $commit) {
        $commit_lower = strtolower($commit);
        
        foreach ($patterns as $category => $pattern) {
            if (isset($pattern['keywords']) && preg_match($pattern['keywords'], $commit)) {
                $categories[$category][] = ucfirst($commit);
            }
        }
    }
    
    // Analyze files
    foreach ($files as $file) {
        $file_lower = strtolower($file);
        
        // Map files to categories
        if (strpos($file_lower, 'backup') !== false) {
            $categories['features'][] = "Enhanced backup system: {$file}";
        } elseif (strpos($file_lower, 'log') !== false && strpos($file_lower, 'viewer') !== false) {
            $categories['features'][] = "Added log viewer: {$file}";
        } elseif (strpos($file_lower, 'alert') !== false) {
            $categories['features'][] = "Alert system updates: {$file}";
        } elseif (strpos($file_lower, 'doc') !== false) {
            $categories['documentation'][] = "Documentation updates: {$file}";
        } elseif (strpos($file_lower, 'tunnel') !== false || strpos($file_lower, 'proxy') !== false) {
            $categories['improvements'][] = "Tunnel proxy improvements: {$file}";
        }
    }
    
    return $categories;
}

function generateSuggestions($categories) {
    $suggestions = [];
    
    // Generate readable suggestions
    $categoryTitles = [
        'features' => 'NEW FEATURES',
        'fixes' => 'BUG FIXES',
        'improvements' => 'IMPROVEMENTS',
        'security' => 'SECURITY',
        'documentation' => 'DOCUMENTATION'
    ];
    
    foreach ($categories as $category => $items) {
        if (!empty($items)) {
            // Remove duplicates and limit
            $items = array_unique($items);
            $items = array_slice($items, 0, 10);
            
            foreach ($items as $item) {
                $suggestions[] = strtoupper($categoryTitles[$category]) . ': ' . $item;
            }
        }
    }
    
    return $suggestions;
}

function smartGenerateDescription($categories) {
    $parts = [];
    
    if (!empty($categories['features'])) {
        $count = count($categories['features']);
        $parts[] = "Added {$count} new feature" . ($count > 1 ? 's' : '');
    }
    
    if (!empty($categories['fixes'])) {
        $count = count($categories['fixes']);
        $parts[] = "fixed {$count} bug" . ($count > 1 ? 's' : '');
    }
    
    if (!empty($categories['improvements'])) {
        $parts[] = "performance and UI improvements";
    }
    
    if (!empty($categories['security'])) {
        $parts[] = "security enhancements";
    }
    
    if (empty($parts)) {
        return "Bug fixes and improvements";
    }
    
    return ucfirst(implode(', ', $parts));
}

// Main execution
$commits = analyzeGitCommits(20);
$files = analyzeRecentFiles();
$categories = categorizeChanges($commits, $files);
$suggestions = generateSuggestions($categories);
$description = smartGenerateDescription($categories);

// Output as JSON for easy parsing
$result = [
    'description' => $description,
    'suggestions' => $suggestions,
    'categories' => $categories,
    'commit_count' => count($commits),
    'file_count' => count($files)
];

echo json_encode($result, JSON_PRETTY_PRINT);
