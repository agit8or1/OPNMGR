<?php
/**
 * On-Demand Secure Tunnel Proxy for Firewall Access
 * Creates a direct SSH tunnel via start_tunnel_async.php, then routes through tunnel_proxy.php
 */

require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();
requireAdmin();

// Get firewall ID
$firewall_id = (int)($_GET['id'] ?? 0);

if (!$firewall_id) {
    http_response_code(400);
    die('Missing firewall ID');
}

// Verify firewall exists
$stmt = db()->prepare('SELECT id, hostname FROM firewalls WHERE id = ?');
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$firewall) {
    http_response_code(404);
    die('Firewall not found');
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Connecting to <?php echo htmlspecialchars($firewall['hostname']); ?></title>
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 0;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .connection-box {
            max-width: 600px;
            background: rgba(255, 255, 255, 0.95);
            color: #333;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
        }
        h1 {
            color: #667eea;
            margin-bottom: 20px;
            font-size: 28px;
        }
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            animation: spin 1s linear infinite;
            margin: 30px auto;
        }
        .spinner.hidden { display: none; }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .status {
            font-size: 18px;
            margin: 20px 0;
            line-height: 1.6;
        }
        .technical {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 25px;
            font-size: 13px;
            color: #666;
            text-align: left;
        }
        .success {
            color: #28a745;
            font-weight: bold;
        }
        .error {
            color: #dc3545;
            font-weight: bold;
        }
        .dot { animation: blink 1.5s infinite; }
        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0; }
        }
    </style>
</head>
<body>
    <div class="connection-box">
        <h1>&#x1f512; Establishing Secure Connection</h1>
        <div class="spinner" id="spinner"></div>
        <div class="status">
            <div id="statusText">Creating SSH tunnel<span class="dot">.</span><span class="dot">.</span><span class="dot">.</span></div>
        </div>
        <div class="technical">
            <strong>Connection Details:</strong><br>
            Firewall: <?php echo htmlspecialchars($firewall['hostname']); ?><br>
            Status: <span id="tunnelStatus">Initializing</span>
        </div>
    </div>

    <script>
        const firewallId = <?php echo $firewall_id; ?>;

        function startTunnel() {
            document.getElementById('tunnelStatus').textContent = 'Creating tunnel...';

            const formData = new FormData();
            formData.append('firewall_id', firewallId);

            fetch('/start_tunnel_async.php', {
                method: 'POST',
                credentials: 'include',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.url) {
                    document.getElementById('statusText').innerHTML = '<span class="success">&#x2713; Tunnel established! Connecting...</span>';
                    document.getElementById('tunnelStatus').textContent = 'Connected - Session ' + (data.session_id || 'active');
                    document.getElementById('spinner').classList.add('hidden');

                    // Redirect to the tunnel proxy URL
                    setTimeout(() => {
                        window.location.href = data.url;
                    }, 1000);
                } else {
                    document.getElementById('statusText').innerHTML = '<span class="error">&#x2717; ' + (data.error || 'Connection failed') + '</span>';
                    document.getElementById('tunnelStatus').textContent = 'Failed';
                    document.getElementById('spinner').classList.add('hidden');

                    // Return to firewall details after 5 seconds
                    setTimeout(() => {
                        window.location.href = '/firewall_details.php?id=' + firewallId;
                    }, 5000);
                }
            })
            .catch(err => {
                console.error('Tunnel creation error:', err);
                document.getElementById('statusText').innerHTML = '<span class="error">&#x2717; Network error - please try again</span>';
                document.getElementById('tunnelStatus').textContent = 'Error';
                document.getElementById('spinner').classList.add('hidden');

                setTimeout(() => {
                    window.location.href = '/firewall_details.php?id=' + firewallId;
                }, 5000);
            });
        }

        // Start tunnel creation immediately
        startTunnel();
    </script>
</body>
</html>
