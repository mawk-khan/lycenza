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
8A.5  Reporting Hierarchy                                         (manager_assignment_id validation, cycle prevention, resolution queries — no new tables)
8A.6  Qualifications, Experience & Certifications                 (EmployeeQualification, EmployeeExperience, EmployeeCertification)
8A.7  Employee Documents                                          (employee_documents — narrow scope, see "Documents" above)
8A.8  Employee Directory                                          (search/filter/paginate API + list UI)
8A.9  Employee Profile Workspace                                  (tabbed detail UI — first of its kind in this codebase)
8A.10 HR Permissions & Sensitive-Data Controls                    (capability seeder rollout, field-suppression proof)
8A.11 Audit & Activity Timeline                                   (AuditRecorder wiring across all HR mutations + a read timeline view)
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
