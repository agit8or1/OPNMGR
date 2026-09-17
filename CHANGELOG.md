# OPNManager Changelog

All notable changes to OPNManager are documented here.

**Last Updated**: September 16, 2026

---

## Version 3.53.0
**Released**: September 17, 2026 | **Agent**: v1.6.7

### Fixed

**A CLI-initiated audit entry never said who acted.**

The rollout entries added in 3.52.0 showed it immediately:

```
actor_type: system
  username: NULL
   message: agent 1.6.7 promoted to stage 'fleet' (was 'held' for 1.6.7); reaches 1 firewall(s)
```

The log recorded what had been done and to what, but nobody was attached to it.
For a promotion that releases an agent to the whole fleet, *who* is most of the
point.

```
actor_type: system
  username: administrator
   message: agent 1.6.7 promoted to stage 'fleet' ...
```

- **`audit_log()` falls back to the operating system user when there is no
  session**, so every CLI call site gains this rather than only the one that
  prompted it. A web request still resolves its actor from the session exactly as
  before.
- **`actor_type` stays `system`.** It is an `ENUM('user','agent','system',
  'anonymous')` and a CLI operator is not a portal user with a `user_id`. The
  audit page already prefers `username` over `actor_type` when one is set, so
  filling in the name was enough and needed no migration.
- **Under sudo, the entry names the person and what they ran as** -
  `administrator (sudo root)` - rather than recording a fleet-wide release as
  `root`. Truncated to the `varchar(64)` the column allows.

### Added

- Eight assertions in `tests/settings_audit_test.php`, including running the
  resolver under a simulated sudo invocation.

---

## Version 3.52.0
**Released**: September 17, 2026 | **Agent**: v1.6.7

### Added

**Agent rollout stage changes are written to the audit log.**

Promoting is the act that lets a release reach firewalls. Until 3.51.0 it
happened implicitly on publish, with no record anywhere of who released what to
whom - the only way to know an agent version had gone out was to notice the
version change on a firewall afterwards.

```
agent 1.6.7 promoted to stage 'fleet' (was 'held' for 1.6.7); reaches 1 firewall(s)
```

```json
{
    "version": "1.6.7",
    "stage": "fleet",
    "previous_stage": "held",
    "previous_version": "1.6.7",
    "effective_before": "held",
    "reaches": 1
}
```

- **The entry records the transition, not the destination.** Previous stage,
  previous version, and the stage that was actually in effect, because "promoted
  to fleet" alone does not say what changed.
- **It records how many firewalls the promotion reaches**, counted before the
  write. That is the question worth asking of the log later: not which stage was
  chosen, but who it opened the update to.
- **Pilot changes are audited** as `agent.rollout.pilot`, naming the firewalls.
  At stage `pilot` that setting is what decides which firewalls a release
  reaches, so it belongs in the same trail.
- **A rejected stage and a failed settings write are audited as failures.** A
  refused promotion is worth a record too.
- Ten further assertions in `tests/agent_rollout_test.php`.

---

## Version 3.51.0
**Released**: September 17, 2026 | **Agent**: v1.6.7

### Found

**Publishing an agent version was deploying it.**

There was no step between the two. Syncing `AGENT_VERSION` to production made the
server advertise it, and every firewall fetched and installed it on its next
check-in:

```
19:24:36  POST /agent_checkin.php               200  642    <- 1.6.7 advertised
19:24:36  GET  install_opnmanager_agent.sh      200  9616
19:24:36  GET  os-opnmanager-agent-1.6.7.tar.gz 200  33599
```

About two minutes from publish to installed, fleet-wide. So "release the agent"
and "change every firewall right now" were the same action, with no way to do the
first without the second. It fired twice on 2026-09-16: 1.6.6 installed itself on
fw48 unprompted, and 1.6.7 did the same while the revert was being typed - that
download had completed 45 seconds earlier.

### Added

- **A rollout stage** - `held`, `pilot` or `fleet` - consulted by
  `agent_checkin.php` before an update is offered. Holding suppresses
  `agent_update_available`, the field the agent acts on, so a held version is
  never installed.
- **`scripts/agent_rollout.php`** shows what is held and who is behind it, marks
  pilot firewalls, and promotes a version. It reports by default and moves only
  with `--apply`, printing which firewalls a promotion would reach first:

  ```
  $ php scripts/agent_rollout.php
  Published agent version : 1.6.7
  Rollout stage           : held
    stored stage 'held' was promoted for (no version), so 1.6.7 is held.
    Publishing does not deploy: promote this version by name to release it.

  1 firewall(s) behind 1.6.7:
    held   fw51   edge-fw.example.net      1.6.3
  ```

- **`firewalls.agent_rollout_pilot`**, defaulting to 0. Stage `pilot` with nobody
  marked offers the update to nobody rather than everybody: the failure mode has
  to be the conservative one.
- `database/migrations/0021_staged_agent_rollout.sql`, defaulting to held with no
  version promoted, so an installation upgrading into this gets the gate closed.
- `tests/agent_rollout_test.php`.

### Changed

- **The stage is bound to a version, not left as a standing mode.**
  `agent_rollout_stage` applies only to `agent_rollout_version`. When a newer
  version is published the stored stage no longer matches it, and the new version
  is held.

  This is the part that matters. A plain on/off flag would sit at `fleet` after
  the first release and deploy every release after that on publish - the original
  behaviour, one forgotten setting away. Binding the stage to a version makes the
  safe state the one you get by forgetting, and makes promoting a release a
  deliberate act naming what it promotes.

- **Holding an update does not hide it.** The check-in response carries
  `agent_update_held` and the stage, because suppressing the offer must not trade
  one silent surprise for another.
- An unrecognised stage degrades to `held`, so a typo in a setting cannot release
  to the fleet.
- `scripts/build_agent_package.sh` prints the promote step after a build, so the
  gate is discoverable at the moment it matters.

---

## Version 3.50.0
**Released**: September 16, 2026 | **Agent**: v1.6.7

### Fixed

**`tail` on the agent log returned script text instead of what the agent was doing.**

Found while verifying the 1.6.6 watchdog. The check asked for the last twelve log
lines and got back the verification script itself:

```
--- last 12 agent log lines ---
ps -o pid,command -ax | grep "daemon: /usr/bin/true" | grep -v grep
pkill -f "daemon: /usr/bin/true" 2>/dev/null
rm -f /tmp/opnmgr-probe.pid
...
```

The agent logged the full body of every queued command, raw:

```sh
log_message "Executing command $cmd_id: $cmd_data"
```

A scripted command - the nightly backup, an install, any of the probes in
`scripts/` - therefore wrote dozens of unprefixed lines into the log that read
like entries and were not. The installer's output was appended into the same
file on top of that.

This was not only cosmetic. **The watchdog decides whether the agent is healthy
by grepping this log** for a recent successful check-in, so a logged command body
could push real entries out of its window or contribute a matching line of its
own. Command bodies can also carry credentials, and the command's result is
reported to the manager regardless, which is where it belongs.

- **`log_message()` collapses newlines, carriage returns and tabs**, so one call
  is exactly one line whatever it is handed. That invariant, rather than the
  discipline of each call site, is what makes the log safe to grep.
- **`command_summary()`** logs a bounded description in place of the body - line
  count, byte count, and a 100 character excerpt. A 50KB single-line command is
  still one bounded entry.
- **Update transcripts moved to `/var/log/opnmanager_agent_update.log`**, rotated
  on the same 10MB cap, with a one-line pointer left in the agent log. Installer
  output was burying the agent's own entries at the moment they mattered most.
- **The agent self-update path no longer discards its output.** That is the path
  that performs an actual fleet upgrade, and it wrote nothing anywhere: when
  1.6.6 installed itself on fw48 the only evidence was the version changing in a
  later check-in.

### Added

- `tests/agent_log_hygiene_test.php` extracts the agent's own logging functions
  and runs them against a realistic multi-line command, asserting that three
  calls produce three lines, that every line carries a timestamp prefix, that a
  watchdog-style grep still finds both check-ins either side of a logged command,
  and that the body never reaches the log verbatim.

---

## Version 3.49.0
**Released**: September 16, 2026 | **Agent**: v1.6.6

### Found

**Every way the agent could stop was permanent.**

The agent's self-update path has always ended like this:

```
log_message "Restarting to use new version..."
# Clean up PID file and exit - let rc.d restart us
rm -f "$PID_FILE"
exit 0
```

rc.d did not restart it. It launched the agent with `daemon -p ... -f`, without
`-r`, so daemon(8) forked the agent and exited. There was no supervisor. The
comment described an arrangement that did not exist.

`watchdog.sh` was written for exactly this case. It has shipped in every package
since it was written and has never run on a single firewall. The only thing that
ever asked for its cron entry was a comment in its own header:

```
# Run this from cron every 5 minutes: */5 * * * * .../watchdog.sh
```

Nothing read that comment. The installer copied the file and scheduled nothing.

So an agent that exited stayed exited, and the only recovery was console access
to a firewall whose entire purpose is not needing any. On 2026-09-16 fw51
stopped at 12:02:40 after a clean run of 200-response check-ins and stayed down.
fw48 had done the same two days earlier and came back only when a queued
reinstall finally ran, twelve hours after it was sent. Neither firewall needed a
fix on the firewall. Both needed something to notice.

### Fixed

- **daemon(8) supervises the agent.** `daemon -r -R 15` restarts it after a
  crash or a self-update, which is what the agent's own comment has always
  claimed. The 15 second delay keeps a persistently failing start to four
  restarts a minute rather than a hot loop.
- **Stopping kills the supervisor first.** Killing only the agent is precisely
  the event the supervisor reacts to, so `service opnmanager_agent stop` would
  otherwise be undone within seconds. The supervisor's pid is recorded
  separately with `-P`.
