# School OS — Transport Module (Phase 10B)

Transport is an independent bounded context proving School Transport
foundation: Routes, ordered Stops, Vehicles, Route operational
(Vehicle/Driver) assignment, and Student Transport assignment. It is
NOT put inside Library, and it is not part of a generic `Operations`
mega-domain (see ADR-equivalent reasoning in
`docs/architecture/DOMAIN-MAP.md` and the Phase 10A precedent this
checkpoint follows structurally, while staying fully independent).

## 1. Checkpoint scope

Proven in this checkpoint:

- Routes and ordered Stops (School-owned reference data).
- Vehicles (School-owned fleet reference data), activate/deactivate.
- Driver = existing HR `Employee`, referenced (never duplicated).
- Route ↔ Vehicle ↔ Driver historical operational assignment.
- Student Transport assignment (Route + optional pickup/drop-off
  Stops).
- Historical-record survival after Route/Stop/Vehicle deactivation.
- Cross-School rejection at the database level for every reference.
- Row-Level Security on every new table.
- Capability-based authorization (no role-name checks).
- Admin JSON API (`/api/v1`) + Inertia UI.
- Audit trail for every significant state change.

Explicitly NOT in scope: GPS/fleet-telematics, live tracking, bus
boarding/attendance, Transport fees, Documents integration for
vehicle/driver documents, automated delay notifications. See §17
"Explicit non-scope" for the full list and reasoning.

**P2 assumption**: no local Phase 10 planning document for Transport
was found in this repository, the user's home directory, or any
accessible paste-cache at the time this checkpoint began (the same
search performed, and the same conclusion reached, for Phase 10A/
Library). This checkpoint implements only the foundation explicitly
specified in the Phase 10B checkpoint brief itself — it does not
invent a full commercial transport-management product.

## 2. Domain boundary

`apps/platform/app/Domain/Transport/` — `Domain/Application/
Infrastructure/Http`, the same four-layer shape every other module in
this repository uses.

Transport OWNS: `TransportRoute`, `TransportStop`,
`TransportVehicle`, `TransportRouteAssignment`,
`TransportStudentAssignment`.

Transport REFERENCES, never duplicates or owns:

- `Student` (Students/SIS domain) — Transport never writes Student
  identity/status.
- `Employee` (HR domain) — Transport never writes Employee identity/
  status; a driver is an ordinary `Employee` referenced by id, with no
  new "driver" identity record and no dependency on a specific
  Position/job title.
- `Campus` (Campus/Academic Structure domain) — optional Route/Vehicle
  ownership, never duplicated location data.

No bidirectional coupling: Students, HR, and Campus have zero
knowledge of Transport. Transport depends on them; they never depend
on Transport.

## 3. Domain model

### TransportRoute

A reusable route definition. `code` (unique per School, normalized via
the existing `NormalizesCode`/`NormalizesCodeInput` traits), `name`,
`description`, `status` (active/inactive, no delete endpoint),
optional `campus_id`.

### TransportStop

An ordered pickup/drop-off point belonging to exactly one Route.
`sequence` (explicit integer ordering, unique per Route — mirrors
GradeLevel's rule-67 precedent: order cannot be inferred from a name
string), `name`, `address`, `status`.

### TransportVehicle

A School Transport vehicle. `code` (unique per School, normalized),
`registration_number` (unique per School, deliberately NOT normalized
— it is real-world government-issued reference data, not a
School-chosen identifier), `capacity` (nullable, informational-only —
see §7), optional `campus_id`, `status`.

### TransportRouteAssignment

The historical Route ↔ Vehicle ↔ Driver operational configuration.
Deliberately a dedicated table, NOT `vehicle_id`/`driver_employee_id`
columns on `transport_routes` — the checkpoint brief explicitly warns
against that shape when the relationship changes over time, and it
does: a School reassigns vehicles/drivers to routes routinely, and
rewriting `transport_routes` in place would silently destroy the
historical record of who drove which vehicle on which route, when.
`vehicle_id` and `driver_employee_id` are both required (not
nullable) — an assignment row represents a COMPLETE configuration, not
a partial state. See §8 for the assignment lifecycle.

### TransportStudentAssignment

One Student's Transport assignment: a Route plus an optional pickup
Stop and an optional drop-off Stop. See §9 "Direction/AM-PM modeling"
and §10 for the lifecycle.

## 4. Campus ownership decision

