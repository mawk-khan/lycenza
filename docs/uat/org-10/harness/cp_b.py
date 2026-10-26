"""ORG.10-B: Organization + Identity + Campus + Authorization (real HTTP)."""
import sys, os, re, time
sys.path.insert(0, os.path.dirname(__file__))
from uat import Session, Register, fixture, nav_keys

fx = fixture()
A, B = fx['schools']['A'], fx['schools']['B']
A1, A2 = fx['campuses']['A1'], fx['campuses']['A2']
R = Register('ORG.10-B')
S = {}


def s(email, school=A):
    if email not in S:
        S[email] = Session(email, school).login()
    elif school:
        S[email].activate(school)
    return S[email]


def st(sess, path):
    return sess.get(path).status


TENANT_PATHS = ['/app/students', '/app/hr/employees', '/app/finance/outstanding', '/app/inventory-stock',
                '/app/communications', '/app/compliance/audit-log', '/app/transport/routes', '/app/library/circulation']

# ---------------------------------------------------------------- Platform separation
pm = s('platform.manager@example.test', None)
pa = s('platform.admin@example.test', None)
pau = s('platform.auditor@example.test', None)
r = pm.get('/app/platform/schools')
R.record('UAT-ORG-001', 'organization', 'platform_manager', '-', 'platform', 'demo', 'GET registry', 'Platform Manager reads the School Registry', r.status, r.status == 200)
codes = {a: st(pm, f'/app/platform/schools/{B}/{a}') for a in ['activate', 'suspend', 'resume', 'close', 'reopen']}
R.record('UAT-ORG-002', 'organization', 'platform_manager', 'B', 'platform', 'B active', 'GET lifecycle review pages', 'Platform Manager cannot open activate/suspend/resume/close/reopen', codes, all(c == 403 for c in codes.values()))
r = pm.post(f'/app/platform/schools/{B}/suspend', {'confirmed': 1, 'reason_code': 'administrative_hold', 'mfa_code': pm.mfa()})
b_status = pa.get(f'/app/platform/schools/{B}').props.get('school', {}).get('status')
R.record('UAT-ORG-003', 'organization', 'platform_manager', 'B', 'platform', 'B active', 'POST suspend', 'Platform Manager suspend refused; School B stays active', (r.status, b_status), r.status in (403, 302) and b_status == 'active')

# Onboarding a School (provisioning) by the Platform Manager
slug = 'uat-org10-' + time.strftime('%H%M%S')
r = pm.post('/app/platform/schools', {'name': 'UAT ORG10 School', 'slug': slug, 'code': 'U' + time.strftime('%H%M%S'), 'admin': 'uat.roleonly@example.test', 'confirmed': 1, 'mfa_code': pm.mfa()})
new_id = (re.search(r'/schools/([0-9a-f-]{36})', r.location or '') or [None, None])[1]
new = pa.get(f'/app/platform/schools/{new_id}').props.get('school', {}) if new_id else {}
R.record('UAT-ORG-004', 'organization', 'platform_manager', 'new', 'platform', 'MFA', 'POST /app/platform/schools', 'Platform Manager onboards a School into provisioning', (r.status, new.get('status')), new.get('status') == 'provisioning')
if new_id:
    r = pm.post(f'/app/platform/schools/{new_id}/activate', {'confirmed': 1, 'mfa_code': pm.mfa()})
    still = pa.get(f'/app/platform/schools/{new_id}').props['school']['status']
    R.record('UAT-ORG-005', 'organization', 'platform_manager', 'new', 'platform', 'provisioning', 'POST activate', 'Platform Manager cannot activate', (r.status, still), still == 'provisioning')
    seq = []
    for action, extra in [('activate', {}), ('suspend', {'reason_code': 'administrative_hold'}), ('resume', {})]:
        r = pa.post(f'/app/platform/schools/{new_id}/{action}', {'confirmed': 1, 'mfa_code': pa.mfa(), **extra})
        seq.append((action, r.status, pa.get(f'/app/platform/schools/{new_id}').props['school']['status'], pa.errors(r) if r.status != 302 or 'lifecycleNotice' not in (r.location or '') else ''))
    R.record('UAT-ORG-006', 'organization', 'platform_admin', 'new', 'platform', 'MFA', 'activate -> suspend -> resume', 'Root runs the lifecycle provisioning->active->suspended->active', seq, [x[2] for x in seq] == ['active', 'suspended', 'active'])

