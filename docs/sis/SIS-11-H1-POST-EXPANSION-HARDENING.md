# SIS 11.H1 — Post-Expansion Hardening, Test-Parity & Concurrency Closure

Status: **IMPLEMENTED (local DDEV)** · 2026-10-31 · branch `integration/sis-11-h1`

| Item | Value |
|---|---|
| Baseline | `main = c9d9cd9f` (the docs-only SIS 11.R1 publication) |
| Audited product baseline | `c4f8cae1` |
| Source | `SIS-11-R1-MID-EXPANSION-AUDIT.md` (§8.1–§8.6, findings L-1 to L-4) |
| Scope | hardening only |
| New capability | none |
| New feature table | none |
| Core SIS score | 96/100, not rescored |

## 1. Product findings

Each finding was reproduced on the unfixed code with a test that failed
before the change and passes after it. All four are latent defence-in-depth
gaps: **no seeded role or route exercised any of them.**

### L-1 — Applicant document integrity

**Before:**
- `applicant_documents.document_id` had **no foreign key**. A forged record
  naming a nonexistent Document was accepted (reproduced).
- `documents_id_applicant_school_unique (id, applicant_id, school_id)` was
  referenced by nothing. It could not serve as an FK target, because
  conversion re-points a Document's owner to the Student.

**After** (migration `2027_01_22_090000_harden_applicant_document_integrity`):
- `documents_id_school_unique (id, school_id)` plus
  `applicant_documents_document_fk (document_id, school_id)` → documents,
  ON DELETE CASCADE. This is the same contract as `student_documents`:
  Documents are removed only by a retention purge, and a record goes with
  its Document.
- `trg_applicant_documents_document_owner_guard` (BEFORE INSERT): a new
  record must name a Document owned by its **own Applicant** in its own
  School. After that the identity is immutable (the existing guard), and a
  move never changes it.
- The dead owner-specific unique key is dropped.
- **Migration safety:**
  - Existing rows are verified first; the migration refuses to run on any
    orphan or foreign row.
  - Nothing is backfilled.
  - The rollback proof is IDENTICAL, and `verify-database` reports failed=0.

**Retention follow-ups:** the new reference is classified in
`StudentRetentionClassificationTest`, and it is excluded from the Student
core `documents` blocker because the cascade handles it.

### L-2 — Applicant Documents authorization

**Before:** `DocumentService::createForApplicant`, `archiveForApplicant`
and `DocumentReadService::applicantContent` checked nothing themselves,
although their docblocks claimed a floor. An actor without
`admissions.documents.*` reached them directly (reproduced). Upstream,
`ApplicantDocumentService` authorized correctly.

**After:** `ApplicantDocumentFloor` makes each entry point refuse an actor
who holds the capability **nowhere** in the School. `archiveForApplicant`
now takes the actor.

**Split of authority** (documented in the floor class and in ADR 0012):
- Documents decides the capability floor.
- Admissions decides the application's Campus scope; out of scope is
  NOT FOUND.

No grant changed.

### L-3 — Converted-application retention concurrency

**Before:**
- `ConvertedApplicationRetentionService::purge` plucked the leaving
  applicants, then deleted them.
- A new application created in between made the unit fail with a
  `QueryException` (reproduced with the two-process harness).

**After:**
- The released applicants are locked first, with `RetentionLocks`, the
  lock-only definer, FOR UPDATE.
- The "no other application" re-check is then read as a new statement.
- A racing application either committed before the lock (the re-check sees
  it, and the applicant is kept) or waits until the unit commits.
- **Safe drift is skipped; it never fails the unit.** Real database errors
  still surface through the unit's error count.
- **Proof:**
  `AdmissionsGuardianRetentionConcurrencyTest::a_new_application_racing_the_converted_purge_keeps_its_applicant`.
  The contender is observed **blocked on the lock**, not timed with sleeps:
  - with the fix: `purged:applicant-kept`, and the new application survives;
  - without the fix: `rejected:QueryException`.

### L-4 — Verification composition

**Before:** `StudentDocumentService::decide` required `verify` and
whole-School `students.view`, but not `students.documents.view`. A
constructed verify-only member could verify (reproduced).

**After:** both `students.documents.view` and `.verify` are required, as
whole-School Gate checks.

Role matrix:

| Holder | Outcome |
|---|---|
| School Administrator, Principal | allowed |
| view without verify | 403 |
| verify without view | 403 |
| Teacher, Admissions Officer | refused (existing tests) |
| another School | 404 (existing tests) |

### Campus-scoped Highly Sensitive applicant files (unchanged by design)
- `admissions.documents.*` still opens applicant files only on the holder's
  own Campus.
- Proven: same-Campus 200, other Campus 404, direct record id from another
  application 404, another School 404.
- After conversion, admissions staff see a count only. The Student's
  documents page returns 403 to them.

## 2. Stale-update protection (four surfaces)

