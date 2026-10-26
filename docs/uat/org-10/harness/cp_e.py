"""ORG.10-E: Inventory + Transport + Library + Operations (real HTTP)."""
import sys, os, re, json, datetime
from decimal import Decimal as D
sys.path.insert(0, os.path.dirname(__file__))
from uat import Session, Register, fixture, once, state

fx = fixture()
A = fx['schools']['A']
A1, A2 = fx['campuses']['A1'], fx['campuses']['A2']
R = Register('ORG.10-E')
S = {}
TODAY = datetime.date.today().isoformat()
MOVER, LEAVER, NORTH = state('mover'), state('leaver'), state('northfield')


def s(email, school=A):
    if email not in S:
        S[email] = Session(email, school).login()
    else:
        S[email].activate(school)
    return S[email]


def data(x):
    return x.get('data', x) if isinstance(x, dict) else x


# ---------------------------------------------------------------- Inventory
st = s('canteen.operator@example.test')  # stores_officer + canteen_operator (whole School)
items = {i['code']: i['id'] for i in data(st.get('/app/inventory-items').props['items'])}
locs = {l['code']: l for l in data(st.get('/app/inventory-locations').props['locations'])}
if 'UAT-NORTH' not in locs and once('inv-loc-north'):
    st.post('/app/inventory-locations', {'code': 'UAT-NORTH', 'name': 'North Campus store', 'campus_id': A2})
    locs = {l['code']: l for l in data(st.get('/app/inventory-locations').props['locations'])}
MAIN, NORTHL, CHALK = locs['MAIN-STORE']['id'], locs['UAT-NORTH']['id'], items['CHALK']


def bal(item, loc):
    for b in data(st.get('/app/inventory-stock?balancesPage=1').props['balances']):
        if b.get('itemCode') == 'CHALK' and b.get('locationCode') == loc:
            return D(b['quantityOnHand'])
    return D(0)


b0 = {'MAIN': bal(CHALK, 'MAIN-STORE'), 'NORTH': bal(CHALK, 'UAT-NORTH')}
dept = st.get('/app/inventory-stock/search/departments?q=Adm').json()['data'][0]['id']
if once('inv-ops'):
    errs = []
    errs.append(st.errors(st.post('/app/inventory-stock/receive', {'inventory_item_id': CHALK, 'inventory_location_id': MAIN, 'quantity': '20'})))
    k = st.get('/app/inventory-stock/issue').props['idempotencyKey']
    errs.append(st.errors(st.post('/app/inventory-stock/issue', {'inventory_item_id': CHALK, 'inventory_location_id': MAIN, 'quantity': '3', 'recipient_department_id': dept, 'idempotency_key': k})))
    replay = st.post('/app/inventory-stock/issue', {'inventory_item_id': CHALK, 'inventory_location_id': MAIN, 'quantity': '3', 'recipient_department_id': dept, 'idempotency_key': k})
    errs.append(st.errors(st.post('/app/inventory-stock/transfer', {'inventory_item_id': CHALK, 'from_location_id': MAIN, 'to_location_id': NORTHL, 'quantity': '5'})))
    wk = st.get('/app/inventory-stock/write-off').props['idempotencyKey']
    nomfa = st.errors(st.post('/app/inventory-stock/write-off', {'inventory_item_id': CHALK, 'inventory_location_id': MAIN, 'quantity': '1', 'reason_code': 'damaged', 'confirm': 1, 'idempotency_key': wk}))
    noconfirm = st.errors(st.post('/app/inventory-stock/write-off', {'inventory_item_id': CHALK, 'inventory_location_id': MAIN, 'quantity': '1', 'reason_code': 'damaged', 'idempotency_key': wk, 'mfa_code': st.mfa()}))
    wk = st.get('/app/inventory-stock/write-off').props['idempotencyKey']
    errs.append(st.errors(st.post('/app/inventory-stock/write-off', {'inventory_item_id': CHALK, 'inventory_location_id': MAIN, 'quantity': '1', 'reason_code': 'damaged', 'confirm': 1, 'idempotency_key': wk, 'mfa_code': st.mfa()})))
    over = st.errors(st.post('/app/inventory-stock/issue', {'inventory_item_id': CHALK, 'inventory_location_id': NORTHL, 'quantity': '999', 'idempotency_key': st.get('/app/inventory-stock/issue').props['idempotencyKey']}))
    state('inv', {'b0': {k_: str(v) for k_, v in b0.items()}, 'errs': errs, 'nomfa': nomfa, 'noconfirm': noconfirm, 'over': over, 'replay': replay.status})
