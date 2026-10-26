"""ORG.10-F: Communications + Events + Portal + Campus-only staff consumption (real HTTP)."""
import sys, os, re, json, datetime, subprocess, ssl, urllib.request
sys.path.insert(0, os.path.dirname(__file__))
from uat import Session, Register, fixture, once, state

fx = fixture()
A = fx['schools']['A']
R = Register('ORG.10-F')
S = {}
TODAY = datetime.date.today()


def s(email, school=A):
    if email not in S:
        S[email] = Session(email, school).login()
    else:
        S[email].activate(school)
    return S[email]


def data(x):
    return x.get('data', x) if isinstance(x, dict) else x


def queue():
    subprocess.run(['ddev', 'exec', '-d', '/var/www/html/apps/platform', 'php artisan queue:work --stop-when-empty --max-time=180'], capture_output=True, timeout=400)


def mailpit_to(subject):
    ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
    d = json.load(urllib.request.urlopen('https://lycenza.ddev.site:8026/api/v1/messages?limit=200', context=ctx))
    return sorted({t['Address'] for m in d['messages'] if subject in m['Subject'] for t in m['To']})


co = s('communications@example.test')
pr = s('principal@example.test')


def approve_pending(kind, ref_key, ref):
    q = data(pr.get('/app/communications/approvals').props['requests'])
    req = next((x for x in q if x.get(ref_key) == ref), None)
    if req:
        pr.post(f"/app/communications/approvals/{req['id']}/approve", {'note': 'UAT'})
    return req


def announce(key, title, audience, extra=None, attach=False):
    if state(key):
        return state(key)
    r = co.post('/app/communications/announcements', {'title': title, 'body': f'{title} body.', 'priority': 'normal', 'audience_type': audience, 'channels': ['in_app', 'email'], **(extra or {})})
    aid = re.search(r'/announcements/([0-9a-f-]{36})', r.location or '').group(1)
    if attach:
        co.post(f'/app/communications/announcements/{aid}/attachments', {}, files={'file': ('briefing.pdf', b'%PDF-1.4 UAT\n%%EOF\n', 'application/pdf')})
    show = co.get(f'/app/communications/announcements/{aid}').props
    if (show.get('approvalRequirement') or {}).get('required'):
        co.post(f'/app/communications/announcements/{aid}/submit-approval')
        approve_pending('announcement', 'announcementId', aid)
    r = co.post(f'/app/communications/announcements/{aid}/publish')
    state(key, aid)
    state(key + 'Err', co.errors(r))
    return aid


staff_ann = announce('annStaff', 'UAT staff briefing', 'staff', attach=True)
cur_year = co.get('/app/communications/announcements/create').props['currentAcademicYear']['id']
evf = co.get('/app/communications/events/create').props
g6a = next(x['id'] for x in evf['sections'] if 'Grade 6' in x['label'] and ' A' in x['label'].replace('·', ' '))
fam_ann = announce('annFamily', 'UAT Grade 6 A family note', 'section', {'academic_cohort': {'academic_year_id': cur_year, 'section_id': g6a, 'recipient_kind': 'guardian'}})
queue()
st_ann = co.get(f'/app/communications/announcements/{staff_ann}').props['announcement']
R.record('UAT-COM-001', 'communications', 'communications_coordinator -> principal', 'A', 'whole-School', 'Staff audience = CurrentStaffMembers', 'create Staff announcement + attachment, approve if required, publish', 'Published; snapshot taken at publish', (st_ann.get('status'), st_ann.get('recipientCount'), state('annStaffErr')), st_ann.get('status') == 'published')
emails = mailpit_to('UAT staff briefing')
R.record('UAT-COM-002', 'communications', 'Mailpit', 'A', 'whole-School', 'published Staff announcement', 'read Mailpit recipients', 'Email reaches current staff only: never former/future/role-only/no-Employee accounts', emails,
         'teacher@example.test' in emails and not {'uat.teacher.former@example.test', 'uat.teacher.future@example.test', 'uat.roleonly@example.test', 'library.operator@example.test'} & set(emails))
state('staffEmailRecipients', emails)

t = s('teacher@example.test')
inbox = [i['title'] for i in t.get('/app/communications').props['items']]
page = t.get(f'/app/communications/announcements/{staff_ann}')
att = (page.props.get('attachments') or [{}])[0].get('id')
dl = t.get(f'/app/communications/attachments/{att}/download').status if att else None
srch = [i['title'] for i in t.get('/app/communications/search?q=UAT').props['items']]
admin = t.get('/app/communications/announcements').status
R.record('UAT-COM-003', 'communications', 'teacher', 'A', 'own', 'recipient of the Staff announcement', 'inbox, open, attachment, search, School list', 'Teacher receives, opens, downloads, searches; School list 403; never the family note',
         (inbox[:5], page.status, dl, srch, admin), 'UAT staff briefing' in inbox and page.status == 200 and dl == 200 and 'UAT staff briefing' in srch and admin == 403 and 'UAT Grade 6 A family note' not in srch)
