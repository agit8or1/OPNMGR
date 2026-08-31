# OPNManager Changelog

All notable changes to OPNManager are documented here.

**Last Updated**: August 31, 2026

---

## Version 3.19.2
**Released**: August 31, 2026 | **Agent**: v1.6.0

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
**Released**: August 31, 2026 | **Agent**: v1.6.0

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
**Released**: August 31, 2026 | **Agent**: v1.6.0

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
**Released**: August 27, 2026 | **Agent**: v1.6.0

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
**Released**: August 27, 2026 | **Agent**: v1.6.0

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
**Released**: August 27, 2026 | **Agent**: v1.6.0

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
**Released**: August 26, 2026 | **Agent**: v1.6.0

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
**Released**: August 26, 2026 | **Agent**: v1.6.0

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

- **Agent 1.6.0** collects the above via `health_collect.py`. Each collector is
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
