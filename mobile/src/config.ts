// Where the app talks to. Change these in app.json ("extra") or with the
// EXPO_PUBLIC_API_BASE / EXPO_PUBLIC_STOREFRONT_HOST environment variables
// at build time, e.g. for the live domain.
import Constants from 'expo-constants';

const extra = (Constants.expoConfig?.extra || {}) as Record<string, string>;

export const API_BASE = (process.env.EXPO_PUBLIC_API_BASE || extra.apiBase || 'https://api-demo.fyndmytrip.in/api').replace(/\/$/, '');
// Tells the API which website's brand, prices and partner this app belongs
// to (backend ResolveTenant reads X-Storefront-Host). A white-label partner's
// own app sets its domain here.
export const STOREFRONT_HOST = process.env.EXPO_PUBLIC_STOREFRONT_HOST || extra.storefrontHost || 'demo.fyndmytrip.in';
export const WEBSITE_URL = (process.env.EXPO_PUBLIC_WEBSITE_URL || extra.websiteUrl || 'https://demo.fyndmytrip.in').replace(/\/$/, '');
