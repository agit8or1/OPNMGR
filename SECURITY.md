# Security

## Reporting a vulnerability

Please report security issues through
[GitHub Private Vulnerability Reporting](https://github.com/agit8or1/OPNMGR/security/advisories/new)
rather than opening a public issue.

Include the affected version, what an attacker gains, and the smallest set of steps that
reproduces it. You will get an acknowledgement within a few days.

Supported for security fixes: the current minor release (3.12.x).

---

## Threat model

OPNManager is self-hosted software that an MSP runs on its own server to manage the
OPNsense firewalls of the customers it supports. It holds credentials for, and can execute
commands on, every firewall in the fleet. Two consequences shape the design:

1. **The management server is the highest-value target on the network.** Compromising it
   yields root on every managed firewall. The application therefore assumes its own
   endpoints will be probed and its database may be read.
2. **Agents run as root on customer firewalls.** Anything the server tells an agent to do
   is executed with full privileges, so every server-to-agent instruction path is treated
   as a code-execution channel and authenticated accordingly.

Customers are organisational containers. They have no accounts, no portal and no login.
There is no reseller tier, downstream tenant or per-firewall licensing.

---

## Agent authentication

Agent requests carry up to three credentials, checked in one place
(`inc/agent_auth.php`, `authenticateAgentRequest()`), which every agent-facing endpoint
calls instead of comparing credentials itself.

| | Credential | What it is | Protects against |
|---|---|---|---|
| 1 | `hardware_id` | Device fingerprint: md5 of hostid, SMBIOS UUID or WAN MAC | Nothing on its own — it is an **identifier, not a secret**. Anyone who can observe the firewall's MAC can derive it. |
| 2 | `api_key` | 256-bit server-issued bearer secret, encrypted at rest | Impersonation by anyone who merely knows the device |
| 3 | HMAC-SHA256 signature | Per-request signature over method, path, timestamp, nonce and SHA-256 of the body | Replay, and theft of the bearer token |

### Signature format

```
X-OPNMGR-Timestamp: <unix seconds>
X-OPNMGR-Nonce:     <8-128 chars, [A-Za-z0-9_-]>
X-OPNMGR-Signature: <hex HMAC-SHA256>
```

The signed string is newline-separated:

```
METHOD \n PATH \n TIMESTAMP \n NONCE \n sha256hex(raw body)
```

keyed with the firewall's `agent_api_secret`. The server rejects a stale timestamp
(default window 300s, configurable), a reused nonce, or a signature that does not verify.
Comparison is constant-time. **A signature that is present but invalid is always fatal**,
in every mode — downgrading to unsigned on a bad signature would make signing pointless.

### Upgrade path — why an installed fleet does not break

Requiring new credentials from agents that do not have them yet would take a fleet
offline. The upgrade is instead per-firewall and self-closing:

1. A firewall with no `api_key` has one generated and returned in its check-in response.
   Until the agent presents it, `hardware_id` alone still authenticates — exactly the
   pre-upgrade behaviour, so there is no regression and no flag day.
2. The first time the agent presents the correct key, `api_key_confirmed` is set.
   **From that moment authentication fails closed for that firewall**: the key is
   mandatory and a downgrade attempt is rejected.
3. The same ratchet applies to signatures via `agent_signing_supported`.

The bootstrap window is the weak point, and it is deliberately the same strength as the
system it replaces. Close it early by setting `agent_auth_mode` to `require_signed`, or
watch it close on its own as agents update.

### Modes

Set `agent_auth_mode` in the settings table:

| Mode | Behaviour |
|---|---|
| `compatibility` | Signatures verified when present, never required. Default for upgrades. |
| `prefer_signed` | As above, but required once an agent has proven it can sign. |
| `require_signed` | Required from every agent, no exceptions. |

Existing installations default to `compatibility`. Move to `prefer_signed` once
`SELECT hostname, api_key_confirmed FROM firewalls` shows `1` everywhere.

---

## Secrets at rest

Values the application must read back are encrypted with XChaCha20-Poly1305
(libsodium AEAD, random 24-byte nonce per value) and stored as
`enc:v1:<base64(nonce||ciphertext)>`.

Encrypted: agent API keys and signing secrets, firewall SSH private keys, SMTP
credentials, AI provider keys, webhook and integration credentials, MFA secrets and
recovery codes.

**Not encrypted:** user passwords. Those are hashed with `password_hash()` and verified
with `password_verify()`, and are rehashed on login when the algorithm is outdated.
Encryption is only for values that must be recovered.

The master key lives in `.env` as `OPNMGR_MASTER_KEY`, never in the database, so a
database-only compromise — SQL injection, a stolen dump, a replica — does not yield
plaintext secrets.

```bash
php scripts/generate_master_key.php   # generate; appends to .env, never overwrites
php scripts/encrypt_secrets.php       # backfill existing plaintext; idempotent
```

Back the key up. Encrypted values cannot be recovered without it, and changing it makes
every already-encrypted value unreadable. Legacy plaintext is read transparently, so the
backfill does not have to be synchronised with a code deploy.

---

## Remote command execution

OPNManager needs to run things on managed firewalls. That capability is split rather than
removed.

**Structured actions** (`api/queue_action.php`) — a fixed catalogue addressed by action id
with typed, validated parameters. The shell text is built server-side from a template and
every interpolation goes through `escapeshellarg()`. Unknown actions, unexpected
parameters, out-of-range values and services outside the allow-list are **rejected, not
escaped**. Covers reboot, service start/stop/restart/status, update check and install,
ping, traceroute, DNS lookup, gateway diagnostics, VPN status and restart, and agent
control.

**Raw shell** (`api/queue_command.php`) — still available, because this is an MSP
administrator tool. It is administrator-only, can be disabled fleet-wide with
`raw_command_enabled`, requires an explicit confirmation flag, and always records the
command text, the user, the source IP, the target firewall and the timestamp in the audit
log before the agent ever sees it.

`queue_firewall_command()` is the only path that inserts into `firewall_commands`, so a
command cannot be attributed to the wrong firewall or bypass the audit trail.

---

## Agent update integrity

Agent updates are fetched by a firewall and executed as root, so the payload is verified
before it runs:

1. The operator publishes a manifest listing each artifact with its SHA-256.
2. The manifest is signed with an Ed25519 key whose secret half lives in `.env`
   (`OPNMGR_RELEASE_SIGNING_KEY`).
3. The agent fetches the manifest over HTTPS, verifies the signature against a pinned
   public key, then verifies the artifact's SHA-256 against the manifest.
4. Installation is atomic, keeps the previous package, verifies the resulting version and
   that the agent starts, and rolls back if either check fails.

Every verification failure is fatal. There is no fallback to installing unverified bytes.

```bash
php scripts/sign_release.php --generate-key   # one-time
php scripts/sign_release.php --publish        # after changing artifacts
php scripts/sign_release.php --verify
```

Where a firewall's `openssl` cannot verify Ed25519, the installer says so explicitly and
relies on HTTPS plus the server-pinned SHA-256 — it never silently claims to have verified
a signature it could not check.

---

## Roles

Staff roles are defined once in `inc/permissions.php` as a capability-to-roles matrix.
Code asks `can('backup.restore')` rather than testing role strings inline. An unknown
capability denies and is logged; a corrupted session role falls back to `readonly`.

| Role | Intended for | Cannot |
|---|---|---|
| **Administrator** | Application owners | — |
| **Technician** | Day-to-day fleet operations: monitoring, diagnostics, approved commands, backups, updates | Manage users, settings, secrets or integrations; run raw shell; restore configurations; delete firewalls |
| **Read Only** | Reporting and oversight | Change anything |

Structured commands derive their required capability from the catalogue's risk level, so
a technician can restart a service but not reboot a firewall or stop services.

---

## Sessions

- Session id regenerated on login and rotated every 15 minutes
- `HttpOnly`, `SameSite=Lax`, and `Secure` when the request is actually HTTPS
- `use_only_cookies` and `use_trans_sid` pinned, so a session id never appears in a URL
- Configurable idle timeout and a separate absolute lifetime, so a session cannot be kept
  alive indefinitely by activity
- Bound to the user agent it was issued to. Deliberately **not** bound to IP, which breaks
  mobile and multi-WAN clients for no real gain
- Login is rate-limited and lockout-protected, and carries a CSRF token
- CSRF tokens on state-changing browser requests

---

## Deployment hardening

Both are required; neither is sufficient alone.

**Web server.** `deploy/nginx-opnmgr-hardening.conf` denies the data and non-entry-point
directories (`backups/`, `scripts/`, `cron/`, `inc/`, `src/`, `database/`, `plugin/`,
`keys/`), dotfiles, editor leftovers and raw SQL, and sets the standard response headers.
Every rule uses the `^~` prefix form so it wins against the catch-all PHP regex regardless
of include order.

Without it, `scripts/` and `cron/` live inside the document root and are matched by
`location ~ \.php$`.

**Application.** `inc/cli_guard.php` refuses any request whose entry point is a
maintenance script, applied across `scripts/` and `cron/`. It only rejects direct
execution, so files that web pages legitimately `require_once` keep working.

---

## Audit log

`audit_log` records the timestamp, actor, source IP, action, object, target firewall,
success and metadata for authentication, user and MFA changes, enrollment, commands,
restores, updates, tunnels, settings changes and bulk operations.

Credential material is stripped centrally in `audit_scrub_metadata()` rather than being
left to each call site — keys matching `password`, `secret`, `token`, `key`, `hardware_id`
and similar are replaced with `[redacted]` before the row is written.

---

## What to do after installing 3.12.0

```bash
php scripts/generate_master_key.php     # back up the key it prints
php scripts/sign_release.php --generate-key
php scripts/migrate.php
php scripts/encrypt_secrets.php
php scripts/cleanup_legacy_endpoints.php
php tests/security_test.php
```

Then install the nginx snippet, and once the fleet has adopted its keys, move
`agent_auth_mode` to `prefer_signed`.
