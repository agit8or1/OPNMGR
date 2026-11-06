# Configuration fixtures

| File | Provenance |
|---|---|
| `opnsense-26.7-home-sanitized.xml` | Real OPNsense 26.7.4 configuration from a production firewall, run through `scripts/sanitize_config_fixture.php` and reviewed by hand. |
| `opnsense-26.7-edge-sanitized.xml` | Real OPNsense 26.7.8 configuration (HAProxy, WireGuard, 67 MVC rules), sanitized the same way. |
| `pfsense-ce-2.7.2-synthetic.xml` | **Synthetic.** Written for these tests from the element names used by pfSense's own source (`config.lib.inc`, `upgrade_config.inc`, the WireGuard package). No pfSense device was available. |
| `pfsense-plus-25.07-synthetic.xml` | **Synthetic**, as above, with a Plus-era `<version>`. |

The synthetic pfSense files prove the parser handles pfSense's element layout;
they are not evidence that any particular pfSense release produces exactly this
file. Replace them with sanitized exports from lab devices when available:

    OPNMGR_FIXTURE_REDACT_WORDS="customername,sitename" \
      php scripts/sanitize_config_fixture.php real.xml tests/fixtures/configs/pfsense-<edition>-<version>-sanitized.xml

The synthetic files deliberately contain marker secrets
(`FIXTURE-...-MUST-NEVER-APPEAR`) so tests can prove secrets never reach the
normalized model, reports, events or AI payloads.
