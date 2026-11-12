// Web build (tests only): load checkout.js in the page itself.
import { useEffect } from 'react';
import type { RzpOptions } from './RazorpaySheet';
export type { RzpOptions };

export function RazorpaySheet({ opts, onDone }: { opts: RzpOptions | null; onDone: (r: { ok: boolean; data?: any; error?: string }) => void }) {
  useEffect(() => {
    if (!opts) return;
    const w = window as any;
    const open = () => {
      const rz = new w.Razorpay({ key: opts.key_id, order_id: opts.order_id, amount: opts.amount, currency: opts.currency, name: opts.name, description: opts.description, prefill: opts.prefill,
        handler: (r: any) => onDone({ ok: true, data: r }), modal: { ondismiss: () => onDone({ ok: false, error: 'Payment was cancelled. Your booking is still on hold, so you can pay again.' }) } });
      rz.open();
    };
    if (w.Razorpay) open();
    else { const s = document.createElement('script'); s.src = 'https://checkout.razorpay.com/v1/checkout.js'; s.onload = open; s.onerror = () => onDone({ ok: false, error: 'Could not load the payment page.' }); document.head.appendChild(s); }
  }, [opts]);
  return null;
}
