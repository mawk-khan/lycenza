# SIS expansion roadmap (SIS.EXP)

Status: **CLOSED 2026-10-30** (`SIS-EXPANSION-CLOSURE.md`); originally a proposed programme, documentation only (SIS.EXP-0, 2026-10-29).
Nothing here is started or authorised. Each wave needs the owner's explicit
authorisation, and the waves marked *decision-gated* also need the named
owner (and, where stated, legal) decision recorded first. Evidence:
`SIS-EXP-0-CURRENT-STATE-AUDIT.md`, `SIS-REFERENCE-CAPABILITY-MAP.md`.

## 1. Principles

Every wave:
1. has **one coherent business outcome**;
2. keeps domain ownership (`SIS-DOMAIN-OWNERSHIP-MAP.md`). SIS reads
   another domain only through that domain's Application read service,
   under that domain's capability and Campus rules (rules 4, 101);
3. adds no capability, role, Campus adoption, export, schema or setup
   definition unless the wave's own brief names it;
4. **extends the canonical Organization demo world** (`SIS-DEMO-PLAN.md`),
   never a separate SIS universe;
5. ends with an owner walkthrough on a fresh `ddev demo-reset`;
6. uses risk-based regression (§4) and publishes through
   `integration/<slice>` → exact-SHA `CI gate` →
   `lycenza-ci publication-check --push` (rule 96).

**Numbering:**
- `SIS.EXP-n` is the expansion programme.
- The completion programme's hierarchical ids (SIS 1.1 … 10.2) are closed
  history and are not reused.
- Corrections are `SIS.EXP-n.1`, `.2`, …

## 2. Waves