for email, label in [('uat.teacher.former@example.test', 'former teacher'), ('uat.teacher.future@example.test', 'future teacher'), ('uat.roleonly@example.test', 'role-only (suspended in B)')]:
    sess = Session(email, A)
    try:
        sess.login()
        items = [i['title'] for i in sess.get('/app/communications').props.get('items', [])]
        code = sess.get(f'/app/communications/announcements/{staff_ann}').status
    except AssertionError as e:
        items, code = str(e)[:60], 'no access'
    R.record(f'UAT-COM-004-{label.split()[0]}', 'communications', label, 'A', 'own', 'not current staff at publish', 'inbox + direct URL', 'Never snapshotted: not in inbox; direct URL refused', (items, code), 'UAT staff briefing' not in json.dumps(items) and code != 200)

# ---------------------------------------------------------------- Campus-only staff (mandatory §16/§46)
campus_only = {}
for email in ['uat.librarian.a1@example.test', 'uat.cashier.a1@example.test', 'uat.principal.a2@example.test']:
    sess = s(email)
    campus_only[email.split('@')[0]] = {
        'emailed': email in emails,
        'hub': sess.get('/app/communications').status,
        'announcement': sess.get(f'/app/communications/announcements/{staff_ann}').status,
        'calendar': sess.get('/app/communications/calendar').status,
        'navHub': 'communications' in json.dumps(sess.get('/app').props.get('navigation')),
    }
consistent = all(v['emailed'] == (v['hub'] == 200) for v in campus_only.values())
R.record('UAT-COM-005', 'communications', 'Campus-only staff (librarian@A1, cashier@A1, principal@A2)', 'A', 'Campus grants only', 'current Employees; role grants Campus-scoped only',
         'Staff announcement: emailed? in-app consumable? calendar?', 'A Staff recipient can consume what is sent to them', campus_only, consistent, defect=None if consistent else 'ORG10-D02')
state('campusOnly', campus_only)

# ---------------------------------------------------------------- Events
def event(key, fields):
    if state(key):
        return state(key)
    r = co.post('/app/communications/events', fields)
    eid = re.search(r'/events/([0-9a-f-]{36})', r.location or '')
    if not eid:
        state(key + 'Err', co.errors(r))
        return None
    eid = eid.group(1)
    co.post(f'/app/communications/events/{eid}/submit-approval')
    approve_pending('event', 'eventId', eid)
    co.post(f'/app/communications/events/{eid}/publish')
    state(key, eid)
    return eid


d1 = (TODAY + datetime.timedelta(days=7)).isoformat()
d2 = (TODAY + datetime.timedelta(days=9)).isoformat()
ev_staff = event('evStaff', {'title': 'UAT staff development day', 'body': 'Training.', 'date_mode': 'all_day', 'starts_on': d1, 'ends_on': d1, 'audience_type': 'staff'})
ev_fam = event('evFamily', {'title': 'UAT Grade 6 A parents meeting', 'body': 'Meet the teachers.', 'date_mode': 'timed', 'starts_at': f'{d2}T09:00', 'ends_at': f'{d2}T11:00', 'audience_type': 'section', 'section_id': g6a})
ev_cancel = event('evCancel', {'title': 'UAT cancelled assembly', 'body': 'Assembly.', 'date_mode': 'all_day', 'starts_on': d1, 'audience_type': 'school_wide'})
if ev_cancel and once('ev-cancel'):
    co.post(f'/app/communications/events/{ev_cancel}/cancel')