- **The installer schedules the watchdog**, every 5 minutes, idempotently, and
  the uninstaller removes it. It goes in root's crontab rather than
  `/etc/crontab`, because OPNsense regenerates that from `config.xml` on every
  configuration apply and would silently drop the entry.
- **The watchdog's health test was wrong**, which did not matter while it never
  ran and matters now. It asked whether a successful check-in appeared in the
  last 20 log lines - something a busy but perfectly healthy agent fails. Every
  five minutes, that verdict would restart a working agent forever. It now
  measures the age of the last successful check-in against five check-in
  intervals with a 15 minute floor: the agent backs off up to 300s per check-in
  on errors, so a firewall that has merely lost its uplink must not be restarted
  in a loop. A deliberately disabled agent, which logs no check-ins on purpose,
  is left alone.
- **The installer restarted the agent only if it was already running** - the one
  case that needed no help. It now restarts unconditionally, so a reinstall
  recovers a dead agent. The restart is detached and delayed by 25 seconds
  because this script usually runs as a command dispatched by the agent itself,
  and restarting synchronously kills the process that has to report the result.

### Changed

- A disabled or unconfigured agent now waits and re-reads its configuration
  instead of exiting. Under supervision, exiting would mean a restart every 15
  seconds; on the old unsupervised setup it meant that enabling the agent in the
  GUI still required a manual service start, because the process that would have
  noticed had already exited.

### Added

- `tests/agent_supervision_test.php` reads the shipped scripts and asserts all
  three links in the chain exist: the supervisor restarts, the watchdog is
  scheduled, and the package contains the watchdog that the cron entry points
  at.

---

## Version 3.48.0
**Released**: September 16, 2026 | **Agent**: v1.6.5

### Fixed

**Two scheduled jobs stopped running on 2026-09-13 and nothing said so for three days.**
Their crontab lines redirect output into a file under `/var/log`. After a rotation the
user running them could no longer create that file, so every cycle cron started a shell,
the shell failed on the redirect, and the PHP never ran:

```
2026-09-16  syslog:  (administrator) CMD (php .../monitor_agent_health.php >> /var/log/agent_health.log 2>&1)
            file:    /var/log/agent_health.log — does not exist
            last run: 2026-09-13
```

cron reported success, because the shell it started exited. `monitor_agent_health.php` is
the only thing that maintains `firewalls.status`, so a firewall that had been silent since
noon still read `online` six hours later, and `api/schedule_speedtest.php` — which selects
on that column — kept queueing work for it.

**The registry only knew the six jobs someone listed.** 3.32.0 gave every cron entrypoint
a way to report its start, outcome and duration, and wired up the six that were known
then. The crontab carries fourteen. The other eight — including both jobs that died —
reported nothing, appeared on no page, and had no state that could look wrong.

**A job dead for days displayed as "Running".** The Scheduled Jobs page read the recorded
status before the age of the last run. A job killed mid-run, or whose host rebooted,
leaves `running` behind permanently; that is the exact state a stopped job is most likely
to be in, and it rendered as a blue "in progress" badge forever.

### Added

- **`job.stale` alerts.** The scheduled jobs are what detects everything else, so their own
  silence now raises an incident like any other fault, and resolves when the job reports a
  run. A job is watched only from its first recorded run: this application does not own the
  crontab and cannot know which jobs an operator chose to schedule, so an unscheduled job
  stays quiet rather than alerting forever.
- **The remaining eight entrypoints report themselves** — `nightly_backups`,
  `monitor_agent_health`, `auto_reset_stale_agents`, `tunnel_health_monitor`,
  `ssh_access_cleanup`, `nginx_tunnel_cleanup`, `schedule_speedtest` and `run_auto_scans`.
  For the two scripts that are also operator tools, only the `cleanup` subcommand counts as
  a scheduled run; for `api/schedule_speedtest.php`, only a CLI invocation does.
- **`cron_jobs_overdue()`**, and 15 assertions in `tests/scheduled_jobs_test.php` covering
  the arming rule, the two-cycle tolerance, a row stuck at `running`, the exact shape of
  this outage, and that every name an entrypoint reports is registered by a migration —
  a job reporting a name no row carries writes its status to nothing.

### Known limitation

The alert evaluator is itself a scheduled job. It reports that the others have stopped; it
cannot report its own death. Watching that needs something outside this application.

---

## Version 3.47.0
**Released**: September 16, 2026 | **Agent**: v1.6.5

### Fixed

**A command waiting for an offline firewall is not a stuck command.** The hourly sweep
failed anything pending for more than an hour. Against a live firewall that is right — it
should have been collected at the next check-in, so an hour means something went wrong.
Against a firewall that is down it is wrong twice over: the command is not stuck, it is
waiting, and the thing most likely to be queued for a firewall that has gone quiet is the
instruction that would bring it back.

That happened on 2026-09-15. A firewall lost its agent during the 1.6.4 rollout, the
recovery install sat pending, and the sweep failed it at the one hour mark. Had the
firewall returned after that it would have rejoined still running the broken agent, with
nothing queued to fix it, and the recovery would have looked like it simply did not work.

The one-hour rule now applies only to firewalls that have checked in within the last
fifteen minutes. Commands for an offline firewall are held.

**An agent install can never report its own result.** It stops the agent, unpacks, and
starts it again — the process executing the command is killed partway through. That is
the same property as a reboot, and reboots have been exempt from redelivery since 3.20.0.
Agent installs were not. The command sat in `sent`, the ten-minute sweep returned it to
`pending`, and the firewall reinstalled its agent again:

```
one firewall, 2026-09-15 — install → sent → (no result) → pending → install → …
```

`install_opnmanager_agent.sh`, `pkg install os-opnmanager-agent` and
`opnmanager_agent restart` now join the reboot family in both the SQL sweep and the PHP
settler.

**The README's Agent row was the one version reference CI did not check.** The
compatibility table's Application row was covered in 3.31.0 after it drifted; the Agent
row one line below it was not, and it sat at v1.6.2 while v1.6.5 was published — three
releases, in the same paragraph that claims "no reference in the tree can drift out of
step."

### Added

- **A seven-day absolute cap on pending commands.** Long enough for any recovery, short
  enough that a decommissioned firewall does not collect a backlog. Without it, holding
  commands for offline firewalls would hold them forever.
- **`tests/command_sweep_test.php`** (11 assertions, in CI), and three more in the retry
  suite asserting that `agent_unacknowledgeable_command_sql()` and
  `agent_command_is_unacknowledgeable()` exempt the same commands. The sweep and the
  settler disagreeing about a single command is exactly how the loop began.

---

## Version 3.46.0
**Released**: September 15, 2026 | **Agent**: v1.6.5

### Added

**The agent signs its requests.** The server has verified HMAC-SHA256 signatures since
3.12.0 — freshness window, nonce replay rejection, per-firewall ratchet, three policy
modes — and has been handing every agent its signing secret and the canonical string with
the note *"Sign requests once supported."* No agent release ever did. Until now TLS was
the only thing protecting command delivery, and anyone holding a firewall's `hardware_id`
and `api_key` could impersonate it.

The agent also **adopts the credentials the server has been sending it all along**. It
previously authenticated with `hardware_id` alone — a value derived from the hardware,
not a secret. It now stores the key and secret at `0600` and presents the key on every
request.

**Credentials are adopted before they are used.** The first check-in after an upgrade
sends nothing new and stores the response; every check-in after that is signed. An agent
never sends a signature it cannot yet compute — which matters, because `compatibility`
mode verifies a signature *whenever one is present*, so a malformed one refuses the
check-in and the firewall goes quiet.

### Fixed

**1.6.4 converted only the check-in, and that was not enough.** Presenting the api_key
*ratchets* it: from the first request that carries it, the server requires it on **every**
endpoint for that firewall. The agent has three POST sites, and command results and
speedtest results still sent `hardware_id` alone — so they were rejected as
`api_key_missing` and the queue filled with commands stuck at `sent`. The audit log caught
it in two consecutive lines:

```
21:34:29  agent.credentials.confirmed   key authentication is now mandatory for this firewall
21:34:30  agent.auth.failed             api_key_missing
```

This was found **on one firewall during a staged rollout**, before the fleet was ever
offered the update. Had 1.6.4 been advertised, both firewalls would have lost command
result reporting, and the fix would have had to travel through the channel that was
broken.

1.6.5 routes all three POSTs through a single credentialed helper. **No raw
`curl … -X POST` remains in the agent**, and the suite asserts that, so a new endpoint
cannot be added without credentials by omission — which is precisely how this happened.

### Added

- **`tests/agent_request_signing_test.php`** (17 assertions, in CI). It does not inspect
  the shell for plausible-looking code: it extracts the agent's own functions, runs them,
  and checks the result against the server's computation. Covers that no signature is
  produced before credentials exist, that the secret is written unreadable to others, that
  each request gets a fresh nonce (a repeated one is rejected as a replay, refusing the
  agent), and that no POST bypasses the helper.

### Changed

- **The 3.43.0 assertion that no released agent could sign failed the moment 1.6.4 was
  built** — which is exactly what it was written for. It recorded a premise and demanded
  the premise be revisited when it changed. The assertion and the banner wording, which
  said signing was "server-side only", were updated together.

---

## Version 3.45.0
**Released**: September 15, 2026 | **Agent**: v1.6.3

> **Nothing is deleted by this release.** The pruner reports unless you pass `--apply`,
> and it is not in any crontab. On the maintainer's installation it reports **933,672
> rows** older than 90 days — about 75% of the database.

### Found

**Nothing had ever pruned the tables that grow forever.** After nine months of watching
two firewalls, four tables were 96% of a 174 MB database:

| Table | Rows | Size |
|---|---:|---:|
| `system_logs` | 404,516 | 80.6 MB |
| `firewall_traffic_stats` | 299,045 | 34.6 MB |
| `firewall_system_stats` | 323,294 | 28.1 MB |
| `firewall_latency` | 324,265 | 19.0 MB |

