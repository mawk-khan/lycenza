# SIS.EXP-R1 — post-P1 closure, remaining decisions and next-wave audit

Status: **audit, documentation only** (2026-10-30, baseline
`origin/main = 74b28b93`). Nothing here implements, authorises, grants or
schedules anything. Companion records: `SIS-EXP-0-CURRENT-STATE-AUDIT.md`,
`SIS-REFERENCE-CAPABILITY-MAP.md` (§5.6 records this re-audit),
`SIS-EXPANSION-ROADMAP.md` (§2.0d), `SIS-DOMAIN-OWNERSHIP-MAP.md`.

Evidence handling is unchanged from SIS.EXP-0: the reference screens stay
with the owner's fingerprinted pack. Only Lycenza-worded findings are
committed, with no vendor, tenant, school, person, contact or identifier.

## 1. Result in one paragraph

All three original P1 gaps are delivered:
- Student documents (EXP-3);
- Leaving and Transfer certificates (EXP-4, production legally gated);
- bulk import (EXP-5).

No P0, HIGH or MEDIUM finding is open, and no family is wholly `SIS_BUILD`.

What remains of the reference's Student surface falls into five groups:
- owner-gated: groups, contact lists, strength counts, parent leave, annual
  confirmation;
- legal-gated: demographics, statutory identifiers, photographs, biometrics,
  discipline, health records;
- another domain's future: Analytics, Timetable, Examinations, Admissions,
  Fees;
- post-v1;
- deliberately not built.

The only SIS-owned work left without a decision is a set of LOW
write-integrity and report-precision residuals (§9). The most material is
the Guardian name edit: it has no stale-form check, takes no row lock and
records submitted rather than changed fields. That is the one open item this
audit judges should be fixed before the expansion is declared closed.

**Recommendation:**
`START SIS.HARDEN-1 — SIS write-integrity & residual hardening`. It is
small, has no schema, capability, role or Campus change, and needs no owner
or legal decision. It is then followed by `SIS.EXP-CLOSE`. Annual Record
Confirmation (OD-SIS-5) is **not** recommended next: it is legally sensitive
and cross-domain (§4).

## 2. P1 closure

| P1 gap | Delivered | Still partial | Legal / production gate | Blocks normal SIS use? |
|---|---|---|---|---|
| Student documents (EXP-3, SR-21) | Per-Student record documents (PDF/JPEG/PNG) in four closed categories. Upload, list, filter, attachment download, metadata edit, final archive, Student 360 card. Highly Sensitive (current MFA to read, fresh code to write) | Statutory identity documents; medical/immunisation records; the per-class checklist grid; Guardian/Student portal sharing; verification and expiry (§2.1) | None for the delivered scope. The excluded categories are legally gated | No |
| Leaving / Transfer certificates (EXP-4, SR-41) | Eligibility from the recorded exit; immutable numbered snapshot (`LC-`/`TC-`); stored PDF Student document; void and linked reissue; history | Other certificate types (bonafide, character); non-Latin scripts; the requisition form | **Development complete / production legally gated** (ADR 0081 §7; §6 below) | Not in development. In production a School cannot issue until the legal review passes |
| Bulk import (EXP-5, SR-06) | Add-only Student + first placement + up to two new Guardians per row, through the canonical services. Signed preview, atomic MFA-assured commit, replay-safe | Deliberately absent, not partial (§2.2) | None | No |

### 2.1 Student-document remainder

| Piece | Classification | Owner |
|---|---|---|
| Statutory identity documents (national ID, passport, visa) | `LEGAL_DECISION_REQUIRED` (SR-05, D-15) | Legal/DPO, then Students |
| Medical / immunisation records | `LEGAL_DECISION_REQUIRED` (health tier; SR-21 part) | Legal/DPO (health-tier review), then Documents |
| Per-class required-document checklist | `OWNER_DECISION_REQUIRED` (a later OD-SIS-1 extension: which documents per Grade, and whether "missing" is a Data Health fact) | Owner, then Students + Documents |
| Guardian / Student portal sharing | `DOMAIN_FUTURE` (Portal), behind POR-L1 and OD-SIS-10 | Portal |
| Verification and expiry | `DOMAIN_FUTURE` (Documents). No approved category needs expiry, and Documents has no verification workflow; any design is cross-product | Documents |

### 2.2 Bulk-import boundary (frozen)

The EXP-5 import is a product contract, not a first version of something
bigger:
1. add-only (every Student and Guardian is NEW);
2. at most **100** Students a file (measured: commit time grows faster than
   linearly past ~100 rows; raising it needs an architecture change, not a
   constant change);
3. `students.import` is held by **School Admin only** (frozen; §8.1);
4. current-state onboarding only: one current placement per Student.

Intentionally absent:
- update import;
- merge;
- historical migration;
- files;
- Finance;
- Attendance;
- Portal accounts.

Each needs its own contract, never a flag on the importer (rule 122).

## 3. Reference map re-audit

