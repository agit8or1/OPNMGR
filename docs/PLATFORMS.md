# Mixed fleets: OPNsense, pfSense CE, pfSense Plus

Since 3.79.0 OPNManager manages OPNsense and pfSense (Community Edition and
Plus) side by side. This page is the reference for what that means in
practice: how a platform is identified, what each platform can and cannot do
from OPNManager, and how much of it has been exercised on real hardware.

> **Validation status (2026-11-06).** OPNsense support is exercised daily on
> two production OPNsense 26.7 firewalls. **No pfSense device — CE or Plus —
> has been available to this project.** Every pfSense operation is
> implemented and covered by fixture/stub tests, and shows as *untested* in the
> product until an administrator records a lab validation. Nothing on this page
> should be read as "live-tested on pfSense".

## 1. Detection

The platform is decided from evidence, recorded with its source and
confidence, and re-checked on every check-in (`inc/platform/platform.php`,
`platform_detect()`):

| Evidence | Says | Confidence |
|---|---|---|
| `opnsense-version` binary present (agent probe) | OPNsense | high |
| `/etc/inc/globals.inc` with `'xml_rootobj' => 'pfsense'` (agent probe) | pfSense | high |
| latest backup's root element `<opnsense>` / `<pfsense>` | that product | high |
| legacy plugin agent (1.x) reporting `opnsense-version` output | OPNsense | medium |
| agent self-declares a family with no marker | that family | medium |
| FreeBSD version | **nothing** — both products run FreeBSD | — |

pfSense edition: `/etc/inc/globals.plus.inc` exists only on Plus; otherwise a
`2.x` version is CE and a `YY.MM` version is Plus; anything else is `unknown`.

If sources disagree the firewall is recorded as unknown with the conflict
text, and is read-only until resolved. A firewall whose platform has not been
verified within `platform_verification_stale_hours` (default 24) has its
supported capabilities shown as **stale** and write operations refused.

## 2. Capability states

| State | Meaning | Write operations |
|---|---|---|
| supported | implemented, and validated (OPNsense baseline or a recorded lab validation for that platform/edition/version prefix) | allowed |
| untested | implemented, never exercised on a live device of that platform/version | **only on designated lab firewalls** |
| unavailable | implemented, but a dependency/probe is missing on this device | refused |
| unsupported | the platform cannot, or OPNManager does not implement it | refused |
| stale | would be supported, but verification is too old | refused |

Pages: **Compatibility** (fleet + implementation matrix) and each firewall's
**Capabilities** page (`firewall_capabilities.php?id=`), where administrators
designate lab devices and record validations with evidence.

## 3. Compatibility matrix

| Capability | OPNsense | pfSense CE | pfSense Plus | Unknown |
|---|---|---|---|---|
| Check-in, inventory, telemetry | supported | untested | untested | read-only |
| Gateways / services / certificates | supported (configctl, Python collector) | untested (pfSense PHP includes: `gwlb.inc`, `service-utils.inc`) | untested | — |
| VPN status (WireGuard / IPsec / OpenVPN) | supported | untested (`wg`, `ipsec_list_sa()`, `openvpn_get_active_*()`) | untested | — |
| CARP / HA detection | supported | untested | untested | — |
| Configuration backup | supported | untested | untested | read-only |
| Configuration interpretation | supported (fixtures from real 26.7 configs) | untested (synthetic fixtures) | untested (synthetic fixtures) | — |
| Ping / traceroute / DNS | supported | untested | untested | read-only |
| Firmware update check | supported | untested (`pfSense-upgrade -c`) | untested | — |
| Service start/stop/restart | supported (`configctl`) | untested (`pfSsh.php playback svc`) | untested | blocked |
| Reboot | supported | untested | untested | blocked |
| Configuration restore | untested (`Config::restoreBackup()`; never executed) | untested (`config_restore()`; needs probe) | untested (needs probe) | blocked |
| Firmware installation | supported (`opnsense-update -bkp`) | **not implemented** | **not implemented** | blocked |
| Agent update | supported (rollout-gated) | untested | untested | blocked |
| Remote GUI / SSH tunnel | supported | **not implemented** (relies on an OPNsense syshook) | **not implemented** | blocked |
| Template preview | supported | untested | untested | — |
| Template application | untested (needs agent 1.8 probe) | untested (needs probe) | untested (needs probe) | blocked |
| Raw shell | supported (admin only) | untested | untested | blocked |

Why pfSense firmware installation is not implemented: `pfSense-upgrade` is
shipped outside the pfSense source tree, so its non-interactive flags could
not be verified from source, and there was no lab device to verify them on.
Requests are closed with that explanation instead of being sent.

## 4. Where OPNsense was assumed, and what replaced it

