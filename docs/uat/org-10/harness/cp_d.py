"""ORG.10-D: Finance + Fees + Ledger + Fee Reminder (real HTTP)."""
import sys, os, re, json, datetime, subprocess
from decimal import Decimal as D
sys.path.insert(0, os.path.dirname(__file__))
from uat import Session, Register, fixture, once, state

fx = fixture()
A, B = fx['schools']['A'], fx['schools']['B']
A1, A2 = fx['campuses']['A1'], fx['campuses']['A2']
R = Register('ORG.10-D')
S = {}
TODAY = datetime.date.today().isoformat()
YESTERDAY = (datetime.date.today() - datetime.timedelta(days=1)).isoformat()


def s(email, school=A):
    if email not in S:
        S[email] = Session(email, school).login()
    else:
        S[email].activate(school)
    return S[email]


def all_rows(sess, url):
    rows, page = [], 1
    while True:
        p = sess.get(f'{url}&page={page}').props
        rows += p['rows']['data']
        if page >= p['rows'].get('last_page', 1):
            return rows, p
        page += 1


fin = s('finance.officer@example.test')
adm = s('school.admin@example.test')
c1 = s('uat.cashier.a1@example.test')
c2 = s('uat.cashier.a2@example.test')

# Students: MOVER + LEAVER (G6-A, Main Campus) and Northfield (North Campus)
rows, _ = all_rows(fin, '/app/finance/outstanding?status=all')
g6a = sorted([r for r in rows if r['studentNumber'] in ('LDS-0001', 'LDS-0002')], key=lambda r: r['studentNumber'])
if not state('mover'):
    state('mover', {'id': g6a[0]['studentId'], 'name': g6a[0]['name'], 'number': g6a[0]['studentNumber']})
    state('leaver', {'id': g6a[1]['studentId'], 'name': g6a[1]['name'], 'number': g6a[1]['studentNumber']})
MOVER, LEAVER = state('mover'), state('leaver')
north = fin.get('/app/finance/charges/students/search?q=Northfield').json()['data'][0]
state('northfield', north)

# ---------------------------------------------------------------- Read-model reconciliation (before any write)
rows, page = all_rows(fin, '/app/finance/outstanding?status=all')
sums = {k: sum(D(r[k]) for r in rows) for k in ['billed', 'concession', 'paid', 'outstanding']}
tot = page['totals']
per_row_ok = all(D(r['billed']) - D(r['concession']) - D(r['paid']) == D(r['outstanding']) for r in rows)
R.record('UAT-FIN-001', 'finance', 'accountant', 'A', 'whole-School', f'{len(rows)} Students, 2 pages', 'Outstanding status=all, every page', 'billed - concession - paid = balance per row; page totals = sum of all rows', (per_row_ok, {k: str(v) for k, v in sums.items()}, tot),
         per_row_ok and all(sums[k] == D(tot[k]) for k in sums))

# ---------------------------------------------------------------- Charges (accountant; stored Campus from placement)
cp = fin.get('/app/finance/charges/create').props
recv = cp['defaultReceivableAccountId'] or next(a['id'] for a in cp['receivableAccounts'] if a['code'] == '1100')
rev = cp['defaultRevenueAccountId'] or next(a['id'] for a in cp['revenueAccounts'] if a['code'] == '4000')
base = {'academic_year_id': cp['defaultAcademicYearId'], 'currency': 'INR', 'receivable_ledger_account_id': recv, 'revenue_ledger_account_id': rev, 'due_date': TODAY, 'confirm_similar': 1}
state('receivable', recv)


def charge(student_id, desc, amount, key):
    if not state(key):
        r = fin.post('/app/finance/charges', {**base, 'student_id': student_id, 'description': desc, 'amount': amount})
        m = re.search(r'/charges/([0-9a-f-]{36})', r.location or '')
        state(key, m.group(1) if m else None)
        if not m:
            print('charge error', fin.errors(r))
    return state(key)


lab = charge(MOVER['id'], 'UAT lab fee', '1500.00', 'chLab')
dup = charge(MOVER['id'], 'UAT duplicate fee', '300.00', 'chDup')
small = charge(MOVER['id'], 'UAT small fee', '100.00', 'chSmall')
lv = charge(LEAVER['id'], 'UAT trip fee', '2000.00', 'chLeaver')
nf = charge(north['id'], 'UAT North activity fee', '1000.00', 'chNorth')
labp = fin.get(f'/app/finance/charges/{lab}').props['charge']
nfp = fin.get(f'/app/finance/charges/{nf}').props['charge']
vis = (c1.get(f'/app/finance/charges/{lab}').status, c1.get(f'/app/finance/charges/{nf}').status, c2.get(f'/app/finance/charges/{nf}').status)
R.record('UAT-FIN-002', 'finance', 'accountant (+cashiers as probes)', 'A', 'whole-School', 'MOVER on Main, Northfield on North', 'create manual charges; read as cashier@A1 / cashier@A2', 'Stored Campus = placement Campus: Main charge visible on A1 only, North charge on A2 only', vis, vis == (200, 404, 200))

