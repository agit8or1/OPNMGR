<?php
/**
 * Report jobs: enqueue with deduplication, generate, retry, schedule, deliver,
 * share, prune. All state lives in report_* tables (migration 0027).
 *
 * Safety defaults: schedules run only when report_schedules_enabled = 1 AND the
 * schedule row is enabled; email goes out only when report_delivery_enabled = 1;
 * share links exist only when report_share_enabled = 1.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/collect.php';
require_once __DIR__ . '/render.php';
require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/ai.php';

if (!function_exists('report_hook')) {
    /** Test seams: 'collect' => fn(array $params, int $now): array, 'mail' => fn(string $to, string $subject, string $html): array. */
    function report_hook(string $name, ?callable $set = null, bool $clear = false): ?callable {
        static $h = [];
        if ($clear) { unset($h[$name]); }
        if ($set !== null) { $h[$name] = $set; }
        return $h[$name] ?? null;
    }
}

if (!function_exists('report_audit')) {
    function report_audit(string $action, array $opts = []): void {
        if (function_exists('audit_log')) { audit_log($action, $opts); }
    }
}

if (!function_exists('report_switch_on')) {
    function report_switch_on(string $name): bool { return (string)report_setting($name, '0') === '1'; }
}

if (!function_exists('report_user_can_access')) {
    /** Customer scoping for view/download/share of an existing report row. */
    function report_user_can_access(array $report, ?int $userId = null): bool {
        if (!function_exists('scope_customer_ids')) { return true; }
        if ($report['customer_id'] === null) { return scope_customer_ids($userId) === null; }
        return scope_can_customer((int)$report['customer_id'], $userId);
    }
}

if (!function_exists('report_params_build')) {
    /**
     * Normalise a request into frozen job parameters. The period is resolved to
     * absolute seconds in the scope's own timezone here, once, so the dedupe key
     * and the generated report agree on exactly what was asked.
     * @throws InvalidArgumentException
     */
    function report_params_build(array $spec, ?DateTimeImmutable $now = null): array {
        $type = (string)($spec['report_type'] ?? '');
        if (!in_array($type, ['firewall', 'site', 'customer', 'fleet'], true)) { throw new InvalidArgumentException('Unknown report type'); }
        $scopeId = $type === 'fleet' ? null : (int)($spec['scope_id'] ?? 0);
        if ($type !== 'fleet' && $scopeId <= 0) { throw new InvalidArgumentException('Select what the report covers'); }
        $scope = report_scope_resolve($type, $scopeId);
        $period = $spec['period_resolved'] ?? report_period_resolve($spec['period'] ?? ['preset' => 'last_week'], $scope['timezone'], $now);
        return [
            'report_type' => $type, 'scope_id' => $scopeId, 'customer_id' => $scope['customer_id'],
            'period' => ['start' => $period['start'], 'end' => $period['end'], 'timezone' => $period['timezone'], 'label' => $period['label']],
            'sections' => report_sections_normalize($spec['sections'] ?? null),
            'include_ai' => !empty($spec['include_ai']), 'pdf' => !isset($spec['pdf']) || !empty($spec['pdf']),
        ];
    }
}

if (!function_exists('report_dedupe_key')) {
    function report_dedupe_key(array $p, string $nonce = ''): string {
        return hash('sha256', json_encode([$p['report_type'], $p['scope_id'], $p['period']['start'], $p['period']['end'], $p['period']['timezone'],
                                          $p['sections'], $p['include_ai'], $p['pdf']]) . $nonce);
    }
}

