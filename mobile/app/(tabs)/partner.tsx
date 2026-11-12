// Partner home for agents, sub-agents, white-label partners, API partners and
// staff: wallet and credit, sales and commission, bookings to pay, quick book.
// Full admin (markups, users, sub-agents, inventory sharing) stays in the
// partner portal on the website.
import React, { useCallback, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { Ionicons } from '@expo/vector-icons';
import { api } from '../../src/api';
import { flow, useApp } from '../../src/store';
import { fmtDate, money } from '../../src/money';
import { ROLE_LABEL } from '../../src/roles';
import { MODULE_TO_SVC, SERVICES } from '../../src/services';
import { statusText, statusTone } from '../../src/status';
import { WEBSITE_URL } from '../../src/config';
import { Button, Card, Choice, Empty, H2, Muted, Tag } from '../../src/components/ui';
import { C } from '../../src/theme';

const RANGES: [string, string][] = [['today', 'Today'], ['week', 'This week'], ['month', 'This month'], ['quarter', 'Quarter']];

export default function Partner() {
  const { user, partner, brand } = useApp();
  const [range, setRange] = useState('month');
  const [wallet, setWallet] = useState<any>(null);
  const [sum, setSum] = useState<any>(null);
  const [recent, setRecent] = useState<any[] | null>(null);
  const [held, setHeld] = useState(0);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async (rg = range) => {
    if (!partner) return;
    await Promise.all([
      api('GET', '/wallet').then((r) => setWallet(r.data)).catch(() => setWallet(null)),
      api('GET', '/reports/summary', { range: rg }).then((r) => setSum(r.data)).catch(() => setSum(null)),
      api('GET', '/my-bookings', { per_page: 5 }).then((r) => setRecent(r.data || [])).catch(() => setRecent([])),
      api('GET', '/my-bookings', { per_page: 1, status: 'held' }).then((r) => setHeld(r.total || 0)).catch(() => setHeld(0)),
    ]);
  }, [partner, range]);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  if (!partner || !user) return <Empty icon="briefcase-outline" title="Partner area" text="Sign in with your partner account to see your wallet, sales, commission and bookings."><Button title="Partner sign in" onPress={() => router.push({ pathname: '/login', params: { who: 'partner' } })} /></Empty>;
  const t = sum?.totals;

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} refreshControl={<RefreshControl refreshing={refreshing} onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false); }} />}>
      <Card style={{ backgroundColor: C.navy, borderColor: C.navy }}>
        <Text style={{ color: '#cbd5e1', fontSize: 12 }}>{ROLE_LABEL[String(user.role)] || 'Partner'} · {brand.name}</Text>
        <Text style={{ color: '#fff', fontSize: 20, fontWeight: '800', marginTop: 2 }} testID="partner-name">{user.name}</Text>
        <View style={{ flexDirection: 'row', marginTop: 14, gap: 12 }}>
          <View style={{ flex: 1 }}><Text style={st.kLbl}>Wallet</Text><Text style={st.kVal} testID="partner-wallet">{wallet ? money(wallet.balance) : '—'}</Text></View>
          <View style={{ flex: 1 }}><Text style={st.kLbl}>Credit available</Text><Text style={st.kVal}>{wallet ? money(wallet.credit_available) : '—'}</Text></View>
        </View>
        {wallet ? <Text style={{ color: '#cbd5e1', fontSize: 12, marginTop: 8 }}>Available to book: {money(wallet.available_to_spend ?? wallet.balance)}{Number(wallet.credit_limit) ? ` · credit limit ${money(wallet.credit_limit)}` : ''}{wallet.credit_next_due_at ? ` · due ${fmtDate(wallet.credit_next_due_at)}` : ''}</Text> : null}
      </Card>

      {held ? (
        <Pressable onPress={() => router.navigate('/trips')} style={st.alert} testID="partner-held">
          <Ionicons name="time-outline" size={20} color={C.warn} />
          <Text style={{ flex: 1, color: C.warn, fontWeight: '600' }}>{held} booking{held > 1 ? 's' : ''} on hold, waiting for payment</Text>
          <Ionicons name="chevron-forward" size={18} color={C.warn} />
        </Pressable>
      ) : null}

      <Card>
        <Choice label="Sales" value={range} options={RANGES} onChange={(v) => { setRange(v); load(v); }} testID="partner-range" />
        {t ? (
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
            {[['Bookings', String(t.bookings_count), 'partner-count'], ['Sales', money(t.total_amount), 'partner-sales'], ['Commission earned', money(t.commission_amount), 'partner-comm'], ['Markup earned', money(t.markup_amount), 'partner-markup']].map(([l, v, id]) => (
              <View key={l} style={st.kpi}><Muted>{l}</Muted><Text style={st.kpiV} testID={id}>{v}</Text></View>
            ))}
          </View>
        ) : <Muted>Sales figures are not available for this login.</Muted>}
      </Card>

      <H2>Book for a customer</H2>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 16 }}>
        {['flight', 'hotel', 'bus', 'holiday', 'cab', 'visa'].map((k) => (
          <Pressable key={k} style={st.quick} onPress={() => router.navigate({ pathname: '/', params: { s: k } })} testID={`quick-${k}`}>
            <Ionicons name={SERVICES[k].icon as any} size={22} color={C.primary} /><Text style={{ fontSize: 12, color: C.ink, marginTop: 4 }}>{SERVICES[k].label}</Text>
          </Pressable>
        ))}
      </View>

      <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
        <H2>Recent bookings</H2>
        <Button kind="link" title="See all" onPress={() => router.navigate('/trips')} />
      </View>
      {recent === null ? <Muted>Loading…</Muted> : !recent.length ? <Muted>No bookings yet.</Muted> : recent.map((r) => (
        <Pressable key={r.id} testID="partner-recent" onPress={() => { flow.trips[r.id] = r; router.push({ pathname: '/trip', params: { id: String(r.id) } }); }} style={st.row}>
          <Ionicons name={(SERVICES[MODULE_TO_SVC[r.module] || 'flight']?.icon || 'briefcase') as any} size={18} color={C.primary} />
          <View style={{ flex: 1 }}><Text style={{ fontWeight: '600', color: C.ink }} numberOfLines={1}>{r.summary}</Text><Muted>{r.booking_code} · {fmtDate(r.booking_date)}</Muted></View>
          <View style={{ alignItems: 'flex-end', gap: 4 }}><Text style={{ fontWeight: '700' }}>{money(r.total_amount, r.currency)}</Text><Tag text={statusText(r.status)} tone={statusTone(r.status)} /></View>
        </Pressable>
      ))}

      <Card style={{ marginTop: 12 }}>
        <H2>Manage your account</H2>
        <Muted style={{ marginBottom: 10 }}>Markups, sub-agents and staff, wallet top-up, reports, your website and the services shared with you are in the partner portal.</Muted>
        <Button kind="ghost" title="Open partner portal" icon="open-outline" onPress={() => WebBrowser.openBrowserAsync(`${WEBSITE_URL}/login`)} />
      </Card>
      {sum?.range ? <Muted>Figures for {fmtDate(sum.range.from)} – {fmtDate(sum.range.to)}.</Muted> : null}
    </ScrollView>
  );
}

const st = StyleSheet.create({
  kLbl: { color: '#cbd5e1', fontSize: 12 },
  kVal: { color: '#fff', fontSize: 20, fontWeight: '800' },
  alert: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: C.warnBg, borderRadius: 12, padding: 12, marginBottom: 12 },
  kpi: { width: '47%', backgroundColor: '#f8fafc', borderRadius: 10, padding: 10, borderWidth: 1, borderColor: C.line },
  kpiV: { fontSize: 17, fontWeight: '800', color: C.ink, marginTop: 2 },
  quick: { width: 96, height: 72, borderRadius: 12, backgroundColor: '#fff', borderWidth: 1, borderColor: C.line, alignItems: 'center', justifyContent: 'center' },
  row: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: '#fff', borderRadius: 12, padding: 12, marginBottom: 8, borderWidth: 1, borderColor: C.line },
});