The same 42 families and the same EXP-0 formula (map §5) were used. This
audit **reclassifies nothing**. Every class change since EXP-0 was made by
the wave that earned it, and each was re-verified here against the code
(§3.3).

### 3.1 Classification totals (42 families, 318 screens)

| Classification | EXP-0 | Now | Ids now |
|---|---|---|---|
| `EXISTING_EQUIVALENT` | 0 | **0** | — |
| `EXISTING_DIFFERENT_ACCEPTED` | 9 | **12** | SR-02, 06, 08, 10, 12, 20, 32, 33, 34, 36, 37, 42 |
| `PARTIAL` | 7 | **7** | SR-03, 04, 09, 21, 25, 29, 41 |
| `SIS_BUILD` | 0 | **0** | — |
| `DOMAIN_FUTURE` | 8 | **8** | SR-11, 14, 15, 16, 18, 26, 27, 39 |
| `PLATFORM_FUTURE` | 0 | **0** | — |
| `POST_V1` | 1 | **1** | SR-40 |
| `DO_NOT_BUILD` | 4 | **4** | SR-07, 19, 30, 38 |
| `OWNER_DECISION_REQUIRED` | 8 | **5** | SR-13, 17, 23, 24, 31 |
| `LEGAL_DECISION_REQUIRED` | 5 | **5** | SR-01, 05, 22, 28, 35 |

SR-42 (navigation) counts as EDA but is excluded from every denominator.

### 3.2 Coverage (exact EXP-0 formula)

| Measure | Value |
|---|---|
| Overall weighted | **39.2 %** (14.5 / 37) |
| Overall strict | **29.7 %** (11 / 37) |
| v1 weighted | **80.6 %** (14.5 / 18) |
| v1 strict | **61.1 %** (11 / 18) |
| Screen-weighted overall / v1 (weighted) | 24.3 % / 74.0 % |

**Trend** (no denominator was changed retrospectively; the v1 denominator
grows only when a family moves into PARTIAL or EDA, as the formula requires):

| Checkpoint | Overall weighted | Overall strict | v1 weighted | v1 strict | Class changes |
|---|---|---|---|---|---|
| EXP-0 | 31.1 % | 21.6 % | 76.7 % | 53.3 % | baseline |
| EXP-1 | 31.1 % | 21.6 % | 76.7 % | 53.3 % | none (SR-03/04/25 improved inside PARTIAL) |
| EXP-2 | 32.4 % | 24.3 % | 80.0 % | 60.0 % | SR-36 PARTIAL → EDA |
| EXP-3 | 33.8 % | 24.3 % | 78.1 % | 56.3 % | SR-21 OWNER → PARTIAL (v1 dips: a PARTIAL enters the denominator at half) |
| EXP-4 | 36.5 % | 27.0 % | 79.4 % | 58.8 % | SR-41 OWNER → PARTIAL; SR-12 PARTIAL → EDA |
| EXP-5 | **39.2 %** | **29.7 %** | **80.6 %** | **61.1 %** | SR-06 OWNER → EDA |

Since EXP-0:
- overall: **+8.1 points** weighted and **+8.1** strict;
- v1: **+3.9 points** weighted and **+7.8** strict.

**Product completeness vs reference breadth.** These figures measure how much
of the reference ERP's Student surface Lycenza covers. They do not measure
the quality of Lycenza's SIS.
- Lycenza's own SIS score is 96/100 against Lycenza's scope
  (`docs/product/SIS-FINAL-LOCAL-UAT-2026-10-28.md`).
- The overall figure is held down deliberately: 127 of the 298 scored screens
  (43 %) are owner- or legal-gated, mostly demographic profiling (SR-01, 40)
  and the generic group hub (SR-17, 52). Another 68 belong to other domains.
- A low parity number here is not evidence of a weak SIS foundation.

### 3.3 Re-verification against the code (baseline `74b28b93`)

| SR | Class | Verified by |
|---|---|---|
| SR-06 | EDA | `StudentGuardianImport`, `/app/students/import`, `StudentGuardianImportArchitectureTest` |
| SR-12 | EDA | Exit filter of `StudentMovementRegister`; certificate checkpoint `StudentCertificateEligibility` |
| SR-21 | PARTIAL | `student_documents`, `StudentDocumentService`; no checklist, health or statutory categories |
| SR-36 | EDA | `/app/students/movements` |
| SR-41 | PARTIAL | `StudentCertificateService`; `StudentCertificateAvailability` refuses production |

## 4. OD-SIS-5 — Annual Record Confirmation (focused audit)

**Evidence.** SR-13: eight screens, re-read for this audit. One "existing
students" page sits under a "Student contracts" menu, with the year being
left and the "promoted" next year as its key, a Campus selector and five
tabs. The page describes itself as document and data verification for the
next year's *financial promotion*.
1. **Manage documents and fields to be verified:** the School picks, per
   year, which fields and which documents must be verified, and whether each
   is mandatory. The field picker is the same ~150-field catalogue as SR-23:
   - Student and parent identity, including national ID, passport, visa and
     residency numbers with expiry, and tax ID;
   - religion, caste, mother tongue, blood group;
   - parent income, occupation and employer;
   - addresses and sibling details.
