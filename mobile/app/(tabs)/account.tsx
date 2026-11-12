// Account: profile, wallet, currency, help and legal pages, sign out, delete account.
import React, { useCallback, useState } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { Ionicons } from '@expo/vector-icons';
import { api } from '../../src/api';
import { useApp } from '../../src/store';
import { money } from '../../src/money';
import { WEBSITE_URL } from '../../src/config';
import { ROLE_LABEL } from '../../src/roles';
import { Button, Card, Choice, H2, Input, Muted, Row } from '../../src/components/ui';
import { C } from '../../src/theme';
import Constants from 'expo-constants';

function Link({ icon, title, onPress, testID }: { icon: any; title: string; onPress: () => void; testID?: string }) {
  return (
    <Pressable testID={testID} onPress={onPress} accessibilityRole="link" style={{ flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 12, borderBottomWidth: 1, borderBottomColor: '#f1f5f9' }}>
      <Ionicons name={icon} size={20} color={C.navy} /><Text style={{ flex: 1, color: C.ink, fontSize: 15 }}>{title}</Text><Ionicons name="chevron-forward" size={18} color="#94a3b8" />
    </Pressable>
  );
}

export default function Account() {
  const { user, partner, signOut, currency, currencies, setCurrency, toast, brand } = useApp();
  const [wallet, setWallet] = useState<any>(null);
  const [del, setDel] = useState(false);
  const [pw, setPw] = useState('');
  const [confirm, setConfirm] = useState('');
  const [busy, setBusy] = useState(false);

  useFocusEffect(useCallback(() => { if (user) api('GET', '/wallet').then((r) => setWallet(r.data)).catch(() => setWallet(null)); }, [user]));
  const open = (path: string) => WebBrowser.openBrowserAsync(`${WEBSITE_URL}${path}`);

  const deleteAccount = async () => {
    setBusy(true);
    try { const r = await api('POST', '/privacy/delete-account', { password: pw, confirm }); toast(r.message || 'Your account has been deleted.'); await signOut(); setDel(false); }
    catch (e: any) { toast(e.message); }
    setBusy(false);
  };

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      {user ? (
        <Card>
          <View style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
            <View style={{ width: 48, height: 48, borderRadius: 24, backgroundColor: C.primary, alignItems: 'center', justifyContent: 'center' }}><Text style={{ color: '#fff', fontWeight: '800', fontSize: 20 }}>{(user.name || '?').slice(0, 1).toUpperCase()}</Text></View>
            <View style={{ flex: 1 }}><Text style={{ fontWeight: '800', fontSize: 17, color: C.ink }} testID="acct-name">{user.name}</Text><Muted>{user.email}</Muted>{partner ? <Muted>{ROLE_LABEL[String(user.role)] || 'Partner'}</Muted> : null}</View>
          </View>
          {wallet ? <Row label="Wallet balance" value={money(wallet.balance ?? wallet.available_balance ?? 0)} testID="acct-wallet" /> : null}
          {wallet && partner && Number(wallet.credit_limit) ? <Row label="Credit available" value={money(wallet.credit_available)} /> : null}
        </Card>
      ) : (
        <Card>
          <H2>Welcome to {brand.name}</H2>
          <Muted style={{ marginBottom: 12 }}>Sign in to see your trips, use your wallet and book faster.</Muted>
          <Button title="Sign in" onPress={() => router.push('/login')} testID="acct-login" />
          <Button kind="ghost" title="Create account" onPress={() => router.push({ pathname: '/login', params: { mode: 'register' } })} style={{ marginTop: 8 }} />
          <Button kind="link" title="Travel agent or partner? Partner sign in" onPress={() => router.push({ pathname: '/login', params: { who: 'partner' } })} style={{ marginTop: 8 }} testID="acct-partner-login" />
        </Card>
      )}
      <Card>
        <Choice label="Show prices in" value={currency} options={currencies.map((c) => [c, c])} onChange={setCurrency} testID="cur" />
        <Muted>Your card is charged in this currency at the day's rate.</Muted>
      </Card>
      <Card>
        <H2>Help & information</H2>
        <Link icon="call-outline" title="Contact us" onPress={() => open('/contact-us')} />
        <Link icon="business-outline" title="Partner with us (travel agents)" onPress={() => open('/partners')} />
        <Link icon="document-text-outline" title="Terms & conditions" onPress={() => open('/terms-and-conditions')} />
        <Link icon="shield-checkmark-outline" title="Privacy policy" onPress={() => open('/privacy-policy')} />
        <Link icon="return-down-back-outline" title="Cancellation policy" onPress={() => open('/cancellation-policy')} />
      </Card>
      {user ? (
        <>
          <Button kind="ghost" title="Sign out" icon="log-out-outline" onPress={signOut} testID="acct-logout" />
          {!del ? <Button kind="link" title="Delete my account" onPress={() => setDel(true)} style={{ marginTop: 12 }} testID="acct-delete" /> : (
            <Card style={{ marginTop: 12, borderColor: C.err }}>
              <H2>Delete your account?</H2>
              <Muted style={{ marginBottom: 10 }}>Your login and personal details are removed. Booking and invoice records are kept for 8 years as tax law requires.</Muted>
              <Input label="Password" value={pw} onChangeText={setPw} secureTextEntry />
              <Input label="Type DELETE to confirm" value={confirm} onChangeText={setConfirm} autoCapitalize="characters" />
              <Button kind="danger" title="Delete account" busy={busy} onPress={deleteAccount} />
              <Button kind="link" title="Keep my account" onPress={() => setDel(false)} />
            </Card>
          )}
        </>
      ) : null}
      <Muted style={{ textAlign: 'center', marginTop: 16 }}>Version {Constants.expoConfig?.version}</Muted>
    </ScrollView>
  );
}
