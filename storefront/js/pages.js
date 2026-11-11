// Every page of the booking flow, each in all of its layout variants.
// Pages render HTML then bind their events; navigation is hash based so the
// storefront needs only an SPA fallback (or none) on the host.
import { SERVICES, ORDER, defaults } from './services.js';
import { api, auth, store } from './api.js';
import { esc, icon, money, fmtDate, toast, fx, charging, inr } from './ui.js';
import { searchForm, readForm } from './chrome.js';
import { gatewayCheckout } from './checkout.js';
import { TEMPLATES, AXES } from './templates.js';

export const go = (hash) => { location.hash = hash; };
const qs = (o) => new URLSearchParams(o).toString();
const busy = (btn, on, label) => { if (!btn) return; btn.disabled = on; if (on) { btn.dataset.label = btn.innerHTML; btn.innerHTML = `<span class="spin"></span>${label || 'Please wait…'}`; } else if (btn.dataset.label) btn.innerHTML = btn.dataset.label; };

// ---------------------------------------------------------------- home
const HEADLINES = {
  flight: ['Fly anywhere, for less', 'Compare fares across airlines and book in minutes.'],
  hotel: ['Stays you will love', 'Hotels, resorts and homestays with instant confirmation.'],
  bus: ['Your seat, your journey', 'Pick your exact seat on thousands of bus routes.'],
  cab: ['Outstation cabs, door to door', 'Sedans, SUVs and hatchbacks with transparent per-km pricing.'],
  holiday: ['Holidays, fully planned', 'Hand-picked packages with stays, transfers and sightseeing.'],
  cruise: ['Set sail', 'Ocean and river cruises with every cabin class.'],
  activity: ['Things to do', 'Tours, experiences and activities wherever you go.'],
  insurance: ['Travel protected', 'Medical, baggage and trip cover from trusted insurers.'],
  visa: ['Visas made simple', 'Apply online with expert review and status updates.'],
};
const POPULAR = {
  flight: [['DEL', 'BOM'], ['BLR', 'DEL'], ['BOM', 'GOI'], ['MAA', 'BLR']],
  hotel: [['Goa'], ['Jaipur'], ['Manali'], ['Udaipur']],
  bus: [['Chennai', 'Bengaluru'], ['Mumbai', 'Pune'], ['Delhi', 'Jaipur'], ['Hyderabad', 'Vijayawada']],
  cab: [['Chennai', 'Pondicherry'], ['Bengaluru', 'Mysuru'], ['Delhi', 'Agra'], ['Mumbai', 'Lonavala']],
  holiday: [['Kashmir'], ['Kerala'], ['Goa'], ['Bali']],
  cruise: [['Chennai', 'Colombo'], ['Mumbai', 'Goa'], ['Kochi', 'Lakshadweep'], ['Singapore', 'Phuket']],
  activity: [['Goa'], ['Dubai'], ['Singapore'], ['Rishikesh']],
  insurance: [['Thailand'], ['Dubai'], ['Europe'], ['USA']],
  visa: [['Singapore'], ['Indonesia'], ['Thailand'], ['United Arab Emirates']],
};

export async function home(root, ctx) {
  const svc = SERVICES[ctx.svc];
  const [h1, sub] = HEADLINES[ctx.svc];
  const v = ctx.t.layout.hero;
  const form = searchForm(ctx);
  let hero;
  switch (v) {
    case 'split': hero = `<section class="hero hero-split"><div class="wrap"><div class="hero-copy"><span class="eyebrow">${esc(ctx.brand.name)}</span><h1>${h1}</h1><p>${sub}</p><ul class="ticks"><li>${icon('check', 16)} Best-fare guarantee</li><li>${icon('check', 16)} 24×7 support</li><li>${icon('check', 16)} Secure payments</li></ul></div><div class="hero-card">${form}</div></div></section>`; break;
    case 'line': hero = `<section class="hero hero-line"><div class="wrap"><h1>${h1}</h1>${form}<p class="muted">${sub}</p></div></section>`; break;
    case 'stack': hero = `<section class="hero hero-stack"><div class="wrap narrow"><h1>${h1}</h1><p>${sub}</p><div class="hero-card">${form}</div></div></section>`; break;
    case 'icons': hero = `<section class="hero hero-icons"><div class="wrap"><h1>What would you like to book?</h1><div class="hero-card">${form}</div></div></section>`; break;
    case 'wizard': hero = `<section class="hero hero-wizard"><div class="wrap"><div class="hero-copy"><h1>${h1}</h1><p>${sub}</p></div><div class="hero-card">${form}</div></div></section>`; break;
    case 'pill': hero = `<section class="hero hero-pill"><div class="wrap"><h1>${h1}</h1><p>${sub}</p>${form}</div></section>`; break;
    case 'rail': hero = `<section class="hero hero-rail"><div class="wrap"><h1>${h1}</h1><div class="hero-card">${form}</div></div></section>`; break;
    case 'magazine': hero = `<section class="hero hero-magazine"><div class="wrap"><div class="mag-head"><span class="eyebrow">Issue ${new Date().getMonth() + 1} · ${svc.label}</span><h1>${h1}</h1><p>${sub}</p></div><div class="mag-form">${form}</div><aside class="mag-side"><div class="mag-tile a"><b>Weekend</b><span>Getaways under ₹10k</span></div><div class="mag-tile b"><b>Long haul</b><span>Plan ahead, pay less</span></div></aside></div></section>`; break;
    case 'fullbleed': hero = `<section class="hero hero-fullbleed"><div class="wrap"><h1>${h1}</h1><p>${sub}</p><div class="hero-card">${form}</div></div></section>`; break;
    default: hero = `<section class="hero hero-tabs"><div class="wrap"><h1>${h1}</h1><p>${sub}</p><div class="hero-card">${form}</div></div></section>`;
  }
  const popular = POPULAR[ctx.svc].map((p) => {
    const q = { ...defaults(svc) };
    const placeFields = svc.fields.filter((f) => f.kind === 'place');
    p.forEach((val, i) => { if (placeFields[i]) q[placeFields[i].name] = val; });
    return `<a class="chip" href="#/results/${ctx.svc}?${qs(q)}">${icon(svc.icon, 16)}${p.join(' → ')}</a>`;
  }).join('');
  root.innerHTML = `${hero}
    <section class="sec"><div class="wrap"><h2>Popular ${svc.label.toLowerCase()} searches</h2><div class="chips">${popular}</div></div></section>
    <section class="sec sec-alt"><div class="wrap"><h2>Holiday packages</h2><div class="pkg-row" id="pkgs"><p class="muted">Loading packages…</p></div></div></section>
    <section class="sec"><div class="wrap"><h2>Offers for you</h2><div class="offer-row" id="offers"><p class="muted">Loading offers…</p></div></div></section>
    <section class="sec trust"><div class="wrap trust-row">${['Instant confirmation|check', 'Secure payments|lock', 'All services, one account|globe', 'Real people, 24×7|user'].map((s) => { const [l, i] = s.split('|'); return `<div>${icon(i, 24)}<b>${l}</b></div>`; }).join('')}</div></section>`;
  bindSearch(root, ctx);
  api('GET', '/packages', { per_page: 4 }).then((r) => {
    const el = root.querySelector('#pkgs'); if (!el) return;
    el.innerHTML = (r.data || []).slice(0, 4).map((p) => `<a class="pkg" href="#/details/holiday/${encodeURIComponent(p.slug)}?${qs({ q: '', travel_date: defaults(SERVICES.holiday).travel_date, travellers: 2 })}" data-pkg="${esc(p.slug)}"><div class="pkg-img" style="background-image:url('${esc(p.cover_image_url)}')"></div><div class="pkg-body"><b>${esc(p.name)}</b><span>${p.duration_nights}N/${p.duration_days}D · ${esc(p.destination_city || '')}</span><strong>${money(p.sell_price, p.currency)}</strong></div></a>`).join('') || '<p class="muted">No packages yet.</p>';
    store.set('offers:holiday', r.data || []);
  }).catch(() => { const el = root.querySelector('#pkgs'); if (el) el.innerHTML = '<p class="muted">Packages are unavailable right now.</p>'; });
  api('GET', '/coupons/active').then((r) => {
    const el = root.querySelector('#offers'); if (!el) return;
    const list = (r.data || []).slice(0, 4);
    el.innerHTML = list.length ? list.map((c) => `<div class="offer"><b>${esc(c.code)}</b><span>${esc(c.description || c.name || '')}</span></div>`).join('') : '<div class="offer"><b>Book early</b><span>The best fares go first. Book 3+ weeks ahead.</span></div><div class="offer"><b>Pay your way</b><span>Cards, UPI, net banking or your wallet.</span></div>';
  }).catch(() => { const el = root.querySelector('#offers'); if (el) el.innerHTML = ''; });
}