2. **Parent information updated log:** the changes parents made.
3. **Documents dashboard:** per Section, how many Students have documents.
   It drills into a list of each Student's "signing authority" (a parent's
   name, mobile and email).
4. **Documents pending for verification:** parent uploads waiting for staff.
5. **Contract forms signed:** Student, Section, date of birth, a "reporting
   date", "agreed on" and an **IP address**.

| # | Question | Answer from the evidence |
|---|---|---|
| 1 | Internal Staff checklist? | Partly. Staff configure it and verify the uploads. The confirmation itself is the parent's |
| 2 | Guardian confirms information? | **Yes.** A designated parent ("signing authority") reviews and updates the configured fields and uploads documents |
| 3 | Student confirms anything? | No evidence |
| 4 | Updates Student fields? | **Yes.** Parent edits change the record (hence the change log) |
| 5 | Locks records? | No evidence |
| 6 | Yearly snapshot? | Per year being left and next year, but no evidence of a frozen snapshot |
| 7 | Signatures / consent? | **Yes.** An electronic acceptance of a "contract", recorded with time and IP address |
| 8 | Regulatory? | Not shown as statutory. It reads as a School–family terms agreement tied to fees for the next year |
| 9 | Medical / statutory data? | **Yes, potentially.** The configurable catalogue includes blood group, national ID, passport/visa, caste, religion and income |
| 10 | Triggers re-enrollment? | **Yes, implicitly.** It gates "financial promotion" into the next year |

**Ownership.** It is not primarily SIS.
- **The Guardian action** belongs to the Guardian **Portal**, which is
  production-gated (POR-L1).
- **The agreement** belongs to a **Documents/Consent** concern; no Lycenza
  domain owns School terms today.
- **The promotion gating** belongs to **Admissions/Re-enrollment** and
  rollover, which deliberately never gate promotion on fees (SR-37).
- **SIS** would own only a minimal confirmation record. It is therefore
  cross-domain, led by the Portal.

**Recommendation: `LEGAL_REVIEW_REQUIRED`.** Rationale:
- the core act is an electronic acceptance of terms;
- its value depends on the Guardian Portal reaching production (POR-L1);
- the reference's data scope is dominated by legally gated fields (SR-01,
  SR-05, health);
- its "financial promotion" coupling contradicts a recorded Lycenza rule (no
  fee-gated promotion).

What could be built is not the reference feature. It would be Guardian
confirmation of existing canonical fields plus acknowledgement of versioned
School terms, with no IP address stored and no promotion gating. Even that
needs:
1. legal advice on electronic acceptance and its evidence;
2. the POR-L1 production gate;
3. an owner decision on which terms exist.

**SR-13 stays `OWNER_DECISION_REQUIRED`;** the legal review is the
precondition of that decision. EXP-6 as written in roadmap §2 is not
authorised and is **not** the recommended next wave.

## 5. Other open decisions

### 5.1 OD-SIS-6 — Section capacity

| Question | Answer |
|---|---|
| Does Section capacity exist? | Yes: `sections.capacity`, nullable integer, Academic Structure (`2026_08_23_090900`), edited on School setup → Sections |
| Advisory or enforced? | **Advisory.** No admission, enrollment, import, transfer or rollover path reads it (migration docblock, ACADEMIC-STRUCTURE §27) |
| Headcount source | Active `student_enrollments` per Section. A per-Section person count is a grouped person count under ADR 0040's Analytics gate unless OD-SIS-6 grants an operational exception |
| Owner | Academic Structure (capacity); Students (placements) |
| Admissions implications | Admissions does not read capacity; a hard cap would turn conversion into a capacity race and needs its own design |
| SIS implications | None today. EXP-2 and EXP-5 deliberately show no headcount |

**Decision default kept:** capacity is informational/advisory only. It must
not block enrollment, import or movement. OD-SIS-6 stays open with only
**display** in question: an operational, whole-School, attribute-free
headcount next to capacity. It is a small read if approved, and not a
pre-closure item.

### 5.2 Cross-School transfer (OD-SIS-9)

- **Need:** real for multi-School groups, rare in practice.
- **Tenancy:** a School is the tenant. Moving a Student between tenants is a
  cross-tenant data copy that RLS, retention and data minimisation all
  forbid by default.
- **Recommendation:** model it as **exit + new admission**. The source School
  records the withdrawal (`transferred_out`) and may issue a Transfer
  Certificate (EXP-4, production-gated). The destination School admits a NEW
  Student through Admissions or the EXP-5 import.
- **Documents and certificates:** they never move between Schools. A family
  presents the paper certificate.
- **Identity:** two Student records in two Schools are correct. Linking them
  would need a separate data-portability decision and ADR, never a SIS
  feature.
- **Decision:** do not build.

