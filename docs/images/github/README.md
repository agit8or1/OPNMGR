# Documentation media

The images here are the ones embedded in [the README](../../../README.md) and
[docs/SCREENSHOTS.md](../../SCREENSHOTS.md).

They show the real OPNManager interface. The data in them is not real: it comes
from [`scripts/demo_fixture.php`](../../../scripts/demo_fixture.php), which
populates a throwaway `*_demo` database with fictitious customers, sites and
simulated telemetry and refuses to run against anything else. No live firewall is
contacted, and no update, reboot or restore is triggered to produce them.

Every address is from a range reserved for documentation — RFC 5737
(`192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`) and RFC 3849
(`2001:db8::/32`) — and every hostname is under a reserved `.example` domain
(RFC 6761). Because nothing real is ever rendered, there is no redaction step
that can silently fail to catch a hostname.

## What is here

| File | Content |
|---|---|
| `<feature>-light.png`, `<feature>-dark.png` | 28 captures, 1440×1000 at deviceScaleFactor 2 |
| `walkthrough-poster.png` | Poster frame for the walkthrough video |
| `captures.json` | Route, theme, tab and viewport for every capture |

`captures.json` is written by the capture script on every run and is the record of
how each image was produced. The walkthrough MP4s are **not** kept here: they are
release assets, because a binary that regenerates from a script does not need to
sit in git history.

## Regenerating

1. Create an isolated database and web root. The schema hardcodes
   `USE opnsense_fw`, so strip that line when importing under another name.
2. Point a throwaway `.env` at it with `DB_NAME` ending in `_demo` and
   `OPNMGR_DEMO=1`, and create an account with `scripts/create_admin.php`.
3. Seed, then capture without a long gap — the fixture seeds check-in ages in
   seconds and the dashboard treats anything older than five minutes as offline:

   ```bash
   OPNMGR_DEMO=1 OPNMGR_DEMO_BACKUP_DIR=/tmp/opnmgr-demo-backups \
     php scripts/demo_fixture.php

   DEMO_PASS='...' node scripts/capture_demo_screenshots.js
   DEMO_PASS='...' node scripts/record_demo_walkthrough.js
   sh scripts/encode_demo_media.sh
   ```

   `DEMO_PASS` comes from the environment; no credential is stored in any script
   or manifest. Set `ONLY=fleet-dashboard-dark` to re-shoot a single capture.

The fixture writes real OPNsense-shaped configuration XML to
`OPNMGR_DEMO_BACKUP_DIR` (default `$TMPDIR/opnmgr-demo-backups`), outside the
repository, because configuration drift and fleet configuration search both parse
the stored file. Delete that directory when you are done.

## Themes

Captures are split evenly between the application's light and dark themes. The
theme is applied through the application's own selector — `theme.js` reads
`opnmgr-theme` from `localStorage` — and each capture waits until
`<html data-theme>` actually reflects the requested value before the shutter.
Nothing is themed with a screenshot filter or injected CSS.
