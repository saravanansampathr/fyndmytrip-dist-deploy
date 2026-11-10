// Header, footer and the search form, in every layout variant.
import { SERVICES, ORDER, defaults } from './services.js';
import { esc, icon } from './ui.js';
import { auth } from './api.js';

export function header(ctx) {
  const v = ctx.t.layout.header;
  const b = ctx.brand;
  const logo = `<a class="logo" href="#/">${b.logo_url ? `<img src="${esc(b.logo_url)}" alt="">` : `<span class="logo-mark">${esc((b.name || 'F')[0])}</span>`}<span class="logo-name">${esc(b.name)}</span></a>`;
  const svcLinks = ORDER.map((k) => `<a class="nav-svc ${ctx.svc === k ? 'on' : ''}" href="#/?s=${k}" data-svc="${k}">${icon(SERVICES[k].icon, 18)}<span>${SERVICES[k].label}</span></a>`).join('');
  const u = auth.user;
  const account = u
    ? `<span class="acct">${icon('user', 18)}<span>${esc(u.name.split(' ')[0])}</span><button class="link" data-act="logout">Sign out</button></span>`
    : `<a class="acct" href="#/login">${icon('user', 18)}<span>Sign in</span></a>`;
  const help = `<span class="help">${esc(b.support_phone || '+91 80 4000 0000')} · ${esc(b.support_email || 'support@' + (b.domain || 'fyndmytrip.com'))}</span>`;
  const tpl = `<a class="nav-link" href="#/templates">Templates</a>`;
  switch (v) {
    case 'centered':
      return `<header class="hdr hdr-centered"><div class="wrap hdr-top">${account}${logo}${tpl}</div><nav class="wrap svc-nav">${svcLinks}</nav></header>`;
    case 'split':
      return `<header class="hdr hdr-split"><div class="wrap">${logo}<nav class="svc-nav pills">${svcLinks}</nav><div class="hdr-end">${tpl}${account}</div></div></header>`;
    case 'glass':
      return `<header class="hdr hdr-glass"><div class="wrap">${logo}<nav class="svc-nav">${svcLinks}</nav><div class="hdr-end">${tpl}${account}</div></div></header>`;
    case 'rail':
      return `<aside class="hdr hdr-rail">${logo}<nav class="svc-nav vertical">${svcLinks}</nav><div class="rail-end">${tpl}${account}</div></aside>`;
    case 'ribbon':
      return `<header class="hdr hdr-ribbon"><div class="ribbon"><div class="wrap">${help}<span>${tpl}</span></div></div><div class="wrap hdr-main">${logo}<nav class="svc-nav">${svcLinks}</nav>${account}</div></header>`;
    case 'stacked':
      return `<header class="hdr hdr-stacked"><div class="wrap hdr-top">${logo}<div class="hdr-end">${help}${tpl}${account}</div></div><nav class="svc-tabs"><div class="wrap">${svcLinks}</div></nav></header>`;
    default:
      return `<header class="hdr hdr-bar"><div class="wrap">${logo}<nav class="svc-nav">${svcLinks}</nav><div class="hdr-end">${tpl}${account}</div></div></header>`;
  }
}

export function footer(ctx) {
  const v = ctx.t.layout.footer;
  const b = ctx.brand;
  const year = new Date().getFullYear();
  const cols = `<div><h4>Book</h4>${ORDER.map((k) => `<a href="#/?s=${k}">${SERVICES[k].label}</a>`).join('')}</div>
    <div><h4>Help</h4><a href="#/login">My account</a><a href="#/templates">Storefront templates</a><span>${esc(b.support_email || 'support@fyndmytrip.com')}</span></div>
    <div><h4>Pay with</h4><span>Cards · UPI · Net banking · Wallet</span><span>${icon('lock', 16)} Secure checkout</span></div>`;
  const copy = `© ${year} ${esc(b.name)}`;
  switch (v) {
    case 'minimal': return `<footer class="ftr ftr-minimal"><div class="wrap"><span>${copy}</span><span>${ORDER.slice(0, 4).map((k) => `<a href="#/?s=${k}">${SERVICES[k].label}</a>`).join(' · ')}</span></div></footer>`;
    case 'band': return `<footer class="ftr ftr-band"><div class="band-cta"><div class="wrap"><strong>Need help with a booking?</strong><span>${esc(b.support_phone || '+91 80 4000 0000')}</span></div></div><div class="wrap ftr-cols">${cols}</div><div class="wrap ftr-copy">${copy}</div></footer>`;
    case 'centered': return `<footer class="ftr ftr-centered"><div class="wrap"><div class="logo-name">${esc(b.name)}</div><nav>${ORDER.map((k) => `<a href="#/?s=${k}">${SERVICES[k].label}</a>`).join('')}</nav><small>${copy}</small></div></footer>`;
    default: return `<footer class="ftr ftr-columns"><div class="wrap ftr-cols"><div><div class="logo-name">${esc(b.name)}</div><p>${esc(b.about ? String(b.about).slice(0, 140) : 'Flights, hotels, buses, cabs, holidays, cruises, activities and travel insurance in one place.')}</p></div>${cols}</div><div class="wrap ftr-copy">${copy}</div></footer>`;
  }
}