### 5.3 Student merge (OD-SIS-13)

- **Need:** duplicate management. Today's path is a `duplicate_record`
  deactivation (closed reason, fresh MFA). EXP-5 never merges and warns on
  duplicates.
- **Complexity:** a Student is referenced by Enrollments, Subject
  enrollments, Charges and Payments (immutable ledgers), Attendance records
  (evidence), Documents and certificates (immutable), Library loans,
  Transport and Hostel assignments, Guardians relationships, lifecycle
  events and audit.
- **Consequences:** a merge would have to re-point or reconcile each one:
  - **Finance and Attendance:** a rewrite of evidence;
  - **Documents:** owner re-pointing of Highly Sensitive files;
  - **Guardians:** relationship de-duplication;
  - **audit and retention:** split clocks.
- **Recommendation:** conservative. **Do not build.** Any future merge needs
  its own ADR across every owning domain.

### 5.4 Back-dated exits (OD-SIS-8)

- **Current rules:**
  - A Student status change is dated now.
  - A placement withdraw/cancel takes an end date that must be within its
    academic year, never before its start and never in the future (ORG.8G);
    so a past date within the year is already allowed for placements.
  - Cancel-before-start records the empty interval (ADR 0078).
- **Need:** recording a leaver late.
- **Attendance:** a back-dated Student exit would strand Attendance records
  after the exit. Data Health already flags
  `attendance.recorded_outside_placement`.
- **Finance:** Charges after the exit are flagged
  (`fees.charge_after_placement_ended`); concessions and corrections are the
  only Finance remedies.
- **Certificates:** EXP-4 certifies the recorded exit. Back-dating would
  change what a certificate states.
- **Academic year:** placements are already bounded by the year.
- **Recommendation:** keep the current rules. The reference's own exit audit
  flags back-dating as a risk.

### 5.5 Student groups (OD-SIS-4)

The reference's 52-screen hub is one generic typed-group engine.

| Need | Covered by |
|---|---|
| Class cohort | Grade + Section |
| Academic cohort | Academic year + Grade, Subject Offerings and electives |
| Communications audience | School, Grade/Section, Staff and Guardian audiences |
| Finance / store "groups" | Fee structures (Fees) |
| Activity / club / co-scholastic | Uncovered; Examinations co-scholastic, DOMAIN_FUTURE |
| House | Uncovered |

**Recommendation:** no generic group engine. House (or one club concept)
stays on OD-SIS-4 and waits for a pilot to name it.

### 5.6 Reports

| Remaining report family | Owner |
|---|---|
| Composition by attribute (SR-01) | Analytics (legal-gated) |
| Strength / headcount (SR-24, SR-27) | Analytics; OD-SIS-6 only for a Section headcount |
| Exit revenue, fee strength, admission analytics (SR-11, SR-26, SR-39) | Analytics + Finance (D-14) |
| Attendance reports and rates (SR-29) | Attendance (rates after the Analytics gate) |
| Class-wide family contact list + export (SR-23) | SIS + Guardians, owner decision (SIS-020, D-11 export) |
| School-wide sibling list (SR-25 remainder) | SIS, owner decision (SIS-019 / PV1-17) |
| Promotion / movement / exit (SR-12, SR-36, SR-37) | SIS, done |
| Transport, Library, Hostel lists | Their domains (existing pages) |
| Documents checklist / pending verification (SR-21, SR-13) | Documents + Portal |

No remaining report gap is SIS-owned and decision-free.

### 5.7 Primary-Guardian directory column

The column EXP-1 left open (SR-03, P3) would be:
- a primary-Guardian name on whole-School directory rows for `guardians.view`
  holders;
- never on Campus rows (rule 101: no Guardian data on a Campus surface);
- one extra join against the primary relationship (bounded, no N+1).

**Recommendation: defer.** It is low value, because the Student 360 Family
card already shows Guardians. It also widens a list surface to family names.
It stays a P3 backlog item and is not needed for closure.

## 6. Legal decisions (consolidated)

| Legal item | Development permitted? | Production permitted? | Production condition | Re-review |
|---|---|---|---|---|
| EXP-4 statutory certificate wording (ADR 0081 §7) | **Yes**, local/testing only, generic wording | **No**, refused in code (`StudentCertificateAvailability`) | Legal approval of the jurisdiction/format, title and statement, field list, reason labels, signatory and seal requirements, and non-Latin handling, then a reviewed code change | On any template, field or jurisdiction change |
| Student Portal (OD-SIS-10; POR-L1 Q16–Q21, E46) | Guardian portal built for development (POR); Student portal not built | No | POR-L1 answers; E46 | Per POR-L1 |
| Statutory identity data (SR-05, D-15) | No (not stored) | No | A named statutory purpose, lawful basis, masking with audited reveal, and retention per identifier | Per identifier |
| Medical / immunisation records (SR-21 part) | No (no health category) | No | Health-tier review (DPO) | — |
| Annual Record Confirmation (SR-13, OD-SIS-5) | Not built | No | Legal review of electronic acceptance and its evidence, plus POR-L1 | — |
| Biometric / RFID Student processing (SR-28, HRX-L2) | No | No | Biometric and RFID privacy review | — |
| Demographic profiling (SR-01), photographs (SR-22), discipline (SR-35) | No | No | D-15 / DOC-002 / DPO | — |