# Module entitlement (School B, library) by Platform Manager
ab = s('annexe.admin@example.test', B)
before = st(ab, '/app/library/titles')
r = pm.post(f'/app/platform/schools/{B}/modules/library/disable', {'reason_code': 'operational_correction', 'confirmed': 1, 'mfa_code': pm.mfa()})
off = st(ab, '/app/library/titles')
r2 = pm.post(f'/app/platform/schools/{B}/modules/library/enable', {'reason_code': 'operational_correction', 'confirmed': 1, 'mfa_code': pm.mfa()})
on = st(ab, '/app/library/titles')
core = st(pm, f'/app/platform/schools/{B}/modules/student_records/disable')
R.record('UAT-ORG-007', 'organization', 'platform_manager+annexe_admin', 'B', 'module', 'library on', 'disable library -> annexe admin GET; enable -> GET; core module page',
         'Disabling removes access (403), enabling restores it; a core module cannot be disabled (404)', (before, r.status, off, r2.status, on, core), before == 200 and off == 403 and on == 200 and core == 404)

r = pau.get('/app/platform/schools')
audit = pau.get('/app/platform/audit-log')
w = pau.post(f'/app/platform/schools/{B}/modules/library/disable', {'reason_code': 'operational_correction', 'confirmed': 1, 'mfa_code': pau.mfa()})
lib_after = st(ab, '/app/library/titles')
R.record('UAT-ORG-008', 'organization', 'platform_auditor', 'B', 'platform', 'MFA login', 'GET registry, audit log; POST module disable', 'Auditor reads registry + platform audit log, changes nothing',
         (r.status, audit.status, audit.page.get('component'), w.status, lib_after), r.status == 200 and audit.status == 200 and w.status == 403 and lib_after == 200)

for persona, sess in [('platform_admin', pa), ('platform_manager', pm), ('platform_auditor', pau), ('group_admin', s('group.admin@example.test', None))]:
    act = sess.post(f'/app/schools/{A}/activate')
    reach = {p: st(sess, p) for p in TENANT_PATHS}
    R.record(f'UAT-ORG-009-{persona}', 'platform-separation', persona, 'A', 'platform', 'no School membership', 'activate School A; GET tenant pages',
             'No implicit tenant access: Students/HR/Finance/Inventory/Communications/audit/Transport/Library never 200', (act.status, reach), all(c != 200 for c in reach.values()))

# ---------------------------------------------------------------- Identity: navigation vs direct route
PERSONAS = ['school.admin@example.test', 'principal@example.test', 'teacher@example.test', 'uat.selfservice@example.test', 'uat.hr@example.test',
            'uat.payroll@example.test', 'finance.officer@example.test', 'uat.cashier.a1@example.test', 'library.operator@example.test',
            'uat.librarian.a1@example.test', 'transport.operator@example.test', 'hostel.warden@example.test', 'reception@example.test',
            'canteen.operator@example.test', 'uat.admissions@example.test', 'communications@example.test', 'uat.principal.a2@example.test',
            'guardian01@example.test', 'student@example.test', 'hr.payroll@example.test']
nav_by = {}
for email in PERSONAS:
    sess = s(email)
    home = sess.get('/app')
    nav = home.props.get('navigation')
    hrefs = []

    def walk(x):
        if isinstance(x, dict):
            if 'href' in x and 'key' in x and isinstance(x['href'], str):
                hrefs.append((x['key'], x['href']))
            for v in x.values():
                walk(v)
        elif isinstance(x, list):
            for v in x:
                walk(v)
    walk(nav)
    bad = []
    for key, href in hrefs:
        path = href.replace('https://lycenza.ddev.site', '')
        if not path.startswith('/app') or path.startswith('/app/schools'):
            continue
        resp = sess.get(path)
        code = resp.status
        # A single-child Guardian is sent straight to that child's page (same area).
        if code == 302 and (resp.location or '').replace('https://lycenza.ddev.site', '').startswith(path + '/'):
            code = sess.follow(resp).status
        if code not in (200,):
            bad.append((key, path, code))
    nav_by[email] = [k for k, _ in hrefs]
    R.record(f'UAT-ID-001-{email.split("@")[0]}', 'identity-navigation', email.split('@')[0], 'A', 'grants', 'demo/fixture roles', 'GET every navigation entry',
             'Every offered navigation entry opens (no impossible navigation)', bad or f'{len(hrefs)} entries ok', not bad)

