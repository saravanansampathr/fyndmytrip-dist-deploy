const { chromium } = require('playwright');
// Website booking run: every service from the search page through results,
// traveller details, review & pay and the confirmation, as every kind of
// user. Checks that the amount paid is the amount the results page quoted,
// that no JS errors happen, then covers packages, visa, coupons, add-ons,
// split wallet + gateway payment, documents, and an admin cancel + refund.
// Run via e2e/run.sh (needs a freshly reset DB, see reset_db.sh).
const FE = process.env.FE_URL || 'http://127.0.0.1:8020', API = process.env.API_URL || 'http://127.0.0.1:8010';
const OUT = process.env.RESULTS_DIR || '.';
const results = [];
const check = (n, ok, d = '') => { results.push({ n, ok: !!ok, d: String(d).slice(0, 400) }); console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : ' -> ' + d)); };
const money = t => parseFloat(String(t).replace(/[₹,\s]/g, ''));
const near = (a, b) => Math.abs(a - b) <= 1; // the site rounds to whole rupees

async function session(browser, email, ctxOpts = {}, currency = null) {
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, ...ctxOpts });
  if (currency) await ctx.addInitScript(c => { try { localStorage.setItem('fyndmytrip_currency', c); } catch (e) {} }, currency);
  const page = await ctx.newPage();
  const st = { errors: [], offers: {}, holds: [], lastConfirm: null };
  page.on('pageerror', e => st.errors.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource|ERR_|favicon|401/.test(m.text())) st.errors.push('console: ' + m.text()); });
  page.on('dialog', d => d.accept('E2E check'));
  await page.route('https://api-demo.fyndmytrip.in/**', async route => {
    const req = route.request();
    const url = req.url().replace('https://api-demo.fyndmytrip.in', API);
    const headers = { ...req.headers() }; delete headers['host'];
    try {
      const resp = await route.fetch({ url, headers });
      const path = url.replace(API + '/api', '');
      if (req.method() === 'POST' && /\/search$/.test(path)) {
        try {
          const j = await resp.json();
          const lists = j.data?.legs ? j.data.legs.map(l => l.offers) : [j.data?.offers || []];
          lists.flat().forEach(o => { st.offers[o.external_id] = o; });
        } catch (e) { /* not json */ }
      }
      if (req.method() === 'POST' && /bookings$|applications$/.test(path)) st.holds.push(JSON.parse(req.postData() || '{}'));
      await route.fulfill({ response: resp, headers: { ...resp.headers(), 'access-control-allow-origin': '*' } });
    } catch (e) { await route.abort(); }
  });
  if (email) {
    await page.goto(FE + '/login');
    await page.fill('input[type=email]', email);
    await page.fill('input[type=password]', 'password');
    await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type=submit]')]);
    await page.waitForTimeout(700);
  }
  return { ctx, page, st };
}

async function click(page, re, { scope = 'button', nth = 0 } = {}) {
  await page.locator(scope).filter({ hasText: re }).filter({ visible: true }).nth(nth).click();
  await page.waitForLoadState('networkidle'); await page.waitForTimeout(700);
}
const fillIf = async (page, sel, v) => { const l = page.locator(sel); if (await l.count()) await l.first().fill(v); };
const bodyText = page => page.locator('body').innerText();

// Review & pay → wallet (or wallet+gateway when gateway=true) → confirmation.
async function payAndConfirm(page, { gateway = false, partial = null } = {}) {
  if (partial !== null) await page.locator('input[type=number]').first().fill(String(partial));
  const guestWallet = await page.locator('[data-testid=wallet-login]').count();
  if (gateway && partial === null && !guestWallet) await page.locator('input[type=checkbox]').first().uncheck();
  await page.waitForTimeout(300);
  await click(page, /^(Pay|Proceed to payment gateway)/);
  if (await page.getByText('Choose a payment method').count()) {
    await click(page, /UPI/);
    await click(page, /completed payment/);
  }
  await page.getByText(/(Booking|Policy) confirmed|Total paid/).first().waitFor({ timeout: 15000 }).catch(() => {});
  const t = await bodyText(page);
  const m = t.match(/Total paid\s*\n\s*(₹[\d,.]+)/);
  return { confirmed: /(Booking|Policy) confirmed/.test(t), paid: m ? money(m[1]) : NaN, code: (t.match(/Booking ID\s+(FMT\w+)/i) || [])[1], text: t };
}

