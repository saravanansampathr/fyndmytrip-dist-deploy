// Search results with sort, refundable-only and a price cap; tap to see details.
import React, { useEffect, useMemo, useState } from 'react';
import { FlatList, Image, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { api } from '../src/api';
import { SERVICES, defaults } from '../src/services';
import { flow } from '../src/store';
import { money, fmtDate } from '../src/money';
import { Button, Empty, Muted, Tag } from '../src/components/ui';
import { C } from '../src/theme';

type Sort = 'price' | 'price-desc' | 'early' | 'name';

export default function Results() {
  const { svc: key } = useLocalSearchParams<{ svc: string }>();
  const svc = SERVICES[key || 'flight'];
  const q = flow.query[svc.key] || defaults(svc);
  const [offers, setOffers] = useState<any[] | null>(null);
  const [err, setErr] = useState('');
  const [sort, setSort] = useState<Sort>('price');
  const [refundable, setRefundable] = useState(false);
  const [nonstop, setNonstop] = useState(false);

  const load = () => {
    setOffers(null); setErr('');
    api(svc.method || 'POST', svc.search, svc.body(q))
      .then((r) => { const list = svc.offers(r); flow.offers[svc.key] = list; setOffers(list); })
      .catch((e) => setErr(e.message));
  };
  useEffect(load, [key]);

  const list = useMemo(() => {
    let l = (offers || []).filter((o) => (!refundable || svc.refundable(o)) && (!nonstop || !o.stops));
    const dep = (o: any) => (svc.times ? svc.times(o).dep : '');
    l = [...l].sort((a, b) => sort === 'price' ? svc.price(a) - svc.price(b) : sort === 'price-desc' ? svc.price(b) - svc.price(a) : sort === 'early' ? dep(a).localeCompare(dep(b)) : String(svc.title(a)).localeCompare(String(svc.title(b))));
    return l;
  }, [offers, sort, refundable, nonstop]);

  const places = svc.fields.filter((f) => f.kind).map((f) => q[f.name]).filter(Boolean).join(' → ');
  const dateField = svc.fields.find((f) => f.type === 'date');
  const sub = [dateField ? fmtDate(q[dateField.name]) : '', `${svc.pax(q)} ${svc.key === 'hotel' ? 'guest' : 'traveller'}${svc.pax(q) > 1 ? 's' : ''}`].filter(Boolean).join(' · ');

  const chip = (on: boolean, label: string, onPress: () => void, id: string) => (
    <Pressable testID={id} onPress={onPress} style={[st.chip, on && st.chipOn]}><Text style={[{ fontSize: 13, color: C.ink }, on && { color: '#fff' }]}>{label}</Text></Pressable>
  );

  return (
    <View style={{ flex: 1 }}>
      <Stack.Screen options={{ title: places || svc.label }} />
      <View style={st.bar}>
        <Muted>{sub}</Muted>
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 8 }}>
          {chip(sort === 'price', 'Cheapest', () => setSort('price'), 'sort-price')}
          {svc.times ? chip(sort === 'early', 'Earliest', () => setSort('early'), 'sort-early') : null}
          {chip(sort === 'price-desc', 'Priciest', () => setSort('price-desc'), 'sort-desc')}
          {chip(refundable, 'Refundable', () => setRefundable(!refundable), 'flt-refund')}
          {svc.key === 'flight' ? chip(nonstop, 'Non-stop', () => setNonstop(!nonstop), 'flt-nonstop') : null}
        </View>
      </View>
      {err ? <Empty icon="cloud-offline" title="We couldn't search right now" text={err}><Button title="Try again" onPress={load} /></Empty>
        : offers === null ? <Empty icon="hourglass" title={`Finding the best ${svc.label.toLowerCase()}…`} />
        : (
          <FlatList
            data={list}
            keyExtractor={(o, i) => svc.id(o) + ':' + i}
            contentContainerStyle={{ padding: 12, paddingBottom: 32 }}
            ListHeaderComponent={<Text testID="result-count" style={{ fontWeight: '700', color: C.ink, marginBottom: 8 }}>{list.length} {svc.label.toLowerCase()} found</Text>}
            ListEmptyComponent={<Empty title={`No ${svc.label.toLowerCase()} match`} text="Try clearing the filters or changing your search." />}
            renderItem={({ item: o }) => {
              const t = svc.times ? svc.times(o) : null;
              const img = svc.image ? svc.image(o) : null;
              return (
                <Pressable testID="result-card" accessibilityRole="button" onPress={() => router.push({ pathname: '/details', params: { svc: svc.key, id: svc.id(o) } })} style={st.card}>
                  <View style={{ flexDirection: 'row', gap: 10 }}>
                    {img ? <Image source={{ uri: img }} style={st.thumb} /> : <View style={[st.thumb, st.thumbIc]}><Ionicons name={svc.icon as any} size={22} color={C.primary} /></View>}
                    <View style={{ flex: 1 }}>
                      <Text style={st.title} numberOfLines={2}>{svc.title(o)}</Text>
                      {svc.subtitle(o) ? <Muted>{svc.subtitle(o)}</Muted> : null}
                    </View>
                    <View style={{ alignItems: 'flex-end' }}>
                      <Text style={st.price}>{money(svc.price(o), o.currency)}</Text>
                      <Muted>{svc.perTraveller ? 'per person' : 'total'}</Muted>
                    </View>
                  </View>
                  {t ? (
                    <View style={st.times}>
                      <View><Text style={st.time}>{t.dep}</Text><Muted>{t.from}</Muted></View>
                      <View style={{ flex: 1, alignItems: 'center' }}><Muted>{t.dur}</Muted><View style={st.track} /></View>
                      <View style={{ alignItems: 'flex-end' }}><Text style={st.time}>{t.arr}</Text><Muted>{t.to}</Muted></View>
                    </View>
                  ) : null}
                  <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 8, alignItems: 'center' }}>
                    {svc.facts(o).slice(0, 3).map((f) => <Text key={f} style={st.fact}>{f}</Text>)}
                    {svc.refundable(o) ? <Tag text="Refundable" /> : null}
                  </View>
                </Pressable>
              );
            }}
          />
        )}
    </View>
  );
}

const st = StyleSheet.create({
  bar: { backgroundColor: '#fff', padding: 12, borderBottomWidth: 1, borderBottomColor: C.line },
  chip: { borderWidth: 1, borderColor: C.line, borderRadius: 16, paddingHorizontal: 10, paddingVertical: 6, backgroundColor: '#fff' },
  chipOn: { backgroundColor: C.navy, borderColor: C.navy },
  card: { backgroundColor: '#fff', borderRadius: 12, padding: 14, marginBottom: 10, borderWidth: 1, borderColor: C.line },
  thumb: { width: 52, height: 52, borderRadius: 10 },
  thumbIc: { backgroundColor: '#fff0f3', alignItems: 'center', justifyContent: 'center' },
  title: { fontSize: 16, fontWeight: '700', color: C.ink },
  price: { fontSize: 18, fontWeight: '800', color: C.ink },
  times: { flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 10 },
  time: { fontSize: 18, fontWeight: '700', color: C.ink },
  track: { height: 2, backgroundColor: C.line, alignSelf: 'stretch', marginTop: 4 },
  fact: { fontSize: 12, color: C.muted, backgroundColor: '#f1f5f9', paddingHorizontal: 8, paddingVertical: 3, borderRadius: 6, overflow: 'hidden' },
});
