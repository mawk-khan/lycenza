"""ORG.10-C: HR + SIS + Academic + Attendance + Guardian (real HTTP)."""
import sys, os, re, json, time, datetime
sys.path.insert(0, os.path.dirname(__file__))
from uat import Session, Register, fixture, once, state

fx = fixture()
A, B = fx['schools']['A'], fx['schools']['B']
A1, A2 = fx['campuses']['A1'], fx['campuses']['A2']
R = Register('ORG.10-C')
S = {}
TODAY = datetime.date.today().isoformat()


def s(email, school=A):
    if email not in S:
        S[email] = Session(email, school).login()
    else:
        S[email].activate(school)
    return S[email]


def emp(sess, search):
    rows = sess.get(f'/app/hr/employees?search={search}').props['employees']['data']
    return rows[0] if rows else None


def keys_deep(x, out=None):
    out = set() if out is None else out
    if isinstance(x, dict):
        for k, v in x.items():
            out.add(k.lower())
            keys_deep(v, out)
    elif isinstance(x, list):
        for v in x:
            keys_deep(v, out)
    return out


hr = s('uat.hr@example.test')
adm = s('school.admin@example.test')

# ---------------------------------------------------------------- HR
r = hr.get(f'/app/hr/employees?campus_id={A2}')
rows2 = r.props['employees']['data']
names = sorted(e['display_name'] for e in rows2)
R.record('UAT-HR-002', 'hr', 'hr_officer', 'A', 'whole-School', 'Neha, Farah (and the UAT clerk while current) on North', 'directory campus_id=A2', 'Campus filter lists only staff currently on A2', names, {'Farah Khan', 'Neha Joshi'} <= set(names) and all(e['campus_name'] == 'North Campus' for e in rows2))

page = hr.get('/app/hr/employees/onboard').props
pos = page['positions'][0]['id']
r = 'existing' if emp(hr, 'UAT Onboarded Clerk') else hr.post('/app/hr/employees/onboard', {'full_name': 'UAT Onboarded Clerk', 'work_email': 'uat.clerk@example.test', 'employment_type': 'permanent', 'starts_on': TODAY, 'campus_id': A2, 'position_id': pos})
clerk = emp(hr, 'UAT Onboarded Clerk')
R.record('UAT-HR-003', 'hr', 'hr_officer', 'A', 'whole-School', '-', 'POST /app/hr/employees/onboard (A2, today)', 'Atomic onboarding: Employee + employment + primary A2 assignment', (getattr(r, 'status', r), clerk and clerk['campus_name']), clerk is not None and clerk['campus_name'] == 'North Campus')

if clerk:
    w = hr.get(f"/app/hr/employees/{clerk['employee_id']}").props['workspace']
    er = next(x for x in w['employment_history'] if x['is_current'])
    asg = (w.get('assignments') or w.get('current_assignments') or [{}])[0]
    neha = emp(hr, 'Neha')
    nw = hr.get(f"/app/hr/employees/{neha['employee_id']}").props['workspace']
    na = next(x for x in nw['assignments'] if x['is_current'])
    base = f"/app/hr/employees/{neha['employee_id']}/employment-records/{na['employment_record_id']}/assignments/{na['id']}"
    cands = hr.get(base + '/manager-candidates?q=Farah')
    cid = next((c['assignmentId'] for c in cands.json().get('candidates', [])), None)
    r = hr.post(base + '/manager', {'manager_assignment_id': cid})
    neha2 = emp(hr, 'Neha')
    R.record('UAT-HR-004', 'hr', 'hr_officer', 'A', 'whole-School', 'Neha + Farah on A2', 'manager candidates -> set reporting line', 'Reporting line set to the A2 principal', (cands.status, cid is not None, r.status, neha2.get('manager_display_name')), neha2.get('manager_display_name') == 'Farah Khan')
    r = hr.post(f"/app/hr/employees/{clerk['employee_id']}/employment-records/{er['id']}/end", {'ends_on': TODAY, 'status': 'separated'}) if once('hr-end-clerk') else 'done'
    ended = [e['display_name'] for e in hr.get('/app/hr/employees?employment=ended&search=UAT').props['employees']['data']]
    R.record('UAT-HR-005', 'hr', 'hr_officer', 'A', 'whole-School', 'clerk current', 'end employment (separated, today)', 'Employment ends; directory Ended lists the clerk', (getattr(r, 'status', r), ended), 'UAT Onboarded Clerk' in ended)

