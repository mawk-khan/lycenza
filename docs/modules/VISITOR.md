# School OS — Visitor Module (Phase 10C)

Visitor is an independent bounded context proving School Visitor
foundation: a Visitor directory (reference records) and a Visit
check-in/check-out lifecycle. It is NOT put inside Transport, HR, or
Students, and it is not part of a generic `Operations` mega-domain —
the same independence Library (Phase 10A) and Transport (Phase 10B)
established structurally.

## 1. Checkpoint scope

Proven in this checkpoint:

- Visitor directory (reference records): create, list/show, update,
  activate/deactivate.
- Visit check-in/check-out lifecycle against a Campus, with an
  optional Employee host.
- One active (checked-in) Visit per Visitor, database-enforced.
- Historical-record survival after Visitor/Campus/Employee
  deactivation.
- Cross-School rejection at the database level for every reference.
- Row-Level Security on every new table.
- Capability-based authorization (no role-name checks).
- Admin JSON API (`/api/v1`) + Inertia UI.
- Audit trail for every significant state change.

Explicitly NOT in scope: Safety/incident management, government-ID/
biometric/photo capture, billing, public/kiosk/self-registration
flows, Communications/Documents integration. See §18 "Explicit
non-scope" for the full list and reasoning.

**P2 assumption**: no local Phase 10 / Visitor planning document was
found in this repository, the user's home directory, or any accessible
paste-cache at the time this checkpoint began (the same search
performed, and the same conclusion reached, for Phase 10A/Library and
Phase 10B/Transport). This checkpoint implements only the foundation
explicitly specified in the Phase 10C checkpoint brief itself — it does
not invent a complete commercial visitor-management product.

## 2. Domain boundary

`apps/platform/app/Domain/Visitor/` — `Application/Infrastructure/
Http`, the same layered shape every other module in this repository
uses (no `Domain/` or `Events/` subdirectory was needed — Visitor has
no value-object/domain-event layer distinct from its two Eloquent
models and one Application service).

Visitor OWNS: `Visitor`, `VisitorVisit`.

Visitor REFERENCES, never duplicates or owns:

- `Campus` (Campus/Academic Structure domain) — a Visit occurs at a
  specific Campus; Visitor never duplicates Campus name/location data.
- `Employee` (HR domain) — an optional host, referenced by id, with no
  new "host" identity record and no dependency on a specific Position/
  job title.

Visitor does NOT reference `Student` — see §13.

No bidirectional coupling: Campus and HR have zero knowledge of
Visitor. Visitor depends on them; they never depend on Visitor.

## 3. Domain model

### Visitor (directory/reference entity)

A School-scoped, reusable identity for a person who visits the School.
`full_name`, optional `phone` (max 32 chars), `status`
(active/inactive, no delete endpoint). Deliberately minimal: no date of
birth, gender, home address, employer, government-ID type/number,
ID-document scan, photograph, biometric data, vehicle/license-plate
tracking, blacklist reason, or security-risk score — none of these
were an explicit product requirement for this checkpoint (checkpoint
brief §7).

### VisitorVisit (historical transaction entity)

One check-in/check-out visit: `visitor_id`, `campus_id`, optional
`host_employee_id`, `purpose` (bounded to 500 chars, treated as
Sensitive free text), optional `gate_pass_number`, `status`
(`checked_in`/`checked_out`), `checked_in_at`, `checked_out_at`
(nullable). See §9 for the lifecycle.

## 4. Security / data classification

Added an explicit **Visitor** row to
`docs/security/DATA-CLASSIFICATION.md`. For the fields this checkpoint
actually collects — name, optional phone, visit purpose, Campus,
Employee host, check-in/check-out timestamps, optional gate-pass
identifier — Visitor data is classified **Sensitive** (not Highly
Sensitive), the same tier as ordinary Student/Employee personal data.
This follows the document's own existing elevation rule: data is
elevated to Highly Sensitive only "in combination with health,
government-ID, or biometric data" — none of which this checkpoint
collects. The classification is conditional on that exclusion holding;
see §18 for the explicit list of fields that would require
re-classification (and, for government identifiers and biometrics,
would independently trigger a `[LEGAL REVIEW REQUIRED]` gate this
checkpoint deliberately does not cross).

