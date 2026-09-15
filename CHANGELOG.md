# OPNManager Changelog

All notable changes to OPNManager are documented here.

**Last Updated**: September 15, 2026

---

## Version 3.25.1
**Released**: September 15, 2026 | **Agent**: v1.6.2

### Fixed

- **The on-demand web proxy was broken at both ends.** `firewall_proxy.php` wrote a
  `request_body` column and read a `status_code` column; `request_queue` has `body`
  and `response_status`. The INSERT threw, so no request was ever queued, and the
  polling SELECT threw too. Both now use the columns the table has.

  While fixing it: the status was forwarded as `(int)$response['status_code']`, which
  on a missing key yields `0`. `http_response_code(0)` is a silent no-op, so a
  response with no status would have reached the browser as a 200. It now falls back
  to 502.

- **The profile page reported two-factor as disabled for everyone.** It tested
  `$user_data['two_factor_secret']`, which is not a column in `users`. The column
  `twofactor_setup.php` writes and `verify2fa.php` reads is `totp_secret`, so an
  account with two-factor genuinely enabled was still shown a "Disabled" badge.

- **`firewall_proxy.php`'s header comment contained a spliced copy of its own
  logic.** The docblock was cut mid-word and a duplicate of the polling and
  response-forwarding block was pasted inside it, where it sat inert — carrying the
  same wrong column names. Anyone "restoring" that block would have reintroduced the
  bug. The comment now describes the file, and the duplicate is gone.

- **`scripts/fetch_logs.php` read a `firewalls.ssh_username` column that has never
  existed.** A `?? 'root'` masked it, so the behaviour was always correct by
  accident. It now says `root` outright, which is what every other SSH path here
  hardcodes.

### Removed

Three endpoints that could only ever throw. Nothing in the tree, the agent source,
or the released agent tarballs (1.5.6 and 1.6.2 were unpacked and searched) calls
any of them, and all three are authenticated, so none was an exposure:

- **`agent_selfheal_report.php`** wrote to `agent_selfheal_log`, a table that has
  never existed in any schema in this repository's history, and updated
  `firewalls.last_selfheal`, `firewalls.selfheal_status` and
  `firewall_agents.last_update`, none of which exist either. Repairing it would have
  meant inventing a schema for a feature nothing uses.
- **`api/record_speedtest.php`** and **`api/run_speedtest.php`** implemented a
  pending-then-complete workflow that `firewall_speedtest` cannot express — it has
  no `status` column — and each carried its own `CREATE TABLE IF NOT EXISTS` with a
  shape disagreeing with `database/schema.sql`. The table already exists, so the
  CREATE was a no-op and the INSERT then failed. The working path is unaffected:
  `api/trigger_speedtest.php` queues the command, `agent_checkin.php` records the
  result into `bandwidth_tests` (which the detail chart reads), and
  `api/agent_speedtest_result.php` writes `firewall_speedtest`.

- **The "Send Test Email" button reported failure for emails it had delivered.**
  `api/test_email.php` wrote the same non-existent `alert_history.recipient_email`
  column. The send succeeded, the history INSERT threw, and the surrounding handler
  turned that into `{"success": false, "error": "Internal server error"}` — so the
  operator was told SMTP was broken when it was working. Recording history is now
  wrapped so it can never do that again.

  This file was found by the new test, not by reading code: it is **not in the
  repository**. A `test_*` rule in `.gitignore` was excluding it, along with
  `api/run_bandwidth_test.php`. Both are endpoints the shipped UI calls
  (`alerts.php` fetches the first), so a fresh clone 404'd on them. Both are now
  tracked, and the ignore rules negate them explicitly.

### Added

- **`tests/schema_columns_test.php`, wired into CI.** It parses `database/schema.sql`
  and every migration, then checks that every column named in a literal INSERT or
  UPDATE across all tracked PHP actually exists — and that the specific names behind
  past outages (`recipient_email`, `recipient_emails`, `sent_successfully`,
  `request_body`, `two_factor_secret`) do not come back. Verified by running it
  against the pre-fix files: it fails there and passes here.

  This is the check that was missing. Column names live in SQL strings and array
  keys, which no linter reads, which is why `inc/alerts.php` could write a
  non-existent column for long enough to silently disable repeat-notification
  suppression. It earned its place immediately: running it against the deployed
  copy surfaced `api/test_email.php`, which no amount of reading the repository
  would have found, because the file was not in the repository.

  It falls back to walking the tree when `git ls-files` returns nothing, so it
  works against a deployed copy as well as a checkout — which is exactly how that
  file was caught.

---

## Version 3.25.0
**Released**: September 15, 2026 | **Agent**: v1.6.2

Presentation and demo-tooling release. No application behaviour change, no schema
migration, no agent update.

### Added

- **A 28-capture screenshot gallery, half light and half dark.**
  `docs/SCREENSHOTS.md` is organised by what an operator is trying to do rather
  than by page name, with a table of contents, a theme label and a caption on
  every entry. Captures are 1440×1000 at deviceScaleFactor 2, so interface text
  stays sharp when GitHub scales them down, and the whole set is 4.2 MB.

- **A recorded walkthrough of the running application**, with a highlight clip, a
  poster frame, a WebVTT caption file (`docs/walkthrough.vtt`) and a transcript
  that doubles as a narration script (`docs/walkthrough-script.md`). The
  walkthrough switches theme through the application's own toggle part way
  through, so both themes are shown honestly rather than claimed. It is
  caption-led: **no narration audio was produced**, and the file has no audio
  track. The MP4s are release assets, not tracked files.

- **`scripts/encode_demo_media.sh`** — encodes the recording to 1080p30 H.264 with
  `yuv420p` and `faststart` so it plays in browsers and on phones, cuts the
  highlight clip, and extracts the poster.

### Changed

- **The demo fixture now writes real OPNsense-shaped configuration XML.**
  Configuration drift and fleet configuration search both parse the stored backup
  file, so seeding database rows alone produced a drift page that could not diff
  and a search that returned nothing. The fixture writes 165 config files to a
  directory outside the repository, then calls `drift_set_baseline()` and
  `drift_evaluate()` — so the drift state in the screenshots is computed by the
  application from real files rather than fabricated. A few configurations carry
  findings the named security checks genuinely detect.

- **The fixture also seeds what the visual pages need**: bandwidth test history
  (the detail chart reads `bandwidth_tests`, which is what `agent_checkin.php`
  writes — `firewall_speedtest` is written by `api/agent_speedtest_result.php` but
  nothing renders it), `firewall_agents` rows, twelve tags, five staff across the
  three roles, and alert triggers and notifications.

- **The capture script takes a manifest.** Every capture records its route, theme,
  any tab it had to click and the viewport, written to
  `docs/images/github/captures.json`, so a capture can be reproduced without
  guesswork. Themes are applied through the application's own selector and each
  capture waits for `<html data-theme>` to settle before the shutter.

- **README rebuilt** around one hero image, three benefits, a six-shot visual tour
  grouped by outcome, and the walkthrough — with a Known limitations section that
  names the hardcoded agent-package URL, the absence of a support policy, and the
  third-party QR service used during two-factor enrolment.

### Fixed

- **A broken documentation link shipped in 3.22.0.** The README linked
  `docs/QUICK_REFERENCE.md`, which is gitignored and therefore 404s on GitHub. The
  file is stale v2.1.0 content that contradicts the current version and references
  pages that no longer exist, so the link is gone rather than the file tracked.

### Removed

Six files with evidence, and nineteen superseded media files. See the cleanup
commit for the per-file reasoning.

---

## Version 3.24.1
**Released**: September 15, 2026 | **Agent**: v1.6.2

### Fixed

- **Alert history was never recorded, and every alert displayed as failed.** Three
  column names that do not exist in `alert_history` were in use at once:

  - `inc/alerts.php` inserted a `recipient_email` column. Every insert threw. The
    throw was caught by the per-recipient handler, which recorded it as *"Error
    sending to <address>"* — so a mail that had already been delivered successfully
    was reported back to the caller as a send failure, and nothing was written to the
    table.
  - `alert_history.php` rendered `recipient_emails` and `sent_successfully`. Neither
    exists, so `$alert['sent_successfully']` was always null and every row displayed
    as **Failed** with **0 recipient(s)** — including, on any installation, rows that
    would have described successful deliveries.

  The consequence was larger than the display: `was_alert_recently_sent()` and
  `get_recent_alert_count()` both read this table to suppress repeat notification.
  With nothing ever written, both always reported zero, so that suppression path
  never engaged.

  The writer now records one row per alert rather than one per recipient — which is
  what `recipients_count` means — using the columns the table actually has, with
  `status` set to `sent`, `partial` or `failed` and per-recipient errors kept in
  `error_message`. Recording history is wrapped so that a logging failure can never
  again turn a delivered alert into a reported failure. The reader renders those
  columns, showing *Partial* distinctly from *Sent* and *Failed*.

  `tests/alerting_test.php` now asserts that the four required columns exist, that
  none of the three phantom names has come back, and that all three status values
  round-trip through the shipped schema. The bug survived because the column names
  live in SQL strings and array keys, which nothing in CI was checking.

- **`alerts.php` carried a credential-shaped string as an input placeholder.** The
  Pushover API token field used a 30-character token-shaped literal as its
  `placeholder`. Nothing was ever stored or transmitted, but it read as a live
  credential to anyone looking at the page or a screenshot of it, and it sat in a
  public repository. It now reads `30-character token from pushover.net/apps`.

### Added

- **Both alerting pages are now in the gallery**, bringing it to eighteen captures.
  They were held back from 3.24.0 precisely because of the two defects above — a
  screenshot would have advertised them as normal behaviour.

---

## Version 3.24.0
**Released**: September 15, 2026 | **Agent**: v1.6.2

### Added

- **A recorded walkthrough.** `scripts/record_demo_walkthrough.js` drives the demo
  environment through eight stops — dashboard, fleet list, search, one firewall's
  health, drift, a rollout in progress, incidents, and the customer/site model — and
  records it at 1440×900. Sign-in happens before recording starts, so no credential is
  ever on camera. The README embeds a 5 MB GIF; the MP4 is a release asset, because a
  2.8 MB binary that regenerates from a script does not need to live in git history
  forever.

