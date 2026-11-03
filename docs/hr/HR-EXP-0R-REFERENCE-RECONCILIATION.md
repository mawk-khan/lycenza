# HR.EXP-0R — HR reference-pack reconciliation (pack gate) & OD-HR-1

> **Superseded in part by HR.0A** (2026-11-03,
> `HR-0A-ARCHITECTURE-RECONCILIATION.md`).
> - **Superseded:** the pack gate (§3, verdict INCOMPLETE on 335 files),
>   the provisional inventory (§4) and the next-checkpoint decision (§10).
>   The owner's completed pack has 602 PNG plus `HR.zip`, with fingerprint
>   `40ed6a83…1ac3`. The gate is **COMPLETE** and the capability map is
>   `HR-REFERENCE-CAPABILITY-MAP.md`.
> - **Still authoritative:** §6 (the OD-HR-1 analysis and
>   recommendation), §7 (legal / privacy matrix) and §8 (cross-ERP
>   boundaries).
> - **Payroll is not in the vendor's HR module** (HR.0A §1), so OD-HR-1
>   remains a Lycenza integrity decision.
>
> This record is kept as written, below, for its history.

Status (as written): **DRAFT — AUDIT ONLY** · 2026-11-01 · baseline
`main = 7a4068fe` (contains the published SIS 11.R2 closure audit) · no
code, schema, capability, role, test, demo or configuration changed.

**Gate result: the HR pack is INCOMPLETE** (§3). Per the brief, the HR
reference capability map (`HR-REFERENCE-CAPABILITY-MAP.md`) is **not**
built against it, no H1–H11 counts or coverage percentage are produced, and
no implementation wave is chosen from partial evidence. This record keeps
the pack evidence, the exact missing list, a provisional family inventory
(not a map), the repository reconciliation and the dedicated **OD-HR-1**
formulation, which does not depend on the pack.

HR.EXP-0 (`HR-EXP-0-CURRENT-STATE-AUDIT.md`, 2026-10-30) is preserved as
written: it correctly recorded an empty pack folder at the time.

## 1. Baseline and preflight

| Item | Value |
|---|---|
| `ddev env-check` | **PASS** |
| HEAD / local `main` / `origin/main` | `7a4068fe` / `7a4068fe` / `7a4068fe` |
| How the baseline was reached | SIS 11.R2 published docs-only: candidate `7a4068fe`, CI run 41347404926 green (docs lane), `publication-check --push`, `integration/sis-11-r2` deleted |
| Working tree / worktrees / stash | clean (this draft is the only addition) / one / `stash@{0}` untouched |
| Migrations | 363 |
| E16 | `OWNER-0O-E16-2026-11-01` valid through **2026-11-28** (review ~2026-11-20); 0 Critical; 0 Composer advisories |
| HR documentation state | `docs/hr/`: HR.EXP-0 audit, gate register, ownership map, roadmap (draft), demo plan (draft); **no** `HR-REFERENCE-CAPABILITY-MAP.md` |
| HR code since HR.EXP-0 | **unchanged**: no commit touched `app/Domain/{HR,Leave,StaffAttendance,Payroll}` or the Payroll controllers since `c5e3dd23` |

## 2. The pack

| Item | Value |
|---|---|
| Location | owner-held: `…/Lycenza/Screenshots Downloaded/HR` (OneDrive Desktop) |
| Files | **335**, all `.png`, no sub-folders, no temporary or empty files |
| Byte-distinct | 335 (no exact duplicates) |
| Image sizes | 1920 px wide mostly; 70 distinct heights (full-page captures up to 27,705 px) |
| Capture window (file names) | 2026-10-30 21:00 → 2026-11-01 00:25 (capture-tool local time) |
| Last modification | 2026-10-31 21:15 (filesystem); **no change for ~23 h** at audit time |
| Companion zip | none (the SIS and Organisation packs each have one) |
| Provisional fingerprint | `c21e40f7f4330eb14f82e479f6622e08c33ad6506784fb18d139651f8c1bd4d4` (SIS convention: sorted names, name + hex SHA-256 per file). **Not frozen as the HR baseline** (gate INCOMPLETE) |

