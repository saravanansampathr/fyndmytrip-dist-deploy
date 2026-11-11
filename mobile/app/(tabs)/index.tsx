// Home: pick a service, search, popular searches, holiday packages, offers.
import React, { useEffect, useState } from 'react';
import { Image, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { api } from '../../src/api';
import { ORDER, POPULAR, SERVICES, defaults } from '../../src/services';
import { flow, useApp } from '../../src/store';
import { startSearch } from '../../src/nav';
import { money } from '../../src/money';
import { SearchForm } from '../../src/components/SearchForm';
import { Card, H2, Muted } from '../../src/components/ui';
import { C } from '../../src/theme';

const HEAD: Record<string, string> = {
  flight: 'Fly anywhere, for less', hotel: 'Stays you will love', bus: 'Your seat, your journey', cab: 'Outstation cabs, door to door',
  holiday: 'Holidays, fully planned', cruise: 'Set sail', activity: 'Things to do', insurance: 'Travel protected', visa: 'Visas made simple',
};

export default function Home() {
  const { brand, currency } = useApp();
  const [svcKey, setSvc] = useState('flight');
  const [pkgs, setPkgs] = useState<any[] | null>(null);
  const [offers, setOffers] = useState<any[]>([]);
  const svc = SERVICES[svcKey];

  useEffect(() => {
    api('GET', '/packages', { per_page: 6 }).then((r) => setPkgs(r.data || [])).catch(() => setPkgs([]));
    api('GET', '/coupons/active').then((r) => setOffers((r.data || []).slice(0, 6))).catch(() => setOffers([]));
  }, []);

  const popular = (POPULAR[svcKey] || []).map((p) => {
    const q = { ...defaults(svc) };
    svc.fields.filter((f) => f.kind).forEach((f, i) => { if (p[i]) q[f.name] = p[i]; });
    return { label: p.join(' → '), q };
  });

  return (
    <SafeAreaView edges={['top']} style={{ flex: 1, backgroundColor: C.bg }}>
      <ScrollView contentContainerStyle={{ paddingBottom: 32 }} keyboardShouldPersistTaps="handled">
        <View style={st.top}>
          <Image source={require('../../assets/logo.png')} style={{ width: 150, height: 38 }} resizeMode="contain" accessibilityLabel={brand.name} />
          <Text style={st.cur} accessibilityLabel={`Prices in ${currency}`}>{currency}</Text>
        </View>
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={st.svcRow}>
          {ORDER.map((k) => (
            <Pressable key={k} testID={`svc-${k}`} accessibilityRole="tab" accessibilityState={{ selected: k === svcKey }} onPress={() => setSvc(k)} style={[st.svc, k === svcKey && st.svcOn]}>
              <Ionicons name={SERVICES[k].icon as any} size={22} color={k === svcKey ? '#fff' : C.primary} />
              <Text style={[st.svcText, k === svcKey && { color: '#fff' }]}>{SERVICES[k].label}</Text>
            </Pressable>
          ))}
        </ScrollView>
        <View style={{ paddingHorizontal: 16 }}>
          <Text style={st.head}>{HEAD[svcKey]}</Text>
          <Card>
            <SearchForm key={svcKey} svc={svc} onSearch={(q) => startSearch(svcKey, q)} />
          </Card>
          <H2>Popular searches</H2>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 16 }}>
            {popular.map((p) => (
              <Pressable key={p.label} onPress={() => startSearch(svcKey, p.q)} style={st.pop}><Text style={{ color: C.ink }}>{p.label}</Text></Pressable>
            ))}
          </View>
          <H2>Holiday packages</H2>
          {pkgs === null ? <Muted>Loading packages…</Muted> : !pkgs.length ? <Muted>Packages are unavailable right now.</Muted> : (
            <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ marginHorizontal: -16 }} contentContainerStyle={{ paddingHorizontal: 16, gap: 12 }}>
              {pkgs.map((p) => (
                <Pressable key={p.slug} style={st.pkg} onPress={() => {
                  flow.offers.holiday = pkgs; flow.query.holiday = { ...defaults(SERVICES.holiday) };
                  router.push({ pathname: '/details', params: { svc: 'holiday', id: p.slug } });
                }}>
                  {p.cover_image_url ? <Image source={{ uri: p.cover_image_url }} style={st.pkgImg} /> : <View style={[st.pkgImg, { backgroundColor: '#fde2e8' }]} />}
                  <View style={{ padding: 10 }}>
                    <Text style={{ fontWeight: '700', color: C.ink }} numberOfLines={2}>{p.name}</Text>
                    <Muted>{p.duration_nights}N/{p.duration_days}D · {p.destination_city || ''}</Muted>
                    <Text style={{ fontWeight: '800', color: C.primary, marginTop: 4 }}>{money(p.sell_price, p.currency)}</Text>
                  </View>
                </Pressable>
              ))}
            </ScrollView>
          )}
          <H2 style={{ marginTop: 16 }}>Offers for you</H2>
          {(offers.length ? offers.map((o) => [o.code, o.description || o.name || '']) : [['Book early', 'The best fares go first. Book 3+ weeks ahead.'], ['Pay your way', 'Cards, UPI, net banking or your wallet.']]).map(([a, b]) => (
            <Card key={a} style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
              <Ionicons name="pricetag" size={22} color={C.primary} />
              <View style={{ flex: 1 }}><Text style={{ fontWeight: '800', color: C.ink }}>{a}</Text><Muted>{b}</Muted></View>
            </Card>
          ))}
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const st = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 16, paddingVertical: 10 },
  cur: { fontWeight: '700', color: C.navy, borderWidth: 1, borderColor: C.line, borderRadius: 8, paddingHorizontal: 8, paddingVertical: 4, overflow: 'hidden' },
  svcRow: { paddingHorizontal: 16, gap: 8, paddingBottom: 8 },
  svc: { alignItems: 'center', justifyContent: 'center', width: 82, height: 70, borderRadius: 12, backgroundColor: '#fff', borderWidth: 1, borderColor: C.line, gap: 4 },
  svcOn: { backgroundColor: C.primary, borderColor: C.primary },
  svcText: { fontSize: 12, fontWeight: '600', color: C.ink },
  head: { fontSize: 22, fontWeight: '800', color: C.ink, marginVertical: 10 },
  pop: { backgroundColor: '#fff', borderRadius: 20, borderWidth: 1, borderColor: C.line, paddingHorizontal: 12, paddingVertical: 8 },
  pkg: { width: 200, backgroundColor: '#fff', borderRadius: 12, overflow: 'hidden', borderWidth: 1, borderColor: C.line },
  pkgImg: { width: '100%', height: 110 },
});