const contact = async page => { await fillIf(page, '#contact_email', 'e2e@example.com'); await fillIf(page, '#contact_phone', '9876543210'); };

const flows = {
  async flight(page, st, o = {}) {
    await page.goto(FE + '/flights'); await page.waitForLoadState('networkidle');
    await click(page, /^Search flights$/);
    await click(page, /^Book$/);
    if (o.guest) { await fillIf(page, '#guest_name', 'Guest Traveller'); await page.fill('#contact_email', o.guestEmail || `guest${Date.now()}@example.com`); await page.fill('#contact_phone', o.guestPhone || '9' + String(Date.now()).slice(-9)); }
    else await contact(page);
    await page.fill('#first-name-0', 'Asha'); await page.fill('#last-name-0', 'Rao');
    await click(page, /Continue to seats/);
    let extras = 0;
    if (o.extras) { await page.getByText('Priority check-in & boarding').click(); await page.waitForTimeout(400); extras = 300; }
    await click(page, /Continue to review/);
    let discount = 0;
    if (o.coupon) {
      await page.fill('input[placeholder="e.g. FLYFIRST10"]', 'FLYFIRST10'); await click(page, /^Apply$/);
      const m = (await bodyText(page)).match(/Coupon \(FLYFIRST10\)\s*\n\s*-₹([\d,.]+)/); discount = m ? money(m[1]) : NaN;
      check(`${o.label} coupon FLYFIRST10 gives a discount`, discount > 0, discount);
    }
    if (o.guest) {
      // Guests get a sign-in prompt instead of wallet controls.
      const t = await bodyText(page);
      check(`${o.label}: guest sees "Log in to use your wallet balance", not the wallet`, /Log in to use your wallet balance/.test(t) && !/Use my wallet balance/.test(t), t.slice(0, 200));
    } else check(`${o.label}: signed-in customer sees the wallet option`, /Use my wallet balance/.test(await bodyText(page)));
    const r = await payAndConfirm(page, o);
    const offer = st.offers[st.holds.at(-1)?.legs?.[0]?.external_id];
    return { ...r, expected: offer ? parseFloat(offer.gross_amount) + extras - discount : NaN };
  },
  async hotel(page, st) {
    await page.goto(FE + '/hotels'); await page.waitForLoadState('networkidle');
    await page.fill('#hotel-rooms', '2'); await page.fill('#hotel-guests', '2');
    await click(page, /^Search hotels$/); await click(page, /^Select$/);
    await contact(page);
    await page.fill('#guest-name-0', 'Asha Rao'); await page.fill('#guest-age-0', '34');
    await page.fill('#guest-name-1', 'Ravi Rao'); await page.fill('#guest-age-1', '36');
    await click(page, /^Continue to review$/);
    const r = await payAndConfirm(page);
    return { ...r, expected: parseFloat(st.offers[st.holds.at(-1)?.external_id]?.gross_amount) };
  },
  async bus(page) {
    await page.goto(FE + '/buses'); await page.waitForLoadState('networkidle');
    await click(page, /^Search buses$/); await click(page, /^View seats$/);
    const seats = page.locator('button[title^="Seat "]:not([disabled])');
    let expected = 0;
    for (const i of [0, 1]) { const t = await seats.nth(i).getAttribute('title'); expected += money(t.split('—').pop()); await seats.nth(i).click(); await page.waitForTimeout(300); }
    await click(page, /Continue to passenger details/);
    await contact(page);
    for (const i of [0, 1]) { await page.fill(`#passenger-name-${i}`, ['Asha Rao', 'Ravi Rao'][i]); await page.fill(`#passenger-age-${i}`, '35'); }
    await click(page, /^Continue to review$/);
    return { ...(await payAndConfirm(page)), expected };
  },
};
for (const [svc, path, btn, extra] of [
  ['cab', '/cabs', /^Search cabs$/, null],
  ['cruise', '/cruises', /^Search sailings$/, null],
  ['activity', '/activities', /^Search activities$/, ['#activity-travellers', '2']],
  ['insurance', '/insurance', /^Search plans$/, null],
]) {
  flows[svc] = async (page, st) => {
    await page.goto(FE + path); await page.waitForLoadState('networkidle');
    if (extra) await page.fill(extra[0], extra[1]);
    await click(page, btn); await click(page, /^Select$/);
    await contact(page);
    for (let i = 0; i < 4; i++) {
      if (!(await page.locator(`#passenger-name-${i}`).count())) break;
      await page.fill(`#passenger-name-${i}`, `Traveller ${i + 1}`);
      await fillIf(page, `#passenger-age-${i}`, '35');
    }
    await click(page, /^Continue to review$/);
    const r = await payAndConfirm(page);
    return { ...r, expected: parseFloat(st.offers[st.holds.at(-1)?.external_id]?.gross_amount) };
  };
}