if (!function_exists('report_enqueue')) {
    /**
     * Queue a report. Identical requests (type, scope, period, sections, options)
     * collapse into one job; the existing job is returned with deduped = true.
     *
     * @param array $opts actor (username), actor_id, schedule_id, check_scope (default true), force, now (DateTimeImmutable)
     * @return array{ok:bool,job_id:int,deduped:bool,error:string}
     */
    function report_enqueue(array $spec, array $opts = []): array {
        try {
            $p = report_params_build($spec, $opts['now'] ?? null);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'job_id' => 0, 'deduped' => false, 'error' => $e->getMessage()];
        }
        if (($opts['check_scope'] ?? true) && !report_scope_authorized($p['report_type'], $p['scope_id'])) {
            return ['ok' => false, 'job_id' => 0, 'deduped' => false, 'error' => 'You are not permitted to report on this scope'];
        }
        $source = report_dedupe_key($p);
        $key = !empty($opts['force']) ? report_dedupe_key($p, bin2hex(random_bytes(8))) : $source;

        $find = function () use ($key) {
            $s = db()->prepare("SELECT id, status, report_id FROM report_jobs WHERE dedupe_key = ?");
            $s->execute([$key]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: null;
        };
        if ($ex = $find()) {
            if ($ex['status'] === 'succeeded' && $ex['report_id']) {
                $r = db()->prepare('SELECT COUNT(*) FROM reports WHERE id = ?'); $r->execute([$ex['report_id']]);
                if ((int)$r->fetchColumn() === 0) {   // report was pruned: the key is stale
                    db()->prepare('UPDATE report_jobs SET dedupe_key = NULL WHERE id = ?')->execute([$ex['id']]);
                    $ex = null;
                }
            }
            if ($ex) { return ['ok' => true, 'job_id' => (int)$ex['id'], 'deduped' => true, 'error' => '']; }
        }
        try {
            db()->prepare('INSERT INTO report_jobs (dedupe_key, dedupe_source, report_type, scope_id, customer_id, params, schedule_id, requested_by, requested_by_user_id, next_attempt_at)
                           VALUES (?,?,?,?,?,?,?,?,?,FROM_UNIXTIME(?))')
                ->execute([$key, $source, $p['report_type'], $p['scope_id'], $p['customer_id'], json_encode($p), $opts['schedule_id'] ?? null,
                           $opts['actor'] ?? ($_SESSION['username'] ?? null), $opts['actor_id'] ?? ($_SESSION['user_id'] ?? null), $opts['queued_at'] ?? time()]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' && ($ex = $find())) {   // lost a race with an identical request
                return ['ok' => true, 'job_id' => (int)$ex['id'], 'deduped' => true, 'error' => ''];
            }
            throw $e;
        }
        $id = (int)db()->lastInsertId();
        report_audit('report.queue', ['object_type' => 'report_job', 'object_id' => $id, 'customer_id' => $p['customer_id'],
                     'message' => 'Queued ' . $p['report_type'] . ' report', 'metadata' => ['period' => $p['period']['label'], 'sections' => $p['sections']]]);
        return ['ok' => true, 'job_id' => $id, 'deduped' => false, 'error' => ''];
    }
}

if (!function_exists('report_generate')) {
    /** Build and store one report from job parameters. Returns the new report id. */
    function report_generate(array $job, int $now): int {
        $p = json_decode($job['params'], true);
        if (!is_array($p)) { throw new RuntimeException('Job parameters are unreadable'); }
        $collect = report_hook('collect') ?? 'report_collect';
        $snap = $collect($p, $now);

        $snap['ai'] = ['status' => 'none'];
        if (!empty($p['include_ai'])) { $snap['ai'] = report_ai_summarize($snap); }

        $html = report_render_html($snap);
        $csv  = report_render_csv($snap);
        $pdfStatus = 'not_requested'; $pdfErr = null; $pdf = null;
        if (!empty($p['pdf'])) {
            if (!report_pdf_available()) { $pdfStatus = 'unavailable'; $pdfErr = 'wkhtmltopdf is not installed'; }
            else {
                $r = report_pdf_render($html);
                if ($r['ok']) { $pdf = $r['pdf']; $pdfStatus = 'ok'; } else { $pdfStatus = 'failed'; $pdfErr = mb_substr((string)$r['error'], 0, 255); }
            }
        }
        $files = [];
        try {
            $files['html'] = report_store_file('html', $html);
            $files['csv']  = report_store_file('csv', $csv);
            if ($pdf !== null) { $files['pdf'] = report_store_file('pdf', $pdf); }
            $snapJson = json_encode($snap, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $title = ucfirst($p['report_type']) . ' report: ' . $snap['scope']['name'] . ' (' . $p['period']['label'] . ')';
            db()->prepare('INSERT INTO reports (job_id, schedule_id, report_type, scope_id, customer_id, title, timezone, period_start, period_end, sections, generated_at, generated_by,
                              html_path, html_sha256, csv_path, csv_sha256, pdf_path, pdf_sha256, pdf_status, pdf_error, snapshot_json, snapshot_sha256, ai_status)
                           VALUES (?,?,?,?,?,?,?,?,?,?,FROM_UNIXTIME(?),?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$job['id'], $job['schedule_id'], $p['report_type'], $p['scope_id'], $p['customer_id'], mb_substr($title, 0, 255), $p['period']['timezone'],
                           $p['period']['start'], $p['period']['end'], json_encode($p['sections']), $now, $job['requested_by'],
                           $files['html'][0], $files['html'][1], $files['csv'][0], $files['csv'][1], $files['pdf'][0] ?? null, $files['pdf'][1] ?? null,
                           $pdfStatus, $pdfErr, $snapJson, hash('sha256', $snapJson), $snap['ai']['status'] === 'none' ? 'none' : $snap['ai']['status']]);
        } catch (Throwable $e) {
            foreach ($files as $f) { report_delete_files(['html_path' => $f[0]]); }   // no orphan files
            throw $e;
        }
        return (int)db()->lastInsertId();
    }
}

if (!function_exists('report_run_job')) {
    /** Claim and run one job. @return array{ok:bool,status:string,report_id:?int,error:?string} */
    function report_run_job(int $jobId, ?int $now = null): array {
        $now = $now ?? time();
        $claim = db()->prepare("UPDATE report_jobs SET status = 'running', attempts = attempts + 1, started_at = FROM_UNIXTIME(?)
                                 WHERE id = ? AND status = 'queued' AND next_attempt_at <= FROM_UNIXTIME(?)");
        $claim->execute([$now, $jobId, $now]);
        if ($claim->rowCount() !== 1) { return ['ok' => false, 'status' => 'not_claimed', 'report_id' => null, 'error' => null]; }
        $s = db()->prepare('SELECT * FROM report_jobs WHERE id = ?');
        $s->execute([$jobId]);
        $job = $s->fetch(PDO::FETCH_ASSOC);
        try {
            $rid = report_generate($job, $now);
            db()->prepare("UPDATE report_jobs SET status = 'succeeded', report_id = ?, finished_at = FROM_UNIXTIME(?), last_error = NULL WHERE id = ?")->execute([$rid, $now, $jobId]);
            report_audit('report.generate', ['object_type' => 'report', 'object_id' => $rid, 'customer_id' => $job['customer_id'] !== null ? (int)$job['customer_id'] : null,
                         'actor_type' => $job['requested_by'] ? null : 'system', 'message' => 'Generated report from job ' . $jobId]);
            return ['ok' => true, 'status' => 'succeeded', 'report_id' => $rid, 'error' => null];
        } catch (Throwable $e) {
            // Log full detail; the stored error is class + trimmed message and never includes the parameters.
            error_log('OPNMGR report job ' . $jobId . ' failed: ' . $e->getMessage());
            $msg = mb_substr(get_class($e) . ': ' . preg_replace('/\s+/', ' ', $e->getMessage()), 0, 500);
            $permanent = $e instanceof InvalidArgumentException;
            if ($permanent || (int)$job['attempts'] >= (int)$job['max_attempts']) {
                db()->prepare("UPDATE report_jobs SET status = 'failed', dedupe_key = NULL, last_error = ?, finished_at = FROM_UNIXTIME(?) WHERE id = ?")->execute([$msg, $now, $jobId]);
                report_audit('report.failed', ['object_type' => 'report_job', 'object_id' => $jobId, 'success' => false,
                             'customer_id' => $job['customer_id'] !== null ? (int)$job['customer_id'] : null, 'message' => 'Report job failed permanently: ' . mb_substr($msg, 0, 200)]);
                return ['ok' => false, 'status' => 'failed', 'report_id' => null, 'error' => $msg];
            }
            $delay = 60 * (2 ** ((int)$job['attempts'] - 1));
            db()->prepare("UPDATE report_jobs SET status = 'queued', last_error = ?, next_attempt_at = FROM_UNIXTIME(?) WHERE id = ?")->execute([$msg, $now + $delay, $jobId]);
            return ['ok' => false, 'status' => 'retry', 'report_id' => null, 'error' => $msg];
        }
    }
}

if (!function_exists('report_worker_run')) {
    /** Process due jobs; also re-queue jobs stuck 'running' for over 30 minutes (a crashed worker). */
    function report_worker_run(int $limit = 10, ?int $now = null, bool $allowAi = true): array {
        $now = $now ?? time();
        db()->prepare("UPDATE report_jobs SET status = 'queued' WHERE status = 'running' AND started_at < FROM_UNIXTIME(?)")->execute([$now - 1800]);
        $s = db()->prepare("SELECT id FROM report_jobs WHERE status = 'queued' AND next_attempt_at <= FROM_UNIXTIME(?) ORDER BY id LIMIT " . (int)$limit);
        $s->execute([$now]);
        $res = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'retry' => 0];
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $r = report_run_job((int)$id, $now);
            if ($r['status'] === 'not_claimed') { continue; }
            $res['processed']++;
            $k = $r['status'] === 'succeeded' ? 'succeeded' : ($r['status'] === 'failed' ? 'failed' : 'retry');
            $res[$k]++;
            if ($r['status'] === 'succeeded') { report_after_generate((int)$id, (int)$r['report_id'], $now); }
        }
        return $res;
    }
}

