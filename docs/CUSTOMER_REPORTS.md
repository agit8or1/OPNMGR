# Customer reports

Service reports for a firewall, a site, a customer, or the whole fleet. Output is an HTML preview, a hash-verified
PDF (optional, needs `wkhtmltopdf`) and a long-format CSV of the underlying metrics. Everything about scheduling,
email, share links and AI ships **disabled**.

Pages: `customer_reports.php` (generate, browse, schedules, recipients, switches), `customer_report.php?id=N`
(preview, downloads, provenance, share links). Code: `inc/reports/*.php`. Migration: `0027_customer_reports.sql`.

## Permissions and scoping

| Capability | Allows |
|---|---|
| `report.view` | open and download reports of customers in your scope |
| `report.generate` | generate on demand, retry / cancel jobs |
| `report.manage` | schedules (create, disable, delete), recipients |
| `report.deliver` (admin) | global switches, enabling a schedule, share links, retention |

Every view, download, generate and share checks staff customer scope (`inc/scope.php`). Out-of-scope reports return
404, identical to a missing one. Fleet reports and internal recipients need an unrestricted user. A customer or site
report selects firewalls strictly by `customer_id` / `site_id`; a site report additionally requires the firewall to
belong to the site's customer.

## Metric definitions (also printed in each report's appendix)

Time is in Unix seconds; every period is computed in the scope's timezone (site, else customer, else server) with
`DateTimeImmutable` and frozen as `[start, end)`. A local day across DST is 23 or 25 hours; hours are always
`(end-start)/3600`. Presets: last full ISO week (Mon-Sun), last calendar month, previous 7/30 days, custom dates.

**Check-in evidence ("heartbeat")**: a stored telemetry row for the firewall: `firewall_system_stats.recorded_at`,
`firewall_latency.measured_at`, `agent_checkins.checkin_time` (the last is currently never written, but is read if
populated). `agent_checkins` is not the history; the first two are. Telemetry is pruned after
`telemetry_retention_days` (default 90), so older periods have no heartbeats: they are reported **unknown**.

Thresholds per firewall (interval `I` = `firewalls.checkin_interval`, default 180 s, clamped 30..3600):

* `T_miss = max(120, 2.5 * I)`. Heartbeats at most `T_miss` apart prove the firewall was up between them.
* `T_out = max(900, offline_alert_threshold, 2 * T_miss)`.
* `T_mgr = clamp(2.5 * max I of all reporting firewalls, 300, 3600)`.

A silence longer than `T_miss` between two heartbeats (or from the last heartbeat to the end of the window) is split in
this order of precedence:

1. **Monitoring gap**: the fleet-wide union of heartbeats has a hole longer than `T_mgr` and at least two firewalls were
   reporting in the window. Attributed to the manager, not the customer; excluded from availability. With fewer than two
   reporting firewalls a fleet-wide silence cannot be told apart from one firewall's, so it is *unknown* (or a confirmed
   outage when a `firewall.offline` incident overlaps).
2. **Planned maintenance**: covered by a non-cancelled `maintenance_windows` row for the firewall, its site or its
   customer (the incident semantics of `inc/maintenance.php`: windows are planned time, data collection continued).
3. Of the remainder `R`: `R < T_out` is a **missed check-in** (not an outage); `R >= T_out` is a **confirmed outage** if
   the manager was demonstrably alive (another firewall reported inside the silence, or a `firewall.offline` incident
   overlaps it), otherwise **unknown**.

Never counted as up or down: **not enrolled** (before `firewalls.enrolled_at`), **future** (after the report was
generated), **unknown** (before the manager held any telemetry at all).

```
measurable            = up + missed_checkin + confirmed_outage
observed availability = (measurable - confirmed_outage) / measurable      n/a when measurable = 0
check-in continuity   = up / measurable
monitoring coverage   = 1 - (monitoring_gap + unknown) / (period - not_enrolled - future)
customer / fleet      = sum(measurable - outage) / sum(measurable)         device-time-weighted
```

Per-firewall percentages are never averaged. Missing data is `null` and renders as "n/a" or "not observed", never 0.
No SLA is calculated or claimed: figures are labelled "observed availability". There are no SLA targets.

* **Latency**: `firewall_latency` samples (agent average WAN latency): count, avg, p50, p95 (nearest rank), max.
  **Packet loss** exists only as `loss_percent` on `firewall_gateway_events`; there is no continuous series, so the report
  shows the max/avg *at transition events* and says so.
* **Gateway / VPN downtime**: derived from `firewall_gateway_events` / `firewall_vpn_events` transitions, with the state
  at period start taken from the last earlier event. With no transition and no earlier state the result is *not observed*,
  never "100% up".
* **Incidents**: `alert_incidents` overlapping the period; "open at end" = not resolved by period end.
* **Backups**: latest validated `backups.uploaded_at`, age vs `report_backup_stale_hours` (default 48), validated
  uploads in period, unvalidated uploads, `backup.failed` incidents, longest gap between validated backups.
* **Configuration changes**: `config_drift` / `config_baselines` state plus audit events (`backup.restore*`, `drift.*`,
  `update.*`, `command.*`, `firewall.assign`, `bulk.execute`). Actor names and messages are deliberately omitted.
  The report cannot say *what* changed between backups; drift against the approved baseline is the only diff.
* **Recommendations**: deterministic rules (`report_recommendations()`), each with its evidence string.
* **Template compliance**: the template module is optional. If a function
  `template_compliance_report_rows(array $firewallIds, int $start, int $end): array` exists, it is called and must return rows
  `['firewall_id'=>int, 'template'=>string, 'status'=>'compliant|noncompliant|exception|unknown', 'exceptions'=>int]`.
  Otherwise the section reads "Not available". (The shipped tests exercise the absent case only.)

