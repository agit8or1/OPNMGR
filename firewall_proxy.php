<?php
/**
 * On-Demand HTTP Proxy for Firewall Access
 * Routes ALL firewall HTTP requeif (!$response) {
    log_error('proxy', "Request timeout: $method $path (waited ${max_wait}s) - Agent not processing requests", null, $firewall_id);
    http_response_code(503);
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Connection Timeout</title>
        <style>
            body { font-family: Arial; background: #1a1a1a; color: #fff; padding: 40px; text-align: center; }
            .error-box { max-width: 600px; margin: 0 auto; background: #2a2a2a; padding: 30px; border-radius: 10px; border: 2px solid #dc3545; }
            h1 { color: #dc3545; margin-bottom: 20px; }
            .icon { font-size: 64px; margin-bottom: 20px; }
            .message { margin: 20px 0; line-height: 1.6; }
            .technical { background: #1a1a1a; padding: 15px; border-radius: 5px; margin-top: 20px; font-size: 12px; color: #888; }
            .btn { display: inline-block; background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-top: 20px; }
            .btn:hover { background: #0056b3; }
        </style>
    </head>
    <body>
        <div class="error-box">
            <div class="icon">⏱️</div>
            <h1>Connection Timeout</h1>
            <div class="message">
                <p><strong>The firewall agent did not respond within 60 seconds.</strong></p>
                <p>This means the agent is not processing proxy requests yet.</p>
                <h3>Current Status:</h3>
                <ul style="text-align: left; display: inline-block;">
                    <li>✅ Agent is checking in (v2.4.0)</li>
                    <li>✅ Firewall is online</li>
                    <li>❌ Agent doesn't support HTTP proxy yet</li>
                </ul>
                <p style="margin-top: 20px;">The request_queue system is ready, but the agent needs to be updated to poll and process queued requests.</p>
            </div>
            <div class="technical">
                <strong>Technical Details:</strong><br>
                Request ID: <?php echo $request_id; ?><br>
                Client ID: <?php echo $client_id; ?><br>
                Firewall: <?php echo htmlspecialchars($firewall['hostname']); ?> (ID: <?php echo $firewall_id; ?>)<br>
                Timeout: <?php echo $max_wait; ?> seconds
            </div>
            <a href="/firewall_details.php?id=<?php echo $firewall_id; ?>" class="btn">← Back to Firewall Details</a>
        </div>
        <script>
            // Auto-close after 10 seconds
            setTimeout(() => {
                if (window.opener) {
                    window.close();
                } else {
                    window.location.href = '/firewall_details.php?id=<?php echo $firewall_id; ?>';
                }
            }, 10000);
        </script>
    </body>
    </html>
    <?php
    exit;
}

// Log successful response
log_info('proxy', "Response received: $method $path - Status: {$response['status_code']}, Size: " . strlen($response['response_body']) . " bytes", null, $firewall_id);

// Forward response to clientough agent's request_queue system
 * Scales to unlimited firewalls (no dedicated ports needed)
 */

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';
requireLogin();
requireAdmin();

// Get firewall ID and path
$firewall_id = (int)($_GET['fw_id'] ?? 0);
$path = $_GET['path'] ?? '/';

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

// Generate unique client ID for this request
$client_id = 'proxy_' . uniqid() . '_' . mt_rand(1000, 9999);

// Get request details
$method = $_SERVER['REQUEST_METHOD'];
$headers = [];
foreach (getallheaders() as $key => $value) {
    if (!in_array(strtolower($key), ['host', 'connection', 'content-length'])) {
        $headers[$key] = $value;
    }
}
$body = file_get_contents('php://input');

// Log request
log_info('proxy', "Proxy request initiated: $method $path (firewall_id=$firewall_id, client=$client_id)");

// Insert into request queue
$stmt = $DB->prepare('
    INSERT INTO request_queue (firewall_id, client_id, method, path, headers, request_body, status, created_at)
    VALUES (?, ?, ?, ?, ?, ?, "pending", NOW())
');
$stmt->execute([
    $firewall_id,
    $client_id,
    $method,
    $path,
    json_encode($headers),
    $body
]);

$request_id = $DB->lastInsertId();
log_info('proxy', "Request queued (ID: $request_id, client: $client_id)");

// Poll for response (max 60 seconds)
$max_wait = 60;
$start_time = time();
$response = null;

while ((time() - $start_time) < $max_wait) {
    $stmt = $DB->prepare('SELECT status, status_code, response_headers, response_body FROM request_queue WHERE id = ?');
    $stmt->execute([$request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($request['status'] === 'completed') {
        $response = $request;
        log_info('proxy', "Request completed: $method $path ({$request['status_code']})");
        break;
    } elseif ($request['status'] === 'failed') {
        log_error('proxy', "Request failed: $method $path - {$request['response_body']}");
        http_response_code(502);
        die("Proxy error: " . $request['response_body']);
    }
    
    usleep(500000); // Wait 0.5 seconds
}

if (!$response) {
    log_error('proxy', "Request timeout: $method $path (waited ${max_wait}s)");
    http_response_code(504);
    die("Timeout: Agent did not respond within ${max_wait} seconds. Agent may be offline.");
}

// Forward response to client
http_response_code((int)$response['status_code']);

// Set response headers
if ($response['response_headers']) {
    $response_headers = json_decode($response['response_headers'], true);
    if ($response_headers) {
        foreach ($response_headers as $key => $value) {
            header("$key: $value");
        }
    }
}

// Output response body
echo $response['response_body'];

// Clean up old requests (>1 hour)
$DB->exec("DELETE FROM request_queue WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