The brief's candidate order was re-sequenced on repository evidence:
- Enrollment, placement, re-enrollment, promotion, transfer and lifecycle
  (the brief's candidate EXP-2 and EXP-4) are **already complete**
  (SIS 4.x/5.x, ORG.7F, ORG.8E/8G), so they get no wave.
- Guardian & family (the brief's EXP-3) is complete except a family block,
  which folds into EXP-1.
- The P1 items are decision-gated, so they follow the decision-free waves.

| Wave | Name | Outcome | Gate | Size | Schema | Map rows |
|---|---|---|---|---|---|---|
| **SIS.EXP-1** | Student 360 Profile & Directory Enhancement | A whole-School Student profile that shows safe summaries from the owning domains. A directory filterable by Grade/Section/Campus. A family block. A hardened edit path | owner authorisation only | M | none | SR-03, SR-04 (hardening), SR-25 (family block) |
| **SIS.EXP-2** | Student Movement & Exit Register | One School-wide, date-ranged register of admissions/conversions, placements, transfers, completions, withdrawals, cancellations, deactivations and reactivations, with closed reason labels and Campus. Plus the rollover bulk Repeat/Manual-Review residual | owner authorisation only | S–M | none (read model over `student_lifecycle_events`, placements, rollover items, Admissions conversions) | SR-12, SR-36, SR-37 |
| **SIS.EXP-3** | Student Documents | Activate the `student` (and, if decided, `guardian`) owner type in the one Documents repository, with a closed category catalogue, upload, view, archive, optional verification and expiry, a per-class checklist and its own capabilities | *decision-gated* OD-SIS-1 + E21 retention classes; health-tier review for immunisation records | M–L | yes (§5) | SR-21 (SR-14 for applicants only if OD-SIS-1 includes them) |
| **SIS.EXP-4** | Leaving / Transfer Certificate | Issue a numbered certificate from a leaver's lifecycle facts, with an append-only issue register and the stored output in Documents. Never withheld for dues without a legal basis | *decision-gated* OD-SIS-2 (format, numbering, wording) + legal review; needs EXP-3 | M | yes (§5) | SR-41, SR-12 (certificate checkpoint) |
| **SIS.EXP-5** | Bulk Student & Guardian Import | An add-only, all-or-nothing import adapter over `StudentService`, `StudentEnrollmentService` and the Guardian services. Preview is read-only and signed; commit is one transaction; no match by name (rule 107 pattern) | *decision-gated* OD-SIS-3 (OD-SWC-4 / OD-PPR-20) | M–L | none expected (server-issued form key) | SR-06 |
| **SIS.EXP-6** | Annual Record Confirmation & Guardian Acknowledgement | Before a new year, a Guardian confirms the Student's record and acknowledges named School terms. The Portal owns the UX; SIS owns the confirmation record | *decision-gated* OD-SIS-5 + legal review of electronic acceptance; Guardian portal production gates still apply (POR-L1) | M | yes (§5) | SR-13 |
| **SIS.EXP-7** | House (or Club) Membership | The one named concept a pilot asks for: per-year membership, one House per Student per year, optional responsibility labels from a closed list. No generic group engine, no gender analytics, no badges | *decision-gated* OD-SIS-4 (D-17 revisit) | M | yes (§5) | SR-17 (part) |
| **SIS.EXP-8** | SIS Parity Closure Re-audit | Re-read the 42-family map; reclassify; rescore; verify the demo world; record the remaining owner/legal gates | after the chosen waves | audit | none | all |

### 2.0g SIS 11.x — Advanced SIS expansion: SIS 11.1 IMPLEMENTED (2026-10-30)

An additive programme after SIS.EXP-CLOSE. It does not reopen SIS.EXP or the
core 96/100. SIS 11.1 was re-scoped by the owner onto `c5e3dd23`
(`SIS-11-1-REPORTING-FOUNDATION.md` §1):

- a closed SIS report catalogue (`StudentReportRegistry`), a Reports hub
  (*People → Student reports*) and a shared report page;
- reusable filter, column (privacy class) and closed-sort contracts;
- the Directory, placements and movement register registered as governed
  entries, not rebuilt;
- one new report, `students.lifecycle` (ORG.8E lifecycle events, "Cancelled
  before start" for ADR 0078's empty interval);
- no schema, capability, role, Campus adoption, export or analytics.

The 28 report-bearing reference families are classified (8 SIS-owned,
5 cross-module, 8 other-ERP, 7 rejected). Advanced SIS Screenshot Coverage
(report subset) is 0/8 → 5/8.

**SIS 11.2 IMPLEMENTED (2026-10-31, `SIS-11-2-OPERATIONAL-REPORTS.md`):**

- `admissions.applications`: the Campus-aware Admissions list, extended
  with School-local created dates, a closed sort, an inactive-Campus label
  and a permission-checked converted-Student link, then registered;
- `students.siblings`: SR-25, owner-approved names-only list, whole School;
- SR-02: equivalent through Data Health;
- SR-21: deferred by owner decision;
- the SIS-owned subset is 6/8 governed and 8/8 dispositioned;
- no schema, capability, route, export or analytics.

**SIS 11.3 IMPLEMENTED (2026-10-31, `SIS-11-3-SECTION-CAPACITY.md`):** OD-SIS-6
was approved.

- The existing advisory `sections.capacity` is reused and guarded in the
  database (≥ 1 or NULL; frozen in a closed year).
- Strength comes from the one placement predicate, on the School-local
  today clamped into the year.
- New `students.section_capacity` report (Campus-aware).
- Occupancy shows on Sections setup, enrolment, Campus transfer, Admissions
  conversion and rollover targets.
- An advisory rollover warning, never a blocker.
- Not built: hard cap, Data Health finding, percentage or trend.

**SIS 11.4 IMPLEMENTED (2026-10-31, `SIS-11-4-EXPORT-AND-PRINT.md`):** D-11
(SIS export) is decided and delivered.

- New `students.export` and `admissions.export` capabilities, School
  Administrator only; they are needed in addition to the report's own
  authority.
- Five registered reports export CSV from their own queries, formula-safe,
  streamed, at most 10,000 rows, and audited without row values.
- A print-ready view under the same rules (at most 1,000 rows).
- Siblings and placements are not exportable; XLSX and PDF are deferred.
- No schema, files or jobs.

**SIS 11.5 IMPLEMENTED (2026-10-31, `SIS-11-5-DOCUMENT-COMPLIANCE.md`):**
Student compliance, documents and verification, on the one Documents
repository.

- Owner decisions: `students.documents.verify` (school_admin, principal);
  `admissions.documents.view`/`.manage` (school_admin, principal,
  admissions_officer), Campus-scoped with the application; a Birth
  certificate type; applicant documents follow Admissions retention and move
  to the Student on conversion; the cohort checklist is for whole-School
  document viewers only, behind MFA.
- Requirement rules (closed dimensions, immutable terms, retire never delete).
- Derived compliance (missing, pending, verified, rejected), advisory only.
- Verify/reject with closed reasons and a fresh code; replacement
  supersedes.
- Pre-admission documents per application.
- Cohort checklist (`students.document_compliance`, states only,
  browser-only). SR-21 → governed (immunisation and photo stay out).

**Next: owner decision; NOT STARTED; never auto-start.**

### 2.0f SIS.EXP-CLOSE — PROGRAMME CLOSED (2026-10-30)

**The SIS expansion programme is closed** (`SIS-EXPANSION-CLOSURE.md`).
- `SIS_BUILD = 0`, no HIGH/MEDIUM finding, all P1 gaps delivered.
- Every remaining item has a named owner (closure record §6).
- **Not built:** EXP-6 (annual confirmation: legal review first) and EXP-7
  (House: pilot-driven).
- The closure re-audit (formerly EXP-8) is SIS.EXP-R1 plus SIS.EXP-CLOSE.
- **Next:** an owner decision. The recommendation is HR ERP, starting with a
  read-only HR.EXP-0 audit against the HR reference pack.

### 2.0e SIS.HARDEN-1 — IMPLEMENTED (2026-10-30)

The decision-free residuals R-1 and R-3 … R-7 are closed (audit §9.0).
- No migration, capability, role, Campus adoption or API-contract change.
- **R-2** stays deferred; **R-9** stays with Documents/security.
- **Next:** `SIS.EXP-CLOSE` (owner authorisation; not started).

### 2.0d SIS.EXP-R1 — POST-P1 AUDIT (2026-10-30, documentation only)

`SIS-EXP-R1-POST-P1-AUDIT.md`:
- **P1:** all three original P1 gaps are delivered (EXP-3/4/5). The EXP-4
  certificate remains development complete / production legally gated.
- **OD-SIS-5 (annual record confirmation):** `LEGAL_REVIEW_REQUIRED`. It is
  cross-domain and Portal-led (POR-L1), and its reference form is an
  electronic contract acceptance that gates fee-based promotion. **EXP-6 as
  written below is not authorised and not recommended next.**
- **OD-SIS-14 (new):** Admissions/Registrar import authority. The
  recommendation keeps `students.import` School-Admin-only; the Admissions
  Officer creates Students only through conversion. No Registrar role exists
  or is proposed.
- **Residual LOWs:** R-1 … R-13 (audit §9). The Guardian name edit (R-1) and
  the register's between-year lifecycle rows (R-4) are the decision-free,
  SIS-owned items left.
- **Recommended next:** `SIS.HARDEN-1 — SIS write-integrity & residual
  hardening` (owner authorisation only; no schema, capability, role, Campus
  or API-contract change), then `SIS.EXP-CLOSE`, which takes the place of
  EXP-8. Publication of either depends on E16 (last valid day 2026-11-12) or
  a successor. **Not started.**

### 2.0c SIS.EXP-5 — IMPLEMENTED (2026-10-30)

OD-SIS-3 was decided by the owner on 2026-10-30. Per the brief's stated
preference, there is one new capability, `students.import`, held by
`school_admin` only. Built as described in
`docs/modules/STUDENT-GUARDIAN-IDENTITY.md`, "Bulk Student & Guardian
import".

**Recorded differences from §2:**
- New Guardians only. No existing-Guardian link, because there is no safe
  explicit reference; a matching contact is a warning.
- At most 100 rows a file, from measured commit time.
- The demo resets to the canonical world: the walkthrough imports live, and
  the fixtures live in `docs/demo/fixtures/`.
- `admissions_officer` is not granted import (an owner decision if wanted).

### 2.0b SIS.EXP-4 — IMPLEMENTED FOR DEVELOPMENT (2026-10-30)

Decided by the owner:
- OD-SIS-2.1–2.7;
- in session: dompdf (Latin script only) and one new capability,
  `students.certificates.issue` (`school_admin`, `principal`).

Built as ADR 0081 (`docs/modules/STUDENT-ENROLLMENT.md`, "Leaving and
Transfer certificates").

**Recorded differences from §2:**
- **Production is refused in code** until the wording is legally approved
  (generic development template).
- **Non-Latin names are refused** until a script-capable renderer is
  decided.
- **No School-wide certificate register or movement-register indicator.**
  History is Student-centric (Student 360 and the certificates page).
- **Demo:** Arjun Mehta is canonically ineligible (his exit is not
  recorded). A new fictional leaver, Meera Iyer, has a recorded transfer
  withdrawal; her certificate rows are direct, database-validated writes (no
  fabricated MFA proof).

### 2.0a SIS.EXP-3 — IMPLEMENTED (2026-10-30)

OD-SIS-1 was decided by the owner on 2026-10-30:
- activate Student documents (not Guardian);
- two new capabilities, `students.documents.view` and
  `students.documents.manage`;
- granted to `school_admin` and `principal`, whole-School only.

The feature is built on the one Documents repository (ADR 0012 amendment;
`docs/modules/STUDENT-GUARDIAN-IDENTITY.md`, "Student documents").

**Recorded differences from §2:**
- No per-class checklist, verification or expiry. No approved category needs
  expiry, and Documents has no verification workflow. Each is a later owner
  decision.
- No immunisation or health tier. Health records stay legally gated (no
  health category exists).
- No applicant documents (SR-14) and no Guardian owner arm.
- Highly Sensitive by default (children's data): current MFA to read, a fresh
  code to write.
- Demo writes are direct and database-validated, through Documents' real
  storage pipeline (no fabricated MFA proof).

### 2.0 SIS.EXP-2 — IMPLEMENTED (2026-10-30)

The School-wide movement and exit register (`/app/students/movements`) is
built as a read-only projection, with no migration, capability, role or
adoption change. See `docs/modules/STUDENT-ENROLLMENT.md` ("Student
movement and exit register") and the walkthrough §4.

**Recorded differences from §2:**
- A declared whole-School operation of `enrollments.view`. History is
  whole-School by rule 101, so a Campus-only reader is refused, not shown
  a partial history.
- No Section headcount (OD-SIS-6 stays open).
- No "via Admissions" entry source (it would invert Students → Admissions).
- No seeded withdrawal or deactivation: they need a fresh MFA proof, which
  the seeder never fabricates. Tests cover them.

### 2.1 SIS.EXP-1 detail — IMPLEMENTED (2026-10-29)

> **Status:** built as designed below, with these recorded differences:
> - the directory's primary-Guardian column was not in the authorised
>   scope and stays open (P3);
> - Attendance shows the latest records of the last 30 days through
>   Attendance's own audited read (no rate or tally);
> - the profile budget is 85 queries (measured 67 with every module and
>   no data, constant as data grows), not 50: the Fees section is the
>   canonical charge-state read;
> - the API PATCH keeps its contract (no stale token).
>
> **Independent security review** (fresh reviewer, read-only, before publication):
> - **H-1 HIGH, found and fixed before publication.** The first stale-form
>   token was an unkeyed SHA-256 over the visible fields plus the date of
>   birth, so a Campus name editor could have recovered the date by trying
>   candidate days. It is now an HMAC under a purpose key derived from the
>   application key, with a regression test. The token never reached
>   `main`.
> - **LOW, fixed:** the profile performs no business write, but the owners'
>   own read audits (Attendance, Payments) do apply, and the wording now
>   says so; deterministic Guardian order in the sibling read.
> - **LOW, carried:**
>   - the API PATCH has no stale token;
>   - filter options list inactive Sections (they match nobody);
>   - sibling truncation is computed before the viewer filter (harmless
>     on this whole-School route).
>
> Evidence: `docs/modules/STUDENT-GUARDIAN-IDENTITY.md` ("Student 360,
> directory filters and edit integrity") and
> `docs/demo/SIS-EXP-1-WALKTHROUGH.md`.

**Profile (whole School, `students.view`)** gains sections. Each appears
only when the viewer holds that domain's own view capability in a scope
covering the Student; each is one bounded read through the owner's
read service.

| Section | Owner and read service | Gate | Shows | Never shows |
|---|---|---|---|---|
| Overview | Students | `students.view` | number, names, status, current class, Campus | — |
| Guardians & family | Guardians | `students.view`; contacts `guardians.view` | relationships, flags; **other Students linked to the same Guardians** (names, number, link) | contacts without `guardians.view`; dues |
| Placement | Students | `enrollments.view` (whole School) | current + history (exists) | — |
| Subjects & electives | Students (subject enrollments) | `enrollments.view` | current subject enrollments | — |
| Attendance | Attendance | `attendance.view` in scope | a link plus the last N records (no rate, no tally) | percentages, counts |
| Fees | Fees/Payments (Outstanding fees read model) | `finance.charges.view` in scope (stored Charge Campus, rule 101) | outstanding balance + link to the fee statement | payments of other Students; family totals |
| Transport | Transport | its view capability in scope | current route and stop | vehicle tracking |
| Hostel | Hostel | its view capability in scope | current residency | — |
| Library | Library | its view capability in scope | open loans count + link | fines detail beyond the link |
| Lifecycle & history | Students | `students.view` | link to history; last lifecycle event with reason label | actor (stays in the audit log) |

**Directory:**
- Grade, Section, Campus and active-year filters on `/app/students`,
  applied after `StudentAccess` scope (they only narrow);
- a primary-Guardian name column for whole-School viewers holding
  `guardians.view`;
- no counts tiles.

**Edit hardening:**
- **L-1:** remove `status` and `school_id` from `$fillable`, and whitelist
  in `StudentService::update()`;
- **L-2:** a stale-form token on the identity edit;
- **L-3:** record only the fields that actually changed.

**Not in EXP-1:**
- documents, certificates, photo, address or any new field;
- any export;
- any Campus adoption (the profile stays a declared whole-School
  operation).

**Performance budget:** profile ≤ 50 queries, with every summary O(1) in
queries; directory unchanged at 27 ± 2.

## 3. Owner decisions (OD-SIS)

| Id | Decision | Recommendation | Blocks |
|---|---|---|---|
| OD-SIS-1 | Activate Student (and Guardian?) documents (= OD-SWC-2, PV1-15): categories, who uploads and views, Guardian visibility, verification, expiry, retention class | Activate Student only first; closed category catalogue; staff-only visibility; health-classified tier for immunisation; no applicant documents until Admissions decides | EXP-3 |
| OD-SIS-2 | Leaving/transfer certificate (CRT-001/002): format, numbering, wording, dues policy | One numbered register per School; output stored as a Document; never withheld for dues without a legal basis; statutory wording via legal review | EXP-4 |
| OD-SIS-3 | Bulk import (OD-SWC-4 / OD-PPR-20) | Add-only CSV of Students + first placement (+ optional Guardians), whole School only, all-or-nothing | EXP-5 |
| OD-SIS-4 | Houses/clubs (D-17 revisit) | Wait for a pilot to name one concept; model House first if any | EXP-7 |
| OD-SIS-5 | Annual record confirmation and Guardian acknowledgement ("contracts") | Confirmation of existing fields + acknowledgement of named, versioned School terms; no IP address stored; legal review before calling it an agreement. **SIS.EXP-R1: `LEGAL_REVIEW_REQUIRED` first** (audit §4) | EXP-6 |
| OD-SIS-6 | Operational Section headcount vs advisory capacity | Allow an operational, whole-School, no-attribute headcount per Section next to capacity, outside the Analytics gate; no gender/age/fee splits | small read in EXP-2 if approved |
| OD-SIS-7 | Student home address | Do not add until a named purpose (Transport stop planning, statutory register) is approved; then the ORG.12C address shape | — |
| OD-SIS-8 | Effective-dated lifecycle / back-dating (D-13) | Keep `now()`: the reference's own exit audit flags back-dating as a risk | — |
| OD-SIS-9 | Cross-School transfer | Not a transfer: the destination School admits a new Student; no cross-tenant copy without a separate data-portability decision | — |
| OD-SIS-10 | Student Portal | Stays legally gated (POR-L1 Q16–Q21, E46) | Portal |
| OD-SIS-11 | Student-number case policy (open since SIS 8.1) | Case-insensitive uniqueness for new numbers | — |
| OD-SIS-12 | Guardian status change reason (open since SIS 2.2) | A closed reason like Student status | — |
| OD-SIS-13 | Student merge / deduplication | Do not build; `duplicate_record` deactivation is the path; any merge needs its own ADR across every owning domain | — |
| OD-SIS-14 | Admissions/Registrar import authority (SIS.EXP-R1) | Keep `students.import` School-Admin-only; granting it would also need `students.manage`, `guardians.manage` and `enrollments.manage`; conversion stays the Admissions path | — |

## 4. Test and regression plan

| Wave | Unit / domain | Authorization (allow + deny) | RLS / isolation | Concurrency | Idempotency | Privacy | Frontend | DDEV walkthrough | Regression level |
|---|---|---|---|---|---|---|---|---|---|
| EXP-1 | summary assemblers; changed-field audit; whitelist | each section hidden without its capability, and with a Campus scope not covering the Student; profile refused to Campus-only grants | foreign-School 404 for profile and each summary | stale-form token: two editors, second refused | n/a (no new retryable mutation) | DOB/Guardian contact never on Campus surfaces; no rates | type-check, lint, a11y lint, 390 px | `school.admin` vs `gachibowli.cashier` (no Fees for an out-of-scope Student) | scoped Students/Guardians/Finance/Attendance suites + query-budget test; full suite only if a shared presenter changes |
| EXP-2 | register query per action type; reason labels | `students.view` + `enrollments.view` required; Campus actor sees only in-scope rows (if adopted later — not in EXP-2) | raw RLS read of the register's sources | n/a (read) | n/a | no Guardian contact, no actor | filters, empty states | walkthrough over the seeded leavers | scoped + Data Health suites |
| EXP-3 | category catalogue; tier mapping; archive | upload/view/archive deny cases; Guardian visibility per decision | raw RLS on documents for the Student owner; storage path through `TenantStoragePath` | concurrent archive | upload form key | health tier; filename never in audit | upload UI, 390 px | upload, verify, archive | **full regression** (Documents is cross-domain; real-MinIO tests reported separately) |
| EXP-4 | numbering; eligibility from lifecycle facts | issue/void deny cases; fresh MFA if DIRECT_ASSURED | RLS + composite FKs | two issuers for one leaver; number uniqueness (DB) | server-issued key | no bank data; no dues text | print view | issue for a seeded leaver | scoped + Documents; full if Documents changes |
| EXP-5 | parser, preview signature, row re-evaluation | whole-School only; deny for Campus actors | foreign-School rows refused | lock order; all-or-nothing rollback | form key; replay never writes twice | no PII in logs | preview/commit UI | import a fixture CSV into Northstar Gachibowli | **full regression** (write path into Students, Enrollments, Guardians) |
| EXP-6 | confirmation record; versioned terms | Guardian of the Student only; staff read | RLS; composite FKs to Guardian and Student | confirm twice | idempotent confirm | minimal stored evidence | portal pages | `guardian01` confirms | scoped + Portal; full if Identity changes |
| EXP-7 | membership rules (one per year) | manage/view deny cases | RLS + composite FKs | concurrent assignment; partial unique | bulk form key | no gender analytics | assign UI | assign a Section to Houses | scoped |
| EXP-8 | — | — | — | — | — | — | — | full demo re-verification | full regression (closure) |

The counter stays at the regression-cadence rule:
- a full regression at 5/5, or sooner after a major cross-domain wave
  (EXP-3, EXP-5);
- a code-lane candidate's remote exact-SHA `CI gate` is the canonical
  full suite (rule 96).

## 5. Schema gap map (proposed concepts only — nothing migrated)

| Concept | Wave | Why existing schema cannot support it | Owner | Tenancy / RLS | Integrity |
|---|---|---|---|---|---|
| Student document category, verification and expiry | EXP-3 | `documents` has no category, verification state or expiry (unlike `employee_documents`); the owner type is refused in code, not schema | Documents | School-owned; existing forced RLS on `documents`; `BelongsToSchool` | closed category CHECK; `verified_by_user_id` same-School; `expires_on ≥ issued_on`; archive, never delete |
| Certificate issue register | EXP-4 | No certificate concept; lifecycle events record the exit, not an issued document | Students | new School-owned table, `TenantRls::enable` + `makeAppendOnly` | unique `(school_id, certificate_number)`; composite FK `(student_id, school_id)`; link to the lifecycle event and the stored Document; `ON DELETE RESTRICT` to School/User (rule 97) |
| Record confirmation / acknowledgement | EXP-6 | `student_processing_authorizations` is a processing basis, not an acknowledgement of terms | Students (record) + Portal (UX) | new School-owned, append-only table | composite FKs to Student and Guardian; terms version; one confirmation per Student × Guardian × year |
| House and House membership | EXP-7 | Only Grade/Section cohorts exist | Students | two School-owned tables, forced RLS | House per School (code normalized, rule 74); membership per year with a composite FK to the year; partial unique one active per Student per year; deactivate, never delete (rule 73) |
| Directory filters, Student 360, movement register | EXP-1/2 | — (existing tables suffice) | Students | — | — |
| Bulk import | EXP-5 | — (server-issued form key, existing write paths) | Students | — | — |

## 6. Organization Control Plane integration

- **Setup Registry:** no change now.
  - EXP-3 adds `SR-21` (and EXP-4 `SR-41`) to the `reference` field of
    `students.student_records`.
  - Any new readiness fact is a bounded EXISTS in a Students-owned
    provider (rule 111).
- **Access Center:** new capabilities (EXP-3/4/6/7) appear automatically
  through the code-owned catalogue. They are classified in
  `ModuleCatalogue`, and must be granted to roles by a reviewed catalogue
  release (rule 93), never at runtime.
- **Presentation:** any new Student navigation entry is subject to ORG.12F
  presentation (menu ≠ permission).
- **Integration Hub:** SMS, biometric and RFID stay owning-domain gaps
  (rule 116). No SIS family is added there.
- **Organization reporting:** no SIS figures. Person counts stay behind the
  Analytics gate (rule 117).
