# School OS — HR & Employee Records Module (Phase 8A)

Phase 8A.0. This document is the architecture contract for the HR/People
domain — `docs/architecture/DOMAIN-MAP.md` Layer 2's `HR` row. It covers
scope for checkpoints 8A.0–8A.16 only; Payroll proper (statutory
PF/ESI/TDS compliance) remains a distinct, later effort — see
"Relationship to the documented roadmap" below.

Development happens entirely on `feature/phase-8a-hr-employee-records`,
branched from `main` at `6edbe2d` (Phase 0D). No merge to `main` until
the Phase 8A.16 closure gate.

## Relationship to the documented roadmap

`docs/roadmap/MASTER-ROADMAP.md` sequences HR as **"Phase 0J — HR and
Payroll,"** documented as coming after Phase 0E (Documents/
Communications), 0F (Students/SIS/Guardians/Admissions), 0G (Finance/
Fees), 0H (Academic Operations), and 0I (LMS) — none of which are built
yet. Phase 8A is a deliberate, explicit exception to that documented
order, made because:

- `DOMAIN-MAP.md`'s own dependency table already scopes HR's real
  technical dependencies narrowly: **Schools and Identity & Access
  only** — both complete since Phase 0B/0D. HR does not depend on
  Students/SIS, Finance, Academic Operations, or LMS to exist.
- Several Layer 3 modules (Academics, Timetable, Attendance, LMS,
  Transport, Library, Inventory, Visitor/Safety) already list HR as a
  dependency in that same table — HR sits *underneath* them, so
  building it now, ahead of modules that depend on it, does not create
  a forward reference.

The one real cost of this resequencing: **Phase 0E (Documents) does not
exist yet**, and Phase 8A.7 (Employee Documents) needs somewhere to
store files. See "Documents — narrow scope, not a parallel system"
below for how that's handled without violating root `CLAUDE.md` rule 2
("no speculative frameworks or infrastructure"). See ADR 0028 for the
full decision record.

`docs/roadmap/MASTER-ROADMAP.md` is annotated (not rewritten) to point
here; its Phase 0E–0J entries stay unchanged since those modules are
still unbuilt and still needed in that scope/order for what they own
beyond HR.

## Canonical terminology (locked)

