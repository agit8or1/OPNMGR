# README screenshots

The images in this directory are the ones embedded in the project README.

They show the real interface. The data in them is not real: it comes from
`scripts/demo_fixture.php`, which populates a throwaway `*_demo` database with
fictitious customers, sites and simulated telemetry and refuses to run against
anything else. No live firewall is contacted, and no update, reboot or restore is
triggered to produce them.

Every address is from a range reserved for documentation — RFC 5737
(`192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`) and RFC 3849
(`2001:db8::/32`) — and every hostname is under a reserved `.example` domain
(RFC 6761).

## Regenerating

1. Create an isolated database and web root. The schema hardcodes
   `USE opnsense_fw`, so strip that line when importing under another name.
2. Point a throwaway `.env` at it with `DB_NAME` ending in `_demo` and
   `OPNMGR_DEMO=1`, and create an account with `scripts/create_admin.php`.
3. Seed and capture, in that order and without a long gap between them — the
   fixture seeds check-in ages in seconds and the dashboard treats anything
   older than five minutes as offline:

   ```bash
   OPNMGR_DEMO=1 php scripts/demo_fixture.php
   DEMO_PASS='...' node scripts/capture_demo_screenshots.js
   DEMO_PASS='...' node scripts/record_demo_walkthrough.js
   ```

   The recorder writes `walkthrough.mp4`. The README embeds a GIF, which is
   produced from it with the two ffmpeg commands in the recorder's header
   comment (800px wide, 6 fps, 128 colours — legible and about 5 MB).

All captures are 1440×900, dark theme, sidebar pinned.

The captures, and where each is used:

| File | Page | Used in |
|---|---|---|
| `fleet-dashboard.png` | Dashboard | README hero, gallery |
| `firewall-health.png` | Health, focused on one firewall | README tour, gallery |
| `config-drift.png` | Configuration Drift | README tour, gallery |
| `fleet-updates.png` | Fleet Updates | README tour, gallery |
| `incidents.png` | Incidents | README tour, gallery |
| `walkthrough.gif` | Eight-stop tour | README, gallery |
| `fleet-firewalls.png` | Firewall list | gallery |
| `fleet-search.png` | Fleet search | gallery |
| `customers.png` | Customers and sites | gallery |
| `health-overview.png` | Health, fleet-wide | gallery |
| `firewall-detail.png` | Firewall detail, system statistics | gallery |
| `backup-history.png` | Firewall detail, Backups tab | gallery |
| `maintenance.png` | Maintenance windows | gallery |
| `bulk-operations.png` | Bulk operations | gallery |
| `audit-log.png` | Audit log | gallery |
| `users-roles.png` | Staff and roles | gallery |
| `dashboard-light.png` | Dashboard, light theme | gallery |

Two pages are deliberately not captured. `alerts.php` renders a Pushover token
shaped placeholder in an empty input, which reads as a live credential in a
screenshot even though nothing is stored. `alert_history.php` selects
`alert_history.*` but renders `recipient_emails` and `sent_successfully`, neither
of which exists in that table, so every row displays as "Failed / 0 recipient(s)"
on any installation — publishing that would advertise a bug as if it were normal.
