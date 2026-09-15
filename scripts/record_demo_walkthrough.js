/**
 * Record the OPNManager walkthrough against the isolated demo environment
 * seeded by scripts/demo_fixture.php.
 *
 * Like the screenshot capture this points at a throwaway database of fictitious
 * data, never an installation, so there is nothing to redact: every address is
 * from a documentation range and every hostname is under `.example`.
 *
 * Output is 1920x1080. Sign-in happens before recording starts, so no
 * credential is ever on camera. The tour runs a dark section, performs one
 * visible theme switch through the application's own toggle, then continues in
 * light - which is why a single recording can show both themes honestly.
 *
 * Produces, under DEMO_VIDEO_DIR (default: outside the repository):
 *   walkthrough.raw.webm   what the browser recorded
 * Encode to the published shapes with scripts/encode_demo_media.sh.
 *
 * Usage:
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
const DIR  = process.env.DEMO_VIDEO_DIR || '/tmp/opnmgr-demo-media';

if (!PASS) {
  console.error('DEMO_PASS is not set. Refusing to run rather than embedding a credential here.');
  process.exit(1);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** Scroll smoothly, so the recording reads as a person looking down a page. */
async function glide(page, to, ms = 1500) {
  await page.evaluate(async (target, duration) => {
    const start = window.scrollY;
    const delta = target - start;
    const t0 = performance.now();
    await new Promise((done) => {
      const step = (now) => {
        const p = Math.min(1, (now - t0) / duration);
        const e = p < 0.5 ? 2 * p * p : 1 - Math.pow(-2 * p + 2, 2) / 2;
        window.scrollTo(0, start + delta * e);
        if (p < 1) requestAnimationFrame(step); else done();
      };
      requestAnimationFrame(step);
    });
  }, to, ms);
}

/**
 * On-screen caption. Captions carry the explanation because no narration is
 * produced here; scripts/walkthrough-script.md holds the narration-ready text.
 */
async function caption(page, text, sub = '') {
  await page.evaluate((t, s) => {
    let el = document.getElementById('__cap');
    if (!el) {
      el = document.createElement('div');
      el.id = '__cap';
      el.style.cssText = [
        'position:fixed', 'left:50%', 'bottom:46px', 'transform:translateX(-50%)',
        'z-index:2147483647', 'max-width:76%', 'padding:14px 26px',
        'background:rgba(8,10,14,.93)', 'color:#fff', 'border-radius:12px',
        'font:600 25px/1.32 system-ui,-apple-system,Segoe UI,Roboto,sans-serif',
        'text-align:center', 'box-shadow:0 10px 34px rgba(0,0,0,.5)',
        'border:1px solid rgba(255,255,255,.14)', 'opacity:0',
        'transition:opacity .28s ease', 'pointer-events:none',
      ].join(';');
      document.body.appendChild(el);
    }
    el.innerHTML = s
      ? `${t}<div style="font-weight:450;font-size:19px;opacity:.82;margin-top:5px">${s}</div>`
      : t;
    requestAnimationFrame(() => { el.style.opacity = '1'; });
  }, text, sub);
}

async function clearCaption(page) {
  await page.evaluate(() => {
    const el = document.getElementById('__cap');
    if (el) el.style.opacity = '0';
  });
}

