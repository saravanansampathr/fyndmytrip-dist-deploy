// Brand colours and spacing. The main colour comes from the build settings
// (app.json extra.primaryColor, or APP_PRIMARY_COLOR for a white-label app).
import Constants from 'expo-constants';

const brand = String((Constants.expoConfig?.extra as any)?.primaryColor || '#ff234f');

export const C = {
  primary: /^#[0-9a-fA-F]{6}$/.test(brand) ? brand : '#ff234f',
  primaryDark: '#d81b42',
  ink: '#0f172a',
  text: '#1e293b',
  muted: '#64748b',
  line: '#e2e8f0',
  bg: '#f6f7fb',
  card: '#ffffff',
  ok: '#16a34a',
  okBg: '#dcfce7',
  warn: '#b45309',
  warnBg: '#fef3c7',
  err: '#dc2626',
  errBg: '#fee2e2',
  navy: '#1d2b53',
};
export const R = 12;