## 5. Active/inactive is NOT blocklisting

This is a deliberate, explicit correction to the Phase 10C readiness
audit's provisional wording, which loosely described Visitor
`status = inactive` as similar to blocklisting. It is not:

- `active` — the Visitor directory record is available for new Visit
  use (selectable at check-in).
- `inactive` — the Visitor directory record is retired/deactivated
  from ordinary use (an old/duplicate/no-longer-relevant record), the
  same ordinary reference-lifecycle rule `TransportVehicle`/
  `TransportRoute`/`LibraryTitle` already use.

There is NO security blocklist/watchlist/risk-scoring subsystem in
this checkpoint. No `blocked`, `banned`, `denied`, `watchlist`, or
`risk_level` column exists, and none is planned here. If a School
needs to flag a Visitor as a genuine security concern, that is a
distinct future product/security decision requiring its own design
(different data-retention and disclosure obligations than an ordinary
"this record isn't in current use" flag) — not something this
checkpoint's `status` column does or should silently become.

## 6. Campus ownership decision

`VisitorVisit.campus_id` is required (a Visit occurs at exactly one
Campus). `Visitor` itself carries no `campus_id` — the directory
identity is School-scoped, not Campus-scoped, so the same known
Visitor can visit multiple Campuses within the same School without
duplicate identity rows (checkpoint brief §11). No Visitor-specific
location/campus structure was invented — the existing `App\Models\
Campus` is used directly, matching every other module's precedent.

## 7. Employee host decision

A Visit MAY optionally reference an existing HR `Employee` as host,
via a composite FK `(host_employee_id, school_id) → employees(id,
school_id)`. No Employee name/email/phone/department/job title is
duplicated onto `VisitorVisit` — the presenter layer joins to
`Employee` for display. Eligibility check on check-in:
`Employee::isActive()` (`record_status === 'active'`) — the same
minimal "is this a going concern" precedent Transport's driver
eligibility check established, deliberately not
`EmployeeDirectoryService`'s more nuanced date-range "current
employment" query. A historical Visit continues to resolve an Employee
that later becomes inactive (no cascade-delete, restrictive FK, no
delete endpoint on Employee).

## 8. Lifecycle actor decision

No `checked_in_by`/`checked_out_by` columns exist on `VisitorVisit`.
This was an explicit investigation (checkpoint brief §10), not an
oversight: neither `transport_route_assignments`,
`transport_student_assignments`, nor `library_loans` — the closest
precedents for a lifecycle-mutation table in this codebase — carry an
actor column. Actor identity for every Visitor mutation is recorded
exclusively through `App\Support\Audit\AuditRecorder`'s
`actor_user_id` column, which already captures "who did this" durably
and queryably without a redundant, module-specific copy. This also
sidesteps the checkpoint brief's explicit warning against conflating
an Employee (who may physically staff reception) with the authenticated
User performing the mutation — `AuditRecorder` records the acting
`User`, never an `Employee`, for exactly this reason.

## 9. Visit lifecycle

`App\Domain\Visitor\Application\VisitorVisitService` is the one
authoritative write path — `checkIn()`/`checkOut()`. `checkIn()`
REJECTS if the Visitor already has an active Visit
(`VisitorAlreadyCheckedInException`) — the caller must explicitly
`checkOut()` first, mirroring `TransportStudentAssignmentService::
assign()`'s explicit-action-required precedent (a Visitor's check-in
is a discrete fact worth an explicit transition, not administrative
housekeeping). `checkOut()` uses a conditional `UPDATE ... WHERE status
= 'checked_in'` (never a blind `$visit->update(...)`), so a
repeated/retried check-out is rejected
(`VisitAlreadyCheckedOutException`) without ever rewriting the original
`checked_in_at`.

