// One trip from My Trips: details, pay for a hold, documents, cancel.
import React, { useState } from 'react';
import { ScrollView, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { api } from '../src/api';
import { MODULE_TO_SVC, SERVICES } from '../src/services';
import { flow, useApp } from '../src/store';
import { fmtDate, money } from '../src/money';
import { openDocument } from '../src/docs';
import { statusText, statusTone } from '../src/status';
import { Button, Card, Empty, H1, H2, Input, Muted, Row, Tag } from '../src/components/ui';
import { C } from '../src/theme';

export default function Trip() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { toast } = useApp();
  const [r, setR] = useState<any>(flow.trips[String(id)]);
  const [quote, setQuote] = useState<any>(null);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState('');
  if (!r) return <Empty title="Trip not found" />;
  const svcKey = MODULE_TO_SVC[r.module] || 'flight';
  const svc = SERVICES[svcKey];
  const ref = r.booking_code || r.booking_reference;
  const ts = r.travel_summary || {};

  const doc = async (path: string, name: string, k: string) => {
    setBusy(k);
    try { await openDocument(path, name); } catch (e: any) { toast('Download failed: ' + e.message); }
    setBusy('');
  };
  const pay = () => {
    flow.booking = { svc: svcKey, booking: r };
    router.push({ pathname: '/payment', params: { svc: svcKey, id: String(r.id) } });
  };
  const askCancel = async () => {
    setBusy('quote');
    try { setQuote((await api('GET', `/my-bookings/${r.id}/cancellation-quote`)).data); } catch (e: any) { toast(e.message); }
    setBusy('');
  };
  const cancel = async () => {
    setBusy('cancel');
    try {
      const res = await api('POST', `/my-bookings/${r.id}/cancel`, { reason: reason || undefined });
      toast(res.message || 'Your booking has been cancelled.');
      const next = { ...r, status: res.data?.status || 'cancelled', can_cancel: false, pay_path: null };
      flow.trips[r.id] = next; setR(next); setQuote(null);
    } catch (e: any) { toast(e.message); }
    setBusy('');
  };

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <Muted>{svc?.label}</Muted>
      <H1>{r.summary}</H1>
      <Tag text={statusText(r.status)} tone={statusTone(r.status)} />
      <Card style={{ marginTop: 12 }}>
        <Row label="Booking ID" value={String(ref)} testID="trip-ref" />
        {r.travel_date_start ? <Row label="Travel date" value={fmtDate(r.travel_date_start) + (r.travel_date_end && r.travel_date_end !== r.travel_date_start ? ' – ' + fmtDate(r.travel_date_end) : '')} /> : null}
        {ts.nights ? <Row label="Nights" value={String(ts.nights)} /> : null}
        <Row label="Booked on" value={`${fmtDate(r.booking_date)} ${r.booking_time || ''}`} />
        <Row label={r.status === 'held' ? 'Amount to pay' : 'Amount paid'} value={money(r.total_amount, r.currency)} bold />
      </Card>
      <View style={{ gap: 10 }}>
        {r.pay_path ? <Button title={`Pay ${money(r.total_amount, r.currency)} now`} icon="lock-closed" onPress={pay} testID="trip-pay" /> : null}
        {r.pdf_url ? <Button kind="ghost" title={`Download ${r.module === 'holiday' ? 'itinerary' : 'ticket / voucher'}`} icon="download-outline" busy={busy === 'pdf'} onPress={() => doc(r.pdf_url, `${ref}.pdf`, 'pdf')} testID="trip-pdf" /> : null}
        {r.invoice_url ? <Button kind="ghost" title="Download invoice" icon="receipt-outline" busy={busy === 'inv'} onPress={() => doc(r.invoice_url, `${ref}-invoice.pdf`, 'inv')} testID="trip-invoice" /> : null}
        {r.can_cancel && !quote ? <Button kind="ghost" title={r.status === 'held' ? 'Cancel this hold' : 'Cancel booking'} icon="close-circle-outline" busy={busy === 'quote'} onPress={askCancel} testID="trip-cancel" /> : null}
      </View>
      {quote ? (
        <Card style={{ marginTop: 12, borderColor: C.err }} testID="cancel-quote">
          <H2>Cancel this booking?</H2>
          {quote.paid ? (
            <>
              <Row label="Cancellation rule" value={String(quote.band_label || '')} />
              <Row label="Cancellation charge" value={money(quote.fee_amount, quote.currency)} />
              {Number(quote.cashback_deducted) ? <Row label="Cashback taken back" value={money(quote.cashback_deducted, quote.currency)} /> : null}
              <Row label="Refund to your wallet" value={money(quote.refund_amount, quote.currency)} bold testID="refund-amt" />
            </>
          ) : <Muted>Nothing was charged for this hold, so nothing is refunded.</Muted>}
          {quote.cancellable === false ? <Text style={{ color: C.err, marginTop: 8 }}>{quote.reason || 'This booking can no longer be cancelled here. Please contact support.'}</Text> : (
            <>
              <Input label="Reason (optional)" value={reason} onChangeText={setReason} />
              <Button kind="danger" title="Yes, cancel booking" busy={busy === 'cancel'} onPress={cancel} testID="cancel-confirm" />
            </>
          )}
          <Button kind="link" title="Keep my booking" onPress={() => setQuote(null)} style={{ marginTop: 6 }} />
        </Card>
      ) : null}
    </ScrollView>
  );
}