| Term | Meaning | Avoid |
|---|---|---|
| `Employee` | The personnel/HR identity — a person the School has an HR record for. Distinct from `User` (login identity). | `Staff`, `Personnel` as a table/model name |
| `Employment` | One legal/organizational engagement of an Employee with the School (start, end, terms). An Employee may have more than one over time (rehire). | — |
| `Assignment` | Where/how an Employee works *within* one Employment: Campus, Department, Position, manager, primary/secondary, effective dates. | — |
| `Department` (HR) | Organizational/administrative grouping (Admin, Accounts, Facilities, Transport, Front Office, …) — **reserved name is `hr_departments`/`App\Domain\HR\...\Department`**, never bare `Department` colliding with anything, and never `AcademicDepartment` (Academic Structure's `academic_departments` already exists and is a *different* concept — grouping of Subjects, not of staff). | `AcademicDepartment` for HR's org units |
| `Position` | A job/organizational title (Teacher, Accountant, Principal, Librarian, Driver, …) — **not** an authorization role. | `Role`, `Designation` as the primary term (may keep `designation` as a synonym label in UI copy only) |
| `EmployeeCategory` | School-configurable classification (e.g. Teaching / Non-Teaching / Contract / Visiting) — reference data, not hardcoded enum. | — |

## Entity model

```
School (Phase 0B/0D)
  └─ Campus (Phase 0B/0D)
  └─ HR Department  (School-wide or Campus-scoped; NEW, hr_departments — distinct from academic_departments)
  └─ Position        (School-wide; NEW, positions)
  └─ EmployeeCategory (School-wide reference data; NEW, employee_categories)
  └─ Employee (NEW)
       ├─ User?                        (optional 0/1 account linkage, nullable user_id)
       ├─ EmployeePersonalDetail       (1:1 — Restricted tier)
       ├─ EmployeeAddress              (1:N — Restricted tier)
       ├─ EmployeeEmergencyContact     (1:N — Restricted tier)
       ├─ EmploymentRecord (1:N — rehire = second row, never a duplicate Employee)
       │      └─ EmployeeAssignment (1:N per Employment)
       │             ├─ Campus (nullable = School-wide assignment)
       │             ├─ HR Department (nullable)
       │             ├─ Position (required)
       │             └─ manager_assignment_id → EmployeeAssignment (self-referencing, nullable)
       ├─ EmployeeQualification        (1:N — Restricted tier)
       ├─ EmployeeExperience           (1:N — Restricted tier)
       ├─ EmployeeCertification        (1:N — Restricted tier)
       ├─ EmployeeDocument             (1:N — tier inherited from document content; see below)
       └─ EmployeeNote                 (1:N — Restricted/Confidential, HR-authored)
```

Deliberately **not** modeled in Phase 8A (candidate entities rejected or
deferred — see "Reuse decisions" below): a generic cross-module
`Person`/`Party` abstraction (none exists in the repo; `Employee` stays
its own record, exactly like the future `Student` record will be its
own — Guardians/Students/Employees are siblings, not subtypes of one
shared table, matching how `User` deliberately stays a narrow auth-only
identity per its existing docblock).

## Domain principles (repo-verified, not assumed)

### 2.1 — User is not Employee

Confirmed already true in the existing codebase: `App\Models\User` is
central (no `school_id`), auth-identity-only, and its own docblock
already states future domain records (Employee, Teacher, Guardian,
Student) link to a User only when they need login — they don't extend
it. `employees.user_id` is a **nullable, optional** foreign key to
`users(id)`. An Employee row can exist with `user_id = null`
indefinitely (pre-joining, no application access ever provisioned).
Losing/disabling the linked User account never mutates the Employee
row — `employees` has no `account_status` column; that state lives
entirely on `users`/`school_memberships`.

### 2.2/2.3 — Employee ≠ Employment ≠ Assignment

Locked as a three-table split (see Entity model). Rejected the flat
`employees.joined_at`/`left_at` shortcut explicitly — it cannot
represent rehire (two employment spans) or a mid-employment
campus/department/position change (which needs to preserve the prior
Assignment row for history, not overwrite it) without a schema
migration later. This mirrors the Academic Structure precedent
(`Section` never reused/mutated across years — see
`docs/modules/ACADEMIC-STRUCTURE.md`).

### 2.4 — Position ≠ Authorization Role

`positions` carries no relationship to `capabilities`/
`membership_role_assignments` whatsoever. Granting a Principal's actual
application access remains a completely separate, existing action
(assigning a school-scoped Role via `MembershipRoleAssignment`) that an
HR Staff member with `hr.employee.update` cannot themselves perform —
that requires whatever capability already gates role assignment. HR
records the job; Identity & Access grants application power. A future
convenience ("when Position = Principal, suggest the Principal role")
is an explicit, human-confirmed UI suggestion at most, never an
automatic grant — out of scope for Phase 8A entirely.

### 2.5 — Campus is not Tenant

Unchanged. Every HR table is `school_id`-scoped (RLS); `campus_id`
columns (on `Assignment`, optionally `Department`) are a nullable
organizational dimension only, exactly like Academic Structure's
existing Campus-scoped entities (Room, Section, Subject Offering).

### 2.6 — Employee status ≠ Account status

`employees` (or `employment_records`, see Lifecycle model below) never
reads or writes anything on `users`/`school_memberships`, and vice
versa. A "Pre-Joining, no account" or "Separated, account already
disabled separately" state pair is representable by construction
because the two status columns live on different tables with no
trigger/observer coupling them.

## Reuse decisions

| Component | Decision | Why |
|---|---|---|
| `BelongsToSchool` / `SchoolScope` / `TenantContext` | **REUSE** | Every HR table gets this exactly like Campus/AcademicDepartment/Section — no alternative exists or should exist (CLAUDE.md rule 17). |
| `TenantRls::enable()/disable()`/`makeAppendOnly()` | **REUSE** | Same as every tenant-owned table since Phase 0B (rule 18). `EmployeeDocument` and any HR audit-adjacent append-only concept use `makeAppendOnly` where genuinely append-only. |
| Composite FK `(id, school_id)` pattern | **REUSE** | Direct copy of the `sections`/`subject_offerings` pattern — `EmployeeAssignment` → Campus/HR-Department/Position, `Employee` → Campus (home campus, if modeled), all use this, not RLS/SchoolScope alone (rule 70). |
| `NormalizesCode`/`NormalizesCodeInput` | **REUSE** | `Department.code`, `Position.code`, `EmployeeCategory.code` follow the exact Campus/AcademicDepartment convention. `employee_number` is **not** a `code` in this sense — see Employee Identifier Strategy. |
| `App\Support\Audit\AuditRecorder` (`school()` method) | **REUSE** | Every HR state change (`employee.created`, `employment.started`, `assignment.changed`, `employee.separated`, sensitive-field views once implemented) calls `AuditRecorder::school()` exactly like `AcademicYearService`. No parallel HR audit table. |
| `ShouldBeOutboxed` / transactional outbox / `WebhookEventRegistry` | **REUSE** | New HR domain events (see below) follow the exact `GradeLevelCreated`-style pattern; none are added to `WebhookEventRegistry::CATALOG` in Phase 8A unless a real external-integration need is identified and reviewed (rule 45) — default is internal-only. |
| `CapabilityResolver` / `AuthorizesCapability` / `EnsureCapability` | **REUSE** | New `hr.*` capabilities registered the same way `academics.*`/`integrations.webhooks.*` were (see Authorization design below). No role-name branching anywhere (rule 24). |
| `GeneratesUuidV7` | **REUSE** | Every new table's PK, per ADR 0019. No exception needed (HR has no catalog-like string-PK case comparable to `capabilities.key`). |
| `TenantStoragePath::for()` | **REUSE, first real caller** | See "Documents — narrow scope" below. |
| Plain-array `present()` controller pattern (Campus/SchoolProfile controllers) | **EXTEND with care** | Reusable for simple entities (Department, Position, EmployeeCategory — rule 76's thin-controller carve-out) but the Employee/Employment/Assignment surface needs **capability-aware field suppression** (Restricted/Highly-Sensitive fields excluded unless the caller holds `hr.employee.sensitive.view`/`.personal.view`) which neither existing controller currently does — this is new, justified logic for 8A.9/8A.14, not a deviation for its own sake. |
| Generic `Person`/`Party` abstraction | **REJECT** | None exists; not creating one now. Employee stays a standalone record, matching how the codebase already treats `User` as narrow and non-extensible. |
| A separate HR audit table | **REJECT** | `school_audit_events` is authoritative and sufficient (rule 11). |
| A new blob-storage/Document abstraction | **REJECT for 8A** (see below) | Root CLAUDE.md rule 2; `TenantStoragePath` + a narrow `employee_documents` table is enough for Phase 8A's actual need. |
| Elasticsearch/Algolia/external search | **REJECT** | No justification yet; PostgreSQL `ILIKE`/trigram (`pg_trgm`, already available since it's stock PostgreSQL 16) on `employees.full_name_search`/`employee_number` covers the directory search need described in section 22. Revisit only if real scale data shows it's insufficient. |
| Laravel Scout | **DEFER** | Not currently used anywhere in the repo; introducing it solely for HR directory search when `ILIKE`/trigram suffices would be exactly the "speculative infrastructure" root CLAUDE.md rule 2 forbids. |
| A configurable employee-numbering *engine* (school-defined formats) | **DEFER to a later 8A checkpoint or beyond** | Section 7 of the brief explicitly permits deferring this; 8A.1 ships one fixed, safe format (see below), not a configuration UI. |
| Import framework | **DEFER** | No existing importer to reuse; 8A.12 designs and builds a narrow CSV/XLSX importer for Employee specifically, not a generic platform import engine (that would itself be speculative infra outside HR's scope). |

## Documents — narrow scope, not a parallel system

Confirmed: **no Documents module exists** (ADR 0012 is written but
unimplemented; `TenantStoragePath::for(School, string $path)` exists
today with zero callers, explicitly laid down for this exact future
need). Phase 8A.7 will be the first real consumer:

- `employee_documents` (School-owned, RLS) stores **metadata only**:
  `id`, `school_id`, `employee_id` (composite FK to `employees(id,
  school_id)`), `category` (reference value: ID proof, qualification
  certificate, contract, photo, …), `classification_tier`
  (`directory`/`restricted`/`highly_sensitive` — see Privacy model),
  `storage_disk`, `storage_path` (built via `TenantStoragePath::for()`,
  never a caller-concatenated fragment — rule 23), `original_filename`,
  `mime_type`, `size_bytes`, `uploaded_by_user_id`, `uploaded_at`,
  `status` (`active`/`archived` — never hard-deleted once an Employee
  has any employment history referencing the period it covers).
- No generic `Document`/`Attachment` polymorphic model, no versioning
  system, no signed-URL infrastructure beyond what Laravel's existing
  storage disks already provide — those genuinely belong to the future
  Phase 0E Documents module's larger, cross-module design.
- **Explicit consequence, tracked in ADR 0028**: when Phase 0E's real
  Documents module is eventually built, it will need its own
  reconciliation/migration step to decide whether `employee_documents`
  gets absorbed into a general `documents` table or stays a
  domain-specific metadata table pointing at shared storage
  infrastructure. That is deliberately **not** decided now — deciding
  it here would be exactly the kind of premature cross-module design
  root CLAUDE.md rule 2 warns against.

## Employee identifier strategy

- **Primary key**: UUIDv7 (`GeneratesUuidV7`), per ADR 0019 — never
  exposed as the human-facing employee number.
- **Human-readable business identifier**: `employees.employee_number`,
  a plain string, **unique per School** (`unique(school_id,
  employee_number)`), immutable after assignment (no update path
  exposed via any capability once set).
- **Fixed Phase 8A.1 format**: `EMP-{6-digit zero-padded sequence}`
  (e.g. `EMP-000001`), sequence **scoped per School**, not global —
  matches the brief's example and keeps the format simple enough not to
  need a configuration engine yet (deferred, see Reuse decisions).
- **Concurrency-safe generation, not naive check-then-insert**: same
  principle CLAUDE.md rule 30 establishes for idempotency keys applies
  here — a bare `SELECT MAX(...) + 1` race is not acceptable under
  concurrent employee creation. 8A.1 uses a per-School counter row
  (`hr_employee_number_counters(school_id, next_value)`) locked with
  `SELECT ... FOR UPDATE` inside the same transaction that creates the
  Employee, with the final `unique(school_id, employee_number)`
  constraint as the authoritative backstop (mirroring how
  `AcademicYear` activation is proven safe under genuine concurrency in
  `AcademicYearActivationConcurrencyTest` — 8A.1 needs an equivalent
  two-process proof for employee-number allocation).
- Rehire reuses the **same** `employee_number` — a second
  `EmploymentRecord` row under the same `Employee`, never a new
  Employee/new number.

## Employee core schema (8A.1, implemented)

`employees` (School-owned, `TenantRls`-protected): `id` (UUIDv7 PK),
`school_id` (FK, RLS-scoped), `user_id` (nullable FK to `users`,
`unique(school_id, user_id)` — see "User linkage" below), `employee_number`
(`unique(school_id, employee_number)`, immutable — enforced by a
`static::updating()` guard that throws
`EmployeeNumberIsImmutableException` on any attempted change),
`full_name` (single string field, not split first/middle/last —
matches the repo's existing `users.name` convention; a structured
name breakdown was not part of the accepted 8A.0 contract and is not
introduced speculatively here), `record_status` (`active`/`archived`,
plain string column matching the `academic_years.status`/
`grade_levels.status` convention — not database-CHECK-constrained,
enforced at the application layer), `created_at`/`updated_at`.
`hr_employee_number_counters` (School-owned, `TenantRls`-protected,
`unique(school_id)`): `id` (UUIDv7 PK), `school_id`, `next_value`.

**User linkage**: `unique(school_id, user_id)`, not a global
`unique(user_id)` — matches this document's own recommendation above.
Linking additionally requires the target User to hold a real
`SchoolMembership` at the target School at link time
(`App\Domain\HR\Application\Exceptions\UnrelatedUserLinkageException`
otherwise) — this is the application-level invariant flagged as
necessary in "Database constraints" below, re-checked only at
link-time, never retroactively (a later membership suspension does not
unwind an established linkage, per principle 2.6).

**Creation pathway**: `App\Domain\HR\Application\EmployeeService::create()`
is the sole sanctioned write path — resolves School/tenant context,
validates User linkage, allocates the employee number
(`EmployeeNumberAllocator::allocate()`, `SELECT ... FOR UPDATE` inside
the same transaction as the Employee insert), persists the Employee,
audits (`AuditRecorder::school()`, `employee.created`), and emits
`App\Domain\HR\Events\EmployeeCreated` (outboxed, internal-only, not
registered in `WebhookEventRegistry`). Proven concurrency-safe with
five genuinely separate OS processes racing the same School
(`EmployeeNumberConcurrencyTest`, mirroring
`AcademicYearActivationConcurrencyTest`'s pattern) — all five receive
distinct, gapless numbers.

## Personal details, contacts & addresses (8A.2, implemented)

Three new Restricted-tier tables extend Employee (entity model's
`EmployeePersonalDetail`/`EmployeeAddress`/`EmployeeEmergencyContact`),
all School-owned, `TenantRls`-protected, composite-FK-safe against
`employees(id, school_id)` (rule 70), cascadeOnDelete on `employee_id`
(these rows have no meaning independent of their owning Employee).
Employee itself gained no new columns — only three relations
(`personalDetail()`, `addresses()`, `emergencyContacts()`).

**`employee_personal_details`** (1:1, `unique(employee_id)`): `date_of_birth`
(plain `date`, no derived-age storage), `nationality`, `marital_status`,
`preferred_language` (all plain nullable strings — not frozen into
enums, since none are branched on by application logic),
`personal_email`/`personal_phone`/`alternate_phone` (personal contact
info lives here, not a separate contact table — the entity model lists
no such table, and the privacy matrix already groups personal
phone/email with DOB/address/emergency-contacts as one Restricted
group). No government identifiers, tax identifiers, bank details, or
health data — those stay Highly Sensitive and unmodeled, per the
existing authorization design's explicit deferral.
`App\Domain\HR\Application\EmployeePersonalDetailService::setDetails()`
is the sole write path (`updateOrCreate()` keyed on `employee_id`,
mirroring `App\Support\Settings\SchoolSettingsService`'s upsert
pattern) — the unique constraint is the database-level backstop
against a genuine race producing two rows.

**`employee_addresses`** (1:N): field names
(`address_line1`/`address_line2`/`city`/`state_region`/`postal_code`/
`country_code`) are a direct copy of `schools`' own established address
shape, not a new convention invented for HR — `country_code` is a raw
ISO 3166-1 alpha-2 string (no `countries` reference table exists or is
introduced here), default `'IN'`. `address_type`
(`current`/`permanent`/`mailing`/`other`) is a plain, application-
validated string, matching the `record_status`/`academic_years.status`
convention of not database-CHECK-constraining this kind of column.
**Invariant** (HR.md was silent on exact cardinality; decided here): at
most one address of each of `current`/`permanent`/`mailing` per
Employee, but arbitrarily many `other` — enforced with a partial unique
index (`employee_addresses_one_per_type_per_employee ... WHERE
address_type <> 'other'`), the same database-enforced pattern
`academic_years_one_active_per_school` established, rather than a
separate `is_primary` boolean duplicating the same invariant through a
second mechanism.

**`employee_emergency_contacts`** (1:N): `name`, `relationship` (plain
flexible string, never branched on — not an enum), `phone` (required),
`alternate_phone`/`email` (optional), `is_primary` (boolean). No field
requires the contact to be a User/Guardian/Employee/other School OS
identity — an emergency contact is simply an external person; no
generic Party/Person subsystem was introduced. **Invariant**: at most
one `is_primary = true` row per Employee, enforced with a partial
unique index (`employee_emergency_contacts_one_primary_per_employee
... WHERE is_primary = true`).
`App\Domain\HR\Application\EmployeeEmergencyContactService::setPrimary()`
is the sole promotion path — demotes whatever was previously primary
and promotes the target contact in one transaction, mirroring
`AcademicYearService::activate()`'s exact demote-then-promote shape,
with the partial unique index as the concurrency backstop. `add()`/
`update()` never accept a caller-supplied `is_primary` value.

**Ownership/IDOR protection**: every write method on
`EmployeeAddressService`/`EmployeeEmergencyContactService` takes the
authoritative `Employee` the caller already resolved from trusted
context, strips any caller-supplied `school_id`/`employee_id` from the
attributes array, and — for `update()`/`remove()`/`setPrimary()` — re-
verifies the target record's actual `employee_id` matches before
touching it (`App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException`
otherwise). This is the same "never trust a caller-supplied id" rule
19/24 already establish for `school_id`, applied to a child record's
parent reference.

**Audit**: every mutation calls the existing `AuditRecorder::school()`
(`employee.personal_details.updated`, `employee.address.created`/
`.updated`/`.removed`, `employee.emergency_contact.created`/`.updated`/
`.removed`/`.primary_changed`) with metadata limited to ids and changed
field *names* — no personal-data values (email/phone/address text/DOB)
are ever written into audit metadata. No parallel HR audit subsystem.

**Domain events**: none added. HR.md's own Domain events list does not
include a personal-details/address/emergency-contact event, and
introducing one for every field mutation here would be exactly the
speculative-event pattern rule 27 (of the 8A.2 brief) warns against —
`AuditRecorder` is sufficient for this checkpoint's actual
requirement.

## Temporal data strategy

Following the exact naming convention `academic_years`/
`academic_terms` already established (`starts_on`/`ends_on`, plain
`date` columns, not `effective_from`/`effective_until` — the brief's
suggested naming is overridden here in favor of repo consistency):

- `employment_records.starts_on` — required, inclusive.
- `employment_records.ends_on` — **nullable**, inclusive when present;
  `null` means "current/ongoing employment," unlike AcademicYear/Term
  which are always fully bounded. This is the one deliberate semantic
  difference from the Academic Structure precedent, because employment
  genuinely is open-ended until separation happens.
- `employee_assignments.starts_on`/`ends_on` — same nullable-open-ended
  semantics, additionally constrained to fall within the owning
  Employment's `[starts_on, ends_on-or-open]` range (CHECK/validation,
  decided in 8A.4).
- **Primary assignment rule**: at most one `is_primary = true`
  Assignment per Employment with `ends_on IS NULL` (currently open) —
  enforced with a PostgreSQL **partial unique index**
  (`employee_assignments_one_primary_open_per_employment`), the same
  database-enforced pattern as `academic_years_one_active_per_school`
  (rule 64), not an application-level check-then-update.
- Overlap validation for *secondary* (non-primary) concurrent
  assignments is intentionally **not** database-constrained in 8A.4 (a
  teacher legitimately holds several simultaneous secondary
  assignments — a Postgres exclusion constraint would need per-
  dimension range logic that isn't justified yet); it is
  application-validated at write time. Revisit with a real `EXCLUDE
  USING gist` constraint only if a concrete overlap-integrity bug
  surfaces (matches the `academic_years` migration's own documented
  reasoning for staying with a simpler check over a `daterange`
  exclusion constraint).
- Future-dated changes (create an Assignment with `starts_on` in the
  future) are allowed by the schema; whether the UI/API exposes
  "effective next month" scheduling is an 8A.4/8A.9 product decision,
  not a schema constraint.

## Rehire strategy

- Employee row is never duplicated. `employee_number` persists across
  employment gaps.
- Each `EmploymentRecord` is independently timestamped and owns its own
  `Assignment` history — a rehired Employee's second `EmploymentRecord`
  starts with zero assignments, built fresh.
- `employees.user_id` linkage is **not** automatically cleared on
  separation and **not** automatically restored on rehire — both are
  explicit HR actions (reprovision/deprovision), audited separately,
  because account lifecycle is a distinct decision from HR lifecycle
  (principle 2.6).
- Prior `EmployeeAssignment` rows (including their `manager_assignment_id`
  links) are immutable history — a rehire never rewrites or reparents
  them.

## Department and Position strategy

Both are School-scoped reference data (rule 71's principle extended to
HR), following the `AcademicDepartment`/`GradeLevel` migration template
exactly (see ADR 0028 and the reference-data pattern captured during
discovery):

- `hr_departments`: `id`, `school_id`, `campus_id` (nullable —
  School-wide department when null, composite FK to `campuses(id,
  school_id)` when set), `parent_department_id` (nullable
  self-referencing FK for hierarchy — e.g. "Accounts" under
  "Administration"), `name`, `code`, `status` (`active`/`inactive`,
  string column, no PHP enum — matches `AcademicDepartment`), no delete
  endpoint (rule 73).
- `positions`: `id`, `school_id`, `name`, `code`, `description`,
  `status`, no delete endpoint. Deliberately **not** Campus-scoped —
  "Teacher" or "Accountant" is a School-wide job title; which Campus an
  Assignment happens at is the Assignment's own `campus_id`, not the
  Position's.
- Both use `unique(school_id, code)` + `unique(id, school_id)` (for
  child composite FKs) + `TenantRls::enable()`, exactly like
  `academic_departments`.

## Department and Position (8A.3, implemented)

`hr_departments` and `positions` are built exactly as specified above,
with the following as-built detail:

- **Hierarchy cycle safety**: self-parenting
  (`parent_department_id = id`) is rejected by a PostgreSQL CHECK
  constraint (`hr_departments_no_self_parent_check`) — database-
  enforced, mirroring `academic_years_date_range_check`'s use of a
  plain CHECK for a simple invariant. An indirect cycle (A's new parent
  is B, whose existing ancestry already leads back to A) cannot be
  expressed as a CHECK constraint, so
  `App\Domain\HR\Application\DepartmentService::reparent()` walks the
  proposed parent's ancestry chain and rejects it at the application
  layer (`DepartmentHierarchyCycleException`) before writing — a
  deterministic, bounded walk (each Department has at most one parent),
  not a generic graph engine.
- **Campus/parent ownership**: `campus_id` and `parent_department_id`
  composite-FK to `campuses(id, school_id)`/`hr_departments(id,
  school_id)` respectively (`nullOnDelete()` on both — losing a Campus
  or a parent Department demotes the child to School-wide/top-level
  rather than blocking the deletion or cascading it away). `positions`
  has neither column at all, per its explicit not-Campus-scoped
  design.
- **Write path**: `App\Domain\HR\Application\DepartmentService`/
  `PositionService` are the sole sanctioned write paths, mirroring
  `EmployeeService`'s shape exactly. Both accept the authoritative
  School/Campus/parent-Department as real, already-resolved model
  instances — never a raw caller-supplied id — and `create()` validates
  Campus/parent same-School ownership before writing
  (`DepartmentCampusMismatchException`/`DepartmentParentMismatchException`
  otherwise). `update()` only ever touches `name`/`code`/`description`
  — status changes go through dedicated `archive()`/`reactivate()`
  methods, and campus/parent changes go through `reparent()` (no
  "recampus" action exists yet; not required by this checkpoint).
- **Position != authorization Role**: verified, not just asserted —
  `positions` has no foreign key to `roles`/`capabilities`/
  `membership_role_assignments`/`platform_role_assignments`, and
  `PositionTest::the_full_position_lifecycle_never_touches_an_authorization_table()`
  proves every `PositionService` method leaves every authorization
  table's row count byte-for-byte unchanged.
- **AcademicDepartment vs HR Department**: confirmed distinct, already
  documented reciprocally in both models' docblocks
  (`App\Domain\AcademicStructure\Infrastructure\AcademicDepartment` /
  `App\Domain\HR\Infrastructure\Department`) — the former groups
  Subjects academically, the latter represents staff organizational
  ownership. Neither references the other; no linkage was added.
- **`EmployeeCategory` deferred**: the checkpoint roadmap below
  originally bundled `employee_categories` into 8A.3, but no
  field-level design for it exists anywhere in this document (unlike
  Department/Position, which this section fully specifies) — 8A.3's
  actual brief scoped this checkpoint to Department/Position only.
  Building `employee_categories` now would mean inventing its schema
  unguided, which is deferred rather than done speculatively; the
  roadmap line below is corrected to reflect this.

## Employment Records & Employee Assignments (8A.4, implemented)

`employment_records` and `employee_assignments` connect Employee to the
School's organizational structure. Employee itself gained no new
column — organizational placement and employment lifecycle live
entirely on these two tables (verified by
`EmployeeSchemaTest::employees_table_has_no_organizational_placement_columns()`,
extended this checkpoint to also cover `employment_id`/
`employment_record_id`/`joined_at`/`left_at`).

**Employee vs EmploymentRecord vs EmployeeAssignment**: Employee is the
permanent identity (never duplicated); EmploymentRecord is one legal
engagement (an Employee may have several over time — rehire); Assignment
is where/how the Employee works *within* one Employment. None of the
three collapse into each other.

**`employment_records`** (School-owned, `TenantRls`-protected,
`unique(id, school_id)`): `employee_id` (composite FK to
`employees(id, school_id)`, `cascadeOnDelete` — Employment has no
meaning independent of its Employee), `employment_type` (permanent|
probationary|fixed_term|part_time|temporary|contract|consultant — a
plain, application-validated string; not School-configurable reference
data, since these are stable, universal HR concepts, not
organization-specific names the way Department/Position are),
`starts_on` (required, inclusive), `ends_on` (nullable, inclusive when
present — `NULL` means current/ongoing), `probation_ends_on` (nullable
date, per this document's own "Employee lifecycle" table), `status`
(the exact 8-value set already locked in by that table:
draft|pre_joining|active|notice_period|separated|terminated|retired|
deceased).

- **Date-range CHECK**: `ends_on IS NULL OR starts_on <= ends_on` —
  `<=`, not the strict `<` `academic_years_date_range_check` uses,
  since a single-day engagement is a legitimate real case for
  employment.
- **Overlap policy**: an Employee must not have two overlapping
  EmploymentRecords at the same School. NOT database-constrained (no
  `daterange`/`EXCLUDE USING gist` extension exists in or is introduced
  to this codebase) — enforced entirely by
  `App\Domain\HR\Application\EmploymentService::create()`, which locks
  the Employee row (`lockForUpdate()`) before checking existing records
  for overlap and inserting, inside one transaction. This closes the
  race for the first-hire case too (no existing EmploymentRecord rows
  to lock), not just subsequent ones — the same lock-then-check-then-
  write pattern `EmployeeNumberAllocator`/`AcademicYearService` already
  established. Proven with sequential/transactional tests, not a real
  multi-process concurrency test (unlike 8A.1's employee-number
  allocation, this specific invariant protects a rare, human-initiated
  action, not a hot path, and the underlying lock pattern is already
  proven correct by that earlier real-process test).
- **Rehire**: `EmploymentService::create()` doubles as the rehire
  pathway — nothing distinguishes "first hire" from "rehire" at the
  schema level beyond it being the Employee's 2nd+ row, so no separate
  `rehire()` method exists. Proven end-to-end
  (`EmploymentRecordTest::rehire_preserves_employee_identity_and_creates_an_independent_second_employment`):
  same Employee UUID and `employee_number`, two distinct EmploymentRecord
  UUIDs, Employment #1 left untouched, Employment #2 independently
  active/open-ended, assignments attach to the correct Employment.
- **Ending employment**: `EmploymentService::end()` sets `ends_on`/
  `status` AND, in the same transaction, closes every currently-open
  Assignment under that Employment to the same `ends_on` date (Option A
  from the brief — chosen over rejecting the call until assignments are
  manually closed first, since that would make ending an Employment
  error-prone for the common case). Never touches `users`/
  `school_memberships` — account deactivation stays a distinct, explicit
  action outside this checkpoint (principle 2.6).

**`employee_assignments`** (School-owned, `TenantRls`-protected,
`unique(id, school_id)` — reserved for 8A.5's `manager_assignment_id`
self-reference): `employment_record_id` (composite FK,
`cascadeOnDelete`), `campus_id` (nullable, composite FK,
`restrictOnDelete`), `department_id` (nullable, composite FK,
`restrictOnDelete`), `position_id` (required, composite FK,
`restrictOnDelete`), `is_primary` (boolean), `starts_on`/`ends_on`
(same nullable-open-ended semantics as EmploymentRecord). Deliberately
has **no `employee_id` column** — always reached through
`employment_record_id`, which makes the "Assignment attached to the
wrong Employee" IDOR shape structurally impossible, not merely tested
against. Deliberately has **no `status` column** — this document's own
"Employee lifecycle" table already decided Assignment status is
derived, never stored (`isCurrent()`: `starts_on <= today <= (ends_on
OR infinity)`). Deliberately has **no `manager_assignment_id` yet** —
reserved for 8A.5.

- **Campus/Department compatibility**: `hr_departments.campus_id`
  null = School-wide (compatible with any Assignment Campus or none),
  non-null = Campus-scoped (the Assignment's own `campus_id` must match
  exactly). Not database-constrained (depends on comparing against a
  different, mutable row) — enforced by
  `EmployeeAssignmentService::create()`
  (`AssignmentDepartmentCampusScopeMismatchException` otherwise).
- **Active-reference-at-creation only**: a NEW Assignment cannot use an
  inactive Department/Position (`AssignmentInactiveDepartmentException`/
  `AssignmentInactivePositionException`); an EXISTING historical
  Assignment keeps referencing its Department/Position/Campus
  unaffected if that row is later archived — archiving never
  retroactively invalidates history, and the FK's `restrictOnDelete`
  additionally guarantees a hard delete can never silently destroy that
  history either.
- **EmploymentRecord date-range containment**: an Assignment's
  `[starts_on, ends_on-or-open]` interval must be fully contained
  within its owning Employment's own interval — this document's own
  "Database constraints" table already decided this is application-
  level, not database-constrained; enforced by
  `EmployeeAssignmentService::create()`
  (`AssignmentOutsideEmploymentRangeException`). An open-ended
  Assignment is only valid under an open-ended Employment (a bounded
  Employment cannot contain an interval that claims to continue past
  the Employment's own end).
- **Primary assignment**: at most one currently-open (`ends_on IS
  NULL`) primary Assignment per EmploymentRecord — the exact partial
  unique index this document's "Temporal data strategy" already named
  (`employee_assignments_one_primary_open_per_employment`). A
  historical (ended) primary never blocks a later open primary, since
  the index only applies to open rows. `create()` never accepts
  caller-supplied `is_primary`; `EmployeeAssignmentService::setPrimary()`
  is the sole promotion path (demote-then-promote in one transaction,
  mirroring `EmployeeEmergencyContactService::setPrimary()`'s identical
  shape from 8A.2).
- **Multiple simultaneous assignments**: fully supported and tested
  (e.g. Teacher + Coordinator concurrently) — nothing in the schema or
  service layer assumes one Position per Employee.

**Reporting hierarchy remains deferred to 8A.5** — `manager_assignment_id`
was NOT added this checkpoint; `unique(id, school_id)` on
`employee_assignments` is the only preparation made.

**`EmployeeCategory` remains deferred** — not introduced; no genuine
dependency on it surfaced during 8A.4.

## Reporting hierarchy strategy

**Decision: `employee_assignments.manager_assignment_id`**, not
`manager_employee_id` and not a separate graph/hierarchy subsystem.

Rationale: the brief's own worked example (a teacher reporting to a
Head of Mathematics for teaching duties, a Vice Principal for
coordinator duties) is exactly the case a bare `employee_id` pointer
cannot represent — it would force picking one manager per Employee,
losing the per-Assignment distinction the rest of the model already
establishes. Pointing at the *manager's specific Assignment* row
(nullable, self-referencing, same-School composite FK) gives each
Assignment its own manager, which is what "reports to for this specific
capacity" actually means. 8A.5 adds: cycle prevention (a manager chain
must not point back to itself — validated at write time, since a
database CHECK can't express acyclicity), and a resolution
helper/read-model for "who does X currently report to" and "who
reports to X" queries. No separate hierarchy/graph table is introduced.

## Reporting Hierarchy (8A.5, implemented)

**Architecture conflict, and resolution**: the 8A.5 checkpoint brief's
own "preferred" design described a separate `employee_reporting_lines`
table. This directly conflicted with the design this document already
committed to above (and that 8A.4's own migration explicitly prepared
for — `employee_assignments.unique(id, school_id)` exists specifically
so a self-referencing composite FK could be added cleanly). Per the
brief's own instruction to report such a conflict and follow the
already-accepted architecture rather than silently redesign it, 8A.5
implements the direct `employee_assignments.manager_assignment_id`
self-reference described above, not a separate table. This paragraph
is that report.

**Schema**: `manager_assignment_id` (nullable uuid, added via a
`Schema::table` migration against the existing `employee_assignments`
table) with a same-School composite FK —
`(manager_assignment_id, school_id) -> employee_assignments(id,
school_id)`, `nullOnDelete()` — and a CHECK constraint
(`manager_assignment_id IS NULL OR manager_assignment_id <> id`)
rejecting direct self-reference at the database level, not only in
application code (verified by
`HrRawIsolationTest::raw_insert_of_an_assignment_with_a_cross_school_manager_is_rejected`/
`raw_update_setting_a_cross_school_manager_is_rejected` and by
`ReportingHierarchyTest`'s self-report tests, which exercise both the
DB constraint and the service-level guard).

**Sole write path**: `App\Domain\HR\Application\ReportingHierarchyService::setManager()`
is the only sanctioned writer of `manager_assignment_id`. One method
handles set, change, and clear uniformly — passing `null` as the
manager clears the pointer — since "change" is just "set" called again
on an Assignment that already has a manager; a separate
`changeManager()` would duplicate the same validation for no benefit.
Guards, all evaluated inside `TenantContext::withSchool()` (mirroring
`DepartmentService`'s established pattern, required because
`assertNotSameEmployee()` below reads an RLS-protected lazy relation):
same-School (`AssignmentManagerMismatchException`), not self
(`SelfReportingException`), not the same Employee's own other
Assignment (`SameEmployeeReportingException` — self-management through
a different Position is normally a confusing hierarchy loop, not a
legitimate case), and no cycle (`ReportingHierarchyCycleException`).

**Cycle prevention**: a deterministic, bounded ancestry walk from the
proposed manager upward (mirrors `DepartmentService::assertNoCycle()`'s
identical shape for Department hierarchy) — not a generic graph
engine, since each Assignment has at most one manager, making the
"graph" a simple chain. Proven directly (chains up to 4 nodes,
including indirect 3-node and 4-node cycles), not just for the
1-hop self-report case.

**Concurrency**: a naive check-then-write is not safe against two
concurrent opposite-direction changes (Transaction 1: A's manager →
B; Transaction 2: B's manager → A) — both could read the pre-change
state, both pass their own cycle check, and both commit, producing a
real, persisted A↔B cycle. `setManager()` closes this by locking both
involved Assignment rows via separate, sequential, single-row `WHERE
id = ? FOR UPDATE` statements in sorted-`id` order — deliberately not
a single `whereIn(...)->orderBy('id')->lockForUpdate()` query, since
PostgreSQL does not guarantee a multi-row locking `SELECT` acquires
its row locks in `ORDER BY` sequence (`ORDER BY` only governs the
returned result set, not lock-acquisition order during the scan).
Because both concurrent callers lock in the same sorted order
regardless of which calls first, the second always blocks on the
shared row until the first commits, then re-reads the now-current
state and correctly detects the cycle the first transaction's change
created. The cycle check itself re-fetches every node fresh from the
database by id on every hop, including the first — the caller-supplied
Eloquent object for the proposed manager can hold stale in-memory
attributes if it was read before a concurrent transaction's lock was
even attempted, and using those attributes directly would silently
walk pre-lock data even though the row itself is correctly locked.
Both of these were real bugs found and fixed during this checkpoint's
own implementation (a first version used ordered multi-row locking and
walked the caller-supplied object directly; a real two-process
concurrency test — `ReportingHierarchyConcurrencyTest`, mirroring
`EmployeeNumberConcurrencyTest`'s/`AcademicYearActivationConcurrencyTest`'s
established real-process pattern — caught both, since a sequential
simulation cannot expose either failure mode). Verified stable across
repeated runs, not a single pass.

**No `is_primary` for reporting**: moot under this single-FK-slot
design — an Assignment has at most one `manager_assignment_id` value by
construction, unlike the address/emergency-contact "multiple candidates,
one primary" pattern from 8A.2.

**History**: manager changes are preserved via the audit trail
(`AuditRecorder::school()`, event `hr.assignment.manager_changed`,
recording the subordinate Assignment, previous manager Assignment id,
and new manager Assignment id) — the same convention this codebase
already uses for other mutable-field history, not a dedicated temporal
table. `manager_assignment_id` itself is a live pointer, not a
temporal/effective-dated relationship; "who reported to whom as of a
past date" is answerable from the audit log, not from a row that
changes shape over time.

**Interaction with ending an Assignment or Employment**: ending an
Assignment (`EmployeeAssignmentService::end()`) or ending an Employment
(`EmploymentService::end()`, which bulk-closes its open Assignments)
never leaves a dangling `manager_assignment_id` pointing at a
now-closed Assignment — both call the shared
`App\Domain\HR\Application\AssignmentClosureCascade::clearDanglingManagerReferences()`
collaborator (introduced to avoid a circular dependency between
`EmploymentService` and `EmployeeAssignmentService`), which nulls out
`manager_assignment_id` on any still-open subordinate Assignment
pointing at a closed one. An ended Assignment's own historical
`manager_assignment_id` is left untouched — ending an Assignment does
not erase who it used to report to.

**Tests added**: 16 in `ReportingHierarchyTest` (relationship
resolution, same/cross-School, self-report, same-Employee, direct and
indirect cycles up to 4 nodes, manager change/clear + audit, closure
cascade on both Assignment-end and Employment-end, no authorization
table touched), 1 real two-process concurrency test in
`ReportingHierarchyConcurrencyTest`, and 2 raw-SQL RLS/constraint tests
appended to `HrRawIsolationTest` — 19 new tests total (222 passed, 349
assertions for the full precise-path HR suite, up from the 8A.4
baseline of 203 passed / 316 assertions).

**Deferred, correctly**: no manager backfill for existing Assignments;
no UI/controllers/routes; `EmployeeCategory` remains out of scope.

## Qualifications, Experience & Certifications (8A.6, implemented)

Three new Restricted-tier tables extend Employee directly (entity
model's `EmployeeQualification`/`EmployeeExperience`/
`EmployeeCertification`, each 1:N), all School-owned,
`TenantRls`-protected, composite-FK-safe against `employees(id,
school_id)` (rule 70), `cascadeOnDelete` on `employee_id`. Employee
itself gained no new columns — only three relations
(`qualifications()`, `experienceRecords()`, `certifications()`), and
deliberately no denormalized summary column (`highest_qualification`,
`years_of_experience`, `certification_count`, `certification_expiry`)
— those are always derived from the child tables at read time.

**`employee_qualifications`**: `qualification_type`
(secondary|higher_secondary|diploma|bachelors|masters|doctorate|
professional|other) is a plain, application-validated string —
matches `employment_type`'s exact convention, not a Postgres enum, not
a PHP enum. `qualification_name`/`institution` required;
`specialization`/`awarding_body`/`country_code`/`grade_or_result`
nullable. `institution`/`awarding_body` are plain descriptive strings,
deliberately not normalized into a School OS organization/reference
table — an external university is not a School OS tenant.
`starts_on`/`completed_on` are nullable dates (not timestamps);
`completed_on = NULL` legitimately represents an in-progress
qualification; no derived age/duration is stored. **Date-range CHECK**:
`starts_on IS NULL OR completed_on IS NULL OR completed_on >=
starts_on`.

**`employee_experience_records`**: represents professional experience
OUTSIDE this School's own employment — deliberately named apart from
`employment_records` so the two are never confused.
**Employee ≠ Employment ≠ Experience**: this table has no
`employment_record_id`/`campus_id`/`department_id`/`position_id`
column and no FK to any of those — an external employer is never
encoded as a School OS `EmploymentRecord`. `organization`/`job_title`/
`starts_on` required; `ends_on` nullable (`NULL` = this specific entry
is ongoing — it does NOT mean "current School employment," which lives
entirely on `employment_records`); `description`/`location`/
`country_code` nullable. **Date-range CHECK**: `ends_on IS NULL OR
ends_on >= starts_on`. Deliberately **no overlap constraint** —
concurrent external experience (part-time, consulting, study + work)
is legitimate and common, unlike `employment_records`' own
no-overlap invariant for employment at this School.

**`employee_certifications`**: `name`/`issuer` are plain descriptive
strings — no hard-coded certification catalogue, no approved contract
for one. `credential_number` (nullable) is deliberately **not unique
at any scope** (not globally, not even `unique(issuer,
credential_number)`) — different issuers use overlapping numbering
formats, and no domain contract justifies inventing that constraint.
`issued_on`/`expires_on` nullable dates; `expires_on = NULL`
legitimately represents a non-expiring certification. No persistent
`is_expired`/`days_until_expiry` column — both are derived from
`expires_on` at read time. **Date-range CHECK**: `issued_on IS NULL OR
expires_on IS NULL OR expires_on >= issued_on`. `(school_id,
expires_on)` and `employee_id` are indexed so a future expiry-
monitoring feature has the access path it needs — no query
scope/helper is added yet, since there is no real consumer in this
checkpoint to test one against (the same restraint rule 2 already
established for speculative infrastructure, extended here to
speculative query-API surface). No scheduled jobs/notifications/AI are
introduced.

**Verification model**: implemented for Qualification and
Certification (externally-issued professional credentials genuinely
subject to HR verification), deliberately **not** implemented for
Experience (the checkpoint brief explicitly cautions against assuming
external experience needs the same workflow as a formally-issued
credential, and no reference-check contact data is collected to
support one — kept minimal, a judgment call documented here since no
approved contract mandates either choice). `verification_status`
(unverified|verified|rejected, default `unverified`) plus
`verified_at` (nullable timestamp) on both tables. Deliberately **no
`verified_by_user_id` column** — `AuditRecorder`'s existing
`actor_user_id` capture is sufficient to answer "who verified this,"
per the brief's own guidance that audit-actor identity suffices absent
an approved verifier-field contract.

**Sole write paths**:
`App\Domain\HR\Application\EmployeeQualificationService`/
`EmployeeExperienceService`/`EmployeeCertificationService` — identical
ownership-verification shape to `EmployeeAddressService` (8A.2): every
method takes the authoritative `Employee` the caller already resolved,
`school_id`/`employee_id` are never accepted from caller-supplied
attributes, and `update()`/`remove()` re-verify the target record's
`employee_id` before touching it (`EmployeeOwnershipMismatchException`
otherwise — the same exception class 8A.2 already established, reused
rather than duplicated). `verification_status`/`verified_at` are never
accepted through `add()`/`update()`'s attributes array — `verify()`/
`reject()` (Qualification and Certification services only) are the
only writers of those two columns.

**Material-edit verification-reset rule**: a verified or rejected
Qualification/Certification whose fields are edited via `update()`
must not silently keep looking verified — `update()` treats ANY field
change as material and resets `verification_status` to `unverified`
(clearing `verified_at`) as part of the same transaction whenever the
record was previously `verified`/`rejected`. No narrower "harmless
field" carve-out is defined — determining one is a judgment call this
checkpoint declines to make without an approved contract, so the
conservative default (any edit invalidates verification) applies
uniformly. An `update()` call that changes no fields (empty
attributes) does not disturb verification status. This directly
satisfies the checkpoint's own requirement that a caller cannot verify
one certificate and silently transform it (issuer, credential number,
dates, name) into another while keeping `verified` status.

**Audit**: every mutation calls `AuditRecorder::school()`
(`hr.qualification.created`/`.updated`/`.removed`/`.verified`/
`.rejected`, `hr.experience.created`/`.updated`/`.removed`,
`hr.certification.created`/`.updated`/`.removed`/`.verified`/
`.rejected`) with metadata limited to ids, changed field *names*, and
a `verificationReset` boolean where relevant — never institution
names, grades, credential numbers, issuers, or any other field value.
No parallel HR audit subsystem.

**Document evidence remains deferred to Phase 8A.7** (ADR 0028) — no
`document_id`/`file_path`/`storage_key`/`storage_disk`/`storage_path`/
`bucket`/`blob`/`signed_url`/`certificate_file`/`attachment_path`
column exists on any of the three tables (schema-guard test:
`EmployeeSchemaTest::professional_record_tables_have_no_document_or_file_storage_columns`).
`employee_documents`' own `category` reference value (already
documented above) is the only loose association a future evidence
attachment will use — never a hard FK from a professional-record row.

**Privacy**: unchanged from the existing classification matrix above —
qualification/experience/certification detail is **Sensitive**, gated
by `hr.employees.personal.view`/`.manage`
(`hr.employees.qualifications.view`/`.manage` was already registered
in 8A.0's authorization design specifically for this checkpoint). No
Highly Sensitive data is modeled (no government IDs, no financial
data, no health data). No automatic Directory exposure — these
records are never included in generic Employee serialization, since no
API Resource class or public/UI surface exists yet in Phase 8A.6.

**Tests added**: 49 in `EmployeeQualificationTest`/
`EmployeeExperienceTest`/`EmployeeCertificationTest` (ownership,
mass-assignment protection, date-range validity, verification
transitions and the material-edit reset rule, audit-metadata
minimization, structural distinction from `EmploymentRecord`), 2
schema-creep guards appended to `EmployeeSchemaTest` (no denormalized
summary columns on `employees`; no document/file columns on any of the
three new tables), and 18 raw-SQL RLS/composite-FK tests appended to
`HrRawIsolationTest` (RLS enabled+forced, no-context isolation,
same-School visibility, cross-School insert/update/delete rejection,
for all three tables) — 69 new tests total (291 passed, 481 assertions
for the full precise-path HR suite, up from the 8A.5 baseline of 222
passed / 349 assertions).

**Deferred, correctly**: `employee_documents`/document evidence
(8A.7); `EmployeeCategory`; UI/controllers/routes; expiry-monitoring
scheduled jobs/notifications/AI; payroll/attendance/leave/
recruitment/performance.

## Employee Documents (8A.7, implemented)

**Dependency discovery, performed before any schema was written**: no
shared Documents module exists anywhere in this repository. Confirmed
by direct inspection, not assumption: no `documents` table/migration/
model exists on this branch or on current `main` (`main` at
`e0c4e1b90f2be87ba4903fba39237dfaa6b3e315`, inspected read-only —
`git diff <8A-branch-base>..main` shows only Students/Guardians work
since the branch point, zero Documents-related paths). ADR 0011
(object storage strategy) and ADR 0012 (file/document domain
architecture) are both "Accepted" as design *decisions*, still
unimplemented — exactly as ADR 0028 already recorded in 8A.0. The only
existing storage primitive is
`App\Support\Tenancy\TenantStoragePath::for()`: a pure tenant-safe
path-building function (prefixes `schools/{id}/...`, rejects `..`/
leading-slash/null-byte/encoded traversal). It provides no document
identity, metadata lifecycle, authorization, audit, malware scanning,
retention, or content validation — a path helper is not a Documents
module, and this checkpoint does not mistake it for one. This is
**Case C** (no reusable Documents capability exists anywhere
discovered).

**Decision**: implement only the narrow, HR-scoped `employee_documents`
metadata table HR.md's own 8A.0-era "Documents — narrow scope, not a
parallel system" section already committed to — not a fresh
architectural decision made under this checkpoint's pressure, but the
already-accepted design being built for the first time. This is
explicitly **metadata only**: `employee_documents` never holds file
bytes, and no code in this checkpoint ever calls
`Illuminate\Support\Facades\Storage`. `storage_disk`/`storage_path`
describe where a file already lives or will live; placing one there is
a future, appropriately-authorized process's responsibility, not
8A.7's.

**Schema**: `employee_documents` (School-owned, `TenantRls`-protected,
composite FK `(employee_id, school_id) -> employees(id, school_id)`,
`cascadeOnDelete`): `category` (id_proof|address_proof|
employment_contract|appointment_letter|qualification_evidence|
experience_evidence|certification_evidence|background_check|
policy_acknowledgement|other — plain, application-validated string,
matching `qualification_type`/`employment_type`'s exact convention;
deliberately not a new School-configurable reference table, avoiding
the EmployeeCategory-shaped taxonomy trap), `classification_tier`
(database-**restricted to `restricted`/`highly_sensitive` only** — a
CHECK constraint, not merely a default; `directory` is not a legal
value for an Employee document, a stronger reading of "should never
default to Directory" chosen deliberately here since no real HR
document plausibly belongs at Directory tier), `storage_disk`/
`storage_path`, `original_filename`, `mime_type`, `size_bytes`,
`uploaded_by_user_id` (nullable FK to `users`, `nullOnDelete()`,
matching `school_audit_events.actor_user_id`'s exact pattern),
`uploaded_at`, `issued_on`/`expires_on` (both nullable, CHECK
`expires_on >= issued_on` when both present — added beyond HR.md's
original 8A.0-era field list, per this checkpoint's own brief, for
documents that carry their own expiry such as a licence scan or an ID
proof), `status` (active|archived, default active, **never
hard-deleted** — HR.md's own already-written text for this table,
verbatim).

**Deliberately absent, and why**: no `checksum` (cannot be honestly
computed without this checkpoint ever reading real file bytes, which
it never does); no malware-scan/quarantine column (no scanner exists
anywhere in this repository — claiming one via a schema column would
be dishonest); no `document_id`/shared-Document FK (no shared
`documents` table exists to reference); no `verification_status`
(conflating "a document exists" with "the underlying credential is
verified" is exactly what 8A.6's `EmployeeQualification`/
`EmployeeCertification.verification_status` already owns exclusively —
uploading/attaching evidence must never auto-verify a structured HR
record); no `is_expired`/signed-URL/public-URL column (derived or
simply never generated).

**Sole write path**: `App\Domain\HR\Application\EmployeeDocumentService`
— `register()`/`update()`/`archive()` only, no `remove()` (matches the
reference-entity lifecycle pattern (rule 73) GradeLevel/Department/
Position/etc already use, not the hard-delete pattern 8A.2/8A.6's
simpler child records use). `register()` never accepts a final
`storage_path` from the caller — it always derives one via
`TenantStoragePath::for($employee->school, $fragment)` from a
caller-supplied relative fragment, so a caller can never control the
persisted path outside the Employee's own School prefix, structurally
rather than by convention. `storage_disk`/`storage_path` are immutable
after registration — `update()` strips them alongside `school_id`/
`employee_id`, so a caller cannot silently re-point an existing
metadata row at different file content. A classification-tier change
via `update()` is allowed (HR staff may need to correct an
over/under-classification) but is captured in audit metadata
(`classificationChanged`), making it visible rather than silent —
reviewed explicitly during this checkpoint's security review, not
overlooked.

**No file-handling capability exists, structurally proven**:
`EmployeeDocumentService`'s public surface is exactly `{register,
update, archive}` (a reflection-based test asserts this), and its
source contains no `Storage::` call (asserted directly). A companion
test confirms no generic `documents` table exists anywhere in the
schema. Together these are 8A.7's own "Documents dependency" proof
(the brief's own required structural evidence that no duplicate
generic Documents platform was silently introduced) — not just a
prose claim.

**Document evidence association**: `EmployeeDocument` is owned
directly by `Employee` only — no `qualification.document_id`/
`experience.document_id`/`certification.document_id` column was
added, and no FK exists in the reverse direction either. A document's
`category` value (e.g. `qualification_evidence`) is the only, loose,
non-relational indicator of its purpose — exactly the same pattern
HR.md's original text already used ("qualification certificate" as an
example `category` value). Qualification/Experience/Certification:
none (no FK either direction).

**Privacy**: Directory — none (structurally impossible, not merely
undefaulted). Restricted — the default and floor for every Employee
document. Highly Sensitive — legal, selectable at registration/update
time, for government identity evidence, background-check evidence, and
similar. No API/UI/read endpoint exists in this checkpoint at all
(matches every 8A.1–8A.6 checkpoint's "no controllers" scope), so there
is no exposure surface yet to review — sensitive-data read
authorization is explicitly deferred to 8A.10.

**Audit**: `hr.employee_document.created`/`.updated`/`.archived`
(`AuditRecorder::school()`) — metadata limited to ids, `category`,
`classification_tier`, changed field *names*, and a
`classificationChanged` flag — never `original_filename`, never
`storage_path`, never file content. No parallel HR audit subsystem.

**Tests added**: 22 in `EmployeeDocumentTest` (identity, ownership,
classification CHECK constraint, expiry-range validity, storage-path
derivation and traversal rejection, immutability of
`storage_disk`/`storage_path`, audit minimization, no-authorization-
coupling, and the two structural Documents-dependency proofs), 2
schema-creep guards appended to `EmployeeSchemaTest` (no document
columns on `employees`; no unimplemented integrity/scan columns on
`employee_documents`), and 6 raw-SQL RLS/composite-FK tests appended to
`HrRawIsolationTest` — 30 new tests total (321 passed, 537 assertions
for the full precise-path HR suite, up from the 8A.6 baseline of 291
passed / 481 assertions).

**Deferred, correctly**: real file upload/storage-write capability
(depends on a real shared Documents module or an explicitly-designed
secure upload pipeline, neither of which exists yet); malware
scanning; checksum/content-integrity tracking; document retention
policy; `EmployeeCategory`; UI/controllers/routes; public/mobile API;
OCR/AI/document intelligence; auto-verification of
Qualification/Certification from an attached document (a file existing
does not prove authenticity — verification remains an explicit,
separate HR domain action); full `hr.*` capability enforcement
(8A.10).

## Employee Directory (8A.8, implemented)

**Read/query foundation only — no controller, route, or UI.** This
checkpoint's own roadmap one-liner below ("search/filter/paginate API +
list UI") was written speculatively in 8A.0, before any capability was
actually seeded; verified before implementation that
`Database\Seeders\CapabilityAndRoleSeeder` registers no `hr.*`
capability at all yet (only mentioned in a comment listing future
domain prefixes). Building an HTTP-reachable controller/route gated by
a capability that does not exist would mean either shipping an
ungated HR-data endpoint (a real regression) or seeding `hr.*`
capabilities here — explicitly reserved for **8A.10 HR Permissions &
Sensitive-Data Controls** ("capability seeder rollout"). 8A.8 therefore
implements the safe query/read layer only:
`App\Domain\HR\Application\EmployeeDirectoryService`,
`EmployeeDirectoryQuery` (input), and `EmployeeDirectoryEntry`
(disclosure DTO) — no `App\Http\Controllers` addition, no route, no
Vue page. UI/API wiring is deferred to whichever future checkpoint
pairs it with real `hr.*` authorization (8A.9/8A.10/8A.14).

**Disclosure projection, not a convenience serializer**:
`EmployeeDirectoryEntry` is never constructed from
`$employee->toArray()` or `Employee::with([...])->get()` — its 12
properties are the *entire* exhaustive contract, enforced by an
allow-list test (`array_keys($entry->toArray())`). Exposed:
`employee_id`, `employee_number`, `display_name`, `position_id`/
`position_name`, `department_id`/`department_name`, `campus_id`/
`campus_name`, `manager_employee_id`/`manager_employee_number`/
`manager_display_name`. Excluded by construction (never selected,
joined, or eager-loaded — not merely hidden by a serializer):
`employee_personal_details`, `employee_addresses`,
`employee_emergency_contacts`, `employee_qualifications`,
`employee_experience_records`, `employee_certifications`,
`employee_documents` — every Restricted/Highly-Sensitive table 8A.2/
8A.6/8A.7 introduced. A dedicated test creates an Employee with
populated data (and distinctive sentinel values) across all seven of
those tables and asserts none of it appears anywhere in the serialized
Directory result.

**No status field exposed.** HR.md's own Directory-tier row (Privacy
classification matrix, below) lists only name/employee number/work
contact/position/department/campus/manager — no status dimension.
`Employee.record_status` is used server-side only, as a query *filter*
(default: `active` only; `includeArchived: true` opts into both), never
as a returned DTO field — deliberately more conservative than "not
currently displayed," per this checkpoint's own core invariant: an
unapproved field is structurally absent, not just unrendered.

**Work-contact gap, documented rather than worked around**: HR.md's
Directory-tier row lists "work email/phone" as an eventually-Directory
concept, but no `work_email`/`work_phone` column exists on `Employee`
yet. `personal_email`/`personal_phone`/`alternate_phone` (8A.2) are
Restricted and are never substituted as work contact information —
Directory contact fields are simply absent until real work-contact
columns exist. Not a bug; a recorded gap for whichever future
checkpoint adds them.

**Current Employment selection**: `starts_on <= today <= (ends_on OR
infinity)` — the same rule `EmployeeAssignment::isCurrent()` already
establishes, applied here to `EmploymentRecord` too (which has no
`isCurrent()` helper of its own; `isOpen()` alone — `ends_on IS NULL`
— is insufficient, since a future-dated open-ended Employment is
"open" but not yet "current"). Past and future EmploymentRecords are
ignored; if no currently-effective one exists, organizational fields
are simply `null` (the Employee still appears in the Directory — a
gap between Employments is not the same thing as `record_status =
archived`).

**Current primary Assignment selection**: within the currently
effective EmploymentRecord, the currently effective `is_primary = true`
Assignment drives `position_id`/`department_id`/`campus_id`. A
superseded (ended) primary Assignment is ignored in favor of its
successor; a non-primary (secondary) Assignment never drives these
fields and never produces a second Directory row for the same
Employee — multiple simultaneous Assignments (e.g. Teacher +
Coordinator) collapse to exactly one Directory entry, with only the
primary Assignment's organizational placement surfaced. If somehow more
than one Assignment/EmploymentRecord satisfies "current" (a data-
integrity violation 8A.4's own no-overlap/one-primary-open invariants
should prevent), the query picks the most-recently-started one via an
explicit, deterministic `ORDER BY starts_on DESC, id ASC` tiebreak —
never Postgres' unspecified natural row order, and never a randomly
"first" result.

**Rehire**: proven directly — an Employee with a closed Employment #1
and an open Employment #2 appears exactly once, using Employment #2's
current primary Assignment; Employment #1's historical Position never
resurfaces and never produces a duplicate row.

**Manager projection — exactly one hop**: the current primary
Assignment's live `manager_assignment_id` pointer (8A.5) is resolved to
that manager Assignment's own EmploymentRecord and Employee, exposing
only `manager_employee_id`/`manager_employee_number`/
`manager_display_name` — no manager Restricted/Highly-Sensitive data,
no recursive reporting-tree traversal, and no claim of historical
point-in-time accuracy (this reflects 8A.5's live pointer exactly as
8A.5 designed it — see that checkpoint's own docblock).

**Search**: `employee_number` (prefix match) and `full_name`
(substring match), both case-insensitive `ILIKE`, both parameterized
(no string-concatenated SQL). User-supplied `%`/`_`/`\` characters are
escaped (`addcslashes`) before being embedded in the `ILIKE` pattern,
so a literal `%` in search input can never act as a SQL wildcard
matching every row — proven with a dedicated test. No Restricted field
is ever a search target (proven: searching a real personal-email value
returns zero results). Organizational name/code text search
(Department/Position/Campus) is deliberately not implemented this
checkpoint — filtering by exact id is sufficient and safer; a
`pg_trgm` GIN index (HR.md's original "Search strategy" section,
written in 8A.0) remains deferred until real query-plan evidence at
production data volumes justifies it — no speculative index migration
was added.

**Filters**: `campusId`/`departmentId`/`positionId`, each matched
against the currently effective primary Assignment (never a historical
one). A filter id belonging to a different School is never
distinguished from one that does not exist — both simply match zero
`employee_assignments` rows (RLS + the composite tenant-safe FK already
guarantee this) — proven with a dedicated test, closing the
existence-oracle risk without an extra, riskier existence check.

**Sorting**: allow-listed to `full_name`/`employee_number` only
(`EmployeeDirectoryQuery::ALLOWED_SORTS`) — an unapproved value falls
back to the deterministic default (`full_name`) rather than being
passed to `orderBy()` or rejected with an exception. Default order is
always fully deterministic: the chosen sort column, then
`employee_number`, then `id` as a final tiebreak.

**Pagination**: Laravel's standard `paginate()`
(`docs/architecture/API.md`'s documented offset-based convention).
Default page size 25; hard maximum 100
(`EmployeeDirectoryService::MAX_PER_PAGE`), enforced inside
`EmployeeDirectoryQuery`'s own constructor — an out-of-range
`perPage` can never reach the query layer at all, let alone return an
unbounded result set.

**Tenant isolation**: every query runs inside
`TenantContext::withSchool($school, ...)`, established by the service
itself (not merely assumed already-correct) — proven with a dedicated
test that deliberately leaves a *different* School active in ambient
`TenantContext` before calling `search()`, confirming the requested
School's boundary still wins. Underlying RLS on `employees`/
`employment_records`/`employee_assignments`/`hr_departments`/
`positions`/`campuses` (already proven in `HrRawIsolationTest`) remains
the defense-in-depth backstop; no privileged/admin database connection
is used anywhere in this class. `employee_number` collisions across
Schools (e.g. two Schools both using `EMP-000001`) are proven never to
cross School boundaries via search.

**Batch-hydration, not N+1**: a fixed, small number of queries per
page (one for the page of Employees, one each for current
EmploymentRecords/current primary Assignments/Departments/Positions/
Campuses/manager Assignments/manager EmploymentRecords/manager
Employees) — never one query per Employee row, regardless of page
size.

**No new migration.** `MIGRATIONS: NONE` — the Directory is entirely
derived, in application/query code, from already-committed 8A.1–8A.5
tables. No denormalized `employee_directory` table, no materialized
view, no cache (tenant-sensitive query-result caching was considered
and explicitly rejected for this checkpoint — a correct, RLS-backed
SQL read model is preferred over cache-invalidation/cross-tenant-
leakage risk for a feature with no demonstrated performance need yet).

**Audit**: none added. An ordinary Directory read/search does not
generate an `AuditRecorder` event — matches this codebase's existing
convention (no read-access audit subsystem exists anywhere yet); a
future Restricted/Highly-Sensitive *read* audit requirement remains
8A.10's concern, not retrofitted here for data this checkpoint never
touches in the first place.

**Tests added**: 26 in `EmployeeDirectoryServiceTest` — exact
disclosure shape, the full Restricted/Highly-Sensitive negative-leak
test across all seven excluded tables, current-Employment/current-
primary-Assignment selection (including the "future open-ended
Employment is not current" edge case), rehire non-duplication,
secondary-Assignment non-duplication, one-hop manager projection,
search (number/name/wildcard-literal/no-Restricted-match), filters
(same-School and cross-School-id-returns-nothing), sort (allow-listed
and invalid-defaults-safely), pagination (deterministic, clamped
maximum), and tenant isolation (cross-School discovery, identical
cross-School employee numbers, ambient-context override safety,
archived-employee default exclusion) — 351 passed, 610 assertions for
the full precise-path HR suite, up from the 8A.7 baseline of 325
passed / 544 assertions.

**Deferred, correctly**: Employee Profile Workspace (8A.9); full `hr.*`
capability rollout and Restricted-field suppression proof (8A.10);
controller/route/UI of any kind; `EmployeeCategory`; imports; public/
mobile API; payroll/attendance/leave/recruitment/performance;
AI/automation; organizational name/code text search;
`pg_trgm` index.

## Employee Profile Workspace (8A.9, implemented)

**Read/query foundation only — no controller, route, or UI**, same
decision and same evidence as 8A.8: `CapabilityAndRoleSeeder` still
registers no `hr.*` capability. The Profile Workspace is the
Restricted-tier counterpart to 8A.8's Directory-tier
`EmployeeDirectoryService`, and exposing it through HTTP now would
mean either an ungated Restricted-data endpoint or pulling 8A.10's
capability-seeder rollout forward prematurely. `EmployeeProfileWorkspaceService`
is implemented as pure read/query architecture; UI/API wiring is
deferred until 8A.10 provides the capability boundary.

**Profile Workspace ≠ Directory.** `EmployeeDirectoryEntry` (8A.8) is
never reused or extended here — the Profile Workspace is a completely
separate set of DTOs (`EmployeeProfileWorkspace` and 11 section types),
built from their own explicit, scoped queries, never
`Employee::with([...everything...])->toArray()`. Where Directory
collapses an Employee to one current-only row, the Profile Workspace
deliberately preserves full history (all EmploymentRecords, all
Assignments including secondary ones) — it answers "what is this
Employee's whole HR record," not "who currently works here."

**Sections** (docblocks on each class are the authoritative field
contract): `summary` (identity + current state, singleton),
`personalDetails`/`contact` (both nullable singletons — DOB/
nationality/marital status/preferred language split from personal
email/phone/alternate phone into two sections, matching the brief's
own B/C section split), `addresses`/`emergencyContacts` (lists),
`employmentHistory`/`assignments` (lists, full history, not just
current), `qualifications`/`experience`/`certifications` (lists),
`documents` (list, Restricted only — see below). Optional singleton
sections are `null` when no row exists; repeatable sections are `[]`
when empty — the workspace always builds successfully, never throws
for legitimately absent HR data.

**Restricted data intentionally included** (this IS the Restricted
HR-internal view, unlike Directory): personal details, personal
contact (still never renamed to "work contact" — the 8A.8 work-contact
gap is unchanged), addresses, emergency contacts, qualification/
experience/certification detail including `credential_number` and
verification state, and Restricted-tier `EmployeeDocument` metadata.

**Highly Sensitive data excluded — the core 8A.9 acceptance gate**:
`EmployeeDocument` rows with `classification_tier = 'highly_sensitive'`
are excluded at the DATABASE QUERY level
(`where('classification_tier', 'restricted')` inside
`EmployeeProfileWorkspaceService`) — never fetched at all, let alone
filtered out afterward. A dedicated test creates one restricted and one
highly-sensitive document for the same Employee and proves only the
restricted one appears. No government identifier/bank/health data
exists anywhere in the underlying schema to expose in the first place
— 8A.9 reads existing data only, adds no new Highly Sensitive fields.

**Document metadata is deliberately narrow**, even within the
Restricted set: `EmployeeProfileDocumentEntry` exposes only `id`,
`category`, `classification_tier` (always `restricted` here),
`issued_on`, `expires_on`, `status` — permanently excluding
`original_filename`/`mime_type`/`size_bytes`/`storage_disk`/
`storage_path` (operational file/storage metadata, not HR-facing) and
`uploaded_by_user_id` (8A.7's own provenance-integrity fix — original-
registration provenance, not general-workspace-appropriate data). Both
`active` and `archived` documents appear (history is not hidden here,
unlike Directory's default). No file content, no upload, no download,
no signed/public URL exists anywhere in this checkpoint.

**Employment history**: every `EmploymentRecord` the Employee has ever
had, ordered `starts_on DESC, id`, each flagged `is_current` (same
`starts_on <= today <= ends_on-or-infinity` rule 8A.8 already
established, computed once against the already-loaded in-memory
collection — no extra query). Rehire produces two Employment entries
under the same, single Employee profile — proven directly. A
future-dated Employment is never flagged current.

**Assignment history**: every `EmployeeAssignment` across all of the
Employee's EmploymentRecords — secondary Assignments are never hidden
here, unlike Directory — each carrying an explicit
`employment_record_id` association (not nested) and its own
`is_current`/`is_primary` flags. The current primary Assignment alone
drives `summary`'s organizational fields; historical and secondary
Assignments remain visible but never overwrite the summary.
`EmployeeProfileAssignmentEntry` deliberately carries no manager
field — manager disclosure lives exclusively on `summary`.

**Reporting manager**: exactly one hop from the current primary
Assignment's live `manager_assignment_id` pointer (8A.5), identical
reasoning to 8A.8 — no recursion, no historical-manager reconstruction
claim, only Directory-tier manager identity exposed.

**Verification information**: qualification/certification
`verification_status`/`verified_at` are included as real Restricted
HR data; no `verified_by_user_id` is synthesized — 8A.6's own
`AuditRecorder`-actor-identity design is left exactly as committed.

**Tenant-safe resolution**: `EmployeeProfileWorkspaceService::build(School
$school, string $employeeId)` resolves the Employee itself (not just
its children) under `TenantContext::withSchool($school, ...)` plus an
explicit `where('school_id', $school->id)`, returning `null` — never
an exception, never a distinguishing message — for both a genuinely
nonexistent id and a valid id belonging to a different School.
Proven directly: both cases return the identical `null` result, and a
School B Employee fully populated across every section still yields
`null` when requested from School A context. Archived Employees ARE
resolvable (unlike Directory's default active-only filter) — historical
HR access to a separated Employee is a legitimate, expected use case.
Ambient `TenantContext` is proven restored to its pre-call state after
`build()` returns.

**Batch-hydration**: Department/Position/Campus lookups for all of an
Employee's Assignments are batched via `whereIn`, exactly like
`EmployeeDirectoryService::hydrate()` — never one query per Assignment.
A single Employee's full profile build issues a small, fixed number of
queries regardless of history depth.

**No mutation.** `EmployeeProfileWorkspaceService` has exactly one
public method (`build()`) and no write path of any kind — the existing
per-domain services (`EmployeePersonalDetailService`,
`EmployeeAddressService`, `EmployeeAssignmentService`, etc.) remain the
only sanctioned mutation paths; no aggregate
"update everything" method was introduced, avoiding the mass-
assignment/authorization risk that would create.

**Ordering**: addresses (`address_type`, `id`), emergency contacts
(`is_primary` desc, `name`, `id`), employment/assignments (`starts_on`
desc, `id`), qualifications/experience (`starts_on` desc, `id`),
certifications (`issued_on` desc, `id`), documents (`uploaded_at` desc,
`id`) — every repeatable section deterministic, never database natural
row order.

**No new migration.** `MIGRATIONS: NONE` — entirely derived from
already-committed 8A.1–8A.7 tables, same as 8A.8.

**Audit**: none added — matches 8A.8's documented no-read-audit
convention; a future Restricted/Highly-Sensitive *read* audit
requirement remains 8A.10's concern.

**Tests added**: 27 in `EmployeeProfileWorkspaceServiceTest` — profile
root (identity, unlinked User, archived Employee, missing-section
safety, summary shape), personal/contact/addresses/emergency-contacts
(shape + ordering), employment history (all records, current
selection, future-not-current), assignments (all including secondary,
historical preservation, primary-drives-summary), rehire (two
Employment entries under one profile), manager (one-hop identity,
Assignment-entries-never-carry-manager), professional records
(qualifications/experience/certifications, structural Experience-vs-
Employment distinction), documents (Restricted shape, Highly-Sensitive
exclusion with mixed-classification data, storage/provenance-field
negative test with sentinels, active+archived both visible), and
tenant isolation (cross-School null result, nonexistent-vs-cross-School
indistinguishability, ambient-context restoration) — 378 passed, 705
assertions for the full precise-path HR suite, up from the 8A.8
baseline of 351 passed / 610 assertions.

**Deferred, correctly**: HR Permissions & Sensitive-Data Controls
(8A.10); any Profile mutation UI or aggregate update endpoint; document
upload/download; `EmployeeCategory`; imports; public/mobile API;
payroll/attendance/leave/recruitment/performance; AI/automation.

## HR Permissions & Sensitive-Data Controls (8A.10, implemented)

**This is a security-boundary checkpoint, not a capability-seeding
task.** 8A.1–8A.9 deliberately built every HR domain service and both
read models (`EmployeeDirectoryService`, `EmployeeProfileWorkspaceService`)
with no HTTP surface at all, specifically because
`Database\Seeders\CapabilityAndRoleSeeder` registered no `hr.*`
capability yet (verified before any 8A.10 code was written, not
assumed). 8A.10 closes that gap: it registers the capability family,
and — because no controller exists yet to be the "obvious" enforcement
point — puts the actual `Gate::authorize('capability', ...)` check
**inside every HR Application service itself**, which is the true
authoritative, production-facing entry point today. A future
controller (8A.14+) calling these services inherits this protection
for free; it is not expected to "remember" to re-check.

**RLS vs authorization, restated precisely**: PostgreSQL RLS (already
proven in `HrRawIsolationTest` since 8A.1) answers "which School's rows
can this database session see" — it has never been, and is not now,
an answer to "which operations may this actor perform." Both layers
run on every request: `TenantContext::withSchool()` scopes the query,
`Gate::authorize('capability', [$key, $school])` scopes the operation.
`HrMultiSchoolAuthorizationTest::real_time_tenant_context_visibility_is_not_mistaken_for_authorization`
proves this directly — a real, RLS-visible row, denied anyway, because
the actor lacks the capability.

**No new HR ACL engine.** Reused, verbatim: `capabilities`/`roles`/
`role_capabilities`/`membership_role_assignments`/
`platform_role_assignments` (unchanged schema), `CapabilityResolver`,
`Gate::define('capability', ...)` (`AppServiceProvider::boot()`), and
`App\Support\Authorization\AuthorizesCapability`. The only change to
shared authorization infrastructure is one new method on that trait,
`authorizeCapabilityFor(User $actor, string $capability, ?School
$school)` — a thin `Gate::forUser($actor)->authorize(...)` wrapper for
callers (Application-layer services) that hold an explicit `$actor`
argument rather than running inside an authenticated HTTP request. No
`hr_roles`/`employee_permissions`/`employee_acl` table was created or
considered necessary.

### Final capability list

Reconciles 8A.0's original "Authorization design" draft below with
what 8A.6/8A.7/8A.8/8A.9 actually built. One correction from that draft,
made explicit here per root `CLAUDE.md` rule 15: qualification/
experience/certification detail (8A.6) and employment/assignment
history (8A.4/8A.5) are gated by their own dedicated capabilities
(`hr.employees.qualifications.*`, `hr.employees.assignments.*`) — the
8A.0 draft's privacy-matrix table listed them under
`hr.employees.personal.*`, but 8A.6's own "implemented" section already
registered and used the dedicated pair; this section is the final,
authoritative word, not the 8A.0 draft.

```
hr.employees.view                    hr.employees.manage
hr.employees.personal.view           hr.employees.personal.manage
hr.employees.assignments.view        hr.employees.assignments.manage
hr.employees.qualifications.view     hr.employees.qualifications.manage
hr.employees.documents.view          hr.employees.documents.manage
hr.employees.notes.view              hr.employees.notes.manage
hr.employees.sensitive.view          hr.employees.sensitive.manage
hr.departments.view                  hr.departments.manage
hr.positions.view                    hr.positions.manage
```

All 18, `namespace = 'school'`. `.notes.*` is registered but unused —
no `employee_notes` table exists yet (same "capability exists, data
does not yet" precedent `.sensitive.*` itself already established in
8A.0). No separate `.verify` capability was introduced for
Qualification/Certification verification — `.qualifications.manage`
covers add/update/remove/verify/reject uniformly, a deliberate
capability-explosion-avoidance decision (root `CLAUDE.md`'s "don't add
abstractions beyond what's needed", applied here to permissions);
`HrMutationAuthorizationTest::qualifications_manage_grants_verification_by_this_checkpoints_design`
documents this decision as a passing test, not just prose.

### Default role grants

`school_admin` and `principal` receive **only**
`hr.employees.view`/`.manage`/`.personal.view` — exactly what 8A.0's
draft already committed to, verified still correct and implemented
byte-for-byte in `CapabilityAndRoleSeeder`. Every other `hr.*`
capability (`.personal.manage`, `.assignments.*`, `.qualifications.*`,
`.documents.*`, `.sensitive.*`, `.notes.*`, `hr.departments.*`,
`hr.positions.*`) is granted to **no system role by default** — a
School's own role configuration (`school.roles.manage`) must add
whichever of these a real "HR Staff" role needs. This closes 8A.0's own
flagged P1 finding ("default role capability grants must not include
sensitive/personal.manage") and extends the same conservative default
to the newly-registered `.assignments.*`/`.qualifications.*`/
`.documents.*`/`hr.departments.*`/`hr.positions.*` pairs, none of which
existed when that P1 was written. `HrCapabilityRegistryTest` proves the
exact granted/not-granted set for both roles, and that no system role
ever receives `.sensitive.*`.

There is no "automatically grant every new School capability to
School Admin" mechanism anywhere in this codebase — `CapabilityAndRoleSeeder`
explicitly lists each role's capabilities; this was verified, not
assumed, before deciding the default-grant boundary above.

### Directory authorization (`EmployeeDirectoryService`)

`search()` now requires `User $actor` and calls
`authorizeCapabilityFor($actor, 'hr.employees.view', $school)` before
running any query — a denied caller learns nothing, not even a row
count. Everything 8A.8 already proved (12-field disclosure allow-list,
search/filter/sort/pagination, tenant isolation) is unchanged;
`HrDirectoryAuthorizationTest` adds the capability boundary itself, and
`HrDefaultDenyTest`/`HrMultiSchoolAuthorizationTest` add the default-deny
and multi-School proofs.

### Profile authorization (`EmployeeProfileWorkspaceService`)

**Entry gate**: `hr.employees.personal.view` is required to call
`build()` at all — `hr.employees.view` (Directory) alone does **not**
grant Profile access, by design
(`HrDefaultDenyTest::directory_capability_alone_does_not_grant_profile_access`).
The check runs before the Employee is even resolved, so it is School-
scoped, never Employee-scoped — it cannot become a cross-tenant
existence oracle, and a same-School denial (`AuthorizationException`)
is never confused with a cross-School/nonexistent-id `null` (unchanged
from 8A.9, both proven independently).

**Section-level gating** (approach B from the checkpoint brief —
"build section-by-section according to multiple view capabilities",
chosen because it maps exactly onto the capability family already
registered):

| Section | Additional capability required | Behavior when absent |
|---|---|---|
| `summary`, `personalDetails`, `contact`, `addresses`, `emergencyContacts` | none beyond the `personal.view` entry gate | n/a — always present once the workspace is reachable at all |
| `employmentHistory`, `assignments` (full history) | `hr.employees.assignments.view` | `[]` — not merely hidden, structurally absent from the returned DTO |
| `qualifications`, `experience`, `certifications` | `hr.employees.qualifications.view` | `[]`, not queried at all |
| `documents` (Restricted only) | `hr.employees.documents.view` | `[]`, not queried at all |
| Highly Sensitive documents | never included here, any capability | excluded at the query level (unchanged from 8A.9) |

`summary`'s current position/department/campus/manager fields (the
same one-hop, Directory-tier-equivalent data 8A.8 exposes) remain
visible to any `personal.view` holder without also requiring
`assignments.view` — a deliberate, documented judgment call (the
richer *history* lists are what `assignments.view` additionally
gates, not the single current snapshot). Because `summary` needs the
current Employment/Assignment resolved regardless, the underlying
query for those two tables still runs even when `assignments.view` is
absent; only the *output* `employmentHistory`/`assignments` arrays are
gated. `HrProfileAuthorizationTest` proves every row of the table
above, plus a dedicated side-channel test
(`no_sensitive_document_side_channel_leaks_through_the_general_profile`)
that plants two sentinel-valued Highly Sensitive documents and proves
neither their content, category, nor count appears anywhere in the
serialized profile for an actor with every OTHER capability except
`sensitive.view`.

### Highly Sensitive document read (`EmployeeSensitiveDocumentReadService`, new)

A single new, deliberately narrow Application service —
`App\Domain\HR\Application\EmployeeSensitiveDocumentReadService::forEmployee(School,
string $employeeId, User $actor): ?array` — is the *only* place
`classification_tier = highly_sensitive` `EmployeeDocument` metadata is
reachable at all. Requires `hr.employees.sensitive.view` specifically;
never satisfied by `.documents.view`/`.manage` or `.personal.view`.
Returns the exact same narrow, safe shape 8A.9 already established
(`EmployeeProfileDocumentEntry`: id/category/classification_tier/
issued_on/expires_on/status) — never a raw Eloquent model, never
`storage_path`/`storage_disk`/`original_filename`/`uploaded_by_user_id`,
never a signed/public URL, never a physical file read. Tenant-safe
resolution identical to `EmployeeProfileWorkspaceService::build()`
(capability check before Employee resolution; `null` for both
nonexistent and cross-School ids, indistinguishably).

**Audit**: exactly one `hr.employee_document.sensitive_viewed`
`SchoolAuditEvent` per successful read that actually returns at least
one Highly Sensitive document — never one row per document, and never
for a read that finds zero (nothing sensitive was actually exposed).
Metadata is limited to the Employee id and the returned document ids;
never storage paths, filenames, or categories of anything hidden.
`HrSensitiveDocumentReadServiceTest` proves both the audit-on-non-empty
and no-audit-on-empty cases explicitly.

### Document classification-transition control (`EmployeeDocumentService`)

The checkpoint's most safety-critical single rule, implemented in one
place (`assertClassificationCapability()`): `hr.employees.documents.manage`
is sufficient **only** when a document's classification stays
`restricted` throughout the call. `hr.employees.sensitive.manage` is
required whenever **either side** of an operation is `highly_sensitive`:

- registering a **new** `highly_sensitive` document,
- updating or archiving a document whose **current** tier is already
  `highly_sensitive` — even when the update touches no classification
  field at all (an ordinary document manager must not edit/archive a
  Highly Sensitive record just because it is still reachable),
- a classification-tier transition in **either direction**
  (`restricted → highly_sensitive` or the reverse).

A denied attempt leaves the record completely unchanged — proven for
both transition directions, for a non-classification field update on
an already-`highly_sensitive` record, and for archive
(`HrDocumentClassificationAuthorizationTest`, 10 tests covering every
row of the required test matrix's classification section). This is
exactly the bypass the checkpoint brief's sections 9–12 warn about:
"ordinary document-management permission must not automatically permit
modification/downgrading of Highly Sensitive records" — closed by
construction, not by convention.

### Mutation authorization — the full map

Every Application-service public mutation method now requires a real,
non-nullable `User $actor` (see "Null actor" below) and authorizes
before doing anything else:

| Service / method(s) | Capability required |
|---|---|
| `EmployeeService::create()` | `hr.employees.manage` |
| `DepartmentService::create/update/archive/reactivate/reparent()` | `hr.departments.manage` |
| `PositionService::create/update/archive/reactivate()` | `hr.positions.manage` |
| `EmploymentService::create/update/end()` | `hr.employees.assignments.manage` |
| `EmployeeAssignmentService::create/end/setPrimary()` | `hr.employees.assignments.manage` |
| `ReportingHierarchyService::setManager()` | `hr.employees.assignments.manage` |
| `EmployeePersonalDetailService::setDetails()` | `hr.employees.personal.manage` |
| `EmployeeAddressService::add/update/remove()` | `hr.employees.personal.manage` |
| `EmployeeEmergencyContactService::add/update/remove/setPrimary()` | `hr.employees.personal.manage` |
| `EmployeeQualificationService::add/update/remove/verify/reject()` | `hr.employees.qualifications.manage` |
| `EmployeeExperienceService::add/update/remove()` | `hr.employees.qualifications.manage` |
| `EmployeeCertificationService::add/update/remove/verify/reject()` | `hr.employees.qualifications.manage` |
| `EmployeeDocumentService::register/update/archive()` | `.documents.manage` or `.sensitive.manage` (classification-aware, see above) |

Reporting-manager changes deliberately share the Employment/Assignment
capability (`.assignments.manage`), not a separate one — HR.md's own
8A.5 design already treats `manager_assignment_id` as one more field of
`employee_assignments`, not an independent subsystem, and no product
requirement asks for finer separation yet.

**Employee Core mutations**: `EmployeeService::create()` is the only
mutation method that exists on that service as of 8A.9 (no
update/archive/user-link method has been built yet — that is 8A.13's
concern); it is protected. Employee-number allocation itself remains
internal to `create()` and was never a separately callable operation,
so it needed no capability of its own.

### Null actor

**No HR Application service accepts a null/anonymous actor.** Every
`?User $actor = null` parameter from 8A.1–8A.9 became a required `User
$actor` in 8A.10 — there is no legitimate system/internal HR mutation
path today (no scheduled job, queued listener, or console command
writes HR data), so "require a real actor everywhere" is the correct,
simplest rule per the checkpoint brief's own guidance, not a narrower
carve-out invented for convenience. One pre-existing 8A.7 test
(`register_with_no_actor_persists_a_null_uploaded_by_user_id...`)
tested a case that is now structurally impossible; it was retired with
an inline comment explaining why, and the invariant it protected
(a caller-supplied `uploaded_by_user_id` attribute can never override
real provenance) remains fully proven by the sibling test that uses a
real, authorized actor.

### Platform / privileged actor

**No HR-specific superadmin bypass exists, and none was built.**
`docs/security/AUTHORIZATION.md` and `CapabilityResolverTest` already
establish, and this checkpoint re-confirmed by inspection (not
assumption), that Platform and School capabilities are resolved and
cached completely independently — a Platform Super Admin has zero
School capabilities without an explicit `SchoolMembership` + School-
scoped role, exactly like any other actor. There is no existing
"platform admin enters a School's context" elevation mechanism to reuse
(`docs/security/AUTHORIZATION.md`'s own "What is NOT yet implemented"
section already says so), so none was invented for HR. A Platform
Super Admin who needs HR access gets it the same way anyone does: a
real membership and an HR-capable role at that School.

### Membership status

Unchanged, reused as-is: `CapabilityResolver::schoolCapabilities()`
already filters to `status = 'active'` memberships (Phase 0B), so a
suspended/invited membership yields zero capabilities regardless of
role assignments — no HR-specific membership-status model was
introduced.

### Capability revocation

No automatic cache-invalidation-on-revoke hook exists anywhere in this
codebase yet (`CapabilityResolver::forgetCache()` is a manual utility;
nothing calls it automatically today when a `MembershipRoleAssignment`
is deleted). 8A.10 follows this existing convention rather than adding
a new one: `HrMutationAuthorizationTest::capability_revocation_takes_effect_on_the_next_check`
deletes the assignment and explicitly calls `forgetCache()`, proving
the mechanism works when a caller does invoke it — exactly what a
future role-management controller action must do after mutating role
assignments. Absent an explicit `forgetCache()` call, a change can
remain visible for up to the resolver's existing 60-second cache TTL;
this is pre-existing Phase 0B behavior, not something 8A.10 changed or
was asked to change.

### Manager / Position authorization separation

Re-verified, not just re-asserted: `PositionTest`'s and
`ReportingHierarchyTest`'s existing "never touches an authorization
table" tests were updated to create their (now-required) authorized
actor **before** capturing baseline Role/`MembershipRoleAssignment`/
`PlatformRoleAssignment` counts, so the assertion still proves what it
always proved — a Position/Department/Assignment/manager-pointer
mutation itself adds zero authorization-table rows.
`HrMutationAuthorizationTest::changing_position_department_or_manager_never_creates_an_authorization_row`
adds one further end-to-end proof (Employment + Assignment creation
through the authorized services) to the same effect.

### Directory / Profile Web UI activation decision

**Decision: A — authorization only, HTTP/UI remains deferred.**
Activating the previously-deferred Directory/Profile Inertia routes
now that capability gates exist would be a legitimate, HR.md-anticipated
next step, but doing so in the same checkpoint that establishes the
authorization foundation itself would leave no room to review the
foundation in isolation first — and the checkpoint brief's own section
37 permits either decision as long as it is not made prematurely
(before authorization tests pass). No controller, route, or Vue page
was added. `EmployeeDirectoryService`/`EmployeeProfileWorkspaceService`/
`EmployeeSensitiveDocumentReadService` are now fully authorization-
aware and ready for a future checkpoint (or a dedicated follow-up) to
wire HTTP routes against, with the capability boundary already proven
rather than added at the same time as the routes.

### Migrations

**NONE.** Every 8A.10 change is either a seeder-data addition
(`CapabilityAndRoleSeeder`, new capability rows only — idempotent,
`HrCapabilityRegistryTest` proves re-running it twice adds nothing and
deletes nothing unrelated) or Application/test-layer code. No new
table, no new column, on any existing HR or authorization table.

### Tests added

57 net new tests across 8 new files (`HrCapabilityRegistryTest`,
`HrDefaultDenyTest`, `HrMultiSchoolAuthorizationTest`,
`HrDirectoryAuthorizationTest`, `HrProfileAuthorizationTest`,
`HrDocumentClassificationAuthorizationTest`,
`HrSensitiveDocumentReadServiceTest`, `HrMutationAuthorizationTest`) —
capability registry/idempotency/default-grants, default-deny for every
entry point, same-User multi-School allow/deny, RLS-vs-RBAC and
RBAC-vs-RLS separation, Directory and Profile capability boundaries,
section-level Profile disclosure (including the sensitive-document
side-channel proof), the full classification-transition bypass matrix,
the narrow sensitive-document read path and its audit behavior, every
remaining mutation capability's deny path, capability revocation, and
Position/manager/authorization-table separation. One pre-existing 8A.7
test was retired (see "Null actor" above) as its premise became
structurally impossible; every other 8A.1–8A.9 test was updated only to
supply a now-required authorized actor, never to weaken what it
originally proved — full suite: 435 tests / 886 assertions / 0
failures (`php artisan test tests/Feature/HR
tests/Feature/Postgres/HrRawIsolationTest.php`), up from the 8A.9
baseline of 378 / 705.

### 8A.11 / 8A.14 boundary

8A.10 adds exactly the audit events a successful sensitive-document
read needs and no more — it does **not** build the Employee Activity
Timeline UI/query aggregation (8A.11's scope), and it does **not**
build any public/mobile API surface (8A.14's scope, unaffected by
anything here beyond inheriting an already-authorization-aware service
layer to build against).

## Audit & Activity Timeline (8A.11, implemented)

**`App\Models\SchoolAuditEvent`/`App\Support\Audit\AuditRecorder` remain
the sole, authoritative audit store (ADR 0017) — this checkpoint adds
NO second event/audit table.** `EmployeeActivityTimelineService` is a
pure READ PROJECTION over the existing ledger: it never writes an audit
event (reading the Timeline is itself never audited — see "Timeline
reads are not audited" below), never mutates a historical row (the
table is append-only at the database privilege level, ADR 0021 — an
`UPDATE`/`DELETE` from the runtime role fails regardless of intent),
and never normalizes or backfills old rows to make linkage easier.

### Audit storage architecture (as inspected, not assumed)

- **Table/model**: `school_audit_events` / `SchoolAuditEvent`.
- **School ownership**: `school_id` (FK, RLS-scoped via
  `App\Support\Tenancy\TenantRls::enable()`, `cascadeOnDelete`).
- **Actor**: `actor_user_id`, nullable, `nullOnDelete` — a deleted User
  leaves the audit row intact with a null actor, never a broken
  reference.
- **Subject/resource**: `subject_type` (nullable string, an Eloquent
  FQCN) + `subject_id` (nullable uuid) — set whenever `AuditRecorder::school()`
  is called with a `$subject` model.
- **Metadata**: `jsonb`, nullable, cast to `array`.
- **RLS**: enabled AND append-only (`TenantRls::makeAppendOnly()` —
  `UPDATE`/`DELETE` revoked from the runtime `school_os_app` role at
  the database privilege level, proven directly in
  `tests/Feature/Audit/AuditImmutabilityTest.php`, already committed
  before this checkpoint and not duplicated here).
- **Indexes**: `school_id`; `(school_id, event_type)`; `occurred_at`.
  No index exists on `subject_type`/`subject_id` or on any JSONB path —
  see "Audit index decision" below for why none was added.
- **Immutability**: enforced at the database privilege level, not just
  by application convention — confirmed by inspection of the migration
  and the existing `AuditImmutabilityTest`, not assumed.

### Employee linkage strategy

In the checkpoint brief's own required preference order:

1. **Explicit Employee subject** — `subject_type = Employee::class AND
   subject_id = $employee->id`. Used by `employee.created` and
   `hr.employee_document.sensitive_viewed` (both audit calls already
   passed the Employee itself as `$subject` since 8A.1/8A.10).
2. **Explicit `metadata.employeeId`** — used by every other mapped HR
   event. This was already present on ALL personal/professional/
   document mutation events since 8A.2/8A.6/8A.7. This checkpoint found
   five methods that carried only a child-resource id
   (`employmentRecordId`/`subordinateAssignmentId`) and NOT
   `employeeId`: `EmploymentService::update()`,
   `EmployeeAssignmentService::create()/end()/setPrimary()`, and
   `ReportingHierarchyService::setManager()`. Each got a narrow,
   purely-additive metadata fix (`'employeeId' => ...`), forward-only —
   no historical row was rewritten, no existing key was removed or
   renamed, and every pre-existing assertion about these events'
   metadata shape from 8A.4/8A.5's own tests still passes unchanged.
   `setManager()`'s event belongs to the **subordinate's** Employee
   Timeline specifically (whose reporting line changed), never the
   manager's — proven directly
   (`HrActivityTimelineLinkageTest::a_reporting_manager_change_resolves_to_the_subordinates_employee`).
3. **Child-resource resolution** — not needed and not used. Option 2
   alone, after the fix above, covers every mapped event type, so no
   query ever joins through `EmploymentRecord`/`EmployeeAssignment` to
   find an Employee id.

`hr.department.*`/`hr.position.*` events are **never** Employee-linked
— Department/Position are org-wide reference data, not per-Employee —
and are correctly, structurally absent from `EVENT_CATEGORIES` (not
filtered out at query time; they were never mapped in the first
place).

**No backfill.** An `hr.employment.updated`/`hr.assignment.*`/
`hr.assignment.manager_changed` row written *before* this checkpoint's
metadata fix lacks `metadata.employeeId` and will not appear in any
Timeline — documented, accepted, fail-safe (exclude rather than guess),
per the brief's own explicit instruction. In this repository's actual
history there is no such pre-8A.11 data (Phase 8A has never run
against real school data per root `CLAUDE.md` rule 16), so this is a
theoretical-only gap, recorded for completeness rather than because it
currently affects anyone.

### HR audit event inventory (from actual code, by category)

| Category | Event types |
|---|---|
| `employee` | `employee.created` |
| `personal` | `employee.personal_details.updated`, `employee.address.{created,updated,removed}`, `employee.emergency_contact.{created,updated,removed,primary_changed}` |
| `employment` | `hr.employment.{created,updated,ended}`, `hr.assignment.{created,ended,primary_changed}`, `hr.assignment.manager_changed` |
| `professional` | `hr.qualification.{created,updated,removed,verified,rejected}`, `hr.experience.{created,updated,removed}`, `hr.certification.{created,updated,removed,verified,rejected}` |
| `document` | `hr.employee_document.{created,updated,archived}` |
| `sensitive_access` | `hr.employee_document.sensitive_viewed` |

`hr.department.*`/`hr.position.*` are deliberately absent (not
Employee-centric — see above). Any event name not in this table is
**unmapped** and structurally cannot appear in any Timeline result,
regardless of actor/capability (fail-closed by construction, not by a
runtime check — see "Unknown/malformed event handling" below).

### Actor ≠ subject Employee (the critical invariant)

Timeline membership is decided **exclusively** by the linkage strategy
above — `actor_user_id` is never consulted to decide which Employee's
Timeline an event belongs to. `HrActivityTimelineLinkageTest::an_event_where_the_employees_linked_user_is_the_actor_but_not_the_subject_does_not_appear_on_the_actors_own_timeline`
proves this directly: an HR staff member whose own `Employee` record
exists performs a mutation on a *different* Employee — that event
appears only on the target Employee's Timeline, never the actor's own,
even though `employees.user_id` links the actor to a real Employee row
in the same School.

### Timeline read model

`EmployeeActivityTimelineService` / `EmployeeActivityTimelineQuery` /
`EmployeeActivityTimelineEntry` (all `App\Domain\HR\Application\*`,
matching every other 8A.x read model's flat namespace convention).
`EmployeeActivityTimelineEntry` is a DISCLOSURE PROJECTION exactly like
`EmployeeDirectoryEntry`/`EmployeeProfileDocumentEntry` — never
constructed from `$auditEvent->toArray()`. Its full, exhaustive field
list: `id`, `eventType`, `category`, `occurredAt`, `actorUserId`,
`actorDisplayName`, `changedFields` (`array<int,string>`). No raw
`metadata` field exists on the DTO at all — `metadata` is read
internally only to extract the single, always-safe `fields` key (a
flat array of changed field *names*, never values, guaranteed by every
writing service's own `array_keys($attributes)` construction) and,
for document events only, the classification-evidence keys described
below. Any other metadata key present on any event (past, present, or
future) is silently ignored — this is what makes "unmapped event" and
"unexpected metadata key" both fail closed by construction rather than
by an enumerated exclusion list that could go stale.

**Resource ids are deliberately NOT exposed** (brief section 50's
"determine whether..." resolved to no, for this checkpoint) —
`documentId`/`qualificationId`/etc. never leave `EmployeeActivityTimelineEntry`.
This is a conservative scope reduction, not an oversight: it makes the
"no forbidden-resource-id side channel" requirement trivially true by
construction, and can be revisited in a later checkpoint if a
navigation use case actually needs it. Actor email, login/security
detail, and role/capability internals are similarly never exposed —
only `actor_user_id` and a batch-resolved `actor_display_name`
(`User.name`, resolved for every distinct non-null actor on the page
in ONE `whereIn` query — never per-row). A missing/deleted actor
(`actor_user_id IS NULL`, or a since-deleted User via the column's own
`nullOnDelete`) yields `actorDisplayName: null`, never an error.

### Authorization — base access and section-level categories

**No new capability was created.** `hr.employees.personal.view` is the
entry gate for the Timeline as a whole — identical to
`EmployeeProfileWorkspaceService`'s 8A.10 entry gate, deliberately: the
Timeline reveals granular Restricted-tier activity (arguably more
revealing than the Profile Workspace itself, since it exposes *change
history*, not just current state), so a Directory-only actor
(`hr.employees.view`) must never reach it — proven
(`HrDefaultDenyTest`-equivalent case in `HrActivityTimelineAuthorizationTest::directory_capability_alone_does_not_grant_timeline_access`).

Beyond the entry gate, each category maps to the exact same 8A.10
capability that already gates the equivalent Profile Workspace section
— reusing the capability model wholesale, not inventing a parallel one:

| Category | Additional capability required |
|---|---|
| `employee`, `personal` | none beyond the entry gate |
| `employment` | `hr.employees.assignments.view` |
| `professional` | `hr.employees.qualifications.view` |
| `document` | `hr.employees.documents.view` (further gated per-event by classification, see below) |
| `sensitive_access` | `hr.employees.sensitive.view` (independent of `.documents.view` — an actor could theoretically hold one without the other) |

Filtering happens **before** any `EmployeeActivityTimelineEntry` is
constructed: the actor's visible-category set is computed once, turned
into a closed `event_type` allow-list, and that allow-list is applied
as a SQL `WHERE event_type IN (...)` — a forbidden category's events
are never fetched from the database at all, let alone serialized and
then hidden.

### Highly Sensitive document events — event-time classification, not current

**The core safety requirement of this checkpoint.** `hr.employee_document.created`
has always carried its classification tier in metadata (`classificationTier`,
since 8A.7) — reliable event-time evidence, used directly.
`hr.employee_document.updated`/`.archived` did **not** carry the
resulting tier before this checkpoint (only `classificationChanged`,
a boolean, on `updated`) — using the document's *current* tier for
these would have been WRONG per the brief's own worked example (a
document created `highly_sensitive`, later deliberately downgraded to
`restricted`, would make an old `updated`/`archived` event about the
Highly Sensitive period look safe using only current-state evidence).
This checkpoint therefore added `'classificationTier' => $document->classification_tier`
(the event-time/resulting tier) to both `update()` and `archive()`'s
metadata — additive only, same reasoning as the `employeeId` fix above.

**Sensitivity determination, from real event-time evidence, fail-closed
where evidence is missing:**

| Event | Sensitive iff |
|---|---|
| `hr.employee_document.created` | `metadata.classificationTier !== 'restricted'` (covers `highly_sensitive` and any missing/malformed value) |
| `hr.employee_document.updated` | `metadata.classificationChanged !== false` **OR** `metadata.classificationTier !== 'restricted'` — a transition in EITHER direction is itself Highly Sensitive information (brief section 20), regardless of the resulting tier; a legacy row lacking `classificationChanged`/`classificationTier` fails closed (treated as sensitive) |
| `hr.employee_document.archived` | `metadata.classificationTier !== 'restricted'` (missing evidence fails closed) |
| `hr.employee_document.sensitive_viewed` | always sensitive, unconditionally |

This exact logic is pushed into the SQL query itself (as `whereRaw`
JSONB-path conditions, parameterized, no string interpolation of
caller input) when the actor lacks `hr.employees.sensitive.view` — not
applied by filtering an already-fetched PHP array — so pagination
`total()` reflects VISIBLE events only (see "No count side-channel"
below). Proven end-to-end with a full 5-event real history (restricted
created → upgraded to highly_sensitive → sensitive-viewed → a further
highly_sensitive edit → downgraded back to restricted):
`hr.employees.documents.view`-only actor sees exactly 1 event (the
original creation) with `total() === 1`; an actor additionally holding
`hr.employees.sensitive.view` sees all 5 with `total() === 5`
(`HrActivityTimelineSensitiveHistoryTest`). A negative control proves
an ordinary non-classification-touching restricted-document edit is
NOT swept up by the fail-closed rule.

### No sensitive side-channel

- **Counts**: a `hr.employees.documents.view`-only actor's result
  never reflects the existence of hidden Highly Sensitive events in
  its `total()` — proven directly, not just by absence of items.
- **Category filter**: requesting `category=sensitive_access` (or any
  structurally valid category the actor cannot see) yields the exact
  same `total() === 0` result as requesting a category that
  legitimately has zero events for that Employee — proven identical,
  item-for-item
  (`HrActivityTimelinePaginationTest::requesting_a_category_the_actor_cannot_see_yields_the_same_empty_result_as_a_category_with_no_events`).
  An unrecognized (not just unauthorized) category string is treated
  identically to "no filter" — never an error, never a distinguishing
  signal.

### Unknown/malformed event handling

An event name absent from `EVENT_CATEGORIES` is excluded by
construction (never enters the SQL `event_type IN (...)` allow-list) —
proven with a real unmapped event carrying an otherwise-valid
`metadata.employeeId` link, confirming the exclusion is about the
event *name*, not the presence/absence of linkage
(`HrActivityTimelineDisclosureTest::unmapped_event_names_never_pass_through_even_when_metadata_carries_a_valid_employee_link`).
Null metadata, a non-array `fields` value, and a deleted actor are all
tolerated without a 500, a cross-tenant lookup, or any raw-value
leakage — each normalizes to a safe default (`changedFields: []`,
`actorDisplayName: null`).

### Ordering, pagination, filters, search

- **Ordering**: `occurred_at DESC, id DESC` — deterministic, proven
  stable across a real multi-event history.
- **Pagination**: default 25, maximum 100
  (`EmployeeActivityTimelineService::MAX_PER_PAGE`), clamped in
  `EmployeeActivityTimelineQuery`'s constructor exactly like
  `EmployeeDirectoryQuery`. Adjacent pages proven to never overlap or
  drop an event under a stable dataset.
- **Filters**: `category` (closed allow-list, invalid → no filter) and
  `occurredFrom`/`occurredTo` — filtering `SchoolAuditEvent.occurred_at`
  (when the action happened), never a domain effective date
  (`Employment.starts_on`, `Certification.issued_on`, ...), per the
  brief's explicit instruction.
- **Search**: **NONE.** No free-text filter over any metadata field
  exists or is planned — root `CLAUDE.md` rule 2 and this checkpoint's
  own explicit "no raw-metadata search" instruction; category + date
  range are the complete filter surface.

### Tenant isolation / RLS vs authorization

Every query is School-scoped (`where('school_id', $school->id)` plus
the underlying RLS already proven for `school_audit_events`) and runs
through the ordinary application DB connection — no privileged
connection anywhere in this class. Both required cross-cutting proofs
are explicit, separate tests: a same-School, RLS-visible Employee row
is still denied without `hr.employees.personal.view`
(`rls_visible_same_school_data_is_still_denied_without_the_capability`),
and a capability granted in School A cannot reach a School B Employee's
Timeline — resolved as `null`, the same tenant-safe result
`EmployeeProfileWorkspaceService` already established for a
cross-School id, never a distinguishing error
(`a_capability_in_school_a_cannot_reach_a_school_b_employees_timeline`).

### Performance

One employee lookup, one `paginate()` call (which itself issues one
`COUNT` and one page `SELECT`), and at most one batched
`User::whereIn('id', ...)` actor-name lookup per page — never one query
per event. Proven with a 25-event page under a real query-log
assertion (`HrActivityTimelinePaginationTest::fetching_a_page_of_many_events_issues_a_bounded_number_of_queries`).

### Audit index decision

**No migration.** The existing `(school_id, event_type)` index already
narrows every Timeline query to one Employee's realistic event volume
before the `metadata->>'employeeId'`/`subject_type`+`subject_id` JSONB
comparison ever runs — HR audit volume per School is bounded by
Employee count × lifecycle events, not the kind of scale that has
demonstrated a poor query plan. Per the brief's own explicit
preference ("no migration is preferable if current indexing is
sufficient") and root `CLAUDE.md` rule 2 (no speculative
infrastructure), no expression index on `metadata->>'employeeId'` was
added. Revisit only if a real query-plan problem is observed at actual
production data volumes.

### Timeline reads are not audited

Reading the Activity Timeline does **not** itself write a new audit
event. 8A.10 already established a real precedent for auditing a
*read* operation specifically because it exposes Highly Sensitive data
(`hr.employee_document.sensitive_viewed`) — the Timeline is different:
it is a curated, already-authorization-filtered VIEW of existing
audit history, not a new disclosure of previously-unaudited data, and
auditing every Timeline read would create exactly the "timeline shows
its own read events, which the next read then shows too" recursion the
brief's own section 40 warns against. This is a deliberate,
documented policy decision, not an oversight — revisit only if a
future product requirement specifically demands read-auditing the
Timeline itself.

### UI / HTTP

**Deferred**, same decision and same reasoning as 8A.10: no controller,
route, or Vue page. `EmployeeActivityTimelineService` is fully
authorization-aware and ready for a future checkpoint to wire an HTTP
surface against, with the capability boundary and disclosure
projection already proven. `EmployeeDirectoryEntry` and the 8A.9
Profile Workspace DTO are both unmodified — the Timeline is not
embedded into either, remaining independently, separately paginable
(brief section 53).

### Capability-cache P3 (carried forward, not re-litigated)

The pre-existing, shared `CapabilityResolver` ~60-second cache window
(documented in 8A.10's security review) is unaffected by this
checkpoint and was not redesigned here — 8A.11 introduces no new
capability and no new cache. Carried forward as-is.

### 8A.12 boundary

8A.11 ships the Timeline read layer only — no Employee Import, no
duplicate-resolution workflow (8A.12's scope), no lifecycle/separation/
rehire expansion (8A.13), no public/mobile API (8A.14), and no UI.

## Employee Import & Duplicate Controls (8A.12, implemented)

**Import is an untrusted-input boundary, not a bulk-write shortcut.**
`EmployeeImportService::import()` never writes to an HR table directly
— every row is pushed through the exact same authoritative Application
services interactive creation already uses
(`EmployeeService::create()`, `EmployeePersonalDetailService::setDetails()`,
`EmploymentService::create()`, `EmployeeAssignmentService::create()`/
`setPrimary()`), so every invariant those services already enforce
(employee-number allocation, Employee/User linkage rules, Employment
overlap rules, Assignment invariants, Department/Position/Campus
ownership, HR audit, privacy classification, service-level field
immutability) applies to imported rows identically, by construction,
with no parallel code path to keep in sync.

### Input format — normalized rows, not file parsing

Input is `array<int, array<string, mixed>>` — one associative array per
row, already parsed. **No CSV/XLSX parsing library was added** and
**no HTTP endpoint or UI exists** — both deliberately deferred, per
root `CLAUDE.md` rule 2 (no speculative infrastructure) and this
checkpoint's own explicit permission to defer physical file parsing in
favor of the smallest approved schema. A future checkpoint can add a
thin file-parsing adapter in front of this exact same service without
touching any invariant proven here.

### Allow-listed input schema (the smallest approved schema)

`EmployeeImportRow::fromArray()` accepts exactly 16 keys and rejects
any other key with `EmployeeImportUnknownFieldException` before any
duplicate check or database write: `full_name`, `user_id`,
`date_of_birth`, `nationality`, `marital_status`, `preferred_language`,
`personal_email`, `personal_phone`, `alternate_phone`,
`employment_type`, `employment_starts_on`, `probation_ends_on`,
`position_code`, `department_code`, `campus_code`,
`assignment_starts_on`.

**Deliberately excluded**: Qualifications, Experience, Certifications,
Documents, reporting-manager assignment, any historical/backdated
record, and every Highly Sensitive field (`government_id`,
`bank_account_number`, `tax_identifier`, any document
`storage_path`/`storage_disk`/`uploaded_by_user_id`). Also excluded:
every caller-controllable ownership/lifecycle field
(`record_status`, `status`, `manager_assignment_id`) — a row can never
set its own status or manager linkage; those remain service-owned.
Proven directly, per field, in
`HrEmployeeImportSchemaTest`'s `#[DataProvider]` case.

`full_name` is required and non-blank; it is preserved exactly as
trimmed (outer whitespace only) — never internally normalized or
split. Internal-whitespace-collapse/case-fold happens only inside the
separate duplicate-detector comparison, never on the stored value.

### Create-only policy — never merge

Import **only ever creates** a new Employee (and, optionally, its
first Employment/Assignment). It never updates an existing Employee's
fields, even when a row's data is provided alongside a detected exact
duplicate. There is no "upsert" mode and none is planned for this
checkpoint — a detected duplicate is reported, not merged.

### Authorization — reuses 8A.10 capabilities, no new capability

**No new capability was created.** `hr.employees.manage` is always
required. `hr.employees.personal.manage` is additionally required only
when a row carries personal-tier fields (`hasPersonalData()`);
`hr.employees.assignments.manage` only when a row carries employment/
assignment-tier fields (`hasEmploymentData()`). This is the same
per-section capability model 8A.10 already established for interactive
create — import does not relax it. All required capabilities for a row
are checked **before** any duplicate-detection query or transaction
for that row begins — a row an actor is only partially authorized for
fails with an authorization error, never a partial mutation
(`HrEmployeeImportAuthorizationTest`).

### Row transaction policy — one transaction per row, per-row atomicity

Each row's entire service-call sequence (Employee → PersonalDetail →
Employment → Assignment) runs inside one `DB::transaction()` closure,
itself inside `TenantContext::withSchool()`. A failure at any step
(e.g. an inactive Position/Department) rolls back the **entire row** —
no Employee, no PersonalDetail, no EmploymentRecord, no Assignment,
and no committed audit event survives a failed row
(`HrEmployeeImportAtomicityTest`).

### Batch failure policy — per-row atomicity, not whole-batch atomicity

A batch is a plain PHP loop over rows, not one outer transaction. A
failing row never rolls back any other row in the same batch —
independent valid rows before and after a failed row commit
independently, proven directly
(`a_failed_row_does_not_roll_back_other_valid_rows_in_the_same_batch`).
`EmployeeImportService::MAX_ROWS_PER_BATCH = 1000` bounds batch size to
keep per-call memory and duration predictable; this is an
in-process constant, not a configuration value, matching the brief's
"smallest approved" guidance — revisit only if a real need for a
larger batch is demonstrated.

### Employee number and User linkage

Employee-number allocation is untouched — imported Employees go
through `EmployeeService::create()`'s existing, already
concurrency-proven allocator (no import-specific numbering path).
`user_id`, when supplied, is validated by the same linkage rules
`EmployeeService` already enforces (a User must belong to the same
School, and — unless intentionally reused for a rehire — not already
be linked to another active Employee); an unrelated/cross-School
User produces the same `UnrelatedUserLinkageException`-derived,
redacted error an interactive create would.

### Duplicate detection — exact and potential signals

`EmployeeDuplicateDetector::detect()` runs before any transaction and
short-circuits row processing on either signal:

- **Exact** (`duplicate_exact`): an existing Employee in the same
  School (including archived/inactive Employees — rehire detection is
  intentional) already has `user_id` equal to the row's `user_id`.
  Requires an authoritative key (`user_id`); a row with no `user_id`
  can never produce an exact-duplicate result.
- **Potential** (`duplicate_potential`): no `user_id` match, but at
  least one existing Employee (any status) has a name that normalizes
  identically — `mb_strtolower(preg_replace('/\s+/u', ' ', trim($fullName)))` —
  evaluated at the SQL level via a parameterized
  `regexp_replace`+`lower`+`trim` expression, never by loading every
  Employee into PHP.

### Same-name policy — no automatic merge, no false positive block

Two Employees with the exact same name are a **legitimate possibility**
in the domain (brief's own explicit acknowledgment) — a name match
alone is reported as `duplicate_potential` (for a human to review
later; no resolution workflow exists yet) but is **never** blocked,
merged, or treated as authoritative. Proven with a real two-process
concurrency test: two genuinely concurrent same-name imports with no
`user_id` both succeed as two separate Employees, never a forced merge
(`concurrent_imports_with_the_same_name_and_no_authoritative_key_both_succeed_as_separate_employees`).

### Idempotency — safe to retry a duplicate row

Re-submitting the identical row (same `user_id`) after it was already
imported returns `duplicate_exact` referencing the existing Employee,
never a second Employee and never a raw constraint-violation error —
proven directly and under real concurrency (see below).

### Reference resolution — tenant-safe by construction

`position_code`/`department_code`/`campus_code` are resolved via
`Model::where('school_id', $school->id)->where('code', strtoupper(trim($code)))->first()`
— a code belonging to a different School and a code that does not
exist anywhere produce the **identical** `EmployeeImportReferenceNotFoundException`
(`reference_not_found`) result, with no distinguishing signal
(`HrEmployeeImportReferenceTest`). An inactive Position/Department is
rejected by the same domain exceptions
(`AssignmentInactivePositionException`/`AssignmentInactiveDepartmentException`)
interactive Assignment creation already throws — no bypass for import.

### Employment / Assignment import scope

A row may optionally carry `employment_type`+`employment_starts_on`
(both required together, never one alone) to create a first
`EmploymentRecord`, and `position_code` (implying employment data must
also be present) to create a first `EmployeeAssignment`, set as the
Employee's primary assignment. Employment overlap rules cannot be
triggered by a single row against a brand-new Employee (there is no
prior Employment to overlap) — the overlap invariant remains reachable
and enforced identically for any *future* Employment change against an
imported Employee, unchanged from 8A.4.

### Error redaction

Every row result carries `{field, code, message}` triples only — never
the original row's values, never a raw exception class or message, and
never an internal database id. `describeFailure()` maps every known
domain exception
(`EmployeeImportReferenceNotFoundException`, `UnrelatedUserLinkageException`,
`EmploymentOverlapException`, `AssignmentInactivePositionException`,
`AssignmentInactiveDepartmentException`,
`AssignmentOutsideEmploymentRangeException`,
`AssignmentDepartmentCampusScopeMismatchException`,
`AssignmentCampusMismatchException`, `AssignmentDepartmentMismatchException`,
`AssignmentPositionMismatchException`) to one of a fixed, safe code
vocabulary (`validation`, `authorization`, `duplicate_exact`,
`duplicate_potential`, `reference_not_found`, `employment_conflict`,
`assignment_conflict`); any unmapped exception gets a fully generic
`[null, 'validation', 'This row could not be processed.']` rather than
leaking its class or message. Proven by serializing a full batch result
to JSON and asserting neither a rejected personal-data value nor an
internal id nor the word "Exception" appears anywhere in the output
(`HrEmployeeImportResultTest`).

### Concurrency — proven with real, separate OS processes

`tests/Support/import-employee.php` mirrors the established
`create-employee.php`/`set-assignment-manager.php` pattern: genuinely
separate PHP processes race `EmployeeImportService::import()` against
real PostgreSQL (`$connectionsToTransact = []`, so fixtures are truly
committed and visible across processes). Three scenarios proven
(`HrEmployeeImportConcurrencyTest`):

1. Five concurrent imports linked to the **same** User → exactly one
   `created`, four `duplicate_exact`, exactly one Employee row in the
   database (the `UniqueConstraintViolationException` race path, not a
   check-then-insert race window).
2. Five concurrent imports for five distinct Employees → five unique
   employee numbers (the existing allocator remains concurrency-safe
   when reached through import).
3. Two concurrent imports with the same name and no `user_id` → both
   `created`, two Employees (no forced merge under real concurrency —
   see "Same-name policy" above).

### Audit and Activity Timeline compatibility

No new audit event type exists or is needed for import. Because import
reuses the exact same Application services, an imported Employee emits
the identical `employee.created`/`employee.personal_details.updated`/
`hr.employment.created`/`hr.assignment.created`/`hr.assignment.primary_changed`
events interactive creation already emits, and is therefore immediately
visible on `EmployeeActivityTimelineService`'s 8A.11 read projection
with no changes to that service. A failed row leaves no committed
audit event, matching its transactional rollback
(`HrEmployeeImportAuditTimelineTest`).

### Migrations

**None.** No new table, no new column, no new index. `EmployeeImportRow`/
`EmployeeImportResult`/`EmployeeDuplicateDetectionResult` are pure
in-memory DTOs; every persisted row goes through existing 8A.1–8A.5
tables via existing services.

### UI / HTTP

**Deferred, same decision and reasoning as 8A.10/8A.11.** No
controller, route, or Vue page — `EmployeeImportService::import()` is
a fully authorization-aware, transaction-safe Application-layer entry
point ready for a future checkpoint to wire a file-upload/HTTP surface
against, without redesigning any invariant proven here.

### 8A.13 boundary

8A.12 ships create-only bulk import and duplicate *detection* — no
duplicate-resolution/merge workflow, no Employee separation/offboarding,
no rehire workflow beyond the detector already recognizing an archived
Employee as an exact/potential match (8A.13's scope), and no public/
mobile API (8A.14).

## Employee lifecycle — state responsibility matrix

Rejecting one overloaded status enum (brief's explicit warning) in
favor of splitting by table:

| State machine | Lives on | Candidate values | Owned by |
|---|---|---|---|
| **Employment status** | `employment_records.status` | `draft`, `pre_joining`, `active`, `notice_period`, `separated`, `terminated`, `retired`, `deceased` | HR (this module) |
| **Assignment status** | Derived from `starts_on`/`ends_on` (no separate status column) — "current" = `starts_on <= today <= (ends_on OR infinity)` | (computed, not stored) | HR |
| **Probation / on-leave** | **Not** a value in `employment_records.status`. Probation is better modeled as a boolean/date pair on the active Employment (`probation_ends_on`, nullable) once 8A.4 needs it — avoids conflating "the legal employment is active" with "a sub-state of that active employment." On-leave is future Leave module territory (Layer 3), referencing `employment_records.id`, not a status value here. | `employment_records.probation_ends_on` (8A.4+) | HR (probation), future Leave module (leave state) |
| **Suspended** | Deliberately **not** an HR-owned concept in 8A — "suspended" already exists as `school_memberships.status` (account/access suspension, Phase 0B). If a future disciplinary-suspension-from-duty concept is needed distinct from account suspension, it's a new, explicit `employment_records` state added with its own migration, not reused from `school_memberships`. | (existing `school_memberships.status`, unrelated) | Identity & Access (existing) |
| **Account status** | `users`/`school_memberships` (existing, untouched) | (existing values) | Identity & Access |

## Authorization design

**Superseded by "HR Permissions & Sensitive-Data Controls (8A.10,
implemented)" above** — this section is the original 8A.0-era draft,
kept for historical record. Two corrections that section makes
explicit: qualification/experience/certification detail and employment/
assignment history are gated by their own dedicated capabilities
(`hr.employees.qualifications.*`/`.assignments.*`), not by
`hr.employees.personal.*` as the privacy-matrix table below still
shows; and the exact default-role-grant set was verified and finalized
in `CapabilityAndRoleSeeder`. Treat the 8A.10 section as authoritative
for anything the two disagree on.

New capabilities, `school` namespace, following the exact
`<domain>.<resource>.<action>` convention confirmed from
`CapabilityAndRoleSeeder` (e.g. `academics.subjects.manage`,
`integrations.webhooks.manage`):

```
hr.employees.view              hr.employees.manage
hr.employees.personal.view      hr.employees.personal.manage
hr.employees.assignments.view    hr.employees.assignments.manage
hr.employees.qualifications.view  hr.employees.qualifications.manage
hr.employees.documents.view      hr.employees.documents.manage
hr.employees.notes.view          hr.employees.notes.manage
hr.employees.sensitive.view      hr.employees.sensitive.manage
hr.departments.view              hr.departments.manage
hr.positions.view                hr.positions.manage
```

- `hr.employees.view`/`.manage` gate **Directory-tier** fields only.
- `hr.employees.personal.*` gates **Restricted-tier** fields
  (addresses, emergency contacts, qualifications, employment history
  detail, DOB, personal contact info).
- `hr.employees.sensitive.*` gates **Highly-Sensitive-tier** fields —
  not modeled with real data in Phase 8A (rule per brief section 4/5),
  but the capability is registered now so 8B+ (government IDs, bank
  details) has a landing spot without inventing a new capability
  family mid-flight.
- **No system-defined role** (`school_admin`, `principal`,
  `platform_super_admin`) is granted `hr.employees.sensitive.*` by
  default in the Phase 8A seeder update — an explicit, reviewed grant
  is required per School's own role configuration, per the brief's
  "do not automatically grant confidential HR permissions to every
  administrator" instruction. `school_admin` and `principal` do get
  `hr.employees.view`/`.manage`/`.personal.view` by default (directory
  + restricted administration is core to those roles' existing scope);
  `.personal.manage`, `.sensitive.*`, `.notes.*` are **not** granted by
  default to any system role — left for each School's own HR Staff
  role/assignment.
- `AuthorizesCapability` trait + `EnsureCapability` middleware, reused
  exactly as-is — no new authorization mechanism.

## Privacy classification matrix

Directly extends the existing tier definitions in
`docs/security/DATA-CLASSIFICATION.md` (which already lists "Employee
(HR) data: Sensitive, elevated to Highly Sensitive for government
identifiers/bank details/health data") rather than inventing a
competing Directory/Restricted/Highly-Sensitive taxonomy from scratch:

| Field group | Existing tier (DATA-CLASSIFICATION.md) | Phase 8A gate |
|---|---|---|
| Name, employee number, work email/phone, position, department, campus, manager | **Internal** (staff-directory-equivalent — visible to any authenticated staff member, not the public) | `hr.employees.view` |
| DOB, personal phone/email, home address, emergency contacts, qualifications, employment/assignment history detail | **Sensitive** | `hr.employees.personal.view` |
| Government identifiers, tax identifiers, bank details, background-check results | **Highly Sensitive** | `hr.employees.sensitive.view` — **not modeled with real columns in Phase 8A**, per brief section 4's explicit instruction; capability exists, data does not yet. |
| HR notes | **Sensitive**-to-**Confidential** depending on content (a note referencing a disciplinary matter or health information inherits that higher tier) — classified per-note via `employee_notes.classification_tier`, not a fixed table-wide tier. | `hr.employees.notes.view`/`.manage` |
| Employee documents | Inherited from content (a photo may be Internal; an ID-proof scan is Highly Sensitive) — `employee_documents.classification_tier` set at upload time, same principle ADR 0012 already establishes for the future Documents module. | Gated by the tier's corresponding capability at read time. |

List/summary views (the Employee Directory, 8A.8) return **Internal**-tier
fields only by default — Sensitive/Highly-Sensitive fields are never
included in a bulk/list response regardless of capability, only in the
single-Employee detail response when the caller's capability permits
(matches DATA-CLASSIFICATION.md's "minimized default visibility" rule
for Highly Sensitive, extended here to Sensitive/list-view fields as
this module's own stricter default).

## Database constraints (PostgreSQL-level vs. application-level)

| Invariant | Enforced at |
|---|---|
| `employee_number` unique per School | PostgreSQL `unique(school_id, employee_number)` |
| Every HR child row references the correct School's parent | PostgreSQL composite FK `(parent_id, school_id)` (rule 70 pattern), on every Employee→Campus, Assignment→Employment/Department/Position/Campus/Manager-Assignment, Document→Employee, etc. |
| At most one primary, currently-open Assignment per Employment | PostgreSQL partial unique index (rule 64 pattern) |
| Employment date ordering (`starts_on <= ends_on` when `ends_on` set) | PostgreSQL CHECK constraint (mirrors `academic_years_date_range_check`) |
| Assignment falls within owning Employment's date range | Application-level validation in 8A.4 (see Temporal strategy — not database-constrained, matches the `academic_years` precedent of choosing simpler validation over a `daterange`/`EXCLUDE` constraint until proven necessary) |
| No manager-assignment cycle | Application-level validation (a CHECK constraint cannot express acyclicity) |
| Work email unique per School (if collected) | PostgreSQL `unique(school_id, work_email)` — deferred to 8A.1/8A.2 schema design, flagged here so it isn't missed |
| One `Employee` ↔ `User` relationship | PostgreSQL `unique(user_id)` on `employees` where not null (a User links to at most one Employee per... — **note**: a User could in principle work at multiple Schools; 8A.1 must decide whether `unique(user_id)` is global or `unique(user_id, school_id)`; recommendation: `unique(user_id, school_id)`, since a person could be an Employee at School A and, separately, a Guardian or different Employee at School B under the same login — consistent with `SchoolMembership` already being per-(user, school)) |
| Cross-School FK prevention everywhere | Composite FK pattern, never RLS/SchoolScope alone (rule 70) |

## API and UI direction (no implementation in 8A.0)

- **API**: `/api/v1/schools/{school}/hr/...` resources — employees,
  employment-records, assignments, departments, positions,
  qualifications, certifications, documents. No API Resource class
  precedent exists yet (`CampusController`/`SchoolProfileController`
  both hand-roll `present()` arrays); Phase 8A **introduces** a real
  permission-aware serialization helper rather than copying the
  precedent verbatim, because Employee is the first entity in this
  codebase that genuinely needs field-level suppression by capability
  — this is new, justified logic, not scope creep (see Reuse decisions
  table). Pagination: neither existing controller paginates; the
  Employee Directory (8A.8) is the first list endpoint in this codebase
  that actually needs it (a School's employee count is unbounded)
  — use Laravel's standard `paginate()`, matching the existing
  `docs/architecture/API.md` pagination convention already documented
  there for future endpoints.
- **UI**: existing pattern is flat multi-page settings screens
  (`resources/js/Pages/App/SchoolSetup/*.vue`), no existing tabbed
  workspace component. The Employee Profile Workspace (8A.9) is the
  first "detail workspace with sections" screen in this codebase —
  `Overview / Employment / Assignments / Personal / Qualifications /
  Documents / Notes / Activity` as progressively-disclosed tabs/sections
  within one Vue page, not a giant single form. This is new UI
  infrastructure Phase 8A genuinely needs to introduce (not reuse),
  flagged here so it isn't mistaken for scope creep.

## Search strategy

PostgreSQL `ILIKE` + a `pg_trgm` GIN index on `employees.full_name` and
`employees.employee_number` (stock PostgreSQL extension, no new
service). Directory filters (campus, department, position, category,
lifecycle status, manager) are plain indexed `WHERE` clauses — no
generic filter framework exists in the repo to reuse, and building one
now for HR alone would be premature; 8A.8 writes straightforward
query-builder filtering matching the one-flag precedent in
`CampusController::index()`, extended to several flags.

## Domain events (Phase 8A, internal-only unless later registered)

```
EmployeeCreated
EmployeeUpdated
EmployeeArchived
EmploymentStarted
EmploymentEnded
EmployeeRehired
AssignmentStarted
AssignmentEnded
PrimaryAssignmentChanged
EmployeeManagerChanged
```

Each follows `App\Support\Events\ShouldBeOutboxed` exactly like
`GradeLevelCreated` (minimized `payload()`, no Sensitive/Highly-Sensitive
field values embedded — an event payload should carry `employee_id`/
`school_id`/references, not personal data, matching webhook rule 44's
minimization principle applied here even though these events aren't
externally webhook-published yet).

## Test strategy (matrix only — see ADR 0028 for what 8A.0 actually adds)

Per checkpoint, the standing requirement (rule 28's "allow and deny"
plus rule 13's layer table) is:

- **Tenant isolation**: School A cannot read/write/reference School B's
  Employee/Department/Position/Assignment (Eloquent-layer 404 pattern,
  same as `AcademicStructureCrudApiTest`), plus a `RawIsolationTest`-
  style raw-SQL/RLS proof for at least the `employees` table.
- **Authorization**: allow/deny pairs for every new capability,
  including the Directory-vs-Restricted-vs-Sensitive field-suppression
  behavior specifically (a caller with only `hr.employees.view` must
  never see a Restricted/Highly-Sensitive field in any response).
- **Domain integrity**: duplicate `employee_number` within a School
  rejected; same number valid in a different School; invalid
  Employment/Assignment date ranges rejected; cross-School Assignment
  references rejected at the composite-FK layer; rehire preserves
  `employee_number` and prior history; concurrent employee-number
  allocation proven safe with two real processes (mirrors
  `AcademicYearActivationConcurrencyTest`).
- **API**: pagination, filtering, permission-aware serialization,
  sensitive-field suppression.
- **UI**: directory access, profile-tab access, unauthorized-tab
  absence (not just visually hidden — the underlying data must not be
  fetched/present in the page payload for a caller lacking the
  capability).

No tests are added in 8A.0 itself beyond what's needed to prove this
checkpoint's own (non-existent) code changes — there is no HR schema
yet to test.

## Security register (Phase 8A.0)

| Finding | Severity | Notes |
|---|---|---|
| Employee Documents (8A.7) has nowhere established to live short of building a narrow, HR-scoped metadata table ahead of the general Documents module | P2 | Accepted, tracked in ADR 0028 — narrow scope, explicit future-reconciliation note, not a blocker. |
| A `school_id`-scoped `employee_number` counter table introduces a new row-lock contention point under high concurrent hiring | P3 | Same class of concern already solved once for `AcademicYear` activation; apply the same proven pattern, not a new one. |
| Default role capability grants must not include `hr.employees.sensitive.*`/`.personal.manage`/`.notes.*` for `school_admin`/`principal` | P1 (if unaddressed) | Explicitly resolved above — those stay ungranted by default in the 8A.1+ seeder update. Must be verified with an actual deny test once the seeder changes are written. |
| Bulk-export / CSV injection risk on any future Employee Directory export feature | P3 (deferred — no export feature is being built in Phase 8A.0) | Flag for whichever checkpoint (8A.8+) first adds CSV/XLSX export — formula-injection prefix sanitization (`=`, `+`, `-`, `@` leading characters) is a known, cheap mitigation to apply then, not now. |
| IDOR via `employee_id` route parameter without capability check | P0 if ever shipped without it | Standing rule (24/19) — every HR controller action added from 8A.1 onward must call `AuthorizesCapability` before touching the model; this is a review-gate item for every subsequent checkpoint, not something 8A.0 can pre-emptively fix since no controller exists yet. |

No P0/P1 currently open — 8A.0 ships no runtime code.

## Checkpoint roadmap

```
8A.0  HR Architecture & Domain Contract                          (this checkpoint — docs/ADR only)
8A.1  Employee Core Schema                                        (Employee, employee_number allocation + concurrency proof, User linkage)
8A.2  Personal Details, Contacts & Addresses                      (EmployeePersonalDetail, EmployeeAddress, EmployeeEmergencyContact)
8A.3  Departments & Positions                                     (hr_departments, positions -- employee_categories deferred, see "Department and Position (8A.3, implemented)" above)
8A.4  Employment Records & Employee Assignments                   (EmploymentRecord, EmployeeAssignment, primary-assignment invariant)
8A.5  Reporting Hierarchy                                         (manager_assignment_id validation, cycle prevention, resolution queries — no new tables) [implemented]
8A.6  Qualifications, Experience & Certifications                 (EmployeeQualification, EmployeeExperience, EmployeeCertification) [implemented]
8A.7  Employee Documents                                          (employee_documents — narrow scope, see "Documents" above) [implemented]
8A.8  Employee Directory                                          (search/filter/paginate query layer — API+UI deferred, see "Employee Directory (8A.8, implemented)") [implemented]
8A.9  Employee Profile Workspace                                  (Restricted-tier read model — tabbed UI deferred, see "Employee Profile Workspace (8A.9, implemented)") [implemented]
8A.10 HR Permissions & Sensitive-Data Controls                    (capability seeder rollout, field-suppression proof) [implemented]
8A.11 Audit & Activity Timeline                                   (AuditRecorder wiring across all HR mutations + a read timeline view) [implemented]
8A.12 Employee Import & Duplicate Controls                        (narrow CSV/XLSX importer, preview/dry-run/row errors)
8A.13 Lifecycle, Separation & Rehire                               (employment_records.status transitions, separation workflow, rehire proof)
8A.14 API & Mobile-Ready Read Layer                               (stable versioned read contracts, sparse fieldsets)
8A.15 Accessibility, Performance & Security Hardening              (index review, a11y pass, security regression suite)
8A.16 Full Regression & Phase Closure                             (merge-readiness gate per CLAUDE.md/root brief section 32)
```

No reordering from the brief's own list was needed — repository
evidence (existing dependency-safe order: Department/Position before
Employment/Assignment; no separate hierarchy table needed for 8A.5)
already matched it.
