# SIS 11.R1 — Mid-Expansion 318-Screenshot Re-Audit & Architecture Reconciliation

Status: **AUDIT COMPLETE — documentation only** · 2026-10-31 · baseline
`main = c4f8cae1` · no product code, schema, capability or test changed.

This is a mid-expansion scope reconciliation. It does not rewrite or
invalidate earlier records:
- the core SIS closure (96/100, final local UAT);
- the SIS.EXP-0 318-screen expansion audit and its family map
  (`SIS-REFERENCE-CAPABILITY-MAP.md`);
- SIS.EXP-R1 and SIS.EXP-CLOSE;
- the SIS 11.1–11.5 completion records.

Each of those remains valid for the scope tested at the time.

## 1. Baseline and preflight

| Item | Value |
|---|---|
| `ddev env-check` | **PASS**: browser, CLI and workers share one engine and one database; DDEV database migrations = 360 |
| HEAD / local `main` / `origin/main` | `c4f8cae1` / `c4f8cae1` / `c4f8cae1` |
| Working tree | clean before this audit; this file is the only addition |
| Worktrees | one (the primary) |
| Historical stash | `stash@{0}` (phase-5d3-preflight), untouched |
| Baseline CI | integration run 41207474787 success; main push run 41209001444 success. 10,211 tests: 10,210 passed, 1 skip (ESI-12) |
| Migrations on disk | 360 |
| Demo / database | `ddev demo-reset` state after SIS 11.5; no audit fixtures were created |

## 2. Sources

1. **The 318-screen pack**, owner-held at `Screenshots Downloaded/Student Information System (SIS)`.
   - The fingerprint was re-verified this session: 318 files, SHA-256 `c7a3699e…6032`, identical to the committed value.
   - Contact sheets were generated in the session scratchpad (never in Git).
   - The decision-relevant families were re-opened at full resolution:
     - SR-13 contracts (S054–S060);
     - SR-14 pre-admission documents (S061–S063);
     - SR-31 leave (S263, S282–S283);
     - the group hub (S085–S108).
2. `SIS-REFERENCE-CAPABILITY-MAP.md` (family map, §3–§5.7) and `SIS-EXP-0-CURRENT-STATE-AUDIT.md`.
3. `SIS-EXP-R1-POST-P1-AUDIT.md` and `SIS-EXPANSION-CLOSURE.md`.
4. `SIS-11-1` … `SIS-11-5` design records.
5. The code at `c4f8cae1`: routes, services, migrations, seeder and tests.
   - Two read-only sub-audits fed this record: cross-cutting test/process gaps, and the architecture, security, performance and schema of 11.1–11.5.
   - Every concrete finding quoted below was re-checked by hand.
6. CLAUDE.md rules 100–126, ADR 0012 (with its SIS 11.5 amendment), ADR 0040, ADR 0076 and ADR 0081.

**Method change from SIS.EXP-0.** The family map classifies 42 families as wholes. This audit splits them into **114 capabilities**: 110 reference capabilities plus 4 export forms (§7.4). Each is classified at three points: SIS.EXP-0, before SIS 11.1 (SIS.EXP-CLOSE `c5e3dd23`), and after SIS 11.5. The full inventory is Appendix A. The family-level scores are also recomputed (§9.3), so the two measures can be compared.

## 3. Classification (R1–R11)

| Class | Meaning |
|---|---|
| R1 | Implemented directly |
| R2 | Functionally equivalent |
| R3 | Lycenza stronger than the reference |
| R4 | Partial |
| R5 | Missing and still valuable |
| R6 | Deferred intentionally |
| R7 | Rejected by design or privacy |
| R8 | Legally or security blocked |
| R9 | Owned by another ERP |
| R10 | Superseded / no longer needed |
| R11 | Needs an owner decision |

Every capability has exactly one class. SR-42 (navigation) is excluded from counting, as in the map.

## 4. 318-screen family reconciliation (summary)

Changes after SIS 11.1–11.5, by family (whole-family view):

| SR | Family | Before 11.1 | After 11.5 | Delivered by |
|---|---|---|---|---|
| SR-03 | Student directory | PARTIAL | PARTIAL (governed report and export; point-of-contact column open) | 11.1, 11.4 |
| SR-10 | Inactivation/reactivation | EDA | EDA (lifecycle register and export added) | 11.1, 11.4 |
| SR-12 | Exit audit | EDA | EDA (governed, exportable) | 11.1, 11.4 |
| SR-13 | Student contracts | OWNER | **PARTIAL**: document requirements and the pending-verification queue exist; parent verification and "contract signed" remain | 11.5 |
| SR-14 | Pre-admission documents | DOMAIN_FUTURE | **PARTIAL**: requirements, applicant upload and verification exist; per-class counts and concessions remain | 11.5 |
| SR-21 | Student documents and checklist | PARTIAL | **EDA**: the checklist grid, verification and birth certificate exist; immunisation and photo stay legal | 11.5 |
| SR-24 | Strength reports | OWNER | **PARTIAL**: operational capacity and strength exist; trends and matrices are gated | 11.3 |
| SR-25 | Sibling report | PARTIAL | **EDA**: the School-wide list exists (names only); family dues are Finance (D-14) | 11.2 |
| SR-36 / SR-37 | Movement / promotion | EDA | EDA (governed, exportable) | 11.1, 11.4 |
| SR-39 | Admission analytics | DOMAIN_FUTURE | DOMAIN_FUTURE (the operational list is governed and exportable; analytics stay gated) | 11.2, 11.4 |

All other families are unchanged; their per-capability rows are in Appendix A.

## 5. Impact of each checkpoint

**SIS 11.1 — Reporting Foundation**
- A closed `StudentReportRegistry`, a hub, a shared page, and contracts for filters, privacy-classed columns and closed sorts.
- It governs the directory, placements and movements, and adds `students.lifecycle`.
- Capabilities C05 and C27 became R1.

**SIS 11.2 — Operational Student Reports**
- `admissions.applications` (Campus-aware; C103 → R1).
- `students.siblings` (names only, whole School; C76 → R1).

**SIS 11.3 — Section Capacity**
- OD-SIS-6: one advisory `sections.capacity` with database guards.
- Strength from one placement predicate; the `students.section_capacity` report.
- Occupancy context across placement, transfer, conversion and rollover.
- C69 and C70 became R1, and C73 became R4.

**SIS 11.4 — Governed Export & Print (D-11)**
- `students.export` and `admissions.export` (School Administrator only).
- CSV for five reports, with formula-safe cells, a row ceiling and one audit event each.
- A print view (X01, X02 → R1). XLSX and PDF are deferred (X03, X04 → R6).

**SIS 11.5 — Compliance & Documents**
- Requirement rules, derived compliance, and verify/reject with closed reasons and fresh MFA.
- Applicant documents bound to their application, which move (never copy) to the Student on conversion.
- The cohort checklist (states only, browser-only).
- C33, C36, C40, C41, C61 and C62 changed class. C35 became R4, and C42 became R11.

## 6. Pillar assessments

### 6.1 Reporting gaps remaining
SIS-owned reporting is governed for 7 of 8 SIS-owned report families. SR-02 is satisfied by Data Health, by design.

Open:
- the point-of-contact column (C08, R5, small);
- grouped counts: document counts by class (C35/C42) and status KPIs (C06);
- vacancy against the pipeline (C73).

Each of these needs a decision about operational counts, the same kind as OD-SIS-6.

Everything else report-shaped is Attendance-owned (C85–C88) or Analytics/Finance (C28, C71, C77–C79, C104–C105).

### 6.2 Export gaps remaining
- **Implemented where authorized:**
  - CSV (five reports);
  - print (five reports).
- **Deferred:** XLSX (X03) and downloadable PDF (X04).
  - CSV opens in every spreadsheet, and a browser prints to PDF.
  - Building either would add a library for no new data. **Not worth implementing now** (value E1).
- **Rejected:** contact and identity exports (SR-23, D-15).
- **Other modules' responsibility:** Finance and Attendance exports.

### 6.3 Documents gaps remaining (detailed SR-21 / SR-13 / SR-14 table)

