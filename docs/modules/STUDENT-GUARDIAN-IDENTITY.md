# School OS — Student & Guardian Identity (Phase 1A)

Status: `students`, `guardians`, their first-class relationship
(`student_guardian_relationships`, Phase 1A.2), Guardian contact
information with a searchable-encrypted-PII architecture
(`guardian_contacts`, Phase 1A.3), and `students.*`/`guardians.*`
authorization capabilities plus the supported Application-layer
mutation services (`StudentService`, `GuardianService`,
`StudentGuardianRelationshipService`, Phase 1A.4) exist. No API, UI,
domain events, addresses, User/persona linking, or verification
workflows (OTP/email/SMS) exist yet — see "Deferred" below. This
document will grow as later Phase 1A checkpoints add those pieces; it
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

`unique(id, school_id)` backs `StudentGuardianRelationship`'s composite
foreign key (Phase 1A.2) and is available for a future
`StudentIdentifier` without a retroactive migration.

### `guardians`

| Column | Notes |
|---|---|
| `id` | UUIDv7 |
| `school_id` | RLS tenant column |
| `first_name`, `middle_name` (nullable), `last_name` (nullable) | |
| `status` | `active`/`inactive` |

No contact fields (email/phone/address) directly on this table —
contact information lives in `guardian_contacts` (Phase 1A.3, see
below). `unique(id, school_id)` backs both `StudentGuardianRelationship`
and `GuardianContact`'s composite foreign keys.

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
checkpoint's brief. Phase 1A.2 introduced no service layer (no real
caller/orchestration existed yet); Phase 1A.4 added
`App\Domain\Guardians\Application\StudentGuardianRelationshipService`
once genuine orchestration existed to justify one (cross-School
guard translation, demote-then-promote primary changes, raw-SQL-error
translation) — see "Application services" below. `attach()`/`sync()`
on the `BelongsToMany` relations above remain explicitly unsupported for
writes (Phase 1A.3's finding, reaffirmed by this service's existence:
every write goes through `StudentGuardianRelationship` directly, never
the pivot convenience methods).

### RLS

Same two-layer isolation as every other tenant-owned table
(`BelongsToSchool` + `TenantRls::enable('student_guardian_relationships')`),
proven under the real `school_os_app` runtime role in
`StudentGuardianRelationshipIntegrityTest`: RLS enabled and forced, zero
visibility with no context, School A cannot read/discover/modify/create
a School B relationship row.

## Guardian contact & searchable PII (Phase 1A.3)

Guardian email/mobile contact information lives in a dedicated
`guardian_contacts` table — never `email`/`phone`/`mobile`/
`whatsapp_number` columns directly on `guardians`, since a Guardian may
have several (personal email, work email, primary/secondary mobile).
See ADR 0028 for the full architectural decision record; this section
covers what it means for this module specifically.

### The privacy problem and its solution

