# GitHub repository presentation

Settings that live in GitHub's own UI rather than in the tree. Apply these on the
repository page under **About → ⚙**. Nothing here is applied automatically.

## About description

GitHub allows 350 characters. Use:

> Self-hosted OPNsense fleet management for MSPs and IT teams. Monitor firewall and
> connectivity health, review configuration backups and drift, and roll out updates
> through canary/pilot/production rings — with firewalls grouped by customer and site.

Shorter alternative, if the one above wraps awkwardly in search results:

> Self-hosted OPNsense fleet management for MSPs and IT teams — health monitoring,
> configuration backups and drift, and ringed update rollouts.

## Website

Leave the **Website** field empty.

The project has no dedicated site. `mspreboot.com` resolves (verified, HTTP 200) but
is the maintainer's MSP consulting site, not an OPNManager project page, so putting it
in the Website field would misdirect anyone clicking it expecting documentation. If you
would rather point somewhere, the honest target is the changelog:

    https://github.com/agit8or1/OPNMGR/blob/main/CHANGELOG.md

## Topics

Replace the current topic list with:

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

Changes from what is set today:

| Topic | Action | Why |
|---|---|---|
| `multi-tenant` | **Remove** | Inaccurate and actively misleading. Customers are organisational groupings; they have no accounts and do not log in. There is no tenant isolation to advertise. |
| `bootstrap` | Remove | A CSS framework detail, not something anyone searches for to find a fleet manager. |
| `firewall` | Remove | Subsumed by the more specific `firewall-management`. |
| `ssh-tunnel` | Remove | An implementation detail of one optional feature. |
| `self-hosted` | **Add** | Primary differentiator and a heavily browsed topic. |
| `msp` | **Add** | Names the audience the project is positioned for. |
| `fleet-management` | **Add** | Matches how the product is now described. |
| `monitoring` | **Add** | Broad topic that carries real search traffic. |

## Other repository settings

- **Releases** — the newest tag is `v3.11.1` while `main` is at 3.22.0. Tagging the
  current tree would make the Releases link in the README useful; until then the README
  says plainly that `main` is what to install.
- **Social preview image** — `docs/images/github/fleet-dashboard.png` works as-is
  (1440×900, close to GitHub's 1280×640 aspect target; it will be letterboxed).
- **Issue templates** — already present in `.github/ISSUE_TEMPLATE/`.
