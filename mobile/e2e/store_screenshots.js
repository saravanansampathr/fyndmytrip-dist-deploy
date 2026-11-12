// Store screenshots (1290 x 2796, iPhone 6.7"/6.9" size, also fine for Google Play)
// taken from the web build against the local API, same setup as app_e2e.js:
//   APP_URL=http://127.0.0.1:8040 node e2e/store_screenshots.js
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const APP = process.env.APP_URL || 'http://127.0.0.1:8040';
const OUT = process.env.OUT_DIR || path.join(__dirname, '..', 'store', 'screenshots');
fs.mkdirSync(OUT, { recursive: true });
const tid = (id) => `[data-testid="${id}"]`;

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({
    viewport: { width: 430, height: 932 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true,
    locale: 'en-IN', timezoneId: 'Asia/Kolkata',
  });
  const page = await ctx.newPage();
  // The local API's test supplier uses names like "MockAir"; give them neutral names for the store.
  const tidy = (pg) => pg.evaluate(() => {
    const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    for (let n; (n = w.nextNode());) n.nodeValue = n.nodeValue.replace(/MockAir Domestic/g, 'Skyline Air').replace(/MockAir/g, 'Skyline').replace(/MOCK-/g, '').replace(/Mock /g, '');
  });
  const shot = async (n) => { await page.waitForTimeout(800); await tidy(page); await page.screenshot({ path: path.join(OUT, n + '.png') }); console.log('saved', n); };

  // 1 home, 2 flight results, 3 fare details, 4 payment, 5 confirmation (guest)
  await page.goto(APP + '/');
  await page.locator(tid('search-btn')).waitFor({ timeout: 30000 });
  await shot('01-book');
  await page.locator(tid('search-btn')).click();
  await page.locator(tid('result-card')).first().waitFor({ timeout: 30000 });
  await shot('02-flight-results');
  await page.locator(tid('result-card')).first().click();
  await page.locator(tid('continue-btn')).waitFor();
  await shot('03-fare-details');
  await page.locator(tid('continue-btn')).click();
  await page.locator(tid('to-payment')).waitFor({ timeout: 15000 });
  for (const [id, v] of Object.entries({ 'p0-first_name': 'Priya', 'p0-last_name': 'Raman', 'c-email': 'priya@example.com', 'c-phone': '9876543210' })) {
    const loc = page.locator(tid(id)); if (await loc.count()) await loc.first().fill(v);
  }
  await page.locator(tid('to-payment')).click();
  await page.locator(tid('pay-total')).waitFor({ timeout: 20000 });
  await shot('04-payment');
  await page.locator(tid('pm-upi')).click().catch(() => {});
  await page.locator(tid('pay-btn')).click();
  await page.getByText('Booking confirmed').waitFor({ timeout: 30000 });
  await shot('05-confirmed');

  // 6 bus seat map
  await page.goto(APP + '/?s=bus');
  await page.locator(tid('search-btn')).waitFor({ timeout: 30000 });
  await page.locator(tid('svc-bus')).click();
  await page.locator(tid('search-btn')).click();
  await page.locator(tid('result-card')).first().waitFor({ timeout: 30000 });
  await page.locator(tid('result-card')).first().click();
  await page.locator(tid('continue-btn')).waitFor();
  await shot('06-bus-seats');

  // 7 partner home
  const p2 = await ctx.newPage();
  await ctx.clearCookies();
  await page.close();
  await p2.goto(APP + '/login?who=partner');
  await p2.evaluate(() => localStorage.clear());
  await p2.goto(APP + '/login?who=partner');
  await p2.locator(tid('a-email')).fill('agent@fyndmytrip.com');
  await p2.locator(tid('a-password')).fill('password');
  await p2.locator(tid('a-submit')).click();
  await p2.locator(tid('partner-count')).waitFor({ timeout: 20000 });
  await p2.waitForTimeout(5000); // let the "Welcome" toast go
  await tidy(p2);
  await p2.screenshot({ path: path.join(OUT, '07-partner.png') }); console.log('saved 07-partner');
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
