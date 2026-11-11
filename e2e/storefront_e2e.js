// Storefront end-to-end run: for EVERY template, drive the real booking flow
// in a browser against the local API: home search -> results -> details
// (bus: seat map) -> travellers (guest checkout, or sign in) -> payment ->
// confirmation, and check the booking really ended up ticketed. Services,
// payment methods and guest/sign-in rotate across templates so every service
// and every page layout is booked through. Also checks each template's pages
// at phone width for horizontal overflow, and that templates stay distinct.
// Env: API_URL, SF_URL, RESULTS_DIR. Run via e2e/run.sh.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const API = (process.env.API_URL || 'http://127.0.0.1:8010') + '/api';
const SF = process.env.SF_URL || 'http://127.0.0.1:8030';
const OUT = path.join(process.env.RESULTS_DIR || 'e2e/results', 'storefront');
fs.mkdirSync(OUT, { recursive: true });
const ONLY = process.env.TEMPLATES ? process.env.TEMPLATES.split(',').map(Number) : null;

const ORDER = ['flight', 'hotel', 'bus', 'cab', 'holiday', 'cruise', 'activity', 'insurance', 'visa'];
const GUEST_OK = ['flight', 'hotel', 'bus', 'holiday'];
const PAY = ['upi', 'credit_card', 'fmt_wallet', 'netbanking', 'debit_card'];
const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok: !!ok, detail: String(detail).slice(0, 300) }); console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok ? '' : '  -> ' + detail)); };

async function apiGet(p, token) {
  const r = await fetch(API + p, { headers: { Accept: 'application/json', ...(token ? { Authorization: 'Bearer ' + token } : {}) } });
  return [r.status, await r.json().catch(() => null)];
}

