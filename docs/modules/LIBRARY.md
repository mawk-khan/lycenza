# School OS — Library Module (Phase 10A)

Phase 10A is the first implementation checkpoint of Phase 10 / Phase 0K
(School Operations, `docs/roadmap/MASTER-ROADMAP.md`). This document
covers the Library module's foundation only — catalogue + physical
circulation. It does not cover, and is not a commitment to, the shape
of any later Phase 10 module (Transport, Inventory, Canteen, Hostel,
Health, Visitor/Safety).

## 1. Checkpoint scope

Prove, end to end, tenant-safe and capability-authorized:

1. Register a bibliographic Title.
2. Register one or more physical Copies of that Title.
3. Check out an available Copy to an eligible Student.
4. Check that Loan back in.
5. The database itself prevents two simultaneous active Loans on one
   Copy, and prevents any cross-School parent reference.
6. Both an administrative `/api/v1` surface and a session-authenticated
   Inertia UI expose this workflow.

No local Phase 10 planning document was found in this environment
(searched exhaustively — repo `docs/`, home directory, Claude Code's
paste-cache) despite explicit request. This checkpoint proceeds on the
roadmap-only scope above, per explicit instruction, and treats that
absence as a documented **assumption**, not a license to expand scope.
Every deferral in §12 follows directly from the checkpoint brief's own
explicit exclusions, not from the missing document.

## 2. Domain boundary

