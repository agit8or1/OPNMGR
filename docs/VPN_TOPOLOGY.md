# VPN Topology and Troubleshooting

Pages: `vpn_topology.php` (map, table, findings), `vpn_diagnostics.php` (findings + guided probes).
Code: `inc/vpn/`. Migration: `database/migrations/0026_vpn_topology.sql`. Cron: `cron/vpn_topology_refresh.php`.
Tests: `tests/vpn_topology_test.php`, `tests/vpn_probe_test.php`.

Permissions: `vpn.view` (see everything in scope), `vpn.diagnose` (run probes), `vpn.manage` (pair, un-pair, classify).
Staff customer scoping applies to every view and endpoint. Nothing here creates, edits, restarts or repairs a tunnel.

## What is collected

| Item | Source | Label shown |
|---|---|---|
| Tunnel/peer identifiers, endpoints, interfaces, protected networks, AllowedIPs, IPsec selectors, OpenVPN networks, enabled/disabled, routes, NAT, rules | Latest readable config backup, parsed by the platform layer (`vpn_config_cache` keys it to the backup it came from) | config snapshot |
| Handshake time, byte counters, SA / session state | `firewall_vpn_tunnels` from agent check-ins | runtime telemetry |
| When counters last moved | `vpn_activity`, written by the cron job | runtime telemetry |

Every tunnel carries both timestamps (configuration time and telemetry time). Private keys and pre-shared keys never enter the
model (the normalizer copies allow-listed fields; `vpn_scrub()` drops secret-shaped keys again before caching; the browser payload is
built from an allow-list). Only `has_psk` (a boolean) survives.

## Model

An **endpoint** is one managed firewall's side of one tunnel. A **tunnel** is a pair of endpoints (both managed) or a single endpoint
(the far side is external, unknown, ambiguous, or a remote-access service). Protocol-specific data stays under `details`.

Kinds: `site_to_site`, `remote_access`, `external` (no managed endpoint matches the remote identity), `ambiguous` (candidates exist
but evidence is not decisive), `unknown` (no remote identity at all, or seen only in telemetry).
Role inference: WireGuard host-only AllowedIPs without an endpoint = roaming client; a network in AllowedIPs = site link; IPsec
mobile / no fixed gateway = remote access; OpenVPN `p2p*` or remote networks = site link. Operators can override.

### Relationship (how a link is known)

* **observed**: configuration identifiers match and an endpoint's telemetry shows a handshake or established SA. Solid line.
* **inferred**: matched from configuration alone. Dashed line.
* **manual**: an operator paired it. Heavy dash-dot line.
* Partial: when only one end is managed the tunnel carries that end's state and says the far side was not observed.

### Matching (score, threshold 60, margin 20)

* WireGuard: public keys in both directions 100, one direction 60, endpoint IP+port 30 per direction (IP alone 10), mirrored tunnel IPs
  30. If both sides know their keys and none correspond the score is capped at 20.
* IPsec: mutual remote gateways 60 when each address belongs to exactly one managed firewall, 40 when an address is shared (NAT),
  25 one way; IKE identities mutual 40; mirrored phase 2 selectors 50 (partial 25).
* OpenVPN: client target = server address:port 35, same tunnel network 30, mirrored networks 25.
* A shared public IP can never pair endpoints by itself. Ties, or a best candidate that is not the other side's best, are left
  ambiguous with the candidates listed.
* Operator decisions (`vpn_peer_pairings`) are applied first: pair (exclusive), un-pair (forbids only that match), classify.

## State rules

States: up, degraded, idle, down, disabled, unknown, stale.

1. Disabled in configuration: disabled.
2. No telemetry: **unknown** (never guessed).
3. Telemetry older than `vpn_stale_minutes` (default 30): **stale**, whatever it said.
4. WireGuard (connectionless; role decides):
   * handshake <= 180 s: up
   * remote-access: counters moved in the last 5 min, or an approved probe reached it: up; otherwise **idle**, including never
     connected and silent for weeks. A failed probe does not make a roaming user down.
   * site-to-site: never handshaken and zero counters: **down**; counters moving but no handshake: degraded; quiet inside the idle
     window: idle (degraded if persistent keepalive <= 60 s is configured, since it should refresh the handshake); silent past the
     window: down.
