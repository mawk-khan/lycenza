# SIS.EXP-0 — Student Information System current-state, reference-parity and expansion audit (2026-10-29)

> **Closed 2026-10-30:** the programme this audit opened is closed; see
> `SIS-EXPANSION-CLOSURE.md` for the final state of every finding.

Status: **audit record, documentation only.** First checkpoint of the SIS
expansion programme (`SIS.EXP`), after the Organization Control Plane
closure (ORG.12H, ADR 0080 §13). Nothing was built: no migration, runtime
code, capability, role, route, UI or demo data changed.

Companion records (all `docs/sis/`):
- `SIS-REFERENCE-CAPABILITY-MAP.md` — the 318-screen reference pack in 42
  families, classified, with coverage scores;
- `SIS-DOMAIN-OWNERSHIP-MAP.md` — reference function → canonical owner;
- `SIS-EXPANSION-ROADMAP.md` — waves, test and regression plan;
- `SIS-DEMO-PLAN.md` — demo audit and the scenarios each wave adds.

The previous SIS programme (SIS 1.1 → SIS 10.2,
`docs/product/SIS-CURRENT-STATE-AUDIT-2026-10-26.md`,
`SIS-FINAL-LOCAL-UAT-2026-10-28.md`) is the **local completion**
programme: it closed every Must and the selected Should items against
Lycenza's own scope (96/100). SIS.EXP is the **expansion** programme
against the reference Student surface. It is additive; it reopens none of
the completion programme's decisions.

**Headline.** The SIS foundation is sound and needs no replacement:
- tenancy and Campus scope hold at three layers;
- the lifecycle is database-enforced;
- Guardian data is minimised.

No HIGH or MEDIUM security finding. Four LOW findings (§U).

Most reference functions Lycenza lacks are owner- or legal-gated, or
belong to another domain. The SIS-owned, decision-free work left is
read-side: Student 360 summaries, directory filters, a family block, a
movement and exit register, and edit-path hardening. The P1 gaps (Student
documents, leaving certificates, bulk import) each wait on a recorded
owner decision.

Verdict: **SIS.EXP-0 PASS WITH OWNER-DECISION PREREQUISITES** (§AK).

---

## A. Baseline

| Item | Value |
|---|---|
| `HEAD` / `main` / `origin/main` | `1ef53e18` (fetched 2026-10-29), clean tree |
| Organization prerequisite | ORG.12H PASS WITH RECORDED CROSS-DOMAIN PREREQUISITES |
| Latest code-bearing full regression | `eb9814d2`: 10,004 cases, 10,003 passed, 1 expected skip (ESI-12), 0 failed — counter **0/5** |
| DDEV | `ddev env-check` PASS (one engine, one database; 355 migrations) |
| Test database | DDEV `school_os_test` was behind (missing ORG.12F tables); reset with the canonical `ddev test --reset-db` before measuring (§V) |
| Reference pack | 318 PNG, fingerprint `c7a3699e…6032`, owner-held (map §1) |

## B. Existing SIS architecture

- **Domains** (`apps/platform/app/Domain`):
  - **`Students`:** Students, placements (`student_enrollments`), lifecycle
    events, rollover, subject enrollments, numbering, duplicate warning,
    search, Campus access, Data Health checks, retention participants;
  - **`Guardians`:** Guardians, contacts, relationships, self-administration
    guard;
  - **`Admissions`:** applicants, applications, conversion;
  - read-only consumers: `Attendance`, `Identity/Portal`, Documents, Fees,
    Transport, Library, Hostel, Communications.
- **Key services:**
  - `StudentService` (create via the allocator, update, `changeStatus`);
  - `StudentEnrollmentService` (enroll, complete, withdraw, cancel,
    `cancelBeforeStart`, transfer, roll-number correction);
  - `CampusStudentOnboardingService`, `CampusStudentTransferService`;
  - `StudentNameEditService`;
  - `EnrollmentRollover*Service` (plan, dry run, bulk decisions, execution);
  - `StudentAccess`, `StudentPurposeLookup`, `StudentOperationalEligibility`;
  - `StudentActivityHistoryService`;
  - `StudentNumberAllocator`, `StudentDuplicateCandidateReader`;
  - `StudentProcessingAuthorizationService` (ADR 0038);
  - `GuardianService`, `GuardianContactService`,
    `StudentGuardianRelationshipService`.
- **Web routes:**
  - `/app/students` — directory, onboard, create, profile, overview, names,
    edit, history, status, Campus transfer, Guardians, account link,
    placements, processing authorizations;
  - `/app/relationships/*`;
  - `/app/guardians/*` — contacts, account link and invitation, portal
    off-boarding;
  - `/app/enrollments/*` — directory, closed-year placements, complete,
    withdraw, cancel, cancel-before-start, roll number, transfer;
  - `/app/enrollment-rollovers/*`;
  - `/app/admissions/*`;
  - `/app/data-health`;
  - `/app/attendance/students/{student}`.
- **API (`/api/v1/schools/{school}/…`, Sanctum human tokens only):**
  - students: index, store, show, update, status;
  - Guardians and relationships;
  - enrollments: directory, history, current, complete, withdraw, cancel,
    transfer;
  - subject enrollments;
  - enrollment rollovers;
  - applicants and admission applications.

  Mutations carry `throttle:school-api-mutations` and, where retryable,
  `idempotent` (§Q).

