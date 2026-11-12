// End-to-end run of the app's web build against a local API, on a phone-sized
// screen. Start the API (see README) and serve dist-web, then:
//   APP_URL=http://127.0.0.1:8040 node e2e/app_e2e.js
const { chromium, devices } = require('playwright');
const fs = require('fs');
const path = require('path');

const APP = process.env.APP_URL || 'http://127.0.0.1:8040';
const OUT = process.env.RESULTS_DIR || path.join(__dirname, 'results');
fs.mkdirSync(OUT, { recursive: true });
const results = [];
const tid = (id) => `[data-testid="${id}"]`;

async function run(name, fn) {
  if (process.env.ONLY && !name.includes(process.env.ONLY)) return;
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ ...devices['Pixel 7'], deviceScaleFactor: 1, locale: 'en-IN', timezoneId: 'Asia/Kolkata' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  const t0 = Date.now();
  try {
    const info = await fn(page);
    results.push({ name, ok: true, ms: Date.now() - t0, ...info, errors });
    console.log('PASS', name, info ? JSON.stringify(info) : '');
  } catch (e) {
    await page.screenshot({ path: path.join(OUT, `fail-${name.replace(/\W+/g, '-')}.png`) }).catch(() => {});
    results.push({ name, ok: false, error: String(e.message || e), errors });
    console.log('FAIL', name, e.message);
  }
  await browser.close();
}

const shot = (page, n) => page.screenshot({ path: path.join(OUT, n + '.png') });
const text = async (page, id) => (await page.locator(tid(id)).first().innerText()).trim();
const num = (s) => Number(String(s).replace(/[^0-9.]/g, ''));

async function open(page) {
  await page.goto(APP + '/');
  await page.locator(tid('search-btn')).waitFor({ timeout: 30000 });
}
async function signIn(page, email = 'customer@fyndmytrip.com') {
  await page.goto(APP + '/login');
  await page.locator(tid('a-email')).fill(email);
  await page.locator(tid('a-password')).fill('password');
  await page.locator(tid('a-submit')).click();
  await page.waitForURL((u) => !u.pathname.includes('login'), { timeout: 15000 });
  await page.locator(tid('search-btn')).waitFor({ timeout: 30000 }).catch(() => {});
}
async function searchAndPick(page, svc) {
  await page.locator(tid('svc-' + svc)).click();
  await page.locator(tid('search-btn')).click();
  await page.locator(tid('result-card')).first().waitFor({ timeout: 30000 });
  const count = await text(page, 'result-count');
  await page.locator(tid('result-card')).first().click();
  await page.locator(tid('continue-btn')).waitFor();
  return count;
}
async function fillTravellers(page, values) {
  await page.locator(tid('to-payment')).waitFor({ timeout: 15000 });
  for (const [id, v] of Object.entries(values)) {
    const loc = page.locator(tid(id));
    if (await loc.count()) await loc.first().fill(String(v));
  }
}
async function payAndConfirm(page, method) {
  await page.locator(tid('pay-total')).waitFor({ timeout: 20000 });
  const quoted = num(await text(page, 'pay-total'));
  const summary = await page.locator(tid('pay-summary')).innerText();
  await page.locator(tid('pm-' + method)).click();
  await page.locator(tid('pay-btn')).click();
  await page.locator(tid('done-status')).waitFor({ timeout: 30000 });
  await page.getByText('Booking confirmed').waitFor({ timeout: 15000 });
  const paid = num(await text(page, 'done-paid'));
  if (Math.abs(paid - quoted) > 0.01) throw new Error(`paid ${paid} != quoted ${quoted}`);
  if (/markup|margin|convenience fee|service charge/i.test(summary)) throw new Error('summary shows our fee as its own line: ' + summary);
  return { quoted, paid, ref: await text(page, 'done-ref'), status: await text(page, 'done-status'), summary: summary.replace(/\s+/g, ' ') };
}

