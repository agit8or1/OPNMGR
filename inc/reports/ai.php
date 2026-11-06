<?php
/**
 * Optional AI narrative for the executive summary.
 *
 * The report is complete without it. When enabled (global report_ai_enabled AND
 * the installation-wide AI switch AND the job asked for it):
 *  - only aggregated, hostname-free facts are sent (no firewall names, IPs,
 *    customer names, incident titles), passed through ai_redact_text() as well;
 *  - the configured provider/model is used, a monthly token budget is
 *    enforced, and identical inputs are served from cache without a call;
 *  - the prompt forbids inventing anything, and the reply is DISCARDED unless
 *    every number in it appears in the facts, it has no URL, and it is short;
 *  - any failure leaves the report without a narrative, never without a report.
 * Whatever is kept is labelled AI-generated in the output.
 *
 * Limitation: the number check is on digits. A model that spells numbers as
 * words ("three") evades it, so the prompt demands digits.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/collect.php';

if (!function_exists('report_ai_hook')) {
    /** Test seam: set a callable($creds, $prompt, $maxTokens) => ['text'=>,'prompt_tokens'=>,'completion_tokens'=>]. */
    function report_ai_hook(?callable $set = null, bool $clear = false): ?callable {
        static $h = null;
        if ($clear) { $h = null; }
        if ($set !== null) { $h = $set; }
        return $h;
    }
}

if (!function_exists('report_ai_facts')) {
    /** Aggregated facts only. Numbers are given both raw and pre-formatted so the narrative can quote either. */
    function report_ai_facts(array $s): array {
        $sm = $s['summary'];
        $inc = [];
        foreach ($s['incidents'] as $i) { $inc[$i['type']] = ($inc[$i['type']] ?? 0) + 1; }
        ksort($inc);
        $recs = [];
        foreach ($s['recommendations'] as $r) { $recs[$r['id']] = ($recs[$r['id']] ?? 0) + 1; }
        ksort($recs);
        return [
            'report_scope' => $s['scope']['type'],
            'period_hours' => round($s['period']['hours'], 2),
            'firewalls' => $sm['firewalls'],
            'observed_availability' => report_fmt_pct($sm['availability']),
            'monitoring_coverage' => report_fmt_pct($sm['coverage'], 1),
            'confirmed_outage' => report_fmt_duration((int)$sm['confirmed_outage_seconds']),
            'missed_checkin_time' => report_fmt_duration((int)$sm['missed_checkin_seconds']),
            'monitoring_gap_time' => report_fmt_duration((int)$sm['monitoring_gap_seconds']),
            'planned_maintenance_time' => report_fmt_duration((int)$sm['maintenance_seconds']),
            'unknown_time' => report_fmt_duration((int)$sm['unknown_seconds']),
            'latency_p95_ms' => $sm['latency_p95_ms'] === null ? 'n/a' : round($sm['latency_p95_ms'], 1),
            'incidents_in_period' => $sm['incidents_in_period'],
            'incidents_open_at_end' => $sm['incidents_open_at_end'],
            'incident_counts_by_type' => $inc,
            'backups_stale_or_missing' => $sm['backups_stale_or_missing'],
            'drifted_unacknowledged' => $sm['drifted_unacknowledged'],
            'recommendation_counts_by_rule' => $recs,
        ];
    }
}

if (!function_exists('report_ai_numbers')) {
    /** Normalised numeric tokens in a text ("99.912" -> "99.912", "1,5" -> "1.5", "2.0" -> "2"). */
    function report_ai_numbers(string $t): array {
        preg_match_all('/\d+(?:[.,]\d+)?/', $t, $m);
        $out = [];
        foreach ($m[0] as $n) {
            $n = str_replace(',', '.', $n);
            if (str_contains($n, '.')) { $n = rtrim(rtrim($n, '0'), '.'); }
            $out[] = $n;
        }
        return $out;
    }
}

if (!function_exists('report_ai_validate')) {
    /** @return ?string reason to discard, or null when acceptable */
    function report_ai_validate(string $narrative, array $facts): ?string {
        $narrative = trim($narrative);
        if ($narrative === '') { return 'empty reply'; }
        if (mb_strlen($narrative) > 1500) { return 'reply too long'; }
        if (preg_match('#https?://|www\.|<[a-z/][^>]*>#i', $narrative)) { return 'reply contains a link or markup'; }
        $allowed = array_flip(report_ai_numbers(json_encode($facts)));
        foreach (report_ai_numbers($narrative) as $n) {
            if (!isset($allowed[$n])) { return 'number "' . $n . '" is not in the supplied facts'; }
        }
        return null;
    }
}

