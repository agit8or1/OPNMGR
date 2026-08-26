#!/usr/local/bin/python3
"""
OPNManager agent - OPNsense health collector.

Emits a single JSON object on stdout describing gateways, VPN tunnels, CARP
state, services and certificate metadata, for the agent to include in its
check-in payload:

    {"gateways": [...], "vpn": [...], "carp": {...},
     "services": [...], "certificates": [...]}

Design rules:

* Every collector is independent and wrapped. A firewall without WireGuard, or
  with a configctl that behaves differently on its OPNsense release, produces an
  empty list for that section instead of failing the whole check-in.
* A section is omitted entirely when it could not be determined, and present but
  empty when it was determined that none exist. The server treats those
  differently: absent means "unknown", empty means "none here".
* Certificate PRIVATE KEYS ARE NEVER READ. Only the <crt> public certificate is
  parsed, for its dates, issuer and subject. The <prv> element is not touched.
"""

import base64
import json
import os
import re
import shutil
import subprocess
import sys
import xml.etree.ElementTree as ET
from datetime import datetime, timezone

CONFIG_XML = "/conf/config.xml"
TIMEOUT = 10


def run(cmd, timeout=TIMEOUT):
    """Run a command, returning stdout or None. Never raises."""
    try:
        result = subprocess.run(
            cmd, capture_output=True, text=True, timeout=timeout, check=False
        )
        if result.returncode != 0 and not result.stdout.strip():
            return None
        return result.stdout
    except Exception:
        return None


def have(binary):
    """Whether an executable is available."""
    return shutil.which(binary) is not None


def configctl(*args):
    """Call configctl and parse JSON if possible."""
    path = "/usr/local/sbin/configctl"
    if not os.path.exists(path):
        return None
    out = run([path] + list(args))
    if not out:
        return None
    try:
        return json.loads(out)
    except Exception:
        return out


# ---------------------------------------------------------------------------
# Gateways
# ---------------------------------------------------------------------------
def collect_gateways():
    data = configctl("interface", "gateways", "status")
    if data is None:
        return None

    rows = []
    items = []
    if isinstance(data, dict):
        items = data.get("items") or data.get("gateways") or []
        if isinstance(items, dict):
            items = list(items.values())
    elif isinstance(data, list):
        items = data

    for item in items:
        if not isinstance(item, dict):
            continue

        def num(value):
            """'12.3ms' / '0.0 %' / '' -> float or None."""
            if value is None:
                return None
            match = re.search(r"[-+]?\d*\.?\d+", str(value))
            return float(match.group(0)) if match else None

        name = item.get("name") or item.get("gateway_name")
        if not name:
            continue

        rows.append({
            "name": str(name)[:64],
            "interface": str(item.get("interface") or "")[:64] or None,
            "address": str(item.get("address") or item.get("gateway") or "")[:45] or None,
            "monitor": str(item.get("monitor") or "")[:45] or None,
            "status": str(item.get("status_translated") or item.get("status") or "unknown")[:32].lower(),
            "latency_ms": num(item.get("delay")),
            "stddev_ms": num(item.get("stddev")),
            "loss_percent": num(item.get("loss")),
            "is_default": bool(item.get("defaultgw") or item.get("is_default")),
            "priority": item.get("priority") if isinstance(item.get("priority"), int) else None,
        })

    return rows


# ---------------------------------------------------------------------------
# VPN
# ---------------------------------------------------------------------------
def collect_wireguard():
    """`wg show all dump` - tab separated, one line per peer."""
    if not have("wg"):
        return []

    out = run(["wg", "show", "all", "dump"])
    if not out:
        return []

    tunnels = []
    for line in out.splitlines():
        parts = line.split("\t")
        # Interface line has 5 fields, peer lines have 9.
        if len(parts) < 9:
            continue

        iface, pubkey, _psk, endpoint, _allowed, handshake, rx, tx, _keepalive = parts[:9]

        try:
            handshake_ts = int(handshake)
        except ValueError:
            handshake_ts = 0

        # WireGuard is connectionless; "up" means a recent handshake.
        status = "up" if handshake_ts and (
            datetime.now(timezone.utc).timestamp() - handshake_ts
        ) < 180 else "down"

        tunnels.append({
            "type": "wireguard",
            "name": f"{iface}:{pubkey[:12]}",
            # The peer PUBLIC key, truncated. Never a private key.
            "peer": pubkey[:44],
            "endpoint": endpoint if endpoint != "(none)" else None,
            "status": status,
            "latest_handshake": handshake_ts or None,
            "rx_bytes": int(rx) if rx.isdigit() else None,
            "tx_bytes": int(tx) if tx.isdigit() else None,
        })

    return tunnels


