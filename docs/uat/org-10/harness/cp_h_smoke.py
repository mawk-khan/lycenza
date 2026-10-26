"""ORG.10-H: final integrated real-HTTP smoke on the final candidate (one pass per persona)."""
import sys, os, json
sys.path.insert(0, os.path.dirname(__file__))
from uat import Session, Register, fixture, state

fx = fixture()
A = fx['schools']['A']
R = Register('ORG.10-H')
M = state('mover')
CHECKS = {
    'school.admin@example.test': [('/app', 200), ('/app/students', 200), ('/app/finance/outstanding', 200), ('/app/compliance/audit-log', 200), ('/app/settings/staff', 200), ('/app/communications/announcements', 200)],
    'teacher@example.test': [('/app/my-attendance/take', 200), ('/app/communications', 200), (f"/app/communications/announcements/{state('annStaff')}", 200), ('/app/communications/calendar', 200), ('/app/my-leave', 200), ('/app/students', 403), ('/app/communications/announcements', 403)],
    'guardian01@example.test': [('/app/portal/communications', 200), ('/app/portal/calendar', 200), ('/app/students', 403), ('/app/communications', 403)],
    'finance.officer@example.test': [('/app/finance/outstanding', 200), (f"/app/finance/charges/{state('chLab')}", 200), (f"/app/finance/ledger-accounts/{state('receivable')}/ledger", 200), ('/app/students', 403)],
    'uat.cashier.a1@example.test': [(f"/app/finance/charges/{state('chLab')}", 200), (f"/app/finance/charges/{state('chNorth')}", 404), ('/app/finance/ledger-accounts', 403)],
    'uat.hr@example.test': [('/app/hr/employees', 200), ('/app/staff-attendance', 200), ('/app/leave/requests', 200), ('/app/finance/outstanding', 403)],
    'canteen.operator@example.test': [('/app/inventory-stock', 200), ('/app/inventory-stock/monthly', 200), ('/app/inventory-stock/register', 200), ('/app/students', 403)],
    'transport.operator@example.test': [('/app/transport/routes', 200), ('/app/transport/assignments', 200), ('/app/transport/assignments/import', 200), ('/app/finance/outstanding', 403)],
    'communications@example.test': [('/app/communications', 200), ('/app/communications/announcements', 200), ('/app/communications/events', 200), ('/app/communications/calendar', 200), ('/app/communications/approvals', 403)],
}
for email, checks in CHECKS.items():
    sess = Session(email, A).login(force=True)
    got = []
    for path, want in checks:
        r = sess.get(path)
        code = sess.follow(r).status if r.status == 302 and (r.location or '').replace('https://lycenza.ddev.site', '').startswith(path + '/') else r.status
        got.append((path, code, want))
    bad = [g for g in got if g[1] != g[2]]
    R.record(f'UAT-SMOKE-{email.split("@")[0]}', 'final-smoke', email.split('@')[0], 'A', 'grants', 'fresh login (forced)', f'{len(checks)} direct URLs', 'Each opens or is refused exactly as authorized', bad or f'{len(got)} ok', not bad)
