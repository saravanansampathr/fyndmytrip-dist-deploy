// Shared booking-flow pieces used by every template: progress steps, trust
// notes, the consent line, the confirmation page extras, and per-page SEO
// tags. Styling lives at the end of css/storefront.css (".ota-").
import { esc, icon } from './ui.js';

const STEPS = ['Choose', 'Travellers', 'Payment', 'Confirmation'];
export const progress = (at) => `<ol class="ota-steps wrap" aria-label="Booking progress">${STEPS.map((s, i) => `<li class="${i < at ? 'done' : i === at ? 'on' : ''}"${i === at ? ' aria-current="step"' : ''}><span>${i < at ? icon('check', 14) : i + 1}</span>${s}</li>`).join('')}</ol>`;

export const trust = () => `<ul class="ota-trust"><li>${icon('check', 16)} Price includes all taxes and fees</li><li>${icon('lock', 16)} Secure payment by UPI, cards, net banking or wallet</li><li>${icon('shield', 16)} Instant confirmation on email and mobile</li></ul>`;

export const consent = () => `<p class="ota-consent">By continuing, you agree to the fare rules and the booking terms, privacy and cancellation policies of this site.</p>`;

const NEXT = {
  flight: [['E-ticket sent', 'Your e-ticket is in your email. You can also download it here.'], ['Check in online', 'Most airlines open web check-in 48 hours before a domestic flight and 24 hours before an international one.'], ['At the airport', 'Carry a government photo ID (a passport for international travel) and arrive 2 hours early, 3 for international.']],
  hotel: [['Voucher sent', 'Your hotel voucher is in your email.'], ['At check-in', 'Show the voucher with a photo ID for every adult guest.'], ['Timings', 'Most hotels check in from 2 PM and check out by 12 noon.']],
  bus: [['m-Ticket sent', 'Show the ticket on your phone when you board.'], ['Boarding', 'Reach your boarding point 15 minutes before departure.']],
  cab: [['Voucher sent', 'Your booking details are in your email.'], ['Driver details', 'You get the driver and car details on your mobile before pickup.']],
  cruise: [['Voucher sent', 'Your cruise voucher is in your email.'], ['Boarding', 'Carry your ID or passport and reach the port at the reporting time.']],
  activity: [['Voucher sent', 'Show your voucher on your phone at the venue.'], ['Arrive early', 'Reach the meeting point 15 minutes before the start.']],
  insurance: [['Policy sent', 'Your policy document is in your email.'], ['Claims', "Call the insurer's helpline on the policy as soon as something happens."]],
  holiday: [['Booking received', 'Our holidays team sends your vouchers and final itinerary before travel.']],
  visa: [['Application received', 'Our visa team emails your document checklist and keeps you updated until the visa is issued.']],
};

function ics(b, title) {
  const d = String(b.travel_date_start || '').replace(/-/g, '');
  if (d.length !== 8) return '';
  const body = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//FyndMyTrip//Storefront//EN', 'BEGIN:VEVENT', `UID:${b.booking_code || b.id}@storefront`, `DTSTAMP:${new Date().toISOString().replace(/[-:]/g, '').slice(0, 15)}Z`, `DTSTART;VALUE=DATE:${d}`, `SUMMARY:${title}`, `DESCRIPTION:Booking ${b.booking_code || b.booking_reference || b.id}`, 'END:VEVENT', 'END:VCALENDAR'].join('\r\n');
  return URL.createObjectURL(new Blob([body], { type: 'text/calendar' }));
}

/** Confirmation extras: actions row + what happens next + help. */
export function confirmExtras(b, svcKey, svcLabel, brand) {
  const ref = b.booking_code || b.booking_reference || b.id;
  const cal = ics(b, `${svcLabel} booking ${ref}`);
  const share = `My ${svcLabel.toLowerCase()} booking with ${String(brand.name || 'us').replace(/\s*\((master|default)\)\s*$/i, '')} is confirmed. Booking ref ${ref}${b.travel_date_start ? ', ' + b.travel_date_start : ''}.`;
  const steps = NEXT[svcKey] || [];
  const help = [brand.support_phone && `<a href="tel:${esc(String(brand.support_phone).replace(/\s/g, ''))}">${icon('phone', 16)} ${esc(brand.support_phone)}</a>`, brand.support_email && `<a href="mailto:${esc(brand.support_email)}?subject=${encodeURIComponent('Booking ' + ref)}">${icon('globe', 16)} ${esc(brand.support_email)}</a>`].filter(Boolean).join('');
  return `<div class="ota-acts">${cal ? `<a class="ota-act" href="${cal}" download="${esc(ref)}.ics">Add to calendar</a>` : ''}<a class="ota-act" href="https://wa.me/?text=${encodeURIComponent(share)}" target="_blank" rel="noopener">Share on WhatsApp</a><button type="button" class="ota-act" data-print>Print</button><button type="button" class="ota-act" data-copy="${esc(ref)}">Copy booking ref</button></div>
  ${steps.length ? `<div class="ota-next"><h2>What happens next</h2><ol>${steps.map(([t, d]) => `<li><b>${esc(t)}</b><span>${esc(d)}</span></li>`).join('')}</ol></div>` : ''}
  <div class="ota-help"><h2>Need help?</h2><p>Quote booking ref <b>${esc(ref)}</b> when you contact us.</p>${help}</div>`;
}

export function bindConfirm(root) {
  root.querySelector('[data-print]')?.addEventListener('click', () => window.print());
  root.querySelector('[data-copy]')?.addEventListener('click', (e) => { try { navigator.clipboard.writeText(e.currentTarget.dataset.copy); e.currentTarget.textContent = 'Copied'; } catch { /* clipboard blocked */ } });
}

// ---- SEO: title, description and robots per page. Admin-set SEO titles
// (extras.js, from Website content) still win on the pages they cover.
const PRIVATE = ['travellers', 'payment', 'done', 'login'];
function meta(name, content) {
  let m = document.head.querySelector(`meta[name="${name}"]`);
  if (content == null) { m?.remove(); return; }
  if (!m) { m = document.createElement('meta'); m.name = name; document.head.appendChild(m); }
  m.content = content;
}
export function pageSeo(page, svc, brandName) {
  const b = String(brandName || 'Travel').replace(/\s*\((master|default)\)\s*$/i, '');
  const label = svc?.label || 'Travel';
  const titles = {
    home: [`${b}: Book ${[...new Set([label, 'Flights', 'Hotels', 'Holidays'])].join(', ').replace(/, ([^,]*)$/, ' & $1')} Online`, `Search and book ${label.toLowerCase()} with ${b}. Clear prices with taxes included, secure payment and instant confirmation.`],
    results: [`${label} search results | ${b}`, `Compare ${label.toLowerCase()} options and prices, then book online with ${b}.`],
    details: [`${label} details | ${b}`, `See what is included, the cancellation policy and the full price before you book.`],
  };
  const [t, d] = titles[page] || [`${b}`, null];
  document.title = t;
  if (d) meta('description', d);
  meta('robots', PRIVATE.includes(page) ? 'noindex, nofollow' : null);
  if (page === 'home' && !document.getElementById('ota-ld')) {
    const s = document.createElement('script');
    s.type = 'application/ld+json'; s.id = 'ota-ld';
    s.textContent = JSON.stringify({ '@context': 'https://schema.org', '@type': 'TravelAgency', name: b, url: location.origin + location.pathname });
    document.head.appendChild(s);
  }
}