The repository had no prior pattern for "a value that must be both
encrypted at rest AND exact-match searchable" — encryption and
plaintext search are normally in tension (you can't index ciphertext
for equality search the way you'd index a plaintext column). The
solution, established here and intended to be reusable by future
modules with the same shape:

1. **`encrypted_value`** (text) — the real contact value, via Laravel's
   built-in `encrypted` Eloquent cast (APP_KEY-based, authenticated,
   non-deterministic — the same mechanism `WebhookEndpoint.secret_encrypted`
   already uses). Decrypted only in-memory, on demand.
2. **`lookup_hash`** (char(64) hex) — a keyed HMAC-SHA-256 digest of
   the *normalized* value, computed by
   `App\Support\Privacy\ContactLookupHasher` as
   `HMAC-SHA256("guardian-contact|{school_id}|{type}|{normalized_value}", key)`.
   Deterministic within one School (enabling an exact-match `WHERE
   lookup_hash = ?` query), but the identical value in two different
   Schools produces two different digests because `school_id`
   participates in the hashed domain string.

No column anywhere stores plaintext or normalized-plaintext contact
information. The lookup key
(`CONTACT_LOOKUP_HMAC_KEY`/`config/privacy.php`) is a secret
deliberately separate from `APP_KEY` so the two can rotate
independently; `lookup_key_version` is stored per row for a future
rotation to identify stale rows (no rotation workflow is implemented
yet — see ADR 0028's "Future extraction/evolution path"). Fails closed:
`ContactLookupHasher::hash()` throws
`ContactLookupKeyNotConfiguredException` if the key is empty, rather
than silently hashing with a predictable/missing key.

### `guardian_contacts`

| Column | Notes |
|---|---|
| `id` | UUIDv7 |
| `school_id` | RLS tenant column; also an ordinary FK to `schools(id)` |
| `guardian_id` | Composite-FK-protected against `guardians(id, school_id)` |
| `type` | `App\Domain\Guardians\Infrastructure\ContactType` enum cast — `email`/`mobile` only; deliberately not `whatsapp` (a mobile number's WhatsApp-capability is a future Communications-module concern, not a distinct contact identity here) |
| `encrypted_value` | Ciphertext only — see above |
| `lookup_hash` | Keyed HMAC digest only — see above |
| `lookup_key_version` | Which `CONTACT_LOOKUP_HMAC_KEY` version produced `lookup_hash` |
| `label` | Nullable, free text (e.g. "Personal", "Work") |
| `is_primary` | At most one active primary per type — see below |
| `is_active` | Contacts are deactivated, not deleted |
| `verified_at` | Nullable identity metadata only — no OTP/email/SMS verification workflow exists yet; a future checkpoint populates this |

`encrypted_value` and `lookup_hash` are `$hidden` on the Eloquent model
so an accidental `toArray()`/`toJson()` never serializes ciphertext or
the lookup digest.

### Email and phone normalization contracts

`App\Support\Privacy\EmailNormalizer`: trims whitespace, lowercases the
entire address (both local part and domain, for one predictable,
deterministic case-folding rule), validates via PHP's
`filter_var(..., FILTER_VALIDATE_EMAIL)`. Deliberately does **not**
strip dots or `+tags`, and does **not** apply any provider-specific
aliasing (e.g. Gmail's dot-insensitivity) — `a.b@x.com` and `ab@x.com`
are never treated as the same identity.

`App\Support\Privacy\PhoneNormalizer`: requires canonical **E.164**
input at the normalization boundary
(`/^\+[1-9]\d{7,14}$/` — a leading `+`, non-zero first digit, 8-15
digits total). A local number without a country code (`9876543210`) is
**rejected, not guessed** — this codebase has no phone-parsing library
dependency and deliberately does not add one, or a homemade
international parser, for this identity-foundation checkpoint.
Country-aware local-number conversion (`9876543210` → `+919876543210`
using the School's country context) is left to a future UI/import
workflow that actually has that context available.

### Duplication and primary-contact invariants (database-enforced)

- `unique(school_id, guardian_id, type, lookup_hash)` — prevents a
  **redundant duplicate row on the same Guardian**, unconditional on
  `is_active` (the simplest rule; re-adding a value a Guardian
  previously deactivated means reactivating that existing row, a
  future mutation-service concern, not inserting a second row).
  Deliberately **not** `unique(school_id, lookup_hash)` — two different
  Guardians legitimately sharing a household email/phone (proven in
  `GuardianContactTest::the_same_email_across_different_guardians_is_allowed`
  and `..._the_same_mobile_..._is_allowed`) must remain possible.
- A partial unique index,
  `guardian_contacts_one_active_primary_per_type` on `(school_id,
  guardian_id, type) WHERE is_primary = true AND is_active = true` — at
  most one active primary contact per Guardian per type (one primary
  email, one primary mobile, independently). The same "partial unique
  index as the concurrency-safety mechanism" pattern
  `academic_years_one_active_per_school` (Phase 0D) and
  `student_guardian_relationships_one_primary_per_student` (Phase
  1A.2) already established.

`GuardianContactService::create()` deliberately does **not**
auto-demote an existing primary on insert (mirroring
`AcademicYearService`'s own `create()`/`activate()` split — `create()`
never demotes a previously-active year; only the separate `activate()`
does): asking to create a second active primary contact is rejected by
the database, not silently "fixed." `GuardianContactService::setPrimary()`
is the dedicated method that safely demotes the previous primary and
promotes the new one in one transaction.

### Duplicate-candidate lookup (never auto-merge)

`GuardianContactService::findCandidatesBySchool()` performs an
exact-match, same-School-only search: normalizes the input, computes
the tenant-separated digest, and queries the indexed `lookup_hash`
column — it never decrypts every Guardian's contacts to search, and
never queries across Schools. It may legitimately return **multiple**
Guardians (household-shared contact) and never auto-merges or blocks
Guardian creation — this is candidate detection only, for a future
UI/workflow to act on.

### Same-School integrity, RLS, and indexes

`guardian_id` uses the same composite-FK pattern as
`student_guardian_relationships`
(`(guardian_id, school_id) -> guardians(id, school_id) ON DELETE CASCADE`)
— a School A row can never reference a School B Guardian, proven via a
raw `pgsql_admin` insert bypassing RLS in
`GuardianContactIntegrityTest`. Same two-layer isolation as every other
tenant-owned table (`BelongsToSchool` + `TenantRls::enable()`), RLS
enabled and forced, proven under the real `school_os_app` runtime role:
zero visibility with no context, School A cannot read/discover/modify/
create a School B contact row.

`index(school_id, type, lookup_hash)` backs the tenant/type-scoped
exact-match candidate lookup — a plain index, never unique, since the
same digest may legitimately match multiple Guardians.

### Configuration and secrets

`CONTACT_LOOKUP_HMAC_KEY` (`.env`, `config/privacy.php`) — required in
any environment that creates/looks up Guardian contacts; the local-dev
`.env.example` value is an inert placeholder, never a real secret (ADR
0016). `CONTACT_LOOKUP_HMAC_KEY_VERSION` (default `1`) tracks which key
produced existing digests, for a future rotation. See ADR 0028 for the
full rotation design (not yet implemented).

### Audit

`GuardianContactService` records `guardian_contact.added`,
`guardian_contact.primary_changed`, and `guardian_contact.deactivated`
via the existing `App\Support\Audit\AuditRecorder` — metadata is
identity-safe by construction (`guardianId`, contact `type`, boolean
flags only; never the decrypted value, ciphertext, or lookup hash).

## Module layout

`App\Domain\Students\Infrastructure\Student` and
`App\Domain\Guardians\Infrastructure\{Guardian,StudentGuardianRelationship,RelationshipType,GuardianContact,ContactType}`,
plus `App\Domain\Students\Application\StudentService` and
`App\Domain\Guardians\Application\{GuardianService,StudentGuardianRelationshipService,GuardianContactService}`
— two separate modules (matching `docs/architecture/DOMAIN-MAP.md`'s
Layer 2 split of Students/SIS and Guardians into distinct rows), each
following the `Domain/Application/Infrastructure/Http` convention
(`apps/platform/app/Domain/README.md`). Both modules now have a
populated `Application` layer (Phase 1A.4); `StudentGuardianRelationshipService`
lives under Guardians, not Students, for the same reason
`StudentGuardianRelationship` itself does (below). No `Http` layer
exists yet in either module, since no API/controller has been built.
Generic reusable pieces (`EmailNormalizer`, `PhoneNormalizer`,
`ContactLookupHasher`) live under `App\Support\Privacy`, not under
either Domain module, since they depend on nothing Guardian-specific
and Support code must never depend on Domain code (layering rule,
`docs/architecture/DOMAIN-MAP.md`).
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
`guardian_contacts` likewise — see "Same-School integrity, RLS, and
indexes" above and `tests/Feature/Postgres/GuardianContactIntegrityTest.php`.

## Deferred (not yet implemented)

- **StudentIdentifier** (government IDs, admission numbers) — explicitly
  out of scope per the Phase 1A startup brief ("government
  identifiers").
- **Address** — no reusable `Address`/`Addressable` architecture exists
  anywhere in this codebase yet; a future checkpoint will need to design
  a minimal one, not invent it inline here.
- **StudentUserLink / GuardianUserLink** (portal login) — explicitly out
  of scope per the Phase 1A startup brief and Phase 1A.3's brief
  ("portal linking").
- **Contact verification workflows** (OTP, verification email/SMS,
  provider callbacks) — `guardian_contacts.verified_at` exists as
  identity metadata only; no verification mechanism populates it yet,
  explicitly out of scope per Phase 1A.3's brief.
- **Contact lookup HMAC key rotation** — `lookup_key_version` exists so
  this is possible later without a schema change, but no rotation
  workflow (a backfill job re-hashing every row under a new key) is
  implemented — see ADR 0028.
- **API, UI, domain events** — none exist yet (Phase 1A.4 added audit
  events for the new Student/Guardian/relationship mutations, matching
  GuardianContact's Phase 1A.3 precedent, but deliberately no
  transactional-outbox domain events — see "Application services"
  below for why).
- **Form/shape validation** (required fields, string lengths, date
  formats) for the new Application services — deferred to the
  controller layer that will call them (Phase 1A.5), matching
  `AcademicYearController::store()`'s `$request->validate()` pattern.
  The services validate genuine *domain* invariants only (duplicate
  Student Number, valid status values, same-School Student/Guardian) —
  not basic input shape, which has no meaning without an HTTP request
  to validate.

## Authorization (Phase 1A.4)

Two capability pairs, following this codebase's established
`.view`/`.manage`-per-resource convention (e.g. `academics.years.view`/
`.manage`) rather than a separate verb per CRUD action:

| Capability | Covers |
|---|---|
| `students.view` | Reading Student identity |
| `students.manage` | Creating/updating a Student, changing Student status, and (jointly with `guardians.manage`) linking/unlinking/changing the primary Guardian |
| `guardians.view` | Reading Guardian identity **and** Guardian contact information (email/mobile) — contact information is a Guardian-owned concept, not a capability an administrator thinks about separately |
| `guardians.manage` | Creating/updating a Guardian, changing Guardian status, adding/updating/deactivating a Guardian's contact information, and (jointly with `students.manage`) linking/unlinking/changing the primary Guardian |

Deliberately **not** split further (no `guardians.contacts.manage`,
no `students.guardians.link`) — CLAUDE.md rule 47/Phase 0D section
48's "keep the capability model understandable to School
administrators" principle, and the brief's explicit rejection of
micro-capabilities for this checkpoint.

**Linking/unlinking a Guardian to a Student, and changing a Student's
primary Guardian, require BOTH `students.manage` AND
`guardians.manage`** — the safe default the brief specifies, since the
operation mutates both domain identities' relationship at once. A
future controller must check both before calling
`StudentGuardianRelationshipService::link()`/`setPrimary()`/`unlink()`.

### Default role grants

Capabilities are seeded via `CapabilityAndRoleSeeder` (the existing
canonical mechanism — no new migration). Only two School-scoped roles
exist in this codebase today (`school_admin`, `principal`); no `Teacher`
or other role exists yet, so none was invented for this checkpoint
(CLAUDE.md rule 2).

| Role | `students.view` | `students.manage` | `guardians.view` | `guardians.manage` |
|---|---|---|---|---|
| `school_admin` | ✅ | ✅ | ✅ | ✅ |
| `principal` | ✅ | ✅ | ✅ | ✅ |

Both existing School-scoped roles receive the full set. For
`school_admin` this follows its existing "manage everything
operational" pattern. For `principal`, this checkpoint deliberately
matches the *academic-structure* precedent (Principal already gets
`academics.structure.manage`/`academics.years.manage`/
`academics.subjects.manage` — hands-on operational authority) rather
than the *School-settings* precedent (Principal gets `school.settings.view`
only, never `.manage`) — a Principal in a real school actively manages
Student/Guardian records (admissions follow-up, discipline, contacting
parents), which is an operational concern, not a purely administrative
one like School profile/Campus configuration (where Principal stays
view-only).

### Authorization boundary

Application-layer services in this codebase are **deliberately
authorization-neutral** — `StudentService`, `GuardianService`,
`StudentGuardianRelationshipService`, and (from Phase 1A.3)
`GuardianContactService` never call `Gate::authorize()`/
`CapabilityResolver` themselves, following the exact same pattern
`App\Domain\AcademicStructure\Application\AcademicYearService`
established: a controller validates input, calls
`$this->authorizeCapability(...)` (the `AuthorizesCapability` trait) or
uses the `capability:` route middleware, *then* calls the service. See
`App\Domain\AcademicStructure\Http\Controllers\AcademicYearController`
for the exact shape every future Student/Guardian controller must
follow.

**This is a deliberate, explicit choice, not an oversight**: it keeps
authorization logic in exactly one layer (the HTTP boundary, where the
actor and the specific action being attempted are both unambiguous),
avoids duplicating capability checks between a controller and the
service it calls, and matches this checkpoint's own instruction not to
put `Gate` calls inside low-level normalizers/hashers/models/database
helpers. Since **no controller exists yet** in this checkpoint (Phase
1A.4 explicitly excludes HTTP/API work), these services are currently
reachable from application code (tests, console commands, future
internal callers) with no authorization check at all — this is
intentional for Phase 1A.4, but means **Phase 1A.5 (the HTTP/API layer)
must wire `Gate::authorize('capability', ...)` into every new controller
action before these services become reachable from an untrusted
request** — a future controller calling one of these services without
an `authorizeCapability()` call first is a bug, not a valid shortcut.
The authorization test matrix in
`tests/Feature/Authorization/StudentGuardianCapabilityTest.php` proves
the capability grants themselves resolve correctly (least privilege,
tenant isolation) using `Gate::forUser($user)->allows('capability', ...)`
directly, exactly as a future controller will call it.

## Application services (Phase 1A.4)

Three new services, following `AcademicYearService`/
`GuardianContactService`'s established shape: validate domain
invariants → write state inside `DB::transaction()` → audit, with
School context enforced via `TenantContext::withSchool()` (never
ambient). `school_id` is never accepted as caller-supplied data in any
of these services — it always derives from the `$school`/`$student`/
`$guardian` object the caller already holds (itself only obtainable
through real, verified tenant resolution upstream).

### `App\Domain\Students\Application\StudentService`

`create()`, `update()` (identity fields only), `changeStatus()`
(`active`/`inactive` — see "Status remains a plain string" below).
`create()`/`update()` catch the database's
`unique(school_id, student_number)` violation
(`Illuminate\Database\UniqueConstraintViolationException`) and
translate it to `DuplicateStudentNumberException` (422,
`DUPLICATE_STUDENT_NUMBER`) — the database constraint remains the
actual guarantee; this is a translation, not a replacement. An invalid
`changeStatus()` value throws `InvalidStudentStatusException` (422,
`INVALID_STUDENT_STATUS`).

### `App\Domain\Guardians\Application\GuardianService`

`create()`, `update()`, `changeStatus()` — identical shape to
`StudentService`, minus the duplicate-number concern (Guardian has no
comparable unique business identifier). Never touches
`guardian_contacts` — Guardian identity and Guardian contact
information are composed by the caller, not coupled inside one service
(`GuardianContactService` remains the sole owner of its
encryption/HMAC-lookup logic, per this checkpoint's brief — nothing
here duplicates or wraps it).

### `App\Domain\Guardians\Application\StudentGuardianRelationshipService`

The **only** sanctioned mutation path for `StudentGuardianRelationship`
— resolves Phase 1A.2's P3 finding for good: `Student::guardians()`/
`Guardian::students()`'s `attach()`/`sync()` are never called anywhere
in this service (Phase 1A.3's regression test,
`StudentGuardianRelationshipTest::attach_is_not_the_supported_mutation_api_and_fails_closed`,
remains the proof that path is intentionally unsafe — nothing in this
checkpoint changes that).

- **`link(Student, Guardian, RelationshipType, attributes, actor)`** —
  checks `$student->school_id === $guardian->school_id` *before* any
  database write, throwing `CrossSchoolRelationshipException` (422) if
  not — defense-in-depth ahead of the composite FK's own rejection
  (rule 24: never rely on a constraint alone to catch what the
  application can check first). Deliberately does **not** accept
  `is_primary` (mirrors `GuardianContactService::create()`'s identical
  split) — `setPrimary()` is the only way to promote a relationship.
  Catches the `unique(school_id, student_id, guardian_id)` violation and
  translates it to `DuplicateRelationshipException` (422).
- **`update(StudentGuardianRelationship, attributes, actor)`** —
  `relationship_type`/authority-flag changes only, never `is_primary`.
- **`setPrimary(StudentGuardianRelationship, actor)`** — demotes
  whichever relationship was previously primary for the *same Student*
  in the **same transaction** as the promotion (never demote/COMMIT/
  promote as separate transactions, per the brief) — the database's
  `student_guardian_relationships_one_primary_per_student` partial
  unique index is the actual concurrency guarantee, exactly matching
  `AcademicYearService::activate()`'s and
  `GuardianContactService::setPrimary()`'s established "demote then
  conditionally promote, translate the race into a domain exception"
  pattern. A genuine concurrent race translates to
  `ConcurrentPrimaryGuardianConflictException` (409).
- **`unlink(StudentGuardianRelationship, actor)`** — hard-deletes the
  relationship row. There is no soft-deactivation column on
  `student_guardian_relationships` (unlike `GuardianContact.is_active`)
  — "unlinking" while keeping both identities is the only meaning this
  operation has today. Deleting the relationship row can never delete
  the Student or Guardian, and never touches another Student's
  relationship with a shared Guardian (foreign keys only cascade
  parent-to-child — see the migration's "Delete behavior" docblock).

### Status remains a plain string (not a new enum)

`students.status`/`guardians.status` remain plain validated strings
(`active`/`inactive`), **not** converted to a PHP backed enum in this
checkpoint. This matches the documented repository convention this
module's own schema tables already state: "`status` column... same
deactivate-don't-delete convention as every other reference/identity
table," validated at the application layer, the same as
`GradeLevel`/`AcademicYear`/`Section`/`Subject`'s `status` columns —
`RelationshipType`/`ContactType`'s PHP-enum-cast treatment is
deliberately reserved for closed sets of *domain* values (family
relationship, contact channel), not the generic active/inactive
lifecycle flag every reference table shares. Converting it now would be
scope creep for a checkpoint about authorization/services, not a
lifecycle redesign; `StudentService::changeStatus()`/
`GuardianService::changeStatus()` validate against the two supported
values and throw a domain exception for anything else, so the
*behavior* an enum would give (reject invalid values) already exists
without the schema/cast change.

### Read patterns (no new abstraction)

No read-service/query-object layer was introduced — matching this
codebase's established pattern (`AcademicYearController::index()`
queries `AcademicYear::query()` directly; no repository/query-object
class exists anywhere in this codebase). The relationships already
defined fully support every read pattern a future controller/UI will
need, with no new code:

```php
// Student detail with Guardian relationships
$student->guardianRelationships()->with('guardian')->get();

// Guardian detail with linked Students
$guardian->studentRelationships()->with('student')->get();

// Primary Guardian for a Student
$student->guardianRelationships()->where('is_primary', true)->first();

// Same-School Guardian candidate lookup by contact (Phase 1A.3, unchanged)
app(GuardianContactService::class)->findCandidatesBySchool($school, $type, $rawValue);
```

Every query above must run inside `TenantContext::withSchool()` (or
ambient request-scoped context) for `BelongsToSchool`'s Eloquent scope
to apply, exactly like every other query in this codebase.

### Audit (Phase 1A.4)

New `SchoolAuditEvent` types, via the existing `AuditRecorder` (no new
audit infrastructure): `student.created`, `student.updated`,
`student.status_changed`, `guardian.created`, `guardian.updated`,
`guardian.status_changed`, `student_guardian.linked`,
`student_guardian.updated`, `student_guardian.primary_changed`,
`student_guardian.unlinked`. GuardianContact's Phase 1A.3 events
(`guardian_contact.added`/`.primary_changed`/`.deactivated`) are
unchanged.

**Audit metadata never carries a Student's or Guardian's name or date
of birth** — those are Sensitive/Highly-Sensitive personal data of
(usually) a minor (`docs/security/DATA-CLASSIFICATION.md`: "Children's
data specifically | Highly Sensitive"), and duplicating them into a
second table (the audit log) is unnecessary exposure the brief's own
"never log full email/mobile.../prefer IDs and non-sensitive
relationship/status metadata" principle already argues against by
analogy. `student.updated`/`guardian.updated`/
`student_guardian.updated` record only the **list of changed field
names**, never the values; `*.status_changed`/
`student_guardian.primary_changed` record the (non-PII, enum-like)
status/id values themselves, matching `AcademicYearService::activate()`'s
`previousActiveAcademicYearId` precedent; `student_guardian.linked`
records `studentId`/`guardianId`/`relationshipType`/the boolean
authority flags — all structural, non-PII facts, mirroring
`GuardianContactService`'s "identity-safe by construction" audit design
exactly.

### No domain events (deliberate)

Unlike `AcademicYearService` (which emits `AcademicYearCreated`/
`Activated`/`Closed` via the transactional outbox, ADR 0025), the new
Student/Guardian/relationship services emit **audit records only, no
domain events** — following `GuardianContactService`'s own precedent in
the same Guardians module (Phase 1A.3 added audit-only events for
identical reasons). No consumer for a `student.created`-shaped event
exists anywhere in this codebase yet (no webhook registry entry, no
listener); adding transactional-outbox rows nothing reads yet would be
exactly the "no speculative infrastructure" CLAUDE.md rule 2 warns
against. A future module with a genuine need (e.g. a Communications
module reacting to `student_guardian.linked` to notify a newly-linked
Guardian) is the point to add the event **and** register it in
`WebhookEventRegistry` if it should ever be externally subscribable
(rule 45) — not before.