## C. SIS database

| Table | Forced RLS | Runtime (`school_os_app`) privileges | Key structural protections |
|---|---|---|---|
| `students` | yes | SELECT, INSERT, UPDATE (no DELETE) | unique `(school_id, student_number)`; `students_status_check` (active/inactive); `students_born_active`; `students_lifecycle_evidence` (status change needs a same-transaction lifecycle event); `students_advance_number_sequence`; retention guard + anchor triggers |
| `student_number_sequences` | yes | SELECT, INSERT, UPDATE; DELETE revoked | forward-only counter (`student_number_sequences_forward_only`) |
| `guardians` | yes | SELECT, INSERT, UPDATE | composite `(id, school_id)` |
| `guardian_contacts` | yes | SELECT, INSERT, UPDATE | encrypted value + HMAC lookup digest with key version (ADR 0041); one active primary per type; same-School FK |
| `student_guardian_relationships` | yes | SELECT, INSERT, UPDATE, **DELETE** (unlink is a hard delete, refused with 409 while evidence depends on it) | unique `(school_id, student_id, guardian_id)`; one primary per Student; composite same-School FKs |
| `student_guardian_account_links` | yes | SELECT, INSERT, UPDATE | exactly one of Student/Guardian; one active per Student, Guardian and membership |
| `student_enrollments` | yes | SELECT, INSERT, UPDATE | composite FKs to year/Campus/Grade/Section bound to the Section's context; one active per Student per year; roll number unique per Section; `student_enrollments_status_check`; `end_shape_check` (active ⇔ no end); born active; `freeze_placement`; `end_within_year`; start within year (SIS 4.2); withdraw/cancel require lifecycle evidence |
| `student_lifecycle_events` | yes, **append-only** | SELECT, INSERT | closed action × reason CHECK; School/User `ON DELETE RESTRICT` |
| `student_subject_enrollments`, `enrollment_rollover_*` (4 tables) | yes | per their migrations | composite same-School FKs; elective-group snapshot trigger |
| `student_processing_authorizations` | yes | no direct DELETE (retention function only) | closed purpose/basis/status CHECKs, termination chain |
| `applicants`, `admission_applications` | yes | SELECT, INSERT, UPDATE | provenance CHECK, one open application per context, terminal guard |

Migrations: 42 of the 355 migrations name Students, Guardians, enrollments or Admissions (some are cross-domain, e.g. Transport riders, marks)
(SIS 1.1 §19 lists the RLS isolation tests; all green in the `eb9814d2`
regression).

## D. Student Master

- **Stored:** number (allocated from the School counter, ADR 0079), first,
  middle and last name, date of birth (not in the future), status,
  timestamps. School and id are immutable.
- **Not stored, by decision:**
  - gender and demographics (D-15);
  - government identifiers (ADMISSIONS §4);
  - home address (post-v1, `STUDENT-GUARDIAN-IDENTITY.md`);
  - photo (DOC-002);
  - medical data (Health-tier review);
  - admission source, other than Admissions provenance on converted
    Students.
- **Duplicate prevention:** a School-unique number, plus a same-name and
  same-date-of-birth warning, re-checked at commit (never a block or
  merge). Data Health adds `student.possible_duplicate` and
  `student.number_case_collision`.
- **Audit:** `student.created`, `.updated` (field names),
  `.status_changed`, `.campus_onboarded`, `.account_linked`/`_unlinked`.

## E. Lifecycle

| Level | States (DB CHECK) | Transitions | Assurance |
|---|---|---|---|
| Student | `active`, `inactive` | deactivate (`left_school`, `transferred_out`, `completed_studies`, `duplicate_record`, `entered_in_error`); reactivate (`returned_to_school`, `deactivated_in_error`) | `students.manage`, whole School, closed reason, confirmation, `FreshMfaProof`, current authority re-read under `FOR NO KEY UPDATE`, lifecycle event DB-required, audited |
| Placement | `active`, `completed`, `withdrawn`, `transferred`, `cancelled` | complete; withdraw (`left_school`, `transferred_out`, `guardian_request`); cancel (`did_not_join`, `duplicate_placement`, `entered_in_error`); cancel before start (empty interval, ADR 0078); transfer (ends source, inserts target) | `enrollments.manage`; withdraw/cancel need reason + fresh MFA + DB-required event |

- There is **no** prospective/admitted Student state: prospective and
  admitted are Admissions application states (`draft`, `submitted`,
  `accepted`, `rejected`, `withdrawn`, `converted`).
- Graduation is a `completed` placement plus `completed_studies`.
- Re-enrollment is a reactivation plus a new placement (SIS-032).
- **Temporal integrity, DB-protected:**
  - start inside the Section's year;
  - end inside the year and not before the start;
  - withdraw/cancel never in the future;
  - placement context frozen.
- Lifecycle event time is `now()`. There is no effective or back-dated
  date and no scheduled future transition (D-13). Not reopened.

## F. Enrollment