(async () => {
  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--hide-scrollbars',
           '--window-size=1920,1080', '--font-render-hinting=none'],
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1920, height: 1080, deviceScaleFactor: 1 });
  // Seed the starting theme only if nothing is stored yet. Setting it
  // unconditionally on every document would overwrite the theme toggle later in
  // the tour, and the light half of the walkthrough would silently record dark.
  await page.evaluateOnNewDocument(() => {
    try {
      if (!localStorage.getItem('opnmgr-theme')) {
        localStorage.setItem('opnmgr-theme', 'dark');
      }
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

  fs.mkdirSync(DIR, { recursive: true });
  const raw = path.join(DIR, 'walkthrough.raw.webm');
  const recorder = await page.screencast({ path: raw, fps: 30 });
  console.log('recording -> ' + raw);

  const visit = async (url, label) => {
    await page.goto(URL + url, { waitUntil: 'networkidle2', timeout: 45000 });
    await page.evaluate(() => document.fonts && document.fonts.ready);
    await sleep(1100);
    console.log('  ' + label);
  };

  // --- Title -------------------------------------------------------------
  await visit('/dashboard.php', 'title');
  await caption(page, 'OPNManager',
    'Self-hosted OPNsense fleet management for MSPs and IT teams');
  await sleep(4200);
  await caption(page, 'Every firewall you look after, grouped by customer and site',
    'All data shown is simulated demo data');
  await sleep(4200);
  await clearCaption(page);
  await sleep(700);

  // --- 1. Orientation: the fleet dashboard -------------------------------
  await caption(page, 'Start with what needs attention',
    'Roll-up tiles appear only when the count is non-zero');
  await sleep(4000);
  await glide(page, 300, 1500);
  await sleep(3200);
  await caption(page, 'Eleven firewalls across five customers',
    'One offline, four with updates pending, two drifted from baseline');
  await sleep(4200);
  await glide(page, 760, 1600);
  await sleep(3000);
  await clearCaption(page);
  await glide(page, 0, 1200);

  // --- 2. Workflow: find a connectivity problem --------------------------
  await visit('/incidents.php', 'incidents');
  await caption(page, 'Workflow 1 — find the problem',
    'One incident per ongoing condition, not one email per poll');
  await sleep(4600);
  await glide(page, 320, 1500);
  await sleep(3600);
  await clearCaption(page);

  await visit('/firewall_health.php?firewall=4', 'firewall health');
  await page.evaluate(() => {
    const el = [...document.querySelectorAll('.card, div')]
      .filter((e) => (e.innerText || '').includes('fw-dal-edge01.northwind.example'))
      .sort((a, b) => a.innerText.length - b.innerText.length)[0];
    if (el) window.scrollTo(0, Math.max(0, el.getBoundingClientRect().top + window.scrollY - 160));
  });
  await sleep(900);
  await caption(page, 'Gateways, tunnels, services and certificates',
    'A degraded LTE gateway at 148 ms and 2.4% loss, and a stopped IDS service');
  await sleep(5400);
  await clearCaption(page);
  await sleep(600);

  // --- 3. Workflow: review what changed ----------------------------------
  await visit('/config_drift.php', 'configuration drift');
  await caption(page, 'Workflow 2 — review what changed',
    'Each firewall compared against the baseline you approved');
  await sleep(4600);
  await clearCaption(page);

  await visit('/config_drift.php?firewall=4', 'configuration diff');
  await page.evaluate(() => {
    const el = [...document.querySelectorAll('.card, div')]
      .filter((e) => (e.innerText || '').includes('DIFFERENCES FROM BASELINE'))
      .sort((a, b) => a.innerText.length - b.innerText.length)[0];
    if (el) window.scrollTo(0, Math.max(0, el.getBoundingClientRect().top + window.scrollY - 170));
  });
  await sleep(900);
  await caption(page, 'Two firewall rules added since the baseline',
    'Serialisation noise is ignored, so an untouched firewall reports no drift');
  await sleep(6400);
  await clearCaption(page);
  await sleep(600);

  // --- Theme switch, through the application's own toggle -----------------
  await visit('/dashboard.php', 'theme switch');
  await caption(page, 'The whole interface has a light theme',
    'System preference detection, with a manual toggle');
  await sleep(3400);
  const before = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));
  await page.evaluate(() => {
    const b = document.getElementById('theme-toggle');
    if (b) b.click();
  });
  try {
    await page.waitForFunction(
      (prev) => document.documentElement.getAttribute('data-theme') !== prev,
      { timeout: 4000 }, before
    );
    const after = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));
    console.log(`  theme toggled ${before} -> ${after}`);
  } catch (e) {
    console.error('  ! theme toggle did not change the theme - aborting');
    await recorder.stop(); await browser.close(); process.exit(1);
  }
  await sleep(2600);
  await clearCaption(page);
  await sleep(900);

  // --- 4. Workflow: plan and stage an update (light) ----------------------
  await visit('/fleet_updates.php', 'fleet updates (light)');
  await caption(page, 'Workflow 3 — plan an update',
    'Canary, then pilot, then production. Rings are a rollout mechanism, not tiers');
  await sleep(5000);
  await glide(page, 330, 1500);
  await sleep(3600);
  await caption(page, 'HA pairs are never updated at the same time',
    'The BACKUP member goes first; the MASTER waits until it is back');
  await sleep(4600);
  await clearCaption(page);
  await glide(page, 0, 1000);

  await visit('/maintenance.php', 'maintenance windows');
  await caption(page, 'Windows scoped to a firewall, a site or a customer',
    'Collection continues; only outbound notification is withheld');
  await sleep(4800);
  await clearCaption(page);

  // --- 5. Visual monitoring and reporting --------------------------------
  await visit('/firewall_details.php?id=4', 'statistics');
  await caption(page, 'Per-firewall traffic, load, memory and disk',
    'Collected on each agent check-in');
  await sleep(5600);
  await glide(page, 300, 1400);
  await sleep(3600);
  await clearCaption(page);

  await visit('/config_search.php?q=check%3Assh_open_to_world', 'config search');
  await caption(page, 'Ask one question across every stored configuration',
    'Named checks run over the parsed config - no AI in the path');
  await sleep(5400);
  await clearCaption(page);

  await visit('/search.php?q=tag%3Acritical-site', 'fleet search');
  await caption(page, 'Find anything across the fleet from one box',
    'customer:, site:, tag:, version:, ip: - and a bare CIDR matches addresses inside it');
  await sleep(5200);
  await clearCaption(page);

  await visit('/bulk_operations.php', 'bulk operations');
  await caption(page, 'Act on many firewalls at once, deliberately',
    'High-risk operations need a typed phrase including the target count');
  await sleep(5000);
  await clearCaption(page);

  await visit('/firewall_details.php?id=4', 'backups');
  await page.evaluate(() => {
    const el = [...document.querySelectorAll('button,a,[role=tab],.nav-link')]
      .find((e) => (e.innerText || '').trim() === 'Backups');
    if (el) el.click();
  });
  await sleep(1400);
  await caption(page, 'Every stored configuration, per firewall',
    'Download, restore or promote one to baseline');
  await sleep(4800);
  await clearCaption(page);

  await visit('/users.php', 'staff roles');
  await caption(page, 'Administrator, Technician and Read Only',
    'A capability matrix, not role strings scattered through the code');
  await sleep(4800);
  await clearCaption(page);

  await visit('/audit_log.php', 'audit log');
  await caption(page, 'Who did what, to which firewall, from where',
    'Credential material is never recorded');
  await sleep(5000);
  await glide(page, 260, 1400);
  await sleep(3400);
  await clearCaption(page);
  await glide(page, 0, 900);

  await visit('/customers.php', 'customers');
  await caption(page, 'Customers and sites are organisational groupings',
    'They have no accounts and never log in — only your staff sign in');
  await sleep(4800);
  await clearCaption(page);

  // --- End card ----------------------------------------------------------
  await visit('/dashboard.php', 'end card');
  await caption(page, 'OPNManager — free and self-hosted',
    'github.com/agit8or1/OPNMGR');
  await sleep(4600);
  await caption(page, 'More free tools for MSPs', 'mspreboot.com');
  await sleep(4400);
  await clearCaption(page);
  await sleep(900);

  await recorder.stop();
  await browser.close();
  console.log('done -> ' + raw);
})();