**Provenance.** The reference school ERP's HR module (the same vendor as
the SIS and ORG.9A packs), one tenant, a system-administrator persona.
Every screen shows the **HR** module selector. File names carry only the
product's browser-tab title. Screen ids `H001`–`H335` follow sorted file
order; the id → file index stays outside Git. No personal value is copied
into this record.

**Inspection method.** All 335 screens were inspected on 6-up contact sheets
(top of page) with full-resolution crops for tall pages, forms and
sensitive screens (four parallel read-only inspections over H001–H084,
H085–H168, H169–H252, H253–H335, each producing a per-screen record; one
sheet re-checked by hand against those records — consistent).

## 3. Completeness gate — **INCOMPLETE**

The product's HR top menu, visible on every screen, is: **Staff Details ·
Attendance · Biometric Attendance · Leaves · Staff Tasks · Reports**. The
pack's coverage by menu:

| Top menu | Captured | Missing |
|---|---|---|
| Staff Details | deep (≈ 230 screens: lists, profile, onboarding, masters, lifecycle, bulk updates, feedback, training, outsourcing, handbook) | the **Staff Documents** tab of the Staff List; the shift page's Unused/Work-Hours shift, Weekly offs, Dashboard and Branch Transfer Staff tabs |
| Attendance | Give Daily Attendance; Manage Shifts & Sessions (partial); HR-home attendance summaries and per-staff attendance reports | the menu's other entries, notably **attendance corrections / regularisation** (an ORG.9A family, SF-HR-13) |
| **Biometric Attendance** | **nothing from this menu** (only identifier fields and the "Biometric Attendance Activated" banner) | devices, punch logs, sync — the whole menu |
| **Leaves** | **nothing from this menu** (only Leave analytics on the HR home and the Leave report under Reports) | leave types, policies, allocation, holidays, application, approval, permissions/compensatory off, balances — the whole menu |
| Staff Tasks | tasks reached from the Staff Wall and the Tasks Monitor | whether the menu has more |
| Reports | one report (Staff Leaves Between Dates) | the report catalogue |
| Payroll | **no screen** (only payroll-adjacent fields: pay type, bank, EPF/UAN/ESI, Form 12C, "Payroll Entity", "HR Payroll Academic Months", "Staff Finance details" search) | pay setup, payroll month/run, **approval**, payslips, statutory outputs — whether in this module or a separate one |

**No expanded top-menu dropdown appears anywhere in the 335 screens**, so the
menus' item lists cannot be enumerated from the pack itself.

**Why INCOMPLETE rather than AMBIGUOUS:** two of the six top-level menus
shown on every screen (Leaves, Biometric Attendance) have zero captures, the
Reports menu has one, and Payroll — the decision-critical area of this
checkpoint (OD-HR-1) — has none. These are expected screens of an HR module,
missing by observation, not by inference.

**Important context — the older pack already covers the gap.** The ORG.9A
evidence register (`docs/product/REFERENCE-SCREENSHOT-REGISTER.md` §2, §4
"HR (20)") indexed **252 HR screens of the same vendor in 20 families**,
including exactly the missing areas: SF-HR-09 leave types/policies/
allocation/holidays (18), SF-HR-10 leave, permission and comp-off approvals
(7), SF-HR-11 leave balances and reports (15), SF-HR-12 attendance capture,
shifts and devices (26), SF-HR-13 corrections and regularisation (11),
SF-HR-14 payroll month, attendance confirmation, pay setup and pay change
log (13), plus 75 HR function rows in `reference-parity/functions.csv`.
The new pack is a much deeper capture of Staff Details. **Neither pack
alone is complete; together they very probably are** (Payroll approval and
payslip screens remain unconfirmed in both).

### 3.1 Exactly what is missing (to close the gate)