- One authoritative placement row per Student × year × Section.
  - School, Campus, year and Grade are copied from the Section and bound
    by composite FK.
  - At most one active placement per Student per year.
  - History is permanent (rows are ended, never edited or deleted).
- Concurrent double enrollment in a year is refused by a partial unique
  index. A next-year draft placement may coexist with the current year's.
- Section change ends the source and inserts the target, same year and
  Grade only. A mid-year Grade change is refused.
- Campus transfer needs authority over both Campuses, plus blockers
  (Hostel, Transport, electives).
- Promotion, repeat, exclude and manual review go through rollover plans
  (dry run, version-guarded bulk decisions, resumable execution). A
  rollover never activates or closes a year.
- **Admissions ≠ Enrollment:** an application converts once
  (`AdmissionConversionService`, row lock) into a NEW Student plus the
  first placement. Admissions never owns placement afterwards.
- **Approvals:** no SIS action uses request/approve (§17 of the brief).
  New enrollment, Section change, Campus transfer and lifecycle
  transitions are direct, assured actions. This is deliberate: the
  reference approval screens (SR-10, SR-14) are classified in the map;
  none is a Lycenza gap.

## G. Academic placement — ownership

Academic Structure owns:
- years and terms;
- Grades (explicit `sequence`);
- Sections (one year; advisory `capacity`);
- subjects and offerings.

SIS consumes them (DOMAIN-MAP direction Students → Academic Structure)
and never duplicates them. The 32 direct reads of Academic Structure
models from SIS files remain a recorded rule-4 observation (SIS 1.1 §3),
not a defect.

## H. Guardian / family

- **One Guardian entity** (`guardians`); there is no Parent model.
- **Links:**
  - Student ↔ Guardian through `student_guardian_relationships`, with
    relationship type and flags (primary, legal guardian, emergency
    contact, authorised pickup);
  - one primary per Student.
- **Contacts:**
  - typed, encrypted, exact-match only (ADR 0041);
  - correction and reactivation (SIS 2.1).
- **Account link:** a Guardian links to a User through
  `student_guardian_account_links` (one active per Guardian and per
  membership) plus the `guardian` role.
- **Self-administration refused:** `GuardianSelfAdministrationGuard`.
- **Siblings:** derivable through a shared Guardian. Demo: 12 Guardians
  have more than one Student.
- **Isolation:** every row is School-owned and RLS-forced.

## I. Documents

- `documents` has `student_id`/`guardian_id` columns with same-School FKs.
- `DocumentService` and `DocumentListingService` **refuse** the `student`
  and `guardian` owner types (`DocumentOwnerTypeNotSupportedException`):
  - no Student upload, categories, verification, expiry or Guardian
    visibility;
  - 0 Student documents in the demo.
- Documents' own safety (object storage paths through
  `TenantStoragePath`, tenant isolation, retention through E21) is in
  place for the activated owner types.
- Student photos have **no** separate storage path; the ORG.12F media
  deferral is unrelated.
- **Status:** OWNER_DECISION_REQUIRED (OD-SIS-1).

## J. Groups

The reference "Student Groups" (SR-17, 52 screens) are:
- typed groups: academic, house, finance, store, custom, club, project;
- with responsibilities, mentors, badges and schedules;
- plus derived "system tags" (SR-19) and fee "segments" (SR-20).

Lycenza mapping:
- academic cohort = Grade + Section (exists);
- fee groups and segments = fee structures and selections (exists, Fees);
- communication audiences = Communications (exists);
- house, club and activity = **not built** (D-17 DEFER; no generic
  grouping framework);
- system tags = **DO_NOT_BUILD** (profiling).

A new generic group model is **not** needed. A named House (or Club)
concept may be modelled once a pilot names it (OD-SIS-4).

## K. Contracts

In the reference, a "Student contract" is:
1. annual pre-promotion **data and document verification** by parents;
2. a parent **acceptance** of enrolment/fee terms ("contract form signed",
   with agreed-on time and IP address);
3. fee-discount agreement evidence;
4. pre-admission document requirements and concession approvals.

It is **not** a legal-contract engine.

| Meaning | Recommended owner | Status |
|---|---|---|
| 1, 2 | Guardian Portal UX over SIS data confirmation; legal review of electronic acceptance | OWNER_DECISION_REQUIRED (OD-SIS-5) |
| 3 | Fees + Documents | DOMAIN_FUTURE |
| 4 | Admissions + Documents | DOMAIN_FUTURE |

**No SIS contract entity is recommended now.**

## L. Timetable

Timetable is owned entirely by the Timetable domain (Academic):
- periods, schedules and Rooms;
- registers taken against it;
- never authority (ADR 0063).

- **SIS:** no timetable, and should not own one.
- **Guardian portal:** no timetable view today (a Portal-owned future
  candidate).
- **Reference features** (cycles, one-day overrides, practical batches,
  workload): Timetable DOMAIN_FUTURE (SR-16).

## M. Attendance integration

Attendance owns registers, corrections and exceptions.

- **SIS profile:** links to `/app/attendance/students/{id}` when the actor
  holds `attendance.view` in some scope; that page re-checks its own
  Campus scope.
- **Not on the SIS profile:** attendance counts or rates (by design, ADR
  0040).
