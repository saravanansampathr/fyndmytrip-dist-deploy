// Price lines a direct customer sees: one Base Fare (our markup and our GST
// are already inside it) plus the airline's own taxes from the supplier, then
// add-ons and discount. Same rule as tickets and invoices
// (backend app/Support/CustomerFare.php).
const TAX_GROUPS: Record<string, string> = { K3: 'Airline GST (K3)', YQ: 'Fuel surcharge (YQ/YR)', YR: 'Fuel surcharge (YQ/YR)' };
const OTHER = 'Airport & other taxes';
const ORDER = [TAX_GROUPS.K3, TAX_GROUPS.YQ, OTHER];

export function fareRows(b: any): { label: string; amount: number }[] {
  if (b.gross_amount == null) return [];
  const gross = Number(b.gross_amount) || 0;
  const taxes: Record<string, number> = {};
  for (const [code, amt] of Object.entries((b.metadata && b.metadata.airline_taxes) || {})) {
    const label = TAX_GROUPS[String(code).toUpperCase()] || OTHER;
    taxes[label] = Math.round(((taxes[label] || 0) + Number(amt)) * 100) / 100;
  }
  const list = Object.entries(taxes).filter(([, v]) => v > 0).sort((a, c) => ORDER.indexOf(a[0]) - ORDER.indexOf(c[0]));
  const sum = list.reduce((t, [, v]) => t + v, 0);
  const rows = sum > 0 && sum < gross ? [{ label: 'Base Fare', amount: gross - sum }, ...list.map(([label, amount]) => ({ label, amount }))] : [{ label: 'Base Fare', amount: gross }];
  if (Number(b.extras_amount)) rows.push({ label: 'Add-ons', amount: Number(b.extras_amount) });
  if (Number(b.discount_amount)) rows.push({ label: `Discount${b.coupon_code ? ' (' + b.coupon_code + ')' : ''}`, amount: -Number(b.discount_amount) });
  return rows;
}
