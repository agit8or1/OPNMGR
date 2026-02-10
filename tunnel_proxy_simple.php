<?php
/**
 * MINIMAL SSH Tunnel Reverse Proxy
 * Simple passthrough proxy - no URL rewriting, no interceptors
 * Just forwards requests through SSH tunnel and returns responses
 * 
 * VERSION: 3.0.0 - Simplified minimal version
 */

require_once __DIR__ . '/inc/bootstrap_agent.php';

// Get session ID from URL
$session_id = (int)($_GET['session'] ?? 0);
if (!$session_id) {
    http_response_code(400);
    die('Missing session ID');
}

// Get session from database
$stmt = db()->prepare("SELECT * FROM ssh_access_sessions WHERE id = ? AND status = 'active'");
$stmt->execute([$session_id]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    http_response_code(404);
    die('Invalid or expired session');
}

// Update last activity
db()->prepare("UPDATE ssh_access_sessions SET last_activity = NOW() WHERE id = ?")
    ->execute([$session_id]);

// Get tunnel port
$tunnel_port = (int)$session['tunnel_port'];
$cookie_jar = "/tmp/tunnel_cookies_{$session_id}.txt";

// Get requested path from URL or use root
$path = $_GET['path'] ?? '';
$path = '/' . ltrim($path, '/');

// Delete cookies on fresh=1 (new session)
if (isset($_GET['fresh']) && $_GET['fresh'] == '1' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (file_exists($cookie_jar)) {
        @unlink($cookie_jar);
    }
}

// Build target URL
$target_url = "http://127.0.0.1:{$tunnel_port}{$path}";
if (!empty($_SERVER['QUERY_STRING'])) {
    // Forward original query string (except session and path params)
    $query = $_SERVER['QUERY_STRING'];
    $query = preg_replace('/&?session=\d+/', '', $query);
    $query = preg_replace('/&?path=[^&]*/', '', $query);
    $query = preg_replace('/&?fresh=1/', '', $query);
    $query = trim($query, '&');
    if (!empty($query)) {
        $target_url .= (strpos($path, '?') !== false ? '&' : '?') . $query;
    }
}

// Initialize curl
$ch = curl_init($target_url);

// Collect response headers
$response_headers = [];
$content_type = 'text/html';

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_CUSTOMREQUEST => $_SERVER['REQUEST_METHOD'],
    CURLOPT_POSTFIELDS => file_get_contents('php://input'),
    
    // Capture response headers
    CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$response_headers, &$content_type, $session_id, $cookie_jar) {
        $len = strlen($header);
        $header = trim($header);
        
        if (empty($header)) return $len;
        
        // Capture Content-Type
        if (stripos($header, 'content-type:') === 0) {
            $content_type = trim(substr($header, 13));
        }
        
        // Handle Set-Cookie - save to jar AND forward to browser
        if (stripos($header, 'set-cookie:') === 0) {
            $cookie_str = trim(substr($header, 11));
            
            // Parse cookie
            $parts = explode(';', $cookie_str);
            $cookie_pair = explode('=', $parts[0], 2);
            if (count($cookie_pair) == 2) {
                $name = trim($cookie_pair[0]);
                $value = trim($cookie_pair[1]);
                
                // Save to cookie jar
                $cookie_line = "127.0.0.1\tFALSE\t/\tFALSE\t0\t{$name}\t{$value}\n";
                file_put_contents($cookie_jar, $cookie_line, FILE_APPEND);
            }
            
            // Forward Set-Cookie to browser
            $response_headers[] = $header;
        }
        // Store other headers
        elseif (strpos($header, ':') !== false) {
            $response_headers[] = $header;
        }
        
        return $len;
    }
]);

// Forward request headers
$forward_headers = [];
if (isset($_SERVER['CONTENT_TYPE'])) {
    $forward_headers[] = "Content-Type: " . $_SERVER['CONTENT_TYPE'];
}
if (isset($_SERVER['HTTP_USER_AGENT'])) {
    $forward_headers[] = "User-Agent: " . $_SERVER['HTTP_USER_AGENT'];
}
if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $forward_headers[] = "X-Requested-With: " . $_SERVER['HTTP_X_REQUESTED_WITH'];
}

// Forward cookies from jar
if (file_exists($cookie_jar)) {
    $cookie_lines = file($cookie_jar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $cookies = [];
    foreach ($cookie_lines as $line) {
        if (empty($line) || $line[0] === '#') continue;
        $parts = preg_split('/\s+/', $line);
        if (count($parts) >= 7) {
            $cookies[] = "{$parts[5]}={$parts[6]}";
        }
    }
    if (!empty($cookies)) {
        $forward_headers[] = "Cookie: " . implode('; ', $cookies);
    }
}

curl_setopt($ch, CURLOPT_HTTPHEADER, $forward_headers);

// Execute request
$response_body = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Set HTTP response code
http_response_code($http_code);

// Forward response headers to browser
foreach ($response_headers as $header) {
    $header_lower = strtolower($header);
    
    // Skip headers that curl handles or we set ourselves
    if (stripos($header_lower, 'content-encoding:') === 0) continue;
    if (stripos($header_lower, 'content-length:') === 0) continue;
    if (stripos($header_lower, 'transfer-encoding:') === 0) continue;
    
    header($header, false);
}

// Set correct Content-Type and length
header("Content-Type: {$content_type}");
header('Content-Length: ' . strlen($response_body));

// Output response
echo $response_body;
