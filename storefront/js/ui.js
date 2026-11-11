// Small shared helpers: escaping, money, icons, and applying a template's theme.
export const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
// Prices come from the API in INR. When the visitor has picked (or been
// given, from their country) another currency, amounts are shown converted
// at the API's rate, and that currency is what the card is charged in.
export const fx = { base: 'INR', rates: { INR: 1 }, cur: 'INR' };
const CUR_KEY = 'fyndmytrip_currency'; // shared with the portal on the same domain
try { fx.cur = localStorage.getItem(CUR_KEY) || 'INR'; } catch { /* storage blocked */ }
export function setCurrency(c) { fx.cur = c; try { localStorage.setItem(CUR_KEY, c); } catch { /* ignore */ } }
export const hasStoredCurrency = () => { try { return !!localStorage.getItem(CUR_KEY); } catch { return false; } };
export const charging = () => fx.cur !== fx.base && fx.rates[fx.cur] ? fx.cur : fx.base;
const fmt = (n, cur) => {
  try { return new Intl.NumberFormat(cur === 'INR' ? 'en-IN' : 'en', { style: 'currency', currency: cur, maximumFractionDigits: ['INR', 'JPY'].includes(cur) ? 0 : 2 }).format(Number(n) || 0); } catch { return '₹' + Math.round(n); }
};
export const inr = (n) => fmt(n, 'INR');
export const money = (n, cur = 'INR') => {
  const to = charging();
  if ((cur || 'INR') === fx.base && to !== fx.base) return fmt((Number(n) || 0) * fx.rates[to], to);
  return fmt(n, cur || 'INR');
};
export const fmtDate = (d) => { try { return new Date(d + (d.length === 10 ? 'T00:00' : '')).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }); } catch { return d; } };

const P = {
  plane: 'M21 16v-2l-8-5V3.5a1.5 1.5 0 0 0-3 0V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5z',
  bed: 'M3 7v11M3 14h18v4M21 14v-3a3 3 0 0 0-3-3h-7v6M7 11.5a1.5 1.5 0 1 0 0-.01',
  bus: 'M5 17h14V6a3 3 0 0 0-3-3H8a3 3 0 0 0-3 3zM5 11h14M7.5 20v-3M16.5 20v-3M8 14h.01M16 14h.01',
  car: 'M5 13l2-5h10l2 5M3 13h18v5H3zM6.5 18v2M17.5 18v2M7 15.5h.01M17 15.5h.01',
  ship: 'M3 17l2 4h14l2-4-9-3zM6 14V8h12v6M12 3v5M9 11h.01M15 11h.01',
  ticket: 'M3 8V6h18v2a2 2 0 0 0 0 4v6H3v-6a2 2 0 0 0 0-4zM13 6v12',
  shield: 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM9 12l2 2 4-4',
  palm: 'M12 22V11M12 11C9 7 5 7 3 9M12 11c3-4 7-4 9-2M12 11c-1-4-4-6-7-6M12 11c1-4 4-6 7-6M8 22h8',
  passport: 'M6 3h12v18H6zM12 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM9 17h6',
  search: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM20 20l-4-4',
  user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0',
  check: 'M5 12l5 5 9-10',
  arrow: 'M5 12h14M13 6l6 6-6 6',
  swap: 'M7 4l-4 4 4 4M3 8h14M17 20l4-4-4-4M21 16H7',
  lock: 'M6 11h12v10H6zM8 11V7a4 4 0 0 1 8 0v4',
  card: 'M3 6h18v12H3zM3 10h18M7 15h3',
  phone: 'M8 3h8v18H8zM11 18h2',
  bank: 'M3 10l9-6 9 6M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 20h18',
  wallet: 'M3 7h16v12H3zM3 7l12-4v4M16 13h.01',
  globe: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18',
  star: 'M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z',
};
export const icon = (name, size = 20) => `<svg class="ic" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${P[name] || P.arrow}"/></svg>`;

const SHADOWS = {
  soft: '0 10px 30px -12px rgba(15,23,42,.25)',
  hard: '4px 4px 0 0 var(--ink)',
  line: '0 0 0 1px var(--line)',
  glow: '0 0 0 1px rgba(255,255,255,.06), 0 12px 40px -10px var(--primary)',
  none: 'none',
};

function hexToRgb(h) { const n = parseInt(h.slice(1), 16); return [n >> 16 & 255, n >> 8 & 255, n & 255]; }
function lum(h) { const [r, g, b] = hexToRgb(h).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; }
const onColor = (h) => (lum(h) > 0.45 ? '#0b0f19' : '#ffffff');

export function applyTheme(t) {
  const root = document.documentElement;
  const { primary, accent, bg } = t.colors;
  const dark = t.dark;
  const vars = {
    '--primary': primary, '--on-primary': onColor(primary),
    '--accent': accent, '--on-accent': onColor(accent),
    '--bg': bg,
    '--surface': dark ? 'color-mix(in srgb, ' + bg + ' 88%, white)' : '#ffffff',
    '--surface-2': dark ? 'color-mix(in srgb, ' + bg + ' 78%, white)' : 'color-mix(in srgb, ' + primary + ' 6%, white)',
    '--ink': dark ? '#f1f5f9' : '#0f172a',
    '--muted': dark ? '#94a3b8' : '#5b6474',
    '--line': dark ? 'rgba(255,255,255,.12)' : 'rgba(15,23,42,.12)',
    '--radius': t.radius + 'px',
    '--radius-sm': Math.min(t.radius, 10) + 'px',
    '--shadow': SHADOWS[t.shadow] || SHADOWS.soft,
    '--font-head': `'${t.fonts.head}', system-ui, sans-serif`,
    '--font-body': `'${t.fonts.body}', system-ui, sans-serif`,
  };
  for (const [k, v] of Object.entries(vars)) root.style.setProperty(k, v);
  root.dataset.dark = dark ? '1' : '0';
  root.dataset.template = t.slug;
  for (const [axis, v] of Object.entries(t.layout)) root.dataset[axis] = v;
  const fams = [...new Set([t.fonts.head, t.fonts.body])].map((f) => 'family=' + f.replace(/ /g, '+') + ':wght@400;600;700').join('&');
  let link = document.getElementById('tpl-fonts');
  if (!link) { link = document.createElement('link'); link.id = 'tpl-fonts'; link.rel = 'stylesheet'; document.head.appendChild(link); }
  link.href = `https://fonts.googleapis.com/css2?${fams}&display=swap`;
}

export function toast(msg, kind = 'error') {
  console.warn('[toast] ' + msg);
  const el = document.createElement('div');
  el.className = 'toast toast-' + kind; el.setAttribute('role', 'alert'); el.textContent = msg;
  document.body.appendChild(el); setTimeout(() => el.remove(), 6000);
}
