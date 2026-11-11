// Prices come from the API in INR. When the traveller picks (or is given,
// from their country) another currency, amounts are shown converted at the
// API's rate and that currency is what the card is charged in. Same rules
// as the website (storefront/js/ui.js).
export const fx = { base: 'INR', rates: { INR: 1 } as Record<string, number>, cur: 'INR' };

export const charging = () => (fx.cur !== fx.base && fx.rates[fx.cur] ? fx.cur : fx.base);

const SYMBOLS: Record<string, string> = { INR: '₹', USD: '$', EUR: '€', GBP: '£', AED: 'AED ', SAR: 'SAR ', SGD: 'S$', AUD: 'A$', CAD: 'C$', JPY: '¥' };

export function fmt(n: any, cur = 'INR') {
  const v = Number(n) || 0;
  const digits = ['INR', 'JPY'].includes(cur) ? 0 : 2;
  try {
    return new Intl.NumberFormat(cur === 'INR' ? 'en-IN' : 'en', { style: 'currency', currency: cur, maximumFractionDigits: digits, minimumFractionDigits: digits }).format(v);
  } catch {
    const s = v.toFixed(digits).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return (SYMBOLS[cur] || cur + ' ') + s;
  }
}

export const inr = (n: any) => fmt(n, 'INR');

/** An INR amount shown in the traveller's currency. */
export function money(n: any, cur = 'INR') {
  const to = charging();
  if ((cur || 'INR') === fx.base && to !== fx.base) return fmt((Number(n) || 0) * fx.rates[to], to);
  return fmt(n, cur || 'INR');
}

export function fmtDate(d?: string | null) {
  if (!d) return '';
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d);
  if (!m) return d;
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  return `${+m[3]} ${months[+m[2] - 1]} ${m[1]}`;
}