| Capability | Before 11.5 | After 11.5 | Status |
|---|---|---|---|
| Per-Student documents | R1 (SIS.EXP-3) | R1 | done |
| Applicant / pre-admission documents | R9 | **R1** | done (Campus-scoped with the application) |
| Requirement configuration | R11 | **R2** | done (closed dimensions, immutable rules) |
| Mandatory / optional flag | R11 | **R1** | done |
| Verification | — | **R1** | done (closed reasons, fresh MFA, final) |
| Pending-verification queue | R11 | **R2** | done (cohort state filter) |
| Rejected → replacement | — | **R1** | done (supersede) |
| Cohort checklist (per class × document) | R11 | **R1** | done (states only) |
| Birth certificate category | R4 | **R1** | done (no identifier) |
| Signed agreements / "contract forms signed" | R8 | R8 | legal (electronic acceptance) |
| Parent-uploaded information / documents | R11 | R11 | Portal owner decision |
| Missing-document dashboard (counts) | R11 | **R4** | the per-Student states exist; grouped counts need a counts decision |
| Document expiry | — | R6 | not in the pack; no requirement |
| Immunisation / health documents | R8 | R8 | legal (health data) |
| Photographs | R8 | R8 | legal (children's images) |
| Government IDs | R8 | R8 | legal (D-15) |
| Bulk download / ZIP | R7 | R7 | rejected (mass export of Highly Sensitive files) |
| School-wide document directory | R7 | R7 | rejected (rule 120) |

### 6.4 Agreements / contracts (§14–§15, §63)
**What the reference shows.** The re-opened SR-13 screens show the reference's "contract":
- the parent re-verifies listed data fields and documents before next-year promotion (labelled "financial promotion");
- it is recorded as **"Agreed on" + IP address**;
- there is a parent-information update log.

This is a Portal-led electronic acceptance tied to fee-based promotion. Lycenza rejects fee-gated promotion (C100).

| Model | Description | Status |
|---|---|---|
| A | Operator-recorded acknowledgement (staff records that a family acknowledged on paper) | technically buildable now; low value without the paper workflow decision; no legal blocker |
| B | Guardian/Student click-to-accept acknowledgement (Portal) | owner decision (OD-SIS-5) and Portal scope; legal review of the wording |
| C | Electronic signature | legally blocked (no review) |
| D | Legally binding contract | legally blocked |

**SIS 11.6 Agreements = PARTIALLY READY.** Only model A is buildable now, and its value is low. The reference's actual function (B with time and IP, data re-verification) is OD-SIS-5 plus legal review, so it should **not** be next.

### 6.5 Groups (§16–§17)
**What the reference shows.** The group hub (52 screens):
- typed groups;
- membership rules and bulk "assign to house";
- responsibilities (captain, prefect);
- staff mentors and participation badges;
- **gender split and gender-ratio-vs-Campus charts on the hub itself**.

**Is a generic "Student Communities" framework still right?** **No.** A generic group engine risks becoming:
- an authorization mechanism (group = audience = permission);
- a segmentation engine (SR-19 profiling by another name);
- an analytics bypass (group counts by gender).

**Guardrails for any future build:**
- **Named concepts only:** House first, then Club (D-17, OD-SIS-4).
- **No authority:** a group is never a capability, Campus scope, audience or approval input.
- **No derived membership:** membership is assigned by staff, never derived from attributes.
- **No grouped analytics:** no gender, demographic or count analytics of groups (C54 R7).
- **Staff mentors** only through `CurrentStaffMembers` (rule 108).
- **Communications audiences** stay School, Grade/Section, Staff and Guardian, until a separate audience decision.

**Readiness: NO.** It needs one owner decision (OD-SIS-4: name the pilot concept). There is no legal blocker and no other-ERP dependency.

### 6.6 Student Leave (§18–§19)
**What the reference shows (S263, S282–S283):**
- leave types: Sick/Health issue, Family Event, Family Emergency, Attending Competitions, Others;
- **free-text remarks such as "sick leave"**;
- notification to the class teacher and coordinator;
- approval columns that are visibly unused.

**Assessment.** Leave is valuable (E4), but:
- it is parent-initiated, so it is Portal-led;
- the remarks are health data;
- D-16 is undecided.

**Proposed Attendance boundary:**
- Leave is a **separate intimation record**. It never writes `attendance_records`.
- At register time, Attendance may *show* a "leave intimated" hint. The teacher still records the mark, and any change goes through the existing correction rules.
- Leave is never mapped automatically to `excused`.
- Reasons come from a closed, non-health catalogue. There is no free-text illness field.

**Readiness: NO** (D-16, health tier, Portal scope). High priority once those decisions exist.

### 6.7 Attendance reporting (§20–§21)

| Capability | Class |
|---|---|
| Student history | R1 |
| Not-marked / staff marking compliance | R2 |
| Late comers | R2 |
| Period-wise / subject-wise | R4 (Attendance-owned) |
| Day-wise / session grid | R9 (Attendance daily model) |
| Monthly / term percentages | R6 (rates gate, ADR 0040) |
| Gender / transport views | R7 |
| Biometric / RFID | R8 |

None of these belongs in SIS. The future adapter architecture for device ingestion still holds, after the biometric legal review and kept separate from manual registers.

### 6.8 Certificates (§22–§24)
**What exists:**
- Leaving and Transfer certificates (SIS.EXP-4):
  - eligibility from the recorded exit;
  - an immutable numbered snapshot (`LC-`/`TC-`), with forward-only counters per School and type;
  - void and linked reissue with a new number;
  - a stored `highly_sensitive` PDF Student document;
  - locked-down dompdf.
- There are no employee certificates.

**What is not built:**
- **Production use:** refused in code until the legal wording review (ADR 0081, `StudentCertificateAvailability`).
- **Bonafide, status and character certificates:** do not appear in the pack. Each would need its own owner decision on format and numbering.
- **Requisition approval:** C109 R2. Eligibility is the recorded exit, so there is no approval queue.

**Ranking.** TC/LC should **not** rank above Groups or Leave as a build: it is already built, and the remaining blocker is legal, not engineering. **Readiness for a new Certificates checkpoint: NO.**

### 6.9 Bulk operations (§25–§26)

| Operation | Status |
|---|---|
| Bulk import | exists: add-only, signed preview, atomic MFA commit, at most 100 rows (C15 R3). No second import mechanism should be built |
| Bulk Campus transfer | R6 (post-v1) |
| Bulk Section assignment | covered by import placement and rollover (R2) |
| Bulk group assignment | waits on Groups |
| Bulk inactive/reactivate | rejected as a queue (C25 R10). A bulk *direct* change would need per-Student assurance; low value |
| Bulk rollover decisions | exist (rollover plans) |

**Readiness: NO** (little remaining value).

### 6.10 Lifecycle approval (§27)
The reference offers inactive/reactive *requests* with single and bulk approval, and back-dated effective dates. Lycenza's direct-with-assurance action is safer and needs fewer steps:
- a closed reason;
- fresh MFA;
- current whole-School authority under the row lock;
- a database-required lifecycle event.

C25 stays **R10**. Configurable maker/checker would only make sense if a School's policy requires a second person, which is an owner policy (rule 102), not a parity gap. Back-dating stays OD-SIS-8 (R11).

### 6.11 SIS operational dashboard (§31)
- A dashboard built from the existing *operational* reads is possible without new authority:
  - capacity seats;
  - pending verifications (per-Student states);
  - recent lifecycle and exit rows;
  - Data Health findings.
- Any **count tiles** (Students by state, documents missing per class) fall under ADR 0040 or need an OD-SIS-6-style extension.

**SIS 11.15 still makes sense** only as a links-and-queues page after such a counts decision. **Not now.**

### 6.12 Student 360 (§32)
**Already exists** (SIS.EXP-1, `StudentProfileSections`): identity, Guardians, placement, documents, certificates, Subjects, Attendance, Fees, Transport, Hostel and Library cards, each read through its owner.

**Not yet available as authoritative sources:**
- Timetable personal view;
- Examinations results (E42);
- HR/teacher context (the class-teacher concept);
- Finance family view (D-14).

**Further Student 360 work: NO** until those owners exist.

## 7. Cross-ERP ownership, privacy and capabilities

### 7.1 Cross-ERP ownership (§33–§38)
Verified: **no** SIS-owned table duplicates another domain's truth.

| Area | Owner and status |
|---|---|
| Finance (SR-11, 14-concessions, 15, 20, 26, 34, 39-revenue: admission revenue, application and registration fee, discount, collection, outstanding, refund, "financial promotion") | Finance or Finance + Analytics composite. "Financial promotion" is rejected (C100) |
| Timetable (SR-16: master, structures, cycles, period types, practical batches, staff workload) | Timetable ERP |
| HR / Teacher (SR-32 class and subject teachers, workload, staff changes) | Teaching Assignments / Timetable / HR |
| Examinations (co-scholastic skills C57, examination attendance, performance) | Examinations; not SIS |
| Communications (WhatsApp absence, document reminders, leave notification, parent messages) | Communications owns delivery. SIS may emit domain events later |

**Noted tension, not duplication:** `SiblingReport` (Guardians) reads `students` directly, the same pattern as `GuardianStudentScope`.

### 7.2 Privacy and legal exclusions reconfirmed (§39)
Unchanged and not reopened:
- unrestricted date-of-birth lists;
- broad parent-contact reports and the column picker (C67 R11, C68 R7);
- demographics (C01, C13, C72);
- government IDs (C14);
- health documents and leave remarks (C63, C92);
- photos (C64, C66);
- contact search beyond exact-match (ADR 0041);
- family-circumstance tags (C58);
- discipline (C97);
- the principal diary (C102).

### 7.3 Capability architecture (§40)

| Capability | Holders | Campus |
|---|---|---|
| `students.export` | school_admin | never adopted |
| `admissions.export` | school_admin | never adopted |
| `students.documents.verify` | school_admin, principal | whole School |
| `admissions.documents.view` / `.manage` | school_admin, principal, admissions_officer | **Campus-adopted** |

- All are classed `children`.
- **No accidental privilege composition found:**
  - export always requires the report's own authority plus whole-School export;
  - no Campus-scoped role can export;
  - admissions staff never reach a Student document after conversion;
  - `verify` confers only verification (declared in `ApprovalWorkflowRegistry::NOT_WORKFLOWS`).
- **Two judgement notes for the owner:**
  1. **Campus-scoped path to Highly Sensitive files.** An admissions_officer or principal granted on a Campus now opens **Highly Sensitive applicant files** for that Campus, behind current MFA. This was the owner's decision, but it is the first Campus-scoped path to `highly_sensitive` documents. Once the same bytes move to the Student, rule 120 keeps them whole-School.
  2. **Latent verify-without-view.** `StudentDocumentService::decide` requires `verify` and whole-School `students.view`, but not `students.documents.view`. No seeded role holds `verify` without `view`, so this is latent only.

### 7.4 Coverage arithmetic inputs
- Appendix A lists 114 capabilities. X01–X04 are the export forms the brief asked to record separately; they are counted with the rest.
- Navigation (SR-42) is excluded.

## 8. Engineering cross-cutting review

### 8.1 Count-pin / cross-cutting checklist (§41–§42, authoritative)
Two of the last three first candidates failed CI on a pin that had not been updated. Run this list whenever the trigger applies. Paths are under `apps/platform/`.

**(a) A School capability is added or changed**
- `SisResidualHardeningArchitectureTest` (School and total counts).
- `StaffRoleGrantAuthorityTest` (classified count and its message).
- `CapabilityClasses::CLASSES`.
- `ModuleCatalogue` / `ModuleCatalogueArchitectureTest` (unless a prefix rule covers the key).
- Grant-right keys: `CapabilityClasses::GRANT_RIGHTS` and `StaffRoleGrantAuthorityTest::EXPECTED_GRANT_RIGHTS`.
- Campus-adopted keys: `CampusScopeCatalogue`, `CampusAdoptionImpactTest` (`HOLDERS`, `*_CAMPUS_CONFERRED`), and the `WHOLE_SCHOOL_OPERATIONS` declarations.

**(b) A role's capability set changes**
- `StaffRoleCatalogueSnapshotTest` (snapshot, holder lists, role count 17).
- `CampusAdoptionImpactTest` holders.
- `ProductionStaffRolesTest` (roles and sensitivity labels).
- `StaffRolePresentation` (for a new role).

**(c) A column referencing `users` is added**
- `UserReferenceCatalog`.
- `UserReferenceCatalogTest`: **both** the live total and the per-treatment count.

**(d) A new tenant table with RLS**
- `ElevationRlsIsolationTest` (RLS table count and comment).
- `TenantRetentionCatalog` (live closure-readiness comparison).
- The module's own RLS list test.

**(e) A new table referencing `students`, `applicants`, `documents` or another Student-rooted parent**
- `StudentRetentionClassificationTest::CLASSIFICATION`.
- `StudentRecordRetentionService` operational/core lists, or a participant's `tables()`.
- `ResidualRetentionClassificationTest`.
- The `ReferencingRows` exclusions in the purging service.

**(f) A new route or route parameter**
- `SchoolRouteCoverage` (or `capability:` middleware).
- `RouteUniverse`, `PlatformRouteCoverage`.
- `WebRouteParameters` (UUID / NOT_UUID).
- `WHOLE_SCHOOL_OPERATIONS` for adopted keys.
- `StudentReportRegistryTest` routes.
- API route-count pins (Leave 40, FeeSetup 22, Timetable 18, …).

**(g) A new read by the retention identity**
- `DatabaseRoleVerifier::RETENTION_TABLE_READS`.
- A GRANT in the migration (and REVOKE in `down()`).
- `RetentionIdentityTest` (exact and column-level grants).

**(h) A decision-shaped column or capability** (`*_by_user_id` of verified/decided/approved…, or `.verify`/`.approve`…)
- `ApprovalWorkflowRegistry` (`WORKFLOWS`, or `NOT_WORKFLOWS` with a reason; capabilities are accepted there since SIS 11.5).
- Also do (c).

**(i) A new Documents owner type**
- `DocumentOwner`, the database owner check, `DocumentParentRetention::OWNER_COLUMNS`, `DocumentRetentionEligibility::OWNER_RETENTION`.
- `StorageOrphanRetentionTest` (owner-map literal and seam callers).
- `DocumentReadServiceStructuralTest` (public methods).
- `Document::$fillable`.
- The `documents` parent in `StudentRetentionClassificationTest`.

**(j) A new report or export entry**
- `StudentReportRegistryTest` (ordered keys, routes, sort pins).
- `StudentReportExportTest`.
- `StudentReportsTest` (hub lists).
- Organization setup/report architecture tests, when a setup area changes.

**Always:**
- Run the **Identity** suite locally. Only the 18 `APP_URL` tests (§8.2) are expected to fail.
- Run Larastan on every touched controller.

### 8.2 DDEV Identity test gap (§43)
- **The failing tests:** 15 assertions in 6 files hard-code `http://localhost:8000`:
  - `StaffInvitation`, `StaffInvitationAcceptance`, `FreshInstallProvisioning`;
  - `BootstrapAccountActivation`, `AccountRecoveryRequest`, `AccountRecoveryReset`.
- **Why only under DDEV:** `phpunit.xml` does not force `APP_URL`, and DDEV exports `APP_URL=https://lycenza.ddev.site`. CI copies `.env.example` (`http://localhost:8000`), so the tests pass there.
- **Effect:** this is what hid the SIS 11.5 first-candidate failure. It lowers local confidence for every slice.

**Recommendation:** a small test-harness correction.
- Option 1: force a test `APP_URL` in `phpunit.xml` (`force="true"`, rule 79) plus the `bin/safe-test` and `ddev test` override sets.
- Option 2: assert against `config('app.url')`.
- Bundle it with the hardening checkpoint (§11) rather than a checkpoint of its own.

### 8.3 Storage local-test gap (§44)
- **The tests:** 11 methods in 8 files need real S3-compatible storage:
  - `DocumentMinioStorageTest`, `DocumentHttpMinioIntegrationTest`, `DocumentReadMinioIntegrationTest`;
  - one test each in `StorageOrphanRetentionTest`, `StudentRetentionPruneTest`, `EmployeeRetentionPruneTest`, `AdmissionsGuardianRetentionTest` and `AcademicOperationsRetentionTest`.
- **Where they can run:** CI provides versitygw (`infrastructure/ci/compose.s3-versitygw.yml`). DDEV has no object storage. They pass only in CI or on the `bin/safe-test` compose stack.
- **Is the CI evidence sufficient?** Yes. These run on every code-lane candidate.
- **Recommendation:** classify them as environment-only, with an explicit skip message when the S3 endpoint is unreachable locally, so a local run reports "skipped (no object storage)" instead of failing. This needs no storage redesign.

### 8.4 Same-second audit ordering (§45)
- **Why ties happen:** `occurred_at` has one-second precision, while the UUIDv7 `id` is monotonic and is a safe tie-breaker.
- **Scale:** 19 statements in 18 test files order by `occurred_at` alone.
- **High likelihood (3):**
  - `CampusProfileTest:146` (asserts three events in order);
  - `TeacherCurriculumDeliveryAccessTest:97`;
  - `TeacherStudentMarkAccessTest:47/294`.
- **Low likelihood (15):** these involve a single matching event or an order-insensitive assertion.

**Priority:** fix the three high-likelihood tests in the next hardening checkpoint (`->orderBy('id')`, or select the event by content). Add the tie-breaker to the rest opportunistically.

### 8.5 Stale-form inventory (§46)
**Protected:**
- Student profile (`StudentProfileVersion`) and Campus name edit;
- Guardian profile and contacts;
- every document write (integer version);
- Admissions applicant and application corrections (`expected_updated_at`);
- academic year and term draft dates;
- School identity and presentation.

Note: `updated_at` tokens have one-second precision.

**Last-save-wins:**

| Path | Significance |
|---|---|
| API v1 Student PATCH, API v1 Guardian PATCH | medium |
| Student–Guardian relationship update (web and API) | medium |
| Section update (including advisory capacity) | low–medium |
| Year/term rename, GradeLevel, Subject, Department, Room, SubjectOffering, Campus, School profile | low (reference data) |

**4 significant paths** lack protection: the two API PATCHes, the relationship update, and the Section edit. None blocks expansion. The relationship and Section edits are worth including in the hardening checkpoint.

### 8.6 Security review of 11.1–11.5 (§47)
**Checked:**
- report enumeration (an unknown key is 404 on show, export and print);
- export escalation (report authority plus whole-School export; forged columns are 422);
- document IDOR (records scoped by application, Student and School under lock);
- requirement leakage (cohort states only, no document ids);
- Campus-scope mismatch;
- client-supplied ids (narrow only; foreign ids match nothing);
- capability composition.

**No HIGH or MEDIUM finding.**

**LOW findings (verified in code):**
- **L-1:** `applicant_documents.document_id` has **no foreign key**, and the unique index `documents_id_applicant_school_unique` is unused.
  - Integrity rests on the single writer plus the immutability trigger.
  - A `(document_id, school_id)` key would enforce same-School (rule 70 spirit).
  - The owner-specific key cannot be used, because conversion re-points the owner.
- **L-2:** `DocumentService::createForApplicant`, `archiveForApplicant` and `DocumentReadService::applicantContent` do **no capability check of their own**.
  - Their docblock claims one.
  - The Student equivalents re-check `students.documents.*`.
  - The only caller authorizes correctly, but a future caller would not be stopped.
- **L-3:** `ConvertedApplicationRetentionService::purge` replaced an atomic `DELETE … WHERE NOT EXISTS` with pluck-then-delete.
  - If an application for a leaving applicant is created concurrently, the unit now errors and rolls back instead of skipping.
  - Data stays safe; the unit simply fails that run.
- **L-4:** latent `verify` without `students.documents.view` (§7.3).
- **L-5 (informational):** the print GET writes one audit row on every load or prefetch.

### 8.7 Performance review (§48)
No significant concern. Low to low-medium items:

| Item | Rating | Detail |
|---|---|---|
| `sectionStrengthQuery` | low | aggregates every non-closed Section of the School per page and count |
| Cohort status filter without a category | low–medium | ORs four EXISTS plus correlated LIMIT-1 subqueries per cohort Student, run for both count and page. Indexed, fine at School scale |
| Export chunking | low–medium | uses OFFSET pages of 500 (up to 20 re-executions; the movement register re-runs its union). No snapshot, so rows can shift between chunks |
| Lifecycle report | low | filters on a derived local date |

No N+1. Every page is bounded at 50 or fewer; CSV is capped at 10,000 rows and print at 1,000.

### 8.8 Schema / ownership review (§49–§50)

**`2027_01_20_090000_guard_section_advisory_capacity`** (SIS 11.3)
- Adds a capacity CHECK and a closed-year trigger. Clean `down()`.
- There is no backfill, so a pre-existing `capacity = 0` row would fail the deploy (none exists in demo or tests).

**`2027_01_21_090000_add_document_compliance`** (SIS 11.5)

| Table | Owner | Contents |
|---|---|---|
| `documents` | Documents | `applicant_id` with a composite key; the owner check covers six arms |
| `student_documents` | Students | verification columns, `verified_by` RESTRICT, unique provenance id, `birth_certificate` category |
| `applicant_documents` | Admissions | composite keys to the application and applicant; the moved-to-Student column uses `SET NULL (column)`; CHECKs, a guard trigger, RLS, no DELETE, retention SELECT; three user references |
| `student_document_requirements` | Students | composite keys; immutable-terms trigger; RLS; no DELETE; two user references |

- **Ownership:** coherent. Students never reads Admissions tables; applicant compliance receives its documents from Admissions.
- **The owner arm is a closed set:** database check, `DocumentOwner`, `OWNER_COLUMNS` and `OWNER_RETENTION`, all pinned by tests. The generic Documents paths refuse `applicant`. A new owner type needs the §8.1(i) list and an ADR 0012 amendment.

**Rule-only checks to note:**
- a requirement's "year not closed" is checked only in the service, without a lock;
- `effective_from` is not bounded to the year;
- duplicate rules are possible, which is harmless because they merge.

### 8.9 Reporting and export architecture (§51–§52)
**Reports.** All eight registered reports have a stable key, an owner, authority capabilities, a declared scope, declared filters, privacy-classed columns, closed sorts with a tie-break, and page sizes of 50 or fewer.
- Both WHOLE_SCHOOL dedicated pages are declared operations.
- The WHOLE_SCHOOL *rendered* reports (lifecycle, siblings) are enforced through `StudentReportAccess::mayOpen` on the shared C3 route, which is sound.
- **Documentation drift:** the registry docblock still says "never an export (D-11)", and an `AdmissionApplicationReadService` docblock is misplaced.

**Exports.** Five exporters, each with an explicit capability, declared columns, the 10,000/1,000 limits, `CsvCell` on every cell, one audit event and the viewer's scope.
- The non-exporters (siblings, placements, document compliance) return 404.
- No export is wider than its screen.

### 8.10 Documents architecture (§53)
Safe types (PDF/JPEG/PNG, content-sniffed), requirement applicability, final verification, version-checked writes, history kept (archive, supersede), subject scope, download authorization on every request, and conversion continuity: all **verified**. Exceptions are L-1 to L-4.

## 9. Coverage

### 9.1 Advanced coverage after SIS 11.5 (capability level, Appendix A)

| Measure | Count |
|---|---|
| A. Total meaningful capabilities | **114** |
| B. Implemented / equivalent / improved (R1 + R2 + R3) | **39** (20 + 9 + 10) |
| C. Partial (R4) | **4** |
| D. Missing and valuable (R5) | **1** |
| E. Deferred (R6) | **6** |
| F. Rejected (R7) | **17** |
| G. Blocked: legal/security (R8) | **10** |
| H. Other ERP (R9) | **19** |
| I. Superseded (R10) | **2** |
| Needs owner decision (R11) | **16** |

114 = 39 + 4 + 1 + 6 + 17 + 10 + 19 + 2 + 16.

**Advanced coverage** = B / (A − R7 − R10) = 39 / 95 = **41.1 %**.

### 9.2 Advanced SIS core operational coverage (§55)
- **Denominator:** A, minus rejected (R7, 17), blocked (R8, 10), other ERP (R9, 19), superseded (R10, 2) and analytics-only deferrals (R6 owned by Analytics, 2) = 114 − 50 = **64**.
- **Strict:** (R1 + R2 + R3) / 64 = **39 / 64 = 60.9 %**.
- **Weighted** (½ × R4): (39 + 2) / 64 = **41 / 64 = 64.1 %**.

The remaining 23 operational capabilities are:
- 16 owner decisions;
- 4 partial;
- 4 deferred (bulk transfer, hobbies, XLSX, PDF);
- 1 missing (point-of-contact column).

That totals 25, which counts the R4 items as also "remaining".

### 9.3 Coverage change since the original 318 audit (§56)

| Measure | SIS.EXP-0 | Before 11.1 (EXP-CLOSE) | After SIS 11.5 | Change since EXP-0 |
|---|---|---|---|---|
| Implemented / equivalent / improved | 15 | 25 | **39** | +24 |
| Owner decision (R11) | 33 | 23 | **16** | −17 |
| Advanced coverage B / (A − R7 − R10) | 15 / 97 = 15.5 % | 25 / 95 = 26.3 % | **39 / 95 = 41.1 %** | +25.6 pts |
| Core operational, strict | 15 / 63 = 23.8 % | 25 / 61 = 41.0 % | **39 / 64 = 60.9 %** | +37.1 pts |
| Core operational, weighted | 18 / 63 = 28.6 % | 27.5 / 61 = 45.1 % | **41 / 64 = 64.1 %** | +35.5 pts |
| Family map, overall weighted / strict (map §5 formula) | 31.1 % / 21.6 % | 39.2 % / 29.7 % | **45.9 % / 35.1 %** (17/37, 13/37) | +14.8 / +13.5 pts |
| Family map, v1 weighted / strict | 76.7 % / 53.3 % | 80.6 % / 61.1 % | **81.0 % / 61.9 %** (17/21, 13/21) | +4.3 / +8.6 pts |
| Screen-weighted, overall | 19.8 % / 10.1 % | 24.3 % / 15.8 % | **29.5 % / 18.8 %** (88/298, 56/298) | +9.7 / +8.7 pts |

**Family reclassifications applied (SIS 11.x):**
- SR-21 PARTIAL → EDA;
- SR-25 PARTIAL → EDA;
- SR-24 OWNER → PARTIAL;
- SR-14 DOMAIN_FUTURE → PARTIAL;
- SR-13 OWNER → PARTIAL.

Resulting family totals: EDA 14 (13 scored + SR-42), PARTIAL 8, DOMAIN 7, POST_V1 1, DNB 4, OWNER 3, LEGAL 5 = 42.

**Attribution of the +14 capabilities since EXP-CLOSE:**

| Checkpoint | Capabilities |
|---|---|
| SIS 11.1 | C05, C27 |
| SIS 11.2 | C76, C103 |
| SIS 11.3 | C69, C70 |
| SIS 11.4 | X01, X02 |
| SIS 11.5 | C33, C36, C40, C41, C61, C62 |

The earlier +10 came from SIS.EXP-1 to EXP-5.

**About the old "~20 %" figure.** It is the screen-weighted family score. It is dominated by the 40-screen demographic page and the 52-screen group hub, and it is not a measure of buildable SIS scope; use §9.2 for that.

## 10. Competitor parity and differentiation

### 10.1 Parity matrix (§57)

| Pillar | Reference | Lycenza now | Gap | Lycenza advantage |
|---|---|---|---|---|
| Student Master | wide personal schema, ~150-field picker | identity, one Guardian model, encrypted contacts | address (OD-SIS-7), demographics (legal) | data minimization, stale-form, precise audit |
| Admissions | applications, documents, concessions | applications, corrections, conversion, documents, verification | per-class counts; concessions are Finance | duplicate-safe conversion, Campus scope, document continuity |
| Placement | branch/section shuffles | placements, transfers, rollover, frozen history | bulk transfer (post-v1) | blockers, both-Campus authority, DB-frozen placements |
| Lifecycle | request/approve queues, back-dating | direct-with-assurance | back-dating (OD-SIS-8) | accountable actor, closed reasons, DB evidence |
| Reporting | many dashboards | 8 governed reports | Attendance and analytics views (other owners) | closed catalogue, privacy-classed columns, Campus scope |
| Export | Excel, PDF, print everywhere | CSV and print on 5 reports | XLSX/PDF (low value) | per-dataset capability, formula-safe, audited |
| Capacity | strength matrices, gaps | advisory capacity, strength, vacancy | trends (Analytics) | one predicate, never blocks, no demographics |
| Documents | checklist, verify, parent contracts | requirements, verify, checklist, applicant continuity | parent upload, e-acceptance | MFA, closed reasons, no-copy move, no bulk ZIP |
| Groups | full hub with gender analytics | none | OD-SIS-4 | — (guardrails defined, §6.5) |
| Leave | parent intimation, free text | none | D-16 | (Attendance boundary defined, §6.6) |
| Attendance | 10-tab reports with % | registers, status, exceptions, history | rates (gated), daily model | registers are the evidence |
| Certificates | TC requisition | LC/TC issue, void, reissue, register | production legal gate | immutable numbering, no dues blocking |
| Bulk Operations | import, bulk transfer, bulk approvals | atomic import | bulk transfer (post-v1) | signed preview, all-or-nothing |
| Dashboards | KPI dashboards | Data Health, Setup Center | counts decision | no small-cohort exposure |
| Student 360 | scattered tabs | one assembled profile over owners | Timetable / Exams / HR cards | absent-not-hidden sections |

### 10.2 Lycenza differentiation (§58)
- Database-enforced integrity: frozen placements, immutable rules and certificates, version guards.
- Append-only audit.
- RLS tenant isolation.
- Privacy by omission: no demographics, IDs, photos or contact dumps.
- MFA on Highly Sensitive reads and writes.
- Stale-form and race protection on identity and documents.
- Explicit domain ownership.
- Data Health instead of hidden exceptions.
- Accessibility pass (SIS 9.1).
- Governed, audited, formula-safe export.
- Document verification with closed reasons and conversion continuity.

## 11. Remaining candidates and recommendation

### 11.1 Priority matrix (§59–§60)
Value scale: E0 none … E5 critical.

| # | Candidate | Value | Effort | Risk | Dependency | Reference evidence | Recommendation |
|---|---|---|---|---|---|---|---|
| 1 | Agreements / contracts | E3 | M | high (legal) | OD-SIS-5, legal, Portal | SR-13 (8 screens) | wait for legal review; model A only if asked |
| 2 | Student Groups (House pilot) | E4 | M | medium (authorization/analytics creep) | OD-SIS-4 | SR-17 (52) | **first feature once OD-SIS-4 names the pilot** |
| 3 | Activities / talents | E2 | M | medium | Groups; Examinations co-scholastic | SR-18, SR-40 | after Groups |
| 4 | Student Leave | E4 | M | high (health data) | D-16, Portal, Attendance | SR-31 (5) | after D-16; Attendance boundary §6.6 |
| 5 | Advanced Attendance reports | E3 | M | medium | Attendance owner; rates gate | SR-29 (25) | not SIS; Attendance programme |
| 6 | Family / sibling expansion (dues) | E2 | M | high (D-14) | Finance | SR-25 | Finance |
| 7 | Certificates / TC | E3 (built) | S | legal | ADR 0081 review | SR-41 (1) | legal review, not code |
| 8 | Bulk Student operations | E2 | M | medium | — | SR-09 | post-v1 |
| 9 | Lifecycle maker/checker | E1 | M | low | owner policy | SR-10 | not adopted (R10) |
| 10 | SIS operational dashboard | E3 | S–M | medium (counts) | counts decision | SR-03/24 | after a counts decision |
| 11 | XLSX | E1 | S | low | library | — | not now |
| 12 | Downloadable report PDFs | E1 | S | low | dompdf reuse | — | not now |
| 13 | Student 360 expansion | E2 | S each | low | Timetable / Exams / HR | — | as owners land |
| 14 | Section stale-form protection | E2 | S | low | — | — | hardening |
| 15 | Identity local test harness | E3 (process) | S | low | — | — | hardening |
| — | SIS 11.5 residuals L-1 to L-4, same-second tests, checklist | E3 | S | low | — | — | hardening |

### 11.2 Dependency ranking (§61)

| Ranking | Candidates |
|---|---|
| Can build now (no unfinished ERP) | hardening items (L-1 to L-4, harness, same-second tests, Section and relationship stale-form); Groups (*after OD-SIS-4*); dashboard links-and-queues (*after a counts decision*); bulk transfer (post-v1) |
| Can partially build now | Agreements model A; Leave (intimation only, without Portal) is possible but would duplicate the Portal flow |
| Should wait | Leave (Portal + Attendance); attendance rates and analytics; family dues; Student 360 expansion |

### 11.3 Blocked decisions (§62)

| Kind | Decisions |
|---|---|
| Legal | OD-SIS-5 electronic acceptance (C37); certificate wording (ADR 0081); demographics / IDs / photos / health; biometrics; discipline |
| Owner | OD-SIS-4 groups pilot; D-16 leave (+ health tier); OD-SIS-7 address; OD-SIS-8 back-dating; SIS-020 family contact list; a document/strength **counts** decision (C35, C42, C06; dashboard); Portal parent upload/edit (C38, C39) |
| Architecture | Analytics D-01..D-04 / D-14 (rates, trends, money by cohort) |

### 11.4 Recommended sequence for remaining SIS expansion (§42)
1. **G — SIS 11.H1 hardening checkpoint** (below).
2. **B — SIS 11.7 Student Groups (House pilot)**, once OD-SIS-4 is decided.
3. **C — SIS 11.9 Student Leave**, once D-16 and the health-tier review are decided (Portal-led, §6.6 boundary).
4. Dashboard (links and queues) after a counts decision.
5. Agreements after legal review.

Attendance reporting, certificates production, Finance/family views and Student 360 cards follow their owners.

### 11.5 Exact next checkpoint (§65)
**G — a correction/hardening checkpoint: "SIS 11.H1 — Post-expansion hardening".**

**Why G and not a feature:**
- Every remaining SIS feature is gated by an owner or legal decision:
  - Agreements: legal;
  - Groups: OD-SIS-4;
  - Leave: D-16 plus health tier;
  - Certificates: legal wording;
  - Dashboard: counts.
- Meanwhile the evidence shows small defects and process gaps in freshly shipped Highly Sensitive code, and a test harness that hid a CI failure.

**Proposed scope** (small, no schema beyond L-1):
1. **L-1:** a same-School `(document_id, school_id)` key on `applicant_documents`; drop the dead unique index.
2. **L-2:** capability re-checks in the three applicant Documents entry points (or correct the docblock if the caller-only model is chosen).
3. **L-3:** restore the atomic leaving-applicant delete.
4. **L-4:** `decide()` also requires `students.documents.view`.
5. Fix the three high-likelihood same-second audit tests.
6. Commit the §8.1 checklist under `docs/development/`.
7. DDEV Identity harness: a forced test `APP_URL`.
8. Explicit local skip for object-storage tests.
9. Stale-form tokens on the Section edit and the Student–Guardian relationship update.
10. Registry docblock drift.

In parallel, the owner can decide OD-SIS-4, so that **B (Student Groups)** follows H1.

## 12. Questionnaire

| Q | Answer |
|---|---|
| Q1 | **114** meaningful capabilities (110 reference + 4 export forms; navigation excluded) |
| Q2 | **20** directly implemented (R1) |
| Q3 | **9** functionally equivalent (R2) |
| Q4 | **10** improved beyond the reference (R3) |
| Q5 | **4** partial (R4) |
| Q6 | **1** genuinely missing (R5: point-of-contact column) |
| Q7 | **6** intentionally deferred (R6) |
| Q8 | **17** rejected (R7) |
| Q9 | **10** legally/security blocked (R8) |
| Q10 | **19** other ERPs (R9). Also: 2 superseded (R10), 16 owner decisions (R11) |
| Q11 | Core operational coverage **39 / 64 = 60.9 %** strict, **41 / 64 = 64.1 %** weighted |
| Q12 | Since before 11.1: implemented capabilities **25 → 39 (+14)**; core strict **41.0 % → 60.9 % (+19.9 pts)**; advanced **26.3 % → 41.1 % (+14.8 pts)** |
| Q13 | **NO** BLOCKER gap |
| Q14 | **NO** MAJOR SIS-owned buildable gap. The large remaining families are owner- or legal-gated decisions, not defects |
| Q15 | **PARTIAL**: SIS-owned reporting is competitive; Attendance and analytics views belong to other owners or are gated |
| Q16 | **PARTIAL**: CSV and print competitive and safer; XLSX/PDF deferred |
| Q17 | **YES** for staff-operated compliance (requirements, verification, checklist, applicant continuity). Parent self-service is outside SIS |
| Q18 | **PARTIAL** (model A only; the reference function is legally blocked) |
| Q19 | **NO**: needs OD-SIS-4 (no legal blocker) |
| Q20 | **NO**: needs D-16, the health tier and Portal |
| Q21 | **NO** as a new build: LC/TC exist; production waits on legal wording; other types are not in the pack |
| Q22 | **NO**: import exists; bulk transfer is post-v1 |
| Q23 | **NO**: Student 360 exists; further cards wait on Timetable, Exams and HR |
| Q24 | **NO** duplicated truth (SiblingReport's direct `students` read is a pattern tension, not duplication) |
| Q25 | **NO** privilege leak (latent L-4; Campus-scoped Highly Sensitive applicant access is owner-decided, §7.3) |
| Q26 | **NO** tenant-isolation concern |
| Q27 | **NO** significant performance concern (low–medium items in §8.7) |
| Q28 | §8.1 (a), (b), (f), (h), plus Campus (a) items when adopted, plus the Identity suite |
| Q29 | §8.1 (c) **both** UserReferenceCatalogTest counts, (h) if decision-shaped, (e)/(g) when the column's table is new |
| Q30 | **YES**, but bundled in SIS 11.H1 rather than as its own checkpoint |
| Q31 | **YES**: 3 high-likelihood, 15 low-likelihood |
| Q32 | **4** significant paths (API Student PATCH, API Guardian PATCH, relationship update, Section edit) |
| Q33 | Student Groups (House), Student Leave, SIS operational dashboard, Agreements (acknowledgement), Activities/talents |
| Q34 | Groups (after OD-SIS-4) and the dashboard (after a counts decision) need no other ERP. Leave needs Portal/Attendance; Agreements needs Portal and legal; Activities wait on Groups |
| Q35 | **G — SIS 11.H1 Post-expansion hardening** |

## 13. Follow-up status (added by SIS 11.H1, 2026-10-31)

The findings above are kept as recorded. SIS 11.H1
(`SIS-11-H1-POST-EXPANSION-HARDENING.md`) closed each one individually:

| Finding | Status |
|---|---|
| L-1 | CLOSED |
| L-2 | CLOSED |
| L-3 | CLOSED |
| L-4 | CLOSED |
| §8.4 three same-second audit tests | CLOSED (11 others classified) |
| §8.2 DDEV `APP_URL` | CLOSED |
| §8.3 object-storage classification | CLOSED |
| §8.1 checklist | committed (`docs/development/CROSS-CUTTING-CHANGE-CHECKLIST.md`) |
| §8.5 Section edit | CLOSED |
| §8.5 relationship edit | CLOSED |
| §8.5 API Student PATCH | CLOSED |
| §8.5 API Guardian PATCH | CLOSED |
| §8.9 docblock drift | CLOSED |
| L-5 (print audit per load) | informational, unchanged |

## Appendix A — Capability inventory (114)

Bold in "After 11.5" marks a change since SIS.EXP-CLOSE.

| Id | SR | Capability | SIS.EXP-0 | Before 11.1 | After 11.5 | Owner | Evidence | Next |
|---|---|---|---|---|---|---|---|---|
| C01 | SR-01 | Composition by demographic lens (gender, religion, caste, mother tongue, parental status, parent occupation) | R8 | R8 | R8 | Analytics | D-15, ADR 0040 | never for parity |
| C02 | SR-01 | Composition by operational attribute (house, transport, lunch, how joined) per Section | R9 | R9 | R9 | Analytics | ADR 0040 person-count gate | Analytics D-01..D-04 |
| C03 | SR-02 | Inactive-on-rolls / active-without-placement exception lists | R3 | R3 | R3 | Students (Data Health) | Data Health checks | — |
| C04 | SR-02 | Promotion pending fee clearance list | R7 | R7 | R7 | — | promotion never fee-gated | never |
| C05 | SR-03 | Student directory with year/Campus/Grade/Section filters | R4 | R4 | **R1** | Students | SIS.EXP-1, SIS 11.1 students.directory | — |
| C06 | SR-03 | Directory status KPI counts | R6 | R6 | R6 | Analytics | ADR 0040 | after Analytics gate |
| C07 | SR-03 | Gender, age and photo columns on the directory | R7 | R7 | R7 | — | D-15, DOC-002 | never |
| C08 | SR-03 | Point-of-contact (primary Guardian) column | R5 | R5 | R5 | Students/Guardians | SIS.EXP-1 left P3 | small SIS read (guardians.view) |
| C09 | SR-03 | Row-level status actions from the list | R3 | R3 | R3 | Students | assured profile action (ORG.8E) | — |
| C10 | SR-04 | Student identity edit | R1 | R3 | R3 | Students | stale-form token, precise audit (SIS.EXP-1) | — |
| C11 | SR-04 | Parent blocks (father/mother/guardian) | R2 | R2 | R2 | Guardians | one Guardian model, typed encrypted contacts | — |
| C12 | SR-04 | Student home address | R11 | R11 | R11 | Students | OD-SIS-7 | owner decision |
| C13 | SR-04 | Demographics (religion, caste, blood group, income, nationality) | R8 | R8 | R8 | — | D-15 | legal |
| C14 | SR-05 | Government/statutory identifiers (national id, passport, visa, tax) | R8 | R8 | R8 | — | D-15, CRT-011 | legal per identifier |
| C15 | SR-06 | Bulk Student import (template, validate, preview, commit) | R11 | R3 | R3 | Students | SIS.EXP-5: signed preview, atomic MFA commit, canonical services | — |
| C16 | SR-06 | Section assignment during import | R11 | R2 | R2 | Students | placement columns in the import contract | — |
| C17 | SR-06 | Import of gender, admission type, address | R11 | R7 | R7 | — | not canonical fields | never |
| C18 | SR-06 | Update / merge import mode | R11 | R7 | R7 | — | rule 122: add-only | never |
| C19 | SR-07 | Higher-education programmes and semesters | R7 | R7 | R7 | — | K-12 scope (SIS-030) | never |
| C20 | SR-08 | Email syntax validity lists | R2 | R2 | R2 | Communications | validation on entry; suppression | — |
| C21 | SR-09 | Single Campus transfer | R3 | R3 | R3 | Students | CampusStudentTransferService, both-Campus authority, blockers | — |
| C22 | SR-09 | Bulk Campus transfer | R6 | R6 | R6 | Students | POST_V1 (ADR 0076 §44.25) | post-v1 |
| C23 | SR-09 | Shuffle / transfer log | R4 | R1 | R1 | Students | movement register (SIS.EXP-2) | — |
| C24 | SR-10 | Inactive / reactive status change | R3 | R3 | R3 | Students | DIRECT_ASSURED: closed reason, fresh MFA, DB evidence | — |
| C25 | SR-10 | Request/approve queue and bulk approval for status changes | R10 | R10 | R10 | Students | superseded by direct-with-assurance (ORG.8E) | reopen only with a new owner policy |
| C26 | SR-10 | Back-dated effective date of a status change | R11 | R11 | R11 | Students | OD-SIS-8 (D-13) | owner decision |
| C27 | SR-10 | Lifecycle (inactive/reactive) register report | R5 | R5 | **R1** | Students | students.lifecycle (SIS 11.1), export (11.4) | — |
| C28 | SR-11 | Exit revenue impact (forgone fees, attrition rate) | R9 | R9 | R9 | Analytics + Finance | D-14, ADR 0040 | Finance/Analytics |
| C29 | SR-12 | Exit register (who, when, reason, final placement) | R4 | R1 | R1 | Students | movement register exits (SIS.EXP-2) | — |
| C30 | SR-12 | Leaving-certificate checkpoint on exit | R11 | R1 | R1 | Students | certificate history (SIS.EXP-4) | — |
| C31 | SR-12 | Dues checkpoint at exit | R7 | R7 | R7 | — | OD-SIS-2.5: fees never block an exit or certificate | never |
| C32 | SR-12 | Attendance/fees after exit exceptions | R3 | R3 | R3 | Students (Data Health) | Data Health checks | — |
| C33 | SR-13 | Configure documents parents must provide (mandatory flag) | R11 | R11 | **R2** | Students | requirement rules (SIS 11.5) | — |
| C34 | SR-13 | Configure data fields parents must re-verify | R11 | R11 | R11 | Students + Portal | OD-SIS-5 | owner decision + legal |
| C35 | SR-13 | Documents dashboard (counts per class) | R11 | R11 | **R4** | Students | cohort checklist shows states, not grouped counts | counts need an OD-SIS-6-style decision |
| C36 | SR-13 | Documents pending verification queue | R11 | R11 | **R2** | Students | cohort filter "Pending verification" + per-Student verify (SIS 11.5) | — |
| C37 | SR-13 | Contract forms signed with agreed-on time and IP address | R8 | R8 | R8 | Portal + Legal | electronic acceptance unreviewed (OD-SIS-5 LEGAL_REVIEW_REQUIRED) | legal review |
| C38 | SR-13 | Parent information update log (parent-edited data) | R11 | R11 | R11 | Portal | Guardian self-service writes not authorized | owner decision |
| C39 | SR-13 | Parent-uploaded documents | R11 | R11 | R11 | Portal + Documents | Guardian upload excluded (SIS 11.5 brief) | owner decision |
| C40 | SR-14 | Pre-admission required-certificate configuration | R9 | R9 | **R1** | Students/Admissions | applicant requirement rules (SIS 11.5) | — |
| C41 | SR-14 | Applicant document upload and verification | R9 | R9 | **R1** | Admissions | applicant_documents, verify (SIS 11.5) | — |
| C42 | SR-14 | Per-class uploaded/verified counts at application stage | R9 | R9 | **R11** | Admissions | grouped counts | owner decision with C35 |
| C43 | SR-14 | Pre-admission concession approval queue | R9 | R9 | R9 | Finance | applicant is not a financial subject (ADM-014) | Finance |
| C44 | SR-15 | Fee-discount agreement documents (agreed on/by, IP) | R9 | R9 | R9 | Finance + Documents | DOC-003 | Finance (+legal acceptance) |
| C45 | SR-16 | Master timetables, structures, cycles, overrides | R9 | R9 | R9 | Timetable | Timetable programme | Timetable ERP |
| C46 | SR-16 | Period types with an attendance flag | R9 | R9 | R9 | Timetable | — | Timetable ERP |
| C47 | SR-16 | Practical batches | R9 | R9 | R9 | Timetable | SIS-022 | Timetable ERP |
| C48 | SR-16 | Staff workload | R9 | R9 | R9 | Timetable/HR | — | Timetable ERP |
| C49 | SR-17 | Typed groups per year (house, club, project) with description | R11 | R11 | R11 | Students | OD-SIS-4 / D-17 | owner decision (named concept pilot) |
| C50 | SR-17 | Membership rules and bulk assign | R11 | R11 | R11 | Students | OD-SIS-4 | with C49 |
| C51 | SR-17 | Student responsibilities (captain, prefect) | R11 | R11 | R11 | Students | OD-SIS-4 | with C49 |
| C52 | SR-17 | Staff mentors of a group | R11 | R11 | R11 | Students + HR | OD-SIS-4; current-staff seam (rule 108) | with C49 |
| C53 | SR-17 | Participation badges and schedules | R11 | R11 | R11 | Students | OD-SIS-4 | with C49 |
| C54 | SR-17 | Gender split / untagged-by-gender analysis of groups | R7 | R7 | R7 | — | gender analytics not adopted | never |
| C55 | SR-17 | Finance/store "groups" (fee targeting) | R9 | R9 | R9 | Fees | fee structures | Finance |
| C56 | SR-18 | Group activities with participation and attachments | R11 | R11 | R11 | Students | follows OD-SIS-4 | with C49 |
| C57 | SR-18 | Co-curricular skill grading per term | R9 | R9 | R9 | Examinations | E40 co-scholastic | Examinations |
| C58 | SR-19 | Derived family-circumstance cohorts (25 system tags) | R7 | R7 | R7 | — | profiling of children (D-15) | never |
| C59 | SR-20 | Student fee segments | R9 | R9 | R9 | Fees | fee structures per year/Grade/Campus | Finance |
| C60 | SR-21 | Per-Student document records | R11 | R1 | R1 | Students | SIS.EXP-3 | — |
| C61 | SR-21 | Per-class document checklist grid | R11 | R11 | **R1** | Students | students.document_compliance (SIS 11.5, states only) | — |
| C62 | SR-21 | Birth certificate / previous school record / report card categories | R11 | R4 | **R1** | Students | birth_certificate added (SIS 11.5); prior school record covers TC/report card | — |
| C63 | SR-21 | Immunisation certificate | R8 | R8 | R8 | — | health data | legal |
| C64 | SR-21 | Student photograph document | R8 | R8 | R8 | — | children's images (DOC-002) | legal |
| C65 | SR-21 | Document viewer | R11 | R3 | R3 | Students | attachment download, re-authorized, audited, MFA | — |
| C66 | SR-22 | Profile photographs and bulk photo download | R8 | R8 | R8 | — | DOC-002, ADR 0075 §10.2 | legal |
| C67 | SR-23 | Class-wide family contact list | R11 | R11 | R11 | Guardians | SIS-020: purpose, permission, review, audit | owner decision |
| C68 | SR-23 | Column picker over ~150 personal fields | R7 | R7 | R7 | — | not adopted | never |
| C69 | SR-24 | Section capacity, seats and overage | R11 | R11 | **R1** | Students/Academic | students.section_capacity (SIS 11.3, OD-SIS-6) | — |
| C70 | SR-24 | Operational Section strength | R11 | R11 | **R1** | Students | one placement predicate (SIS 11.3) | — |
| C71 | SR-24 | Opening/closing and month-wise strength trends | R9 | R9 | R9 | Analytics | ADR 0040 | Analytics |
| C72 | SR-24 | Gender and age matrices | R7 | R7 | R7 | — | D-15 | never |
| C73 | SR-24 | Vacancy against the admissions pipeline | R11 | R11 | **R4** | Students + Admissions | capacity and applications reports exist separately | join is analytics-adjacent |
| C74 | SR-24 | Staff wards counts | R7 | R7 | R7 | — | profiling (SR-19) | never |
| C75 | SR-25 | Family block on the Student profile | R5 | R1 | R1 | Students | SIS.EXP-1 | — |
| C76 | SR-25 | School-wide sibling list | R11 | R11 | **R1** | Students/Guardians | students.siblings (SIS 11.2, names only) | — |
| C77 | SR-25 | Dues by family | R9 | R9 | R9 | Finance | D-14 | Finance/Analytics |
| C78 | SR-26 | Strength audit with receivables by grade | R9 | R9 | R9 | Finance + Analytics | D-14 | Finance |
| C79 | SR-27 | Branch-wise strength across the chain | R9 | R9 | R9 | Analytics (cross-School) | ADR 0080 §13.4 | Analytics |
| C80 | SR-28 | Biometric/RFID punch and device logs | R8 | R8 | R8 | Attendance + Integrations | HRX-L2, RFID privacy | legal + adapter |
| C81 | SR-28 | WhatsApp absence messages | R9 | R9 | R9 | Communications | E28 channels | Communications (+legal) |
| C82 | SR-29 | A Student's attendance history | R1 | R1 | R1 | Attendance | /app/attendance/students/{id} | — |
| C83 | SR-29 | Attendance not marked / staff marking compliance | R2 | R2 | R2 | Attendance | register status submitted/pending/not submitted | — |
| C84 | SR-29 | Late comers | R2 | R2 | R2 | Attendance | late exceptions in the register | — |
| C85 | SR-29 | Day-wise consolidated / session-wise grid | R9 | R9 | R9 | Attendance | daily model PV1-19 / ATT-08 | Attendance |
| C86 | SR-29 | Period-wise and subject-wise views | R4 | R4 | R4 | Attendance | registers per timetable entry; no subject view | Attendance |
| C87 | SR-29 | Monthly / term percentages and averages | R6 | R6 | R6 | Analytics | ADR 0040 rates gate | after Analytics gate |
| C88 | SR-29 | Gender / transport / residential attendance views | R7 | R7 | R7 | — | D-15 | never |
| C89 | SR-30 | Manual monthly attendance totals entry/upload | R7 | R7 | R7 | — | no evidence (registers are evidence) | never |
| C90 | SR-31 | Parent leave intimation (dates, type, attachment) | R11 | R11 | R11 | Attendance + Portal | D-16 | owner decision |
| C91 | SR-31 | Leave types with notification targets | R11 | R11 | R11 | Attendance | D-16 | with C90 |
| C92 | SR-31 | Free-text leave remarks (illness) | R8 | R8 | R8 | — | health data in free text | never as free text |
| C93 | SR-31 | Leave report | R11 | R11 | R11 | Attendance | D-16 | with C90 |
| C94 | SR-32 | Class and subject teacher lists | R2 | R2 | R2 | Teaching Assignments | V11.2 directory | — |
| C95 | SR-33 | Field change and activity logs | R3 | R3 | R3 | Compliance | history names only, audit log; no values or IP | — |
| C96 | SR-34 | Withdrawal fee refunds with approval | R3 | R3 | R3 | Payments | ORG.8C correction workflow | — |
| C97 | SR-35 | Suspension log | R8 | R8 | R8 | — | discipline data, DPO first | legal |
| C98 | SR-36 | School-wide Section/Campus movement history | R4 | R1 | R1 | Students | students.movements (SIS.EXP-2), export (11.4) | — |
| C99 | SR-37 | Promoted list | R2 | R1 | R1 | Students | movement register + rollover | — |
| C100 | SR-37 | Financial promotion / promotion pending fee due | R7 | R7 | R7 | — | never fee-gated | never |
| C101 | SR-37 | Promotion revert | R10 | R10 | R10 | Students | placement correction paths | — |
| C102 | SR-38 | Principal diary | R7 | R7 | R7 | — | SIS-033 | never |
| C103 | SR-39 | Admission applications operational list | R4 | R4 | **R1** | Admissions | admissions.applications (SIS 11.2), export (11.4) | — |
| C104 | SR-39 | Admission revenue, discount, collection analytics | R9 | R9 | R9 | Analytics + Finance | D-14, ADR 0040 | Finance/Analytics |
| C105 | SR-39 | Source / conversion funnel analytics | R9 | R9 | R9 | Analytics | ADR 0040 | Analytics |
| C106 | SR-40 | Student hobbies and talents | R6 | R6 | R6 | Students | POST_V1 (LRN-009) | post-v1 after classification |
| C107 | SR-40 | Parent hobbies and talents | R7 | R7 | R7 | — | no School purpose | never |
| C108 | SR-41 | Leaving / Transfer certificate issue with register and serial number | R11 | R4 | R4 | Students | SIS.EXP-4; production legally gated (ADR 0081) | legal wording review |
| C109 | SR-41 | Certificate requisition form | R11 | R2 | R2 | Students | eligibility from the recorded exit | — |
| C110 | SR-41 | Deposit-refund bank details on the requisition | R7 | R7 | R7 | — | rule 91 | never |
| X01 | export | CSV export of operational reports | R5 | R5 | **R1** | Students (SIS 11.4) | governed export, students.export/admissions.export | — |
| X02 | export | Print view of operational reports | R5 | R5 | **R1** | Students (SIS 11.4) | governed print | — |
| X03 | export | Excel (XLSX) export | R5 | R5 | **R6** | Students | deferred in D-11 | low value over CSV |
| X04 | export | Downloadable PDF of reports | R5 | R5 | **R6** | Students | deferred in D-11; print-to-PDF exists | low value over print |
