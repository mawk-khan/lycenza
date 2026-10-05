# School OS — Hostel Module (Phase 10D)

Hostel is an independent bounded context proving School hostel
residency foundation: a Hostel/Room/Bed directory (structural reference
records) and a Student residency assignment lifecycle. It is NOT put
inside Transport, HR, Students, or a generic `Operations` mega-domain —
the same independence Library (10A), Transport (10B), and Visitor (10C)
established structurally.

## 1. Checkpoint scope

Proven in this checkpoint:

- Hostel directory (belongs to a Campus): create, list/show, update,
  activate/deactivate.
- HostelRoom directory (belongs to a Hostel): create, list/show,
  update, activate/deactivate.
- HostelBed directory (belongs to a HostelRoom): create, list/show,
  update, activate/deactivate.
- Student residency assignment lifecycle: explicit assign, explicit
  end. No silent transfer.
- One active residency per Bed AND one active residency per Student,
  both database-enforced.
- Historical residency survival after Bed/Room/Hostel/Student
  deactivation.
- Cross-School rejection at the database level for every reference.
- Row-Level Security on every new table.
- Capability-based authorization (no role-name checks).
- Admin JSON API (`/api/v1`) + Inertia UI.
- Audit trail for every significant state change.

Explicitly NOT in scope: any Fees/billing/deposit/payment-status,
warden/staff management, meal plans, visitor/curfew/disciplinary
rules, Health/Safety/medical data, Documents/Communications
integration, Guardian/Student self-service. See §16 "Explicit
non-scope" for the full list and reasoning.

**P2 assumption**: no local Phase 10 / Hostel planning document was
found in this repository, the user's home directory, or any accessible
paste-cache at the time this checkpoint began (the same search
performed, and the same conclusion reached, for Phase 10A/Library,
10B/Transport, and 10C/Visitor). This checkpoint implements only the
narrow structure-and-residency foundation explicitly specified in the
Phase 10D checkpoint brief itself — it does not invent a complete
commercial hostel-management product.

## 2. Domain boundary

`apps/platform/app/Domain/Hostel/` — `Application/Infrastructure/Http`,
the same layered shape every other module in this repository uses (no
`Domain/` or `Events/` subdirectory was needed — Hostel has no
value-object/domain-event layer distinct from its four Eloquent models
and one Application service).

Hostel OWNS: `Hostel`, `HostelRoom`, `HostelBed`,
`HostelResidencyAssignment`.

Hostel REFERENCES, never duplicates or owns:

- `Campus` (Campus/Academic Structure domain) — a Hostel belongs to
  exactly one Campus; Hostel never duplicates Campus name/location
  data.
- `Student` (Students domain) — a residency assignment references a
  Student by id; Hostel never duplicates Student name/guardian/
  academic data.

No bidirectional coupling: Campus and Students have zero knowledge of
Hostel. Hostel depends on them; they never depend on Hostel.

## 3. Domain model

### Hostel (directory/reference entity)

`school_id`, `campus_id` (required — belongs to exactly one Campus),
`code`, `name`, `status` (active/inactive, no delete endpoint).
Deliberately minimal: no fees, warden/staff assignment, meal-plan,
gender-policy, curfew, visitor-policy, or medical-facility fields —
none of these were an explicit product requirement for this checkpoint.

### HostelRoom (directory/reference entity)

`school_id`, `hostel_id`, `code`, optional `floor_or_block` (bounded
free text, no structured `HostelBlock` table), `status`
(active/inactive). **Deliberately does NOT store a capacity column** —
see §6.

Not a reuse of Academic Structure's `Room` model — that model is
explicitly a teaching-space concept (`room_type:
classroom|laboratory|auditorium|library|sports|other`), unrelated to
residential capacity/occupancy. Reusing it would have coupled two
unrelated concerns onto one table; HostelRoom is a fully independent
model with no relationship to Academic Structure's `Room` at all.