Unchanged by this audit. **No legal decision was resolved.**

## 7. Remaining owner decisions (consolidated)

| Id | Decision | Status | Recommendation |
|---|---|---|---|
| OD-SIS-1 / -2 / -3 | Documents / certificate / import | **Decided 2026-10-30** | — |
| OD-SIS-4 | House or club (D-17) | Open | Wait for a pilot to name one concept |
| OD-SIS-5 | Annual record confirmation | Open | `LEGAL_REVIEW_REQUIRED` first (§4) |
| OD-SIS-6 | Section headcount next to advisory capacity | Open | Display only if approved; never enforcement |
| OD-SIS-7 | Student home address | Open | Not until a named purpose |
| OD-SIS-8 | Back-dated lifecycle | Open | Keep the current rules |
| OD-SIS-9 | Cross-School transfer | Open | Exit + new admission; do not build |
| OD-SIS-10 | Student Portal | Open (legal) | POR-L1 |
| OD-SIS-11 | Student-number case policy | Open | Case-insensitive uniqueness for new numbers |
| OD-SIS-12 | Guardian status change reason | Open | A closed reason, like Student status (can ride on HARDEN-1 only if decided) |
| OD-SIS-13 | Student merge | Open | Do not build |
| **OD-SIS-14 (new)** | Admissions/Registrar import authority | Open | Keep `students.import` School-Admin-only (§8.1) |
| SR-23 (SIS-020 + D-11) | Class-wide family contact list and export | Open | Owner decision; export is D-11 |
| SR-25 (SIS-019 / PV1-17) | School-wide sibling list | Open | Owner decision |
| SR-31 (D-16) | Parent-submitted Student leave | Open | D-16 pilot requirement; health-tier review |
| SR-03 remainder | Primary-Guardian directory column | Authorisation only (P3) | Defer (§5.7) |

## 8. Authorization, Campus scope and schema deltas (EXP-1 → EXP-5)

### 8.1 Capabilities and roles

New School capabilities (182 → 186), all whole-School, never Campus-adopted,
each classed in `ModuleCatalogue`:

| Capability | Wave | Roles |
|---|---|---|
| `students.documents.view` | EXP-3 | `school_admin`, `principal` |
| `students.documents.manage` | EXP-3 | `school_admin`, `principal` |
| `students.certificates.issue` | EXP-4 | `school_admin`, `principal` |
| `students.import` | EXP-5 | `school_admin` |

**Exact role deltas** (from `CapabilityAndRoleSeeder`, `0e885dfb..74b28b93`):
- `school_admin` gained four keys;
- `principal` gained three;
- no other role changed.

EXP-1 and EXP-2 changed no capability or role. The Student core keys
(`students.view`/`.manage`, `enrollments.*`, `guardians.*`) are unchanged.

**Admissions Officer audit:**

| Question | Answer |
|---|---|
| Current capabilities | `admissions.view`, `admissions.manage`, `communications.events.view`, `communications.inbox.view` |
| Admissions conversion authority | The `app.admissions.convert` route under `capability:admissions.manage` (`AdmissionConversionService`) |
| Does it already create Students? | **Yes, one accepted application at a time.** Conversion composes the same canonical Student, Guardian, contact, relationship and placement services in one transaction |
| Would import require `students.manage`? | Yes. The import requires `students.import` **and** `students.manage`, `guardians.manage`, `enrollments.manage` |
| Escalation | Large. Those three keys open editing of every Student identity, every Guardian and every placement School-wide, far beyond creating the applicants it processed |
| Recommendation | **Do not grant.** Conversion is the Admissions Officer's sanctioned path for creating Students. Recorded as OD-SIS-14; no change |

**Registrar:** no code-owned Registrar or equivalent role exists. The staff
catalogue is the 17 pinned system roles (rule 93). A seeder comment
anticipates "a future registrar-style role" for delegated Enrollment
administration, without inventing it. No broader business need is proven,
so none is proposed.

### 8.2 Campus scope

`CampusScopeCatalogue` changed by exactly **one entry** since EXP-0. EXP-2
declared `app.students.movements` a `WHOLE_SCHOOL_OPERATIONS` entry of
`enrollments.view`.
- It **narrows**: a Campus-only reader is refused the School-wide history.
- No key was adopted, no adoption broadened, and no Campus authority
  widened.

The EXP-1 directory filters only narrow the existing `StudentAccess` scope.
The three new capability families are never Campus-adopted.

### 8.3 Schema

