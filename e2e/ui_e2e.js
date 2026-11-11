const { chromium } = require('playwright');
// Browser end-to-end run against the built frontend; API calls to the
// production host are re-routed to the local backend. Run via e2e/run.sh.
const FE = process.env.FE_URL || 'http://127.0.0.1:8020', API = process.env.API_URL || 'http://127.0.0.1:8010';
const OUT = process.env.RESULTS_DIR || '.';
const results = []; const check = (n, ok, d = '') => { results.push({ n, ok: !!ok, d: String(d).slice(0, 300) }); console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : ' -> ' + d)); };

async function session(browser, email, password = 'password') {
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource|ERR_|favicon/.test(m.text())) errors.push('console: ' + m.text()); });
  await page.route('https://api-demo.fyndmytrip.in/**', async route => {
    const req = route.request();
    const url = req.url().replace('https://api-demo.fyndmytrip.in', API);
    const headers = { ...req.headers() }; delete headers['host'];
    try {
      const resp = await route.fetch({ url, headers });
      await route.fulfill({ response: resp, headers: { ...resp.headers(), 'access-control-allow-origin': '*' } });
    } catch (e) { await route.abort(); }
  });
  await page.goto(FE + '/login');
  await page.fill('input[type=email]', email);
  await page.fill('input[type=password]', password);
  await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type=submit]')]);
  await page.waitForTimeout(800);
  const token = await page.evaluate(() => JSON.stringify(Object.keys(localStorage)));
  check(`UI login ${email}`, !page.url().includes('/login'), page.url() + ' ' + token);
  return { ctx, page, errors };
}