`status`/`checked_out_at` consistency is enforced by a CHECK
constraint: `(status = 'checked_out') = (checked_out_at IS NOT NULL)`,
plus `checked_out_at IS NULL OR checked_out_at >= checked_in_at` —
mirrors `transport_student_assignments_status_end_consistency_check`
exactly.

**Check-out clock (S3, 2026-10-08).** `checkOut()` records
`max(now, checked_in_at)`. If the checking-out node's clock reads earlier
than the stored check-in (a backward NTP/VM step, or skew between the node
that checked in and this one), the check-out is recorded at the check-in
instant instead of being refused with a 500 by the CHECK above. The CHECK
stays the authority; `checked_in_at` is still never rewritten.

## 10. One-active-visit invariant

Enforced with a PostgreSQL partial unique index:
`visitor_visits_one_active_per_visitor ON visitor_visits (visitor_id)
WHERE status = 'checked_in'`.

`VisitorVisitService::checkIn()` additionally takes `lockForUpdate()`
on the Visitor row both a would-be racer and the call target touch —
PostgreSQL serializes any two concurrent `checkIn()` calls on that lock
before either reaches its own invariant check, mirroring
`TransportStudentAssignmentService::assign()`'s locking discipline
exactly.

Proven under REAL two-process concurrency (not a sequential
simulation), using the project's established `Symfony\Component\
Process\Process::start()`/`wait()` harness pattern
(`tests/Support/check-in-visitor.php`,
`VisitorVisitCheckInConcurrencyTest`): exactly one of two concurrent
`checkIn()` calls for the same Visitor succeeds; the loser is caught by
its own post-lock "already checked in" check
(`VisitorAlreadyCheckedInException`), never the raw unique-constraint
path — proven separately and directly by the RLS isolation test's
`the_database_rejects_a_second_active_visit_for_the_same_visitor`.

**Test-infrastructure note**: this concurrency test's fixtures are
deliberately created OUTSIDE the base `TestCase`'s `DatabaseTransactions`
wrapper (`protected $connectionsToTransact = [];`), matching
`LibraryLoanCheckoutConcurrencyTest`/
`TransportStudentAssignmentConcurrencyTest`'s exact precedent — the two
spawned subprocesses are separate PostgreSQL sessions and can never see
this test process's otherwise-uncommitted rows. Getting this wrong
during implementation produced two distinct, since-fixed failure modes,
both worth recording: (1) without the fix, both subprocesses reliably
fail with "No query results for model [School]" because the fixtures
were never actually committed; (2) in the RLS isolation test's
raw-SQL "database rejects a second active visit" proof, using a
*different* connection (`pgsql_admin`) than the one that created the
fixture row deadlocks the test entirely — Postgres blocks the second
insert's unique-index check waiting for the first (still-open,
transaction-wrapped) insert to resolve, which never happens until the
test method itself returns. The fix there was using the SAME `pgsql`
connection/session for both statements, exactly matching
`TransportStudentAssignmentsRlsIsolationTest`'s established pattern.

## 11. Check-out invariant

See §9 — the conditional `UPDATE ... WHERE status = 'checked_in'` is
the sole mechanism; no route-level idempotency was added for check-out
(see §14).

## 12. Gate-pass number

Optional. Chosen semantics: one locally-recorded identifier per
historical Visit, never reused — so `visitor_visits` carries a
permanent partial unique index, `visitor_visits_school_gate_pass_unique
ON visitor_visits (school_id, gate_pass_number) WHERE gate_pass_number
IS NOT NULL`, School-scoped. Deliberately NOT normalized via
`NormalizesCode` — like `transport_vehicles.registration_number`, a
gate-pass number is real-world/locally-issued reference data, not a
School-chosen identifier. No barcode/QR/badge-printing hardware
integration is implemented or implied.