Both `TransportRoute.campus_id` and `TransportVehicle.campus_id` are
nullable/optional — the exact `library_copies.campus_id` precedent. A
Route/Vehicle MAY serve one specific Campus in a multi-campus School,
or may be School-wide shared Transport (the common case: one bus fleet
serving all of a School's campuses). A single-campus School simply
leaves it null. Nothing structurally prevents a multi-campus School
from sharing Transport across Campuses — the checkpoint brief's
explicit requirement. No separate Transport location hierarchy was
invented.

## 5. Route operational assignment decision

`transport_route_assignments` enforces ONE active assignment per Route
at a time via a partial unique index
(`transport_route_assignments_one_active_per_route`), the exact
`library_loans_one_active_per_copy` pattern applied to a structurally
identical problem. Deliberately NOT the mirror constraint (one active
assignment per Vehicle) — a School may legitimately run the same
physical vehicle on two different Routes at different times of day
without this checkpoint modeling a full session/timetable engine
(checkpoint brief's explicit permission to avoid that complexity).

`TransportRouteAssignmentService::assign()` follows the
`AcademicYearService::activate()` AUTO-REPLACE precedent: assigning a
new Vehicle/Driver to a Route automatically ends whatever was
previously active for that Route, in the same transaction. Day-to-day
operational reassignment (a replacement driver, a swapped vehicle) is
administrative housekeeping, not a fact worth forcing a separate
explicit `end()` call for — the opposite reasoning from Student
assignment below.

## 6. Student assignment model

`TransportStudentAssignmentService::assign()` follows the
`LibraryLoanService::checkout()` REJECT-IF-OCCUPIED precedent instead:
if the Student already has an active assignment, `assign()` throws
`StudentAlreadyAssignedException` — the caller must explicitly `end()`
the current assignment first. A Student's Transport assignment is a
discrete fact worth an explicit transition, not administrative
housekeeping (the opposite of Route operational assignment above).

`transport_student_assignments` enforces ONE active assignment per
Student at a time via a partial unique index
(`transport_student_assignments_one_active_per_student`).

## 7. Vehicle capacity invariant

`capacity` is informational/reporting only in this checkpoint — no
seat-count enforcement against `transport_student_assignments` is
implemented. Storing it now costs nothing and avoids a future
migration if reporting needs it later; real enforcement would need its
own concurrency-safe design (counting current active assignments per
Route against the assigned Vehicle's capacity, itself requiring a
locking discipline) and is explicitly deferred — the checkpoint
brief's explicit permission to avoid unnecessary concurrency
complexity purely because a capacity field exists.

## 8. Employee driver reference

A driver is an ordinary, existing `App\Domain\HR\Infrastructure\
Employee`, referenced via a composite FK
`(driver_employee_id, school_id) → employees(id, school_id)` — no
duplicate driver identity record, and no hard dependency on a specific
Position/job title (capability-based authorization, not job title,
controls who MANAGES Transport; a driver being assignable has nothing
to do with which capabilities that Employee's own User account, if
any, holds).

Eligibility check: `Employee::isActive()` (`record_status ===
'active'`) — this Employee row's own existence state, the same minimal
"is this a going concern" precedent `Student::isActive()` established
for Library's Student eligibility. Deliberately NOT the more nuanced
`EmployeeDirectoryService`'s date-range "current employment" query
(`EmploymentRecord.starts_on <= today AND (ends_on IS NULL OR ends_on
>= today)`) — that is directory-display-specific logic, not a
canonical "can this Employee be assigned as a driver" rule for this
checkpoint's minimal scope. Driver change never corrupts assignment
history — see §11.

## 9. Direction/AM-PM modeling

ONE `TransportStudentAssignment` row carries BOTH an optional pickup
Stop and an optional drop-off Stop on the SAME Route — deliberately
NOT two separate AM/PM assignment rows, and deliberately NOT a
"direction" column. This is the smallest model that satisfies "a
Student may have different pickup and drop-off Stops."

Supporting a Student riding genuinely DIFFERENT Routes inbound vs.
outbound is explicitly out of scope for this checkpoint — a documented
assumption, not a repository requirement discovered during
implementation. A future checkpoint adding that would need either a
`direction` column with its own partial-uniqueness scope
(`one active per (student, direction)` instead of per-student) or two
independent assignment "slots," and should design that deliberately
rather than this checkpoint guessing at the shape.

## 10. Stop integrity (structural enforcement)

Checkpoint brief §13's core invariant: a Student assignment's pickup/
drop-off Stop must belong to the SAME Route as the assignment. This is
enforced STRUCTURALLY, not only by controller validation:

- `transport_stops` carries `unique(id, route_id, school_id)`, in
  addition to the standard `unique(id, school_id)`.
- `transport_student_assignments.pickup_stop_id`/`dropoff_stop_id` are
  3-column composite FKs against exactly that unique:
  `(pickup_stop_id, route_id, school_id) → transport_stops(id,
  route_id, school_id)`.

A Stop belonging to Route A referenced by an assignment naming Route B
is rejected by PostgreSQL itself, proven with a raw privileged INSERT
that bypasses application validation entirely — see
`tests/Feature/Postgres/TransportStudentAssignmentsRlsIsolationTest::
a_pickup_stop_from_a_different_route_is_rejected_at_the_database_level`
(and its drop-off mirror). PostgreSQL's default FK `MATCH SIMPLE`
means a NULL `pickup_stop_id` (no fixed pickup point recorded) is not
checked at all — the correct behavior for an optional reference.

`TransportStudentAssignmentService::assign()` also performs a friendly
application-level check (`StopNotOnRouteException`, HTTP 422) before
ever attempting the write, turning what would otherwise be a raw
PostgreSQL foreign-key-violation `QueryException` into the project's
normal error envelope — the database constraint remains the
authoritative backstop regardless.

## 11. Reference-entity lifecycle (rule 73)

Routes, Stops, and Vehicles use `status` (active/inactive) and have NO
delete endpoint — a `TransportRouteAssignment` or
`TransportStudentAssignment` row may reference any of these
historically even after deactivation. Proven directly:
`TransportRouteLifecycleTest::
a_historical_student_assignment_survives_the_referenced_route_and_stop_being_deactivated`,
`TransportVehicleLifecycleTest::
a_historical_operational_assignment_survives_the_referenced_vehicle_being_deactivated`,
and the equivalent service-level tests in
`TransportRouteAssignmentServiceTest`/
`TransportStudentAssignmentServiceTest`. Changing a Route's name, a
Vehicle's registration, or reassigning a driver never corrupts
historical Student-assignment or operational-assignment records —
those are always new, explicit rows (§5/§6), never in-place mutations
of history.

## 12. Tenancy / RLS / composite FKs

Every Transport table: `school_id` + `App\Support\Tenancy\
BelongsToSchool` + UUIDv7 primary key + RLS enabled AND forced via
`App\Support\Tenancy\TenantRls::enable()` + `unique(id, school_id)`.

Composite FKs (School-equality enforced at the database level, never
RLS/SchoolScope alone):

| Child | Column(s) | Parent |
|---|---|---|
| `transport_routes` | `(campus_id, school_id)` | `campuses(id, school_id)` |
| `transport_stops` | `(route_id, school_id)` | `transport_routes(id, school_id)` |
| `transport_vehicles` | `(campus_id, school_id)` | `campuses(id, school_id)` |
| `transport_route_assignments` | `(route_id, school_id)` | `transport_routes(id, school_id)` |
| `transport_route_assignments` | `(vehicle_id, school_id)` | `transport_vehicles(id, school_id)` |
| `transport_route_assignments` | `(driver_employee_id, school_id)` | `employees(id, school_id)` |
| `transport_student_assignments` | `(student_id, school_id)` | `students(id, school_id)` |
| `transport_student_assignments` | `(route_id, school_id)` | `transport_routes(id, school_id)` |
| `transport_student_assignments` | `(pickup_stop_id, route_id, school_id)` | `transport_stops(id, route_id, school_id)` |
| `transport_student_assignments` | `(dropoff_stop_id, route_id, school_id)` | `transport_stops(id, route_id, school_id)` |

Every one of these is proven rejected at the raw-SQL level (a real
`pgsql_admin` privileged INSERT bypassing RLS entirely) in
`tests/Feature/Postgres/Transport*RlsIsolationTest.php` — 35 tests, 45
assertions, covering RLS-enabled-and-forced, no-context-zero-rows,
cross-School SELECT/INSERT/UPDATE rejection, every composite-FK
cross-School rejection above, the Stop-belongs-to-wrong-Route case
(§10), and both partial unique indexes.

`student_id` cascades on delete (an assignment has no meaning
independent of its Student, mirroring `library_loans.student_id`);
every other reference restricts (none of Route/Stop/Vehicle/Employee
expose a delete endpoint, so this is defensive-only, matching every
other composite FK in this codebase without a true ownership
relationship).

## 13. One-active-per-Route / one-active-per-Student invariants

Both enforced with PostgreSQL partial unique indexes:

- `transport_route_assignments_one_active_per_route ON
  transport_route_assignments (route_id) WHERE status = 'active'`
- `transport_student_assignments_one_active_per_student ON
  transport_student_assignments (student_id) WHERE status = 'active'`

Both services additionally take `lockForUpdate()` on the shared row
both a would-be racer and the call target touch (the Route row for
operational assignment, the Student row for Student assignment) —
PostgreSQL serializes any two concurrent `assign()` calls on that lock
before either reaches its own invariant check, mirroring
`LibraryLoanService::checkout()`'s and `AcademicYearService::
activate()`'s locking discipline exactly.

Proven under REAL two-process concurrency (not a sequential
simulation), using the project's established
`Symfony\Component\Process\Process::start()`/`wait()` harness pattern
(`tests/Support/assign-transport-route.php`,
`tests/Support/assign-transport-student.php`,
`TransportRouteAssignmentConcurrencyTest`,
`TransportStudentAssignmentConcurrencyTest`):

- Route assignment: both concurrent `assign()` calls succeed (the
  auto-replace model never rejects); the end state is proven to hold
  exactly one active assignment and two total rows (one ended, one
  active) — the row lock serializes the auto-replace correctly.
- Student assignment: exactly one of two concurrent `assign()` calls
  for the same Student succeeds; the loser is caught by its own
  post-lock "already assigned" check
  (`StudentAlreadyAssignedException`), never the raw unique-constraint
  path — proven separately and directly by the RLS isolation test's
  `the_database_rejects_a_second_active_assignment_for_the_same_student`.

## 14. Capabilities

Three independently gateable areas, mirroring Library's catalogue/
circulation split:

- `transport.routes.view` / `transport.routes.manage` — Routes AND
  Stops together (a Stop has no independent meaning outside its Route,
  matching Library's Copy-under-Title precedent — no separate
  `transport.stops.*` pair).
- `transport.vehicles.view` / `transport.vehicles.manage` — the
  Vehicle fleet AND the Route Vehicle/Driver operational assignment
  (assigning a Vehicle/Driver to a Route is fleet-operations work, not
  Student-facing work).
- `transport.assignments.view` / `transport.assignments.manage` —
  Student Transport assignment only.

No capability-inheritance exists in this codebase — a role needing
both view and manage in an area is granted both explicitly.
`school_admin` and `principal` hold all six by default, matching the
"day-to-day operational parity" precedent already established for
`library.*`/`students.*`/`guardians.*`/`enrollments.*` on those roles.
No new dedicated driver/transport-staff system role was created (not
genuinely required for this checkpoint — a School wanting narrower
Transport-only staff can already compose a custom role from these six
capabilities via the existing role system).

Proven: `tests/Feature/Authorization/TransportCapabilityTest.php` (13
tests, 51 assertions) — catalog integrity, no speculative capabilities,
default role grants, area/view-manage independence, tenant isolation,
central-identity-alone denial, existing grants unaffected.

## 15. Audit

Every significant state change is recorded via the existing
`App\Support\Audit\AuditRecorder::school()`:
`transport.route.created`/`.updated`, `transport.stop.created`/
`.updated`, `transport.vehicle.created`/`.updated`,
`transport.route_assignment.assigned`/`.ended`,
`transport.student_assignment.assigned`/`.ended`. Metadata carries IDs
and codes only — never Student name/address or other Sensitive data
(`docs/security/DATA-CLASSIFICATION.md`).

## 16. Events

No domain events are emitted for routine Route/Stop/Vehicle CRUD or
operational assignment (checkpoint brief's explicit instruction — not
every state change needs an event). A `TransportAssignmentChanged`
event was considered and explicitly DEFERRED: there is no current
consumer for it (no Communications-domain guardian-alert wiring
exists yet — see §18) and no existing convention in this codebase
favors emitting an event purely for potential future use. `BusDelayed`
is explicitly OUT OF SCOPE — this checkpoint deliberately does not
build a fake "delay" workflow or button solely to satisfy an event
name; there is no trip/delay model to attach it to. Any future event
this module emits will use the existing transactional outbox (ADR
0025), never a bespoke queue.

## 17. Explicit non-scope (deferred, not forgotten)

- Live GPS/location tracking, maps, geofencing, ETA, route
  optimization, external telematics/mapping APIs. Student Transport
  location is Sensitive data — building this without a dedicated
  privacy/architecture review would risk accidentally creating a
  location-surveillance platform. Requires its own review before any
  future checkpoint attempts it.
- Bus boarding/attendance: RFID/QR/NFC/biometric scanning, "student on
  bus" attendance, parent pickup confirmation.
- Transport fees/billing: no fee fields, payment status, receivable
  tables, or ledger entries on any Transport table. A future
  integration must use the real Finance/Fees domain (not yet built),
  never parallel financial logic in Transport.
- Fuel logs, vehicle maintenance, insurance workflow, accident/
  insurance claims.
- Driver mobile app, Student/Guardian Transport portal (this
  checkpoint is admin-only UI).
- Documents integration: vehicle registration/insurance, driver
  license, and route documents are NOT modeled as Document-domain
  entities in this checkpoint. The Documents exclusive-owner
  architecture (`docs/modules/DOCUMENTS.md`) was not modified.
- Communications integration: no Transport-specific email/SMS, no new
  Communications audience type. Guardian pickup/drop-off alerts, if
  ever built, are a distinct future Transport checkpoint's job, and
  should reuse the existing Communications domain rather than
  Transport growing its own notification logic.
- Advanced analytics, AI route planning, automated delay
  notifications, the `BusDelayed` event/workflow (§16).

## 18. Communications / Documents decision

No changes were made to either domain. Transport's admin UI and API
are entirely self-contained; nothing in this checkpoint required
extending `WebhookEventRegistry`, the Communications audience-type
enum, or the Documents exclusive-owner arc.

## 19. API surface

`/api/v1/schools/{schoolId}/transport-routes[...]`,
`/transport-vehicles[...]`, `/transport-routes/{id}/assignments[...]`,
`/transport-route-assignments/{id}/end`,
`/transport-student-assignments[...]`. No public/mobile-specific API.
GET (list/show) endpoints authorize inside the controller
(`AuthorizesCapability` trait) rather than via route middleware,
matching every other simple-CRUD module's precedent; mutating routes
additionally carry `capability:`/`throttle:school-api-mutations`
route middleware.

`idempotent` middleware is applied ONLY to the two "assign" mutations
(`POST .../transport-routes/{id}/assignments`, `POST
.../transport-student-assignments`) — a network retry of either could
otherwise silently duplicate a costly side effect (ending-and-
recreating the same operational assignment, or a confusing rejection
for what the client experienced as a lost response to an
already-successful Student assignment), the exact reasoning
`LibraryLoanController::store()` already established for checkout.
Route/Stop/Vehicle creation and both `end()` actions rely on their own
unique-constraint/conditional-update retry-safety instead, matching
Library's Title/Copy creation and `checkIn()` precedent — idempotency
tests were NOT blindly copied from Library; each endpoint's actual
retry-safety mechanism was evaluated on its own terms (see
`TransportApiTest`'s replay/conflict/authorization-still-evaluated
proofs for both idempotent endpoints).

OpenAPI (`packages/contracts/openapi/school-os-api.yaml`) documents
every path/schema; `packages/shared-types` was regenerated via `npm
run generate` with verified zero drift (identical output on a second
run).

## 20. Administrative UI

Session-authenticated Inertia pages under `/app/transport/...`:
Routes (list/create/show-with-ordered-Stops/activate-deactivate),
Vehicles (list/create/activate-deactivate), Route Operations
(current Route↔Vehicle↔Driver, assign/reassign/end, history), Student
Assignments (search/select Student, assign Route + optional Stops,
list current, end). Reuses the existing `EmptyState`/`Pagination`/
`StatusBadge` components and layout conventions verbatim — no
app-shell redesign. No Guardian/Student-facing views this checkpoint.

The Operations and Assignments forms' live search/lookup endpoints
(`/app/transport/operations/search/{vehicles,drivers}`,
`/app/transport/assignments/search/{students,routes}`,
`/app/transport/assignments/routes/{id}/stops`) explicitly call
`authorizeCapability()` — the specific mistake the Phase 10A Library
checkpoint's security review found and fixed in its own checkout
search endpoints. Regression-tested directly:
`TransportAdminUiTest::
a_member_without_vehicles_manage_cannot_use_the_operations_search_endpoints`
and
`a_member_without_assignments_manage_cannot_use_the_assignment_search_endpoints`
prove an authenticated School member without the relevant capability
cannot enumerate Vehicle registration numbers, Employee names, Student
names, or Route/Stop data through these endpoints.

## 21. Security review

Reviewed and addressed/proven-safe for every item the checkpoint brief
required:

- Cross-School IDOR: every Route/Vehicle/RouteAssignment/
  StudentAssignment lookup 404s on a cross-School id
  (`TransportApiTest`).
- RLS escape: proven at the raw-SQL level for all 5 tables (§12).
- Composite-FK bypass: proven rejected for every parent reference,
  including the Stop-belongs-to-wrong-Route case (§10, §12).
- Unauthorized Student/Employee search: the anti-P1 regression tests
  (§20).
- Unsafe mass assignment: every controller validates an explicit field
  allow-list; no `$request->all()` passed to `create()`/`update()`.
- Sensitive-data leakage in audit metadata: IDs/codes only (§15).
- Race conditions / duplicate assignments: proven under real
  concurrency (§13).
- Stop/route mismatch: proven at the database level (§10).
- Inactive-resource assignment: `VehicleNotEligibleException`,
  `DriverNotEligibleException`, `RouteNotAvailableException`,
  `StudentNotEligibleException` — each has a dedicated test.
- Idempotency bypass / authorization-still-evaluated-on-replay:
  `TransportApiTest::
  a_student_assignment_replay_after_losing_the_manage_capability_is_denied`.
- Accidental historical deletion: no delete endpoint exists on any
  Transport table (§11); reference entities never hard-delete.

No P0/P1/P2/P3 findings remain open.

## 22. Test evidence

- Postgres/RLS: 35 tests, 45 assertions
  (`tests/Feature/Postgres/Transport*RlsIsolationTest.php`, 5 files).
- Authorization: 13 tests, 51 assertions
  (`tests/Feature/Authorization/TransportCapabilityTest.php`).
- Domain/service lifecycle: 19 tests, 43 assertions
  (`TransportRouteAssignmentServiceTest`,
  `TransportStudentAssignmentServiceTest`).
- Route/Vehicle lifecycle (API): 16 tests, 40 assertions
  (`TransportRouteLifecycleTest`, `TransportVehicleLifecycleTest`).
- Full API surface + idempotency: 16 tests, 34 assertions
  (`TransportApiTest`).
- Real two-process concurrency: 2 tests, 7 assertions
  (`TransportRouteAssignmentConcurrencyTest`,
  `TransportStudentAssignmentConcurrencyTest`).
- Admin Inertia UI + anti-P1 search-endpoint regressions: 15 tests, 92
  assertions (`tests/Feature/App/TransportAdminUiTest.php`).

Total: 116 tests, 319 assertions, all passing, zero skipped.

## Retention (E21.3B, 2026-10-02)

A Student's **ended** Transport assignments are D7 operational Student
history: they are deleted 7 calendar years after the Student's final exit,
by `platform:student-retention-prune` through
`App\Domain\Transport\Application\Retention\TransportAssignmentRetentionService`,
one Student per transaction under the Student-row lock.
- An **active** assignment is never deleted on age, and it keeps the
  Student's core record.
- Routes, stops and vehicles are School configuration and stay. Driver
  (route) assignments are E21.3E (7 y after `ends_on`). No Finance row
  references a Student assignment.

Project-adopted, pending legal ratification (`docs/security/E21-RETENTION-DETERMINATION.md`
§5.6). Holds (`RETENTION_HOLD_SCHOOL_IDS`) keep everything; `--dry-run`
counts with the same rule.

## Driver-assignment retention (E21.3E, 2026-10-02)

An ended driver (route) assignment is deleted 7 calendar years after its
`ends_on` by `platform:operations-retention-prune`
(`DriverAssignmentRetentionService`). Ending is one-way (a new assignment is
a new row); an active assignment is never eligible. The driver reference
goes with the row; routes, stops and vehicles stay. Project-adopted, pending legal ratification (`docs/security/E21-RETENTION-DETERMINATION.md` §5.9).
