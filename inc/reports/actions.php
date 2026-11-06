<?php
/**
 * Report actions shared by the pages and the JSON endpoints, so permission,
 * customer-scope and audit checks live in exactly one place.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/jobs.php';

if (!function_exists('report_deny')) {
    function report_deny(string $msg): array { return ['ok' => false, 'message' => $msg, 'data' => []]; }
}
if (!function_exists('report_okay')) {
    function report_okay(string $msg, array $data = []): array { return ['ok' => true, 'message' => $msg, 'data' => $data]; }
}
if (!function_exists('report_need')) {
    /** Capability check that audits through require-style denial text without exiting. */
    function report_need(string $cap): ?array {
        if (can($cap)) { return null; }
        audit_log('authz.denied', ['success' => false, 'message' => 'Role "' . (current_role() ?? 'anonymous') . '" denied capability "' . $cap . '"', 'metadata' => ['capability' => $cap]]);
        return report_deny('Your role does not permit this operation');
    }
}

if (!function_exists('report_action')) {
    /** @return array{ok:bool,message:string,data:array} */
    function report_action(string $action, array $in): array {
        switch ($action) {
        case 'generate':
            if ($d = report_need('report.generate')) { return $d; }
            if (!empty($in['include_ai']) && (string)report_setting('report_ai_enabled', '0') !== '1') { $in['include_ai'] = false; }
            $r = report_enqueue([
                'report_type' => (string)($in['report_type'] ?? ''), 'scope_id' => isset($in['scope_id']) ? (int)$in['scope_id'] : null,
                'period' => ['preset' => (string)($in['preset'] ?? 'last_week'), 'from' => $in['from'] ?? '', 'to' => $in['to'] ?? ''],
                'sections' => $in['sections'] ?? null, 'include_ai' => !empty($in['include_ai']), 'pdf' => !isset($in['pdf']) || !empty($in['pdf']),
            ], ['force' => !empty($in['force'])]);
            if (!$r['ok']) { return report_deny($r['error']); }
            $data = ['job_id' => $r['job_id'], 'deduped' => $r['deduped'], 'report_id' => null];
            // Plain reports are built right away so the user sees the result; AI narratives are produced only by the background worker.
            if (!$r['deduped'] && empty($in['include_ai'])) {
                $run = report_run_job($r['job_id']);
                $data['report_id'] = $run['report_id'];
                if ($run['status'] === 'retry' || $run['status'] === 'failed') { return ['ok' => false, 'message' => 'Report generation failed: ' . $run['error'], 'data' => $data]; }
            } elseif ($r['deduped']) {
                $j = db()->prepare('SELECT report_id FROM report_jobs WHERE id = ?'); $j->execute([$r['job_id']]); $data['report_id'] = $j->fetchColumn() ?: null;
            }
            return report_okay($r['deduped'] ? 'An identical report job already exists.' : ($data['report_id'] ? 'Report generated.' : 'Report queued; the background worker will build it.'), $data);

        case 'retry_job':
        case 'cancel_job':
            if ($d = report_need('report.generate')) { return $d; }
            $s = db()->prepare('SELECT * FROM report_jobs WHERE id = ?'); $s->execute([(int)($in['job_id'] ?? 0)]); $j = $s->fetch(PDO::FETCH_ASSOC);
            if (!$j || !report_user_can_access(['customer_id' => $j['customer_id'] === null ? null : (int)$j['customer_id']])) { return report_deny('Job not found'); }
            if ($action === 'cancel_job') {
                $u = db()->prepare("UPDATE report_jobs SET status = 'cancelled', dedupe_key = NULL WHERE id = ? AND status = 'queued'"); $u->execute([$j['id']]);
                return $u->rowCount() ? report_okay('Job cancelled.') : report_deny('Only queued jobs can be cancelled.');
            }
            if ($j['status'] !== 'failed') { return report_deny('Only failed jobs can be retried.'); }
            try {
                $u = db()->prepare("UPDATE report_jobs SET status = 'queued', attempts = 0, dedupe_key = dedupe_source, last_error = NULL, next_attempt_at = NOW() WHERE id = ?");
                $u->execute([$j['id']]);
            } catch (PDOException $e) { return report_deny('An identical job is already active.'); }
            report_audit('report.retry', ['object_type' => 'report_job', 'object_id' => $j['id'], 'message' => 'Retry requested']);
            return report_okay('Job re-queued.');

        case 'save_schedule':
            if ($d = report_need('report.manage')) { return $d; }
            $r = report_schedule_save($in, !empty($in['id']) ? (int)$in['id'] : null);
            return $r['ok'] ? report_okay('Schedule saved. It stays disabled until an administrator enables it.', ['id' => $r['id']]) : report_deny($r['error']);

        case 'toggle_schedule':
            $want = !empty($in['enabled']);
            if ($d = report_need($want ? 'report.deliver' : 'report.manage')) { return $d; }
            $s = db()->prepare('SELECT * FROM report_schedules WHERE id = ?'); $s->execute([(int)($in['id'] ?? 0)]); $sc = $s->fetch(PDO::FETCH_ASSOC);
            if (!$sc || !report_scope_authorized($sc['report_type'], $sc['scope_id'] === null ? null : (int)$sc['scope_id'])) { return report_deny('Schedule not found'); }
            db()->prepare('UPDATE report_schedules SET enabled = ? WHERE id = ?')->execute([$want ? 1 : 0, $sc['id']]);
            report_audit('report.schedule.' . ($want ? 'enable' : 'disable'), ['object_type' => 'report_schedule', 'object_id' => $sc['id'], 'customer_id' => $sc['customer_id'] === null ? null : (int)$sc['customer_id']]);
            return report_okay($want ? 'Schedule enabled.' : 'Schedule disabled.');

        case 'delete_schedule':
            if ($d = report_need('report.manage')) { return $d; }
            $s = db()->prepare('SELECT * FROM report_schedules WHERE id = ?'); $s->execute([(int)($in['id'] ?? 0)]); $sc = $s->fetch(PDO::FETCH_ASSOC);
            if (!$sc || !report_scope_authorized($sc['report_type'], $sc['scope_id'] === null ? null : (int)$sc['scope_id'])) { return report_deny('Schedule not found'); }
            db()->prepare('DELETE FROM report_schedules WHERE id = ?')->execute([$sc['id']]);
            report_audit('report.schedule.delete', ['object_type' => 'report_schedule', 'object_id' => $sc['id']]);
            return report_okay('Schedule deleted.');

        case 'save_recipient':
        case 'delete_recipient':
            if ($d = report_need('report.manage')) { return $d; }
            $cid = (int)($in['customer_id'] ?? 0);
            // customer 0 = internal recipients for fleet reports: unrestricted staff only.
            if (!($cid === 0 ? scope_customer_ids() === null : scope_can_customer($cid))) { return report_deny('You are not permitted to manage recipients for this customer'); }
            if ($action === 'delete_recipient') {
                db()->prepare('DELETE FROM report_recipients WHERE id = ? AND customer_id = ?')->execute([(int)($in['id'] ?? 0), $cid]);
                report_audit('report.recipient.delete', ['customer_id' => $cid ?: null, 'object_type' => 'report_recipient', 'object_id' => (int)($in['id'] ?? 0)]);
                return report_okay('Recipient removed.');
            }
            $email = trim((string)($in['email'] ?? ''));
            if (!report_recipient_valid($email)) { return report_deny('Enter a valid email address'); }
            if ($cid > 0) {
                $c = db()->prepare('SELECT COUNT(*) FROM customers WHERE id = ?'); $c->execute([$cid]);
                if (!(int)$c->fetchColumn()) { return report_deny('Customer not found'); }
            }
            db()->prepare('INSERT INTO report_recipients (customer_id, email, name, created_by) VALUES (?,?,?,?)
                           ON DUPLICATE KEY UPDATE name = VALUES(name), enabled = 1')->execute([$cid, $email, mb_substr(trim((string)($in['name'] ?? '')), 0, 128), $_SESSION['username'] ?? null]);
            report_audit('report.recipient.save', ['customer_id' => $cid ?: null, 'message' => 'Report recipient saved']);
            return report_okay('Recipient saved.');

        case 'save_settings':
            if ($d = report_need('report.deliver')) { return $d; }
            $changed = [];
            foreach (['report_schedules_enabled', 'report_delivery_enabled', 'report_share_enabled'] as $k) {
                if (array_key_exists($k, $in)) { $changed[$k] = !empty($in[$k]) ? '1' : '0'; }
            }
            if (array_key_exists('report_ai_enabled', $in)) {
                if ($d = report_need('ai.manage')) { return $d; }
                $changed['report_ai_enabled'] = !empty($in['report_ai_enabled']) ? '1' : '0';
            }
            if (isset($in['report_retention_days'])) { $changed['report_retention_days'] = (string)max(30, min(3650, (int)$in['report_retention_days'])); }
            if (isset($in['report_share_max_hours'])) { $changed['report_share_max_hours'] = (string)max(1, min(720, (int)$in['report_share_max_hours'])); }
            foreach ($changed as $k => $v) {
                db()->prepare('INSERT INTO settings (`name`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute([$k, $v]);
            }
            report_audit('settings.change', ['object_type' => 'setting', 'message' => 'Report settings changed', 'metadata' => $changed]);
            return report_okay('Settings saved.');

        case 'create_share':
        case 'revoke_share':
            if ($d = report_need('report.deliver')) { return $d; }
            if ($action === 'revoke_share') {
                $s = db()->prepare('SELECT r.* FROM report_shares h JOIN reports r ON r.id = h.report_id WHERE h.id = ?'); $s->execute([(int)($in['share_id'] ?? 0)]);
                $rep = $s->fetch(PDO::FETCH_ASSOC);
                if (!$rep || !report_user_can_access($rep)) { return report_deny('Share link not found'); }
                return report_share_revoke((int)$in['share_id'], $_SESSION['username'] ?? null) ? report_okay('Share link revoked.') : report_deny('Link was already revoked.');
            }
            $rep = report_fetch_for_user((int)($in['report_id'] ?? 0));
            if (!$rep) { return report_deny('Report not found'); }
            $r = report_share_create((int)$rep['id'], (int)($in['hours'] ?? 72), $_SESSION['username'] ?? null);
            if (!$r['ok']) { return report_deny($r['error']); }
            $base = function_exists('opnmgr_server_url') ? rtrim(opnmgr_server_url(), '/') : '';
            return report_okay('Share link created. Copy it now: it cannot be shown again.', ['url' => $base . '/report_share.php?t=' . $r['token'], 'expires_at' => $r['expires_at'], 'share_id' => $r['share_id']]);
        }
        return report_deny('Unknown action');
    }
}

if (!function_exists('report_serve')) {
    /** Send a verified report file with download-safe headers. Exits. */
    function report_serve(array $report, string $fmt, bool $inline = false): void {
        try {
            $bytes = report_read_verified($report, $fmt);
        } catch (RuntimeException $e) {
            $code = $e->getCode() === 404 ? 404 : 500;
            if ($code === 500) { report_audit('report.integrity_failed', ['success' => false, 'object_type' => 'report', 'object_id' => $report['id'], 'message' => 'Stored file does not match its recorded hash']); }
            http_response_code($code);
            header('Content-Type: text/plain; charset=utf-8');
            echo $code === 404 ? 'Not found' : 'This report failed its integrity check and was not served.';
            exit;
        }
        $types = ['html' => 'text/html; charset=utf-8', 'csv' => 'text/csv; charset=utf-8', 'pdf' => 'application/pdf'];
        $fname = 'report-' . (int)$report['id'] . '.' . $fmt;
        header('Content-Type: ' . $types[$fmt]);
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('X-Robots-Tag: noindex, nofollow');
        // The stored HTML has no scripts; the CSP makes sure it stays that way, and sandbox blocks everything else.
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
        header('Content-Disposition: ' . (($inline && $fmt === 'html') ? 'inline' : 'attachment') . '; filename="' . $fname . '"');
        echo $bytes;
        exit;
    }
}