## 13. Student host is out of scope

No Student host relationship and no polymorphic `host_type`/`host_id`
exist. A School visitor ordinarily visits an Employee, an office, or
the School generally — modeling a generic host abstraction
speculatively, before a second concrete host type is ever needed, would
be exactly the kind of premature abstraction root `CLAUDE.md` warns
against. If Student-linked visitation is required later, it should be
added through a deliberate, reviewed extension, not retrofitted here.

## 14. Idempotency decisions

**Check-in**: the `idempotent` middleware IS applied to `POST
.../visitor-visits` (the check-in mutation) — a network retry could
otherwise silently create a second `VisitorVisit` row for the same
physical arrival, the same reasoning `LibraryLoanController::store()`
and Transport's two "assign" mutations already established. Proven:
`VisitorApiTest`'s replay/conflict/authorization-still-evaluated-on-
replay tests (first request, identical replay, same-key-different-
payload conflict, exactly one Visit exists, capability re-evaluated on
replay after the actor is disabled).

**Check-out**: deliberately does NOT carry the `idempotent` middleware.
`POST .../visitor-visits/{id}/end` already addresses an existing,
specific Visit resource, and `VisitorVisitService::checkOut()`'s own
conditional `UPDATE ... WHERE status = 'checked_in'` already makes a
repeated/retried request safe by construction — a second call
predictably receives `VISITOR_VISIT_ALREADY_CHECKED_OUT` rather than
double-processing anything. Adding idempotency middleware here would
add no additional guarantee, only symmetry for its own sake — the
exact anti-pattern the checkpoint brief explicitly warned against.
Matches Transport's `end()`/Library's `checkIn()` precedent exactly.

## 15. Search endpoints

The admin check-in UI's live search helpers
(`/app/visitor/visits/search/visitors`,
`/app/visitor/visits/search/hosts`) explicitly call
`authorizeCapability('visitor.visits.manage', ...)` BEFORE querying —
the specific mistake the Phase 10A Library checkpoint's security review
found and fixed in its own checkout search endpoints, and that Phase
10B re-verified for Transport. Regression-tested directly:
`VisitorAdminUiTest::
a_member_without_visits_manage_cannot_use_the_check_in_search_endpoints`
proves an authenticated School member without the capability cannot
enumerate Visitor names/contact data or Employee host data through
these endpoints.

## 16. Audit

Every significant state change is recorded via the existing
`App\Support\Audit\AuditRecorder::school()`: `visitor.created`,
`visitor.updated`, `visitor.visit.checked_in`,
`visitor.visit.checked_out`. Metadata carries IDs, Campus id, Employee
host id, status, timestamps, and gate-pass identifier only — never
phone number, full Visitor name, visit purpose text, or Employee
phone/email (`docs/security/DATA-CLASSIFICATION.md`). Note:
`visitor.updated`'s audit payload deliberately diverges from Transport's
`transport.route.updated` precedent of logging a full field-level
before/after diff — because `full_name`/`phone` are the exact Sensitive
personal fields this rule exists to protect, only the `status`
transition (if any) is logged, not the field values themselves.

## 17. Events / Documents / Communications decision

**Events**: zero Visitor domain events are emitted in this checkpoint.
No current consumer exists for a `VisitorCheckedIn`/`VisitorCheckedOut`
event — inventing one purely because it could theoretically be useful
later repeats exactly the speculative-event mistake Library and
Transport's checkpoints already declined to make. Any future event
this module emits will use the existing transactional outbox (ADR
0025), never a bespoke mechanism.

**Documents**: no changes. This checkpoint collects no ID-document
scans, Visitor photographs, or arbitrary Visitor attachments, so
Visitor needs no Documents owner type — the Documents exclusive-owner
arc was not touched.