inv = state('inv')
b1 = {'MAIN': bal(CHALK, 'MAIN-STORE'), 'NORTH': bal(CHALK, 'UAT-NORTH')}
exp_main = D(inv['b0']['MAIN']) + 20 - 3 - 5 - 1
exp_north = D(inv['b0']['NORTH']) + 5
R.record('UAT-INV-001', 'inventory', 'stores_officer', 'A', 'whole-School', f"CHALK Main {inv['b0']['MAIN']}", 'receive 20, issue 3 to Administration (replayed once), transfer 5 to North, write-off 1',
         'Balances = previous + movements; replayed issue counted once', (inv['errs'], str(b1['MAIN']), str(exp_main), str(b1['NORTH']), str(exp_north)), not any(inv['errs']) and b1['MAIN'] == exp_main and b1['NORTH'] == exp_north)
R.record('UAT-INV-002', 'inventory', 'stores_officer', 'A', 'whole-School', '-', 'write-off without MFA; without confirmation', 'Write-off needs fresh MFA AND confirmation (direct-assured)', (inv['nomfa'], inv['noconfirm']), bool(inv['nomfa']) and bool(inv['noconfirm']))
R.record('UAT-INV-003', 'inventory', 'stores_officer', 'A', 'whole-School', 'North holds 5', 'issue 999 from North', 'Sufficiency refused; never negative stock', inv['over'], bool(inv['over']))
mv = data(st.get('/app/inventory-stock?movementsPage=1').props['movements'])
today_chalk = [m for m in mv if m.get('itemCode') == 'CHALK' or 'CHALK' in json.dumps(m)][:6]
issue = next((m for m in today_chalk if m.get('movementType') == 'issue'), {})
wo = next((m for m in today_chalk if m.get('movementType') == 'write_off'), {})
R.record('UAT-INV-004', 'inventory', 'stores_officer', 'A', 'whole-School', '-', 'movement list', 'Issue carries the Department only; write-off carries its closed reason; no person on a movement',
         (issue.get('recipientDepartment'), wo.get('writeOffReason'), sorted(k for k in issue.keys() if any(p in k.lower() for p in ['user', 'actor', 'person', 'employee']))), issue.get('recipientDepartment') and wo.get('writeOffReason') and not any(p in json.dumps(list(issue.keys())).lower() for p in ['actor', 'userid', 'employee']))
mon = st.get(f'/app/inventory-stock/monthly?month={TODAY[:7]}&item=CHALK').props['summary']
rows = data(mon.get('rows') or [])
ch = next((r for r in rows if 'CHALK' in json.dumps(r)), {})
nums = {k: ch.get(k) for k in ch if any(w in k.lower() for w in ['opening', 'in', 'out', 'closing'])}
vals = {k.lower(): D(str(v)) for k, v in nums.items() if v is not None and re.match(r'^-?\d', str(v))}
opening = next((v for k, v in vals.items() if 'opening' in k), None)
closing = next((v for k, v in vals.items() if 'closing' in k), None)
qin = next((v for k, v in vals.items() if k.endswith('in') or k == 'inquantity' or k == 'received'), None)
qout = next((v for k, v in vals.items() if 'out' in k), None)
R.record('UAT-INV-005', 'inventory', 'stores_officer', 'A', 'whole-School', 'whole-School set', f'month view {TODAY[:7]} item CHALK', 'opening + in - out = closing; closing = sum of current balances; transfer inside the set nets to 0',
         (nums, str(b1['MAIN'] + b1['NORTH'])), closing is not None and opening is not None and qin is not None and qout is not None and opening + qin - qout == closing and closing == b1['MAIN'] + b1['NORTH'])
reg = st.get(f'/app/inventory-stock/register?location_id={NORTHL}&from={TODAY}&to={TODAY}').props
R.record('UAT-INV-006', 'inventory', 'stores_officer', 'A', 'whole-School', '-', 'register filtered to the North store', 'Register narrows to the Location (filter, not permission)', [(r.get('movementType'), r.get('quantity')) for r in data(reg['rows'])], reg is not None and all('UAT-NORTH' in json.dumps(r) for r in data(reg['rows'])))
r = st.request('PATCH', f'/app/inventory-locations/{MAIN}', {'code': 'MAIN-STORE', 'name': locs['MAIN-STORE'].get('name', 'Main store'), 'campus_id': A2})
after = {l['code']: l for l in data(st.get('/app/inventory-locations').props['locations'])}['MAIN-STORE'].get('campusId')
R.record('UAT-INV-007', 'inventory', 'stores_officer', 'A', 'whole-School', 'MAIN-STORE has operational history', 'PATCH campus_id to North', 'H1R: a used Location\'s Campus is frozen', (r.status, st.errors(r) if r.status == 302 else '', after == locs['MAIN-STORE'].get('campusId')), after == locs['MAIN-STORE'].get('campusId'))

