const { chromium } = require('playwright');
const crypto = require('crypto');
// Browser run of the Phase 1 admin screens: Admin settings tabs, an edited
// message template, a published legal page replacing the built-in one, and
// two-factor login from setup to the next sign-in. Run via e2e/run.sh.
const FE = process.env.FE_URL || 'http://127.0.0.1:8020', API = process.env.API_URL || 'http://127.0.0.1:8010';
const OUT = process.env.RESULTS_DIR || '.';
const results = []; const check = (n, ok, d = '') => { results.push({ n, ok: !!ok, d: String(d).slice(0, 300) }); console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : ' -> ' + d)); };

function totp(secret) {
  const alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = '';
  for (const c of secret.replace(/=+$/, '').toUpperCase()) bits += alpha.indexOf(c).toString(2).padStart(5, '0');
  const key = Buffer.from(bits.match(/.{8}/g).map(b => parseInt(b, 2)));
  const msg = Buffer.alloc(8); msg.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const h = crypto.createHmac('sha1', key).update(msg).digest(); const o = h[19] & 15;
  return String(((h.readUInt32BE(o) & 0x7fffffff) % 1e6)).padStart(6, '0');
}

async function open(browser) {
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push('pageerror: ' + e.message));
  await page.route('https://api-demo.fyndmytrip.in/**', async route => {
    const req = route.request();
    const headers = { ...req.headers() }; delete headers['host'];
    try {
      const resp = await route.fetch({ url: req.url().replace('https://api-demo.fyndmytrip.in', API), headers });
      await route.fulfill({ response: resp, headers: { ...resp.headers(), 'access-control-allow-origin': '*' } });
    } catch (e) { await route.abort(); }
  });
  return { ctx, page, errors };
}
async function login(page, email, password = 'password') {
  await page.goto(FE + '/login');
  await page.fill('input[type=email]', email);
  await page.fill('input[type=password]', password);
  await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type=submit]')]);
  await page.waitForTimeout(800);
}

(async () => {
  require('fs').mkdirSync(OUT + '/shots', { recursive: true });
  const browser = await chromium.launch();
  let { ctx, page, errors } = await open(browser);
  await login(page, 'admin@fyndmytrip.com');
  check('admin signs in', !page.url().includes('/login'), page.url());

  await page.goto(FE + '/admin-settings'); await page.waitForLoadState('networkidle');
  check('Admin settings page shows', await page.locator('[data-testid=admin-settings]').isVisible());
  check('nav has Admin settings', await page.locator('a[href="/admin-settings"]').count() > 0);
  await page.waitForSelector('text=Save tax settings');
  await page.locator('input[maxlength="5"]').first().fill('FYT');
  await page.click('text=Save tax settings');
  check('tax settings save', await page.waitForSelector('text=New invoices use these settings', { timeout: 8000 }).then(() => true, () => false));
  await page.screenshot({ path: OUT + '/shots/admin-tax.png', fullPage: true });

  for (const [tab, text] of [['Messages', 'Booking confirmed'], ['Website pages', 'Privacy'], ['Failed bookings', 'Paid bookings that need attention'], ['GST register', 'Invoices and credit notes'], ['Security & health', 'System health']]) {
    await page.click(`button:has-text("${tab}")`);
    const ok = await page.waitForSelector(`text=${text}`, { timeout: 8000 }).then(() => true, () => false);
    check(`tab ${tab}`, ok);
    await page.screenshot({ path: OUT + `/shots/admin-${tab.split(' ')[0].toLowerCase()}.png`, fullPage: true });
  }

  // edit a message template
  await page.click('button:has-text("Messages")');
  await page.locator('button:has-text("Email")').first().click();
  await page.locator('textarea').first().fill('Hello {{customer_name}}, booking {{booking_code}} is confirmed. E2E');
  await page.click('button:has-text("Save")');
  check('template saves', await page.waitForSelector('text=The next message uses this text', { timeout: 8000 }).then(() => true, () => false));

  // publish the privacy page and see it on the website
  await page.click('button:has-text("Website pages")');
  await page.locator('button:has-text("Privacy")').first().click();
  await page.locator('textarea').first().fill('<h2>E2E privacy</h2><p>Edited in admin.</p><script>alert(1)</script>');
  await page.click('button:text-is("Publish")');
  check('page publishes', await page.waitForSelector('text=Visitors now see this page', { timeout: 8000 }).then(() => true, () => false));
  await page.goto(FE + '/privacy-policy'); await page.waitForLoadState('networkidle');
  check('website shows the published page', await page.locator('text=E2E privacy').isVisible());
  check('scripts are stripped from pages', !(await page.content()).includes('alert(1)'));
  await page.goto(FE + '/terms-and-conditions'); await page.waitForLoadState('networkidle');
  check('unpublished page falls back to the built-in one', (await page.locator('h1').count()) > 0 && !(await page.locator('text=Loading…').isVisible()));

  // two-factor login
  await page.goto(FE + '/account-security'); await page.waitForLoadState('networkidle');
  await page.click('text=Set up two-factor login');
  await page.waitForSelector('code.select-all');
  const secret = (await page.locator('code.select-all').textContent()).trim();
  check('QR code shows', await page.locator('svg').first().isVisible());
  await page.fill('input[inputmode=numeric]', totp(secret));
  await page.click('button:has-text("Turn on")');
  check('2FA turns on with recovery codes', await page.waitForSelector('text=Recovery codes', { timeout: 8000 }).then(() => true, () => false));
  check('no page errors (admin)', errors.length === 0, errors.join(' | '));
  await ctx.close();

  ({ ctx, page, errors } = await open(browser));
  page.on('dialog', d => d.accept(totp(secret)));
  await login(page, 'admin@fyndmytrip.com');
  check('sign-in asks for the code and lets the user in', !page.url().includes('/login'), page.url());
  await ctx.close();

  ({ ctx, page, errors } = await open(browser));
  page.on('dialog', d => d.accept('000000'));
  await login(page, 'admin@fyndmytrip.com');
  check('a wrong code keeps the user out', page.url().includes('/login'), page.url());
  await ctx.close();

  // a partner admin sees only their own site's messages and pages
  ({ ctx, page, errors } = await open(browser));
  await login(page, 'admin@acmetravel.fyndmytrip.com');
  await page.goto(FE + '/admin-settings'); await page.waitForLoadState('networkidle');
  check('partner admin sees Messages and Website pages only', await page.locator('button:has-text("Website pages")').isVisible() && !(await page.locator('button:has-text("Tax & invoices")').count()));
  await ctx.close();

  await browser.close();
  require('fs').writeFileSync(OUT + '/ui_admin_results.json', JSON.stringify(results, null, 1));
  const failed = results.filter(r => !r.ok);
  console.log(`\nadmin UI: ${results.length - failed.length}/${results.length} passed`);
  process.exit(failed.length ? 1 : 0);
})();
