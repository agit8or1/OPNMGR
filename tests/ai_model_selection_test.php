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
check('the modal select only fills the free-text field',
    (bool) preg_match('/<select id="model_select"(?![^>]*\sname=)/', $page),
    'in the Add Provider modal the catalogue is a suggestion, not the submitted value');

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

// --- which LLM runs must be a choice, not an accident -------------------------
//
// is_active defaults to 1 in the schema and the insert did not override it, so
// adding a second provider quietly made it active alongside the first. The scan
// then took `WHERE is_active = TRUE LIMIT 1` with no ordering, and which LLM ran
// was whatever the database returned first. The only way to choose was a "Set as
// Default" button on whichever provider card you happened to scroll to.

$scan = (string) @file_get_contents($root . '/api/ai_scan.php');

check('a new provider does not silently become the active one',
    str_contains($page, "INSERT INTO ai_settings (provider, api_key, model, is_active)"),
    'the column defaults to 1, so the insert has to say otherwise');
check('the first provider configured does become active',
    str_contains($page, '$existing === 0 ? 1 : 0'),
    'otherwise nothing would be selected and no scan could run');
check('adding a later provider says it is not in use yet',
    str_contains($page, 'Select it above to use it for analysis'));

check('there is one explicit selector for the LLM in use',
    str_contains($page, 'LLM used for analysis:') && str_contains($page, "id=\"active_llm\""));
check('the model is a control of its own, not part of the provider label',
    str_contains($page, 'id="active_model"')
    && !preg_match('/id="active_llm"[\s\S]{0,600}&mdash;/', $page),
    'two providers can differ only by model, and the model must be changeable here');
check('it submits through the existing default-provider handler',
    (bool) preg_match('/active_llm[\s\S]{0,2000}name="set_default_provider"/', $page),
    'that handler already deactivates the others first');

check('the scan picks deterministically',
    str_contains($scan, 'WHERE is_active = TRUE ORDER BY id LIMIT 1'),
    'silently alternating between two LLMs is worse than picking the wrong one');

// --- choosing a provider and choosing its model are two controls --------------
//
// The page showed one dropdown. It listed configured rows as "OpenAI - gpt-4-turbo",
// so with a single provider configured it had a single option, and the model was
// baked into that option's label: changing which model ran meant opening the Edit
// modal. Provider and model are now two selects side by side.

check('the model select sits beside the provider select',
    str_contains($page, 'id="active_model"') && str_contains($page, 'name="model"'));
check('the provider option no longer carries the model in its label',
    !preg_match('/id="active_llm"[\s\S]{0,600}&mdash;[\s\S]{0,120}\$p\[.model.\]/', $page),
    'a provider list that embeds the model cannot offer a second model for it');
check('changing the provider refills the model list',
    str_contains($page, 'onchange="onActiveProviderChanged(this)"')
    && str_contains($page, 'function onActiveProviderChanged'));
check('the list is filled on load, not only on change',
    (bool) preg_match('/DOMContentLoaded[\s\S]{0,300}onActiveProviderChanged/', $page),
    'otherwise the dropdown is empty until the provider is touched');
check('the configured rows reach the script',
    str_contains($page, 'const configuredProviders'));
check('the model in use is listed even when the catalogue lacks it',
    (bool) preg_match("/add\(row\.model, row\.model \+ '  - in use'\)/", $page),
    'a list claiming to show what is in use must contain what is in use');
check('the submit persists the chosen model',
    (bool) preg_match('/set_default_provider[\s\S]{0,900}UPDATE ai_settings SET model = \? WHERE id = \?/', $page),
    'a dropdown that does not save is worse than no dropdown');
check('an empty model does not blank the stored one',
    (bool) preg_match('/if \(\$model !== \'\'\) \{/', $page));

// --- the curated list must not be the stale list it warns about ---------------
//
// The comment above the catalogue explains that a hardcoded list goes stale, and
// named gpt-4 as the example. Anthropic's entry was refreshed; OpenAI's and
// Google's were left as the exact lists the comment complains about.

foreach (['openai' => 'gpt-4o', 'anthropic' => 'claude-opus-5', 'google' => 'gemini-2.5-pro'] as $prov => $model) {
    check("the {$prov} catalogue offers a current model ({$model})", str_contains($page, $model));
}
check('every provider with suggestions marks a default choice',
    (function () use ($page): bool {
        foreach (['openai', 'anthropic', 'google'] as $prov) {
            if (!preg_match("/'{$prov}' => \[[\s\S]{0,1400}?'hint'/", $page, $m)) { return false; }
            if (!str_contains($m[0], "'suggested' => true")) { return false; }
        }
        return true;
    })(),
    'a list of five with none recommended leaves the choice unmade');

// --- the stored key must not be handed to the browser -------------------------
//
// The card comment said "the stored key is never sent to the browser" and the
// edit handler's said "the UI never echoes it back". Both were false:
// json_encode($provider) put the whole row, api_key column included, into the
// onclick of every provider card, and showEditModal() loaded that ciphertext
// into the API key field. Saving a model change then re-encrypted the
// ciphertext, and the double-wrapped result decrypts to an enc:v1: blob that
// authenticates as nothing - the same class of failure as the 8,497 SMTP
// rejections. The field was also `required`, so the documented "leave blank to
// keep the stored key" path could not be reached through the UI at all.

