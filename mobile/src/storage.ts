// The sign-in token goes in the phone's secure storage (Keychain / Keystore).
// Other small settings (currency) go in AsyncStorage. On the web build used
// for tests, both fall back to localStorage.
import { Platform } from 'react-native';
import * as SecureStore from 'expo-secure-store';
import AsyncStorage from '@react-native-async-storage/async-storage';

const web = Platform.OS === 'web';
const ls = () => { try { return globalThis.localStorage; } catch { return undefined; } };

export const secure = {
  async get(k: string) { if (web) return ls()?.getItem(k) ?? null; try { return await SecureStore.getItemAsync(k); } catch { return null; } },
  async set(k: string, v: string) { if (web) { ls()?.setItem(k, v); return; } await SecureStore.setItemAsync(k, v); },
  async del(k: string) { if (web) { ls()?.removeItem(k); return; } try { await SecureStore.deleteItemAsync(k); } catch { /* gone */ } },
};

export const prefs = {
  async get(k: string) { try { return await AsyncStorage.getItem(k); } catch { return null; } },
  async set(k: string, v: string) { try { await AsyncStorage.setItem(k, v); } catch { /* ignore */ } },
};
