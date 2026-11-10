#!/usr/bin/env python3
"""OPNManager customer probe.

Runs on a workstation or small VM on a customer network and reports whether the
paths customers actually use still work: internal DNS, a service across the
VPN, and an internet service. OPNManager uses the results to hold an update
ring (and the second member of a CARP pair) when a client path breaks.

Deliberately narrow. It can do exactly three things, each with a 5 second
timeout, and only for checks the manager administrator approved:

  dns               resolve a hostname, optionally via a named internal resolver
  vpn_service       open (and close) a TCP connection to a private address
  internet_service  make one HTTPS HEAD request, certificate verified, to a
                    public host

It never runs commands, never sends anything but pass/fail, latency and a
short reason, and refuses targets outside those address ranges even if the
manager asks. Python 3.8+ standard library only; no install step.

  OPNMGR_PROBE_TOKEN=opp_... python3 opnmgr_probe.py --manager https://opnmgr.example.com
  python3 opnmgr_probe.py --manager https://opnmgr.example.com --token-file /etc/opnmgr-probe.token
  ... --once        run one round and exit (cron, Rem0te script, testing)

Rem0te can run this file as a script on a managed endpoint with the same
token; nothing here depends on Rem0te.
"""

import argparse
import ipaddress
import json
import os
import random
import socket
import ssl
import struct
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

VERSION = "1.0.0"
TIMEOUT = 5.0
MAX_CHECKS = 10
DNS_PORT = 53  # tests point this at a local fake resolver

INTERNAL_NETS = [ipaddress.ip_network(n) for n in
                 ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "100.64.0.0/10", "fc00::/7")]


def is_internal(addr):
    ip = ipaddress.ip_address(addr)
    return any(ip in n for n in INTERNAL_NETS if n.version == ip.version)


def is_public(addr):
    ip = ipaddress.ip_address(addr)
    return ip.is_global and not is_internal(addr)


def resolve_all(host, port):
    infos = socket.getaddrinfo(host, port, type=socket.SOCK_STREAM)
    return sorted({i[4][0] for i in infos})


def dns_query(name, resolver, qtype=1):
    """One A (or AAAA) query over UDP to an explicit resolver. Returns addresses."""
    qid = random.randint(0, 0xFFFF)
    q = b"".join(bytes([len(p)]) + p.encode("idna") for p in name.rstrip(".").split(".")) + b"\0"
    pkt = struct.pack(">HHHHHH", qid, 0x0100, 1, 0, 0, 0) + q + struct.pack(">HH", qtype, 1)
    fam = socket.AF_INET6 if ":" in resolver else socket.AF_INET
    with socket.socket(fam, socket.SOCK_DGRAM) as s:
        s.settimeout(TIMEOUT)
        s.sendto(pkt, (resolver, DNS_PORT))
        data, _ = s.recvfrom(4096)
    rid, flags, qd, an = struct.unpack(">HHHH", data[:8])
    if rid != qid:
        raise ValueError("mismatched DNS reply")
    rcode = flags & 0xF
    if rcode == 3:
        raise ValueError("NXDOMAIN")
    if rcode:
        raise ValueError("DNS error rcode %d" % rcode)
    off = 12
    for _ in range(qd):
        off = skip_name(data, off) + 4
    out = []
    for _ in range(an):
        off = skip_name(data, off)
        rtype, _cls, _ttl, rdlen = struct.unpack(">HHIH", data[off:off + 10])
        off += 10
        rdata = data[off:off + rdlen]
        off += rdlen
        if rtype == 1 and rdlen == 4:
            out.append(socket.inet_ntop(socket.AF_INET, rdata))
        elif rtype == 28 and rdlen == 16:
            out.append(socket.inet_ntop(socket.AF_INET6, rdata))
    return out


def skip_name(data, off):
    while True:
        n = data[off]
        if n == 0:
            return off + 1
        if n & 0xC0 == 0xC0:
            return off + 2
        off += n + 1


def answer_matches(addrs, expect):
    if not expect:
        return True
    net = ipaddress.ip_network(expect, strict=False)
    return any(ipaddress.ip_address(a) in net for a in addrs if ipaddress.ip_address(a).version == net.version)


def check_dns(c):
    name = c["target"]
    if c.get("resolver"):
        if not is_internal(c["resolver"]):
            return False, "refused by probe: resolver is not an internal address"
        addrs = dns_query(name, c["resolver"], 1) or dns_query(name, c["resolver"], 28)
        via = " via %s" % c["resolver"]
    else:
        addrs = resolve_all(name, None)
        via = ""
    if not addrs:
        return False, "no address for %s%s" % (name, via)
    if not answer_matches(addrs, c.get("expect")):
        return False, "%s%s -> %s, not in %s" % (name, via, ",".join(addrs[:3]), c["expect"])
    return True, "%s%s -> %s" % (name, via, ",".join(addrs[:3]))


def split_hostport(target):
    if target.startswith("["):
        host, _, port = target[1:].partition("]:")
    else:
        host, _, port = target.rpartition(":")
    return host, int(port)


