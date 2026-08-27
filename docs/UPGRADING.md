# Upgrading OPNManager

## General procedure

```bash
cd /home/administrator/opnsense
git pull
php scripts/migrate.php
sudo /usr/local/sbin/opnmgr-update-wrapper sync
```

Migrations are idempotent and record what they have applied, so re-running is a no-op.
`php scripts/migrate.php --status` shows what is applied and what is pending without
changing anything.

The in-app **System Update** page performs the same steps, including migrations, and takes
a database backup first.

---

## Upgrading to 3.16.0

Migrations 0012 and 0013 add the campaign, bulk operation and restore-job tables, plus
`firewalls.update_ring`. All additive.

Every firewall defaults to the **production** ring. Before running a campaign, move a
small number of low-risk firewalls to canary:

```sql
UPDATE firewalls SET update_ring = 'canary' WHERE hostname IN ('lab-fw-01');
```

Or use the ring selector on the Fleet Updates page.

If you run CARP pairs, confirm OPNMGR has paired them — HA safety depends on it:

```sql
SELECT hostname, carp_state, ha_peer_firewall_id FROM firewalls WHERE carp_enabled = 1;
```

Pairing is resolved from the CARP peer address the agent reports, so it requires agent
1.6.0. Until a pair is linked, both members are treated as standalone and could be
dispatched together.

The restore path changed: `api/download_backup.php` is no longer used by firewalls.
Restores now go through `api/download_restore_config.php`, authenticated as the agent
with a single-use token. No action needed beyond deploying the new code.

---

## Upgrading to 3.15.0

Migration 0011 adds the incident and maintenance-window tables. Additive; nothing existing
changes shape, and the old alert tables are left alone.

Install the evaluator, which both detects conditions and transitions maintenance windows:

```cron
*/5 * * * * /usr/bin/php /var/www/opnsense/cron/evaluate_alerts.php >> /var/log/opnmgr_alerts.log 2>&1
```

Check what it would do before letting it notify anyone:

```bash
php cron/evaluate_alerts.php --dry-run
```

`cron/check_offline_firewalls.php` is superseded by the evaluator and can be removed from
cron once you are satisfied with the new behaviour. Leaving both running is harmless but
will send duplicate offline notifications.

Notification pacing defaults to 60 minutes doubling, four times. Adjust in `settings`:

```sql
UPDATE settings SET value = '30' WHERE name = 'alert_notify_repeat_minutes';
UPDATE settings SET value = '6'  WHERE name = 'alert_notify_max_repeats';
```

---

## Upgrading to 3.14.0

Migrations 0009 and 0010 add the drift and health tables. They are additive; nothing
existing changes shape.

Two things need doing by hand after upgrading:

**Set a baseline per firewall.** Drift cannot report anything until you have declared
which configuration is correct. Go to *Config Drift*, open a firewall and choose a backup
to promote. Firewalls without a baseline show as "No baseline" rather than as drifted.

**Update agents to 1.6.0** for health telemetry. Older agents keep checking in exactly as
before and simply report no health sections; the Health page shows them as "not reporting
health" rather than inventing failures. Queue the update from the firewall's page, or:

```sql
SELECT hostname, agent_version FROM firewalls ORDER BY agent_version;
```

Certificate expiry thresholds default to 30/14/7 days and gateway warning thresholds to
5% loss / 150 ms. Adjust in the `settings` table:

```sql
UPDATE settings SET value = '45' WHERE name = 'cert_warn_days_medium';
```

---

## Upgrading to 3.13.0

Migration 0007 widens the `users.role` enum and maps existing `user` rows to
`technician` — the closest match to what they could already do. Defaulting them to
read-only would silently remove access people currently have. Review roles afterwards:

```sql
SELECT username, role FROM users;
```

Migration 0008 adds `customer_id` / `site_id` to `firewalls` and backfills them from the
old `customer_name` / `customer_group` strings, creating any customer rows that were
implied by a group name but never existed. Both legacy columns are kept and are written
alongside the foreign keys, so pages that still read them keep working.

