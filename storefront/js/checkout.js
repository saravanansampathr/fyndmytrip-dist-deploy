// Real card/UPI payment before POST .../pay. The API's /payments/checkout
// says which gateway to use: 'simulated' (no keys set, demo), 'razorpay'
// (Razorpay Checkout popup) or 'stripe' (hosted page in a new window, then
// we poll until the webhook marks it paid). Mirrors fmtGatewayCheckout in
// the portal (tools/frontend-margin-patch/admin_settings.src.js).
import { api } from './api.js';

function loadScript(src) {
  return new Promise((ok, bad) => {
    if (document.querySelector(`script[src="${src}"]`)) return ok();
    const s = document.createElement('script');
    s.src = src; s.onload = ok; s.onerror = () => bad(new Error('Could not load the payment page. Check your connection.'));
    document.head.appendChild(s);
  });
}

/** Returns the extra fields to send with POST .../pay once the customer has paid. */
export async function gatewayCheckout(bookingId, method, currency) {
  const c = (await api('POST', '/payments/checkout', { booking_id: Number(bookingId), method, currency })).data;
  if (c.mode === 'razorpay') {
    await loadScript('https://checkout.razorpay.com/v1/checkout.js');
    const res = await new Promise((ok, bad) => {
      const rz = new window.Razorpay({
        key: c.key_id, order_id: c.order_id, amount: c.amount, currency: c.currency, name: c.name, description: c.description, prefill: c.prefill,
        handler: ok, modal: { ondismiss: () => bad(new Error('Payment was cancelled. Your booking is still on hold, so you can pay again.')) },
      });
      rz.open();
    });
    return { razorpay_payment_id: res.razorpay_payment_id, razorpay_order_id: res.razorpay_order_id, razorpay_signature: res.razorpay_signature, fmt_checked: true };
  }
  if (c.mode === 'stripe') {
    const w = window.open(c.checkout_url, 'fmt_pay', 'width=520,height=760');
    if (!w) { location.href = c.checkout_url; throw new Error('Opening the payment page…'); }
    await new Promise((ok, bad) => {
      let n = 0;
      const t = setInterval(async () => {
        n++;
        try {
          const s = (await api('GET', `/payments/checkout/${c.transaction_id}/status`)).data;
          if (s.paid) { clearInterval(t); try { w.close(); } catch { /* ignore */ } ok(); }
          else if (w.closed && n > 2) {
            clearInterval(t);
            const s2 = (await api('GET', `/payments/checkout/${c.transaction_id}/status`)).data;
            s2.paid ? ok() : bad(new Error('Payment was not completed. Your booking is still on hold, so you can pay again.'));
          }
        } catch { /* keep polling */ }
        if (n > 400) { clearInterval(t); bad(new Error('Payment timed out.')); }
      }, 3000);
    });
    return { fmt_checked: true };
  }
  return { fmt_checked: true }; // simulated: no gateway keys set
}