| Wave | Tables | Tenancy |
|---|---|---|
| EXP-3 | `student_documents` (closed category, archive reason, form key + fingerprint; no runtime DELETE; trigger-frozen once archived) | `BelongsToSchool`, forced RLS (`TenantRls`), same-Student composite key to `documents` |
| EXP-4 | `student_certificates` (bounded snapshot; ISSUED → VOIDED only; one issued per exit and type; linked replacement), `student_certificate_sequences` (forward-only per School and type) | Forced RLS on both; composite same-School keys; no runtime DELETE |
| EXP-5 | `student_import_batches` (counts, keyed digest, actor, time) | Forced RLS, append-only, unique (School, digest) |

The RLS tenant-table count went from 220 to 223 (`ElevationRlsIsolationTest`).
The model is coherent: every new table is School-owned, RLS-forced, and
retained with its School or the Student core record.

### 8.4 Data ownership

SIS still owns none of:
- Finance;
- Attendance;
- Transport;
- Library;
- Hostel;
- Academic subjects.

Student 360 projects each through its owner's read path under the owner's
capability and scope (`StudentProfileArchitectureTest`). The import writes
none of them (`StudentGuardianImportArchitectureTest`).

## 9. Residual LOW findings (EXP-1 → EXP-5)

| # | Residual | Severity | Exploitability | Surface | Fix before closure? | Checkpoint |
|---|---|---|---|---|---|---|
| R-1 | **Guardian name edit:** no stale-form token, no row lock or authority re-read under lock, and `guardian.updated` lists *submitted* keys, not changed ones (`GuardianService::update`) | LOW | None by an attacker. Two legitimate `guardians.manage` editors can silently overwrite each other, and the audit can name an unchanged field | Web Guardian edit; API Guardian PATCH | **Yes**: the one write-integrity gap left in SIS's own identity records, mirroring what EXP-1 closed for Students | SIS.HARDEN-1 |
| R-2 | **Student API PATCH** keeps last-write-wins (no version token); lock, authority re-read and precise audit already apply | LOW | Needs a staff personal access token with `students.manage`; lost update only | `/api/v1/schools/{school}/students/{id}` | No. The staff web never uses it; the mobile client is an unshipped skeleton; **no production partner scope exists** (O15 open, so production has no partner route) | Before any mobile or partner client ships (a contract change in `packages/contracts`) |
| R-3 | `StudentEnrollment::$fillable` still includes `school_id`/`status` | LOW (informational) | None: placements are DB-frozen and evidence-guarded (ORG.8G); every writer uses explicit paths | Model layer | Yes, cheap; closes the L-1 pattern | SIS.HARDEN-1 |
| R-4 | Movement register: a deactivation or reactivation dated **between** academic years matches no year, so it disappears whenever a year filter is applied (EXP-2 LOW-D) | LOW | None (completeness of a read) | `/app/students/movements` | Yes: a register that can hide an exit under a filter should not be called closed | SIS.HARDEN-1 (attribute to the next year, or label "between years") |
| R-5 | Directory filter options list inactive Sections (they match nobody) | LOW (cosmetic) | None | `/app/students` | Optional | SIS.HARDEN-1 |
| R-6 | Sibling truncation is computed before the viewer filter | LOW (informational) | None on the current whole-School route | Student 360 Family card | Optional; matters only if a Campus profile ever exists | SIS.HARDEN-1 |
| R-7 | Concurrent void (certificates) and concurrent archive (documents): the loser gets 404, not 409 | INFO | None; the state is correct | EXP-3/EXP-4 pages | Optional | SIS.HARDEN-1 |
| R-8 | Certificate and document history lists do not re-check `students.view` beyond the page floor (matches EXP-3) | INFO | None: both capability families are held only by roles that also hold whole-School `students.view` | EXP-3/EXP-4 history | Optional | SIS.HARDEN-1 |
| R-9 | Student documents are stored byte-for-byte: EXIF metadata and PDF active content are kept; no malware scan | LOW | Needs `students.documents.manage` + fresh MFA to upload; downloads are attachment-only | Documents (all owners, incl. HR) | **No: cross-cutting** (§9.1) | Documents / security programme |
| R-10 | Issuance in one School serializes while the certificate PDF renders (~0.1 s) | LOW (accepted) | None | EXP-4 | No (accepted: the number is printed on the file) | — |
| R-11 | Movement search word count | **Not a finding** | Bounded: `NameSearch::MAX_WORDS = 5`, input `max:255` | — | — | — |
| R-12 | Cascading School keys on `students`/`guardians` (EXP-0 L-4) | INFO | None at runtime (the runtime role cannot DELETE a School, rule 86) | Schema | No | — |
| R-13 | Import residuals | **None open.** All five EXP-5 LOWs were fixed before publication | — | — | — | — |

**First to fix:** R-1, the Guardian edit.

### 9.0 After SIS.HARDEN-1 (2026-10-30)

