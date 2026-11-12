// Every bookable service: its search form, how to read the API's offers and
// how to build the hold request. A port of storefront/js/services.js so the
// app books exactly the way the website does.
export type Field = {
  name: string; label: string; type?: 'text' | 'date' | 'number' | 'select'; placeholder?: string;
  value: any; kind?: 'place' | 'airport'; min?: number; max?: number; options?: [string, string][];
};
export type TravellerField = [name: string, label: string, type?: 'select' | 'number' | 'date' | 'text', options?: string[]];
export type Service = {
  key: string; label: string; noun: string; icon: string; method?: 'GET' | 'POST';
  search: string; base?: string | null; guest?: string | boolean | null; payBase?: string; seats?: boolean;
  fields: Field[];
  body: (q: any) => Record<string, any>;
  offers: (r: any) => any[];
  pax: (q: any) => number;
  id: (o: any) => string;
  price: (o: any) => number;
  refundable: (o: any) => boolean;
  image?: (o: any) => string | null;
  title: (o: any) => string;
  subtitle: (o: any) => string;
  times?: (o: any) => { from: string; to: string; dep: string; arr: string; dur: string };
  facts: (o: any) => string[];
  traveller: TravellerField[];
  seatmapBody?: (o: any, q: any) => Record<string, any>;
  bookPath?: (o: any, guest: boolean) => string;
  hold: (o: any, q: any, pax: any[], extra: any) => Record<string, any>;
  perTraveller?: boolean;
};

export const day = (n: number) => new Date(Date.now() + n * 864e5).toISOString().slice(0, 10);
const hm = (iso?: string) => (iso || '').slice(11, 16);
export const dur = (m?: number) => (m ? `${Math.floor(m / 60)}h ${String(m % 60).padStart(2, '0')}m` : '');
const inrN = (n: any) => '₹' + Number(n || 0).toLocaleString('en-IN');

const common = {
  offers: (r: any) => r.data.offers || [],
  price: (o: any) => Number(o.gross_amount),
  id: (o: any) => String(o.external_id),
  refundable: (o: any) => !!o.is_refundable,
};