**Communications**: no changes. No host SMS/email, no Guardian
notification, no new Communications audience type, no Visitor-specific
messaging infrastructure. Host notification, if ever required, is a
distinct future additive integration reusing the existing
Communications domain.

## 18. Explicit non-scope (deferred, not forgotten)

- Government ID numbers, passport/national-ID/license numbers,
  government-ID scans/documents — collecting these would independently
  require the `[LEGAL REVIEW REQUIRED]` gate `docs/security/
  DATA-CLASSIFICATION.md` already flags for government identifiers,
  which this checkpoint deliberately does not cross.
- Biometrics, facial recognition, fingerprints — same
  `[LEGAL REVIEW REQUIRED]` gate for biometric data.
- Retained visitor photographs — would materially change privacy/
  retention obligations; not collected.
- Medical information, Safety/incident records — the readiness audit
  found "incident records" may fall under Health's own unresolved
  `[LEGAL REVIEW REQUIRED]` gate; Safety/incident management is
  explicitly a separate future checkpoint requiring its own legal/
  security readiness decision, never bolted onto Visitor as a renamed
  "note."
- Blacklist/watchlist/risk scoring (§5).
- Visitor vehicle/license-plate tracking, GPS/location tracking.
- Public kiosk, self-registration, pre-registration/invitations, QR
  invitation flow, badge printers, turnstile/access-control hardware —
  Phase 10C is authenticated administrative operation only; these
  introduce distinct abuse/security/idempotency concerns requiring a
  separate checkpoint.
- Automated host SMS/email notification (§17).
- Documents owner integration (§17).
- Billing/Finance integration of any kind.
- Guardian/Student-facing Visitor portals.
- A generic Student/polymorphic host abstraction (§13).

## 19. Tenancy / RLS / composite FKs

Both Visitor tables: `school_id` + `App\Support\Tenancy\
BelongsToSchool` + UUIDv7 primary key + RLS enabled AND forced via
`App\Support\Tenancy\TenantRls::enable()` + `unique(id, school_id)`.

Composite FKs (School-equality enforced at the database level, never
RLS/SchoolScope alone):

| Child | Column(s) | Parent |
|---|---|---|
| `visitor_visits` | `(visitor_id, school_id)` | `visitors(id, school_id)` |
| `visitor_visits` | `(campus_id, school_id)` | `campuses(id, school_id)` |
| `visitor_visits` | `(host_employee_id, school_id)` | `employees(id, school_id)` |

Every one of these is proven rejected at the raw-SQL level (a real
`pgsql_admin`/`pgsql` privileged INSERT or DELETE bypassing the
application layer entirely) in
`tests/Feature/Postgres/Visitor*RlsIsolationTest.php` — 14 tests, 21
assertions, covering RLS-enabled-and-forced, no-context-zero-rows,
cross-School SELECT/UPDATE rejection, every composite-FK cross-School
rejection above, the one-active-per-Visitor partial unique index, and
the historical-integrity deletion proof below.

**All three FKs `RESTRICT` on delete** — `visitor_id`, `campus_id`,
and `host_employee_id` alike. This is a deliberate, corrected decision:
an earlier revision of this migration used `cascadeOnDelete()` for
`visitor_id`, reasoning by analogy to
`transport_student_assignments.student_id`'s cascade — but that
analogy does not actually hold for Visitor. Transport's Student
assignment and Visitor's Visit are not the same shape with respect to
deletion risk: this checkpoint's own explicit requirement is that a
completed Visit remains "permanently queryable," and Visitor rows are
never intended to be hard-deleted at all (§5 — the only lifecycle
transition is active/inactive). A CASCADE FK would silently destroy
that history the moment *anything* — a future maintenance script, a
raw SQL admin session, or a later refactor adding a `Visitor::destroy()`
call — deleted the Visitor row; "there is no delete endpoint today" is
not a database-level guarantee against any of those. RESTRICT makes
Visitor deletion structurally impossible while any Visit references it,
regardless of the application layer, matching Campus's and Employee's
already-correct posture (neither exposes a delete endpoint either, and
both already used RESTRICT from the start). Proven directly:
`VisitorsRlsIsolationTest::a_visitor_referenced_by_a_historical_visit_cannot_be_deleted`
— even a privileged same-session raw DELETE against a Visitor
referenced by a historical Visit is rejected by PostgreSQL itself, and
both the Visitor and the Visit survive the attempt.

