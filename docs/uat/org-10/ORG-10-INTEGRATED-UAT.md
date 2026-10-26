# ORG.10 — Integrated Local UAT & Release-Readiness Audit (2026-10-26)

Status: **evidence record** (ADR 0076 §78). Local DDEV only, synthetic data,
real HTTPS against `https://lycenza.ddev.site`, real PostgreSQL/RLS, real
queue jobs and Mailpit. No staging, no production, no real data.

## 1. Baseline and environment

- **Start:** `origin/main` = local `main` = `b3b23bf7` (ORG.9D-1R1). Clean tree,
  one worktree, `integration/e16-r2` at `fb29aff5` (untouched), stash
  `phase-5d3-preflight` (untouched). Branch `integration/org-10`.
- **DDEV:** web + db OK; `ddev demo-reset` (guarded demo), then the ORG.10
  fixture (`harness/fixture.php`, demo-guarded) and the harness checkpoints.
- **Reproduce:** `ddev demo-reset`, run the fixture with the command in its
  header, then `python3 -I -B docs/uat/org-10/harness/cp_b.py` … `cp_h_smoke.py`
  in order (re-runnable: each write runs once; local state in
  `apps/platform/storage/app/org10-uat/`, gitignored). Raw register:
  [`register.jsonl`](register.jsonl) (ids masked).
- **Harness:** session login with the TOTP challenge (6 logins/min/IP
  throttle respected), School activation, Inertia page props, XSRF form posts,
  fresh-MFA codes, a JSONL evidence register. Business operations go through
  real routes and canonical services only; the fixture writes identities, role
  grants and MFA factors through the same demo-guarded seams as
  `DemoDataBuilder` (never business rows).

## 2. Module inventory (repository truth: `ModuleCatalogue`)

All 22 optional modules are **enabled** in both demo Schools; the 7 core
modules are always on. None is post-v1; production gating is noted.

| Module | Kind | UAT coverage | Production |
|---|---|---|---|
| Identity & Access, Organization, Compliance & Audit | core | B, G (lifecycle, staff accounts, off-boarding, audit filters) | general gates |
| Academic Structure | core | C (Campus Section scope, timetable lookup) | general gates |
| Student Records | core | C, G (Campus onboarding, transfer, leaver, isolation) | E21 |
| Staff Records | core | C (directory, onboarding, reporting line, end, SR.4) | E33 |
| Documents | core | not separately exercised (covered by owning records) | E21 |
| Admissions | optional | navigation + direct-route only (admissions officer) | E21 |
| Timetable, Student Attendance | optional | C, F (take, future date, unrelated teacher, correction, register status, Events independence) | TCH-L1, E33 |
| Curriculum, LMS, Examinations | optional | navigation only (teacher + principal pages open) | marks refused in production (E42) |
| Finance, Fees & Payments | optional | D (charges, cashier Campus, concessions, corrections, cancel, outstanding, as-at, ledger) | E30–E32, E34 |
| Leave, Staff Attendance | optional | C (configuration, request, decision, G-4, month report) | HRX |
| Payroll | optional | read surfaces only; maker/checker in PHPUnit | OD-8.4, PAY-L1 |
| Library | optional | E, G (title/copies, Campus checkout, check-in after move) | E34 (fines) |
| Transport | optional | E, G (route, rider, bulk CSV, transfer blocker) | general gates |
| Hostel, Canteen, Visitors | optional | smoke (pages/forms) | general gates |
| Inventory | optional | E (receive/issue/transfer/write-off, reconciliation, H1R) | general gates |
| Communications | optional | D, F (fee reminder, Staff/family announcements, Events, consumer separation) | E17–E20, fee-reminder purpose |
| Guardian Portal | optional | F, G (inbox, calendar, fees/attendance, relationship end) | E46 |
| Analytics, Automation | optional | navigation only | analytics export legal-gated |
| Integrations | optional | not exercised (API/webhooks; no UAT surface) | E16/release |

**Least coverage:** Integrations (no web UAT surface), then Curriculum/LMS/
Examinations and Analytics/Automation (navigation + authorization only). They
were not changed since their own closure audits and are carried by the
canonical regression.

## 3. School / Campus topology and personas

- **School A** "Lycenza Demo School" (Asia/Kolkata): **Campus A1** Main
  (demo), **Campus A2** North (ORG.10, via `CampusAdministrationService`) with
  Section *Grade 6 · N*; a temporary Campus created and deactivated in UAT.
- **School B** "Lycenza Demo Annexe School" for isolation; the shared user
  `multi.school` (principal in A, School Admin in B).
- **Personas exercised:** school_admin, principal, principal@A2 (Campus-only),
  teacher, staff_self_service, hr_officer, payroll_officer, hr.payroll
  (HR+sensitive+payroll), accountant, cashier@A1, cashier@A2 (Campus-only),
  librarian (whole-School) and librarian@A1 (Campus-only), transport_coordinator,
  hostel_warden, front_office, stores_officer + canteen_operator,
  admissions_officer, communications_coordinator, Guardian, Student account,
  Platform Super Admin, Platform Manager, Platform Auditor, Group Admin.
