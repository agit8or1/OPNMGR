/**
 * OPNManager documentation screenshot capture.
 *
 * Lives in scripts/ (denied by the nginx hardening snippet) rather than the
 * document root. The previous version sat at /take_screenshots.js, was served
 * publicly over HTTPS, and had the administrator password hardcoded in it.
 *
 * Credentials come from the environment; nothing is committed:
 *
 *   OPNMGR_URL=https://opn.example.net \
 *   OPNMGR_SCREENSHOT_USER=admin \
 *   OPNMGR_SCREENSHOT_PASS='...' \
 *   node scripts/take_screenshots.js
 *
 * Hostnames, IP addresses and IPv6 literals are redacted in the rendered page
 * before capture so published screenshots do not leak customer infrastructure.
 */

const puppeteer = require('puppeteer');
const path = require('path');
const fs = require('fs');

const URL  = process.env.OPNMGR_URL  || 'https://127.0.0.1';
const USER = process.env.OPNMGR_SCREENSHOT_USER || 'admin';
const PASS = process.env.OPNMGR_SCREENSHOT_PASS || '';
const OUT  = process.env.OPNMGR_SCREENSHOT_OUT  || path.join(__dirname, '..', 'screenshots');
const HOST_HEADER = process.env.OPNMGR_HOST_HEADER || '';

if (!PASS) {
    console.error('OPNMGR_SCREENSHOT_PASS is not set.');
    console.error('Refusing to run rather than embedding a credential in this file.');
    process.exit(1);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Replace identifying strings in the rendered DOM with block characters.
 * Runs in the page context immediately before each capture.
 */
async function redact(page) {
    await page.evaluate(() => {
        // The FQDN rule deliberately requires a real TLD suffix. A looser
        // "word.word.word" pattern also matches dotted identifiers the UI shows
        // legitimately - audit action names like "agent.auth.failed", capability
        // names like "backup.restore" - and blanking those makes the captures
        // useless.
        const TLD = '(?:com|net|org|io|dev|co|uk|de|fr|nl|us|ca|au|info|biz|local|lan|internal|home|corp)';
        const patterns = [
            /\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/,               // IPv4
            // 3+ groups, so a clock time like 17:59:47 is not mistaken for IPv6.
            /\b(?:[0-9a-fA-F]{1,4}:){3,7}[0-9a-fA-F]{1,4}\b/,          // IPv6
            new RegExp('\\b[a-z0-9-]+(?:\\.[a-z0-9-]+)*\\.' + TLD + '\\b', 'i'), // FQDN
            /\b[0-9a-f]{32}\b/i,                                      // hardware ids
            /\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/,      // email
        ];

        const walk = (node) => {
            if (node.nodeType === Node.TEXT_NODE) {
                let text = node.textContent;
                let changed = false;
                for (const pattern of patterns) {
                    const global = new RegExp(pattern.source, 'gi');
                    if (global.test(text)) {
                        changed = true;
                        text = text.replace(
                            new RegExp(pattern.source, 'gi'),
                            (m) => '█'.repeat(Math.min(m.length, 14))
                        );
                    }
                }
                if (changed) {
                    const span = document.createElement('span');
                    span.textContent = text;
                    span.style.color = '#3b82f6';
                    span.style.fontFamily = 'monospace';
                    node.parentNode.replaceChild(span, node);
                }
            } else if (node.nodeType === Node.ELEMENT_NODE
                       && !['SCRIPT', 'STYLE', 'NOSCRIPT'].includes(node.tagName)) {
                Array.from(node.childNodes).forEach(walk);
            }
        };
        walk(document.body);
    });
}

/**
 * Force a theme for the capture.
 *
 * The app's theme.js reads localStorage ('opnmgr-theme') on load and applies it,
 * so setting the attribute alone is undone on the next navigation. Write the
 * stored preference too, then reload so the page renders in that theme from the
 * start rather than flipping after paint.
 */
async function setTheme(page, theme) {
    await page.evaluate((t) => {
        try { localStorage.setItem('opnmgr-theme', t); } catch (e) { /* private mode */ }
        document.documentElement.setAttribute('data-theme', t);
        document.documentElement.setAttribute('data-bs-theme', t);
    }, theme);
    await page.reload({ waitUntil: 'networkidle2' });
    await sleep(400);
}

async function capture(page, name, label) {
    console.log(`  ${label}`);
    await sleep(700);
    await redact(page);
    await page.screenshot({ path: path.join(OUT, name), fullPage: false });
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });

    const browser = await puppeteer.launch({
        headless: 'new',
        args: ['--no-sandbox', '--ignore-certificate-errors'],
        ignoreHTTPSErrors: true,
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 1600, height: 1000, deviceScaleFactor: 2 });
    if (HOST_HEADER) {
        await page.setExtraHTTPHeaders({ Host: HOST_HEADER });
    }

    try {
        // --- login -----------------------------------------------------------
        await page.goto(`${URL}/login.php`, { waitUntil: 'domcontentloaded', timeout: 20000 });
        await setTheme(page, 'dark');
        await capture(page, '01-login.png', 'Login');
        // setTheme reloads, so re-query the form fields below.

        await page.type('#username', USER);
        await page.type('#password', PASS);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 25000 }).catch(() => {}),
            page.click('button[type=submit]'),
        ]);

        if (page.url().includes('login.php')) {
            throw new Error('Login failed - check OPNMGR_SCREENSHOT_USER / OPNMGR_SCREENSHOT_PASS');
        }

        // --- pages -----------------------------------------------------------
        const pages = [
            ['/dashboard.php',       '02-dashboard.png',    'Dashboard'],
            ['/firewalls.php',       '03-firewalls.png',    'Firewalls'],
            ['/customers.php',       '04-customers.png','Customers'],
            ['/alerts.php',          '05-alerts.png',       'Alerts'],
            ['/audit_log.php',       '06-audit-log.png',    'Audit log'],
            ['/search.php?q=customer%3AAGIT8OR', '11-search.png', 'Fleet search'],
            ['/firewall_health.php?firewall=48',  '12-health.png', 'Firewall health'],
            ['/config_drift.php',                 '13-drift.png',  'Config drift'],
            ['/users.php',           '07-users.png',        'Users and roles'],
            ['/settings.php',        '08-settings.png',     'Settings'],
            ['/about.php',           '09-about.png',        'About'],
        ];

        for (const [route, file, label] of pages) {
            try {
                await page.goto(URL + route, { waitUntil: 'networkidle2', timeout: 20000 });
                await capture(page, file, label);
            } catch (e) {
                console.warn(`  skipped ${label}: ${e.message}`);
            }
        }

        // --- light theme sample ---------------------------------------------
        await page.goto(`${URL}/dashboard.php`, { waitUntil: 'networkidle2', timeout: 20000 });
        await setTheme(page, 'light');
        await capture(page, '10-dashboard-light.png', 'Dashboard (light theme)');

        console.log(`\nScreenshots written to ${OUT}`);
    } catch (err) {
        console.error('Screenshot run failed:', err.message);
        process.exitCode = 1;
    } finally {
        await browser.close();
    }
})();
