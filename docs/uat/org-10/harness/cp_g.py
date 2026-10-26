"""ORG.10-G: Cross-domain lifecycle + Campus move + School isolation + audit + retention/erasure."""
import sys, os, re, json, datetime, subprocess
from decimal import Decimal as D
sys.path.insert(0, os.path.dirname(__file__))
from uat import Session, Register, fixture, once, state

fx = fixture()
A, B = fx['schools']['A'], fx['schools']['B']
A1, A2 = fx['campuses']['A1'], fx['campuses']['A2']
R = Register('ORG.10-G')
S = {}
TODAY = datetime.date.today().isoformat()
MOVER, LEAVER = state('mover'), state('leaver')


def s(email, school=A):
    key = f'{email}|{school}'
    if key not in S:
        S[key] = Session(email, school).login()
    else:
        S[key].activate(school)
    return S[key]


def data(x):
    return x.get('data', x) if isinstance(x, dict) else x


def artisan(cmd):
    p = subprocess.run(['ddev', 'exec', '-d', '/var/www/html/apps/platform', f'php artisan {cmd} --no-interaction'], capture_output=True, text=True, timeout=600)
    return p.returncode, (p.stdout + p.stderr).strip()


adm = s('school.admin@example.test')
tr = s('transport.operator@example.test')
c1 = s('uat.cashier.a1@example.test')
c2 = s('uat.cashier.a2@example.test')
p2 = s('uat.principal.a2@example.test')
la1 = s('uat.librarian.a1@example.test')

# ---------------------------------------------------------------- Campus move (MOVER: Main -> North)
tp = adm.get(f"/app/students/{MOVER['id']}/transfer").props
dest = next((d['id'] for d in tp.get('destinations') or [] if 'North' in json.dumps(d)), None)
if once('move-blocked'):
    r = adm.post(f"/app/students/{MOVER['id']}/transfer", {'target_section_id': dest, 'roll_number': '2'})
    state('moveBlocked', adm.errors(r))
R.record('UAT-XD-MOVE-001', 'cross-domain', 'school_admin', 'A', 'enrollments.manage on both Campuses', 'MOVER is an active Transport rider on Main', 'transfer to North Section N', 'Transfer refused generically while a source-Campus rider assignment is active', (dest is not None, state('moveBlocked')), bool(state('moveBlocked')))
if once('move-end-rider'):
    rider = next(a for a in data(tr.get('/app/transport/assignments').props['assignments']) if MOVER['name'] in json.dumps(a))
    tr.post(f"/app/transport/assignments/{rider['id']}/end")
if once('move-do'):
    r = adm.post(f"/app/students/{MOVER['id']}/transfer", {'target_section_id': dest, 'roll_number': '2'})
    state('moveErr', adm.errors(r) if '/overview' not in (r.location or '') else {})
ov = adm.get(f"/app/students/{MOVER['id']}/overview").props
place = json.dumps(ov.get('currentPlacement'))
R.record('UAT-XD-MOVE-002', 'cross-domain', 'school_admin', 'A', 'both Campuses', 'rider assignment ended', 'transfer to North', 'Current placement is North; source placement ended (immutable rows)', (state('moveErr'), place[:160]), 'North' in place and not state('moveErr'))
lab = state('chLab')
vis = (c1.get(f'/app/finance/charges/{lab}').status, c2.get(f'/app/finance/charges/{lab}').status)
R.record('UAT-XD-MOVE-003', 'cross-domain', 'cashier@A1 / cashier@A2', 'A', 'Campus', 'lab fee stored on Main before the move', 'read the charge after the move', 'Charge Campus is stored history: still Main (A1 200, A2 404)', vis, vis == (200, 404))
loan = state('loanA1')
ci = la1.post(f'/app/library/circulation/{loan}/check-in') if once('move-checkin') else None
lst = la1.get(f'/app/library/circulation/{loan}').props.get('loan') or {}
R.record('UAT-XD-MOVE-004', 'cross-domain', 'librarian@A1', 'A', 'Campus A1', 'loan written on Main before the move', 'check in after the Student moved to North', 'Return follows the loan Campus, never the Student\'s current Campus', (ci.status if ci else 'done', lst.get('status'), lst.get('returnedAt') is not None), lst.get('returnedAt') is not None)
dir2 = [x.get('studentNumber') or x.get('student_number') for x in data(p2.get('/app/students').props['students'])]
R.record('UAT-XD-MOVE-005', 'cross-domain', 'principal@A2', 'A', 'Campus A2', 'MOVER now on North', 'Campus Student directory', 'North principal now sees MOVER (current placement)', dir2, MOVER['number'] in dir2)

# ---------------------------------------------------------------- Leaver (LEAVER)
if once('leaver-status'):
    r = adm.post(f"/app/students/{LEAVER['id']}/status", {'status': 'inactive', 'reason_code': 'left_school', 'confirm': 1, 'mfa_code': adm.mfa()})
    state('leaverErr', adm.errors(r))