- **`docs/SCREENSHOTS.md`** — the full gallery, sixteen captures with alt text and
  full-size links. The README keeps its hero and four-shot tour and links here, so the
  first screen still answers what the product does rather than turning into a contact
  sheet.

- **Eleven more captures**: the fleet list with tags, fleet search, customers and
  sites, the fleet-wide health overview, firewall detail statistics, backup history,
  maintenance windows, bulk operations, the audit log, staff roles, and the light theme.

### Changed

- **The demo fixture now seeds staff, tags, alerting and agent registrations.** Four
  pages were worth capturing but rendered empty or misleading without them:

  - `firewall_agents` rows. `firewalls.php` and the dashboard read check-in state from
    that table first and only fall back to `firewalls.last_checkin`, so the fleet list
    showed every device as "Never" checked in while the dashboard showed them online.
  - Five MSP staff across the admin, technician and readonly roles, so the capability
    model is visible rather than a single account.
  - Five colour-coded tags applied across the fleet, which also gives fleet search
    something real to match on.
  - Seven alert triggers and seven notification records.

### Notes

Two pages are deliberately absent from the gallery, and both are worth fixing:

- **`alert_history.php` renders every alert as "Failed / 0 recipient(s)" on any
  installation.** It selects `alert_history.*` and then reads `$alert['recipient_emails']`
  and `$alert['sent_successfully']`, neither of which is a column in that table, so the
  status test is always falsy. This is not a demo artefact — a real installation shows
  the same thing.

- **`alerts.php` carries a Pushover-token-shaped string as the `placeholder` attribute
  of an empty input.** Nothing is stored and nothing leaks, but it reads as a live
  credential in a screenshot, and it is worth confirming it was never a real token
  before it stays in a public repository.

---

## Version 3.23.0
**Released**: September 15, 2026 | **Agent**: v1.6.2

### Removed

Twenty-four files, none of them referenced by anything that runs. Verified by
grepping every tracked file for each path before deleting it.

- **`screenshots/` (19 PNGs, ~9 MB).** Captured from a live installation and kept
  only because the old README embedded them. `docs/images/github/` replaced them in
  3.22.0, and they were the last thing in the tree produced by pointing a camera at
  the real fleet. The only remaining mention was an *exclusion* pattern in
  `package_builder.php`, which is unaffected by their absence.

- **`scripts/take_screenshots.js`.** The live-installation capture path. It worked by
  rewriting hostnames, addresses and email addresses in the rendered DOM immediately
  before each capture — a redaction step that fails open: anything its patterns missed
  got published. `scripts/capture_demo_screenshots.js` renders a database that never
  held real data, so there is nothing to redact and nothing to get wrong. Keeping both
  meant keeping the risky one.

- **`download.php`.** Served `downloads_public.zip` — a 5.8 MB archive of screenshots —
  to anyone who asked, with no authentication and no link anywhere in the application.
  The two apparent references were to `/api/updates/download.php`, which is a different,
  live endpoint and is untouched.

- **`download_agent_wrapper.php`.** A PID-safe wrapper for
  `/usr/local/bin/opnsense_agent.sh`, the pre-plugin agent. Nothing has invoked it since
  the agent became a native OPNsense plugin, and nothing links to it.

- **`download/opnsense_agent_v3.8.5.sh`** and **`scripts/upgrade_to_v350.php`.** A
  superseded agent build and a one-off migration for 3.5.0. Neither is referenced.

### Changed

- **The README's Releases section no longer names a version.** Written in 3.22.0 and
  already wrong twice by 3.22.1, because every tag and every bump invalidated it. It
  now states the rule — each version is tagged from `main` — and links to
  `releases/latest`, which stays correct without editing.

### Notes

Two dead-looking endpoints were deliberately left in place:

- **`download_agent.php`** looks legacy, but `agent_checkin.php` hands agents a
  `selfheal_url` pointing at it and the file it serves does exist. Removing it would
  break agent self-healing.
- **`download_tunnel_key.php`** reads private keys from `/opt/opnsense-tunnels/keys/`,
  a path the current tunnel system does not use, so it almost certainly always 404s.
  It is gated behind `requireLogin()` and `requireAdmin()`, so it is not an exposure,
  but an endpoint whose job is to emit private keys should be removed deliberately
  rather than as part of a tidy-up.

---

## Version 3.22.1
**Released**: September 15, 2026 | **Agent**: v1.6.2

### Changed

- **`docs/github-about.md` now records applied settings rather than pending ones.**
  The About description, the twelve topics and the deliberately empty Website field
  were applied to the repository on 2026-09-15, and v3.22.0 was tagged as the latest
  release, so the file read as a to-do list describing work already done. It is now
  the record of the intended state — the thing to check if the settings drift, and
  the thing to update first if they are changed on purpose.

  Notably `multi-tenant` is gone from the topic list. It advertised tenant isolation
  the product does not implement: customers are organisational groupings with no
  accounts and no login.

---

## Version 3.22.0
**Released**: September 15, 2026 | **Agent**: v1.6.2

### Changed

- **Rewrote `README.md` around what the product does.** The first screen was a
  reverse-chronological pile of release notes for 3.12 through 3.17 — every one of
  which is already in this file — before any statement of what OPNManager is for.
  It now leads with the positioning, one fleet dashboard screenshot, the three
  things the product actually helps an operator do, a short screenshot tour, and a
  Mermaid diagram of the communication direction. The release history was removed
  from the README rather than duplicated; this file is the history.

- **Removed the "Production Stable" status claim.** Nothing in the repository
  defines a release policy, a support window or a validation gate that the claim
  could refer to, so it asserted more than the project documents.

- **Tagged this release and pointed the README at it.** The newest tag had been
  `v3.11.1`, ten minor versions behind `main`, so the README's Releases link led
  nowhere useful. 3.12 through 3.21 shipped on `main` and exist only in this file, so
  the changelog stays the authoritative history rather than the releases page.

- **Feature availability is now stated per agent version.** Health telemetry needs
  agent 1.6.2 — 1.6.0 reported no gateways and 1.6.1 miscounted services — and the
  README previously still carried the 3.14-era line saying the health collector was
  "not in a published agent release yet", which stopped being true at 1.6.0.

- **Documented that the agent installer fetches its package from the project's
  distribution host.** The one-liner the UI generates is correctly built from the
  operator's own hostname, but `install_opnmanager_agent.sh` has `PLUGIN_URL`
  hardcoded to `opn.agit8or.net`, so a self-hosted installation does not currently
  serve its own agent tarball. Calling a product self-hosted while quietly relying
  on someone else's host is the kind of claim this release is meant to stop making.
  The README now says so and explains how to mirror the package.

- **Added the independence notice.** The project is not affiliated with or endorsed
  by Deciso B.V. or the OPNsense project, and now says so.

### Added

- **`scripts/demo_fixture.php`** — seeds a throwaway database with fictitious
  customers, sites and simulated telemetry so documentation screenshots never come
  from a live fleet. It refuses to run unless `OPNMGR_DEMO=1` and `DB_NAME` ends in
  `_demo`, refuses a database holding agent credentials, and writes nothing to
  `firewall_commands`, `agent_commands` or `request_queue` — it asserts those are
  empty before it exits, so a demo fleet has no path to instructing anything.
  Addresses come from the documentation ranges in RFC 5737 and RFC 3849 and
  hostnames from the reserved `.example` domain.

- **`scripts/capture_demo_screenshots.js`** — captures the README images from that
  demo environment at a consistent 1440×900, dark theme, sidebar pinned. It takes
  its credential from the environment and points at the demo instance, so unlike
  the live-installation capture path there is no redaction step that can silently
  fail to catch a hostname.

- **`docs/images/github/`** — five screenshots (dashboard, firewall health,
  configuration drift, fleet updates, incidents) with a `README.md` recording how
  they were produced and how to regenerate them.

- **`docs/github-about.md`** — the About description, Website decision and topic
  list for the repository page, with the reasoning for each change. Notably it
  recommends dropping the `multi-tenant` topic: customers are organisational
  groupings with no accounts and no login, so the topic advertises isolation the
  product does not implement.

---

## Version 3.21.3
**Released**: September 5, 2026 | **Agent**: v1.6.2

### Changed

- **Documented the Firewall Health feature.** It has shipped since 3.14.0 and was
  described in `README.md`, but `FEATURES.md` — the feature reference — had no
  section for it at all, and still listed the agent at v1.5.6 with no mention of
  health telemetry among its functions. Added a Firewall Health section covering
  gateways, VPN tunnels, services, certificates and CARP, including the service
  inventory's `configctl service list` source and the note that certificate
  private keys are never read.

- **The in-app changelog skipped the entire 3.21 line.** `getChangelogEntries()`
  stopped at 3.20.0, so the release that made the Health page work at all was
  invisible in the UI. Added a 3.21.0 entry covering the agent 1.6.0 release and
  the 1.6.1/1.6.2 fixes.

- **`docs/UPGRADING.md` told operators to update agents to 1.6.0** for health
  telemetry. That is the minimum that reports anything, but 1.6.0 always reported
  zero gateways and 1.6.1 counted unconfigured services as stopped, so the
  guidance now names 1.6.2 and says why.

---

## Version 3.21.2
**Released**: September 5, 2026 | **Agent**: v1.6.2

### Fixed

- **Every firewall reported services as stopped that were never configured.**
  `collect_services()` walked a hardcoded candidate list and treated a service as
  installed if `/usr/local/etc/rc.d/<name>` existed — true for everything shipped
  by an installed package, configured or not — and then hardcoded
  `"enabled": True` on the result. Since nothing ever set `enabled` to anything
  else, the fleet view's `enabled = 1 AND running = 0` test collapsed into "not
  running", so an unconfigured `openvpn`, `strongswan` or `radvd` counted as a
  stopped service on a perfectly healthy firewall. Both firewalls in the fleet
  showed "4 stopped" permanently, and every newly enrolled one would have too.

  The collector now reads `configctl service list`, which reports only the
  services OPNsense actually has configured — that being what "enabled" has to
  mean. On the live fleet this takes fw48 from four false positives to none, and
  it surfaces a genuine stopped service on fw51 (`senpai`, the Zenarmor engine)
  that the candidate list never covered.

  Multi-instance services (`dpinger` per gateway, `wireguard` per tunnel) are
  reported once per instance and are now merged, counting as up only when every
  instance is. A service whose status string cannot be parsed is omitted rather
  than assumed stopped, and an unreadable registry omits the whole section, so
  the server keeps the last known list instead of concluding every service
  vanished.

