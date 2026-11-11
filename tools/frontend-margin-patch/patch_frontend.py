"""Rebuilds frontend/ with the margin-rules page swapped in and holiday
package payment added.

The React/TypeScript source of the portal is not in this repo (only the
compiled Vite build is), so the Margin rules page is written directly in
the bundle's own style (markup_page.src.js) and spliced over the old
page's functions. Usage:

    python3 patch_frontend.py <original build dir> <output dir>

<original build dir> is an unpatched batch-106 build (index.html +
assets/index-ZxkLBm7F.js, as in updatedassets.zip or this repo's first
import commit).
"""
import hashlib, os, re, shutil, sys
SRC, OUT = sys.argv[1], sys.argv[2]
HERE = os.path.dirname(os.path.abspath(__file__))
js_name, css_name = 'index-ZxkLBm7F.js', 'index-FKMQ4pEu.css'
s = open(f'{SRC}/assets/{js_name}', encoding='utf-8').read()
i = s.index('async function uae(){'); j = s.index('}async function xae(){') + 1
new_page = open(os.path.join(HERE, 'markup_page.src.js'), encoding='utf-8').read().strip()
s = s[:i] + new_page + s[j:]
old_nav = "{to:`/markup-rules`,label:`Markup rules`,icon:`⇧`,roles:[`super_admin`,`staff`,`partner_admin`,`agent`,`sub_agent`]}"
assert s.count(old_nav) == 1
s = s.replace(old_nav, "{to:`/markup-rules`,label:`Margin rules`,icon:`⇧`,roles:[`super_admin`,`staff`,`partner_admin`,`agent`,`sub_agent`,`api_user`]}")
# Holiday packages: after "Request booking" holds the booking, let the
# traveller pay for it (wallet / gateway / split) — holiday_pay.src.js.
s = s.replace('function Use(){', open(os.path.join(HERE, 'holiday_pay.src.js'), encoding='utf-8').read().strip() + 'function Use(){', 1)
anchor = '[v,y]=(0,x.useState)(!1);async function b(t){'
assert s.count(anchor) == 1
s = s.replace(anchor, '[v,y]=(0,x.useState)(!1),[fmtHb,fmtHbS]=(0,x.useState)(null);async function b(t){', 1)
for old_ok, new_ok in [
    ("onSuccess:e=>{h(null),_(`Booking ${e.booking_reference.slice(0,8)} held successfully.`)}",
     "onSuccess:e=>{h(null),fmtHbS(e),_(`Booking ${e.booking_reference.slice(0,8)} held — complete payment below to confirm it.`)}"),
    ("onSuccess:({booking:e,user:t,token:r})=>{n(zs({user:t,token:r})),h(null),_(",
     "onSuccess:({booking:e,user:t,token:r})=>{n(zs({user:t,token:r})),h(null),fmtHbS(e),_("),
    ("g&&(0,U.jsx)(`p`,{className:`text-sm font-medium text-emerald-600`,children:g})]}),(0,U.jsx)(X,{className:`mt-4 space-y-3`,children:D?",
     "g&&(0,U.jsx)(`p`,{className:`text-sm font-medium text-emerald-600`,children:g}),fmtHb&&(0,U.jsx)(FmtHolidayPay,{booking:fmtHb,onPaid:fmtHbS})]}),(0,U.jsx)(X,{className:`mt-4 space-y-3`,children:D?"),
]:
    assert s.count(old_ok) == 1, old_ok[:60]
    s = s.replace(old_ok, new_ok, 1)