Every surface uses the existing version-digest pattern, compared under the
row lock. A stale or forged value is a **409** (web: the `refusal` banner;
API: the error envelope with `error.code`). A missing value is a **422**.

| Surface | Token | Paths | 409 code |
|---|---|---|---|
| Section edit (including capacity and status) | `SectionVersion` | web `PUT /app/school-setup/sections/{id}` (edit form and status toggle), API `PATCH …/sections/{id}` | `SECTION_CHANGED` |
| Student–Guardian relationship edit | `StudentGuardianRelationshipVersion` (includes `is_primary`) | web `PUT /app/relationships/{id}`, API `PATCH …/student-guardian-relationships/{id}` | `GUARDIAN_RELATIONSHIP_CHANGED` |
| API Student PATCH | `StudentProfileVersion` (existing, keyed) | `PATCH …/students/{id}` | `STUDENT_PROFILE_CHANGED` |
| API Guardian PATCH | `GuardianProfileVersion` (existing) | `PATCH …/guardians/{id}` | `GUARDIAN_PROFILE_CHANGED` |

- **API responses** now carry `version`.
- **API contract** (`school-os-api.yaml`):
  - `version` is required on the four PATCH bodies;
  - a new `GuardianUpdateInput` (create keeps `GuardianInput`);
  - 409 responses documented;
  - shared types regenerated.
- **Relationship edits** never write `is_primary`; an extra one in the
  payload is ignored. A form opened before the primary Guardian changed is
  stale, so no edit can create an impossible primary state.
- **Not changed:** the database authorities (unique indexes, locks,
  foreign keys, RLS).

## 3. Test parity and harness

- **`APP_URL`:**
  - `phpunit.xml` forces `APP_URL=http://localhost:8000` (the CI value;
    rule 79 `force="true"`).
  - The 15 hard-coded assertions now derive from `config('app.url')`.
  - The Identity suite under canonical DDEV: **18 failed → 0 failed
    (199/199 passed)**.
- **Object storage** (`Tests\Concerns\RequiresObjectStorage`):
  - covers the 11 real-S3 tests, three classes in `setUp` and five single
    methods;
  - locally without MinIO they are **explicit skips**, with the reason
    "requires the real S3-compatible object storage integration service …";
  - in CI (`CI`/`GITHUB_ACTIONS`) an unreachable store **fails** the test,
    so CI keeps executing them against versitygw;
  - the other 45 tests in those files run locally as before.
- **Same-second audit ordering:**
  - **Fixed (3):** `CampusProfileTest` and
    `TeacherCurriculumDeliveryAccessTest` order by the UUIDv7 `id`;
    `TeacherStudentMarkAccessTest` selects each event by its paper.
  - **Searched and classified, unchanged:** 11 statements in 11 files
    (`latest()`, `orderBy`/`orderByDesc` on `occurred_at`/`created_at`).
    Each involves one matching event or an order-insensitive assertion.
    None has the defect pattern.
- **Checklist:** `docs/development/CROSS-CUTTING-CHANGE-CHECKLIST.md`,
  linked from CLAUDE.md, covers:
  - capability, role and user-reference changes;
  - table, route and retention-read changes;
  - Campus scope, Documents owner types, reports and stale tokens.

## 4. Documentation corrections

| Location | Correction |
|---|---|
| `DocumentService` / `DocumentReadService` applicant docblocks | now describe the enforced floor |
| `StudentReportRegistry` | no longer claims "never an export" |
| `AdmissionApplicationReadService` | the SORTS docblock sits on `SORTS` |
| `ConvertedApplicationRetentionService` | documents the lock-then-recheck |
| ADR 0012 | SIS 11.H1 amendment note |
| CLAUDE.md | rule 127 |

## 5. Findings closure

| Finding | Before | After | Status |
|---|---|---|---|
| L-1 applicant document integrity | no FK; dead unique key | same-School FK, insert-owner trigger, dead key dropped | **CLOSED** |
| L-2 applicant Documents floor | none in the entry points | `ApplicantDocumentFloor` in all three | **CLOSED** |
| L-3 converted purge concurrency | unit failed on a racing application | lock, then re-check; skip | **CLOSED** |
| L-4 verify without view | verify-only could decide | view and verify required | **CLOSED** |
| Audit timestamp risk | 3 order-dependent tests | id order / content selection | **CLOSED** (11 others classified) |
| `APP_URL` mismatch | 18 Identity failures under DDEV | 0 | **CLOSED** |
| Storage test classification | 11 hard failures locally | explicit skips locally; CI must run them | **CLOSED** |
| Section stale write | last-save-wins | `SectionVersion` | **CLOSED** |
| Relationship stale write | last-save-wins | `StudentGuardianRelationshipVersion` | **CLOSED** |
| API Student PATCH stale write | version optional | required | **CLOSED** |
| API Guardian PATCH stale write | version optional | required | **CLOSED** |
