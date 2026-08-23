# School OS — Student & Guardian Identity (Phase 1A)

Status: **foundation slice only** (this checkpoint). `students` and
`guardians` exist as permanent, School-scoped identity tables. No API,
UI, capabilities, domain events, or relationship model exist yet — see
"Deferred" below. This document will grow as later Phase 1A checkpoints
add those pieces; it intentionally does not describe work that has not
landed.

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

## Module layout

`App\Domain\Students\Infrastructure\Student` and
`App\Domain\Guardians\Infrastructure\Guardian` — two separate modules
(matching `docs/architecture/DOMAIN-MAP.md`'s Layer 2 split of
Students/SIS and Guardians into distinct rows), each following the
`Domain/Application/Infrastructure/Http` convention
(`apps/platform/app/Domain/README.md`) even though only `Infrastructure`
exists yet — no `Application`/`Http` layer is needed until this
checkpoint's data model grows business logic or an API.

## RLS and tenancy

Both tables use `App\Support\Tenancy\BelongsToSchool` (Eloquent scope +
auto-fill `school_id`) and `App\Support\Tenancy\TenantRls::enable()`
(Postgres RLS, enabled and forced) — the same two-layer isolation every
other tenant-owned table in this codebase uses. Proven at the raw-SQL
level under the real `school_os_app` runtime role, independent of
Eloquent, in
`tests/Feature/Postgres/StudentGuardianRlsIsolationTest.php`.

## Deferred (not yet implemented)

- **GuardianContact** — email/phone/address for a Guardian. Deferred
  because the repository has no existing reusable PII/searchable-
  contact-data pattern to follow yet (no blind index, no normalized-
  email/phone convention beyond Laravel's own `encrypted` cast used
  today only for webhook secrets and AI signing keys) — this needs its
  own deliberate design, not an ad hoc column added here.
- **StudentGuardianRelationship** — the many-to-many join supporting
  mother/father/guardian/etc. relationship types. Deferred to the next
  slice once both parent tables exist (they now do); explicitly NOT
  `father_id`/`mother_id` columns on `students`.
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