function bindSearch(root, ctx) {
  root.querySelectorAll('.svc-switch [data-svc]').forEach((b) => b.addEventListener('click', () => go('#/?s=' + b.dataset.svc)));
  root.querySelectorAll('form[data-search]').forEach((f) => f.addEventListener('submit', (e) => {
    e.preventDefault();
    go(`#/results/${ctx.svc}?${qs(readForm(f))}`);
  }));
}

// ---------------------------------------------------------------- results
export async function results(root, ctx) {
  const svc = SERVICES[ctx.svc];
  const q = { ...defaults(svc), ...ctx.query };
  ctx.query = q;
  root.innerHTML = `<section class="res-head"><div class="wrap">${searchForm(ctx, { compact: true })}</div></section><section class="wrap res-body"><div class="loading">${'<div class="skel"></div>'.repeat(4)}</div></section>`;
  bindSearch(root, ctx);
  let offers;
  try {
    const r = await api(svc.method || 'POST', svc.search, svc.body(q));
    offers = svc.offers(r);
    store.set('offers:' + ctx.svc, offers);
    store.set('query:' + ctx.svc, q);
  } catch (e) {
    root.querySelector('.res-body').innerHTML = `<div class="empty">${icon('search', 32)}<h3>We couldn't search right now</h3><p>${esc(e.message)}</p></div>`;
    return;
  }
  const state = { sort: 'price', refundable: false, max: Math.max(0, ...offers.map(svc.price)) };
  const body = root.querySelector('.res-body');
  const draw = () => {
    let list = offers.filter((o) => (!state.refundable || svc.refundable(o)) && svc.price(o) <= state.max);
    list = list.sort((a, b) => (state.sort === 'price' ? svc.price(a) - svc.price(b) : state.sort === 'price-desc' ? svc.price(b) - svc.price(a) : String(svc.title(a)).localeCompare(svc.title(b))));
    const link = (o) => `#/details/${ctx.svc}/${encodeURIComponent(svc.id(o))}?${qs(q)}`;
    const toolbar = `<div class="res-toolbar"><b>${list.length} ${svc.label.toLowerCase()} found</b><label>Sort <select data-sort><option value="price" ${state.sort === 'price' ? 'selected' : ''}>Cheapest first</option><option value="price-desc" ${state.sort === 'price-desc' ? 'selected' : ''}>Most expensive first</option><option value="name" ${state.sort === 'name' ? 'selected' : ''}>Name</option></select></label><label class="chk"><input type="checkbox" data-refund ${state.refundable ? 'checked' : ''}> Refundable only</label></div>`;
    const v = ctx.t.layout.results;
    const items = list.length ? renderResults(v, list, svc, link) : `<div class="empty"><h3>No ${svc.label.toLowerCase()} match</h3><p>Try clearing the filters or changing your search.</p></div>`;
    if (v === 'sidebar') {
      const hi = Math.max(0, ...offers.map(svc.price));
      body.innerHTML = `<div class="res-sidebar"><aside class="filters"><h3>Filters</h3><label>Max price <b>${money(state.max)}</b><input type="range" min="0" max="${Math.ceil(hi)}" value="${Math.ceil(state.max)}" data-max></label><label class="chk"><input type="checkbox" data-refund ${state.refundable ? 'checked' : ''}> Refundable only</label><label>Sort<select data-sort><option value="price">Cheapest first</option><option value="price-desc" ${state.sort === 'price-desc' ? 'selected' : ''}>Most expensive first</option><option value="name" ${state.sort === 'name' ? 'selected' : ''}>Name</option></select></label></aside><div><div class="res-toolbar"><b>${list.length} ${svc.label.toLowerCase()} found</b></div>${items}</div></div>`;
    } else body.innerHTML = toolbar + items;
    body.querySelectorAll('[data-sort]').forEach((s) => s.addEventListener('change', () => { state.sort = s.value; draw(); }));
    body.querySelectorAll('[data-refund]').forEach((c) => c.addEventListener('change', () => { state.refundable = c.checked; draw(); }));
    body.querySelectorAll('[data-max]').forEach((r) => r.addEventListener('change', () => { state.max = +r.value; draw(); }));
  };
  draw();
}

