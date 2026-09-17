<?php
/**
 * List the models a configured provider currently serves.
 *
 * The settings page used to offer a hardcoded list of model ids - gpt-4,
 * claude-3-opus-20240229, gemini-pro - which were the right answer when they
 * were written and quietly stopped being it. A self-hosted product cannot ship a
 * catalogue that stays current, so ask the provider instead: it always knows
 * what it serves today, and the answer costs one request.
 *
 * Providers that do not expose a listing endpoint return a clear reason rather
 * than an empty list, so the interface can say why and fall back to free text.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/secrets.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
if (!function_exists('can') || !can('settings.manage')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not permitted']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$csrf  = $input['csrf'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!csrf_verify($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$provider = strtolower(trim((string)($input['provider'] ?? '')));
if (!preg_match('/^[a-z]+$/', $provider)) {
    echo json_encode(['success' => false, 'error' => 'Unknown provider']);
    exit;
}

// Use the stored key for this provider. The key is never returned to the
// browser - only the model names it unlocks.
$stmt = db()->prepare('SELECT api_key FROM ai_settings WHERE provider = ? LIMIT 1');
$stmt->execute([$provider]);
$stored = $stmt->fetchColumn();
$api_key = $stored ? (string) (opnmgr_decrypt((string)$stored) ?? '') : '';

/** @return array{ok:bool,models?:array<int,string>,error?:string} */
function fetch_models(string $provider, string $api_key): array
{
    $endpoints = [
        'openai'    => ['url' => 'https://api.openai.com/v1/models',
                        'headers' => ['Authorization: Bearer ' . $api_key]],
        'anthropic' => ['url' => 'https://api.anthropic.com/v1/models',
                        'headers' => ['x-api-key: ' . $api_key, 'anthropic-version: 2023-06-01']],
        'ollama'    => ['url' => rtrim(getenv('OLLAMA_HOST') ?: 'http://127.0.0.1:11434', '/') . '/api/tags',
                        'headers' => []],
    ];

    if (!isset($endpoints[$provider])) {
        return ['ok' => false, 'error' => 'This provider does not publish a model list. Enter the model id directly.'];
    }
    if ($provider !== 'ollama' && $api_key === '') {
        return ['ok' => false, 'error' => 'Save an API key for this provider first, then fetch.'];
    }

    $ch = curl_init($endpoints[$provider]['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $endpoints[$provider]['headers'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => 'Could not reach the provider: ' . $err];
    }
    if ($code !== 200) {
        $decoded = json_decode((string)$body, true);
        $message = $decoded['error']['message'] ?? "HTTP {$code}";
        return ['ok' => false, 'error' => 'Provider refused: ' . substr((string)$message, 0, 200)];
    }

    $data   = json_decode((string)$body, true);
    $models = [];

    // Each provider names the list differently; take the ids and sort them.
    foreach (['data', 'models'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            foreach ($data[$key] as $entry) {
                $id = $entry['id'] ?? $entry['name'] ?? $entry['model'] ?? null;
                if (is_string($id) && $id !== '') {
                    $models[] = $id;
                }
            }
        }
    }

    $models = array_values(array_unique($models));
    sort($models, SORT_NATURAL | SORT_FLAG_CASE);

    if (!$models) {
        return ['ok' => false, 'error' => 'The provider returned no models.'];
    }
    return ['ok' => true, 'models' => $models];
}

$result = fetch_models($provider, $api_key);
echo json_encode($result['ok']
    ? ['success' => true, 'models' => $result['models'], 'count' => count($result['models'])]
    : ['success' => false, 'error' => $result['error']]);