# ---------------------------------------------------------------- Transport
tr = s('transport.operator@example.test')
routes = {x['code']: x for x in data(tr.get('/app/transport/routes').props['routes'])}
if 'UAT-R1' not in routes and once('trn-route'):
    r = tr.post('/app/transport/routes', {'code': 'UAT-R1', 'name': 'UAT Main route', 'campus_id': A1})
    rid = re.search(r'/routes/([0-9a-f-]{36})', r.location).group(1)
    for seq, name in [(1, 'Gate'), (2, 'Market')]:
        tr.post(f'/app/transport/routes/{rid}/stops', {'name': name, 'sequence': seq})
    state('route', rid)
rid = state('route')
stops = tr.get(f'/app/transport/routes/{rid}').props['stops']
riders = lambda: data(tr.get('/app/transport/assignments').props.get('assignments') or {})
if once('trn-assign'):
    r = tr.post('/app/transport/assignments', {'student_id': MOVER['id'], 'route_id': rid, 'pickup_stop_id': stops[0]['id'], 'dropoff_stop_id': stops[1]['id']})
    state('trnAssignErr', tr.errors(r))
mine = [a for a in riders() if MOVER['name'] in json.dumps(a)]
R.record('UAT-TRN-001', 'transport', 'transport_coordinator', 'A', 'whole-School', 'route UAT-R1 on Main, stops 1-2', 'assign MOVER', 'Single rider assignment active', (state('trnAssignErr'), len(mine)), len(mine) >= 1)
north_no = (NORTH.get('studentNumber') or NORTH.get('student_number') or '1')
csv = ('student_number,route_code,pickup_stop,dropoff_stop\n'
       'LDS-0003,UAT-R1,1,2\nLDS-0003,UAT-R1,1,2\nLDS-0001,UAT-R1,1,2\nLDS-0004,UAT-R1,99,2\n' + f'{north_no},UAT-R1,1,2\n').encode()
before = len(riders())
pv = tr.post('/app/transport/assignments/import/preview', {}, files={'file': ('riders.csv', csv, 'text/csv')})
prev = pv.props.get('preview') or {}
outcomes = [x.get('outcome') or x.get('status') for x in prev.get('rows') or []]
after_preview = len(riders())
R.record('UAT-TRN-002', 'transport', 'transport_coordinator', 'A', 'whole-School', 'mixed CSV: valid, repeated, already assigned, invalid stop, wrong-Campus Student', 'preview',
         'Each row classified; not committable; nothing written by preview', (pv.status, outcomes, prev.get('committable'), before, after_preview), prev.get('committable') is False and before == after_preview and 'ready' in outcomes)
ok_csv = b'student_number,route_code,pickup_stop,dropoff_stop\nLDS-0003,UAT-R1,1,2\nLDS-0004,UAT-R1,1,2\nLDS-0001,UAT-R1,1,2\n'
pv2 = tr.post('/app/transport/assignments/import/preview', {}, files={'file': ('ok.csv', ok_csv, 'text/csv')}).props.get('preview') or {}
c1 = tr.post('/app/transport/assignments/import/commit', {'payload': pv2.get('payload'), 'issued_at': pv2.get('issuedAt'), 'signature': pv2.get('signature')}, as_json=True)
n1 = len(riders())
c2 = tr.post('/app/transport/assignments/import/commit', {'payload': pv2.get('payload'), 'issued_at': pv2.get('issuedAt'), 'signature': pv2.get('signature')}, as_json=True)
n2 = len(riders())
R.record('UAT-TRN-003', 'transport', 'transport_coordinator', 'A', 'whole-School', '2 ready + 1 already assigned', 'commit; commit the same signed preview again', 'All-or-nothing commit adds 2; the retry adds nothing (add-only, safe)',
         (pv2.get('committable'), c1.status, after_preview, n1, c2.status, n2), pv2.get('committable') and n1 == after_preview + 2 and n2 == n1)