evs = {k: co.get(f'/app/communications/events/{v}').props.get('event', {}).get('status') if v else state(k + 'Err') for k, v in [('evStaff', ev_staff), ('evFamily', ev_fam), ('evCancel', ev_cancel)]}
R.record('UAT-EVT-001', 'events', 'communications_coordinator -> principal', 'A', 'whole-School', '-', 'all-day Staff, timed Section (G6-A guardians), School-wide then cancel; submit, approve, publish', 'Published / published / cancelled', evs, evs == {'evStaff': 'published', 'evFamily': 'published', 'evCancel': 'cancelled'})
cal = json.dumps(t.get('/app/communications/calendar').props.get('events'))
R.record('UAT-EVT-002', 'events', 'teacher', 'A', 'own', 'current staff', 'staff calendar', 'Staff Event + cancelled School-wide Event (marked); never the family Event', ('staff development' in cal, 'parents meeting' in cal, 'cancelled assembly' in cal), 'staff development' in cal and 'parents meeting' not in cal and 'cancelled assembly' in cal)
g = s('guardian01@example.test')
pcal = json.dumps(g.get('/app/portal/calendar').props.get('events'))
pin = [i['title'] for i in g.get('/app/portal/communications').props.get('items', [])]
R.record('UAT-EVT-003', 'events+portal', 'guardian01 (child in G6-A)', 'A', 'guardian', 'portal (development only, E46)', 'portal calendar + inbox', 'Family Event and family note visible; never staff mail or Staff Event', ('parents meeting' in pcal, 'staff development' in pcal, pin[:4]), 'parents meeting' in pcal and 'staff development' not in pcal and 'UAT Grade 6 A family note' in pin and 'UAT staff briefing' not in pin)
self_dec = None
if once('ev-selfdecide'):
    r = co.post('/app/communications/events', {'title': 'UAT self-decide probe', 'body': 'x', 'date_mode': 'all_day', 'starts_on': d2, 'audience_type': 'school_wide'})
    eid = re.search(r'/events/([0-9a-f-]{36})', r.location or '').group(1)
    co.post(f'/app/communications/events/{eid}/submit-approval')
    q = data(pr.get('/app/communications/approvals').props['requests'])
    req = next(x for x in q if x.get('eventId') == eid)
    self_dec = s('school.admin@example.test').post(f"/app/communications/approvals/{req['id']}/approve", {})
    state('evSelf', {'eid': eid, 'admin': self_dec.status})
    pubr = co.post(f'/app/communications/events/{eid}/publish')
    state('evSelfPub', co.get(f'/app/communications/events/{eid}').props['event']['status'])
R.record('UAT-EVT-004', 'events', 'communications_coordinator', 'A', 'whole-School', 'pending Event', 'publish before approval', 'An Event never publishes without an approval', state('evSelfPub'), state('evSelfPub') == 'pending_approval')

# Events never alter Attendance expectation
adm = s('school.admin@example.test')
before = adm.get(f'/app/attendance/registers?date={TODAY.isoformat()}').props.get('counts')
event('evHoliday', {'title': 'Holiday - school closed (UAT)', 'body': 'Not an attendance fact.', 'date_mode': 'all_day', 'starts_on': (TODAY + datetime.timedelta(days=1)).isoformat(), 'audience_type': 'school_wide'})
tomorrow = (TODAY + datetime.timedelta(days=1)).isoformat()
reg_t = adm.get(f'/app/attendance/registers?date={tomorrow}')
after = adm.get(f'/app/attendance/registers?date={TODAY.isoformat()}').props.get('counts')
R.record('UAT-EVT-005', 'events+attendance', 'school_admin', 'A', 'whole-School', 'published "Holiday" Event tomorrow', 'register status today and tomorrow', 'Register expectation unchanged by Events (OD-9.3)', (before, after, reg_t.status, (reg_t.props.get('counts'))), before == after)

# ---------------------------------------------------------------- Consumer capability separation (direct URLs)
matrix = {}
for email in ['teacher@example.test', 'uat.selfservice@example.test', 'communications@example.test', 'principal@example.test']:
    sess = s(email)
    matrix[email.split('@')[0]] = {p: sess.get(p).status for p in ['/app/communications', '/app/communications/calendar', '/app/communications/announcements', '/app/communications/sent', '/app/communications/events']}
exp = {'teacher': [200, 200, 403, 403, 403], 'uat.selfservice': [200, 200, 403, 403, 403], 'communications': [200, 200, 200, 200, 200], 'principal': [200, 200, 200, 200, 200]}
R.record('UAT-COM-006', 'communications', 'teacher / self-service / coordinator / principal', 'A', 'whole-School', 'inbox.view vs events.view vs view vs announce', 'direct URLs: Hub, calendar, School list, Sent, Event management',
         'Consumers get inbox + calendar only; administrators keep the rest', matrix, all(list(matrix[k].values()) == v for k, v in exp.items()))

# ---------------------------------------------------------------- Staff + Guardian persona
sg = s('uat.staffguardian@example.test')
hub = [i['title'] for i in sg.get('/app/communications').props.get('items', [])]
portal = [i['title'] for i in sg.get('/app/portal/communications').props.get('items', [])]
cal = json.dumps(sg.get('/app/communications/calendar').props.get('events'))
pcal2 = json.dumps(sg.get('/app/portal/calendar').props.get('events'))
R.record('UAT-XD-SG-001', 'cross-domain', 'Staff+Guardian', 'A', 'staff + guardian', 'teacher + linked Guardian (child not in G6-A)', 'Hub, portal inbox, staff calendar, portal calendar',
         'Staff mail/Events on staff surfaces; portal never shows staff mail; no duplicates', {'hub': hub[:5], 'portal': portal[:5], 'staffCal': 'staff development' in cal, 'portalStaffEvent': 'staff development' in pcal2},
         'UAT staff briefing' in hub and hub.count('UAT staff briefing') == 1 and 'UAT staff briefing' not in portal and 'staff development' in cal and 'staff development' not in pcal2)