if (!function_exists('report_ai_prompt')) {
    function report_ai_prompt(array $facts): string {
        return "You write a short executive summary (at most 4 sentences) of a network firewall service report.\n"
            . "RULES: Use ONLY the facts in the JSON below. Do not invent incidents, causes, devices, trends, comparisons with earlier periods, "
            . "metrics or recommendations. Do not give advice that is not a rule count in the facts. Write every number as digits and copy it exactly "
            . "as given. Do not mention SLA or contractual compliance. No links, no markdown. If a value is 'n/a', say it is unavailable.\n"
            . "FACTS:\n" . json_encode($facts, JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('report_ai_default_transport')) {
    /** Reuse the AI scan provider call path. CLI only: api/ai_scan.php runs its endpoint when included under a web SAPI. */
    function report_ai_default_transport(array $creds, string $prompt, int $maxTokens): array {
        if (PHP_SAPI !== 'cli') { throw new RuntimeException('AI summaries are generated by the background worker only'); }
        require_once dirname(__DIR__, 2) . '/api/ai_scan.php';
        $key = (string)$creds['api_key']; $model = (string)$creds['model'];
        $text = match ($creds['provider']) {
            'openai'    => callOpenAI($key, $model, $prompt, $maxTokens),
            'anthropic' => callAnthropic($key, $model, $prompt),
            'google'    => callGoogleGemini($key, $model, $prompt),
            'ollama'    => callOllama($key, $model, $prompt),
            default     => throw new RuntimeException('Unsupported AI provider'),
        };
        $u = function_exists('last_token_usage') ? last_token_usage() : [];
        return ['text' => (string)$text, 'prompt_tokens' => $u['prompt'] ?? null, 'completion_tokens' => $u['completion'] ?? null];
    }
}

if (!function_exists('report_ai_month_tokens')) {
    function report_ai_month_tokens(): int {
        $s = db()->query("SELECT COALESCE(SUM(COALESCE(prompt_tokens,0)+COALESCE(completion_tokens,0)),0) FROM report_ai_summaries
                           WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
        return (int)$s->fetchColumn();
    }
}

if (!function_exists('report_ai_summarize')) {
    /**
     * @return array{status:string,provider?:string,model?:string,narrative?:string,detail?:string,cached?:bool,input_hash?:string}
     *   status: none (not enabled) | ok | failed | discarded. Never throws.
     */
    function report_ai_summarize(array $snapshot): array {
        try {
            if ((string)report_setting('report_ai_enabled', '0') !== '1') { return ['status' => 'none', 'detail' => 'AI summaries are disabled']; }
            if (function_exists('ai_enabled') && !ai_enabled()) { return ['status' => 'none', 'detail' => 'AI is disabled for this installation']; }
            $creds = function_exists('get_active_ai_credentials') ? get_active_ai_credentials() : null;
            if (!$creds) { return ['status' => 'none', 'detail' => 'No AI provider is configured']; }

            $facts = report_ai_facts($snapshot);
            $factsJson = json_encode($facts, JSON_UNESCAPED_SLASHES);
            if (function_exists('ai_redact_text')) {
                $red = ai_redact_text($factsJson);
                $factsJson = is_array($red) ? (string)($red['text'] ?? $factsJson) : (string)$red;
                $facts = json_decode($factsJson, true) ?: $facts;
            }
            $prompt = report_ai_prompt($facts);
            $hash = hash('sha256', $creds['provider'] . '|' . $creds['model'] . '|v1|' . $prompt);
            $base = ['provider' => $creds['provider'], 'model' => $creds['model'], 'input_hash' => $hash];

            $c = db()->prepare("SELECT narrative FROM report_ai_summaries WHERE input_hash = ? AND status = 'ok' ORDER BY id DESC LIMIT 1");
            $c->execute([$hash]);
            if (($cached = $c->fetchColumn()) !== false) {
                // Cached text was validated against these same facts when stored.
                return $base + ['status' => 'ok', 'narrative' => (string)$cached, 'cached' => true];
            }

            $budget = (int)report_setting('report_ai_monthly_token_budget', 200000);
            if ($budget <= 0 || report_ai_month_tokens() >= $budget) {
                return $base + ['status' => 'failed', 'detail' => 'Monthly AI token budget is exhausted'];
            }

            $transport = report_ai_hook() ?? 'report_ai_default_transport';
            $res = $transport($creds, $prompt, 600);
            $text = trim((string)($res['text'] ?? ''));
            $why = report_ai_validate($text, $facts);
            $ins = db()->prepare('INSERT INTO report_ai_summaries (input_hash, provider, model, status, narrative, detail, prompt_tokens, completion_tokens) VALUES (?,?,?,?,?,?,?,?)');
            if ($why !== null) {
                $ins->execute([$hash, $creds['provider'], $creds['model'], 'discarded', null, mb_substr($why, 0, 255), $res['prompt_tokens'] ?? null, $res['completion_tokens'] ?? null]);
                return $base + ['status' => 'discarded', 'detail' => $why];
            }
            $ins->execute([$hash, $creds['provider'], $creds['model'], 'ok', $text, null, $res['prompt_tokens'] ?? null, $res['completion_tokens'] ?? null]);
            return $base + ['status' => 'ok', 'narrative' => $text, 'cached' => false];
        } catch (Throwable $e) {
            // The provider message can echo request content; keep only a class-level reason.
            error_log("OPNMGR report AI summary failed: " . get_class($e) . (getenv("REPORT_DEBUG") ? " " . $e->getMessage() : ""));
            return ['status' => 'failed', 'detail' => 'AI provider call failed'];
        }
    }
}
