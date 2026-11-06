<?php
/**
 * PDF hardening: report content must not make the renderer fetch anything.
 *
 * A local PHP listener logs every request it receives. A positive control
 * (unhardened wkhtmltopdf on hostile HTML) proves the listener would see a
 * fetch; then the same hostile content goes through the real report path and
 * through report_pdf_render() on raw, unescaped HTML, and the log must stay empty.
 *
 *   DB_NAME=opnsense_fw_test_reports php tests/report_pdf_test.php
 */
require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/reports/jobs.php';

$dbName = db()->query('SELECT DATABASE()')->fetchColumn();
if (strpos($dbName, 'opnsense_fw_test') !== 0) { fwrite(STDERR, "refusing to run against $dbName\n"); exit(2); }

T::group('PDF availability');
if (!report_pdf_available()) {
    T::ok(true, 'wkhtmltopdf not installed: PDF is reported unavailable and the HTML report still works (hardening tests skipped)');
    $r = report_pdf_render('<p>x</p>');
    T::ok(!$r['ok'] && $r['error'] !== null, 'render reports a clear error');
    exit(T::summary());
}
T::ok(true, 'wkhtmltopdf found at ' . report_pdf_binary());

$dir = sys_get_temp_dir() . '/opnmgr_pdf_test_' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$log = $dir . '/hits.log';
file_put_contents($dir . '/router.php', '<?php file_put_contents(' . var_export($log, true) . ', $_SERVER["REQUEST_URI"] . "\n", FILE_APPEND); header("Content-Type: image/png"); echo base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==");');
$port = random_int(20000, 40000);
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $dir . '/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, $dir);
register_shutdown_function(function () use ($srv, $dir) { proc_terminate($srv); exec('rm -rf ' . escapeshellarg($dir)); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) { break; } usleep(100000); }
$hits = fn() => is_file($log) ? trim((string)file_get_contents($log)) : '';

$hostile = [
    "<img src=http://127.0.0.1:$port/x>",
    "<img src=\"http://127.0.0.1:$port/y\" onerror=alert(1)>",
    "file:///etc/passwd",
    "<iframe src=file:///etc/passwd></iframe><link rel=stylesheet href=http://127.0.0.1:$port/css>",
    "<script src=http://127.0.0.1:$port/js></script>",
];

T::group('Positive control: the listener sees an unhardened fetch');
$raw = "<html><body><img src=\"http://127.0.0.1:$port/control\"></body></html>";
$p = proc_open(['/usr/bin/wkhtmltopdf', '--quiet', '-', '-'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, ['QT_QPA_PLATFORM' => 'offscreen', 'PATH' => '/usr/bin:/bin']);
fwrite($pipes[0], $raw); fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); proc_close($p);
$controlSeen = str_contains($hits(), '/control');
T::ok($controlSeen, 'unhardened wkhtmltopdf requests the image (so a silent log below is meaningful)');
file_put_contents($log, '');

T::group('Report with hostile hostnames');
db()->exec('DELETE FROM firewall_latency'); db()->exec('DELETE FROM firewalls'); db()->exec('DELETE FROM customers');
db()->prepare('INSERT INTO customers (name, timezone) VALUES (?,?)')->execute([$hostile[1], 'UTC']);
$cid = (int)db()->lastInsertId();
foreach ($hostile as $i => $h) {
    db()->prepare("INSERT INTO firewalls (hostname, ip_address, hardware_id, status, customer_id, enrolled_at) VALUES (?,?,?,?,?,'2026-01-01 00:00:00')")
        ->execute([$h, '198.51.100.9', bin2hex(random_bytes(8)), 'online', $cid]);
}
$params = report_params_build(['report_type' => 'customer', 'scope_id' => $cid, 'period' => ['preset' => 'custom', 'from' => '2026-03-01', 'to' => '2026-03-02']]);
$snap = report_collect($params, strtotime('2026-03-03 00:00 UTC'));
$html = report_render_html($snap);
T::ok(!preg_match('/<img[^>]+127\.0\.0\.1/i', $html) && !str_contains($html, '<iframe') && !str_contains($html, '<script') && !preg_match('/<link\b/i', $html), 'hostile values are escaped; no active tags in the report HTML');
T::ok(str_contains($html, '&lt;img src=http://127.0.0.1:' . $port), 'the hostname is shown as inert text');
$csv = report_render_csv($snap);
T::ok(str_contains($csv, 'file:///etc/passwd'), 'CSV keeps the text');
$r = report_pdf_render($html);
T::ok($r['ok'] && str_starts_with($r['pdf'], '%PDF'), 'a PDF is produced: ' . ($r['error'] ?? ''));
sleep(1);
T::eq('', $hits(), 'no request reached the local listener while rendering the report');

T::group('Renderer hardening on raw, unescaped hostile HTML');
$rawHtml = '<html><head><link rel="stylesheet" href="http://127.0.0.1:' . $port . '/css"><script src="http://127.0.0.1:' . $port . '/js"></script></head><body>'
         . implode('', $hostile) . '<img src="http://127.0.0.1:' . $port . '/raw"><iframe src="http://127.0.0.1:' . $port . '/frame"></iframe>'
         . '<div style="background:url(http://127.0.0.1:' . $port . '/bg)">x</div></body></html>';
$r = report_pdf_render($rawHtml);
T::ok($r['ok'], 'rendering completes even though every resource is blocked');
sleep(1);
T::eq('', $hits(), 'no network fetch from <img>, <link>, <script>, <iframe> or CSS url() (dead proxy + no images + no JS)');
$args = implode(' ', report_pdf_args('/usr/bin/wkhtmltopdf', false));
T::ok(str_contains($args, '--disable-local-file-access') && str_contains($args, '--disable-javascript') && str_contains($args, '--proxy http://127.0.0.1:9') && str_contains($args, '--no-images'), 'hardening flags present');

T::group('Local file access');
$secret = $dir . '/secret.txt'; file_put_contents($secret, 'TOP-SECRET-LOCAL-CONTENT');
$r = report_pdf_render('<html><body><iframe src="file://' . $secret . '"></iframe><img src="file://' . $secret . '"></body></html>');
$txt = '';
if ($r['ok'] && is_executable('/usr/bin/pdftotext')) { file_put_contents($dir . '/o.pdf', $r['pdf']); $txt = (string)shell_exec('/usr/bin/pdftotext ' . escapeshellarg($dir . '/o.pdf') . ' - 2>/dev/null'); }
T::ok(!str_contains($r['pdf'] ?? '', 'TOP-SECRET-LOCAL-CONTENT') && !str_contains($txt, 'TOP-SECRET-LOCAL-CONTENT'), 'a file:// reference does not pull local file content into the PDF');

exit(T::summary());