export const SERVICES: Record<string, Service> = {
  flight: {
    ...common, key: 'flight', label: 'Flights', noun: 'flight', icon: 'airplane', base: '/flights/bookings', guest: '/flights/guest-bookings',
    search: '/flights/search', perTraveller: true,
    fields: [
      { name: 'origin', label: 'From', placeholder: 'City or airport', value: 'DEL', kind: 'airport' },
      { name: 'destination', label: 'To', placeholder: 'City or airport', value: 'BOM', kind: 'airport' },
      { name: 'departure_date', label: 'Departure', type: 'date', value: () => day(10) },
      { name: 'adults', label: 'Travellers', type: 'number', value: 1, min: 1, max: 9 },
      { name: 'cabin_class', label: 'Class', type: 'select', value: 'economy', options: [['economy', 'Economy'], ['premium_economy', 'Premium economy'], ['business', 'Business'], ['first', 'First']] },
    ],
    body: (q) => ({ origin: String(q.origin).toUpperCase(), destination: String(q.destination).toUpperCase(), departure_date: q.departure_date, adults: +q.adults || 1, cabin_class: q.cabin_class }),
    offers: (r) => (r.data.legs && r.data.legs[0] ? r.data.legs[0].offers : []),
    pax: (q) => +q.adults || 1,
    title: (o) => o.carrier_or_provider,
    subtitle: (o) => [o.flight_number, o.aircraft_type].filter(Boolean).join(' · '),
    times: (o) => { const s = o.segments[0], e = o.segments[o.segments.length - 1]; return { from: s.origin, to: e.destination, dep: hm(s.departure_at), arr: hm(e.arrival_at), dur: dur(o.duration_minutes) }; },
    facts: (o) => [o.stops ? `${o.stops} stop${o.stops > 1 ? 's' : ''}${o.layover_city ? ' via ' + o.layover_city : ''}` : 'Non-stop', o.baggage_allowance_kg ? `${o.baggage_allowance_kg} kg bag` : '', o.meal_included ? 'Meal included' : '', o.fare_basis ? `${o.fare_basis} fare` : ''].filter(Boolean),
    traveller: [['title', 'Title', 'select', ['Mr', 'Mrs', 'Ms', 'Miss', 'Master', 'Dr']], ['first_name', 'First name'], ['last_name', 'Last name'], ['gender', 'Gender', 'select', ['male', 'female', 'other']]],
    hold: (o, q, pax) => ({ legs: [{ supplier_code: o.supplier_code, external_id: o.external_id, segments: o.segments }], travel_date: q.departure_date, passengers: pax }),
  },
  hotel: {
    ...common, key: 'hotel', label: 'Hotels', noun: 'stay', icon: 'bed', base: '/hotels/bookings', guest: '/hotels/guest-bookings',
    search: '/hotels/search',
    fields: [
      { name: 'city', label: 'City', placeholder: 'Where are you going?', value: 'Goa', kind: 'place' },
      { name: 'check_in_date', label: 'Check-in', type: 'date', value: () => day(10) },
      { name: 'check_out_date', label: 'Check-out', type: 'date', value: () => day(12) },
      { name: 'rooms', label: 'Rooms', type: 'number', value: 1, min: 1, max: 9 },
      { name: 'guests', label: 'Guests', type: 'number', value: 2, min: 1, max: 20 },
    ],
    body: (q) => ({ city: q.city, check_in_date: q.check_in_date, check_out_date: q.check_out_date, rooms: +q.rooms || 1, guests: +q.guests || 1 }),
    pax: (q) => +q.guests || 1,
    title: (o) => o.carrier_or_provider,
    subtitle: (o) => [o.fare_basis, o.meal_plan].filter(Boolean).join(' · '),
    facts: (o) => [`${o.star_rating || '-'}★ hotel`, o.meal_plan, `${o.nights} night${o.nights > 1 ? 's' : ''}`, `${o.seats_available} rooms left`].filter(Boolean),
    traveller: [['name', 'Full name'], ['age', 'Age', 'number']],
    hold: (o, q, pax) => ({ supplier_code: o.supplier_code, external_id: o.external_id, segment: o.segments[0], check_in_date: q.check_in_date, check_out_date: q.check_out_date, rooms: +q.rooms || 1, guests: pax.map((p) => ({ name: p.name, age: +p.age || 30 })) }),
  },
  bus: {
    ...common, key: 'bus', label: 'Buses', noun: 'bus', icon: 'bus', base: '/bus/bookings', guest: '/bus/guest-bookings',
    search: '/bus/search', seats: true,
    fields: [
      { name: 'origin', label: 'From', value: 'Chennai', kind: 'place' },
      { name: 'destination', label: 'To', value: 'Bengaluru', kind: 'place' },
      { name: 'departure_date', label: 'Travel date', type: 'date', value: () => day(5) },
      { name: 'passengers', label: 'Seats', type: 'number', value: 1, min: 1, max: 6 },
    ],
    body: (q) => ({ origin: q.origin, destination: q.destination, departure_date: q.departure_date, passengers: +q.passengers || 1 }),
    pax: (q) => +q.passengers || 1,
    title: (o) => o.carrier_or_provider,
    subtitle: (o) => o.bus_type || '',
    times: (o) => { const s = o.segments[0]; return { from: s.origin, to: s.destination, dep: hm(s.departure_at), arr: hm(s.arrival_at), dur: dur(o.duration_minutes) }; },
    facts: (o) => [`${o.seats_available} seats left`, o.is_ac ? 'AC' : 'Non-AC', o.boarding_point ? `Boards at ${o.boarding_point}` : ''].filter(Boolean),
    traveller: [['title', 'Title', 'select', ['Mr', 'Mrs', 'Ms']], ['name', 'Full name'], ['age', 'Age', 'number'], ['gender', 'Gender', 'select', ['male', 'female']]],
    seatmapBody: (o, q) => ({ supplier_code: o.supplier_code, external_id: o.external_id, travel_date: q.departure_date, bus_type: o.bus_type, seats_available: o.seats_available }),
    hold: (o, q, pax, extra) => ({
      supplier_code: o.supplier_code, external_id: o.external_id, segment: o.segments[0], travel_date: q.departure_date, bus_type: o.bus_type, seats_available: o.seats_available,
      boarding_point: extra.boarding || (o.boarding_points || [{}])[0].point || o.boarding_point, dropping_point: extra.dropping || (o.dropping_points || [{}])[0].point || o.dropping_point,
      passengers: pax.map((p, i) => ({ ...p, age: +p.age || 30, seat_number: extra.seats[i] })),
    }),
  },
  cab: {
    ...common, key: 'cab', label: 'Cabs', noun: 'cab', icon: 'car', base: '/cabs/bookings', guest: null,
    search: '/cabs/search',
    fields: [
      { name: 'origin', label: 'Pickup', value: 'Chennai', kind: 'place' },
      { name: 'destination', label: 'Drop', value: 'Pondicherry', kind: 'place' },
      { name: 'travel_date', label: 'Pickup date', type: 'date', value: () => day(6) },
      { name: 'passengers', label: 'Passengers', type: 'number', value: 1, min: 1, max: 7 },
    ],
    body: (q) => ({ origin: q.origin, destination: q.destination, travel_date: q.travel_date, passengers: +q.passengers || 1 }),
    pax: () => 1,
    title: (o) => o.car_type,
    subtitle: (o) => o.carrier_or_provider,
    facts: (o) => [`${o.included_km} km included`, `₹${o.extra_km_fare}/extra km`, dur(o.duration_minutes), o.is_refundable ? 'Free cancellation' : ''].filter(Boolean),
    traveller: [['name', 'Lead passenger'], ['age', 'Age', 'number']],
    hold: (o, q, pax) => ({ supplier_code: o.supplier_code, external_id: o.external_id, segment: o.segments[0], travel_date: q.travel_date, passengers: pax.map((p) => ({ name: p.name, age: +p.age || 30 })) }),
  },
  holiday: {
    key: 'holiday', label: 'Holidays', noun: 'package', icon: 'umbrella', method: 'GET', search: '/packages', perTraveller: true,
    fields: [
      { name: 'q', label: 'Destination or package', placeholder: 'Kashmir, Kerala, Bali…', value: '', kind: 'place' },
      { name: 'travel_date', label: 'Travel date', type: 'date', value: () => day(30) },
      { name: 'travellers', label: 'Travellers', type: 'number', value: 2, min: 1, max: 20 },
    ],
    body: (q) => ({ q: q.q, per_page: 24 }),
    offers: (r) => r.data || [],
    pax: (q) => +q.travellers || 1,
    id: (o) => String(o.slug),
    price: (o) => Number(o.sell_price),
    refundable: () => false,
    image: (o) => o.cover_image_url,
    title: (o) => o.name,
    subtitle: (o) => o.route_summary || o.destination_city || '',
    facts: (o) => [`${o.duration_nights}N / ${o.duration_days}D`, o.category, o.hotel_category, o.package_type].filter(Boolean),
    traveller: [['name', 'Full name'], ['occupancy_type', 'Room sharing', 'select', ['adult_sharing', 'adult_single', 'child_with_bed', 'child_without_bed', 'infant']]],
    bookPath: (o, guest) => `/packages/${o.slug}/${guest ? 'guest-bookings' : 'bookings'}`,
    payBase: '/holiday-bookings',
    guest: true,
    hold: (o, q, pax) => ({ travel_date: q.travel_date, travelers: pax.map((p) => ({ name: p.name, occupancy_type: p.occupancy_type || 'adult_sharing' })) }),
  },
  cruise: {
    ...common, key: 'cruise', label: 'Cruises', noun: 'cruise', icon: 'boat', base: '/cruises/bookings', guest: null,
    search: '/cruises/search',
    fields: [
      { name: 'departure_port', label: 'Departure port', value: 'Chennai', kind: 'place' },
      { name: 'arrival_port', label: 'Arrival port', value: 'Colombo', kind: 'place' },
      { name: 'sail_date', label: 'Sail date', type: 'date', value: () => day(20) },
      { name: 'travellers', label: 'Travellers', type: 'number', value: 2, min: 1, max: 9 },
    ],
    body: (q) => ({ departure_port: q.departure_port, arrival_port: q.arrival_port, sail_date: q.sail_date, travellers: +q.travellers || 1 }),
    pax: (q) => +q.travellers || 1,
    title: (o) => o.ship_name || o.carrier_or_provider,
    subtitle: (o) => `${o.carrier_or_provider} · ${o.fare_basis} cabin`,
    facts: (o) => [`${o.nights} nights`, `${o.fare_basis} cabin`, `${o.seats_available} cabins left`, o.is_refundable ? 'Refundable' : 'Non-refundable'],
    traveller: [['name', 'Full name']],
    hold: (o, q, pax) => ({ supplier_code: o.supplier_code, external_id: o.external_id, segment: o.segments[0], sail_date: q.sail_date, passengers: pax.map((p) => ({ name: p.name })) }),
  },
  activity: {
    ...common, key: 'activity', label: 'Activities', noun: 'activity', icon: 'ticket', base: '/activities/bookings', guest: null,
    search: '/activities/search',
    fields: [
      { name: 'city', label: 'City', value: 'Goa', kind: 'place' },
      { name: 'activity_date', label: 'Date', type: 'date', value: () => day(8) },
      { name: 'travellers', label: 'Travellers', type: 'number', value: 2, min: 1, max: 20 },
    ],
    body: (q) => ({ city: q.city, activity_date: q.activity_date, travellers: +q.travellers || 1 }),
    pax: (q) => +q.travellers || 1,
    title: (o) => `${o.fare_basis} with ${o.carrier_or_provider}`,
    subtitle: (o) => o.boarding_point || '',
    facts: (o) => [dur(o.duration_minutes), `${o.seats_available} spots left`, o.is_refundable ? 'Refundable' : 'Non-refundable'].filter(Boolean),
    traveller: [['name', 'Full name']],
    hold: (o, q, pax) => ({ supplier_code: o.supplier_code, external_id: o.external_id, segment: o.segments[0], activity_date: q.activity_date, passengers: pax.map((p) => ({ name: p.name })) }),
  },
  insurance: {
    ...common, key: 'insurance', label: 'Insurance', noun: 'policy', icon: 'shield-checkmark', base: '/insurance/bookings', guest: null,
    search: '/insurance/search',
    fields: [
      { name: 'destination', label: 'Destination', value: 'Thailand', kind: 'place' },
      { name: 'trip_start_date', label: 'Trip starts', type: 'date', value: () => day(15) },
      { name: 'trip_end_date', label: 'Trip ends', type: 'date', value: () => day(20) },
      { name: 'travellers', label: 'Travellers', type: 'number', value: 1, min: 1, max: 9 },
    ],
    body: (q) => ({ destination: q.destination, trip_start_date: q.trip_start_date, trip_end_date: q.trip_end_date, travellers: +q.travellers || 1 }),
    pax: (q) => +q.travellers || 1,
    title: (o) => `${o.carrier_or_provider} ${o.fare_basis}`,
    subtitle: (o) => `Cover up to ${inrN(o.coverage_amount)}`,
    facts: (o) => [`${inrN(o.coverage_amount)} cover`, `${o.policy_term_days} day policy`, `${o.fare_basis} plan`],
    traveller: [['name', 'Insured person']],
    hold: (o, q, pax) => ({ supplier_code: o.supplier_code, external_id: o.external_id, segment: o.segments[0], destination: q.destination, trip_start_date: q.trip_start_date, trip_end_date: q.trip_end_date, passengers: pax.map((p) => ({ name: p.name })) }),
  },
  visa: {
    key: 'visa', label: 'Visa', noun: 'visa', icon: 'document-text', method: 'GET', search: '/visa', guest: null, payBase: '/visa-bookings', perTraveller: true,
    fields: [
      { name: 'q', label: 'Country you are visiting', placeholder: 'Dubai, Singapore, Thailand…', value: '', kind: 'place' },
      { name: 'travel_date', label: 'Travel date', type: 'date', value: () => day(30) },
      { name: 'travellers', label: 'Applicants', type: 'number', value: 1, min: 1, max: 10 },
    ],
    body: (q) => ({ q: q.q, per_page: 50 }),
    offers: (r) => r.data || [],
    pax: (q) => +q.travellers || 1,
    id: (o) => String(o.id),
    price: (o) => Number(o.sell_price),
    refundable: () => false,
    title: (o) => `${o.destination_country_name} ${o.visa_type}`,
    subtitle: (o) => `Processed ${o.processing_time_label}`,
    facts: (o) => [`${o.entry_type} entry`, `Valid ${o.validity_days} days`, `Stay up to ${o.stay_days} days`].filter(Boolean),
    traveller: [['name', 'Name as on passport'], ['passport_number', 'Passport number'], ['date_of_birth', 'Date of birth', 'date'], ['nationality', 'Nationality']],
    bookPath: (o) => `/visa/${o.id}/applications`,
    hold: (o, q, pax) => ({ travel_date: q.travel_date, applicants: pax.map((p) => ({ name: p.name, passport_number: p.passport_number, date_of_birth: p.date_of_birth || undefined, nationality: p.nationality || undefined })) }),
  },
};