That is roughly 1,800 rows per firewall per day with no upper bound. For a tool positioned
at fleets it is the wrong shape — fifty firewalls would add about 33 million rows a year,
and nothing would remove one.

### Fixed

- **`log_retention_days` did nothing.** It sat at `90` with no code reading it. The only
  caller of `cleanup_old_logs()` is a manual endpoint that passes a **hardcoded 30** and
  is in no crontab — so logs were pruned when somebody remembered to click something, at
  a retention nobody had chosen.

  That is the fourth setting found this session that stored an intention and ignored it,
  after `users.is_active`, `require_mfa_for_admins`, and two-factor enforcement itself.

### Added

- **`cron/prune_telemetry.php`.** Applies retention to all four tables, honouring
  `log_retention_days` and a new `telemetry_retention_days` (default 90, clamped to
  7–3650 so a stray `0` cannot empty them).

  **Reports by default, deletes only with `--apply`.** The first run on an established
  installation removes hundreds of thousands of rows; that should be a decision, not a
  surprise.

  **Deletes run in 5,000-row chunks with a pause between them.** A single `DELETE` of that
  size holds locks long enough to stall the agent check-ins that write to these same
  tables every two minutes. Verified on a throwaway copy of the real data: one chunk
  removed exactly 5,000 rows and left everything inside the retention window untouched.

### Changed

- **The job is registered but deliberately not scheduled**, and carries no expected
  interval, so the Scheduled Jobs page will not flag it overdue for an operator who has
  chosen not to run it. Adding it to cron is a decision about deleting history.

### Added

- **`tests/telemetry_retention_test.php`** (19 assertions, in CI). Table coverage,
  report-before-delete, clamped retention, chunking and the pause, run reporting, and that
  the migration never overwrites a retention an operator has already set.

### Note

The injection guard added in 3.44.0 failed this release on its author's own new file:
`prune_telemetry.php` interpolates a table name into `SELECT COUNT(*) FROM \`{$table}\``.
The value comes from a hardcoded map four lines above it, so it is safe and was added to
the reviewed allowlist with that reason — which is the workflow the allowlist exists for.
Worth recording that the guard caught the next thing written after it, rather than waiting
for a stranger.

---

## Version 3.44.0
**Released**: September 15, 2026 | **Agent**: v1.6.3

This release fixes nothing. Two audits came back clean, and the result is pinned so it
stays that way.

### Audited

- **SQL injection — none.** Every query reaching user input uses a prepared statement
  with placeholders. Five places build a query by interpolation, and each was read
  individually: `LIMIT {$limit}` where `$limit` is `int`-typed and clamped to 1..200; two
  `{$where}` fragments that are ternaries between two literal strings; and table and
  column names from hardcoded call sites in two scripts. None takes a value from a
  request.

- **Stored cross-site scripting from the fleet — none.** This is the one worth checking
  carefully: a firewall's check-in payload is *not* trusted input. The box belongs to a
  customer, it may be compromised, and what it reports — WAN addresses, interface names,
  OPNsense version, uptime — is rendered in an administrator's browser. Every
  agent-supplied field is escaped wherever it appears. The two lines that looked like
  exceptions were `about.php` rendering the in-app changelog array and `generate_pdf.php`
  rendering `platform_versions`; neither is agent data, and both happen to use a key named
  `version`.

### Added

- **`tests/injection_guard_test.php`** (6 assertions, in CI). Both properties hold today
  and are the kind that get lost one line at a time.

  The interpolation allowlist names the five reviewed files explicitly rather than
  matching a pattern, so removing an entry costs nothing and adding one is a decision
  somebody has to make deliberately. Each half also asserts it examined a plausible number
  of candidates, so neither can pass by scanning nothing.

  Verified by planting a vulnerable file of each kind — a `query()` with a raw `$_GET`
  value, and an `echo $firewall['wan_ip']` — and confirming both halves fail.

---

## Version 3.43.0
**Released**: September 15, 2026 | **Agent**: v1.6.3

### Found

**Agent request signing has a complete server and no client.**

The manager verifies HMAC-SHA256 signatures over a canonical
`METHOD\nPATH\nTIMESTAMP\nNONCE\nSHA256(BODY)`, enforces a freshness window, rejects
replayed nonces, ratchets each firewall once it has proven it can sign, and offers three
fleet-wide policy modes. The check-in response hands each agent its signing secret *and
the canonical string to sign*, annotated **"Sign requests once supported."**

It never was. No agent release contains a line of signing code — the agent does not store
the `api_secret` it is sent, computes no HMAC, and sends no `X-OPNMGR-Signature` header.
`agent_signing_supported` is `0` for every firewall because no agent has ever produced a
signature.

So today the only thing protecting command delivery is TLS. Anyone holding a firewall's
`hardware_id` and `api_key` can impersonate it, and signing — which exists precisely to
prevent that — is inert.

### Security

- **`agent_auth_mode = require_signed` is a trap.** It reads as the hardened option and
  would refuse **every check-in in the fleet**. It has no UI, so it can only be set by
  someone who went looking for it in the database — exactly the person most likely to
  choose it.

  The interface now warns, on every administrative page, when the configured policy
  cannot be satisfied by the agents actually deployed: which setting, how many firewalls
  are affected, that no agent implements signing, and the exact way back.

### Changed

- **The policy is reported, never overridden.** Quietly downgrading a security setting is
  the failure this codebase has been full of, and the fix for it is not another instance
  of it. A refused fleet is survivable and reversible; a control that pretends to be on is
  not. The interface keeps working while agents are refused, which is what makes the
  banner a viable route back.

### Fixed

- **`tests/undefined_functions_test.php` and `tests/endpoint_authz_test.php` enumerated
  tracked files only**, so a new file's definitions were invisible until committed — the
  same blind spot fixed in the identity guard in 3.30.1. It surfaced here as a false
  failure against a file added minutes earlier. Both now scan tracked and new files
  together.

### Added

- **`tests/agent_signing_test.php`** (17 assertions, in CI). Pins that no released agent
  signs — with a note to update the assertion rather than delete it if one ever does —
  that the policy is not silently downgraded, the satisfiability rule for each mode
  including an empty fleet, and that the banner names the setting and gives the remedy.

---

## Version 3.42.0
**Released**: September 15, 2026 | **Agent**: v1.6.3

### Security

- **A failed restore left the configuration on the firewall.** The generated script runs
  under `set -e`, so a non-zero exit from `configctl` ended it *at that line* — and the
  `rm -f "$TMP"` sat below it, along with the error message. The file left behind is a
  complete OPNsense configuration: user password hashes, IPsec pre-shared keys, RADIUS
  secrets, sitting in `/tmp`.

  Cleanup is a `trap` on `EXIT HUP INT TERM` now, which covers every path out — the fetch
  failing, the sanity check rejecting the file, `configctl` failing, or the script being
  killed.

### Fixed

- **A failed restore explained nothing.** Same cause: the `ERROR: restore failed` message
  was below the line the script died on, so the operator saw a bare non-zero exit.

- **The real exit code is preserved.** Worth recording because my first fix was wrong:
  `if ! configctl ...; then RC=$?` captures the *negated test result*, so a restore that
  failed with 3 reported 1. The new suite caught it on the first run. `set -e` is now
  lifted for exactly that call.

- **Missing agent credentials produced silence.** `HW=$(cat ...)` under `set -e` exits
  when the file is absent, so on a firewall whose agent credentials are missing the script
  stopped at the first line with no output whatsoever. It now says what is wrong.

### Added

- **`tests/config_restore_test.php`** (13 assertions, in CI). Runs the generated script
  with `curl` and `configctl` replaced by stubs: a successful restore exits 0, reports
  success and leaves nothing behind; a failing one propagates the real exit code, says
  why, and leaves nothing behind; a fetched file that is not a configuration is refused
  without `configctl` ever being reached.

  Restore has **never been performed** on this installation — `audit_log` holds no restore
  entries at all — so this script had never been executed by anything before now. Verified
  to fail against the pre-fix version.

### Verified

**The backups themselves are sound**, which is worth stating plainly. They are stored at
`/var/lib/opnmgr/backups/`, outside the web root, mode `0640` owned by the web user.
Checksums and sizes match the database for every file checked, each parses as XML with an
`<opnsense>` root, and `validate_backup_upload()` genuinely parses the document with
external entities disabled rather than just checking the file is non-empty. Both firewalls
have current backups with no gaps.

---

## Version 3.41.0
**Released**: September 15, 2026 | **Agent**: v1.6.3

> **This changes nothing until you enable it.** `require_mfa_for_admins` remains `0`.
> Turning it on now does what it says; before this release it did nothing at all.

### Security

- **`require_mfa_for_admins` required nothing.** The setting has been in the settings
  table with no line of code reading it. An operator who turned it on got a stored `1`
  and no change in behaviour.

  That is the third setting found this way — after `users.is_active` in 3.37.0, and
  two-factor enforcement itself in 3.35.0, where `login()` never read `totp_secret` at
  all. A security control that stores your intention and ignores it is worse than one
  that is absent, because you stop looking.

### Fixed

- **With the setting on, an administrator without a second factor is sent to enrol**, and
  the rest of the interface is unavailable until they do. **Enrolment rather than
  refusal** is the whole design constraint: a setting that applies to administrators must
  not be able to strand the administrator who turned it on. `twofactor_setup.php`,
  `verify2fa.php`, `login.php` and `logout.php` stay reachable.

- **Evaluated on every authenticated request**, not only at login, so enabling it applies
  to sessions that are already open rather than waiting for everyone to sign in again.

- **API callers get a 403 with JSON** explaining the requirement, rather than a redirect
  to an HTML page that a fetch() would render as a confusing success.

- **A settings or database error leaves administrators in**, and the requirement
  reasserts on the next request. Failing closed here would strand everyone on a transient
  read error.

### Note