# Visa: "Apply now" did nothing. It now opens an applicant form that
# creates the application and hands over to the same payment step.
for old_ok, new_ok in [
    ("function Yse(){kY(`Visa Services", "function Yse(){let[fmtVa,fmtVaS]=(0,x.useState)(null);kY(`Visa Services"),
    ("(0,U.jsx)(`button`,{className:`rounded-md bg-brand-teal px-3 py-2 text-sm font-medium text-white hover:bg-brand-teal-dark`,children:`Apply now`})]}),Xse(e)&&",
     "(0,U.jsx)(`button`,{type:`button`,onClick:()=>fmtVaS(fmtVa===e.id?null:e.id),className:`rounded-md bg-brand-teal px-3 py-2 text-sm font-medium text-white hover:bg-brand-teal-dark`,children:fmtVa===e.id?`Close`:`Apply now`})]}),fmtVa===e.id&&(0,U.jsx)(FmtVisaApply,{product:e}),Xse(e)&&"),
]:
    assert s.count(old_ok) == 1, old_ok[:60]
    s = s.replace(old_ok, new_ok, 1)
# My Trips: finish paying for a held booking, or cancel your own booking
# (refund quote first) — FmtTripActions in holiday_pay.src.js.
for old_ok, new_ok in [
    ("e.summary})]})]}),(0,U.jsxs)(`div`,{className:`mt-6 flex justify-end gap-2`,children:[(0,U.jsx)(J,{variant:`secondary`,onClick:t,children:`Close`})",
     "e.summary})]})]}),(0,U.jsx)(FmtTripActions,{booking:e},e.id),(0,U.jsxs)(`div`,{className:`mt-6 flex justify-end gap-2`,children:[(0,U.jsx)(J,{variant:`secondary`,onClick:t,children:`Close`})"),
    ("(0,U.jsxs)(`td`,{className:`text-right whitespace-nowrap`,children:[e.invoice_url&&(0,U.jsx)(J,{variant:`secondary`,onClick:()=>b(e.id,e.invoice_url)",
     "(0,U.jsxs)(`td`,{className:`text-right whitespace-nowrap`,children:[e.pay_path?(0,U.jsx)(J,{onClick:()=>_(e.id),children:`Pay now`}):e.can_cancel&&(0,U.jsx)(J,{variant:`secondary`,onClick:()=>_(e.id),children:`Cancel`}),` `,e.invoice_url&&(0,U.jsx)(J,{variant:`secondary`,onClick:()=>b(e.id,e.invoice_url)"),
    # Package page: show the exact total for the traveller mix (with
    # children/infant prices and the viewer's margin) from /quote.
    ("S=zi({queryKey:[`package`,e],queryFn:()=>CY(e),enabled:!!e}),",
     "S=zi({queryKey:[`package`,e],queryFn:()=>CY(e),enabled:!!e}),fmtQ=zi({queryKey:[`package-quote`,e,i.filter(t=>t.name.trim()).map(t=>t.occupancy_type).join()],queryFn:async()=>{let{data:r}=await K.post(`/packages/${e}/quote`,{travelers:i.filter(t=>t.name.trim()).map(t=>({occupancy_type:t.occupancy_type}))});return r.data},enabled:!!e&&i.some(t=>t.name.trim())}),"),
    ("children:`Estimated total`}),(0,U.jsxs)(`span`,{className:`text-lg font-bold text-brand-navy`,children:[`₹`,oe.toFixed(2)]})",
     "children:fmtQ.data?`Total payable`:`Estimated total`}),(0,U.jsxs)(`span`,{className:`text-lg font-bold text-brand-navy`,\"data-testid\":`package-total`,children:[`₹`,(fmtQ.data&&i.some(t=>t.name.trim())?Number(fmtQ.data.total_amount):oe).toFixed(2)]})"),
]:
    assert s.count(old_ok) == 1, old_ok[:60]
    s = s.replace(old_ok, new_ok, 1)
