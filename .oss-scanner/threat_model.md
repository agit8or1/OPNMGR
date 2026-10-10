# Threat model — OPNManager (OPNMGR)

Read alongside [SECURITY.md](../SECURITY.md), which documents the security architecture in detail. Where this file and
SECURITY.md disagree, the code is the authority and the disagreement is itself worth reporting.

## What this project does

OPNManager is a self-hosted PHP 8.3 / MariaDB web application that an MSP or IT team runs on its own server to manage
a fleet of OPNsense firewalls belonging to the customers it supports. It stores credentials for every managed firewall
and can make each one execute commands as root. A small agent (`plugin/os-opnmanager-agent/`, FreeBSD `sh` plus one
Python helper) runs as root on each firewall and polls the server over HTTPS.

The two facts that drive severity:

1. **The management server is the highest-value target on the network.** Compromising it, or controlling what it
   tells agents, yields root on every managed firewall, which is code execution inside every customer's network.
2. **Anything the server sends an agent is executed as root.** Every server→agent path is a code-execution channel.

Customers are organisational containers only: they have no accounts and no login. The only human users are the
operator's own staff, in three roles defined in `inc/permissions.php`: `admin`, `technician`, `readonly`.

## Architecture

| Layer | Where | Notes |
|---|---|---|
| Web UI (session auth) | `*.php` in the repo root, `ajax/`, most of `api/` | Bootstrap 5 pages. `inc/auth.php` (`requireLogin`, `requireAdmin`), `inc/permissions.php` (`can()`), `inc/csrf.php`. Optional TOTP 2FA (`src/TwoFactorAuth.php`, `verify2fa.php`). |
| Agent API (machine auth) | `agent_checkin.php`, `agent_enroll.php`, `api/enroll.php`, `api/command_result.php`, `api/upload_backup.php`, `api/agent_*`, `api/updates/*`, other endpoints that call `authenticateAgentRequest()` | Authenticated in one place, `inc/agent_auth.php`: `hardware_id` (an identifier, **not a secret**), a 256-bit `api_key`, and optional HMAC-SHA256 request signing with timestamp + nonce. |
| Enrollment | `api/enrollment_key.php` (admin creates a one-time token), `agent_enroll.php` / `api/enroll.php` (agent redeems it) | Tokens are 256-bit random and expire. |
| Command queue | `inc/agent_commands.php` (`queue_firewall_command()`), `api/queue_action.php` (structured catalogue), `api/queue_command.php` (raw shell, admin-only, audited, can be disabled) | Agents fetch queued commands on check-in and run them as root. |
| Agent updates | `inc/release_manifest.php`, `scripts/sign_release.php`, `inc/agent_update.php` | Ed25519-signed manifest plus per-artifact SHA-256, verified on the firewall before install. |
| Remote access | `tunnel_proxy.php`, `firewall_proxy_ondemand.php`, `scripts/manage_ssh_tunnel.php`, `scripts/manage_ssh_access.php` | On-demand SSH tunnels from the server to a firewall's web GUI, using per-firewall SSH keys. Ports 8100–8200. |
| Backups / restore | `api/upload_backup.php`, `api/download_backup.php`, `inc/backup_storage.php`, `inc/config_restore.php` | Firewall `config.xml` files on disk under `backups/`. They contain the firewall's own secrets. |
| Secrets at rest | `inc/crypto.php`, `inc/secrets.php` | XChaCha20-Poly1305, master key in `.env` (`OPNMGR_MASTER_KEY`), never in the DB. Passwords are `password_hash()`ed. |
| AI review (optional, off by default) | `inc/ai_redaction.php`, `api/ai_scan.php` | Firewall configs are redacted before leaving the server. Redaction failure must refuse, never send raw. |
| Cron / CLI | `cron/`, `scripts/` | Guarded from web execution by `inc/cli_guard.php` and by the nginx snippet. |
| Deployment | `deploy/nginx-opnmgr-hardening.conf` | Denies `backups/`, `scripts/`, `cron/`, `inc/`, `src/`, `database/`, `plugin/`, `keys/`, dotfiles, SQL. **Assume it is installed**; the scanner image installs it. |

## Where untrusted input enters

Rank attacker positions from most to least concerning:

1. **Unauthenticated network attacker** reaching the web server (it is often internet-facing, because firewalls check
   in from customer sites). Entry points: login, 2FA verification, enrollment redemption, agent endpoints, anything
   that forgets `requireLogin()` / `authenticateAgentRequest()`, and static paths the nginx snippet should deny.
2. **A compromised or malicious managed firewall / agent.** It controls everything it reports: check-in JSON,
   hostnames, interface names, versions, log excerpts, health telemetry, command results, and uploaded `config.xml`
   backups. All of that is rendered to administrators, stored, parsed (XML), and sometimes fed back into commands.
   One customer's firewall must not be able to affect another firewall, read another's data, or run code on the
   server or in an administrator's browser.
3. **Someone who knows a firewall's `hardware_id`** (derivable from its MAC or SMBIOS UUID) but has no `api_key`. In
   `compatibility` mode, a firewall that has never confirmed a key authenticates on `hardware_id` alone. That bootstrap
   window is documented and intended. Bypassing the ratchet **after** `api_key_confirmed` / `agent_signing_supported`
   is set is a vulnerability.
4. **A low-privilege staff user** (`readonly`, `technician`) trying to do what the role matrix forbids: raw shell,
   restore, user/settings management, reading secrets, deleting firewalls, or touching firewalls outside its scope.
5. **Network attacker between agent and server.** The agent uses HTTPS. Update payloads must still verify against the
   pinned Ed25519 key, so a TLS problem alone must not yield code execution on a firewall.

## Components that matter most

- `inc/agent_auth.php`, `agent_checkin.php`, `agent_enroll.php`, `api/enroll.php`: authentication and the key ratchet.
- Command queue and anything that builds shell text: `inc/agent_commands.php`, `api/queue_action.php`,
  `api/queue_command.php`, `inc/firewall_policy.php`, `inc/config_restore.php`, `scripts/manage_ssh_*.php`.
  Interpolation that reaches a shell without `escapeshellarg()`, or catalogue validation that can be bypassed, is
  root on a firewall.
- The agent itself (`plugin/os-opnmanager-agent/src/opnsense/scripts/OPNsense/OPNManagerAgent/*.sh`): it parses server
  responses as root, and must not execute anything except validated queued commands and verified update artifacts.
- Update integrity: `inc/release_manifest.php`, `scripts/sign_release.php`, and the agent's verify-then-install path.
- Backups and restore: path traversal in upload/download, XML parsing (XXE), cross-firewall access, serving backups
  without auth.
- Session and auth: `inc/auth.php`, `inc/csrf.php`, `inc/brute_force_protection.php`, 2FA, role checks in `can()`.
- Secret handling: anything that returns decrypted secrets, SSH private keys or `.env` contents, or logs them.
- Output encoding of agent-supplied data in the UI (stored XSS from a firewall into an admin session is a fleet takeover).
- Tunnels/proxy: SSRF or open proxy through `tunnel_proxy.php` / `firewall_proxy*.php`, tunnel session tokens.

## Lower priority / out of scope

- `vendor/`, `node_modules/`: third-party. Report only if *our* usage makes them exploitable.
- `docs/`, the many `*.md` notes in the repository root, `development/`, `scripts/capture_demo_screenshots.js`,
  `scripts/record_demo_walkthrough.js`, `scripts/demo_fixture.php`: documentation and demo tooling, not shipped
  behaviour.
- Legacy one-off shell scripts in the repository root (`emergency_*.sh`, `fix_agent.sh`, `debug_*.sh`, `simple_*.sh`,
  `super_simple_agent.sh`, `test_install.sh`): run by hand by an operator. Report only if the server itself serves or
  executes them, or hands them to agents.
- The documented `hardware_id`-only bootstrap window in `compatibility` mode (see 3 above). Bypasses of the ratchet are
  in scope.
- `agent_auth_mode=require_signed` breaking released agents: a known limitation listed in the README.
- Anything that requires an `admin` to attack their own installation (an admin can already run raw shell on every
  firewall by design). Reports in this class are useful only when they cross a boundary an admin does not have, such
  as reading `OPNMGR_MASTER_KEY`, or code execution on the server host as a different user.
- Missing hardening headers, cookie flags on plain-HTTP dev setups, and self-XSS: informational at most.
- Deployments without `deploy/nginx-opnmgr-hardening.conf`: issues that exist only because it is missing are low,
  unless `inc/cli_guard.php` was meant to block the path and does not.