1. **Owner declaration of scope:** is the HR reference baseline (a) this pack
   alone, or (b) this pack **plus** the ORG.9A HR families (252 screens,
   already indexed)?
2. **Six expanded top-menu dropdowns** (Staff Details, Attendance, Biometric
   Attendance, Leaves, Staff Tasks, Reports) — 6 screenshots that prove the
   menu inventory.
3. If (a): the **Leaves** menu pages (types, policies/quotas, holidays,
   application, approval queue, permissions/comp-off, balances, reports), the
   **Biometric Attendance** menu pages, attendance **corrections /
   regularisation**, the **Reports** menu catalogue and its main reports, and
   the **Staff Documents** tab.
4. In either case: **Payroll** — whether the vendor has a Payroll module and,
   if so, its pay setup, payroll run/month, **approval** (what the approver
   sees), payslip and statutory screens. Needed for OD-HR-1 reference
   behaviour.
5. A note that capture is finished (or a zip beside the folder, as with SIS
   and Organisation), so the fingerprint can be frozen.

## 4. Provisional family inventory (evidence only — not a capability map)

Each of the 335 screens assigned to exactly one observed family (verified:
no screen unassigned or double-assigned).

| Family (observed) | Screens |
|---|---|
| Staff master, profile sections and bulk field updates | 95 |
| Staff onboarding (add, duplicate check, enroll, outsourced, transport, CSV import) | 44 |
| Staff collaboration (wall, groups, messages, meetings, birthday greetings) | 39 |
| Staff attendance (sessions, shifts, daily marking, attendance reports) | 32 |
| Organisation masters (qualifications, designations + levels, roles & responsibilities, departments) | 25 |
| Workforce dashboards & analytics | 15 |
| Employment lifecycle (contract, superannuation, inactive/exit, NOC clearance, branch transfer, exit approvals) | 14 |
| Training, professional development & achievements | 14 |
| Feedback, praise, complaints & internal notes | 9 |
| Staff list reports (trained, employment status, staff kids, change log) | 9 |
| Outsourcing vendors & manpower contracts | 7 |
| Leave reports (Reports menu) | 6 |
| Staff documents (resumes, handbook & policies) | 6 |
| Teacher substitution & workload (Timetable-derived) | 5 |
| Staff tasks | 5 |
| Payroll, tax & statutory fields | 5 |
| Staff transport assignment | 4 |
| Biometric / RFID identifiers | 1 |
| **Total** | **335** |

The pack is heavy with same-page dropdown states (an estimated ~40 % of
screens are the same page with a different control open), so screen counts
overstate distinct functions.

### 4.1 Early signals for the future map (not classifications)

- **Already strong in Lycenza** (to be confirmed at mapping): Employee
  identity separate from User; onboarding atomic with first assignment;
  departments/positions/categories; reporting line; Campus-scoped HR;
  employment lifecycle with history; Leave ledger and decisions; Payroll
  maker-checker, posting via Finance's `LedgerService`.
- **Reference concepts not in Lycenza:** designation **levels** (L0–L3),
  roles & responsibilities per designation (with "use in staff assessment"),
  superannuation/retirement date, NOC clearance checklist, inactive-staff
  approval queue, praise/feedback/complaints, training & PD registers,
  outsourcing vendors/contracts, staff collaboration wall/meetings, staff
  birthdays and greetings, staff-kids report, shift rosters with grace/late
  rules.
- **Reference data Lycenza deliberately does not hold:** religion, caste,
  marital status/"divorcee", physical identification marks, health (blood
  group, height, weight, disability), family income, Aadhaar/PAN/passport/
  driving licence on the general profile, bank account on the general
  profile, biometric/face/RFID codes, gender-and-age workforce charts.
- **Belongs to other owners:** staff bus routes (Transport), substitution
  and workload (Timetable), staff wall/messages/meetings/birthday SMS
  (Communications), staff-kids fee dues (Fees/SIS), vendor contracts and
  payment types (Finance/procurement), Form 12C and statutory IDs (Payroll),
  library research log (Library), access level on the HR form (Identity —
  rule 92: job titles are never roles).

