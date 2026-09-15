# Screenshots

Every image below is the real OPNManager interface. The data in it is not real: it
comes from `scripts/demo_fixture.php`, which populates a throwaway database with
fictitious customers, sites and simulated telemetry and refuses to run against
anything else. Addresses are reserved documentation ranges (RFC 5737, RFC 3849) and
hostnames use the reserved `.example` domain (RFC 6761). No live firewall is
contacted to produce them, and no update, reboot or restore is triggered.

All captures are 1440×900. Dark theme except where noted. Click any image for
full size.

[← Back to the README](../README.md) · [How these are regenerated](images/github/README.md)

## Walkthrough

A minute through the fleet dashboard, the firewall list, fleet search, one firewall's
health, configuration drift, a rollout in progress, incidents, and the customer/site
model.

[![Walkthrough of OPNManager: the fleet dashboard, firewall list, fleet search, per-firewall health, configuration drift, an update rollout in progress, the incident list, and customers and sites](images/github/walkthrough.gif)](images/github/walkthrough.gif)

A higher-quality 1440×900 MP4 of the same walkthrough is attached to the
[latest release](https://github.com/agit8or1/OPNMGR/releases/latest).

## The fleet

### Dashboard

Roll-up tiles appear only when non-zero, so an uneventful fleet is a short page. Here
one firewall is offline, four have updates pending, two have drifted from baseline and
three certificates are inside the warning window.

[![Fleet dashboard with eleven firewalls across five customers, roll-up tiles for offline devices, pending updates, gateways down, VPN down, drift, expiring certificates, critical incidents and active maintenance, above a per-firewall health table](images/github/fleet-dashboard.png)](images/github/fleet-dashboard.png)

### Firewall list

Sortable and filterable, with colour-coded tags, health score, per-device version and
agent version.

[![Firewall management list showing hostname, WAN and LAN addresses, IPv6, customer, OPNsense version, tags, check-in age, uptime and a health grade for each of eleven firewalls](images/github/fleet-firewalls.png)](images/github/fleet-firewalls.png)

### Fleet search

One box across the whole fleet. Qualifiers (`customer:`, `site:`, `tag:`, `version:`,
`agent:`, `ip:`, `interface:`, `vpn:`, `status:`) combine with AND, and a bare CIDR
matches any firewall with an address inside it.

[![Fleet search for tag:critical-site returning five matching firewalls with their customer, site, WAN and LAN addresses, OPNsense and agent versions, tags and last-seen time](images/github/fleet-search.png)](images/github/fleet-search.png)

### Customers and sites

Customers group sites, sites group firewalls. These are organisational containers:
they carry a code, timezone, tags, contacts and a default maintenance window, and they
have no accounts and no login.

[![Customer management page listing five customer organisations with code, contact, timezone, default maintenance window, their sites and the number of firewalls at each](images/github/customers.png)](images/github/customers.png)

## Health and problems

### Health overview

Fleet-wide counts of gateways down and degraded, tunnels down, services stopped and
certificates expiring, with the certificates inside 30 days listed and per-firewall
agent authentication state.

[![Firewall health overview with counters for gateways down, gateways degraded, VPN tunnels down, services stopped, expired and expiring certificates and unsettled CARP, above a table of certificates expiring within thirty days and an agent health table](images/github/health-overview.png)](images/github/health-overview.png)

### One firewall's health

Gateways with interface, address, latency and loss; VPN tunnels with handshake age and
transfer; service state; and certificate expiry. Certificate metadata only — private
key material is never collected or stored.

[![Health detail for one firewall showing two gateways including one flagged high latency at 148.70 ms with 2.40% loss, a WireGuard tunnel, six services with one stopped, and a certificate expiring in six days](images/github/firewall-health.png)](images/github/firewall-health.png)

### Incidents

One entry per ongoing problem, opened when the condition starts and closed when it
actually clears. Acknowledging stops notification without closing the incident.

[![Incident list with two critical and four warning incidents, each showing severity, the problem, affected firewall and customer, how long it has been open, how many times it has been seen and notified, and acknowledge controls](images/github/incidents.png)](images/github/incidents.png)

### Firewall detail

Per-device system statistics over the selected window — WAN throughput, CPU load,
memory and disk.

[![Firewall detail page showing WAN traffic, CPU usage, memory and disk charts over twenty-four hours for a single firewall](images/github/firewall-detail.png)](images/github/firewall-detail.png)

## Configuration

### Configuration drift

Each firewall's newest backup compared against the baseline you approved.
Serialisation noise and the fields OPNsense rewrites on every save are ignored, so an
untouched firewall does not report drift. Nothing here restores anything.

[![Configuration drift page showing three drifted firewalls with the changed sections named - filter and nat, dhcpd, interfaces and staticroutes - alongside eight matching baseline, with detection age and baseline age](images/github/config-drift.png)](images/github/config-drift.png)

### Backup history

Per-firewall configuration backups with size, type and per-backup download, restore
and delete.

[![Configuration backup history for one firewall listing fifteen nightly automated backups with date, type, size and description](images/github/backup-history.png)](images/github/backup-history.png)

## Operations

### Fleet updates

Rollout rings — canary, then pilot, then production. Rings are a rollout mechanism,
not customer tiers. Progression is manual unless explicitly automated, and members of
a CARP pair are never updated simultaneously.

[![Fleet updates page with a running OPNsense 26.7.3 campaign showing canary completed cleanly, pilot in progress and production holding, above a fleet table with each firewall's ring, current and available version and HA partner](images/github/fleet-updates.png)](images/github/fleet-updates.png)

### Bulk operations

High-risk operations require typing a confirmation phrase that includes the target
count. Raw shell is deliberately not available as a bulk operation.

[![Bulk operations page with an operation selector, eleven selectable target firewalls each showing customer, site, rollout ring and status, and a run control](images/github/bulk-operations.png)](images/github/bulk-operations.png)

### Maintenance windows

Scoped to a firewall, a site or a whole customer. Monitoring, health collection and
incident recording continue during a window; only outbound notification is withheld,
and the suppression is recorded on the incident.

[![Maintenance windows page listing scheduled, active and completed windows with their scope, time range and reason](images/github/maintenance.png)](images/github/maintenance.png)

## Administration

### Staff and roles

Administrator, Technician and Read Only, defined once as a capability matrix rather
than role strings scattered through the code. Navigation and actions follow the
capability, not the role name.

[![User management page listing five staff accounts with name, username, email and role badges for admin, technician and readonly](images/github/users-roles.png)](images/github/users-roles.png)

### Audit log

Who did what, to which firewall, from where — filterable by action, user, firewall,
result and date range. Credential material is never recorded.

[![Audit log listing actions including baseline approval, incident acknowledgement, ring advancement, backup download, agent check-in and a refused raw shell command, each with actor, target and result](images/github/audit-log.png)](images/github/audit-log.png)

### Light theme

A full light theme with system-preference detection and a manual toggle.

[![The same fleet dashboard rendered in the light theme](images/github/dashboard-light.png)](images/github/dashboard-light.png)