lsh = adm.get(f"/app/students/{LEAVER['id']}").props
R.record('UAT-XD-LIFE-001', 'cross-domain', 'school_admin', 'A', 'whole-School', 'LEAVER active with outstanding trip fee', 'status -> inactive (left_school), confirm + fresh MFA', 'Leaver recorded with a lifecycle reason', (state('leaverErr'), lsh.get('student', {}).get('status')), lsh.get('student', {}).get('status') == 'inactive')
lv_rows = data(s('finance.officer@example.test').get(f"/app/finance/outstanding?status=all&standing=leavers").props['rows'])
lvr = next((r for r in lv_rows if r['studentNumber'] == LEAVER['number']), None)
R.record('UAT-XD-LIFE-002', 'cross-domain', 'accountant', 'A', 'whole-School', 'leaver with trip fee 2000 - concession 200', 'Outstanding standing=leavers', 'Leaver debt stays visible and collectable', lvr and (lvr['billed'], lvr['concession'], lvr['outstanding'], lvr['leaver']), lvr is not None and lvr['leaver'])
rp = c1.get(f"/app/finance/payments/record?student_id={LEAVER['id']}").props
chg = [c for c in rp.get('charges') or [] if c['id'] == state('chLeaver')]
R.record('UAT-XD-LIFE-003', 'cross-domain', 'cashier@A1', 'A', 'Campus A1', 'leaver charge stored on Main', 'payment form for the leaver', 'A leaver is collectable on the stored Charge Campus', [(c.get('description'), c.get('outstanding')) for c in chg], bool(chg))

# ---------------------------------------------------------------- Guardian relationship end (Staff+Guardian)
sg = s('uat.staffguardian@example.test')
gid = fx['staffGuardianGuardianId']
gshow = adm.get(f'/app/guardians/{gid}').props
students = gshow.get('students') or []
before_portal = [i['title'] for i in sg.get('/app/portal/communications').props.get('items', [])]
rel = students[0] if students else {}
rel_id = rel.get('relationshipId') or rel.get('relationship_id') or rel.get('id')
if rel_id and once('guardian-unlink'):
    r = adm.request('DELETE', f'/app/relationships/{rel_id}')
    state('unlink', {'status': r.status, 'errors': adm.errors(r)})
after_portal_r = sg.get('/app/portal/communications')
after_portal = [i['title'] for i in after_portal_r.props.get('items', [])]
fees = sg.get('/app/portal/fees')
R.record('UAT-GRD-001', 'guardian', 'school_admin -> Staff+Guardian', 'A', 'guardian', 'Guardian related to one Student; family note delivered earlier', 'end the relationship; reopen portal inbox + fees',
         'Delivered history follows the immutable recipient snapshot; Student-linked data follows the current relationship', {'unlink': state('unlink'), 'inboxBefore': before_portal, 'inboxAfter': (after_portal_r.status, after_portal), 'fees': (fees.status, fees.location)},
         'UAT Grade 6 A family note' in after_portal and fees.status != 200 or (fees.status == 200 and not fees.props.get('students')))

# ---------------------------------------------------------------- Cross-School isolation (shared user + School B admin)
ids = {
    'student': f"/app/students/{MOVER['id']}",
    'student-overview': f"/app/students/{MOVER['id']}/overview",
    'employee': '/app/hr/employees/' + s('uat.hr@example.test').get('/app/hr/employees?search=Neha').props['employees']['data'][0]['employee_id'],
    'charge': f'/app/finance/charges/{lab}',
    'payment': f"/app/finance/payments/{state('payLab')}",
    'receipt': f"/app/finance/payments/{state('payLab')}/receipt",
    'concession': f"/app/finance/concessions/{state('concession')}",
    'announcement': f"/app/communications/announcements/{state('annStaff')}",
    'event': f"/app/communications/events/{state('evStaff')}",
    'transport-route': f"/app/transport/routes/{state('route')}",
    'library-loan': f'/app/library/circulation/{loan}',
    'library-title': f"/app/library/titles/{state('title')}",
}
for email in ['multi.school@example.test', 'annexe.admin@example.test']:
    sess = s(email, B)
    got = {k: sess.get(v).status for k, v in ids.items()}
    audit = sess.get('/app/compliance/audit-log?event=finance')
    leaks = {k: v for k, v in got.items() if v == 200}
    R.record(f'UAT-XD-ISO-{email.split("@")[0]}', 'isolation', email.split('@')[0], 'B', 'School B context', 'School A ids', 'GET every School A record id while acting in School B',
             'Every cross-School id is the unknown 404 (or refusal); no disclosure', (got, audit.status), not leaks)