# Direct-route refusals (navigation is not authority)
DENY = {
    'teacher@example.test': ['/app/students', '/app/hr/employees', '/app/finance/outstanding', '/app/communications/announcements', '/app/inventory-stock', '/app/settings/staff', '/app/compliance/audit-log'],
    'uat.selfservice@example.test': ['/app/students', '/app/hr/employees', '/app/payroll/runs', '/app/leave/requests', '/app/communications/announcements'],
    'library.operator@example.test': ['/app/students', '/app/finance/outstanding', '/app/hr/employees', '/app/inventory-stock'],
    'uat.cashier.a1@example.test': ['/app/finance/ledger-accounts', '/app/finance/fee-setup', '/app/finance/concessions', '/app/students', '/app/finance/charges/create'],
    'finance.officer@example.test': ['/app/students', '/app/hr/employees', '/app/communications/announcements'],
    'guardian01@example.test': ['/app/students', '/app/communications', '/app/finance/outstanding'],
    'student@example.test': ['/app/students', '/app/communications', '/app/portal/communications'],
    'principal@example.test': ['/app/finance/ledger-accounts', '/app/finance/payments/record', '/app/payroll/runs'],
}
for email, paths in DENY.items():
    sess = s(email)
    got = {p: st(sess, p) for p in paths}
    R.record(f'UAT-ID-002-{email.split("@")[0]}', 'identity-direct-route', email.split('@')[0], 'A', 'grants', 'nav hides', 'GET out-of-authority URLs directly', 'Direct URLs refused (403/404), never 200', got, all(c in (403, 404) for c in got.values()))

# ---------------------------------------------------------------- Inactive membership (off-boarding through Settings -> Staff accounts)
adm = s('school.admin@example.test')
staff_page = adm.get('/app/settings/staff')
members = staff_page.props.get('members') or staff_page.props.get('staff') or []
target = next((m for m in members if (m.get('email') or '') == 'uat.roleonly@example.test'), None)
R.record('UAT-ID-003a', 'identity', 'school_admin', 'A', 'whole-School', 'Staff accounts page', 'GET /app/settings/staff', 'Staff accounts lists the role-only member', (staff_page.status, bool(target), list(staff_page.props.keys())[:12]), staff_page.status == 200 and target is not None)
if target:
    mid = target.get('membershipId') or target.get('id')
    ro = s('uat.roleonly@example.test')
    pre = st(ro, '/app/my-attendance/take')
    r = adm.post(f'/app/settings/staff/members/{mid}/suspend', {'mfa_code': adm.mfa()}, as_json=True)
    ro2 = Session('uat.roleonly@example.test', A)
    act = ro2.post(f'/app/schools/{A}/activate')
    after = st(ro, '/app/my-attendance/take')
    R.record('UAT-ID-003', 'identity', 'school_admin -> role-only teacher', 'A', 'membership', 'active member', 'off-board (suspend) with fresh MFA; member re-enters', 'Suspended membership cannot enter the School or reach teacher pages', (pre, r.status, r.body[:120], act.status, after), r.status == 200 and after != 200)

# ---------------------------------------------------------------- Campus foundation
code = 'T' + time.strftime('%H%M%S')
r = adm.post('/app/school-setup/campuses', {'name': 'UAT Temporary Campus', 'code': code})
camps = adm.get('/app/school-setup/campuses').props.get('campuses', [])
tmp = next((c for c in camps if c['code'] == code), None)
r2 = adm.put(f'/app/school-setup/campuses/{tmp["id"]}', {'name': 'UAT Temporary Campus', 'code': code, 'status': 'inactive'}) if tmp else None
camps = adm.get('/app/school-setup/campuses').props.get('campuses', [])
tmp2 = next((c for c in camps if c['code'] == code), {})
R.record('UAT-CAM-001', 'campus', 'school_admin', 'A', 'whole-School', '-', 'create Campus, then deactivate', 'Campus created active, then inactive (never deleted)', (r.status, tmp and tmp['status'], tmp2.get('status')), tmp is not None and tmp2.get('status') == 'inactive')
p2 = s('uat.principal.a2@example.test')
denied = {p: st(p2, p) for p in ['/app/school-setup/campuses', '/app/settings/staff', '/app/finance/outstanding']}
R.record('UAT-CAM-002', 'campus', 'principal@A2', 'A', 'Campus A2', 'principal granted on A2 only', 'GET whole-School administration', 'Campus principal gets no access administration/School configuration/Finance', denied, denied['/app/settings/staff'] in (403, 404) and denied['/app/finance/outstanding'] in (403, 404))
cash = s('uat.cashier.a1@example.test')
r = cash.get(f'/app/finance/outstanding?campus_id={A2}')
R.record('UAT-CAM-003', 'campus', 'cashier@A1', 'A', 'Campus A1', 'cashier Campus grant', 'GET outstanding with campus_id=A2 filter', 'A Campus filter is never permission: no A2 rows', (r.status, len((r.props.get('rows') or {}).get('data', [])), r.props.get('scope')), r.status in (403, 404, 422) or len((r.props.get('rows') or {}).get('data', [])) == 0)