def collect_openvpn():
    data = configctl("openvpn", "connections")
    tunnels = []

    if isinstance(data, dict):
        for key, entry in data.items():
            if not isinstance(entry, dict):
                continue
            tunnels.append({
                "type": "openvpn",
                "name": str(entry.get("description") or key)[:128],
                "status": "up" if str(entry.get("status", "")).lower() in ("connected", "up", "ok") else "down",
                "peer": str(entry.get("real_address") or "")[:255] or None,
                "endpoint": str(entry.get("virtual_address") or "")[:255] or None,
                "rx_bytes": entry.get("bytes_received") if isinstance(entry.get("bytes_received"), int) else None,
                "tx_bytes": entry.get("bytes_sent") if isinstance(entry.get("bytes_sent"), int) else None,
            })

    return tunnels


def collect_ipsec():
    tunnels = []

    if have("swanctl"):
        out = run(["swanctl", "--list-sas", "--raw"])
        if out:
            for match in re.finditer(r"^(\S+):", out, re.M):
                name = match.group(1)
                tunnels.append({
                    "type": "ipsec",
                    "name": name[:128],
                    "status": "up" if "ESTABLISHED" in out else "down",
                })
            if tunnels:
                return tunnels

    data = configctl("ipsec", "status")
    if isinstance(data, dict):
        for key, entry in data.items():
            if not isinstance(entry, dict):
                continue
            tunnels.append({
                "type": "ipsec",
                "name": str(key)[:128],
                "status": "up" if str(entry.get("status", "")).lower() in ("established", "up") else "down",
            })

    return tunnels


def collect_vpn():
    tunnels = []
    for collector in (collect_wireguard, collect_openvpn, collect_ipsec):
        try:
            tunnels.extend(collector() or [])
        except Exception:
            continue
    return tunnels


# ---------------------------------------------------------------------------
# CARP / HA
# ---------------------------------------------------------------------------
def collect_carp():
    out = run(["ifconfig"])
    if not out:
        return None

    vhids = []
    current_iface = None

    for line in out.splitlines():
        if line and not line[0].isspace():
            current_iface = line.split(":")[0]
            continue

        # carp: MASTER vhid 1 advbase 1 advskew 0
        match = re.search(
            r"carp:\s+(\w+)\s+vhid\s+(\d+)(?:\s+advbase\s+(\d+))?(?:\s+advskew\s+(\d+))?",
            line,
        )
        if match:
            vhids.append({
                "vhid": match.group(2),
                "state": match.group(1).upper(),
                "interface": current_iface,
                "advbase": int(match.group(3)) if match.group(3) else None,
                "advskew": int(match.group(4)) if match.group(4) else None,
            })

    carp = {"vhids": vhids}

    # HA sync peer from config.xml, if configured.
    try:
        tree = ET.parse(CONFIG_XML)
        node = tree.getroot().find("./hasync")
        if node is not None:
            peer = node.findtext("synchronizetoip") or ""
            if peer.strip():
                carp["peer_host"] = peer.strip()[:255]
    except Exception:
        pass

    return carp


# ---------------------------------------------------------------------------
# Services
# ---------------------------------------------------------------------------
# Only services that are meaningful to an MSP. A service that is not installed
# on this firewall is omitted, not reported as stopped.
SERVICE_CANDIDATES = [
    ("unbound", "DNS resolver"),
    ("dnsmasq", "DNS forwarder"),
    ("dhcpd", "DHCPv4 server"),
    ("kea-dhcp4", "Kea DHCPv4"),
    ("openvpn", "OpenVPN"),
    ("strongswan", "IPsec"),
    ("wireguard", "WireGuard"),
    ("nginx", "Web GUI"),
    ("lighttpd", "Web GUI"),
    ("openssh", "SSH"),
    ("ntpd", "NTP"),
    ("chronyd", "NTP"),
    ("syslog-ng", "Syslog"),
    ("cron", "Cron"),
    ("configd", "OPNsense configd"),
    ("suricata", "IDS/IPS"),
    ("haproxy", "HAProxy"),
    ("squid", "Proxy"),
    ("radvd", "Router advertisements"),
    ("pf", "Packet filter"),
]


