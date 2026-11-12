// Sign in, create an account, or reset a forgotten password.
import React, { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { api } from '../src/api';
import * as WebBrowser from 'expo-web-browser';
import { useApp } from '../src/store';
import { isPartner, ROLE_LABEL } from '../src/roles';
import { WEBSITE_URL } from '../src/config';
import { Button, Card, Choice, H2, Input, Muted } from '../src/components/ui';

export default function Login() {
  const { mode: m0, who: w0 } = useLocalSearchParams<{ mode?: string; who?: string }>();
  const [who, setWho] = useState<'customer' | 'partner'>(w0 === 'partner' ? 'partner' : 'customer');
  const { signIn, toast, brand } = useApp();
  const [mode, setMode] = useState<'login' | 'register' | 'forgot'>((m0 as any) || 'login');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [need2fa, setNeed2fa] = useState(false);
  const [busy, setBusy] = useState(false);

  const done = async (r: any) => {
    await signIn(r.token, r.user);
    const p = isPartner(r.user);
    toast(`Welcome, ${String(r.user?.name || '').split(' ')[0]}!${p ? ` Signed in as ${ROLE_LABEL[r.user.role] || 'partner'}.` : ''}`);
    if (p && who === 'partner') { if (router.canDismiss()) router.dismissAll(); router.replace('/partner'); return; }
    if (router.canGoBack()) router.back(); else router.replace('/');
  };

  const submit = async () => {
    setBusy(true);
    try {
      if (mode === 'login') {
        const r = await api('POST', '/auth/login', { email: email.trim(), password, two_factor_code: need2fa ? code.trim() : undefined });
        await done(r);
      } else if (mode === 'register') {
        const r = await api('POST', '/auth/register', { name: name.trim(), email: email.trim(), phone: phone.trim() || undefined, password, password_confirmation: password });
        await done(r);
      } else {
        const r = await api('POST', '/auth/forgot-password', { email: email.trim() });
        toast(r?.message || 'If that email has an account, we have sent a reset link.');
        setMode('login');
      }
    } catch (e: any) {
      if (e.body?.two_factor_required) { setNeed2fa(true); toast('Enter the 6-digit code from your authenticator app.'); }
      else toast(e.message);
    }
    setBusy(false);
  };

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={{ padding: 16 }} keyboardShouldPersistTaps="handled">
        <Choice label="I am a" value={who} options={[['customer', 'Customer'], ['partner', 'Partner / Agent']]} onChange={(v) => { setWho(v as any); if (v === 'partner' && mode === 'register') setMode('login'); }} testID="auth-who" />
        {who === 'customer' ? <Choice label="" value={mode === 'forgot' ? 'login' : mode} options={[['login', 'Sign in'], ['register', 'Create account']]} onChange={(v) => setMode(v as any)} testID="auth-mode" /> : null}
        <Card>
          <H2>{mode === 'login' ? (who === 'partner' ? 'Partner sign in' : `Sign in to ${brand.name}`) : mode === 'register' ? 'Create your account' : 'Reset your password'}</H2>
          {who === 'partner' && mode === 'login' ? <Muted style={{ marginBottom: 10 }}>For travel agents, sub-agents, white-label partners, API partners and their staff. Use the same login as the partner portal.</Muted> : null}
          {mode === 'register' ? <Input label="Full name" value={name} onChangeText={setName} autoComplete="name" testID="a-name" /> : null}
          <Input label="Email" value={email} onChangeText={setEmail} keyboardType="email-address" autoCapitalize="none" autoComplete="email" testID="a-email" />
          {mode === 'register' ? <Input label="Mobile (optional)" value={phone} onChangeText={setPhone} keyboardType="phone-pad" placeholder="+91" testID="a-phone" /> : null}
          {mode !== 'forgot' ? <Input label={mode === 'register' ? 'Choose a password (8+ characters)' : 'Password'} value={password} onChangeText={setPassword} secureTextEntry autoComplete={mode === 'register' ? 'new-password' : 'current-password'} testID="a-password" /> : null}
          {need2fa && mode === 'login' ? <Input label="6-digit code" value={code} onChangeText={setCode} keyboardType="number-pad" maxLength={8} testID="a-2fa" /> : null}
          <Button title={mode === 'login' ? 'Sign in' : mode === 'register' ? 'Create account' : 'Send reset link'} busy={busy} onPress={submit} testID="a-submit" />
          {mode === 'login' ? <Button kind="link" title="Forgot password?" onPress={() => setMode('forgot')} style={{ marginTop: 8 }} /> : null}
          {mode === 'forgot' ? <Button kind="link" title="Back to sign in" onPress={() => setMode('login')} style={{ marginTop: 8 }} /> : null}
        </Card>
        {who === 'partner' ? (
          <Card>
            <H2>Not a partner yet?</H2>
            <Muted style={{ marginBottom: 10 }}>Travel agents can apply with PAN, GSTIN and KYC documents. We review and set up your account, wallet and credit.</Muted>
            <Button kind="ghost" title="Become a partner" icon="briefcase-outline" onPress={() => WebBrowser.openBrowserAsync(`${WEBSITE_URL}/agent-signup`)} testID="become-partner" />
          </Card>
        ) : <Muted>Booking flights, hotels, buses or holidays? You can also book as a guest without an account.</Muted>}
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
