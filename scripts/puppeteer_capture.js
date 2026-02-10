const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

// Configuration
const USERNAME = 'screenshot';
const PASSWORD = 'Screenshot2025!';
const BASE_URL = 'http://localhost';
const OUTPUT_DIR = '/var/www/opnmanager-website/assets/images';

// Pages to capture
const PAGES = [
    { name: 'Dashboard', url: '/dashboard.php', filename: 'dashboard-real.png' },
    { name: 'Firewall Management', url: '/firewalls.php', filename: 'firewall-management-real.png' },
    { name: 'Update Management', url: '/updates.php', filename: 'update-management-real.png' },
    { name: 'Settings Interface', url: '/settings.php', filename: 'settings-interface-real.png' },
    { name: 'User Management', url: '/users.php', filename: 'user-management-real.png' }
];

async function captureScreenshots() {
    console.log('=== OPNmanager Puppeteer Screenshot Capture ===');
    console.log(`Using credentials: ${USERNAME} / ${PASSWORD}`);
    console.log(`Output directory: ${OUTPUT_DIR}`);
    console.log('');

    // Ensure output directory exists
    if (!fs.existsSync(OUTPUT_DIR)) {
        fs.mkdirSync(OUTPUT_DIR, { recursive: true });
    }

    let browser;
    try {
        // Launch browser
        browser = await puppeteer.launch({
            headless: 'new',
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-accelerated-2d-canvas',
                '--no-first-run',
                '--no-zygote',
                '--disable-gpu'
            ]
        });

        const page = await browser.newPage();
        
        // Set viewport size
        await page.setViewport({
            width: 1920,
            height: 1080,
            deviceScaleFactor: 1
        });

        console.log('Logging in to OPNmanager...');
        
        // Navigate to login page
        await page.goto(`${BASE_URL}/login.php`, { 
            waitUntil: 'networkidle2',
            timeout: 30000
        });

        // Wait for login form
        await page.waitForSelector('input[name="username"]', { timeout: 10000 });
        await page.waitForSelector('input[name="password"]', { timeout: 10000 });

        // Fill login form
        await page.type('input[name="username"]', USERNAME);
        await page.type('input[name="password"]', PASSWORD);

        // Submit form and wait for navigation
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }),
            page.click('button[type="submit"]')
        ]);

        // Check if login was successful by looking for dashboard elements
        const currentUrl = page.url();
        console.log(`Current URL after login: ${currentUrl}`);

        // Wait a bit for any redirects
        await new Promise(resolve => setTimeout(resolve, 2000));

        // Check if we're on a dashboard or authenticated page
        try {
            await page.waitForSelector('body', { timeout: 5000 });
            const bodyText = await page.evaluate(() => document.body.innerText.toLowerCase());
            
            if (bodyText.includes('login') && !bodyText.includes('logout')) {
                throw new Error('Login appears to have failed - still on login page');
            }
            
            console.log('✓ Login successful!');
        } catch (error) {
            console.log('✗ Login verification failed:', error.message);
            throw error;
        }

        console.log('');
        console.log('Capturing authenticated screenshots...');
        console.log('');

        // Capture each page
        for (const pageInfo of PAGES) {
            try {
                console.log(`Capturing: ${pageInfo.name}`);
                console.log(`URL: ${BASE_URL}${pageInfo.url}`);
                
                // Navigate to the page
                await page.goto(`${BASE_URL}${pageInfo.url}`, { 
                    waitUntil: 'networkidle2',
                    timeout: 30000
                });

                // Wait for page to fully load
                await new Promise(resolve => setTimeout(resolve, 3000));

                // Take screenshot
                const outputPath = path.join(OUTPUT_DIR, pageInfo.filename);
                await page.screenshot({
                    path: outputPath,
                    fullPage: false,
                    clip: {
                        x: 0,
                        y: 0,
                        width: 1920,
                        height: 1080
                    }
                });

                console.log(`✓ Screenshot saved: ${outputPath}`);
                
                // Set proper permissions
                try {
                    fs.chmodSync(outputPath, 0o644);
                } catch (chmodError) {
                    console.log(`Warning: Could not set permissions on ${outputPath}`);
                }

            } catch (error) {
                console.log(`✗ Failed to capture ${pageInfo.name}: ${error.message}`);
            }
            
            console.log('');
        }

        console.log('Screenshot capture complete!');

    } catch (error) {
        console.error('Screenshot capture failed:', error.message);
        process.exit(1);
    } finally {
        if (browser) {
            await browser.close();
        }
    }
}

// Run the capture
captureScreenshots().catch(console.error);