---

## Version 3.21.1
**Released**: September 5, 2026 | **Agent**: v1.6.1

### Fixed

- **The gateway health section was always empty.** With 1.6.0 deployed, VPN
  tunnels, services and certificates all reported, but every firewall showed no
  gateways at all. `configctl interface gateways status` returns the gateways as
  a bare object keyed by gateway name — `{"WAN_DHCP": {...}, "WAN_DHCP6": {...}}`
  — with no envelope, and `collect_gateways()` only looked for `items` or
  `gateways` keys, so it found neither and returned an empty list. It now falls
  back to treating any dict-shaped value as a gateway, while still accepting the
  enveloped and list shapes.

- **`~` was stored as a gateway address.** OPNsense writes `~` for a field it did
  not measure (an IPv6 gateway with no monitor reports `"address":"~"`,
  `"delay":"~"`). The numeric fields already ignored it by accident, but the text
  fields did not, so `~` was persisted and rendered as if it were an address.
  Unmeasured fields are now null.

---

## Version 3.21.0
**Released**: September 5, 2026 | **Agent**: v1.6.0

### Added

- **Agent 1.6.0 released — health telemetry actually ships.** The health
  collector had been sitting in `plugin/.../health_collect.py` since 3.17.0 with
  no tarball to carry it, so every firewall in the fleet reported the same thing:
  "Not reporting health — agent 1.5.6 (requires 1.6.0+)". The server side was
  complete the whole time (`health_ingest()`, the `firewall_gateways` /
  `firewall_vpn_tunnels` / `firewall_services` / `firewall_certificates` tables,
  `firewall_health.php`); only the package was missing.
  `downloads/plugins/os-opnmanager-agent-1.6.0.tar.gz` is now built and published,
  `AGENT_VERSION` and `downloads/AGENT_VERSION.txt` name it, and the release
  manifest is re-signed, so agents pick it up on their next check-in through the
  normal update path.

### Fixed

- **The installer never copied the health collector.** It installs
  `opnsense/scripts/OPNsense/OPNManagerAgent/*.sh` — a glob that silently
  excludes `health_collect.py`. Shipping the 1.6.0 tarball without fixing this
  would have upgraded every agent to a version whose `get_health_json()` finds no
  script, returns `{}`, and reports nothing: the same empty fleet view, now with
  a higher version number on it. The installer copies and chmods `*.py`, and the
  post-install verification fails loudly when `health_collect.py` is absent.

- **`watchdog.sh` was missing from the plugin source tree.** It shipped in every
  released tarball up to 1.5.6 but existed nowhere in `plugin/`, so packaging
  from source would have quietly dropped it from new installs. Restored from the
  1.5.6 artifact; a stale `__pycache__/` directory that would have been packaged
  alongside it is excluded.

- **The plugin installer was gitignored.** `downloads/` was ignored wholesale, and
  `install_opnmanager_agent.sh` is the only copy of that script — authored source,
  not a build product — so the fix above would have lived on the release host and
  nowhere else, and a fresh clone would have had no installer at all. `.gitignore`
  now excludes the built artifacts (tarballs, `manifest.json`,
  `AGENT_VERSION.txt`) while tracking the installer.

- **The 1.6.0 health gate is no longer a magic literal.** `firewall_health.php`
  hardcoded "requires 1.6.0+" in two places, unconnected to the agent version the
  rest of the application reasons about. Both now render
  `AGENT_HEALTH_MIN_VERSION`.

---

## Version 3.20.8
**Released**: August 31, 2026 | **Agent**: v1.5.6

### Changed

- **`scripts/automated_backup.php` now queues through `queue_firewall_command()`**
  instead of inserting into `firewall_commands` directly. The direct insert left
  the primary nightly backup — the one that runs every night — with the weakest
  audit trail of any command the system issues: no `audit_log` entry at all,
  recorded as a raw `MEDIUM`-risk shell command rather than the structured
  `LOW`-risk `backup_upload` action, and with no `parameters`, so the command
  could not be joined back to the `backups` row it belonged to.

  Both nightly jobs now queue identically:

  ```
  Automated nightly configuration backup                 backup_upload  is_raw 0  LOW
  Automated nightly configuration backup (second pass)   backup_upload  is_raw 0  LOW
  ```

  It also deletes the `backups` row again if queueing fails, matching the second
  pass — leaving it would claim a backup that was never attempted, which is the
  false-coverage problem this whole cycle exists to prevent.

  Verified against both live firewalls: commands queued as `backup_upload` with
  `{"backup_id":N}`, two `command.action` rows added to `audit_log`, and the
  uploads landed and validated as before.

---

## Version 3.20.7
**Released**: August 31, 2026 | **Agent**: v1.5.6

### Added

- **Backup retention and reaping are now scheduled**, at 03:30 in root's crontab
  with `--apply`, completing the nightly cycle:

  | | | |
  |---|---|---|
  | 01:00 | `scripts/automated_backup.php` | take the backup |
  | 02:00 | `cron/nightly_backups.php` | second pass, skips firewalls already covered |
  | 03:30 | `cron/prune_backups.php --apply` | enforce retention, reap fileless rows |
  | 04:00 | `scripts/check_backup_health.php --log` | verify the result and report |

  Prune runs before the check so the check sees the settled state. Root, because
  `/var/lib/opnmgr/backups` is `www-data:www-data 0750` and the reap exits 3
  rather than guessing when it cannot read the store. Failures go to syslog under
  `backup-prune` as well as the log file.

  `--apply` is destructive by design — the entry carries a comment saying so and
  naming the settings that govern it. It deletes nothing today: retention is 90
  days and the oldest backup is 2026-08-27, so the first real deletion is roughly
  three months out.

---

## Version 3.20.6
**Released**: August 31, 2026 | **Agent**: v1.5.6

### Added

- **`cron/prune_backups.php` now reaps backup rows whose upload never arrived**,
  closing the gap recorded in 3.20.5. A row is created when the upload is
  *queued*, so one still without a file after a grace window (default 48h,
  `backup_fileless_grace_hours`, migration 0016; 0 disables) is never getting
  one — the firewall was offline, or the upload was rejected. Previously nothing
  removed them: `record_backup_failure()` annotates and keeps the row, and
  retention prunes only by age, so they accumulated and inflated the backup
  count.

  The pass runs after retention and reports by default like the rest of the
  script; `--apply` deletes, `--grace=N` overrides the window, `--no-reap` skips
  it. A dry run names the rows it would remove and why.

- **It refuses to reap when it cannot read the backup store.** Deciding what to
  delete depends on telling a *missing* file from an *unreadable* one, and
  `/var/lib/opnmgr/backups` is `www-data:www-data 0750` — run as another user,
  every backup looks missing and the pass would be deciding blind. It skips and
  exits 3 with one clear line, rather than emitting a warning per row as the
  first cut did.

### Fixed

- **`database/schema.sql` regenerated.** Migration 0015 drops and re-adds each
  foreign key, and MySQL names the backing index after the constraint when it
  creates one, so three indexes are now `<table>_ibfk_1` rather than
  `firewall_id`. Functionally irrelevant, but a fresh install loads `schema.sql`
  and would otherwise diverge from an upgraded one.

---

## Version 3.20.5
**Released**: August 31, 2026 | **Agent**: v1.5.6

### Changed

- **The 170 backup rows with no stored file were removed.** 3.20.3 annotated
  them instead of deleting them, on the reasoning that the gap should stay
  visible in the record; with the cause fixed, verified and written up here,
  keeping 170 rows that describe backups which never existed was worse — the
  backups list read as 183 backups when 13 were real. Dumped to
  `backups_db/dangling_backup_rows_*.sql.gz` (170 INSERTs, counted against the
  pre-delete total) before deletion.

  `backups` is now 13 rows, all 13 resolving to a file on disk, all validated,
  spanning 2026-08-27 to 2026-08-31 — i.e. every row dates from after the upload
  fix, which is what honest backup coverage looks like here.

### Known gap

- **Nothing reaps a backup row whose upload never arrives.** `api/upload_backup.php`
  calls `record_backup_failure()` when an upload is rejected, which annotates the
  row but keeps it, and `cron/prune_backups.php` prunes only by age. So a
  firewall that is offline when its backup is queued still leaves a row with no
  file behind, and those will slowly accumulate again. They are no longer
  invisible — `scripts/check_backup_health.php` counts rows newer than the last
  successful upload and exits 2 — but reaping them is not automatic yet.

---

## Version 3.20.4
**Released**: August 31, 2026 | **Agent**: v1.5.6

### Added

- **The backup coverage check is now scheduled**, at 04:00 in root's crontab,
  after both backup jobs (01:00 primary, 02:00 second pass). It must run as root
  or `www-data` — `/var/lib/opnmgr/backups` is `www-data:www-data 0750`, and the
  check refuses to answer rather than guess without read access.

- **`--log` records the verdict in `system_logs`.** A cron job that only appends
  to a file nobody opens is the same failure mode this check exists to catch, so
  the scheduled run writes an INFO/WARNING/ERROR row into the app's own log, and
  a non-zero exit additionally goes to syslog under the `backup-health` tag. The
  ERROR message names the uncovered firewalls rather than just counting them.

---

## Version 3.20.3
**Released**: August 31, 2026 | **Agent**: v1.5.6

### Fixed

- **Nightly backups had not reached disk in months, and nothing said so.**
  `cron/nightly_backups.php` built its own upload command by hand:

  ```sh
  curl -k -X POST -F "backup=@$BACKUP_FILE" -F "firewall_id=NN" \
       https://opn.agit8or.net/api/upload_backup.php
  ```

  That carries no agent credentials, and `api/upload_backup.php` has required
  them since 3.12.0 (`authenticateAgentRequest`), so every upload it queued was
  rejected. Three things then hid the failure: the command never checked curl's
  exit code, so the firewall reported it *completed*; the job created no
  `backups` row, so a rejected upload left no trace; and the surviving rows came
  from the other nightly job, which made the backup list look populated. The
  result was 170 rows describing backups that were never stored, and files on
  disk stopping dead at 2026-02-09.

  The job now uses `build_backup_upload_command()` — the same builder behind
  manual backups, bulk operations and pre-restore snapshots, which reads the
  agent's credential files on the firewall and fails on a non-zero curl exit. It
  also creates the `backups` row before queueing (so a rejection is recorded
  against it), routes through `queue_firewall_command()` for audit and risk
  level, and deletes the row again if queueing fails rather than leaving a claim
  of a backup that was never attempted. Verified end to end against both live
  firewalls: files on disk, `validated = 1`, checksums recorded.