`raw_command_admin_only` is also read by nothing, and it is **left alone deliberately**.
`api/queue_command.php` already requires an administrator unconditionally, so the
stricter behaviour is what happens regardless; wiring the setting up could only ever
loosen a restriction that is currently absolute. Recorded here rather than silently
implemented.

### Added

- **`tests/mfa_requirement_test.php`** (13 assertions, in CI). The role scope, the
  already-enrolled case, every escape hatch by name, the JSON branch for API callers, and
  the fail-open behaviour on a database error. Verified to fail when the enforcement is
  removed.

### Verified

Tested against a running server with the setting on: every page redirects an un-enrolled
administrator to enrolment, the enrolment page and logout stay open, and an API call
returns the 403 JSON. The setting was returned to `0` afterwards and confirmed.

---

## Version 3.40.0
**Released**: September 15, 2026 | **Agent**: v1.6.3

### Fixed

- **Two editors for one set of mail settings.** `settings.php` carried an SMTP modal that
  **nothing ever opened** — no code anywhere referenced `#smtpModal` — behind a handler
  that saved host, port, username, password and encryption but *not* the From address or
  From name, which are fields the alert sender actually reads.

  That duplication is how the two drifted: `settings.php` ended up printing the decrypted
  password into the page and wiping it on a blank save (both fixed in 3.36.0), while
  `smtp_settings.php` had always handled both correctly. The dead modal and its handler
  are gone. `smtp_settings.php` is the editor, reachable from the SMTP card in Settings,
  and it saves all seven fields — verified by round-tripping every one of them and
  confirming a blank password leaves the stored credential untouched.

- **The Settings SMTP card said only "Email server".** It named no server, so a manager
  pointed at a mail provider nobody had chosen looked identical to one correctly
  configured. The card now shows the host, the username, and whether delivery is actually
  working — the last from the same signal that raises the delivery banner.

### Changed

- **Placeholders no longer name a mail provider.** Every SMTP field hinted at Gmail —
  `smtp.gmail.com`, `your-email@gmail.com`, `noreply@yourdomain.com`. A placeholder reads
  as a recommendation, and these made a wrongly configured installation look deliberate.

### Added

- **`tests/smtp_settings_test.php`** (16 assertions, in CI). One editor must save all
  seven fields; `settings.php` must save none of them; the dead modal must stay gone; the
  card must show host and delivery state; and no placeholder may name a provider.
  Verified to fail when the modal is put back.

---

## Version 3.39.0
**Released**: September 15, 2026 | **Agent**: v1.6.3

### Security

Four state-changing paths accepted a cross-site request. All four sat behind a valid
session, so they needed an operator to load an attacker's page while logged in — not a
remote hole, but each removes or redirects a protection:

- **`twofactor_setup.php` accepted `disable_2fa` with no token.** An operator who loaded
  the wrong page had their second factor stripped, silently, from a form they never saw —
  and 3.35.0 had just made that second factor real for the first time.
- **`alerts.php` accepted new notification settings with no token.** Redirecting alerts
  to an address of the attacker's choosing, or switching them off entirely, was one
  cross-site request.
- **`api/request_queue.php` queued an arbitrary method, path, headers and body** to be
  proxied at a managed firewall, behind `requireLogin()` alone — no token, no role check.
  It now requires `firewall.manage` and a token.
- **`package_builder.php` rendered a CSRF token, its JavaScript sent one, and the handler
  never looked at it.** A decorative token is worse than none: it reads as protection in
  review, which is exactly how this survived.

### Changed

- **`firewall_edit.php` compared the token by hand** — `$_POST['csrf_token'] !==
  $_SESSION['csrf_token']`. It uses `csrf_verify()` now, which compares with
  `hash_equals()` and is the one implementation the rest of the application uses. A
  second copy is a second thing to get wrong.

### Added

- **CSRF coverage in `tests/endpoint_authz_test.php`.** Any endpoint that writes, is
  reachable by a browser session, and can receive a POST must call `csrf_verify()`.
  Machine-to-machine callers — the agent and enrolment — are excluded, since they carry
  no cookie.

  It accepts **only** `csrf_verify()`. An earlier version of this scan counted
  `csrf_token()` as protection, and that is precisely what hid `package_builder.php`:
  the page mentions CSRF twice and verifies nothing. Verified to fail against each of
  the fixes reverted.

### Verified

Both directions tested against a running server: a POST without a token is refused and
changes nothing, and a POST carrying the rendered token still saves normally.

---

## Version 3.38.0
**Released**: September 15, 2026 | **Agent**: v1.6.3

### Security

- **`api/manage_ssh_keys.php` returned SSH key metadata to anyone.** It gated on
  `check_authentication()` — a function defined nowhere in this codebase — so the
  expression was always false. That broke in two directions at once:

  - **GET was never gated.** The dispatch called the handler regardless of the flag, so
    an unauthenticated request for any `firewall_id` returned that firewall's SSH key
    fingerprints, key types, bit sizes and timestamps. Confirmed against the running
    production server before the fix: **HTTP 200 with key metadata and no session**. No
    private key material was exposed, but it enumerates infrastructure to anyone who can
    reach the server.
  - **POST failed closed.** The same always-false flag returned 401 for every
    `regenerate` and `delete`, so those SSH key actions on the firewall details page have
    never worked.

  Both halves now go through the application's own role checks: reading key metadata
  requires `firewall.view`, changing a key requires `firewall.manage`. Verified after the
  fix: unauthenticated GET is 401, an administrator's GET is 200, a `readonly` role gets
  200 on read and **403 on regenerate**.

- **`api/updates/check.php` and `api/updates/download.php` answered anyone.** Both took an
  `instance_id`, validated nothing, and responded — `download.php` returning file contents
  and SQL statements for the caller to apply. They belong to the multi-instance update
  distribution built alongside the licensing subsystem removed in 3.29.0, and nothing in
  this codebase calls either. A machine-to-machine caller would need a credential of its
  own, which has never existed here, so both now require `system.maintenance` rather than
  being open.

### Added

- **`tests/endpoint_authz_test.php`** (7 assertions, in CI). Every endpoint that touches
  data must authenticate its caller — by session, by the agent mechanism
  (`authenticateAgentRequest()`), or by an enrolment token checked against
  `enrollment_tokens` with an expiry. Comments are stripped before the check, so a file
  that *describes* one of these bugs cannot satisfy its own guard, and the suite asserts
  it examined a plausible number of endpoints so it cannot pass by scanning nothing.
  Verified to fail against the pre-fix code.

---

## Version 3.37.0
**Released**: September 15, 2026 | **Agent**: v1.6.3

### Security

- **`users.is_active` did nothing.** The column has been in the schema all along with no
  line of code reading it. Setting it to `0` looked exactly like disabling an account,
  and the account carried on logging in. There was no control for it in the interface
  either, so the only way to stop someone was to delete them — which also destroys the
  record of what they did.

  It is enforced now, in both places that matter:

  - **At login**, checked *after* password verification, so a wrong password and a
    disabled account are indistinguishable from outside. The refusal is audited.
  - **On sessions already open**, which end within a minute. Without this, "disable this
    user" would have quietly meant "disable them at their next login" — not what anyone
    reaching for it needs. The check runs once a minute per session rather than on every
    request, bounding both the cost and the exposure, and a database blip logs nobody out.

### Added

- **Enable/Disable in user management.** Deleting an account destroys the record of what
  it did; disabling keeps the audit trail and stops the login. Two guards, because this
  is the control that can lock you out of your own installation:

  - you cannot disable your own account;
  - the **last active administrator** is refused, since deactivating it would leave
    nobody able to administer the installation.

  Both the activation and the deactivation are audited.

- **Account status and last sign-in in the user list.** Two admin accounts on the
  maintainer's installation, created a year ago, had never signed in once — and nothing
  in the interface surfaced that.

### Fixed

- **The user listing query selected neither `is_active` nor `last_login`.** Added
  alongside the display, since otherwise both would have rendered as decoration
  regardless of what the database actually held — the same shape of bug as the badge
  this release fixes.

- **`tests/account_status_test.php`** (15 assertions, in CI). Refusal at login, session
  termination, the rate limit, the self and last-admin guards, and that the listing
  selects the columns it displays. Verified to fail against the pre-fix code.

---

## Version 3.36.0
**Released**: September 15, 2026 | **Agent**: v1.6.3

### Security

- **The SMTP password was printed into the page.** `settings.php` rendered the
  *decrypted* credential as the `value` of its password input. `type="password"` hides
  it on screen, but the value still sits in the page source, in the browser's cache, and
  in anything between. `smtp_settings.php` has always declined to echo it back — the two
  dialogs had diverged.

### Fixed

- **Saving the SMTP dialog could silently wipe the password.** `settings.php` treated a
  blank field as "store the empty string" rather than "unchanged", so opening it to edit
  the host or port and saving cleared the credential. `smtp_settings.php` already got
  this right. Nothing recorded the change, so there was no way to see it had happened.

- **Settings changes were never audited.** `save_setting()` was defined twice —
  identically, in `settings.php` and `smtp_settings.php` — and neither recorded anything.
  The `settings` table has no `updated_at`. So `audit_log` held 1,657 entries without a
  single settings change among them, and a straightforward question — *when did the mail
  server configuration last change, and to what?* — had no answer anywhere in the system.

### Added

- **An audit trail for configuration.** `save_setting()` now lives in `inc/secrets.php`
  and records the setting's name with its previous and new value, so a change can be read
  back rather than merely detected. `save_secret_setting()` records that a credential was
  set, replaced or cleared — **the name and the fact, never the value**, because an audit
  trail holding credentials is a second place to steal them from. Auditing never prevents
  the write.

- **`tests/settings_audit_test.php`** (12 assertions, in CI). No page may print a stored
  credential into its form; a blank password field must mean unchanged; changes must be
  audited with their previous value; the credential audit line must never contain the
  credential; and neither page may redefine `save_setting()` privately and bypass the
  trail. Verified to fail against the pre-fix code.

