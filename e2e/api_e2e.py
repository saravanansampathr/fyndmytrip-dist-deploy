"""API end-to-end run: every user type books every service and the margin
charged is checked against the rules created at the start. Run via
e2e/run.sh (needs a backend served on $API_URL against the SQLite DB at
$E2E_DB, prepared by e2e/reset_db.sh)."""
import json, os, sqlite3, sys, urllib.request, datetime, decimal
BASE = os.environ.get('API_URL', 'http://127.0.0.1:8010') + '/api'
DB = os.environ['E2E_DB']
BACKEND = os.environ['BACKEND_DIR']
RESULTS = os.environ.get('RESULTS_DIR', '.')
results = []

def call(method, path, token=None, body=None, expect=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(BASE + path, data=data, method=method)
    req.add_header('Accept', 'application/json'); req.add_header('Content-Type', 'application/json')
    if token: req.add_header('Authorization', 'Bearer ' + token)
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            code, raw = r.status, r.read()
    except urllib.error.HTTPError as e:
        code, raw = e.code, e.read()
    try: js = json.loads(raw) if raw else None
    except Exception: js = {'raw': raw[:300].decode(errors='replace')}
    return code, js

def check(name, ok, detail=''):
    results.append((name, ok, detail))
    print(('PASS ' if ok else 'FAIL ') + name + ('' if ok else '  -> ' + str(detail)[:400]))

PASSWORDS = {}
def login(email):
    c, j = call('POST', '/auth/login', body={'email': email, 'password': PASSWORDS.get(email, 'password')})
    check(f'login {email}', c == 200 and j.get('token'), (c, j))
    return j.get('token') if j else None

d = lambda n: (datetime.date.today() + datetime.timedelta(days=n)).isoformat()

admin = login('admin@fyndmytrip.com')

# ---- margin rules (admin sets one per audience on ALL services) ----
pct = {'admin': 1, 'white_label': 2, 'api_partner': 3, 'staff': 4, 'agent': 5, 'sub_agent': 6, 'b2c': 7}
for aud, p in pct.items():
    c, j = call('POST', '/markup-rules', admin, {'module': 'all', 'rule_type': 'percentage', 'applies_to_role': aud, 'percentage': p, 'name': f'E2E {aud}'})
    check(f'admin creates all-services margin for {aud}', c == 201, (c, j))

c, opts = call('GET', '/markup-rules/options', admin)
check('admin options lists 8 audiences + all services', c == 200 and len(opts['data']['audiences']) == 8 and any(m['value'] == 'all' for m in opts['data']['modules']), (c, opts))
users = {u['email']: u for u in opts['data']['users']}
direct_agent = users['agent@fyndmytrip.com']
c, j = call('POST', '/markup-rules', admin, {'module': 'hotel', 'rule_type': 'flat_per_pnr', 'applies_to_role': 'agent', 'amount': 250, 'user_id': direct_agent['id'], 'name': 'E2E chosen agent hotels'})
check('admin creates hotel-only flat margin for one chosen agent user', c == 201 and j['data']['user']['id'] == direct_agent['id'], (c, j))
c, j = call('POST', '/markup-rules/preview', admin, {'module': 'hotel', 'net_amount': 10000, 'user_id': direct_agent['id']})
check('preview: chosen agent on hotel -> 250 flat', c == 200 and j['data']['markup_amount'] == '250.00', (c, j))
c, j = call('POST', '/markup-rules/preview', admin, {'module': 'flight', 'net_amount': 10000, 'user_id': direct_agent['id']})
check('preview: chosen agent on flight -> 5%', c == 200 and j['data']['markup_amount'] == '500.00', (c, j))

# customer forbidden
cust = login('customer@fyndmytrip.com')
c, j = call('POST', '/markup-rules', cust, {'module': 'all', 'rule_type': 'percentage', 'applies_to_role': 'b2c', 'percentage': 50})
check('direct customer blocked from creating margins (403)', c == 403, (c, j))

# partner admin creates a staff_user login (exercises tenant user management)
pa = login('admin@acmetravel.fyndmytrip.com')
c, j = call('POST', '/tenants/2/users', pa, {'name': 'Acme Desk Staff', 'email': 'desk@acmetravel.fyndmytrip.com', 'password': 'password', 'password_confirmation': 'password', 'role': 'staff_user'})
check('white-label admin creates a staff user', c in (200, 201), (c, j))
PASSWORDS['desk@acmetravel.fyndmytrip.com'] = (j or {}).get('temporary_password', 'password')
import subprocess
subprocess.run(['php', 'artisan', 'tinker', '--execute', '$u=App\\Models\\User::where("email","desk@acmetravel.fyndmytrip.com")->first(); $w=App\\Domains\\Wallet\\Models\\Wallet::firstOrCreate(["tenant_id"=>$u->tenant_id,"owner_type"=>"user","owner_id"=>$u->id]); app(App\\Domains\\Wallet\\Services\\WalletService::class)->credit($w,"500000.00","topup");'],
    cwd=BACKEND, env={**os.environ, 'DB_CONNECTION': 'sqlite', 'DB_DATABASE': DB}, check=False, capture_output=True)

# white-label admin manages its own margins + targets its own staff user
c, j = call('POST', '/markup-rules', pa, {'module': 'flight', 'rule_type': 'percentage', 'applies_to_role': 'staff', 'percentage': 1.5, 'name': 'WL desk flights'})
check('white-label admin creates own margin', c == 201 and j['data']['tenant_id'] == 2, (c, j))
c, j = call('POST', '/markup-rules', pa, {'module': 'all', 'rule_type': 'percentage', 'applies_to_role': 'b2c', 'percentage': 3, 'user_id': 1})
check('white-label admin cannot target a Master user (422)', c == 422, (c, j))

sa = login('subagent@acmetravel.fyndmytrip.com')
c, j = call('GET', '/markup-rules', sa)
check('sub-agent can view margins', c == 200, (c, j))
c, j = call('POST', '/markup-rules', sa, {'module': 'all', 'rule_type': 'percentage', 'applies_to_role': 'b2c', 'percentage': 1})
check('sub-agent cannot change margins (403)', c == 403, (c, j))

# top up the new staff user's wallet directly
con = sqlite3.connect(DB)

# ---- booking matrix ----
def flight(t):
    c, j = call('POST', '/flights/search', t, {'origin': 'DEL', 'destination': 'BOM', 'departure_date': d(10)})
    o = j['data']['legs'][0]['offers'][0]
    return '/flights/bookings', {'legs': [{'supplier_code': o['supplier_code'], 'external_id': o['external_id'], 'segments': o['segments']}], 'travel_date': d(10), 'passengers': [{'first_name': 'Jane', 'last_name': 'Doe', 'gender': 'female'}]}
def hotel(t):
    c, j = call('POST', '/hotels/search', t, {'city': 'Goa', 'check_in_date': d(10), 'check_out_date': d(12), 'rooms': 1, 'guests': 1})
    o = j['data']['offers'][0]
    return '/hotels/bookings', {'supplier_code': o['supplier_code'], 'external_id': o['external_id'], 'segment': o['segments'][0], 'check_in_date': d(10), 'check_out_date': d(12), 'rooms': 1, 'guests': [{'name': 'Jane Doe', 'age': 30}]}
def bus(t):
    c, j = call('POST', '/bus/search', t, {'origin': 'Chennai', 'destination': 'Bengaluru', 'departure_date': d(5)})
    o = j['data']['offers'][0]
    c, m = call('POST', '/bus/seatmap', t, {'supplier_code': o['supplier_code'], 'external_id': o['external_id'], 'travel_date': d(5), 'bus_type': o['bus_type'], 'seats_available': o['seats_available']})
    seat = next(s['seat_number'] for r in m['data']['rows'] for s in r['seats'] if s['state'] == 'available')
    return '/bus/bookings', {'supplier_code': o['supplier_code'], 'external_id': o['external_id'], 'segment': o['segments'][0], 'travel_date': d(5), 'bus_type': o['bus_type'], 'seats_available': o['seats_available'],
        'boarding_point': (o.get('boarding_points') or [{}])[0].get('point', o.get('boarding_point')), 'dropping_point': (o.get('dropping_points') or [{}])[0].get('point', o.get('dropping_point')),
        'passengers': [{'name': 'John Doe', 'seat_number': seat, 'gender': 'male', 'title': 'Mr', 'age': 30}]}
def cab(t):
    c, j = call('POST', '/cabs/search', t, {'origin': 'Chennai', 'destination': 'Pondicherry', 'travel_date': d(6), 'passengers': 1})
    o = j['data']['offers'][0]
    return '/cabs/bookings', {'supplier_code': o['supplier_code'], 'external_id': o['external_id'], 'segment': o['segments'][0], 'travel_date': d(6), 'passengers': [{'name': 'Jane Doe', 'age': 30}]}
def cruise(t):
    c, j = call('POST', '/cruises/search', t, {'departure_port': 'Chennai', 'arrival_port': 'Colombo', 'sail_date': d(20), 'travellers': 1})
    o = j['data']['offers'][0]
    return '/cruises/bookings', {'supplier_code': o['supplier_code'], 'external_id': o['external_id'], 'segment': o['segments'][0], 'sail_date': d(20), 'passengers': [{'name': 'Jane Doe'}]}
def activity(t):
    c, j = call('POST', '/activities/search', t, {'city': 'Goa', 'activity_date': d(8), 'travellers': 1})
    o = j['data']['offers'][0]
    return '/activities/bookings', {'supplier_code': o['supplier_code'], 'external_id': o['external_id'], 'segment': o['segments'][0], 'activity_date': d(8), 'passengers': [{'name': 'Jane Doe'}]}
def insurance(t):
    c, j = call('POST', '/insurance/search', t, {'destination': 'Thailand', 'trip_start_date': d(15), 'trip_end_date': d(20), 'travellers': 1})
    o = j['data']['offers'][0]
    return '/insurance/bookings', {'supplier_code': o['supplier_code'], 'external_id': o['external_id'], 'segment': o['segments'][0], 'destination': 'Thailand', 'trip_start_date': d(15), 'trip_end_date': d(20), 'passengers': [{'name': 'Jane Doe'}]}
modules = {'flight': flight, 'hotel': hotel, 'bus': bus, 'cab': cab, 'cruise': cruise, 'activity': activity, 'insurance': insurance}

people = [
    ('admin@fyndmytrip.com', 'admin'), ('staff@fyndmytrip.com', 'admin'), ('customer@fyndmytrip.com', 'b2c'),
    ('admin@acmetravel.fyndmytrip.com', 'white_label'), ('agent@acmetravel.fyndmytrip.com', 'agent'),
    ('subagent@acmetravel.fyndmytrip.com', 'sub_agent'), ('agent@fyndmytrip.com', 'agent'),
    ('api@fyndmytrip.com', 'api_partner'), ('desk@acmetravel.fyndmytrip.com', 'staff'),
]
D = decimal.Decimal
for email, aud in people:
    tok = login(email)
    if not tok: continue
    # make sure wallet has funds (staff user is new)
    for mod, fn in modules.items():
        name = f'{email} books {mod}'
        try:
            path, body = fn(tok)
            c, j = call('POST', path, tok, body)
            if c != 201: check(name + ' (hold)', False, (c, j)); continue
            bid = j['data']['id']
            row = con.execute('select gross_amount, extras_amount, discount_amount, supplier_net_amount, markup_amount from bookings where id=?', (bid,)).fetchone()
            total = D(str(row[0])) + D(str(row[1] or 0)) - D(str(row[2] or 0))
            if email.startswith('desk@'):
                w = con.execute("select id from wallets where owner_type='user' and owner_id=(select id from users where email=?)", (email,)).fetchone()
            c, j = call('POST', f'{path}/{bid}/confirm', tok, {'wallet_amount': str(total)})
            status = (j or {}).get('data', {}).get('status') if isinstance(j, dict) else None
            # expected margin
            net, mk = D(str(row[3])), D(str(row[4]))
            seeded_b2c = {'flight': 6, 'hotel': 12, 'bus': 8, 'cab': 10, 'cruise': 11, 'activity': 18, 'insurance': 15}
            if email == 'agent@fyndmytrip.com' and mod == 'hotel': exp = D('250.00')
            elif aud == 'b2c': exp = (net * seeded_b2c[mod] / 100).quantize(D('0.01'))
            elif email.startswith('desk@') and mod == 'flight': exp = (net * D('1.5') / 100).quantize(D('0.01'))
            else: exp = (net * pct[aud] / 100).quantize(D('0.01'))
            ok_margin = abs(mk - exp) <= D('0.02') * max(1, 1)
            check(name + f' -> {status}', c == 200 and status == 'ticketed', (c, j))
            check(name + f' margin {mk} == expected {exp} ({aud})', ok_margin, (net, mk, exp))
        except Exception as e:
            check(name, False, repr(e))
    for p in ['/my-bookings', '/my-payments', '/wallet', '/recent-searches?module=flight']:
        c, j = call('GET', p, tok)
        check(f'{email} GET {p}', c == 200, (c, j))


# ---- wider product surface ----
def call_h(method, path, token=None, body=None, headers=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(BASE + path, data=data, method=method)
    req.add_header('Accept', 'application/json'); req.add_header('Content-Type', 'application/json')
    for k, v in (headers or {}).items(): req.add_header(k, v)
    if token: req.add_header('Authorization', 'Bearer ' + token)
    try:
        with urllib.request.urlopen(req, timeout=60) as r: return r.status, r.read(), r.headers.get('Content-Type', '')
    except urllib.error.HTTPError as e: return e.code, e.read(), ''

c, raw, _ = call_h('GET', '/whitelabel/current', headers={'X-Storefront-Host': 'acmetravel.fyndmytrip.com'})
check('white-label storefront resolves by host (acmetravel)', c == 200 and b'acme' in raw.lower(), (c, raw[:200]))
c, raw, _ = call_h('GET', '/whitelabel/current')
check('master storefront resolves', c == 200, (c, raw[:200]))

c, j = call('GET', '/packages')
pk = (j or {}).get('data') or []
check('public holiday package listing', c == 200 and len(pk) > 0, (c, str(j)[:200]))
if pk:
    slug = pk[0]['slug']
    c, j = call('GET', f'/packages/{slug}')
    check('public holiday package detail', c == 200, (c, str(j)[:200]))
    for email in ['customer@fyndmytrip.com', 'agent@fyndmytrip.com']:
        t = login(email)
        c, j = call('POST', f'/packages/{slug}/bookings', t, {'travel_date': d(30), 'travelers': [{'name': 'Jane Doe', 'occupancy_type': 'adult_sharing'}, {'name': 'John Doe', 'occupancy_type': 'adult_sharing'}]})
        check(f'{email} books a holiday package', c in (200, 201), (c, str(j)[:300]))

c, j = call('GET', '/visa')
check('public visa catalogue', c == 200, (c, str(j)[:200]))

for email in ['admin@fyndmytrip.com', 'admin@acmetravel.fyndmytrip.com', 'agent@fyndmytrip.com']:
    t = login(email)
    c, j = call('GET', '/reports/summary', t)
    check(f'{email} reports summary', c == 200, (c, str(j)[:200]))
    c, j = call('GET', '/reports/bookings', t)
    check(f'{email} reports bookings list', c == 200, (c, str(j)[:200]))

cust = login('customer@fyndmytrip.com')
bid = con.execute("select id from bookings where status='ticketed' and created_by_user_id=(select id from users where email='customer@fyndmytrip.com') limit 1").fetchone()[0]
c, raw, ct = call_h('GET', f'/bookings/{bid}/invoice.pdf', cust)
check('customer downloads invoice PDF', c == 200 and raw[:4] == b'%PDF', (c, raw[:80]))

admin = login('admin@fyndmytrip.com')
c, j = call('GET', f'/reports/bookings/{bid}/cancellation-quote', admin)
check('admin cancellation quote', c == 200, (c, str(j)[:200]))
total = con.execute('select total_amount from bookings where id=?', (bid,)).fetchone()[0]
c, j = call('POST', f'/reports/bookings/{bid}/refunds', admin, {'method': 'wallet', 'amount': str(min(D(str(total)), D('100.00')))})
check('admin issues wallet refund on customer booking', c in (200, 201), (c, str(j)[:300]))

agent_t = login('agent@fyndmytrip.com')
c, j = call('GET', f'/reports/bookings/{bid}', agent_t)
check("agent cannot open a Master customer's booking", c in (403, 404), (c, str(j)[:200]))

c, j = call('GET', '/markup-rules', admin)
check('admin lists margins with owner/user/account details', c == 200 and all('owner' in r for r in j['data']), (c, str(j)[:300]))

fails = [r for r in results if not r[1]]
print(f'\n{len(results) - len(fails)} passed, {len(fails)} failed of {len(results)} checks')
json.dump([{'check': n, 'ok': bool(o), 'detail': str(dd)[:300]} for n, o, dd in results], open(os.path.join(RESULTS, 'api_results.json'), 'w'), indent=1)
sys.exit(1 if fails else 0)
