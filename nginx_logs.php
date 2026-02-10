<?php
// Administration > Nginx Logs
require_once __DIR__ . '/inc/bootstrap.php';
requireAdmin();

$logType = $_GET['type'] ?? 'error';
$lines = (int)($_GET['lines'] ?? 100);
$search = $_GET['search'] ?? '';

// Validate log type
$allowedLogs = [
    'error' => '/var/log/nginx/error.log',
    'access' => '/var/log/nginx/access.log',
    'tunnel_cleanup' => '/var/log/opnsense/tunnel_cleanup.log',
    'nginx_cleanup' => '/var/log/opnsense/nginx_cleanup.log'
];

if (!isset($allowedLogs[$logType])) {
    $logType = 'error';
}

$logFile = $allowedLogs[$logType];

// Handle download BEFORE any output
if (isset($_GET['download']) && file_exists($logFile)) {
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . basename($logFile) . '"');
    header('Content-Length: ' . filesize($logFile));
    readfile($logFile);
    exit;
}

// Handle clear BEFORE any output (POST only with CSRF token)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear']) && file_exists($logFile)) {
    if (csrf_verify($_POST['csrf_token'] ?? '')) {
        shell_exec('sudo truncate -s 0 ' . escapeshellarg($logFile));
    }
    header('Location: ?type=' . $logType);
    exit;
}

// Now include header and continue with page output
require_once 'inc/header.php';
$logContent = '';
$logSize = 0;
$lastModified = '';

if (file_exists($logFile)) {
    $logSize = filesize($logFile);
    $lastModified = date('Y-m-d H:i:s', filemtime($logFile));
    
    // Read last N lines
    $command = "tail -n {$lines} " . escapeshellarg($logFile);
    
    // Add search filter if specified
    if (!empty($search)) {
        $command .= " | grep -i " . escapeshellarg($search);
    }
    
    $logContent = shell_exec($command . " 2>&1");
    
    if (empty($logContent)) {
        $logContent = "No matching entries found.";
    }
} else {
    $logContent = "Log file not found: {$logFile}";
}
?>

<style>
.log-viewer {
    background: #1a1a1a;
    border: 1px solid #333;
    border-radius: 8px;
    padding: 1rem;
    font-family: 'Courier New', monospace;
    font-size: 0.85rem;
    color: #00ff00;
    max-height: 600px;
    overflow-y: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
}

.log-viewer .error {
    color: #ff6b6b;
}

.log-viewer .warning {
    color: #ffd93d;
}

.log-viewer .info {
    color: #6bcfff;
}

.log-stats {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 6px;
    padding: 0.75rem;
    margin-bottom: 1rem;
}

.card-dark {
    background: linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.03));
    color: #ffffff;
    border: 1px solid rgba(255,255,255,0.15);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    border-radius: 8px;
}

.card-dark h2, .card-dark h4 {
    color: #ffffff;
}

.card-dark .text-muted {
    color: #cbd5e0;
}
</style>

<div class="card-dark">
    <h2><i class="fa fa-file-alt me-2"></i> Nginx Log Viewer</h2>
    <p class="text-muted">View and search nginx and tunnel management logs in real-time.</p>
</div>

<div class="card-dark">
    <div class="row mb-3">
        <div class="col-md-3">
            <label class="form-label" style="color: #fff;">Log Type</label>
            <select class="form-control" onchange="window.location.href='?type=' + this.value + '&lines=<?php echo $lines; ?>&search=<?php echo urlencode($search); ?>'">
                <option value="error" <?php echo $logType === 'error' ? 'selected' : ''; ?>>Nginx Error Log</option>
                <option value="access" <?php echo $logType === 'access' ? 'selected' : ''; ?>>Nginx Access Log</option>
                <option value="tunnel_cleanup" <?php echo $logType === 'tunnel_cleanup' ? 'selected' : ''; ?>>Tunnel Cleanup Log</option>
                <option value="nginx_cleanup" <?php echo $logType === 'nginx_cleanup' ? 'selected' : ''; ?>>Nginx Cleanup Log</option>
            </select>
        </div>
        
        <div class="col-md-2">
            <label class="form-label" style="color: #fff;">Lines to Show</label>
            <select class="form-control" onchange="window.location.href='?type=<?php echo $logType; ?>&lines=' + this.value + '&search=<?php echo urlencode($search); ?>'">
                <option value="50" <?php echo $lines === 50 ? 'selected' : ''; ?>>50</option>
                <option value="100" <?php echo $lines === 100 ? 'selected' : ''; ?>>100</option>
                <option value="200" <?php echo $lines === 200 ? 'selected' : ''; ?>>200</option>
                <option value="500" <?php echo $lines === 500 ? 'selected' : ''; ?>>500</option>
                <option value="1000" <?php echo $lines === 1000 ? 'selected' : ''; ?>>1000</option>
            </select>
        </div>
        
        <div class="col-md-5">
            <label class="form-label" style="color: #fff;">Search Filter</label>
            <form method="GET" class="input-group">
                <input type="hidden" name="type" value="<?php echo $logType; ?>">
                <input type="hidden" name="lines" value="<?php echo $lines; ?>">
                <input type="text" name="search" class="form-control" placeholder="Search logs..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i></button>
                <?php if (!empty($search)): ?>
                    <a href="?type=<?php echo $logType; ?>&lines=<?php echo $lines; ?>" class="btn btn-secondary"><i class="fa fa-times"></i> Clear</a>
                <?php endif; ?>
            </form>
        </div>
        
        <div class="col-md-2 d-flex align-items-end">
            <button class="btn btn-success w-100" onclick="location.reload()">
                <i class="fa fa-sync-alt me-2"></i> Refresh
            </button>
        </div>
    </div>
    
    <div class="log-stats">
        <div class="row text-center">
            <div class="col-md-4">
                <strong style="color: #fff;">File:</strong> 
                <span style="color: #cbd5e0;"><?php echo basename($logFile); ?></span>
            </div>
            <div class="col-md-4">
                <strong style="color: #fff;">Size:</strong> 
                <span style="color: #cbd5e0;"><?php echo round($logSize / 1024 / 1024, 2); ?> MB</span>
            </div>
            <div class="col-md-4">
                <strong style="color: #fff;">Last Modified:</strong> 
                <span style="color: #cbd5e0;"><?php echo $lastModified; ?></span>
            </div>
        </div>
    </div>
    
    <div class="log-viewer" id="logViewerContainer" style="font-size: 0.8rem; background: #1a1a1a; border: 1px solid #333; border-radius: 8px; padding: 1rem; max-height: 800px; overflow-y: auto;">
        <table class="table table-dark table-sm" style="margin-bottom: 0; font-family: 'Courier New', monospace;">
            <thead style="position: sticky; top: 0; background: #2a2a2a; border-bottom: 2px solid #444;">
                <tr>
                    <th style="width: 180px; color: #cbd5e0;">Timestamp</th>
                    <th style="width: 120px; color: #cbd5e0;">Level</th>
                    <th style="color: #cbd5e0;">Message</th>
                </tr>
            </thead>
            <tbody id="logTableBody">