## How to exercise it (in the scanner image)

- `opnmgr-start`: starts MariaDB, php-fpm and nginx. The app is at `http://127.0.0.1:8080/`, and an admin login
  `scanner-admin` / `scanner-admin-password-0001` exists. Create `technician` / `readonly` users from **Users** to test
  role boundaries.
- `opnmgr-start db`, then `php scripts/...`: CLI tools against the same database.
- `opnmgr-test`: runs the regression suites CI runs (`tests/*_test.php`). `tests/bootstrap.php` provides
  `call_endpoint()`, which runs any endpoint in a subprocess with a synthetic request. It is the quickest way to write
  a reproducer without the web server.
- Firewall enrollment: as admin, generate a token through `api/enrollment_key.php`, then POST to `agent_enroll.php`
  with `{token, hardware_id, hostname}`. After that, check-in is `POST /agent_checkin.php` with JSON. See
  `checkin.sh` in the agent for the exact payload and signing.
- No real OPNsense firewall is available. Agent shell scripts can be run under `sh` with stubbed `configctl` / `fetch`
  / `curl`, as `tests/config_restore_test.php` and `tests/agent_request_signing_test.php` do.

## How we rate severity

Rate by what the attacker reaches, not by bug class.

**Critical**
- Unauthenticated remote code execution on the management server.
- Any path from an unauthenticated position, or from a single managed firewall, to command execution on *other*
  firewalls (queueing commands, forging updates, hijacking another firewall's identity).
- Bypassing agent update verification so a firewall installs unsigned or altered code.
- Unauthenticated disclosure of `.env` (master key, release signing key), SSH private keys, or decrypted secrets.
- Authentication bypass to an `admin` session.

**High**
- Authenticated (`readonly` / `technician`) escalation to `admin` capabilities, raw shell, restore, or secrets.
- Stored XSS from agent-supplied data, or from a lower role, that executes in an admin's session. Admin sessions can
  queue root commands, so treat it as fleet compromise unless CSP or encoding demonstrably blocks the payload.
- SQL injection reachable by any authenticated user or by an agent (it reads encrypted secrets and rewrites the queue).
  Unauthenticated SQLi is critical.
- A compromised firewall reading or modifying another firewall's backups, commands, telemetry or credentials.
- Defeating the `api_key` / signing ratchet for a firewall that has already confirmed it.
- Command injection into a shell built by the server, reachable by a `technician`, or by an agent through reported
  data.
- Path traversal / arbitrary file read or write on the server (backups, uploads, downloads).
- XXE or other parser abuse in `config.xml` handling that reads server files.
- CSRF on any state-changing action that queues commands, changes users/roles/settings, or restores configurations.
- SSRF via the tunnel/proxy endpoints to arbitrary hosts.

**Medium**
- CSRF on lower-impact state changes; stored XSS limited to `readonly`-visible pages with no admin exposure.
- Information disclosure of fleet metadata (hostnames, IPs, versions) to unauthenticated users.
- Weaknesses in login rate limiting, 2FA enforcement (`require_mfa_for_admins`), or session lifetime that need
  additional conditions to exploit.
- AI redaction failures that send a secret off-host, when AI review is enabled (it is off by default).
- Audit-log gaps or scrubbing failures that write credential material to `audit_log` or log files.

**Low / informational**
- Denial of service, except a single unauthenticated request that takes the server down persistently (medium).
- Reflected XSS that needs an unusual victim interaction; missing headers; verbose errors without secrets.
- Timing differences without a demonstrated practical attack.

Do not cap severity for lack of a full exploit chain when the primitive is clear and reachable. Do say what was
demonstrated and what was inferred.

## How reports and patches should look

- Name the attacker position (from the list above), the endpoint or file, and what is gained.
- A reproducer as a `tests/*_test.php`-style script using `call_endpoint()`, or a curl sequence against
  `http://127.0.0.1:8080/`, is ideal.
- Patches should match the existing style: prepared statements via `db()`, `escapeshellarg()` for every shell
  interpolation, `htmlspecialchars()` on output, `can('<capability>')` instead of inline role checks,
  `queue_firewall_command()` as the only queue writer, and a regression test added to `tests/`.
