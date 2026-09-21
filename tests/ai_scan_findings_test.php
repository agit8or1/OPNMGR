<?php
/**
 * A security scan must not delete the findings that matter.
 *
 * Run with: php tests/ai_scan_findings_test.php
 *
 * The scan post-processed the model's output through a keyword filter before
 * storing it. The filter matched substrings against the JSON of each finding,
 * and the list contained 'log' and 'nat' - so "Alternate gateway lacks failover
 * monitoring" and "Designated management VLAN is not isolated" were both deleted
 * for containing 'nat', and anything mentioning 'login' or 'technology' went the
 * same way.
 *
 * Worse, the SSH and web-GUI patterns matched the dangerous case rather than the
 * safe one:
 *
 *   'ssh.*0\.0\.0\.0'    ->  "SSH is exposed to 0.0.0.0/0 on the WAN"
 *   'ssh.*unrestricted'  ->  "SSH is unrestricted and reachable from the internet"
 *   'web interface.*http'->  "Web interface exposed over plain HTTP to the internet"
 *
 * Each of those is a finding the prompt explicitly instructs the model to raise
 * as CRITICAL, and each was removed before it reached the report. A firewall
 * with SSH open to the world scanned clean.
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

$root = dirname(__DIR__);
$raw  = (string) @file_get_contents($root . '/api/ai_scan.php');

// Code only. The fix documents the strings it removed - including the literal
// "DO NOT MENTION LOGS AT ALL" - so a "must not contain" assertion against the
// raw file fails on the explanation rather than on the code.
$src = implode("\n", array_filter(explode("\n", $raw), static function (string $l): bool {
    $t = ltrim($l);
    return $t !== '' && !str_starts_with($t, '//') && !str_starts_with($t, '*')
        && !str_starts_with($t, '/*') && !str_starts_with($t, '#');
}));

check('api/ai_scan.php is readable', $src !== '');

// --- the filter must not eat real findings -----------------------------------

check('the bare noun keywords are gone',
    !preg_match("/'log',\s*'logging'/", $src) && !preg_match("/'nat',\s*'port forward'/", $src),
    "'log' and 'nat' as substrings cannot distinguish a finding from a word");

check('matching is on word boundaries',
    str_contains($src, 'preg_quote($keyword') && str_contains($src, '\\b'),
    'stripos() over the whole finding is what matched "nat" inside "Alternate"');
check('the substring search is gone',
    !preg_match('/stripos\(\$finding_text, \$keyword\)/', $src));

check('the inverted SSH patterns are removed',
    (bool) preg_match('/\$ssh_false_positive_patterns = \[\];/', $src),
    'they matched SSH exposed to the internet, the single most important finding');
check('the inverted web GUI patterns are removed',
    (bool) preg_match('/\$web_gui_false_positive_patterns = \[\];/', $src));

// The suppression that remains must be defensible: only what the scan genuinely
// cannot judge from a 30-line sample.
if (preg_match('/\$prohibited_keywords = \[(.*?)\];/s', $src, $m)) {
    $kept = $m[1];
    check('only log retention and availability remain suppressed',
        str_contains($kept, 'log retention') && str_contains($kept, 'log availability'));
    check('root login findings are no longer deleted outright',
        !str_contains($kept, "'root login'"),
        '"SSH root login permitted from any source" is a real critical');
    check('port forwarding findings are no longer deleted outright',
        !str_contains($kept, "'port forwarding'"));
} else {
    check('the keyword list was found', false);
}

// --- the prompt must not contradict itself -----------------------------------

check('the prompt no longer forbids mentioning logs',
    !str_contains($src, 'DO NOT MENTION LOGS AT ALL'),
    'it sat directly above a section that sends logs and asks for threats in them');

check('findings must carry evidence',
    str_contains($src, 'evidence: the specific configuration element'),
    'a concern with nothing to check against reads as an opinion');
check('findings must carry severity, impact and remediation',
    str_contains($src, '- severity:') && str_contains($src, '- impact:') && str_contains($src, '- remediation:'));
check('generic advice is discouraged',
    str_contains($src, 'Generic hardening advice'));

// --- the answer must not be capped below the question ------------------------

check('the output ceiling was raised from 2000',
    !str_contains($src, "'max_tokens' => 2000"),
    '2000 tokens for grade, summary, concerns, recommendations and log analysis');
check('the OpenAI ceiling is a parameter, not a literal',
    str_contains($src, 'function callOpenAI($api_key, $model, $prompt, $max_tokens = 8000, array $overrides = [])'));
check('a refused ceiling is retried at the limit the API states',
    str_contains($src, 'max_tokens is too large') && str_contains($src, 'return callOpenAI('),
    'models differ widely, so a fixed number is wrong for somebody; gpt-4-turbo allows 4096');
check('the retry cannot loop',
    (bool) preg_match('/\$allowed > 0 && \$allowed < \$max_tokens/', $src),
    'it only retries downward, once');
check('Anthropic is raised too', str_contains($src, "'max_tokens' => 8000,"));

// --- the report must survive being stored ------------------------------------
//
// concerns and recommendations were bound to the INSERT exactly as parsed. When
// the model returned a list of objects - which is what asking for severity,
// evidence, impact and remediation per finding encourages - the converter
// recursed and handed back an array, PDO stringified it, and the column held the
// five characters "Array". The analysis was produced and then discarded at the
// last step:
//
//   id  summary  concerns  recs  improvements
//   40      490         5     5             5
//   41      571       576   613           202

check('a section renderer exists', str_contains($src, 'function report_section_text'));
check('every report section goes through it',
    substr_count($src, 'report_section_text($analysis[') >= 4,
    'summary, recommendations, concerns and improvements');
check('the raw parsed value is no longer bound directly',
    !preg_match("/\\\$analysis\\['concerns'\\],/", $src),
    'that is what put the literal string "Array" in the column');
check('a list of objects keeps its labels',
    str_contains($src, "str_replace('_', ' '"),
    'severity/title/remediation are the useful part of a structured finding');
check('nested values are flattened rather than dropped',
    (bool) preg_match("/if \\(is_array\\(\\\$v\\)\\) \\{\s*\\\$v = implode/", $src));

// --- redaction must still hold ----------------------------------------------

check('the configuration is still redacted before it leaves',
    str_contains($src, 'ai_prepare_config('),
    'this is the one thing that must never regress while loosening filters');
check('a redaction failure still aborts rather than falling back',
    str_contains($src, 'Configuration could not be redacted'));

// --- the request must adapt to the model, not the other way round -------------
//
// Switching to a GPT-5 model made every scan fail with HTTP 400: "Unsupported
// parameter: 'max_tokens' is not supported with this model. Use
// 'max_completion_tokens' instead." The newer generation renamed the field, and
// the request had the old name compiled in - so recommending a current model
// broke the feature that recommended it.
//
// The fix is deliberately not a table of model names mapped to parameter names:
// that is the stale-catalogue bug again. The API states which parameter it wants
// when it refuses, so the retry uses the name it was given.

check('the completion limit parameter is not hardcoded',
    str_contains($raw, "\$limit_param = \$overrides['limit_param'] ?? 'max_tokens'"),
    'the name differs by model generation');
check('a rename is taken from the error message',
    str_contains($raw, "Unsupported parameter: '([a-z_]+)'[^']*Use '([a-z_]+)' instead"),
    'the API names the replacement; a hardcoded map would go stale');
check('the retry only renames the parameter it sent',
    str_contains($raw, '$m[1] === $limit_param'),
    'a message about some other parameter must not be treated as this one');
check('the retry cannot loop on an unchanged name',
    str_contains($raw, '$m[2] !== $limit_param'));
check('a refused temperature is dropped rather than failing the scan',
    (bool) preg_match("/\\\$send_temperature\s*&&[\s\S]{0,200}temperature[\s\S]{0,200}'temperature' => false/", $raw),
    'temperature is a nicety here; the analysis is not worth losing over it');
check('the downward token retry carries the overrides forward',
    str_contains($raw, 'return callOpenAI($api_key, $model, $prompt, $allowed, $overrides);'),
    'otherwise the second retry would undo the first one');
check('the payload is built once and encoded',
    str_contains($raw, 'CURLOPT_POSTFIELDS => json_encode($payload)'));

// --- severity must track reachability, not tidiness --------------------------
//
// The prompt described the grade bands and left the severity of individual
// findings entirely to the model, which rated hardening gaps as medium and high
// and then graded down against its own inflation. A firewall whose worst real
// problem was plaintext administration on a LAN-only GUI scored C/75; DNSSEC
// disabled and a permissive WireGuard policy - neither reachable from the
// internet - were both medium.
//
// Anchored to what an attacker can actually reach, the same configuration scores
// A/91, while a firewall with four administrative interfaces genuinely published
// to the internet stays at F. The point was never leniency.

check('severity is defined by reachability',
    str_contains($src, 'assign by what an attacker can reach, not by how tidy the setting is'));
check('critical requires internet reachability AND administrative access',
    str_contains($src, 'reachable from any internet source AND grants administrative control'));
check('high is the authenticated or limited case',
    str_contains($src, 'reachable from the internet but authenticated or limited in scope'));
check('medium requires a position the attacker must already hold',
    str_contains($src, 'exploitable only from a position an attacker must already hold'));
check('low is named as where most preferences belong',
    str_contains($src, 'This is where most configuration preferences belong'));

foreach ([
    'DNSSEC validation disabled'  => 'resolver-integrity hardening, not an exposure',
    'permissive any-to-any policy on a VPN interface' => 'admitted by cryptographic key',
    'web GUI on plain HTTP'       => 'NO internet-facing rule permitting it',
] as $case => $reason) {
    check("{$case} is calibrated as low", str_contains($src, $case),
        'this was rated medium or high and drove the grade down');
    check("...with its reason stated", str_contains($src, $reason),
        'a rule with no reason is one the next model ignores');
}

check('the grade is derived from the findings raised',
    str_contains($src, 'The grade must follow from the findings you actually raised'),
    'grade and severity were two independent judgements that disagreed');
check('a critical forces D or F', str_contains($src, 'any CRITICAL -> D or F'));
check('only low and info is an A',
    str_contains($src, 'only LOW and INFO -> A')
    && str_contains($src, 'nothing but hardening preferences outstanding is an A, not a B'));
check('the same issue is not counted twice',
    str_contains($src, 'Do not grade down for the same issue twice'));
check('intentional publishing does not reduce the grade',
    str_contains($src, 'do not grade down for intentional publishing of non-administrative services'),
    'a published Plex or mail server is a decision, not a defect');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
