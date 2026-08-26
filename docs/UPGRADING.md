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