export const ORDER = ['flight', 'hotel', 'bus', 'cab', 'holiday', 'cruise', 'activity', 'insurance', 'visa'];

/** Which module a My Trips row belongs to, as the app's service key. */
export const MODULE_TO_SVC: Record<string, string> = { flight: 'flight', hotel: 'hotel', bus: 'bus', cab: 'cab', holiday: 'holiday', cruise: 'cruise', activity: 'activity', insurance: 'insurance', visa: 'visa' };

export function defaults(svc: Service) {
  const q: Record<string, any> = {};
  for (const f of svc.fields) q[f.name] = typeof f.value === 'function' ? f.value() : f.value;
  return q;
}

export const POPULAR: Record<string, string[][]> = {
  flight: [['DEL', 'BOM'], ['BLR', 'DEL'], ['BOM', 'GOI'], ['MAA', 'BLR']],
  hotel: [['Goa'], ['Jaipur'], ['Manali'], ['Udaipur']],
  bus: [['Chennai', 'Bengaluru'], ['Mumbai', 'Pune'], ['Delhi', 'Jaipur'], ['Hyderabad', 'Vijayawada']],
  cab: [['Chennai', 'Pondicherry'], ['Bengaluru', 'Mysuru'], ['Delhi', 'Agra'], ['Mumbai', 'Lonavala']],
  holiday: [['Kashmir'], ['Kerala'], ['Goa'], ['Bali']],
  cruise: [['Chennai', 'Colombo'], ['Mumbai', 'Goa'], ['Kochi', 'Lakshadweep'], ['Singapore', 'Phuket']],
  activity: [['Goa'], ['Dubai'], ['Singapore'], ['Rishikesh']],
  insurance: [['Thailand'], ['Dubai'], ['Europe'], ['USA']],
  visa: [['Singapore'], ['Indonesia'], ['Thailand'], ['United Arab Emirates']],
};

/** The PDF each service issues once confirmed (…/bookings/{id}/<file>). */
export const DOCS: Record<string, string> = { flight: 'ticket.pdf', hotel: 'voucher.pdf', bus: 'ticket.pdf', cab: 'voucher.pdf', cruise: 'ticket.pdf', activity: 'voucher.pdf', insurance: 'policy.pdf' };
