// How a booking status is shown to a traveller.
export const statusTone = (s: string) => (['ticketed', 'confirmed'].includes(s) ? 'ok' : ['held', 'pending', 'refund_processing'].includes(s) ? 'warn' : ['cancelled', 'failed', 'refunded'].includes(s) ? 'err' : 'plain') as any;
export const statusText = (s: string) => ({ held: 'Awaiting payment', ticketed: 'Confirmed', confirmed: 'Confirmed', cancelled: 'Cancelled', refunded: 'Refunded', refund_processing: 'Refund in progress', failed: 'Failed' } as Record<string, string>)[s] || s.replace(/_/g, ' ');