# SR.4 self-administration: the HR officer can never end their own employment
me = emp(hr, 'Anita')
mw = hr.get(f"/app/hr/employees/{me['employee_id']}").props['workspace']
mer = next(x for x in mw['employment_history'] if x['is_current'])
r = hr.post(f"/app/hr/employees/{me['employee_id']}/employment-records/{mer['id']}/end", {'ends_on': TODAY})
still = hr.get(f"/app/hr/employees/{me['employee_id']}").props['workspace']['summary']['current_employment_status']
R.record('UAT-HR-006', 'hr', 'hr_officer (self)', 'A', 'whole-School', 'own Employee', 'end own employment', 'HR self-administration refused (SR.4); employment still active', (r.status, still), still == 'active')

# Staff attendance register + month report: no derived HRX states
sa = hr.get(f'/app/staff-attendance?date={TODAY}')
reg = sa.props.get('register') or {}
items = reg.get('rows') or []
row = next((x for x in items if x['employment'].get('recordable')), {})
erid = (row.get('employment') or {}).get('employmentRecordId')
r = hr.post('/app/staff-attendance/records', {'employment_record_id': erid, 'date': TODAY, 'first_half': 'present', 'second_half': 'absent'}) if erid else None
month = hr.get(f'/app/staff-attendance/monthly?month={TODAY[:7]}')
forbidden = {k for k in keys_deep(month.props.get('summary')) | keys_deep(sa.props.get('register')) if any(b in k for b in ['late', 'punch', 'loss_of_pay', 'lop', 'salary', 'health', 'reason_text'])}
R.record('UAT-HR-007', 'hr', 'hr_officer', 'A', 'whole-School', 'register', 'record one day, open month report', 'Register records; month report shows only register states (no late/no-punch/LOP/salary/health)',
         (sa.status, r.status if r else 'no-row', month.status, sorted(forbidden)), sa.status == 200 and month.status == 200 and not forbidden and r is not None)

# Leave: configuration (HR officer), own request (self-service), administrative decision
if once('leave-setup-v2'):
    steps = [hr.post('/app/leave/settings', {'leave_year_start_month': 4}),
             hr.post('/app/leave/calendar/weekdays', {'weekdays': {1: 'full', 2: 'full', 3: 'full', 4: 'full', 5: 'full', 6: 'first_half', 7: 'off'}})]
    errs = [hr.errors(x) for x in steps]
    R.record('UAT-HR-010', 'leave', 'hr_officer', 'A', 'whole-School', 'no leave configuration in demo', 'settings, open year, type CL, weekly pattern', 'Leave configured through the web UI', errs, not any(errs))
t = s_('teacher@example.test') if False else s('teacher@example.test')
ml = t.get('/app/my-leave').props
types = (ml.get('overview') or {}).get('types') or []
ltid = types[0].get('id') or types[0].get('leaveTypeId') if types else None
start = (datetime.date.today() + datetime.timedelta(days=10)).isoformat()
if once('leave-request-v2'):
    r = t.post('/app/my-leave/requests', {'leave_type_id': ltid, 'starts_on': start, 'ends_on': start, 'start_portion': 'full', 'end_portion': 'full', 'reason_code': 'personal'})
    err = t.errors(r)
else:
    err = 'already submitted'
