// One option in full: journey, inclusions, cancellation, bus seats, fare estimate.
import React, { useEffect, useState } from 'react';
import { Image, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { api } from '../src/api';
import { SERVICES, defaults } from '../src/services';
import { flow, useApp } from '../src/store';
import { fmtDate, money } from '../src/money';
import { Button, Card, Choice, Empty, H1, H2, Muted, Row, Steps, Trust } from '../src/components/ui';
import { C } from '../src/theme';

export default function Details() {
  const { svc: key, id } = useLocalSearchParams<{ svc: string; id: string }>();
  const svc = SERVICES[key || 'flight'];
  const { toast } = useApp();
  const q = flow.query[svc.key] || defaults(svc);
  const [o, setO] = useState<any>(() => (flow.offers[svc.key] || []).find((x) => svc.id(x) === String(id)));
  const [loading, setLoading] = useState(!o && svc.key === 'holiday');
  const [seatmap, setSeatmap] = useState<any[] | null>(null);
  const [seatErr, setSeatErr] = useState('');
  const [seats, setSeats] = useState<string[]>([]);
  const [boarding, setBoarding] = useState('');
  const [dropping, setDropping] = useState('');
  const pax = svc.pax(q);

  useEffect(() => {
    if (!o && svc.key === 'holiday' && id) {
      api('GET', '/packages/' + encodeURIComponent(id)).then((r) => { setO(r.data); flow.offers.holiday = [r.data]; }).catch(() => {}).finally(() => setLoading(false));
    }
  }, []);
  useEffect(() => {
    if (!o || !svc.seats || !svc.seatmapBody) return;
    setBoarding((o.boarding_points || [{}])[0].point || '');
    setDropping((o.dropping_points || [{}])[0].point || '');
    api('POST', '/bus/seatmap', svc.seatmapBody(o, q)).then((r) => setSeatmap(r.data.rows || [])).catch((e) => setSeatErr(e.message));
  }, [o]);

  if (loading) return <Empty icon="hourglass" title="Loading…" />;
  if (!o) return <Empty icon="alert-circle" title="This option is no longer available" text="Fares change often. Please search again."><Button title="Search again" onPress={() => router.dismissTo('/')} /></Empty>;

  const unit = svc.price(o);
  const total = svc.perTraveller ? unit * pax : unit;
  const img = svc.image ? svc.image(o) : null;
  const toggleSeat = (n: string) => setSeats((cur) => cur.includes(n) ? cur.filter((x) => x !== n) : [...(cur.length >= pax ? cur.slice(1) : cur), n]);

  const cont = () => {
    if (svc.seats && seats.length !== pax) { toast(`Please choose ${pax} seat${pax > 1 ? 's' : ''}.`); return; }
    flow.pick = { svc: svc.key, offer: o, query: q, extra: { seats, boarding, dropping }, estimate: total };
    router.push({ pathname: '/travellers', params: { svc: svc.key } });
  };

  return (
    <View style={{ flex: 1 }}>
      <Stack.Screen options={{ title: svc.label }} />
      <Steps at={0} />
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 120 }}>
        {img ? <Image source={{ uri: img }} style={{ width: '100%', height: 180, borderRadius: 12, marginBottom: 12 }} /> : null}
        <H1>{svc.title(o)}</H1>
        {svc.subtitle(o) ? <Muted style={{ marginBottom: 12 }}>{svc.subtitle(o)}</Muted> : null}
        <Card>
          <H2>Overview</H2>
          {svc.facts(o).map((f) => <View key={f} style={st.li}><Ionicons name="checkmark-circle" size={16} color={C.ok} /><Text style={st.liT}>{f}</Text></View>)}
          {(o.segments || []).length && svc.key !== 'hotel' ? (
            <View style={{ marginTop: 10 }}>
              <Text style={st.sub}>Journey</Text>
              {(o.segments || []).map((s: any, i: number) => (
                <View key={i} style={{ marginBottom: 6 }}>
                  <Text style={{ color: C.ink, fontWeight: '600' }}>{s.origin} → {s.destination}</Text>
                  <Muted>{s.departure_at ? `${fmtDate(s.departure_at.slice(0, 10))} ${s.departure_at.slice(11, 16)}` : ''}{s.arrival_at ? ` – ${s.arrival_at.slice(11, 16)}` : ''}{s.flight_number ? ` · ${s.flight_number}` : ''}</Muted>
                </View>
              ))}
            </View>
          ) : null}
        </Card>
        {svc.key === 'holiday' ? (
          <Card>
            <H2>Inclusions</H2>
            {(o.inclusions || []).map((x: string) => <View key={x} style={st.li}><Ionicons name="checkmark-circle" size={16} color={C.ok} /><Text style={st.liT}>{x}</Text></View>)}
            {(o.exclusions || []).length ? <Text style={st.sub}>Not included</Text> : null}
            {(o.exclusions || []).map((x: string) => <View key={x} style={st.li}><Ionicons name="close-circle" size={16} color={C.muted} /><Text style={st.liT}>{x}</Text></View>)}
            {(o.itinerary || []).length ? <Text style={st.sub}>Itinerary</Text> : null}
            {(o.itinerary || []).map((d: any, i: number) => <View key={i} style={{ marginBottom: 8 }}><Text style={{ fontWeight: '700', color: C.ink }}>{d.title || 'Day ' + d.day}</Text>{d.description ? <Muted>{d.description}</Muted> : null}</View>)}
          </Card>
        ) : null}
        {svc.key === 'visa' ? (
          <Card>
            <H2>Documents required</H2>
            {(o.documents_required || []).map((x: string) => <View key={x} style={st.li}><Ionicons name="document" size={16} color={C.primary} /><Text style={st.liT}>{x}</Text></View>)}
            {(o.included_services || []).length ? <Text style={st.sub}>Included</Text> : null}
            {(o.included_services || []).map((x: string) => <View key={x} style={st.li}><Ionicons name="checkmark-circle" size={16} color={C.ok} /><Text style={st.liT}>{x}</Text></View>)}
          </Card>
        ) : null}
        <Card>
          <H2>Cancellation</H2>
          {o.cancellation_policy?.bands ? o.cancellation_policy.bands.map((b: any) => <Row key={b.label} label={b.label} value={`${Number(b.refund_percentage)}% refund`} />)
            : <Muted>{svc.refundable(o) ? 'This booking is refundable. Cancellation charges may apply.' : 'This booking is non-refundable.'}</Muted>}
        </Card>
        {svc.seats ? (
          <Card testID="seatmap">
            <H2>Choose {pax} seat{pax > 1 ? 's' : ''}</H2>
            {seatErr ? <Text style={{ color: C.err }}>Seat map unavailable: {seatErr}</Text> : !seatmap ? <Muted>Loading seat map…</Muted> : (
              <View>
                {seatmap.map((row: any, i: number) => (
                  <View key={i} style={{ flexDirection: 'row', gap: 6, marginBottom: 6, justifyContent: 'center' }}>
                    {row.seats.map((s: any) => {
                      const free = s.state === 'available' || s.state === 'female_only';
                      const on = seats.includes(s.seat_number);
                      return (
                        <Pressable key={s.seat_number} testID={free ? 'seat-free' : undefined} disabled={!free} onPress={() => toggleSeat(s.seat_number)}
                          accessibilityLabel={`Seat ${s.seat_number}${free ? '' : ', booked'}${s.state === 'female_only' ? ', ladies only' : ''}`}
                          style={[st.seat, !free && st.seatTaken, s.state === 'female_only' && st.seatFem, on && st.seatOn]}>
                          <Text style={{ fontSize: 11, color: on ? '#fff' : C.ink }}>{s.seat_number}</Text>
                        </Pressable>
                      );
                    })}
                  </View>
                ))}
                <Muted>Grey = booked · Pink = ladies only</Muted>
                <Text style={{ marginTop: 6, color: C.ink }} testID="seat-note">{seats.length ? 'Seats: ' + seats.join(', ') : 'No seats chosen yet.'}</Text>
                {(o.boarding_points || []).length ? <Choice label="Boarding point" value={boarding} options={o.boarding_points.map((p: any) => [p.point, p.point])} onChange={setBoarding} /> : null}
                {(o.dropping_points || []).length ? <Choice label="Dropping point" value={dropping} options={o.dropping_points.map((p: any) => [p.point, p.point])} onChange={setDropping} /> : null}
              </View>
            )}
          </Card>
        ) : null}
        <Card>
          <H2>Fare summary</H2>
          <Row label={svc.title(o)} value={money(unit, o.currency) + (svc.perTraveller && pax > 1 ? ` × ${pax}` : '')} />
          {svc.key === 'hotel' ? <Row label="Rooms × nights" value={`${q.rooms} × ${o.nights}`} /> : null}
          <Row label="Estimated total" value={money(total, o.currency)} bold testID="estimate" />
          <Muted style={{ marginTop: 6 }}>Taxes included. The final price is confirmed on the next step.</Muted>
          <Trust />
        </Card>
      </ScrollView>
      <View style={st.foot}>
        <View><Muted>Total</Muted><Text style={{ fontSize: 20, fontWeight: '800', color: C.ink }}>{money(total, o.currency)}</Text></View>
        <Button title="Continue" icon="arrow-forward" onPress={cont} testID="continue-btn" style={{ flex: 1, marginLeft: 16 }} />
      </View>
    </View>
  );
}

const st = StyleSheet.create({
  li: { flexDirection: 'row', gap: 8, alignItems: 'flex-start', marginBottom: 6 },
  liT: { color: C.text, flex: 1, fontSize: 14 },
  sub: { fontWeight: '700', color: C.ink, marginTop: 10, marginBottom: 6 },
  seat: { width: 40, height: 36, borderRadius: 6, borderWidth: 1, borderColor: '#94a3b8', alignItems: 'center', justifyContent: 'center', backgroundColor: '#fff' },
  seatTaken: { backgroundColor: '#e2e8f0', borderColor: '#e2e8f0' },
  seatFem: { borderColor: '#ec4899', backgroundColor: '#fdf2f8' },
  seatOn: { backgroundColor: C.primary, borderColor: C.primary },
  foot: { position: 'absolute', left: 0, right: 0, bottom: 0, flexDirection: 'row', alignItems: 'center', padding: 12, paddingBottom: 24, backgroundColor: '#fff', borderTopWidth: 1, borderTopColor: C.line },
});