- **Two nightly backup jobs were running.** `scripts/automated_backup.php` at
  01:00 (correct since 3.20.0) and `cron/nightly_backups.php` at 02:00 (broken).
  Rather than delete one, the 02:00 job is now a genuine second pass: it skips
  any firewall that already has a backup row for today, so it does nothing when
  the earlier run worked and takes a real backup when it did not. `--force` and
  `--dry-run` added.

- **The 170 rows with no file are annotated** rather than deleted, so the gap
  stays visible in the record instead of disappearing. Retention ages them out.

### Added

- **`scripts/check_backup_health.php`** — the reason this went unnoticed for
  months is that nothing measured backups, only counted rows. This resolves each
  firewall's most recent backup to an actual file on disk and reports coverage
  against a window (`--days`, default 2). Exit 1 if a firewall is uncovered, 2 if
  rows reference missing files, 0 when clean.

  It distinguishes a *missing* file from an *unreadable* one:
  `/var/lib/opnmgr/backups` is `www-data:www-data 0750`, so running as another
  user cannot see the files and would report healthy backups as absent — the
  dangerous direction to be wrong in. It refuses to answer and exits 3 instead,
  telling you to re-run as `www-data`. "Uploads failing now" is judged by rows
  newer than the last successful upload, not a fixed window, so it does not cry
  wolf for a week after every fix.

---

## Version 3.20.2
**Released**: August 31, 2026 | **Agent**: v1.5.6

### Fixed

- **`check_versions.php` could not run in CI.** 3.20.1 made the newest released
  tarball in `downloads/plugins/` the authority for the agent version — correct
  locally, but `downloads/` is gitignored, so a fresh checkout has no release
  artifacts and the check aborted with "No released agent tarball found". It now
  falls back to the `AGENT_VERSION` constant, which is tracked in git, and skips
  only the checks a real release can answer (the unreleased-source-bump check and
  the source-vs-tarball comparison). README, `agent.sh` and CHANGELOG are still
  validated against it, so version drift is still caught on every push.

---

## Version 3.20.1
**Released**: August 31, 2026 | **Agent**: v1.5.6

### Fixed

- **Health scores no longer cancel themselves out.** Both firewalls in the fleet
  graded A+ 88/100 despite one having a pending system update. The old weighting
  gave Updates 20 points and Uptime 15, so `fw.agit8or.net` lost 10 for its
  pending update and won back exactly 10 for its 13-day uptime, landing on the
  same score as the fully patched `home.agit8or.net` — which was itself penalised
  10 points for the short uptime that its update reboot had produced. Patch level
  is now the heaviest component (30) and uptime a minor stability signal (15) that
  can no longer offset it. Grades were recalibrated so a firewall with pending
  updates cannot reach an A: the two firewalls now score 98 (A+) and 84 (B+).

- **Uptime parsing only understood `"N days"`.** Anything shorter — `"6 mins"`,
  `"0d 0h 4m"` — fell through to the catch-all branch worth 5 of 15 points, so
  every recently rebooted firewall took a silent 10-point hit. The parser now
  handles `13 days`, `6 mins`, `0d 0h 4m`, `up 5 days, 03:14` and
  `1 day, 2 hours`, and an unparseable value scores neutrally instead of badly.

- **A long uptime is no longer a bonus.** Over 365 days now scores *below* a
  normal uptime, since unapplied kernel patches are the usual cause. A pending
  `reboot_required` is scored explicitly.

- **The health calculation existed twice and the copies disagreed.** `inc/health.php`
  was used for sorting while a ~180-line inline duplicate in `firewalls.php` was
  used for display, and only the inline copy checked for major upgrades — so
  sorting by Health could order rows differently from the numbers beside them.
  Worse, `firewalls.php` computed `$latest_major_version` *after* the health-sort
  block, leaving it undefined there. There is now one implementation
  (`calculateHealthReport()`), used by both `firewalls.php` and `dashboard.php`,
  and the fleet version is resolved before sorting.

- **Agent version reconciled to a single constant.** `AGENT_VERSION` (1.6.0) and
  `LATEST_AGENT_VERSION` (1.5.6) were two hand-maintained literals that had
  drifted apart, so no agent could ever match the target and every firewall was
  permanently capped at 18 of 20 agent points. `LATEST_AGENT_VERSION` is now an
  alias of `AGENT_VERSION`, and `AGENT_VERSION` is authoritative for "newest
  installable agent".

- **Reverted the premature agent bump to 1.6.0.** `plugin/.../agent.sh` declared
  v1.6.0 and no such tarball was ever published, so the version pointed at a
  download that does not exist. The label is back to 1.5.6; the health-collector
  code it was bumped for stays in the plugin source and ships with the next real
  agent release. `downloads/AGENT_VERSION.txt` read `3.6.0` — an *application*
  version — and fed the signed release manifest's `agent_version`, advertising an
  agent that never existed; corrected to 1.5.6. README and the in-app changelog no
  longer describe the health collector as a shipped agent 1.6.0 feature.

### Changed

- **`scripts/check_versions.php` now takes the newest released tarball in
  `downloads/plugins/` as the authority for the agent version**, not the
  in-source `agent.sh`. Pointing the UI at a version that was bumped in source
  but never packaged tells firewalls to fetch a download that does not exist.
  The check now also covers the installer's `PLUGIN_VERSION` and
  `downloads/AGENT_VERSION.txt` (which read `3.6.0`, an application version, and
  fed the signed release manifest), and reports an unreleased source bump rather
  than propagating it.

### Added

- **Migration `0015_revalidate_firewall_fks.sql` — forces the database to
  re-check every foreign key that references `firewalls`.** A `firewall_agents`
  row was found for firewall 25, deleted months earlier, in a table carrying
  `ON DELETE CASCADE`. InnoDB enforces that on every ordinary write, so it can
  only have arrived while `foreign_key_checks` was 0 — a restore or an import.
  The same event left ~65,000 orphaned telemetry rows for that one firewall in
  five other tables whose constraints also said it was impossible. MySQL and
  MariaDB have no `VALIDATE CONSTRAINT`, so a constraint bypassed that way stays
  permanently unverified; the only way to make the server check is to drop it and
  add it back with checks on, which is what this does.

  It runs in two phases: phase one counts orphans for all 24 constraints and
  aborts naming the first offender without touching DDL, so a constraint is never
  dropped that could not be re-added. Verified against a clone of production —
  the abort path leaves all 24 constraints intact, delete and update rules come
  back byte-identical, and a re-run is a no-op.

  Applied on 2026-08-31 after clearing the last orphans. All 24 constraints are
  back with identical rules, and are now enforced rather than merely declared:
  deleting a firewall cascades to `firewall_agents` as it should, and inserting a
  row for a firewall that does not exist is refused with `ERROR 1452`.

- **`ssh_access_sessions` orphans were cleared, not kept.** They were initially
  exempted in `check_referential_integrity.php` as history worth outliving the
  firewall. That was wrong on the schema's own terms: the FK is
  `ON DELETE CASCADE` and `firewall_id` is `NOT NULL`, so the constraint is
  explicit that those rows die with their firewall, and the exemption would have
  blocked migration 0015 permanently. 19 rows for deleted firewall 25 (2025-11-10
  to 11-12) were backed up and removed; 70 rows for live firewalls are untouched.
  Only `audit_log`, which carries no foreign key at all, is still treated as
  history.

- **`scripts/check_referential_integrity.php` — the recurring guard.** No
  constraint definition can stop a *future* restore from bypassing checks again,
  so revalidating once is not a fix on its own. This reports orphans across every
  table referencing `firewalls` (`--all` also covers the 21 tables carrying a
  `firewall_id` with no constraint at all, `firewall_commands` among them), knows
  that `audit_log` and `ssh_access_sessions` orphans are history rather than
  defects, and exits 1 on anything actionable. Run it after any restore.

- **`scripts/check_agent_install.php` — flags firewalls whose agent install is
  missing its `etc/` tree.** A firewall in this state keeps checking in normally,
  so nothing in the fleet view looks wrong; it needs its own check. The report
  works out which release was current on each firewall's enrolment date and flags
  it only when that release was one of the affected packages — in-place upgrades
  never remove the two files, so only the original install matters. `--probe`
  queues the new read-only `agent_install_verify` action (LOW risk, no params) to
  turn that inference into ground truth on the next check-in; `--json` for
  scripting, exit 1 when anything is confirmed broken.

  The affected-release list is not just hardcoded: the script scans the published
  tarballs and treats a missing `etc/` tree as affected only where it is a
  *regression* — a release that lost a directory an earlier one had. Releases
  before 1.1.1 never carried `etc/` and used a different install layout, so they
  are correctly ignored. That scan found this same regression had happened once
  before, in **1.2.7 through 1.3.1**, and was fixed by accident at 1.3.2 without
  anyone noticing either the break or the repair.

- **`scripts/migrate.php` emitted a comment-only statement in some files.** Its
  splitter recognises a line comment as `-- ` with a trailing space, so a bare
  `--` separator line accumulated into the statement buffer instead. Harmless
  where the header runs into ordinary SQL — MySQL treats a leading `--` line as a
  comment — but a file whose comment header is followed by `DELIMITER` flushed
  that buffer on its own, and the server rejects an empty query. Every existing
  migration has bare `--` lines and would have hit this the moment one of them
  opened a stored-procedure block. The splitter now drops statements with no SQL
  left in them; all 16 migration files parse to the same statement counts as
  before.

### Packaging

