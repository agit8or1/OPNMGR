/**
 * Capture the documentation screenshots in docs/images/github/ from the
 * isolated demo environment seeded by scripts/demo_fixture.php.
 *
 * This deliberately does not point at an installation. It expects the demo
 * database and a throwaway web root, so nothing it renders is real customer
 * infrastructure and there is no redaction step to get wrong.
 *
 * Every capture is 1440x900, dark theme, sidebar pinned so the navigation
 * labels are legible rather than a column of bare icons.
 *
 * Reseed immediately before capturing: check-in ages are seeded in seconds and
 * the dashboard treats anything older than five minutes as not live.
 *
 *   OPNMGR_DEMO=1 php scripts/demo_fixture.php
 *   DEMO_PASS='...' node scripts/capture_demo_screenshots.js
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

if (!PASS) {
  console.error('DEMO_PASS is not set. Refusing to run rather than embedding a credential here.');
  process.exit(1);
}

fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const TARGETS = [
  { file: 'fleet-dashboard',  url: '/dashboard.php' },
  { file: 'firewall-health',  url: '/firewall_health.php?firewall=4',
    scrollTo: 'fw-dal-edge01.northwind.example' },
  { file: 'firewall-detail',  url: '/firewall_details.php?id=4', scroll: 1400 },
  { file: 'backup-history',   url: '/firewall_details.php?id=4', tab: 'Backups' },
  { file: 'config-drift',     url: '/config_drift.php' },
  { file: 'fleet-updates',    url: '/fleet_updates.php' },
  { file: 'incidents',        url: '/incidents.php' },
];

(async () => {
  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--force-device-scale-factor=1'],
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
  await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'dark' }]);
  await page.evaluateOnNewDocument(() => {
    try {
      localStorage.setItem('opnmgr-theme', 'dark');
      localStorage.setItem('opnmgr-sidebar-pinned', 'true');
    } catch (e) { /* ignore */ }
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

  for (const t of TARGETS) {
    await page.goto(URL + t.url, { waitUntil: 'networkidle2', timeout: 45000 });
    await sleep(2600);

    if (t.tab) {
      const clicked = await page.evaluate((label) => {
        const el = [...document.querySelectorAll('button,a,[role=tab],.nav-link')]
          .find((e) => (e.innerText || '').trim() === label);
        if (el) { el.click(); return true; }
        return false;
      }, t.tab);
      if (!clicked) { console.log(`  SKIP ${t.file}: tab "${t.tab}" not found`); continue; }
      await sleep(2000);
    }
    if (t.scrollTo) {
      const ok = await page.evaluate((needle) => {
        const el = [...document.querySelectorAll('.card, .panel, section, div')]
          .filter((e) => (e.innerText || '').includes(needle))
          .sort((a, b) => a.innerText.length - b.innerText.length)[0];
        if (!el) return false;
        window.scrollTo(0, Math.max(0, el.getBoundingClientRect().top + window.scrollY - 150));
        return true;
      }, t.scrollTo);
      if (!ok) console.log(`  (scrollTo "${t.scrollTo}" not found)`);
      await sleep(900);
    }
    if (t.scroll) {
      await page.evaluate((y) => window.scrollTo(0, y), t.scroll);
      await sleep(900);
    }

    await page.screenshot({ path: path.join(OUT, `${t.file}.png`), fullPage: false });
    const chars = await page.evaluate(() => document.body.innerText.length);
    console.log(`  ${t.file.padEnd(18)} chars=${chars}`);
  }
  await browser.close();
})();