function timesBlock(svc, o) {
  if (!svc.times) return '';
  const t = svc.times(o);
  return `<div class="times"><div><b>${t.dep}</b><small>${esc(t.from)}</small></div><div class="track"><small>${t.dur}</small><i></i></div><div><b>${t.arr}</b><small>${esc(t.to)}</small></div></div>`;
}
const thumb = (svc, o) => (svc.image ? `<div class="thumb" style="background-image:url('${esc(svc.image(o))}')"></div>` : `<div class="thumb thumb-ic">${icon(svc.icon, 28)}</div>`);
const facts = (svc, o, n = 4) => `<ul class="facts">${svc.facts(o).slice(0, n).map((f) => `<li>${esc(f)}</li>`).join('')}</ul>`;
const priceTag = (svc, o) => `<div class="price"><small>from</small><strong>${money(svc.price(o), o.currency)}</strong>${svc.refundable(o) ? '<em class="tag ok">Refundable</em>' : ''}</div>`;
const cta = (href) => `<a class="btn btn-primary" href="${href}" data-select>Select</a>`;

function renderResults(v, list, svc, link) {
  switch (v) {
    case 'grid':
      return `<div class="res-grid">${list.map((o) => `<article class="card r-card">${thumb(svc, o)}<h3>${esc(svc.title(o))}</h3><p class="muted">${esc(svc.subtitle(o))}</p>${timesBlock(svc, o)}${facts(svc, o, 3)}<div class="r-foot">${priceTag(svc, o)}${cta(link(o))}</div></article>`).join('')}</div>`;
    case 'table':
      return `<div class="table-wrap"><table class="res-table"><thead><tr><th>${svc.label.slice(0, -1) || svc.label}</th><th>Details</th><th>Price</th><th></th></tr></thead><tbody>${list.map((o) => `<tr><td><b>${esc(svc.title(o))}</b><br><small class="muted">${esc(svc.subtitle(o))}</small></td><td>${svc.times ? (() => { const t = svc.times(o); return `${t.dep} → ${t.arr} · ${t.dur}<br>`; })() : ''}<small>${esc(svc.facts(o).slice(0, 3).join(' · '))}</small></td><td><strong>${money(svc.price(o), o.currency)}</strong></td><td>${cta(link(o))}</td></tr>`).join('')}</tbody></table></div>`;
    case 'timeline':
      return `<ol class="res-timeline">${list.map((o) => `<li class="card"><span class="dot"></span><div class="tl-main"><h3>${esc(svc.title(o))}</h3>${timesBlock(svc, o) || `<p class="muted">${esc(svc.subtitle(o))}</p>`}${facts(svc, o)}</div>${priceTag(svc, o)}${cta(link(o))}</li>`).join('')}</ol>`;
    case 'compact':
      return `<div class="res-compact">${list.map((o) => `<a class="row" href="${link(o)}" data-select><b>${esc(svc.title(o))}</b><span class="muted">${esc(svc.facts(o).slice(0, 2).join(' · '))}</span>${svc.times ? `<span>${svc.times(o).dep}–${svc.times(o).arr}</span>` : '<span></span>'}<strong>${money(svc.price(o), o.currency)}</strong>${icon('arrow', 18)}</a>`).join('')}</div>`;
    case 'ticket':
      return `<div class="res-tickets">${list.map((o) => `<article class="ticket"><div class="t-main"><span class="t-brand">${icon(svc.icon, 18)} ${esc(svc.title(o))}</span>${timesBlock(svc, o) || `<h3>${esc(svc.subtitle(o))}</h3>`}${facts(svc, o, 3)}</div><div class="t-stub"><strong>${money(svc.price(o), o.currency)}</strong><small>${svc.refundable(o) ? 'Refundable' : 'Non-refundable'}</small>${cta(link(o))}</div></article>`).join('')}</div>`;
    case 'masonry':
      return `<div class="res-masonry">${list.map((o, i) => `<article class="card m-card ${i % 3 === 0 ? 'tall' : ''}">${thumb(svc, o)}<div class="m-body"><h3>${esc(svc.title(o))}</h3><p class="muted">${esc(svc.subtitle(o))}</p>${facts(svc, o, i % 3 === 0 ? 4 : 2)}<div class="r-foot">${priceTag(svc, o)}${cta(link(o))}</div></div></article>`).join('')}</div>`;
    default: // list + sidebar
      return `<div class="res-list">${list.map((o) => `<article class="card r-row">${thumb(svc, o)}<div class="r-main"><h3>${esc(svc.title(o))}</h3><p class="muted">${esc(svc.subtitle(o))}</p>${timesBlock(svc, o)}${facts(svc, o)}</div><div class="r-side">${priceTag(svc, o)}${cta(link(o))}</div></article>`).join('')}</div>`;
  }
}

// ---------------------------------------------------------------- details
function findOffer(ctx) {
  const svc = SERVICES[ctx.svc];
  const list = store.get('offers:' + ctx.svc) || [];
  return list.find((o) => String(svc.id(o)) === String(ctx.id));
}