### HostelBed (directory/reference entity)

`school_id`, `hostel_room_id`, `code`, `status` (active/inactive).
**Deliberately carries no `student_id`/occupant column** — see §6.
`isAvailableForAssignment()` derives availability from its own status
AND its Room's status AND that Room's Hostel's status (§9).

### HostelResidencyAssignment (historical transaction entity)

`school_id`, `student_id`, `hostel_bed_id`, `status`
(`active`/`ended`), `starts_on`, `ends_on` (nullable). See §9 for the
lifecycle. **Deliberately does not duplicate `room_id`/`hostel_id`/
`campus_id`** onto this row — the full hierarchy (Bed → Room → Hostel →
Campus) is always resolved via relationship joins, exactly like
Hostel's capacity/occupancy decisions in §6.

## 4. Security / data classification

Added an explicit **Hostel residency** row to `docs/security/
DATA-CLASSIFICATION.md`. For the fields this checkpoint actually
collects — Hostel/Room/Bed structural identifiers, a Student's Bed
assignment and its start/end dates — Hostel residency data is
classified **Sensitive** (not Highly Sensitive), the same tier as
ordinary Student personal data. This follows the document's own
existing elevation rule: data is elevated to Highly Sensitive only "in
combination with health, government-ID, or biometric data" — none of
which this checkpoint collects. The classification is conditional on
that exclusion holding; see §18 for the explicit list of fields that
would require re-classification.

## 5. Active/inactive is NOT blocked/unsafe/unavailable

Mirrors Visitor's §5 correction exactly, applied to three entities
here instead of one:

- `active` — the Hostel/Room/Bed record is available for new residency
  use.
- `inactive` — the record is retired/deactivated from ordinary use (a
  closed wing, a room under renovation the School has manually flagged,
  a decommissioned bed frame) — the same ordinary reference-lifecycle
  rule `TransportVehicle`/`Visitor`/`LibraryTitle` already use.

There is NO maintenance-tracking, disciplinary, or safety-inspection
subsystem here. Deactivating a Hostel/Room/Bed blocks only NEW
assignments (§9) — it never auto-ends an existing resident's
assignment, and it never alters historical residency records (§10,
§19).

## 6. Capacity and occupancy are always derived, never stored

Per the checkpoint brief's explicit requirement: "Room capacity for
Phase 10D is derived from its Bed records," and Bed occupancy is
derived from its active residency assignment, never a stored/
duplicated mutable counter anywhere in the schema, API, or UI.

- Room capacity = count of that Room's active `HostelBed` rows
  (`withCount(['beds' => fn ($q) => $q->where('status', 'active')])`,
  exposed as `bedCount` in the Hostel show page and API responses).
- Bed occupancy = `HostelBed::activeResidency()` (a `hasOne` scoped to
  `status = 'active'`) being non-null — exposed as `occupied` (API) /
  `occupantName` (UI), never a boolean/status column on `hostel_beds`
  itself.

This was a deliberate constraint, not an oversight: a stored counter or
occupant column would be a second source of truth that could drift
from the actual `hostel_residency_assignments` rows under concurrent
writes or a missed update path — the same "no second source of truth"
principle Transport's `stops_count`/`beds_count` derivation already
established for Larastan-recognized `withCount()` naming.

## 7. Student eligibility

`HostelResidencyService::assign()` checks `Student::isActive()`
(`status === 'active'`) — the same minimal "is this a going concern"
precedent Transport's driver eligibility and Visitor's host-eligibility
checks established. No application/approval workflow, no Guardian
consent step, no fee/payment eligibility gate, no disciplinary-status
check — none of these were an explicit product requirement for this
checkpoint (see §18).

## 8. Actor identity decision