<?php
// Parse nginx error logs and format them
$lines = array_filter(explode("\n", $logContent));
foreach (array_slice($lines, -200) as $line) {
    if (empty($line)) continue;
    
    // Parse nginx error log format: 2025/10/27 01:02:00 [error] PID: message
    preg_match('/^(\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2}:\d{2})\s+\[(\w+)\]\s+(.*)$/', $line, $matches);
    
    if ($matches) {
        $timestamp = str_replace('/', '-', $matches[1]);
        $level = $matches[2];
        $message = $matches[3];
        
        // Parse the message to extract key info
        if (preg_match('/^\*\d+\s+(.+?),\s+client:\s+(.+?),\s+server:\s+(.+?),\s+request:\s+"(.+?)"\s*,(.*)$/i', $message, $msg_parts)) {
            $error_msg = $msg_parts[1];
            $client = $msg_parts[2];
            $server = $msg_parts[3];
            $request = $msg_parts[4];
            
            $level_color = ($level === 'error') ? '#ff6b6b' : (($level === 'warn') ? '#ffd93d' : '#6bcfff');
            $level_bg = ($level === 'error') ? 'rgba(255, 107, 107, 0.1)' : (($level === 'warn') ? 'rgba(255, 217, 61, 0.1)' : 'rgba(107, 207, 255, 0.1)');
            
            echo "<tr style='border-bottom: 1px solid #333; background: $level_bg;'>";
            echo "<td style='color: #a0aec0; white-space: nowrap;'>$timestamp</td>";
            echo "<td style='color: $level_color; font-weight: bold;'>$level</td>";
            echo "<td style='color: #e2e8f0;'>";
            echo "<div style='margin-bottom: 4px;'><strong>Error:</strong> " . htmlspecialchars(substr($error_msg, 0, 80)) . (strlen($error_msg) > 80 ? '...' : '') . "</div>";
            echo "<small style='color: #cbd5e0;'>Client: " . htmlspecialchars($client) . " | Request: " . htmlspecialchars(substr($request, 0, 60)) . (strlen($request) > 60 ? '...' : '') . "</small>";
            echo "</td>";
            echo "</tr>";
        } else {
            // Fallback for lines that don't match the pattern
            $level_color = ($level === 'error') ? '#ff6b6b' : (($level === 'warn') ? '#ffd93d' : '#6bcfff');
            echo "<tr style='border-bottom: 1px solid #333;'>";
            echo "<td style='color: #a0aec0; white-space: nowrap;'>$timestamp</td>";
            echo "<td style='color: $level_color; font-weight: bold;'>$level</td>";
            echo "<td style='color: #e2e8f0;'>" . htmlspecialchars(substr($message, 0, 150)) . (strlen($message) > 150 ? '...' : '') . "</td>";
            echo "</tr>";
        }
    }
}
?>
            </tbody>
        </table>
    </div>
    
    <script>
    // Auto-scroll to bottom
    document.getElementById('logViewerContainer').scrollTop = document.getElementById('logViewerContainer').scrollHeight;
    </script>
    
    <div class="mt-3">
        <button class="btn btn-warning" onclick="if(confirm('Download full log file?')) window.location.href='?type=<?php echo $logType; ?>&download=1'">
            <i class="fa fa-download me-2"></i> Download Full Log
        </button>
        
        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to clear this log file? This cannot be undone!')">
            <input type="hidden" name="clear" value="1">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <button type="submit" class="btn btn-danger">
                <i class="fa fa-trash me-2"></i> Clear Log
            </button>
        </form>
    </div>
</div>

<?php require_once 'inc/footer.php'; ?>
