<?php
/**
 * On-Demand Reverse Tunnel Proxy for Firewall Access
 * Creates proxy request, waits for agent to establish tunnel, routes traffic
 */

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';
requireLogin();
requireAdmin();

// Get firewall ID
$firewall_id = (int)($_GET['id'] ?? 0);

if (!$firewall_id) {
    http_response_code(400);
    die('Missing firewall ID');
}

// Verify firewall exists
$stmt = $DB->prepare('SELECT id, hostname FROM firewalls WHERE id = ?');
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$firewall) {
    http_response_code(404);
    die('Firewall not found');
}

// Generate unique client ID
$client_id = 'tunnel_' . uniqid() . '_' . mt_rand(1000, 9999);

// Assign a tunnel port (8100-8200 range - matches firewall rules)
$tunnel_port = assignTunnelPort();

if (!$tunnel_port) {
    http_response_code(503);
    die('No available tunnel ports (all 101 ports in use)');
}

// Insert proxy request into queue
$stmt = $DB->prepare('
    INSERT INTO request_queue (firewall_id, tunnel_port, client_id, method, path, status, created_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
');
$stmt->execute([$firewall_id, $tunnel_port, $client_id, 'TUNNEL', '/', 'pending']);
$request_id = $DB->lastInsertId();

// Log request creation
$details = json_encode([
    'request_id' => $request_id,
    'tunnel_port' => $tunnel_port,
    'client_id' => $client_id
]);
$DB->prepare('INSERT INTO system_logs (category, message, additional_data, firewall_id, level, timestamp) VALUES (?, ?, ?, ?, ?, NOW())')
   ->execute(['proxy', "On-demand tunnel requested: Port $tunnel_port", $details, $firewall_id, 'INFO']);

// Wait for agent to establish tunnel (max 30 seconds)
$max_wait = 30;
$start_time = time();
$tunnel_ready = false;

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
        <h1>🔐 Establishing Secure Connection</h1>
        <div class="spinner"></div>
        <div class="status">
            <div id="statusText">Requesting tunnel from agent<span class="dot">.</span><span class="dot">.</span><span class="dot">.</span></div>
        </div>
        <div class="technical">
            <strong>Connection Details:</strong><br>
            Firewall: <?php echo htmlspecialchars($firewall['hostname']); ?><br>
            Request ID: <?php echo $request_id; ?><br>
            Tunnel Port: <?php echo $tunnel_port; ?><br>
            Status: <span id="tunnelStatus">Pending</span>
        </div>
    </div>
    
    <script>
        let checkCount = 0;
        const maxChecks = <?php echo $max_wait; ?>;
        
        function checkTunnelStatus() {
            checkCount++;
            
            fetch('/check_tunnel_status.php?request_id=<?php echo $request_id; ?>')
                .then(res => res.json())
                .then(data => {
                    document.getElementById('tunnelStatus').textContent = data.status;
                    
                    if (data.status === 'processing') {
                        document.getElementById('statusText').innerHTML = '<span class="success">✓ Tunnel established! Connecting...</span>';
                        // Redirect to the firewall through the tunnel
                        setTimeout(() => {
                            window.location.href = 'https://opn.agit8or.net:<?php echo $tunnel_port; ?>/firewall/<?php echo $firewall_id; ?>/';
                        }, 1000);
                    } else if (data.status === 'failed' || data.status === 'timeout') {
                        document.getElementById('statusText').innerHTML = '<span class="error">✗ Connection failed</span>';
                        setTimeout(() => window.location.href = '/firewall_details.php?id=<?php echo $firewall_id; ?>', 3000);
                    } else if (checkCount >= maxChecks) {
                        document.getElementById('statusText').innerHTML = '<span class="error">✗ Timeout - Agent not responding</span>';
                        setTimeout(() => window.location.href = '/firewall_details.php?id=<?php echo $firewall_id; ?>', 3000);
                    } else {
                        // Keep checking
                        setTimeout(checkTunnelStatus, 1000);
                    }
                })
                .catch(err => {
                    console.error('Check failed:', err);
                    if (checkCount < maxChecks) {
                        setTimeout(checkTunnelStatus, 1000);
                    }
                });
        }
        
        // Start checking after 2 seconds
        setTimeout(checkTunnelStatus, 2000);
    </script>
</body>
</html>
<?php

function assignTunnelPort() {
    global $DB;
    
    // Find an available port in range 8100-8200 (matches firewall rules)
    for ($port = 8100; $port <= 8200; $port++) {
        // Check if port is in use (active within last 5 minutes)
        $stmt = $DB->prepare('
            SELECT COUNT(*) as count 
            FROM request_queue 
            WHERE tunnel_port = ? 
            AND status IN ("pending", "processing")
            AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ');
        $stmt->execute([$port]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] == 0) {
            return $port;
        }
    }
    
    return null; // All ports in use
}
