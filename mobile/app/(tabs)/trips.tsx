// My Trips: every booking of the signed-in customer, newest first.
import React, { useCallback, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, TextInput, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { api } from '../../src/api';
import { MODULE_TO_SVC, SERVICES } from '../../src/services';
import { flow, useApp } from '../../src/store';
import { fmtDate, money } from '../../src/money';
import { Button, Empty, Muted, Tag } from '../../src/components/ui';
import { statusText, statusTone } from '../../src/status';
import { C } from '../../src/theme';


export default function Trips() {
  const { user, partner, ready } = useApp();
  const [q, setQ] = useState('');
  const [rows, setRows] = useState<any[] | null>(null);
  const [err, setErr] = useState('');
  const [refreshing, setRefreshing] = useState(false);
  const [tab, setTab] = useState<'upcoming' | 'past'>('upcoming');

  const load = useCallback(async () => {
    if (!user) return;
    setErr('');
    try { const r = await api('GET', '/my-bookings', { per_page: 100, search: q.trim() || undefined }); setRows(r.data || []); } catch (e: any) { setErr(e.message); }
  }, [user, q]);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  if (!ready) return null;
  if (!user) return <Empty icon="briefcase-outline" title="Your trips live here" text="Sign in to see your bookings, download tickets and invoices, pay for held bookings or cancel."><Button title="Sign in" onPress={() => router.push('/login')} testID="trips-login" /></Empty>;
  if (err && !rows) return <Empty icon="cloud-offline" title="Couldn't load your trips" text={err}><Button title="Try again" onPress={load} /></Empty>;
  if (!rows) return <Empty icon="hourglass" title="Loading your trips…" />;

  const today = new Date().toISOString().slice(0, 10);
  const past = (r: any) => ['cancelled', 'refunded', 'failed'].includes(r.status) || (r.travel_date_start && r.travel_date_start < today);
  const list = rows.filter((r) => (tab === 'past' ? past(r) : !past(r)));

  return (
    <View style={{ flex: 1 }}>
      <View style={st.tabs}>
        {(['upcoming', 'past'] as const).map((t) => (
          <Pressable key={t} onPress={() => setTab(t)} style={[st.tab, tab === t && st.tabOn]} testID={`trips-${t}`}><Text style={[{ fontWeight: '600', color: C.muted }, tab === t && { color: C.primary }]}>{t === 'upcoming' ? 'Upcoming' : 'Past & cancelled'}</Text></Pressable>
        ))}
      </View>
      {partner ? (
        <View style={{ padding: 12, paddingBottom: 0 }}>
          <TextInput value={q} onChangeText={setQ} onSubmitEditing={load} returnKeyType="search" placeholder="Search booking ID, customer email or phone" placeholderTextColor="#94a3b8" style={st.search} testID="trips-search" />
        </View>
      ) : null}
      <FlatList
        data={list}
        keyExtractor={(r) => String(r.id)}
        contentContainerStyle={{ padding: 12, paddingBottom: 32 }}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false); }} />}
        ListEmptyComponent={<Empty icon="airplane-outline" title={tab === 'upcoming' ? 'No upcoming trips' : 'Nothing here yet'} text={tab === 'upcoming' ? 'Your next adventure starts with a search.' : undefined}>{tab === 'upcoming' ? <Button title="Book a trip" onPress={() => router.navigate('/')} /> : null}</Empty>}
        renderItem={({ item: r }) => {
          const svc = SERVICES[MODULE_TO_SVC[r.module] || 'flight'];
          return (
            <Pressable testID="trip-row" onPress={() => { flow.trips[r.id] = r; router.push({ pathname: '/trip', params: { id: String(r.id) } }); }} style={st.row}>
              <View style={st.ic}><Ionicons name={(svc?.icon || 'briefcase') as any} size={20} color={C.primary} /></View>
              <View style={{ flex: 1 }}>
                <Text style={{ fontWeight: '700', color: C.ink }} numberOfLines={1}>{r.summary}</Text>
                <Muted>{r.booking_code || r.booking_reference} · {fmtDate(r.travel_date_start) || fmtDate(r.booking_date)}</Muted>
                <View style={{ flexDirection: 'row', gap: 8, marginTop: 6, alignItems: 'center' }}>
                  <Tag text={statusText(r.status)} tone={statusTone(r.status)} />
                  {r.pay_path ? <Text style={{ color: C.primary, fontWeight: '700', fontSize: 12 }}>Pay now</Text> : null}
                </View>
              </View>
              <Text style={{ fontWeight: '800', color: C.ink }}>{money(r.total_amount, r.currency)}</Text>
            </Pressable>
          );
        }}
      />
    </View>
  );
}

const st = StyleSheet.create({
  tabs: { flexDirection: 'row', backgroundColor: '#fff', borderBottomWidth: 1, borderBottomColor: C.line },
  tab: { flex: 1, alignItems: 'center', paddingVertical: 12, borderBottomWidth: 2, borderBottomColor: 'transparent' },
  tabOn: { borderBottomColor: C.primary },
  row: { flexDirection: 'row', gap: 12, alignItems: 'center', backgroundColor: '#fff', borderRadius: 12, padding: 12, marginBottom: 10, borderWidth: 1, borderColor: C.line },
  search: { minHeight: 44, borderWidth: 1, borderColor: C.line, borderRadius: 10, paddingHorizontal: 12, backgroundColor: '#fff', fontSize: 15 },
  ic: { width: 40, height: 40, borderRadius: 10, backgroundColor: '#fff0f3', alignItems: 'center', justifyContent: 'center' },
});
