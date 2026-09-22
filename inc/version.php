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
if (!defined('APP_VERSION_DATE')) { define('APP_VERSION_DATE', '2026-09-21'); }
if (!defined('APP_VERSION_NAME')) { define('APP_VERSION_NAME', 'A Quiet Peer Is Not A Down One'); }

// AGENT_VERSION is THE single constant for "newest agent available to install".
// Its value must match the newest released tarball in downloads/plugins/, because
// that is the only version a firewall can actually be upgraded to - an unreleased
// source bump here tells every agent to fetch a package that does not exist.
// inc/agent_version.php aliases LATEST_AGENT_VERSION to it; do not redefine it there.
// scripts/check_versions.php enforces this against the released artifact.
if (!defined('AGENT_VERSION')) { define('AGENT_VERSION', '1.7.0'); }
if (!defined('AGENT_VERSION_DATE')) { define('AGENT_VERSION_DATE', '2026-09-18'); }
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
            'version' => '3.77.2',
            'date' => '2026-09-21',
            'type' => 'patch',
            'title' => 'A Quiet Peer Is Not A Down One',
            'changes' => [
                'FIXED: Every idle WireGuard peer raised a vpn.down incident that resolved minutes later. WireGuard is connectionless - a peer handshakes when it has traffic to send - and the agent called anything past 180 seconds down, so a phone with its screen off looked exactly like a tunnel that had failed',
                'ADDED: A third tunnel state, idle: quiet, but within the window a working peer can be quiet for (health_wireguard_idle_minutes, default 15). Only down is a fault. The verdict is reached on ingest, so every agent already in the field gets it without an upgrade',
                'CHANGED: The health page shows an idle peer in amber rather than as a failure, and the VPN Down counts on the dashboard and the fleet health page no longer include it',
                'CHANGED: A vpn.down incident records when the tunnel last handshook, or says that it never has',
            ],
        ],
        [
            'version' => '3.77.1',
            'date' => '2026-09-21',
            'type' => 'patch',
            'title' => 'The Grade Pays Its Own Width',
            'changes' => [
                'FIXED: The health grade added in 3.76.1 widened the dashboard Health column and squeezed every column beside it. The badge was placed next to the bar and the percentage without taking any width back, and the bar\'s right margin and the badge\'s left margin were both still there on top of the flex gap. The bar gives up the width the badge needs and the spacing is the gap alone, so the cell fits the 120px it always had',
            ],
        ],
        [
            'version' => '3.77.0',
            'date' => '2026-09-20',
            'type' => 'minor',
            'title' => 'Counted Once Per Scan',
            'changes' => [
                'FIXED: Log Analysis Statistics multiplied every total by the number of log files a scan read. api/ai_scan.php writes one row per log and fills each with the same report-level figures, so a scan reporting 5 blocked attempts stored 5 three times; two such scans showed 30 against a true 10. The totals collapse each report to one row before adding, and the panels group by scan',
                'CHANGED: The tiles open the detail of each scan rather than a pointer to its report - which logs were read, how many lines, the threat level and anomaly score beside the figure itself',
                'ADDED: Sample AI reports in the demo fixture, and four new documentation captures: a firewall that scans badly, one that scans well, the log analysis panel and the provider settings. None of these could be photographed from a real installation, since a report is a list of a firewall\'s actual weaknesses beside its actual addresses',
                'CHANGED: All 32 documentation screenshots regenerated from the demo fixture, so they show the scan grade and health grade columns, the pending agent version, the alert policy tab and the incident detail view',
            ],
        ],
        [
            'version' => '3.76.1',
            'date' => '2026-09-20',
            'type' => 'patch',
            'title' => 'Health Has A Grade Too',
            'changes' => [
                'FIXED: The fleet table showed a letter grade for the AI scan and a bare percentage for health, so two columns of the same kind read as different sorts of thing. calculateHealthReport() has always computed a grade and a per-component breakdown; the dashboard called calculateHealthScore(), which returns the number and throws the rest away',
                'ADDED: The health grade sits beside its percentage, coloured by the same thresholds as the bar so one number never carries two verdicts, and hovering gives the breakdown the health report already builds - which component lost the points - rather than a label repeating the column heading',
            ],
        ],
        [
            'version' => '3.76.0',
            'date' => '2026-09-20',
            'type' => 'minor',
            'title' => 'The Incident, Not Just Its History',
            'changes' => [
                'ADDED: An incident opens to the incident itself - exact first seen, last seen and resolved times with how long it lasted, the object involved, the firewall with its WAN and LAN addresses, customer, occurrence and notification counts, acknowledgement, and the full detail text rather than 120 characters of it. The incident title is the link; "History" was a small button at the far right of a nine-column row',
                'ADDED: Conditions now record what they saw. Only firewall.offline stored any metadata, so every other incident arrived with nothing to inspect: gateway incidents carry the gateway address, monitor IP and interface; VPN incidents the peer, endpoint and last handshake; service incidents the service and its status. Addresses sort to the top of the recorded detail, because that is what you reach for first',
                'FIXED: CI was red on two jobs. Migration 0022 was written as a plain ALTER TABLE ADD COLUMN, and since database/schema.sql already carries those columns and ships with an empty schema_migrations table, a fresh install ran the migration against a schema that already had the change and failed with "Duplicate column name". Every other ALTER in the directory already used IF NOT EXISTS',
                'ADDED: A test that fails on any unguarded ADD COLUMN, ADD INDEX, CREATE TABLE or DROP TABLE in a migration, so the next one cannot break an install the same way',
                'FIXED: The new incident view built its address cells by concatenating escaped strings inside a ternary. tests/injection_guard_test.php rejects that shape - an escaped concatenation is one edit away from an unescaped one - and it was right to',
            ],
        ],
        [
            'version' => '3.75.0',
            'date' => '2026-09-20',
            'type' => 'minor',
            'title' => 'Ask The Tile What It Means',
            'changes' => [
                'ADDED: The Log Analysis Statistics tiles open the records their figures are made of. "30 blocked attempts" over thirty days had no route to which scan, which log, or when; each tile now lists its contributing rows, linked to the report each came from',
                'ADDED: A tile reading zero explains what was examined rather than opening an empty table. No threats found says how many lines across how many files were read and that nothing was flagged - an empty table reads as "not checked"',
                'FIXED: The Total Analyses panel initially listed one row per log file beneath a tile counting scans, showing six under a two. It groups by report now, so the figure and its detail agree. A panel that disagrees with its own headline is worse than none: it invites doubt about whichever number was checked second',
                'CHANGED: The blocked and failed-authentication figures state that they come from the log excerpt each scan read, not from the firewall\'s own counters',
                'CHANGED: The tiles are controls - keyboard reachable, focus-visible, with their open state exposed - and only one panel opens at a time, since four stacked tables would be the same wall of numbers further down the page',
            ],
        ],
        [
            'version' => '3.74.2',
            'date' => '2026-09-20',
            'type' => 'patch',
            'title' => 'Both Columns Click',
            'changes' => [
                'CHANGED: The health figure in the fleet table links to that firewall\'s health detail, as the scan grade beside it already did. Two columns of the same kind, one inviting a click and the other quietly not, is the inconsistency',
                'FIXED: The automatic scan scheduler re-reads auto_scan_enabled immediately before each scan rather than only when the run starts. A scan spends money and sends a configuration to a third party, and with a sixty second pause between firewalls a fleet run lasts long enough for the operator to switch scanning off while it is still going',
                'VERIFIED: the 02:00 cron is the only automatic path to a scan. The widget and the firewall page both start one from a button, and a manual scan deliberately ignores the automatic toggle - switching scheduled scanning off must not take the scan button away',
            ],
        ],
        [
            'version' => '3.74.1',
            'date' => '2026-09-20',
            'type' => 'patch',
            'title' => 'Severity By What Is Reachable',
            'changes' => [
                'FIXED: Grading was overly aggressive because severity had no rubric. The prompt described the grade bands and left the severity of each finding to the model, which rated hardening gaps as medium and high and then graded down against its own inflation. Severity is now anchored to what an attacker can reach: critical means internet-reachable and administrative, low means a hardening gap with no path in from the internet',
                'FIXED: DNSSEC disabled, a permissive any-to-any policy on a VPN interface, a plain-HTTP web GUI with no internet-facing rule permitting it, WAN ICMP, and unreferenced expiring certificates are all calibrated as low, each with the reason stated - a rule with no reason is one the next model ignores. A VPN peer is admitted by cryptographic key, so a permissive VPN interface is open to people who already hold a key, not to the internet',
                'CHANGED: The grade now follows from the findings actually raised rather than being a separate judgement: any critical grades D or F, only low and info grades A. A firewall with nothing outstanding but hardening preferences is an A',
                'CHANGED: Intentional publishing of non-administrative services - web, mail, DNS, media - no longer reduces the grade. That is a decision, not a defect',
                'MEASURED: one firewall moved from C/75 to A/91 on an unchanged configuration; the other stayed at F/38, its four genuinely internet-reachable administrative interfaces still critical while its VPN and interface-policy findings dropped to low. The aim was calibration, not leniency',
            ],
        ],
        [
            'version' => '3.74.0',
            'date' => '2026-09-20',
            'type' => 'minor',
            'title' => 'A New Agent, Said Out Loud',
            'changes' => [
                'FIXED: The manager knew a newer agent existed and showed it nowhere. agent_rollout_state() and LATEST_AGENT_VERSION appeared only in agent_checkin.php, scripts/ and tests, so with 1.7.0 published and held the only way to find out was to run a CLI script - while the rollout header claimed "the manager still knows an update exists and still shows it". The fleet table now shows the pending version beside the running one, marked held when it is not being offered',
                'ADDED: An Agent Updates card in Settings: published version, rollout stage, how many firewalls are behind it, and a switch for automatic updates. With it on, a newly published version goes to the whole fleet and each firewall installs it on its next check-in without being asked; off - the default - a new version is held until promoted by name',
                'FIXED: inc/agent_rollout.php used db() without requiring bootstrap. Every settings read fell back to its default and every write failed silently into its own catch, which looks exactly like a gate that is working',
                'CHANGED: The rollout decision is a pure function, agent_rollout_decide(), so it can be exercised without writing to live settings. The test that first covered auto-promotion switched the real setting on; agents check in every two minutes, one did so inside that window, and 1.7.0 was promoted to the fleet for real. A test must not be able to deploy software',
                'DEPLOYED: agent 1.7.0 to both firewalls, deliberately and one at a time',
            ],
        ],
        [
            'version' => '3.73.0',
            'date' => '2026-09-20',
            'type' => 'minor',
            'title' => 'Alerts You Can Choose',
            'changes' => [
                'ADDED: Alert policy, configurable per firewall and per object. Below firewalls.alerts_enabled there was nothing: either every condition for every object, or silence. A tunnel that is down by design could only be quietened by muting the whole firewall, which hid everything that mattered along with it',
                'ADDED: Each of the nineteen conditions can be set to Alert, Muted or Inherit on a firewall, and the object-scoped ones - VPN tunnels, services, gateways, certificates - can be muted individually. A tunnel is listed under the exact key the evaluator raises it by, so a policy cannot be written against a name that never matches',
                'ADDED: Two conditions that did not exist. speedtest.slow alerts when the most recent speed test is below a threshold, and latency.high when latency averaged over the sustained window is above one. Both are opt-in and silent until a threshold is set for that firewall: "slow" and "high" are site-specific, and a global default would either never fire or fire constantly',
                'ADDED: Thresholds are per scope as well. A branch circuit and a datacentre circuit are not slow at the same number',
                'FIXED: Muting a condition now closes the incidents it has already opened. Left alone, a muted condition would keep its incident open forever - nothing re-raises it, so nothing resolves it, and it counts against the notification repeat limit indefinitely',
                'CHANGED: Policy is enforced inside raise(), the one point every condition passes through, so a condition added later cannot forget to honour it. A test asserts every raisable condition appears in the catalogue the UI is built from',
                'NOTE: Absence of a policy row means alert, exactly as before, so this release changes no alerting behaviour until someone uses it',
            ],
        ],
        [
            'version' => '3.72.1',
            'date' => '2026-09-18',
            'type' => 'patch',
            'title' => 'Agent 1.7.0, Published And Held',
            'changes' => [
                'ADDED: Agent 1.7.0. It reads the WAN address from the interface holding the default route rather than taking whichever address ifconfig prints first, which on a firewall whose LAN interface is named ahead of its WAN one was the LAN address. It falls back to the old behaviour when there is no default route, so a box mid-reconfiguration still reports something',
                'PUBLISHED, NOT DEPLOYED: the rollout stage was promoted for 1.6.9, so 1.7.0 is held and nothing is being offered to any firewall. Releasing it is a separate, explicit act that names the version',
                'FIXED: README.md and CHANGELOG.md had drifted to 3.69.2 across nine releases while VERSION and the in-app changelog moved on. scripts/check_versions.php had been reporting it the whole time',
            ],
        ],
        [
            'version' => '3.72.0',
            'date' => '2026-09-18',
            'type' => 'minor',
            'title' => 'Health Is Not The Same As Safe',
            'changes' => [
                'ADDED: The fleet table shows the grade from each firewall\'s most recent AI configuration scan, beside its health. The two measure different things - health is reachability and service state, the grade is what the configuration review concluded - and showing only the first meant a firewall could sit at 100% health on the page used to judge the fleet at a glance while its management interface was reachable from any source on the internet',
                'ADDED: The grade links to the report it came from, so it is a number with its findings attached rather than one to argue with. Clicking it does not trigger the row navigation to firewall details',
                'ADDED: A firewall that has never been scanned shows a dash and says so, because a blank cell reads as "fine". A grade older than thirty days is dimmed and outlined, since it describes a configuration that may no longer exist; hovering any grade gives the score, risk level and scan date',
                'FIXED: The join takes the newest report per firewall rather than joining ai_scan_reports directly, which would have listed a firewall once per scan it has ever had',
            ],
        ],
        [
            'version' => '3.71.1',
            'date' => '2026-09-18',
            'type' => 'patch',
            'title' => 'The First Line Of ifconfig Is Not The WAN',
            'changes' => [
                'FIXED: The fleet view showed an RFC1918 address under WAN IP. The agent reported wan_ip as the first IPv4 address ifconfig prints, in kernel interface order, which is the WAN address only when the WAN interface happens to come first. On a firewall whose LAN interface is named ahead of its WAN interface it reported the LAN address',
                'ADDED: firewall_wan_address() derives the address from wan_interface_stats, which the agent has been sending all along and which carries each interface with its address and gateway. The interface holding a default gateway is the one facing the internet; failing that a routable address is preferred over a private one, and the reported value remains the fallback for agents predating the field',
                'FIXED: The map geolocated the reported address, so a firewall reporting a private one was looked up, found nowhere, and silently given no marker. It now geolocates the derived address',
                'CHANGED: The dashboard, fleet list, network tools, search and map all derive the address the same way, and each query fetches the column it derives from - deriving from a column that was never selected would silently return the old wrong value',
                'FIXED: The agent now reads the address from the interface holding the default route, falling back to the old behaviour when there is no default route. AGENT_VERSION is deliberately unchanged, so nothing is deployed to any firewall by this release',
            ],
        ],
        [
            'version' => '3.71.0',
            'date' => '2026-09-18',
            'type' => 'minor',
            'title' => 'Scheduled Scans Actually Scan',
            'changes' => [
                'FIXED: Scheduled AI scanning had never run once. The toggle saved, the schedule was stored, and four separate faults each independently prevented a scan: the scheduler called performAIScan(), a function that has never existed; api/ai_scan.php ran its entire body on include, so reaching its functions meant running a scan with no firewall id and exiting; that file resolved one include against the working directory and so died immediately under the CLI; and the cron entry ran as an account that cannot read /etc/opnmgr/keys/*, which are www-data 0600',
                'CHANGED: A scan is now opnmgr_run_ai_scan(), called by both the web endpoint and the scheduler, so a scheduled scan and a manual one cannot drift apart. The web entry point is guarded by PHP_SAPI so including the file never runs a scan or sends headers',
                'FIXED: The cron entry moved from the administrator account to www-data, the account that owns the SSH keys. It failed on "Load key: Permission denied" before the scan could start',
                'FIXED: The scheduler logged to /var/log/opnsense_auto_scans.log, which the service account cannot write, so every file write failed silently while the echo went to the cron redirect. It logs under /var/log/opnmgr with the rest of the jobs',
                'FIXED: The scan type was read from the firewalls row rather than the firewall_ai_settings row that holds the schedule',
                'ADDED: scripts/deploy.sh, carrying the exclusions that were previously retyped by hand. logs/ is among them - without it each deploy overwrote production log files with stale copies from the working tree',
                'VERIFIED: A scheduled run completed against a firewall configured for config_with_logs - report 45, 24,117 tokens, next scan advanced to the following week',
            ],
        ],
        [
            'version' => '3.70.2',
            'date' => '2026-09-18',
            'type' => 'patch',
            'title' => 'An Include That Only Worked From One Directory',
            'changes' => [
                'FIXED: api/ai_scan.php resolved one include relative to the working directory rather than to its own location. Under the web server the working directory happens to be api/, so it worked there and nowhere else - scripts/run_auto_scans.php died on "require_once(../inc/agent_version.php): Failed to open stream" every time it ran. Its eleven neighbours in the same file all use __DIR__',
                'ADDED: A test that fails on any include in api/ resolved against the working directory',
            ],
        ],
        [
            'version' => '3.70.1',
            'date' => '2026-09-17',
            'type' => 'patch',
            'title' => 'Conditional Exemptions, Readable Citations',
            'changes' => [
                'FIXED: "ABSOLUTE PROHIBITION - DO NOT CREATE FINDINGS FOR" headed a list whose entries carried conditions - "SSH root login ... THIS IS SECURE when SSH rules restrict source IPs". The heading was unconditional and the entries were not, and the heading won: root login was suppressed whether or not the condition held. Until the rule digest existed the condition could not even be checked, so it was simply assumed. A firewall with permitrootlogin=1 and port 22 open to any source scanned clean on the most dangerous combination there is',
                'CHANGED: The list is now headed CONDITIONAL EXEMPTIONS, each condition must be verified against the rule table, an unsettled condition is stated in the finding rather than resolved in favour of the exemption, and root login with SSH open to any source is explicitly a CRITICAL finding. Item 3 was item 1 restated and has been replaced',
                'FIXED: SSH password authentication was drifting in and out of reports because it sat adjacent to the root-login exemptions without being covered by them. What is NOT exempt is now stated outside the exemption list',
                'FIXED: affected_rules came back accurate and unreadable - pasted XML fragments and lines echoing back the column headings of the rule table. The prompt now specifies a one-line citation format, refuses XML, and caps citations at four per finding',
                'ADDED: ai_format_affected_rules() normalises citations for display, including those already stored in the old shape. An XML fragment is reduced to its identifying fields rather than hidden, so an existing report does not come to look unsupported',
                'FIXED: Viewing an AI report no longer opens a new tab, from either the View button or the redirect after a scan completes. A popup blocker swallowing window.open made a finished scan look like it had done nothing',
            ],
        ],
        [
            'version' => '3.70.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'The Rules Are Not Where The Name Says',
            'changes' => [
                'FIXED: Scans analysed a rule set they had never been given. OPNsense keeps firewall rules in two places: the legacy <filter> section, which on a current installation is usually <filter/> - self-closing and empty - and <OPNsense><Firewall><Filter><rules>, further down under a heading that does not announce itself. A reader that stops at the section named "filter" concludes the firewall has no rules at all, so every statement the scan made about "the WAN policy" was inference from NAT entries alone',
                'ADDED: ai_rule_digest() normalises both sections into one table placed ahead of the XML, marked as authoritative, noting explicitly that an empty <filter/> does not mean there are no rules, and counting how many enabled pass rules target the firewall itself. It is built from the redacted document, never the raw one, so it cannot become a route around ai_redact_config()',
                'CHANGED: The prompt said "NEVER FLAG NAT RULES", without qualification. A port forward is how an administrative interface usually reaches the internet, so an instruction meant to stop noise about published services also suppressed the finding most worth having. Ordinary service publishing is still not flagged; a forward exposing a management interface now is',
                'VERIFIED: On one installation the fix moved a confirmed WAN-facing web GUI from an inferred finding to a cited one, correctly separated an exposed HTTPS listener from a permitted-but-closed HTTP port, dropped a false "SSH exposed to the internet", and surfaced two findings it could not previously see - an any-to-any rule on OPT1 and IDS running with its signature-update cron disabled. Prompt cost rose about 2,000 tokens',
            ],
        ],
        [
            'version' => '3.69.9',
            'date' => '2026-09-17',
            'type' => 'patch',
            'title' => 'The Request Adapts To The Model',
            'changes' => [
                'FIXED: Scans failed outright on any GPT-5 generation model with HTTP 400 - "Unsupported parameter: max_tokens is not supported with this model. Use max_completion_tokens instead." The newer models renamed the field and the request had the old name compiled in, so moving to a current model broke the feature that recommended moving to one',
                'CHANGED: The retry takes the parameter name from the error message rather than from a table of model names mapped to parameter names, which is the stale-catalogue bug in another costume. It renames only the parameter it actually sent and cannot loop on an unchanged name',
                'ADDED: A model that refuses a temperature has the parameter dropped and the request retried, rather than losing the analysis over an optional nicety',
                'VERIFIED: First measured scan on this installation - 104,054 tokens, 26 seconds, against 85 seconds on the previous model',
            ],
        ],
        [
            'version' => '3.69.8',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'One List, Every Provider',
            'changes' => [
                'CHANGED: The LLM control is one list spanning every configured provider, grouped by provider. With an OpenAI key and a Claude key configured it shows both providers\' models together; with one provider configured it shows that provider\'s. The thing being chosen is a model - the provider follows from it - so choosing a provider first, before you could see what it offered, was a step that existed only because the form was built that way',
                'CHANGED: The ratings table sits with the control it informs and is itself a selector: each row has a radio that mirrors into the dropdown, so the advice and the act of choosing are in the same place',
                'CHANGED: Editing a provider edits its API key and nothing else. The model field in that modal was a second control for a value now chosen in the list above, and when the two disagreed the modal won silently. An empty key closes without changing anything rather than storing nothing as the key',
                'CHANGED: "Fetch from provider" asks every configured provider rather than only the active one, and each provider\'s results land in its own group. One provider failing no longer loses the others\' results',
                'FIXED: The provider row id travels with the model id in one field, so a saved choice can never pair a model with the wrong key. The split takes the first separator only, so a model id containing one is not truncated',
            ],
        ],
        [
            'version' => '3.69.7',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'Rated, Banded, And Measured',
            'changes' => [
                'ADDED: Suggested models carry a rating out of five for firewall config review and a relative cost band, shown in the dropdown and in a comparison table under the selector. The model in use and the recommended one are marked, and the rating is explicitly for reasoning over a rule set rather than for quality in general - a fast model can be excellent elsewhere and still miss the interaction between two rules',
                'ADDED: Every scan now records the token usage the provider reports. It was being decoded and thrown away on every response, so the installation had no idea what a scan cost. The table shows average tokens and duration per model from this installation\'s own scans, which is the only figure on the row that is not an opinion',
                'CHANGED: Cost is a band, never a price. Per-token pricing differs by account and changes without this page changing, so quoting a figure here would be wrong for somebody and stale for everybody - the trap the model list fell into twice already. A test fails if a dollar figure is ever written into the catalogue',
                'ADDED: A model nobody has rated is shown as "unrated" rather than as zero stars or a middling guess. gpt-6-astra is listed unrated: an opinion rendered as stars is indistinguishable from a measurement',
                'ADDED: Migration 0022 adds the token columns. Scans recorded before it are NULL rather than 0, and the page filters them out, so an unmeasured scan is never reported as a free one',
            ],
        ],
        [
            'version' => '3.69.6',
            'date' => '2026-09-17',
            'type' => 'patch',
            'title' => 'Written From Memory, Not From The Provider',
            'changes' => [
                'FIXED: The OpenAI catalogue was refreshed one release earlier from memory rather than from the provider. It named gpt-4o "current generation" while the account was serving 41 GPT-5 variants, gpt-5.6 and gpt-6-astra. It now leads with gpt-5.5 as the recommendation for rule-set review, and lists gpt-6-astra, gpt-5.5-pro, gpt-5.4 and gpt-5.4-mini beside it',
                'CHANGED: The OpenAI hint no longer implies the list is current. It says the list is a snapshot, that an account may serve newer or fewer models than it names, and that "Fetch from provider" is the authority',
                'ADDED: A test that fails when a provider recommends a model the same catalogue elsewhere calls older, previous generation or deprecated, or whose id is from a family known to be superseded. The stale-catalogue bug has now recurred twice; it should not need a person to notice it a third time',
            ],
        ],
        [
            'version' => '3.69.5',
            'date' => '2026-09-17',
            'type' => 'patch',
            'title' => 'Ask The Provider, Keep The Key',
            'changes' => [
                'FIXED: The model dropdown offered only the five ids hardcoded in this file, all of them GPT-4 era. Live discovery already existed but sat inside the Add and Edit modals - the one place you are not looking when changing the model of a provider that is already configured. "Fetch from provider" is now beside the selector: OpenAI returns 130 models on this installation against the 5 the catalogue knew',
                'FIXED: The stored API key ciphertext was written into the page source of every render. json_encode($provider) passed the whole row, api_key column included, to showEditModal(), which loaded it into the key field. Changing only the model then re-encrypted the ciphertext, and the double-wrapped result decrypts to an enc:v1: blob that authenticates as nothing - the same failure that produced 8,497 rejected SMTP logins. The row now has its secret removed before it reaches the browser',
                'FIXED: The edit form marked the key field required, so the handler\'s documented "leave blank to keep the stored key" branch could never be reached through the UI. The field is optional now and says so',
                'CHANGED: Both API key fields are password inputs with autofill off. A key in a text input is readable over a shoulder and is offered back by the browser afterwards',
                'FIXED: A key of nothing but whitespace was encrypted and stored as the key. Both handlers trim before deciding whether anything was entered',
            ],
        ],
        [
            'version' => '3.69.4',
            'date' => '2026-09-17',
            'type' => 'patch',
            'title' => 'Two Choices, Two Dropdowns',
            'changes' => [
                'FIXED: The AI page offered one dropdown where it holds two decisions. It listed configured rows as "OpenAI - gpt-4-turbo", so with a single provider configured it had a single option and the model was baked into that option\'s label - changing which model ran meant opening the Edit modal. Provider and model are two selects side by side now, and the model is saved by the same button',
                'FIXED: The model list is filled on page load rather than only when the provider is touched, so it is never an empty dropdown',
                'ADDED: The model currently in use is always the first entry, marked "in use", even when it is not in the curated catalogue. A list claiming to show what is running has to contain what is running',
                'FIXED: The OpenAI and Google catalogues were the stale lists the comment directly above them warns about - gpt-4-turbo/gpt-4/gpt-3.5-turbo and a lone gemini-pro, with no entry marked recommended. Anthropic\'s had been refreshed and theirs had not. Both now list current models with a recommended default, and a test fails if any provider offers suggestions without marking one',
            ],
        ],
        [
            'version' => '3.69.3',
            'date' => '2026-09-17',
            'type' => 'patch',
            'title' => 'A Page With Sections, Not A Menu',
            'changes' => [
                'FIXED: Settings is a link again. 3.69.2 turned it into a collapsing sidebar group with AI Analysis, Health, Update and Backup nested inside it, which was the wrong shape: Settings is a page made of sections, and its sections belong on the page. The four destinations are cards there now, beside the fourteen already present, and the sidebar holds one plain link',
                'FIXED: tests/ai_redaction_test.php set ai_enabled back to "0" when it finished, commented as restoring the installation default. The default is whatever the operator chose, and on an installation with AI deliberately enabled every full suite run switched it off - silently, days after the fact, attributable to nobody. It now reads the value before the test and puts that value back, including restoring the absence of the row when there was none',
                'ADDED: A test asserting that restore, because the suite writes to the live database and a test that does not give the installation back as it found it is a defect in the installation, not in the test',
            ],
        ],
        [
            'version' => '3.69.2',
            'date' => '2026-09-17',
            'type' => 'patch',
            'title' => 'Configure The Manager, In One Place',
            'changes' => [
                'CHANGED: AI Analysis, Health, Update and Backup are nested under Settings. All five are "configure the manager itself" and sat as separate peers among ten admin entries; the admin section is four rows shorter closed, with the same destinations open',
                'ADDED: The group opens by itself on any of its own pages, decided in PHP rather than in script, so it is correct before anything runs and the group holding the page you are looking at is never shut',
                'CHANGED: One collapse implementation now drives both the admin and settings groups. Two copies would have drifted',
                'ADDED: scripts/add_ai_provider.php configures a provider with the key read from stdin - never echoed, never in shell history, never in a transcript. It reports only the key\'s length and last four characters, which is enough to confirm the right thing was pasted and not enough to reconstruct it',
            ],
        ],
        [
            'version' => '3.69.1',
            'date' => '2026-09-17',
            'type' => 'patch',
            'title' => 'Whichever Row Came First',
            'changes' => [
                'FIXED: Adding a second AI provider quietly made it active alongside the first. is_active defaults to 1 in the schema and the insert never overrode it, and the scan then took WHERE is_active = TRUE LIMIT 1 with no ordering - so which LLM actually ran was whatever the database returned first. A later provider is now added inactive, and the first one configured becomes active because otherwise nothing would be',
                'ADDED: One explicit selector at the top of the page - "LLM used for analysis" - listing each configured provider with its model, since two providers can differ only by model. Choosing which LLM runs was previously a "Set as Default" button on whichever provider card you happened to scroll to',
                'FIXED: The scan orders by id before taking one row. If two are somehow active, the same provider runs every time: silently alternating between two LLMs is worse than picking the wrong one',
                'ADDED: Seven assertions covering the selector and the insert',
            ],
        ],
        [
            'version' => '3.69.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'Ask The Provider',
            'changes' => [
                'FIXED: The AI settings page offered a hardcoded list of model ids - gpt-4, claude-3-opus-20240229, gemini-pro - which were the right answer when they were written and quietly stopped being it, with no way to choose anything else. A self-hosted product cannot ship a catalogue that stays current',
                'ADDED: Three routes to a model now. Curated suggestions, each with a note on why you would pick it and one marked recommended; live discovery that asks the provider\'s own API what it currently serves; and free text, so a model released tomorrow needs no release here. Only the first can go stale, and it is no longer the only option',
                'ADDED: api/ai_models.php lists models from OpenAI, Anthropic and a local Ollama. It uses the stored key server-side and returns only model names - the browser never needs to hold the key to list models. Providers with no listing endpoint say so rather than returning an empty list',
                'CHANGED: The Claude suggestions are current, and carry context windows so the choice is informed. Azure is labelled as wanting a deployment name, which is whatever the operator called it in the portal and cannot be guessed',
                'FIXED: The edit dialog had the same fixed dropdown, which would have silently offered a configured model no longer in the list. It uses the same field, prefilled with whatever is actually configured',
                'ADDED: tests/ai_model_selection_test.php, including that the discovery endpoint requires settings permission and never returns the key it used',
            ],
        ],
        [
            'version' => '3.68.1',
            'date' => '2026-09-17',
            'type' => 'patch',
            'title' => 'The Column Contained The Word Array',
            'changes' => [
                'FIXED: Scan concerns and recommendations were stored as the five characters "Array". They were bound to the INSERT exactly as parsed, and when the model returned a list of objects - which is what asking for severity, evidence, impact and remediation per finding encourages - the converter recursed, handed back an array, and PDO stringified it. The analysis was being produced and then discarded at the last step. Same firewall, same model, before and after: concerns 5 characters to 576, recommendations 5 to 613, improvements 5 to 202',
                'ADDED: report_section_text() renders a section whatever shape it arrives in - a string, a list of strings, or a list of objects whose labels are kept, because severity and remediation are the useful part of a structured finding',
                'FIXED: max_tokens 8000 exceeded what the configured model accepts. gpt-4-turbo allows 4096 and refused the request outright, so raising the ceiling had made scans fail rather than improve. The OpenAI call takes the limit as a parameter and retries once at whatever maximum the API reports, downward only',
                'CONTEXT: The first scan after these fixes returned a real finding with evidence - repeated probes from a single address against port 2375, the unauthenticated Docker API - quoting the firewall log line it rested on',
            ],
        ],
        [
            'version' => '3.68.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'A Firewall With SSH Open Scanned Clean',
            'changes' => [
                'FOUND: The AI security scan deleted findings after the model produced them, through a keyword filter whose SSH and web-GUI patterns matched the dangerous case rather than the safe one. "ssh.*0\\.0\\.0\\.0" removed "SSH is exposed to 0.0.0.0/0 on the WAN", "ssh.*unrestricted" removed "SSH is unrestricted and reachable from the internet", "web interface.*http" removed "Web interface exposed over plain HTTP to the internet". Each is a finding the prompt explicitly instructs the model to raise as CRITICAL. A firewall with SSH open to the world scanned clean',
                'FIXED: Those pattern lists are empty. The intended suppression - SSH restricted to specific source IPs is fine - is a judgement about the rule set that the model makes from the configuration, and the prompt explains it at length. A regex over finding text cannot tell restricted from unrestricted, and it got it backwards',
                'FIXED: The keyword filter matched substrings against the JSON of each finding, and the list contained "log" and "nat". "Alternate gateway lacks failover monitoring" and "Designated management VLAN is not isolated" were both deleted for containing "nat"; anything mentioning "login" or "technology" went the same way. Matching is on word boundaries now, and the list is down to log retention and log availability, which a thirty-line sample genuinely cannot support',
                'FIXED: The prompt said "DO NOT MENTION LOGS AT ALL" directly above the section that sends log excerpts and asks which threats appear in them',
                'CHANGED: max_tokens raised from 2000 to 8000. Two thousand tokens covered the grade, score, risk level, summary, concerns, recommendations, improvements and log analysis together, which is most of why the output read as thin',
                'ADDED: Findings must carry severity, the configuration element they are based on, concrete impact and a specific remediation - and must not be raised at all if nothing in the configuration can be pointed at. Generic hardening advice is explicitly discouraged: the reader has this firewall in front of them',
                'ADDED: tests/ai_scan_findings_test.php, including that configuration redaction still holds and still aborts rather than falling back, which must not regress while filters are being loosened',
            ],
        ],
        [
            'version' => '3.67.2',
            'date' => '2026-09-17',
            'type' => 'patch',
            'title' => 'A Constant The Web Server Does Not Have',
            'changes' => [
                'FIXED: Starting a tunnel from the interface returned a 500. The tunnel session reconciler added in 3.59.0 kills processes with posix_kill($pid, SIGTERM), and SIGTERM is a pcntl constant that PHP-FPM does not load - so every tunnel started from the UI hit "Uncaught Error: Undefined constant SIGTERM". It had only ever been exercised from the CLI, where pcntl is present and the constant resolves, which is exactly why it looked fine. scripts/manage_ssh_tunnel.php already used the numeric signal for this reason and the convention was there to follow',
                'FIXED: The failure reported itself as "Network error - please try again", which was wrong twice over. start_tunnel_async.php is consumed as JSON but sent no Accept header, so requireLogin() answered an expired session with a 302 to the login page; res.json() threw on the HTML and the catch printed the same message it printed for a server fatal. The request declares Accept: application/json now, a 401 is reported as an expired session, and any other error shows what actually happened',
                'ADDED: Five assertions covering both, including that no web-reachable path references a pcntl constant',
            ],
        ],
        [
            'version' => '3.67.1',
            'date' => '2026-09-17',
            'type' => 'patch',
            'title' => 'A Column Reserved For Nothing',
            'changes' => [
                'FIXED: Documentation pages rendered in the right three-quarters of the window. The layout reserved a col-md-3 for inc/sidebar.php, which has been disabled for some time - its entire body is a display:none div - and an empty quarter-width column still occupies its quarter. Centred now, with a 1140px measure, because documentation set to the full width of a wide monitor is its own kind of unreadable',
                'FIXED: The documentation card carried a fixed slate palette (#2c3e50, #34495e, #ecf0f1) rather than theme variables, so on the light theme it rendered a dark card on a pale page with the muted intro line near-black on near-black. That is the second page today whose hardcoded dark-theme colours only showed once there was enough text to read',
            ],
        ],
        [
            'version' => '3.67.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'No TLS To Renew For',
            'changes' => [
                'FIXED: A firewall serving its web interface over plain HTTP still raised a certificate expiry warning. OPNsense keeps <ssl-certref> populated whether or not the GUI serves TLS, so the certificate looked in use - and the warning had nothing to act on, because there is no TLS to renew a certificate for',
                'ADDED: Agent v1.6.9 discounts a web GUI certificate reference when system/webgui/protocol is not https, and only when the GUI is the certificate\'s sole reference. The same certificate bound to an OpenVPN server stays monitored: suppressing that would trade a harmless alert for a blind spot',
                'CONTEXT: This emptied the incident list. Of the twelve open incidents at the start of the day, ten were stale, one was a critical about a certificate nothing served, and the last was a warning about a certificate on a firewall with no TLS',
                'ADDED: Four assertions covering the three cases that matter - GUI on http with the certificate used only there, GUI on https, and GUI on http with the certificate also bound elsewhere',
            ],
        ],
        [
            'version' => '3.66.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'True About A Certificate',
            'changes' => [
                'FIXED: A CRITICAL "certificate expires in 4 days" was raised for a firewall whose web interface was serving a Let\'s Encrypt certificate with 89 days left. OPNsense keeps every certificate ever created in config.xml - the self-signed one from install, anything ACME has superseded, every CA in the chain - and all of them were alerted on identically. The alert was true about a certificate and false about the firewall',
                'ADDED: Agent v1.6.8 reports whether anything references each certificate. A refid appearing anywhere outside its own <cert> or <ca> element is a reference - system/webgui/ssl-certref, an OpenVPN certref, an IPsec or HAProxy binding - so matching on the value catches consumers from plugins we have never heard of',
                'CHANGED: The evaluator resolves rather than raises for a certificate the agent positively reports as unused. NULL is deliberately not treated as "no": an agent too old to report in_use keeps its certificate alerting, because silently dropping it would be worse than the noise',
                'CONTEXT: On the fleet this resolved one false critical and left the one real warning standing - a certificate in use, expiring in 19 days, on a firewall with no ACME client configured to renew it',
                'ADDED: tests/certificate_alert_test.php, including that the collector still never reads a private key and that the published package actually contains the change',
            ],
        ],
        [
            'version' => '3.65.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'The Body Was Thrown Away',
            'changes' => [
                'FIXED: Recent Changes on the system update page printed $message_lines[0] and discarded the rest of every commit message, so each entry showed as a one-line summary while the body - where the reasoning actually is - was thrown away. The subject stays prominent and the body is available behind a disclosure, collapsed by default because ten commits with full bodies is a wall of text',
                'CHANGED: Attribution trailers are stripped from the displayed body. They are not part of the explanation',
                'FIXED: .commit-item hardcoded dark-theme greys (#cbd5e1, #e2e8f0) which are close to invisible on the light theme. That barely showed while only a one-line subject was rendered and became a wall of unreadable text once the body was. Theme variables now',
                'CHANGED: The sidebar is a quarter shorter: rows from 42px to 34px, no vertical row margin - 1px each side is 2px per row and 44px over the whole menu, for separation the hover highlight already provides - and tighter section labels. Measured 1,140px of content down to 902px, so the whole menu fits a laptop viewport without scrolling',
            ],
        ],
        [
            'version' => '3.64.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'Eight Thousand Undelivered',
            'changes' => [
                'FIXED: 8,497 alert notifications failed, every one of them, with 535 Username and Password not accepted. The credentials were correct. Secrets are stored as enc:v1:... and nothing decrypted them before AUTH, so the ciphertext was being offered as the password. Decryption now happens once, inside send_smtp_email(), so there is a single place that can get it wrong',
                'FOUND: The only decrypt attempt in the mail path called decrypt_setting_value(), a function that does not exist in this codebase and never has, wrapped in function_exists() - so the guard was permanently false and quietly did nothing while reading as a safeguard',
                'FIXED: A failed decryption now sends nothing rather than the ciphertext. Offering an encrypted blob as a password produces a 535 that reads like a wrong password and sends you looking at the wrong thing',
                'FIXED: The SMTP settings test opened a TCP socket, closed it, and reported success. It never authenticated, so it passed with the wrong password, with no password, and with no username at all - the one failure it could not detect was the only thing wrong. It authenticates now through the same code the alerts use, and sends no mail',
                'FOUND: Restoring delivery released a backlog of twelve open incidents that mailed at once, ten of them stale. Each condition resolves by iterating what the agent currently reports, so an object that stops being reported is never visited and its incident stays open forever, still counting toward the notification repeat limit. health_ingest_services() deletes rows the agent stops reporting - deliberately - so eight service.stopped incidents outlived the rows that justified them',
                'ADDED: resolve_vanished() closes incidents whose object is no longer reported, for services and VPN tunnels, skipped when the firewall is stale because reporting nothing is not the same as nothing being wrong',
                'ADDED: A sweep for incidents against firewalls that no longer exist. One was open against __opnmgr_test_a, a fixture firewall deleted long ago, and nothing iterates a deleted firewall so it could never have resolved',
                'ADDED: tests/smtp_delivery_test.php, twenty-four assertions across both defects',
                'CHANGED: Two user accounts created for screenshot capture held placeholder addresses (screenshot@localhost, screenshots@local) and, being administrators, received every alert. Their addresses were cleared, so alerts reach real mailboxes and stop being recorded as partial failures',
            ],
        ],
        [
            'version' => '3.63.3',
            'date' => '2026-09-17',
            'type' => 'patch',
            'title' => 'Moved To Where They Belong',
            'changes' => [
                'CHANGED: The walkthrough and highlight MP4s and the caption file moved from the v3.31.0 release to v3.63.2, where the interface they show matches the code the tag points at. They were fetched from the old release rather than re-recorded, so the published bytes are unchanged - the sizes match exactly - and removed from v3.31.0 only after the upload was confirmed',
                'NOTE: This retires the caveat recorded in 3.63.2. The videos no longer hang from a tag five weeks older than the interface in them; v3.31.0 carries only its own agent tarball again',
            ],
        ],
        [
            'version' => '3.63.2',
            'date' => '2026-09-17',
            'type' => 'patch',
            'title' => 'Release Assets',
            'changes' => [
                'CHANGED: The walkthrough and highlight MP4s were re-recorded and uploaded to the v3.31.0 release, alongside docs/walkthrough.vtt. They are not tracked files - a binary that regenerates from a script does not belong in git history - so regenerating them was part of publishing them',
                'CHANGED: The committed poster frame was replaced with one from the take that was actually published, so the still and the video it fronts are the same recording',
                'NOTE: The videos show the v3.63.1 interface while the release they hang from is tagged v3.31.0, which is five weeks of development older. That was a deliberate choice to avoid publishing nineteen unpushed commits to a public repository merely to host two files',
            ],
        ],
        [
            'version' => '3.63.1',
            'date' => '2026-09-17',
            'type' => 'patch',
            'title' => 'Poster Frame',
            'changes' => [
                'CHANGED: The walkthrough was re-recorded and its poster frame regenerated, so the last stale documentation asset matches the current build - it shows the version in the header and the grouped admin menu',
                'CHANGED: Only the poster is committed, at 1920x1080. The MP4s remain release assets and are gitignored: a binary that regenerates from a script does not need to sit in git history',
                'CHANGED: The demo environment was stood up and dismantled again - throwaway database, web root, account, server, recording and encoded video all removed, production confirmed untouched at two firewalls',
            ],
        ],
        [
            'version' => '3.63.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'Recaptured',
            'changes' => [
                'CHANGED: All 28 documentation screenshots regenerated against the current build, so they show the version in the header and the grouped admin menu rather than a product two days out of date',
                'FIXED: The capture script reported a skeleton page on every run. It tested body.innerHTML for "Loading..." regardless of visibility, and settings.php carries one in an unopened tunnels table that JavaScript replaces - so a real skeleton would have been indistinguishable from the false alarm everyone had learned to ignore. It now only counts a placeholder that is on screen',
                'CHANGED: Captures were taken in an isolated environment and it was dismantled afterwards - a throwaway database, web root, account and server, all removed, with the production database confirmed untouched. The fixture\'s own assertion that a demo fleet has no command-queue rows passed',
                'ADDED: The annotated figures in the User Guide were re-rendered against the new images and checked marker by marker, because coordinates placed over one screenshot are not automatically right over another',
            ],
        ],
        [
            'version' => '3.62.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'Ten Entries, One Row',
            'changes' => [
                'CHANGED: The admin section of the sidebar is a collapsible group. It carried ten entries on a sidebar that already had twenty above it, which was most of the scrolling. Nothing was removed - everything is still one click away, and the section now costs one row until you want it',
                'ADDED: The group opens by itself when you are already on one of its pages, decided in PHP rather than script, so it is correct before anything runs and never flashes shut on the page you are looking at',
                'ADDED: The open state is remembered per browser, and a blocked or private-mode localStorage leaves it closed rather than throwing',
                'FIXED: The first implementation did not collapse at all. grid-template-rows: 0fr sizes only the first grid row, so ten sibling links each got their own auto-sized row and the group stayed 440px tall while reporting itself closed. The items are wrapped in a single inner element now, and the collapsed height was measured rather than assumed',
                'CHANGED: The chevron is hidden when the sidebar is a rail, since it is the only thing there that is not a destination',
            ],
        ],
        [
            'version' => '3.61.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'Two Documentation Systems',
            'changes' => [
                'FOUND: There were two. documentation.php was 669 lines of hardcoded HTML last meaningfully updated in October 2025, and it was the one the sidebar linked. doc_viewer.php rendered the same documentation from the documentation_pages table, scripts/migrate_docs_to_db.php existed to move it there, and the sidebar\'s isActive() already listed both files - a migration started and never finished, leaving the stale copy as the only one anyone could reach',
                'CHANGED: documentation.php redirects to the database-backed viewer. The URL is kept because it is what is bookmarked and what the sidebar, header search and external links point at',
                'ADDED: The User Guide is rewritten for v3.61.0 with annotated screenshots - the dashboard, enrollment and the fleet list, fourteen numbered markers across three figures',
                'ADDED: Annotations are positioned over the image in percentages rather than burned into it, so they stay aligned when the image scales and can be corrected without recapturing. Marker positions were checked against a rendering and moved off the values they were covering',
                'ADDED: scripts/build_user_documentation.php generates the page, so the content has a source, a diff and a review rather than being edited in the database. It verifies every referenced screenshot exists before writing, and writes only with --apply',
                'CHANGED: Screenshots come from docs/images/github/, captured against the isolated demo fleet. Every hostname, address and customer in them is fictitious - the alternative is a redaction step that can silently fail',
                'ADDED: The guide leads with what actually breaks: an agent that stops reporting, what self-heals since 1.6.6, and the per-firewall reinstall command that keeps a firewall\'s identity instead of enrolling it twice',
            ],
        ],
        [
            'version' => '3.60.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'Verified On First Contact',
            'changes' => [
                'CHANGED: All thirteen SSH call sites moved from StrictHostKeyChecking=no to accept-new against a managed known_hosts at /etc/opnmgr/known_hosts. Disabling the check meant first contact with any firewall was trusted blindly, and this manager holds root keys to the whole fleet, so first contact is exactly when verification matters',
                'ADDED: scripts/pin_host_keys.php pins a firewall\'s host keys after verifying them over the agent channel - which is authenticated and signed independently of SSH - rather than over SSH, where verifying SSH with SSH proves nothing. A key the firewall does not report is refused, not written',
                'FOUND: The first dry run refused to pin three keys per firewall, because ip_address is 0.0.0.0 on every row and scanning it reaches the manager rather than the firewall. The verification correctly declined to pin the local host\'s keys under a firewall\'s name; placeholder addresses are now skipped outright',
                'FIXED: is_tunnel_active() looked for "ssh.*-L {port}:" while the tunnel is started with -L 127.0.0.1:{port}: - the loopback prefix was added to stop tunnels being reachable off-box and this check was never updated. It never matched, so start_tunnel\'s "already running, reuse it" branch was unreachable and a second connect re-bound a live port',
                'ADDED: AI Analysis is in the sidebar. The page existed, was linked only from a firewall\'s detail page, and the answer to "AI analysis is disabled" was a URL you had to be told. The error message now names the path as well',
                'ADDED: tests/ssh_host_key_test.php asserts no caller disables the check, that every caller shares one known_hosts, and that pinning verifies out-of-band',
            ],
        ],
        [
            'version' => '3.59.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'Free In The Table, Busy On The Machine',
            'changes' => [
                'FIXED: The tunnel port allocator logged "Port pair 8100/8101 shows as free in DB but one is in use on system" and skipped to the next pair. Skipping is right, but nothing reconciled the two views, so the range leaked a pair at a time until it would be exhausted',
                'FOUND: Drift ran in both directions and nothing ever corrected either. A session past expires_at stayed active forever - the status enum has an "expired" value that no code has ever set - so an abandoned session reserved its port permanently. A session whose ssh process had died stayed active too, holding a port nothing was listening on. And an ssh process outliving its session held a port the table called free, which is the case that produced the warning',
                'ADDED: reconcile_tunnel_sessions() runs before allocation. It expires sessions past their window and takes their tunnels down, closes sessions whose tunnel is gone, and kills tunnels with no session behind them - bounded to the tunnel port range, and never touching a port a live session claims',
                'FIXED: Process matching does not use pgrep -f. Its pattern appears in the command line of the shell running it, so "pgrep -f -- \'-L 127.0.0.1:8199:\'" reported a match for a port with no tunnel at all. Reconciliation kills what that returns, so it matches on the executable being ssh and excludes its own pid',
                'ADDED: tests/tunnel_port_reconcile_test.php, and both drift directions were exercised against the live table with throwaway rows: an expired session became expired, a session with no tunnel became closed, and neither live tunnel was disturbed',
            ],
        ],
        [
            'version' => '3.58.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'Forwarding Nothing',
            'changes' => [
                'FIXED: A tunnel that forwarded nothing was reported as established. ssh treats a failed port bind - the port still held by a previous tunnel, most often - as a warning, so without ExitOnForwardFailure it stayed connected and backgrounded itself under -f. exec() saw return code 0, the session was recorded active, and the proxy met a closed port: "Failed to connect to 127.0.0.1 port 8101". The ssh process was running, authenticated, and forwarding nothing',
                'FIXED: SSH to a firewall failed with "Permission denied (publickey)" although the manager\'s key was in its authorized_keys all along. OpenSSH silently refuses keys from an over-permissive /root/.ssh, which is indistinguishable from a missing key. Deploying the key also corrects the modes, and that is what restored access',
                'ADDED: tests/tunnel_forward_test.php. Exit status is load-bearing here - it is the only thing anyone checks - so the option that makes it truthful is now asserted',
            ],
        ],
        [
            'version' => '3.57.1',
            'date' => '2026-09-17',
            'type' => 'patch',
            'title' => 'Initializing Forever',
            'changes' => [
                'FIXED: The Agent Repair progress modal sat at "Initializing... 0%" forever. repair_agent_ssh.php built its session id with uniqid(..., true), which embeds a dot, and api/repair_status.php validates the id against ^[A-Za-z0-9_-]+$ - so every repair ever started was rejected by its own status endpoint. Session ids are hex now',
                'FIXED: The poller ignored an unsuccessful status response entirely. There was no else branch, so a 400 produced no error, no stop and no message - the modal simply stayed at zero. Any outcome now ends the poll and says what happened',
                'ADDED: A five minute ceiling on the poll, so it cannot spin indefinitely when something upstream stops answering',
                'ADDED: Assertions that a generated session id passes the validator that consumes it, and that the old format would still be rejected. Both halves looked individually reasonable and disagreed, which is the only way this bug was possible',
            ],
        ],
        [
            'version' => '3.57.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'Reinstall This One',
            'changes' => [
                'ADDED: Every firewall page carries a copy-paste command that reinstalls the agent on that firewall, with a copy button. When an agent stops, the recovery is someone at that firewall\'s console - twice this week that meant composing the installer invocation from memory',
                'ADDED: The command carries that firewall\'s own hardware id, so a box that has lost its /usr/local/etc files rejoins as the same record. A generic command has a worse failure than being wrong: the firewall enrols as a NEW record, silently abandoning its history, alerts and backups while looking like a success',
                'ADDED: The installer accepts OPNMGR_HARDWARE_ID and seeds it only when the firewall has none. An id already present is never overwritten - it says so and carries on - which is what makes running this on a healthy firewall safe. It reinstalls the agent and leaves identity, credentials and configuration untouched',
                'ADDED: A malformed id is refused rather than written, and a seeded id file is created 0600',
                'ADDED: A firewall with no hardware id recorded is called out in the page rather than quietly given a command that cannot bind it',
                'ADDED: Copying falls back to execCommand where navigator.clipboard is unavailable, which is over plain http - often exactly where a broken fleet is being worked on',
                'ADDED: tests/agent_reinstall_command_test.php, twenty-one assertions, including that the id write sits in the else branch of the existence test and that the installer still removes neither the hardware id nor the stored credentials',
            ],
        ],
        [
            'version' => '3.56.1',
            'date' => '2026-09-17',
            'type' => 'patch',
            'title' => 'Table Not Modelled Here',
            'changes' => [
                'FIXED: The schema contract test skipped any statement whose table was not in the schema - "table not modelled here" - which is exactly the case it most needed to catch. A table the schema does not have is not unmodelled; it is a statement that cannot run. Both files that wrote to activity_log were scanned by this test and passed, and the 500 was found by clicking the button instead',
                'ADDED: It now fails when an INSERT or UPDATE names a table the schema lacks, allowing only genuine temporary tables. Verified by reintroducing the activity_log insert and confirming the test caught it',
                'FIXED: The UPDATE pattern is case-insensitive and matched English prose - "Update ring set to" in a log message read as UPDATE ring SET. It now requires an actual assignment in the SET clause',
            ],
        ],
        [
            'version' => '3.56.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'The Repair That Would Have Broken It',
            'changes' => [
                'FIXED: Repair Agent returned 500. It wrote to activity_log, a table that exists in no schema and no migration, so every request that got as far as succeeding died on the last line - after the repair had already been launched. api/ssh_install_agent.php had the identical line. Both record to audit_log now',
                'FOUND: The repair would have installed the wrong agent. Its payload downloaded downloads/tunnel_agent.sh - the legacy standalone agent, last modified October 2025 - and added a cron entry running it every two minutes. On a fleet running the 1.6.x plugin agent that is not a repair; it is a second, obsolete agent checking in beside the real one. The only reason it never happened is that sudo blocked the transfer',
                'CHANGED: The repair now runs the same installer as every other install path, over a single SSH command. No scp, no temp file on either side, and nothing that can go stale between here and the firewall',
                'FIXED: The script ran "sudo -u www-data" while already running as www-data, which no sudoers rule permits and none should need to',
                'FIXED: The SSH connectivity test reported success when it had connected to nothing. It folded stderr into the stream it matched - ssh ... \'echo "Connected"\' 2>&1 | grep -q "Connected" - and sudo\'s denial message quotes the command it refused, which contains that echo. The check matched the text of its own failure. Every "[SUCCESS] SSH connection successful" line this ever logged is worthless, including the one written against fw51 today. stderr goes to the log now, and the marker is matched as a whole line',
                'CHANGED: The version label moved from the dashboard KPI strip to the page header, beside the brand. It rendered correctly where it was and was still missed: small, muted, and to the right of a tile grid that wraps. The top of the page means the top of the page',
                'ADDED: tests/agent_repair_test.php, eighteen assertions covering all three defects, asserting against code with comments stripped so the fix\'s own account of what it replaced does not trip them',
            ],
        ],
        [
            'version' => '3.55.1',
            'date' => '2026-09-17',
            'type' => 'patch',
            'title' => 'Just The One Number',
            'changes' => [
                'CHANGED: The dashboard version chip shows the application version only. The agent version was added beside it in 3.55.0 on the reasoning that it decides what firewalls are offered - but that belongs to the rollout surface, where scripts/agent_rollout.php already reports it in context, rather than to the dashboard header',
            ],
        ],
        [
            'version' => '3.55.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'Which Version Is This',
            'changes' => [
                'ADDED: The dashboard states its own version in the top toolbar - the application version and the agent version it publishes to the fleet. Answering "which version is this" meant reading a file on the server, and the agent number in particular decides what every firewall is offered',
                'FIXED: APP_VERSION_DATE had been left at 2026-09-16 through four releases. Each bump updated APP_VERSION_NAME and not the date beside it, which nothing checked because nothing displayed it. It is in the dashboard tooltip now, and a test fails if it falls behind the newest changelog entry',
                'ADDED: The chip links to the updates page and is a single line on a phone rather than overflowing the toolbar',
            ],
        ],
        [
            'version' => '3.54.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'A 403 Nobody Saw',
            'changes' => [
                'FIXED: The Repair Agent button on the firewall page posted only firewall_id, with no CSRF token anywhere, so csrf_verify() refused it with a 403 and the button had never worked. Reported from the browser console as "/api/repair_agent_ssh.php:1 Failed to load resource: 403"',
                'FIXED: Reset Agent was broken differently and worse: it read its token from document.querySelector(\'[name="csrf_token"]\'), an element that does not exist on that page - the only hidden input there is name="csrf" - so querySelector returned null and .value threw a TypeError before any request was made',
                'FIXED: Twenty-two more POSTs to CSRF-protected endpoints sent no token, across seven pages: the firewall page (agent update, enrollment key, speedtest, and both SSH key operations), the admin queue (all seven actions), alerts, logs, network tools, dev features and settings. Six of those pages had no token available to JavaScript at all',
                'CHANGED: JSON callers send X-CSRF-Token rather than a body field. A JSON body never populates $_POST, so a token placed there reaches nothing - every one of these endpoints already accepted the header',
                'ADDED: tests/csrf_callers_test.php scans every page for POSTs to endpoints that verify CSRF and fails if one carries no token. Nothing caught any of this because the failure is invisible from the server: a refused POST looks exactly like an attack being blocked, which is what csrf_verify() is for. Only the browser console showed it',
            ],
        ],
        [
            'version' => '3.53.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'By Whom',
            'changes' => [
                'FIXED: CLI-initiated audit entries recorded actor_type "system" with a NULL username, so the log said what had been done and to what, but never by whom. The rollout entries added in 3.52.0 showed this immediately: "agent 1.6.7 promoted to stage fleet" with nobody attached to it. For a promotion that releases an agent to the whole fleet, who is most of the point',
                'CHANGED: audit_log() falls back to the operating system user when there is no session, so every CLI call site gains this and not only the one that prompted it. Entries from a web request still resolve their actor from the session exactly as before',
                'CHANGED: actor_type stays "system". It is an ENUM(user, agent, system, anonymous) and a CLI operator is not a portal user with a user_id; the audit page already prefers username over actor_type when one is set, so filling in the name was enough and needed no migration',
                'ADDED: Under sudo the effective user is root while the person is SUDO_USER, and the entry names both - "administrator (sudo root)" - rather than recording a fleet-wide release as root. The name is truncated to the varchar(64) the column allows',
                'ADDED: Eight assertions in tests/settings_audit_test.php, including running the resolver under a simulated sudo invocation',
            ],
        ],
        [
            'version' => '3.52.0',
            'date' => '2026-09-17',
            'type' => 'minor',
            'title' => 'Who Released What',
            'changes' => [
                'ADDED: Agent rollout stage changes are written to the audit log. Promoting is the act that lets a release reach firewalls, and until 3.51.0 it happened implicitly on publish with no record anywhere of who released what to whom - the only way to know an agent version had gone out was to notice the version change on a firewall afterwards',
                'ADDED: The entry records the transition rather than the destination - previous stage, previous version, and the stage that was actually in effect - because "promoted to fleet" alone does not say what changed',
                'ADDED: It also records how many firewalls the promotion reaches, counted before the write. That is the question worth asking of the log later: not which stage was chosen, but who it opened the update to',
                'ADDED: Pilot changes are audited as agent.rollout.pilot, naming the firewalls. At stage pilot that setting is what decides which firewalls a release reaches, so it belongs in the same trail',
                'ADDED: A rejected stage and a failed settings write are both audited as failures. A refused promotion is worth a record too',
                'ADDED: Ten assertions in tests/agent_rollout_test.php covering the audit trail',
            ],
        ],
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