# Multi-currency: pay in the visitor's own currency. Every POST .../pay
# carries the selected currency; on a first visit the currency is set from
# the visitor's country (browser time zone or CDN country header); and each
# payment-gateway step says what the card will be charged.
old_rq = "K.interceptors.request.use(e=>{let t=qs.getState().auth.token;"
assert s.count(old_rq) == 1
s = s.replace(old_rq, "K.interceptors.request.use(e=>{try{if(String(e.method).toLowerCase()===`post`&&/\\/pay$/.test(e.url||``)){let c=localStorage.getItem(Us);c&&!(e.data&&e.data.currency)&&(e.data=Object.assign({},e.data||{},{currency:c}))}}catch(_){}let t=qs.getState().auth.token;", 1)
old_rs = "K.interceptors.response.use(e=>e,e=>(e.response?.status===401&&qs.dispatch(Bs()),Promise.reject(e)));"
assert s.count(old_rs) == 1
s = s.replace(old_rs, old_rs + "setTimeout(()=>{try{if(!localStorage.getItem(Us)){K.get(`/currency-rates`,{params:{tz:Intl.DateTimeFormat().resolvedOptions().timeZone}}).then(r=>{let c=r.data?.data?.suggested;c&&!localStorage.getItem(Us)&&qs.dispatch(Gs(c))}).catch(()=>{})}}catch(_){}},0);", 1)
k = "(0,U.jsx)(`h3`,{className:`mb-2 text-sm font-semibold text-brand-navy`,children:`Choose a payment method`})"
n_notes = 0
for m in list(re.finditer(re.escape(k) + r",\(0,U\.jsx\)\(`div`,\{className:`[^`]*`,children:\(([a-z])\?\.available_methods", s))[::-1]:
    v = m.group(1)
    s = s[:m.start()] + f"(0,U.jsx)(FmtChargeNote,{{amount:{v}?.amount}})," + s[m.start():]
    n_notes += 1
assert n_notes == 7, n_notes
# Phase 1 admin screens (admin_settings.src.js): tax & invoices, message
# templates, website pages, failed bookings, GST register, security and
# system health; each user's own two-factor setup; legal pages that show the
# admin-edited text when one is published; and real Razorpay / Stripe
# checkout before every booking payment.
s = s.replace('function Use(){', open(os.path.join(HERE, 'admin_settings.src.js'), encoding='utf-8').read().strip() + '\nfunction Use(){', 1)
for old_ok, new_ok in [
    ("(0,U.jsx)(H,{path:`/platform-settings`,element:(0,U.jsx)(Soe,{})})",
     "(0,U.jsx)(H,{path:`/platform-settings`,element:(0,U.jsx)(Soe,{})}),(0,U.jsx)(H,{path:`/admin-settings`,element:(0,U.jsx)(FmtAdminSettings,{})}),(0,U.jsx)(H,{path:`/account-security`,element:(0,U.jsx)(FmtAccountSecurity,{})})"),
    ("{to:`/platform-settings`,label:`Platform settings`,icon:`⚙`,roles:[`super_admin`,`staff`]}",
     "{to:`/platform-settings`,label:`Platform settings`,icon:`⚙`,roles:[`super_admin`,`staff`]},{to:`/admin-settings`,label:`Admin settings`,icon:`🛠`,roles:[`super_admin`,`staff`,`partner_admin`]},{to:`/account-security`,label:`Login security`,icon:`🔐`,roles:[`super_admin`,`staff`,`partner_admin`,`agent`,`sub_agent`,`staff_user`,`api_user`]}"),
    # Sign-in: ask for the authenticator code when the account has 2FA on,
    # and send users who must set it up to the Login security page.
    ("async function Hf(e){let{data:t}=await K.post(`/auth/login`,e);return t}",
     "async function Hf(e){let t;try{t=(await K.post(`/auth/login`,e)).data}catch(n){if(n?.response?.status===422&&n.response.data?.two_factor_required&&!e.two_factor_code){let c=prompt(`Enter the 6-digit code from your authenticator app (or a recovery code):`);if(!c)throw n;return Hf({...e,two_factor_code:c.trim()})}throw n}try{t?.two_factor_setup_required?sessionStorage.setItem(`fmt_2fa_setup`,`1`):sessionStorage.removeItem(`fmt_2fa_setup`)}catch(_){}return t}"),
    ("l(i&&i.startsWith(`/`)?i:`/`)",
     "l((()=>{try{return sessionStorage.getItem(`fmt_2fa_setup`)===`1`}catch(_){return!1}})()?`/account-security`:i&&i.startsWith(`/`)?i:`/`)"),
    # Registered before the existing interceptor so it runs after it (axios
    # runs request interceptors last-registered first) and sees the currency.
    ("K.interceptors.request.use(e=>{try{if(String(e.method)",
     "K.interceptors.request.use(fmtGatewayCheckout);K.interceptors.request.use(e=>{try{if(String(e.method)"),
]:
    assert s.count(old_ok) == 1, old_ok[:60]
    s = s.replace(old_ok, new_ok, 1)
