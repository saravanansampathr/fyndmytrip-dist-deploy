// Thin client for the FyndMyTrip API, the same calls the website storefront
// makes (storefront/js/api.js).
import { API_BASE, STOREFRONT_HOST } from './config';

export type User = { id: number; name: string; email?: string | null; phone?: string | null; role?: string; is_guest?: boolean };

let token: string | null = null;
let onUnauthorized: (() => void) | null = null;
export const setToken = (t: string | null) => { token = t; };
export const getToken = () => token;
export const setUnauthorizedHandler = (fn: () => void) => { onUnauthorized = fn; };

export class ApiError extends Error {
  status: number; body: any;
  constructor(status: number, body: any) {
    const errs = body && body.errors ? (Object.values(body.errors) as any[]).flat().join(' ') : '';
    super(errs || (body && body.message) || `Request failed (${status})`);
    this.status = status; this.body = body;
  }
}

export function apiHeaders(): Record<string, string> {
  const h: Record<string, string> = { Accept: 'application/json', 'X-Storefront-Host': STOREFRONT_HOST, 'X-Client': 'fyndmytrip-app' };
  if (token) h.Authorization = 'Bearer ' + token;
  return h;
}

export function apiUrl(path: string, query?: Record<string, any>) {
  let url = API_BASE + path;
  if (query) {
    const qs = Object.entries(query).filter(([, v]) => v !== '' && v != null).map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`).join('&');
    if (qs) url += (url.includes('?') ? '&' : '?') + qs;
  }
  return url;
}

export async function api<T = any>(method: string, path: string, body?: Record<string, any> | null): Promise<T> {
  const headers = apiHeaders();
  const init: RequestInit = { method, headers };
  let url = API_BASE + path;
  if (body && method === 'GET') url = apiUrl(path, body);
  else if (body) { headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(body); }
  let res: Response;
  try { res = await fetch(url, init); } catch { throw new ApiError(0, { message: 'No internet connection. Please check your network and try again.' }); }
  const text = await res.text();
  let json: any = null;
  try { json = text ? JSON.parse(text) : null; } catch { json = { message: text.slice(0, 200) }; }
  // An expired session, or a guest session used outside its own booking.
  if (res.status === 401 && token) onUnauthorized?.();
  if (!res.ok) throw new ApiError(res.status, json);
  return json as T;
}