## Jobs, storage and integrity

* `report_enqueue()` computes a dedupe key from type, scope, frozen period, timezone, sections and options. An identical
  request returns the existing queued, running or successful job; a failed job releases its key so it can be retried;
  `force` regenerates. Plain on-demand reports are built inside the request through the same job path; AI reports are built
  only by the background worker (the provider call path in `api/ai_scan.php` is CLI-only).
* Retries: 3 attempts, backoff 60 s then 120 s. After that the job is `failed`, shown on the Reports page with its error,
  audited (`report.failed`) and `report_run` is marked failed on the scheduled-jobs page. PDF failure does not fail a report;
  it is recorded (`pdf_status`/`pdf_error`).
* Files are written with exclusive create under `report_storage_path` (default `/var/lib/opnmgr/reports`; `/var/www/` is
  refused), mode 0440, and recorded with SHA-256 in `reports`, next to the metric snapshot + provenance JSON and its hash. Every
  download re-hashes and returns 500 (audited as `report.integrity_failed`) on mismatch. Reports are never edited; regeneration
  produces a new report. The directory must be writable by the user running cron and readable by the web user.
* Audit events: `report.queue`, `report.generate`, `report.failed`, `report.view`, `report.download`, `report.deliver`,
  `report.share.create|revoke|access`, `report.schedule.*`, `report.recipient.*`, `report.prune`.

## PDF hardening

Report HTML is built from escaped values, one inline stylesheet and an optional `data:` logo (read only from `/assets/`);
it has no script, link, iframe or remote image. `wkhtmltopdf` is additionally run with `--disable-local-file-access
--disable-javascript --proxy http://127.0.0.1:9 --disable-external-links --disable-internal-links --load-error-handling ignore
--no-images` (images allowed only when the data-URI logo is present), HTML on stdin and PDF on stdout, and a stripped
environment. `tests/report_pdf_test.php` proves no request reaches a local listener from hostile hostnames or raw hostile HTML,
with an unhardened positive control.

## Delivery and share links

Delivery needs `report_delivery_enabled = 1`, a schedule with "Email" ticked, and enabled recipients for that customer
(`report_recipients`, `customer_id` 0 = internal recipients for fleet reports). The email contains a title and a time-limited
share link, never report content (the SMTP helper cannot attach files). Share links need `report_share_enabled = 1`; only the
SHA-256 of the token is stored; lifetime is capped by `report_share_max_hours` (default 168, max 720); links can be revoked on
the report page; turning the global switch off disables all of them. `report_share.php?t=TOKEN[&fmt=csv|pdf]` is public by
design and rate-limited only by the 256-bit token. Recipient addresses containing CR/LF or other header characters are rejected.

When a *scheduled* report is ready, `integration_emit('report.ready', ...)` is called if it exists, with ids, period, a manager
link and a PDF flag only.

## AI summaries

Off by default: needs `report_ai_enabled`, installation AI (`ai_enabled`), an active provider, and the schedule/request's
`include_ai`. Only aggregated, hostname-free facts are sent (`report_ai_facts()`, also passed through `ai_redact_text()`). The prompt
forbids invention; the reply is discarded unless every number in it appears in the facts, it has no link/markup, and it is under
1500 characters. Identical inputs are served from `report_ai_summaries` without a call; `report_ai_monthly_token_budget` (default
200000, 0 blocks AI) caps spend using the provider's reported token counts. Failures, discards and budget exhaustion leave the report
complete without a narrative. Narratives are labelled "AI-generated". The number check is on digits; a model spelling numbers as
words evades it (the prompt requires digits).

## Cron (not installed automatically)

```
*/10 * * * *  php /var/www/opnsense/cron/report_run.php   >> /var/log/opnmgr/report_run.log 2>&1
30 3 * * *    php /var/www/opnsense/cron/report_prune.php --apply >> /var/log/opnmgr/report_prune.log 2>&1
```

`report_run` is safe to install immediately: it only queues schedules when `report_schedules_enabled = 1` and the schedule is
enabled, and only emails when `report_delivery_enabled = 1`. Both jobs are registered in `scheduled_tasks` by the migration. Run as a
user that can write the report storage directory and the log directory (see the cron-log note: a failed redirect silently kills a job).

## Settings (table `settings`)

`report_schedules_enabled`, `report_delivery_enabled`, `report_share_enabled`, `report_ai_enabled` (all `0`),
`report_share_max_hours` (168), `report_retention_days` (400, floor 30), `report_ai_monthly_token_budget` (200000),
`report_backup_stale_hours` (48, optional), `report_storage_path` (optional).

## Tests

```
tests/lib/make_test_db.sh opnsense_fw_test_reports
for t in report_metrics report_jobs report_pdf report_web; do DB_NAME=opnsense_fw_test_reports php tests/${t}_test.php; done
```

## Known limitations

* Availability is inferred from check-in telemetry, not from active probing; a firewall that checks in but has a broken WAN is "up".
* Telemetry retention bounds how far back a period can be measured.
* Heartbeats from every firewall are read to detect monitoring gaps; for a very large fleet over a long period that query is the
  report's main cost.
* Email carries a link only. PDF attachments would need an SMTP helper that supports MIME attachments.
* `database/schema.sql` is not regenerated by this module (shared file); run `scripts/generate_schema.sh` after merging migrations.