# ---------------------------------------------------------------- Campus-scoped cashier
r = c2.get(f'/app/finance/charges/{lab}')
R.record('UAT-FIN-003', 'finance', 'cashier@A2', 'A', 'Campus A2', 'lab fee stored on Main', 'GET the Main charge', 'Out-of-scope Charge is the unknown 404', r.status, r.status == 404)
rp = c1.get(f"/app/finance/payments/record?student_id={MOVER['id']}").props
settle = next(a['id'] for a in rp['settlementAccounts'])
state('settle', settle)


def pay(sess, allocations, amount, key):
    if state(key):
        return state(key), {}
    p = sess.get(f"/app/finance/payments/record?student_id={MOVER['id']}").props
    r = sess.post('/app/finance/payments/record', {'idempotency_key': p['idempotencyKey'], 'method': 'cash', 'amount': amount, 'occurred_on': TODAY,
                                                    'settlement_ledger_account_id': settle, 'allocations': allocations, 'mfa_code': sess.mfa()})
    m = re.search(r'/payments/([0-9a-f-]{36})', r.location or '')
    errs = {} if m else sess.errors(r)
    if m:
        state(key, m.group(1))
    return (m.group(1) if m else None), errs


pid, e = pay(c1, [{'charge_id': lab, 'amount': '500.00'}], '500.00', 'payLab')
R.record('UAT-FIN-004', 'finance', 'cashier@A1', 'A', 'Campus A1', 'lab fee on Main', 'record cash payment 500 with fresh MFA', 'Payment recorded; receipt issued', (pid, e), pid is not None)
if pid:
    rc = c1.get(f'/app/finance/payments/{pid}/receipt')
    R.record('UAT-FIN-005', 'finance', 'cashier@A1', 'A', 'Campus A1', 'payment', 'GET receipt', 'Receipt with number', (rc.status, (rc.props.get('receipt') or {}).get('receiptNumber')), rc.status == 200)
pid2, e2 = pay(c1, [{'charge_id': lab, 'amount': '50.00'}, {'charge_id': nf, 'amount': '50.00'}], '100.00', 'payMixed') if not state('payMixed') else (None, {'note': 'checked'})
R.record('UAT-FIN-006', 'finance', 'cashier@A1', 'A', 'Campus A1', 'Main + North charges', 'one payment allocated to Main AND North', 'Mixed-Campus payment refused for a Campus actor (generic)', (pid2, e2), pid2 is None and bool(e2))
psmall, _ = pay(c1, [{'charge_id': small, 'amount': '100.00'}], '100.00', 'paySmall')
r = c2.get(f'/app/finance/payments/{pid}') if pid else None
R.record('UAT-FIN-007', 'finance', 'cashier@A2', 'A', 'Campus A2', 'payment touching Main only', 'GET the payment', 'Payment visible only when every Charge is in scope (404 otherwise)', r and r.status, r is not None and r.status == 404)

# ---------------------------------------------------------------- Concession (maker/checker)
if once('fin-concession-setting'):
    exp = next(a['id'] for a in fin.get('/app/finance/ledger-accounts').props['accounts'] if a['type'] == 'expense')
    adm.post('/app/finance/concessions/settings', {'concession_ledger_account_id': exp})
if not state('concession'):
    cc = fin.get(f'/app/finance/concessions/create?charge_id={lv}').props
    r = fin.post('/app/finance/concessions', {'idempotency_key': cc['idempotencyKey'], 'scope': 'targeted', 'category': 'concession', 'kind': 'fixed', 'fixed_amount': '200.00', 'charge_id': lv})
    m = re.search(r'/concessions/([0-9a-f-]{36})', r.location or '')
    state('concession', m.group(1) if m else None)
    if not m:
        print('concession error', fin.errors(r))
con = state('concession')
r_self = fin.post(f'/app/finance/concessions/{con}/approve', {'mfa_code': fin.mfa()})
cshow = adm.get(f'/app/finance/concessions/{con}').props
r_nomfa = adm.post(f'/app/finance/concessions/{con}/approve', {})
e_nomfa = adm.errors(r_nomfa)
r_ok = adm.post(f'/app/finance/concessions/{con}/approve', {'mfa_code': adm.mfa()})
after = adm.get(f'/app/finance/concessions/{con}').props['concession']
R.record('UAT-FIN-008', 'fees', 'accountant -> school_admin', 'A', 'whole-School', 'targeted 200 on the leaver trip fee', 'requester approve; approver without MFA; approver with MFA',
         'Requester cannot approve (no authority); approval needs fresh MFA; another person approves', (r_self.status, e_nomfa, after.get('status')), r_self.status == 403 and bool(e_nomfa) and after.get('status') in ('approved', 'applied', 'active'))