- **Special identities:** current teacher; former teacher (separated
  2026-09-30, later off-boarded and erased); future teacher (starts
  2026-12-01); role-only teacher (no Employee); Staff+Guardian (teacher who
  is the activated Guardian of a G6-A Student); current Guardian; ended Guardian
  relationship; leaver Student (LDS-0002); Student moved Main → North
  (LDS-0001); whole-School and Campus-scoped Finance actors; Campus-only staff;
  Communications recipients without management authority; a user in School A
  and B.

## 4. Results

**149 scenarios, 194 executions.** First attempt: 129 PASS, 19 FAIL, 1
OBSERVATION. Of the 19 first-attempt failures, **17 were harness or
test-design errors** (wrong prop name, a one-time flash read twice, a
re-run of an already-applied step, a paginated last line, an assertion that
did not match the documented contract); each was corrected and **retested
PASS** with the same product build. **2 are product findings** (§5).
Final: **146 PASS, 2 open findings, 1 observation**; 33 scenarios retested.
**After ORG.10-R1 (OD-9.7):** ORG10-D02 CLOSED (UAT-COM-005 retested PASS,
plus 3 Campus-only persona retests); ORG10-D01 OPEN UAT-MINOR / ACCEPTED
FOR STAGING TESTING.

| Checkpoint | Scenarios | Result |
|---|---|---|
| ORG.10-B Organization, Platform, Identity, Campus | 45 | all PASS |
| ORG.10-C HR, SIS, Academic, Attendance | 23 | 22 PASS, 1 open (D01) |
| ORG.10-D Finance, Fees, Ledger, Fee reminder | 20 | 19 PASS, 1 observation |
| ORG.10-E Inventory, Transport, Library, operations | 16 | all PASS |
| ORG.10-F Communications, Events, Portal | 14 | 13 PASS, 1 open (D02) |
| ORG.10-G Cross-domain, isolation, audit, retention, security | 21 | all PASS |
| ORG.10-H Validation and final smoke | 10 | all PASS |

Highlights (all PASS):
- **Platform separation:** Platform Manager onboards into `provisioning` but
  cannot open or run activate/suspend/resume/close/reopen; root runs
  provisioning → active → suspended → active with fresh MFA; module
  disable removes access (403) and enable restores it; core modules cannot be
  disabled (404); the Auditor reads Registry + platform audit and changes
  nothing; no platform or Group role reaches any tenant page.
- **Navigation vs authority:** every navigation entry of 20 personas opens;
  out-of-authority URLs are 403/404 for 8 personas.
- **Finance reconciliation:** billed − concession − paid = balance on every
  row; totals = sum of all pages; MOVER's row equals canonical Charge state
  (cancelled excluded, reversed payment re-opens, 1175.00 also in the fee
  reminder email); ledger running balance = opening + cumulative movements on
  all 76 lines over 2 pages; as-at yesterday is empty (all postings today).
- **Campus Finance:** stored Charge Campus decides (Main charge 404 to the
  North cashier, before and after the Student moved); mixed-Campus payment
  refused for a Campus cashier; payment visibility atomic.
- **Assurance / maker-checker:** concession (requester cannot approve; no MFA
  refused), payment correction (requested, executed only by another person
  with MFA), Charge cancellation (MFA + closed reason; paid charge refused),
  write-off (MFA + confirmation + closed reason; key replay counted once),
  Events and fee reminder (creator/preparer never decides), valid MFA never
  creates authority.
- **Inventory:** balances = previous + movements; month view
  opening+received+in−issued−written-off−out = closing = sum of balances;
  no negative stock; Department-only recipient; used Location Campus frozen.
- **Transport bulk import:** mixed file classified (ready, duplicate,
  conflict, invalid) with nothing written; commit adds exactly 2; re-commit of
  the same signed preview adds nothing; a tampered payload is refused.
- **Campus move:** transfer refused while a source-Campus rider assignment is
  active; after ending it the Student moves; the Charge keeps Main; the Main
  librarian checks in the Main loan; the North principal now sees the Student.
- **Isolation:** 12 School A record types are the unknown 404 from School B
  for both the shared user and the School B admin; no School A Communication
  in School B.
- **Communications:** Staff announcement reaches current staff only (never
  former, future, role-only or Employee-less accounts); the teacher receives,
  opens, downloads, searches and cannot administer; capability matrix
  inbox/events/view/announce holds on direct URLs; Events never change
  Attendance expectation; family Event and note reach only the Guardian
  portal; Staff+Guardian personas stay separate.
- **Audit, retention, erasure:** filters narrow, unknown values refused,
  envelope fields only; all sensitive actions audited; eight retention
  prunes run as dry runs and honour undecided periods; a former teacher's
  identity is minimized after off-boarding (refused while the membership was
  active) with no unregistered User link.
- **Stale authority:** a revoked Campus grant is refused on the same live
  session's next request.

## 5. Defect register