## 5. Repository reconciliation (unchanged since HR.EXP-0)

| Area | Authoritative implementation |
|---|---|
| Employee master | `employees` (+ personal details, addresses, emergency contacts, notes, qualifications, certifications, experience); HR domain 155 files / 87 test files |
| Identity boundary | User (Identity) ≠ Employee (HR) ≠ Teacher (= `ActingEmployee` + `TeachingAssignment` ownership, ADR 0063; no Teacher table) |
| Structure | `hr_departments` (hierarchy), positions, employee categories, live reporting line |
| Lifecycle | employment statuses `draft … deceased`, `end()`, rehire as new employment, archive/restore |
| Documents | `employee_documents` metadata + Documents bytes (`employee` owner type, MFA reads) |
| Leave | Leave domain (types, years, policies, ledger, requests, decisions, staff calendar); 44 files / 18 tests |
| Staff attendance | half-day present/absent, corrections append-only; no clock/device/biometric data; 14 files / 11 tests |
| Payroll | components, structures, compensation, periods, runs, results, adjustments, posting (`LedgerService`), payslips, statutory engine (not wired); 180 files / 64 tests |
| Self-service | `/app/my-leave`, `/app/my-staff-attendance`, `/app/my-payslips` |
| Capabilities | 46 keys across `hr.*` (29), `payroll.*` (17) incl. `hr.leave.*`, `hr.staff_attendance.*` |
| Roles | `school_admin`, `principal`, `hr_officer`, `hr_sensitive_records` (add-on), `payroll_officer`, `staff_self_service`; `teacher` holds no `hr.*` key |
| Web pages | HR 12, Leave 7, Payroll 15, Staff Attendance 3, Staff accounts 1, self-service 4 |

Open security findings S-1…S-16 (`HR-LEGAL-SECURITY-GATE-REGISTER.md` §3)
are unchanged. **HIGH: none.**

## 6. OD-HR-1 — Payroll approval visibility (P0)

### Issue
A payroll run is approved by someone who cannot see what they approve.

### Current repository behaviour (verified at `7a4068fe`)
- `payroll.runs.approve`, `.post`, `.reverse` are held by **`school_admin`**
  only; `payroll.compensation.sensitive.view` / `.manage` by
  **`payroll_officer`** only.
- `PayrollRunController::renderShow()` lists run results only when the
  viewer holds `payroll.compensation.sensitive.view` **and** current
  assurance (`SensitiveReadAssurance`); otherwise `resultDetails = null`.
