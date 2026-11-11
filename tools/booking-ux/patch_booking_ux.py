#!/usr/bin/env python3
"""Booking-flow and SEO upgrade for the compiled portal (frontend/).

Run by tools/build_portal.sh after the other patches. It:

1. Adds booking_ux.src.js (React components written in the bundle's own
   style) and booking_ux.css.
2. Confirmation pages of all 7 checkouts (flight, bus, hotel, cab,
   insurance, cruise, activity): a clear success header with booking ID
   (copy), PNR, where the ticket was sent, add-to-calendar, WhatsApp share
   and print; then "What happens next", help and cancellation guidance.
3. Checkout: a trust list in the flight fare summary, trust notes on every
   payment step, and a consent line (terms, privacy, cancellation policy)
   next to every pay button.
4. Phones: results filters open as a bottom sheet; checkout keeps the
   total and main button in a bar at the bottom.
5. SEO: clean landing pages for popular routes (/flights/delhi-to-mumbai-
   flights, /buses/chennai-to-bengaluru-bus-tickets, /hotels/hotels-in-goa)
   that run the real search and add route text, breadcrumbs, FAQs and
   related links; footer links use them; canonical/og:url on every page and
   noindex on private pages.
6. A public B2B page (/partners) linked from the header and footer.

Usage: python3 tools/booking-ux/patch_booking_ux.py <frontend_dir>
The page data comes from tools/seo/seo_data.py; tools/seo/build_seo.py then
writes the crawler HTML, sitemap and robots.txt from the same data.
"""
import hashlib
import json
import pathlib
import re
import sys

HERE = pathlib.Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / 'seo'))
import seo_data as D  # noqa: E402

ROOT = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else HERE.parents[1] / 'frontend')
MARK = '/* fyndmytrip booking UX layer.'

PUBLIC = {'/', '/flights', '/hotels', '/buses', '/cabs', '/insurance', '/cruises', '/activities', '/visa',
          '/packages', '/about-us', '/contact-us', '/privacy-policy', '/terms-and-conditions', '/refund-policy',
          '/cancellation-policy', '/disclaimer', '/agent-signup', '/register', '/partners'}


def seo_js():
    pages = {}
    for path, p in D.SERVICE_PAGES.items():
        pages[path] = {k: p[k] for k in ('title', 'description', 'h1', 'intro', 'faqs')}
    land = D.landing_pages()
    by_kind = {}
    for p in land:
        by_kind.setdefault(p['kind'], []).append(p)
    for p in land:
        sib = by_kind[p['kind']]
        if p['kind'] == 'hotel':
            rel = [q['path'] for q in sib if q is not p]
        else:
            o, d = p['params']['from'], p['params']['to']
            rel = [q['path'] for q in sib if q is not p and (q['params']['from'] in (o, d) or q['params']['to'] in (o, d))]
            rel += [q['path'] for q in sib if q is not p and q['path'] not in rel]
        lead = p['intro'][0]
        pages[p['path']] = {'title': p['title'], 'description': p['description'], 'h1': p['h1'], 'lead': lead,
                            'intro': p['intro'], 'faqs': p['faqs'], 'kind': p['kind'], 'params': p['params'],
                            'related': rel[:10]}
    data = {
        'pages': pages,
        'flightIdx': {f'{o}-{d}': D.flight_path(o, d) for o, d in D.FLIGHT_ROUTES},
        'busIdx': {f'{o}-{d}': D.bus_path(o, d) for o, d in D.BUS_ROUTES},
        'hotelIdx': {c: D.hotel_path(c) for c in D.HOTEL_CITIES},
        'noindex': [],
    }
    return data


def js_list(items):
    return json.dumps(items, ensure_ascii=False, separators=(',', ':'))


def sub_once(s, old, new, count=1, label=None):
    n = s.count(old)
    assert n == count, f'{label or old[:70]!r}: expected {count}, found {n}'
    return s.replace(old, new)