mb = s('multi.school@example.test', B)
mb_items = [i['title'] for i in mb.get('/app/communications').props.get('items', [])]
R.record('UAT-XD-ISO-hub', 'isolation', 'multi.school (principal A, admin B)', 'B', 'School B', 'School A announcements exist', 'Hub inbox + search in School B', 'No School A Communication appears in School B', mb_items[:5], not any('UAT' in t for t in mb_items))

# ---------------------------------------------------------------- School audit log
al = adm.get(f'/app/compliance/audit-log?from={TODAY}&to={TODAY}').props.get('log') or {}
opts = al.get('filterOptions') or {}
entries = al.get('entries') or []
fields = al.get('fields') or []
fam = adm.get('/app/compliance/audit-log?event=communications').props.get('log') or {}
bad = adm.get('/app/compliance/audit-log?event=not.a.real.event')
nxt = al.get('nextCursor')
page2 = adm.get(f'/app/compliance/audit-log?from={TODAY}&to={TODAY}&cursor={nxt}').props.get('log') if nxt else None
types = {e.get('event') or e.get('eventType') for e in entries}
R.record('UAT-AUD-001', 'audit', 'school_admin', 'A', 'whole-School', "today's UAT activity", 'audit log: date range, family filter, unknown value, cursor', 'Filters narrow; unknown value 422-style refusal; cursor pages; envelope fields only (no metadata/IP/user agent)',
         {'fields': fields, 'families': len(opts.get('eventFamilies') or []), 'famEntries': len(fam.get('entries') or []), 'bad': bad.status, 'page2': bool(page2), 'sample': sorted(t for t in types if t)[:12]},
         not any(f in ('metadata', 'ip_address', 'user_agent', 'ip') for f in fields) and bool(fam.get('entries')) and bad.status in (302, 422))
want = ['payment_correction', 'charge', 'attendance', 'write_off', 'announcement', 'event', 'transport', 'concession']
allf = json.dumps(opts.get('events') or [])
R.record('UAT-AUD-002', 'audit', 'school_admin', 'A', 'whole-School', 'UAT actions performed', 'audit event catalogue', 'Approvals, financial assurance, attendance correction, write-off, publish, Event cancel and Transport import are audited', {w: (w in allf) for w in want}, all(w in allf for w in want))
R.record('UAT-AUD-003', 'audit', 'principal@A2', 'A', 'Campus A2', '-', 'GET audit log', 'Campus actor never reads the School audit log', s('uat.principal.a2@example.test').get('/app/compliance/audit-log').status, s('uat.principal.a2@example.test').get('/app/compliance/audit-log').status in (403, 404))

# ---------------------------------------------------------------- Retention (dry runs on the demo database)
ret = {}
for cmd in ['platform:student-retention-prune --dry-run', 'platform:guardian-retention-prune --dry-run', 'platform:admissions-retention-prune --dry-run', 'platform:finance-retention-prune --dry-run',
            'platform:communications-prune --dry-run', 'platform:academic-retention-prune --dry-run', 'platform:payroll-retention-prune --dry-run', 'platform:staff-account-credentials-prune --dry-run']:
    code, out = artisan(cmd)
    ret[cmd.split()[0]] = (code, out.splitlines()[-1][:120] if out else '')
R.record('UAT-RET-001', 'retention', 'operator (console)', 'all', 'platform', 'demo data created today', 'every retention prune --dry-run', 'Each runs, deletes nothing, honours undecided periods', ret, all(v[0] == 0 for v in ret.values()))

# ---------------------------------------------------------------- User erasure (synthetic former teacher)
uid = fx['accounts']['uat.teacher.former@example.test']['id']
if not state('erasureCase'):
    code, out = artisan(f'platform:erasure-case-open --subject-type=user --subject={uid} --channel=written')
    m = re.search(r'[0-9a-f]{8}-[0-9a-f-]{27}', out)
    state('erasureCase', m.group(0) if m else None)
    state('erasureOpen', out[-300:])
case = state('erasureCase')
d = artisan(f'platform:erasure-case-decide {case} --decision=approve --reason=request_valid --force') if case and once('erasure-decide') else (0, 'done')
plan = artisan(f'platform:erasure-case-execute {case} --dry-run') if case else (1, state('erasureOpen'))
ex = artisan(f'platform:erasure-case-execute {case}') if case and once('erasure-exec') else (0, 'done')
R.record('UAT-RET-002', 'erasure', 'operator (console)', 'A', 'platform', 'former teacher: User + membership + Employee link + MFA + Staff Event/announcement history', 'open -> decide approve -> execute dry-run -> execute',
         'Erasure executes; no unregistered User link blocks it (UserReferenceCatalog complete)', {'open': state('erasureOpen')[-120:] if state('erasureOpen') else None, 'decide': d[1][-120:], 'plan': plan[1][-200:], 'exec': ex[1][-200:]}, case is not None and plan[0] == 0 and ex[0] == 0)
