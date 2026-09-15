/**
 * Capture the documentation screenshots in docs/images/github/ from the
 * isolated demo environment seeded by scripts/demo_fixture.php.
 *
 * This deliberately does not point at an installation. It expects the demo
 * database and a throwaway web root, so nothing it renders is real customer
 * infrastructure and there is no redaction step that can silently fail.
 *
 * Captures are 1440x1000 at deviceScaleFactor 2, so interface text stays sharp
 * when GitHub scales the image down. Themes come from the application's own
 * selector: theme.js reads `opnmgr-theme` from localStorage, and each capture
 * waits until <html data-theme> actually reflects the requested value.
 *
 * Reseed immediately before capturing: check-in ages are seeded in seconds and
 * the dashboard treats anything older than five minutes as not live.
 *
 *   OPNMGR_DEMO=1 OPNMGR_DEMO_BACKUP_DIR=/tmp/opnmgr-demo-backups \
 *     php scripts/demo_fixture.php
 *   DEMO_PASS='...' node scripts/capture_demo_screenshots.js
 *
 * Environment:
 *   DEMO_URL   default http://127.0.0.1:8899
 *   DEMO_USER  default demo
 *   DEMO_PASS  required; never hardcode a credential here
 *   DEMO_OUT   default docs/images/github
 *   ONLY       optional comma-separated list of capture names
 *
 * @since 3.22.0
 */

const puppeteer = require('puppeteer');
const path = require('path');
const fs = require('fs');

const URL  = process.env.DEMO_URL  || 'http://127.0.0.1:8899';
const USER = process.env.DEMO_USER || 'demo';
const PASS = process.env.DEMO_PASS || '';
const OUT  = process.env.DEMO_OUT  || path.join(__dirname, '..', 'docs', 'images', 'github');
const ONLY = (process.env.ONLY || '').split(',').map((s) => s.trim()).filter(Boolean);

if (!PASS) {
  console.error('DEMO_PASS is not set. Refusing to run rather than embedding a credential here.');
  process.exit(1);
}

const VIEWPORT = { width: 1440, height: 1000, deviceScaleFactor: 2 };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * The capture manifest. Each entry records the route, theme and any workflow
 * state needed to reach it, so a capture can be reproduced without guesswork.
 *
 *   file    output basename (theme suffix is added)
 *   url     route, including the query string that puts the page in this state
 *   theme   'light' | 'dark' - applied through the application's own selector
 *   tab     optional in-page tab to click after load
 *   scrollTo optional text; the smallest element containing it is scrolled to
 *   scroll  optional absolute Y offset
 */
const TARGETS = [
  // --- Overview and dashboards -------------------------------------------
  { file: 'fleet-dashboard',     url: '/dashboard.php',                      theme: 'dark'  },
  { file: 'fleet-dashboard',     url: '/dashboard.php',                      theme: 'light' },
  { file: 'fleet-firewalls',     url: '/firewalls.php',                      theme: 'light' },
  { file: 'customers-sites',     url: '/customers.php',                      theme: 'dark'  },

  // --- Visual insight and monitoring --------------------------------------
  { file: 'firewall-statistics', url: '/firewall_details.php?id=4',          theme: 'dark'  },
  { file: 'firewall-health',     url: '/firewall_health.php?firewall=4',     theme: 'light',
    scrollTo: 'fw-dal-edge01.northwind.example' },
  { file: 'health-overview',     url: '/firewall_health.php',                theme: 'dark'  },
  { file: 'incidents',           url: '/incidents.php',                      theme: 'light' },
  { file: 'alert-history',       url: '/alert_history.php',                  theme: 'dark'  },
  { file: 'audit-log',           url: '/audit_log.php',                      theme: 'light' },
  { file: 'manager-health',      url: '/health_monitor.php',                 theme: 'dark'  },
  { file: 'agent-diagnostics',   url: '/diagnostics.php',                    theme: 'light' },

  // --- Configuration and change control -----------------------------------
  { file: 'config-drift',        url: '/config_drift.php',                   theme: 'light' },
  { file: 'config-diff',         url: '/config_drift.php?firewall=4',        theme: 'dark',
    scrollTo: 'DIFFERENCES FROM BASELINE' },
  { file: 'config-search',       url: '/config_search.php?q=check%3Assh_open_to_world', theme: 'light' },
  { file: 'backup-history',      url: '/firewall_details.php?id=4',          theme: 'dark', tab: 'Backups' },

  // --- Everyday workflows --------------------------------------------------
  { file: 'fleet-updates',       url: '/fleet_updates.php',                  theme: 'dark'  },
  { file: 'bulk-operations',     url: '/bulk_operations.php',                theme: 'light' },
  { file: 'maintenance-windows', url: '/maintenance.php',                    theme: 'dark'  },
  { file: 'fleet-search',        url: '/search.php?q=tag%3Acritical-site',   theme: 'light' },
  { file: 'enroll-firewall',     url: '/add_firewall_page.php',              theme: 'light' },

  // --- Management and administration --------------------------------------
  { file: 'settings',            url: '/settings.php',                       theme: 'dark'  },
  { file: 'users-roles',         url: '/users.php',                          theme: 'light' },
  { file: 'tags',                url: '/manage_tags_ui.php',                 theme: 'dark'  },
  { file: 'approved-commands',   url: '/approved_commands.php',              theme: 'light' },
  { file: 'alerting',            url: '/alerts.php',                         theme: 'dark'  },
  { file: 'system-backup',       url: '/system_backup.php',                  theme: 'light' },
  { file: 'about',               url: '/about.php',                          theme: 'dark'  },
];

