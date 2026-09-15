# Screenshots

Every image below is the real OPNManager interface. The data in it is not real: it
comes from [`scripts/demo_fixture.php`](../scripts/demo_fixture.php), which populates
a throwaway database with fictitious customers, sites and simulated telemetry and
refuses to run against anything else. Addresses are reserved documentation ranges
(RFC 5737, RFC 3849) and hostnames use the reserved `.example` domain (RFC 6761).
No live firewall is contacted, and no update, reboot or restore is triggered.

**28 captures · 14 light · 14 dark · 1440×1000 at 2× · click any image for full size**

[← Back to the README](../README.md) ·
[Walkthrough video](#walkthrough-video) ·
[How these are regenerated](images/github/README.md) ·
[More free MSP tools at mspreboot.com](https://mspreboot.com/free-projects)

## Contents

- [Walkthrough video](#walkthrough-video)
- [Overview and dashboards](#overview-and-dashboards)
- [Visual insight and monitoring](#visual-insight-and-monitoring)
- [Configuration and change control](#configuration-and-change-control)
- [Everyday workflows](#everyday-workflows)
- [Management and administration](#management-and-administration)

---

## Walkthrough video

A recorded tour of the running application: the fleet dashboard, finding a
connectivity problem, reviewing what changed against an approved baseline, planning
a ringed update, and the customer and site model — with a theme switch part way
through.

[![Play the OPNManager walkthrough: fleet dashboard, incident triage, configuration diff, update rollout and customer grouping](images/github/walkthrough-poster.png)](https://github.com/agit8or1/OPNMGR/releases/latest)

**[▶ Download the walkthrough from the latest release](https://github.com/agit8or1/OPNMGR/releases/latest)** —
`opnmanager-walkthrough-1080p.mp4`, with a short highlight clip, a transcript and a
WebVTT caption file alongside it. The captions are also in
[`docs/walkthrough.vtt`](walkthrough.vtt) and the script in
[`docs/walkthrough-script.md`](walkthrough-script.md).

---

## Overview and dashboards

### Fleet dashboard
**Dark** — See what needs attention before anything else: roll-up tiles appear only
when the count is non-zero, so a quiet fleet stays a short page.

[![Fleet dashboard in dark theme: eleven firewalls across five customers, with tiles for offline devices, pending updates, gateways down, VPN down, configuration drift, expiring certificates, critical incidents and active maintenance, above a per-firewall health table](images/github/fleet-dashboard-dark.png)](images/github/fleet-dashboard-dark.png)

### Fleet dashboard, light theme
**Light** — The same view with the theme toggled, showing that the whole interface
follows system preference or a manual choice.

[![The same fleet dashboard rendered in the light theme, with identical tiles and health table](images/github/fleet-dashboard-light.png)](images/github/fleet-dashboard-light.png)

### Firewall list
**Light** — Sort and filter the whole fleet, with colour-coded tags, health grade,
OPNsense version and agent version on every row.

[![Firewall list in light theme showing hostname, WAN and LAN addresses, IPv6, customer, OPNsense version, tags, check-in age, uptime and a health grade for eleven firewalls](images/github/fleet-firewalls-light.png)](images/github/fleet-firewalls-light.png)

### Customers and sites
**Dark** — Group firewalls the way you actually support them. Customers and sites are
organisational containers with a code, timezone, tags, contacts and a default
maintenance window; they have no accounts and never log in.

[![Customer management in dark theme listing five customer organisations with code, contact, timezone, default maintenance window, their sites and firewall counts](images/github/customers-sites-dark.png)](images/github/customers-sites-dark.png)

---

## Visual insight and monitoring

### Firewall statistics
**Dark** — Read a single firewall's WAN throughput, CPU load, memory and disk over
the selected window, collected on each agent check-in.

[![Firewall detail in dark theme showing four populated charts over twenty-four hours: WAN traffic inbound and outbound, CPU load average, memory usage percentage and disk usage percentage, each with average, peak and low figures](images/github/firewall-statistics-dark.png)](images/github/firewall-statistics-dark.png)

### Firewall health
**Light** — Diagnose one firewall: gateways with latency and loss, VPN tunnels with
handshake age, service state and certificate expiry. Certificate metadata only —
private key material is never collected.

[![Per-firewall health panel in light theme showing two gateways including one flagged high latency at 148.70 ms with 2.40% loss, a WireGuard tunnel, six services with one stopped, and a certificate expiring in six days](images/github/firewall-health-light.png)](images/github/firewall-health-light.png)

### Fleet health overview
**Dark** — Spot fleet-wide problems at a glance: gateways down and degraded, tunnels
down, services stopped, and every certificate inside the warning window.

[![Fleet health overview in dark theme with counters for gateways down, gateways degraded, VPN tunnels down, services stopped, expired and expiring certificates and unsettled CARP, above a table of certificates expiring within thirty days and per-firewall agent authentication state](images/github/health-overview-dark.png)](images/github/health-overview-dark.png)

### Incidents
**Light** — Work one entry per ongoing problem rather than one email per poll.
Acknowledging stops notification without closing the incident.

[![Incident list in light theme with two critical and four warning incidents, each showing severity, the problem, affected firewall and customer, how long it has been open, and acknowledge controls](images/github/incidents-light.png)](images/github/incidents-light.png)

### Notification history
**Dark** — Confirm what was actually sent, to how many recipients, and whether
delivery succeeded. A partly delivered alert records as *Partial* and keeps the error.

[![Alert history in dark theme listing seven notifications with time, severity, affected firewall, message, recipient count and a delivery status of Sent or Partial](images/github/alert-history-dark.png)](images/github/alert-history-dark.png)

### Audit history
**Light** — Answer "who changed this" — filterable by action, user, firewall, result
and date range. Credential material is never recorded.

[![Audit log in light theme listing sign-ins, a baseline approval, an incident acknowledgement, a rollout ring advance, a backup download, an agent check-in and a refused raw shell command, each with actor, source address, firewall and result](images/github/audit-log-light.png)](images/github/audit-log-light.png)

### Manager health
**Dark** — Check the management server itself: CPU, memory, disk and load, plus the
state of the services the platform depends on.

[![System health monitor in dark theme showing CPU usage, memory, disk space and load average, above service status for database, command queue, request queue and firewall connectivity, and server and application information](images/github/manager-health-dark.png)](images/github/manager-health-dark.png)

### Agent diagnostics
**Light** — Verify the fleet is actually reporting: per-agent last check-in, age and
connection tests.

[![System diagnostics in light theme showing an agent status table with each firewall's last check-in time and minutes since, alongside a proxy status panel](images/github/agent-diagnostics-light.png)](images/github/agent-diagnostics-light.png)

---

## Configuration and change control

### Configuration drift
**Light** — See which firewalls no longer match the configuration you approved, and
which sections changed. Serialisation noise and fields OPNsense rewrites on every
save are ignored, so an untouched firewall reports no drift.

[![Configuration drift in light theme showing three drifted firewalls with changed sections named — filter, dhcpd, and filter with interfaces — alongside eight matching baseline, with detection age and baseline age](images/github/config-drift-light.png)](images/github/config-drift-light.png)

### Configuration diff
**Dark** — Review the exact change: each added, removed or modified entry, named by
its description rather than by line number. Nothing here restores anything.

[![Configuration diff in dark theme showing two firewall rules added since the baseline, each labelled "added" with the rule description, interface and type, above an acknowledgement note field](images/github/config-diff-dark.png)](images/github/config-diff-dark.png)

### Fleet configuration search
**Light** — Ask a deterministic question across every stored configuration. Named
checks such as "SSH open to the internet" run over the parsed config, with no AI in
the path.

[![Fleet configuration search in light theme running the ssh_open_to_world check across eleven configurations and reporting one match, showing the offending WAN pass rule permitting port 22 from any source](images/github/config-search-light.png)](images/github/config-search-light.png)

### Backup history
**Dark** — Retrieve any stored configuration for a firewall, with per-backup
download, restore and delete.

[![Configuration backup history in dark theme listing nightly automated backups for one firewall with date, type, size and description, and per-row download, restore and delete actions](images/github/backup-history-dark.png)](images/github/backup-history-dark.png)

---

## Everyday workflows

### Update planning and rollout
**Dark** — Stage firmware across canary, pilot and production rings with manual
progression. Rings are a rollout mechanism, not customer tiers, and members of a CARP
pair are never updated at the same time.

[![Fleet updates in dark theme with a running OPNsense 26.7.3 campaign showing canary completed cleanly, pilot in progress and production holding, above a fleet table with each firewall's ring, current and available version and HA partner](images/github/fleet-updates-dark.png)](images/github/fleet-updates-dark.png)

### Bulk operations
**Light** — Act on many firewalls at once. High-risk operations require typing a
confirmation phrase that includes the target count, and raw shell is deliberately not
available in bulk.

[![Bulk operations in light theme with an operation selector, eleven selectable target firewalls each showing customer, site, rollout ring and status, and a run control](images/github/bulk-operations-light.png)](images/github/bulk-operations-light.png)

### Maintenance windows
**Dark** — Schedule planned work against a firewall, a site or a whole customer.
Monitoring and incident recording continue; only outbound notification is withheld.

[![Maintenance windows in dark theme with a scheduling form above a table of scheduled, active and completed windows showing scope, target, time range and reason](images/github/maintenance-windows-dark.png)](images/github/maintenance-windows-dark.png)

### Fleet search
**Light** — Find anything across the fleet from one box. Qualifiers such as
`customer:`, `site:`, `tag:`, `version:` and `ip:` combine with AND, and a bare CIDR
matches any firewall with an address inside it.

[![Fleet search in light theme for tag:critical-site returning five matching firewalls with customer, site, WAN and LAN addresses, OPNsense and agent versions, tags and last-seen time](images/github/fleet-search-light.png)](images/github/fleet-search-light.png)

### Enrol a firewall
**Light** — Add a firewall by pasting one command into its SSH session. Enrollment
tokens are single-use and expire.

[![Add New Firewall in light theme showing one-click enrollment with a single copyable command, an explanation of what it does, and a security notes panel covering token uniqueness, expiry and agent removal](images/github/enroll-firewall-light.png)](images/github/enroll-firewall-light.png)

---

## Management and administration

### Settings
**Dark** — Reach every platform setting from one place: certificates, alerting,
backup retention, branding, GeoIP, SMTP, tunnels and the security scanner.

[![Settings in dark theme showing a grid of configuration cards for ACME certificates, alert settings, backup retention, branding, Fail2ban, GeoIP blocking, general settings, SMTP, system backup, tunnel management and security scanner](images/github/settings-dark.png)](images/github/settings-dark.png)

### Staff and roles
**Light** — Give technicians the access they need. Administrator, Technician and Read
Only are defined once as a capability matrix; navigation and actions follow the
capability, not the role name.

[![User management in light theme listing five staff accounts with name, username, email and role badges for admin, technician and readonly](images/github/users-roles-light.png)](images/github/users-roles-light.png)

### Tags
**Dark** — Organise the fleet along your own lines — contract level, circuit type,
compliance scope — and use those tags in search and bulk selection.

[![Tag management in dark theme listing twelve colour-coded tags such as critical-site, ha-pair, pci-scope and dual-wan, each with the number of firewalls carrying it](images/github/tags-dark.png)](images/github/tags-dark.png)

### Approved commands
**Light** — Constrain what may be run remotely. Each entry carries a category, a risk
level, a timeout and whether it requires confirmation.

[![Approved commands in light theme showing command statistics by category and risk level, a form to add an approved command, and a table of thirty approved command patterns with category, risk, timeout and confirmation requirement](images/github/approved-commands-light.png)](images/github/approved-commands-light.png)

### Alerting
**Dark** — Choose which conditions notify and how. Alerts are opt-in per severity and
go to administrator accounts with an email address.

[![Alert system configuration in dark theme with email and Pushover sections, per-severity toggles for informational, warning and critical alerts, and the list of administrator recipients](images/github/alerting-dark.png)](images/github/alerting-dark.png)

### Platform backup and restore
**Light** — Protect the management server itself: database, configuration, SSH keys
and certificates, with retention and an upload-to-restore path.

[![System backup and restore in light theme showing what a backup contains, backup statistics, an upload-to-restore panel with a warning, and a table of existing backups](images/github/system-backup-light.png)](images/github/system-backup-light.png)

### About
**Dark** — Confirm what is actually running: application and agent versions, schema
and API versions, and platform health.

[![About page in dark theme showing version information for application, agent, tunnel proxy, database schema and API, overall system health, and statistics for total firewalls, active agents and total backups](images/github/about-dark.png)](images/github/about-dark.png)

---

[← Back to the README](../README.md) ·
[Regenerate these images](images/github/README.md) ·
[mspreboot.com](https://mspreboot.com)
