const { chromium } = require('playwright');
// Storefront extras: admin banners, offers and FAQ on the home page, the
// SEO title, and the analytics tag with a purchase event on the
// confirmation page. Run via e2e/run.sh (needs the API and storefront up).
const API = (process.env.API_URL || 'http://127.0.0.1:8010') + '/api', SF = process.env.SF_URL || 'http://127.0.0.1:8030';
const results = []; const check = (n, ok, d = '') => { results.push({ n, ok: !!ok }); console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : ' -> ' + d)); };
async function call(method, path, token, body) {
  const r = await fetch(API + path, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: 'Bearer ' + token } : {}) }, body: body ? JSON.stringify(body) : undefined });
  return r.json();
}
(async () => {
  const token = (await call('POST', '/auth/login', null, { email: 'admin@fyndmytrip.com', password: 'password' })).token;
  await call('POST', '/admin/content', token, { type: 'banner', title: 'E2E monsoon sale', body: '<p>Flat 10% off</p>', link_url: '/#/?s=hotel' });
  await call('POST', '/admin/content', token, { type: 'offer', title: 'E2E bank offer', data: { coupon_code: 'E2EBANK' } });
  await call('POST', '/admin/content', token, { type: 'faq', title: 'E2E: can I cancel?', body: 'Yes, from My trips.' });
  await call('POST', '/admin/content', token, { type: 'seo', data: { path: '/', meta_title: 'E2E cheap flights title', meta_description: 'E2E description' } });
  await call('PUT', '/marketing-tags', token, { ga4_id: 'G-E2ESTORE1' });
  const browser = await chromium.launch(); const page = await browser.newPage(); const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.route('**/config.js', (r) => r.fulfill({ contentType: 'application/javascript', body: `window.FMT_CONFIG={apiBase:'${API}'}` }));
  await page.route(/fonts\.(googleapis|gstatic)\.com|picsum\.photos|googletagmanager\.com|facebook\.net/, (r) => r.fulfill({ status: 200, body: '' }));
  await page.goto(SF + '/#/'); await page.waitForTimeout(3000);
  check('banner on home page', await page.locator('[data-fmt-banners] >> text=E2E monsoon sale').count() > 0);
  check('offer on home page', await page.locator('[data-fmt-offers] >> text=E2EBANK').count() > 0);
  check('FAQ on home page', await page.locator('[data-fmt-faq] >> text=E2E: can I cancel?').count() > 0);
  check('SEO title applied', (await page.title()) === 'E2E cheap flights title', await page.title());
  check('analytics tag loaded', await page.locator('script[src*="G-E2ESTORE1"]').count() > 0);
  await page.goto(SF + '/#/done/flight/123'); await page.waitForTimeout(1500);
  check('no purchase event for a booking that is not shown', !(await page.evaluate(() => (window.dataLayer || []).some((a) => a[0] === 'event' && a[1] === 'purchase'))));
  check('no page errors', errors.length === 0, errors.join(' | '));
  await page.screenshot({ path: (process.env.RESULTS_DIR || 'e2e/results') + '/shots/storefront-extras.png', fullPage: false });
  await browser.close();
  const failed = results.filter((r) => !r.ok); console.log(`\nstorefront extras: ${results.length - failed.length}/${results.length} passed`);
  process.exit(failed.length ? 1 : 0);
})();
