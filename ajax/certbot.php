<?php
require_once __DIR__ . '/../inc/bootstrap.php';
requireLogin();
header('Content-Type: application/json');
$domain = $_GET['domain'] ?? '';
$email = $_GET['email'] ?? '';
$dry = isset($_GET['dry']) ? true : false;
if (!$domain || !$email) {
  echo json_encode(['ok'=>false,'error'=>'domain and email required']);
  exit;
}
$logdir = '/var/log/opnmgr';
if (!is_dir($logdir)) mkdir($logdir,0755,true);
// writable check
if (!is_writable($logdir)) {
  echo json_encode(['ok'=>false,'error'=>'Log dir not writable by web user: '.$logdir]);
  exit;
}
$cb = trim(shell_exec('command -v certbot || true'));
if (empty($cb)) { echo json_encode(['ok'=>false,'error'=>'certbot not installed']); exit; }
$cmd = sprintf('%s --nginx -n --agree-tos -m %s -d %s --logs-dir %s --config-dir %s --work-dir %s %s', escapeshellcmd($cb), escapeshellarg($email), escapeshellarg($domain), escapeshellarg($logdir), escapeshellarg($logdir.'/config'), escapeshellarg($logdir.'/work'), $dry? '--dry-run' : '');
$out = shell_exec($cmd . ' 2>&1');
$logfile = $logdir.'/last_certbot.log';
file_put_contents($logfile, date('c').'
'. $cmd ."

". $out );
echo json_encode(['ok'=>true,'out'=>substr($out,0,800),'log'=>$logfile]);
exit;