(async () => {
  const { TEMPLATES, AXES } = await import(path.resolve(__dirname, '../storefront/js/templates.js'));
  // distinctness: every pair of templates differs on >= 4 page layouts
  let minDiff = 99;
  for (let a = 0; a < TEMPLATES.length; a++) for (let b = a + 1; b < TEMPLATES.length; b++) {
    let d = 0; for (const k in AXES) if (TEMPLATES[a].layout[k] !== TEMPLATES[b].layout[k]) d++;
    minDiff = Math.min(minDiff, d);
  }
  check(`${TEMPLATES.length} templates (50+)`, TEMPLATES.length >= 50, TEMPLATES.length);
  check(`every pair of templates differs on at least 4 of 7 page layouts (min ${minDiff})`, minDiff >= 4, minDiff);
  for (const axis in AXES) {
    const used = new Set(TEMPLATES.map((t) => t.layout[axis]));
    check(`every ${axis} layout is used by some template`, used.size === AXES[axis].length, [...used].join());
  }

  // Guests type the email and mobile of an EXISTING account (live bug: that
  // signed them in to it, and a used mobile blocked them). Neither may matter.
  const login = await fetch(API + '/auth/login', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'customer@fyndmytrip.com', password: 'password' }) }).then((r) => r.json());
  const OWNER = login.user;
  check('seeded customer can sign in (used as the existing account for guest runs)', !!(OWNER && OWNER.id), JSON.stringify(login).slice(0, 200));

  const browser = await chromium.launch();
  const run = Date.now().toString(36);
  const list = TEMPLATES.filter((t) => !ONLY || ONLY.includes(t.id));
  for (const t of list) {
    const i = t.id - 1;
    const svc = ORDER[i % ORDER.length];
    const asGuest = GUEST_OK.includes(svc) && i % 2 === 0;
    const method = PAY[i % PAY.length];
    const tag = `T${String(t.id).padStart(2, '0')} ${t.name} [${svc}${asGuest ? ', guest' : ', signed in'}, ${method}]`;
    const ctx = await browser.newContext({ viewport: { width: 1360, height: 900 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => { if (m.type() === 'warning' && m.text().startsWith('[toast]')) errors.push(m.text()); if (m.type() === 'error' && !/ERR_TUNNEL|ERR_NAME|net::|Failed to load resource/.test(m.text())) errors.push(m.text()); });
    await page.route('**/config.js', (r) => r.fulfill({ contentType: 'application/javascript', body: `window.FMT_CONFIG={apiBase:'${API}'}` }));
    await page.route(/fonts\.(googleapis|gstatic)\.com|picsum\.photos/, (r) => r.fulfill({ status: 200, body: '' }));
    const shot = (n) => page.screenshot({ path: path.join(OUT, `t${String(t.id).padStart(2, '0')}-${n}.png`), fullPage: n === 'home' });
    try {
      await page.goto(`${SF}/?template=${t.id}#/?s=${svc}`);
      await page.waitForSelector('form[data-search]');
      const applied = await page.evaluate(() => ({ ...document.documentElement.dataset }));
      check(`${tag}: template layouts applied`, Object.keys(AXES).every((k) => applied[k] === t.layout[k]), JSON.stringify(applied));
      await page.waitForTimeout(400);
      await shot('home');
      if (svc === 'holiday') await page.fill('form[data-search] [name=q]', 'Goa');
      await page.click('form[data-search] button[type=submit]');
      await page.waitForSelector('[data-select]', { timeout: 20000 });
      const n = await page.locator('[data-select]').count();
      check(`${tag}: search shows real results (${n})`, n > 0, n);
      await shot('results');
      await page.locator('[data-select]').first().click();
      await page.waitForSelector('[data-continue]');
      if (svc === 'bus') {
        await page.waitForSelector('[data-seat]:not([disabled])');
        await page.locator('[data-seat]:not([disabled]):not(.fem)').first().click();
      }
      await shot('details');
      await page.locator('[data-continue]').first().click();
      await page.waitForSelector('form[data-trav]');
      if (!asGuest) {
        await page.fill('[name=login_email]', 'customer@fyndmytrip.com');
        await page.fill('[name=login_password]', 'password');
        await page.click('[data-login]');
        await page.waitForSelector('.signed');
      }
      // fill every traveller field (stepper layout reveals steps one by one)
      const fill = async () => {
        await page.$$eval('form[data-trav] details', (ds) => ds.forEach((d) => { d.open = true; }));
        for (const el of await page.locator('[data-pax] input:visible').all()) {
          const name = await el.getAttribute('name');
          await el.fill(name === 'age' ? '31' : name === 'passport_number' ? 'Z' + String(1000000 + t.id) : name === 'date_of_birth' ? '1990-04-12' : name === 'nationality' ? 'Indian' : name === 'last_name' ? 'Traveller' : name === 'first_name' ? 'Asha' : 'Asha Traveller');
        }
      };
      await fill();
      if (asGuest) await page.fill('[name=guest_name]', 'Asha Traveller');
      if (t.layout.travellers === 'stepper') await page.click('[data-step="0"] [data-next="1"]');
      // Odd guest runs reuse the existing account's email and mobile.
      const reuse = asGuest && t.id % 4 === 1 && OWNER;
      if (asGuest) await page.fill('[name=contact_email]', reuse ? OWNER.email : `guest-${run}-${t.id}@example.com`);
      await page.fill('[name=contact_phone]', reuse && OWNER.phone ? OWNER.phone : '9' + String(Date.now() + t.id).slice(-9));
      if (t.layout.travellers === 'stepper') await page.click('[data-step="1"] [data-next="2"]');
      await shot('travellers');
      await page.click('form[data-trav] button[type=submit]');
      {
        await page.waitForSelector('.pay', { timeout: 20000 });
        await shot('payment');
        const walletLogin = await page.locator('[data-wallet-login]').count();
        check(`${tag}: ${asGuest ? 'guest sees "Sign in to use your wallet balance"' : 'signed-in customer gets no sign-in prompt'}`, asGuest ? walletLogin === 1 : walletLogin === 0, walletLogin);
        // wallet is only offered when it covers the booking and the trip is domestic
        const walletShown = await page.locator('[data-pm=fmt_wallet], input[value=fmt_wallet], option[value=fmt_wallet]').count();
        const m = method === 'fmt_wallet' && !walletShown ? 'upi' : method;
        if (t.layout.payment === 'minimal') await page.selectOption('[data-pm-select]', m);
        else if (t.layout.payment === 'radios') await page.check(`input[name=pm][value=${m}]`);
        else if (t.layout.payment === 'accordion') await page.$eval(`[data-acc=${m}]`, (d) => { d.open = true; });
        if (t.layout.payment === 'accordion') await page.click(`[data-acc=${m}] [data-pay-now]`);
        else { if (!['minimal', 'radios'].includes(t.layout.payment)) await page.click(`[data-pm=${m}]`); await page.click('[data-pay]'); }
      }
      await page.waitForSelector('.done-card', { timeout: 30000 });
      await page.waitForTimeout(300);
      await shot('confirmation');
      const status = (await page.textContent('[data-status]')).trim();
      const ref = (await page.textContent('[data-ref]')).trim();
      check(`${tag}: booking ${ref} paid -> ${status}`, ['holiday', 'visa'].includes(svc) ? ['confirmed', 'ticketed'].includes(status) : status === 'ticketed', status);
      // the API agrees
      const token = await page.evaluate(() => JSON.parse(sessionStorage.getItem('fmt:token')));
      if (asGuest) {
        // A guest is never signed in: no account in the header, the session
        // is a guest one, and it can't open any account page.
        const acct = (await page.textContent('.acct')).trim();
        check(`${tag}: guest is not shown as signed in`, /sign in/i.test(acct), acct);
        const [mc, me] = await apiGet('/auth/me', token);
        check(`${tag}: guest session is not an account${reuse ? ' (existing email + mobile used)' : ''}`, mc === 200 && me.data.is_guest === true && me.data.email === null && (!OWNER || me.data.id !== OWNER.id), JSON.stringify(me).slice(0, 200));
        const [c] = await apiGet('/my-bookings?per_page=100', token);
        check(`${tag}: guest session can't open My bookings`, c === 401, c);
      } else {
        const [c, mine] = await apiGet('/my-bookings?per_page=100', token);
        const rows = (mine && (mine.data?.data || mine.data)) || [];
        check(`${tag}: booking appears in the customer's My bookings`, c === 200 && JSON.stringify(rows).includes(ref), c);
      }
      check(`${tag}: no JS errors`, errors.length === 0, errors.join(' | '));
    } catch (e) {
      check(`${tag}: flow completed`, false, e.message.split('\n')[0] + ' | ' + errors.join(' | '));
      await shot('failure').catch(() => {});
    }
    // phone width: no horizontal page scroll on home + results
    try {
      const m = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1 });
      const p = await m.newPage();
      await p.route('**/config.js', (r) => r.fulfill({ contentType: 'application/javascript', body: `window.FMT_CONFIG={apiBase:'${API}'}` }));
      await p.route(/fonts\.(googleapis|gstatic)\.com|picsum\.photos/, (r) => r.fulfill({ status: 200, body: '' }));
      await p.goto(`${SF}/?template=${t.id}#/?s=${svc}`);
      await p.waitForSelector('form[data-search]');
      await p.waitForTimeout(300);
      const over1 = await p.evaluate(() => document.documentElement.scrollWidth - innerWidth);
      if (t.id % 9 === 1) await p.screenshot({ path: path.join(OUT, `t${String(t.id).padStart(2, '0')}-mobile.png`), fullPage: false });
      await p.click('form[data-search] button[type=submit]');
      await p.waitForSelector('[data-select]', { timeout: 20000 });
      const over2 = await p.evaluate(() => document.documentElement.scrollWidth - innerWidth);
      check(`T${t.id}: phone width, no sideways scroll (home ${over1}px, results ${over2}px)`, over1 <= 1 && over2 <= 1, `${over1}/${over2}`);
      await m.close();
    } catch (e) { check(`T${t.id}: phone width`, false, e.message.split('\n')[0]); }
    await ctx.close();
  }
  await browser.close();
  const failed = results.filter((r) => !r.ok);
  fs.writeFileSync(path.join(OUT, '..', 'storefront_results.json'), JSON.stringify({ passed: results.length - failed.length, failed: failed.length, results }, null, 1));
  console.log(`\nstorefront: ${results.length - failed.length} passed, ${failed.length} failed`);
  process.exit(failed.length ? 1 : 0);
})();