(async () => {
  require('fs').mkdirSync(OUT + '/shots', { recursive: true });
  const browser = await chromium.launch();

  // ---- super admin: create a user-targeted rule via the UI, then preview it ----
  let { ctx, page, errors } = await session(browser, 'admin@fyndmytrip.com');
  await page.goto(FE + '/markup-rules'); await page.waitForLoadState('networkidle');
  check('admin sees margin rules form', await page.locator('[data-testid=markup-form]').isVisible());
  check('nav shows "Margin rules"', await page.getByRole('link', { name: 'Margin rules' }).count() > 0);
  const audiences = await page.locator('#markup-audience option').allTextContents();
  check('audience list covers every user type', ['Everyone','Admins','Staff users','White-label partners','API partners','Agents','Sub-agents','Direct customers (B2C)'].every(a => audiences.includes(a)), audiences.join('|'));
  const services = await page.locator('#markup-module option').allTextContents();
  check('service list has "All services" + modules', services.includes('All services') && services.includes('Hotels'), services.join('|'));
  await page.screenshot({ path: OUT + '/shots/01-admin-margin-page.png', fullPage: true });

  await page.fill('#markup-name', 'UI: Direct agent hotels');
  await page.selectOption('#markup-module', 'hotel');
  await page.selectOption('#markup-audience', 'agent');
  const acct = await page.locator('#markup-account option', { hasText: 'Direct' }).first().getAttribute('value');
  await page.selectOption('#markup-account', acct);
  const usr = await page.locator('#markup-user option', { hasText: 'agent@fyndmytrip.com' }).first().getAttribute('value');
  await page.selectOption('#markup-user', usr);
  await page.getByRole('button', { name: 'Flat amount per booking (PNR)' }).click();
  await page.fill('#markup-amount', '300');
  await Promise.all([page.waitForResponse(r => r.url().includes('/markup-rules') && r.request().method() === 'POST'), page.getByRole('button', { name: 'Create rule' }).click()]);
  await page.waitForTimeout(800);
  const row = page.locator('[data-testid=markup-table] tr', { hasText: 'UI: Direct agent hotels' });
  check('created rule appears with chosen user + account', await row.count() === 1 && /User: .*agent@fyndmytrip.com/.test(await row.innerText()) && /Account:/.test(await row.innerText()), await row.count() ? await row.innerText() : 'no row');

  await page.selectOption('#preview-module', 'hotel');
  await page.selectOption('#preview-account', acct);
  await page.selectOption('#preview-user', usr);
  await page.fill('#preview-net', '5000');
  await page.getByRole('button', { name: 'Check margin' }).click();
  await page.waitForSelector('[data-testid=markup-preview-result]');
  const pv = await page.locator('[data-testid=markup-preview-result]').innerText();
  check('preview shows ₹300 margin for the chosen agent on hotels', pv.includes('₹300.00') && pv.includes('₹5300.00'), pv);
  await page.screenshot({ path: OUT + '/shots/02-admin-rule-created-and-preview.png', fullPage: true });

  // edit flow
  await row.getByRole('button', { name: 'Edit' }).click();
  await page.fill('#markup-amount', '275');
  await Promise.all([page.waitForResponse(r => r.url().includes('/markup-rules/') && r.request().method() === 'PUT'), page.getByRole('button', { name: 'Save changes' }).click()]);
  await page.waitForTimeout(600);
  check('edit saves new amount', (await page.locator('[data-testid=markup-table] tr', { hasText: 'UI: Direct agent hotels' }).innerText()).includes('₹275'));
  // filters
  await page.selectOption('#filter-module', 'hotel');
  const filtered = await page.locator('[data-testid=markup-table] tbody tr').count();
  check('service filter narrows the table', filtered >= 1, filtered);
  check('no JS errors on admin margin page', errors.length === 0, errors.join(' | '));
  await ctx.close();

  // ---- white-label admin, agent, API partner can manage; sub-agent read-only; customer has no nav entry ----
  for (const [email, canManage] of [['admin@acmetravel.fyndmytrip.com', true], ['agent@fyndmytrip.com', true], ['api@fyndmytrip.com', true], ['subagent@acmetravel.fyndmytrip.com', false]]) {
    ({ ctx, page, errors } = await session(browser, email));
    await page.goto(FE + '/markup-rules'); await page.waitForLoadState('networkidle'); await page.waitForTimeout(500);
    const form = await page.locator('[data-testid=markup-form]').isVisible();
    const ro = await page.locator('[data-testid=markup-readonly]').isVisible();
    check(`${email} margin page ${canManage ? 'editable' : 'read-only'}`, canManage ? form && !ro : !form && ro, `form=${form} ro=${ro}`);
    if (canManage) {
      const owner = await page.locator('#markup-owner').count();
      check(`${email} cannot pick another owner tenant`, owner === 0);
      const users = await page.locator('#markup-user option').allTextContents();
      check(`${email} user picker limited to own hierarchy`, !users.some(u => u.includes('admin@fyndmytrip.com')), users.join('|'));
    }
    check(`no JS errors for ${email}`, errors.length === 0, errors.join(' | '));
    await page.screenshot({ path: `${OUT}/shots/03-${email.split('@')[0]}-${email.split('@')[1].split('.')[0]}.png`, fullPage: true });
    await ctx.close();
  }

  ({ ctx, page, errors } = await session(browser, 'customer@fyndmytrip.com'));
  check('customer has no Margin rules nav link', await page.getByRole('link', { name: 'Margin rules' }).count() === 0);
  await ctx.close();

  // ---- smoke: main pages per role render without JS errors ----
  const pages = ['/', '/flights', '/hotels', '/buses', '/cabs', '/packages', '/cruises', '/activities', '/insurance', '/visa', '/dashboard', '/wallet', '/my-bookings', '/my-payments', '/reports', '/tenants', '/commission-rules', '/cashback-rules', '/support-tickets', '/profile'];
  for (const email of ['admin@fyndmytrip.com', 'admin@acmetravel.fyndmytrip.com', 'agent@acmetravel.fyndmytrip.com', 'api@fyndmytrip.com', 'customer@fyndmytrip.com']) {
    ({ ctx, page, errors } = await session(browser, email));
    const bad = [];
    for (const p of pages) {
      errors.length = 0;
      await page.goto(FE + p); await page.waitForLoadState('networkidle'); await page.waitForTimeout(250);
      const crashed = await page.locator('text=/Something went wrong|Unexpected Application Error/i').count();
      if (errors.length || crashed) bad.push(`${p}: ${crashed ? 'crash ' : ''}${errors.join(' ; ')}`);
    }
    check(`${email}: ${pages.length} pages render without JS errors`, bad.length === 0, bad.join(' || '));
    await ctx.close();
  }

  await browser.close();
  const failed = results.filter(r => !r.ok);
  console.log(`\n${results.length - failed.length} passed, ${failed.length} failed of ${results.length} UI checks`);
  require('fs').writeFileSync(OUT + '/ui_results.json', JSON.stringify(results, null, 1));
  process.exit(failed.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