export async function details(root, ctx) {
  const svc = SERVICES[ctx.svc];
  const q = { ...defaults(svc), ...(store.get('query:' + ctx.svc) || {}), ...ctx.query };
  let o = findOffer(ctx);
  if (!o && ctx.svc === 'holiday') {
    try { const r = await api('GET', '/packages/' + encodeURIComponent(ctx.id)); o = r.data; store.set('offers:holiday', [o]); } catch { /* handled below */ }
  }
  if (!o) { root.innerHTML = `<div class="wrap empty"><h3>This option is no longer available</h3><p>Fares change often. Please search again.</p><a class="btn btn-primary" href="#/?s=${ctx.svc}">Search again</a></div>`; return; }
  const pax = svc.pax(q);
  const unit = svc.price(o);
  const isHotel = ctx.svc === 'hotel';
  // flights, packages and visas are priced per traveller; other services'
  // results already show the price for the whole party
  const total = ['flight', 'holiday', 'visa'].includes(ctx.svc) ? unit * pax : unit;
  const fare = `<div class="fare"><h3>Fare summary</h3><dl><dt>${esc(svc.title(o))}</dt><dd>${money(unit, o.currency)}${total !== unit ? ` × ${pax}` : ''}</dd>${isHotel ? `<dt>Rooms × nights</dt><dd>${q.rooms} × ${o.nights}</dd>` : ''}<dt class="tot">Estimated total</dt><dd class="tot">${money(total, o.currency)}</dd></dl><p class="muted small">Taxes included. The final price is confirmed when you continue.</p></div>`;
  const segs = (o.segments || []).map((s) => `<li><b>${esc(s.origin)}</b> → <b>${esc(s.destination)}</b><span>${s.departure_at ? fmtDate(s.departure_at.slice(0, 10)) + ' ' + s.departure_at.slice(11, 16) : ''}${s.arrival_at ? ' – ' + s.arrival_at.slice(11, 16) : ''}</span>${s.flight_number ? `<small>${esc(s.flight_number)} · ${esc(s.aircraft_type || '')}</small>` : ''}</li>`).join('');
  const policy = o.cancellation_policy ? `<ul class="policy">${o.cancellation_policy.bands.map((b) => `<li>${esc(b.label)}: <b>${Number(b.refund_percentage)}% refund</b></li>`).join('')}</ul>` : `<p>${svc.refundable(o) ? 'This booking is refundable. Cancellation charges may apply.' : 'This booking is non-refundable.'}</p>`;
  const visaInfo = ctx.svc === 'visa' ? `<h3>Documents required</h3><ul class="ticks">${(o.documents_required || []).map((x) => `<li>${icon('check', 16)} ${esc(x)}</li>`).join('')}</ul><h3>Included</h3><ul class="plain">${(o.included_services || []).map((x) => `<li>${esc(x)}</li>`).join('')}</ul>` : '';
  const pkg = ctx.svc === 'holiday' ? `<h3>Inclusions</h3><ul class="ticks">${(o.inclusions || []).map((x) => `<li>${icon('check', 16)} ${esc(x)}</li>`).join('')}</ul><h3>Exclusions</h3><ul class="plain">${(o.exclusions || []).map((x) => `<li>${esc(x)}</li>`).join('')}</ul>${o.itinerary ? `<h3>Itinerary</h3><ol class="itin">${(o.itinerary || []).map((d) => `<li><b>${esc(d.title || 'Day ' + d.day)}</b><p>${esc(d.description || '')}</p></li>`).join('')}</ol>` : ''}` : '';
  const seatUI = svc.seats ? `<div class="seat-pick" id="seat-pick"><h3>Choose ${pax} seat${pax > 1 ? 's' : ''}</h3><p class="muted">Loading seat map…</p></div>` : '';
  const overview = `${facts(svc, o, 8)}${segs ? `<h3>Journey</h3><ul class="segs">${segs}</ul>` : ''}${pkg}${visaInfo}`;
  const btn = `<button class="btn btn-primary btn-lg" data-continue>Continue ${icon('arrow', 18)}</button>`;
  const head = `<div class="d-head">${thumb(svc, o)}<div><span class="eyebrow">${svc.label}</span><h1>${esc(svc.title(o))}</h1><p class="muted">${esc(svc.subtitle(o))}</p></div></div>`;
  const crumbs = `<nav class="crumbs wrap"><a href="#/?s=${ctx.svc}">Home</a> / <a href="#/results/${ctx.svc}?${qs(q)}">Results</a> / <span>Details</span> / <span class="muted">Travellers</span> / <span class="muted">Payment</span></nav>`;
  let html;
  switch (ctx.t.layout.details) {
    case 'columns':
      html = `${crumbs}<section class="wrap d-columns">${head}<div class="cols3"><div class="card">${'<h3>Overview</h3>' + overview}</div><div class="card"><h3>Cancellation</h3>${policy}${seatUI}</div><div class="card">${fare}${btn}</div></div></section>`; break;
    case 'tabs':
      html = `${crumbs}<section class="wrap d-tabs">${head}<div class="tabs" role="tablist"><button class="on" data-tab="ov">Overview</button><button data-tab="po">Cancellation</button><button data-tab="fa">Fare</button></div><div class="card tab-panels"><div data-panel="ov">${overview}${seatUI}</div><div data-panel="po" hidden>${policy}</div><div data-panel="fa" hidden>${fare}</div></div><div class="d-bar"><strong>${money(total, o.currency)}</strong>${btn}</div></section>`; break;
    case 'sheet':
      html = `${crumbs}<section class="wrap narrow d-sheet"><div class="card sheet">${head}${overview}<h3>Cancellation</h3>${policy}${seatUI}${fare}${btn}</div></section>`; break;
    case 'banner':
      html = `<section class="d-banner"><div class="wrap"><span class="eyebrow">${svc.label}</span><h1>${esc(svc.title(o))}</h1><p>${esc(svc.subtitle(o))}</p><strong class="big">${money(total, o.currency)}</strong></div></section>${crumbs}<section class="wrap d-bannered"><div class="card">${overview}${seatUI}</div><div class="card"><h3>Cancellation</h3>${policy}${fare}${btn}</div></section>`; break;
    default:
      html = `${crumbs}<section class="wrap d-panel"><div class="d-main">${head}<div class="card">${overview}</div><div class="card"><h3>Cancellation</h3>${policy}</div>${seatUI ? `<div class="card">${seatUI}</div>` : ''}</div><aside class="d-aside"><div class="card sticky">${fare}${btn}</div></aside></section>`;
  }
  root.innerHTML = html;
  root.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => {
    root.querySelectorAll('[data-tab]').forEach((x) => x.classList.toggle('on', x === b));
    root.querySelectorAll('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== b.dataset.tab; });
  }));
  const extra = { seats: [], boarding: '', dropping: '' };
  if (svc.seats) await loadSeats(root, svc, o, q, pax, extra);
  root.querySelectorAll('[data-continue]').forEach((b) => b.addEventListener('click', () => {
    if (svc.seats && extra.seats.length !== pax) { toast(`Please choose ${pax} seat${pax > 1 ? 's' : ''}.`); return; }
    store.set('pick', { svc: ctx.svc, offer: o, query: q, extra, estimate: total });
    go(`#/travellers/${ctx.svc}`);
  }));
}