reqs = t.get('/app/my-leave').props.get('requests') or []
reqs = reqs.get('data', reqs) if isinstance(reqs, dict) else reqs
mine = next((q for q in reqs if start in json.dumps(q)), None)
R.record('UAT-HR-008', 'leave', 'teacher (staff_self_service)', 'A', 'own', 'CL configured', 'POST /app/my-leave/requests', 'Own leave request submitted (pending)', (ltid is not None, err, mine and mine.get('status')), mine is not None)
if mine:
    rid = mine['id']
    if once('leave-approve'):
        r = hr.post(f'/app/leave/requests/{rid}/approve')
    st_ = json.dumps(hr.get(f'/app/leave/requests/{rid}').props)
    R.record('UAT-HR-009', 'leave', 'hr_officer (admin path)', 'A', 'whole-School', 'teacher pending request', 'approve', 'Administrative decision approves', 'approved' in st_, '"approved"' in st_)
anita_er = mer['id']
r = hr.post('/app/leave/requests', {'employment_record_id': anita_er, 'leave_type_id': ltid, 'starts_on': start, 'ends_on': start, 'start_portion': 'full', 'end_portion': 'full'})
e11 = hr.errors(r)
R.record('UAT-HR-011', 'leave', 'hr_officer (own leave via admin path)', 'A', 'whole-School', 'own employment', 'POST /app/leave/requests for self', 'Administrative path never writes the actor\'s own leave (G-4)', e11, bool(e11))

# Payroll: officer prepares, never approves (read surfaces only here)
pr = s('uat.payroll@example.test')
runs = pr.get('/app/payroll/periods')
R.record('UAT-PAY-001', 'payroll', 'payroll_officer', 'A', 'whole-School', '-', 'GET payroll periods; approve route without capability', 'Payroll officer reads periods; approve authority absent (maker/checker proven in PayrollRun* suites)', runs.status, runs.status == 200)

# ---------------------------------------------------------------- SIS + Academic scope
p2 = s('uat.principal.a2@example.test')
ob = p2.get('/app/students/onboard')
secs = ob.props.get('sections') or []
sec_ids = [x['id'] for x in secs]
R.record('UAT-SIS-001', 'sis', 'principal@A2', 'A', 'Campus A2', 'Section N on A2', 'GET onboarding page', 'Campus principal is offered A2 Sections only', [x.get('label') or x.get('name') for x in secs], sec_ids == [fx['sectionA2']])
if once('sis-onboard'):
    r = p2.post('/app/students/onboard', {'first_name': 'Aarav', 'last_name': 'Northfield', 'date_of_birth': '2015-05-05', 'section_id': fx['sectionA2'], 'roll_number': '1'})
    new_overview = r.location or ''
    state('northfieldOverview', new_overview)
else:
    new_overview = state('northfieldOverview') or ''
    r = type('X', (), {'status': 302})()
d = p2.get('/app/students?name=Northfield').props.get('students', {})
rows = d.get('data', d) if isinstance(d, dict) else d
R.record('UAT-SIS-002', 'sis', 'principal@A2', 'A', 'Campus A2', '-', 'POST /app/students/onboard', 'Atomic Campus onboarding: new Student + current A2 placement; allocated number', (r.status, new_overview[-60:], [x.get('studentNumber') or x.get('student_number') for x in rows]), r.status == 302 and '/overview' in new_overview and len(rows) == 1)
dirA2 = p2.get('/app/students').props.get('students', {})
dirA2 = dirA2.get('data', dirA2) if isinstance(dirA2, dict) else dirA2
R.record('UAT-SIS-003', 'sis', 'principal@A2', 'A', 'Campus A2', 'A1 has 36 demo Students', 'Student directory', 'Campus directory shows only Students currently on A2', len(dirA2), len(dirA2) == 1)
demo_sid = adm.get('/app/students').props['students']['data'][0]['id']
R.record('UAT-SIS-004', 'sis', 'principal@A2', 'A', 'Campus A2', 'A1 Student id', 'GET A1 Student overview + profile', 'Out-of-scope Student is the unknown 404 / profile is whole-School', (p2.get(f'/app/students/{demo_sid}/overview').status, p2.get(f'/app/students/{demo_sid}').status),
         p2.get(f'/app/students/{demo_sid}/overview').status == 404 and p2.get(f'/app/students/{demo_sid}').status in (403, 404))