def check_vpn(c):
    host, port = split_hostport(c["target"])
    addrs = resolve_all(host, port)
    bad = [a for a in addrs if not is_internal(a)]
    if not addrs or bad:
        return False, "refused by probe: %s is not only private addresses (%s)" % (host, ",".join(bad or ["none"]))
    last = None
    for a in addrs:
        try:
            with socket.create_connection((a, port), timeout=TIMEOUT):
                return True, "connected to %s:%d" % (a, port)
        except OSError as e:
            last = e
    return False, "%s:%d %s" % (host, port, describe(last))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


def check_internet(c):
    u = urllib.parse.urlsplit(c["target"])
    if u.scheme != "https" or not u.hostname:
        return False, "refused by probe: only https:// URLs"
    addrs = resolve_all(u.hostname, u.port or 443)
    bad = [a for a in addrs if not is_public(a)]
    if not addrs or bad:
        return False, "refused by probe: %s resolves to non-public %s" % (u.hostname, ",".join(bad or ["none"]))
    req = urllib.request.Request(c["target"], method="HEAD", headers={"User-Agent": "opnmgr-probe/" + VERSION})
    opener = urllib.request.build_opener(NoRedirect, urllib.request.HTTPSHandler(context=ssl.create_default_context()))
    try:
        status = opener.open(req, timeout=TIMEOUT).status
    except urllib.error.HTTPError as e:
        status = e.code
    expect = c.get("expect")
    ok = (status == int(expect)) if expect else status < 500
    return ok, "HTTP %d from %s%s" % (status, u.hostname, "" if ok else (" (expected %s)" % expect if expect else ""))


def describe(e):
    if isinstance(e, socket.timeout):
        return "timed out after %ds" % TIMEOUT
    if isinstance(e, ConnectionRefusedError):
        return "connection refused"
    return (getattr(e, "strerror", None) or str(e) or e.__class__.__name__)[:120]


RUNNERS = {"dns": check_dns, "vpn_service": check_vpn, "internet_service": check_internet}


def run_check(c):
    fn = RUNNERS.get(c.get("type"))
    if fn is None:
        return {"check_id": c.get("id"), "ok": False, "detail": "refused by probe: unknown check type"}
    socket.setdefaulttimeout(TIMEOUT)
    t0 = time.monotonic()
    try:
        ok, detail = fn(c)
    except Exception as e:  # a failed path is a result, not a crash
        ok, detail = False, describe(e)
    return {"check_id": c["id"], "ok": bool(ok), "latency_ms": int((time.monotonic() - t0) * 1000), "detail": detail[:250]}


def manager_request(base, token, method, body=None):
    url = base.rstrip("/") + "/api/customer_probe.php"
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method, headers={
        "Authorization": "Bearer " + token, "Content-Type": "application/json",
        "User-Agent": "opnmgr-probe/" + VERSION})
    with urllib.request.urlopen(req, timeout=15, context=ssl.create_default_context() if url.startswith("https") else None) as r:
        return json.loads(r.read().decode())


def manager_url_ok(base):
    u = urllib.parse.urlsplit(base)
    # Plain HTTP only to this machine (a local test manager); everything else is HTTPS.
    return u.scheme == "https" or (u.scheme == "http" and u.hostname in ("127.0.0.1", "localhost", "::1"))


def one_round(base, token):
    cfg = manager_request(base, token, "GET")
    checks = cfg.get("checks", [])[:MAX_CHECKS]
    results = [run_check(c) for c in checks]
    manager_request(base, token, "POST", {"probe_version": VERSION, "results": results})
    for r in results:
        print("%s check %s: %s" % ("PASS" if r["ok"] else "FAIL", r["check_id"], r["detail"]), flush=True)
    return int(cfg.get("interval_seconds", 60)), all(r["ok"] for r in results)


def main():
    ap = argparse.ArgumentParser(description="OPNManager customer probe")
    ap.add_argument("--manager", required=True, help="https://your-opnmanager")
    ap.add_argument("--token-file", help="file holding the probe token (else OPNMGR_PROBE_TOKEN)")
    ap.add_argument("--once", action="store_true", help="run one round and exit (exit 1 if any check failed)")
    a = ap.parse_args()

    if not manager_url_ok(a.manager):
        sys.exit("refusing: --manager must be https:// (http:// is allowed only to localhost)")
    token = (open(a.token_file).read() if a.token_file else os.environ.get("OPNMGR_PROBE_TOKEN", "")).strip()
    if not token.startswith("opp_"):
        sys.exit("no probe token: set OPNMGR_PROBE_TOKEN or --token-file (shown once when the probe is created)")

    while True:
        try:
            interval, all_ok = one_round(a.manager, token)
        except urllib.error.HTTPError as e:
            if e.code == 401:
                sys.exit("the manager rejected this token (disabled, rotated or deleted)")
            print("manager returned HTTP %d" % e.code, file=sys.stderr, flush=True)
            interval, all_ok = 60, False
        except Exception as e:
            print("cannot reach the manager: %s" % describe(e), file=sys.stderr, flush=True)
            interval, all_ok = 60, False
        if a.once:
            sys.exit(0 if all_ok else 1)
        time.sleep(max(30, interval) + random.uniform(0, 5))


if __name__ == "__main__":
    main()