async function run(browser, email, svc, opts = {}) {
  const label = `${opts.label || svc} as ${email || 'guest'}`;
  const { ctx, page, st } = await session(browser, email);
  try {
    const r = await flows[svc](page, st, { ...opts, label, guest: !email });
    check(`${label}: booking confirmed (${r.code || '-'})`, r.confirmed, r.text.slice(0, 300));
    check(`${label}: paid ₹${r.paid} = quoted ₹${r.expected?.toFixed?.(2)}`, near(r.paid, r.expected), `paid ${r.paid} expected ${r.expected}`);
    if (!email) {
      // A guest is never signed in, even with an existing account's email/mobile.
      const s = await page.evaluate(() => ({ user: localStorage.getItem('fyndmytrip_user'), token: localStorage.getItem('fyndmytrip_token') }));
      check(`${label}: guest is not shown as signed in`, !s.user, s.user);
      const me = await fetch(API + '/api/auth/me', { headers: { Accept: 'application/json', Authorization: 'Bearer ' + s.token } }).then(x => x.json()).catch(() => null);
      check(`${label}: guest session is not an account`, me?.data?.is_guest === true && me.data.email === null, JSON.stringify(me).slice(0, 200));
    }
  } catch (e) {
    check(`${label}: completes`, false, e.message.split('\n')[0]);
    await page.screenshot({ path: `${OUT}/shots/fail-${svc}-${(email || 'guest').split('@')[0]}.png`, fullPage: true }).catch(() => {});
  }
  check(`${label}: no JS errors`, st.errors.length === 0, st.errors.join(' | '));
  await ctx.close();
}