function input(f, q, cls = '') {
  const val = q[f.name] ?? '';
  const id = 'f-' + f.name;
  if (f.type === 'select') {
    return `<label class="fld ${cls}" for="${id}"><span>${esc(f.label)}</span><select id="${id}" name="${f.name}">${f.options.map(([v, l]) => `<option value="${v}" ${v === val ? 'selected' : ''}>${l}</option>`).join('')}</select></label>`;
  }
  const t = f.type || 'text';
  const extra = t === 'number' ? `min="${f.min || 1}" max="${f.max || 9}"` : '';
  return `<label class="fld ${cls}" for="${id}"><span>${esc(f.label)}</span><input id="${id}" name="${f.name}" type="${t}" value="${esc(val)}" placeholder="${esc(f.placeholder || '')}" ${extra} ${f.kind === 'place' && f.name !== 'q' ? 'required' : ''}></label>`;
}

function switcher(ctx, style) {
  return `<div class="svc-switch sw-${style}" role="tablist">${ORDER.map((k) => `<button type="button" role="tab" aria-selected="${ctx.svc === k}" class="${ctx.svc === k ? 'on' : ''}" data-svc="${k}">${icon(SERVICES[k].icon, style === 'icons' ? 28 : 18)}<span>${SERVICES[k].label}</span></button>`).join('')}</div>`;
}

const submit = (label = 'Search') => `<button class="btn btn-primary btn-search" type="submit">${icon('search', 18)}<span>${label}</span></button>`;

// The home-page search form in the template's hero layout. compact=true is the
// "modify search" bar on the results page.
export function searchForm(ctx, { compact = false } = {}) {
  const svc = SERVICES[ctx.svc];
  const q = { ...defaults(svc), ...(ctx.query || {}) };
  const fields = svc.fields;
  if (compact) return `<form class="search-form sf-compact" data-search>${fields.map((f) => input(f, q)).join('')}${submit('Update')}</form>`;
  const v = ctx.t.layout.hero;
  const all = fields.map((f) => input(f, q)).join('');
  switch (v) {
    case 'line': {
      const parts = fields.map((f, i) => `${i ? `<em>${i === 1 && f.kind === 'place' ? 'to' : i === fields.length - 1 ? 'for' : 'on'}</em>` : `<em>${svc.label}</em>`}${input(f, q, 'inline')}`).join('');
      return `${switcher(ctx, 'text')}<form class="search-form sf-line" data-search>${parts}${submit('Go')}</form>`;
    }
    case 'wizard': {
      const groups = [fields.filter((f) => f.kind === 'place'), fields.filter((f) => f.type === 'date'), fields.filter((f) => f.kind !== 'place' && f.type !== 'date')];
      const names = ['Where', 'When', 'Who'];
      return `${switcher(ctx, 'chips')}<form class="search-form sf-wizard" data-search>${groups.map((g, i) => (g.length ? `<fieldset class="step"><legend><b>${i + 1}</b>${names[i]}</legend>${g.map((f) => input(f, q)).join('')}</fieldset>` : '')).join('')}${submit()}</form>`;
    }
    case 'rail':
      return `<div class="sf-rail-wrap">${switcher(ctx, 'vertical')}<form class="search-form sf-rail" data-search><h3>${esc(svc.label)}</h3><div class="grid-2">${all}</div>${submit()}</form></div>`;
    case 'pill':
      return `${switcher(ctx, 'underline')}<form class="search-form sf-pill" data-search>${all}${submit('')}</form>`;
    case 'stack':
      return `${switcher(ctx, 'select')}<form class="search-form sf-stack" data-search>${all}${submit('Find ' + svc.label.toLowerCase())}</form>`;
    case 'icons':
      return `${switcher(ctx, 'icons')}<form class="search-form sf-row" data-search>${all}${submit()}</form>`;
    case 'magazine':
      return `${switcher(ctx, 'text')}<form class="search-form sf-boxed" data-search>${all}${submit('Search ' + svc.label)}</form>`;
    case 'split':
      return `${switcher(ctx, 'chips')}<form class="search-form sf-grid" data-search>${all}${submit()}</form>`;
    case 'fullbleed':
      return `${switcher(ctx, 'glass')}<form class="search-form sf-row sf-glass" data-search>${all}${submit()}</form>`;
    default:
      return `${switcher(ctx, 'tabs')}<form class="search-form sf-row" data-search>${all}${submit()}</form>`;
  }
}

export function readForm(form) {
  return Object.fromEntries(new FormData(form).entries());
}
