<?php
/**
 * Send a test Pushover notification.
 *
 * Backs the "Send Test Push" button in alerts.php. That button has been calling
 * this path since the Pushover settings were added; the file was never written,
 * so the fetch 404'd and the operator got a JSON parse error rather than an
 * answer about whether their token works.
 *
 * @since 3.27.0
 */

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$csrf = $input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (function_exists('csrf_verify') && !csrf_verify($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF validation failed']);
    exit;
}

$userKey = trim((string) ($input['test_user_key'] ?? ''));
if (!preg_match('/^[a-zA-Z0-9]{30}$/', $userKey)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'A Pushover user key is 30 alphanumeric characters']);
    exit;
}

// The application token is configuration, not something the caller supplies.
try {
    $stmt = db()->prepare("SELECT setting_value FROM alert_settings WHERE setting_name = 'pushover_api_token'");
    $stmt->execute();
    $token = trim((string) $stmt->fetchColumn());
} catch (Throwable $e) {
    error_log('test_pushover.php could not read the token: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not read the Pushover settings']);
    exit;
}

if ($token === '') {
    echo json_encode([
        'success' => false,
        'error'   => 'No Pushover application token is saved yet. Save one first, then send a test.',
    ]);
    exit;
}

$post = http_build_query([
    'token'   => $token,
    'user'    => $userKey,
    'title'   => 'OPNManager test notification',
    'message' => 'If you can read this, Pushover alerts are configured correctly.',
    'priority' => 0,
]);

$ch = curl_init('https://api.pushover.net/1/messages.json');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $post,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$body   = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($body === false) {
    error_log('test_pushover.php transport error: ' . $curlErr);
    echo json_encode(['success' => false, 'error' => 'Could not reach api.pushover.net']);
    exit;
}

$decoded = json_decode((string) $body, true);

if ($status === 200 && (int) ($decoded['status'] ?? 0) === 1) {
    echo json_encode(['success' => true, 'message' => 'Check your Pushover app.']);
    exit;
}

// Pushover returns the specific complaint in `errors`; pass it through, since
// "invalid token" and "invalid user key" need different fixes.
$errors = $decoded['errors'] ?? null;
echo json_encode([
    'success' => false,
    'error'   => is_array($errors) && $errors
        ? implode('; ', array_map('strval', $errors))
        : "Pushover returned HTTP {$status}",
]);
