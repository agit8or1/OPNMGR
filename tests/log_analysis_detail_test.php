<?php
/**
 * A number on a tile must be answerable.
 *
 * Run with: php tests/log_analysis_detail_test.php
 *
 * "Log Analysis Statistics (Last 30 Days)" showed four totals - analyses,
 * threats, blocked attempts, failed auth - and offered no route to what was in
 * them. "30 blocked attempts" over thirty days, with no way to ask which scan,
 * which log, or when.
 *
 * Each tile opens the records its figure is made of. The property that matters
 * is that they agree: the first version of the Total Analyses panel listed one
 * row per log file against a tile counting scans, so it showed six beneath a
 * two. A detail panel that disagrees with its own headline is worse than none -
 * it invites the reader to doubt whichever number they checked second.
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
$page = (string) @file_get_contents($root . '/firewall_details.php');
$css  = (string) @file_get_contents($root . '/assets/css/app.css');

check('firewall_details.php is readable', $page !== '');

// --- the tiles are controls ---------------------------------------------------

check('the tiles are generated from one list rather than four copies',
    str_contains($page, '$la_tiles = ['),
    'four hand-written cards drift apart');
foreach (['analyses', 'threats', 'blocks', 'auth'] as $key) {
    check("the {$key} tile has a panel", str_contains($page, "la-panel-{$key}"));
}
check('each tile says it can be opened',
    str_contains($page, 'la-tile-hint') && str_contains($page, 'Show the records behind this number'));
check('the tiles are reachable by keyboard',
    str_contains($page, 'tabindex="0"') && str_contains($page, "e.key === 'Enter'"),
    'they are controls now, not decoration');
check('their state is exposed to assistive technology',
    str_contains($page, 'aria-expanded') && str_contains($page, 'aria-controls'));
check('one panel is open at a time',
    (bool) preg_match('/const close = \(\) =>[\s\S]{0,300}hidden = true/', $page),
    'four stacked tables is the same wall of numbers further down the page');
check('the tiles are styled as interactive', str_contains($css, '.la-tile { cursor: pointer'));
check('keyboard focus is visible', str_contains($css, '.la-tile:focus-visible'));

// --- the detail must agree with the headline ---------------------------------

check('the analyses panel groups by report',
    str_contains($page, '$la_by_report'),
    'the tile counts COUNT(DISTINCT asr.id) - scans, not log files');
check('the grouping is explained where it could confuse',
    str_contains($page, 'appears once here'),
    'the same scan appears once here and three times in the per-log figures');
// The figure is the model's count for the whole scan, and api/ai_scan.php stores
// it against every log file the scan read - so summing the rows multiplied it by
// the number of logs. A scan reporting 5 showed 15, and two such scans showed 30
// against a true 10. The panel groups by scan for the same reason the total now
// collapses each report before adding.
check('the blocked panel lists one row per scan',
    (bool) preg_match('/la-panel-blocks[\s\S]{0,900}foreach \(\$la_by_report/', $page),
    'one row per log file would repeat the same figure and disagree with the tile');
check('the per-scan figure is taken once, not summed',
    (bool) preg_match("/\\\$la_by_report\[\\\$rid\]\['blocked'\]\s*=\s*max\(/", $page),
    'max, because the same scan-level number is written to every log row');
check('the aggregate collapses each report before adding',
    str_contains($page, 'MAX(COALESCE(lar.blocked_attempts, 0))')
    && str_contains($page, 'GROUP BY asr.id'),
    'SUM over the raw rows multiplies by the number of logs read');
check('scans contributing nothing are not listed as if they did',
    (bool) preg_match("/la-panel-blocks[\s\S]{0,700}\\\$g\['blocked'\] === 0\) \{ continue; \}/", $page));
check('each row shows what the scan actually read',
    (bool) preg_match('/la-panel-blocks[\s\S]{0,1200}Logs read[\s\S]{0,600}Threat level/', $page),
    'the incident detail is which logs, how many lines and what level - not just a count');

// --- zero must mean something ------------------------------------------------

check('no threats says what was examined',
    str_contains($page, 'No active threats were identified')
    && str_contains($page, 'lines across'),
    'an empty table reads as "not checked"; the point is that it was checked and found nothing');
check('no failed authentication says so explicitly',
    str_contains($page, 'No failed authentication attempts appeared'));

// --- the figures must not overclaim ------------------------------------------

check('the blocked figure is scoped to the sample that was read',
    str_contains($page, "not from the firewall's\n                                        own counters")
    || str_contains($page, "own counters"),
    'these counts come from the log excerpt sent for analysis, not the firewall counters');
check('the panel explains why it groups by scan',
    str_contains($page, 'it is stored against each log file it read, not measured'),
    'otherwise the reader cannot tell why three logs show one number');

// --- every item traces back --------------------------------------------------

check('records link to the report they came from',
    substr_count($page, '/ai_reports.php?report_id=<?= (int)$rid ?>') >= 3,
    'analyses, blocked and failed-auth panels each link their rows');
check('threat items carry their log, time and report',
    (bool) preg_match("/log_threat_items[\s\S]{0,1200}report_id/", $page));
check('the JSON columns are decoded once, not in the markup',
    str_contains($page, '$log_threat_items = []') && str_contains($page, 'json_decode'),
    'decoding inside a loop in the view is where a malformed row takes the page down');
check('a malformed JSON column is skipped rather than fatal',
    (bool) preg_match('/if \(!is_array\(\$decoded\)\) \{ continue; \}/', $page));

// --- the detail query must match the totals query ----------------------------

foreach ([
    "scan_type = 'config_with_logs'" => 'the totals count only scans that read logs',
    'INTERVAL 30 DAY'                => 'the panel must cover the same window as the headline',
] as $needle => $why) {
    check("the detail query shares: {$needle}", substr_count($page, $needle) >= 2, $why);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