check('the row handed to the edit modal has its secret removed',
    str_contains($page, "unset(\$provider_js['api_key'])")
    && !preg_match('/showEditModal\(<\?= json_encode\(\$provider\) \?>\)/', $page),
    'the ciphertext was in the page source of every render');
check('the edit field is not prefilled with it',
    !str_contains($page, "edit_api_key').value = provider.api_key"),
    're-encrypting the ciphertext produces a key that decrypts to a ciphertext');
check('the edit field is optional, so blank-means-keep is reachable',
    (bool) preg_match('/id="edit_api_key"(?:(?!>)[\s\S])*?placeholder="Leave blank/', $page)
    && !preg_match('/id="edit_api_key"(?:(?!>)[\s\S])*?\brequired\b/', $page),
    'the handler has always had the branch; the form made it unreachable');
check('both key fields are password inputs',
    substr_count($page, '<input type="password" name="api_key"') === 2,
    'a key in a text input is shoulder-surfable and lands in browser autofill');
check('neither offers autofill', substr_count($page, 'autocomplete="new-password"') === 2);
check('whitespace does not count as a key',
    substr_count($page, "\$api_key = trim((string) (\$_POST['api_key'] ?? ''))") === 2,
    'a field of spaces would otherwise be encrypted and stored as the key');
check('the card still shows only a masked key',
    str_contains($page, 'opnmgr_mask_secret(opnmgr_decrypt('));

// --- the catalogue must not be the only source of models ----------------------
//
// Discovery existed, but only inside the Add and Edit modals - the one place you
// are not looking when changing the model of a provider already configured. The
// dropdown beside the provider therefore offered exactly what this file knew,
// which for OpenAI was five GPT-4 era ids.

check('discovery is reachable from the main selector',
    str_contains($page, 'function fetchActiveModels')
    && str_contains($page, 'onclick="fetchActiveModels()"'));
check('it asks for the selected provider, not the modal one',
    (bool) preg_match('/fetchActiveModels[\s\S]{0,700}configuredProviders\.find/', $page));
check('it appends rather than replacing the suggestions',
    (bool) preg_match('/fetchActiveModels[\s\S]{0,2200}modelSelect\.appendChild\(group\)/', $page),
    'replacing them would drop the model currently in use from the list');
check('it does not offer a duplicate of a model already listed',
    (bool) preg_match('/fetchActiveModels[\s\S]{0,2200}known\.has\(id\)/', $page));
check('a provider with no listing endpoint says so instead of spinning',
    (bool) preg_match('/fetchActiveModels[\s\S]{0,600}discoverable === false/', $page));
check('a session that expired mid-fetch is reported as such',
    substr_count($page, 'Your session has expired. Sign in again.') === 2);

// --- the recommendation must not be a model the list itself calls old ----------
//
// The OpenAI catalogue was refreshed from memory rather than from the provider:
// gpt-4o was written in and annotated "current generation" while the account was
// serving gpt-5.5, gpt-5.6 and gpt-6-astra. A hand-written list cannot be kept
// current - that is why discovery exists - but it can at least be stopped from
// recommending something it elsewhere describes as superseded.

$catalogue = [];
if (preg_match('/\$available_providers = \[([\s\S]*?)\n\];/', $page, $m)) {
    preg_match_all("/'([a-z]+)' => \[([\s\S]*?)\n    \],/", $m[1], $blocks, PREG_SET_ORDER);
    foreach ($blocks as $b) { $catalogue[$b[1]] = $b[2]; }
}
check('the catalogue parses', count($catalogue) >= 3);

foreach ($catalogue as $prov => $body) {
    if (!str_contains($body, "'suggested' => true")) { continue; }
    if (!preg_match("/\['id' => '([^']+)',\s*'note' => '([^']*)'[^\]]*'suggested' => true/", $body, $hit)) {
        check("the {$prov} recommendation is parseable", false);
        continue;
    }
    check("the {$prov} recommendation is not described as old ({$hit[1]})",
        !preg_match('/\bolder\b|\bprevious generation\b|\bdeprecated\b/i', $hit[2]),
        'recommending an entry the same list calls superseded is the stale-catalogue bug');
    check("the {$prov} recommendation is not a superseded family ({$hit[1]})",
        !preg_match('/^(gpt-4|gpt-3|claude-3|claude-.*-4-|gemini-pro|gemini-1)/', $hit[1]),
        'these were current when written and stopped being so without the file changing');
}

check('the OpenAI hint does not claim the list is current',
    (bool) preg_match("/'openai' => \[[\s\S]*?'hint' => '([^']*)'/", $page, $h)
    && str_contains($h[1], 'snapshot'),
    'a list that cannot stay current should not imply that it is');
check('discovery is named as the authority',
    (bool) preg_match("/'openai' => \[[\s\S]*?'hint' => '[\s\S]{0,200}Fetch from provider/", $page));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