def main():
    assets = ROOT / 'assets'
    html_p = ROOT / 'index.html'
    html = html_p.read_text(encoding='utf-8')
    main_name = re.search(r'/assets/(index-[A-Za-z0-9_-]+\.js)', html).group(1)
    s = (assets / main_name).read_text(encoding='utf-8')
    if MARK in s:
        print('booking UX patch already applied')
        return

    data = seo_js()
    # Every routed path that is not public gets noindex.
    paths = set(re.findall(r'path:`(/[^`]*)`', s))
    data['noindex'] = sorted(p for p in paths if p not in PUBLIC and ':' not in p and p != '*' and not p.endswith('/checkout'))
    src = (HERE / 'booking_ux.src.js').read_text(encoding='utf-8').strip()
    helpers = ('var FMT_SEO=' + json.dumps(data, ensure_ascii=False, separators=(',', ':')) + ';\n'
               'function fmtFlightPath(e){return FMT_SEO.flightIdx[e.from+`-`+e.to]||`/flights?from=${e.from}&to=${e.to}`}\n'
               'function fmtBusPath(e){return FMT_SEO.busIdx[e.from+`-`+e.to]||`/buses?from=${encodeURIComponent(e.from)}&to=${encodeURIComponent(e.to)}`}\n'
               'function fmtHotelPath(c){return FMT_SEO.hotelIdx[c]||`/hotels?city=${encodeURIComponent(c)}`}\n')
    s = sub_once(s, 'function Use(){', src + '\n' + helpers + 'function Use(){')

    # --- 2. confirmation pages -------------------------------------------
    heads = [(m.start(), m.group(1)) for m in re.finditer(r'children:`Complete your (\w+) (?:booking|purchase)`', s)]
    heads += [(m.start(), 'flight') for m in re.finditer(r'children:`Complete your booking`', s)]
    heads.sort()
    pat = re.compile(r'(\w+)===`confirmed`&&(\w+)&&\(0,U\.jsxs\)\(X,\{className:`space-y-4`,children:\[')
    out, last, seen = [], 0, []
    for m in pat.finditer(s):
        svc = [h for h in heads if h[0] < m.start()][-1][1]
        b = m.group(2)
        extra = ''
        if svc == 'flight':
            extra = ',route:`${Ce[0].offer.segments[0].origin} → ${Ce[Ce.length-1].offer.segments[Ce[Ce.length-1].offer.segments.length-1].destination}`,when:Ce[0].offer.segments[0].departure_at'
        rep = (f'{m.group(1)}===`confirmed`&&{b}&&(0,U.jsxs)(X,{{className:`space-y-4 fmt-confirm`,children:['
               f'(0,U.jsx)(FmtConfirmHero,{{booking:{b},service:`{svc}`{extra}}}),(0,U.jsx)(FmtNextSteps,{{booking:{b},service:`{svc}`}}),')
        out.append(s[last:m.start()] + rep)
        last = m.end()
        seen.append(svc)
    s = ''.join(out) + s[last:]
    assert sorted(seen) == sorted(['flight', 'bus', 'hotel', 'cab', 'insurance', 'cruise', 'activity']), seen

    # --- 3. checkout trust + consent --------------------------------------
    s = sub_once(s, '(0,U.jsxs)(X,{className:`h-fit space-y-4 lg:sticky lg:top-4`,children:[(0,U.jsxs)(`div`,{className:`space-y-2`,children:[(0,U.jsxs)(`div`,{className:`flex items-center justify-between`,children:[(0,U.jsx)(`div`,{className:`text-xs font-semibold uppercase tracking-wide text-slate-400`,children:`Your trip`})',
                 '(0,U.jsxs)(X,{className:`h-fit space-y-4 lg:sticky lg:top-4 fmt-sum`,children:[(0,U.jsxs)(`div`,{className:`space-y-2`,children:[(0,U.jsxs)(`div`,{className:`flex items-center justify-between`,children:[(0,U.jsx)(`div`,{className:`text-xs font-semibold uppercase tracking-wide text-slate-400`,children:`Your trip`})')
    s = sub_once(s, ',i]})}function S7(', ',i,(0,U.jsx)(FmtSummaryTrust,{})]})}function S7(')
    s = sub_once(s, '(0,U.jsx)(`p`,{className:`text-center text-xs text-slate-400`,children:s})]})}',
                 '(0,U.jsx)(`p`,{className:`text-center text-xs text-slate-400`,children:s}),(0,U.jsx)(FmtConsent,{})]})}')
    h3 = '(0,U.jsx)(`h3`,{className:`mb-2 text-sm font-semibold text-brand-navy`,children:`Choose a payment method`})'
    n = s.count(h3)
    assert n >= 7, n
    s = s.replace(h3, '(0,U.jsx)(FmtPayTrust,{}),' + h3)
    # Gateway step rows (Back + "I've completed payment").
    s, n1 = re.subn(r'(\(0,U\.jsxs\)\(`div`,\{className:`flex gap-3 border-t border-slate-100 pt-4`,children:\[\(0,U\.jsx\)\(J,\{variant:`secondary`,onClick:\(\)=>\w\(`review`\))',
                    r'(0,U.jsx)(FmtConsent,{}),\1', s)
    assert n1 == 7, n1
    # Review steps that pay straight from the page (all but flights).
    s, n2 = re.subn(r'(\(0,U\.jsxs\)\(`div`,\{className:`flex gap-3 border-t border-slate-100 pt-4`,children:\[\(0,U\.jsx\)\(J,\{variant:`secondary`,onClick:\(\)=>\w\(`(?:passengers|guests)`\),children:`Back`\}\),\(0,U\.jsx\)\(J,\{onClick:\(\)=>\w+\.mutate\(\))',
                    r'(0,U.jsx)(FmtSummaryTrust,{}),(0,U.jsx)(FmtConsent,{}),\1', s)
    assert n2 == 6, n2

    # --- 4. filters become a bottom sheet on phones -----------------------
    flt = '(0,U.jsxs)(X,{className:`space-y-5`,children:[(0,U.jsxs)(`div`,{className:`flex items-center justify-between`,children:[(0,U.jsx)(`h3`,{className:`text-sm font-semibold text-brand-navy`,children:'
    assert s.count(flt) == 2, s.count(flt)
    s = s.replace(flt, flt.replace('`space-y-5`', '`space-y-5 fmt-filters`'))

    # --- 5. SEO landing pages ---------------------------------------------
    s = sub_once(s, '(0,U.jsx)(H,{path:`/flights/checkout`,element:(0,U.jsx)(ere,{})})',
                 '(0,U.jsx)(H,{path:`/flights/checkout`,element:(0,U.jsx)(ere,{})}),(0,U.jsx)(H,{path:`/flights/:fmtSlug`,element:(0,U.jsx)(FmtLanding,{base:R5})}),(0,U.jsx)(H,{path:`/partners`,element:(0,U.jsx)(FmtPartnersPage,{})})')
    s = sub_once(s, '(0,U.jsx)(H,{path:`/hotels/checkout`,element:(0,U.jsx)(qre,{})})',
                 '(0,U.jsx)(H,{path:`/hotels/checkout`,element:(0,U.jsx)(qre,{})}),(0,U.jsx)(H,{path:`/hotels/:fmtSlug`,element:(0,U.jsx)(FmtLanding,{base:zre})})')
    s = sub_once(s, '(0,U.jsx)(H,{path:`/buses/checkout`,element:(0,U.jsx)(Ore,{})})',
                 '(0,U.jsx)(H,{path:`/buses/checkout`,element:(0,U.jsx)(Ore,{})}),(0,U.jsx)(H,{path:`/buses/:fmtSlug`,element:(0,U.jsx)(FmtLanding,{base:Sre})})')
    s = sub_once(s, 'let e=t.get(`from`),n=t.get(`to`);e&&n&&ae({trip_type:`one_way`',
                 'let e=t.get(`from`)||fmtLandNow()?.params.from,n=t.get(`to`)||fmtLandNow()?.params.to;e&&n&&ae({trip_type:`one_way`')
    s = sub_once(s, 'let e=t.get(`from`),n=t.get(`to`);e&&n&&k({origin:e,destination:n',
                 'let e=t.get(`from`)||fmtLandNow()?.params.from,n=t.get(`to`)||fmtLandNow()?.params.to;e&&n&&k({origin:e,destination:n')
    s = sub_once(s, 'let e=t.get(`city`);e&&j({city:e', 'let e=t.get(`city`)||fmtLandNow()?.params.city;e&&j({city:e')
    s = sub_once(s, 'function MY({eyebrow:e,title:t,subtitle:n,backgroundImageUrl:r,variant:i=`classic`,children:a}){',
                 'function MY({eyebrow:e,title:t,subtitle:n,backgroundImageUrl:r,variant:i=`classic`,children:a}){let fmtL=fmtLandNow();fmtL&&(t=fmtL.h1,n=fmtL.lead);')
    s = sub_once(s, 'function h5({title:e,subtitle:t,children:n}){', 'function h5({title:e,subtitle:t,children:n}){let fmtL=fmtLandNow();fmtL&&(e=fmtL.h1,t=fmtL.lead);')
    # In-app titles on public pages match the pre-rendered ones.
    s = sub_once(s, 'function kY(e,t){(0,x.useEffect)(()=>{', 'function kY(e,t){let fmtP=FMT_SEO.pages[location.pathname.replace(/\\/+$/,``)||`/`];fmtP&&(e=fmtP.title,t=fmtP.description);(0,x.useEffect)(()=>{')
    # Footer / home route lists come from seo_data.py, and link to the landing pages.
    fl = [{'from': o, 'to': d, 'fromLabel': D.AIRPORTS[o][0], 'toLabel': D.AIRPORTS[d][0]} for o, d in D.FLIGHT_ROUTES[:D.FOOTER_FLIGHTS]]
    bl = [{'from': o, 'to': d} for o, d in D.BUS_ROUTES[:D.FOOTER_BUSES]]
    hl = D.HOTEL_CITIES[:D.FOOTER_HOTELS]
    m = re.search(r' sm=(\[\{from:`DEL`.*?\}\]),cm=(\[.*?\]),lm=(\[.*?\]),um=', s)
    assert m, 'route lists not found'
    s = s[:m.start()] + f' sm={js_list(fl)},cm={js_list(bl)},lm={js_list(hl)},um=' + s[m.end():]
    s = s.replace('to:`/flights?from=${e.from}&to=${e.to}`', 'to:fmtFlightPath(e)')
    s = s.replace('to:`/buses?from=${encodeURIComponent(e.from)}&to=${encodeURIComponent(e.to)}`', 'to:fmtBusPath(e)')
    s = s.replace('to:`/hotels?city=${encodeURIComponent(e)}`', 'to:fmtHotelPath(e)')
    s = s.replace('to:`/hotels?city=${encodeURIComponent(e.label)}`', 'to:fmtHotelPath(e.label)')
    assert 'to:`/flights?from=' not in s and 'to:`/hotels?city=' not in s

    # --- 6. B2B page links --------------------------------------------------
    s = sub_once(s, "{to:`/#offers`,label:`Offers & coupons`}]}", "{to:`/#offers`,label:`Offers & coupons`},{to:`/partners`,label:`Partner with us (B2B)`},{to:`/agent-signup`,label:`Travel agent sign-up`}]}")
    s = sub_once(s, '(0,U.jsxs)(`div`,{className:`hidden items-center gap-2 sm:flex`,children:[(0,U.jsx)(Mn,{to:`/login`',
                 '(0,U.jsxs)(`div`,{className:`hidden items-center gap-2 sm:flex`,children:[(0,U.jsx)(Mn,{to:`/partners`,className:`fmt-nav-b2b`,children:`For travel agents`}),(0,U.jsx)(Mn,{to:`/login`')
    s = sub_once(s, 'Interested in a white-label storefront or partnership? Write to us at [partners@fyndmytrip.in].',
                 'Interested in a B2B account, a white-label storefront or our API? See our Partner page at /partners.')

    # --- write: new file names so browsers never mix old and new ----------
    css = (HERE / 'booking_ux.css').read_text(encoding='utf-8')
    css_name = f'fmt-ux-{hashlib.sha256(css.encode()).hexdigest()[:8]}.css'
    (assets / css_name).write_text(css, encoding='utf-8')
    new_main = f'index-{hashlib.sha256(s.encode()).hexdigest()[:8]}.js'
    for chunk in sorted(assets.glob('*.js')):
        if chunk.name == main_name:
            continue
        c = chunk.read_text(encoding='utf-8')
        if main_name in c:
            c = c.replace(main_name, new_main)
            base = chunk.name.rsplit('-', 1)[0]
            nn = f'{base}-{hashlib.sha256(c.encode()).hexdigest()[:8]}.js'
            (assets / nn).write_text(c, encoding='utf-8')
            chunk.unlink()
            s = s.replace(chunk.name, nn)
    (assets / main_name).unlink()
    (assets / new_main).write_text(s, encoding='utf-8')
    html = html.replace(main_name, new_main)
    html = html.replace('</head>', f'    <link rel="stylesheet" href="/assets/{css_name}">\n  </head>', 1)
    html_p.write_text(html, encoding='utf-8')
    print('booking UX patch applied:', new_main, css_name)


if __name__ == '__main__':
    main()