| # | Status after SIS.HARDEN-1 |
|---|---|
| R-1 | **Closed.** `GuardianProfileVersion` (keyed HMAC over a JSON encoding), FOR NO KEY UPDATE row lock, `guardians.manage` re-read under the lock, canonical names, `changedFields` from the stored change. Web only; the API keeps its contract like R-2 |
| R-2 | **Deferred, unchanged.** No production partner Student scope; the staff web never uses the API; the mobile client is not shipped. Reopen with the mobile/partner Student write contract |
| R-3 | **Closed.** `StudentEnrollment::PROTECTED_FIELDS` (`school_id`, `status`, `ends_on`) are not fillable; the one creation path uses `forceCreate` |
| R-4 | **Closed.** A status change dated in no year is marked "Outside academic year" and found by its own filter, never assigned to a neighbouring year |
| R-5 | **Closed, corrected.** The finding said inactive Sections "match nobody", but deactivating a Section never ends its placements. The directory now offers active Sections plus an inactive Section that still holds a current placement, labelled "(inactive)" |
| R-6 | **Closed.** The viewer's scope is applied before the display limit, in one query; nothing counts hidden siblings |
| R-7 | **Closed, corrected.** Measured with two real processes: the certificate-void loser already received 409, because it waits on the certificate lock. Only the document-archive loser received 404, from a status-filtered Document lock. Both now give 409; other Schools' ids stay 404 |
| R-8 | Accepted INFO (no bypass found by the HARDEN-1 review) |
| R-9 | Transferred to the Documents/security backlog (§9.1) |
| R-10 | Accepted |
| R-11 | Not a finding |
| R-12 | Informational |
| R-13 | None |
| R-14 | **New at SIS.EXP-CLOSE (LOW).** Certificate issue, void and reissue check whole-School `students.view` before the transaction (cached) and re-read only `students.certificates.issue` under the Student lock; rule 121 wording corrected to the code. Destination: SIS post-v1 (one fresh re-read per entry point). See `SIS-EXPANSION-CLOSURE.md` §7 |

**New in the HARDEN-1 review**, both fixed before publication:
- **LOW:** an array `academic_year_id` raised a 500 (the rule now bails
  first).
- **INFO:** a separator collision in the Guardian token (now JSON-encoded).

**Pre-existing, local only:** each local Students-suite run leaves 33
committed fixture Schools in the test database, already visible before
HARDEN-1. After enough runs, the Platform Registry tests page past them.
CI builds a fresh database per shard and is unaffected.
`bin/safe-test --reset-db` clears them. Recorded for test hygiene, not
changed here.

### 9.1 Document content sanitisation

EXIF stripping, PDF active-content sanitisation and malware scanning are
**cross-product Documents and security requirements**, not SIS work. The same
repository stores HR, Communications and Student files, and any fix belongs
in the one Documents pipeline (ADR 0012) with its own review. It is recorded
for the Documents/security backlog and the pre-production security review.
It does not block SIS expansion closure.

## 10. E16

| Question | Answer (repository truth) |
|---|---|
| What E16 authorises | Owner decision `OWNER-0O-E16-2026-10-16` (E16-S1): 16 reviewed no-fix HIGH advisories (115 records) in the `app` and `ai-gateway` base-image OS packages, accepted as not affected, false positive or unreachable accepted risk under runtime-hardening conditions (`infrastructure/release/vulnerability-exceptions.json`) |
| Window | Created 2026-10-16; every record expires **2026-11-13**; **last passing day 2026-11-12** (the evaluator treats `expires <= today` as expired, and one expired record invalidates the file) |
| Blocked on expiry | From 2026-11-13 the CI `Supply chain` job and `lycenza-ci publication-check` refuse **every** candidate, docs-only included. Nothing reaches `main` |
| Who approves a successor | The owner, through a new fresh-scan successor decision (the E16-S1 pattern: fresh scan, re-verified records, a new approval id). No automatic renewal; no external DPO or security-counsel approval is claimed |
| Does the next checkpoint depend on it? | **Yes, for publication.** SIS.HARDEN-1 and SIS.EXP-CLOSE both publish through `publication-check`. Each must publish by 2026-11-12 or wait for the successor. Do not start a checkpoint whose publication would rely on an expired decision |

## 11. Demo (canonical world after EXP-5)

| Feature | Covered by |
|---|---|
| Student 360 | Aarav Menon (every card) |
| Family / siblings | the Menon siblings, Aarav (Main) and Diya (Early Years) |
| Two Campuses | Northstar Gachibowli Main + Early Years, and an Early Years Campus Principal |
| Movement | 2025-26 promotion, repeat and completed exit; Kiara's Campus transfer then Section change (22 movements) |
| Exit | Arjun (completed, unrecorded exit: the ineligible example); Meera (recorded transfer withdrawal) |
| Documents | Aarav: three active and one archived; Diya: one; a Lycenza Demo School cross-School negative |
| Certificates | Meera: LC-000001 voided, then LC-000002 and TC-000001 |
| Bulk import | Walkthrough only: `docs/demo/fixtures/sis-exp-5-import-{valid,invalid}.csv`; reset removes imports |

The demo is sufficient to review every SIS feature.

