<?php
/**
 * Choosing a model must not depend on this file being up to date.
 *
 * Run with: php tests/ai_model_selection_test.php
 *
 * The settings page offered a hardcoded list - gpt-4, claude-3-opus-20240229,
 * gemini-pro - which were the right answer when they were written and quietly
 * stopped being it, with no way to pick anything else. A self-hosted product
 * cannot ship a catalogue that stays current, so there are three routes now:
 * curated suggestions, live discovery from the provider's own API, and free
 * text. Only the first can go stale, and it is no longer the only option.
 */

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) { $passed++; return; }
    $failed++;
    echo "FAIL: {$what}\n";
    if ($detail !== '') { echo "      {$detail}\n"; }
}

$root  = dirname(__DIR__);
$page  = (string) @file_get_contents($root . '/ai_settings.php');
$api   = (string) @file_get_contents($root . '/api/ai_models.php');

check('ai_settings.php is readable', $page !== '');
check('api/ai_models.php exists', $api !== '');

// --- free text is always available -------------------------------------------

check('the model can be typed', str_contains($page, 'name="model" id="model_input"'),
    'a release on the provider side must not need a release here');
check('the select only fills the field', str_contains($page, 'function onModelPicked'),
    'the catalogue is a suggestion, not a constraint');
check('the select is not the submitted field',
    !preg_match('/<select name="model"/', $page));

// --- suggestions carry a reason ----------------------------------------------

check('entries carry a note', str_contains($page, "'note' =>"),
    'a bare list of ids does not help anyone choose');
check('one entry is marked recommended', str_contains($page, "'suggested' => true"));
check('providers carry a hint', str_contains($page, "'hint' =>"));
check('Azure explains that it wants a deployment name',
    str_contains($page, 'deployment name'),
    'Azure model ids are whatever the operator called the deployment');

// --- discovery ----------------------------------------------------------------

check('providers declare whether they can be queried', str_contains($page, "'discoverable' =>"));
check('the page can fetch from the provider', str_contains($page, 'function fetchModels'));
check('a provider without a list disables the button',
    str_contains($page, 'fetchBtn.disabled = !entry.discoverable'));

check('discovery asks the provider directly',
    str_contains($api, 'api.openai.com/v1/models') && str_contains($api, 'api.anthropic.com/v1/models'),
    'the provider always knows what it serves today');
check('a local Ollama is supported without a key',
    str_contains($api, '/api/tags') && str_contains($api, "provider !== 'ollama'"));

// --- the key must not leak ----------------------------------------------------

check('discovery uses the stored key rather than one from the browser',
    str_contains($api, 'SELECT api_key FROM ai_settings') && !str_contains($api, "input['api_key']"),
    'the browser should never need to hold the key to list models');
check('the key is decrypted server side', str_contains($api, 'opnmgr_decrypt('));
check('only model names are returned',
    !preg_match('/echo json_encode\([^)]*api_key/', $api));

// --- and the endpoint is guarded ---------------------------------------------

check('it requires a session', str_contains($api, 'isLoggedIn()'));
check('it requires settings permission', str_contains($api, "can('settings.manage')"));
check('it verifies CSRF', str_contains($api, 'csrf_verify('));
check('the provider name is constrained',
    str_contains($api, "preg_match('/^[a-z]+$/', \$provider)"),
    'it selects an endpoint, so it must not be free-form');

// --- failure must explain itself ---------------------------------------------

check('a provider without a listing endpoint says so',
    str_contains($api, 'does not publish a model list'));
check('a missing key says so', str_contains($api, 'Save an API key for this provider first'));
check('a refusal reports the provider message', str_contains($api, 'Provider refused: '));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
