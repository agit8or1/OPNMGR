# OPNManager customer probe

A firewall that checks in after an update has shown that its own WAN works. It
has not shown that a workstation behind it can still resolve internal names,
reach the file server across the WireGuard tunnel, or reach the internet. This
probe checks those paths from the customer's side.

`opnmgr_probe.py` is one file, Python 3.8+ standard library only. Run it on any
always-on machine on the customer LAN. Rem0te is optional: if you already
manage the endpoint with it, run the same script from Rem0te with the same
token.

## What it can do

It runs only the checks an OPNManager administrator approved for its token
(**Customer Checks** in the sidebar), at most 10, each with a 5 second timeout:

| Path | Check | Refused unless |
|---|---|---|
| Internal DNS | resolve a hostname, optionally through a named resolver, optionally requiring the answer to be in an address/CIDR | the resolver is a private address |
| Service across VPN | open and close one TCP connection to `host:port` | the host is, or resolves only to, 10/8, 172.16/12, 192.168/16, 100.64/10 or fc00::/7 |
| Internet service | one HTTPS `HEAD` with certificate verification, no redirects followed | the URL is `https://` and the host resolves only to public addresses |

It sends back pass/fail, latency and a one-line reason. It never runs commands
or sends anything else. The manager checks these rules when a check is approved,
and the probe checks them again before running it, so a manager that has been
tampered with still cannot point it anywhere else.

## Run it

Create the probe on **Customer Checks**. The token is shown once.

```sh
OPNMGR_PROBE_TOKEN=opp_... python3 opnmgr_probe.py --manager https://opnmgr.example.com
```

`--once` runs one round and exits, with status 1 if any check failed. Use it for
cron, a Rem0te script, or a first test. `--token-file` reads the token from a
file so it does not show in `ps`.

### systemd

```ini
# /etc/systemd/system/opnmgr-probe.service
[Unit]
Description=OPNManager customer probe
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/usr/bin/python3 /opt/opnmgr-probe/opnmgr_probe.py --manager https://opnmgr.example.com --token-file /etc/opnmgr-probe.token
DynamicUser=yes
ProtectSystem=strict
ProtectHome=yes
NoNewPrivileges=yes
Restart=always
RestartSec=60

[Install]
WantedBy=multi-user.target
```

`chmod 0644 /etc/opnmgr-probe.token`, or use `LoadCredential=` with DynamicUser.

### Windows (Task Scheduler)

Action: `py.exe -3 C:\opnmgr-probe\opnmgr_probe.py --manager https://opnmgr.example.com --token-file C:\opnmgr-probe\token.txt`,
trigger at startup, "run whether user is logged on or not". No elevation is needed.

## How rollouts use it

Customer checks are off unless a campaign enables **Customer path checks** on
**Fleet Updates**. When it does:

- **Before an update**, every required check must have a result from the last
  10 minutes (`customer_check_stale_minutes`). That result is stored as the
  target's baseline. A failing baseline does not hold the update, because the
  update may be the fix. It is recorded so a failure afterwards can be reported
  as "was already failing".
- **After an update**, and once 3 minutes have passed (`customer_check_settle_minutes`),
  every required check must report healthy again. Until it does, the ring is not
  clean and the next ring does not start. A probe that stops reporting holds the
  ring in the same way as a failure. The campaign shows which firewall, probe and
  path failed.
- **CARP pairs** keep their existing order: BACKUP first, and never both at once.
  A probe behind either member covers both. The second member is held until the
  client paths are healthy after the first member's update.
- **Manual progression** still works. An operator can start a held ring by
  giving a reason, and the override is audited.

Informational (non-required) checks are shown and never hold anything.