Nothing here needs manual intervention; `php scripts/migrate.php` covers it.

---

## Upgrading to 3.12.0

3.12.0 introduces encrypted secrets, per-firewall agent credentials and signed release
manifests. None of it disconnects an installed fleet, but there are one-time steps.

### 1. Generate the master key — before anything else

```bash
php scripts/generate_master_key.php
```

This appends `OPNMGR_MASTER_KEY` to `.env`. It never overwrites an existing key.

**Back this value up now.** Encrypted secrets cannot be recovered without it, and changing
it later makes every already-encrypted value unreadable.

If you run separate development and production checkouts, both `.env` files need the
*same* key or the production copy cannot read what development encrypted.

### 2. Apply migrations

```bash
php scripts/migrate.php
```

Adds `schema_migrations`, `audit_log` and `agent_request_nonces`, the agent credential and
backup integrity columns, structured command columns, and tunnel session ownership.

### 3. Encrypt existing secrets

```bash
php scripts/encrypt_secrets.php --dry-run   # see what would change
php scripts/encrypt_secrets.php
```

Encrypts SSH private keys, SMTP and AI credentials and MFA recovery codes in place.
Idempotent — safe on every upgrade. Legacy plaintext is read transparently, so this does
not have to be synchronised with the code deploy.

### 4. Generate the release signing key

```bash
php scripts/sign_release.php --generate-key
php scripts/sign_release.php --publish
```

Required before verified agent updates will run. Without it, `api/trigger_agent_update.php`
returns 503 rather than queueing an unverified update.

### 5. Remove the legacy endpoints

```bash
php scripts/cleanup_legacy_endpoints.php
```

Deletes 14 obsolete web-root endpoints. Most match the gitignored `*_fix.php` pattern, so
a `git pull` will not remove them from a deployed installation.

### 6. Harden the web server

```bash
sudo cp deploy/nginx-opnmgr-hardening.conf /etc/nginx/snippets/opnmgr-hardening.conf
```

Add this inside each OPNManager `server {}` block, after the `root` directive:

```nginx
include /etc/nginx/snippets/opnmgr-hardening.conf;
```

If your vhost has a `location /scripts/ { ... }` alias block, remove it — it serves the
PHP source of the maintenance scripts.

```bash
sudo nginx -t && sudo systemctl reload nginx
```

### 7. Update the privileged wrapper

```bash
sudo cp deploy/opnmgr-update-wrapper /usr/local/sbin/opnmgr-update-wrapper
sudo chmod 750 /usr/local/sbin/opnmgr-update-wrapper
```

The previous version made `.env` world-readable and the backups directory world-writable
on every update.

### 8. Move backups out of the document root

```bash
sudo mkdir -p /var/lib/opnmgr/backups
sudo chown -R www-data:www-data /var/lib/opnmgr
sudo chmod 750 /var/lib/opnmgr/backups
```

Existing backups stay where they are and remain downloadable; only new ones use the new
location.

### 9. Verify

```bash
php tests/security_test.php
php scripts/migrate.php --status
```

---

## After the fleet has updated

Agents adopt their new credentials on their next check-in. Watch progress with:

```sql
SELECT hostname, api_key_confirmed, agent_signing_supported, last_checkin FROM firewalls;
```

Once `api_key_confirmed` is `1` everywhere, tighten the policy:

```sql
UPDATE settings SET value = 'prefer_signed' WHERE name = 'agent_auth_mode';
```

`require_signed` rejects any agent that cannot sign, so only move to it when every agent
shows `agent_signing_supported = 1`.

---

## Rolling back

Migrations are additive — they add columns and tables rather than dropping them — so the
previous release runs against the 3.12.0 schema. To roll the code back:

```bash
git checkout <previous-tag>
sudo /usr/local/sbin/opnmgr-update-wrapper sync
```

Leave `OPNMGR_MASTER_KEY` in `.env`. Removing it would make the encrypted secrets
unreadable; the older code reads the values it wrote before encryption and ignores the
rest.