| Defect | Severity | Scenario | Behaviour | Expected contract | Root cause | Security | Disposition |
|---|---|---|---|---|---|---|---|
| **ORG10-D01** | UAT-MINOR | UAT-HR-001 | Staff directory "Current" lists a hire whose employment starts 2026-12-01; "Pre-joining" is always empty | ADR 0076 §69 groups by the latest employment **status**; every web path writes a future hire as `active`, so the status grouping cannot express "not yet started" | Directory filter is status-only (as contracted); no web path writes `pre_joining` | none — authority and Staff identity use date-based currency (`CurrentEmployeeAssignments`, `CurrentStaffMembers`) | **OPEN UAT-MINOR / ACCEPTED FOR STAGING TESTING** (owner, ORG.10-R1 §42/§57). Unchanged; listed in the staging handoff |
| **ORG10-D02** | **UAT-MAJOR** | UAT-COM-005 | Current staff whose role grants are **Campus-only** (librarian@A1, cashier@A1, principal@A2) were in the Staff audience and **emailed** Staff announcements, but got **403** on the Hub, the announcement page and the School calendar | A Staff recipient can consume what is sent to them (§16/§46) | Staff audience = HR identity (`CurrentStaffMembers`, role-independent); `communications.inbox.view`/`.events.view` were read through whole-School paths, which never counted a Campus grant (rule 101, `CapabilityResolver::grants()`) | none (failed closed) | **CLOSED** by ORG.10-R1 (owner decision **OD-9.7**, ADR 0076 §79): `ConsumerCapabilities` (exactly the two keys) are activated by an active Campus grant on an active Campus of the same School; tests `CampusOnlyStaffConsumerAccessTest`, `ConsumerCapabilityActivationArchitectureTest`; DDEV retest UAT-COM-005 + UAT-R1-* PASS |

**ORG10-D02 decision (OD-9.7, ORG.10-R1):** option 1 was chosen — the two
consumption keys are satisfied by any active grant of the same School
(whole-School, or Campus-scoped on an active Campus). It is capability
activation only: no Campus adoption, no `CampusScopeCatalogue` or role-template
change, no Campus filtering of own content, and no other key of a Campus grant
reaches a whole-School check (pinned for every Campus-grantable system role).

No code was changed in ORG.10 itself; ORG.10-R1 implemented OD-9.7.

## 6. Observations (not defects)

- **UAT-FEE-005:** the large-batch fee-reminder publish cost is a known ORG.9D-2
  follow-up and cannot be measured on the demo's 2 Guardian accounts.
- The Charge page does not show the stored Charge Campus (Outstanding shows it
  as a filter); the stored Campus is proven by scope behaviour.
- Demo operations-desk accounts have no Employee, so they are (correctly) not
  Staff audience recipients; demo realism, not product.
- Demo Charge form has no configured default receivable/revenue account.
- A whole-School Transport coordinator may assign a North Student to a Main
  route (Campus restriction is for Campus grants only, ORG.7D-5).
- A Guardian with one child is sent straight to that child's portal page.
- Known Communications LOWs carried (ADR 0076 §77.10): unread badge cost at
  volume, Staff+Guardian Hub shows own Guardian mail, GET read markers,
  unescaped ILIKE.

## 7. Known backlog encountered (never classified as defects)

- **V1 outstanding (owner decisions):** ORG10-D02 (Campus-only consumption), ORG10-D01 (date-derived directory state), OD-9.4/OD-9.5 Inventory person recipients/stocktake, D-11 exports, cash/bank book classification (ORG.9B).
- **Production-only:** E17–E20 email provider, E21, E30–E32, E33, E34, E36–E44, E45, E46, E47, HRX gates, fee-reminder email purpose (ADR 0055), M-1, OD-8.4, OD-8.9, E16 successor, PPR staging.
- **Post-v1:** Communications recurrence/automatic reminders, Campus-targeted Events, Student Portal, academic closure calendar, Transport GPS/maps/tracking/capacity, Inventory custody/stocktake/fixed assets/procurement, Library renewal/borrower limits/masters, dues ageing, Trial Balance.
- **Evidence-insufficient locally:** fee-reminder large-batch performance (2 Guardian accounts), DST Event behaviour (demo School is Asia/Kolkata, no DST).
- **Intentionally not adopted:** Communications Campus targeting, Payment Campus, `accountant` Campus scope, marks Campus adoption.

## 8. Validation, smoke, regression

- **Backstop suites (real PostgreSQL):** Inventory issue/write-off/Location races, fee reminder edge cases (zero balance, paid after preview, preference/consent), Library checkout race, teacher Attendance race, user minimization race, raw-SQL RLS isolation, Campus grant invariants, Charge Campus integrity: **70 passed**.
- **Final integrated smoke** (fresh logins): school admin, teacher, Guardian, accountant, cashier@A1, HR officer, stores officer, transport coordinator, communications coordinator — **9/9 PASS**.
- **Full regression:** see ADR 0076 §78.

## 9. Scenario register

Generated from `register.jsonl` (first and final attempt per scenario).

