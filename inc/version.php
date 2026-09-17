<?php
/**
 * OPNManager Version Management
 * Single source of truth for all version information
 * Version is read from VERSION file to avoid hardcoding
 */

// Read version from VERSION file
$version_file = __DIR__ . '/../VERSION';
$app_version = file_exists($version_file) ? trim(file_get_contents($version_file)) : '2.2.3';

if (!defined('APP_NAME')) { define('APP_NAME', 'OPNManager'); }
if (!defined('APP_VERSION')) { define('APP_VERSION', $app_version); }
if (!defined('APP_VERSION_DATE')) { define('APP_VERSION_DATE', '2026-09-16'); }
if (!defined('APP_VERSION_NAME')) { define('APP_VERSION_NAME', 'Publish Is Not Deploy'); }

// AGENT_VERSION is THE single constant for "newest agent available to install".
// Its value must match the newest released tarball in downloads/plugins/, because
// that is the only version a firewall can actually be upgraded to - an unreleased
// source bump here tells every agent to fetch a package that does not exist.
// inc/agent_version.php aliases LATEST_AGENT_VERSION to it; do not redefine it there.
// scripts/check_versions.php enforces this against the released artifact.
if (!defined('AGENT_VERSION')) { define('AGENT_VERSION', '1.6.7'); }
if (!defined('AGENT_VERSION_DATE')) { define('AGENT_VERSION_DATE', '2026-09-16'); }
if (!defined('AGENT_MIN_VERSION')) { define('AGENT_MIN_VERSION', '1.3.0'); } // Minimum supported agent version

// First agent release that collects OPNsense health telemetry (gateways, VPN,
// CARP, services, certificates). Firewalls below this report no health sections
// and are shown as "not reporting" rather than as a wall of false failures.
if (!defined('AGENT_HEALTH_MIN_VERSION')) { define('AGENT_HEALTH_MIN_VERSION', '1.6.0'); }

if (!defined('DATABASE_VERSION')) { define('DATABASE_VERSION', '1.4.0'); }
if (!defined('API_VERSION')) { define('API_VERSION', '1.1.0'); }
if (!defined('TUNNEL_PROXY_VERSION')) { define('TUNNEL_PROXY_VERSION', '2.1.0'); }

// System information
define('PHP_MIN_VERSION', '8.0');
define('BOOTSTRAP_VERSION', '5.3.8');
define('JQUERY_VERSION', '3.7.1');

