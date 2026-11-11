// Pay for the held booking: UPI, cards, net banking, international card or
// the customer's wallet. Real payments go through the gateway the admin set
// (Razorpay in the app, or Stripe's hosted page); without keys the API uses
// its simulated payment, like the website.
import React, { useEffect, useRef, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { Ionicons } from '@expo/vector-icons';
import { api } from '../src/api';
import { SERVICES } from '../src/services';
import { flow, useApp } from '../src/store';
import { charging, fmtDate, fx, inr, money } from '../src/money';
import { fareRows } from '../src/fare';
import { Button, Card, Empty, H2, Muted, Row, Steps, Trust } from '../src/components/ui';
import { RazorpaySheet, RzpOptions } from '../src/components/RazorpaySheet';
import { C } from '../src/theme';
import { WEBSITE_URL } from '../src/config';

const METHODS: Record<string, [string, any, string]> = {
  upi: ['UPI', 'phone-portrait-outline', 'Google Pay, PhonePe, Paytm or any UPI app'],
  credit_card: ['Credit card', 'card-outline', 'Visa, Mastercard, RuPay, Amex'],
  debit_card: ['Debit card', 'card-outline', 'All major Indian banks'],
  netbanking: ['Net banking', 'business-outline', '50+ banks'],
  international_card: ['International card', 'globe-outline', 'Cards issued outside India'],
  fmt_wallet: ['My wallet', 'wallet-outline', 'Pay instantly from your account balance'],
};
const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

export default function Payment() {
  const { svc: key, id } = useLocalSearchParams<{ svc: string; id: string }>();
  const svc = SERVICES[key || 'flight'];
  const { user, hasSession, toast } = useApp();
  const [b, setB] = useState<any>(null);
  const [err, setErr] = useState('');
  const [wallet, setWallet] = useState<any>(null);
  const [method, setMethod] = useState('');
  const [busy, setBusy] = useState(false);
  const [rzp, setRzp] = useState<RzpOptions | null>(null);
  const rzpDone = useRef<(r: any) => void>(() => {});
  const payBase = svc.payBase || (svc.base as string);

  useEffect(() => {
    (async () => {
      if (!hasSession) { setErr('Your session has ended. Please start the booking again.'); return; }
      try {
        const saved = flow.booking && String(flow.booking.booking.id) === String(id) ? flow.booking.booking : null;
        const bk = svc.base ? (await api('GET', `${svc.base}/${id}`)).data : saved; // packages and visas have no single-booking GET
        if (!bk) throw new Error('Please start the booking again.');
        if (bk.status !== 'held') { router.replace({ pathname: '/done', params: { svc: svc.key, id: String(id) } }); return; }
        setB(bk);
      } catch (e: any) { setErr(e.message); }
      if (user) { try { setWallet((await api('GET', '/wallet')).data); } catch { /* no wallet */ } }
    })();
  }, []);

  if (err) return <Empty icon="alert-circle" title="Booking not found" text={err}><Button title="Back to search" onPress={() => router.dismissTo('/')} /></Empty>;
  if (!b) return <Empty icon="hourglass" title="Loading your booking…" />;

  const total = Number(b.total_amount);
  const bal = wallet ? Number(wallet.balance ?? wallet.available_balance ?? 0) : 0;
  const methods = Object.keys(METHODS).filter((m) => m !== 'fmt_wallet' || (wallet && !b.is_international && bal >= total));
  const offer = flow.booking?.offer;

  async function gateway(): Promise<Record<string, any>> {
    const c = (await api('POST', '/payments/checkout', { booking_id: Number(id), method, currency: charging() })).data;
    if (c.mode === 'razorpay') {
      const r: any = await new Promise((ok) => { rzpDone.current = ok; setRzp(c); });
      setRzp(null);
      if (!r.ok) throw new Error(r.error || 'Payment was not completed.');
      return { razorpay_payment_id: r.data.razorpay_payment_id, razorpay_order_id: r.data.razorpay_order_id, razorpay_signature: r.data.razorpay_signature, fmt_checked: true };
    }
    if (c.mode === 'stripe') {
      await WebBrowser.openBrowserAsync(c.checkout_url, { dismissButtonStyle: 'done' });
      // The page is closed (or the customer came back): ask the API, which
      // hears from Stripe's webhook, whether it was paid.
      for (let i = 0; i < 10; i++) {
        const s = (await api('GET', `/payments/checkout/${c.transaction_id}/status`)).data;
        if (s.paid) return { fmt_checked: true };
        await sleep(2000);
      }
      throw new Error('Payment was not completed. Your booking is still on hold, so you can pay again.');
    }
    return { fmt_checked: true }; // simulated: no gateway keys set
  }

  async function pay() {
    if (!method) { toast('Choose how you would like to pay.'); return; }
    setBusy(true);
    try {
      let r;
      if (method === 'fmt_wallet') r = await api('POST', `${payBase}/${id}/confirm`, { wallet_amount: total.toFixed(2) });
      else {
        r = await api('POST', `${payBase}/${id}/confirm`, { wallet_amount: 0 });
        if (r.requires_payment) { const paid = await gateway(); r = await api('POST', `${payBase}/${id}/pay`, { method, currency: charging(), ...paid }); }
      }
      flow.booking = { svc: svc.key, booking: r.data, offer };
      router.replace({ pathname: '/done', params: { svc: svc.key, id: String(id) } });
    } catch (e: any) { toast(e.message); setBusy(false); }
  }

  return (
    <View style={{ flex: 1 }}>
      <Steps at={2} />
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
        <Card testID="pay-summary">
          <H2>Booking summary</H2>
          <Text style={{ fontWeight: '700', color: C.ink, marginBottom: 6 }}>{offer ? svc.title(offer) : svc.label}</Text>
          <Row label="Booking ID" value={String(b.booking_code || b.booking_reference)} testID="pay-ref" />
          {b.travel_date_start ? <Row label="Travel date" value={fmtDate(b.travel_date_start)} /> : null}
          {b.passenger_count != null ? <Row label="Travellers" value={String(b.passenger_count)} /> : null}
          {fareRows(b).map((r) => <Row key={r.label} label={r.label} value={(r.amount < 0 ? '−' : '') + money(Math.abs(r.amount), b.currency)} />)}
          <Row label="Total payable" value={money(total, b.currency)} bold testID="pay-total" />
          {wallet ? <Muted style={{ marginTop: 6 }}>Wallet balance: {money(bal)}</Muted> : null}
        </Card>
        <Card>
          <H2>How would you like to pay?</H2>
          {methods.map((m) => (
            <Pressable key={m} testID={`pm-${m}`} accessibilityRole="radio" accessibilityState={{ checked: method === m }} onPress={() => setMethod(m)} style={[st.pm, method === m && st.pmOn]}>
              <Ionicons name={METHODS[m][1]} size={22} color={method === m ? C.primary : C.navy} />
              <View style={{ flex: 1 }}><Text style={{ fontWeight: '700', color: C.ink }}>{METHODS[m][0]}</Text><Muted>{METHODS[m][2]}</Muted></View>
              <Ionicons name={method === m ? 'radio-button-on' : 'radio-button-off'} size={20} color={method === m ? C.primary : '#94a3b8'} />
            </Pressable>
          ))}
          {!user ? (
            <View style={{ flexDirection: 'row', gap: 6, alignItems: 'center', marginTop: 4 }}>
              <Ionicons name="wallet-outline" size={16} color={C.muted} />
              <Muted>Have an account? Sign in before booking to use your wallet balance.</Muted>
            </View>
          ) : null}
        </Card>
        <Text style={{ color: C.text, fontSize: 13, marginBottom: 12 }} testID="consent">
          By paying, you agree to the fare rules and our{' '}
          <Text style={{ color: C.primary }} onPress={() => WebBrowser.openBrowserAsync(`${WEBSITE_URL}/terms-and-conditions`)}>terms</Text>,{' '}
          <Text style={{ color: C.primary }} onPress={() => WebBrowser.openBrowserAsync(`${WEBSITE_URL}/cancellation-policy`)}>cancellation policy</Text> and{' '}
          <Text style={{ color: C.primary }} onPress={() => WebBrowser.openBrowserAsync(`${WEBSITE_URL}/privacy-policy`)}>privacy policy</Text>. Traveller names must match their ID.
        </Text>
        <Button title={`Pay ${money(total, b.currency)}`} icon="lock-closed" busy={busy} onPress={pay} testID="pay-btn" />
        {charging() !== fx.base ? <Muted style={{ marginTop: 8 }}>You are charged {money(total, b.currency)} ({inr(total)}) in {charging()} at today's rate.</Muted> : null}
        <Muted style={{ marginTop: 8 }}>Payments are processed securely by our payment partner. Your booking is held while you pay.</Muted>
        <Trust />
      </ScrollView>
      <RazorpaySheet opts={rzp} onDone={(r) => rzpDone.current(r)} />
    </View>
  );
}

const st = StyleSheet.create({
  pm: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 12, borderWidth: 1, borderColor: C.line, borderRadius: 10, marginBottom: 8, backgroundColor: '#fff' },
  pmOn: { borderColor: C.primary, backgroundColor: '#fff5f7' },
});
