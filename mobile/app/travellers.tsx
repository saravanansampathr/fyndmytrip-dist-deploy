// Who's travelling, contact details, then hold the booking. Guests book
// without an account (flights, hotels, buses, holidays); a guest session can
// only open its own booking and never signs the guest in.
import React, { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { api } from '../src/api';
import { SERVICES } from '../src/services';
import { flow, useApp } from '../src/store';
import { money } from '../src/money';
import { Button, Card, Choice, DateField, Empty, H2, Input, Muted, Steps, Trust } from '../src/components/ui';
import { C } from '../src/theme';

const pretty = (v: string) => v.replace(/_/g, ' ');

export default function Travellers() {
  const { svc: key } = useLocalSearchParams<{ svc: string }>();
  const svc = SERVICES[key || 'flight'];
  const { user, signIn, toast } = useApp();
  const pick = flow.pick;
  const count = pick ? svc.pax(pick.query) : 1;
  const [people, setPeople] = useState<Record<string, string>[]>(() => Array.from({ length: count }, (_, i) => {
    const p: Record<string, string> = {};
    for (const [name, , type, opts] of svc.traveller) p[name] = type === 'select' && opts ? opts[0] : '';
    if (i === 0 && user) { p.name = user.name; p.first_name = user.name.split(' ')[0]; p.last_name = user.name.split(' ').slice(1).join(' '); }
    return p;
  }));
  const [email, setEmail] = useState(user?.email || '');
  const [phone, setPhone] = useState(user?.phone || '');
  const [busy, setBusy] = useState(false);

  if (!pick || pick.svc !== svc.key) return <Empty title="Please start your search again" />;

  const set = (i: number, k: string, v: string) => setPeople((pp) => pp.map((p, j) => (j === i ? { ...p, [k]: v } : p)));
  const canGuest = !!svc.guest;

  const submit = async () => {
    const required = svc.traveller.filter(([n, , t]) => t !== 'select' && !['date_of_birth', 'nationality'].includes(n)).map(([n]) => n);
    if (people.some((p) => required.some((n) => !String(p[n] || '').trim()))) { toast("Please fill in every traveller's details."); return; }
    if (!/^\S+@\S+\.\S+$/.test(email.trim())) { toast('Please enter a valid contact email.'); return; }
    const guest = !user;
    if (guest && !canGuest) { toast(`Please sign in or create an account to book ${svc.label.toLowerCase()}.`); return; }
    setBusy(true);
    const pp = people.map((p) => Object.fromEntries(Object.entries(p).map(([k, v]) => [k, String(v).trim()])));
    const payload: Record<string, any> = { ...svc.hold(pick.offer, pick.query, pp, pick.extra || {}), contact_email: email.trim(), contact_phone: phone.trim() || undefined };
    if (guest) Object.assign(payload, { guest_name: (pp[0].name || `${pp[0].first_name || ''} ${pp[0].last_name || ''}`).trim(), guest_email: email.trim(), guest_phone: phone.trim() || undefined });
    const path = svc.bookPath ? svc.bookPath(pick.offer, guest) : (guest ? (svc.guest as string) : (svc.base as string));
    try {
      const r = await api('POST', path, payload);
      if (r.token) await signIn(r.token, r.user); // guest session for this booking only
      flow.booking = { svc: svc.key, booking: r.data, offer: pick.offer };
      router.push({ pathname: '/payment', params: { svc: svc.key, id: String(r.data.id) } });
    } catch (e: any) { toast(e.message); }
    setBusy(false);
  };

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined} keyboardVerticalOffset={90}>
      <Steps at={1} />
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        <Card style={{ backgroundColor: '#fff7f9' }}>
          <Text style={{ fontWeight: '700', color: C.ink }}>{svc.title(pick.offer)}</Text>
          <Muted>{svc.subtitle(pick.offer)}</Muted>
          {pick.extra?.seats?.length ? <Text style={{ marginTop: 4 }}>Seats: <Text style={{ fontWeight: '700' }}>{pick.extra.seats.join(', ')}</Text></Text> : null}
          <Text style={{ marginTop: 4, color: C.ink }}>Estimated <Text style={{ fontWeight: '800' }}>{money(pick.estimate)}</Text></Text>
        </Card>

        {user ? (
          <Card><View style={{ flexDirection: 'row', gap: 8, alignItems: 'center' }}><Ionicons name="person-circle" size={22} color={C.ok} /><Text style={{ flex: 1 }} testID="booking-as">Booking as <Text style={{ fontWeight: '700' }}>{user.name}</Text></Text></View></Card>
        ) : (
          <Card>
            <H2>{canGuest ? 'Sign in or continue as a guest' : 'Sign in to continue'}</H2>
            <Muted>{canGuest ? 'Sign in to use your wallet and see this trip in My Trips. Or just fill in the details below: no account or password needed.' : `${svc.label} need an account. Sign in or create one in seconds.`}</Muted>
            <Button kind="ghost" title={canGuest ? 'Sign in' : 'Sign in or create account'} icon="log-in-outline" onPress={() => router.push({ pathname: '/login', params: { back: '1' } })} style={{ marginTop: 10 }} testID="trav-login" />
          </Card>
        )}

        {people.map((p, i) => (
          <Card key={i}>
            <H2>{svc.key === 'hotel' ? 'Guest' : svc.key === 'visa' ? 'Applicant' : 'Traveller'} {i + 1}{i === 0 ? ' (lead)' : ''}</H2>
            {svc.traveller.map(([name, label, type, opts]) => type === 'select' && opts
              ? <Choice key={name} label={label} value={p[name]} options={opts.map((o) => [o, pretty(o)])} onChange={(v) => set(i, name, v)} testID={`p${i}-${name}`} />
              : type === 'date'
                ? <DateField key={name} label={label} value={p[name]} onChange={(v) => set(i, name, v)} testID={`p${i}-${name}`} />
                : <Input key={name} label={label} value={p[name]} onChangeText={(v) => set(i, name, v)} testID={`p${i}-${name}`} keyboardType={type === 'number' ? 'number-pad' : 'default'} autoCapitalize={name.includes('passport') ? 'characters' : 'words'} />)}
          </Card>
        ))}

        <Card>
          <H2>Contact details</H2>
          <Muted style={{ marginBottom: 10 }}>We send your ticket and updates here.</Muted>
          <Input label="Email" value={email} onChangeText={setEmail} keyboardType="email-address" autoCapitalize="none" autoComplete="email" testID="c-email" />
          <Input label="Mobile" value={phone} onChangeText={setPhone} keyboardType="phone-pad" placeholder="+91" autoComplete="tel" testID="c-phone" />
        </Card>
        <Button title="Continue to payment" icon="arrow-forward" busy={busy} onPress={submit} testID="to-payment" />
        <Trust />
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