async function loadSeats(root, svc, o, q, pax, extra) {
  const box = root.querySelector('#seat-pick');
  try {
    const r = await api('POST', '/bus/seatmap', svc.seatmapBody(o, q));
    const rows = r.data.rows || [];
    const bp = (o.boarding_points || []).map((p) => `<option>${esc(p.point)}</option>`).join('');
    const dp = (o.dropping_points || []).map((p) => `<option>${esc(p.point)}</option>`).join('');
    box.innerHTML = `<h3>Choose ${pax} seat${pax > 1 ? 's' : ''}</h3><div class="seatmap">${rows.map((row) => `<div class="seat-row">${row.seats.map((s) => { const free = s.state === 'available' || s.state === 'female_only'; return `<button type="button" class="seat ${free ? '' : 'taken'} ${s.state === 'female_only' ? 'fem' : ''}" ${free ? '' : 'disabled'} data-seat="${esc(s.seat_number)}" title="${esc(s.seat_number)} · ${money(s.gross_amount)}">${esc(s.seat_number)}</button>`; }).join('')}</div>`).join('')}</div><p class="legend"><span class="seat"></span> Available <span class="seat taken"></span> Booked <span class="seat fem"></span> Ladies only <span class="seat sel"></span> Yours</p>${bp ? `<div class="grid-2"><label class="fld"><span>Boarding point</span><select data-bp>${bp}</select></label><label class="fld"><span>Dropping point</span><select data-dp>${dp}</select></label></div>` : ''}<p class="muted" data-seat-note>No seats chosen yet.</p>`;
    extra.boarding = (o.boarding_points || [{}])[0].point || '';
    extra.dropping = (o.dropping_points || [{}])[0].point || '';
    box.querySelector('[data-bp]')?.addEventListener('change', (e) => { extra.boarding = e.target.value; });
    box.querySelector('[data-dp]')?.addEventListener('change', (e) => { extra.dropping = e.target.value; });
    box.querySelectorAll('[data-seat]').forEach((b) => b.addEventListener('click', () => {
      const n = b.dataset.seat; const i = extra.seats.indexOf(n);
      if (i >= 0) extra.seats.splice(i, 1); else { if (extra.seats.length >= pax) extra.seats.shift(); extra.seats.push(n); }
      box.querySelectorAll('[data-seat]').forEach((x) => x.classList.toggle('sel', extra.seats.includes(x.dataset.seat)));
      box.querySelector('[data-seat-note]').textContent = extra.seats.length ? 'Seats: ' + extra.seats.join(', ') : 'No seats chosen yet.';
    }));
  } catch (e) { box.innerHTML = `<p class="err">Seat map unavailable: ${esc(e.message)}</p>`; }
}

// ---------------------------------------------------------------- travellers
function travellerFields(svc, i, prefill = {}) {
  return svc.traveller.map(([name, label, type, opts]) => {
    const id = `p${i}-${name}`;
    const val = prefill[name] ?? '';
    if (type === 'select') return `<label class="fld" for="${id}"><span>${label}</span><select id="${id}" name="${name}">${opts.map((o) => `<option value="${o}" ${o === val ? 'selected' : ''}>${o.replace(/_/g, ' ')}</option>`).join('')}</select></label>`;
    return `<label class="fld" for="${id}"><span>${label}</span><input id="${id}" name="${name}" type="${type || 'text'}" value="${esc(val)}" required ${type === 'number' ? 'min="0" max="120"' : ''}></label>`;
  }).join('');
}

