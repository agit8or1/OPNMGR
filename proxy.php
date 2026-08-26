<?php
/**
 * Firewall Proxy Handler
 * Queues requests for firewall and waits for responses
 */

// bootstrap.php (not bootstrap_agent.php): this endpoint is driven by a signed-in
// operator, not by an agent, so it needs the session and auth helpers.
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/logging.php';

// This queues arbitrary HTTP requests into a managed firewall's own web UI over
// the tunnel. It had no authentication at all, and defaulted to a hardcoded
// firewall id when none was supplied.
requireLogin();

$firewall_id = (int)($_GET['fw_id'] ?? 0);
if ($firewall_id <= 0) {
    http_response_code(400);
    echo 'Missing fw_id';
    exit;
}

// Confirm the target exists before anything is queued against it.
$fw_stmt = db()->prepare('SELECT id, hostname FROM firewalls WHERE id = ?');
$fw_stmt->execute([$firewall_id]);
$fw_row = $fw_stmt->fetch(PDO::FETCH_ASSOC);
if (!$fw_row) {
    http_response_code(404);
    echo 'Firewall not found';
    exit;
}

audit_log('proxy.request', [
    'object_type' => 'firewall',
    'object_id'   => (string)$firewall_id,
    'firewall_id' => $firewall_id,
    'message'     => 'Proxied request into firewall web UI: ' . ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
]);
$path = $_SERVER['REQUEST_URI'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Log the proxy attempt
log_event('INFO', 'proxy', "Proxy request initiated: $method $path", $_SESSION['user_id'] ?? null, $firewall_id, [
    'method' => $method,
    'path' => $path,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
]);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Remove proxy script from path
$path = preg_replace('/\/proxy\.php.*$/', '/', $path);
if ($path === '') $path = '/';

$headers = [];
if (function_exists('getallheaders')) {
    $headers = getallheaders();
} else {
    // Fallback for environments where getallheaders() doesn't exist
    foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) == 'HTTP_') {
            $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
        }
    }
}
$body = file_get_contents('php://input');

// Generate unique client ID
$client_id = uniqid('client_', true);

// Queue the request
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'http://localhost/api/request_queue.php?action=queue_request',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'firewall_id' => $firewall_id,
        'client_id' => $client_id,
        'method' => $method,
        'path' => $path,
        'headers' => json_encode($headers),
        'body' => $body
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5
]);

$queue_result = curl_exec($ch);
$queue_response = json_decode($queue_result, true);
curl_close($ch);

if (!$queue_response || !$queue_response['success']) {
    log_event('ERROR', 'proxy', "Failed to queue proxy request: $method $path", $_SESSION['user_id'] ?? null, $firewall_id, [
        'error' => $queue_response['error'] ?? 'Unknown error',
        'method' => $method,
        'path' => $path
    ]);
    http_response_code(500);
    echo "Failed to queue request";
    exit;
}

log_event('DEBUG', 'proxy', "Proxy request queued successfully", $_SESSION['user_id'] ?? null, $firewall_id, [
    'client_id' => $client_id,
    'method' => $method,
    'path' => $path
]);

// Wait for response (with timeout)
$max_wait = 30; // 30 seconds
$check_interval = 1; // Check every 1 second
$waited = 0;

while ($waited < $max_wait) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "http://localhost/api/request_queue.php?action=get_response&client_id=" . urlencode($client_id),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3
    ]);
    
    $response_result = curl_exec($ch);
    $response_data = json_decode($response_result, true);
    curl_close($ch);
    
    if ($response_data && $response_data['status'] === 'completed') {
        // Send response back to client
        http_response_code($response_data['response_status']);
        
        $response_headers = json_decode($response_data['response_headers'], true);
        if ($response_headers) {
            foreach ($response_headers as $name => $value) {
                if (strtolower($name) !== 'content-length') {
                    header("$name: $value");
                }
            }
        }
        
        echo $response_data['response_body'];
        exit;
    }
    
    sleep($check_interval);
    $waited += $check_interval;
}

// Timeout
log_event('ERROR', 'proxy', "Proxy timeout after {$max_wait}s: $method $path", $_SESSION['user_id'] ?? null, $firewall_id, [
    'client_id' => $client_id,
    'method' => $method,
    'path' => $path,
    'waited_seconds' => $waited,
    'max_wait' => $max_wait
]);

http_response_code(504);
echo "Gateway timeout - firewall did not respond";
?>