---

## Version 3.35.0
**Released**: September 15, 2026 | **Agent**: v1.6.3

> **If you had 2FA "enabled"**, it was not protecting your account and is now enforced.
> Confirm your authenticator still produces accepted codes before relying on it. No
> account on the maintainer's installation was enrolled, so nobody could be locked out
> by this change.

### Security

- **Two-factor authentication was never asked for.** `login()` verified the password,
  set `$_SESSION['user_id']` and returned success — it never read `totp_secret`. Nothing
  anywhere redirected to `verify2fa.php`; no code path referenced it at all. An account
  whose profile said *"2FA is currently enabled for your account"* was protected by a
  password and nothing else.

  This is the same shape as the lockdown toggles and the scheduled jobs page: a control
  surface reporting a protection it did not have. It is worse here, because the operator
  chose that protection deliberately.

- **And the verification page could not have worked either.** `verify2fa.php` called
  `clear2FA()` on a **correct** code. That function is defined nowhere in the codebase,
  so entering the right number produced a fatal error page, while a wrong number returned
  a tidy "Invalid code". Combined with the enrolment bug fixed earlier (a hex secret
  offered to authenticators as if it were Base32), two-factor has never worked end to end
  in any part: enrol, challenge, or verify.

### Fixed

- **The second factor is now held at login.** An enrolled account gets a pending state
  rather than a session: no `user_id` is set, so `isLoggedIn()` is false and every
  `requireLogin()` page refuses until the code is confirmed. The pending state expires
  after five minutes and is bound to the address that supplied the password, so a stolen
  cookie cannot be completed elsewhere. Promotion regenerates the session id.

  `login()` returns `'2fa'` for this case — truthy, so the caller tests it before the
  success branch. Verified end to end against a real enrolled account: correct password
  redirects to the challenge, the session is *not* authenticated at that point, a wrong
  code is rejected, a correct code promotes and lands on the dashboard. Also verified
  that an account **without** 2FA logs in exactly as before.

- **The verification form had no CSRF token**, and the page read `$_SESSION['user_id']` —
  only set once a session is already authenticated, so by the time anyone could reach it
  the second factor was moot.

- **Seven more calls to functions that do not exist**, each a fatal when reached:
  `write_log()` and `log_action()` across five endpoints and two cron jobs
  (`inc/logging.php` provides `log_event()`, with the arguments in a different order),
  and `check_authentication()` in `development/todo.php`.

  `scripts/run_auto_scans.php` calls `performAIScan()`, which has never existed — the
  only entry point is `performAIAnalysis()`, with a different signature. Scheduled AI
  scanning has therefore never run, and the script is in no crontab. Its table
  (`firewall_ai_settings`) carries real rows, so the feature is unfinished rather than
  abandoned; the script now says so and exits instead of calling into nothing.

### Changed

- **Reaching a firewall no longer depends on that firewall's certificate, anywhere.**
  Managed firewalls routinely carry self-signed or expired certificates; that is
  something to report, not a reason to lose the ability to manage the box. The live
  paths already connected with verification off, but `inc/opnsense_api.php` defaulted the
  other way — adopting it would have made management fail exactly when a certificate
  problem most needed looking at. Certificate state is still collected and reported by
  `health_ingest_certificates()`, which is unchanged.

### Added

- **`tests/undefined_functions_test.php`** (9 assertions, in CI). Tokenises every tracked
  PHP file, collects definitions and call sites, and fails if a call has no definition —
  honouring `function_exists()` guards as deliberate optional dependencies. It also pins
  that no connection path requires a valid firewall certificate. Verified to fail against
  the pre-fix code.

---

## Version 3.34.0
**Released**: September 15, 2026 | **Agent**: v1.6.3

### Added

- **`scripts/check_production_drift.php`.** Deployment here is a file copy from a working
  tree, not a checkout, and nothing ever checked the result. It reports three things:

  - **STALE** — a tracked file whose deployed copy differs, meaning the server is not
    running the code in the repository;
  - **MISSING** — a tracked file absent from the deployment;
  - **EXTRA** — a deployed file that is not tracked and is not runtime data.

  STALE and MISSING fail; EXTRA is reported but does not, because a deployment
  legitimately holds release artifacts, keys, logs and vendor code.

  On its first run it found a stale CI workflow and two test suites added hours earlier
  that had never been deployed.

### Fixed

- **111 files on the server that are in no repository.** A year of accumulated session
  notes, emergency shell scripts, SQL dumps, `.broken` copies of live pages, an
  `.archive/` directory holding 16 old versions of pages, and six test endpoints.
  Archived off the server and removed.

  Checked before removing: **no unauthenticated exposure.** The test endpoints returned
  401, `.archive/` and `diagnostics/` were blocked at the web server. This was hygiene,
  not a breach. Every candidate was checked for inbound references first — the eight that
  had any turned out to be changelog text, comments, and two substring false positives.

- **A stale `check_agent_install.php` at the deployment root** still carrying the
  hostname removed in 3.30.0. `tests/server_identity_test.php` scans the repository and
  never sees what is actually being served — precisely the gap the drift checker closes.

- **`about_backup_20251009.php`**, a dated copy of `about.php`, was web-reachable and
  returned 500 on every request. A sweep of all 93 deployed pages now finds no 500s.

- **Unanchored `.gitignore` rules hid real documentation.** `*_GUIDE.md`,
  `*_IMPLEMENTATION.md` and eight more exist to ignore session notes in the project root;
  unanchored, they match at every depth and were silently excluding `docs/`. Anchored
  now, and `docs/AGENT_RECOVERY_GUIDE.md` and `docs/AI_LOG_ANALYSIS_IMPLEMENTATION.md`
  are tracked.

  This is the **third** time an unanchored ignore rule has hidden a real file in this
  project, so `tests/referenced_files_test.php` now fails if any of those rules loses its
  anchor. Verified by removing one anchor and watching the suite fail.

  `docs/QUICK_REFERENCE.md` and `docs/VERSION_2.1.0_GUIDE.md` describe v2.1.0 and remain
  deliberately untracked rather than published as though current — they need updating or
  removing, which is a decision about content.

---

## Version 3.33.0
**Released**: September 15, 2026 | **Agent**: v1.6.3

> **If you upgrade and a red banner appears**, it is not new breakage — it is a delivery
> failure that was already happening silently. The banner reports what `alert_history`
> has been recording all along.

### Fixed

- **Alerting worked perfectly and told nobody.** It detected conditions, raised
  incidents, and recorded every notification — with `status = 'failed'`. On the
  maintainer's installation `alert_history` held **911 notifications, every one failed,
  not one sent**, going back to the first row in the table. The SMTP credential had been
  rejected by the provider the entire time.

  Nothing surfaced this. No banner, no dashboard tile, no health signal, no self-alert.
  The only trace was 114,355 lines in `/var/log/opnmgr_alerts.log`. That is worse than
  having no alerting at all, because the operator believes they are covered.

- **The reason was unreadable.** `send_smtp_email()` caught every failure and returned
  the string `Internal server error`, so the response that actually explains the problem —
  `535-5.7.8 Username and Password not accepted` — reached the log and nowhere else. The
  administrator diagnosing their own mail server was shown less than the server told us.

  It now returns the SMTP response itself, **redacted**: base64 runs are stripped, since
  an echoed `AUTH` line carries the username and password and would otherwise be written
  into `alert_history` and rendered on screen. The configured credentials are also
  replaced if a server quotes them back in the clear. Verified that `535-5.7.8` and
  `Connection refused (111)` survive intact while a base64 credential does not.

### Added

- **`inc/notification_health.php`.** Delivery state per channel: consecutive failures
  since the last success, and whether a channel has *ever* delivered — which separates a
  misconfiguration from an outage, and deserves different wording. `partial` counts as
  delivery, because somebody was told. Channels are tracked independently, so a working
  Pushover does not mask a dead mail path.

- **A banner on every administrative page** when a channel has failed three consecutive
  attempts. Three, so a single transient bounce is not reported as a broken channel.

  It is **not dismissible**, deliberately: the failure persists until someone fixes a
  credential, and a banner an operator can wave away is precisely how 911 undelivered
  alerts go unnoticed. It states plainly that this warning cannot be emailed to you.
  Administrators only — it names configuration, and they are the account that can act on
  it. Wrapped so that a failing banner can never take a page down.

- **`tests/notification_health_test.php`** (33 assertions, in CI). Covers
  never-delivered versus outage, partial delivery, failures before a success not being
  counted, per-channel isolation, an empty history not being reported as failure, and
  that redaction keeps the SMTP diagnostic while removing a credential. Verified to fail
  against the pre-fix code.

---

## Version 3.32.0
**Released**: September 15, 2026 | **Agent**: v1.6.3

> **Upgrading**: run `php scripts/migrate.php`. Migration 0018 replaces the invented
> rows in `scheduled_tasks` with the jobs that actually run. Daily jobs will show
> "Never run" until their next scheduled run — nothing recorded before this release.

### Fixed

- **The Scheduled Tasks page was fiction, end to end.** Four separate failures stacked:

  1. **The endpoint was fatal on every request.** `api/manage_tasks.php` gated on
     `check_authentication()` — a function defined nowhere in this codebase. So the page
     could never list a job and its toggles could never save. PHP stops at the fatal, so
     nothing executed unauthenticated: a feature that had never worked, not a way past
     the login.
  2. **The toggle controlled nothing.** It wrote `scheduled_tasks.enabled`, a column no
     cron script, include or scheduler has ever read. Switching a job "off" changed a
     column and left it running on its normal schedule.
  3. **The rows were invented.** The table was seeded once by hand with five entries.
     Two named a real job on the wrong schedule; three — *Firewall Health Check*,
     *SSH Tunnel Cleanup*, *Proxy Session Cleanup* — have never been scheduled at all.
     The four jobs that genuinely run on a schedule (stuck-command cleanup, alert
     evaluation, backup health, backup pruning) were absent entirely.
  4. **Nothing ever wrote `last_run`.** Every row read "never run", including jobs
     running every five minutes.

  The page was also unreachable — nothing in the application linked to it.