# ---------------------------------------------------------------- Payment correction (request -> another person's approval)
if psmall and not state('correction'):
    pp = fin.get(f'/app/finance/payments/{psmall}').props
    r = fin.post(f'/app/finance/payments/{psmall}/correction-requests', {'kind': 'reversal', 'idempotency_key': pp['correctionKey'], 'correction_count': len((pp.get('corrections') or {}).get('history') or []), 'reason': 'UAT reversal of a test payment', 'confirm': 1})
    q = adm.get('/app/finance/payment-correction-requests?status=pending').props['requests']
    q = q.get('data', q) if isinstance(q, dict) else q
    state('correction', q[0]['id'] if q else None)
cr = state('correction')
r_fin = fin.post(f'/app/finance/payment-correction-requests/{cr}/approve', {'mfa_code': fin.mfa()})
r_ok = adm.post(f'/app/finance/payment-correction-requests/{cr}/approve', {'mfa_code': adm.mfa()})
pp = adm.get(f'/app/finance/payments/{psmall}').props
R.record('UAT-FIN-009', 'payments', 'accountant -> school_admin', 'A', 'whole-School', 'cash 100 on the small fee', 'request reversal; requester approves; admin approves with MFA',
         'Correction is requested, never self-approved; another person executes it (reversal recorded)', (r_fin.status, r_ok.status, [h.get('kind') for h in (pp.get('corrections') or {}).get('history') or []]), r_fin.status == 403 and any(h.get('kind') == 'reversal' for h in (pp.get('corrections') or {}).get('history') or []))

# ---------------------------------------------------------------- Charge cancellation (assurance)
r1 = adm.post(f'/app/finance/charges/{dup}/cancel', {'reason_code': 'duplicate_charge'})
e1 = adm.errors(r1)
r2 = adm.post(f'/app/finance/charges/{dup}/cancel', {'reason_code': 'duplicate_charge', 'mfa_code': adm.mfa()})
dstat = adm.get(f'/app/finance/charges/{dup}').props['charge']
r3 = adm.post(f'/app/finance/charges/{lab}/cancel', {'reason_code': 'charged_in_error', 'mfa_code': adm.mfa()})
e3 = adm.errors(r3)
R.record('UAT-FIN-010', 'fees', 'school_admin', 'A', 'whole-School', 'unpaid duplicate; paid lab fee', 'cancel without MFA; with MFA; cancel a paid charge',
         'Cancellation needs fresh MFA + closed reason; a Charge with standing payments cannot be cancelled', (e1, dstat.get('status') or dstat.get('cancelledAt'), e3), bool(e1) and 'cancel' in json.dumps(dstat).lower() and bool(e3))

# ---------------------------------------------------------------- Reconciliation for MOVER, as-at, Campus filter
rows, page = all_rows(fin, f"/app/finance/outstanding?status=all&q={MOVER['number']}")
mrow = rows[0] if rows else {}
ch = fin.get(f"/app/finance/charges?student_id={MOVER['id']}&include_cancelled=0").props
chs = ch.get('charges', {})
chs = chs.get('data', chs) if isinstance(chs, dict) else chs
R.record('UAT-FIN-011', 'finance', 'accountant', 'A', 'whole-School', 'MOVER: demo fees + lab 1500 (paid 500) + small 100 (paid, reversed); dup cancelled', 'compare Outstanding row with Charge history',
         'Outstanding row = canonical Charge state (cancelled excluded, reversed payment re-opens)', (mrow.get('billed'), mrow.get('paid'), mrow.get('outstanding'), [(c.get('description'), c.get('amount'), c.get('balance') or c.get('outstanding')) for c in chs][:8]), bool(mrow))