export async function travellers(root, ctx) {
  const pick = store.get('pick');
  if (!pick || pick.svc !== ctx.svc) { go('#/?s=' + ctx.svc); return; }
  const svc = SERVICES[ctx.svc];
  const pax = svc.pax(pick.query);
  const u = auth.member;
  const people = Array.from({ length: pax }, (_, i) => i);
  const block = (i) => `<div class="pax" data-pax="${i}">${travellerFields(svc, i, i === 0 && u ? { name: u.name, first_name: u.name.split(' ')[0], last_name: u.name.split(' ').slice(1).join(' ') } : {})}</div>`;
  const contact = `<div class="grid-2"><label class="fld" for="c-email"><span>Email</span><input id="c-email" name="contact_email" type="email" required value="${esc(u?.email || '')}"></label><label class="fld" for="c-phone"><span>Mobile</span><input id="c-phone" name="contact_phone" type="tel" value="${esc(u?.phone || '')}" placeholder="+91"></label></div>`;
  const who = u ? `<div class="signed">${icon('user', 18)} Booking as <b>${esc(u.name)}</b> (${esc(u.email)})</div>`
    : `<div class="who"><div class="who-opt"><h4>Have an account?</h4><p class="muted">Sign in to use your wallet and see this booking in My bookings.</p><div class="grid-2"><label class="fld"><span>Email</span><input name="login_email" type="email" autocomplete="username"></label><label class="fld"><span>Password</span><input name="login_password" type="password" autocomplete="current-password"></label></div><button type="button" class="btn btn-ghost" data-login>Sign in</button></div>${svc.guest ? `<div class="who-opt"><h4>Or continue as a guest</h4><p class="muted">No account or password needed. We'll send your booking to the email and mobile below.</p><label class="fld"><span>Your full name</span><input name="guest_name" value=""></label></div>` : `<div class="who-opt"><h4>New here?</h4><p class="muted">${svc.label} need an account. Create one in seconds.</p><div class="grid-2"><label class="fld"><span>Full name</span><input name="reg_name"></label><label class="fld"><span>Choose a password</span><input name="reg_password" type="password" minlength="8"></label></div><button type="button" class="btn btn-ghost" data-register>Create account</button></div>`}</div>`;
  const summary = `<aside class="card mini-sum"><h4>${esc(svc.title(pick.offer))}</h4><p class="muted small">${esc(svc.subtitle(pick.offer))}</p>${pick.extra?.seats?.length ? `<p>Seats: <b>${pick.extra.seats.join(', ')}</b></p>` : ''}<p>Estimated <strong>${money(pick.estimate)}</strong></p></aside>`;
  const go_ = `<button class="btn btn-primary btn-lg" type="submit">Continue to payment ${icon('arrow', 18)}</button>`;
  const v = ctx.t.layout.travellers;
  let form;
  const label = (i) => `${svc.key === 'hotel' ? 'Guest' : 'Traveller'} ${i + 1}${i === 0 ? ' (lead)' : ''}`;
  switch (v) {
    case 'stepper':
      form = `<ol class="stepper"><li class="on" data-step-dot="0">Travellers</li><li data-step-dot="1">Contact</li><li data-step-dot="2">Review</li></ol>
        <div data-step="0">${who}${people.map((i) => `<h3>${label(i)}</h3>${block(i)}`).join('')}<button type="button" class="btn btn-primary" data-next="1">Next</button></div>
        <div data-step="1" hidden><h3>Contact details</h3>${contact}<button type="button" class="btn btn-ghost" data-next="0">Back</button> <button type="button" class="btn btn-primary" data-next="2">Next</button></div>
        <div data-step="2" hidden><h3>Review</h3><div data-review class="review"></div><button type="button" class="btn btn-ghost" data-next="1">Back</button> ${go_}</div>`; break;
    case 'accordion':
      form = `${people.map((i) => `<details class="acc" ${i === 0 ? 'open' : ''}><summary>${label(i)}</summary>${block(i)}</details>`).join('')}<details class="acc" open><summary>Contact &amp; sign in</summary>${who}${contact}</details>${go_}`; break;
    case 'cards':
      form = `<div class="pax-cards">${people.map((i) => `<div class="card pax-card"><span class="badge">${i + 1}</span><h4>${label(i)}</h4>${block(i)}</div>`).join('')}</div><div class="card">${who}<h3>Contact details</h3>${contact}</div>${go_}`; break;
    case 'inline':
      form = `<div class="pax-inline"><div class="pax-inline-head">${svc.traveller.map(([, l]) => `<span>${l}</span>`).join('')}</div>${people.map((i) => block(i)).join('')}</div><h3>Contact</h3>${contact}${who}${go_}`; break;
    default:
      form = `${people.map((i) => `<h3>${label(i)}</h3>${block(i)}`).join('')}<h3>Contact details</h3>${contact}${who}${go_}`;
  }
  root.innerHTML = `<nav class="crumbs wrap"><span>Details</span> / <b>Travellers</b> / <span class="muted">Payment</span></nav><section class="wrap trav trav-${v}"><form class="card trav-form" data-trav novalidate><h2>Who's travelling?</h2>${form}</form>${summary}</section>`;
  const f = root.querySelector('[data-trav]');
  const showStep = (n) => { f.querySelectorAll('[data-step]').forEach((s) => { s.hidden = s.dataset.step !== String(n); }); f.querySelectorAll('[data-step-dot]').forEach((d) => d.classList.toggle('on', +d.dataset.stepDot <= n)); if (n === 2) { const pp = collect(); f.querySelector('[data-review]').innerHTML = `<ul>${pp.map((p) => `<li>${esc(Object.values(p).join(' · '))}</li>`).join('')}</ul><p>${esc(f.contact_email.value)} ${esc(f.contact_phone.value)}</p>`; } };
  f.querySelectorAll('[data-next]').forEach((b) => b.addEventListener('click', () => showStep(+b.dataset.next)));
  const collect = () => [...f.querySelectorAll('[data-pax]')].map((el) => Object.fromEntries([...el.querySelectorAll('input,select')].map((i) => [i.name, i.value.trim()])));
  f.querySelector('[data-login]')?.addEventListener('click', async (e) => {
    busy(e.currentTarget, true, 'Signing in…');
    try { const r = await api('POST', '/auth/login', { email: f.login_email.value, password: f.login_password.value }); auth.save(r.token, r.user); travellers(root, ctx); ctx.refreshChrome?.(); }
    catch (err) { toast(err.message); busy(e.currentTarget, false); }
  });
  f.querySelector('[data-register]')?.addEventListener('click', async (e) => {
    busy(e.currentTarget, true, 'Creating…');
    try { const r = await api('POST', '/auth/register', { name: f.reg_name.value, email: f.contact_email.value, phone: f.contact_phone.value || undefined, password: f.reg_password.value, password_confirmation: f.reg_password.value }); auth.save(r.token, r.user); travellers(root, ctx); ctx.refreshChrome?.(); }
    catch (err) { toast(err.message.includes('email') ? err.message : err.message + ' (enter your email under Contact details first)'); busy(e.currentTarget, false); }
  });
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    const pp = collect();
    const empty = [...f.querySelectorAll('[data-pax] input, [name=contact_email]')].find((i) => !i.value.trim());
    if (empty) {
      // reveal the field wherever the layout keeps it (closed accordion, other step)
      const step = empty.closest('[data-step]'); if (step) showStep(+step.dataset.step);
      const acc = empty.closest('details'); if (acc) acc.open = true;
      empty.focus();
      toast(empty.name === 'contact_email' ? 'Please enter a contact email.' : 'Please fill in every traveller\'s details.');
      return;
    }
    const guest = !auth.member;
    if (guest && !svc.guest) { toast(`Please sign in or create an account to book ${svc.label.toLowerCase()}.`); return; }
    const btn = f.querySelector('button[type=submit]');
    busy(btn, true, 'Holding your booking…');
    const payload = { ...svc.hold(pick.offer, pick.query, pp, pick.extra || {}), contact_email: f.contact_email.value, contact_phone: f.contact_phone.value || undefined };
    if (guest) Object.assign(payload, { guest_name: (f.guest_name?.value || pp[0].name || `${pp[0].first_name || ''} ${pp[0].last_name || ''}`).trim(), guest_email: f.contact_email.value, guest_phone: f.contact_phone.value || undefined });
    const path = svc.bookPath ? svc.bookPath(pick.offer, guest) : (guest ? svc.guest : svc.base);
    try {
      const r = await api('POST', path, payload);
      // Guest checkout: a session for this booking only, never a login.
      if (r.token) auth.save(r.token, r.user);
      const booking = r.data;
      store.set('booking', { svc: ctx.svc, booking, offer: pick.offer });
      ctx.refreshChrome?.();
      go(`#/payment/${ctx.svc}/${booking.id}`);
    } catch (err) { toast(err.message); busy(btn, false); }
  });
}

// ---------------------------------------------------------------- payment
const METHODS = {
  upi: ['UPI', 'phone', 'Google Pay, PhonePe, Paytm or any UPI app'],
  credit_card: ['Credit card', 'card', 'Visa, Mastercard, RuPay, Amex'],
  debit_card: ['Debit card', 'card', 'All major Indian banks'],
  netbanking: ['Net banking', 'bank', '50+ banks'],
  international_card: ['International card', 'globe', 'Cards issued outside India'],
  fmt_wallet: ['My wallet', 'wallet', 'Pay instantly from your account balance'],
};