5. IPsec / OpenVPN: the agent's status; a remote-access service with nothing connected is idle.
6. A fresh failed probe turns an up/idle site-to-site tunnel into degraded (with the firewall-origin caveat).
7. Two ends: down+healthy = degraded (one-sided); unknown does not vote.

Freshness uses the firewall's last check-in as the time the telemetry rows were last true (the agent replaces them wholesale each
check-in), because `updated_at` does not change when the values do not.

## Findings

Subnet overlap (only inside a routing domain: firewalls joined by a matched tunnel; 1:1 NAT / phase 2 NAT downgrades it; a tunnel
joining customers is called out separately; a remote network overlapping the firewall's own LAN is flagged per firewall), static
route conflicts / missing / not in AllowedIPs, WireGuard AllowedIPs mismatch (both managed), IPsec selector mismatch, apparent
asymmetric routing, outbound NAT on tunnel traffic with no earlier no-NAT rule, filter rules on the tunnel interface, possible
MTU/MSS (low confidence), one-sided configuration or establishment, missing configuration snapshots.

Each finding records evidence, per-firewall freshness, affected firewalls and tunnels, basis (observed / configured / inferred),
confidence, limitation, and the next diagnostic (with a prefilled probe where one makes sense).

## Guided probes

Technician chooses source firewall, destination IP or subnet, protocol (icmp, tcp, udp, traceroute), port, optional tunnel and optional
payload size (ICMP, don't-fragment). `vpn_probe_run()`:

1. validates every parameter (literal IPv4 only, port 1-65535, size 0-1472, no loopback/multicast/reserved, no names);
2. checks customer scope and the `vpn.diagnostics` platform capability for every leg;
3. picks the **source address**: the firewall's own address on the protected network (IPsec: inside the phase 2 local selector, else
   refuse), bound with `ping -S` / `nc -s` / `traceroute -s`; the page shows address, interface and reason before you run;
4. rate-limits: `vpn_probe_rate_user_per_hour` (30), `vpn_probe_rate_firewall_per_hour` (20) and at most 3 probes waiting per firewall;
5. when both ends are managed and requested, adds a reverse leg from the far firewall to the first leg's source address;
6. queues each leg through `build_structured_command('vpn_probe', …)` + `queue_firewall_command()` (structured, LOW risk, audited) and
   stores it in `vpn_diagnostic_runs`, optionally linked to an incident.

**A probe runs on the firewall. A successful result does not prove a LAN client's policy path works** (LAN rules, NAT and policy routing
that client traffic meets are bypassed), and a failure does not prove a client cannot connect. The UI states this on every probe and result.
A hypothetical LAN-client policy preview (`vpn_policy_eval`) accompanies the plan; it is approximate and reports `uncertain` when
aliases cannot be resolved.

## Operations

* Crontab: `*/5 * * * * php /var/www/opnsense/cron/vpn_topology_refresh.php >> /var/log/opnmgr/vpn_topology_refresh.log 2>&1`
  (registered as `vpn_topology_refresh`).
* Settings: `vpn_stale_minutes`, `vpn_probe_rate_user_per_hour`, `vpn_probe_rate_firewall_per_hour`.
* `database/schema.sql` must be regenerated (`scripts/generate_schema.sh`) when 0026 ships.

## Limitations

IPv4 only. Live routing tables, dynamic routing and policy routing are not visible. WireGuard/OpenVPN protected networks are inferred
from interface addresses. Agent IPsec/OpenVPN telemetry name formats are matched heuristically (description, ikeid, vpnid); unmatched
rows appear as runtime-only endpoints. pfSense behaviour comes from synthetic fixtures; nothing has been verified on a live device.
