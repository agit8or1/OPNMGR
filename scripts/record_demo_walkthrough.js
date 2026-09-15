/**
 * Record a walkthrough of OPNManager against the isolated demo environment
 * seeded by scripts/demo_fixture.php.
 *
 * Like the screenshot capture, this deliberately points at a throwaway database
 * of fictitious data rather than an installation, so there is nothing to redact.
 *
 * Produces an H.264 MP4 at 1440x900. Convert to a README-sized GIF with:
 *
 *   ffmpeg -i walkthrough.mp4 -vf "fps=8,scale=960:-1:flags=lanczos,palettegen" -y palette.png
 *   ffmpeg -i walkthrough.mp4 -i palette.png \
 *     -lavfi "fps=8,scale=960:-1:flags=lanczos[x];[x][1:v]paletteuse" -y walkthrough.gif
 *
 * Usage:
 *
 *   OPNMGR_DEMO=1 php scripts/demo_fixture.php
 *   DEMO_PASS='...' node scripts/record_demo_walkthrough.js
 *
 * @since 3.24.0
 */

const puppeteer = require('puppeteer');
const path = require('path');
const fs = require('fs');

const URL  = process.env.DEMO_URL  || 'http://127.0.0.1:8899';
const USER = process.env.DEMO_USER || 'demo';
const PASS = process.env.DEMO_PASS || '';
const OUT  = process.env.DEMO_VIDEO_OUT
          || path.join(__dirname, '..', 'docs', 'images', 'github', 'walkthrough.mp4');

if (!PASS) {
  console.error('DEMO_PASS is not set. Refusing to run rather than embedding a credential here.');
  process.exit(1);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** Scroll smoothly so the recording reads as a person looking down a page. */
async function glide(page, to, ms = 1400) {
  await page.evaluate(async (target, duration) => {
    const start = window.scrollY;
    const delta = target - start;
    const t0 = performance.now();
    await new Promise((done) => {
      const step = (now) => {
        const p = Math.min(1, (now - t0) / duration);
        // ease-in-out, so it does not start or stop abruptly
        const e = p < 0.5 ? 2 * p * p : 1 - Math.pow(-2 * p + 2, 2) / 2;
        window.scrollTo(0, start + delta * e);
        if (p < 1) requestAnimationFrame(step); else done();
      };
      requestAnimationFrame(step);
    });
  }, to, ms);
}

(async () => {
  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--force-device-scale-factor=1',
           '--hide-scrollbars'],
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

  // Sign in before recording starts - no credential is ever on camera.
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

  fs.mkdirSync(path.dirname(OUT), { recursive: true });
  const recorder = await page.screencast({ path: OUT, fps: 25 });
  console.log('recording -> ' + OUT);

  const visit = async (url, label) => {
    await page.goto(URL + url, { waitUntil: 'networkidle2', timeout: 45000 });
    await sleep(800);
    console.log('  ' + label);
  };

  // 1. The fleet at a glance.
  await visit('/dashboard.php', 'dashboard');
  await sleep(3200);
  await glide(page, 420, 1600);
  await sleep(2600);
  await glide(page, 0, 1200);
  await sleep(900);

  // 2. The fleet list, grouped by customer and tagged.
  await visit('/firewalls.php', 'firewalls');
  await sleep(3400);
  await glide(page, 380, 1600);
  await sleep(2400);

  // 3. Search across the whole fleet.
  await visit('/search.php?q=tag%3Acritical-site', 'fleet search');
  await sleep(4000);

  // 4. One firewall's health.
  await visit('/firewall_health.php?firewall=4', 'firewall health');
  await sleep(2000);
  await page.evaluate(() => {
    const el = [...document.querySelectorAll('.card, div')]
      .filter((e) => (e.innerText || '').includes('fw-dal-edge01.northwind.example'))
      .sort((a, b) => a.innerText.length - b.innerText.length)[0];
    if (el) window.scrollTo(0, Math.max(0, el.getBoundingClientRect().top + window.scrollY - 150));
  });
  await sleep(4200);

  // 5. What changed since the approved baseline.
  await visit('/config_drift.php', 'configuration drift');
  await sleep(4000);

  // 6. Rollout state.
  await visit('/fleet_updates.php', 'fleet updates');
  await sleep(4200);

  // 7. What is actually wrong right now.
  await visit('/incidents.php', 'incidents');
  await sleep(4200);

  // 8. How the fleet is organised.
  await visit('/customers.php', 'customers');
  await sleep(2000);
  await glide(page, 400, 1500);
  await sleep(2800);

  await recorder.stop();
  await browser.close();
  console.log('done');
})();
