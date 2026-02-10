<?php
/**
 * Direct Tunnel Access - Simple Redirect
 * Redirects user directly to the SSH tunnel port
 * No PHP proxy, no cookie manipulation - just browser → tunnel → firewall
 */

require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();

// Get session ID
$session_id = isset($_GET['session']) ? intval($_GET['session']) : 0;

if (!$session_id) {
    die("Error: No session ID provided");
}

// Get session details from database
$stmt = db()->prepare("
    SELECT s.*, f.hostname as firewall_hostname, f.name as firewall_name
    FROM ssh_access_sessions s
    JOIN firewalls f ON s.firewall_id = f.id
    WHERE s.id = ?
");
$stmt->execute([$session_id]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    die("Error: Session not found");
}

// Check if session is still active
if ($session['status'] !== 'active') {
    die("Error: Session has expired or been closed. Please create a new tunnel session from the firewall details page.");
}

// Check if session has expired
if (strtotime($session['expires_at']) < time()) {
    die("Error: Session expired at " . $session['expires_at'] . ". Please create a new tunnel session.");
}

// Build the direct tunnel URL
$tunnel_url = "http://opn.agit8or.net:" . $session['tunnel_port'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redirecting to Firewall...</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
            max-width: 500px;
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
        .firewall-info {
            background: #f7fafc;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #667eea;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            margin: 10px 0;
            font-size: 14px;
        }
        .label {
            font-weight: 600;
            color: #666;
        }
        .value {
            color: #333;
        }
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .manual-link {
            margin-top: 20px;
            padding: 15px;
            background: #edf2f7;
            border-radius: 6px;
        }
        .manual-link a {
            color: #667eea;
            font-weight: 600;
            text-decoration: none;
            font-size: 16px;
        }
        .manual-link a:hover {
            text-decoration: underline;
        }
        .note {
            font-size: 12px;
            color: #718096;
            margin-top: 15px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔒 Secure Tunnel Access</h1>
        
        <div class="firewall-info">
            <div class="info-row">
                <span class="label">Firewall:</span>
                <span class="value"><?= htmlspecialchars($session['firewall_name']) ?></span>
            </div>
            <div class="info-row">
                <span class="label">Tunnel Port:</span>
                <span class="value"><?= htmlspecialchars($session['tunnel_port']) ?></span>
            </div>
            <div class="info-row">
                <span class="label">Expires:</span>
                <span class="value"><?= date('g:i A', strtotime($session['expires_at'])) ?></span>
            </div>
        </div>

        <div class="spinner"></div>
        <p>Redirecting to firewall...</p>

        <div class="manual-link">
            <p style="margin: 0 0 10px 0; font-size: 14px; color: #666;">Not redirecting automatically?</p>
            <a href="<?= htmlspecialchars($tunnel_url) ?>" target="_blank">Click here to access firewall →</a>
        </div>

        <div class="note">
            <strong>Note:</strong> This is a direct connection through an encrypted SSH tunnel. 
            Your browser connects directly to port <?= $session['tunnel_port'] ?> which tunnels to the firewall's management interface.
            No intermediate proxy or cookie manipulation.
        </div>
    </div>

    <script>
        // Auto-redirect after 2 seconds
        setTimeout(function() {
            window.location.href = '<?= $tunnel_url ?>';
        }, 2000);
    </script>
</body>
</html>
