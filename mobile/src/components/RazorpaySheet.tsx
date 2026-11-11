// Razorpay Checkout inside the app: Razorpay's standard checkout.js runs in a
// WebView and posts the result back. Only used when the admin has saved
// Razorpay keys (otherwise the API answers mode "simulated").
import React from 'react';
import { Modal, View } from 'react-native';
import { WebView } from 'react-native-webview';

export type RzpOptions = { key_id: string; order_id: string; amount: number; currency: string; name?: string; description?: string; prefill?: any };

export function RazorpaySheet({ opts, onDone }: { opts: RzpOptions | null; onDone: (r: { ok: boolean; data?: any; error?: string }) => void }) {
  if (!opts) return null;
  const cfg = JSON.stringify({ key: opts.key_id, order_id: opts.order_id, amount: opts.amount, currency: opts.currency, name: opts.name, description: opts.description, prefill: opts.prefill || {} });
  const html = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="background:#fff">
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
  var post = function (m) { window.ReactNativeWebView.postMessage(JSON.stringify(m)); };
  var o = ${cfg};
  o.handler = function (r) { post({ ok: true, data: r }); };
  o.modal = { ondismiss: function () { post({ ok: false, error: 'Payment was cancelled. Your booking is still on hold, so you can pay again.' }); } };
  try { var rz = new Razorpay(o); rz.on('payment.failed', function (e) { post({ ok: false, error: (e && e.error && e.error.description) || 'Payment failed.' }); }); rz.open(); }
  catch (e) { post({ ok: false, error: 'Could not open the payment page. Check your connection.' }); }
</script></body></html>`;
  return (
    <Modal visible animationType="slide" onRequestClose={() => onDone({ ok: false, error: 'Payment was cancelled. Your booking is still on hold, so you can pay again.' })}>
      <View style={{ flex: 1, paddingTop: 40, backgroundColor: '#fff' }}>
        <WebView originWhitelist={['*']} source={{ html, baseUrl: 'https://checkout.razorpay.com' }} javaScriptEnabled setSupportMultipleWindows={false}
          onMessage={(e) => { try { onDone(JSON.parse(e.nativeEvent.data)); } catch { onDone({ ok: false, error: 'Payment failed.' }); } }} />
      </View>
    </Modal>
  );
}