No `assigned_by_employee_id`/`ended_by_employee_id` columns exist on
`HostelResidencyAssignment`. This mirrors Visitor's §8 decision exactly
(and the same investigation was re-run for Hostel): actor identity for
every Hostel mutation is recorded exclusively through `App\Support\
Audit\AuditRecorder`'s `actor_user_id` column — never a duplicated,
module-specific `Employee` reference. `User` and `Employee` are not
interchangeable; conflating "who performed this action" (a `User`) with
"which staff member" (an `Employee`) would be exactly the mistake the
Visitor checkpoint's own brief warned against repeating.

## 9. Residency lifecycle

`App\Domain\Hostel\Application\HostelResidencyService` is the one
authoritative write path — `assign()`/`end()`. `assign()` validates, in
order: `Student::isActive()`
(`StudentNotEligibleException`), then `HostelBed::
isAvailableForAssignment()` (`BedNotAvailableException` — covering the
Bed's own status, its Room's status, and that Room's Hostel's status in
one exception, deliberately not split per ancestor level since the
caller-facing remedy is identical: "this Bed cannot receive an
assignment right now").

Inside a `DB::transaction()`, after acquiring row locks (§11), it
re-checks both invariants synchronously: an already-resident Student
(`StudentAlreadyResidentException`) and an already-occupied Bed
(`BedAlreadyOccupiedException`) — explicit rejection, never a silent
transfer. The caller must explicitly `end()` an existing residency
first.

`end()` uses a conditional `UPDATE ... WHERE status = 'active'` (never
a blind `$assignment->update(...)`), so a repeated/retried end is
rejected (`ResidencyAlreadyEndedException`) without ever rewriting the
original `starts_on`.

`status`/`ends_on` consistency is enforced by a CHECK constraint —
`(status = 'ended') = (ends_on IS NOT NULL)` — plus `ends_on IS NULL OR
ends_on >= starts_on`, mirroring
`transport_student_assignments_status_end_consistency_check`/
`visitor_visits`'s equivalent constraint exactly.

## 10. Two active-residency invariants

Enforced with two PostgreSQL partial unique indexes on
`hostel_residency_assignments`:

- `hostel_residency_assignments_one_active_per_bed ON
  (hostel_bed_id) WHERE status = 'active'`
- `hostel_residency_assignments_one_active_per_student ON
  (student_id) WHERE status = 'active'`

This is Hostel's structural difference from Visitor/Transport's
single-invariant tables: two independent uniqueness constraints on one
table, both requiring their own concurrency proof (§11).

## 11. Concurrency and lock ordering

`HostelResidencyService::assign()` acquires `lockForUpdate()` on the
Student row FIRST, then the Bed row — a deterministic, documented
order (in the service's own docblock) chosen specifically because two
independent invariants share one table: without a fixed order, one
concurrent `assign()` call locking Bed-then-Student while another locks
Student-then-Bed would be a genuine deadlock risk. Every code path in
this module acquires locks in this same order; no other lock-order
exists anywhere in Hostel.

A genuine database-level race loss (`UniqueConstraintViolationException`
surviving past both application-layer checks) is translated to
`ConcurrentResidencyConflictException` (409) — the database's partial
unique indexes remain the final backstop even if the lock ordering were
ever violated by a future code path.

Proven under REAL two-process concurrency (not a sequential
simulation), using the project's established `Symfony\Component\
Process\Process::start()`/`wait()` harness pattern
(`tests/Support/assign-hostel-residency.php`,
`HostelResidencyConcurrencyTest`) — two separate tests, one per
invariant:

- Two concurrent `assign()` calls for two different Students racing the
  SAME Bed: exactly one succeeds; the loser is caught by its own
  post-lock "already occupied" check (`BedAlreadyOccupiedException`).
- Two concurrent `assign()` calls for the SAME Student racing two
  different Beds: exactly one succeeds; the loser is caught by its own
  post-lock "already resident" check (`StudentAlreadyResidentException`).

Both raw-SQL uniqueness proofs (complementing, not replacing, the real
concurrency tests) live in
`HostelResidencyAssignmentsRlsIsolationTest`, using the same `pgsql`
connection/session as the fixture insert — the exact same-connection
lesson Visitor's §10 documents was applied correctly from the first
draft of these tests, with no failed attempt needed this time.

## 12. Idempotency decisions

**Assign**: the `idempotent` middleware IS applied to `POST
.../hostel-residency-assignments` — a network retry could otherwise
silently attempt to create a second historical assignment row for the
same physical placement, the same reasoning Library/Transport/Visitor's
"create a new historical row" mutations already established. Proven:
`HostelApiTest`'s replay/conflict/authorization-still-evaluated-on-
replay tests (first request, identical replay, same-key-different-
payload conflict, exactly one assignment exists, capability
re-evaluated on replay after the actor is disabled).

**End**: deliberately does NOT carry the `idempotent` middleware. `POST
.../hostel-residency-assignments/{id}/end` already addresses an
existing, specific assignment resource, and
`HostelResidencyService::end()`'s own conditional `UPDATE ... WHERE
status = 'active'` already makes a repeated/retried request safe by
construction — a second call predictably receives
`HOSTEL_RESIDENCY_ALREADY_ENDED` rather than double-processing
anything. Adding idempotency middleware here would add no additional
guarantee, only symmetry for its own sake. Matches Visitor's
check-out/Transport's `end()` precedent exactly.

## 13. Search endpoints

The admin assign UI's live search helpers
(`/app/hostel-residency/search/students`,
`/app/hostel-residency/search/beds`) explicitly call
`authorizeCapability('hostel.residency.manage', ...)` BEFORE
querying — carrying forward the Phase 10A Library security finding,
re-verified for Transport and Visitor. Regression-tested directly:
`HostelAdminUiTest::
a_member_without_residency_manage_cannot_use_the_assign_search_endpoints`
proves an authenticated School member without the capability cannot
enumerate Student identity data or available-Bed data through these
endpoints. The Bed search additionally filters to Beds that are
themselves available for assignment (active Bed, active Room, active
Hostel, no active residency) — so the search result set never leaks an
already-occupied or currently-unavailable Bed as a selectable option.

## 14. Audit

Every significant state change is recorded via the existing
`App\Support\Audit\AuditRecorder::school()`: `hostel.created`,
`hostel.updated`, `hostel.room.created`, `hostel.room.updated`,
`hostel.bed.created`, `hostel.bed.updated`, `hostel.residency.assigned`,
`hostel.residency.ended`. Metadata carries IDs, codes, status
transitions, and dates only — never Student name, guardian details,
medical information, or arbitrary free-text notes (no such field
exists anywhere in this module's schema — see §18).

## 15. Events / Documents / Communications decision

**Events**: zero Hostel domain events are emitted in this checkpoint.
No current consumer exists for a
`HostelResidencyAssigned`/`HostelResidencyEnded` event — inventing one
purely because it could theoretically be useful later repeats exactly
the speculative-event mistake Library/Transport/Visitor's checkpoints
already declined to make. Any future event this module emits will use
the existing transactional outbox (ADR 0025), never a bespoke
mechanism.

**Documents**: no changes. This checkpoint collects no room-condition
photos, ID scans, or arbitrary Hostel attachments, so Hostel needs no
Documents owner type.

**Communications**: no changes. No Guardian notification on
assignment, no new Communications audience type, no Hostel-specific
messaging infrastructure. Guardian notification, if ever required, is
a distinct future additive integration reusing the existing
Communications domain.

## 16. Explicit non-scope (deferred, not forgotten)

- Hostel fees, deposits, billing, payment status, or any cost field of
  any kind on Hostel's own tables. The FEE integration (OPF, ADR 0067)
  references `hostel_residency_assignments` by id from separate,
  Hostel-owned link rows; it never adds financial columns to this
  module's tables. See "Fee integration (OPF, ADR 0067)" below.
- Warden/staff assignment, a dedicated "Warden" role — the existing
  `hostel.directory.manage`/`hostel.residency.manage` capabilities
  already let a School compose whatever staffing role it needs.
- Meal plans, Canteen integration.
- Visitor rules or coupling to the Visitor module — a Hostel Visitor
  is, if ever modeled, a Visitor-domain concept referencing a Campus
  the same way any other Visit does, never a Hostel-owned relationship.
- Attendance, curfew, disciplinary rules/records.
- Health/Safety data — medications, allergies, medical conditions,
  incident records. Safety/incident management remains a distinct
  future checkpoint requiring its own legal/security readiness
  decision.
- Government-ID numbers, biometric data — collecting either would
  independently require the `[LEGAL REVIEW REQUIRED]` gate `docs/
  security/DATA-CLASSIFICATION.md` already flags, which this
  checkpoint deliberately does not cross.
- Documents/Communications integration (§15).
- Guardian/Student-facing portals or self-service of any kind.
- Roommate preference matching, a room-transfer request workflow (an
  explicit transfer is always modeled as `end()` then a fresh
  `assign()` — never an in-place row mutation).
- Occupancy analytics, AI-assisted room allocation.

## 17. Tenancy / RLS / composite FKs

All four Hostel tables: `school_id` + `App\Support\Tenancy\
BelongsToSchool` + UUIDv7 primary key + RLS enabled AND forced via
`App\Support\Tenancy\TenantRls::enable()` + `unique(id, school_id)`.

Composite FKs (School-equality enforced at the database level, never
RLS/SchoolScope alone):

| Child | Column(s) | Parent | On delete |
|---|---|---|---|
| `hostels` | `(campus_id, school_id)` | `campuses(id, school_id)` | RESTRICT |
| `hostel_rooms` | `(hostel_id, school_id)` | `hostels(id, school_id)` | RESTRICT |
| `hostel_beds` | `(hostel_room_id, school_id)` | `hostel_rooms(id, school_id)` | RESTRICT |
| `hostel_residency_assignments` | `(student_id, school_id)` | `students(id, school_id)` | RESTRICT |
| `hostel_residency_assignments` | `(hostel_bed_id, school_id)` | `hostel_beds(id, school_id)` | RESTRICT |

**Every FK in this module is RESTRICT.** This was a deliberate decision
made correctly from the first migration draft — a direct consequence
of the Phase 10C Visitor historical-integrity correction (that
checkpoint initially shipped `visitor_visits.visitor_id` as CASCADE by
false analogy to Transport's `transport_student_assignments.student_id`
CASCADE, then corrected it to RESTRICT before integration). Hostel's
own explicit requirement is that historical residency remains
"permanently queryable" and that none of Hostel/Room/Bed/Student is
ever intended to be hard-deleted while referenced — so
`hostel_residency_assignments.student_id`
deliberately deviates from Transport's `student_id` CASCADE precedent,
matching Visitor's corrected posture instead. A CASCADE FK anywhere in
this chain would silently destroy history the moment *anything* — a
future maintenance script, a raw SQL admin session, or a later refactor
adding a delete endpoint — deleted a parent row; "there is no delete
endpoint today" is not a database-level guarantee against any of those.

Every one of the FKs above is proven rejected at the raw-SQL level (a
real `pgsql_admin`/`pgsql` privileged INSERT bypassing the application
layer entirely), and every historical-deletion-protection edge is
proven directly with a real DELETE attempt, in
`tests/Feature/Postgres/Hostel*RlsIsolationTest.php` (4 files, 30
tests): RLS-enabled-and-forced, no-context-zero-rows, cross-School
SELECT/UPDATE rejection, every composite-FK cross-School rejection
above, both partial unique indexes (§10), and four deletion-rejection
proofs — a Hostel referenced by a Room, a Room referenced by a Bed, a
Bed referenced by a residency assignment, and a Student referenced by a
residency assignment — each confirming the row survives the rejected
DELETE attempt.

## 18. Capabilities

Two independently gateable areas, mirroring Visitor's directory/visits
split:

- `hostel.directory.view` / `hostel.directory.manage` — Hostel, Room,
  and Bed structural records (one namespace covering all three levels,
  not a capability per entity type — the checkpoint brief's explicit
  "minimal namespace" requirement).
- `hostel.residency.view` / `hostel.residency.manage` — the Student
  residency assignment lifecycle.

No capability-inheritance exists in this codebase — a role needing both
view and manage in an area is granted both explicitly. `school_admin`
and `principal` hold all four by default, matching the "day-to-day
operational parity" precedent already established for `library.*`/
`transport.*`/`visitor.*` on those roles. No dedicated "Warden" system
role was created (§16).

Proven: `tests/Feature/Authorization/HostelCapabilityTest.php` (12
tests) — catalog integrity, no speculative capabilities, default role
grants, area/view-manage independence, tenant isolation,
central-identity-alone denial, existing grants unaffected.

## 19. API surface

`/api/v1/schools/{schoolId}/hostels[...]`,
`/hostels/{hostelId}/hostel-rooms[...]`,
`/hostel-rooms/{hostelRoomId}/hostel-beds[...]`,
`/hostel-residency-assignments[...]`, and
`/hostel-residency-assignments/{id}/end`. No public/mobile/Guardian
API. GET (list/show) endpoints authorize inside the controller
(`AuthorizesCapability` trait) rather than via route middleware,
matching every other simple-CRUD module's precedent; mutating routes
additionally carry `capability:`/`throttle:school-api-mutations` route
middleware, and assign additionally carries `idempotent` (§12).

OpenAPI (`packages/contracts/openapi/school-os-api.yaml`) documents
every path/schema; `packages/shared-types` was regenerated via `npm run
generate` with verified zero drift (identical output on a second run).

## 20. Administrative UI

Session-authenticated Inertia pages under `/app/hostels`,
`/app/hostel-rooms`, `/app/hostel-beds`, `/app/hostel-residency`:
Hostel directory (list/search/create/update/activate-deactivate), a
Hostel detail page listing its Rooms (with derived bed-count) and an
inline add-Room form, a Room detail page listing its Beds (with
derived occupant name) and an inline add-Bed form, and Residency pages
(current/historical views, an assign flow with Student/Bed live
search, end action). Reuses the existing `EmptyState`/`Pagination`/
`StatusBadge` components and layout conventions verbatim — no
app-shell redesign. No Student/Guardian-facing views, no warden/staff
management UI this checkpoint.

## 21. Security review

Reviewed and addressed/proven-safe for every item the checkpoint brief
required:

- Cross-School IDOR: every Hostel/Room/Bed/Assignment lookup 404s on a
  cross-School id, and cross-School `student_id`/`hostel_bed_id` in an
  assign payload is rejected (`HostelApiTest`).
- RLS escape: proven at the raw-SQL level for all four tables (§17).
- Composite-FK bypass: proven rejected for every parent reference
  (§17).
- Unauthorized Student/Bed search: the anti-P1 regression test (§13).
- Sensitive-data leakage: search-endpoint results are capability-gated
  (§13); audit metadata never carries Student name/guardian details
  (§14).
- Unsafe mass assignment: every controller validates an explicit field
  allow-list; no `$request->all()` passed to `create()`/`update()`.
- Double occupancy / double residency: proven impossible at the
  database level and under real concurrency (§10, §11).
- Lock-order deadlocks: deterministic Student-then-Bed ordering,
  documented and applied uniformly (§11).
- Inactive-resource assignment: an inactive Bed, an inactive Bed's
  Room, or an inactive Bed's Hostel each independently blocks a new
  assignment — three dedicated tests (`HostelLifecycleTest`).
- Idempotency bypass / authorization-still-evaluated-on-replay:
  `HostelApiTest::
  an_assign_replay_after_losing_the_manage_capability_is_denied`.
- Historical deletion: proven rejected at the database level for every
  ancestor in the chain, including the two external parents (Student,
  HostelBed) — §17.
- Accidental Finance fields: no fee/deposit/cost/payment-status column
  exists anywhere in this module's schema (§16).
- Accidental Health/Safety data: no medical/incident/disciplinary
  field exists anywhere in this module's schema (§16).
- Generic notes misuse: no free-text notes field exists on any Hostel
  table — there is no field to misuse for out-of-scope data.

No P0/P1/P2 findings remain open. No P3 test-infrastructure findings
were produced this checkpoint — the deterministic lock-order design and
the same-connection concurrency-test pattern (both direct lessons
carried forward from Visitor's §10/§19) were applied correctly from the
first draft of every affected test.

## 22. Test evidence

- Postgres/RLS: 30 tests
  (`tests/Feature/Postgres/Hostel*RlsIsolationTest.php`, 4 files).
- Real two-process concurrency: 2 tests
  (`HostelResidencyConcurrencyTest`).
- Authorization: 12 tests
  (`tests/Feature/Authorization/HostelCapabilityTest.php`).
- Directory/Room/Bed lifecycle (API): 13 tests
  (`tests/Feature/Hostel/HostelLifecycleTest.php`).
- Residency service lifecycle: 9 tests
  (`tests/Feature/Hostel/HostelResidencyServiceTest.php`).
- Full API surface + idempotency + IDOR: 16 tests
  (`tests/Feature/Hostel/HostelApiTest.php`).
- Admin Inertia UI + anti-P1 search-endpoint regressions: 13 tests
  (`tests/Feature/App/HostelAdminUiTest.php`).

Total: 95 tests, 258 assertions, all passing, zero skipped.

## Retention (E21.3B, 2026-10-02)

A Student's **ended** residencies are D7 operational Student history: they
are deleted 7 calendar years after the Student's final exit, by
`platform:student-retention-prune` through
`App\Domain\Hostel\Application\Retention\HostelResidencyRetentionService`,
one Student per transaction under the Student-row lock. The
`student_id` RESTRICT foreign key is unchanged: retention deletes the
residency rows explicitly, never by cascade.
- An **active** residency is never deleted on age, and it keeps the
  Student's core record.
- Hostels, rooms and beds are School configuration and stay. No Finance row
  references a residency.

Project-adopted, pending legal ratification (`docs/security/E21-RETENTION-DETERMINATION.md`
§5.6). Holds (`RETENTION_HOLD_SCHOOL_IDS`) keep everything; `--dry-run`
counts with the same rule.

## Fee integration (OPF, ADR 0067)

Contract only (OPF.0, 2026-10-05); **OPF.2 implements it, and nothing below
is built yet.**
- **Recurring Hostel fee through selections.**
  - Residency start selects, and residency end withdraws, the Student's
    optional Hostel fee line for the academic year. It goes through the
    trusted FEE source-selection seam, under `hostel.residency.manage`.
  - Only FEE assessment runs (`finance.fee_assessments.run`) charge, a full
    billing period at a time (no proration).
  - Ending or transferring a residency never cancels or alters a charge.
    Corrections are explicit Finance actions.
- **Pricing tiers.** A room-category or hostel price is its own fee head /
  line in FEE. Hostel owns only the tier → fee-head mapping (no amount;
  `hostel.directory.manage`).
- **Academic year.** Carrying active residencies into the next year is an
  explicit, audited, idempotent operation.
- **Excluded:**
  - **Deposits and refunds** (ADR 0067 D2): a refundable deposit is a
    liability and needs its own liability / refund / credit contract. It is
    never modelled as fee revenue.
  - **Damage charges:** stay manual ad-hoc Finance charges. No inspection
    concept is introduced.
- **No financial columns** are added to Hostel's own tables; the link rows
  are separate, Hostel-owned Finance evidence (D8), registered for
  retention.
