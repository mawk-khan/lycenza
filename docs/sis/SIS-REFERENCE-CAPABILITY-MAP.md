# SIS reference capability map — Student Information System pack (318 screens)

Status: **authoritative reference map for the SIS expansion programme**
(SIS.EXP-0, 2026-10-29, documentation only). Companion records:
`SIS-EXP-0-CURRENT-STATE-AUDIT.md` (repository truth),
`SIS-DOMAIN-OWNERSHIP-MAP.md`, `SIS-EXPANSION-ROADMAP.md`,
`SIS-DEMO-PLAN.md`.

This map records what the reference ERP's Student area **does**, which
Lycenza domain owns that function, and whether Lycenza builds it. Nothing
here is itself a capability, role, module, Campus adoption, schema or
implementation decision. The reference product's packaging (everything a
Student touches sits under one "Student" menu) and its security design
(approval queues for every status change, unmasked parent contacts in
reports, configurable column pickers over every personal field) are
**evidence of business need, never Lycenza's contract** (rules 4, 93, 98,
100, 101, 102; ADR 0075 §22 D-15).

## 1. Provenance and handling

| Item | Value |
|---|---|
| Source | Owner-supplied captures of a live multi-branch tenant on the reference ERP, under one system-administrator persona |
| Location | Owner-held, outside the repository (`Screenshots Downloaded/Student Information System (SIS)` in the owner's Lycenza folder; a zip of the same folder sits beside it) |
| Files | 318 PNG files; 315 byte-distinct (3 byte-identical repeats); 0 unreadable files |
| Pack fingerprint | SHA-256 over, for each file in sorted file-name order, its UTF-8 file name followed by its hex SHA-256: `c7a3699eeda88538ed21226402e7423586525551fd1c663ef0416d370b1f6032` |
| Relation to earlier evidence | Supersedes, **for the Student area only**, the eleven `SF-SIS-*` families (67 screens) of `docs/product/REFERENCE-SCREENSHOT-REGISTER.md` §4. That register, `reference-parity/*.csv` and the ORG.12 Organisation map are otherwise unchanged. Function ids (`SIS-0xx`, `DOC-0xx`, `CRT-0xx`, `ADM-0xx`, `LRN-0xx`) are cited, not renumbered |
| Screen ids | `S001`–`S318`, in sorted file-name order. The id → file index stays with the pack, outside Git |
| Committed | Normalized, Lycenza-worded findings only |
| Never committed | Screenshots, the id → file index, vendor or tenant names, any school, branch, person, contact, identifier, URL or amount |

### 1.1 Review method and evidence

- Every file was decoded and cut into 1,700-pixel-high tiles at 1,280 px
  width (778 tiles in total; the tile set and index stay in the session
  scratchpad, never in Git).
- **All 318 screens were inspected visually.** For the 49 very tall pages
  (more than four tiles: long tables and stitched scroll captures) the
  first two, a middle and the last tile were read — 497 tiles in all. Those
  pages repeat one layout row after row; their remaining tiles were not
  opened, and no conclusion depends on them.
- Each screen was assigned to **exactly one** family (`SR-01`…`SR-42`); the
  assignment was machine-checked: 318 assigned, 0 unassigned, 0 assigned
  twice.
- **Repeated states:** a screen is a repeat when it shows a family's
  existing screen in another tab, filter, sort, scroll position, chart/table
  toggle or modal step. Repeats are counted in their family and add no row.
  The families dominated by repeats are SR-01 (one analytics page in 16
  lenses × two views), SR-17 (one group hub in nine type tabs, each with the
  same four modals), SR-16 (one timetable hub, eight tabs), SR-29 (one
  attendance report, ten tabs) and SR-39 (one admissions dashboard, eight
  tabs and seven matrix measures).
- **Partly legible:** five screens (S095–S099) are corrupted stitched
  captures of one bulk "assign to house" modal, and one (S260) is a very
  small, low-resolution modal. Their family is unambiguous from the
  surrounding screens; they are counted as reviewed but partly legible.
  **No screen was unreadable.**
- Evidence level: every family is **E5** (screen evidence plus repository
  comparison, `REFERENCE-PARITY-MATRIX.md` §15 scale).

## 2. Classification vocabulary

| Classification | Meaning |
|---|---|
| `EXISTING_EQUIVALENT` | Lycenza already provides the function in an equivalent form |
| `EXISTING_DIFFERENT_ACCEPTED` | Lycenza meets the need differently, deliberately; the difference is accepted |
| `PARTIAL` | Part of the function exists; the rest is named with its owner |
| `SIS_BUILD` | Built in a named SIS.EXP checkpoint, with no outstanding decision |
| `DOMAIN_FUTURE` | Belongs to another owning business domain's future programme, not SIS |
| `PLATFORM_FUTURE` | Belongs to the platform plane (Lycenza as vendor), never School tenant data |
| `POST_V1` | Valid need, scheduled after v1 |
| `DO_NOT_BUILD` | Contradicts a frozen rule, ADR or recorded decision |
| `OWNER_DECISION_REQUIRED` | The product owner must decide before any design |
| `LEGAL_DECISION_REQUIRED` | Blocked on a legal/regulatory/privacy determination |

Gap severity (only for gaps): **P0** architecture/security blocker ·
**P1** core v1 · **P2** important v1 · **P3** useful enhancement ·
**post-v1**. A family is classified as a whole; a `PARTIAL` row names the
SIS_BUILD, decision or legal parts of its gap and the checkpoint that
carries each.

## 3. Family map

`SR-nn` ids are stable. Table A: what it is and what Lycenza does. Table B
(§3.2): how Lycenza would authorize it and why it is or is not built.

### 3.1 Table A — function, owner, current implementation, classification

| SR | Reference family | Screens | Observed function | Lycenza owner | Current Lycenza implementation (route / service) | Classification | Gap · severity · checkpoint |
|---|---|---|---|---|---|---|---|
| SR-01 | Student composition by attribute | 40 | One analytics page "look at students by" 16 lenses (gender, caste, sub-caste, religion, mother tongue, residential category, house, parental status incl. divorced/separated, transport mode, lunch, parent qualification/occupation, how they joined), per-Section heat map and table, totals | Analytics | None. Person-counting Analytics fails closed (ADR 0040, V11.1 D-01..D-04); none of these attributes is stored (D-15) | `LEGAL_DECISION_REQUIRED` | Post-v1; demographic profiling of children is never a parity goal |
| SR-02 | Roll-integrity exception lists | 2 | "Inactive but still on rolls" and "promotion pending fee clearance" lists | Students (Data Health) | Data Health `InactiveStudentWithActiveEnrollmentCheck`, `ActiveStudentWithoutCurrentPlacementCheck`, 12 read-only checks (`/app/data-health`) | `EXISTING_DIFFERENT_ACCEPTED` | — (fee-gated promotion is not adopted, SR-37) |
| SR-03 | Student directory | 6 | List with status KPIs, year/branch/class/section/gender filters, photo, enrolment code, register number, how joined, admission date, age, point of contact, last attended, row actions inactive/suspend | Students | `/app/students` (`StudentDirectoryController@index`): name (any order) / number / status filters, current class column, 20 per page, Campus-aware rows; Grade/Section/Campus/year filters only on `/app/enrollments` | `PARTIAL` | **SIS_BUILD P2 (SIS.EXP-1):** Grade/Section/Campus filters on the directory, a primary-Guardian name column for whole-School `guardians.view` holders. Not copied: KPI counts (gated), gender/age/photo (D-15, DOC-002), row-level status actions (assured flow) |
| SR-04 | Student master record (identity, personal, parents, address) | 4 | Edit modal tabs: identity, personal (religion, caste, mother tongue, blood group, nationality, identification marks, scholarship), parents (father/mother/guardian blocks with occupation, income, custody, point of contact), address (current/permanent, map locality) | Students; Guardians | Student: number, names, DOB (`/app/students/{id}/edit`, `StudentService::update`); Guardian is its own entity with typed, encrypted contacts and relationship flags (primary, legal guardian, emergency, pickup) | `PARTIAL` | Identity: equivalent. Parents: different accepted (one Guardian model, no father/mother schema). **OWNER_DECISION** Student home address (OD-SIS-7). **LEGAL** demographics, blood group, income (D-15). Hardening of the edit path: SIS.EXP-1 (audit §U) |
| SR-05 | Government and statutory identifiers | 3 | National identity number (student and parents, masked with reveal), passport/visa with expiry, tax id, education registry ids, state register numbers, family id, utility account numbers | Students (would be) | Not stored (ADMISSIONS §4, `STUDENT-GUARDIAN-IDENTITY.md`, CRT-011) | `LEGAL_DECISION_REQUIRED` | Each identifier needs a named statutory purpose, lawful basis, masking/reveal audit and retention (D-15); never for parity |
| SR-06 | Bulk Student import | 5 | Three-step wizard: assign sections, upload CSV (names, DOB, gender, admission type, parents, contacts, address), validate, review | Students (adapter over the one write path) | Not built. The adapter pattern exists (rule 107, `TransportRiderImport`) | `OWNER_DECISION_REQUIRED` | **P1 once decided** (OD-SWC-4 / OD-PPR-20, SIS-036): School onboarding at go-live. Checkpoint SIS.EXP-5 |
| SR-07 | Higher-education programmes | 3 | Programme/semester structure and attendance for professional colleges | — | Not a Lycenza product line | `DO_NOT_BUILD` | SIS-030 (K-12 scope) |
| SR-08 | Email syntax validation | 3 | Lists of Student/parent/staff emails coloured valid/invalid | Communications / email layer | Validation on entry (E.164 / email rules); delivery problems handled by suppression (ADR 0055) | `EXISTING_DIFFERENT_ACCEPTED` | — (SIS-027) |
| SR-09 | Campus (branch) transfer, incl. bulk | 6 | Select many Students, target year/branch/class/section, save; shuffle log | Students | Single transfer `/app/students/{id}/transfer` (`CampusStudentTransferService`: authority over BOTH Campuses, blockers for Hostel/Transport/electives, ends source and inserts destination, audited); same-Campus Section change `/app/enrollments/{id}/transfer`; history in Student history | `PARTIAL` | Bulk transfer **POST_V1** (SIS-008, ADR 0076 §44.25): per-Student locks, blockers and outcomes, whole-School only |
| SR-10 | Inactivation / reactivation approval queue | 7 | Request inactive/reactive (reasons wrong entry, TC taken, long absent, no intimation; back-dated effective date), pending dashboard, single and bulk approve/reject | Students | Direct assured action: `POST /app/students/{id}/status` (`StudentService::changeStatus`) — closed `StudentLifecycleReason`, confirmation, fresh MFA, current whole-School authority under the row lock, `student_lifecycle_events` row (DB-required) | `EXISTING_DIFFERENT_ACCEPTED` | — Request/approve and bulk approval deliberately not copied (SIS-004/005/006, ORG.8E `DIRECT_ASSURED`); effective/back-dated dates are D-13 (OD-SIS-8) |
| SR-11 | Exit revenue impact | 2 | Projected revenue lost, attrition rate, forgone fees by component and month, proration ledger, refund liability | Analytics + Finance | None (money grouped by cohort is privacy-gated, D-14; person counts gated, ADR 0040) | `DOMAIN_FUTURE` | Analytics, after D-01..D-04/D-14 |
| SR-12 | Exit audit (exit register, certificate checkpoint, dues checkpoint, reasons, reactivation register) | 8 | Request → approval → effective date → leaving certificate → dues trail with coded checkpoints (back-dated exits, attendance after exit, dues at exit, fee plan still assigned) | Students; Fees; Attendance | Lifecycle events with closed reasons (ORG.8E/8G); Data Health `attendance.recorded_outside_placement`, `fees.charge_after_placement_ended`, inactive-with-placement; Outstanding fees with a Leavers filter (ORG.9B); per-Student history | `PARTIAL` | **SIS_BUILD P2 (SIS.EXP-2):** a School-wide movement & exit register (date range, action, reason label, Campus). Certificate checkpoint waits on SR-41. No back-dating to audit (SR-10) |
| SR-13 | Student "contracts" for existing Students | 8 | Before next-year promotion: configure fields/documents parents must verify, documents dashboard, pending-verification queue, "contract forms signed" with agreed-on time and IP address, parent-information update log | Portal (Guardian acknowledgement) + Students (data confirmation) + Documents | None. The only consent-like record is `student_processing_authorizations` (purpose `academic_records`, basis guardian/adult/statutory, ADR 0038), which is not an agreement | `OWNER_DECISION_REQUIRED` | OD-SIS-5 (annual re-enrolment confirmation and acknowledgement) + legal review of electronic acceptance; then SIS.EXP-6 |
| SR-14 | Pre-admission documents and concession approvals | 3 | Required certificates per class for applicants, document upload/verify counts at application stage, pre-admission concession approval queue | Admissions + Documents + Fees | Applicant documents not collected (ADM-023); an applicant is not a financial subject (ADM-014); concessions are requested/decided on the shared approval foundation after conversion (rule 102) | `DOMAIN_FUTURE` | Admissions/Documents, after OD-SIS-1 |
| SR-15 | Fee-discount agreement documents | 1 | Per Student actual/discount/net with request sent by, agreed on/by, IP address | Fees + Documents | Fee concessions with request, decision and beneficiary-conflict guard (ORG.8A-R1); no agreement document | `DOMAIN_FUTURE` | DOC-003, Finance |
| SR-16 | Timetable structure | 25 | Master timetables (weekly, fortnightly, multi-week cycle, day rotation), structures, scheduling, image upload per section, one-day overrides, period types with an attendance flag, practical batches, staff workload | Timetable (Academic) | Timetable periods and schedules per Section and Room (`/app/timetable-schedule`); registers are taken against it | `DOMAIN_FUTURE` | Timetable programme (cycles, substitutions, workload); practical batches SIS-022 |
| SR-17 | Student groups (house, club, custom, project, finance, store) | 52 | Typed groups per year with logo and description, applicable classes, one-or-many membership rules, student responsibilities (captain, prefect), staff mentors, participation badges, schedules, bulk "assign to house", untagged-student analysis by boys/girls | Students (named concepts only) | Academic cohorts are Grade + Section (Academic Structure). No house/club model (D-17 DEFER, SIS-021). Finance/store "groups" are fee targeting, owned by Fees | `OWNER_DECISION_REQUIRED` | OD-SIS-4 (a pilot names House or Club); then SIS.EXP-7 as that named concept, never a generic engine |
| SR-18 | Group activities and co-curricular skills | 10 | House/club activities with dates, classes, participation, attachments; co-curricular skill master graded A–E per term | Examinations / learning (co-scholastic) | Not built (LRN-001 achievements, E40 report-card inputs) | `DOMAIN_FUTURE` | Examinations co-scholastic, after E40 |
| SR-19 | Derived system tags | 7 | 25 nightly cohorts (siblings, staff child, single child, widowed/divorced/adoptive parent, minority, disabled, foreign, loan/EMI opted, mandate opted, hosteler) with member lists and bulk email to parents | — | Not built. Communications audiences are School, Grade/Section, Staff, Guardian (rules 108–110) | `DO_NOT_BUILD` | Inferring family circumstances, disability or minority status into reusable cohorts is profiling of children, outside any lawful purpose (D-15, DATA-CLASSIFICATION) |
| SR-20 | Student segments for fee mapping | 6 | Students mapped to a fee segment (general / not mapped), class and section views | Fees | Fee structures per year, Grade and Campus (ADR 0062); Hostel and Transport fee selections; admission fee intent | `EXISTING_DIFFERENT_ACCEPTED` | — |
| SR-21 | Student documents and checklist | 4 | Per-class grid of birth certificate, leaving certificate, immunisation certificate, photograph, report card; viewer | Documents (Student owner) | Schema has `documents.student_id`/`guardian_id` with same-School FKs; `DocumentService` refuses the `student` and `guardian` owner types (`DocumentOwnerTypeNotSupportedException`) | `OWNER_DECISION_REQUIRED` | **P1 once decided** (OD-SIS-1 = OD-SWC-2, PV1-15, E21 retention; immunisation is health data). Checkpoint SIS.EXP-3 |
| SR-22 | Profile photographs (Student and parents) | 1 | Student/father/mother/guardian photos, bulk download named by code | Students / Documents | Not built | `LEGAL_DECISION_REQUIRED` | Children's image consent and retention (DOC-002, ADR 0075 §10.2); ID cards wait on it (CRT-008) |
| SR-23 | Contact details report and column picker | 3 | Class list with DOB, gender, national id, address, parent names/mobiles/emails; a picker over ~150 personal fields | Students / Guardians | Guardian contact values only to `guardians.view`, encrypted, exact-match lookup only (ADR 0041); no export | `OWNER_DECISION_REQUIRED` | SIS-020 class-wide family contact list + D-11 export; the configurable column picker over personal fields is not adopted |
| SR-24 | Strength reports | 11 | Opening/closing strength, month-wise movement, gender and age matrices, seat fill vs sanctioned capacity, per-class gap, vacancy with admissions pipeline, staff wards, admissions and exits | Analytics (counts); Academic Structure (capacity) | None. Sections carry an advisory `capacity`; grouped person counts are gated (ADR 0040, SIS-017) | `OWNER_DECISION_REQUIRED` | OD-SIS-6: may an operational Section headcount vs advisory capacity be shown outside the Analytics gate? Everything else waits on D-01..D-04 |
| SR-25 | Sibling report | 5 | Families grouped by parent with dues for this and last year; "no siblings" and "all" tabs; residential category | Students / Guardians (+ Fees for dues) | Derivable: a Guardian page lists every linked Student; no sibling block on the Student profile; dues per Student in Fees | `PARTIAL` | **SIS_BUILD P3 (SIS.EXP-1):** a family block on the whole-School profile (other Students of the same Guardians). School-wide sibling list: OD-SIS (SIS-019/PV1-17); dues by family: D-14 |
| SR-26 | Strength audit (fees) | 5 | Admission counts, receivables by fee type, paid/dues and revenue by grade, loss from inactive Students | Fees + Analytics | Outstanding fees per Student and as at a date (ORG.9B); no grouped money | `DOMAIN_FUTURE` | D-14 / Analytics |
| SR-27 | Branch-wise strength | 2 | Counts per branch per class across the whole chain | Analytics (cross-School) | None; Network reports read platform tables only (rule 117) | `DOMAIN_FUTURE` | Cross-School read model decision (ADR 0080 §13.4) |
| SR-28 | Device attendance and absence messages | 3 | Biometric/RFID punch logs, device logs, lost ID cards; WhatsApp absence messages with templates and credits | Attendance + Integrations; Communications | Not built; Integration Hub shows "Not available" (ORG.12G) | `LEGAL_DECISION_REQUIRED` | HRX-L2 biometrics, RFID privacy review, E28 messaging channels |
| SR-29 | Student attendance reports | 25 | Daily consolidated/class/gender/transport/residential views, session-wise, monthly averages, overall and term-wise %, register grid, late comers, attendance not marked, period-wise, staff marking compliance | Attendance | Registers per timetable entry; register status submitted/pending/not submitted; exceptions (absent/late/excused); a Student's records over a range (`/app/attendance/students/{id}`); no rates or tallies by design (ADR 0040, `DailyOperationsReadModelGuardTest`) | `PARTIAL` | Attendance-owned: daily (non-period) model and mark-all (PV1-19/ATT-08 decision); rates only after the Analytics gate. Not SIS |
| SR-30 | Manual monthly attendance entry and upload | 2 | Type or upload monthly working/present-day totals per Student | Attendance | Not built | `DO_NOT_BUILD` | Attendance is evidence from registers; typed monthly totals have none |
| SR-31 | Parent-submitted Student leave | 5 | Leave intimation with type, free-text remarks (often illness), dates, attachment, approval trail; leave-type configuration with teacher notification | Attendance + Portal | Not built (D-16 DEFER) | `OWNER_DECISION_REQUIRED` | D-16 pilot requirement; free-text illness is health data (Health-tier review) |
| SR-32 | Class and subject teacher lists | 4 | Class teacher and subject teacher per section with teacher contact details | Teaching Assignments (Academic) | Teaching Assignments directory with teacher/Section filters (V11.2); ownership from TeachingAssignment (ADR 0063) | `EXISTING_DIFFERENT_ACCEPTED` | — No "class teacher" flag; staff contact details are never on an academic list |
| SR-33 | Data-change and activity logs | 3 | Student field changes old → new value with IP and changed-by; teacher-assignment change log; staff activity log with IP/device | Compliance audit; Students | Student history (`StudentActivityHistoryService`: field names, no values, actor hidden); School audit log under `school.audit.view` | `EXISTING_DIFFERENT_ACCEPTED` | — Personal values and IP addresses are never written to history (ADR 0042 §10, rule 85 spirit) |
| SR-34 | Withdrawal fee refunds | 1 | Refund requests with two approval levels, amount, cheque number | Payments | Recorded refund as a payment correction requested and executed by another person's approval with fresh MFA (ORG.8C) | `EXISTING_DIFFERENT_ACCEPTED` | — No bank or cheque details (rule 91) |
| SR-35 | Suspension log | 1 | Suspensions with reason, remarks, revoke | — | Not built (LRN-005) | `LEGAL_DECISION_REQUIRED` | Discipline is a new behavioural children's-data category; DPO first |
| SR-36 | Section and Campus movement history | 4 | Section shuffles and branch transfers in/out with parent contact; per-Student date-wise timeline (admission, promoted, passed out, inactive, reactive; system vs effective date) | Students | Per-Student history page and placement history; placements directory filters by year/Campus/Grade/Section/status (SIS-015) | `PARTIAL` | **SIS_BUILD P2 (SIS.EXP-2):** the School-wide movement register (with SR-12). No parent contact on it |
| SR-37 | Promotion reports | 4 | Promoted list with revert, promotion pending with fee due, "financial promotion" date list | Students (rollover) | Rollover plans: dry run, per-item decisions (promote, repeat, exclude, manual review), execution results (`/app/enrollment-rollovers`) | `EXISTING_DIFFERENT_ACCEPTED` | — Promotion is never gated on fees (no "financial promotion"); a promoted placement is corrected by the placement correction paths, not a "revert" |
| SR-38 | Principal diary and daily branch activity | 6 | Daily diary of attendance, staff presence, supervision rounds, complaints, incidents, admissions, shuffles, transport meter readings | — | Not built | `DO_NOT_BUILD` | SIS-033 (diary products not adopted, ADR 0076 §31.8) |
| SR-39 | Admission analytics | 20 | New-admission revenue: fee charged, discounts, net, collected, lost to dropouts, by class/branch/curriculum/source, per fee head, pre-admission fees | Analytics (Admissions + Fees data) | Admissions list with filters; admission fee intent (OPF.3) | `DOMAIN_FUTURE` | ADM-028; gated by D-01..D-04 and D-14 |
| SR-40 | Hobbies and talents (Student and parents) | 5 | Hobbies/talents per Student, father, mother | Students | Not built | `POST_V1` | Student field only after classification (LRN-009, D-15 discipline); parent hobbies have no School purpose and are not adopted |
| SR-41 | Leaving / transfer certificate requisition | 1 | Printable request with reason, transport stage, signatures, and bank details for a deposit refund | Students (certificate) + Documents (stored output) | `transferred_out`/`left_school`/`completed_studies` reasons exist (ORG.8E); no certificate | `OWNER_DECISION_REQUIRED` | **P1 once decided** (CRT-001/002, OD-SIS-2: format, numbering, issue register; never withheld for dues without a legal basis). Checkpoint SIS.EXP-4. Bank details never (rule 91) |
| SR-42 | Module navigation | 2 | Top menus of the Student module and the report catalogue | Navigation (presentation) | `StaffNavigation` over capabilities, School presentation profile (ORG.12F) | `EXISTING_DIFFERENT_ACCEPTED` | — Navigation only; excluded from coverage (§5) |

**Coverage:** 42 families, **318 screens** (sum of the Screens column), 0
unclassified screens.

### 3.2 Table B — authorization, assurance and rationale

Capabilities are existing seeded keys unless marked *(none yet)*; no
capability is added by this map.

| SR | Capability (existing) | Scope | Sensitive / assurance | Intentional difference | Build / do-not-build rationale |
|---|---|---|---|---|---|
| SR-01 | *(none yet)* | — | Demographic and family-status data of children | No attribute profiling | Legal: D-15, ADR 0040 small-cohort gate |
| SR-02 | Data Health hub: each check opens on its own source capability (e.g. `enrollments.view`) | whole School | none | Data Health reports, never repairs | Already met |
| SR-03 | `students.view` (+ `enrollments.view` for class) | Campus-adopted (`StudentAccess`) | Campus rows never carry DOB or Guardian data (rule 101) | Status changes stay on the assured profile action | Filters are additive reads on existing authority |
| SR-04 | `students.manage` (whole School); `guardians.manage` | whole School (names: Campus) | DOB on the whole-School profile only | One Guardian model; no father/mother schema | Identity complete; address is an owner decision; demographics legal |
| SR-05 | *(none yet)* | — | Highly Sensitive; masking + audited reveal like `payroll.statutory.identifiers.view` | Not stored | Legal gate per identifier |
| SR-06 | `students.manage` + `enrollments.manage` (+ `guardians.manage` if Guardians are imported) | whole School | idempotent, all-or-nothing (rule 107) | Adapter over `StudentService` and `StudentEnrollmentService`, never a second write path | Owner decision first |
| SR-07 | — | — | — | — | Not a product line |
| SR-08 | — | — | — | Validate on entry | Met |
| SR-09 | `enrollments.manage` over both Campuses | Campus (both) | row locks, blockers | Direct action, no request queue | Bulk is post-v1 |
| SR-10 | `students.manage` | whole School | closed reason + fresh MFA + DB-required event | `DIRECT_ASSURED`, never request/approve | Superior to an approval queue: the actor is accountable, the reason is closed, nothing is back-dated |
| SR-11 | *(none yet)* | — | family financial inference | — | D-14 |
| SR-12 | `students.view` + `enrollments.view` (register); Data Health as today | whole School | reason labels, never free text | No back-dating, so no back-dating audit | Read model over existing evidence |
| SR-13 | *(none yet)* | — | Guardian identity, possibly a binding agreement | Not modelled | Owner + legal |
| SR-14 | `admissions.*`, `finance.fee_concessions.*` | whole School / per domain | applicant documents = children's documents | Concessions after conversion only | Domain future |
| SR-15 | `finance.fee_concessions.*` | per Fees | — | — | Domain future |
| SR-16 | `timetable.*` | Campus | — | Timetable never authority (ADR 0063) | Timetable-owned |
| SR-17 | *(none yet)* | — | gender analytics on groups not adopted | Named concepts only | D-17 |
| SR-18 | *(none yet)* | — | assessment of children | — | Examinations/E40 |
| SR-19 | — | — | — | — | Profiling of children and families |
| SR-20 | `finance.fee_structures.*` | whole School | — | Fees target by structure | Met |
| SR-21 | *(none yet)*: a Student-document capability is part of OD-SIS-1 (only `hr.employees.documents.*` exist today) | whole School first | E21 retention; health-classified certificates | One Documents repository, owner-scoped | Owner decision |
| SR-22 | — | — | children's images | — | Legal |
| SR-23 | `guardians.view` (contacts) | whole School | encrypted contacts, no partial search (ADR 0041) | No column picker over personal fields | Owner decision + D-11 |
| SR-24 | *(none yet)* | — | small-cohort counts | Advisory capacity only | Owner decision |
| SR-25 | `students.view` + `guardians.view` | whole School | names only on the profile | No family dues aggregation | Family block is a small read; list needs a decision |
| SR-26 | — | — | family financial inference | — | D-14 |
| SR-27 | — | — | cross-School tenant data | Network never reads tenant tables | Analytics architecture decision |
| SR-28 | — | — | biometric data | — | Legal |
| SR-29 | `attendance.view` | Campus-adopted | no rates/tallies | Registers are the evidence | Attendance-owned |
| SR-30 | — | — | — | — | No evidence |
| SR-31 | *(none yet)* | — | health remarks | — | D-16 |
| SR-32 | `teaching.assignments.view` (existing web directory) | per domain | no staff contact on academic lists | — | Met |
| SR-33 | `school.audit.view` | whole School | values and IPs never recorded | History minimization | Met |
| SR-34 | `finance.payment_corrections.request`/`.approve` | whole School | fresh MFA, another person approves | — | Met |
| SR-35 | — | — | discipline data | — | Legal |
| SR-36 | `students.view` + `enrollments.view` | whole School | no Guardian contact | — | Read model |
| SR-37 | `enrollments.rollovers.view`/`.manage` | whole School | — | Never fee-gated | Met |
| SR-38 | — | — | — | — | Not adopted |
| SR-39 | — | — | gated money and counts | — | Domain future |
| SR-40 | — | — | extra personal data | — | Post-v1 after classification |
| SR-41 | *(none yet)* | whole School | certificate number register; no bank data | — | Owner decision |
| SR-42 | — | — | — | Menu ≠ permission (ORG.12F) | Met |

## 4. Summary

| Classification | Families | Screens | Ids |
|---|---|---|---|
| `EXISTING_EQUIVALENT` | 0 | 0 | — |
| `EXISTING_DIFFERENT_ACCEPTED` | 9 | 32 | SR-02, SR-08, SR-10, SR-20, SR-32, SR-33, SR-34, SR-37, SR-42 |
| `PARTIAL` | 7 | 58 | SR-03, SR-04, SR-09, SR-12, SR-25, SR-29, SR-36 |
| `SIS_BUILD` | 0 | 0 | — (the buildable parts live inside PARTIAL rows; §3.1) |
| `DOMAIN_FUTURE` | 8 | 68 | SR-11, SR-14, SR-15, SR-16, SR-18, SR-26, SR-27, SR-39 |
| `PLATFORM_FUTURE` | 0 | 0 | — |
| `POST_V1` | 1 | 5 | SR-40 |
| `DO_NOT_BUILD` | 4 | 18 | SR-07, SR-19, SR-30, SR-38 |
| `OWNER_DECISION_REQUIRED` | 8 | 89 | SR-06, SR-13, SR-17, SR-21, SR-23, SR-24, SR-31, SR-41 |
| `LEGAL_DECISION_REQUIRED` | 5 | 48 | SR-01, SR-05, SR-22, SR-28, SR-35 |
| **Total** | **42** | **318** | |

Reading the numbers:
- **No family is `EXISTING_EQUIVALENT`.** The reference packages almost
  every function differently (approval queues, unmasked contact reports,
  configurable field catalogues), and Lycenza deliberately does not copy
  that packaging. Nine families are met in a different, accepted form.
- **No family is wholly `SIS_BUILD`.** Every buildable SIS gap is the
  missing part of an existing capability (directory filters, a family
  block, a movement register, edit hardening).
- **Screens are dominated by what Lycenza does not adopt or cannot yet
  adopt:** 137 of 318 screens (43 %) are owner- or legal-gated, mostly
  demographic analytics (SR-01, 40) and the group hub (SR-17, 52).

## 5. Coverage scores

Family-level formulas, matching `REFERENCE-PARITY-MATRIX.md` §5:

- **Overall weighted** = (EE + EDA + ½·PARTIAL) / (all families − DO_NOT_BUILD
  − navigation). **Strict:** without ½·PARTIAL.
- **v1 weighted** = (EE + EDA + ½·PARTIAL) / (EE + EDA + PARTIAL + SIS_BUILD):
  only functions that exist or are v1 SIS candidates; domain-future,
  post-v1, owner- and legal-gated families are outside the v1 denominator.
  **Strict:** without ½·PARTIAL.
- A family shown as "Not available", a menu, or a deep link to another
  domain scores only what is actually implemented; SR-42 (navigation) is
  excluded from every denominator.

| Measure | Value |
|---|---|
| Overall weighted | **31.1 %** (11.5 / 37) |
| Overall strict | **21.6 %** (8 / 37) |
| v1 weighted | **76.7 %** (11.5 / 15) |
| v1 strict | **53.3 %** (8 / 15) |
| Screen-weighted, overall (weighted / strict) | 19.8 % / 10.1 % (59 / 298, 30 / 298) |
| Screen-weighted, v1 (weighted / strict) | 67.0 % / 34.1 % (59 / 88, 30 / 88) |
| E3+ coverage | equal to the overall figures: all 41 scored families are E5 |

These measure coverage of the **reference's Student surface**, not Lycenza
SIS completeness. Lycenza's own SIS local score is 96/100
(`docs/product/SIS-FINAL-LOCAL-UAT-2026-10-28.md`), against Lycenza's scope.
The gap between the two is the reference's breadth, most of it consciously
gated (§4).

## 5.1 After SIS.EXP-1 (2026-10-29)

SIS.EXP-1 improved three families **inside** their existing
classification; none changes class, so every score in §5 is unchanged
(same formula):

| SR | Built by SIS.EXP-1 | Still open (unchanged class) |
|---|---|---|
| SR-03 | Campus / Grade / Section filters on the directory (current class) | primary-Guardian ("point of contact") column, P3; KPI counts, gender, photo stay gated |
| SR-04 | edit hardening: no mass-assigned School/status, stale-form refusal, precise changed-field audit | Student home address (OD-SIS-7); demographics (legal) |
| SR-25 | the family block on the Student profile (siblings through an active shared Guardian) | the School-wide sibling list (SIS-019 / PV1-17); family dues (D-14) |

Not reclassified, as required: documents (SR-21), certificates (SR-41),
import (SR-06), groups (SR-17) and the report families.

## 5.2 After SIS.EXP-2 (2026-10-30)

| SR | Built by SIS.EXP-2 | New class |
|---|---|---|
| SR-36 | The School-wide movement register: Section changes and Campus transfers as one row each, the promotion/repeat history, and a per-Student timeline via the search. Read-only, whole-School, closed reasons, no parent contact | **`EXISTING_DIFFERENT_ACCEPTED`** (was PARTIAL) |
| SR-12 | The exit register (Exits filter): who, when, closed reason, final placement and outcome | stays `PARTIAL`: the leaving-certificate checkpoint waits on OD-SIS-2 (SR-41) |
| SR-37 | The promotion list now also sits in a School-wide register | unchanged (`EXISTING_DIFFERENT_ACCEPTED`) |

Recomputed with the §5 formula. EDA now counts 9 scored families; PARTIAL
counts 6.

| Measure | After SIS.EXP-0/1 | After SIS.EXP-2 |
|---|---|---|
| Overall weighted / strict | 31.1 % / 21.6 % | **32.4 % / 24.3 %** (12 / 37, 9 / 37) |
| v1 weighted / strict | 76.7 % / 53.3 % | **80.0 % / 60.0 %** (12 / 15, 9 / 15) |
| Screen-weighted, overall | 19.8 % / 10.1 % | 20.5 % / 11.4 % (61 / 298, 34 / 298) |
| Screen-weighted, v1 | 67.0 % / 34.1 % | 69.3 % / 38.6 % (61 / 88, 34 / 88) |

Not reclassified: certificates, documents, import, groups and the
strength/analytics families.

## 5.3 After SIS.EXP-3 (2026-10-30)

| SR | Built by SIS.EXP-3 | New class |
|---|---|---|
| SR-21 | Per-Student record documents: upload (PDF/JPEG/PNG), list, filter, attachment download, metadata edit and final archive, under a closed four-category catalogue (enrollment paperwork, previous-school record, consent form, family correspondence) and a Student 360 count card. Staff only, Highly Sensitive | **`PARTIAL`** (was OWNER_DECISION_REQUIRED). Still open: the reference's per-class checklist grid of required certificates; immunisation (health) and photograph categories stay legally gated; the birth certificate is not a named category |
| SR-14 | — (applicant documents stay out: no Admissions owner arm) | unchanged (`DOMAIN_FUTURE`) |
| SR-41 | — (no generated certificate) | unchanged (`OWNER_DECISION_REQUIRED`, OD-SIS-2) |

Recomputed with the §5 formula. EDA stays 9 scored families; PARTIAL now
counts 7. Moving SR-21 into the v1 denominator LOWERS the v1 percentages,
because a PARTIAL family enters the denominator but counts only half.

| Measure | After SIS.EXP-2 | After SIS.EXP-3 |
|---|---|---|
| Overall weighted / strict | 32.4 % / 24.3 % | **33.8 % / 24.3 %** (12.5 / 37, 9 / 37) |
| v1 weighted / strict | 80.0 % / 60.0 % | **78.1 % / 56.3 %** (12.5 / 16, 9 / 16) |
| Screen-weighted, overall | 20.5 % / 11.4 % | 21.1 % / 11.4 % (63 / 298, 34 / 298) |
| Screen-weighted, v1 | 69.3 % / 38.6 % | 68.5 % / 37.0 % (63 / 92, 34 / 92) |

Not reclassified: certificates (SR-41), contracts, statutory documents,
bulk import (SR-06), groups and the strength/analytics families.

## 6. Re-use

- SIS.EXP checkpoints cite `SR-nn` ids in their records and, where a
  Setup Registry definition changes, in its `reference` field.
- The SIS.EXP-8 closure re-audit re-reads this map unchanged and records
  each family's new status.
- The id → file index is kept with the pack; a future re-audit regenerates
  it from the same fingerprinted pack.