- **Reference reports** (rates, monthly %, daily model): Attendance-owned
  (SR-29); rates are gated.

## N. Finance integration

Fees and Payments own charges, payments, concessions, corrections and the
Outstanding fees read model (ORG.9B).

The SIS profile shows **no** finance summary today. A per-Student
balance is available in Finance (fee statement, Outstanding fees) under
Finance capabilities and Campus rules (rule 101).

**Candidate (SIS.EXP-1):** a link plus the Student's outstanding balance,
shown only to holders of `finance.charges.view` in scope, read through
Finance's own read service.

## O. Transport / Library / Inventory

- **Transport:** rider assignments (`transport_student_assignments`;
  8 in the demo).
- **Library:** loans and fines (5 loans in the demo).
- **Hostel:** residency (4 in the demo).
- None is shown on the SIS profile.
- **Inventory:** records no person (O5, rule 105), so there is no Student
  link.
- **Candidate (SIS.EXP-1):** a safe summary per domain (current route and
  stop; open loans; current residency), each behind its own view
  capability and Campus scope.

## P. Portal

- **Guardian portal** (local/testing only; `PortalAvailability`;
  production gated by POR-L1/E46, E21, E30–E32):
  - Student selection through `ActingGuardianResolver` (linked, eligible
    Students only);
  - inbox and conversations;
  - calendar;
  - attendance per linked Student (`portal.attendance.view` + Attendance
    module);
  - fees and payments (`portal.fees.view` + Fees);
  - no profile, timetable or documents view.
- **Student portal:** not built. The Student account link exists, but the
  linked Student persona gets 403 everywhere (ADR 0070 §28.12, POR-L1
  Q16–Q21). **LEGAL_DECISION_REQUIRED.**

## Q. API / partner access

- **Auth:** the SIS API is `auth:sanctum` + `school-membership` (human
  personal access tokens).
  - Every route carries `capability:`.
  - Every retryable mutation is `idempotent`, authorized before the
    guard (rule 98).
- **Projection:** the show endpoint is the whole-School detail (DOB
  included). The index is Campus-aware.
- **Partners:** `PartnerScopeRegistry::PRODUCTION` is **empty**; only the
  local/testing `partner.probe.read` scope exists. Partner clients
  therefore **cannot** reach any Student endpoint.
- **Production partner scope:** not approved. Not broadened here.
- **Exports:** none on any SIS surface (D-11 stays separate).

## R. Reports

Existing SIS-related read surfaces: **12**.

| # | Report | Route | Owner |
|---|---|---|---|
| 1 | Student directory, incl. inactive | `/app/students` | SIS |
| 2 | Placements directory | `/app/enrollments` | SIS |
| 3 | Closed-year placements | `/app/enrollments/closed-year-placements` | SIS |
| 4 | Per-Student history | `/app/students/{id}/history` | SIS |
| 5 | Rollover plans and results | `/app/enrollment-rollovers` | SIS |
| 6 | Data Health: the Student, placement and year checks | `/app/data-health` | SIS |
| 7 | Admissions list | `/app/admissions` | Admissions |
| 8 | Attendance registers | — | Attendance |
| 9 | Attendance exceptions | — | Attendance |
| 10 | A Student's attendance | `/app/attendance/students/{id}` | Attendance |
| 11 | Outstanding fees, incl. leavers | — | Fees |
| 12 | Teaching Assignments directory | — | Academic |

- **Reference report families:** 23 of the 42 families are reports,
  dashboards or analytics. Their owners are in
  `SIS-DOMAIN-OWNERSHIP-MAP.md` §2.
- **Genuine SIS report gaps:** a School-wide movement and exit register
  (SR-12/SR-36), and directory filters (SR-03).
- **Exports:** separately gated (D-11). None exist.

## S. Privacy

| Field / data | Stored? | Gate today | Needs ORG.12E central projection later? |
|---|---|---|---|
| Student names, number | yes | `students.view` (Campus rows: operational fields only) | no (direct identifier, but the core record) |
| Student date of birth | yes | whole-School profile only; never on Campus surfaces | **yes** (family `date_of_birth` already catalogued) |
| Guardian names | yes | `students.view` on the profile; `guardians.view` on Guardian pages | candidate (Guardian identity) |
| Guardian email/mobile | yes, encrypted | `guardians.view`; exact-match lookup only | **yes** (family `guardian_contact`) |
| Relationship flags (legal guardian, pickup) | yes | `students.view` | candidate |
| Processing authorizations | yes | `students.processing_authorizations.*` + `mfa` | no (already its own capability + MFA) |
| Home address, statutory identifiers, gender, religion/caste, health, photo, biometrics | **no** | — | would each need a new family before storage |

- **Open legal decisions:** see §AC.
- **No field policy is implemented here.**

## T. Authorization matrix

