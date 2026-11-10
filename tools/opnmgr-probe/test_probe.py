#!/usr/bin/env python3
"""Unit tests for opnmgr_probe.py: run with  python3 -I tools/opnmgr-probe/test_probe.py

Covers what the end-to-end test (tests/customer_probe_e2e_test.php) cannot
reach without root: DNS through an explicit internal resolver (a fake one on a
high port here), and the probe's own refusals.
"""
import importlib.util
import os
import socket
import struct
import sys
import threading
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location("opnmgr_probe", os.path.join(HERE, "opnmgr_probe.py"))
probe = importlib.util.module_from_spec(spec)
spec.loader.exec_module(probe)


class FakeResolver(threading.Thread):
    """Answers A queries: fs.corp.lan -> 10.20.0.5, anything else NXDOMAIN."""

    def __init__(self):
        super().__init__(daemon=True)
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self.sock.bind(("127.0.0.1", 0))
        self.port = self.sock.getsockname()[1]

    def run(self):
        while True:
            data, addr = self.sock.recvfrom(512)
            qid = data[:2]
            off, labels = 12, []
            while data[off]:
                labels.append(data[off + 1:off + 1 + data[off]].decode())
                off += data[off] + 1
            q = data[12:off + 5]
            name, qtype = ".".join(labels), struct.unpack(">H", data[off + 1:off + 3])[0]
            if name == "fs.corp.lan" and qtype == 1:
                ans = b"\xc0\x0c" + struct.pack(">HHIH", 1, 1, 60, 4) + socket.inet_aton("10.20.0.5")
                self.sock.sendto(qid + struct.pack(">HHHHH", 0x8180, 1, 1, 0, 0) + q + ans, addr)
            else:
                self.sock.sendto(qid + struct.pack(">HHHHH", 0x8183, 1, 0, 0, 0) + q, addr)


class ProbeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.dns = FakeResolver()
        cls.dns.start()
        probe.DNS_PORT = cls.dns.port
        # The resolver must be "internal"; the fake one listens on loopback, so
        # route the probe's query there while keeping the configured address private.
        real = socket.socket.sendto

        def sendto(self, data, addr):
            if addr[0] == "10.0.0.53":
                addr = ("127.0.0.1", addr[1])
            return real(self, data, addr)
        socket.socket.sendto = sendto

    def run_check(self, **c):
        c.setdefault("id", 1)
        return probe.run_check(c)

    def test_dns_via_internal_resolver(self):
        r = self.run_check(type="dns", target="fs.corp.lan", resolver="10.0.0.53")
        self.assertTrue(r["ok"], r)
        self.assertIn("10.20.0.5", r["detail"])

    def test_dns_expectation(self):
        self.assertTrue(self.run_check(type="dns", target="fs.corp.lan", resolver="10.0.0.53", expect="10.20.0.0/24")["ok"])
        r = self.run_check(type="dns", target="fs.corp.lan", resolver="10.0.0.53", expect="10.99.0.0/16")
        self.assertFalse(r["ok"])
        self.assertIn("not in 10.99.0.0/16", r["detail"])

    def test_dns_nxdomain_is_a_failure_not_a_crash(self):
        r = self.run_check(type="dns", target="gone.corp.lan", resolver="10.0.0.53")
        self.assertFalse(r["ok"])
        self.assertIn("NXDOMAIN", r["detail"])

    def test_refuses_public_resolver(self):
        r = self.run_check(type="dns", target="fs.corp.lan", resolver="8.8.8.8")
        self.assertFalse(r["ok"])
        self.assertIn("refused by probe", r["detail"])

    def test_refuses_vpn_check_to_public_or_loopback(self):
        for t in ("8.8.8.8:53", "127.0.0.1:22"):
            r = self.run_check(type="vpn_service", target=t)
            self.assertFalse(r["ok"])
            self.assertIn("refused by probe", r["detail"])

    def test_refuses_internet_check_to_private_or_http(self):
        self.assertIn("refused by probe", self.run_check(type="internet_service", target="https://10.1.2.3/")["detail"])
        self.assertIn("refused by probe", self.run_check(type="internet_service", target="http://example.com/")["detail"])

    def test_refuses_unknown_types(self):
        r = self.run_check(type="shell", target="id")
        self.assertFalse(r["ok"])
        self.assertIn("unknown check type", r["detail"])

    def test_manager_must_be_https_except_localhost(self):
        self.assertTrue(probe.manager_url_ok("https://m.example"))
        self.assertTrue(probe.manager_url_ok("http://127.0.0.1:8080"))
        self.assertFalse(probe.manager_url_ok("http://m.example"))


if __name__ == "__main__":
    sys.exit(unittest.main(verbosity=1, exit=False).result.wasSuccessful() is False)
