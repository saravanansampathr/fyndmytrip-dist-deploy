const { chromium } = require('playwright');
// Accounts & inventory: the master shares inventories with accounts, the
// switch reaches the agent's flight search, a white-label partner's
// accounts follow the partner, and a partner admin sees only its own
// accounts. Run via e2e/run.sh.
const FE = process.env.FE_URL || 'http://127.0.0.1:8020', API = process.env.API_URL || 'http://127.0.0.1:8010';
const OUT = process.env.RESULTS_DIR || '.';
const results = []; const check = (n, ok, d = '') => { results.push({ n, ok: !!ok, d: String(d).slice(0, 300) }); console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : ' -> ' + d)); };
async function open(browser, viewport) {
  const ctx = await browser.newContext({ viewport: viewport || { width: 1440, height: 900 } });
  const page = await ctx.newPage(); const errors = [];
  page.on('pageerror', e => errors.push('pageerror: ' + e.message));
  await page.route('https://api-demo.fyndmytrip.in/**', async route => {
    const req = route.request(); const headers = { ...req.headers() }; delete headers['host'];
    try { const resp = await route.fetch({ url: req.url().replace('https://api-demo.fyndmytrip.in', API), headers }); await route.fulfill({ response: resp, headers: { ...resp.headers(), 'access-control-allow-origin': '*' } }); } catch (e) { await route.abort(); }
  });
  return { ctx, page, errors };
}
async function login(page, email) {
  await page.goto(FE + '/login'); await page.fill('input[type=email]', email); await page.fill('input[type=password]', 'password');
  await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type=submit]')]); await page.waitForTimeout(800);
}
async function apiToken(email) {
  const r = await fetch(API + '/api/auth/login', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ email, password: 'password' }) });
  const j = await r.json(); return j.token || j.data?.token || j.access_token;
}
async function flightSuppliers(token) {
  const d = new Date(Date.now() + 12 * 864e5).toISOString().slice(0, 10);
  const r = await fetch(API + '/api/flights/search', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json', authorization: 'Bearer ' + token }, body: JSON.stringify({ origin: 'DEL', destination: 'BOM', departure_date: d }) });
  const j = await r.json(); return [...new Set((j.data?.legs?.[0]?.offers || []).map(o => o.supplier_code))];
}
const seen = (page, text) => page.waitForSelector(`text=${text}`, { timeout: 8000 }).then(() => true, () => false);
const row = (page, name) => page.locator('tr', { has: page.locator(`p:text-is("${name}")`) });
(async () => {
  require('fs').mkdirSync(OUT + '/shots', { recursive: true });
  const browser = await chromium.launch();
  const agentToken = await apiToken('agent@fyndmytrip.com');
  check('direct agent sees mock flights before', (await flightSuppliers(agentToken)).includes('MOCKAIR'));

  let { ctx, page, errors } = await open(browser);
  await login(page, 'admin@fyndmytrip.com');
  check('menu has Accounts & inventory', await seen(page, 'Accounts & inventory'));
  await page.goto(FE + '/accounts'); await page.waitForLoadState('networkidle');
  check('page loads', await seen(page, 'Connect API Demo Partner'));
  check('inventory table', await seen(page, 'Give to all'));
  await page.screenshot({ path: OUT + '/shots/accounts-admin.png', fullPage: true });

  // Switch Mock Flight off for the direct agent with one click.
  await row(page, 'Direct Agent (no white-label)').locator('button[data-inv="MOCKAIR"]').click();
  check('chip turns off', await page.waitForSelector('tr:has(p:text-is("Direct Agent (no white-label)")) button[data-inv="MOCKAIR"][data-state="off"]', { timeout: 8000 }).then(() => true, () => false));
  check('agent search no longer has it', !(await flightSuppliers(agentToken)).includes('MOCKAIR'));

  // Manage the white-label partner: Mock Flight off and only flights + hotels.
  await row(page, 'Acme Travel (White-Label demo)').locator('button:text-is("Manage")').click();
  check('detail opens', await seen(page, 'Services this account may sell'));
  const detail = page.locator('[data-testid=account-detail]');
  await detail.locator('[data-inv-row="MOCKAIR"] select').selectOption('off');
  for (const svc of ['Buses', 'Cabs', 'Holidays', 'Insurance', 'Visa', 'Cruises', 'Activities']) await detail.locator(`label:has-text("${svc}") input`).uncheck();
  await page.click('button:text-is("Save account")'); check('account saves', await seen(page, 'Saved. Applies'));
  await page.waitForTimeout(800);
  await page.screenshot({ path: OUT + '/shots/accounts-manage.png', fullPage: true });
  check('sub-agent follows the partner', await page.waitForSelector('tr:has(p:text-is("Downtown Sub-Agent — Kiosk 3")) button[data-inv="MOCKAIR"][data-state="off_above"]', { timeout: 8000 }).then(() => true, () => false));
  const subToken = await apiToken('subagent@acmetravel.fyndmytrip.com');
  check('sub-agent search no longer has it', !(await flightSuppliers(subToken)).includes('MOCKAIR'));
  const svc = await (await fetch(API + '/api/account/services', { headers: { accept: 'application/json', authorization: 'Bearer ' + subToken } })).json();
  check('services endpoint follows the partner', JSON.stringify(svc.data.services) === JSON.stringify(['flight', 'hotel']), JSON.stringify(svc.data.services));
  check('no page errors (admin)', errors.length === 0, errors.join(' | '));
  await ctx.close();

  ({ ctx, page, errors } = await open(browser));
  await login(page, 'admin@acmetravel.fyndmytrip.com');
  await page.goto(FE + '/accounts'); await page.waitForLoadState('networkidle');
  check('partner admin sees its agent', await seen(page, 'Acme Agent — Downtown Branch'));
  check('partner admin does not see other accounts', (await page.locator('p:text-is("Direct Agent (no white-label)")').count()) === 0);
  check('no page errors (partner)', errors.length === 0, errors.join(' | '));
  await ctx.close();

  ({ ctx, page, errors } = await open(browser, { width: 390, height: 844 }));
  await login(page, 'admin@fyndmytrip.com');
  await page.goto(FE + '/accounts'); await page.waitForLoadState('networkidle'); await seen(page, 'Give to all');
  await page.screenshot({ path: OUT + '/shots/accounts-phone.png', fullPage: false });
  check('no page errors (phone)', errors.length === 0, errors.join(' | '));
  await ctx.close();

  await browser.close();
  require('fs').writeFileSync(OUT + '/ui_accounts_results.json', JSON.stringify(results, null, 2));
  const failed = results.filter(r => !r.ok).length; console.log(`${results.length - failed}/${results.length} passed`); process.exit(failed ? 1 : 0);
})();