- **`scheduled_tasks.id` was `NOT NULL` with no `AUTO_INCREMENT`,** so every insert had
  to carry an explicit id. That is why the table was populated once, by hand, and never
  grew a row for a job added later.

### Changed

- **The page reports instead of pretending to control.** Jobs are started by the system
  crontab, which this application does not own and should not silently override. The
  page shows when each job last ran, how long it took, whether it failed and why, and
  flags a job *overdue* once it has not reported for twice its expected interval.

- **It is reachable.** Added to the Admin sidebar as **Scheduled Jobs**.

### Added

- **`inc/cron_runs.php`.** One line in each cron entrypoint records that run. Completion
  is written from a shutdown handler, so a fatal or an `exit()` is still reported rather
  than leaving the row stuck at "running". Every database write is wrapped and swallowed
  with a log line — a scheduled backup must never be lost because bookkeeping could not
  write a status row. Verified both paths against the live database: a real job records
  `ok` with its duration, and a deliberately fatal job records `failed` with the message.

- **`tests/scheduled_jobs_test.php`** (34 assertions, in CI). Every registered job must
  name a script that exists, every cron entrypoint must report itself, the endpoint must
  gate on a function that is actually defined, and it must not write the column nothing
  reads. Verified to fail against the pre-fix code.

---

## Version 3.31.0
**Released**: September 15, 2026 | **Agent**: v1.6.3

> **Upgrading**: firewalls are offered v1.6.3 on their next check-in and update
> themselves. If you self-host, run `./scripts/build_agent_package.sh` and
> `php scripts/sign_release.php --publish` after pulling, or your manager will
> advertise a version its `downloads/` directory cannot serve.

### Added

- **Agent v1.6.3.** Carries the `checkin.sh` fix from 3.30.2: forcing a check-in no
  longer relabels a current agent as eight releases old. This is the release that
  actually delivers it — 3.30.2 fixed the source, which firewalls never see.

- **`scripts/build_agent_package.sh`.** There was no build script. Packages were tarred
  by hand, which is precisely how `plugin/src` came to differ from the package it was
  supposedly built from, and how `checkin.sh` shipped a version label eight releases
  behind `agent.sh`. The script:

  - takes the version from `AGENT_VERSION` in `inc/version.php`, the same single source
    the rest of the version machinery uses, so the package filename and the agent's
    self-reported version cannot disagree;
  - refuses to build if `agent.sh` declares a different version than `inc/version.php`;
  - refuses to overwrite a tarball that already exists, because an agent that already
    installed that version would keep its old bytes while the manifest advertised new
    ones (`FORCE=1` for a version that was never distributed);
  - excludes `__pycache__`, which is build output from whichever Python happened to run
    and was one of the reasons source and package differed.

### Changed

- **The package is built deterministically** — sorted entries, fixed mtime, numeric
  owner — so rebuilding the same source produces identical bytes, and a diff against a
  published artifact means the source really changed rather than that the tar ran on a
  different day. Verified: 1.6.3 rebuilds byte-for-byte identical, has the same tree
  structure as 1.6.2, and differs from it in exactly the two intended files.

- **Directory entries are included in the archive.** A files-only build would have
  silently dropped the empty `service/templates` tree that every previous package
  shipped.

- **`downloads/manifest.json` re-signed** to cover 1.6.3. All 51 artifacts verify
  against the pinned Ed25519 public key.

---

## Version 3.30.2
**Released**: September 15, 2026 | **Agent**: v1.6.2

> **Note**: the `checkin.sh` fix is in the agent source. Shipping it to firewalls needs
> a new agent package — `plugin/` is tracked, the built tarball is not. Until then the
> force-check-in button still misreports the version on installed agents.

### Fixed

- **One button press aged the agent eight releases.** `agent.sh` and `checkin.sh` each
  carried their own `AGENT_VERSION` literal, and they drifted: `agent.sh` said `1.6.2`,
  `checkin.sh` said `1.1.7`. `checkin.sh` is what the `checkin` configctl action runs —
  the GUI's force-check-in button and `configctl opnmanager_agent checkin` both invoke
  it — and the manager records whatever version the payload reports.

  So forcing a check-in on a correctly installed 1.6.2 agent relabelled it 1.1.7: below
  the documented minimum supported version, nine points off its health score, and
  flagged as needing an update — until the next scheduled check-in from `agent.sh` put
  the real version back. The effect was transient, which is why it survived: it looked
  like a flapping health score rather than a bug.

  `agent.sh` is the only declaration now; `checkin.sh` reads from it and fails loudly if
  it cannot.

- **"Older agents are refused" was never true.** `AGENT_MIN_VERSION` is read in exactly
  one place — `inc/health.php`, to score the firewall. Nothing rejects a check-in from an
  old agent. The compatibility table now says what actually happens: they still report
  in, and lose health score and version-gated features.

- **The paragraph claiming CI prevents version drift had itself drifted.** The
  compatibility table and the sentence below it both still read `3.29.0`, two releases
  behind — and that sentence is the one asserting *"CI enforces these against `VERSION`
  and the published artifact ... so no reference in the tree can drift out of step."*
  `scripts/check_versions.php` checked the badge and two links, but never those two.
  It covers both now, so the guarantee is real rather than asserted.

- **The stated agent-installer limitation was out of date, and hid a real one.** The
  README said the installer fetches its package from the project's distribution host
  with `PLUGIN_URL` hardcoded. That stopped being true in 3.30.0. The limitation that
  *is* true was never written down: `downloads/` holds release artifacts and is
  gitignored, so a fresh clone has no `os-opnmanager-agent-<version>.tar.gz` to serve and
  enrolment gets a 404 until the operator builds one from the tracked `plugin/` tree.

### Added

- **`tests/agent_package_test.php`** (10 assertions, in CI). The agent's version must
  have exactly one source; the suite fails if a second literal reappears in any agent
  script, and checks that `checkin.sh` derives it and errors rather than guessing.
  Verified to fail against the pre-fix source.

---

## Version 3.30.1
**Released**: September 15, 2026 | **Agent**: v1.6.2

### Fixed

- **`tests/server_identity_test.php` could not see files that were not committed yet.**
  It enumerated candidates with `git ls-files`, which lists the index — so a file
  present on disk but not yet added was skipped entirely. That is precisely the moment
  a literal slips through, and it did: the suite passed locally and then failed in CI on
  its own first commit, because `inc/server_identity.php` named the offending hostname
  in its own docblock. It now scans with `--cached --others --exclude-standard`, so new
  files are covered before they are committed rather than after. Verified by dropping an
  untracked file containing the literal and watching the suite fail.

- The hostname in the `inc/server_identity.php` docblock, replaced with a description.

---

## Version 3.30.0
**Released**: September 15, 2026 | **Agent**: v1.6.2

> **Upgrading**: nothing to migrate. On first enrolment after this release the
> manager generates its own SSH enrolment key under `/etc/opnmgr/keys/`; that
> directory must be writable by the web user. Firewalls already enrolled are
> unaffected — they are managed with per-firewall keys, which this does not touch.

### Fixed

- **This installation's own address is no longer somebody else's.** 38 tracked files
  carried the maintainer's hostname and public IP as literals. They were not all
  cosmetic. A self-hosted deployment would have:

  - told its firewalls to download and execute the agent installer from a third
    party's server (`inc/agent_version.php`, `agent_checkin.php`, `api/ssh_install_agent.php`);
  - opened each onboarded firewall's WAN SSH port to a third party's IP rather than
    to the manager doing the onboarding (`api/auto_onboard_firewall.php`,
    `scripts/setup_permanent_ssh_rule.php`);
  - looked for its TLS certificate in `/etc/letsencrypt/live/<someone else's domain>/`
    and reported it missing however its TLS was actually configured
    (`api/tunnel_health_check.php`, `scripts/manage_nginx_tunnel_proxy.php`, which also
    emitted an nginx `server_name` for a domain the operator does not own);
  - handed operators a tunnel URL, an uninstall command and a connection-test probe
    aimed at a host they do not run (`tunnel_proxy.php`, `start_tunnel_async.php`,
    `tunnel_direct.php`, `firewall_view.php`, `diagnostics.php`);
  - told the AI security scan that a specific third-party IP is a trusted SSH source
    (`api/ai_scan.php`).

  Everything resolves through the new `inc/server_identity.php`, in order: the
  `server_url` setting, `APP_URL` in `.env`, the `manager_fqdn` setting,
  `main_server` in `config/instance.json`, then the host of the request being served.
  There is no compiled-in fallback. When nothing is configured the helpers return an
  empty string and each caller reports that rather than emitting a URL pointing
  somewhere wrong.

- **The enrolment script authorised a key the operator does not hold.** `simple_enroll.sh`
  appended a hardcoded `ssh-ed25519` public key to `/root/.ssh/authorized_keys` on every
  firewall it enrolled. It was not even a dedicated enrolment key: it was the
  per-firewall key generated for firewall 21 on one installation. So every other
  deployment granted root SSH on its customers' firewalls to a key belonging to
  someone else, and every firewall enrolled by a single installation trusted a key
  minted for a different firewall.

  `inc/enrollment_key.php` generates this installation's own ed25519 key on first use,
  outside the document root, and `api/get_enroll_script.php` substitutes it when serving
  the script — refusing to serve at all if it cannot be produced. Nothing depended on
  the old key: per-firewall keys are generated after enrolment and deployed through the
  agent, never over SSH with the enrolment key.

- **`api/tunnel_keep_alive.php`** fetched the agent from `/download/tunnel_agent.sh`.
  The file is served from `/downloads/`. Together with the hardcoded host, that restart
  command could not have worked on any installation.