- **Rebuilt the 1.5.6 agent tarball with its missing `etc/` tree.** The published
  1.5.5 and 1.5.6 packages contained only `opnsense/`; every release from 1.3.x
  through 1.5.4 also shipped `etc/inc/plugins.inc.d/opnmanageragent.inc` and
  `etc/rc.d/opnmanager_agent`. `install_opnmanager_agent.sh` copies both out of
  the archive and checks neither `cp` exit code, so a fresh install from 1.5.6
  carried on past two silent failures, printed a non-fatal
  `WARNING: Missing files: opnmanageragent.inc`, then ran
  `sysrc opnmanager_agent_enable="YES"` for a service whose rc.d script had never
  been installed — no OPNsense plugin hook, no startup script. Existing firewalls
  were unaffected: they were installed from an earlier package and upgraded in
  place, which is why the fleet kept checking in normally.

  The rebuild is the published 1.5.6 `opnsense/` tree byte for byte, plus the
  `etc/` tree from 1.5.4 (identical across 1.5.0-1.5.4). No source code was
  repackaged, so the unreleased health collector is *not* in it. Verified by
  replaying the installer's file operations against the new archive: all four
  copies succeed and the verify step reports nothing missing.
  sha256 `08df3d2d...905eba6d`, 24439 bytes; the original is kept at
  `downloads/plugins/archive/os-opnmanager-agent-1.5.6.tar.gz.pre-etc-fix-20260831`
  (`downloads/` is gitignored, so it is not recoverable from git).

- **`plugin/.../etc/rc.d/opnmanager_agent` was behind the shipped copy.** The
  hardened start/stop logic (stale-pidfile cleanup, `pkill -9` of orphaned agent
  processes) went into the package at 1.5.0 but was never written back to source,
  so the next build from source would have regressed it on every firewall. Source
  now matches what ships.

---

## Version 3.20.0
**Released**: August 31, 2026 | **Agent**: v1.5.6

### Added

- **Backup retention is now enforced.** It has been configurable since 3.12.0 and
  applied by nothing. `backup_retention_days` was seeded at 90 by migration 0002
  and never read by any code; the settings UI wrote a separate
  `backup_retention_months` / `backup_retention_type` / `backup_min_keep` /
  `backup_max_keep` scheme that was also never read. Nothing anywhere deleted an
  old backup, so this installation was holding 519 backups going back to
  2025-11-10 under a nominal one-month policy.

  Retention is now a window in days (`backup_retention_days`, default 90, 0 to
  keep indefinitely) enforced by `cron/prune_backups.php`. The superseded
  months/count settings are removed by migration 0014, which carries an existing
  time-based policy across as months x 30 rather than silently widening it.

- **`backup_retention_min_keep` (default 3): the newest backups per firewall are
  never pruned, whatever their age.** Age alone is an unsafe deletion rule - a
  firewall that stopped checking in four months ago has nothing *but* backups
  older than the window, so a pure age sweep would delete every copy of its
  configuration at exactly the moment it is least recoverable.

- `cron/prune_backups.php` reports by default and deletes only with `--apply`,
  with `--days=` and `--floor=` overrides. Deleting configuration backups is not
  reversible, so the destructive mode is opt-in even for the scheduled job.

### Changed

- The backup retention settings dialog now asks for a window in days and a
  minimum to keep, replacing the two-mode months/count form whose values were
  never applied to anything.

### Fixed

- **A reboot was redelivered on every check-in, so one reboot request became a
  reboot loop.** `checkQueuedCommands()` resets any command sitting in `sent`
  for more than ten minutes back to `pending`, on the assumption that no result
  within that window means the agent never got it. That assumption does not hold
  for a reboot: the firewall stops executing partway through the command, so the
  agent that was going to POST the result dies with it. A result therefore never
  arrives, the command is reset to `pending`, and the firewall is handed its own
  reboot again the moment it finishes booting.

  Observed on `home.agit8or.net` on 2026-08-31: command 8017 (`/sbin/reboot`) was
  queued at 12:28:01, and its `sent_at` had already been refreshed to 12:39:25 —
  a second delivery — with a third due at ~12:49. The duplicate guard in
  `api/reboot_firewall.php` does not help here, because nothing is queuing a new
  command; the same row is being reissued.

  Commands that take the box down — `/sbin/reboot`, `/sbin/halt`,
  `/sbin/poweroff`, `shutdown -r/-h/-p` — are now settled as completed when they
  time out rather than reset to pending, and are excluded from the timeout reset
  in both the general and update-agent command paths. The absence of a result is
  recorded as the expected outcome, with a note saying so, instead of being read
  as a delivery failure.

### Added

- `agent_command_is_unacknowledgeable()`, `agent_unacknowledgeable_command_sql()`
  and `settle_unacknowledgeable_commands()` in `inc/agent_commands.php`, with
  `tests/agent_command_retry_test.php` covering the loop directly.

- `find_expired_backups()` and `prune_expired_backups()` in
  `inc/backup_storage.php`, both scoped to an optional firewall id, with
  `tests/backup_retention_test.php`.

---

## Version 3.19.4
**Released**: August 31, 2026 | **Agent**: v1.5.6

### Changed

- Reverted the 3.19.3 dashboard map changes. The new tiles and layout were worse
  than what they replaced; the network map is back to the OpenStreetMap basemap
  and its prior sizing.

---

## Version 3.19.2
**Released**: August 31, 2026 | **Agent**: v1.5.6

### Fixed

- **`reboot_required` was never measured, only guessed** — and was wrong on both
  production firewalls simultaneously, in opposite directions.

  The agent has never reported a reboot flag; `reboot` appears nowhere in
  `agent.sh`. So `agent_checkin.php` takes its "agent doesn't support reboot
  detection — preserve existing value" branch on every single check-in, leaving
  the column writable only by code that inferred it: the old update path set it
  to `1` the instant a request was handed to the agent, and the update-recovery
  branches set it to `0` whenever a firewall reappeared with status `updating`.
  Neither consulted the firewall.

  The result: `fw.agit8or.net` asserted "reboot required" continuously from
  2026-03-04 — for roughly six months, across many actual reboots, its uptime at
  the time of the fix being 13 days — because a March update request set the flag
  and nothing could ever clear it. Meanwhile `home.agit8or.net` reported *no*
  reboot needed immediately after installing a new base and kernel, with 187 days
  of uptime, because the recovery branch had cleared the flag.

  Reboot state is now derived from evidence the system actually has: the agent
  reports uptime, so the boot time can be estimated and compared against the
  completion time of the last update known to have installed successfully. A box
  that booted before that update has not started the new kernel and genuinely
  needs a reboot; with no successful update on record there is no evidence of a
  pending reboot and none is claimed.

- **An unreadable uptime no longer clears a real pending reboot.** The parser
  returns null rather than zero for `Unknown`, empty and unrecognised values —
  zero would have placed the boot instant at the check-in and silently marked
  every outstanding reboot as satisfied. When the state cannot be determined the
  stored value is left alone.

- **A failed update is not counted as installed.** The agent reports every
  command as `completed` regardless of outcome, so the derivation requires the
  `OPNMGR_UPDATE_EXIT=0` marker introduced in 3.19.1 rather than trusting command
  status.

### Added

- `inc/reboot_state.php`, with `tests/reboot_state_test.php` (22 assertions)
  covering uptime parsing, stale-flag recomputation, failed and unmarked updates,
  and the unreadable-uptime case. Wired into CI.

---

## Version 3.19.1
**Released**: August 31, 2026 | **Agent**: v1.5.6

### Fixed

- **"Upgrade Firewall OS/Dependencies" left no evidence it had run**, which made
  a successful upgrade indistinguishable from a failed one.

  The button set a `firewalls.update_requested` flag and immediately returned
  success, claiming a "standalone updater" would process it within a minute. No
  standalone updater exists — `firewall_updaters` has never had a row.
  `agent_checkin.php` then cleared that flag the moment it read it, *before* the
  agent had run anything, and set `updates_available = 0` and
  `reboot_required = 1` on the assumption it would work. The agent executed the
  upgrade detached under `nohup` and never reported a result, and no row was
  written to `firewall_commands`, so the command history showed nothing at all.
  The firewall list then reloaded two seconds later into a page with no trace of
  the request.

  Confirmed on a production firewall: the upgrade genuinely ran and succeeded
  (26.1.3 → 26.1.11_10), but because nothing surfaced it the operator concluded
  the feature was broken and clicked three more times, dispatching the upgrade
  again on each click.

  Updates are now dispatched through the same tracked command queue as every
  other agent action, so each request produces a command row whose real output
  and outcome are recorded and visible in the history.

- **A full upgrade could be killed partway through.** The agent executes queued
  commands as `eval "$cmd" 2>&1 | head -1000`. A real upgrade emits far more than
  1000 lines — the 26.1.3 → 26.1.11 run pulled 99 packages — and once `head`
  exits, the writing process receives SIGPIPE. Routing updates through the
  command queue therefore exposed them to a truncation hazard the old detached
  path did not have. `install_updates` now redirects the updater's own output to
  `/var/log/opnmanager_update.log` and returns only a bounded tail, so the
  updater's stdout never touches that pipe.

- **Every command was reported as successful.** The agent hardcodes
  `"status":"completed"` when reporting back and never captures or transmits the
  exit code, so a failed upgrade looked exactly like a successful one.
  `install_updates` now echoes its own exit status as a parseable marker, which
  the server reads to decide the real outcome; the marker is written into the log
  as well, so it survives a reboot that cuts the report short. An upgrade that
  reports back without a recognisable marker is recorded as `unconfirmed` rather
  than assumed to have worked.

- **The server asserted post-update state before any work had been done.**
  `agent_checkin.php` set `updates_available = 0` and `reboot_required = 1` at
  the moment a request was handed to the agent. A request that never executed
  still left the server reporting "no updates available, reboot required". Both
  values now come only from what the agent actually reports.

### Added

- Requesting an update while one is already pending or in flight no longer
  queues a second one; the endpoint returns the existing command id, so repeat
  clicks are harmless.

### Changed

- The firewall list shows the queued command number after a successful request
  and no longer reloads the page two seconds later — that reload erased the only
  feedback the operator had just been given.
- `api/update_firewall.php` is gated on the `update.install` capability rather
  than a blanket admin check, matching the other update paths.

### Removed