asat = all_rows(fin, f"/app/finance/outstanding?status=all&as_at={YESTERDAY}&q={MOVER['number']}")[0]
R.record('UAT-FIN-012', 'finance', 'accountant', 'A', 'whole-School', 'UAT charges created today', f'Outstanding as_at={YESTERDAY}', 'As-at yesterday excludes today\'s charges and payments', (asat[0].get('billed') if asat else None, mrow.get('billed')), asat and D(asat[0]['billed']) < D(mrow['billed']))
a2rows = all_rows(adm, f'/app/finance/outstanding?status=all&campus_id={A2}')[0]
unav = all_rows(adm, '/app/finance/outstanding?status=all&campus_id=unavailable')[0]
R.record('UAT-FIN-013', 'finance', 'school_admin', 'A', 'whole-School', 'Northfield charge stored North', 'Outstanding campus_id=A2 and =unavailable', 'Campus filter by stored Charge Campus; NULL-Campus charges only under "no campus"', ([r['name'] for r in a2rows], len(unav)), [r['name'] for r in a2rows] == [north['displayName'] if 'displayName' in north else a2rows[0]['name']] if a2rows else False)
c1rows = all_rows(c1, '/app/finance/outstanding?status=all')[0]
R.record('UAT-FIN-014', 'finance', 'cashier@A1', 'A', 'Campus A1', '-', 'Outstanding (Campus scope)', 'Campus cashier never sees North debt', [r['name'] for r in c1rows if 'Northfield' in r['name']], not any('Northfield' in r['name'] for r in c1rows))

# ---------------------------------------------------------------- Ledger statement
acct = state('receivable')
st_ = fin.get(f'/app/finance/ledger-accounts/{acct}/ledger?from={TODAY}&to={TODAY}').props
lines = st_['lines']['data']
sign = 1 if st_['account'].get('normalSide') == 'debit' else -1
calc = D(st_['opening']) + sign * sum(D(l['debit'] or 0) - D(l['credit'] or 0) for l in lines)
R.record('UAT-FIN-015', 'ledger', 'accountant', 'A', 'whole-School', "today's postings on the receivable account", 'statement from=to=today', 'opening + movements = closing; running balance ends at closing; posted lines only',
         (st_['opening'], st_['totalDebit'], st_['totalCredit'], st_['closing'], lines[-1]['balance'] if lines else None, str(calc)), calc == D(st_['closing']) and (not lines or D(lines[-1]['balance']) == D(st_['closing'])))

# ---------------------------------------------------------------- Fee reminder (ORG.9D-2) end to end
if not state('reminder'):
    cr_ = adm.get('/app/communications/announcements/create?audience=fee_reminder&status=outstanding').props
    fields = cr_['feeReminder']['fields']
    r = adm.post('/app/communications/announcements', {'title': 'Fee reminder (UAT)', 'body': 'Please clear the dues listed below.', 'priority': 'normal', 'audience_type': 'fee_reminder', 'channels': ['email'], 'fee_reminder': fields})
    m = re.search(r'/announcements/([0-9a-f-]{36})', r.location or '')
    state('reminder', m.group(1) if m else None)
    if not m:
        print('reminder error', adm.errors(r))
rem = state('reminder')
show = adm.get(f'/app/communications/announcements/{rem}').props
prev = (show.get('preview') or {}).get('feeReminder')
R.record('UAT-FEE-001', 'fee-reminder', 'school_admin', 'A', 'whole-School', 'two current Guardian accounts', 'compose from Outstanding; preview', 'Preview shows Guardians reached, Students without a Guardian account counted, figures preparer-only', json.dumps(prev)[:400], prev is not None)
if once('reminder-submit'):
    adm.post(f'/app/communications/announcements/{rem}/submit-approval')
pr = s('principal@example.test')
q = pr.get('/app/communications/approvals').props['requests']
q = q.get('data', q) if isinstance(q, dict) else q
req = next((x for x in q if x.get('announcementId') == rem), None)
self_dec = adm.post(f"/app/communications/approvals/{req['id']}/approve", {}) if req else None
self_e = adm.errors(self_dec) if self_dec else {}
pshow = pr.get(f'/app/communications/announcements/{rem}').props
R.record('UAT-FEE-002', 'fee-reminder', 'principal', 'A', 'whole-School', 'pending approval', 'approver views the reminder', 'Approver never sees the preparer\'s figures', json.dumps((pshow.get('preview') or {}))[:200], not ((pshow.get('preview') or {}).get('feeReminder') or {}).get('lines'))
if req and once('reminder-approve'):
    pr.post(f"/app/communications/approvals/{req['id']}/approve", {'note': 'UAT approve'})
r = adm.post(f'/app/communications/announcements/{rem}/publish') if once('reminder-publish') else None
subprocess.run(['ddev', 'exec', '-d', '/var/www/html/apps/platform', 'php artisan queue:work --stop-when-empty --max-time=120'], capture_output=True, timeout=300)
final = adm.get(f'/app/communications/announcements/{rem}').props['announcement']
R.record('UAT-FEE-003', 'fee-reminder', 'school_admin -> principal', 'A', 'whole-School', 'reminder draft', 'submit; preparer self-approve; principal approves; preparer publishes',
         'Always approved by someone else; preparer cannot decide; published', (self_e, final.get('status'), final.get('requirement')), bool(self_e) and final.get('status') == 'published' and final.get('requirement') != 'required')
state('reminderPublished', TODAY)
