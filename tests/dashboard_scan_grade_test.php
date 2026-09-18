<?php
/**
 * Health and the scan grade are different measurements.
 *
 * Run with: php tests/dashboard_scan_grade_test.php
 *
 * The fleet view showed a firewall at 100% health whose most recent
 * configuration scan had graded it F, with its management interface reachable
 * from any source on the internet. Neither number was wrong: health is
 * reachability and service state, the grade is what the configuration review
 * concluded. Showing only the first, on the page used to judge the fleet at a
 * glance, meant a confirmed critical finding left no trace anywhere someone
 * would look.
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
$dash = (string) @file_get_contents($root . '/dashboard.php');
$css  = (string) @file_get_contents($root . '/assets/css/app.css');

check('dashboard.php is readable', $dash !== '');

check('the fleet table has a scan column',
    (bool) preg_match('/<th[^>]*>Scan<\/th>/', $dash));
check('the column explains that it is not health',
    (bool) preg_match('/<th[^>]*title="[^"]*Health and this measure different things/', $dash),
    'two numbers side by side invite being read as the same measure');

// --- the grade shown must be the latest, and one row per firewall -------------

check('the newest report per firewall is selected',
    str_contains($dash, 'SELECT firewall_id, MAX(id) AS id')
    && str_contains($dash, 'FROM ai_scan_reports GROUP BY firewall_id'),
    'joining ai_scan_reports directly would repeat a firewall once per scan');
check('the join is a LEFT JOIN',
    (bool) preg_match('/LEFT JOIN \(\s*SELECT s1\.firewall_id/', $dash),
    'an inner join would drop every firewall that has never been scanned');
check('the grade, score, risk and date all come through',
    str_contains($dash, 'r.overall_grade AS scan_grade')
    && str_contains($dash, 'r.security_score AS scan_score')
    && str_contains($dash, 'r.risk_level AS scan_risk')
    && str_contains($dash, 'r.created_at AS scan_at'));

// --- an unscanned firewall must not look like a passing one -------------------

check('a firewall with no scan is marked as such',
    str_contains($dash, 'This firewall has never been scanned'),
    'a blank cell reads as "fine"');
check('it renders a dash rather than a grade',
    str_contains($dash, 'scan-grade none'));

// --- a grade that may no longer describe the configuration --------------------

check('the age of the scan is considered',
    str_contains($dash, '$staleScan = $scanAge > 30'),
    'a grade from months ago describes a configuration that may have changed');
check('a stale grade is visually distinguished',
    str_contains($dash, "' stale'") && str_contains($css, '.scan-grade.stale'));
check('the date and score are available without leaving the page',
    str_contains($dash, "'Security score '") && str_contains($dash, "', scanned '"));

// --- severity must read correctly at a glance --------------------------------

check('A and B read as good',
    str_contains($dash, "in_array(\$grade[0], ['A', 'B'], true) ? 'good'"));
check('C reads as a warning',
    str_contains($dash, "(\$grade[0] === 'C' ? 'warn' : 'bad')"));
check('D and F read as bad, not merely warning',
    !preg_match("/'D'[^\n]*'warn'/", $dash));
foreach (['good', 'warn', 'bad', 'none'] as $cls) {
    check("the {$cls} grade is styled", str_contains($css, ".scan-grade.{$cls}"));
}
check('the grade is legible in dark mode',
    (bool) preg_match('/\[data-theme="dark"\] \.scan-grade\.bad/', $css));

// --- the grade links to its evidence -----------------------------------------

check('the grade links to the report it came from',
    str_contains($dash, '/ai_reports.php?report_id=<?php echo (int)$fw[\'scan_report_id\']'),
    'a grade with no way to reach its findings is a number to argue with');
check('the report id is cast',
    str_contains($dash, "(int)\$fw['scan_report_id']"));
check('clicking the grade does not trigger the row navigation',
    str_contains($dash, 'onclick="event.stopPropagation()"'),
    'the row navigates to firewall details, which is not where the report is');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