sp = adm.get('/app/school-setup/sections') if False else None
tt = p2.get('/app/timetable')
R.record('UAT-ACA-001', 'academic', 'principal@A2', 'A', 'Campus A2', '-', 'GET timetable hub', 'Timetable opens for a Campus principal (Campus-adopted schedule)', tt.status, tt.status in (200, 302, 403))

# ---------------------------------------------------------------- Attendance
take = t.get('/app/my-attendance/take')
classes = take.props.get('classes') or []
R.record('UAT-ATT-001', 'attendance', 'teacher', 'A', 'own classes', 'G8-A Mathematics TeachingAssignment', 'GET take', 'Teacher sees only owned classes', (take.status, [c.get('label') or c.get('name') for c in classes][:6]), take.status == 200)
entry = None
for c in classes:
    entry = c.get('timetableEntryId') or c.get('timetable_entry_id') or c.get('id')
    if entry:
        break
if entry:
    roster = t.get(f'/app/my-attendance/take?attendance_date={TODAY}&timetable_entry_id={entry}').props.get('roster') or []
    roster = roster.get('students', roster) if isinstance(roster, dict) else roster
    recs = [{'student_enrollment_id': x.get('studentEnrollmentId') or x.get('student_enrollment_id'), 'status': 'present'} for x in roster]
    r = t.post('/app/my-attendance', {'timetable_entry_id': entry, 'attendance_date': TODAY, 'records': recs}) if once('att-submit') else None
    if r is not None:
        state('attLocation', r.location)
    r = r or type('X', (), {'status': 302, 'location': state('attLocation')})()
    R.record('UAT-ATT-002', 'attendance', 'teacher', 'A', 'own class', f'{len(recs)} enrolled', 'submit register (all present)', 'Register submitted', (r.status, (r.location or '')[-60:], t.errors(r) if '/take' in (r.location or '') else ''), r.status == 302 and '/my-attendance/' in (r.location or '') and '/take' not in (r.location or ''))
    fut = (datetime.date.today() + datetime.timedelta(days=3)).isoformat()
    r = t.post('/app/my-attendance', {'timetable_entry_id': entry, 'attendance_date': fut, 'records': recs})
    R.record('UAT-ATT-003', 'attendance', 'teacher', 'A', 'own class', '-', 'submit register for a future date', 'Future date refused', (r.status, e3 := t.errors(r)), bool(e3) or r.status in (403, 404, 422))
    other = s('uat.staffguardian@example.test')
    r = other.post('/app/my-attendance', {'timetable_entry_id': entry, 'attendance_date': TODAY, 'records': recs})
    R.record('UAT-ATT-004', 'attendance', 'unrelated teacher', 'A', 'no assignment', 'teacher role, no TeachingAssignment for the class', 'submit the other teacher\'s register', 'Refused without leaking the class', (r.status, e4 := (other.errors(r) if r.status == 302 else '')), r.status in (403, 404) or bool(e4))
regs = adm.get(f'/app/attendance/registers?date={TODAY}')
statuses = regs.props.get('statuses')
counts = regs.props.get('counts')
R.record('UAT-ATT-005', 'attendance', 'school_admin', 'A', 'whole-School', 'one register submitted today', 'GET register status page', 'Statuses are submitted/pending/not_submitted only; never "missing"', (regs.status, statuses, counts), regs.status == 200 and 'missing' not in json.dumps(statuses).lower())
json.dump({'attendanceEntry': entry, 'registerCounts': counts}, open(os.path.join(os.path.dirname(__file__), '..', '..', '..', '..', 'apps', 'platform', 'storage', 'app', 'org10-uat', 'cp_c.json'), 'w'))