| ID | Checkpoint | Domain | Persona | Scope | Expected | First | Final | Defect |
|---|---|---|---|---|---|---|---|---|
| `UAT-ORG-001` | B | organization | platform_manager | platform | Platform Manager reads the School Registry | PASS | **PASS** |  |
| `UAT-ORG-002` | B | organization | platform_manager | platform | Platform Manager cannot open activate/suspend/resume/close/reopen | PASS | **PASS** |  |
| `UAT-ORG-003` | B | organization | platform_manager | platform | Platform Manager suspend refused; School B stays active | PASS | **PASS** |  |
| `UAT-ORG-004` | B | organization | platform_manager | platform | Platform Manager onboards a School into provisioning | PASS | **PASS** |  |
| `UAT-ORG-005` | B | organization | platform_manager | platform | Platform Manager cannot activate | PASS | **PASS** |  |
| `UAT-ORG-006` | B | organization | platform_admin | platform | Root runs the lifecycle provisioning->active->suspended->active | PASS | **PASS** |  |
| `UAT-ORG-007` | B | organization | platform_manager+annexe_admin | module | Disabling removes access (403), enabling restores it; a core module cannot be disabled (404) | PASS | **PASS** |  |
| `UAT-ORG-008` | B | organization | platform_auditor | platform | Auditor reads registry + platform audit log, changes nothing | PASS | **PASS** |  |
| `UAT-ORG-009-platform_admin` | B | platform-separation | platform_admin | platform | No implicit tenant access: Students/HR/Finance/Inventory/Communications/audit/Transport/Library never 200 | PASS | **PASS** |  |
| `UAT-ORG-009-platform_manager` | B | platform-separation | platform_manager | platform | No implicit tenant access: Students/HR/Finance/Inventory/Communications/audit/Transport/Library never 200 | PASS | **PASS** |  |
| `UAT-ORG-009-platform_auditor` | B | platform-separation | platform_auditor | platform | No implicit tenant access: Students/HR/Finance/Inventory/Communications/audit/Transport/Library never 200 | PASS | **PASS** |  |
| `UAT-ORG-009-group_admin` | B | platform-separation | group_admin | platform | No implicit tenant access: Students/HR/Finance/Inventory/Communications/audit/Transport/Library never 200 | PASS | **PASS** |  |
| `UAT-ID-001-school.admin` | B | identity-navigation | school.admin | grants | Every offered navigation entry opens (no impossible navigation) | PASS | **PASS** |  |
| `UAT-ID-001-principal` | B | identity-navigation | principal | grants | Every offered navigation entry opens (no impossible navigation) | PASS | **PASS** |  |
| `UAT-ID-001-teacher` | B | identity-navigation | teacher | grants | Every offered navigation entry opens (no impossible navigation) | PASS | **PASS** |  |
| `UAT-ID-001-uat.selfservice` | B | identity-navigation | uat.selfservice | grants | Every offered navigation entry opens (no impossible navigation) | PASS | **PASS** |  |
| `UAT-ID-001-uat.hr` | B | identity-navigation | uat.hr | grants | Every offered navigation entry opens (no impossible navigation) | PASS | **PASS** |  |
| `UAT-ID-001-uat.payroll` | B | identity-navigation | uat.payroll | grants | Every offered navigation entry opens (no impossible navigation) | PASS | **PASS** |  |
| `UAT-ID-001-finance.officer` | B | identity-navigation | finance.officer | grants | Every offered navigation entry opens (no impossible navigation) | PASS | **PASS** |  |
| `UAT-ID-001-uat.cashier.a1` | B | identity-navigation | uat.cashier.a1 | grants | Every offered navigation entry opens (no impossible navigation) | PASS | **PASS** |  |
| `UAT-ID-001-library.operator` | B | identity-navigation | library.operator | grants | Every offered navigation entry opens (no impossible navigation) | PASS | **PASS** |  |
| `UAT-ID-001-uat.librarian.a1` | B | identity-navigation | uat.librarian.a1 | grants | Every offered navigation entry opens (no impossible navigation) | PASS | **PASS** |  |
| `UAT-ID-001-transport.operator` | B | identity-navigation | transport.operator | grants | Every offered navigation entry opens (no impossible navigation) | PASS | **PASS** |  |
| `UAT-ID-001-hostel.warden` | B | identity-navigation | hostel.warden | grants | Every offered navigation entry opens (no impossible navigation) | PASS | **PASS** |  |
| `UAT-ID-001-reception` | B | identity-navigation | reception | grants | Every offered navigation entry opens (no impossible navigation) | PASS | **PASS** |  |
| `UAT-ID-001-canteen.operator` | B | identity-navigation | canteen.operator | grants | Every offered navigation entry opens (no impossible navigation) | PASS | **PASS** |  |
| `UAT-ID-001-uat.admissions` | B | identity-navigation | uat.admissions | grants | Every offered navigation entry opens (no impossible navigation) | PASS | **PASS** |  |
| `UAT-ID-001-communications` | B | identity-navigation | communications | grants | Every offered navigation entry opens (no impossible navigation) | PASS | **PASS** |  |
| `UAT-ID-001-uat.principal.a2` | B | identity-navigation | uat.principal.a2 | grants | Every offered navigation entry opens (no impossible navigation) | PASS | **PASS** |  |
| `UAT-ID-001-guardian01` | B | identity-navigation | guardian01 | guardian | Portal Attendance/Fees open (single child redirects to the child page by design) | FAIL | **PASS** (retested) |  |
| `UAT-ID-001-student` | B | identity-navigation | student | grants | Every offered navigation entry opens (no impossible navigation) | PASS | **PASS** |  |
| `UAT-ID-001-hr.payroll` | B | identity-navigation | hr.payroll | grants | Every offered navigation entry opens (no impossible navigation) | PASS | **PASS** |  |
| `UAT-ID-002-teacher` | B | identity-direct-route | teacher | grants | Direct URLs refused (403/404), never 200 | PASS | **PASS** |  |
| `UAT-ID-002-uat.selfservice` | B | identity-direct-route | uat.selfservice | grants | Direct URLs refused (403/404), never 200 | PASS | **PASS** |  |
| `UAT-ID-002-library.operator` | B | identity-direct-route | library.operator | grants | Direct URLs refused (403/404), never 200 | PASS | **PASS** |  |
| `UAT-ID-002-uat.cashier.a1` | B | identity-direct-route | uat.cashier.a1 | grants | Direct URLs refused (403/404), never 200 | PASS | **PASS** |  |
| `UAT-ID-002-finance.officer` | B | identity-direct-route | finance.officer | grants | Direct URLs refused (403/404), never 200 | PASS | **PASS** |  |
| `UAT-ID-002-guardian01` | B | identity-direct-route | guardian01 | grants | Direct URLs refused (403/404), never 200 | PASS | **PASS** |  |
| `UAT-ID-002-student` | B | identity-direct-route | student | grants | Direct URLs refused (403/404), never 200 | PASS | **PASS** |  |
| `UAT-ID-002-principal` | B | identity-direct-route | principal | grants | Direct URLs refused (403/404), never 200 | PASS | **PASS** |  |
| `UAT-ID-003a` | B | identity | school_admin | whole-School | Staff accounts lists the role-only member | PASS | **PASS** |  |
| `UAT-ID-003` | B | identity | school_admin -> role-only teacher | membership | Suspended membership cannot enter the School or reach teacher pages | PASS | **PASS** |  |
| `UAT-CAM-001` | B | campus | school_admin | whole-School | Campus created active, then inactive (never deleted) | PASS | **PASS** |  |
| `UAT-CAM-002` | B | campus | principal@A2 | Campus A2 | Campus principal gets no access administration/School configuration/Finance | PASS | **PASS** |  |
| `UAT-CAM-003` | B | campus | cashier@A1 | Campus A1 | A Campus filter is never permission: no A2 rows | PASS | **PASS** |  |
| `UAT-HR-001` | C | hr | hr_officer | whole-School | Former teacher under Ended; future hire NOT under Current; Pre-joining lists future hires | FAIL | **FAIL** | ORG10-D01 |
| `UAT-HR-002` | C | hr | hr_officer | whole-School | Campus filter lists only staff currently on A2 | PASS | **PASS** (retested) |  |
| `UAT-HR-003` | C | hr | hr_officer | whole-School | Atomic onboarding: Employee + employment + primary A2 assignment | PASS | **PASS** (retested) |  |
| `UAT-HR-004` | C | hr | hr_officer | whole-School | Reporting line set to the A2 principal | FAIL | **PASS** (retested) |  |
| `UAT-HR-005` | C | hr | hr_officer | whole-School | Employment ends; directory Ended lists the clerk | PASS | **PASS** (retested) |  |
| `UAT-HR-006` | C | hr | hr_officer (self) | whole-School | HR self-administration refused (SR.4); employment still active | PASS | **PASS** (retested) |  |
| `UAT-HR-007` | C | hr | hr_officer | whole-School | Register records; month report shows only register states (no late/no-punch/LOP/salary/health) | FAIL | **PASS** (retested) |  |
| `UAT-HR-010` | C | leave | hr_officer | whole-School | Leave configured through the web UI | FAIL | **PASS** (retested) |  |
| `UAT-HR-008` | C | leave | teacher (staff_self_service) | own | Own leave request submitted (pending) | FAIL | **PASS** (retested) |  |
| `UAT-HR-011` | C | leave | hr_officer (own leave via admin path) | whole-School | Administrative path never writes the actor's own leave (G-4) | FAIL | **PASS** (retested) |  |
| `UAT-PAY-001` | C | payroll | payroll_officer | whole-School | Payroll officer reads periods; approve authority absent (maker/checker proven in PayrollRun* suites) | PASS | **PASS** (retested) |  |
| `UAT-SIS-001` | C | sis | principal@A2 | Campus A2 | Campus principal is offered A2 Sections only | PASS | **PASS** (retested) |  |
| `UAT-SIS-002` | C | sis | principal@A2 | Campus A2 | Atomic Campus onboarding: new Student + current A2 placement; allocated number | PASS | **PASS** (retested) |  |
| `UAT-SIS-003` | C | sis | principal@A2 | Campus A2 | Campus directory shows only Students currently on A2 | PASS | **PASS** (retested) |  |
| `UAT-HR-009` | C | leave | hr_officer (admin path) | whole-School | Administrative decision approves | PASS | **PASS** |  |
| `UAT-SIS-004` | C | sis | principal@A2 | Campus A2 | Out-of-scope Student is the unknown 404 / profile is whole-School | PASS | **PASS** |  |
| `UAT-ACA-001` | C | academic | principal@A2 | Campus A2 | Lookup never returns an A1 Section | FAIL | **PASS** (retested) |  |
| `UAT-ATT-001` | C | attendance | teacher | own classes | Teacher sees only owned classes | PASS | **PASS** |  |
| `UAT-ATT-002` | C | attendance | teacher | own class | Register submitted | PASS | **PASS** |  |
| `UAT-ATT-003` | C | attendance | teacher | own class | Future date refused | PASS | **PASS** |  |
| `UAT-ATT-004` | C | attendance | unrelated teacher | no assignment | Refused without leaking the class | PASS | **PASS** |  |
| `UAT-ATT-005` | C | attendance | school_admin | whole-School | Statuses are submitted/pending/not_submitted only; never "missing" | PASS | **PASS** |  |
| `UAT-ATT-006` | C | attendance | teacher | own register | Correction applied with a closed reason; an unknown reason is refused | PASS | **PASS** |  |
| `UAT-FIN-001` | D | finance | accountant | whole-School | billed - concession - paid = balance per row; page totals = sum of all rows | PASS | **PASS** (retested) |  |
| `UAT-FIN-002` | D | finance | accountant (+cashiers as probes) | whole-School | Stored Campus = placement Campus: Main charge visible on A1 only, North charge on A2 only | FAIL | **PASS** (retested) |  |
| `UAT-FIN-003` | D | finance | cashier@A2 | Campus A2 | Out-of-scope Charge is the unknown 404 | PASS | **PASS** (retested) |  |
| `UAT-FIN-004` | D | finance | cashier@A1 | Campus A1 | Payment recorded; receipt issued | PASS | **PASS** (retested) |  |
| `UAT-FIN-005` | D | finance | cashier@A1 | Campus A1 | Receipt with number | PASS | **PASS** (retested) |  |
| `UAT-FIN-006` | D | finance | cashier@A1 | Campus A1 | Mixed-Campus payment refused for a Campus actor (generic) | PASS | **PASS** (retested) |  |
| `UAT-FIN-007` | D | finance | cashier@A2 | Campus A2 | Payment visible only when every Charge is in scope (404 otherwise) | PASS | **PASS** (retested) |  |
| `UAT-FIN-008` | D | fees | accountant -> school_admin | whole-School | Requester cannot approve (no authority); approval needs fresh MFA; another person approves | PASS | **PASS** (retested) |  |
| `UAT-FIN-009` | D | payments | accountant -> school_admin | whole-School | Correction is requested, never self-approved; another person executes it (reversal recorded) | PASS | **PASS** |  |
| `UAT-FIN-010` | D | fees | school_admin | whole-School | Cancellation needs fresh MFA + closed reason; a Charge with standing payments cannot be cancelled | PASS | **PASS** |  |
| `UAT-FIN-011` | D | finance | accountant | whole-School | Outstanding row = canonical Charge state (cancelled excluded, reversed payment re-opens) | PASS | **PASS** |  |
| `UAT-FIN-012` | D | finance | accountant | whole-School | As-at yesterday shows no debt posted today (posting-date clock) | FAIL | **PASS** (retested) |  |
| `UAT-FIN-013` | D | finance | school_admin | whole-School | Campus filter by stored Charge Campus; NULL-Campus charges only under "no campus" | PASS | **PASS** |  |
| `UAT-FIN-014` | D | finance | cashier@A1 | Campus A1 | Campus cashier never sees North debt | PASS | **PASS** |  |
| `UAT-FIN-015` | D | ledger | accountant | whole-School | Every running balance = opening + cumulative movements; last = closing; deterministic order | FAIL | **PASS** (retested) |  |
| `UAT-FEE-001` | D | fee-reminder | school_admin | whole-School | Preview shows Guardians reached, Students without a Guardian account counted, figures preparer-only | PASS | **PASS** |  |
| `UAT-FEE-002` | D | fee-reminder | principal | whole-School | Approver never sees the preparer's figures | PASS | **PASS** |  |
| `UAT-FEE-003` | D | fee-reminder | school_admin -> principal | whole-School | Always approved by someone else; preparer cannot decide; published | PASS | **PASS** |  |
| `UAT-FEE-004` | D | fee-reminder | Mailpit | whole-School | One email per current Guardian account, only their Students, amount = Outstanding (Aarav 1175.00), subject without amount, no Student account | PASS | **PASS** |  |
| `UAT-FEE-005` | D | fee-reminder | school_admin | whole-School | Large-batch publish cost is a known ORG.9D-2 follow-up; not re-measurable on 2 recipients locally | OBSERVATION | **OBSERVATION** |  |
| `UAT-INV-001` | E | inventory | stores_officer | whole-School | Balances = previous + movements; replayed issue counted once | PASS | **PASS** |  |
| `UAT-INV-002` | E | inventory | stores_officer | whole-School | Write-off needs fresh MFA AND confirmation (direct-assured) | PASS | **PASS** |  |
| `UAT-INV-003` | E | inventory | stores_officer | whole-School | Sufficiency refused; never negative stock | PASS | **PASS** |  |
| `UAT-INV-004` | E | inventory | stores_officer | whole-School | Issue carries the Department only; write-off carries its closed reason; no person on a movement | PASS | **PASS** |  |
| `UAT-INV-005` | E | inventory | stores_officer | whole-School | opening+received+transferredIn-issued-writtenOff-transferredOut = closing = Main 51 + North 5 | FAIL | **PASS** (retested) |  |
| `UAT-INV-006` | E | inventory | stores_officer | whole-School | Register narrows to the Location (filter, not permission) | PASS | **PASS** |  |
| `UAT-INV-007` | G | inventory | stores_officer | whole-School | Web Location update accepts only name/status (Campus never editable on the web); Campus unchanged; DB freeze proven by InventoryLocationCampusFreeze suites | PASS | **PASS** (retested) |  |
| `UAT-TRN-001` | E | transport | transport_coordinator | whole-School | Single rider assignment active | PASS | **PASS** |  |
| `UAT-TRN-002` | E | transport | transport_coordinator | whole-School | Each row classified; not committable; nothing written by preview | PASS | **PASS** |  |
| `UAT-TRN-003` | E | transport | transport_coordinator | whole-School | Commit adds exactly 2; retry adds nothing; re-preview shows already_assigned | FAIL | **PASS** (retested) |  |
| `UAT-TRN-004` | E | transport | transport_coordinator | whole-School | Signature binds the rows: refused, nothing written | PASS | **PASS** |  |
| `UAT-LIB-001` | E | library | librarian@A1 | Campus A1 | Main copy lends; out-of-scope North copy is the unknown 404 (canonical envelope); no loan written | FAIL | **PASS** (retested) |  |
| `UAT-LIB-002` | E | library | librarian | whole-School | Active loans list with overdue flag (none overdue: due in 14 days) | PASS | **PASS** |  |
| `UAT-OPS-001` | E | visitors | front_office | whole-School | Front office reaches directory and check-in with Campus choices | PASS | **PASS** |  |
| `UAT-OPS-002` | E | hostel | hostel_warden | whole-School | Hostel warden reads residencies | PASS | **PASS** |  |
| `UAT-OPS-003` | E | canteen | canteen_operator | whole-School | Canteen order form offers outlets | PASS | **PASS** |  |
| `UAT-TRN-005` | E | transport | transport_coordinator | whole-School | Whole-School coordinator may assign across Campuses (Campus restriction applies to Campus grants only, ORG.7D-5) | PASS | **PASS** |  |
| `UAT-COM-001` | F | communications | communications_coordinator -> principal | whole-School | Published; snapshot taken at publish | PASS | **PASS** |  |
| `UAT-COM-002` | F | communications | Mailpit | whole-School | Email reaches current staff only: never former/future/role-only/no-Employee accounts | PASS | **PASS** |  |
| `UAT-COM-003` | F | communications | teacher | own | Teacher receives, opens, downloads, searches; School list 403; never the family note | PASS | **PASS** |  |
| `UAT-COM-004-former` | F | communications | former teacher | own | Never snapshotted: not in inbox; direct URL refused | PASS | **PASS** |  |
| `UAT-COM-004-future` | F | communications | future teacher | own | Never snapshotted: not in inbox; direct URL refused | PASS | **PASS** |  |
| `UAT-COM-004-role-only` | F | communications | role-only (suspended in B) | own | Never snapshotted: not in inbox; direct URL refused | PASS | **PASS** |  |
| `UAT-COM-005` | R1 | communications | Campus-only staff (librarian@A1, cashier@A1, principal@A2) | Campus grants only | A Staff recipient can consume what is sent to them | FAIL | **PASS** (retested) | ORG10-D02 |
| `UAT-EVT-001` | F | events | communications_coordinator -> principal | whole-School | Published / published / cancelled | PASS | **PASS** |  |
| `UAT-EVT-002` | F | events | teacher | own | Staff Event + cancelled School-wide Event (marked); never the family Event | PASS | **PASS** |  |
| `UAT-EVT-003` | F | events+portal | guardian01 (child in G6-A) | guardian | Family Event and family note visible; never staff mail or Staff Event | PASS | **PASS** |  |
| `UAT-EVT-004` | F | events | principal (creator + approver) | whole-School | Creator can never approve own Event; publish refused while pending | FAIL | **PASS** (retested) |  |
| `UAT-EVT-005` | F | events+attendance | school_admin | whole-School | Register expectation unchanged by Events (OD-9.3) | PASS | **PASS** |  |
| `UAT-COM-006` | F | communications | teacher / self-service / coordinator / principal | whole-School | Consumers get inbox + calendar only; administrators keep the rest | PASS | **PASS** |  |
| `UAT-XD-SG-001` | F | cross-domain | Staff+Guardian | staff + guardian | Staff mail/Events on staff surfaces; portal never shows staff mail; no duplicates | PASS | **PASS** |  |
| `UAT-XD-MOVE-001` | G | cross-domain | school_admin | enrollments.manage on both Campuses | Transfer refused generically while a source-Campus rider assignment is active | PASS | **PASS** |  |
| `UAT-XD-MOVE-002` | G | cross-domain | school_admin | both Campuses | Current placement is North; source placement ended (immutable rows) | PASS | **PASS** |  |
| `UAT-XD-MOVE-003` | G | cross-domain | cashier@A1 / cashier@A2 | Campus | Charge Campus is stored history: still Main (A1 200, A2 404) | PASS | **PASS** |  |
| `UAT-XD-MOVE-004` | G | cross-domain | librarian@A1 | Campus A1 | Return follows the loan Campus, never the Student's current Campus | PASS | **PASS** |  |
| `UAT-XD-MOVE-005` | G | cross-domain | principal@A2 | Campus A2 | North principal now sees MOVER (current placement) | PASS | **PASS** |  |
| `UAT-XD-LIFE-001` | G | cross-domain | school_admin | whole-School | Leaver recorded with a lifecycle reason | PASS | **PASS** |  |
| `UAT-XD-LIFE-002` | G | cross-domain | accountant | whole-School | Leaver debt stays visible and collectable | PASS | **PASS** |  |
| `UAT-XD-LIFE-003` | G | cross-domain | cashier@A1 | Campus A1 | A leaver is collectable on the stored Charge Campus | PASS | **PASS** |  |
| `UAT-GRD-001` | G | guardian | school_admin -> Staff+Guardian | guardian | Delivered history follows the immutable recipient snapshot; Student-linked data follows the current relationship | PASS | **PASS** |  |
| `UAT-XD-ISO-multi.school` | G | isolation | multi.school | School B context | Every cross-School id is the unknown 404 (or refusal); no disclosure | PASS | **PASS** |  |
| `UAT-XD-ISO-annexe.admin` | G | isolation | annexe.admin | School B context | Every cross-School id is the unknown 404 (or refusal); no disclosure | PASS | **PASS** |  |
| `UAT-XD-ISO-hub` | G | isolation | multi.school (principal A, admin B) | School B | No School A Communication appears in School B | PASS | **PASS** |  |
| `UAT-AUD-001` | G | audit | school_admin | whole-School | Family and exact filters narrow; combined filters work; unknown value refused; envelope fields only | FAIL | **PASS** (retested) |  |
| `UAT-AUD-002` | G | audit | school_admin | whole-School | Approvals, assurance, correction, write-off, publish, Event cancel, Transport import audited | FAIL | **PASS** (retested) |  |
| `UAT-AUD-003` | G | audit | principal@A2 | Campus A2 | Campus actor never reads the School audit log | PASS | **PASS** |  |
| `UAT-RET-001` | G | retention | operator (console) | platform | Each runs, deletes nothing, honours undecided periods | PASS | **PASS** |  |
| `UAT-RET-002` | G | erasure | school_admin (off-board) + operator (console) | platform | User identity minimized; no unregistered User link blocks it; School records need a School case | FAIL | **PASS** (retested) |  |
| `UAT-SEC-001` | G | security | cashier@A1 (valid MFA) | Campus A1 | Fresh MFA is provenance, never authority: 403 | PASS | **PASS** |  |
| `UAT-SEC-002` | G | security | school_admin -> cashier@A2 (live session) | Campus A2 | Authority re-evaluated on the next request (no stale grant) | PASS | **PASS** |  |
| `UAT-SEC-003` | G | security | principal@A2 | Campus A2 | No date of birth or Guardian contact on a Campus surface | PASS | **PASS** |  |
| `UAT-VAL-001` | H | validation | PHPUnit (real PostgreSQL) | - | Representative concurrency and database backstops hold | PASS | **PASS** |  |
| `UAT-SMOKE-school.admin` | H | final-smoke | school.admin | grants | Each opens or is refused exactly as authorized | PASS | **PASS** |  |
| `UAT-SMOKE-teacher` | H | final-smoke | teacher | grants | Each opens or is refused exactly as authorized | PASS | **PASS** |  |
| `UAT-SMOKE-guardian01` | H | final-smoke | guardian01 | grants | Each opens or is refused exactly as authorized | PASS | **PASS** |  |
| `UAT-SMOKE-finance.officer` | H | final-smoke | finance.officer | grants | Each opens or is refused exactly as authorized | PASS | **PASS** |  |
| `UAT-SMOKE-uat.cashier.a1` | H | final-smoke | uat.cashier.a1 | grants | Each opens or is refused exactly as authorized | PASS | **PASS** |  |
| `UAT-SMOKE-uat.hr` | H | final-smoke | uat.hr | grants | Each opens or is refused exactly as authorized | PASS | **PASS** |  |
| `UAT-SMOKE-canteen.operator` | H | final-smoke | canteen.operator | grants | Each opens or is refused exactly as authorized | PASS | **PASS** |  |
| `UAT-SMOKE-transport.operator` | H | final-smoke | transport.operator | grants | Each opens or is refused exactly as authorized | PASS | **PASS** |  |
| `UAT-SMOKE-communications` | H | final-smoke | communications | grants | Each opens or is refused exactly as authorized | PASS | **PASS** |  |
| `UAT-R1-uat.librarian.a1` | R1 | communications | uat.librarian.a1 | Campus grant only | Consumes own Staff Communications/Events; management and other Campus data still refused | PASS | **PASS** |  |
| `UAT-R1-uat.cashier.a1` | R1 | communications | uat.cashier.a1 | Campus grant only | Consumes own Staff Communications/Events; management and other Campus data still refused | PASS | **PASS** |  |
| `UAT-R1-uat.principal.a2` | R1 | communications | uat.principal.a2 | Campus grant only | Consumes own Staff Communications/Events; management and other Campus data still refused | PASS | **PASS** |  |
