const puppeteer = require('puppeteer');
const URL = 'https://opn.agit8or.net';
const OUT = '/home/administrator/opnsense/screenshots';
async function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
async function blur(page) {
    await page.evaluate(() => {
        const pats = [/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/,/[0-9a-fA-F]{4}:[0-9a-fA-F:]+/,/home\.agit8or\.net/i,/fw\.agit8or\.net/i,/opn\.agit8or\.net/i,/agit8or/i];
        function walk(n){if(n.nodeType===3){let t=n.textContent,c=false;for(const p of pats){const g=new RegExp(p.source,'gi');if(g.test(t)){c=true;t=t.replace(new RegExp(p.source,'gi'),m=>'\u2588'.repeat(Math.min(m.length,14)));}}if(c){const s=document.createElement('span');s.textContent=t;s.style.color='#3b82f6';s.style.fontFamily='monospace';n.parentNode.replaceChild(s,n);}}else if(n.nodeType===1&&!['SCRIPT','STYLE','NOSCRIPT'].includes(n.tagName)){Array.from(n.childNodes).forEach(walk);}}
        walk(document.body);
    });
}
async function pin(page){await page.evaluate(()=>{var s=document.getElementById('sidebar');if(s){s.classList.add('pinned','expanded');document.body.setAttribute('data-sidebar-pinned','true');}});await sleep(300);}
async function unpin(page){await page.evaluate(()=>{var s=document.getElementById('sidebar');if(s){s.classList.remove('pinned','expanded');document.body.setAttribute('data-sidebar-pinned','false');}});await sleep(300);}
async function theme(page,t){await page.evaluate((th)=>{document.documentElement.setAttribute('data-theme',th);document.documentElement.setAttribute('data-bs-theme',th);},t);await sleep(300);}

(async () => {
    const browser = await puppeteer.launch({headless:true,args:['--no-sandbox','--ignore-certificate-errors'],ignoreHTTPSErrors:true});
    const page = await browser.newPage();
    await page.setViewport({width:1920,height:1080,deviceScaleFactor:2});

    await page.goto(URL+'/login.php',{waitUntil:'domcontentloaded',timeout:15000});
    await sleep(800);
    console.log('1/9 Login'); await theme(page,'dark'); await page.screenshot({path:OUT+'/01-login.png'});

    await page.type('#username','admin'); await page.type('#password','Chin00k2023###');
    await page.click('button[type="submit"]'); await sleep(3000);

    console.log('2/9 Dashboard'); await page.goto(URL+'/dashboard.php',{waitUntil:'domcontentloaded',timeout:15000}); await sleep(4000);
    await theme(page,'dark'); await pin(page); await blur(page); await page.screenshot({path:OUT+'/02-dashboard.png'});

    console.log('3/9 Dashboard full'); await page.screenshot({path:OUT+'/02b-dashboard-full.png',fullPage:true});

    console.log('4/9 Firewalls'); await page.goto(URL+'/firewalls.php',{waitUntil:'domcontentloaded',timeout:15000}); await sleep(2000);
    await theme(page,'dark'); await pin(page); await blur(page); await page.screenshot({path:OUT+'/03-firewalls.png'});

    console.log('5/9 FW details'); await page.goto(URL+'/firewall_details.php?id=51',{waitUntil:'domcontentloaded',timeout:15000}); await sleep(3000);
    await theme(page,'dark'); await pin(page); await blur(page); await page.screenshot({path:OUT+'/04-firewall-details.png'});

    console.log('6/9 Users'); await page.goto(URL+'/users.php',{waitUntil:'domcontentloaded',timeout:15000}); await sleep(1500);
    await theme(page,'dark'); await pin(page); await blur(page); await page.screenshot({path:OUT+'/05-users.png'});

    console.log('7/9 Settings'); await page.goto(URL+'/settings.php',{waitUntil:'domcontentloaded',timeout:15000}); await sleep(1500);
    await theme(page,'dark'); await pin(page); await blur(page); await page.screenshot({path:OUT+'/06-settings.png'});

    console.log('8/9 Collapsed'); await page.goto(URL+'/dashboard.php',{waitUntil:'domcontentloaded',timeout:15000}); await sleep(3000);
    await theme(page,'dark'); await unpin(page); await blur(page); await page.screenshot({path:OUT+'/07-sidebar-collapsed.png'});

    console.log('9/9 Light'); await page.goto(URL+'/dashboard.php',{waitUntil:'domcontentloaded',timeout:15000}); await sleep(3000);
    await theme(page,'light'); await pin(page); await blur(page); await page.screenshot({path:OUT+'/08-light-mode.png'});

    await browser.close(); console.log('Done');
})();