(async () => {
  await run('guest flight booking (UPI)', async (page) => {
    await open(page);
    await shot(page, '01-home');
    const count = await searchAndPick(page, 'flight');
    await shot(page, '02-details');
    await page.locator(tid('continue-btn')).click();
    await fillTravellers(page, { 'p0-first_name': 'Asha', 'p0-last_name': 'Raman', 'c-email': 'asha.guest@example.com', 'c-phone': '+919800000001' });
    await shot(page, '03-travellers');
    await page.locator(tid('to-payment')).click();
    await page.locator(tid('pay-total')).waitFor({ timeout: 20000 });
    await shot(page, '04-payment');
    const r = await payAndConfirm(page, 'upi');
    await shot(page, '05-confirmed');
    if (!/Base Fare/.test(r.summary)) throw new Error('no Base Fare line');
    // a guest is never signed in: My Trips asks to sign in
    await page.goto(APP + '/trips');
    await page.locator(tid('trips-login')).waitFor({ timeout: 10000 });
    return { count, ...r };
  });

  await run('guest bus booking with seat choice', async (page) => {
    await open(page);
    await searchAndPick(page, 'bus');
    await page.locator(tid('seat-free')).first().waitFor({ timeout: 20000 });
    await page.locator(tid('seat-free')).first().click();
    const seats = await text(page, 'seat-note');
    await shot(page, '06-bus-seats');
    await page.locator(tid('continue-btn')).click();
    await fillTravellers(page, { 'p0-name': 'Ravi Kumar', 'p0-age': '34', 'c-email': 'ravi.guest@example.com' });
    await page.locator(tid('to-payment')).click();
    return { seats, ...(await payAndConfirm(page, 'credit_card')) };
  });

  await run('cab needs an account for guests', async (page) => {
    await open(page);
    await searchAndPick(page, 'cab');
    await page.locator(tid('continue-btn')).click();
    await page.getByText('Sign in to continue').waitFor({ timeout: 10000 });
    await fillTravellers(page, { 'p0-name': 'Guest Person', 'p0-age': '40', 'c-email': 'g@example.com' });
    await page.locator(tid('to-payment')).click();
    await page.locator(tid('toast')).waitFor({ timeout: 5000 });
    return { toast: await text(page, 'toast') };
  });

  await run('customer hotel booking with wallet, then cancel in My Trips', async (page) => {
    await signIn(page);
    await searchAndPick(page, 'hotel');
    await page.locator(tid('continue-btn')).click();
    await page.locator(tid('booking-as')).waitFor();
    await fillTravellers(page, { 'p0-age': '35', 'p1-name': 'Second Guest', 'p1-age': '33' });
    await page.locator(tid('to-payment')).click();
    const r = await payAndConfirm(page, 'fmt_wallet');
    await page.locator(tid('dl-invoice')).waitFor({ timeout: 10000 });
    await page.goto(APP + '/trips');
    await page.locator(tid('trip-row')).first().waitFor({ timeout: 20000 });
    await shot(page, '07-my-trips');
    await page.locator(tid('trip-row')).first().click();
    const tripRef = await text(page, 'trip-ref');
    if (tripRef !== r.ref) throw new Error(`newest trip ${tripRef} is not ${r.ref}`);
    await page.locator(tid('trip-cancel')).click();
    await page.locator(tid('cancel-quote')).waitFor({ timeout: 15000 });
    await shot(page, '08-cancel-quote');
    const refund = await page.locator(tid('refund-amt')).count() ? await text(page, 'refund-amt') : '';
    await page.locator(tid('cancel-confirm')).click();
    await page.getByText(/^Cancelled$|Refund in progress|Refunded/).first().waitFor({ timeout: 20000 });
    return { ...r, refund };
  });

  await run('customer holiday package, pay later from My Trips', async (page) => {
    await signIn(page);
    await page.locator(tid('svc-holiday')).click();
    await page.locator(tid('search-btn')).click();
    await page.locator(tid('result-card')).first().waitFor({ timeout: 30000 });
    await page.locator(tid('result-card')).first().click();
    await page.locator(tid('continue-btn')).click();
    await fillTravellers(page, { 'p1-name': 'Partner Traveller' });
    await page.locator(tid('to-payment')).click();
    await page.locator(tid('pay-total')).waitFor({ timeout: 20000 });
    const quoted = num(await text(page, 'pay-total'));
    // leave without paying, then pay from My Trips
    await page.goto(APP + '/trips');
    await page.locator(tid('trip-row')).first().waitFor({ timeout: 20000 });
    await page.locator(tid('trip-row')).first().click();
    await page.locator(tid('trip-pay')).click();
    const r = await payAndConfirm(page, 'netbanking');
    if (Math.abs(r.paid - quoted) > 0.01) throw new Error('paid differs from first quote');
    return r;
  });

  await run('currency switch to USD', async (page) => {
    await open(page);
    await page.goto(APP + '/account');
    await page.locator(tid('cur-USD')).click();
    await page.goto(APP + '/');
    await page.locator(tid('search-btn')).click();
    await page.locator(tid('result-card')).first().waitFor({ timeout: 30000 });
    const card = await page.locator(tid('result-card')).first().innerText();
    if (!card.includes('$')) throw new Error('price not in USD: ' + card);
    await shot(page, '09-usd-results');
    return { sample: card.split('\n').find((l) => l.includes('$')) };
  });

  await run('sign in, account and sign out', async (page) => {
    await signIn(page);
    await page.goto(APP + '/account');
    const name = await text(page, 'acct-name');
    await page.locator(tid('acct-wallet')).waitFor({ timeout: 10000 });
    const wallet = await text(page, 'acct-wallet');
    await shot(page, '10-account');
    await page.locator(tid('acct-logout')).click();
    await page.locator(tid('acct-login')).waitFor({ timeout: 10000 });
    return { name, wallet };
  });


  await run('partner (agent) books for a customer from wallet', async (page) => {
    await page.goto(APP + '/login?who=partner');
    await page.locator(tid('a-email')).fill('agent@fyndmytrip.com');
    await page.locator(tid('a-password')).fill('password');
    await page.locator(tid('a-submit')).click();
    await page.locator(tid('partner-wallet')).waitFor({ timeout: 20000 });
    await page.locator(tid('partner-count')).waitFor({ timeout: 20000 });
    const walletBefore = await text(page, 'partner-wallet');
    await shot(page, '11-partner-home');
    await page.locator(tid('quick-flight')).click();
    await page.locator(tid('search-btn')).waitFor({ timeout: 20000 });
    await page.locator(tid('search-btn')).click();
    await page.locator(tid('result-card')).first().waitFor({ timeout: 30000 });
    await page.locator(tid('result-card')).first().click();
    await page.locator(tid('continue-btn')).click();
    await page.locator(tid('booking-as')).waitFor();
    const email = await page.locator(tid('c-email')).inputValue();
    if (email) throw new Error('customer email should start empty for a partner, got ' + email);
    await fillTravellers(page, { 'p0-first_name': 'Meena', 'p0-last_name': 'Iyer', 'c-email': 'meena.customer@example.com' });
    await page.locator(tid('to-payment')).click();
    await page.locator(tid('pay-total')).waitFor({ timeout: 20000 });
    await shot(page, '12-partner-payment');
    const r = await payAndConfirm(page, 'fmt_wallet');
    await page.getByText('Ticket without fare').waitFor({ timeout: 10000 });
    await shot(page, '13-partner-confirmed');
    // the booking shows in the partner's Bookings, found by its ID
    await page.goto(APP + '/trips');
    await page.locator(tid('trips-search')).fill(r.ref);
    await page.waitForTimeout(1500);
    await page.locator(tid('trip-row')).first().waitFor({ timeout: 20000 });
    const rows = await page.locator(tid('trip-row')).count();
    await page.goto(APP + '/partner');
    await page.locator(tid('partner-recent')).first().waitFor({ timeout: 20000 });
    await page.waitForTimeout(1000);
    const walletAfter = await text(page, 'partner-wallet');
    if (num(walletAfter) >= num(walletBefore)) throw new Error(`wallet not charged: ${walletBefore} -> ${walletAfter}`);
    return { ...r, rows, walletBefore, walletAfter, count: await text(page, 'partner-count') };
  });

  await run('customer login does not show the Partner tab', async (page) => {
    await signIn(page);
    await page.goto(APP + '/account');
    await page.locator(tid('acct-name')).waitFor();
    const partnerTab = await page.locator('a[href="/partner"]').count();
    if (partnerTab) throw new Error('customer sees Partner tab');
    return { partnerTab };
  });

  fs.writeFileSync(path.join(OUT, 'app_results.json'), JSON.stringify(results, null, 2));
  const bad = results.filter((r) => !r.ok);
  console.log(`\n${results.length - bad.length}/${results.length} passed`);
  process.exit(bad.length ? 1 : 0);
})();