tampered = dict(pv2)
pl = [dict(x) for x in (pv2.get('payload') or [])]
if pl:
    pl[0]['student_number'] = 'LDS-0005'
c3 = tr.post('/app/transport/assignments/import/commit', {'payload': pl, 'issued_at': pv2.get('issuedAt'), 'signature': pv2.get('signature')}, as_json=True)
R.record('UAT-TRN-004', 'transport', 'transport_coordinator', 'A', 'whole-School', 'signed preview', 'commit a tampered payload', 'Signature binds the rows: refused, nothing written', (c3.status, len(riders()) == n2), c3.status in (302, 403, 422) and len(riders()) == n2)

# ---------------------------------------------------------------- Library
lb = s('library.operator@example.test')
la1 = s('uat.librarian.a1@example.test')
if once('lib-setup'):
    r = lb.post('/app/library/titles', {'title': 'UAT Field Guide', 'author': 'UAT'})
    tid = re.search(r'/titles/([0-9a-f-]{36})', r.location).group(1)
    lb.post(f'/app/library/titles/{tid}/copies', {'code': 'UAT-LIB-A1', 'campus_id': A1})
    lb.post(f'/app/library/titles/{tid}/copies', {'code': 'UAT-LIB-A2', 'campus_id': A2})
    state('title', tid)
copies = {c['code']: c for c in lb.get(f"/app/library/titles/{state('title')}").props['copies']}
due = (datetime.date.today() + datetime.timedelta(days=14)).isoformat()
if once('lib-checkout'):
    r1 = la1.post('/app/library/circulation', {'library_copy_id': copies['UAT-LIB-A1']['id'], 'student_id': MOVER['id'], 'due_at': due})
    r2 = la1.post('/app/library/circulation', {'library_copy_id': copies['UAT-LIB-A2']['id'], 'student_id': MOVER['id'], 'due_at': due})
    state('libErrs', [la1.errors(r1), la1.errors(r2)])
e = state('libErrs')
loans = data(la1.get(f"/app/library/circulation?status=all&student={MOVER['number']}").props['loans'])
R.record('UAT-LIB-001', 'library', 'librarian@A1', 'A', 'Campus A1', 'copies on Main and North', 'check out Main copy; check out North copy', 'Main copy lends; North copy refused generically (Campus)', (e, [l.get('copyCode') for l in loans]), not e[0] and bool(e[1]) and any(l.get('copyCode') == 'UAT-LIB-A1' for l in loans))
loan = next((l for l in loans if l.get('copyCode') == 'UAT-LIB-A1' and not l.get('returnedAt')), None)
state('loanA1', loan and loan['id'])
act = data(lb.get('/app/library/circulation?status=active').props['loans'])
R.record('UAT-LIB-002', 'library', 'librarian', 'A', 'whole-School', 'active loans', 'active list', 'Active loans list with overdue flag (none overdue: due in 14 days)', [(l.get('copyCode'), l.get('isOverdue')) for l in act if 'UAT' in (l.get('copyCode') or '')], all(not l.get('isOverdue') for l in act if 'UAT' in (l.get('copyCode') or '')))
# Check-in happens in ORG.10-G after the Campus move (history follows the loan Campus).

# ---------------------------------------------------------------- Visitors, Hostel, Canteen (operational smoke)
rc = s('reception@example.test')
vis = data(rc.get('/app/visitor/directory').props.get('visitors') or {})
vcreate = rc.get('/app/visitor/visits/create').props
R.record('UAT-OPS-001', 'visitors', 'front_office', 'A', 'whole-School', '-', 'visitor directory + check-in form', 'Front office reaches directory and check-in with Campus choices', (len(vis), [c.get('name') for c in vcreate.get('campuses') or []]), vcreate.get('campuses') is not None)
hw = s('hostel.warden@example.test')
hr_ = hw.get('/app/hostel-residency?status=active')
R.record('UAT-OPS-002', 'hostel', 'hostel_warden', 'A', 'whole-School', '-', 'residency list', 'Hostel warden reads residencies', hr_.status, hr_.status == 200)
co = st.get('/app/canteen-orders/create').props
R.record('UAT-OPS-003', 'canteen', 'canteen_operator', 'A', 'whole-School', '-', 'order form', 'Canteen order form offers outlets', [o.get('code') for o in co.get('outlets') or []], bool(co.get('outlets')))