export async function payment(root, ctx) {
  const svc = SERVICES[ctx.svc];
  const saved = store.get('booking');
  if (!auth.token) { go('#/login'); return; }
  const payBase = svc.payBase || svc.base;
  let booking;
  try {
    // packages and visas have no single-booking GET; use the hold response
    booking = svc.base ? (await api('GET', `${svc.base}/${ctx.id}`)).data : (saved && String(saved.booking.id) === String(ctx.id) ? saved.booking : null);
    if (!booking) throw new Error('Please start the booking again.');
  } catch (e) { root.innerHTML = `<div class="wrap empty"><h3>Booking not found</h3><p>${esc(e.message)}</p></div>`; return; }
  if (booking.status !== 'held') { go(`#/done/${ctx.svc}/${ctx.id}`); return; }
  let wallet = null;
  if (auth.member) { try { wallet = (await api('GET', '/wallet')).data; } catch { /* no wallet */ } }
  const total = Number(booking.total_amount);
  const bal = wallet ? Number(wallet.balance ?? wallet.available_balance ?? 0) : 0;
  const methods = Object.keys(METHODS).filter((m) => m !== 'fmt_wallet' || (wallet && !booking.is_international && bal >= total));
  const offer = saved?.offer;
  const sum = `<div class="pay-sum"><h3>Booking summary</h3><p><b>${esc(offer ? svc.title(offer) : svc.label)}</b></p><dl><dt>Booking ID</dt><dd>${esc(booking.booking_code || booking.booking_reference)}</dd><dt>Travel date</dt><dd>${esc(fmtDate(booking.travel_date_start || ''))}</dd><dt>Travellers</dt><dd>${booking.passenger_count}</dd><dt>Base fare</dt><dd>${money(booking.gross_amount, booking.currency)}</dd>${Number(booking.extras_amount) ? `<dt>Extras</dt><dd>${money(booking.extras_amount)}</dd>` : ''}${Number(booking.discount_amount) ? `<dt>Discount</dt><dd>−${money(booking.discount_amount)}</dd>` : ''}<dt class="tot">Total payable</dt><dd class="tot">${money(total, booking.currency)}</dd></dl>${wallet ? `<p class="muted small">Wallet balance: ${money(bal)}</p>` : ''}</div>`;
  const opt = (m, type) => { const [l, ic, d] = METHODS[m]; return type === 'radio' ? `<label class="pm"><input type="radio" name="pm" value="${m}">${icon(ic, 22)}<span><b>${l}</b><small>${d}</small></span></label>` : `<button type="button" class="pm" data-pm="${m}">${icon(ic, type === 'tile' ? 30 : 20)}<b>${l}</b><small>${d}</small></button>`; };
  const payBtn = `<button class="btn btn-primary btn-lg btn-pay" data-pay disabled>${icon('lock', 18)} Pay ${money(total, booking.currency)}</button>`;
  // Guests have no wallet: offer sign-in instead (a new hold is made under the account).
  const walletLogin = auth.member ? '' : `<p class="wallet-login">${icon('wallet', 18)} <span>Have an account? <button type="button" class="link" data-wallet-login>Sign in to use your wallet balance</button></span></p>`;
  const secure = walletLogin + `<p class="secure">${icon('lock', 16)} Payments are processed securely by our payment partner. Your hold is kept while you pay.</p>` + (charging() !== fx.base ? `<p class="muted small" data-charge-note>Your card is charged <b>${money(total, booking.currency)}</b> (${inr(total)}) in ${charging()} at today's rate.</p>` : '');
  const v = ctx.t.layout.payment;
  let html;
  switch (v) {
    case 'tabs': html = `<div class="card"><h2>Payment</h2><div class="pm-tabs" role="tablist">${methods.map((m) => opt(m, 'tab')).join('')}</div><div class="pm-detail" data-detail>Choose how you'd like to pay.</div>${payBtn}${secure}</div><div class="card">${sum}</div>`; break;
    case 'radios': html = `<div class="card"><h2>How would you like to pay?</h2><div class="pm-radios">${methods.map((m) => opt(m, 'radio')).join('')}</div>${payBtn}${secure}</div><div class="card">${sum}</div>`; break;
    case 'tiles': html = `<div class="card wide">${sum}</div><div class="card wide"><h2>Pick a payment method</h2><div class="pm-tiles">${methods.map((m) => opt(m, 'tile')).join('')}</div>${payBtn}${secure}</div>`; break;
    case 'split': html = `<div class="pay-dark">${sum}</div><div class="card"><h2>Pay securely</h2><div class="pm-list">${methods.map((m) => opt(m, 'row')).join('')}</div>${payBtn}${secure}</div>`; break;
    case 'accordion': html = `<div class="card"><h2>Payment options</h2>${methods.map((m, i) => `<details class="acc pm-acc" ${i === 0 ? 'open' : ''} data-acc="${m}"><summary>${icon(METHODS[m][1], 20)} ${METHODS[m][0]}</summary><p class="muted">${METHODS[m][2]}</p><button type="button" class="btn btn-primary" data-pm="${m}" data-pay-now>${icon('lock', 16)} Pay ${money(total)} with ${METHODS[m][0]}</button></details>`).join('')}${secure}</div><div class="card">${sum}</div>`; break;
    default: html = `<div class="card narrow-card">${sum}<label class="fld"><span>Pay with</span><select data-pm-select><option value="">Choose a method</option>${methods.map((m) => `<option value="${m}">${METHODS[m][0]}</option>`).join('')}</select></label>${payBtn}${secure}</div>`;
  }
  root.innerHTML = `<nav class="crumbs wrap"><span>Details</span> / <span>Travellers</span> / <b>Payment</b></nav><section class="wrap pay pay-${v}">${html}</section>`;
  let chosen = '';
  const payBtnEl = root.querySelector('[data-pay]');
  const choose = (m) => { chosen = m; root.querySelectorAll('[data-pm]').forEach((b) => b.classList.toggle('on', b.dataset.pm === m)); if (payBtnEl) payBtnEl.disabled = !m; const d = root.querySelector('[data-detail]'); if (d && m) d.innerHTML = `<b>${METHODS[m][0]}</b><p class="muted">${METHODS[m][2]}. You'll be taken through our payment partner's secure step.</p>`; };
  root.querySelectorAll('[data-pm]').forEach((b) => b.addEventListener('click', () => { choose(b.dataset.pm); if (b.hasAttribute('data-pay-now')) pay(b); }));
  root.querySelectorAll('input[name=pm]').forEach((r) => r.addEventListener('change', () => choose(r.value)));
  root.querySelector('[data-pm-select]')?.addEventListener('change', (e) => choose(e.target.value));
  payBtnEl?.addEventListener('click', () => pay(payBtnEl));
  root.querySelector('[data-wallet-login]')?.addEventListener('click', () => { toast('Sign in below, then continue to pay with your wallet.'); go(`#/travellers/${ctx.svc}`); });
  async function pay(btn) {
    if (!chosen) return;
    busy(btn, true, 'Processing payment…');
    try {
      let r;
      if (chosen === 'fmt_wallet') r = await api('POST', `${payBase}/${ctx.id}/confirm`, { wallet_amount: total.toFixed(2) });
      else {
        r = await api('POST', `${payBase}/${ctx.id}/confirm`, { wallet_amount: 0 });
        if (r.requires_payment) { const paid = await gatewayCheckout(ctx.id, chosen, charging()); r = await api('POST', `${payBase}/${ctx.id}/pay`, { method: chosen, currency: charging(), ...paid }); }
      }
      store.set('booking', { ...(saved || {}), booking: r.data });
      go(`#/done/${ctx.svc}/${ctx.id}`);
    } catch (e) { toast(e.message); busy(btn, false); }
  }
}

