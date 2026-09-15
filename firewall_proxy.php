<?php
/**
 * On-Demand HTTP Proxy for Firewall Access
 *
 * Routes firewall HTTP requests through the agent's request_queue table, so
 * no dedicated port per firewall is needed.
 *
 * The header comment used to contain a pasted copy of the polling and
 * response-forwarding block, spliced into this sentence mid-word. That copy
 * was inert (it sat inside the comment) but carried the same wrong column
 * names as the live code below, so anyone 'restoring' it would have
 * reintroduced the bug. It is gone; the live implementation follows.
 */

require_once __DIR__ . '/inc/bootstrap.php';
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
$stmt = db()->prepare('SELECT id, hostname FROM firewalls WHERE id = ?');
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
$stmt = db()->prepare('
    INSERT INTO request_queue (firewall_id, client_id, method, path, headers, body, status, created_at)
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

$request_id = db()->lastInsertId();
log_info('proxy', "Request queued (ID: $request_id, client: $client_id)");

// Poll for response (max 60 seconds)
$max_wait = 60;
$start_time = time();
$response = null;

while ((time() - $start_time) < $max_wait) {
    $stmt = db()->prepare('SELECT status, response_status, response_headers, response_body FROM request_queue WHERE id = ?');
    $stmt->execute([$request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($request['status'] === 'completed') {
        $response = $request;
        log_info('proxy', "Request completed: $method $path ({$request['response_status']})");
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

// Forward response to client. Default to 502 rather than letting (int)null
// produce 0: http_response_code(0) is a silent no-op, so a missing status
// would have been served to the browser as 200.
http_response_code((int)($response['response_status'] ?: 502));

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
db()->exec("DELETE FROM request_queue WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