| Feature | Capability | Scope | Ownership / assurance | Module |
|---|---|---|---|---|
| Directory | `students.view` | Campus-adopted (`StudentAccess`) | Campus rows minimised | core |
| Full profile, history, Guardians | `students.view` | declared whole School | contacts need `guardians.view` | core |
| Create / edit identity | `students.manage` | whole School | allocator number; duplicate warning | core |
| Name edit | `students.manage` | Campus (current in-scope placement) | `lockedWriteTarget()` | core |
| Status change | `students.manage` | whole School | reason + fresh MFA + DB event | core |
| Campus onboarding | `students.manage` AND `enrollments.manage` | destination Campus | new Student only, atomic | core |
| Placement read | `enrollments.view` | Campus-adopted (current only) | — | core |
| Placement writes | `enrollments.manage` | whole School (Campus transfer: both Campuses) | withdraw/cancel: reason + MFA + event | core |
| Rollover | `enrollments.rollovers.view` / `enrollments.manage` | whole School | version-guarded | core |
| Guardians | `guardians.view` / `.manage` | whole School | self-administration guard | core |
| Processing authorizations | `students.processing_authorizations.*` | whole School | `mfa` route middleware | core |
| Admissions | `admissions.view` / `.manage` | applications Campus-adopted | conversion whole School | `admissions` |
| Portal | `portal.*` (guardian scope) | linked Students only | ActingGuardian | `guardian_portal` + source module |

- **Roles holding these keys** (demo catalogue query):
  - `school_admin` and `principal`: Students, Guardians, Enrollments,
    Admissions; rollover manage for the School Admin only;
  - `admissions_officer`: Admissions only;
  - `teacher`: `attendance.teacher` only;
  - `guardian`: `portal.*`.
- **No platform or group role holds any of them**, so Platform and
  Network roles have no implicit Student access:
  - elevation opens no School route (rule 83);
  - Group reads are limited to `curriculum.coverage` (rule 84);
  - Network reads touch platform tables only (rules 113, 117).
- **No Student-specific role is proposed** (rule 93).

## U. Security findings

Method: code and schema inspection, the runtime privilege and RLS
catalogue queried on DDEV, the role → capability catalogue, and the
existing isolation test inventory (SIS 1.1 §19). No penetration test.

| Area | Result |
|---|---|
| Cross-School IDOR | none found: forced RLS + `SchoolScope` + 404 hiding; HTTP foreign-School tests exist |
| Campus scope escape | none: `StudentAccess` applies scope in SQL; adopted keys declared (rule 101) |
| Guardian ownership escape | none: ActingGuardian and linked-Student checks |
| Teacher ownership escape | none: the teacher holds no Student capability |
| Platform / Network access | none |
| Document leakage | not reachable: the Student owner type is refused |
| Lifecycle bypass | none: DB triggers require evidence; born-active; CHECKs |
| Stale authorization | authority re-read under lock on status, placement and transfer writes |
| Export / API exposure | no exports; partner scopes empty |

**HIGH:** none. **MEDIUM:** none.

**LOW:**
- **L-1 Mass-assignable status/School fields.** `Student::$fillable`,
  `StudentEnrollment::$fillable` and `Guardian::$fillable` include
  `school_id` and `status`, and `StudentService::update()` passes caller
  attributes straight to `update()`. Controllers whitelist today, and
  the DB refuses a status change without lifecycle evidence, and RLS
  WITH CHECK refuses a School change, so it is not exploitable. Rule 97's
  `schools.status` precedent suggests removing both from `$fillable` and
  whitelisting in the service. → SIS.EXP-1.
- **L-2 Lost update on the Student profile.** The identity edit has no
  stale-form token and takes no row lock (last writer wins between two
  editors). Guardian contacts already use a version token. → SIS.EXP-1.
- **L-3 Imprecise audit.** `student.updated` records `changedFields` as
  every submitted key (the web form always submits five fields), not the
  fields that actually changed. → SIS.EXP-1.
- **L-4 Cascading School keys.** `students.school_id` (and the Guardian
  tables) are `ON DELETE CASCADE`. School deletion is refused at runtime
  (rule 86) and retention triggers guard Students, so this is
  informational. Record it for the History-RESTRICT review (rule 97);
  no change proposed now.

No prerequisite remediation is required before expansion planning.

> **Update (SIS.EXP-1, 2026-10-29):**
> - **Closed:** L-1 (Student and Guardian `school_id`/`status` no longer
>   fillable; trusted paths explicit; `StudentService::update()`
>   whitelists), L-2 (stale-form token on both identity edit paths) and
>   L-3 (`changedFields` precise; an unchanged save writes nothing).
> - **Carried forward, informational:** L-4 (cascading School keys).
> - **Residual LOW, new:** the Guardian name edit has no stale token and
>   lists submitted fields, and `StudentEnrollment` still mass-assigns
>   `school_id`/`status` (DB-frozen and evidence-guarded). Neither is in
>   SIS.EXP-1's scope.

## V. Performance

Measured on DDEV against `school_os_test`, as School Admin, with fixtures
at 10 and 40 Students (2 Sections). The first Student has 4 Guardians,
each with an encrypted contact. The temporary harness was deleted and
never committed.