// Changelog entries (most recent first)
function getChangelogEntries($limit = 10) {
    // $limit was accepted and ignored: about.php asks for 3 and rendered the
    // entire history. Slice before returning.
    $entries = [
        [
            'version' => '3.51.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'Publish Is Not Deploy',
            'changes' => [
                'FOUND: Publishing an agent version was deploying it. Syncing AGENT_VERSION to production made every firewall fetch and install the new agent on its next check-in, within about two minutes, with no step in between - so "release the agent" and "change every firewall right now" were one action. It fired twice on 2026-09-16: 1.6.6 installed itself on fw48 unprompted, and 1.6.7 did the same while the revert was being typed, the download having completed 45 seconds earlier',
                'ADDED: A rollout stage - held, pilot or fleet - consulted by agent_checkin.php before an update is offered. Holding suppresses agent_update_available, which is the field the agent acts on, so a held version is never installed',
                'CHANGED: The stage is bound to a version, not left as a standing mode. agent_rollout_stage applies only to agent_rollout_version, so when a newer version is published the stored stage no longer matches and the new version is held. The safe state is the one you get by forgetting, and promoting a release has to name the version it promotes - a flag left at "fleet" cannot deploy the next release',
                'ADDED: scripts/agent_rollout.php shows what is held and who is behind it, marks pilot firewalls, and promotes a version. It reports by default and moves only with --apply, printing which firewalls a promotion would reach before making it',
                'ADDED: firewalls.agent_rollout_pilot, defaulting to 0. Stage "pilot" with nobody marked offers the update to nobody rather than everybody: the failure mode has to be the conservative one',
                'CHANGED: Holding an update does not hide it. The check-in response carries agent_update_held and the rollout stage, because suppressing the offer must not trade one silent surprise for another',
                'ADDED: An unrecognised stage degrades to held, so a typo in a setting cannot release to the fleet',
                'ADDED: database/migrations/0021_staged_agent_rollout.sql, defaulting to held with no version promoted. An installation upgrading into this gets the gate closed',
                'ADDED: tests/agent_rollout_test.php. The property under test is not that a flag exists but that a stage left at "fleet" does not deploy the next release the moment it is published - the gate is otherwise only as good as somebody\'s memory',
                'CHANGED: scripts/build_agent_package.sh prints the promote step after a build, so the gate is discoverable at the moment it matters',
            ],
        ],
        [
            'version' => '3.50.0',
            'date' => '2026-09-16',
            'type' => 'minor',
            'title' => 'One Entry, One Line',
            'changes' => [
                'FIXED: The agent logged the full body of every queued command, raw. A scripted command - the nightly backup, an install, any of the probes in scripts/ - wrote dozens of unprefixed lines into the log that read like entries and were not, so tail on the agent log returned script text instead of what the agent had been doing. Found while verifying the 1.6.6 watchdog: tail -12 of the log returned the verification script rather than any of the agent activity it was asked about',
                'FIXED: This was not only cosmetic. The watchdog decides whether the agent is healthy by grepping that log for a recent successful check-in, so a logged command body could push real entries out of its window or contribute a matching line of its own. Command bodies can also carry credentials, and the result is reported to the manager regardless, which is where it belongs',
                'CHANGED: log_message() collapses newlines, carriage returns and tabs, so one call is exactly one line whatever it is handed. That invariant, not the call sites, is what makes the log safe to grep',
                'ADDED: command_summary() logs a bounded description - line count, byte count, and a 100 character excerpt - in place of the body. A 50KB single-line command is still one bounded entry',
                'FIXED: Installer output was appended straight into the agent log, burying the agent\'s own entries under install chatter at the moment they mattered most. Update transcripts now go to /var/log/opnmanager_agent_update.log, rotated on the same 10MB cap, with a one-line pointer left in the agent log',
                'FIXED: The agent self-update path - the one that actually performs a fleet upgrade - discarded all of its output. When 1.6.6 installed itself on fw48 there was no transcript of it anywhere; the only evidence was the version changing in a later check-in. It writes to the transcript file now',
                'ADDED: tests/agent_log_hygiene_test.php extracts the agent\'s own logging functions and runs them against a realistic multi-line command, asserting three calls produce three lines, that every line carries a timestamp prefix, that a watchdog-style grep still finds both check-ins either side of a logged command, and that the body never reaches the log verbatim',
            ],
        ],
        [
            'version' => '3.49.0',
            'date' => '2026-09-16',
            'type' => 'minor',
            'title' => 'Nothing Was Watching',
            'changes' => [
                'FOUND: Every way the agent could stop was permanent. Its self-update path has always ended in "rm -f PID_FILE; exit 0 - let rc.d restart us", and nothing ever restarted it: rc.d launched daemon(8) without -r, so the supervisor supervised nothing. The only recovery was console access to a firewall whose entire purpose is not needing any',
                'FOUND: watchdog.sh, written to restart a crashed agent, has shipped in every package since it was written and has never run on a single firewall. The only thing that ever asked for its cron entry was a comment in its own header, addressed to a human who did not read it. That is the third mechanism this month that stored an intention and never acted on it',
                'FIXED: daemon(8) now supervises the agent with -r and a 15 second restart delay, so a crash or a self-update restarts it. The stop path kills the supervisor before the agent, because killing only the agent is precisely what the supervisor reacts to',
                'FIXED: The installer schedules the watchdog in root\'s crontab every 5 minutes, idempotently, and the uninstaller removes it. Root\'s crontab and not /etc/crontab: OPNsense regenerates that from config.xml on every configuration apply and would drop the entry',
                'FIXED: The watchdog decided an agent was stuck by looking for a check-in in the last 20 log lines, which a busy but healthy agent fails. Now that it actually runs, that verdict would restart a working agent every five minutes, so it measures the age of the last successful check-in against five check-in intervals with a 15 minute floor - the agent backs off to 300s per check-in, and a firewall that has merely lost its uplink must not be restarted in a loop',
                'FIXED: The installer restarted the agent only if it was already running - the one case that needed no help. It now restarts unconditionally, detached and delayed, so it also recovers a dead agent without killing the process that has to report the command result',
                'CHANGED: A disabled or unconfigured agent waits and re-reads its configuration instead of exiting. Under supervision exiting would be a restart every 15 seconds, and on the old setup it meant enabling the agent in the GUI still required a manual service start',
                'ADDED: tests/agent_supervision_test.php reads the shipped scripts and asserts all three links exist: the supervisor restarts, the watchdog is scheduled, and the package contains the watchdog the cron entry points at',
                'CONTEXT: fw51 stopped at 12:02:40 on 2026-09-16 after a clean run of 200-response check-ins and stayed down. fw48 had done the same two days earlier and came back only when a queued reinstall finally ran, 12 hours later. Neither needed a fix on the firewall; both needed something to notice',
            ],
        ],
        [
            'version' => '3.48.0',
            'date' => '2026-09-16',
            'type' => 'minor',
            'title' => 'The Job That Stopped',
            'changes' => [
                'FOUND: Two scheduled jobs stopped running on 2026-09-13 and nothing said so for three days. Their crontab lines redirect into a file under /var/log; after a rotation the user running them could no longer create it, so every cycle cron started a shell, the shell failed on the redirect, and the PHP never ran. cron reported success, because the shell it started exited',
                'FIXED: monitor_agent_health.php is the only thing that maintains firewalls.status, so a firewall silent since noon still read "online" six hours later - and api/schedule_speedtest.php, which selects on that column, kept queueing work for it',
                'FIXED: The job registry knew six jobs. The crontab carries fourteen. The other eight - including both jobs that died - reported nothing, appeared on no page, and had no state that could look wrong. They all report now',
                'ADDED: job.stale alerts. The scheduled jobs are what detects everything else, so their own silence raises an incident like any other fault. A job is watched only from its first recorded run: this application does not own the crontab, so an unscheduled job stays quiet rather than alerting forever',
                'FIXED: A job dead for days displayed as "Running". The Scheduled Jobs page read the recorded status before the age of the last run, and a job killed mid-run leaves "running" behind permanently - the exact state a stopped job is most likely to be in',
                'ADDED: cron_jobs_overdue(), and 15 assertions covering the arming rule, the two-cycle tolerance, a row stuck at "running", and that every name an entrypoint reports is registered by a migration. The alert evaluator is itself a scheduled job: it reports that the others stopped, and cannot report its own death',
            ],
        ],
        [
            'version' => '3.47.0',
            'date' => '2026-09-16',
            'type' => 'minor',
            'title' => 'Waiting, Not Stuck',
            'changes' => [
                'FIXED: The hourly sweep no longer fails commands queued for a firewall that is simply offline. Against a live firewall an hour pending means something went wrong; against a firewall that is down the command is not stuck, it is waiting - and the thing most likely to be queued for a firewall that has gone quiet is the instruction that would bring it back. On 2026-09-15 a firewall lost its agent, the recovery install sat pending, and the sweep failed it at the one hour mark',
                'ADDED: An absolute seven-day cap, so a decommissioned firewall does not accumulate a backlog forever',
                'FIXED: An agent install or restart kills the process that would have reported the result, exactly as a reboot does - and it was not in the exempt set. The command sat in "sent", the ten-minute sweep returned it to "pending", and the firewall reinstalled its agent again. One firewall was lost to that loop',
                'ADDED: tests/command_sweep_test.php, and retry-suite coverage asserting the SQL and the PHP exempt the same commands - the sweep and the settler disagreeing about one command is how the loop began',
                'FIXED: The Agent row of the README compatibility table was the one version reference CI did not check, and it had drifted three agent releases behind. It is checked now',
            ],
        ],
        [
            'version' => '3.46.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'The Agent Signs',
            'changes' => [
                'ADDED: Agent v1.6.5 signs its requests. The server has verified HMAC-SHA256 signatures since 3.12.0 and handed every agent its secret with the note "Sign requests once supported" - no agent release ever did, so TLS was the only thing protecting command delivery and anyone holding a hardware_id and api_key could impersonate a firewall',
                'ADDED: The agent adopts the api_key and api_secret the server has been sending it all along, stores them 0600, and presents the key on every request. It previously authenticated with hardware_id alone - a value derived from the hardware, not a secret',
                'CHANGED: Credentials are adopted before they are used. The first check-in after an upgrade sends nothing new and stores the response; every check-in after that is signed. An agent never sends a signature it cannot yet compute, which matters because compatibility mode verifies a signature whenever one is present - a malformed one would refuse the check-in',
                'FIXED: 1.6.4 converted only the check-in. Presenting the key ratchets it, so the server immediately required it on every endpoint, and command and speedtest results were rejected as api_key_missing - the queue filled with commands stuck at "sent". Found on one firewall during a staged rollout, before the fleet was offered the update. All three POSTs go through one credentialed helper in 1.6.5 and no raw POST remains',
                'ADDED: tests/agent_request_signing_test.php extracts the agent\'s own shell functions, runs them, and checks the signature against the server computation - including that no signature is produced before credentials exist, that each request gets a fresh nonce, and that no POST bypasses the helper',
                'CHANGED: The 3.43.0 assertion that no released agent could sign failed the moment 1.6.4 was built, which is what it was for. It and the banner wording were updated together',
            ],
        ],
        [
            'version' => '3.45.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'The Database That Only Grows',
            'changes' => [
                'FOUND: Four tables were 96% of a 174 MB database after nine months of watching two firewalls, and nothing had ever pruned them - system_logs at 404k rows and 80 MB, plus three telemetry tables at another 82 MB. Roughly 1,800 rows per firewall per day with no upper bound. Fifty firewalls would add about 33 million rows a year',
                'FIXED: log_retention_days sat at 90 and was read by no code at all. The only caller of cleanup_old_logs() is a manual endpoint that passes a hardcoded 30 and is in no crontab, so logs were pruned when somebody remembered to click something, at a retention nobody chose. That is the fourth setting this session that stored an intention and ignored it',
                'ADDED: cron/prune_telemetry.php applies retention to all four tables, honouring log_retention_days and a new telemetry_retention_days. It reports by default and deletes only with --apply, because the first run on an established installation removes hundreds of thousands of rows',
                'ADDED: Deletes run in 5,000-row chunks with a pause between them. A single DELETE of that size holds locks long enough to stall the check-ins that write to the same tables every two minutes',
                'CHANGED: The job is registered but deliberately not scheduled, and carries no expected interval so it is never flagged overdue for an operator who has chosen not to run it. Adding it to cron is a decision about deleting history',
                'ADDED: tests/telemetry_retention_test.php covers table coverage, report-before-delete, the clamped retention values, chunking, and that the migration never overwrites a retention an operator has already chosen',
            ],
        ],
        [
            'version' => '3.44.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'Nothing Wrong, Pinned',
            'changes' => [
                'AUDITED: SQL injection. Every query reaching user input uses a prepared statement with placeholders. Five places interpolate, and each was read: an int clamped to 1..200, two ternaries between literal strings, and table names from hardcoded lists. Nothing to fix',
                'AUDITED: Stored cross-site scripting from the fleet. A firewall\'s check-in payload is not trusted input - the box belongs to a customer and may be compromised - and what it reports is shown in an administrator\'s browser. Every agent-supplied field is escaped wherever it is rendered. The two apparent hits were the in-app changelog and platform_versions, neither of which comes from an agent',
                'ADDED: tests/injection_guard_test.php pins both, because they are easy to lose one line at a time. The interpolation allowlist names the five reviewed files, so removing one is free and adding one is a decision someone has to make. Verified by planting a vulnerable file of each kind and watching both halves fail',
            ],
        ],
        [
            'version' => '3.43.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'Signing With No Signer',
            'changes' => [
                'FOUND: Agent request signing is complete on the server - HMAC-SHA256 verification, a freshness window, nonce replay rejection, a per-firewall ratchet, three fleet-wide policy modes - and the check-in response hands each agent its signing secret and the exact canonical string, annotated "Sign requests once supported." No agent release has ever contained a line of signing code. The agent does not store the secret, computes no HMAC, and sends no signature header',
                'SECURITY: That makes agent_auth_mode = require_signed a trap. It reads as the hardened option and would refuse every check-in in the fleet. It has no UI, so only someone who went looking in the database can set it - exactly the person likely to choose it',
                'ADDED: The interface warns, loudly and on every administrative page, when the configured signing policy cannot be satisfied by the agents actually deployed. It names the setting, says how many firewalls are affected, and gives the exact way back',
                'CHANGED: The policy is reported, never overridden. Silently downgrading a security setting is the failure this codebase has been full of - a refused fleet is survivable and reversible, a control that pretends to be on is not. The interface keeps working while agents are refused, so the banner is the route back',
                'FIXED: tests/undefined_functions_test.php and tests/endpoint_authz_test.php enumerated tracked files only, so a new file\'s definitions were invisible until committed - the same blind spot fixed in the identity guard in 3.30.1. Both scan tracked and new files now',
                'DOCUMENTED: The README records that signing is server-side only and that agent_auth_mode must stay at compatibility',
            ],
        ],
        [
            'version' => '3.42.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'The Restore Nobody Had Ever Run',
            'changes' => [
                'SECURITY: A failed restore left the fetched configuration in /tmp on the firewall. The script ran under set -e, so a non-zero exit from configctl ended it at that line and the rm -f below never executed. That file is a complete OPNsense configuration - user password hashes, IPsec pre-shared keys, RADIUS secrets. Cleanup is a trap now, covering the fetch failing, the sanity check rejecting the file, configctl failing, and the script being killed',
                'FIXED: A failed restore also printed nothing explaining itself, for the same reason - the error message sat below the line the script died on. The operator saw a bare non-zero exit',
                'FIXED: The real exit code is preserved. The first version of this fix used `if ! configctl ...; then RC=$?`, where $? is the negated test result - so a restore that failed with 3 reported 1. Caught by the new suite immediately',
                'FIXED: The agent credential files are read with $(cat ...) under set -e, so on a firewall whose agent credentials are missing the script exited at that line with no output at all. It now reports the missing credentials',
                'ADDED: tests/config_restore_test.php runs the generated script with curl and configctl stubbed, covering a successful restore, a failing one, and a fetched file that is not a configuration. Restore had never been performed on this installation - audit_log holds no restore entries - so this script had never been executed by anything until now',
                'VERIFIED: Backups themselves are sound. Stored outside the web root at 0640 owned by the web user, checksums and sizes match the database, every file parses as XML with an <opnsense> root, and validation genuinely parses rather than checking the file is non-empty',
            ],
        ],
        [
            'version' => '3.41.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'A Setting That Required Nothing',
            'changes' => [
                'SECURITY: require_mfa_for_admins sat in the settings table with no line of code reading it. Turning it on stored a 1 and changed no behaviour - the third setting found this way, after users.is_active and the two-factor enforcement that did not exist at all before 3.35.0',
                'FIXED: With it on, an administrator without a second factor is sent to enrol and the rest of the interface is unavailable until they do. Enrolment rather than refusal, because a setting that applies to administrators must not strand the administrator who turned it on. The enrolment page, verify2fa, login and logout stay reachable',
                'FIXED: The requirement is evaluated on every authenticated request, not only at login, so enabling it applies to sessions that are already open. API callers get a 403 with JSON rather than a redirect to an HTML page',
                'CHANGED: A settings or database error while evaluating the requirement leaves administrators in, and reasserts on the next request - failing closed here would strand everyone on a transient read error',
                'NOTE: raw_command_admin_only is also read by nothing, but api/queue_command.php already requires an administrator unconditionally, so the stricter behaviour is what happens regardless. The setting cannot loosen it; left alone and documented rather than wired to relax a restriction',
                'ADDED: tests/mfa_requirement_test.php covers the role scope, the enrolled case, every escape hatch, the JSON branch and the fail-open behaviour',
            ],
        ],
        [
            'version' => '3.40.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'One Place To Set The Mail Server',
            'changes' => [
                'FIXED: settings.php carried an SMTP modal that nothing ever opened - no code referenced #smtpModal - behind a handler that saved host, port, username, password and encryption but not the From address or From name, which the alert sender reads. Removed; smtp_settings.php is the one editor, reachable from the SMTP card in Settings',
                'FIXED: The Settings SMTP card said only "Email server" and gave no hint which one. It shows the configured host, the username and whether delivery is working, so an installation pointed at a server nobody chose is visible from the page rather than only in a log',
                'CHANGED: Every SMTP placeholder named a specific mail provider - smtp.gmail.com, your-email@gmail.com. That reads as a recommendation, and made a wrongly configured install look deliberate. They are neutral now',
                'ADDED: tests/smtp_settings_test.php requires that one editor saves all seven fields, that settings.php no longer saves any of them, that the card shows host and delivery state, and that no placeholder names a provider',
            ],
        ],
        [
            'version' => '3.39.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'A Form You Never Saw',
            'changes' => [
                'SECURITY: twofactor_setup.php accepted disable_2fa with no CSRF token. An operator who loaded an attacker\'s page had their second factor stripped, silently, from a form they never saw - and 3.35.0 had just made that second factor real',
                'SECURITY: alerts.php accepted new notification settings with no token, so redirecting alerts to an address of the attacker\'s choosing, or switching them off entirely, was one cross-site request',
                'SECURITY: api/request_queue.php queued an arbitrary method, path, headers and body to be proxied at a managed firewall, behind requireLogin() alone - no token and no role check. It now requires firewall.manage and a token',
                'SECURITY: package_builder.php rendered a CSRF token and its JavaScript sent one, and the handler never looked at it. A decorative token is worse than none: it reads as protection',
                'CHANGED: firewall_edit.php compared the token by hand with !== against the session value. It uses csrf_verify() now, which compares with hash_equals() and is the single implementation the rest of the application uses',
                'ADDED: tests/endpoint_authz_test.php now also fails if a browser-driven writer does not verify a token. It accepts only csrf_verify() - rendering a token does not count, which is what hid two of these',
            ],
        ],
        [
            'version' => '3.38.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'An Endpoint That Never Asked Who You Were',
            'changes' => [
                'SECURITY: api/manage_ssh_keys.php gated on check_authentication(), a function defined nowhere, so the expression was always false. GET was never gated at all - the dispatch ran it regardless - and returned SSH key fingerprints, types, bit sizes and timestamps for any firewall id to an unauthenticated caller. Confirmed against production before the fix: HTTP 200 with key metadata, no session',
                'FIXED: The same always-false expression made POST fail closed, so regenerating or deleting an SSH key returned 401 every time and those actions on the firewall details page have never worked. Reading key metadata now requires firewall.view and changing a key requires firewall.manage, through the application\'s own role checks',
                'SECURITY: api/updates/check.php and api/updates/download.php took an instance_id, validated nothing, and answered anyone - download returning file contents and SQL statements to apply. They belong to the multi-instance update distribution built alongside the licensing subsystem removed in 3.29.0, and nothing in this codebase calls them. Both require system.maintenance until a machine credential exists',
                'ADDED: tests/endpoint_authz_test.php fails if any endpoint that touches data does not authenticate its caller - by session, by the agent mechanism, or by an enrolment token. Comments are stripped first, so a file describing these bugs cannot satisfy its own check',
            ],
        ],
        [
            'version' => '3.37.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'Disabling An Account Now Disables It',
            'changes' => [
                'SECURITY: users.is_active sat in the schema with no line of code reading it. Setting it to 0 looked like disabling an account and did nothing at all - the account carried on logging in. Enforced now at login, and on open sessions',
                'FIXED: A disabled account is refused at login, checked after password verification so a wrong password and a disabled account are indistinguishable from outside. The refusal is audited',
                'FIXED: Sessions already open end within a minute of deactivation. Without that, "disable this user" would have meant "disable them at their next login". The check is rate limited to once a minute per session rather than run per request, and a database blip logs nobody out',
                'ADDED: Enable/Disable in user management. Deleting an account destroys the record of what it did; disabling keeps the audit trail and stops the login. You cannot disable your own account, and the last active administrator is refused - deactivating it would leave nobody able to administer the installation',
                'ADDED: The user list shows account status and when each account last signed in. Two admin accounts on the maintainer\'s installation had never signed in at all and nothing surfaced that',
                'FIXED: The user listing query selected neither is_active nor last_login, so both would have rendered as decoration regardless of the stored values',
                'ADDED: tests/account_status_test.php covers refusal at login, session termination, the self and last-admin guards, and that the listing selects the columns it displays',
            ],
        ],
        [
            'version' => '3.36.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'Who Changed That Setting',
            'changes' => [
                'SECURITY: settings.php rendered the decrypted SMTP password into the page as an input value. type="password" hides it on screen; it was still in the page source, the browser cache and anything in between. smtp_settings.php has always declined to echo it back',
                'FIXED: settings.php treated a blank password field as "store the empty string" rather than "unchanged", so opening the SMTP dialog to edit any other field and saving wiped the credential. smtp_settings.php already handled this correctly; the two had diverged',
                'FIXED: Settings changes were never audited. save_setting() was defined twice - once in settings.php, once in smtp_settings.php - identically, and neither recorded anything. The settings table has no updated_at, so audit_log held 1,657 entries without a single settings change among them, and a question about when a credential last changed had no answer',
                'ADDED: save_setting() moved to inc/secrets.php and records the setting name with its previous and new value. save_secret_setting() records that a credential was set, replaced or cleared - the name and the fact, never the value, because an audit trail holding credentials is a second place to steal them from',
                'ADDED: tests/settings_audit_test.php pins all four: no page prints a stored credential, a blank password means unchanged, changes are audited with their previous value, and the credential audit line never carries the credential',
            ],
        ],
        [
            'version' => '3.35.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'Two-Factor Was Never Asked For',
            'changes' => [
                'SECURITY: Two-factor authentication was never enforced. login() set a full session on the password alone and never read totp_secret; nothing redirected to verify2fa.php, and no code path did. An account showing "2FA is currently enabled" on its profile was protected by a password and nothing else - the badge asserted a control that did not exist',
                'SECURITY: verify2fa.php called clear2FA() on a *correct* code. That function is defined nowhere, so entering the right number produced a fatal while a wrong one returned a tidy "Invalid code". Even someone who reached the page could not complete two-factor',
                'FIXED: login() now holds an enrolled account at a pending step - no user_id is set, so isLoggedIn() is false and every requireLogin() page refuses - until verify2fa.php confirms the code and promotes the session. The pending state expires after 5 minutes and is bound to the address that supplied the password',
                'FIXED: The verification form had no CSRF token and the page read $_SESSION[user_id], which is only set once a session is already authenticated. Both corrected',
                'FIXED: Seven more calls to functions that do not exist, each a fatal when reached: write_log() and log_action() across five endpoints and two cron jobs (inc/logging.php provides log_event(), with the arguments in a different order), and check_authentication() in development/todo.php',
                'CHANGED: Reaching a firewall no longer depends on that firewall\'s certificate anywhere. Live paths already connected with verification off; inc/opnsense_api.php defaulted the other way, so adopting it would have made management fail exactly when a certificate problem most needed looking at. Certificate state is still collected and reported',
                'ADDED: tests/undefined_functions_test.php tokenises every tracked PHP file and fails if a call has no definition, honouring function_exists() guards. It also pins that no connection path requires a valid firewall certificate',
            ],
        ],
        [
            'version' => '3.34.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'What Is Actually On The Server',
            'changes' => [
                'ADDED: scripts/check_production_drift.php compares a deployed tree against the repository - tracked files whose deployed copy differs, tracked files missing from the deployment, and deployed files that are not tracked. Deployment here is a file copy, not a checkout, and nothing checked it',
                'FIXED: The deployment was carrying 111 files that are in no repository: a year of session notes, emergency shell scripts, SQL dumps, .broken copies of live pages, an .archive directory of 16 old page versions, and six test endpoints. Archived off the server and removed. No unauthenticated exposure - the test endpoints returned 401 and .archive was blocked at the web server - so this was hygiene, not a breach',
                'FIXED: A stale copy of check_agent_install.php sat at the deployment root still carrying the hostname removed in 3.30.0. tests/server_identity_test.php scans the repository and never sees what is actually being served, which is exactly the gap the drift checker closes',
                'FIXED: about_backup_20251009.php, a dated copy of about.php, was web-reachable and returned 500 on every request. A sweep of all 93 deployed pages now finds no 500s',
                'FIXED: Two test suites added earlier today had never been deployed, and the CI workflow on the server was stale. The drift checker found both on its first run',
                'FIXED: Unanchored .gitignore rules for session notes (*_GUIDE.md, *_IMPLEMENTATION.md and eight more) matched at every depth and silently excluded real documentation under docs/. Anchored to the root, and docs/AGENT_RECOVERY_GUIDE.md and docs/AI_LOG_ANALYSIS_IMPLEMENTATION.md are tracked now. This is the third time an unanchored ignore rule has hidden a real file',
                'ADDED: tests/referenced_files_test.php now fails if any of those rules loses its anchor',
            ],
        ],
        [
            'version' => '3.33.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'Alerts Nobody Received',
            'changes' => [
                'FIXED: Alerting detected, raised and recorded correctly - and delivered nothing. On the maintainer\'s installation alert_history held 911 notifications, every one failed, not a single one sent, going back to the first row in the table. The SMTP credential had been rejected by the provider the whole time. Nothing surfaced it: no banner, no tile, no health signal. The only trace was 114,355 lines in a log file',
                'ADDED: inc/notification_health.php tracks delivery per channel - consecutive failures since the last success, and whether a channel has ever delivered at all, which distinguishes a misconfiguration from an outage',
                'ADDED: A banner on every administrative page when a channel has failed three consecutive attempts. Not dismissible: the failure persists until someone fixes a credential, and a banner that can be waved away is how 911 undelivered alerts go unnoticed. It states plainly that it cannot be emailed',
                'FIXED: send_smtp_email() returned "Internal server error" for every failure, so the response that explains the problem - 535-5.7.8 Username and Password not accepted - reached only the log. It now returns the server\'s own response, redacted: base64 runs are stripped, because an echoed AUTH line carries the username and password and would otherwise land in alert_history and on screen',
                'ADDED: tests/notification_health_test.php covers never-delivered versus outage, partial delivery, per-channel isolation, and that redaction keeps the SMTP code while removing a credential',
            ],
        ],
        [
            'version' => '3.32.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'The Scheduled Jobs Page Was Fiction',
            'changes' => [
                'FIXED: api/manage_tasks.php was fatal on every request. It gated on check_authentication(), a function defined nowhere in the codebase, so the Scheduled Tasks page could never list a job and its toggles could never save. PHP stops at the fatal, so nothing ran unauthenticated - a feature that had never worked, not a way past the login',
                'FIXED: The toggle beside each job wrote scheduled_tasks.enabled, which no cron script, include or scheduler has ever read. Switching a job off changed a column and left it running on its normal schedule',
                'FIXED: scheduled_tasks was seeded once by hand with five rows. Two named a real job on the wrong schedule, three - Firewall Health Check, SSH Tunnel Cleanup, Proxy Session Cleanup - have never been scheduled at all, and the four jobs that do run were absent entirely. Migration 0018 replaces them with the six real ones',
                'FIXED: last_run was NULL on every row because nothing ever wrote it, so a job running every five minutes displayed as never having run',
                'FIXED: scheduled_tasks.id was NOT NULL with no AUTO_INCREMENT, so every insert needed an explicit id. That is why the table was populated once and never grew a row for a job added later',
                'ADDED: inc/cron_runs.php. Each cron entrypoint records its own start, outcome and duration, with completion written from a shutdown handler so a fatal is reported rather than leaving the row stuck at running. Every write is wrapped - a scheduled backup must never be lost because bookkeeping could not write a row',
                'CHANGED: The page reports rather than pretends to control. Jobs are started by the system crontab, which this application does not own; the page shows when each last ran, how long it took, whether it failed, and flags a job overdue at twice its expected interval',
                'CHANGED: The page was unreachable - nothing linked to it. It is now in the Admin sidebar as Scheduled Jobs',
                'ADDED: tests/scheduled_jobs_test.php asserts every registered job names a script that exists and every cron entrypoint reports itself',
            ],
        ],
        [
            'version' => '3.31.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'Agent 1.6.3, Built By A Script',
            'changes' => [
                'ADDED: Agent v1.6.3, which carries the checkin.sh fix from 3.30.2 - forcing a check-in no longer relabels a current agent as eight releases old. Firewalls are offered it on their next check-in',
                'ADDED: scripts/build_agent_package.sh. There was no build script: packages were tarred by hand, which is how plugin/src came to differ from the package it was supposedly built from and how checkin.sh shipped a version label eight releases behind agent.sh. The version comes from AGENT_VERSION, the package name and the agent\'s self-reported version cannot disagree, and the build refuses to overwrite a version that already exists',
                'CHANGED: The package is built deterministically - sorted entries, fixed mtime, numeric owner - so rebuilding the same source gives identical bytes and a diff against a published artifact means the source really changed. Verified: 1.6.3 rebuilds byte-for-byte, and differs from 1.6.2 in exactly the two intended files',
                'FIXED: Packages were built from a file list, so the empty service/templates tree every previous release shipped would have been silently dropped. Directory entries are included',
                'CHANGED: downloads/manifest.json re-signed to cover 1.6.3; all 51 artifacts verify against the pinned Ed25519 public key',
            ],
        ],
        [
            'version' => '3.30.2',
            'date' => '2026-09-15',
            'type' => 'patch',
            'title' => 'One Button Aged The Agent Eight Releases',
            'changes' => [
                'FIXED: checkin.sh carried its own AGENT_VERSION literal saying 1.1.7 while agent.sh said 1.6.2. checkin.sh is what the `checkin` configctl action runs - the force-check-in button and `configctl opnmanager_agent checkin` both invoke it - and the manager stores whatever version the payload reports. One press relabelled a current agent as eight releases old, dropping it below the minimum supported version, costing nine points of health score and flagging it as needing an update, until the next scheduled check-in put the real version back. Only agent.sh declares the version now',
                'FIXED: README claimed agents below the minimum supported version "are refused". Nothing refuses them - AGENT_MIN_VERSION is read only by the health score. Corrected to say what actually happens',
                'FIXED: README\'s compatibility table and the sentence under it both still said 3.29.0, two releases behind - and that same sentence is the one claiming "CI enforces these ... so no reference in the tree can drift out of step". scripts/check_versions.php now covers both, so the claim is true',
                'FIXED: README said the agent installer fetches its package from the project\'s distribution host with PLUGIN_URL hardcoded. That stopped being true in 3.30.0. Replaced with the limitation that was always real and never stated: downloads/ is gitignored, so a fresh clone has no agent package to serve and enrolment 404s until the operator builds and places one',
                'ADDED: tests/agent_package_test.php asserts the agent version has exactly one source and fails if a second literal reappears',
            ],
        ],
        [
            'version' => '3.30.1',
            'date' => '2026-09-15',
            'type' => 'patch',
            'title' => 'The Guard Could Not See New Files',
            'changes' => [
                'FIXED: tests/server_identity_test.php scanned only `git ls-files`, so a file that existed but had not been committed yet was invisible to it. That is exactly when a literal slips through: the suite passed locally and failed in CI on its own first commit, because inc/server_identity.php named the offending hostname in its docblock. It now scans tracked and new files together',
                'FIXED: Removed that hostname from the inc/server_identity.php docblock',
            ],
        ],
        [
            'version' => '3.30.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'Your Own Address',
            'changes' => [
                'FIXED: 38 files carried the maintainer\'s own hostname and public IP as literals, so a self-hosted install pointed its customers\' firewalls at a server its operator does not control. All of it now resolves through inc/server_identity.php, from the server_url setting, APP_URL, manager_fqdn or config/instance.json',
                'FIXED: The enrolment script appended a hardcoded SSH public key to root\'s authorized_keys on every firewall it enrolled - and not a dedicated key, but the per-firewall key generated for firewall 21 on one installation. Every deployment authorised a key its operator does not hold for root on its customers\' firewalls. inc/enrollment_key.php generates this installation\'s own key on first use and enrolment refuses without it',
                'FIXED: The agent download URL, the tunnel proxy URLs, the nginx tunnel certificate paths and server_name, the tunnel health check\'s certificate paths, and the "allow SSH from the manager" rule built by auto-onboarding and setup_permanent_ssh_rule.php were all wrong on any install but one',
                'FIXED: api/get_map_locations.php labelled the management server with a literal hostname, ssh_access_instructions.php told operators to permit a third party\'s IP through their firewall and trust its SSH key, and api/ai_scan.php told the model that the maintainer\'s IP is a trusted SSH source',
                'CHANGED: downloads/plugins/install_opnmanager_agent.sh takes OPNMGR_BASE_URL from whoever emits the install command, since a script served as a static file cannot know which host fetched it. It refuses rather than guessing',
                'FIXED: api/tunnel_keep_alive.php pointed at /download/tunnel_agent.sh; the file is served from /downloads/. Combined with the hardcoded host, the restart command could not work anywhere',
                'REMOVED: agent_checkin.php queued a reverse-tunnel setup command on every check-in from a firewall with no tunnel. It fetched setup_reverse_proxy.sh, which has never existed here, and piped the resulting error page into sh on the firewall',
                'CHANGED: Real hostnames and IPs in CHANGELOG.md and the in-app changelog replaced with documentation placeholders. They remain in Git history, which has not been rewritten',
                'ADDED: tests/server_identity_test.php fails if any installation-specific host, IP or SSH key reappears in a tracked file, and covers the resolver\'s precedence and its unconfigured case',
            ],
        ],
        [
            'version' => '3.29.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'The Licence Server Is Gone',
            'changes' => [
                'REMOVED: The licensing subsystem. OPNManager is MIT licensed and self-hosted, and the project describes these tools as having no licence server - but the repository shipped one, and it did not work: license_server.php queried tables that scripts/migrate.php never created, so they existed on no installation and the page threw wherever it was opened. It was also unlinked from every navigation',
                'REMOVED: api/instances/register.php, which inserted into customer_instances - a table that exists in no schema file and on no installation',
                'REMOVED: The Licensing System section of FEATURES.md - thirty-seven lines marked "Production" describing licence tiers with monthly prices, grace periods and feature degradation, for a system that has never existed in a project that is free',
                'CHANGED: Migration 0017 drops the licensing tables. Run php scripts/migrate.php after upgrading',
            ],
        ],
        [
            'version' => '3.28.1',
            'date' => '2026-09-15',
            'type' => 'patch',
            'title' => 'The About Page Was Seven Releases Behind',
            'changes' => [
                'FIXED: This changelog was stale by seven releases - about.php advertised v3.21.0 as newest on a 3.28.0 install. scripts/check_versions.php now checks it against VERSION, as it already did for CHANGELOG.md, so CI fails rather than shipping a stale About page',
                'FIXED: getChangelogEntries($limit) ignored its argument, so about.php asked for three entries and rendered all thirty-three',
            ],
        ],
        [
            'version' => '3.28.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'The Lockdown Policies Actually Work',
            'changes' => [
                'ADDED: Web GUI IP Lockdown and Secure Outbound Lockdown are implemented. Both were UI controls with nothing behind them - one fatal on save, the other fatal on load. They now queue a policy script through the agent like every other firewall change, so no agent release and no new credentials on the firewall',
                'ADDED: Every generated policy backs up /conf/config.xml, marks the rules it writes and removes only those (so it is idempotent and never touches a human-written rule), validates the XML before installing, and restores the backup if the filter reload is rejected',
                'ADDED: Web GUI lockdown applies on WAN only and always permits this manager first, so a typo cannot cut the platform off from the firewall it manages. Unparseable entries are reported rather than silently dropped',
                'CHANGED: Enabling outbound lockdown now requires typing RESTRICT <hostname> and is admin-only. It blocks mail, VPN and NTP on a customer network, which is more than one toggle click should commit to',
                'FIXED: The walkthrough video links pointed at releases/latest, which broke the moment a later release shipped without the media attached. They are pinned to the v3.25.0 assets',
                'NOTE: The lockdown policies have not been run against a live OPNsense firewall. The generator is tested by executing the real scripts against a sample configuration',
            ],
        ],
        [
            'version' => '3.27.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'Nine Missing Files, Two Of Them Fatal',
            'changes' => [
                'FIXED: Saving a firewall crashed if the Web GUI IP list changed - require_once on scripts/queue_command.php, a file that has never existed, firing after the UPDATE had already committed',
                'FIXED: api/tunnel_management.php and api/apply_secure_lockdown.php were fatal on load, both reachable from the UI, each requiring an inc/ file that has never existed',
                'ADDED: api/test_pushover.php, api/test_ssl.php and api/test_nginx.php - three UI buttons that had been calling endpoints nobody had written',
                'FIXED: scripts/install_snyk.sh restored; a bulk "remove unused files" commit deleted it while security_scan.php still exec\'d it',
                'REMOVED: inc/api_auth.php, 34 orphan lines redefining requireLogin/requireAdmin unguarded - loading it beside inc/auth.php is a hard fatal',
                'CHANGED: .gitignore patterns are anchored to the repository root. Unanchored test_* and *_test.* rules had been excluding real product endpoints, so a fresh clone 404\'d on them',
                'ADDED: tests/referenced_files_test.php - every URL the UI fetches and every include the server builds must exist',
            ],
        ],
        [
            'version' => '3.26.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'Two-Factor Enrolment Works Now',
            'changes' => [
                'FIXED: Two-factor enrolment could never have succeeded. The otpauth URI carried the secret as hex, but the format requires Base32 - hex contains 0, 1, 8 and 9, which are not in the Base32 alphabet, so an authenticator derived a different key and the six digits never matched',
                'FIXED: The enrolment QR was fetched from api.qrserver.com with the secret in the query string, handing the shared TOTP secret to a third party. It is rendered on this server as an inline SVG, and api.qrserver.com is gone from the img-src CSP directive',
                'ADDED: bacon/bacon-qr-code ^2.0 renders the QR. Run composer install --no-dev after upgrading',
                'ADDED: tests/twofactor_test.php checks Base32 against the RFC 4648 vectors and runs a full TOTP round-trip',
            ],
        ],
        [
            'version' => '3.25.1',
            'date' => '2026-09-15',
            'type' => 'patch',
            'title' => 'Phantom Columns',
            'changes' => [
                'FIXED: The on-demand web proxy was broken at both ends - firewall_proxy.php wrote a request_body column and read a status_code column; request_queue has body and response_status',
                'FIXED: The profile page reported two-factor as disabled for every account, testing two_factor_secret when the column is totp_secret',
                'FIXED: "Send Test Email" reported failure for mail it had delivered - api/test_email.php wrote the same non-existent alert_history.recipient_email column',
                'ADDED: tests/schema_columns_test.php - every column named in a literal INSERT or UPDATE must exist in the shipped schema',
            ],
        ],
        [
            'version' => '3.24.1',
            'date' => '2026-09-15',
            'type' => 'patch',
            'title' => 'Alert History Was Never Recorded',
            'changes' => [
                'FIXED: inc/alerts.php inserted a recipient_email column that does not exist, so every insert threw and a delivered email was reported to the caller as a send failure. Nothing was ever written to alert_history, which silently disabled the repeat-notification suppression that reads it',
                'FIXED: alert_history.php rendered recipient_emails and sent_successfully, neither of which is a column, so every alert displayed as Failed with 0 recipients',
                'FIXED: alerts.php carried a credential-shaped string as the Pushover token placeholder',
            ],
        ],
        [
            'version' => '3.24.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'Screenshots And A Walkthrough',
            'changes' => [
                'ADDED: A 28-capture screenshot gallery in docs/SCREENSHOTS.md, half light and half dark, at 1440x1000 and deviceScaleFactor 2',
                'ADDED: A recorded walkthrough of the running application with a highlight clip, poster, WebVTT captions and a transcript',
                'ADDED: scripts/demo_fixture.php writes real OPNsense-shaped configuration XML and calls the application\'s own drift functions, so the drift and search screenshots are computed by the product rather than staged',
                'CHANGED: Captures record their route, theme and viewport in docs/images/github/captures.json',
            ],
        ],
        [
            'version' => '3.22.0',
            'date' => '2026-09-15',
            'type' => 'minor',
            'title' => 'A README That Says What This Is',
            'changes' => [
                'CHANGED: The README leads with the product rather than a reverse-chronological pile of release notes for 3.12 through 3.17, all of which were already in CHANGELOG.md',
                'REMOVED: The "Production Stable" claim - nothing in the repository defines a release policy, support window or validation gate it could refer to',
                'FIXED: Health telemetry was still described as "not in a published agent release yet". That stopped being true at agent 1.6.0, and the real requirement is 1.6.2',
                'CHANGED: The architecture description had the SSH direction backwards. Agents check in outbound; the manager only connects in on demand, after the agent opens a time-limited rule',
            ],
        ],
        [
            'version' => '3.21.0',
            'date' => '2026-09-05',
            'type' => 'minor',
            'title' => 'Health Actually Ships',
            'changes' => [
                'ADDED: Agent 1.6.2 released, so the Firewall Health page finally reports. The health collector had sat in the plugin source since 3.17.0 with no tarball to carry it, and the newest published agent was 1.5.6 - so every firewall in the fleet read "Not reporting health - agent 1.5.6 (requires 1.6.0+)". The server side (health_ingest(), the gateway/VPN/service/certificate tables, firewall_health.php) was complete the whole time; only the package was missing',
                'FIXED: The installer never copied health_collect.py. It installs scripts/*.sh, a glob that silently excludes the collector, so shipping 1.6.0 as-is would have upgraded every agent to a version whose get_health_json() finds no script, returns {}, and reports nothing - the same empty page with a higher version number. The installer now copies and chmods *.py and fails verification when the collector is absent',
                'FIXED: Gateways always reported as zero. configctl interface gateways status returns a bare object keyed by gateway name, with no envelope; collect_gateways() looked only for an "items" or "gateways" key, found neither, and returned an empty list. It now falls back to any dict-shaped value while still accepting the enveloped and list shapes (agent 1.6.1)',
                'FIXED: Services showed as stopped that were never configured. The collector treated a service as installed if /usr/local/etc/rc.d/<name> existed - true for anything an installed package ships - and hardcoded enabled=true, so the fleet test enabled=1 AND running=0 collapsed into "not running". An unconfigured openvpn, strongswan or radvd counted as stopped on a healthy firewall, and every newly enrolled firewall would have shown the same. The collector now reads configctl service list, which reports only what OPNsense actually has configured (agent 1.6.2)',
                'FIXED: Multi-instance services (dpinger per gateway, wireguard per tunnel) are reported once per instance and are now merged, counting as up only when every instance is',
                'FIXED: "~", how OPNsense writes an unmeasured field, was stored as a gateway address or monitor rather than as null',
                'FIXED: watchdog.sh shipped in every released tarball but existed nowhere in plugin/, so packaging from source would have quietly dropped it from new installs',
                'FIXED: downloads/plugins/install_opnmanager_agent.sh is now tracked. downloads/ was gitignored wholesale and that script is authored source with no other copy, so a fix to it lived on the release host and nowhere else',
                'CHANGED: firewall_health.php renders AGENT_HEALTH_MIN_VERSION instead of two hardcoded "1.6.0" literals',
            ],
        ],
        [
            'version' => '3.20.0',
            'date' => '2026-08-31',
            'type' => 'minor',
            'title' => 'No Reboot Loops, Real Retention',
            'changes' => [
                'ADDED: Backup retention is now enforced. It has been configurable since 3.12.0 and applied by nothing - backup_retention_days was seeded at 90 and never read, and the settings UI wrote a separate months/count scheme that was also never read, so no backup was ever deleted. Retention is now a window in days enforced by cron/prune_backups.php',
                'ADDED: backup_retention_min_keep (default 3) never prunes a firewall\'s newest backups whatever their age, so a firewall that has stopped checking in cannot lose every copy of its configuration to an age-only sweep',
                'ADDED: cron/prune_backups.php reports by default and deletes only with --apply, with --days= and --floor= overrides',
                'CHANGED: The backup retention dialog now asks for a window in days and a minimum to keep, replacing the two-mode months/count form whose values were never applied',
                'CHANGED: Migration 0014 removes the superseded months/count settings, carrying an existing time-based policy across as months x 30',
                'FIXED: A reboot was redelivered on every check-in, turning one reboot request into a reboot loop. checkQueuedCommands() resets any command left in \'sent\' for ten minutes back to \'pending\', assuming no result means the agent never received it. A reboot can never report a result - the firewall stops executing partway through the command - so it was reset and handed back to the box the moment it finished booting. Observed on fw-chi-edge02.northwind.example: command 8017 (/sbin/reboot) was queued at 12:28:01 and had already been redelivered at 12:39:25',
                'FIXED: Commands that take the firewall down (/sbin/reboot, /sbin/halt, /sbin/poweroff, shutdown -r/-h/-p) are now settled as completed when they time out, and excluded from the stuck-command reset in both the general and update-agent paths. A missing result is recorded as the expected outcome rather than read as a delivery failure',
                'ADDED: settle_unacknowledgeable_commands() in inc/agent_commands.php with tests/agent_command_retry_test.php',
            ],
        ],
        [
            'version' => '3.19.4',
            'date' => '2026-08-31',
            'type' => 'patch',
            'title' => 'Map Rollback',
            'changes' => [
                'Reverted the 3.19.3 dashboard map changes — the new tiles and layout were worse than what they replaced',
                'Network map is back to the OpenStreetMap basemap and prior sizing',
            ],
        ],
        [
            'version' => '3.19.2',
            'date' => '2026-08-31',
            'type' => 'patch',
            'title' => 'Reboot State Measured, Not Guessed',
            'changes' => [
                'FIXED: reboot_required was never measured. The agent has never reported a reboot flag, so agent_checkin.php preserves the stored value on every check-in and the column was writable only by code that inferred it. fw-chi-edge01.northwind.example asserted "reboot required" continuously from 2026-03-04 across many actual reboots, while fw-chi-edge02.northwind.example reported no reboot needed immediately after installing a base and kernel it had not booted into. It is now derived by comparing estimated boot time against the completion of the last update known to have installed successfully',
                'FIXED: An unreadable uptime no longer clears a real pending reboot. The parser returns null rather than zero for Unknown/empty/unrecognised values, and an indeterminate state leaves the stored value untouched',
                'FIXED: A failed update is no longer counted as installed. The agent reports every command as completed regardless of outcome, so the derivation requires the OPNMGR_UPDATE_EXIT=0 marker rather than trusting command status',
                'ADDED: inc/reboot_state.php with tests/reboot_state_test.php (22 assertions), wired into CI',
            ],
        ],
        [
            'version' => '3.19.1',
            'date' => '2026-08-31',
            'type' => 'minor',
            'title' => 'Trackable Firewall OS Updates',
            'changes' => [
                'FIXED: "Upgrade Firewall OS/Dependencies" left no evidence that anything had happened, so a successful update was indistinguishable from a failed one. The button set a firewalls.update_requested flag and returned success; agent_checkin.php cleared that flag the moment it read it, before the agent had run anything; the agent executed the upgrade with nohup and never reported a result; and no row was written to the command history. Operators reasonably concluded the feature was broken and clicked again, running the upgrade repeatedly. The update is now dispatched as a normal tracked command, so it appears in the command history and its real output and exit status are recorded',
                'FIXED: A full upgrade could be killed partway through. The agent runs queued commands as eval \"$cmd\" 2>&1 | head -1000; a real upgrade emits far more than 1000 lines and once head exits the writer receives SIGPIPE. install_updates now redirects the updater output to /var/log/opnmanager_update.log and returns only a bounded tail',
                'FIXED: The agent hardcodes \"status\":\"completed\" for every command and never transmits an exit code, so a failed upgrade looked identical to a successful one. install_updates now echoes its own exit status as a marker that the server reads to decide the real outcome. An upgrade reporting back without a recognisable marker is recorded as unconfirmed rather than assumed successful',
                'FIXED: agent_checkin.php optimistically set updates_available = 0 and reboot_required = 1 at the moment an update request was handed to the agent, asserting an outcome before any work had been done. A request that never executed still left the server reporting "no updates available, reboot required". Both values are now only ever set from what the agent actually reports',
                'ADDED: Requesting an update while one is still pending or in flight no longer queues a second one. The endpoint returns the existing command id instead, so repeat clicks are harmless',
                'CHANGED: The firewall list now shows the queued command number after a successful request, and no longer reloads the page two seconds later - that reload erased the only feedback the operator had been given',
                'CHANGED: api/update_firewall.php is gated on the update.install capability rather than a blanket admin check, matching the rest of the update paths',
                'REMOVED: triggerOPNsenseUpdate() and triggerAgentUpdate() in api/update_firewall.php. Both were curl-based helpers that nothing ever called',
            ],
        ],
        [
            'version' => '3.18.0',
            'date' => '2026-08-31',
            'type' => 'minor',
            'title' => 'Real Security Posture and No Silent Config Staleness',
            'changes' => [
                'FIXED: The Security Status panel on the firewall detail page was hardcoded HTML. Every firewall displayed "SSH Access - Enabled - Port 22" and "API Authentication - Enabled" with green ticks regardless of its actual configuration. It is now computed from that firewall\'s own configuration and recorded agent state',
                'ADDED: SSH exposure distinguishes four states - service disabled, running but no WAN rule permits it, WAN with source restrictions, and open to any source - because those carry very different risk and the old panel showed all four identically',
                'ADDED: The panel reports root login and password authentication, the WAN rules permitting SSH with their sources, and real agent authentication state including whether the API key is pinned and requests are signed',
                'FIXED: resolve_backup_path() treated an unreadable backup the same as a missing one, so callers silently fell back to an older configuration. Backups live in a www-data-only directory, so anything running as another user quietly answered from stale data - a six-month-old config was used to answer whether SSH was exposed',
                'ADDED: drift_config_freshness() reports which configuration an answer came from, whether newer ones were skipped and why, and surfaces it in the UI',
                'CHANGED: The ssh_on_wan configuration check is split into ssh_open_to_world (a WAN rule from ANY source - the finding that matters) and ssh_on_wan (informational, includes source-restricted management access)',
            ]
        ],
        [
            'version' => '3.17.1',
            'date' => '2026-08-27',
            'type' => 'patch',
            'title' => 'Remove the superseded offline-check cron',
            'changes' => [
                'REMOVED: cron/check_offline_firewalls.php, superseded by cron/evaluate_alerts.php. It emailed on a timer with no notion of an ongoing problem - it could not say "still down", never said "back online", and gave nobody anything to acknowledge',
                'REMOVED: its cron entries, which were scheduled in BOTH the root and administrator crontabs at one-minute intervals, so it had been running twice a minute',
                'REMOVED: its 8.2 MB log file, which it wrote into the cron directory inside the document root',
            ]
        ],
        [
            'version' => '3.17.0',
            'date' => '2026-08-27',
            'type' => 'minor',
            'title' => 'AI Redaction, Dashboard Roll-ups and Final Hardening',
            'changes' => [
                'SECURITY: api/ai_scan.php sent the entire raw config.xml to an external AI provider with no redaction whatsoever. An OPNsense configuration carries password hashes, X.509 and WireGuard private keys, IPsec pre-shared keys and SNMP communities - all of it was being transmitted to a third party',
                'ADDED: inc/ai_redaction.php strips credential material before anything leaves the server. Redaction cannot be disabled; a configuration that will not parse is refused rather than sent raw',
                'ADDED: AI is opt-in and off by default. An administrator must read a disclosure of exactly what is and is not transmitted before enabling it, and can disable it entirely',
                'SECURITY: api/tunnel_management.php allowed any signed-in user, including read-only, to kill tunnels and run privileged commands. It now requires the tunnel.close capability',
                'SECURITY: ai_reports.php called unserialize() on values originating in AI provider responses without restricting classes',
                'SECURITY: Shell interpolation escaped across system_backup.php, security_scan.php, update_docs_trigger.php and the tunnel scripts',
                'FIXED: ai_settings.php included the page header before checking authorisation, so the shell rendered before a redirect could be sent',
                'ADDED: Dashboard KPI tiles for reboots pending, gateways down, VPN down, drift, backup failures, certificate expiry, critical incidents and maintenance. Tiles only appear when non-zero, so a row of numbers means something needs attention',
                'ADDED: scripts/check_versions.php enforces a single authoritative version source and runs in CI, so README, inc/version.php and the CHANGELOG cannot drift apart again',
            ]
        ],
        [
            'version' => '3.16.0',
            'date' => '2026-08-27',
            'type' => 'minor',
            'title' => 'Fleet Update Management, Bulk Operations and Configuration Search',
            'changes' => [
                'ADDED: Fleet update view showing customer, site, current and available version, agent version, update and reboot state per firewall',
                'ADDED: Update rings (canary, pilot, production) as a rollout mechanism, not customer tiers. Progression is manual unless auto-progress is explicitly enabled, and a ring containing any failure never counts as clean',
                'ADDED: HA-safe updates. Members of a CARP pair are never dispatched simultaneously, the BACKUP is updated before the MASTER, and the second member is held until the first is back online with CARP settled',
                'ADDED: Bulk operations across selected firewalls, with typed confirmation phrases that include the target count for high-risk actions. Raw shell is deliberately not a bulk operation',
                'ADDED: Deterministic fleet configuration search over stored backups, with named checks (SSH on WAN, web GUI on WAN, any-any pass rules, UPnP) plus literal and CIDR matching. No AI required',
                'ADDED: Agent health view covering version currency, check-in punctuality, authentication state, signing support and clock skew',
                'SECURITY: The restore path built its download URL from the client-supplied Host header with no scheme, and pointed at a session-protected endpoint a firewall cannot authenticate to. Restores now use an agent-authenticated, single-use token endpoint',
                'ADDED: Restore safety - the backup is validated and checksum-verified, a pre-restore snapshot is taken first, the firewall hostname must be typed to confirm, and success is only recorded once the agent checks in again after the restore',
            ]
        ],
        [
            'version' => '3.15.0',
            'date' => '2026-08-26',
            'type' => 'minor',
            'title' => 'Incident-Based Alerting and Maintenance Windows',
            'changes' => [
                'ADDED: Alerting is now incident-based. One incident per ongoing problem, opened when a condition becomes true, updated while it persists and resolved when it clears - replacing "send an email whenever the condition is true and a 60-minute timer elapsed"',
                'ADDED: Incident lifecycle OPEN / ACKNOWLEDGED / RESOLVED. Acknowledging stops notification without closing the incident; incidents auto-resolve when the condition actually clears',
                'ADDED: Notification backoff (60m, 120m, 240m) with a repeat limit, so an offline firewall no longer notifies every couple of minutes indefinitely',
                'ADDED: 17 alert types covering offline, gateways (down/degraded/flapping), VPN tunnels, CARP faults, services, certificate expiry, sustained CPU/memory/disk, config drift, backup and update failure, outdated agents and repeated agent authentication failures',
                'ADDED: Maintenance windows scoped to a firewall, a site or a whole customer. During a window monitoring, health collection and incident recording all continue; only outbound notification is withheld, and the suppression is recorded as an incident event',
                'ADDED: cron/evaluate_alerts.php, which asserts current truth for every condition and is idempotent - running it more often produces the same incidents, not more of them',
                'FIXED: The maintenance lookup cache was per-process with no way to invalidate it, so a long-running evaluator or a window created mid-request would read stale state',
            ]
        ],
        [
            'version' => '3.14.0',
            'date' => '2026-08-26',
            'type' => 'minor',
            'title' => 'Configuration Drift and Firewall Health',
            'changes' => [
                'ADDED: Configuration drift detection built on the existing backups. Compares a canonical form of the config, so serialisation noise and the <revision> block OPNsense stamps on every save are not reported as changes',
                'ADDED: Baselines, section-level drift attribution, readable diffs, acknowledgement and promote-to-baseline. Drift is never acted on automatically',
                'ADDED: OPNsense health telemetry - gateways (status, latency, loss, default, transition history), VPN tunnels (WireGuard/OpenVPN/IPsec with handshake and byte counters), CARP/HA state, services and certificate expiry',
                'ADDED: Certificate expiry warnings at configurable 30/14/7 day thresholds, and gateway flapping detection',
                'ADDED: Agent health collector (health_collect.py) in the plugin source, pending an agent release. Certificate metadata only; private key material is never read',
                'FIXED: Gateway latency and loss reported with units ("12.4 ms", "0.0 %") were stored as NULL',
                'FIXED: The drift differ compared a single repeated element against a list of them field by field, so adding one firewall rule looked like every field of the first rule had been edited',
                'FIXED: Drift could not find a current configuration on installations with long runs of backup rows whose upload never arrived',
            ]
        ],
        [
            'version' => '3.13.0',
            'date' => '2026-08-26',
            'type' => 'minor',
            'title' => 'MSP Staff Roles, Customer/Site Model and Fleet Search',
            'changes' => [
                'ADDED: MSP staff roles - Administrator, Technician and Read Only - defined in one capability matrix (inc/permissions.php) rather than role strings scattered through the codebase',
                'ADDED: Customer and site model. Customer -> Site -> Firewall(s), with customer_id/site_id foreign keys replacing two parallel free-text columns',
                'ADDED: Global fleet search with field qualifiers (customer:, site:, tag:, version:, agent:, ip:, interface:, vpn:, status:) and CIDR range matching, plus a header typeahead',
                'ADDED: Searchable audit log UI with filters for action, user, firewall, result and date range',
                'FIXED: The Customers page counted firewalls by matching customer_name, which was empty for firewalls linked via customer_group, so customers with firewalls showed a count of zero',
                'FIXED: customers.php included the page header before checking authorisation, so the shell rendered before the login redirect could be sent',
                'FIXED: The delete guard on customers counted by the same empty column, so a customer with firewalls could be deleted and orphan them',
                'FIXED: users.php wrote $_POST[\'role\'] into the database with no allow-list',
                'FIXED: System Update reported an update whenever the local commit differed from GitHub, including when the checkout was ahead; it now uses git ahead/behind counts',
                'FIXED: Update pulls ran against main regardless of the checked-out branch, ignored git stash failures, and never applied database migrations',
                'SECURITY: The update wrapper made .env world-readable and the backups directory world-writable on every update',
                'SECURITY: The screenshot tool in the document root contained the administrator password in plaintext and was publicly served',
            ]
        ],
        [
            'version' => '3.12.0',
            'date' => '2026-08-26',
            'type' => 'minor',
            'title' => 'Agent Authentication Hardening & Secret Encryption',
            'changes' => [
                'SECURITY: Fixed arbitrary file write in api/upload_backup.php - agent-supplied filenames were written into the web root, where nginx executes .php (remote code execution as www-data)',
                'SECURITY: Fixed IDOR in api/command_result.php - any authenticated agent could finalise and overwrite the result of another firewall\'s command',
                'SECURITY: Agent API keys and signing secrets are now issued per firewall and encrypted at rest with XChaCha20-Poly1305 (OPNMGR_MASTER_KEY in .env)',
                'SECURITY: Optional HMAC-SHA256 request signing for agents with timestamp, nonce replay protection and constant-time comparison',
                'SECURITY: Centralised all agent authentication in inc/agent_auth.php; 22 endpoints refactored off their hand-rolled hardware_id checks',
                'SECURITY: Agent authentication failures now return a generic error and no longer log expected hardware IDs',
                'ADDED: Application audit log (audit_log table) with automatic redaction of credential-bearing metadata',
                'ADDED: Database migration runner (scripts/migrate.php) with idempotent, checksummed migrations',
                'ADDED: Security regression test suite (tests/security_test.php)',
                'FIXED: Configuration backup uploads had been failing since hardware_id authentication was introduced - queued commands sent no credentials, so no backup reached disk after 2026-02-09',
                'FIXED: api/get_commands.php used a bound parameter for LIMIT, which fails under native prepared statements',
                'CHANGED: Backups are stored outside the document root with server-generated names, SHA-256 checksums and XML validation',
                'REMOVED: Hardcoded firewall_id == 21 override that falsified LAN IP, IPv6 and uptime in agent check-ins',
            ]
        ],
        [
            'version' => '3.10.0',
            'date' => '2026-07-25',
            'type' => 'minor',
            'title' => 'Security Hardening & Dependency Updates',
            'changes' => [
                'SECURITY: Enabled GitHub Private Vulnerability Reporting (closes #5)',
                'SECURITY: Added authentication to 25+ unauthenticated API endpoints (critical: download_tunnel_key, generate_enrollment_token, admin_queue, tunnel_close, etc.)',
                'SECURITY: Added CSRF validation to 19+ state-changing endpoints (users, profile, AI settings, license server, fail2ban, tunnel management, etc.)',
                'SECURITY: Fixed command injection in tcpdump filter (run_diagnostic.php) — switched from blocklist to allowlist + escapeshellarg()',
                'SECURITY: Removed hardcoded auth key from emergency_agent_update.php — now uses hardware_id validation',
                'SECURITY: Fixed password hash exposure in get_user.php API response',
                'SECURITY: Fixed path traversal in snyk_scan_progress.php — added scan_id allowlist regex',
                'SECURITY: Added CLI-only guard to snyk_scan_runner.php (blocks web access)',
                'SECURITY: Added auth to monitor.php, tunnel_auto_login.php, tunnel_health_check.php, create_backup_test.php, restore_backup.php',
                'SECURITY: Agent-facing endpoints now validate hardware_id via hash_equals() (check_update_flag, clear_update_flag, update_complete, update_status, etc.)',
                'UPDATED: Bootstrap 5.3.3→5.3.8, Font Awesome 6.4.0→6.7.2, Chart.js 4.4.0→4.5.1 (all pages unified)',
                'UPDATED: Parsedown 1.7.4→1.8.0, Puppeteer 24.37.3→24.43.1',
                'FIXED: npm audit vulnerabilities — basic-ftp (critical), ws (high), js-yaml (high), ip-address (moderate)',
                'FIXED: Pinned Chart.js version in dashboard.php (was loading unversioned from CDN)',
                'IMPROVED: SECURITY.md updated with email contact for vulnerability reports',
            ]
        ],
        [
            'version' => '3.9.2',
            'date' => '2026-03-09',
            'type' => 'patch',
            'title' => 'DST Timezone Fix & Tunnel Improvements',
            'changes' => [
                'FIXED: Checkin times showing "1 hour ago" on DST transition — MySQL timezone changed from hardcoded offset to America/New_York',
                'FIXED: Tunnel health monitor SSH key lookup now checks multiple key locations (/var/www/opnsense/keys/ and /etc/opnmgr/keys/)',
                'FIXED: Tunnel health monitor SSH key permissions auto-corrected if not 0600',
                'FIXED: Tunnel heal exec() replaced redundant shell_exec() call for proper exit code capture',
                'FIXED: Expired tunnel sessions now properly remove nginx proxy config on cleanup',
            ]
        ],
        [
            'version' => '3.9.1',
            'date' => '2026-03-02',
            'type' => 'patch',
            'title' => 'Update Status Fix',
            'changes' => [
                'FIXED: OPNsense update stuck in "Updating..." state - completion detection was gated behind 5-hour timer',
                'FIXED: Check-in no longer overwrites updating/update_pending status with online prematurely',
                'FIXED: Reduced stuck-update timeout from 15 to 5 minutes for faster recovery',
                'NEW: Auto-refresh every 10 seconds on firewalls page when any firewall is updating',
            ]
        ],
        [
            'version' => '3.9.0',
            'date' => '2026-02-24',
            'type' => 'minor',
            'title' => 'Auto-Healing SSH Tunnels',
            'changes' => [
                'NEW: tunnel_health_monitor.php cron — auto-detects and restarts dead SSH tunnels every 2 min',
                'NEW: SSH key auto-repair — re-deploys keys via agent when authentication fails',
                'NEW: Missing/broken nginx proxy configs auto-recreated during heal cycle',
                'NEW: Expired session cleanup integrated into health monitor',
                'NEW: File-lock prevents overlapping monitor runs',
                'FIXED: Nginx proxy protocol mismatch — firewalls with web_port=443 now get proxy_pass https://',
                'FIXED: Removed dead cron entries (cleanup_proxy_sessions.php, cleanup_tunnels.sh)',
            ]
        ],
        [
            'version' => '3.8.6',
            'date' => '2026-02-24',
            'type' => 'minor',
            'title' => 'Update System Overhaul & Chart Timeframes',
            'changes' => [
                'FIXED: Critical bug - reboot_required never clearing (read $_POST instead of JSON $input)',
                'FIXED: updates_available stuck when current and available versions match',
                'FIXED: Update button click handler (event.target hit icon, not button)',
                'FIXED: check_updates.php was demo code with hardcoded versions and rand()',
                'FIXED: Health score penalty for missing API credentials (not used by agent system)',
                'NEW: Animated "Updating..." state with progress bar in Updates column',
                'NEW: Updating/Update Queued status badges in Status column',
                'NEW: Clickable "Reboot Required" badge triggers firewall reboot',
                'NEW: Toast notifications replace alert() dialogs for update/reboot actions',
                'NEW: 1 Hour, 4 Hours, 12 Hours chart timeframes in firewall details',
                'NEW: Auto-recovery for firewalls stuck in updating status (>15 min timeout)',
                'NEW: Force update check when firewall reboots (reboot_required 1→0)',
                'NEW: checkUpdates() JS function (was referenced but never defined)',
                'IMPROVED: All chart APIs now accept hours parameter with smart aggregation',
                'IMPROVED: Reboot API rewritten with JSON support, CSRF, duplicate prevention'
            ]
        ],
        [
            'version' => '3.7.0',
            'date' => '2026-02-12',
            'type' => 'minor',
            'title' => 'Queue Auto-Cleanup & Data Retention',
            'changes' => [
                'NEW: Automatic purge of old command queue records (completed >7d, failed/cancelled >14d)',
                'NEW: System health check on About page (database, queue, agents, disk)',
                'NEW: Global queue summary with all status counts across all firewalls',
                'NEW: Purge Old Records button in Queue Management with purgeable count',
                'NEW: Stuck command indicator badge in Queue Management',
                'FIXED: About page 500 error - missing getSystemHealth() function',
                'IMPROVED: Queue Management summary now shows sent/cancelled counts',
                'IMPROVED: Cron cleanup now runs in two phases: stuck recovery + data purge'
            ]
        ],
        [
            'version' => '3.6.0',
            'date' => '2026-02-11',
            'type' => 'minor',
            'title' => 'Configurable Per-Firewall Speedtest Intervals',
            'changes' => [
                'NEW: Per-firewall speedtest interval setting (2h, 4h, 8h, 12h, 24h, or disabled)',
                'NEW: Speedtest interval dropdown in firewall Configuration section',
                'IMPROVED: Scheduler now uses interval-based logic instead of random daily scheduling',
                'IMPROVED: Deduplication prevents queuing speedtests when one is already pending',
                'Database: Added speedtest_interval_hours column (default: 4 hours)'
            ]
        ],
        [
            'version' => '2.2.3',
            'date' => '2025-12-11',
            'type' => 'patch',
            'title' => 'Tunnel Proxy HTTPS Protocol Fixes',
            'changes' => [
                'FIXED: Tunnel proxy "Empty reply from server" errors after login',
                'FIXED: tunnel_proxy.php now uses HTTPS for port 443 connections',
                'FIXED: Redirect handler (line 414) now uses correct protocol',
                'FIXED: Initial curl_init (line 122) protocol detection',
                'FIXED: Duplicate SSH tunnel process prevention',
                'FIXED: Agent stability on fw-chi-edge02.northwind.example (FW 48)',
                'UPDATED: tunnel_proxy.php to v2.0.2',
                'UPDATED: Version management - APP_VERSION now reads from VERSION file',
                'IMPROVED: All version numbers now centralized and non-hardcoded'
            ]
        ],
        [
            'version' => '2.3.1',
            'date' => '2025-11-01',
            'type' => 'patch',
            'title' => 'Architecture Simplification & 2FA Improvements',
            'changes' => [
                'REMOVED: Separate update agent - simplified to single unified agent architecture',
                'UPDATED: Agent version to 3.7.3 with improved uptime parsing',
                'FIXED: 2FA QR code generation - now uses proper Base32 encoding (TOTP standard)',
                'FIXED: 2FA issuer name changed from "OPNsense" to "OPNmgr"',
                'FIXED: Content Security Policy to allow QR code API (api.qrserver.com)',
                'FIXED: About page contrast issues - changed text-muted to text-secondary',
                'FIXED: Timezone selector session conflicts - removed duplicate session_start()',
                'FIXED: Uptime display for multi-day uptimes (agent regex bug fixed)',
                'IMPROVED: Simplified version management - removed unused update agent constants',
                'IMPROVED: Documentation updated to reflect single agent architecture'
            ]
        ],
        [
            'version' => '2.3.0',
            'date' => '2025-10-30',
            'type' => 'minor',
            'title' => 'Advanced Monitoring & Graph Infrastructure',
            'changes' => [
                'NEW: Latency monitoring system with database storage',
                'NEW: SpeedTest infrastructure with scheduled/on-demand testing',
                'NEW: Real-time graph endpoints for latency and speedtest data',
                'NEW: System statistics graphs (CPU, Memory, Disk usage)',
                'NEW: Traffic statistics with proper rate calculation',
                'FIXED: API authentication - endpoints now return JSON errors instead of redirects',
                'FIXED: JavaScript fetch() calls now include credentials for session cookies',
                'FIXED: Graph data loading - all endpoints properly authenticated',
                'IMPROVED: Consistent API error handling across all endpoints',
                'Database Schema v1.4.0: Added firewall_latency and firewall_speedtest tables'
            ]
        ],
        [
            'version' => '2.1.0',
            'date' => '2025-10-24',
            'type' => 'minor',
            'title' => 'Security Features & Bug Fixes',
            'changes' => [
                'NEW: Secure Outbound Lockdown feature - restrict all outbound to HTTP/HTTPS only',
                'NEW: Forced DNS through Unbound with logging',
                'NEW: Comprehensive secure lockdown documentation with 6+ use cases',
                'FIXED: Critical tunnel URL bug - missing / in path construction',
                'FIXED: Backup upload field name mismatch (backup vs backup_file)',
                'FIXED: Backup command path (opnsense-backup → /conf/config.xml)',
                'FIXED: Cookie aggressive auto-deletion preventing login',
                'FIXED: Tunnel speed - removed 2s blocking wait, now 2-3 seconds',
                'FIXED: Orphaned SSH tunnel cleanup with kill -9',
                'FIXED: Network Tools HTML formatting and card nesting',
                'FIXED: Deployment package delete button functionality',
                'NEW: Settings page for housekeeping/scheduled tasks management',
                'NEW: AI reports grade explanations and full report display',
                'IMPROVED: All cron tasks visible in Administration settings',
                'Database Schema v1.3.0: Added secure_outbound_lockdown column'
            ]
        ],
        [
            'version' => '2.0.0',
            'date' => '2025-10-10',
            'type' => 'major',
            'title' => 'Enhanced Command Execution & Base64 Encoding',
            'changes' => [
                'MILESTONE: v2.0 production release',
                'NOTE: Dual-agent system (v2.0-2.3.0) was deprecated in v2.3.1',
                'NEW: Agent v3.2.0 with base64 command encoding',
                'FIXED: Multi-line command execution (base64 encoding prevents pipe parsing issues)',
                'FIXED: Version display parsing JSON correctly (shows "25.7.4" not raw JSON)',
                'FIXED: UI firewall display improvements',
                'IMPROVED: Agent tracking with firewall_agents table',
                'IMPROVED: Command queue now supports complex multi-line scripts'
            ]
        ],
        [
            'version' => '1.0.0',
            'date' => '2025-10-09',
            'type' => 'major',
            'title' => 'Production Ready - v1.0 Release',
            'changes' => [
                'MILESTONE: OPNManager reaches v1.0 production stability',
                'FIXED: Edit Firewall - tag_names column error (use firewall_tags junction table)',
                'FIXED: Edit Firewall - variable name bugs ($hostname not $name)',
                'FIXED: Add Firewall page - brightness/contrast issues (dark theme)',
                'IMPROVED: Centralized version management system',
                'IMPROVED: Tag management using proper many-to-many relationships'
            ]
        ]
    ];

    $limit = (int) $limit;
    return $limit > 0 ? array_slice($entries, 0, $limit) : $entries;
}