`apps/platform/app/Domain/Library/` — an independent bounded context,
`Domain/Application/Infrastructure/Http` layout
(`apps/platform/app/Domain/README.md`). **No** `App\Domain\Operations`
mega-domain was created, and none should be, per the checkpoint brief's
explicit instruction and the discovery audit's domain-boundary finding
(Health and Visitor/Safety in particular have a materially different
security posture from Library/Transport/Inventory/Canteen/Hostel — see
that audit's §F).

Library owns Library lifecycle/state only. Student (`App\Domain\Students\Infrastructure\Student`)
and, if a future checkpoint needs an issuing/receiving staff reference,
Employee (`App\Domain\HR\Infrastructure\Employee`) remain owned by
their existing domains — Library references them by composite FK, it
never duplicates their data or reimplements their lifecycle rules.

## 3. Catalogue-vs-copy modeling decision

**Option B (Title + Copy split), not Option A (one flat `library_items`
row).** A real School library routinely holds several physical copies
of the identical bibliographic title. Option A would force either (a)
duplicating title/author across N rows for N copies — an "editing copy
2 doesn't update copy 1's title" data-integrity hazard the moment more
than one copy of anything exists — or (b) collapsing multiple copies
into one row, making "how many copies, which ones are out" impossible
to represent per-copy. Neither is acceptable for the checkpoint's own
objective #2 ("physical copies/items can be tracked where required").

- **`library_titles`** — the bibliographic record. School-scoped (not
  Campus-scoped, see §4). Fields: `title` (required), `author`
  (nullable — not every catalogued item has one identifiable author),
  `isbn` (nullable, per the checkpoint brief's explicit allowance),
  `status` (active/inactive, rule 73). No uniqueness on `title` itself
  — distinct physical works (different editions, donated duplicates)
  may legitimately share a title string; the individually-identifying
  value lives on the Copy, not here. Deliberately **no** publisher,
  edition, publication year, genre, or external catalogue id — none
  are needed to prove the checkout/check-in workflow, and adding them
  now would be the speculative-field pattern CLAUDE.md rule 2 forbids.
  No external ISBN/metadata lookup of any kind.
- **`library_copies`** — one row per individually loanable physical
  object. Composite FK to `library_titles(id, school_id)`. `code`
  (not `accession_code` — named `code` specifically so
  `App\Support\NormalizesCode`/`NormalizesCodeInput` are reused
  verbatim, exactly like `Subject.code`/`Department.code`), unique per
  School, is the physical item's own accession identifier. `status`
  (active/inactive) is the ordinary reference-entity lifecycle
  (lost/withdrawn), **never** "currently on loan" — see §7 for why.
- **`library_loans`** — one row per checkout event (§8).

## 4. School/Campus ownership decision

`library_titles` is School-scoped only. `library_copies` carries an
**optional, nullable** `campus_id` (composite FK to `campuses(id,
school_id)`) — a bibliographic Title is the same regardless of which
Campus physically holds a copy of it, but a physical Copy is a real
object that may sit at one specific Campus in a multi-campus School,
mirroring exactly how `rooms` is Campus-scoped while
`subjects`/`grade_levels` are School-wide. A single-campus School
simply leaves it null; a multi-campus School can differentiate without
a future migration. `library_copies` deliberately does **not**
reference Academic Structure's `Room` — no requirement for "which room
holds this copy" exists in this checkpoint's objective, and the
checkpoint brief explicitly says not to couple Library to Room without
an actual requirement.

## 5. Schema

```
library_titles          library_copies                library_loans
─────────────           ──────────────                ─────────────
id (uuid pk)             id (uuid pk)                  id (uuid pk)
school_id (fk)            school_id (fk)                school_id (fk)
title                      library_title_id (composite FK) library_copy_id (composite FK)
author (nullable)          campus_id (nullable, composite FK) student_id (composite FK, cascade)
isbn (nullable)            code (unique per School)     status (active|returned)
status (active|inactive)   status (active|inactive)     checked_out_at
                                                          due_at
                                                          checked_in_at (nullable)
```

Every table: `BelongsToSchool`, `GeneratesUuidV7` (ADR 0019), RLS
enabled+forced via `App\Support\Tenancy\TenantRls`, `unique(id,
school_id)` for composite-FK targeting from children, real composite
FKs (never RLS/SchoolScope alone) against every School-owned parent.

**No Employee issuer/receiver reference.** `App\Support\Audit\AuditRecorder`
already captures the acting User for every checkout/check-in call
(§9) — resolving that to a specific Employee row would need a
User↔Employee link this checkpoint has no proven need for, and would
be exactly the speculative field CLAUDE.md rule 2 forbids. Addable
later, additively, if a real reporting need appears.

## 6. Reference-entity / historical-integrity rules

`library_titles`/`library_copies` use active/inactive (rule 73), never
hard-delete — no delete route exists for either. A `library_loans` row
referencing a since-deactivated Title/Copy remains fully valid and
readable; deactivation is never simulated by mutating historical Loan
data. Proven in
`tests/Feature/Library/LibraryLoanServiceTest::historical_loans_remain_valid_after_the_copy_and_title_are_deactivated`
and `LibraryCatalogueLifecycleTest::a_historical_loan_survives_the_referenced_title_and_copy_being_deactivated`.

## 7. Circulation lifecycle

`status` (active|returned) **plus** full timestamps
(`checked_out_at`/`due_at`/`checked_in_at`) — `status` is what the
partial unique index and "is this currently open" queries need; the
timestamps are what a historical record and a future due-date/overdue
need require. `due_at` is **required**, supplied directly by the
caller at checkout — no holiday-aware calendar, no grade-specific
period, no configurable renewal policy (checkpoint brief §13).

**Due date semantics (UAT-C1, UAT-LIB-01).** The only rule is the
database's `library_loans_due_after_checkout_check` (`due_at >=
checked_out_at`), so a loan due the same day is valid.

- **Web checkout form:** sends a calendar date. The controller reads it
  as "due back by the end of that day" in the School's timezone (23:59:59
  local, stored in UTC). A same-day loan is therefore due tonight, and a
  return on the due date is not overdue.
- **API:** sends a full timestamp, which is taken as given.
- **Validation:** `LibraryLoanService::checkout()` checks the rule itself
  before the INSERT. A due time before the checkout is
  `LIBRARY_DUE_BEFORE_CHECKOUT` (422; a `due_at` form error), never a 500.
- **Display:** loan dates are shown in the School's calendar.

Before UAT-C1 the form's date became 00:00 UTC. That is earlier than a
same-day checkout, so it reached the constraint as a 500; west of UTC it
would also have failed for "tomorrow". Proven by
`Tests\Feature\Library\LibraryCheckoutDueDateTest`.

**Copy availability is fully derived, never stored.** `LibraryCopy::isAvailable()`
= `status === 'active'` AND no `library_loans` row with `status =
'active'` referencing it. There is deliberately no `available`/
`checked_out` column on `library_copies` — a second, mirrored status
value would be exactly the dual-source-of-truth the checkpoint brief's
"prevents logically impossible circulation states" requirement warns
against.

### The core invariant

**One physical Copy must never have two simultaneous active Loans.**
Database-enforced via a partial unique index:

```sql
CREATE UNIQUE INDEX library_loans_one_active_per_copy
  ON library_loans (library_copy_id) WHERE status = 'active';
```

— the exact `academic_years_one_active_per_school`/
`student_enrollments_one_active_per_student_year` pattern already
proven in this repository, never an application-level
check-then-insert.

`App\Domain\Library\Application\LibraryLoanService::checkout()`
additionally takes `lockForUpdate()` on the Copy row before its own
availability check (mirroring `AcademicYearService::activate()`'s
lock-then-check-then-write shape) — this closes the common,
non-racing case cheaply and, because both a would-be racer and this
call target the SAME row, means PostgreSQL itself serializes any two
concurrent checkout attempts on that Copy: **proven empirically, not
assumed** (`tests/Feature/Library/LibraryLoanCheckoutConcurrencyTest.php`,
two genuinely separate OS processes), the losing process is caught by
its own post-lock `CopyNotAvailableException`, never the raw
`UniqueConstraintViolationException` path — that catch clause remains
the authoritative backstop for any future write path that does not
take this same lock (proven independently, single-process, via a
privileged raw INSERT bypassing the lock entirely, in
`tests/Feature/Postgres/LibraryLoansRlsIsolationTest::the_database_rejects_a_second_active_loan_for_the_same_copy`).

A returned Loan cannot be checked in twice: `checkIn()` uses a
conditional `UPDATE ... WHERE status = 'active'` (zero affected rows →
`LoanAlreadyReturnedException`), identical to
`AcademicYearService::activate()`'s conditional-update pattern. A
database `CHECK` constraint additionally makes `status = 'returned'`
structurally equivalent to `checked_in_at IS NOT NULL` — an impossible
"returned but no checked-in timestamp" (or vice versa) state cannot
exist at the row level at all.

**Check-in clock (2026-10-08; the Library counterpart of S3).**
`checkIn()` records `max(now, checked_out_at)`. If the returning node's
clock reads earlier than the stored checkout (a backward NTP/VM step, or
skew between the node that checked out and this one), the return is
recorded at the checkout instant instead of being refused with a 500 by
`library_loans_checkin_after_checkout_check`, which stays the authority.
Because `due_at >= checked_out_at`, such a return is never overdue and
never fined. `checked_out_at` is never rewritten.

## 8. Student eligibility assumption

This repository has no dedicated concept of "library borrowing
eligibility." The minimum existing Student state this checkpoint
requires is `App\Domain\Students\Infrastructure\Student::isActive()`
(`status === 'active'`) — the same status every other module already
treats as "is this Student a going concern at this School." No
configurable eligibility engine was built; `StudentNotEligibleException`
is thrown otherwise. This is a documented assumption, not a discovered
repository requirement.

## 9. Capabilities

| Capability | Grants |
|---|---|
| `library.catalogue.view` | Read Titles/Copies |
| `library.catalogue.manage` | Create/update Titles/Copies, (de)activate |
| `library.circulation.view` | Read Loans |
| `library.circulation.manage` | Check out, check in, use the checkout-form search endpoints |

Since OPF.4 a check-in also assesses an overdue fine (no extra capability), and three fine capabilities exist: `library.fines.view` / `.manage` / `.void` (School Admin only; see "Fines (OPF.4, ADR 0067)").

Mirrors the existing `academics.structure.*`/`academics.subjects.*`
and `hr.employees.*`/`hr.departments.*` split — catalogue and
circulation are independently gateable, matching the actor-category
design `docs/security/AUTHORIZATION.md` already anticipated (Librarian
named, unimplemented) before this checkpoint.

**Default grants**: `school_admin` and `principal` both hold all four,
matching the existing "day-to-day operational concern" parity pattern
already established for `students.*`/`guardians.*`/`enrollments.*` on
both roles (checking a book out to a Student is routine administrative
work, not a rare/high-blast-radius action). No new system role (e.g. a
dedicated "Librarian" role) was created — `docs/security/AUTHORIZATION.md`
itself states tenant-custom roles remain future work; a School wanting
a narrower Librarian-only grant already can via a custom role (proven
directly in the authorization test suite). *(SR.0 correction, 2026-10-09: a School cannot compose, create or configure a role — no runtime role writer exists (ADR 0059 §1), and tenant-custom roles are deferred (ADR 0063 T3). The fixed system catalogue in ADR 0071 provides `librarian` (catalogue, circulation and `library.fines.view` only; `library.fines.manage` publishes fine policy and stays off it, as does `library.fines.void`). The "proof" used test-only fixture roles.)* *(SR.3 update, 2026-10-09: implemented — the fixed production catalogue is seeded by `CapabilityAndRoleSeeder` and snapshot-pinned; ADR 0071 §25.)*

Enforcement is exclusively via `Gate::authorize('capability', ...)`
(the `capability:` route middleware for `/api/v1`,
`AuthorizesCapability` controller trait for the Inertia surface) — no
role-name branch exists anywhere in Library code.

## 10. Audit

Every checkout and check-in calls `AuditRecorder::school()`:
`library.loan.checked_out` / `library.loan.checked_in`, metadata
limited to ids/codes/dates (no Sensitive data beyond what the actor
already sees in the response — Student name/number are Sensitive-tier
per `docs/security/DATA-CLASSIFICATION.md` but are not placed in audit
metadata, only the Student id). Title/Copy creation and update also
audit (`library.title.created`/`.updated`, `library.copy.created`).

## 11. Events

**None emitted in this checkpoint.** The checkpoint brief explicitly
forbids an event for routine catalogue CRUD, and explicitly defers any
`LibraryLoanOverdue`/`BookOverdue` event (overdue is time-derived, not
a lifecycle mutation — detecting it needs scheduled evaluation,
idempotent recurrence, and a Communications integration, none of which
this checkpoint's objective requires). No consumer exists yet for a
checkout/check-in event either. Adding one now would be exactly the
speculative-infrastructure pattern CLAUDE.md rule 2 forbids — this is a
deliberate choice, not an oversight; see §12.

## 12. API surface

`/api/v1/schools/{schoolId}/library-titles`,
`/library-titles/{id}/copies`, `/library-copies/{id}`,
`/library-loans`, `/library-loans/{id}`,
`/library-loans/{id}/check-in` — capability-gated, versioned, documented
in `packages/contracts/openapi/school-os-api.yaml` (`LibraryTitle`,
`LibraryCopy`, `LibraryLoan` schemas + their `*Input` variants),
generated into `packages/shared-types`. Checkout (`POST
/library-loans`) carries the `idempotent` middleware — the checkpoint
brief's own explicit flag: a network retry must never risk a duplicate
checkout attempt on the same Copy. Title/Copy creation deliberately do
**not** use `idempotent` — a duplicate retry gets a clean `422` from
the unique `code` constraint instead, matching the existing
Subject/Campus precedent for exactly this reasoning. Check-in
similarly relies on its own conditional-update rejection
(`LoanAlreadyReturnedException`), not idempotency middleware.

## 13. Administrative UI

`resources/js/Pages/App/Library/{Catalogue,Circulation}/*.vue` —
catalogue browse/search/create/show (with inline copy registration),
circulation active-loans list/checkout-form (live copy/student search)/
check-in. Reuses `EmptyState`/`Pagination`/`StatusBadge` (existing
components; `StatusBadge` was **not** extended with a `returned` status
value — Loan status is rendered with a small inline conditional
instead, to avoid touching a shared, already-tested component for a
single new caller). No Student/Guardian portal, no mobile API, no
public catalogue — all explicitly out of scope (§14).

## 14. Explicit non-scope (this checkpoint)

- Fines, fine balances, payment tables, ledger postings, any Finance/Fees
  integration (Finance was not yet on `main` when this checkpoint closed).
  Overdue fines are now OPF.4 (ADR 0067 §30; implemented 2026-10-06). See
  "Fines (OPF.4, ADR 0067)" below. Fine balances, payment tables and
  ledger postings stay in Finance/Fees, never in Library.
- Reservations, holds, waiting lists, renewals, recurring loans,
  inter-library transfers.
- Documents module integration (no new owner arm added to the
  `documents` exclusive-arc schema) — deferred exactly as the
  discovery audit identified.
- Guardian/Student-facing portal, mobile API, public catalogue,
  advanced analytics dashboard.
- External ISBN/catalogue metadata lookup, Elasticsearch/Meilisearch/
  Algolia/vector search — plain PostgreSQL `ilike` covers this
  checkpoint's catalogue/checkout-form search needs.
- `LibraryLoanOverdue`/`BookOverdue` events and any overdue
  notification via Communications.
- Employee issuer/receiver tracking on a Loan.
- A dedicated "Librarian" system role.

## 15. Security review (see also the closure report)

Findings, all resolved before closure:

- **P1, found and fixed**: `LibraryCirculationController::searchAvailableCopies()`/
  `searchStudents()` (the checkout form's live-search JSON endpoints)
  initially carried no capability check at all — any authenticated
  School member, regardless of Library capability, could have
  enumerated Student name/number (Sensitive-tier) and the available
  Copy list. Fixed by adding `library.circulation.manage` (the same
  precedent `StudentGuardianRelationshipController::searchGuardians()`
  already establishes and this checkpoint's own code failed to
  replicate on first pass). Regression tests added:
  `LibraryAdminUiTest::a_member_without_any_library_capability_cannot_use_the_checkout_search_endpoints`
  and `::a_circulation_manage_member_can_use_the_checkout_search_endpoints`.

No other material finding — see the closure report's §J for the full
checklist (tenant escape, cross-School FK, IDOR, mass assignment,
audit-data leakage, SQL injection, concurrency, idempotency bypass,
hard-delete of historical records) and its evidence.

## 16. Test evidence

See the Phase 10A closure report for exact counts. Summary: RLS
isolation + composite-FK cross-School denial (3 tables), authorization
allow/deny/wrong-School (all 4 capabilities), catalogue lifecycle
(create/update/deactivate/reactivate/list-filtering/historical-survival),
circulation lifecycle (checkout/check-in/double-checkout-prevention/
double-checkin-prevention), a real two-OS-process concurrency proof,
`/api/v1` contract tests including idempotency replay/conflict/
capability-revocation-on-replay, and Inertia UI tests for every page
and action.

## Retention (E21.3B, 2026-10-02)

A Student's **returned** loans are D7 operational Student history: they are
deleted 7 calendar years after the Student's final exit
(`StudentRetentionEligibility`), by `platform:student-retention-prune`
through `App\Domain\Library\Application\Retention\LibraryLoanRetentionService`,
one Student per transaction under the Student-row lock.
- An **unreturned** (`active`) loan is never deleted on age, and it keeps
  the Student's core record.
- A re-entry (new Enrollment or reactivation) restarts the clock.
- Titles and copies are School inventory and stay. Since OPF.4 a returned
  loan with a Library fine is kept by that fine (Finance evidence,
  `dependency_blocked`); other loans are unaffected.

Project-adopted, pending legal ratification (`docs/security/E21-RETENTION-DETERMINATION.md`
§5.6). Holds (`RETENTION_HOLD_SCHOOL_IDS`) keep everything; `--dry-run`
counts with the same rule.

## Fines (OPF.4, ADR 0067)

**Implemented by OPF.4 (2026-10-06; ADR 0067 §30).** As built:
- **Policy:** `library_fine_policies`, immutable numbered versions per
  School; the highest governs. An active version names the FEE fee head,
  a daily rate, grace days and an optional cap; a disabled version switches
  fines off.
- **Assessment:** only at check-in (`LibraryLoanService::checkIn()`, same
  transaction, loan row lock held), once per loan
  (`library_fines_one_per_loan_kind`). Overdue days = each started 24 hours
  after `due_at`; chargeable = overdue − grace; amount = chargeable × rate,
  capped. No assess endpoint.
- **Charge:** one Student event charge through FEE's `FeeSourceChargeService`
  (never `ChargeService` directly), on the fee head's accounts, for the
  School's active academic year.
- **Not applicable** (no or disabled policy, within grace, no active year,
  fee head inactive) is audited and never fails the return.
- **Void:** `POST /library-fines/{fine}/void` (`library.fines.void`) records
  an insert-only void and cancels the unpaid, unwaived charge in one
  transaction; Finance's generic cancel cannot cancel a fine's charge.
- **Capabilities:** `library.fines.view` / `.manage` / `.void` (School Admin
  only by default); check-in stays `library.circulation.manage`.
- **Legal:** E34 is still LEGAL_REVIEW_REQUIRED; production use waits.

The contract:
- **Library-owned versioned fine policy.** Rate, grace and cap semantics,
  as immutable versions, snapshotted on each assessment. Library calculates
  the amount.
- **Not the tuition late-fee policy.** FEE's late-fee rules
  (`fee_late_fee_rules`, structure-scoped, legal item E31) are not the
  Library fine engine.
- **Event charge through FEE.**
  - An overdue loan is assessed once per loan and fine kind, only at
    check-in (`library.circulation.manage`). There is no explicit
    assessment path or capability (ADR 0067 §30.3).
  - It goes through FEE's event-charge seam (which calls
    `ChargeService::assess()`), with a Library-owned link row (unique per
    loan and kind, unique charge) created in the same transaction, under
    the loan-row lock.
  - There is no daily accumulation of separate charges.
  - The ledger destination is a fee head / account mapping, validated as
    FEE validates accounts.
- **Corrections:**
  - a FEE `waiver` concession (maker/checker);
  - an explicit Library void path that voids the fine evidence and cancels
    the charge while it is cancellable and unpaid.

  No refunds.
- **Excluded:** lost or damaged loan states and replacement-cost charging.
  No new loan states are added.
- **Retention.** The link rows are Finance evidence (D8). They are
  registered with the anchors and the catalog, and the Finance retention
  expiry function is amended to handle them (as for `canteen_orders`). While
  a fine exists, its loan stays `dependency_blocked`.
- **Legal.** New register item **E34** (Library fine / penalty regulation),
  separate from E31. Development is permitted; production use waits for a
  qualified answer.

### Browser surface (SWC.3, 2026-10-14, ADR 0073 §19)

**Fine policy page.** `/app/library/fine-policy` (Operations → Library →
Fine policy, `library.fines.view`) shows the current version and the
immutable history. Publishing (`library.fines.manage`) carries the version
the form showed. A repeat or stale publish is refused
(`LIBRARY_FINE_POLICY_CHANGED`, 409, checked under the advisory lock;
§19.4). The `/api/v1` publish is unchanged.

**Loan page.** `/app/library/circulation/{loan}` is linked from each
Circulation row (`library.circulation.view`):
- with `library.fines.view`, it shows the loan's fine: the stored amount,
  days, policy version, and the void status and reason;
- with `library.fines.void`, it adds a reasoned void behind a confirmation.

A check-in that assessed a fine lands on that page.

**No School-wide fine list (owner decision X2).**

**Gaps and gates:**
- Older returned loans are reachable only by URL; recorded for SWC.5.
  *(2026-10-14, SWC.5: resolved. Circulation now has Active / Returned
  tabs, the `status` filter `/api/v1` already offered under
  `library.circulation.view`; ADR 0073 §20.2. It lists loans only, never
  fines (X2).)*
- Production stays gated by E34 (ADR 0058).

## Date display (UAT-C6, 2026-10-16, ADR 0075 §19)

A loan's checked-out, due and returned dates are sent to the pages as the
**School's calendar dates** (`YYYY-MM-DD`, UAT-C1).

**How they are shown.** The pages use `formatCalendarDate()`
(`resources/js/datetime.ts`), which shows "16 Oct 2026" and never shifts
the day.

**What went wrong (RA-LIB-DATES).** UAT-C4 had passed these dates through
the instant formatter, which read the bare date as midnight UTC:
- an invented "5:30 am" in India;
- the previous day for a School west of UTC.

**Guard:** `Tests\Feature\Library\LibraryCalendarDateDisplayTest` checks
this.

**Unchanged:** stored `due_at` (end of the School day), overdue status
and fines.

## Campus scope deferred (ORG.7D-3, 2026-10-19, ADR 0076 §39.5)

Library is NOT Campus-adopted. Reasons:
- loans carry no Campus;
- a Copy's Campus is nullable and can change while it is on loan;
- cross-Campus lending is undefined.

Adoption needs a loan-Campus rule and Copy-Campus immutability while on
loan. The Copy search's unescaped LIKE stays recorded (R8). A Campus grant
confers nothing here.

## Campus contract (ORG.7D-4, 2026-10-19, ADR 0076 §40.10)

Library is still NOT Campus-adopted. The contract for the implementation
wave is frozen.
- **Titles and copies:** titles are School-shared. A copy is a Campus
  resource, and a copy with no Campus is whole-School only.
- **Loans:** a loan keeps the copy's Campus at checkout, so a migration is
  required (`library_loans.campus_id`, a matching insert trigger and
  immutability). A copy's Campus can no longer move while it is on loan.
  Historical access never follows a Student's current Campus.
- **Lending:** a Campus-scoped librarian lends only when their scope covers
  the copy's Campus and the borrower is operational on that Campus, proven
  under lock at checkout. Every refusal is one generic
  `LIBRARY_CHECKOUT_UNAVAILABLE`.
- **Adoptable keys:** only `library.circulation.*`. The catalogue and fines
  stay whole-School.
- **Owner decisions:**
  - the check-in fine side effect needs a high-risk approval or
    reclassification;
  - the copy-move policy;
  - a web Campus field;
  - cross-Campus returns.

## Campus-scoped circulation (ORG.7D-5, 2026-10-19, ADR 0076 §41)

`library.circulation.view/.manage` are Campus-adopted. The catalogue
(`library.catalogue.*`) and fines (`library.fines.*`) stay whole-School.
- **Loan Campus.** Every loan keeps its copy's Campus at checkout
  (`library_loans.campus_id`, immutable, database-enforced). A copy on an
  active loan cannot change Campus.
- **Campus-scoped librarians** lend only their Campuses' copies, to current
  Students of THAT Campus (proven under lock at checkout). They return and
  see loans by the loan's own Campus, never the borrower's current one.
  Every refusal is `LIBRARY_CHECKOUT_UNAVAILABLE`, and other Campuses'
  records are not found.
- **School-wide (no-Campus) copies** are whole-School only.
- **Copy Campus administration.** Whole-School catalogue administrators set
  and move a copy's Campus on the title page (refused while on loan, active
  Campus only).
- **Fines.** A Campus-scoped check-in still assesses the overdue fine
  (owner-approved bounded side effect); the librarian gains no Fees or
  fines authority.

## Fine void assurance (ORG.8E, 2026-10-22, ADR 0076 §63)

- Voiding a fine needs a listed reason (assessed in error, loan record
  corrected, returned before it was due, other), the note, a fresh
  authentication code and the current `library.fines.void` authority. The
  fine's charge is cancelled through Fees, once. A waiver is a Fees
  concession, never a void. Production stays gated by E34.

## ORG.8 closure audit (ORG.8F, 2026-10-22, ADR 0076 §64)

Library fine void assurance is complete for v1 (listed reason, note, fresh
code, current authority, at most once, through the Fees source seam).
Production execution stays gated by E34.

## Fine void and Guardian conflict (ORG.8G, 2026-10-22, ADR 0076 §65)

A fine void ends its Charge through the assured Charge core, so it is now
also refused for a staff member who is a linked Guardian (active or revoked
link) of the Student, in the service and in the database.

## Circulation filters (ORG.9C-1, 2026-10-23, ADR 0076 §69)

The circulation list (`library.circulation.view`, scoped by each loan's stored Campus, ORG.7D-5) now offers:
- an **All loans** view;
- a School-local **date range**: issued, or returned on the returned tab;
- a **Student** name or number (wildcards are literal);
- a **Campus**, which only narrows the reader's scope. The options are the reader's in-scope Campuses only.

It stays read-only and is still never a fine list (owner decision X2). Retention-expired loans are gone, not resurrected.

**Stopped as transactional or configuration (PV1-07, separate decision needed):**
- per-borrower loan limits;
- a default loan period;
- renewal;
- web title editing;
- category, language and publisher masters.
