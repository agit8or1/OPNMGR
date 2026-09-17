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
    str_contains($src, 'function callOpenAI($api_key, $model, $prompt, $max_tokens = 8000)'));
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

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
