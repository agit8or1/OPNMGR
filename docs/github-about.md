# GitHub repository presentation

Settings that live in GitHub's own UI rather than in the tree, under
**About → ⚙** on the repository page.

> **Applied on 2026-09-15.** The repository carries the description, topics and
> Website setting described below. This file is the record of that intended state,
> not a to-do list — check it if the settings drift, and update it here first if
> you change them.

Nothing here is applied automatically; GitHub settings are not part of the tree.

## About description

GitHub allows 350 characters. Currently set to this (248 characters):

> Self-hosted OPNsense fleet management for MSPs and IT teams. Monitor firewall and
> connectivity health, review configuration backups and drift, and roll out updates
> through canary/pilot/production rings — with firewalls grouped by customer and site.

## Website

The **Website** field is deliberately empty.

The project has no dedicated site of its own. Two candidates were considered:

- `https://mspreboot.com/free-projects` — the author's project index, which does
  list OPNManager among six free tools (verified). It is accurate, but it points
  visitors at a catalogue rather than at this project's own documentation.
- `https://github.com/agit8or1/OPNMGR/blob/main/CHANGELOG.md` — the authoritative
  history.

Leaving the field empty keeps the repository's own README as the landing page,
which serves a visitor better than either. The MSPReboot links in the README and
gallery cover the promotion without hijacking the About link. If a dedicated
product site ever exists, that becomes the primary About URL.

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

- **Releases** — each version is tagged from `main`. The walkthrough MP4 and the
  highlight clip are attached as release assets rather than tracked in git.
- **Social preview image** — `docs/images/github/fleet-dashboard-dark.png` works
  as-is (2880×2000; GitHub will letterbox it to its 1280×640 target).
- **Issue templates** — already present in `.github/ISSUE_TEMPLATE/`.