- `triggerOPNsenseUpdate()` and `triggerAgentUpdate()` in
  `api/update_firewall.php` — curl-based helpers that nothing ever called.

---

## Version 3.18.0
**Released**: August 31, 2026 | **Agent**: v1.5.6

### Fixed

- **The Security Status panel was hardcoded.** Every firewall displayed
  "SSH Access — Enabled — Port 22" and "API Authentication — Enabled" with green
  ticks, as static HTML, regardless of its configuration. A static green tick on
  a security panel is worse than no panel, because it gets read as evidence
  during an audit.

  It is now computed per firewall. SSH exposure distinguishes four states —
  service disabled, running with no WAN rule permitting it, WAN with source
  restrictions, and open to any source — because those carry very different risk
  and the old panel rendered all four identically. The panel also shows root
  login and password authentication settings, the WAN rules permitting SSH with
  their sources, and real agent authentication state.

  An unrecognised rule source is treated as open rather than assumed safe:
  guessing in the permissive direction on a security panel is the wrong way
  round.

- **Unreadable backups were silently treated as missing.** `resolve_backup_path()`
  returned null for both, so `drift_latest_backup()` quietly fell back to an
  older readable configuration. Backups live in a `www-data`-only directory, so
  anything running as another user answered from stale data with no indication —
  a configuration six months out of date was used to answer whether SSH was
  exposed to the internet.

  The two cases are now distinguished, including the case where a file cannot
  even be stat'd because an ancestor directory is not traversable.
  `drift_config_freshness()` reports which configuration an answer came from and
  whether newer ones were skipped, and the UI surfaces it.

### Changed

- The `ssh_on_wan` configuration check is split in two:
  `ssh_open_to_world` reports WAN rules permitting SSH from **any** source — the
  finding that actually matters — while `ssh_on_wan` remains informational and
  includes source-restricted management access. Reporting both identically is
  how a restricted management rule gets mistaken for an exposed service.

---

## Version 3.17.1
**Released**: August 27, 2026 | **Agent**: v1.5.6

### Removed

- `cron/check_offline_firewalls.php`, superseded by `cron/evaluate_alerts.php`.
  It emailed whenever a firewall had been silent longer than a threshold and a
  60-minute timer had elapsed, with no notion of an ongoing problem: it could
  not say "still down", never said "back online", and gave nobody anything to
  acknowledge. The evaluator opens and resolves incidents instead.

  It was scheduled in **both** the root and the administrator crontab at
  one-minute intervals, so it had been running twice a minute. Both entries are
  gone, replaced by a dated comment so the removal is discoverable rather than
  an unexplained gap in the schedule.

  Its 8.2 MB log file, written into the `cron/` directory inside the document
  root, has been removed with it.

---

## Version 3.17.0
**Released**: August 27, 2026 | **Agent**: v1.5.6

AI redaction, dashboard roll-ups and the closing security pass.

### Fixed - security

- **Configuration was sent to external AI providers unredacted.**
  `api/ai_scan.php` put the entire raw `config.xml` into the prompt. An OPNsense
  configuration carries user password hashes, X.509 private keys, WireGuard
  private keys, IPsec pre-shared keys, RADIUS and LDAP bind secrets and SNMP
  community strings. All of it was being transmitted to a third party, and none
  of it helps a model reason about whether a rule set is safe.

  `inc/ai_redaction.php` now strips credential material first. Structure is
  preserved so the model still sees that a key exists and where, along with
  non-secret context such as a certificate's description. Redaction cannot be
  switched off, and a configuration that fails to parse is refused rather than
  falling back to the raw document.

- **AI is now opt-in and off by default.** An administrator sees an explicit
  disclosure of what is and is not transmitted, and must acknowledge it before
  enabling. Nothing in the product depends on AI: configuration search, security
  checks, health, updates, drift, alerting and backups all work with it off.

- `api/tunnel_management.php` allowed any signed-in user, including a read-only
  one, to kill tunnels, reset all sessions and run privileged commands. It now
  requires the `tunnel.close` capability for state-changing actions.

- `ai_reports.php` called `unserialize()` on values originating in AI provider
  responses without restricting classes.

- Shell interpolation escaped across `system_backup.php`, `security_scan.php`,
  `update_docs_trigger.php` and the tunnel management scripts.

- `ai_settings.php` included the page header before checking authorisation, so
  the page shell was already on the wire when a redirect should have been sent.

### Added

- **Dashboard roll-up tiles**: reboots pending, gateways down, VPN tunnels down,
  configuration drift, backup failures, certificate expiry, critical incidents
  and firewalls in maintenance. A tile only appears when its count is non-zero,
  so the strip stays a triage surface rather than a wall of zeros, and each one
  links to the page that can resolve it.

- **`scripts/check_versions.php`** enforces a single authoritative version
  source. `VERSION` and the agent's own `AGENT_VERSION` line are authoritative;
  the README badge, `inc/version.php` constants and the CHANGELOG heading are
  derived and checked in CI, so they cannot silently drift apart again. `--fix`
  rewrites the derived references; a missing CHANGELOG entry is never
  auto-written.

- `tests/ai_redaction_test.php` — 36 assertions, every one of them about
  material that must not leave the server.

---

## Version 3.16.0
**Released**: August 27, 2026 | **Agent**: v1.5.6

Fleet update management, bulk operations and configuration search.

### Added

- **Fleet update view**: customer, site, current and available version, agent
  version, update and reboot state for every managed firewall, filterable by
  ring and by whether an update is pending.

- **Update rings** (canary, pilot, production). These are a rollout mechanism,
  not customer tiers: a canary firewall belongs to a customer like any other,
  and the ring says nothing about that customer's importance. Progression
  between rings is manual unless a campaign explicitly enables auto-progress,
  and a ring containing any failure never counts as clean - so automatic
  progression cannot roll a bad release onward.

- **HA-safe updates.** If two firewalls are a CARP pair, dispatching to both at
  once takes the customer offline. The dispatcher never dispatches to a firewall
  whose HA partner is mid-update, prefers the BACKUP member first so the MASTER
  keeps serving, and holds the second member until the first is back online with
  CARP settled. When health cannot be confirmed it holds with a stated reason
  rather than guessing.

- **Bulk operations** across selected firewalls. High-risk actions require
  typing a confirmation phrase that includes the target count, so confirming a
  3-firewall reboot does not also confirm a 300-firewall one. Raw shell is
  deliberately absent from the bulk catalogue: "run this command on every
  firewall" should not be reachable by accident.

- **Fleet configuration search**, deterministic and reproducible, over the
  stored configuration backups. Named checks answer the common questions (SSH
  reachable from WAN, web GUI on WAN, any-to-any pass rules, UPnP enabled, SSH
  password auth, missing DNS servers), alongside literal and CIDR matching. AI
  is not in the path.

- **Agent health**: version currency against the published release, check-in
  punctuality measured against the agent's own interval, authentication state,
  signing support and clock skew.

### Fixed - security

- The configuration restore path built its download URL from the
  client-supplied `Host` header, omitted the scheme entirely, and pointed at
  `download_backup.php`, which requires an operator's browser session that a
  firewall does not have. Restores now go through an agent-authenticated,
  single-use token endpoint.

### Added - restore safety

- The backup is validated and checksum-verified before anything is dispatched, a
  pre-restore snapshot is taken first, the firewall's hostname must be typed to
  confirm the target, and success is recorded only once the agent has checked in
  *after* the restore. A restore whose agent does not return within the
  verification window is failed rather than assumed successful.

---

## Version 3.15.0
**Released**: August 26, 2026 | **Agent**: v1.5.6

Incident-based alerting and maintenance windows.

### Added

- **Alerting is now incident-based.** An incident is one ongoing problem: opened
  when a condition first becomes true, updated while it persists, resolved when
  it clears. The previous behaviour sent an email whenever a condition was true
  and a crude 60-minute timer had elapsed, so it could never say "still down",
  never said "back online", and gave nobody anything to acknowledge.

- **Lifecycle**: OPEN / ACKNOWLEDGED / RESOLVED. Acknowledging stops
  notification without closing the incident, because the problem is still there.
  Incidents auto-resolve when the condition actually clears, and the released
  dedupe key means the same condition recurring later opens a *new* incident
  rather than reopening the old one - so separate outages stay separate.

- **Notification backoff** of 60m, 120m, 240m with a repeat limit. An offline
  firewall no longer notifies every couple of minutes forever.

- **17 alert types**: firewall offline, gateway down/degraded/flapping, VPN
  tunnel down, CARP fault, service stopped, certificate expiring/expired,
  sustained CPU/memory/disk, configuration drift, backup failure, update
  failure, outdated agent, and repeated agent authentication failures.

- **Maintenance windows** scoped to a firewall, a site or a whole customer,
  resolved hierarchically. During a window agents keep checking in, health keeps
  being collected and displayed, and incidents are still opened and recorded.
  Only the outbound notification is withheld, and the suppression is written to
  the incident's event trail. Discarding the events would lose the record of
  what happened during exactly the period somebody was working on the box.

- `cron/evaluate_alerts.php` asserts the current truth of every condition and is
  idempotent: running it more often produces the same incidents, not more of
  them. Installed at five-minute intervals.

- `tests/alerting_test.php` — 49 assertions covering deduplication, the
  lifecycle, acknowledgement, backoff, scope resolution and suppression.

### Fixed

- The maintenance lookup cache was per-process with no way to invalidate it, so
  a long-running evaluator, or a window created during a request, would read
  stale state. It now carries a generation counter that `maintenance_reset_cache()`
  bumps.

---

## Version 3.14.0
**Released**: August 26, 2026 | **Agent**: v1.5.6

Configuration drift and OPNsense-specific health.

### Added

- **Configuration drift**, built on the configuration backups the fleet already
  uploads rather than a parallel snapshot system. An operator marks a backup as
  the baseline; each firewall's newest backup is compared against it.

  The comparison is semantic, not textual. Two backups of an untouched firewall
  routinely differ by hundreds of lines purely in serialisation - `<item />`
  versus `<item/>`, quoting of the XML declaration, indentation - and the
  `<revision>` block is stamped on every save. Comparing text reports drift on a
  firewall nobody has touched. The XML is parsed into a canonical form with
  sibling order normalised and volatile fields removed, then hashed per section.
  On the reference installation this reduced 782 differing lines between two
  real backups to a single true finding.

  Section-level attribution, readable diffs that name a changed rule by its
  description, acknowledgement (which does not move the baseline), and
  promote-to-baseline. Detecting drift never restores anything.

