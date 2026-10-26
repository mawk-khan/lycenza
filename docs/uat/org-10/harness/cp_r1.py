"""ORG.10-R1: retest ORG10-D02 (Campus-only staff consume Staff Communications/Events) on DDEV."""
import sys, os, json
sys.path.insert(0, os.path.dirname(__file__))
from uat import Session, Register, fixture, state

fx = fixture()
A, A1 = fx['schools']['A'], fx['campuses']['A1']
R = Register('ORG.10-R1')
ann, fam = state('annStaff'), state('annFamily')
results = {}
for email in ['uat.librarian.a1@example.test', 'uat.cashier.a1@example.test', 'uat.principal.a2@example.test']:
    s = Session(email, A).login(force=True)
    home = s.get('/app')
    nav = json.dumps(home.props.get('navigation'))
    inbox = [i['title'] for i in s.get('/app/communications').props.get('items', [])]
    cal = json.dumps(s.get('/app/communications/calendar').props.get('events'))
    row = {
        'navHub': '"communications"' in nav, 'navCalendar': 'communications-calendar' in nav,
        'inboxHasBriefing': 'UAT staff briefing' in inbox,
        'announcement': s.get(f'/app/communications/announcements/{ann}').status,
        'staffEvent': 'UAT staff development day' in cal, 'wholeSchoolEvent': 'Holiday - school closed (UAT)' in cal,
        'unreceived': s.get(f'/app/communications/announcements/{fam}').status,
        'adminList': s.get('/app/communications/announcements').status,
        'eventMgmt': s.get('/app/communications/events').status,
        'approvals': s.get('/app/communications/approvals').status,
    }
    if 'cashier' in email:
        row['northCharge'] = s.get(f"/app/finance/charges/{state('chNorth')}").status
        row['mainCharge'] = s.get(f"/app/finance/charges/{state('chLab')}").status
        row['ledger'] = s.get('/app/finance/ledger-accounts').status
    if 'principal' in email:
        sts = s.get('/app/students').props.get('students', {})
        row['students'] = sorted(x.get('studentNumber') for x in (sts.get('data', sts) if isinstance(sts, dict) else sts))
        row['a1StudentOverview'] = s.get(f"/app/students/{state('leaver')['id']}/overview").status
        row['finance'] = s.get('/app/finance/outstanding').status
    results[email.split('@')[0]] = row
    ok = row['navHub'] and row['navCalendar'] and row['inboxHasBriefing'] and row['announcement'] == 200 and row['staffEvent'] and row['wholeSchoolEvent'] \
        and row['unreceived'] == 403 and row['adminList'] == 403 and row['eventMgmt'] == 403 and row['approvals'] == 403 \
        and row.get('northCharge', 404) == 404 and row.get('mainCharge', 200) == 200 and row.get('ledger', 403) == 403 \
        and row.get('a1StudentOverview', 404) == 404 and row.get('finance', 403) == 403
    R.record(f"UAT-R1-{email.split('@')[0]}", 'communications', email.split('@')[0], 'A', 'Campus grant only', 'current Employee; ORG.10 Staff announcement + Events published',
             'fresh login; nav; inbox; announcement; calendar; unreceived; admin/Event/approval; Campus business scope', 'Consumes own Staff Communications/Events; management and other Campus data still refused', row, ok)
all_ok = all(r['announcement'] == 200 and r['staffEvent'] for r in results.values())
R.record('UAT-COM-005', 'communications', 'Campus-only staff (librarian@A1, cashier@A1, principal@A2)', 'A', 'Campus grants only', 'OD-9.7 implemented (ADR 0076 §79)',
         'RETEST: Staff announcement emailed AND in-app consumable; calendar', 'A Staff recipient can consume what is sent to them', results, all_ok, defect='ORG10-D02')