| Screen | Route | Queries (10 / 40 Students) | Time (warm) |
|---|---|---|---|
| Student directory | `/app/students` | 27 / 27 | 25 ms |
| Student profile | `/app/students/{id}` | 33 / 33 | 22 ms |
| Student history | `/app/students/{id}/history` | 17 / 17 | 12 ms |
| Campus overview | `/app/students/{id}/overview` | 29 / 29 | 20 ms |
| Placements directory | `/app/enrollments` | 23 / 23 | 19 ms |
| Guardian list | `/app/guardians` | 11 / 11 | 12 ms |
| Guardian detail | `/app/guardians/{id}` | 28 / 28 | 19 ms |
| Data Health | `/app/data-health` | 49 / 49 | 34 ms |

- **N+1:** none. Query counts are constant across page sizes; existing
  pins agree (`StudentListPlacementUiTest`, the
  `StudentEnrollmentReadServiceTest` budget).
- **Blockers:** none.
- **SIS.EXP-1 constraint:** each Student 360 summary must add a constant
  number of queries (one bounded read per domain). Budget: ≤ 50 for the
  profile.

## W. Demo audit

See `SIS-DEMO-PLAN.md` §1.

- **Lycenza Demo School** is the only School with SIS data:
  - 36 Students, 24 Guardians, 36 relationships, 36 active placements;
  - one Campus, 3 years;
  - 12 Guardians with more than one Student;
  - 2 portal links;
  - 4 applications;
  - 1 rollover plan.
- **Gaps:** 0 inactive Students, 0 ended placements, 0 lifecycle events,
  0 Students with more than one Guardian, 0 Student documents, a single
  Campus.
- **Lycenza Demo Annexe:** 3 Students, no placements.
- **No SIS data:** Northstar Gachibowli (2 Campuses, the canonical
  multi-Campus School), Greenfield and Horizon, along with every other
  Northstar School.

## X. Reference screenshot audit

- **Pack:** 318 screens, all inspected; 0 unreadable; 6 partly legible
  (S095–S099 stitched captures, S260 low resolution); 3 byte-identical
  repeats.
- **Families:** 42. Repeated-state families: SR-01, SR-16, SR-17, SR-29,
  SR-39 (map §1.1).
- **Method:** a tiled contact set — 778 tiles, 497 read, every screen
  covered; the long pages were sampled at the head, middle and tail.

## Y. Capability map totals

| Classification | Families |
|---|---|
| EE | 0 |
| EDA | 9 |
| PARTIAL | 7 |
| SIS_BUILD | 0 |
| DOMAIN_FUTURE | 8 |
| PLATFORM_FUTURE | 0 |
| POST_V1 | 1 |
| DO_NOT_BUILD | 4 |
| OWNER_DECISION_REQUIRED | 8 |
| LEGAL_DECISION_REQUIRED | 5 |
| **Total** | **42** |

## Z. Coverage scores

- **Overall:** 31.1 % weighted / 21.6 % strict.
- **v1:** 76.7 % weighted / 53.3 % strict.
- **Screen-weighted, overall:** 19.8 % / 10.1 %.
- **Screen-weighted, v1:** 67.0 % / 34.1 %.
- **E3+ = overall** (every family is E5).

Formulas are in map §5. These figures measure the reference's surface.
Lycenza's own SIS completeness stays 96/100.

## AA. P0 / P1 gaps

- **P0:** none.
- **P1, each blocked on an owner decision:**
  - Student documents (SR-21, OD-SIS-1);
  - leaving/transfer certificate (SR-41, OD-SIS-2);
  - bulk Student import (SR-06, OD-SIS-3).
- **P2, decision-free SIS builds:**
  - Student 360 summaries;
  - directory filters;
  - movement and exit register;
  - edit hardening (L-1..L-3).

## AB. Owner decisions

All are in `SIS-EXPANSION-ROADMAP.md` §3: OD-SIS-1 … OD-SIS-13.

- Student documents.
- Leaving certificate.
- Bulk import.
- Houses/clubs.
- Annual confirmation and acknowledgement ("contracts").
- Section headcount vs capacity.
- Home address.
- Effective-dated lifecycle (D-13).
- Cross-School transfer.
- Student Portal.
- Student-number case policy.
- Guardian status reason.
- Student merge.

## AC. Legal decisions

- Student Portal (POR-L1 / E46).
- Statutory and government identifiers (D-15; per identifier).
- Health and medical data: immunisation certificates, blood group,
  free-text illness in leave requests (Health-tier review,
  DATA-CLASSIFICATION).
- Sensitive documents and retention (E21).
- Children's photographs (DOC-002).
- Biometrics, RFID and device attendance (HRX-L2).
- Demographic profiling (caste, religion, family status: D-15, ADR 0040).
- Discipline and suspension (LRN-005).
- Person-count analytics (ADR 0040 7.4 counsel route).
- Electronic acceptance of fee/enrolment terms (OD-SIS-5).

## AD. Domain future (not SIS)

| Owner | Reference functions |
|---|---|
| Timetable | SR-16 |
| Attendance | SR-29 daily model, rates; SR-31 leave if D-16 changes |
| Fees / Payments | SR-15, SR-20, SR-26 |
| Admissions | SR-14, SR-39 |
| Documents | SR-14, SR-15, SR-21 storage |
| Examinations | SR-18 |
| Analytics | SR-01, SR-11, SR-24, SR-26, SR-27, SR-39 |
| Integrations / Communications | SR-08, SR-28 |
| Portal | SR-13 acknowledgement UX; Guardian timetable/profile/documents views |