- **Firewall health**: gateways (status, latency, packet loss, default gateway,
  and transition history so flapping is visible), VPN tunnels for WireGuard,
  OpenVPN and IPsec (state, peer, endpoint, latest handshake, byte counters),
  CARP/HA state rolled up per node with peer resolution, services, and
  certificate expiry with configurable 30/14/7 day thresholds.

  Only what an agent reported is shown. A firewall that does not run OpenVPN has
  no OpenVPN row rather than a permanently "stopped" one, and a firewall on an
  older agent reads as "not reporting health" rather than as a wall of failures.

- **The agent health collector** (`health_collect.py`, in the plugin source pending
  an agent release) collects the above. Each collector is
  independent and degrades to an omitted section rather than failing check-in.
  Certificate METADATA only: the `<prv>` private key element is never read.

- `tests/health_drift_test.php` — 48 assertions covering canonicalisation, noise
  rejection, drift attribution, ingestion, transitions, pruning and validation.

### Fixed

- Gateway latency and loss arriving with units (`"12.4 ms"`, `"0.0 %"`) were
  stored as NULL, because the server required a strictly numeric value.
- The drift differ compared a single repeated element against a list of them
  field by field, so adding one firewall rule reported one "modified" line per
  field of the first rule instead of one addition.
- Drift could not locate a current configuration on installations with long runs
  of backup rows whose upload never arrived; the lookup gave up after a fixed
  window instead of paging back to the newest readable file.

---

## Version 3.13.0
**Released**: August 26, 2026 | **Agent**: v1.5.6

MSP operations release: staff roles, the customer/site model and fleet search.

### Added

- **MSP staff roles.** Administrator, Technician and Read Only, defined once in
  `inc/permissions.php` as a capability-to-roles matrix. Unknown capabilities deny
  and are logged; a corrupted session role degrades to read-only. Structured
  commands derive their required capability from the catalogue's risk level, so a
  technician can restart a service but not reboot a firewall.
- **Customer and site model.** `Customer -> Site -> Firewall(s)` with real
  `customer_id` / `site_id` foreign keys. Customers gain a short code, active flag,
  timezone, tags and a default maintenance window; sites add name, code, timezone,
  address, notes and their own window. Customers remain organisational containers
  and have no accounts.
- **Global fleet search.** Header typeahead plus a results page. Qualifiers
  `customer:`, `site:`, `tag:`, `version:`, `agent:`, `ip:`, `interface:`, `vpn:`
  and `status:` combine with AND; a bare CIDR matches addresses inside the range,
  computed with INET_ATON rather than string prefixes.
- **Audit log UI** with filters for action, user, firewall, result and date range.
- `tests/msp_test.php` — 58 assertions covering roles, customers/sites and search.

### Fixed

- The Customers page counted firewalls by matching `customer_name`, which was empty
  for every firewall linked through `customer_group`, so customers with firewalls
  reported a count of zero. The delete guard used the same query, so such a customer
  could be deleted and orphan its firewalls.
- `customers.php` included the page header before checking authorisation, so the
  page shell was already on the wire when the login redirect was attempted.
- `users.php` wrote `$_POST['role']` into the database with no allow-list.
- System Update reported "update available" whenever the local commit differed from
  GitHub, including when the checkout was ahead of the remote. It now uses git
  ahead/behind counts and distinguishes up to date, behind, ahead and diverged.
- Update pulls ran `git pull origin main` regardless of the checked-out branch,
  ignored `git stash` failures, and never applied database migrations.
- The `04-customers.php.png` screenshot filename typo, the redaction patterns that
  blanked dotted identifiers and clock times, and theme forcing that produced two
  identical light-mode captures.

### Security

- The update wrapper ran `chmod -R a+r` over the production directory, making `.env`
  world-readable on every update, and `chmod 777` on the backups directory.
- The screenshot tool sat in the document root with the administrator password
  hardcoded in it and was publicly served over HTTPS.

---

## Version 3.12.0
**Released**: August 26, 2026 | **Agent**: v1.5.6

Security release. Full architecture in [SECURITY.md](SECURITY.md).

### Fixed - security

- **Remote code execution.** `api/upload_backup.php` wrote the agent-supplied filename
  into `/var/www/opnsense/backups`, which is inside the document root and matched by the
  nginx `location ~ \.php$` block, so an authenticated agent could upload a `.php` file
  and execute code as `www-data`. Backups now store outside the web root under
  server-generated names and must parse as an `<opnsense>` configuration.
- **Cross-firewall IDOR.** `api/command_result.php` authenticated the agent then updated
  `firewall_commands` with no firewall scoping, letting any agent finalise and overwrite
  another firewall's command result.
- **Unauthenticated tunnel access.** `tunnel_proxy.php` authenticated on
  `ssh_access_sessions.id`, an `AUTO_INCREMENT` integer, while describing it as
  "unguessable". Sessions now record an owner and a bearer token.
- **Unsigned update chain.** Agent updates ran `fetch -o - <url> | sh`. Now Ed25519-signed
  manifest plus SHA-256 per artifact, atomic install and rollback.
- **Weak agent credential.** `hardware_id` (md5 of hostid or WAN MAC) was the only
  credential, and `api_key` was unset so its check was a no-op. Per-firewall API keys and
  HMAC signing secrets, provisioned over the authenticated check-in and pinned on use.
- Unauthenticated root-executing endpoints removed or authenticated, including 13 stale
  incident scripts, three of which carried auth keys hardcoded in a public repository.
- `agent_token` was read but never verified on two telemetry endpoints.
- Path traversal in `api/repair_status.php` and `api/ssh_install_status.php`.
- `api/get_client_ip.php` preferred caller-controlled headers over `REMOTE_ADDR`.
- Maintenance scripts and data directories were web-reachable; `/scripts/*.php` returned
  its own source and `/backups/*.php` executed.
- `chmod -R a+r` in the update wrapper made `.env` world-readable on every update, and
  `chmod 777` made the backups directory world-writable.
- The screenshot tool in the web root contained the administrator password in plaintext
  and was publicly served.
- No CSRF on the login form; `session.cookie_secure` forced on while a plain-HTTP vhost
  was served; session user agent recorded but never checked.

### Fixed - bugs

- Configuration backup uploads had been failing since 2026-02-09: the queued command
  carried no credentials, so rows were created nightly but no file reached disk.
- `api/trigger_agent_update.php` called `verify_session()`, which is defined nowhere, so
  every agent-update request returned a 500.
- `api/get_commands.php` bound `LIMIT` as a parameter, which fails under native prepares.
- `agent_checkin.php` read `$firewall_status` about a hundred lines before it was
  assigned, clobbering in-progress `updating` state on every check-in.
- The System Update page compared commit hashes for inequality, so it reported "update
  available" whenever the checkout was *ahead* of the remote; it also trusted a
  hand-maintained `COMMIT` file that had drifted from the real HEAD. It now uses git
  ahead/behind counts and distinguishes up to date, behind, ahead and diverged.
- `git pull origin main` ran regardless of the checked-out branch, and a failed
  `git stash` was ignored. Pulls are now fast-forward only on the current branch's
  upstream and abort on a dirty tree.
- Database migrations were never applied after an update pull.
- Removed a hardcoded `firewall_id == 21` branch that falsified LAN IP, IPv6 and uptime.

### Added

- Central agent authentication (`inc/agent_auth.php`), used by 22 endpoints.
- Optional HMAC-SHA256 signed agent requests with nonce replay protection and a
  compatibility mode so installed agents are not disconnected.
- Secret encryption at rest (XChaCha20-Poly1305) keyed from `OPNMGR_MASTER_KEY`.
- Signed release manifests and a verifying agent installer with rollback.
- Structured remote operation catalogue with validated parameters
  (`api/queue_action.php`), alongside an explicitly privileged, audited raw shell path.
- MSP staff roles (Administrator, Technician, Read Only) defined in one capability matrix.
- Application audit log with central credential redaction.
- Database migration runner (`scripts/migrate.php`), idempotent and checksummed.
- Security regression suite (`tests/security_test.php`, 29 assertions) and GitHub Actions
  CI covering lint, dependency audit, ShellCheck, the suite, and an upgrade-path job.
- nginx hardening snippet and a CLI guard for maintenance scripts.

### Changed

- Backups are stored outside the document root with SHA-256 checksums and XML validation.
- Agent credentials moved to `agent_api_key` / `agent_api_secret`; `api_key` and
  `api_secret` return to meaning the OPNsense box's own REST API credentials.
- Documentation describes OPNManager as self-hosted MSP software; customers are
  organisational containers for grouping firewalls, not accounts.

---

## Version 3.11.8
_Released: August 6, 2026_

### Changes

- **Migration Now Drops the Obsolete `alert_recipients` Table** `database/alert_system_schema.sql`
  v3.11.7 stopped *creating* the table; this release also removes it from databases that still
  carry it, via `DROP TABLE IF EXISTS alert_recipients;`.

  **This makes the file destructive** — back up before running it. Any rows still in
  `alert_recipients` are discarded. The file header carries a prominent warning and the cleanup
  section documents exporting them (`SELECT * FROM alert_recipients;`) and recreating them under
  Alerts > Notifications first. `DROP TABLE IF EXISTS` makes it a no-op on databases that never
  had the table, so re-running remains safe.

  Verified across all three paths: an old database holding the table with data (dropped, exit 0),
  a re-run against the same database (no-op, exit 0), and a fresh install that never had it
  (exit 0). The five real `alert*` tables are untouched in every case.

---

## Version 3.11.7
_Released: August 6, 2026_

### Changes

- **Removed Obsolete `alert_recipients` Table** `database/alert_system_schema.sql`
  The table definition and its sample-recipient `INSERT` are gone. Nothing in the codebase
  referenced the table, and it existed in no database on the reference host — recipient
  configuration lives in `alert_notifications`. Running this file no longer creates a dead table.

  `database/schema.sql` was unaffected (it never contained the table, having been generated from
  the reference installation). Existing databases that still carry the orphan table are left
  untouched; the file header documents the manual `DROP TABLE IF EXISTS alert_recipients;` for
  anyone who wants to clean it up.

