#!/usr/bin/env node
/**
 * Authenticated Screenshot Capture for OPNsense Manager
 * Captures full-page screenshots with authentication
 */

const puppeteer = require('puppeteer');
const path = require('path');
const fs = require('fs');

const BASE_URL = process.env.BASE_URL || 'https://opn.agit8or.net';
const USERNAME = process.env.USERNAME || 'admin';
const PASSWORD = process.env.PASSWORD || 'password';
const SCREENSHOT_DIR = path.join(__dirname, '..', 'screenshots');

// Create screenshots directory
if (!fs.existsSync(SCREENSHOT_DIR)) {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

async function captureScreenshot(page, name, url, waitForSelector = null) {
    console.log(`Capturing: ${name} (${url})`);

    try {
        await page.goto(`${BASE_URL}${url}`, {
            waitUntil: 'networkidle0',
            timeout: 30000
        });

        // Wait for specific element if provided, but don't fail if not found
        if (waitForSelector) {
            try {
                await page.waitForSelector(waitForSelector, { timeout: 5000 });
            } catch (e) {
                console.log(`  Warning: Selector ${waitForSelector} not found, continuing anyway`);
            }
        }

        // Wait a bit for any animations/dynamic content
        await new Promise(resolve => setTimeout(resolve, 3000));

        const screenshotPath = path.join(SCREENSHOT_DIR, `${name}.png`);
        await page.screenshot({
            path: screenshotPath,
            fullPage: true
        });

        console.log(`  ✓ Saved: ${screenshotPath}`);
        return true;
    } catch (error) {
        console.log(`  ✗ Failed: ${error.message}`);
        return false;
    }
}

async function main() {
    console.log('=====================================');
    console.log('OPNsense Manager Screenshot Capture');
    console.log('=====================================');
    console.log(`Base URL: ${BASE_URL}`);
    console.log(`Screenshot Dir: ${SCREENSHOT_DIR}`);
    console.log('');

    const browser = await puppeteer.launch({
        headless: true,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-web-security',
            '--ignore-certificate-errors'
        ]
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 1920, height: 1080 });

    // Accept self-signed certificates
    await page.setBypassCSP(true);

    try {
        // 1. Capture login page
        console.log('\nCapturing login page...\n');
        await captureScreenshot(page, '01-login', '/login.php', 'input[name="username"]');

        // 2. Perform login
        console.log('\nLogging in...\n');
        await page.type('input[name="username"]', USERNAME);
        await page.type('input[name="password"]', PASSWORD);
        await Promise.all([
            page.click('button[type="submit"]'),
            page.waitForNavigation({ waitUntil: 'networkidle2' })
        ]);

        console.log('Login successful!\n');

        // 3. Capture authenticated pages
        console.log('Capturing authenticated pages...\n');

        await captureScreenshot(page, '02-dashboard', '/dashboard.php', '.card-dark');
        await new Promise(resolve => setTimeout(resolve, 1000));

        await captureScreenshot(page, '03-firewalls-list', '/firewalls.php', 'table');
        await new Promise(resolve => setTimeout(resolve, 1000));

        // Try to capture a specific firewall details page with graphs
        // First, get the first firewall ID
        await page.goto(`${BASE_URL}/firewalls.php`, { waitUntil: 'networkidle2' });
        const firewallLink = await page.$('a[href*="firewall_details.php?id="]');

        if (firewallLink) {
            const href = await page.evaluate(el => el.getAttribute('href'), firewallLink);
            await captureScreenshot(page, '04-firewall-details-with-graphs', href, '.card-dark');
            await new Promise(resolve => setTimeout(resolve, 2000)); // Wait for graphs to load
        }

        await captureScreenshot(page, '05-add-firewall', '/add_firewall.php', '.card-dark');
        await new Promise(resolve => setTimeout(resolve, 1000));

        await captureScreenshot(page, '06-customers', '/customers.php', '.card-dark');
        await new Promise(resolve => setTimeout(resolve, 1000));

        await captureScreenshot(page, '07-manage-tags', '/manage_tags_ui.php', '.card-dark');
        await new Promise(resolve => setTimeout(resolve, 1000));

        await captureScreenshot(page, '08-settings', '/settings.php', '.settings-grid');
        await new Promise(resolve => setTimeout(resolve, 1000));

        await captureScreenshot(page, '09-security-scanner', '/security_scan.php', '.card-dark');
        await new Promise(resolve => setTimeout(resolve, 1000));

        await captureScreenshot(page, '10-system-logs', '/logs.php', 'table');
        await new Promise(resolve => setTimeout(resolve, 1000));

        await captureScreenshot(page, '11-users', '/users.php', 'table');
        await new Promise(resolve => setTimeout(resolve, 1000));

        await captureScreenshot(page, '12-add-user', '/add_user.php', 'form');
        await new Promise(resolve => setTimeout(resolve, 1000));

        await captureScreenshot(page, '13-system-update', '/system_update.php', '.card-dark');
        await new Promise(resolve => setTimeout(resolve, 1000));

        await captureScreenshot(page, '14-support', '/support.php', '.card-dark');
        await new Promise(resolve => setTimeout(resolve, 1000));

        await captureScreenshot(page, '15-about', '/about.php', '.card-dark');

        console.log('\n=====================================');
        console.log('Screenshot capture complete!');
        console.log('=====================================');

    } catch (error) {
        console.error('Error:', error);
    } finally {
        await browser.close();
    }
}

main().catch(console.error);