## AE. Proposed SIS expansion waves

See `SIS-EXPANSION-ROADMAP.md` §2.

| Wave | Scope | Precondition |
|---|---|---|
| SIS.EXP-1 | Student 360 & directory | none |
| SIS.EXP-2 | Movement & exit register | none |
| SIS.EXP-3 | Student documents | OD-SIS-1 |
| SIS.EXP-4 | Leaving certificate | OD-SIS-2 + EXP-3 |
| SIS.EXP-5 | Bulk import | OD-SIS-3 |
| SIS.EXP-6 | Annual confirmation & acknowledgement | OD-SIS-5 + legal |
| SIS.EXP-7 | House & activity groups | OD-SIS-4 |
| SIS.EXP-8 | Parity closure re-audit | — |

## AF. Demo plan

See `SIS-DEMO-PLAN.md` §3. Every wave extends the canonical Organization
demo world, with Northstar Gachibowli as the multi-Campus SIS School and
Lycenza Demo School kept as the existing fixture.

## AG. Test / regression plan

See `SIS-EXPANSION-ROADMAP.md` §4. Each wave runs:
- scoped tests, authorization allow/deny and RLS;
- then a risk-based regression level.

## AH. Documentation

**Created:**
- `docs/sis/SIS-EXP-0-CURRENT-STATE-AUDIT.md` (this file);
- `docs/sis/SIS-REFERENCE-CAPABILITY-MAP.md`;
- `docs/sis/SIS-EXPANSION-ROADMAP.md`;
- `docs/sis/SIS-DOMAIN-OWNERSHIP-MAP.md`;
- `docs/sis/SIS-DEMO-PLAN.md`.

**Updated:**
- `docs/roadmap/MASTER-ROADMAP.md` (programme row 14);
- `docs/product/REFERENCE-SCREENSHOT-REGISTER.md` (SF-SIS families
  superseded for the Student area);
- `docs/product/SIS-CURRENT-STATE-AUDIT-2026-10-26.md` (pointer row).

## AI. Publication

Docs-only candidate on `integration/sis-exp-0`, published to `main` by
`lycenza-ci publication-check --push` after a green exact-SHA `CI gate`
(rule 96). The commit SHA is recorded in the roadmap row.

## AJ. Repository state

- **Runtime:** unchanged (no `apps/`, `services/`, `packages/` or
  `infrastructure/` file changed).
- **Tests:** no committed test change. The temporary measurement test was
  removed before commit.
- **Regression counter:** stays **0/5** (no runtime change).

## AK. Verdict

**SIS.EXP-0 PASS WITH OWNER-DECISION PREREQUISITES**

- The audit is closed. The SIS architecture is fundamentally sound;
  expansion is additive, and no foundation needs replacement.
- SIS.EXP-1 can start on owner authorisation with no decision.
- Every P1 wave needs its recorded owner decision first, and some need a
  legal determination.

## AL. First implementation checkpoint

**SIS.EXP-1 — Student 360 Profile & Directory Enhancement** (not started).

**Why first:**
- it is the only decision-free, SIS-owned, P2 work with high operator
  value;
- it adds no schema, capability or Campus adoption;
- it closes the LOW findings;
- it creates the profile sections that SIS.EXP-3/4 (documents,
  certificates) plug into.

**Prerequisites:**
- owner authorisation;
- `ddev env-check`;
- a fresh `ddev demo-reset`;
- the canonical demo extended per `SIS-DEMO-PLAN.md` §3.1, inside that
  checkpoint;
- E16 validity through 2026-11-12.

---

## Explicit questions (brief §112)