// ---------------------------------------------------------------- confirmation
const DOCS = { flight: 'ticket.pdf', hotel: 'voucher.pdf', bus: 'ticket.pdf', cab: 'voucher.pdf', cruise: 'ticket.pdf', activity: 'voucher.pdf', insurance: 'policy.pdf' };
export async function done(root, ctx) {
  const svc = SERVICES[ctx.svc];
  let b = store.get('booking')?.booking;
  if (svc.base && auth.token) { try { b = (await api('GET', `${svc.base}/${ctx.id}`)).data; } catch { /* keep cached */ } }
  if (!b) { root.innerHTML = '<div class="wrap empty"><h3>Booking not found</h3></div>'; return; }
  const ok = ['ticketed', 'confirmed'].includes(b.status);
  root.innerHTML = `<section class="wrap narrow done"><div class="card done-card ${ok ? 'ok' : ''}"><div class="done-ic">${icon(ok ? 'check' : 'lock', 40)}</div><h1>${ok ? 'Booking confirmed' : 'Booking ' + esc(b.status)}</h1><p class="muted">A confirmation has been sent to ${esc(b.contact_email || 'your email')}.</p><dl><dt>Booking reference</dt><dd data-ref>${esc(b.booking_code || b.booking_reference || b.id)}</dd><dt>Status</dt><dd data-status>${esc(b.status)}</dd>${(b.ticket_numbers || []).length ? `<dt>Ticket / voucher no.</dt><dd>${esc(b.ticket_numbers.join(', '))}</dd>` : ''}<dt>Amount</dt><dd>${money(b.total_amount, b.currency)}</dd><dt>Travel date</dt><dd>${esc(fmtDate(b.travel_date_start || ''))}</dd></dl><div class="done-actions">${ok && DOCS[ctx.svc] ? `<button class="btn btn-primary" data-doc="${svc.base}/${b.id}/${DOCS[ctx.svc]}">Download ${DOCS[ctx.svc].replace('.pdf', '')}</button>` : ''}${ok && ctx.svc === 'flight' && b.can_hide_fare ? `<button class="btn btn-ghost" data-doc="${svc.base}/${b.id}/ticket.pdf?fare=0" data-name="ticket-no-fare.pdf">Ticket without fare</button>` : ''}${ok ? `<button class="btn btn-ghost" data-doc="/bookings/${b.id}/invoice.pdf">Download invoice</button>` : ''}<a class="btn btn-ghost" href="#/?s=${ctx.svc}">Book another</a></div></div></section>`;
  root.querySelectorAll('[data-doc]').forEach((btn) => btn.addEventListener('click', async () => {
    try { const res = await api('GET', btn.dataset.doc, null, { raw: true }); const blob = await res.blob(); const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = btn.dataset.name || btn.dataset.doc.split('/').pop(); a.click(); }
    catch (e) { toast('Download failed: ' + e.message); }
  }));
}

// ---------------------------------------------------------------- login
export async function login(root, ctx) {
  root.innerHTML = `<section class="wrap narrow"><form class="card login" data-login-form><h2>Sign in</h2><label class="fld"><span>Email</span><input name="email" type="email" required autocomplete="username"></label><label class="fld"><span>Password</span><input name="password" type="password" required autocomplete="current-password"></label><button class="btn btn-primary btn-lg" type="submit">Sign in</button><p class="muted small">Booking as a guest? Just start a search. You only need an account for cabs, cruises, activities and insurance.</p></form></section>`;
  root.querySelector('form').addEventListener('submit', async (e) => {
    e.preventDefault(); const f = e.currentTarget; const btn = f.querySelector('button'); busy(btn, true, 'Signing in…');
    try { const r = await api('POST', '/auth/login', { email: f.email.value, password: f.password.value }); auth.save(r.token, r.user); ctx.refreshChrome?.(); history.length > 1 ? history.back() : go('#/'); }
    catch (err) { toast(err.message); busy(btn, false); }
  });
}

// ---------------------------------------------------------------- template gallery
export async function gallery(root, ctx) {
  root.innerHTML = `<section class="wrap"><div class="gal-head"><h1>${TEMPLATES.length} storefront templates</h1><p class="muted">Each template is a complete design for the whole booking journey: header, home and search, results, details, traveller form, payment and footer. White-label partners choose one in their storefront settings. Click a template to preview it here.</p></div><div class="gallery">${TEMPLATES.map((t) => `<a class="gal ${t.id === ctx.t.id ? 'on' : ''}" href="?template=${t.id}#/" data-tpl="${t.id}"><div class="gal-swatch" style="background:${t.colors.bg}"><i style="background:${t.colors.primary}"></i><i style="background:${t.colors.accent}"></i><span style="color:${t.dark ? '#fff' : '#111'};font-family:'${t.fonts.head}'">Aa</span></div><div class="gal-body"><b>${t.id}. ${esc(t.name)}</b><small>${esc(t.audience)}</small><ul>${Object.keys(AXES).map((a) => `<li><span>${a}</span>${t.layout[a]}</li>`).join('')}</ul></div></a>`).join('')}</div></section>`;
}

export { ORDER };