## 20. Capabilities

Two independently gateable areas, mirroring Library's catalogue/
circulation and Transport's routes/vehicles/assignments splits:

- `visitor.directory.view` / `visitor.directory.manage` — Visitor
  reference records.
- `visitor.visits.view` / `visitor.visits.manage` — the check-in/
  check-out Visit lifecycle.

No capability-inheritance exists in this codebase — a role needing both
view and manage in an area is granted both explicitly. `school_admin`
and `principal` hold all four by default, matching the "day-to-day
operational parity" precedent already established for `library.*`/
`transport.*` on those roles. No dedicated "Receptionist" system role
was created — not genuinely required; a School wanting narrower
front-desk-only staff can already compose a custom role from these four
capabilities via the existing role system. *(SR.0 correction, 2026-10-09: a School cannot compose, create or configure a role — no runtime role writer exists (ADR 0059 §1), and tenant-custom roles are deferred (ADR 0063 T3). The fixed system catalogue in ADR 0071 provides `front_office` (all four `visitor.*` keys).)* *(SR.3 update, 2026-10-09: implemented — the fixed production catalogue is seeded by `CapabilityAndRoleSeeder` and snapshot-pinned; ADR 0071 §25.)*

Proven: `tests/Feature/Authorization/VisitorCapabilityTest.php` (12
tests, 33 assertions) — catalog integrity, no speculative capabilities,
default role grants, area/view-manage independence, tenant isolation,
central-identity-alone denial, existing grants unaffected.

## 21. API surface

`/api/v1/schools/{schoolId}/visitors[...]`, `/visitor-visits[...]`, and
`/visitor-visits/{id}/end`. No public/mobile-specific/kiosk API. GET
(list/show) endpoints authorize inside the controller
(`AuthorizesCapability` trait) rather than via route middleware,
matching every other simple-CRUD module's precedent; mutating routes
additionally carry `capability:`/`throttle:school-api-mutations` route
middleware, and check-in additionally carries `idempotent` (§14).

OpenAPI (`packages/contracts/openapi/school-os-api.yaml`) documents
every path/schema; `packages/shared-types` was regenerated via `npm run
generate` with verified zero drift (identical output on a second run).

## 22. Administrative UI

Session-authenticated Inertia pages under `/app/visitor/...`: Visitor
Directory (list/search/create/update/activate-deactivate), Visits
(check-in flow with Visitor/host search + Campus select + purpose +
optional gate-pass, current-checked-in and historical views,
check-out action). Reuses the existing `EmptyState`/`Pagination`/
`StatusBadge` components and layout conventions verbatim — no
app-shell redesign. No public kiosk, self-registration, or Guardian/
Student-facing views this checkpoint.

## 23. Data-retention posture

Visit-history retention is currently governed by whatever
platform-wide/legal retention policy eventually applies to Sensitive
personal data generally (`docs/security/DATA-CLASSIFICATION.md`
explicitly flags retention/purge policy as `[LEGAL REVIEW REQUIRED],
not yet decided` for the platform as a whole — see `docs/modules/
DOCUMENTS.md` for the identical posture already adopted there). This
checkpoint does not invent a Visitor-specific retention/pruning system,
and does not claim indefinite retention is a final legal decision — it
simply does not delete historical `VisitorVisit`/`Visitor` rows
automatically, pending that broader decision.

## 24. Security review

Reviewed and addressed/proven-safe for every item the checkpoint brief
required:

