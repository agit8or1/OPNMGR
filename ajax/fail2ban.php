<?php
require_once __DIR__ . '/../inc/bootstrap.php';
requireLogin();
header('Content-Type: application/json');

$jail = trim($_GET['jail'] ?? $_POST['jail'] ?? '');
if ($jail === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'no jail']);
    exit;
}

$wrapper = '/usr/local/sbin/opnmgr-fail2ban-wrapper';
$fbin = trim(shell_exec('command -v fail2ban-client || true'));
if (empty($fbin) && !is_executable($wrapper)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'fail2ban-client not available']);
    exit;
}

// prefer wrapper if present
$use_wrapper = is_executable($wrapper);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $act = $_POST['act'] ?? '';
    $ip = trim($_POST['ip'] ?? '');

    if ($act === 'ban') {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['ok' => false, 'error' => 'invalid ip']);
            exit;
        }
        if ($use_wrapper) {
            $cmd = sprintf('sudo %s %s %s %s', escapeshellcmd($wrapper), 'ban', escapeshellarg($jail), escapeshellarg($ip));
        } else {
            $cmd = sprintf('%s set %s banip %s', escapeshellcmd($fbin), escapeshellarg($jail), escapeshellarg($ip));
            $cmd = 'sudo ' . $cmd;
        }
        $out = shell_exec($cmd . ' 2>&1');
        echo json_encode(['ok' => true, 'cmd' => $cmd, 'out' => substr($out, 0, 2000)]);
        exit;
    } elseif ($act === 'unban') {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['ok' => false, 'error' => 'invalid ip']);
            exit;
        }
        if ($use_wrapper) {
            $cmd = sprintf('sudo %s %s %s %s', escapeshellcmd($wrapper), 'unban', escapeshellarg($jail), escapeshellarg($ip));
        } else {
            $cmd = sprintf('%s set %s unbanip %s', escapeshellcmd($fbin), escapeshellarg($jail), escapeshellarg($ip));
            $cmd = 'sudo ' . $cmd;
        }
        $out = shell_exec($cmd . ' 2>&1');
        echo json_encode(['ok' => true, 'cmd' => $cmd, 'out' => substr($out, 0, 2000)]);
        exit;
    } elseif ($act === 'reload') {
        if ($use_wrapper) {
            $cmd = sprintf('sudo %s %s', escapeshellcmd($wrapper), 'reload');
        } else {
            $cmd = escapeshellcmd($fbin) . ' reload';
            $cmd = 'sudo ' . $cmd;
        }
        $out = shell_exec($cmd . ' 2>&1');
        echo json_encode(['ok' => true, 'cmd' => $cmd, 'out' => substr($out, 0, 2000)]);
        exit;
    }
}

// Default: status
if ($use_wrapper) {
    $cmd = sprintf('sudo %s %s %s', escapeshellcmd($wrapper), 'status', escapeshellarg($jail));
} else {
    $cmd = escapeshellcmd($fbin) . ' status ' . escapeshellarg($jail);
    $cmd = 'sudo ' . $cmd;
}
$out = shell_exec($cmd . ' 2>&1');

// parse IPs
$ips = [];
if (preg_match('/Banned IP list:\s*(.*)$/mi', $out, $m)) {
    $ips = array_map('trim', array_filter(array_map('trim', preg_split('/[,\n]/', $m[1]))));
}
if (empty($ips) && preg_match('/Currently banned:\s*(.*)$/mi', $out, $m)) {
    $ips = array_map('trim', array_filter(array_map('trim', preg_split('/[,\n]/', $m[1]))));
}
if (empty($ips)) {
    if (preg_match_all('/(\d{1,3}(?:\.\d{1,3}){3})/', $out, $m)) $ips = array_values(array_unique($m[1]));
}

echo json_encode(['ok' => true, 'raw' => substr($out, 0, 2000), 'ips' => $ips]);
