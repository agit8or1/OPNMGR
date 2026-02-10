<?php
/**
 * Simple Markdown Document Viewer
 */
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();

$file = $_GET['file'] ?? '';

// Security: Only allow specific markdown files
$allowed_files = [
    'TUNNEL_PROXY_FIX_DOCUMENTATION.md',
    'TUNNEL_PROXY_QUICK_REFERENCE.md',
    'PERMANENT_SSH_RULE_IMPLEMENTATION.md',
    'CHANGELOG.md',
    'README_TUNNEL_PROXY.md',
    'TUNNEL_BADGE_ISSUE.md',
    'TUNNEL_BADGE_IMPLEMENTED.md',
    'FIXES_SUMMARY_v2.2.2.md'
];

if (!in_array($file, $allowed_files)) {
    http_response_code(403);
    die('Access denied');
}

$filepath = __DIR__ . '/' . $file;

if (!file_exists($filepath)) {
    http_response_code(404);
    die('File not found');
}

$content = file_get_contents($filepath);

// Simple markdown to HTML conversion
$content = htmlspecialchars($content);

// Convert headers
$content = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $content);
$content = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $content);
$content = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $content);
$content = preg_replace('/^#### (.+)$/m', '<h4>$1</h4>', $content);

// Convert bold
$content = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $content);

// Convert code blocks
$content = preg_replace('/```(.+?)```/s', '<pre><code>$1</code></pre>', $content);
$content = preg_replace('/`([^`]+)`/', '<code>$1</code>', $content);

// Convert lists
$content = preg_replace('/^- (.+)$/m', '<li>$1</li>', $content);
$content = preg_replace('/^(\d+)\. (.+)$/m', '<li>$2</li>', $content);

// Wrap lists
$content = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $content);

// Convert line breaks
$content = nl2br($content);

$page_title = basename($file);
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($page_title); ?> - OPNManager</title>
    <style>
        body {
            background: #1a1d23;
            color: #e0e0e0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        h1 { color: #4fc3f7; border-bottom: 3px solid #3498db; padding-bottom: 10px; }
        h2 { color: #81c784; margin-top: 30px; }
        h3 { color: #ffb74d; margin-top: 20px; }
        h4 { color: #ff8a65; }
        pre {
            background: #2d3139;
            border: 1px solid #3a3f4b;
            border-radius: 4px;
            padding: 15px;
            overflow-x: auto;
        }
        code {
            background: #2d3139;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            color: #64b5f6;
        }
        pre code {
            background: none;
            padding: 0;
            color: #a5d6a7;
        }
        ul {
            margin-left: 20px;
        }
        li {
            margin: 8px 0;
            color: #b0b0b0;
        }
        strong {
            color: #fff;
        }
        a {
            color: #64b5f6;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            padding: 8px 16px;
            background: #3498db;
            color: white;
            border-radius: 4px;
            text-decoration: none;
        }
        .back-link:hover {
            background: #2980b9;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <a href="/dev_features.php" class="back-link">← Back to Features</a>
    
    <div class="content">
        <?php echo $content; ?>
    </div>
    
    <br><br>
    <a href="/dev_features.php" class="back-link">← Back to Features</a>
</body>
</html>