if (!function_exists('report_after_generate')) {
    /** Scheduled reports: create deliveries (if enabled) and emit the integration event. */
    function report_after_generate(int $jobId, int $reportId, int $now): void {
        $j = db()->prepare('SELECT * FROM report_jobs WHERE id = ?'); $j->execute([$jobId]); $job = $j->fetch(PDO::FETCH_ASSOC);
        if (!$job || !$job['schedule_id']) { return; }
        $sch = db()->prepare('SELECT * FROM report_schedules WHERE id = ?'); $sch->execute([$job['schedule_id']]); $sch = $sch->fetch(PDO::FETCH_ASSOC);
        if ($sch && (int)$sch['deliver'] === 1 && report_switch_on('report_delivery_enabled')) {
            report_deliveries_queue($reportId, $now);
        }
        if (function_exists('integration_emit')) {
            try {
                $base = function_exists('opnmgr_server_url') ? rtrim(opnmgr_server_url(), '/') : '';
                $r = db()->prepare('SELECT report_type, customer_id, timezone, period_start, period_end, pdf_status FROM reports WHERE id = ?');
                $r->execute([$reportId]); $r = $r->fetch(PDO::FETCH_ASSOC);
                // Ids and a sanitised summary only: the report content never leaves through the integration bus.
                integration_emit('report.ready', [
                    'report_id' => $reportId, 'job_id' => $jobId, 'schedule_id' => (int)$job['schedule_id'], 'report_type' => $r['report_type'],
                    'customer_id' => $r['customer_id'] !== null ? (int)$r['customer_id'] : null,
                    'period_start' => (int)$r['period_start'], 'period_end' => (int)$r['period_end'], 'timezone' => $r['timezone'],
                    'pdf_available' => $r['pdf_status'] === 'ok', 'link' => $base . '/customer_report.php?id=' . $reportId,
                ]);
            } catch (Throwable $e) { error_log('OPNMGR report integration_emit failed: ' . get_class($e)); }
        }
    }
}