| # | Answer |
|---|---|
| 1 | 318 screens audited |
| 2 | 42 functional families |
| 3 | None unreadable; 6 partly legible (S095–S099, S260) |
| 4 | SR-01, SR-16, SR-17, SR-29, SR-39 (plus tab/modal repeats in SR-09, SR-10, SR-12, SR-19, SR-24, SR-25, SR-37, SR-40) |
| 5 | `App\Domain\Students\Infrastructure\Student` (`students`) |
| 6 | `App\Domain\Guardians\Infrastructure\Guardian` (`guardians`) |
| 7 | No — no Parent model exists or is proposed |
| 8 | `student_guardian_relationships` (type + primary/legal/emergency/pickup flags) |
| 9 | Student: active, inactive. Placement: active, completed, withdrawn, transferred, cancelled |
| 10 | All: deactivate/reactivate, complete, withdraw, cancel, cancel-before-start, transfer, Campus transfer — no approval workflow |
| 11 | Student status change, placement withdraw and cancel (closed reason + fresh MFA + lifecycle event) |
| 12 | Yes: start/end within the year, end ≥ start, never future, frozen context, evidence triggers |
| 13 | One placement row per Student × year × Section, bound to the Section context |
| 14 | Yes, permanent history |
| 15 | `student_enrollments` (year, Campus, Grade, Section from the Section, roll number) |
| 16 | No — one active placement per Student per year (partial unique index) |
| 17 | Yes: reactivation + new placement; annually via rollover |
| 18 | Yes (rollover promote) |
| 19 | Yes (rollover repeat, per item; bulk repeat is an open residual) |
| 20 | Yes (`CampusStudentTransferService`) |
| 21 | No — and not as a transfer: a destination School admits a new Student (OD-SIS-9) |
| 22 | None represents a Lycenza gap |
| 23 | Inactivation/reactivation (SR-10), Campus transfer (SR-09), pre-admission concession (Fees-owned shared approval already) |
| 24 | Parent data/document verification before promotion + acceptance of enrolment/fee terms + fee-discount and pre-admission documents |
| 25 | No |
| 26 | Portal (acknowledgement UX) over SIS data confirmation, Documents, Fees, Admissions — after OD-SIS-5 |
| 27 | Academic cohorts only (Grade/Section); no house/club |
| 28 | Grade, Section, fee structures/selections, Communications audiences |
| 29 | No; a named House/Club concept only after OD-SIS-4 |
| 30 | None (owner type refused) |
| 31 | No Student upload exists to be unsafe; the Documents pipeline itself is tenant-safe |
| 32 | Yes (schema same-School FKs, RLS) |
| 33 | Not applicable until activation; would need a health-classified tier |
| 34 | No |
| 35 | No (Timetable) |
| 36 | No (Attendance) |
| 37 | No (Fees/Payments) |
| 38 | No (Transport) |
| 39 | No (Library) |
| 40 | No (Inventory; records no person) |
| 41 | Partly: whole-School profile with identity, Guardians, placements, history link, attendance link |
| 42 | Guardians, placement, attendance link |
| 43 | Finance balance, Transport, Library, Hostel, subject enrollments, documents (not activated) |
| 44 | Teacher holds `attendance.teacher` only: owned classes' rosters inside Take, audited; no Student directory |
| 45 | `ActingGuardianResolver`: linked, eligible Students only, per portal capability + source module |
| 46 | No |
| 47 | No |
| 48 | Yes, to human Sanctum tokens with School membership and capabilities |
| 49 | No (partner production scopes empty) |
| 50 | No |
| 51 | Names, number, DOB; Guardian names and encrypted contacts; relationship flags; processing authorizations |
| 52 | DOB and Guardian contacts (catalogued families); Guardian identity/flags are candidates |
| 53 | Yes (§AC) |
| 54 | 12 read surfaces (§R) |
| 55 | Movement/exit register (SR-12/36); directory filters (SR-03) |
| 56 | See `SIS-DOMAIN-OWNERSHIP-MAP.md` |
| 57 | Yes (D-11); none exist |
| 58 | 27 |
| 59 | 33 |
| 60 | None |
| 61 | None |
| 62 | None |
| 63 | None |
| 64 | L-1 mass-assignable status/School, L-2 lost update on profile edit, L-3 imprecise `changedFields`, L-4 cascading School keys (informational) |
| 65 | 21.6 % |
| 66 | 31.1 % |
| 67 | 53.3 % |
| 68 | 76.7 % |
| 69 | 0 |
| 70 | 9 |
| 71 | 7 |
| 72 | 0 |
| 73 | 8 |
| 74 | 0 |
| 75 | 1 |
| 76 | 4 |
| 77 | 8 |
| 78 | 5 |
| 79 | None |
| 80 | SR-21 documents, SR-41 leaving certificate, SR-06 import — all owner-decision-gated |
| 81 | Not now. When EXP-3/EXP-4 land, add `SR-21`/`SR-41` to `students.student_records`' `reference` and consider a documents readiness fact in that wave |
| 82 | No. EXP-1 adds no capability; EXP-3/EXP-4 capabilities would surface automatically through the code-owned catalogue |
| 83 | Yes (`SIS-DEMO-PLAN.md`) |
| 84 | Student 360 summaries, directory filters, family block, edit hardening (L-1..L-3) |
| 85 | School-wide movement & exit register (+ rollover bulk repeat/manual-review residual) |
| 86 | Demographics, identifiers, photos, biometrics, discipline, profiling tags, analytics, bulk transfer, manual attendance totals, diaries |
| 87 | Pre-admission documents, application-stage tracking, admission analytics (with Analytics), enquiry/CRM (existing ADM decisions) |
| 88 | Timetable (cycles, overrides, workload), teaching lists, co-scholastic assessment |
| 89 | Daily model, rates, late/session views, device attendance, Student leave |
| 90 | Fee segments, discount agreements, refunds, exit revenue, strength audit |
| 91 | Guardian timetable/profile/documents views; acknowledgement UX; Student Portal (legal) |
| 92 | Student document repository, checklist, verification/expiry, stored certificates |
| 93 | Strength, composition, attrition, admissions revenue, branch-wise counts |
| 94 | Yes |
| 95 | No |
| 96 | Additive |
| 97 | Yes, for SIS.EXP-1 and EXP-2; the P1 waves after their decisions |
| 98 | SIS.EXP-1 — Student 360 Profile & Directory Enhancement |
| 99 | Decision-free, SIS-owned, high value, no schema; closes L-1..L-3; frames later waves |
| 100 | Owner authorisation; env-check; fresh demo-reset; E16 validity; demo extension planned inside the wave |