- `PayrollRunSummary` (the approver's view) carries **no figure at all** —
  status, kind, preparer, approver, poster and timestamps; `payroll_runs`
  has no amount column. The approver does not even see totals.
- Approval (`PayrollRunService::approve()`) re-reads authority fresh
  (`requireCurrent`) and enforces maker-checker (ORG.8B): the run's creator,
  every recorded calculator and every adjustment author can never approve;
  stale results are refused; DB backstop
  `payroll_runs_participant_approval_guard`.
- So a `payroll_officer` can enter an inflated manual override or correction
  delta for a colleague and a `school_admin` approves it **blind**. The
  control is structurally present and substantively empty.
- Compounded by **S-2 / OD-HR-2**: compensation assignment (one
  `payroll_officer` + fresh MFA) has no checker at all.

### Screenshot / reference behaviour
**Not observable.** Neither the new HR pack (no Payroll screen) nor the
ORG.9A HR families (SF-HR-14 covers payroll month, attendance confirmation,
pay setup and pay change log — no approval screen is indexed) shows a
payroll approval step. The reference evidence cannot settle OD-HR-1; it is
a Lycenza integrity decision (rule 102: approval is not permission;
`MakerCheckerPolicy` must stay meaningful).

### Risk
- **Integrity / fraud:** an approver accountable for pay they cannot verify
  (inflated override, ghost correction delta, unchecked compensation change
  via S-2).
- **Over-disclosure if fixed carelessly:** giving every School
  Administrator the full salary register (Highly Sensitive, rule 95) just
  because they approve.

### Option A — Approval implies full visibility
Grant `payroll.compensation.sensitive.view` to the approving role
(`school_admin`). Simple; every School Administrator then reads every
salary on every run, every time. Maximises disclosure; small Schools often
have several admins.

### Option B — Governed approval review projection
A new approval-only read: run totals, headcount, joiners/leavers, every
manual override and correction delta **with amounts**, and per-person
change against the previous posted run above a threshold — under fresh MFA
(`SensitiveReadAssurance`), audited per view, no full register, no export.
The approver sees everything that *changed* and the totals, not every
unchanged salary. New read path, no new authority. Residual: someone with
only the projection still cannot reconcile an unchanged-but-wrong salary
(already caught by the previous approval of that salary).

### Option C — Approval requires approval authority AND sensitive-view authority
`approve()` refuses unless the approver *also* holds
`payroll.compensation.sensitive.view` now (conjunction checked fresh in the
decision transaction). "No one approves what they cannot inspect" becomes
structural. Cost: a School must have a second sensitive-payroll person who
is not a run participant; the default catalogue must change (either
`school_admin` gains sensitive view — Option A for admins — or a School
grants `payroll_sensitive` via the existing grant right to a named
approver). Small Schools may be unable to staff a checker.

### Option D — Split review stages
A sensitive **reviewer** (verifies the variance report) and a non-sensitive
**authoriser** (releases totals for posting). Needs a new capability/role in
the closed staff catalogue (rule 93: reviewed release, 17 roles pinned) —
the heaviest change for a v1 School.

### Recommendation
**Option C as the rule, with Option B as the approval screen.** Approval
requires current sensitive-view authority (no blind approval, structurally),
and the approver's page leads with the governed variance projection (totals,
joiners/leavers, every override and delta with amounts, above-threshold
per-person changes) before the full results, all under fresh MFA and
audited. Decide **OD-HR-2** in the same decision: route compensation changes
into that variance review (or give them their own checker), so the approver
also sees pay changes made since the last run. Default catalogue proposal
for the owner to accept or adjust: `school_admin` remains the approver and
gains `payroll.compensation.sensitive.view` **only through an explicit
`payroll_sensitive` grant** (not by default), so a School consciously names
who may see pay to approve it.

### What is blocked until decided
- HR.HARDEN-0's payroll items (S-1, S-2, S-5 service-layer proof, S-13
  posting authority) — they implement the decision.
- Any Payroll wave (HR.EXP-7) and Payroll production.
- **Not blocked:** non-Payroll HR work (Employee master, structure,
  lifecycle, documents, Leave, staff attendance) once the pack gate closes.

## 7. Legal / privacy matrix (existing gates + newly observed reference data)

| Capability area | Sensitivity | Existing legal status | Development | Production | Decision needed |
|---|---|---|---|---|---|
| Staff health (blood group, height, weight, disability, insurance) | health | HRX-L1 OPEN (absent by construction) | not without a decision | no | yes (HRX-L1) |
| Biometric / face / RFID codes, punches, devices | biometric | HRX-L2 OPEN | not without a decision | no | yes (HRX-L2) |
| Shift grace / late / early rules | clock-time evidence | HRX-L2 (timing) | no | no | yes |
| Statutory leave | legal | HRX-L3 OPEN | School policy only | no statutory claim | yes |
| Loss of pay / NCP | pay | HRX-L4 BLOCKED (mechanism only) | evidence only | no money effect | yes |
| Statutory payroll (PF/ESI/PT/LWF/TDS), Form 12C declarations | tax / statutory IDs | ADR 0036 legal-gated; no production role | demo role only | no | catalogue release + legal |
| Bank details / disbursement files | financial | not recorded (gate register "needed before") | no | no | yes (new legal record) |
| Aadhaar / PAN / passport / driving licence on the profile | government ID | D-15 discipline; only Payroll statutory IDs exist (encrypted) | no (outside Payroll) | no | yes |
| Religion, caste, marital, identification marks, family income | special category / profiling | not adopted (D-15 analogue) | no | no | never for parity |
| Complaints, memos, internal notes, termination remarks | disciplinary | gate register "needed before" (OD-HR-8) | no | no | yes (legal first) |
| Recruitment / candidate data (post applied, advertisement, references) | candidate personal data | gate register "needed before" | no | no | yes (OD-HR-6 + legal) |
| Employee family / nominees | family data | OD-HR-12 (legal) | no | no | yes |
| Workforce gender/age analytics | person counts | ADR 0040 Analytics gate | no | no | Analytics D-01..D-04 |
| Staff-kids fee report | minors + money | D-14 / SIS rule 101 | no | no | yes |
| Staff identity, employment, structure, Leave, attendance halves | personal | E21 retention ratification pending; E47 personas | permitted | E21 / E47 / O1 | production only |