// Get system health status for about page (guarded to avoid conflict with api/health_monitor.php)
if (!function_exists('getSystemHealth')) {
function getSystemHealth() {
    $checks = [];
    $overall = 'healthy';

    // Database connectivity
    try {
        db()->query("SELECT 1");
        $checks['database'] = ['status' => 'ok', 'message' => 'Connected'];
    } catch (Exception $e) {
        $checks['database'] = ['status' => 'error', 'message' => 'Connection failed'];
        $overall = 'unhealthy';
    }

    // Command queue health
    try {
        $stmt = db()->prepare("
            SELECT
                SUM(CASE WHEN status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 1 ELSE 0 END) as stuck,
                SUM(CASE WHEN status = 'failed' AND completed_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) as recent_failures
            FROM firewall_commands
        ");
        $stmt->execute();
        $q = $stmt->fetch(PDO::FETCH_ASSOC);
        $stuck = (int)$q['stuck'];
        $failures = (int)$q['recent_failures'];
        if ($stuck > 5) {
            $checks['command_queue'] = ['status' => 'error', 'message' => "$stuck stuck commands"];
            $overall = 'unhealthy';
        } elseif ($stuck > 0 || $failures > 10) {
            $checks['command_queue'] = ['status' => 'warning', 'message' => "$stuck stuck, $failures recent failures"];
        } else {
            $checks['command_queue'] = ['status' => 'ok', 'message' => 'Healthy'];
        }
    } catch (Exception $e) {
        $checks['command_queue'] = ['status' => 'warning', 'message' => 'Unable to check'];
    }

    // Firewall agents
    try {
        $stmt = db()->query("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'online' AND last_checkin > DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 1 ELSE 0 END) as online
            FROM firewalls
        ");
        $f = $stmt->fetch(PDO::FETCH_ASSOC);
        $total = (int)$f['total'];
        $online = (int)$f['online'];
        if ($total > 0 && $online === 0) {
            $checks['firewall_agents'] = ['status' => 'error', 'message' => "0/$total online"];
            $overall = 'unhealthy';
        } elseif ($online < $total) {
            $checks['firewall_agents'] = ['status' => 'warning', 'message' => "$online/$total online"];
        } else {
            $checks['firewall_agents'] = ['status' => 'ok', 'message' => "$online/$total online"];
        }
    } catch (Exception $e) {
        $checks['firewall_agents'] = ['status' => 'warning', 'message' => 'Unable to check'];
    }

    // Disk space
    $free_pct = disk_free_space('/') / disk_total_space('/') * 100;
    if ($free_pct < 5) {
        $checks['disk_space'] = ['status' => 'error', 'message' => sprintf('%.0f%% free', $free_pct)];
        $overall = 'unhealthy';
    } elseif ($free_pct < 15) {
        $checks['disk_space'] = ['status' => 'warning', 'message' => sprintf('%.0f%% free', $free_pct)];
    } else {
        $checks['disk_space'] = ['status' => 'ok', 'message' => sprintf('%.0f%% free', $free_pct)];
    }

    return ['status' => $overall, 'checks' => $checks];
}
} // end function_exists('getSystemHealth')

// Get version info array
function getVersionInfo() {
    return [
        'app' => [
            'name' => APP_NAME,
            'version' => APP_VERSION,
            'date' => APP_VERSION_DATE,
            'codename' => APP_VERSION_NAME
        ],
        'agent' => [
            'version' => AGENT_VERSION,
            'date' => AGENT_VERSION_DATE,
            'min_supported' => AGENT_MIN_VERSION
        ],
        'tunnel_proxy' => [
            'version' => TUNNEL_PROXY_VERSION
        ],
        'database' => [
            'version' => DATABASE_VERSION
        ],
        'api' => [
            'version' => API_VERSION
        ],
        'dependencies' => [
            'php_min' => PHP_MIN_VERSION,
            'php_current' => PHP_VERSION,
            'bootstrap' => BOOTSTRAP_VERSION,
            'jquery' => JQUERY_VERSION
        ]
    ];
}
?>