- **`api/repair_agent_ssh.php` and `cleanup_agents.php`** downloaded the agent from
  `download_tunnel_agent.php`, an endpoint that has never existed in this codebase. Both
  now use this installation's address and abort on a failed download instead of running
  whatever came back.

### Changed

- **`downloads/plugins/install_opnmanager_agent.sh` takes `OPNMGR_BASE_URL`** from
  whoever emits the install command. A script served as a static file cannot know which
  host fetched it, so it is passed in; the script exits with an explanation rather than
  guessing. The command shown in Settings, the one `agent_checkin.php` hands to agents,
  and the SSH installer all set it.

- **`config/instance.json` no longer ships a `main_server`.** It is a template; a real
  hostname in it was a fallback that silently pointed installations at the wrong server.

- **Real hostnames and IPs in `CHANGELOG.md` and the in-app changelog** replaced with
  documentation placeholders (RFC 5737 addresses, `.example` hostnames). Entries that
  contrast two firewalls still read correctly because each maps to a distinct
  placeholder. **These values remain in Git history**, which has not been rewritten.

### Removed

- **Reverse-tunnel auto-setup in `agent_checkin.php`.** On every check-in from a firewall
  whose tunnel had never been established, it queued a command that fetched
  `setup_reverse_proxy.sh` and piped the result into `sh`. That script has never existed
  in this repository or on the server, so the firewall downloaded an error page and
  executed it, and the command queue filled with work that could only fail. Tunnels are
  established on demand through `manage_ssh_tunnel.php`, which is what the UI uses.

### Added

- **`tests/server_identity_test.php`** (19 assertions, in CI). Fails if any
  installation-specific host, IP or SSH public key reappears in a tracked file, and
  covers the resolver's precedence, bare hosts, malformed input, and the unconfigured
  case callers must handle. Verified to fail against the pre-fix tree.

---

## Version 3.29.0
**Released**: September 15, 2026 | **Agent**: v1.6.2

> **Upgrading**: run `php scripts/migrate.php` after pulling. Migration 0017 drops
> the licensing tables. On the maintainer's installation that removed one row — a
> test record named "Test" that had never checked in.

### Removed

- **The licensing subsystem.** OPNManager is MIT licensed and self-hosted, and the
  project's own published description says these tools have *"no per-seat billing.
  No licence server."* The repository shipped one anyway, and it did not work:

  - `license_server.php` queried `license_tiers` and `license_checkins`. Those tables
    were only ever created by `db/migrations/create_license_tables.sql`, and
    `scripts/migrate.php` reads `database/migrations/` — never `db/migrations/`. So
    they existed on **no** installation, including the maintainer's, and the page
    threw wherever it was opened. It was also unlinked from every navigation.
  - `api/instances/register.php` inserted into `customer_instances`, a table that
    exists in no schema file and on no installation.
  - `package_builder.php` excluded `api/license_checkin.php` from deployment
    packages; that file has never existed either.

  Removed: `license_server.php`, `inc/license_utils.php`,
  `db/migrations/create_license_tables.sql`, `api/instances/register.php`, the
  `deployed_instances` table, and the stale entries in `package_builder.php` and
  `generate_pdf.php`.

- **The Licensing System section of `FEATURES.md`.** Thirty-seven lines marked
  **"✅ Production"** describing licence tiers with prices ($49/month Starter, and
  others), grace periods, expiry notifications and "feature degradation on expired
  licence" — for a system that has never existed, in a project that is free. It
  named tables (`licenses`, `licensed_servers`), an endpoint
  (`/api/instance_checkin.php`) and a UI (`/deployment/licenses.php`) that appear
  nowhere in the repository.

  Advertising paid tiers for software that is MIT licensed and has no licence
  server is the most misleading thing the documentation said.

### Verified

The documented install path was re-run end to end against an empty database:
`database/schema.sql` imports cleanly and produces 80 tables with no licence tables
among them, `scripts/migrate.php` applies all 18 migrations, a second run reports the
database up to date, and `--status` shows zero pending.

---

## Version 3.28.1
**Released**: September 15, 2026 | **Agent**: v1.6.2

### Fixed

- **The in-app changelog was seven releases behind.** `about.php` renders
  `getChangelogEntries()`, so on a 3.28.0 installation the About page advertised
  **v3.21.0** as the newest release. Everything from 3.22.0 onward was missing —
  entries for all seven are now present.

  This is the second time this has drifted: 3.21.3 fixed the same thing after the
  entire 3.21 line went missing. `scripts/check_versions.php` now checks the in-app
  changelog's newest entry against `VERSION`, exactly as it already did for
  `CHANGELOG.md`, so CI fails rather than shipping a stale About page. Verified by
  simulating the drift: the check reports it and exits non-zero.

- **`getChangelogEntries($limit)` ignored its argument.** `about.php` asks for three
  entries and was handed all thirty-three, so the About page rendered the complete
  release history instead of a summary. The function now slices before returning;
  a limit of `0` still returns everything.

---

## Version 3.28.0
**Released**: September 15, 2026 | **Agent**: v1.6.2

### Added

- **Web GUI IP Lockdown and Secure Outbound Lockdown now do something.** Both were
  UI controls with nothing behind them — one fatal on save, the other fatal on load —
  and both are now implemented end to end.

  They are applied the way every other firewall change already is: a policy script is
  queued through `queue_firewall_command()`, the agent executes it on its next
  check-in, and the result is audited. That needs no agent release and no new
  credentials on the firewall — the previous design wanted per-firewall OPNsense API
  keys and an SSH tunnel for every change.

  `inc/firewall_policy.php` generates the scripts. Every one of them:

  - backs up `/conf/config.xml` before touching it;
  - marks every rule it writes, and removes only rules carrying that marker — so it
    is idempotent, disabling is exact, and a human-written rule is never touched;
  - validates the resulting XML and refuses to install it if it will not parse;
  - reloads the filter, and **restores the backup if the reload is rejected**, so a
    firewall is never left running a rule set it could not load.

  **Web GUI IP Lockdown** restricts the GUI on WAN to an explicit list, on the port
  the firewall actually uses. LAN is never restricted, and this manager's own address
  is always permitted and listed first, so a typo cannot cut the platform off from
  the firewall it manages. If the manager's address cannot be determined the policy
  is refused rather than applied without it, and the save says so. Unparseable
  entries are reported instead of silently dropped — a list the operator believes is
  permitted, minus a typo, is how someone gets locked out.

  **Secure Outbound Lockdown** does what the UI has always described: permits DNS to
  the firewall and HTTP/HTTPS out, blocks and logs the rest on LAN. Because that
  stops mail, VPN and NTP on a customer network, enabling it now requires typing
  `RESTRICT <hostname>`; a toggle click is not enough consent. It is also
  admin-only, where before any signed-in user could have flipped it.

- **`tests/firewall_policy_test.php`, wired into CI.** It does not only inspect the
  generated text: it runs the real scripts against a sample configuration containing
  a human-written rule and a stale rule from a previous run, then asserts the human
  rule survived, the stale one was replaced, applying twice does not accumulate
  rules, disabling removes every managed rule and nothing else, and a malformed
  result is refused rather than installed.

  Two defects were found this way and fixed before release: the backup path was
  hardcoded rather than derived from the configuration being edited, and an early
  assertion miscounted because `<rule>` also appears inside the script's own `awk`
  program.

### Fixed

- **The walkthrough video link was broken.** The README pointed at
  `releases/latest`, which was correct when the media was attached to the newest
  release and wrong the moment any later release was published without it — which
  happened five times. Every video link is now pinned to the `v3.25.0` assets, which
  is where the media lives, and all of them were checked for a 200.

### Known limitation

The lockdown policies have not been run against a live OPNsense firewall as part of
this change. The generator is tested by executing the real scripts against a sample
configuration, and the scripts protect themselves with a backup, XML validation and
a rollback on reload failure — but the effect on a production firewall is untested.
Apply to one firewall you can reach out-of-band before using either across a fleet.

---

## Version 3.27.1
**Released**: September 15, 2026 | **Agent**: v1.6.2

### Fixed

- **The enrollment script was not in the repository, and carried one installation's
  management IP.** CI caught this on the 3.27.0 release run — the local test passed
  because the file exists on the maintainer's machine, and failed on a fresh checkout,
  which is precisely the bug.

  `api/get_enroll_script.php` reads `simple_enroll.sh`, substitutes the panel URL and
  enrollment token, and serves it to a firewall. A `simple_*.sh` rule in `.gitignore`
  excluded it, so a clone served an **empty** script.

  Worse, the script hardcoded `MGMT_SERVER_IP="198.51.100.10"` and used it to add a
  firewall rule permitting SSH to that address. Published as-is, every other
  self-hosted deployment would have opened SSH on its customers' firewalls to somebody
  else's server, while its own manager still could not reach them. The address is now
  a placeholder that `api/get_enroll_script.php` fills in with the manager that served
  the script, resolved from `SERVER_NAME` and falling back to `SERVER_ADDR`. If it
  cannot be determined the endpoint refuses rather than serving a script with a
  literal placeholder, and the script itself exits if it is ever run unsubstituted.

### Changed

