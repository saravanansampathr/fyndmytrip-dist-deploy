// Thin client for the FyndMyTrip API. The base URL comes from config.js
// (window.FMT_CONFIG.apiBase). X-Storefront-Host tells the API which
// white-label storefront a guest is on (see backend ResolveTenant).
const cfg = window.FMT_CONFIG || {};
export const API_BASE = (cfg.apiBase || 'https://api-demo.fyndmytrip.in/api').replace(/\/$/, '');

const store = {
  get: (k) => { try { return JSON.parse(sessionStorage.getItem('fmt:' + k)); } catch { return null; } },
  set: (k, v) => { try { sessionStorage.setItem('fmt:' + k, JSON.stringify(v)); } catch { /* private mode */ } },
  del: (k) => { try { sessionStorage.removeItem('fmt:' + k); } catch { /* ignore */ } },
};
export { store };

export const auth = {
  get token() { return store.get('token'); },
  get user() { return store.get('user'); },
  save(token, user) { store.set('token', token); store.set('user', user); },
  clear() { store.del('token'); store.del('user'); },
  // A guest checkout session only opens the guest's own booking; it is not
  // a signed-in account (see backend GuestCheckoutService).
  get member() { const u = store.get('user'); return u && !u.is_guest ? u : null; },
};

export class ApiError extends Error {
  constructor(status, body) {
    const errs = body && body.errors ? Object.values(body.errors).flat().join(' ') : '';
    super(errs || (body && body.message) || `Request failed (${status})`);
    this.status = status; this.body = body;
  }
}

export async function api(method, path, body, { raw = false } = {}) {
  let url = API_BASE + path;
  const headers = { Accept: 'application/json', 'X-Storefront-Host': cfg.storefrontHost || location.host };
  if (auth.token) headers.Authorization = 'Bearer ' + auth.token;
  const init = { method, headers };
  if (body && method === 'GET') {
    const qs = new URLSearchParams(Object.entries(body).filter(([, v]) => v !== '' && v != null)).toString();
    if (qs) url += '?' + qs;
  } else if (body) {
    headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }
  const res = await fetch(url, init);
  if (raw) { if (!res.ok) throw new ApiError(res.status, null); return res; }
  const text = await res.text();
  let json = null;
  try { json = text ? JSON.parse(text) : null; } catch { json = { message: text.slice(0, 200) }; }
  // An expired session, or a guest session used outside its booking.
  if (res.status === 401 && auth.token) auth.clear();
  if (!res.ok) throw new ApiError(res.status, json);
  return json;
}