- Cross-School IDOR: every Visitor/VisitorVisit lookup 404s on a
  cross-School id (`VisitorApiTest`).
- RLS escape: proven at the raw-SQL level for both tables (§19).
- Composite-FK bypass: proven rejected for every parent reference
  (§19).
- Unauthorized Visitor/Employee-host search: the anti-P1 regression
  test (§15).
- Contact-information exposure: search-endpoint results are
  capability-gated (§15); audit metadata never carries phone/name/
  purpose (§16).
- Unsafe mass assignment: every controller validates an explicit field
  allow-list; no `$request->all()` passed to `create()`/`update()`.
- Purpose-text leakage: bounded to 500 chars, never copied into audit
  metadata, no full-text search infrastructure built for it.
- Audit Sensitive-data leakage: IDs/codes/status/timestamps only (§16).
- Actor identity confusion: no Employee/User conflation — actor is
  always the authenticated `User` via `AuditRecorder` (§8).
- Multiple active Visits: proven impossible at the database level and
  under real concurrency (§10).
- Check-out races: proven safe via the conditional UPDATE (§9, §11).
- Inactive-Visitor check-in / inactive-Employee-host assignment:
  `VisitorNotEligibleException`/`HostEmployeeNotEligibleException` —
  each has a dedicated test.
- Gate-pass collision: proven rejected (`VisitorApiTest::
  a_duplicate_gate_pass_number_within_the_same_school_is_rejected`).
- Idempotency bypass / authorization-still-evaluated-on-replay:
  `VisitorApiTest::a_check_in_replay_after_losing_the_manage_capability_is_denied`.
- Accidental Safety data collection / accidental ID-document
  collection: no such fields exist anywhere in the schema, models, or
  UI (§18).
- Accidental hard deletion: no delete endpoint exists on either
  Visitor table; reference entities never hard-delete; the database
  itself now structurally rejects a Visitor deletion attempt while any
  Visit references it (§19 — corrected from an initial `CASCADE` to
  `RESTRICT` on `visitor_id` during post-closure review, before this
  checkpoint's integration to `main`).

No P0/P1/P2 findings remain open. Two P3 test-infrastructure findings
are recorded: the concurrency-test transaction-wrapping pitfall (§10),
and the same-connection-visibility pitfall in the historical-integrity
deletion test (§19) — both fixed during this checkpoint, kept
documented as lessons for future tests in this codebase.

## 25. Test evidence

- Postgres/RLS: 14 tests, 21 assertions
  (`tests/Feature/Postgres/Visitor*RlsIsolationTest.php`, 2 files).
- Authorization: 12 tests, 33 assertions
  (`tests/Feature/Authorization/VisitorCapabilityTest.php`).
- Directory lifecycle (API): 6 tests
  (`tests/Feature/Visitor/VisitorLifecycleTest.php`).
- Visit service lifecycle: 9 tests
  (`tests/Feature/Visitor/VisitorVisitServiceTest.php`).
- Full API surface + idempotency + IDOR: 18 tests
  (`tests/Feature/Visitor/VisitorApiTest.php`).
- Real two-process concurrency: 1 test, 4 assertions
  (`VisitorVisitCheckInConcurrencyTest`).
- Admin Inertia UI + anti-P1 search-endpoint regressions: 10 tests
  (`tests/Feature/App/VisitorAdminUiTest.php`).

Total: 68 tests, 178 assertions, all passing, zero skipped.

## Retention (E21.3E, 2026-10-02)

A checked-out visit is deleted 1 calendar year after `checked_out_at` by
`platform:operations-retention-prune` (`VisitorRetentionService`), one
visitor per transaction; the visitor goes only with its last visit, so a
returning visitor keeps its identity. A visit never checked out is reported
unresolved and kept (no checkout is ever inferred). Host references go with
the visit. Project-adopted, pending legal ratification (`docs/security/E21-RETENTION-DETERMINATION.md` §5.9).