## 8. Cross-ERP boundaries observed

| Reference capability | Owner | Must not duplicate |
|---|---|---|
| Staff bus routes, staff transport list | Transport | rider assignment truth |
| Teacher substitution, weekly workload "as per Timetable" | Timetable / Teaching Assignments | timetable or ownership |
| Staff wall, groups, messages, meetings, birthday SMS/WhatsApp | Communications (Staff audience via `CurrentStaffMembers`, rule 108) | message delivery, audiences |
| Access level chosen on the staff form | Identity (rule 92; job titles never roles) | role grants |
| Staff kids with fee dues | SIS + Fees (D-14) | fee balances |
| Vendor register, contracts, paying account, payment type | Finance / procurement | ledger, payables |
| Joining security deposit | Finance | money records |
| Form 12C, PF/ESI/UAN, nominees | Payroll (statutory, legal-gated) | — |
| Research log with library book search | Library (catalogue) | catalogue |
| Salary posting | Finance via `LedgerService` (unchanged) | ledgers |

## 9. Findings

| # | Severity | Finding | Blocks HR expansion |
|---|---|---|---|
| 0R-F1 | **GATE** | HR pack incomplete: Leaves and Biometric Attendance menus, the Reports catalogue and all Payroll absent; no expanded top-menu dropdown (§3) | blocks the reference map and pack-derived wave selection |
| 0R-F2 | LOW (doc drift) | `HR-LEGAL-SECURITY-GATE-REGISTER.md` §1 still says E16 last valid day **2026-11-12**; current decision `OWNER-0O-E16-2026-11-01` is valid through **2026-11-28** (R2-F2 confirmed) | no; correct when HR docs are next published |
| 0R-F3 | INFO | OD-HR-1 is sharper than recorded: the approver sees **no figure at all**, not even totals (`PayrollRunSummary`) | strengthens the P0 |
| 0R-F4 | INFO | The reference pairs the HR form with **access level** (application role) assignment; Lycenza must keep rule 92 (roles only through Staff accounts) when mapping onboarding | no |
| 0R-F5 | INFO | ~40 % of screens are dropdown-state variants; a future map must count functions, not screens | no |

## 10. Decisions

- **HR.EXP-0 closure: STILL OPEN** — the repository audit stands; the
  reference map cannot be built until the pack gate closes (§3.1).
- **HR implementation readiness: READY AFTER OWNER DECISION** — (1) the
  pack scope / recapture (§3.1) and (2) OD-HR-1 (+ OD-HR-2) for anything
  touching Payroll. Non-Payroll HR work is unblocked by OD-HR-1 but its wave
  must be chosen from a complete reference.
- **Exact next checkpoint: HR.EXP-0R-PACK-CLOSURE** — the owner declares the
  HR reference scope (recommended: this pack **plus** the ORG.9A HR
  families, with the six expanded top-menu dropdowns and, if the vendor has
  one, the Payroll run/approval/payslip screens added), then the pack is
  fingerprinted and frozen and the capability map is built. OD-HR-1 can be
  decided in parallel.
