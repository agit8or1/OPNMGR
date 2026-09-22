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

// --- two columns of the same kind should behave the same way -----------------
//
// The scan grade linked to its report; the health figure beside it linked
// nowhere, so one column invited a click and the other quietly did not.

check('the health figure links to its detail',
    str_contains($dash, 'class="dash-fw-health-link"')
    && str_contains($dash, '/firewall_health.php?firewall=<?php echo (int)$fw[\'id\']'));
check('it lands on that firewall rather than the fleet page',
    str_contains($dash, 'firewall_health.php?firewall='),
    'firewall_health.php reads ?firewall= to focus one device');
check('clicking it does not also trigger the row navigation',
    (bool) preg_match('/dash-fw-health-cell" onclick="event.stopPropagation\(\)"/', $dash),
    'the row navigates to firewall details, which is not the health page');
check('the link is styled to look like one on hover',
    str_contains($css, '.dash-fw-health-link'));

// --- health had a grade and never showed it ----------------------------------
//
// calculateHealthReport() computes a score, a grade and a per-component
// breakdown. The dashboard called calculateHealthScore(), which throws the rest
// away, so Health printed "100%" beside a Scan column printing "A" - two columns
// of the same kind reading as different sorts of thing.

check('the dashboard takes the whole health report',
    str_contains($dash, "calculateHealthReport(\$fw, \$latest_major_version)"),
    'calculateHealthScore() returns only the number and discards the grade');
check('the grade is carried onto the row',
    str_contains($dash, "\$fw['health_grade']"));
check('it is rendered beside the percentage',
    str_contains($dash, 'class="health-grade'),
    'the scan column shows a letter; this one showed only a percentage');
check('the grade is escaped', str_contains($dash, "htmlspecialchars(\$hGrade)"));
check('its colour follows the same thresholds as the bar',
    (bool) preg_match('/class="health-grade <\?php echo \$healthClass/', $dash),
    'a green bar beside an amber grade would be two verdicts on one number');
check('an empty grade renders nothing rather than an empty badge',
    str_contains($dash, "\$hGrade !== ''"));

check('the health breakdown is the tooltip',
    str_contains($dash, 'buildHealthTooltip($fw[\'health_report\'])'),
    'the scan grade explains itself on hover; this one said only "Health checks for ..."');
check('a missing report falls back rather than fataling',
    str_contains($dash, "function_exists('buildHealthTooltip') && !empty(\$fw['health_report'])"));

foreach (['good', 'warn', 'bad'] as $cls) {
    check("the {$cls} health grade is styled", str_contains($css, ".health-grade.{$cls}"));
}
check('the health grade is legible in dark mode',
    str_contains($css, '[data-theme="dark"] .health-grade.bad'));

// --- the grade pays its own width --------------------------------------------
//
// The badge was added beside the bar and the percentage without taking any
// width back, and the bar's own right margin and the badge's own left margin
// were both still applied on top of the flex gap - so the cell outgrew the
// 120px the column had always been given and squeezed every column beside it.

check('the health bar gives up width for the badge',
    (bool) preg_match('/\.dash-fw-health-cell \.health-bar \{[^}]*flex: 0 0 48px/', $dash),
    'a 60px bar plus the percentage plus the badge does not fit 120px');
check('the bar no longer adds a margin of its own',
    (bool) preg_match('/\.dash-fw-health-cell \.health-bar \{[^}]*margin-right: 0/', $dash),
    'margin-right plus the flex gap spaced the cell twice');
check('the badge no longer adds a margin of its own',
    (bool) preg_match('/\.dash-fw-health-cell \.health-grade \{[^}]*margin-left: 0/', $dash),
    'the shared .health-grade rule carries margin-left for non-flex callers');
check('the badge does not claim a minimum width in the cell',
    (bool) preg_match('/\.dash-fw-health-cell \.health-grade \{[^}]*min-width: 0/', $dash));
check('the column keeps the width it always had',
    str_contains($dash, '.dash-fw-health-cell { min-width: 120px; }'),
    'widening the column is the regression, not the fix');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