for comp, slug in [('jce', 'privacy-policy'), ('Mce', 'terms-and-conditions'), ('Nce', 'refund-policy'), ('Pce', 'cancellation-policy'), ('Fce', 'disclaimer'), ('Ice', 'about-us')]:
    old_ok = f"path:`/{slug}`,element:(0,U.jsx)({comp},{{}})"
    assert s.count(old_ok) == 1, old_ok
    s = s.replace(old_ok, f"path:`/{slug}`,element:(0,U.jsx)(FmtCmsPage,{{slug:`{slug}`,fallback:{comp}}},`{slug}`)", 1)
h = hashlib.sha256(s.encode()).hexdigest()[:8]
new_js = f'index-{h}.js'
if os.path.exists(OUT): shutil.rmtree(OUT)
os.makedirs(f'{OUT}/assets')
html = open(f'{SRC}/index.html', encoding='utf-8').read()
refs = set(re.findall(r'/assets/([^"\']+)', html))
assert js_name in refs and css_name in refs, refs
html = html.replace(js_name, new_js)
open(f'{OUT}/index.html', 'w', encoding='utf-8').write(html)
# Lazy chunks import the main bundle by file name, so give them the new
# name too (and new names of their own, so no stale cached copy is reused).
renamed = {}
for f in sorted(set(re.findall(r'`\./([A-Za-z0-9_.\-]+\.js)`', s))):
    c = open(f'{SRC}/assets/{f}', encoding='utf-8').read()
    if js_name in c:
        c = c.replace(js_name, new_js)
        base = f.rsplit('-', 1)[0]
        nf = f'{base}-{hashlib.sha256(c.encode()).hexdigest()[:8]}.js'
        open(f'{OUT}/assets/{nf}', 'w', encoding='utf-8').write(c)
        renamed[f] = nf
for old, nf in renamed.items():
    s = s.replace(f'`./{old}`', f'`./{nf}`')
assert js_name not in s
open(f'{OUT}/assets/{new_js}', 'w', encoding='utf-8').write(s)
# lazily imported chunks referenced from the main bundle
chunks = set(re.findall(r'assets/([A-Za-z0-9_.\-]+\.(?:js|css))', s)) | set(re.findall(r'"\./([A-Za-z0-9_.\-]+\.js)"', s)) | set(re.findall(r'`\./([A-Za-z0-9_.\-]+\.js)`', s))
for f in sorted(refs | chunks):
    if f == js_name or f in renamed: continue
    p = f'{SRC}/assets/{f}'
    if os.path.exists(p): shutil.copy(p, f'{OUT}/assets/{f}')
    elif f not in renamed.values(): print('missing chunk', f)
for f in ['favicon.png', 'apple-touch-icon.png', 'logo.png', 'logo-light.png', 'icons.svg']:
    shutil.copy(f'{SRC}/{f}', f'{OUT}/{f}')
# Stripe Checkout returns here (in the payment popup, or the main window).
shutil.copy(os.path.join(HERE, 'payment-return.html'), f'{OUT}/payment-return.html')
for d in ['data', 'payments', 'certifications']:
    shutil.copytree(f'{SRC}/{d}', f'{OUT}/{d}')
print('built', new_js, sorted(os.listdir(f'{OUT}/assets')))