---

## Version 3.11.6
_Released: August 6, 2026_

### Changes

- **Published Remaining Upgrade Migrations** `database/alert_system_schema.sql`, `database/migrate_v3.4.0.sql`
  Both were still excluded by the `*.sql` ignore rule. They are only needed by installations that
  predate the alert system and agent v3.4.0 respectively — `database/schema.sql` already covers both
  for fresh installs — but they are now tracked so existing deployments can upgrade from a clone.
  Each carries a header stating that fresh installs do not need it. The `*.sql` negation was widened
  to `database/*.sql`, with `database/credentials*.sql` explicitly re-ignored.

  Noted while publishing: the `alert_recipients` table in `alert_system_schema.sql` is obsolete —
  no PHP file references it and it does not exist in the reference installation (recipient
  configuration now lives in `alert_notifications`). It is retained so the file still applies
  cleanly to an old database, and flagged in the file header.

---

## Version 3.11.5
_Released: August 6, 2026_

### Bug Fixes

- **Database Schema Missing From Repository** `.gitignore`, `database/schema.sql` ([#6](https://github.com/agit8or1/OPNMGR/issues/6))
  A blanket `*.sql` ignore rule meant `database/schema.sql` was never committed, so the install step
  documented in the README (`mysql -u root -p < database/schema.sql`) failed on every fresh clone.
  Added negation rules for the shipped schema and migrations, and published a complete, current
  schema: all 59 tables plus the `v_firewall_wan_status` view and the static reference data
  (agent command allowlist, feature catalogue). The stale v2.0.0 schema covered only 9 tables.
  _Fixed by: Claude Code_

- **README Install Steps Referenced Non-Existent Files** `README.md`
  Quick Start told users to copy `inc/db.php.example`, which does not exist — configuration is via
  `.env`. Rewritten with the real sequence: composer install, schema import, database user creation,
  `.env` setup, and admin account creation.
  _Fixed by: Claude Code_

### New Features

- **First-Admin Bootstrap Script** `scripts/create_admin.php`
  A fresh install had no way to create the initial administrator. This CLI script creates an admin
  account interactively (with hidden password entry) or from arguments, using the same
  `password_hash()` scheme `inc/auth.php` verifies against.

- **Schema Generator** `scripts/generate_schema.sh`
  Regenerates `database/schema.sql` from a live installation so the published schema cannot drift
  again. Output is idempotent and carries no user, firewall, credential or customer data.

---

## Version 3.8.6
_Released: February 24, 2026_

### Critical Bug Fixes

- **Reboot Required Never Clearing** `agent_checkin.php`
  `reboot_required` flag could never be cleared by agent check-ins because the code read from `$_POST` (empty for JSON requests) instead of `$input`. Once set to 1, it was permanent.
  _Fixed by: Claude Code_

- **Updates Available Stuck After Manual Update** `agent_checkin.php`
  `updates_available=1` persisted even when `current_version` matched `available_version`. Added sanity check to clear stale flag.
  _Fixed by: Claude Code_

- **Update Button Click Did Nothing** `firewalls.php`
  `event.target` hit the `<i>` icon inside the button, not the button itself. Fixed with `.closest('button')`.
  _Fixed by: Claude Code_

- **check_updates.php Was Demo Code** `api/check_updates.php`
  Endpoint used hardcoded version strings and `rand(0,1)`. Rewritten to trigger real update check on next agent check-in.
  _Fixed by: Claude Code_

### New Features

- **Update Status Animation** `firewalls.php`
  Animated "Updating..." state with progress bar and status text in the Updates column when firewall is updating. Status column shows blue spinning badge.

- **Clickable Reboot Required Badge** `firewalls.php`
  "Reboot Required" badge is now a clickable button that triggers a firewall reboot with confirmation dialog.

- **Toast Notifications** `firewalls.php`
  Replaced browser `alert()` dialogs with styled toast notifications for update, reboot, and check actions.

- **Chart Timeframes: 1h, 4h, 12h** `firewall_details.php`
  Added 1 Hour, 4 Hours, and 12 Hours to the time frame dropdown. All chart APIs updated from days to hours parameter with adaptive aggregation intervals.

- **Stuck Update Auto-Recovery** `agent_checkin.php`
  Firewalls stuck in `updating` status for >15 minutes auto-recover to `online` on next agent check-in.

- **Force Update Check on Reboot** `agent_checkin.php`
  When `reboot_required` transitions from 1→0 (firewall rebooted), forces immediate update check instead of waiting 5 hours.

### Improvements

- Health score no longer penalizes for missing OPNsense API credentials (agent system doesn't use them)
- Reboot API rewritten with JSON support, CSRF validation, admin requirement, and duplicate command prevention
- Added missing `checkUpdates()` JavaScript function
- "Reboot Required" badge hidden during active updates (redundant)

### Files Modified
- `agent_checkin.php` - $_POST→$input fix, sanity checks, stuck recovery, reboot transition
- `firewalls.php` - Update animation, reboot button, toast notifications, health score fix
- `api/update_firewall.php` - No changes (was already correct)
- `api/check_updates.php` - Complete rewrite
- `api/reboot_firewall.php` - Complete rewrite with JSON/CSRF support
- `firewall_details.php` - Added 1h/4h/12h timeframes
- `api/get_traffic_stats.php` - Hours parameter, adaptive aggregation
- `api/get_system_stats.php` - Hours parameter, adaptive aggregation
- `api/get_latency_stats.php` - Hours parameter, adaptive aggregation
- `api/get_speedtest_results.php` - Hours parameter

---

## Version 3.6.0
_Released: February 11, 2026_

### New Features

- **Configurable Speedtest Intervals** `firewall_details.php`, `schedule_speedtest.php`
  Per-firewall speedtest scheduling with configurable intervals: every 2, 4, 8, 12, or 24 hours, or disabled entirely. Default is every 4 hours. Replaces the previous random once-daily scheduling with interval-based logic. Includes deduplication to prevent queuing when a test is already pending.
  _Implemented by: Claude Code_

### Database Changes

- Added `speedtest_interval_hours` column to `firewalls` table (INT, default 4)
  - `0` = disabled, `2/4/8/12/24` = hours between tests

### Files Modified
- `/var/www/opnsense/firewall_details.php` - Added speedtest interval dropdown and POST handler
- `/var/www/opnsense/api/schedule_speedtest.php` - Rewritten with interval-based scheduling logic
- `/var/www/opnsense/inc/version.php` - Version bump to 3.6.0

---

## Version 2.2.3
_Released: December 11, 2025_

### 🐛 Bug Fixes

- **Tunnel Proxy HTTPS Protocol Support** `tunnel_proxy.php v2.0.2`
  Fixed "Empty reply from server" errors in tunnel proxy system. Root cause: tunnel_proxy.php was using HTTP to connect to HTTPS-only SSH tunnels (port 443). Updated both initial requests (line 122) and redirect handlers (line 414) to use correct protocol based on firewall's web_port setting. After-login redirects now work correctly.
  _Fixed by: Claude Code_

- **SSH Tunnel Duplicate Process Prevention** `infrastructure`
  Resolved issue where multiple SSH tunnel processes were being created on the same port, causing connection conflicts. Implemented cleanup of duplicate tunnels before establishing new connections.
  _Fixed by: Claude Code_

- **OPNsense Agent Stability** `agent`
  Resolved agent check-in failures on home.agit8or.net (FW 48). Agent was being killed by reinstall commands without proper restart. Implemented proper service restart procedures.
  _Fixed by: Claude Code_

### 🎨 User Interface

- **About Page Enhancement** `about.php`
  Enhanced version information display to show all version numbers in organized sections. Now displays Application Versions (app, agent, tunnel proxy, database, API) and Dependencies (PHP, Bootstrap, jQuery) with color-coded badges for better visibility.
  _Improved by: Claude Code_

### 📦 Technical Details

**Files Modified:**
- `/var/www/opnsense/tunnel_proxy.php` (v2.0.1 → v2.0.2)
  - Line 122: Initial curl_init now uses `{$protocol}://` instead of hardcoded HTTP
  - Line 414: Redirect handler now uses `{$protocol}://` instead of hardcoded HTTP
  - Line 512: Debug logging updated to show correct protocol
  - Protocol determination: `($web_port == 443) ? 'https' : 'http'`

- `/var/www/opnsense/inc/version.php`
  - APP_VERSION now reads from VERSION file (not hardcoded)
  - Added TUNNEL_PROXY_VERSION constant (v2.0.2)
  - Corrected AGENT_VERSION from 3.7.7 to 1.4.0
  - Added tunnel_proxy to getVersionInfo() array
  - Updated getChangelogEntries() with v2.2.3 release

- `/var/www/opnsense/about.php`
  - Removed deprecated "Update Agent" section
  - Added comprehensive version display
  - Added Dependencies section (PHP, Bootstrap, jQuery)
  - Improved visual organization with badges

- `/var/www/opnsense/doc_viewer.php`
  - Updated "about" page version display to show all version numbers
  - Added Agent release date and min supported version
  - Added Tunnel Proxy, Database Schema, and API versions
  - Added Dependencies section (PHP, Bootstrap, jQuery)
  - Changed title from "Version Information" to "Application Versions"

**Components Affected:**
- Tunnel Proxy System
- SSH Tunnel Management
- OPNsense Agent (v1.4.0)
- Version Management System

**Upgrade Notes:**
- No database migrations required
- PHP opcache cleared automatically on deployment
- Existing active tunnel sessions continue to work
- No action required from users

---

## Version 2.4.0
_Released: September 17, 2025_

### 🚀 Improvements

- **Sidebar Menu Removed** `ui`
  Removed duplicate sidebar navigation menu to simplify interface and reduce clutter. Main navigation now consolidated to header menu only.
  _by system_


---

## Version 1.0.1
_Released: September 16, 2025_

### 📦 Updates Applied

- **Marketing Website Disable Update** `Agent`
  Added marketing website disable functionality to agent. Updated agent script to automatically disable port 88 services. Added disable_marketing_website.sh script for manual execution. Improved security for managed firewall deployments.
  _by OPNmanager System_


---
