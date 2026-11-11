// App-wide state: who is signed in, the website brand, the display currency
// and a small toast. The booking in progress (search → pay) is kept in
// `flow`, a plain object, because each step only reads what the step
// before it wrote.
import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { Animated, StyleSheet, Text } from 'react-native';
import { getLocales, getCalendars } from 'expo-localization';
import { api, setToken, setUnauthorizedHandler, User } from './api';
import { secure, prefs } from './storage';
import { fx } from './money';
import { C } from './theme';

export const flow: {
  offers: Record<string, any[]>;
  query: Record<string, any>;
  pick: null | { svc: string; offer: any; query: any; extra: any; estimate: number };
  booking: null | { svc: string; booking: any; offer?: any };
  trips: Record<string, any>;
} = { offers: {}, query: {}, pick: null, booking: null, trips: {} };

type Brand = { name: string; [k: string]: any };
type Ctx = {
  ready: boolean;
  user: User | null;          // a real signed-in account (never a guest session)
  hasSession: boolean;        // any token, guest or member
  brand: Brand;
  currency: string;
  currencies: string[];
  setCurrency: (c: string) => void;
  signIn: (token: string, user: User) => Promise<void>;
  signOut: () => Promise<void>;
  toast: (msg: string) => void;
};

const AppCtx = createContext<Ctx>(null as any);
export const useApp = () => useContext(AppCtx);

const TOKEN = 'fmt_token', USER = 'fmt_user', CUR = 'fyndmytrip_currency';

export function AppProvider({ children }: { children: React.ReactNode }) {
  const [ready, setReady] = useState(false);
  const [rawUser, setRawUser] = useState<User | null>(null);
  const [hasSession, setHasSession] = useState(false);
  const [brand, setBrand] = useState<Brand>({ name: 'FyndMyTrip' });
  const [currency, setCur] = useState('INR');
  const [currencies, setCurrencies] = useState<string[]>(['INR']);
  const [msg, setMsg] = useState('');
  const fade = useRef(new Animated.Value(0)).current;
  const timer = useRef<any>(null);

  const toast = useCallback((m: string) => {
    setMsg(m);
    Animated.timing(fade, { toValue: 1, duration: 150, useNativeDriver: false }).start();
    clearTimeout(timer.current);
    timer.current = setTimeout(() => Animated.timing(fade, { toValue: 0, duration: 250, useNativeDriver: false }).start(() => setMsg('')), 3800);
  }, [fade]);

  const clear = useCallback(async () => {
    setToken(null); setRawUser(null); setHasSession(false);
    await secure.del(TOKEN); await secure.del(USER);
  }, []);

  const signIn = useCallback(async (token: string, user: User) => {
    setToken(token); setRawUser(user); setHasSession(true);
    await secure.set(TOKEN, token); await secure.set(USER, JSON.stringify(user));
  }, []);

  const signOut = useCallback(async () => {
    try { await api('POST', '/auth/logout'); } catch { /* token may already be gone */ }
    await clear();
  }, [clear]);

  const setCurrency = useCallback((c: string) => { fx.cur = c; setCur(c); prefs.set(CUR, c); }, []);

  useEffect(() => {
    setUnauthorizedHandler(() => { clear(); });
    (async () => {
      const t = await secure.get(TOKEN);
      const u = await secure.get(USER);
      if (t) { setToken(t); setHasSession(true); try { setRawUser(u ? JSON.parse(u) : null); } catch { /* ignore */ } }
      try { const r = await api('GET', '/whitelabel/current'); if (r?.data) setBrand(r.data); } catch { /* offline: still open */ }
      try {
        const tz = getCalendars()[0]?.timeZone || '';
        const r = (await api('GET', '/currency-rates', { tz, country: getLocales()[0]?.regionCode || '' })).data;
        fx.base = r.base || 'INR'; fx.rates = r.rates || { INR: 1 };
        setCurrencies(Object.keys(fx.rates));
        const stored = await prefs.get(CUR);
        const c = stored && fx.rates[stored] ? stored : (r.suggested && fx.rates[r.suggested] ? r.suggested : fx.base);
        fx.cur = c; setCur(c);
      } catch { /* rates unavailable: prices stay in INR */ }
      setReady(true);
    })();
  }, [clear]);

  // A guest checkout session only opens the guest's own booking; it is not a
  // signed-in account (see backend GuestCheckoutService).
  const user = rawUser && !rawUser.is_guest ? rawUser : null;
  const brandName = String(brand.name || 'FyndMyTrip').replace(/\s*\((master|default)\)\s*$/i, '');
  const value = useMemo(() => ({ ready, user, hasSession, brand: { ...brand, name: brandName }, currency, currencies, setCurrency, signIn, signOut, toast }),
    [ready, user, hasSession, brand, brandName, currency, currencies, setCurrency, signIn, signOut, toast]);

  return (
    <AppCtx.Provider value={value}>
      {children}
      {msg ? (
        <Animated.View pointerEvents="none" style={[s.toast, { opacity: fade }]} accessibilityLiveRegion="polite">
          <Text style={s.toastText} testID="toast">{msg}</Text>
        </Animated.View>
      ) : null}
    </AppCtx.Provider>
  );
}

const s = StyleSheet.create({
  toast: { position: 'absolute', left: 16, right: 16, bottom: 96, backgroundColor: C.ink, borderRadius: 10, paddingVertical: 12, paddingHorizontal: 16, zIndex: 1000 },
  toastText: { color: '#fff', fontSize: 14, textAlign: 'center' },
});
