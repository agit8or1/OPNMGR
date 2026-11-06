<?php
/**
 * GET ?id=N&fmt=html|csv|pdf : authenticated, customer-scoped, hash-verified download.
 * @since 3.79.0
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/scope.php';
require_once __DIR__ . '/../inc/reports/actions.php';

requireLogin();
require_permission('report.view');
$fmt = (string)($_GET['fmt'] ?? 'html');
$rep = report_fetch_for_user((int)($_GET['id'] ?? 0));
if (!$rep || !in_array($fmt, ['html', 'csv', 'pdf'], true)) { http_response_code(404); header('Content-Type: text/plain'); echo 'Not found'; exit; }
report_audit('report.download', ['object_type' => 'report', 'object_id' => $rep['id'], 'customer_id' => $rep['customer_id'] === null ? null : (int)$rep['customer_id'], 'message' => 'Downloaded ' . $fmt]);
report_serve($rep, $fmt, !empty($_GET['inline']));