(async () => {
  require('fs').mkdirSync(OUT + '/shots', { recursive: true });
  const browser = await chromium.launch();

  for (const svc of ['flight', 'hotel', 'bus', 'cab', 'cruise', 'activity', 'insurance']) await run(browser, 'customer@fyndmytrip.com', svc);
  for (const email of ['admin@fyndmytrip.com', 'staff@fyndmytrip.com', 'admin@acmetravel.fyndmytrip.com', 'agent@acmetravel.fyndmytrip.com',
    'subagent@acmetravel.fyndmytrip.com', 'agent@fyndmytrip.com', 'api@fyndmytrip.com']) {
    await run(browser, email, 'flight');
    await run(browser, email, 'hotel');
  }
  await run(browser, null, 'flight', { gateway: true, label: 'flight (guest checkout, gateway)' });
  {
    // Live bug: a guest typing an existing account's email was signed in to it.
    const owner = await fetch(API + '/api/auth/login', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'customer@fyndmytrip.com', password: 'password' }) }).then(x => x.json());
    await run(browser, null, 'flight', { label: 'flight (guest with an existing account email + mobile)', guestEmail: owner.user.email, guestPhone: owner.user.phone || '9876543210' });
  }
  await run(browser, 'customer@fyndmytrip.com', 'flight', { coupon: true, label: 'flight with coupon' });
  await run(browser, 'customer@fyndmytrip.com', 'flight', { extras: true, label: 'flight with add-on' });
  await run(browser, 'customer@fyndmytrip.com', 'flight', { partial: 1000, label: 'flight wallet ₹1000 + gateway' });
  await run(browser, 'customer@fyndmytrip.com', 'flight', { gateway: true, label: 'flight gateway only' });

  // ---- multi-currency: the visitor's currency is picked from their country, and they pay in it ----
  {
    const { ctx, page, st } = await session(browser, null, { timezoneId: 'Asia/Dubai' });
    await page.goto(FE + '/'); await page.waitForLoadState('networkidle'); await page.waitForTimeout(1500);
    const cur = await page.evaluate(() => localStorage.getItem('fyndmytrip_currency'));
    check('currency: a first visit from Dubai switches to AED', cur === 'AED', cur);
    check('currency: no JS errors on first visit', st.errors.length === 0, st.errors.join(' | '));
    await ctx.close();
  }
  {
    const { ctx, page, st } = await session(browser, 'customer@fyndmytrip.com', {}, 'USD');
    let payBody = null;
    page.on('request', r => { if (r.method() === 'POST' && /\/pay$/.test(r.url())) payBody = r.postData(); });
    await page.goto(FE + '/flights'); await page.waitForLoadState('networkidle');
    await click(page, /^Search flights$/); await click(page, /^Book$/);
    await contact(page);
    await page.fill('#first-name-0', 'Asha'); await page.fill('#last-name-0', 'Rao');
    await click(page, /Continue to seats/); await click(page, /Continue to review/);
    await page.locator('input[type=checkbox]').first().uncheck(); await page.waitForTimeout(300);
    await click(page, /^(Pay|Proceed to payment gateway)/);
    const note = page.locator('[data-testid=charge-note]');
    const noteText = (await note.count()) ? await note.first().innerText() : '';
    check('currency: payment step says the card is charged in USD', /charged \$[\d,.]+ USD/.test(noteText), noteText || 'no charge note');
    await click(page, /International cards|UPI/); await click(page, /completed payment/);
    const t = await bodyText(page);
    check('currency: USD flight booking confirmed', /Booking confirmed/.test(t), t.slice(0, 200));
    check('currency: the payment call carried currency USD', /"currency":"USD"/.test(payBody || ''), payBody);
    const token = await page.evaluate(() => localStorage.getItem('fyndmytrip_token'));
    const pays = await page.evaluate(async ([api, tk]) => {
      const r = await fetch(api + '/api/my-payments', { headers: { Accept: 'application/json', ...(tk ? { Authorization: 'Bearer ' + tk } : {}) } });
      return r.ok ? r.json() : { status: r.status };
    }, [API, token]);
    const list = pays.data || pays;
    const last = Array.isArray(list) ? list.find(p => p.purpose === 'booking_payment') : null;
    check('currency: My Payments records the USD charge', last && last.charge_currency === 'USD' && parseFloat(last.charge_amount) > 0, JSON.stringify(last || pays).slice(0, 200));
    check('currency: no JS errors paying in USD', st.errors.length === 0, st.errors.join(' | '));
    await ctx.close();
  }

  // ---- holiday package: request booking, then pay in the page ----
  {
    const { ctx, page, st } = await session(browser, 'customer@fyndmytrip.com');
    await page.goto(FE + '/packages/goa-beach-escape-north-south-goa'); await page.waitForLoadState('networkidle');
    await page.fill('main input[type=date]', new Date(Date.now() + 40 * 864e5).toISOString().slice(0, 10));
    await page.fill('input[placeholder="Full name"]', 'Asha Rao');
    await click(page, /Add traveler/); await page.locator('input[placeholder="Full name"]').nth(1).fill('Ravi Rao');
    await page.waitForTimeout(800);
    const twoAdults = money(await page.locator('[data-testid=package-total]').innerText());
    // Goa has no infant tier: the baby must cost a fraction of an adult, not a full fare.
    await click(page, /Add traveler/); await page.locator('input[placeholder="Full name"]').nth(2).fill('Baby Rao');
    await page.locator('select').filter({ has: page.locator('option[value=infant]') }).nth(2).selectOption('infant');
    await page.waitForTimeout(1200);
    const est = money(await page.locator('[data-testid=package-total]').innerText());
    check(`holiday: infant adds ₹${(est - twoAdults).toFixed(2)}, not an adult fare (2 adults ₹${twoAdults})`, est > twoAdults && est - twoAdults < twoAdults / 2 * 0.2, `${twoAdults} -> ${est}`);
    check('holiday: pricing table lists infant price', /Infant/.test(await bodyText(page)));
    await click(page, /^Request booking$/);
    check('holiday: payment step shown after request', /Pay for your holiday/.test(await bodyText(page)));
    await page.fill('input[aria-label="Amount from wallet"]', '5000');
    await click(page, /Proceed to payment gateway/); await click(page, /UPI/); await click(page, /completed payment/);
    const t = await bodyText(page);
    check('holiday: paid and confirmed', /Payment received — booking confirmed/.test(t), t.slice(0, 200));
    const paid = money((t.match(/Total paid (₹[\d,.]+)/) || [])[1]);
    check(`holiday: paid ₹${paid} = estimate ₹${est}`, near(paid, est));
    check('holiday: no JS errors', st.errors.length === 0, st.errors.join(' | '));
    await ctx.close();
  }

  // ---- visa: apply now → applicants → pay ----
  {
    const { ctx, page, st } = await session(browser, 'customer@fyndmytrip.com');
    await page.goto(FE + '/visa'); await page.waitForLoadState('networkidle');
    await page.fill('main input', 'Thailand'); await page.waitForTimeout(1200);
    await click(page, /^Apply now$/);
    await page.fill('input[aria-label="Travel date"]', new Date(Date.now() + 30 * 864e5).toISOString().slice(0, 10));
    await page.fill('input[placeholder="Full name (as on passport)"]', 'Asha Rao'); await page.fill('input[placeholder="Passport no."]', 'Z1234567');
    await click(page, /Add applicant/);
    await page.locator('input[placeholder="Full name (as on passport)"]').nth(1).fill('Ravi Rao'); await page.locator('input[placeholder="Passport no."]').nth(1).fill('Y7654321');
    await click(page, /Continue to payment/);
    await click(page, /^Pay ₹/);
    const t = await bodyText(page);
    check('visa: application paid and confirmed', /Payment received — booking confirmed/.test(t), t.slice(0, 300));
    check('visa: no JS errors', st.errors.length === 0, st.errors.join(' | '));
    await ctx.close();
  }
  {
    const { ctx, page } = await session(browser, null);
    await page.goto(FE + '/visa'); await page.waitForLoadState('networkidle');
    await page.fill('main input', 'Thailand'); await page.waitForTimeout(1200);
    await click(page, /^Apply now$/);
    check('visa: guests are asked to sign in', /to apply for this visa/.test(await bodyText(page)));
    await ctx.close();
  }

  // ---- My Trips: documents only for paid bookings ----
  {
    const { ctx, page, st } = await session(browser, 'customer@fyndmytrip.com');
    await page.goto(FE + '/my-bookings'); await page.waitForLoadState('networkidle'); await page.waitForTimeout(800);
    const rows = await page.locator('tbody tr').allInnerTexts();
    const paidRows = rows.filter(r => /\tticketed\t|\tconfirmed\t/.test(r));
    check(`my trips lists bookings (${rows.length})`, rows.length > 10);
    check('my trips: every paid booking has an invoice', paidRows.length > 0 && paidRows.every(r => /Invoice/.test(r)), paidRows.filter(r => !/Invoice/.test(r)).join(' || '));
    check('my trips: no JS errors', st.errors.length === 0, st.errors.join(' | '));
    await ctx.close();
  }

  // ---- My Trips: pay for a held booking, and cancel a paid one ----
  {
    const { ctx, page, st } = await session(browser, 'customer@fyndmytrip.com');
    await page.goto(FE + '/packages/kerala-backwaters-hills-kochi-munnar-alleppey'); await page.waitForLoadState('networkidle');
    await page.fill('main input[type=date]', new Date(Date.now() + 50 * 864e5).toISOString().slice(0, 10));
    await page.fill('input[placeholder="Full name"]', 'Asha Rao');
    await click(page, /^Request booking$/);
    await page.goto(FE + '/my-bookings'); await page.waitForLoadState('networkidle'); await page.waitForTimeout(800);
    const heldRow = page.locator('tbody tr').filter({ hasText: 'Kerala' }).filter({ has: page.getByRole('button', { name: 'Pay now', exact: true }) }).first();
    check('my trips: held booking shows Pay now', await heldRow.count() > 0, 'no held row with Pay now');
    await heldRow.getByRole('button', { name: 'Pay now', exact: true }).click(); await page.waitForTimeout(500);
    await click(page, /^Pay now ₹/);
    await click(page, /^Pay ₹/);
    let t = await bodyText(page);
    check('my trips: held booking paid from My Trips', /Payment received — your booking is confirmed/.test(t), t.slice(0, 300));
    await click(page, /^Close$/);

    await page.goto(FE + '/my-bookings'); await page.waitForLoadState('networkidle'); await page.waitForTimeout(800);
    const before = (await page.locator('tbody tr').allInnerTexts()).filter(r => /Hotels/.test(r) && /\tticketed\t/.test(r)).length;
    const row = page.locator('tbody tr').filter({ hasText: 'Hotels' }).filter({ hasText: 'ticketed' }).filter({ has: page.getByRole('button', { name: 'Cancel', exact: true }) }).first();
    await row.getByRole('button', { name: 'Cancel', exact: true }).click(); await page.waitForTimeout(500);
    await click(page, /^Cancel booking$/);
    t = await bodyText(page);
    const refund = money((t.match(/Refund to your wallet\s*\n?\s*(₹[\d,.]+)/) || [])[1]);
    check(`my trips: cancellation quote shows refund ₹${refund}`, refund >= 0 && /Cancellation charge/.test(t), t.slice(0, 300));
    await click(page, /^Yes, cancel booking$/);
    t = await bodyText(page);
    check('my trips: booking cancelled by the traveller', /Booking cancelled\./.test(t), t.slice(0, 300));
    await click(page, /^Close$/);
    await page.goto(FE + '/my-bookings'); await page.waitForLoadState('networkidle'); await page.waitForTimeout(800);
    const after = (await page.locator('tbody tr').allInnerTexts()).filter(r => /Hotels/.test(r) && /\tticketed\t/.test(r)).length;
    check(`my trips: cancelled booking leaves the ticketed list (${before} -> ${after})`, after === before - 1);
    check('my trips actions: no JS errors', st.errors.length === 0, st.errors.join(' | '));
    await ctx.close();
  }

  // ---- admin: cancel a paid hotel booking and refund it to the wallet ----
  {
    const { ctx, page, st } = await session(browser, 'admin@fyndmytrip.com');
    const reqs = []; page.on('request', r => { if (r.method() === 'POST' && /reports\/bookings/.test(r.url())) reqs.push(r.url().replace(/^.*?\/api\//, '/')); });
    await page.goto(FE + '/reports'); await page.waitForLoadState('networkidle');
    await page.evaluate(() => [...document.querySelectorAll('button')].find(e => e.innerText.trim() === 'Bookings').click());
    await page.waitForLoadState('networkidle'); await page.waitForTimeout(1200);
    const code = await page.evaluate(() => { const r = [...document.querySelectorAll('tbody tr')].find(tr => /Hotels/.test(tr.innerText) && /ticketed/.test(tr.innerText)); return r && r.querySelector('button')?.innerText.trim(); });
    await page.evaluate(c => [...document.querySelectorAll('button')].find(e => e.innerText.trim() === c).click(), code);
    await page.waitForLoadState('networkidle'); await page.waitForTimeout(1000);
    await click(page, /^Cancel booking$/); await page.fill('textarea', 'Customer asked to cancel'); await click(page, /^Confirm cancel/);
    await click(page, /^Initiate refund$/); await click(page, /^Use quoted amount/); await click(page, /^Credit wallet now$/);
    check(`admin cancels and refunds ${code}`, reqs.some(u => /status$/.test(u)) && reqs.some(u => /refunds$/.test(u)), reqs.join(', '));
    check('admin reports: no JS errors', st.errors.length === 0, st.errors.join(' | '));
    await ctx.close();
  }

  await browser.close();
  const failed = results.filter(r => !r.ok);
  require('fs').writeFileSync(`${OUT}/ui_booking_results.json`, JSON.stringify(results, null, 1));
  console.log(`\nWEBSITE BOOKINGS: ${results.length - failed.length}/${results.length} passed`);
  process.exit(failed.length ? 1 : 0);
})();
