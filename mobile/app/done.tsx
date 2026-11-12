// Confirmation: reference, PNR / ticket numbers, documents and what happens next.
import React, { useEffect, useState } from 'react';
import { ScrollView, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { api } from '../src/api';
import { DOCS, SERVICES } from '../src/services';
import { flow, useApp } from '../src/store';
import { fmtDate, money } from '../src/money';
import { openDocument } from '../src/docs';
import { statusText } from '../src/status';
import { Button, Card, Empty, H1, Muted, Row, Steps } from '../src/components/ui';
import { C } from '../src/theme';

const mask = (e?: string) => { const [a, d] = String(e || '').split('@'); return d ? a.slice(0, 2) + '•••@' + d : ''; };

export default function Done() {
  const { svc: key, id } = useLocalSearchParams<{ svc: string; id: string }>();
  const svc = SERVICES[key || 'flight'];
  const { brand, user, hasSession, toast } = useApp();
  const [b, setB] = useState<any>(flow.booking?.booking && String(flow.booking.booking.id) === String(id) ? flow.booking.booking : null);
  const [dl, setDl] = useState('');

  useEffect(() => {
    if (svc.base && hasSession) api('GET', `${svc.base}/${id}`).then((r) => setB(r.data)).catch(() => {});
  }, []);

  if (!b) return <Empty title="Booking not found" />;
  const ok = ['ticketed', 'confirmed'].includes(b.status);
  const ref = b.booking_code || b.booking_reference || b.id;
  const doc = async (path: string, name: string) => {
    setDl(path);
    try { await openDocument(path, name); } catch (e: any) { toast('Download failed: ' + e.message); }
    setDl('');
  };
  const docName = DOCS[svc.key];

  return (
    <View style={{ flex: 1 }}>
      <Steps at={ok ? 3 : 2} />
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
        <Card style={{ alignItems: 'center', paddingVertical: 24 }}>
          <View style={{ width: 64, height: 64, borderRadius: 32, backgroundColor: ok ? C.okBg : C.warnBg, alignItems: 'center', justifyContent: 'center', marginBottom: 10 }}>
            <Ionicons name={ok ? 'checkmark' : 'time-outline'} size={36} color={ok ? C.ok : C.warn} />
          </View>
          <H1 style={{ textAlign: 'center' }}>{ok ? 'Booking confirmed' : statusText(String(b.status))}</H1>
          <Muted style={{ textAlign: 'center' }}>{b.contact_email ? `We've sent the details to ${mask(b.contact_email)}. ` : ''}Thank you for booking with {brand.name}.</Muted>
        </Card>
        <Card>
          <Row label="Booking ID" value={String(ref)} testID="done-ref" />
          <Row label="Status" value={statusText(String(b.status))} testID="done-status" />
          {b.supplier_booking_ref ? <Row label={svc.key === 'flight' ? 'Airline PNR' : 'Confirmation no.'} value={b.supplier_booking_ref} /> : null}
          {(b.ticket_numbers || []).length ? <Row label="Ticket / voucher no." value={b.ticket_numbers.join(', ')} /> : null}
          <Row label="Amount paid" value={money(b.total_amount, b.currency)} testID="done-paid" />
          {b.travel_date_start ? <Row label="Travel date" value={fmtDate(b.travel_date_start)} /> : null}
        </Card>
        {ok ? (
          <View style={{ gap: 10, marginBottom: 12 }}>
            {docName && svc.base ? <Button title={`Download ${docName.replace('.pdf', '')}`} icon="download-outline" busy={dl === 'doc'} onPress={() => doc(`${svc.base}/${b.id}/${docName}`, `${ref}-${docName}`)} testID="dl-doc" /> : null}
            {svc.key === 'flight' && b.can_hide_fare ? <Button kind="ghost" title="Ticket without fare" onPress={() => doc(`${svc.base}/${b.id}/ticket.pdf?fare=0`, `${ref}-ticket-no-fare.pdf`)} /> : null}
            <Button kind="ghost" title="Download invoice" icon="receipt-outline" onPress={() => doc(`/bookings/${b.id}/invoice.pdf`, `${ref}-invoice.pdf`)} testID="dl-invoice" />
          </View>
        ) : null}
        {ok ? (
          <Card>
            <Text style={{ fontWeight: '700', color: C.ink, marginBottom: 6 }}>What happens next</Text>
            {[
              svc.key === 'flight' ? 'Web check-in opens 48 hours before departure on the airline website.' : `Keep your ${docName ? docName.replace('.pdf', '') : 'booking ID'} handy when you travel.`,
              'Carry a government photo ID that matches the traveller name.',
              user ? 'You can see, download or cancel this booking any time in My Trips.' : 'Save your Booking ID. Sign in with the same email to see future bookings in My Trips.',
            ].map((t) => <View key={t} style={{ flexDirection: 'row', gap: 8, marginBottom: 6 }}><Ionicons name="checkmark-circle" size={16} color={C.ok} /><Text style={{ flex: 1, color: C.text }}>{t}</Text></View>)}
          </Card>
        ) : null}
        <Button kind="ghost" title={user ? 'Go to My Trips' : 'Book another trip'} onPress={() => { flow.pick = null; router.dismissTo(user ? '/trips' : '/'); }} testID="done-home" />
      </ScrollView>
    </View>
  );
}