| Area | Assumption found | Now |
|---|---|---|
| Structured commands | `configctl` for services, gateways, VPN; `opnsense-update` | built by the platform adapter (`inc/platform/adapters.php`); unknown platforms get only base-FreeBSD read-only diagnostics |
| Legacy endpoints | ~20 files insert shell text into `firewall_commands` directly | every queued command passes `platform_delivery_verdict()` at check-in; commands using the other product's tools, or raw shell on unverified/untested platforms, are closed as failed with the reason |
| Firmware updates | check-in answered every update request with `opnsense-update -bkp` | gated on `firmware.install`; refused requests recorded as `unsupported` |
| Agent updates | one OPNsense plugin installer for every agent | pfSense agents get their own installer, gated on `agent.update` |
| Backups | upload accepted only `<opnsense>` | the firewall's own root is required; unverified firewalls accept either and the root feeds detection |
| Restore | `configctl firmware restore` — **an action that does not exist in OPNsense core**, so no restore could ever have worked; no OPNsense/pfSense distinction | platform-native locked install; manager and firewall both refuse the other product's XML |
| Remote tunnel | pf rule via OPNsense syshook | refused on platforms without it, with the reason |
| Health collection | Python + configctl | separate pfSense collector in PHP using pfSense's own includes |
| Agent | MVC plugin, configd actions | separate pfSense agent (below); the plugin is never installed on pfSense |
| `configure_alternate_hostnames()` | OPNsense agent `sed`-edits `config.xml` | unchanged on OPNsense (existing behaviour); the pfSense agent never edits `config.xml` |

## 5. Agents

### OPNsense: `os-opnmanager-agent` plugin (1.8.0)

Unchanged mechanism. 1.8.0 adds a `platform` block to every check-in
(markers, product/FreeBSD/config versions, relevant packages, tool probes,
including whether `Config::restoreBackup()` exists). It is published held:
the rollout gate (`scripts/agent_rollout.php`) must promote it before any
firewall is offered it.

### pfSense: `agent/pfsense/` (1.0.0)

| File on the firewall | Purpose |
|---|---|
| `/usr/local/opnmgr/opnmgr_agent.sh` | check-in loop, credential adoption, request signing, command execution |
| `/usr/local/opnmgr/opnmgr_collect.php` | telemetry + platform probe, using pfSense's PHP includes (read-only) |
| `/usr/local/etc/rc.d/opnmgr_agent.sh` | started at boot; runs the agent under `daemon(8) -r` |
| `/usr/local/etc/opnmgr_agent.conf` | `SERVER_URL`, `VERIFY_SSL`, one-time `ENROLL_TOKEN` (0600) |
| `/usr/local/etc/opnmanager_{hardware_id,api_key,api_secret}` | identity and credentials — same names as the OPNsense agent, so manager-side commands are shared |

Build: `scripts/build_pfsense_agent_package.sh` (deterministic tarball +
`.sha256`, verified by the installer). The installer refuses OPNsense and
anything without pfSense's `globals.inc`, and never edits `config.xml`,
installs packages or touches rules. It does not need Python, configctl or
any package.

Persistence across pfSense upgrades has not been verified on a device.

### Protocol and request signing (both agents)

Reconciled against source, not documentation: both agents adopt
`agent_credentials.api_key`/`api_secret` from a check-in response, then send
`X-OPNMGR-API-Key` and sign every POST with HMAC-SHA256 over
`METHOD\nPATH\nTIMESTAMP\nNONCE\nSHA256HEX(BODY)` — exactly the canonical
string `inc/agent_auth.php` verifies. `tests/agent_request_signing_test.php`
(OPNsense) and `tests/pfsense_agent_test.php` (pfSense) execute the shipped
shell functions and check the output against that computation. (The pfSense
test caught that credentials are nested under `agent_credentials`.) The
plugin README's `configctl opnmanager_agent checkin` and "OPNsense 24.1 or
later" remain accurate for OPNsense; nothing there applies to pfSense.

## 6. Enrollment

**Add Firewall** creates the record and an enrollment key (base64 JSON with
the server URL and a single-use token, 24 h). OPNsense: install the plugin,
paste the key under *Services → OPNManager Agent*. pfSense: run, as root,

    fetch -o - https://<manager>/downloads/pfsense/install_opnmgr_agent_pfsense.sh \
      | env OPNMGR_BASE_URL=https://<manager> OPNMGR_ENROLL_KEY='<key>' sh

The firewall is read-only until its first check-in has reported the platform.

## 7. Privileges and dependencies

| | OPNsense | pfSense |
|---|---|---|
| Runs as | root (plugin service) | root (rc.d, `daemon(8)`) |
| Required | `curl`, `openssl`, Python 3 (base) | `/usr/local/bin/php` (base), `openssl`, `curl` (base) |
| Optional | `wg`, `swanctl` for VPN detail | WireGuard package, `swanctl` |
| Manager | PHP 8, MariaDB 10.6+ | same |

## 8. Netgate Nexus and third-party APIs

Investigated 2026-11-06 against Netgate's documentation:

- **Nexus** is Netgate's multi-instance management controller for **pfSense
  Plus only** (docs say 25.07+, the product page says 25.11+ — they disagree),
  licensed separately, with managed devices reached over a Nexus zero-trust
  VPN. The REST API is described by an OpenAPI schema (github.com/Netgate/pfsense-api)
  but its authentication flow is not publicly documented, and it is not
  available on CE. With no Plus device or Nexus licence available, OPNManager
  **does not use Nexus**; the pfSense agent only reports whether a Nexus
  package is installed (`netgate_nexus_pkg` probe).
- **pfSense-pkg-RESTAPI** (pfrest / jaredhendrickson13) is a **third-party**
  package. It is not a dependency of anything here. If added later it must be
  an optional adapter, labelled third-party.

## 9. Lab validation procedure

1. Enroll a non-production device; confirm its Capabilities page shows the
   expected platform with high confidence.
2. Designate it as a lab device (Capabilities page, administrators).
3. Exercise one capability, verify the result **on the firewall itself**.
4. Record the validation with what was run and observed; it applies to that
   platform/edition and version prefix.

Record here, for each validation: platform, edition, exact version, agent
build, operation, result. *(None recorded yet.)*
