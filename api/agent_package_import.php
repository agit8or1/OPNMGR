<?php
/**
 * Offline import of the agent package from Add Firewall.
 *
 * For managers that cannot reach GitHub: an administrator uploads the package
 * and its signed release record, and they are staged only if they verify
 * exactly as scripts/stage_agent_package.php --import would. Never replaces a
 * staged package with different bytes.
 *
 * @since 3.81.0
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/agent_package.php';

requireLogin();

$back = function (bool $ok, string $message): void {
    $_SESSION['agent_import_flash'] = ['ok' => $ok, 'message' => $message];
    header('Location: /add_firewall_page.php');
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
require_permission('settings.manage');
if (!csrf_verify($_POST['csrf'] ?? '')) {
    $back(false, 'Invalid CSRF token; reload the page and try again.');
}

$pkg = $_FILES['package'] ?? null;
$rec = $_FILES['record'] ?? null;
foreach (['package' => $pkg, 'release record' => $rec] as $label => $f) {
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        $back(false, "The {$label} was not uploaded. Choose both files and try again.");
    }
}
if ($pkg['size'] > 5 * 1024 * 1024 || $rec['size'] > 64 * 1024) {
    $back(false, 'Those files are far larger than an agent package and its record; check you chose the right ones.');
}

// Under their release names, so every message names the file the administrator chose.
$version = agent_expected_version();
$tmp = sys_get_temp_dir() . '/opnmgr-agent-upload-' . bin2hex(random_bytes(8));
if (!@mkdir($tmp, 0700)) {
    $back(false, 'Could not create a temporary directory for the upload.');
}
$pkgPath = $tmp . '/' . agent_package_name($version);
$recPath = $tmp . '/' . agent_release_record_name($version);
$r = (move_uploaded_file($pkg['tmp_name'], $pkgPath) && move_uploaded_file($rec['tmp_name'], $recPath))
    ? agent_package_stage_release($pkgPath, $recPath)
    : agent_package_error('stage_failed', 'The uploaded files could not be read', []);
@unlink($pkgPath);
@unlink($recPath);
@rmdir($tmp);

audit_log('agent.package.import', [
    'success'  => $r['ok'],
    'message'  => $r['ok']
        ? sprintf('Agent package %s staged from an upload', $r['version'])
        : 'Agent package upload refused (' . $r['code'] . ')',
    'metadata' => $r['ok'] ? ['sha256' => $r['sha256']] : ['code' => $r['code'], 'error' => $r['error']],
]);

if (!$r['ok']) {
    $back(false, 'Not staged: ' . $r['error'] . '.');
}
$m = agent_package_republish_manifest();
$back(true, sprintf('Agent %s verified and staged (sha256 %s).%s', $r['version'], substr($r['sha256'], 0, 16),
    $m['ok'] ? '' : ' Agent update manifest not re-signed: ' . $m['message'] . '.'));
