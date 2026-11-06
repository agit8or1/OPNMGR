# Dependency exposure

Checked 2026-11-06 with `php scripts/ci/dependency_audit.php` (Composer and
every npm lockfile; blocking in CI).

## Where the dependencies run

| Ecosystem | Packages | Runs in the PHP request path? |
|---|---|---|
| Composer | `erusev/parsedown`, `bacon/bacon-qr-code` (+ `dasprid/enum`) | **yes** — Markdown docs, 2FA QR codes |
| npm (root `package.json`) | `puppeteer` | no — screenshot / walkthrough tooling (`scripts/capture_*.js`, `scripts/record_demo_walkthrough.js`), run by hand |
| npm (`scripts/package.json`, git-ignored, local only) | `puppeteer` | no — same tooling |

## Advisories found and resolved

The scanner matches for **basic-ftp, extract-zip, ip-address, js-yaml and
zod** (plus ws, get-uri, pac-proxy-agent, proxy-agent, chromium-bidi) all
reached the tree through `puppeteer` 24.x → `@puppeteer/browsers` /
`proxy-agent` / `chromium-bidi`. npm's own remediation for every one of them
was the parent package, `puppeteer@25.16.0` (a semver-major of the parent, not
a forced transitive override).

| Before (24.43.1 / 24.37.2) | After (25.16.0) |
|---|---|
| root: 12 advisories (11 high, 1 moderate) | 0 |
| scripts/: 10 advisories (1 critical, 9 high) | 0 |
| basic-ftp, extract-zip, ip-address, js-yaml | no longer in either tree |
| zod < 4.6.6 | 4.6.7 |
| ws 8.0.0 – 8.20.1 | 8.22.1 |

Compatibility: the tooling uses only `launch`, `newPage`, `setViewport`,
`goto`, `type`, `click`, `evaluate`, `evaluateOnNewDocument`,
`emulateMediaFeatures`, `waitForFunction`, `waitForNavigation`, `screenshot`
and `screencast`. A smoke run of those calls against a local `data:` page on
Puppeteer 25.16.0 / Chrome 156.0.8078.47 succeeded for both trees. The capture
scripts themselves were not run, because they sign in to a live manager.

One advisory had no backported fix for its older major line ("JS-YAML:
Quadratic CPU consumption in `!!omap` resolution — CVE-2026-59870 fix not
backported"); it no longer applies because js-yaml is no longer installed.

## Remaining exposure

- **None reported** by Composer or npm audit as of the date above.
- Puppeteer downloads a Chrome build from Google's CDN at `npm install`; that is
  a supply-chain dependency of the tooling, not of the application.
- The tooling is not deployed as part of the web application; nothing under
  `node_modules/` is reachable through the PHP request path. Excluding
  `node_modules/` from the production web root (or denying it in the web
  server) is still recommended.

## CI behaviour

`scripts/ci/dependency_audit.php` exits **0** (clean), **1** (advisories, each
listed with the parent-package fix when npm knows one) or **2** (a scanner
could not run or returned unparseable output — never treated as clean). The
previous `composer audit --no-interaction || true` passed in all three cases.
Abandoned Composer packages are reported as warnings without failing the job.