- **Support section corrected.** The README stated there was no paid support offering.
  Managed hosting and support for OPNManager are available through
  [mspreboot.com](https://mspreboot.com); the project itself remains free, MIT
  licensed and self-hosted, and nothing documented requires an engagement.

---

## Version 3.27.0
**Released**: September 15, 2026 | **Agent**: v1.6.2

An audit for files that are referenced but absent. It found nine, including two
fatal errors on paths a user can reach.

### Fixed

- **Saving a firewall crashed if you changed the Web GUI IP list.**
  `firewall_details.php` did `require_once __DIR__ . '/scripts/queue_command.php'`
  on a file that has never existed in this repository, which is a fatal error — and
  it fired after the `UPDATE` had already committed, so the row changed and the page
  died. There was nothing to call in its place: `configure_webgui_access.sh` does not
  exist either, `queue_command()` is not in scope, and the agent has no handler for
  any of it.

  The field also promised something the product does not do. Its help text said
  *"Restrict web GUI access to specific IPs"* and named a hardcoded public IP as
  always-permitted, while nothing was ever pushed to any firewall. A security control
  that silently does nothing is worse than no control, so the field is now labelled
  **Recorded only**, says plainly that it does not restrict anything, and points at
  where to actually configure it. The stored values are untouched.

- **Two endpoints were fatal on load, both reachable from the UI.**
  `api/tunnel_management.php` (linked from Settings) required `inc/functions.php`,
  and `api/apply_secure_lockdown.php` (the Secure Outbound Lockdown toggle on the
  firewall detail page) required `inc/ssh_tunnel.php`. Neither file has ever existed.

  `tunnel_management.php` did not need it — everything it uses comes from
  `inc/bootstrap.php` — so the include is gone and the endpoint works.

  `apply_secure_lockdown.php` depends on `create_ssh_tunnel()`, which was meant to
  come from that missing file and is not defined anywhere. The rest of the endpoint
  is implemented, so rather than delete a half-built feature or invent an SSH tunnel
  subsystem, it now returns **501 with an explanation** instead of fataling. The
  toggle reports a clear reason rather than a JSON parse error.

- **`admin/reset_agent.php` included `inc/nav.php`**, which has never existed. The
  page renders its own shell, so the include is gone.

- **Four UI buttons called endpoints that were never written.** All four are now
  implemented and behave the way their callers already expect:

  | Endpoint | Button |
  |---|---|
  | `api/test_pushover.php` | "Send Test Push" in Alerts — posts through the Pushover API using the saved application token and reports the specific error Pushover returns |
  | `api/test_ssl.php` | "Test SSL certificates" in Diagnostics — reports the certificate this server presents, its issuer, validity and days remaining |
  | `api/test_nginx.php` | "Test nginx configuration" in Diagnostics — runs `nginx -t`, falling back to `apachectl configtest` |

  All three require a session and refuse with 401 otherwise.

- **`scripts/install_snyk.sh` was restored.** `security_scan.php` still `exec()`s it,
  but commit `1ac6a54` *"Remove 248 unused files - major repo cleanup"* deleted it.
  Recovered unchanged from `a20f8cb`.

- **`api/instances/register.php` advertised `/api/support/ticket.php`**, which does
  not exist, so every registered instance was handed a support URL that 404s. It is
  no longer advertised.

### Removed

- **`inc/api_auth.php`** — 34 orphan lines redefining `requireLogin()` and
  `requireAdmin()` **unguarded**. Nothing included it, and loading it alongside
  `inc/auth.php` is a hard fatal (`Cannot redeclare requireLogin()`), which was
  confirmed by running it. Any future `require_once` of it would have been an instant
  crash.

- **`deployment_packages.php`** — an orphan page, in no navigation and linked from
  nowhere, whose entire client side called `api/deployment_packages.php`. That API
  was never committed, so the page has never functioned.

### Changed

- **`.gitignore` patterns are anchored to the repository root.** `test_*`, `debug_*`,
  `temp_*`, `*_test.*` and `*_debug.*` were unanchored, so they matched at every
  depth and quietly excluded real product files: `api/test_email.php`,
  `api/test_ssl.php`, `api/test_nginx.php`, `api/test_pushover.php` and
  `api/run_bandwidth_test.php`. A fresh clone 404'd on all of them, and nobody could
  have committed a fix, because the fix would have been ignored too. Root-level
  scratch files are still ignored; this was verified both ways.

### Added

- **`tests/referenced_files_test.php`, wired into CI.** Every internal URL the UI
  fetches, every `require`/`include` built from `__DIR__`, and every `.sh` path the
  server executes must exist on disk. It also asserts that the shipped endpoints are
  not excluded by `.gitignore`.

  It earned its place while being written: the first three findings came from the
  manual audit, and the test then surfaced `inc/nav.php`, `inc/ssh_tunnel.php` and
  `inc/functions.php` — including both fatal endpoints — which the manual pass had
  missed because it only looked at URLs, not at server-side includes.

---

## Version 3.26.2
**Released**: September 15, 2026 | **Agent**: v1.6.2

### Fixed

- **Three pages emitted the page before deciding who was allowed to see it.**
  `inc/header.php` starts sending the response, and `header('Location: ...')` cannot
  take effect once output has begun — so an auth gate placed after that include
  degraded from a redirect into a 200 carrying a truncated page.

  Measured against the pre-fix files on an isolated instance:

  | Request | Before | After |
  |---|---|---|
  | `twofactor_setup.php`, unauthenticated | 200 | 302 → `/login.php` |
  | `users.php`, unauthenticated | 200 | 302 → `/login.php` |
  | `users.php`, as a technician | 200, 13,956 bytes | 302 → `/dashboard.php`, 0 bytes |
  | `network_tools.php`, unauthenticated | 200 | 302 → `/login.php` |

  **No data escaped in any of these.** The gate's `exit()` still stopped the page
  before a single record rendered — the 13,956 bytes a technician received were
  header, navigation and sidebar markup, with no user list, no account and no
  "Current Users" table; that was checked, not assumed. What was broken was the
  behaviour: a non-admin got a half-rendered page instead of being sent away, and a
  gate that cannot redirect is one refactor away from being a gate that does not
  stop anything.

  All three now authorise first and include the header once every redirect-capable
  step has run.

### Added

- **`tests/auth_ordering_test.php`, wired into CI.** It checks every gated page that
  renders: if `isLoggedIn()`, `requireLogin()`, `requireAdmin()` or
  `requireCapability()` appears after the header include, the build fails. Ten pages
  currently qualify.

  The first version of the audit behind it missed `network_tools.php` entirely,
  because its pattern matched `require_once 'inc/header.php'` but not
  `require_once __DIR__ . '/inc/header.php'` — and almost every page uses the second
  form. The test asserts a minimum number of pages were actually examined, so a
  pattern that silently matches nothing fails rather than passing.

---

## Version 3.26.1
**Released**: September 15, 2026 | **Agent**: v1.6.2

### Fixed

- **CI could not run the new two-factor suite.** 3.26.0 added a test that exercises
  the QR renderer, but the `security-tests` job never ran `composer install`, so
  `vendor/autoload.php` did not exist and the job failed at that step. The job now
  installs the dependencies it needs, and requests the `xmlwriter` extension the SVG
  backend uses. No product code changed.

---

## Version 3.26.0
**Released**: September 15, 2026 | **Agent**: v1.6.2

> **Upgrading**: this adds one Composer dependency. Run `composer install --no-dev`
> after pulling, or `twofactor_setup.php` will fail to load.

### Fixed

- **Two-factor enrolment could never have worked.** The `otpauth://` URI in the QR
  carried the secret as hex, but the URI format requires Base32. Hex contains `0`,
  `1`, `8` and `9`, none of which exist in the Base32 alphabet, and `a`-`f` decode to
  entirely different values — so an authenticator app derived a different HMAC key
  from the one `verify2FACode()` checks against, and the six digits never matched.
  Anyone who tried to enable two-factor got "Invalid verification code" every time,
  with nothing in any log to explain it.

  The secret is still generated and stored as hex, so nothing already in the database
  changes and the verification path is untouched. Only the URI and the manual-entry
  display now carry the same bytes correctly Base32-encoded. The URI also states
  `algorithm`, `digits` and `period` explicitly rather than relying on app defaults.

- **The enrolment QR was fetched from `api.qrserver.com` with the secret in the query
  string.** That handed the shared TOTP secret to a third party, and to every proxy
  and access log between here and there. It is now rendered on your own server as an
  inline SVG — inline rather than a URL so the secret does not end up in this
  server's access log either.

  `api.qrserver.com` is also gone from the `img-src` Content-Security-Policy
  directive in `inc/header.php`, which had been widened to permit it.

### Added

- **`bacon/bacon-qr-code` ^2.0** (BSD-2-Clause) renders the QR. Pinned to the 2.x
  line deliberately: 3.x requires PHP 8.1, and this project documents a PHP 8.0
  floor. It uses the SVG backend, so no image extension is required.

- **`tests/twofactor_test.php`, wired into CI.** It checks `base32Encode()` against
  the RFC 4648 test vectors, asserts the advertised secret contains no character
  outside the Base32 alphabet, decodes that secret the way an authenticator does and
  requires it to equal the server's key, then runs a full TOTP round-trip: generate
  the code a compliant app would produce and require the server to accept it, and an
  unrelated code to be rejected. It also asserts the rendered QR loads nothing over
  the network.

  Verification during development went further than the test does: the rendered SVG
  was rasterised and decoded with `zbarimg`, and the decoded payload matched the
  `otpauth://` URI exactly.

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
  hardcoded to `manager.example`, so a self-hosted installation does not currently
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
       https://manager.example/api/upload_backup.php
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
  gave Updates 20 points and Uptime 15, so `fw-chi-edge01.northwind.example` lost 10 for its
  pending update and won back exactly 10 for its 13-day uptime, landing on the
  same score as the fully patched `fw-chi-edge02.northwind.example` — which was itself penalised
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

  Observed on `fw-chi-edge02.northwind.example` on 2026-08-31: command 8017 (`/sbin/reboot`) was
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

  The result: `fw-chi-edge01.northwind.example` asserted "reboot required" continuously from
  2026-03-04 — for roughly six months, across many actual reboots, its uptime at
  the time of the fix being 13 days — because a March update request set the flag
  and nothing could ever clear it. Meanwhile `fw-chi-edge02.northwind.example` reported *no*
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
  Resolved agent check-in failures on fw-chi-edge02.northwind.example (FW 48). Agent was being killed by reinstall commands without proper restart. Implemented proper service restart procedures.
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
