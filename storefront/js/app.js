// Router and template selection.
// Template precedence: ?template=N in the URL (preview, remembered for the
// session) > the white-label tenant's chosen template (GET /whitelabel/current)
// > template 1.
import { api, auth, store } from './api.js';
import { applyTheme, toast, fx, setCurrency, hasStoredCurrency } from './ui.js';
import { byId } from './templates.js';
import { SERVICES } from './services.js';
import { header, footer } from './chrome.js';
import * as pages from './pages.js';
import { pageSeo } from './ota.js';

const ctx = { t: null, brand: { name: 'FyndMyTrip' }, svc: 'flight', query: {}, id: null };

function parseHash() {
  const h = location.hash.replace(/^#/, '') || '/';
  const [path, query = ''] = h.split('?');
  const parts = path.split('/').filter(Boolean);
  return { parts, query: Object.fromEntries(new URLSearchParams(query)) };
}

function renderChrome() {
  document.getElementById('hdr').innerHTML = header(ctx);
  document.getElementById('ftr').innerHTML = footer(ctx);
  document.querySelector('[data-act=logout]')?.addEventListener('click', async () => {
    try { await api('POST', '/auth/logout'); } catch { /* token may already be gone */ }
    auth.clear(); renderChrome(); location.hash = '#/';
  });
  document.querySelector('[data-cur]')?.addEventListener('change', (e) => { setCurrency(e.target.value); route(); });
}
ctx.refreshChrome = renderChrome;

async function route() {
  const { parts, query } = parseHash();
  const [page = '', svc, id] = parts;
  ctx.query = query;
  ctx.id = id ? decodeURIComponent(id) : null;
  if (svc && SERVICES[svc]) ctx.svc = svc;
  else if (query.s && SERVICES[query.s]) ctx.svc = query.s;
  const main = document.getElementById('page');
  document.documentElement.dataset.page = page || 'home';
  renderChrome();
  window.scrollTo(0, 0);
  pageSeo(page || 'home', SERVICES[ctx.svc], ctx.brand.name);
  const handler = { '': pages.home, results: pages.results, details: pages.details, travellers: pages.travellers, payment: pages.payment, done: pages.done, login: pages.login, templates: pages.gallery }[page] || pages.home;
  try { await handler(main, ctx); } catch (e) { console.error(e); toast(e.message || 'Something went wrong'); }
  main.focus({ preventScroll: true });
}

async function boot() {
  const url = new URL(location.href);
  const fromUrl = url.searchParams.get('template');
  if (fromUrl) store.set('template', +fromUrl);
  try { const r = await api('GET', '/whitelabel/current'); ctx.brand = r.data || ctx.brand; } catch { /* API down: still render */ }
  try {
    let tz = ''; try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch { /* old browser */ }
    const r = (await api('GET', '/currency-rates', { tz })).data;
    fx.base = r.base || 'INR'; fx.rates = r.rates || { INR: 1 };
    if (!hasStoredCurrency() && r.suggested) setCurrency(r.suggested);
    if (!fx.rates[fx.cur]) fx.cur = fx.base;
  } catch { /* rates unavailable: prices stay in INR */ }
  const chosen = store.get('template') || ctx.brand.storefront_template_id || ctx.brand.template_id || 1;
  ctx.t = byId(chosen);
  applyTheme(ctx.t);
  document.title = `${ctx.brand.name} · Flights, hotels, buses & holidays`;
  window.addEventListener('hashchange', route);
  await route();
}

boot();
