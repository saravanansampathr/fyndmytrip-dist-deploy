// The 54 storefront templates. A template is a full design for the whole
// customer journey: brand palette + type + shape, and a layout choice for
// every page of the flow (header, home/search, results, details, traveller
// form, payment, footer). No two templates share the same set of page
// layouts (checked by e2e/storefront_e2e.js), so each one is a different
// end-to-end booking experience, not just a recolour.
//
// ids 1-12 keep the names of the original white-label templates, so a tenant
// that picked one of those before keeps the same template here.

export const AXES = {
  header: ['bar', 'centered', 'split', 'glass', 'rail', 'ribbon', 'stacked'],
  hero: ['tabs-card', 'fullbleed', 'split', 'line', 'stack', 'icons', 'wizard', 'pill', 'rail', 'magazine'],
  results: ['list', 'grid', 'table', 'timeline', 'compact', 'ticket', 'sidebar', 'masonry'],
  details: ['panel', 'columns', 'tabs', 'sheet', 'banner'],
  travellers: ['single', 'stepper', 'accordion', 'cards', 'inline'],
  payment: ['tabs', 'radios', 'tiles', 'split', 'accordion', 'minimal'],
  footer: ['columns', 'minimal', 'band', 'centered'],
};

// name, primary, accent, background, dark?, heading font, body font, radius(px), shadow, audience
const ROWS = [
  ['Classic Navy', '#0f2a4a', '#14b8a6', '#f4f7fb', 0, 'Poppins', 'Inter', 12, 'soft', 'The original FyndMyTrip look'],
  ['Sunrise Coral', '#ff6b4a', '#ffb703', '#fff8f4', 0, 'Montserrat', 'Nunito', 18, 'soft', 'Leisure & holiday brands'],
  ['Emerald Business', '#0f6f4f', '#c9a227', '#f3f7f4', 0, 'IBM Plex Sans', 'IBM Plex Sans', 4, 'line', 'Corporate travel desks'],
  ['Midnight Slate', '#3b82f6', '#22d3ee', '#0b1220', 1, 'Space Grotesk', 'Inter', 10, 'glow', 'Tech-forward OTAs'],
  ['Royal Purple', '#7c3aed', '#f0abfc', '#faf7ff', 0, 'Playfair Display', 'Lato', 16, 'soft', 'Luxury travel'],
  ['Desert Sand', '#c2793a', '#2f4858', '#fbf5ec', 0, 'Cormorant Garamond', 'Karla', 6, 'soft', 'Gulf-market storefronts'],
  ['Crimson Express', '#dc2626', '#111827', '#ffffff', 0, 'Oswald', 'Roboto', 2, 'hard', 'Low-cost, high-conversion'],
  ['Ocean Teal', '#0d9488', '#38bdf8', '#f0fdfa', 0, 'Quicksand', 'Quicksand', 24, 'none', 'Boutique agencies'],
  ['Sunny Yellow', '#eab308', '#1f2937', '#fffdf0', 0, 'Rubik', 'Rubik', 14, 'hard', 'Budget & backpacker'],
  ['Steel Grey Corporate', '#475569', '#0ea5e9', '#f8fafc', 0, 'Archivo', 'Archivo', 4, 'line', 'B2B & API partners'],
  ['Rose Gold', '#b76e79', '#e0a8a0', '#fff7f6', 0, 'Fraunces', 'Mulish', 20, 'soft', 'Honeymoon & weddings'],
  ['Forest Adventure', '#4d7c0f', '#f59e0b', '#f7f9f0', 0, 'Barlow Condensed', 'Barlow', 8, 'hard', 'Adventure & trekking'],
  ['Arctic Blue', '#0369a1', '#7dd3fc', '#f0f9ff', 0, 'Sora', 'Inter', 12, 'soft', 'Winter & ski trips'],
  ['Saffron Heritage', '#ea580c', '#7c2d12', '#fff7ed', 0, 'Yeseva One', 'Lora', 6, 'soft', 'Pilgrimage & heritage India'],
  ['Neon Night', '#a855f7', '#22d3ee', '#0a0a12', 1, 'Outfit', 'Outfit', 14, 'glow', 'Nightlife & city breaks'],
  ['Monsoon Green', '#15803d', '#84cc16', '#f2fbf3', 0, 'Lexend', 'Lexend', 10, 'none', 'Kerala & nature retreats'],
  ['Charcoal Gold', '#d4a017', '#f5e6b3', '#121212', 1, 'Cinzel', 'Raleway', 2, 'line', 'Premium concierge'],
  ['Bubblegum', '#ec4899', '#8b5cf6', '#fff5fb', 0, 'Baloo 2', 'Nunito', 28, 'hard', 'Family & kids holidays'],
  ['Nordic Minimal', '#111827', '#9ca3af', '#ffffff', 0, 'Manrope', 'Manrope', 0, 'line', 'Minimalist brands'],
  ['Lagoon', '#06b6d4', '#0e7490', '#ecfeff', 0, 'Plus Jakarta Sans', 'Plus Jakarta Sans', 16, 'soft', 'Maldives & islands'],
  ['Terracotta', '#b45309', '#065f46', '#fdf6ee', 0, 'DM Serif Display', 'DM Sans', 8, 'soft', 'Mediterranean escapes'],
  ['Indigo Pro', '#4338ca', '#f97316', '#f5f5ff', 0, 'Inter', 'Inter', 8, 'soft', 'Agents & sub-agents'],
  ['Mint Fresh', '#10b981', '#064e3b', '#f0fdf7', 0, 'Urbanist', 'Urbanist', 18, 'none', 'Wellness & yoga travel'],
  ['Volcano', '#b91c1c', '#f97316', '#1c1210', 1, 'Bebas Neue', 'Work Sans', 4, 'hard', 'Extreme adventure'],
  ['Lavender Fields', '#8b5cf6', '#c4b5fd', '#fbfaff', 0, 'Josefin Sans', 'Josefin Sans', 22, 'soft', 'Europe tours'],
  ['Cobalt Air', '#1d4ed8', '#facc15', '#ffffff', 0, 'Titillium Web', 'Titillium Web', 6, 'hard', 'Airline-style flight shops'],
  ['Sandstone', '#a16207', '#57534e', '#faf7f2', 0, 'Libre Baskerville', 'Source Sans 3', 2, 'line', 'Rajasthan & desert tours'],
  ['Cherry Blossom', '#db2777', '#fbcfe8', '#fff7fa', 0, 'Shippori Mincho', 'Noto Sans', 14, 'soft', 'Japan & East Asia'],
  ['Graphite', '#374151', '#10b981', '#111827', 1, 'IBM Plex Mono', 'IBM Plex Sans', 4, 'line', 'Developer & API demos'],
  ['Tropicana', '#f97316', '#16a34a', '#fffbf2', 0, 'Fredoka', 'Nunito', 24, 'hard', 'Beach holidays'],
  ['Deep Sea', '#0c4a6e', '#2dd4bf', '#06141f', 1, 'Exo 2', 'Exo 2', 12, 'glow', 'Cruise lines'],
  ['Copper Rail', '#9a3412', '#fde68a', '#fffaf3', 0, 'Alegreya Sans', 'Alegreya Sans', 6, 'hard', 'Rail & road journeys'],
  ['Skyline', '#2563eb', '#a5b4fc', '#f7f9ff', 0, 'Red Hat Display', 'Red Hat Text', 12, 'soft', 'Business city trips'],
  ['Olive Grove', '#65a30d', '#713f12', '#fbfcf5', 0, 'Spectral', 'Spectral', 8, 'none', 'Slow travel & farm stays'],
  ['Berry Burst', '#be185d', '#7e22ce', '#fff8fc', 0, 'Righteous', 'Poppins', 16, 'hard', 'Youth & student travel'],
  ['Polar Night', '#60a5fa', '#e0e7ff', '#0f172a', 1, 'Lexend', 'Inter', 10, 'glow', 'Aurora & Arctic tours'],
  ['Marigold', '#d97706', '#be123c', '#fffbeb', 0, 'Kalam', 'Hind', 12, 'soft', 'Festivals & weddings India'],
  ['Pine Lodge', '#14532d', '#ca8a04', '#f6f8f4', 0, 'Bitter', 'Bitter', 4, 'line', 'Hill stations & lodges'],
  ['Electric Lime', '#65a30d', '#18181b', '#fbffe8', 0, 'Unbounded', 'Inter', 0, 'hard', 'Bold startup OTAs'],
  ['Pearl', '#64748b', '#c7a17a', '#fdfcfa', 0, 'Marcellus', 'Jost', 30, 'soft', 'Five-star hotels'],
  ['Ruby Velvet', '#9f1239', '#fda4af', '#1a0b10', 1, 'Playfair Display', 'Lato', 6, 'glow', 'Luxury nightlife'],
  ['Aqua Breeze', '#0891b2', '#f472b6', '#f5fdff', 0, 'Comfortaa', 'Comfortaa', 26, 'none', 'Water parks & activities'],
  ['Granite Pro', '#1f2937', '#ef4444', '#f3f4f6', 0, 'Roboto Condensed', 'Roboto', 2, 'line', 'Corporate travel managers'],
  ['Peach Sorbet', '#fb7185', '#fdba74', '#fffaf7', 0, 'Pacifico', 'Nunito', 20, 'soft', 'Couples & weekend getaways'],
  ['Navy Anchor', '#1e3a8a', '#dc2626', '#f8fafc', 0, 'Merriweather', 'Open Sans', 4, 'hard', 'Ferries & cruises'],
  ['Cactus', '#3f6212', '#fb923c', '#fbfaf3', 0, 'Chakra Petch', 'Chakra Petch', 8, 'hard', 'Road trips'],
  ['Midnight Bloom', '#c084fc', '#fb7185', '#140b1f', 1, 'Syne', 'Inter', 16, 'glow', 'Music festival trips'],
  ['Glacier', '#0e7490', '#cbd5e1', '#f8fcfd', 0, 'Nunito Sans', 'Nunito Sans', 10, 'none', 'Calm, accessible booking'],
  ['Sunset Strip', '#f43f5e', '#f59e0b', '#fff8f5', 0, 'Kanit', 'Kanit', 12, 'hard', 'Bus & coach operators'],
  ['Bamboo', '#4d7c0f', '#a3a380', '#f9faf4', 0, 'Zen Kaku Gothic New', 'Noto Sans', 6, 'line', 'Southeast Asia'],
  ['Royal Blue Rail', '#1e40af', '#fbbf24', '#f5f8ff', 0, 'Cabin', 'Cabin', 6, 'soft', 'Rail & IRCTC-style agents'],
  ['Onyx', '#fafafa', '#f59e0b', '#09090b', 1, 'Archivo Black', 'Archivo', 0, 'line', 'High-contrast premium'],
  ['Coral Reef', '#e11d48', '#14b8a6', '#fff6f7', 0, 'Overpass', 'Overpass', 14, 'soft', 'Diving & snorkel trips'],
  ['Heritage Teak', '#7c2d12', '#ca8a04', '#fbf7f1', 0, 'Rozha One', 'Mukta', 4, 'hard', 'Palace stays & royal India'],
];

// Spreads layouts so any two templates differ on at least 4 of the 7 page
// layouts (searched offline; checked again by the storefront e2e run).
const SPREAD = [[2, 5, 7], [5, 2, 10], [2, 11, 0], [4, 4, 10], [12, 1, 2], [10, 1, 1], [7, 4, 6]];

const pick = (list, n) => list[((n % list.length) + list.length) % list.length];

export const TEMPLATES = ROWS.map((r, i) => {
  const [name, primary, accent, bg, dark, head, body, radius, shadow, audience] = r;
  return {
    id: i + 1,
    slug: name.toLowerCase().replace(/[^a-z0-9]+/g, '-'),
    name, audience, dark: !!dark,
    colors: { primary, accent, bg },
    fonts: { head, body },
    radius, shadow,
    layout: Object.fromEntries(Object.keys(AXES).map((axis, j) => {
      const [mul, every, offset] = SPREAD[j];
      return [axis, pick(AXES[axis], i * mul + Math.floor(i / every) + offset)];
    })),
  };
});

export const byId = (id) => TEMPLATES.find((t) => t.id === +id) || TEMPLATES[0];