def collect_services():
    services = []

    for name, description in SERVICE_CANDIDATES:
        rc_script = f"/usr/local/etc/rc.d/{name}"
        base_script = f"/etc/rc.d/{name}"

        installed = os.path.exists(rc_script) or os.path.exists(base_script)

        # pf is built into the kernel rather than an rc.d service.
        if name == "pf":
            out = run(["pfctl", "-s", "info"]) if have("pfctl") else None
            if out is None:
                continue
            services.append({
                "name": name,
                "description": description,
                "running": "Status: Enabled" in out,
                "enabled": True,
            })
            continue

        if not installed:
            continue

        script = rc_script if os.path.exists(rc_script) else base_script
        out = run([script, "status"])
        running = bool(out and re.search(r"\bis running\b", out))

        if not running:
            # Some rc scripts are quiet; fall back to a process check.
            pgrep = run(["pgrep", "-x", name])
            running = bool(pgrep and pgrep.strip())

        services.append({
            "name": name,
            "description": description,
            "running": running,
            "enabled": True,
        })

    return services


# ---------------------------------------------------------------------------
# Certificates
# ---------------------------------------------------------------------------
def parse_certificate(pem_bytes):
    """
    Extract dates, issuer and subject from a PEM certificate using openssl.

    Only ever called with the PUBLIC certificate body.
    """
    if not have("openssl"):
        return None

    try:
        result = subprocess.run(
            ["openssl", "x509", "-noout", "-subject", "-issuer", "-startdate", "-enddate"],
            input=pem_bytes, capture_output=True, timeout=TIMEOUT, check=False,
        )
        text = result.stdout.decode("utf-8", "replace")
    except Exception:
        return None

    def field(prefix):
        match = re.search(rf"^{prefix}=(.*)$", text, re.M)
        return match.group(1).strip() if match else None

    def date(prefix):
        raw = field(prefix)
        if not raw:
            return None
        try:
            return int(datetime.strptime(raw, "%b %d %H:%M:%S %Y %Z")
                       .replace(tzinfo=timezone.utc).timestamp())
        except Exception:
            return None

    return {
        "subject": field("subject"),
        "issuer": field("issuer"),
        "not_before": date("notBefore"),
        "not_after": date("notAfter"),
    }


def collect_certificates():
    if not os.path.exists(CONFIG_XML):
        return None

    try:
        tree = ET.parse(CONFIG_XML)
    except Exception:
        return None

    certificates = []

    for tag, cert_type in (("cert", "certificate"), ("ca", "ca")):
        for node in tree.getroot().findall(f"./{tag}"):
            refid = node.findtext("refid") or ""
            if not refid:
                continue

            # PUBLIC certificate only. The sibling <prv> element holds the
            # private key and is deliberately never read.
            body = node.findtext("crt") or ""
            parsed = None
            if body.strip():
                try:
                    parsed = parse_certificate(base64.b64decode(body))
                except Exception:
                    parsed = None

            entry = {
                "refid": refid[:64],
                "name": (node.findtext("descr") or "")[:255] or None,
                "type": cert_type,
            }
            if parsed:
                entry.update({
                    "issuer": (parsed["issuer"] or "")[:255] or None,
                    "subject": (parsed["subject"] or "")[:255] or None,
                    "not_before": parsed["not_before"],
                    "not_after": parsed["not_after"],
                })
            certificates.append(entry)

    return certificates


# ---------------------------------------------------------------------------
def main():
    health = {}

    for key, collector in (
        ("gateways", collect_gateways),
        ("vpn", collect_vpn),
        ("carp", collect_carp),
        ("services", collect_services),
        ("certificates", collect_certificates),
    ):
        try:
            value = collector()
        except Exception:
            value = None
        # None means "could not determine" and is omitted, so the server leaves
        # the previous state alone rather than concluding everything vanished.
        if value is not None:
            health[key] = value

    json.dump(health, sys.stdout, separators=(",", ":"))
    sys.stdout.write("\n")


if __name__ == "__main__":
    main()
