# GitHub repository presentation

Settings that live in GitHub's own UI rather than in the tree, under
**About → ⚙** on the repository page.

> **Applied on 2026-09-15 (v3.22.0).** The repository now carries the description,
> topics and Website setting described below. This file is the record of that
> intended state, not a to-do list — check it if the settings ever drift, and update
> it here first if you change them.

Nothing here is applied automatically; GitHub settings are not part of the tree.

## About description

GitHub allows 350 characters. Currently set to this (248 characters):

> Self-hosted OPNsense fleet management for MSPs and IT teams. Monitor firewall and
> connectivity health, review configuration backups and drift, and roll out updates
> through canary/pilot/production rings — with firewalls grouped by customer and site.

Shorter alternative, if the one above wraps awkwardly in search results:

> Self-hosted OPNsense fleet management for MSPs and IT teams — health monitoring,
> configuration backups and drift, and ringed update rollouts.

## Website

The **Website** field is deliberately empty.

The project has no dedicated site. `mspreboot.com` resolves (verified, HTTP 200) but
is the maintainer's MSP consulting site, not an OPNManager project page, so putting it
in the Website field would misdirect anyone clicking it expecting documentation. If you
would rather point somewhere, the honest target is the changelog:

    https://github.com/agit8or1/OPNMGR/blob/main/CHANGELOG.md

## Topics

The twelve topics currently set:

```
opnsense
firewall-management
self-hosted
monitoring
msp
network-monitoring
fleet-management
opnsense-plugin
sysadmin
php
mysql
dashboard
```

How that differs from the pre-3.22.0 list:

| Topic | Action | Why |
|---|---|---|
| `multi-tenant` | **Removed** | Inaccurate and actively misleading. Customers are organisational groupings; they have no accounts and do not log in. There is no tenant isolation to advertise. |
| `bootstrap` | Removed | A CSS framework detail, not something anyone searches for to find a fleet manager. |
| `firewall` | Removed | Subsumed by the more specific `firewall-management`. |
| `ssh-tunnel` | Removed | An implementation detail of one optional feature. |
| `self-hosted` | **Added** | Primary differentiator and a heavily browsed topic. |
| `msp` | **Added** | Names the audience the project is positioned for. |
| `fleet-management` | **Added** | Matches how the product is now described. |
| `monitoring` | **Added** | Broad topic that carries real search traffic. |

## Other repository settings

- **Releases** — done. v3.22.0 is tagged and is the latest release. It was the first
  tag since v3.11.1; 3.12 through 3.21 shipped on `main` and exist only in the
  changelog.
- **Social preview image** — `docs/images/github/fleet-dashboard.png` works as-is
  (1440×900, close to GitHub's 1280×640 aspect target; it will be letterboxed).
- **Issue templates** — already present in `.github/ISSUE_TEMPLATE/`.