**Missing scenario:**
- a seeded **deactivation/withdrawal** in the register: it needs a fresh MFA
  proof, which seeders never fabricate (it is covered by tests);
- for HARDEN-1, a **between-years** lifecycle row (R-4), which would be a test
  fixture rather than demo data.

Nothing is seeded by this audit.

## 12. Performance (consolidated, development stack)

| Workflow | Measured |
|---|---|
| Directory | 25 queries (filtered 28), constant with data |
| Student 360 | 67 queries with every module (EXP-1, budget 85); 42–50 in the EXP-3/EXP-4 measurements; constant as data grows |
| Movement register | 25 queries, constant for any page or filter and any history size |
| Documents list | 21 queries, constant from 1 to 60 documents |
| Certificates page | 34 queries; PDF render ~74 ms, 23–25 KB |
| Import preview | 31–39 queries; 34 ms for 100 rows |
| Import commit | 0.44 s for 10 rows; **3.9 s for 100 rows** (~63 queries a row through the canonical services) |

**Slowest workflow:** the import commit. **The 100-row cap is a product
contract.** Raising it needs an architecture change (fewer nested service
transactions or a set-based canonical write path), never a constant change.

## 13. Candidate next waves

Each criterion is scored 1–5, where 5 is best for doing the wave now (high
value, low risk, no dependency).

| Candidate | Customer value | Security risk | Legal dependency | Schema complexity | Domain dependency | Demo value | Parity gain | Production impact | Total |
|---|---|---|---|---|---|---|---|---|---|
| Residual SIS hardening (R-1, R-3–R-8) | 3 | 5 (removes risk) | 5 (none) | 5 (none) | 5 (SIS only) | 2 | 1 | 4 | **30** |
| Section headcount display (OD-SIS-6) | 3 | 4 | 5 | 5 | 3 (Academic + Analytics gate) | 3 | 2 (SR-24 part) | 2 | 27, **owner-gated** |
| Primary-Guardian directory column | 2 | 3 (widens a list) | 5 | 5 | 4 | 2 | 1 | 1 | 23 |
| Student reporting / parity closure (SR-23 contacts, sibling list) | 3 | 2 (contact lists) | 4 | 4 | 3 | 3 | 2 | 2 | 23, **owner-gated + D-11** |
| Student groups / House (OD-SIS-4) | 3 | 4 | 5 | 2 (two tables) | 4 | 4 | 2 (SR-17 part) | 1 | 25, **owner-gated (pilot)** |
| Annual Record Confirmation (OD-SIS-5) | 3 | 2 (Guardian identity, acceptance evidence) | 1 (legal) | 2 | 1 (Portal production-gated) | 3 | 2 | 1 | 15, **legal-gated** |

## 14. Closure assessment

| Closure criterion | State |
|---|---|
| 1. No P0 | Met |
| 2. No unresolved HIGH/MEDIUM | Met |
| 3. Original P1 gaps addressed | Met (§2) |
| 4. Remaining gaps explicitly owned | Met (§2.1, §5, §6, §7) |
| 5. Demo coherent | Met (§11) |
| 6. Regression green | Met: `74b28b93`, 10,121 tests, 10,120 passed, 1 ESI-12 skip, 0 failed |
| 7. No hidden SIS_BUILD item without a plan | **Not yet.** R-1 (Guardian edit integrity) and R-4 (register year gap) are SIS-owned, decision-free defects. They need either a fix or an explicit owner acceptance |

**Closure is not viable today, and is viable immediately after
SIS.HARDEN-1** (or after the owner explicitly accepts R-1 and R-4 as
residuals).

**Expansion closure is not product completeness.** Closing SIS.EXP would
not mean any of these is complete or approved:
- the Student Portal;
- Attendance;
- Finance;
- Analytics;
- any legal approval;
- production readiness.

Each keeps its own programme and gates.

## 15. Recommendation

**`START SIS.HARDEN-1 — SIS write-integrity & residual hardening`**, then
`SIS.EXP-CLOSE`.

Scope:
- **R-1:** a keyed stale-form token and row lock on the Guardian name edit,
  authority re-read under the lock, and precise `changedFields`;
- **R-3:** `StudentEnrollment` fillable;
- **R-4:** register attribution of between-year lifecycle rows;
- **R-5:** active-only filter options;
- **R-6:** sibling truncation after the viewer filter;
- **R-7:** 409 for concurrent void and archive.

No migration, capability, role, Campus, API-contract or UI-flow change. R-2
(API version token) stays deferred to the mobile/partner contract, and R-9
to Documents.

**Why:**
- it is the only remaining SIS-owned, decision-free work;
- it removes the last write-integrity gap in SIS's own identity records;
- it unblocks closure criterion 7;
- it is small enough to publish before the E16 window closes (2026-11-12).

Every feature candidate is owner- or legal-gated, or belongs to another
domain.

**Approvals:** owner authorisation only; no owner or legal decision is a
prerequisite. Its publication depends on E16 being valid, or on a successor.
