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
   ```

All captures are 1440×900, dark theme, sidebar pinned.

| File | Page |
|---|---|
| `fleet-dashboard.png` | Dashboard — hero image |
| `firewall-health.png` | Firewall Health, focused on one firewall |
| `config-drift.png` | Configuration Drift |
| `fleet-updates.png` | Fleet Updates |
| `incidents.png` | Incidents |