// ---------------------------------------------------------------------------
// Schedules
// ---------------------------------------------------------------------------

if (!function_exists('report_schedule_save')) {
    /** Create or update a schedule. New schedules are always disabled; enabling is a separate admin action. */
    function report_schedule_save(array $d, ?int $id = null): array {
        $type = (string)($d['report_type'] ?? '');
        if (!in_array($type, ['firewall', 'site', 'customer', 'fleet'], true)) { return ['ok' => false, 'error' => 'Unknown report type', 'id' => 0]; }
        $cad = (string)($d['cadence'] ?? '');
        if (!in_array($cad, ['weekly', 'monthly'], true)) { return ['ok' => false, 'error' => 'Cadence must be weekly or monthly', 'id' => 0]; }
        $scopeId = $type === 'fleet' ? null : (int)($d['scope_id'] ?? 0);
        try { $scope = report_scope_resolve($type, $scopeId); } catch (InvalidArgumentException $e) { return ['ok' => false, 'error' => $e->getMessage(), 'id' => 0]; }
        if (!report_scope_authorized($type, $scopeId)) { return ['ok' => false, 'error' => 'You are not permitted to schedule reports for this scope', 'id' => 0]; }
        $day = (int)($d['run_day'] ?? 1); $hour = (int)($d['run_hour'] ?? 6);
        $day = $cad === 'weekly' ? max(1, min(7, $day)) : max(1, min(28, $day));
        $hour = max(0, min(23, $hour));
        $name = trim((string)($d['name'] ?? '')) ?: (ucfirst($type) . ' ' . $cad . ' report');
        $sections = json_encode(report_sections_normalize($d['sections'] ?? null));
        $next = report_next_run($cad, $day, $hour, $scope['timezone'], time());
        $vals = [mb_substr($name, 0, 128), $type, $scopeId, $scope['customer_id'], $cad, $day, $hour, $sections, !empty($d['include_ai']) ? 1 : 0, !empty($d['deliver']) ? 1 : 0, $next];
        if ($id === null) {
            db()->prepare('INSERT INTO report_schedules (name, report_type, scope_id, customer_id, cadence, run_day, run_hour, sections, include_ai, deliver, next_run_at, created_by, enabled)
                           VALUES (?,?,?,?,?,?,?,?,?,?,FROM_UNIXTIME(?),?,0)')->execute(array_merge($vals, [$_SESSION['username'] ?? null]));
            $id = (int)db()->lastInsertId();
        } else {
            $own = db()->prepare('SELECT customer_id, report_type, scope_id FROM report_schedules WHERE id = ?'); $own->execute([$id]); $old = $own->fetch(PDO::FETCH_ASSOC);
            if (!$old || !report_scope_authorized($old['report_type'], $old['scope_id'] !== null ? (int)$old['scope_id'] : null)) { return ['ok' => false, 'error' => 'Schedule not found', 'id' => 0]; }
            db()->prepare('UPDATE report_schedules SET name=?, report_type=?, scope_id=?, customer_id=?, cadence=?, run_day=?, run_hour=?, sections=?, include_ai=?, deliver=?, next_run_at=FROM_UNIXTIME(?) WHERE id=?')
                ->execute(array_merge($vals, [$id]));
        }
        report_audit('report.schedule.save', ['object_type' => 'report_schedule', 'object_id' => $id, 'customer_id' => $scope['customer_id'], 'message' => 'Saved report schedule (disabled until enabled by an administrator)']);
        return ['ok' => true, 'error' => '', 'id' => $id];
    }
}

if (!function_exists('report_schedule_tick')) {
    /**
     * Queue the report for every due schedule. Does nothing unless the global
     * switch is on. Idempotent: the job dedupe key covers re-runs of the tick.
     */
    function report_schedule_tick(?int $now = null): array {
        $now = $now ?? time();
        $res = ['enabled' => report_switch_on('report_schedules_enabled'), 'queued' => 0, 'deduped' => 0, 'errors' => 0];
        if (!$res['enabled']) { return $res; }
        $s = db()->prepare("SELECT *, UNIX_TIMESTAMP(next_run_at) AS next_run_ts FROM report_schedules WHERE enabled = 1 AND next_run_at IS NOT NULL AND next_run_at <= FROM_UNIXTIME(?) ORDER BY id");
        $s->execute([$now]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $sch) {
            try {
                $scope = report_scope_resolve($sch['report_type'], $sch['scope_id'] !== null ? (int)$sch['scope_id'] : null);
                $preset = $sch['cadence'] === 'weekly' ? 'last_week' : 'last_month';
                // The period is relative to the scheduled instant, not to when the tick happens to run.
                $r = report_enqueue(['report_type' => $sch['report_type'], 'scope_id' => $sch['scope_id'], 'period' => ['preset' => $preset],
                                     'sections' => json_decode((string)$sch['sections'], true), 'include_ai' => (bool)$sch['include_ai']],
                                    ['check_scope' => false, 'queued_at' => $now, 'schedule_id' => (int)$sch['id'], 'actor' => 'schedule:' . $sch['id'], 'now' => new DateTimeImmutable('@' . (int)($sch['next_run_ts'] ?? $now))]);
                $r['deduped'] ? $res['deduped']++ : $res['queued']++;
                if (!$r['ok']) { $res['errors']++; }
                $next = report_next_run($sch['cadence'], (int)$sch['run_day'], (int)$sch['run_hour'], $scope['timezone'], $now);
                db()->prepare('UPDATE report_schedules SET last_run_at = FROM_UNIXTIME(?), next_run_at = FROM_UNIXTIME(?) WHERE id = ?')->execute([$now, $next, $sch['id']]);
            } catch (Throwable $e) {
                $res['errors']++;
                error_log('OPNMGR report schedule ' . $sch['id'] . ' failed: ' . $e->getMessage());
                // Push the schedule forward so one broken row does not retry every tick forever.
                db()->prepare('UPDATE report_schedules SET next_run_at = FROM_UNIXTIME(?) WHERE id = ?')->execute([$now + 3600, $sch['id']]);
            }
        }
        return $res;
    }
}

// ---------------------------------------------------------------------------
// Shares
// ---------------------------------------------------------------------------

if (!function_exists('report_share_create')) {
    /** @return array{ok:bool,token?:string,share_id?:int,expires_at?:int,error?:string} The token is returned once and never stored. */
    function report_share_create(int $reportId, int $hours, ?string $actor = null, ?int $now = null): array {
        if (!report_switch_on('report_share_enabled')) { return ['ok' => false, 'error' => 'Share links are disabled']; }
        $now = $now ?? time();
        $max = max(1, min(720, (int)report_setting('report_share_max_hours', 168)));
        $hours = max(1, min($max, $hours));
        $r = db()->prepare('SELECT id, customer_id FROM reports WHERE id = ?'); $r->execute([$reportId]); $rep = $r->fetch(PDO::FETCH_ASSOC);
        if (!$rep) { return ['ok' => false, 'error' => 'Report not found']; }
        $token = 'rs_' . bin2hex(random_bytes(32));
        $exp = $now + $hours * 3600;
        db()->prepare('INSERT INTO report_shares (report_id, token_hash, expires_at, created_by) VALUES (?,?,FROM_UNIXTIME(?),?)')
            ->execute([$reportId, hash('sha256', $token), $exp, $actor]);
        $sid = (int)db()->lastInsertId();
        report_audit('report.share.create', ['object_type' => 'report', 'object_id' => $reportId, 'customer_id' => $rep['customer_id'] !== null ? (int)$rep['customer_id'] : null,
                     'message' => 'Created share link valid ' . $hours . 'h', 'metadata' => ['share_id' => $sid]]);
        return ['ok' => true, 'token' => $token, 'share_id' => $sid, 'expires_at' => $exp];
    }
}
if (!function_exists('report_share_revoke')) {
    function report_share_revoke(int $shareId, ?string $actor = null): bool {
        $u = db()->prepare('UPDATE report_shares SET revoked_at = NOW(), revoked_by = ? WHERE id = ? AND revoked_at IS NULL');
        $u->execute([$actor, $shareId]);
        if ($u->rowCount()) { report_audit('report.share.revoke', ['object_type' => 'report_share', 'object_id' => $shareId, 'message' => 'Revoked share link']); }
        return $u->rowCount() > 0;
    }
}
if (!function_exists('report_share_resolve')) {
    /** @return array{ok:bool,report?:array,share_id?:int,reason?:string} reason: invalid|expired|revoked|disabled */
    function report_share_resolve(string $token, ?int $now = null): array {
        if (!report_switch_on('report_share_enabled')) { return ['ok' => false, 'reason' => 'disabled']; }
        if (!preg_match('/^rs_[0-9a-f]{64}$/', $token)) { return ['ok' => false, 'reason' => 'invalid']; }
        $now = $now ?? time();
        $s = db()->prepare('SELECT id, report_id, UNIX_TIMESTAMP(expires_at) exp, revoked_at FROM report_shares WHERE token_hash = ?');
        $s->execute([hash('sha256', $token)]);
        $sh = $s->fetch(PDO::FETCH_ASSOC);
        if (!$sh) { return ['ok' => false, 'reason' => 'invalid']; }
        if ($sh['revoked_at'] !== null) { return ['ok' => false, 'reason' => 'revoked']; }
        if ((int)$sh['exp'] <= $now) { return ['ok' => false, 'reason' => 'expired']; }
        $r = db()->prepare('SELECT * FROM reports WHERE id = ?'); $r->execute([$sh['report_id']]);
        $rep = $r->fetch(PDO::FETCH_ASSOC);
        if (!$rep) { return ['ok' => false, 'reason' => 'invalid']; }
        db()->prepare('UPDATE report_shares SET last_accessed_at = NOW(), access_count = access_count + 1 WHERE id = ?')->execute([$sh['id']]);
        return ['ok' => true, 'report' => $rep, 'share_id' => (int)$sh['id']];
    }
}

// ---------------------------------------------------------------------------
// Delivery
// ---------------------------------------------------------------------------

if (!function_exists('report_recipient_valid')) {
    function report_recipient_valid(string $e): bool {
        return strlen($e) <= 254 && !preg_match('/[\r\n<>,;"\s]/', $e) && filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('report_deliveries_queue')) {
    /** Queue one delivery per enabled recipient of the report's customer (customer 0 = internal, for fleet reports). */
    function report_deliveries_queue(int $reportId, ?int $now = null): int {
        if (!report_switch_on('report_delivery_enabled')) { return 0; }
        $r = db()->prepare('SELECT customer_id FROM reports WHERE id = ?'); $r->execute([$reportId]); $cid = $r->fetchColumn();
        if ($cid === false) { return 0; }
        $q = db()->prepare('SELECT email FROM report_recipients WHERE customer_id = ? AND enabled = 1');
        $q->execute([$cid === null ? 0 : (int)$cid]);
        $n = 0;
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $email) {
            if (!report_recipient_valid($email)) { continue; }
            $i = db()->prepare('INSERT IGNORE INTO report_deliveries (report_id, recipient_address, next_attempt_at) VALUES (?,?,FROM_UNIXTIME(?))');
            $i->execute([$reportId, $email, $now ?? time()]);
            $n += $i->rowCount();
        }
        return $n;
    }
}

if (!function_exists('report_mail_send')) {
    function report_mail_send(string $to, string $subject, string $html): array {
        if ($h = report_hook('mail')) { return $h($to, $subject, $html); }
        require_once dirname(__DIR__) . '/smtp_mailer.php';
        require_once dirname(__DIR__) . '/secrets.php';
        $set = decrypt_settings_map(db()->query('SELECT `name`,`value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR));
        if (empty($set['smtp_host']) || empty($set['smtp_from_email'])) { return ['success' => false, 'error' => 'SMTP is not configured']; }
        return send_smtp_email($set, $to, $subject, $html, $set['smtp_from_email'], $set['smtp_from_name'] ?? '');
    }
}

if (!function_exists('report_delivery_run')) {
    /** Send queued deliveries. Does nothing unless the global delivery switch is on. 3 attempts, then visible failure. */
    function report_delivery_run(int $limit = 20, ?int $now = null): array {
        $now = $now ?? time();
        $res = ['enabled' => report_switch_on('report_delivery_enabled'), 'sent' => 0, 'failed' => 0, 'retry' => 0];
        if (!$res['enabled']) { return $res; }
        $s = db()->prepare("SELECT * FROM report_deliveries WHERE status = 'queued' AND next_attempt_at <= FROM_UNIXTIME(?) ORDER BY id LIMIT " . (int)$limit);
        $s->execute([$now]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $err = null;
            try {
                $r = db()->prepare('SELECT id, customer_id, title, timezone, period_start, period_end FROM reports WHERE id = ?'); $r->execute([$d['report_id']]); $rep = $r->fetch(PDO::FETCH_ASSOC);
                if (!$rep) { throw new RuntimeException('Report no longer exists'); }
                if (!report_recipient_valid($d['recipient_address'])) { throw new RuntimeException('Recipient address is invalid'); }
                $sh = report_share_create((int)$rep['id'], 168, 'delivery', $now);
                if (!$sh['ok']) { throw new RuntimeException($sh['error']); }
                db()->prepare('UPDATE report_deliveries SET share_id = ? WHERE id = ?')->execute([$sh['share_id'], $d['id']]);
                $base = function_exists('opnmgr_server_url') ? rtrim(opnmgr_server_url(), '/') : '';
                $link = $base . '/report_share.php?t=' . $sh['token'];
                $subject = 'Network service report: ' . preg_replace('/[^\p{L}\p{N} .,:()\/_-]/u', '', mb_substr((string)$rep['title'], 0, 100));
                $body = '<p>A new service report is ready.</p><p>' . report_h($rep['title']) . '</p><p><a href="' . report_h($link) . '">Open the report</a></p>'
                      . '<p>This link expires ' . report_h(report_fmt_ts($sh['expires_at'], $rep['timezone'])) . '. Do not forward it.</p>';
                $m = report_mail_send($d['recipient_address'], $subject, $body);
                if (empty($m['success'])) { throw new RuntimeException((string)($m['error'] ?? 'Mail transport failed')); }
                db()->prepare("UPDATE report_deliveries SET status='sent', attempts=attempts+1, sent_at=FROM_UNIXTIME(?), last_error=NULL WHERE id=?")->execute([$now, $d['id']]);
                report_audit('report.deliver', ['object_type' => 'report', 'object_id' => $rep['id'], 'customer_id' => $rep['customer_id'] !== null ? (int)$rep['customer_id'] : null, 'message' => 'Delivered report link by email']);
                $res['sent']++;
            } catch (Throwable $e) {
                $err = mb_substr($e->getMessage(), 0, 250);
                $att = (int)$d['attempts'] + 1;
                if ($att >= 3) {
                    db()->prepare("UPDATE report_deliveries SET status='failed', attempts=?, last_error=? WHERE id=?")->execute([$att, $err, $d['id']]);
                    report_audit('report.deliver', ['object_type' => 'report', 'object_id' => $d['report_id'], 'success' => false, 'message' => 'Delivery failed permanently: ' . $err]);
                    $res['failed']++;
                } else {
                    db()->prepare("UPDATE report_deliveries SET attempts=?, last_error=?, next_attempt_at=FROM_UNIXTIME(?) WHERE id=?")->execute([$att, $err, $now + 300 * $att, $d['id']]);
                    $res['retry']++;
                }
            }
        }
        return $res;
    }
}

// ---------------------------------------------------------------------------
// Retention
// ---------------------------------------------------------------------------

if (!function_exists('report_prune')) {
    /** Delete reports older than the retention setting (default 400 days, floor 30). Report-only unless $apply. */
    function report_prune(bool $apply = false, ?int $days = null, ?int $now = null): array {
        $now = $now ?? time();
        $days = max(30, $days ?? (int)report_setting('report_retention_days', 400));
        $s = db()->prepare('SELECT * FROM reports WHERE generated_at < FROM_UNIXTIME(?)');
        $s->execute([$now - $days * 86400]);
        $old = $s->fetchAll(PDO::FETCH_ASSOC);
        $out = ['days' => $days, 'candidates' => count($old), 'deleted' => 0];
        if (!$apply) { return $out; }
        foreach ($old as $rep) {
            report_delete_files($rep);
            db()->prepare('DELETE FROM report_shares WHERE report_id = ?')->execute([$rep['id']]);
            db()->prepare('DELETE FROM report_deliveries WHERE report_id = ?')->execute([$rep['id']]);
            db()->prepare('UPDATE report_jobs SET dedupe_key = NULL, report_id = NULL WHERE report_id = ?')->execute([$rep['id']]);
            db()->prepare('DELETE FROM reports WHERE id = ?')->execute([$rep['id']]);
            $out['deleted']++;
        }
        db()->prepare("DELETE FROM report_jobs WHERE status IN ('failed','cancelled') AND created_at < FROM_UNIXTIME(?)")->execute([$now - $days * 86400]);
        db()->prepare("DELETE FROM report_shares WHERE expires_at < FROM_UNIXTIME(?)")->execute([$now - 30 * 86400]);
        if ($out['deleted']) { report_audit('report.prune', ['message' => 'Pruned ' . $out['deleted'] . ' report(s) older than ' . $days . ' days']); }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// Authenticated download
// ---------------------------------------------------------------------------

if (!function_exists('report_fetch_for_user')) {
    /** Load a report row only if the current user may see its customer; audits denials. */
    function report_fetch_for_user(int $id): ?array {
        $s = db()->prepare('SELECT * FROM reports WHERE id = ?'); $s->execute([$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) { return null; }
        if (!report_user_can_access($r)) {
            report_audit('authz.denied', ['success' => false, 'object_type' => 'report', 'object_id' => $id, 'message' => 'Report access outside the user\'s customer scope']);
            return null;   // indistinguishable from "does not exist"
        }
        return $r;
    }
}