/** Freeze motion just before the shutter, after charts have drawn. */
const FREEZE_CSS = `*,*::before,*::after{animation:none!important;
  transition:none!important;caret-color:transparent!important}
  ::-webkit-scrollbar{width:0!important;height:0!important}`;

(async () => {
  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--hide-scrollbars',
           '--font-render-hinting=none'],
  });
  const page = await browser.newPage();
  await page.setViewport(VIEWPORT);

  await page.evaluateOnNewDocument(() => {
    try { localStorage.setItem('opnmgr-sidebar-pinned', 'true'); } catch (e) { /* ignore */ }
  });

  await page.goto(`${URL}/login.php`, { waitUntil: 'networkidle2' });
  await page.type('#username', USER);
  await page.type('#password', PASS);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
    page.click('button[type=submit]'),
  ]);
  if (page.url().includes('login.php')) {
    console.error('Login failed'); await browser.close(); process.exit(1);
  }

  fs.mkdirSync(OUT, { recursive: true });
  const manifest = [];

  for (const t of TARGETS) {
    const name = `${t.file}-${t.theme}`;
    if (ONLY.length && !ONLY.includes(name) && !ONLY.includes(t.file)) continue;

    // Persist the theme the way the application does, then load the page so
    // theme.js applies it during boot rather than as a visible flash.
    await page.evaluate((th) => {
      try { localStorage.setItem('opnmgr-theme', th); } catch (e) { /* ignore */ }
    }, t.theme);
    await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: t.theme }]);

    await page.goto(URL + t.url, { waitUntil: 'networkidle2', timeout: 45000 });

    // The theme must actually be on the document before anything is captured.
    try {
      await page.waitForFunction(
        (th) => document.documentElement.getAttribute('data-theme') === th,
        { timeout: 5000 }, t.theme
      );
    } catch (e) {
      console.log(`  ! ${name}: theme did not settle on ${t.theme}`);
    }

    if (t.tab) {
      const clicked = await page.evaluate((label) => {
        const el = [...document.querySelectorAll('button,a,[role=tab],.nav-link')]
          .find((e) => (e.innerText || '').trim() === label);
        if (el) { el.click(); return true; }
        return false;
      }, t.tab);
      if (!clicked) { console.log(`  ! ${name}: tab "${t.tab}" not found`); continue; }
      await sleep(1200);
    }

    // Fonts, images and charts.
    await page.evaluate(() => document.fonts && document.fonts.ready);
    await page.evaluate(async () => {
      await Promise.all([...document.images]
        .filter((i) => !i.complete)
        .map((i) => new Promise((res) => { i.onload = i.onerror = res; })));
    });
    await sleep(2800);

    if (t.scrollTo) {
      const ok = await page.evaluate((needle) => {
        const el = [...document.querySelectorAll('.card, .panel, section, div')]
          .filter((e) => (e.innerText || '').includes(needle))
          .sort((a, b) => a.innerText.length - b.innerText.length)[0];
        if (!el) return false;
        window.scrollTo(0, Math.max(0, el.getBoundingClientRect().top + window.scrollY - 150));
        return true;
      }, t.scrollTo);
      if (!ok) console.log(`  ! ${name}: scrollTo "${t.scrollTo}" not found`);
      await sleep(700);
    }
    if (t.scroll) { await page.evaluate((y) => window.scrollTo(0, y), t.scroll); await sleep(700); }

    await page.addStyleTag({ content: FREEZE_CSS });
    await sleep(250);

    const file = path.join(OUT, `${name}.png`);
    await page.screenshot({ path: file });

    const check = await page.evaluate(() => ({
      theme: document.documentElement.getAttribute('data-theme'),
      overflow: document.documentElement.scrollWidth > window.innerWidth + 2,
      skeleton: /Loading\.\.\.|Please wait|spinner-border/i.test(document.body.innerHTML),
      broken: [...document.images].filter((i) => i.complete && i.naturalWidth === 0).length,
    }));
    manifest.push({ name, url: t.url, theme: t.theme, tab: t.tab || null,
                    viewport: `${VIEWPORT.width}x${VIEWPORT.height}@${VIEWPORT.deviceScaleFactor}x`,
                    bytes: fs.statSync(file).size });
    console.log(`  ${name.padEnd(28)} ${check.theme.padEnd(5)} ` +
                `${(fs.statSync(file).size / 1024).toFixed(0).padStart(4)}KB` +
                `${check.overflow ? '  OVERFLOW' : ''}` +
                `${check.broken ? `  ${check.broken} BROKEN IMG` : ''}` +
                `${check.skeleton ? '  SKELETON?' : ''}`);
  }

  fs.writeFileSync(path.join(OUT, 'captures.json'), JSON.stringify(manifest, null, 2));
  console.log(`\n${manifest.length} captures -> ${OUT}`);
  console.log(`light: ${manifest.filter((m) => m.theme === 'light').length}  ` +
              `dark: ${manifest.filter((m) => m.theme === 'dark').length}`);
  await browser.close();
})();
