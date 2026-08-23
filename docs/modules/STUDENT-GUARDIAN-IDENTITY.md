# School OS — Student & Guardian Identity (Phase 1A)

Status: `students`, `guardians`, and their first-class relationship
(`student_guardian_relationships`, Phase 1A.2) exist. No API, UI,
capabilities, domain events, Guardian contact info, addresses, or
User/persona linking exist yet — see "Deferred" below. This document
will grow as later Phase 1A checkpoints add those pieces; it
intentionally does not describe work that has not landed.

## Principle

A Student is a permanent School-level identity, deliberately
independent of admission, enrollment, grade/section placement,
attendance, fee account, or portal login — all separate concerns owned
by future Layer 2/3 modules (`docs/architecture/DOMAIN-MAP.md`). A
Guardian is likewise a permanent School-level identity, never
duplicated once per child: a Guardian with several Students at the same
School is one row. Neither table has a `user_id` column — a future
`StudentUserLink`/`GuardianUserLink` (portal access) would be an
explicit, separate join, following the same pattern the `User` model's
own docblock already anticipates ("future domain records ... link to a
User when they need login, they don't extend it").

## Schema (this checkpoint)

### `students`

| Column | Notes |
|---|---|
| `id` | UUIDv7 (ADR 0019) |
| `school_id` | RLS tenant column (`App\Support\Tenancy\BelongsToSchool`) |
| `student_number` | Unique within a School only (`unique(school_id, student_number)`), never globally unique, stable across academic years, carries no grade/class/roll-number meaning |
| `first_name`, `middle_name` (nullable), `last_name` (nullable) | |
| `date_of_birth` | |
| `status` | `active`/`inactive` — same deactivate-don't-delete convention as every other reference/identity table in this codebase (CLAUDE.md rule 73) |

`unique(id, school_id)` is present (unused by any child table yet) so a
future composite foreign key — `StudentGuardianRelationship`,
`StudentIdentifier` — can reference `(id, school_id)` without a
retroactive migration.

### `guardians`

| Column | Notes |
|---|---|
| `id` | UUIDv7 |
| `school_id` | RLS tenant column |
| `first_name`, `middle_name` (nullable), `last_name` (nullable) | |
| `status` | `active`/`inactive` |

No contact fields (email/phone/address) on this table — see "Deferred."
`unique(id, school_id)` present for the same forward-looking reason as
`students`.

## Relationship architecture (Phase 1A.2)

The relationship between a Student and a Guardian is a **first-class
domain record** (`student_guardian_relationships`), never
`father_id`/`mother_id`/`guardian_1_id`/`guardian_2_id` columns on
`students`. Rejected explicitly: those columns cap the number of
Guardians per Student, cannot express shared/step/foster/legal-only
guardianship, and cannot let two Students (siblings) reference the same
Guardian row without duplicating it. A real join table has none of
these limits and matches how `MembershipRoleAssignment` already models
"which role(s) does this membership grant" as its own table rather than
columns on `School Membership`.

### `student_guardian_relationships`

| Column | Notes |
|---|---|
| `id` | UUIDv7 |
| `school_id` | RLS tenant column; also an ordinary FK to `schools(id)` |
| `student_id` | Composite-FK-protected — see "Same-School integrity" |
| `guardian_id` | Composite-FK-protected — see "Same-School integrity" |
| `relationship_type` | Family relationship — see "Relationship types" |
| `is_primary` | At most one `true` per Student — see "Primary Guardian invariant" |
| `is_legal_guardian` | Legal authority — independent of `relationship_type` |
| `is_emergency_contact` | Per-relationship, not per-Guardian |
| `is_authorized_pickup` | Per-relationship, not per-Guardian |

### Relationship types

`App\Domain\Guardians\Infrastructure\RelationshipType` — a native PHP
backed-string enum (`Mother`/`mother`, `Father`/`father`,
`GenericParent`/`parent`, `StepParent`/`step_parent`,
`Grandparent`/`grandparent`, `LegalGuardian`/`legal_guardian`,
`FosterGuardian`/`foster_guardian`, `Sibling`/`sibling`,
`Relative`/`relative`, `Other`/`other`), cast on the Eloquent model via
`casts()`. This is the first column in the codebase backed by a native
PHP enum cast rather than a plain string + `in:...`/`Rule::in()`
validation (the convention every `status` column elsewhere in this
codebase uses) — chosen because "which of ten fixed relationship
kinds" is exactly the closed-set-of-named-values case a PHP backed enum
exists for, and the codebase already uses backed string enums elsewhere
(`App\Support\Idempotency\IdempotencyOutcome`,
`App\Support\Observability\QueueName`) for the identical reason, just
not yet as an Eloquent attribute cast. No PostgreSQL native enum type
is used (none exists anywhere in this codebase; the column is a plain
`string`).

`relationship_type = grandparent` and `is_legal_guardian = true` are
deliberately independent — legal authority is not encoded by picking a
specific `relationship_type` value; a grandparent, a relative, or an
"other" can each independently be the legal guardian.

### Sibling reuse

A Guardian linked to two Students at the same School is one `guardians`
row referenced by two separate `student_guardian_relationships` rows —
never a duplicated Guardian identity. Proven in
`StudentGuardianRelationshipTest::a_guardian_is_reused_not_duplicated_across_sibling_students`.

### Same-School integrity (database-enforced)

Both `student_id` and `guardian_id` use the same composite-foreign-key
pattern Section/SubjectOffering established in Phase 0D:

```
(student_id, school_id)  -> students(id, school_id)   ON DELETE CASCADE
(guardian_id, school_id) -> guardians(id, school_id)   ON DELETE CASCADE
```

A School A Student can never be paired with a School B Guardian (or
vice versa) — PostgreSQL rejects the INSERT with a foreign-key
violation regardless of what a client or a compromised application
layer sends, proven via a raw `pgsql_admin` insert (bypassing RLS's own
`WITH CHECK` so the failure can only be the composite FK) in
`tests/Feature/Postgres/StudentGuardianRelationshipIntegrityTest.php`.

### Uniqueness invariants (database-enforced)

- `unique(school_id, student_id, guardian_id)` — one canonical
  relationship row per Student/Guardian pair. Two different
  `relationship_type`/flag combinations for the same pair are never two
  rows; the existing row is updated instead (e.g.
  `relationship_type = grandparent, is_legal_guardian = true` on one
  row, not a second row for the "legal guardian" aspect).
- A partial unique index,
  `student_guardian_relationships_one_primary_per_student` on
  `(school_id, student_id) WHERE is_primary = true` — the same
  "PostgreSQL partial unique index as the concurrency-safety mechanism"
  pattern `academic_years_one_active_per_school` (Phase 0D) already
  established. At most one primary Guardian per Student; a Student may
  have zero. Different Students may each independently have their own
  primary Guardian.

### Delete behavior

Both composite foreign keys cascade FROM `students`/`guardians` INTO
`student_guardian_relationships` (`ON DELETE CASCADE`): hard-deleting a
Student or Guardian removes only its own relationship rows, never the
other party's identity row, and never another Student's relationship
with a shared Guardian. Deleting a relationship row can never delete a
Student or Guardian — foreign keys only cascade parent-to-child, so
this direction is structurally impossible regardless of the `ON DELETE`
clause chosen. Students/Guardians have no delete endpoint today (Status
is `active`/`inactive`, matching CLAUDE.md rule 73's deactivate-don't-
delete convention) — this cascade is defensive completeness for the
rare administrative hard-delete, not an expected normal operation.

### Indexes and why each exists

- `unique(school_id, student_id, guardian_id)` — duplicate prevention;
  also serves "all Guardians for a Student" (school_id, student_id is a
  usable prefix).
- `student_guardian_relationships_one_primary_per_student` partial
  unique index — the primary-Guardian invariant; also serves "find the
  primary Guardian for a Student."
- `index(school_id, guardian_id)` — "all Students for a Guardian";
  `guardian_id` is not a usable prefix of the unique index above (it's
  third), so this is a genuinely separate index. Also backs the
  composite FK's cascade-delete lookup when a Guardian row is removed.

No other index was added — a plain `index('school_id')` would be
redundant given the unique index above already leads with `school_id`.

### Model relationships

`Student::guardianRelationships()` / `Guardian::studentRelationships()`
return the domain records themselves (`HasMany<StudentGuardianRelationship>`)
— relationship_type, is_primary, etc. `Student::guardians()` /
`Guardian::students()` return the plain related-identity collection
(`BelongsToMany`, with the relationship columns available via
`->pivot` for read convenience) — deliberately named to distinguish
"the join record" from "the collection on the other side," per this
checkpoint's brief. No service layer was introduced: with no
controller/API yet calling into this model, and no business rule beyond
what the database constraints already enforce authoritatively, a
service would have no real caller and nothing to orchestrate (Phase 0D's
own convention, CLAUDE.md rule 76, reserves a service for once real
invariants/orchestration exist) — fixtures and future callers create
`StudentGuardianRelationship` rows directly, the same way
`CreatesTenancyFixtures`' other `create*` helpers do for every other
Phase 0D model.

### RLS

Same two-layer isolation as every other tenant-owned table
(`BelongsToSchool` + `TenantRls::enable('student_guardian_relationships')`),
proven under the real `school_os_app` runtime role in
`StudentGuardianRelationshipIntegrityTest`: RLS enabled and forced, zero
visibility with no context, School A cannot read/discover/modify/create
a School B relationship row.

## Module layout

`App\Domain\Students\Infrastructure\Student` and
`App\Domain\Guardians\Infrastructure\{Guardian,StudentGuardianRelationship,RelationshipType}`
— two separate modules (matching `docs/architecture/DOMAIN-MAP.md`'s
Layer 2 split of Students/SIS and Guardians into distinct rows), each
following the `Domain/Application/Infrastructure/Http` convention
(`apps/platform/app/Domain/README.md`) even though only `Infrastructure`
exists yet — no `Application`/`Http` layer is needed until this
checkpoint's data model grows business logic or an API.
`StudentGuardianRelationship` lives under the Guardians module because
`docs/architecture/DOMAIN-MAP.md`'s own "Owns" column already assigns
"guardian-student relationships" to Guardians, not Students/SIS.

## RLS and tenancy

`students` and `guardians` use `App\Support\Tenancy\BelongsToSchool`
(Eloquent scope + auto-fill `school_id`) and
`App\Support\Tenancy\TenantRls::enable()` (Postgres RLS, enabled and
forced) — the same two-layer isolation every other tenant-owned table
in this codebase uses. Proven at the raw-SQL level under the real
`school_os_app` runtime role, independent of Eloquent, in
`tests/Feature/Postgres/StudentGuardianRlsIsolationTest.php`.
`student_guardian_relationships` uses the identical mechanism — see
"RLS" under "Relationship architecture" above and
`tests/Feature/Postgres/StudentGuardianRelationshipIntegrityTest.php`.

## Deferred (not yet implemented)

- **GuardianContact** — email/phone/address for a Guardian. Deferred
  because the repository has no existing reusable PII/searchable-
  contact-data pattern to follow yet (no blind index, no normalized-
  email/phone convention beyond Laravel's own `encrypted` cast used
  today only for webhook secrets and AI signing keys) — this needs its
  own deliberate design, not an ad hoc column added here.
- **StudentIdentifier** (government IDs, admission numbers) — explicitly
  out of scope per the Phase 1A startup brief ("government
  identifiers").
- **Address** — no reusable `Address`/`Addressable` architecture exists
  anywhere in this codebase yet; a future checkpoint will need to design
  a minimal one, not invent it inline here.
- **StudentUserLink / GuardianUserLink** (portal login) — explicitly out
  of scope per the Phase 1A startup brief ("portal linking").
- **API, UI, capabilities (`students.*`/`guardians.*`), domain events,
  audit wiring** — none exist yet. The established capability-naming
  convention in this codebase is a `.view`/`.manage` pair per resource
  (e.g. `academics.years.view`/`.manage`), not the four-verb
  `.view`/`.create`/`.update`/`.archive` set sometimes used as
  illustrative examples — a future checkpoint adding
  `students.view`/`students.manage` and
  `guardians.view`/`guardians.manage` should follow the two-verb
  pattern already established, not introduce a new one.
