# School OS — Student & Guardian Identity (Phase 1A)

Status: `students`, `guardians`, their first-class relationship
(`student_guardian_relationships`, Phase 1A.2), Guardian contact
information with a searchable-encrypted-PII architecture
(`guardian_contacts`, Phase 1A.3), `students.*`/`guardians.*`
authorization capabilities plus the supported Application-layer
mutation services (`StudentService`, `GuardianService`,
`StudentGuardianRelationshipService`, Phase 1A.4), and the
administrative `/api/v1` HTTP surface exposing all of the above to
authenticated School staff (Phase 1A.5), and a session-authenticated
Inertia/Vue administrative UI (Phase 1A.6) exist. No domain events,
addresses, User/persona linking, or verification workflows (OTP/email/
SMS) exist yet — see "Deferred" below. This
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
- **Public/mobile/parent-portal API** — the `/api/v1` surface added in
  Phase 1A.5 is the same *administrative* surface every other School
  OS module uses (Campus, Academic Year, ...), reached by authenticated
  School staff via Sanctum, not a new public contract. A Guardian/
  Student-facing portal API remains unbuilt and explicitly out of
  scope.
- **Domain events** — none exist yet (Phase 1A.4/1A.5 added audit
  events for every Student/Guardian/relationship/contact mutation,
  matching GuardianContact's Phase 1A.3 precedent, but deliberately no
  transactional-outbox domain events — see "Application services"
  below for why).
- **Admissions, academic enrollment, grade/class/section, attendance,
  fees, transport, health, documents, portal, communications,
  government identifiers, AI/automation** — none touched by the
  administrative UI either (Phase 1A.6); the Student form/detail page
  still collects/shows identity fields only.

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

## Administrative HTTP boundary (Phase 1A.5)

### Which HTTP architecture, and why

The existing `/api/v1/schools/{schoolId}/...` surface (`routes/api.php`,
`docs/architecture/API.md`) is **not** a public/mobile-only API — it is
already the *administrative* HTTP surface every other School OS module
uses (Campus, Academic Year, Grade Level, Subject, webhook
management, ...): Sanctum-authenticated, `school-membership`-scoped,
capability-gated. Student/Guardian identity reuses this exact
architecture rather than inventing a second one — a new top-level
`/api/v2` or a bespoke "admin API" would have been the kind of
speculative parallel architecture CLAUDE.md rule 2 rejects. A genuine
public parent/student-portal API remains a distinct, unbuilt surface
(see "Deferred" above) — nothing in this checkpoint exposes Student/
Guardian data outside authenticated School staff.

Every new route lives inside the existing
`Route::middleware(['auth:sanctum', 'school-membership'])->prefix('schools/{school}')`
group in `routes/api.php` — `school-membership`
(`App\Http\Middleware\Api\EnsureSchoolMembershipContext`) re-verifies a
real, active `SchoolMembership` for the route's `{school}` id (never
trusting it merely because it parses as a UUID, rule 19) and sets
`TenantContext` for the whole request before any controller code runs,
exactly like every other nested resource. Controllers never call
`TenantContext::withSchool()` themselves (unlike Application services
or tests) — the ambient request-scoped context set by this middleware
is already active by the time a controller method runs.

### Controller structure and thinness

Four new controllers, one per resource concern, each thin by
construction — matching `CampusController`/`AcademicYearController`/
`RoomController`'s established shape exactly:

- `App\Domain\Students\Http\Controllers\StudentController`
- `App\Domain\Guardians\Http\Controllers\GuardianController`
- `App\Domain\Guardians\Http\Controllers\GuardianContactController`
- `App\Domain\Guardians\Http\Controllers\StudentGuardianRelationshipController`

Every mutation delegates entirely to the Phase 1A.4/1A.3 Application
services (`StudentService`, `GuardianService`,
`StudentGuardianRelationshipService`, `GuardianContactService`) — no
controller computes a lookup hash, encrypts/decrypts a value itself,
writes a relationship row directly, or duplicates the Student Number
uniqueness check. `Student::guardians()->attach()`/`sync()`/
`syncWithoutDetaching()` are never called anywhere in the HTTP layer
(confirmed by a repository-wide grep before commit, and by Phase
1A.3's still-passing regression test proving that path fails closed).

### Validation convention

Inline `$request->validate([...])` per action (the established
convention — no Form Request classes, no API Resource classes, no DTO
framework exist anywhere in this codebase, and none were introduced
here). Every write uses an explicit whitelisted array — never
`Model::create($request->all())`/`$model->update($request->all())` —
so a client cannot inject `id`, `school_id`, `created_at`, or any other
unlisted field regardless of what the request body contains.

`school_id` is **never** read from client input anywhere in this HTTP
layer: it always derives from the route-bound, membership-verified
`School $school` (Student/Guardian create) or from the resolved
parent/child model's own `school_id` (relationship linking derives it
from `$student->school_id`, already tenant-verified by `Student::query()->findOrFail()`'s
own `SchoolScope`).

### Pagination

`GET .../students` and `GET .../guardians` are the first real
implementations of the `page`/`per_page` convention `docs/architecture/API.md`
already documented but no prior endpoint needed (every Phase 0D
catalog — Campus, Academic Year, Subject, ... — is small enough per
School that `->get()` was sufficient; Students/Guardians are not).
Laravel's `paginate()`, `per_page` bounded `[1, 100]` (default `25`,
matching the OpenAPI `PerPage` parameter exactly), response shape
`{ data: [...], meta: { page, perPage, total } }` matching the
`PaginationMeta` OpenAPI schema. `page` is read directly from the
query string by Laravel's paginator (no separate validation needed —
an out-of-range page number returns an empty `data` array, never an
error).

### Tenant-safe resource resolution

Every singular resource (`Student`, `Guardian`, `GuardianContact`,
`StudentGuardianRelationship`) is resolved via
`Model::query()->findOrFail($id)` — never implicit route-model
binding (matching every existing controller's established reason: the
`school-membership` middleware must have already run and set
`TenantContext` before `BelongsToSchool`'s `SchoolScope` can safely
apply; implicit binding resolves too early in the middleware pipeline
for that to be guaranteed). Because `SchoolScope` is a global Eloquent
scope, a foreign-School id is invisible to the query — `findOrFail()`
throws `ModelNotFoundException`, which Laravel renders as a plain `404`
indistinguishable from a genuinely nonexistent id. **No controller
anywhere in this checkpoint returns a message like "this record belongs
to another School"** — that would leak cross-tenant existence (rule
25). `StudentGuardianRelationshipApiTest::a_foreign_school_student_is_inaccessible_for_linking`
and the equivalent Student/Guardian/contact tests prove a foreign-School
id and a genuinely random UUID produce byte-identical `404` responses.

A relationship's `guardian_id` request-body field (not a route
parameter) uses Laravel's own `Rule::exists('guardians', 'id')->where('school_id', $school->id)`
validation rule instead — a foreign-School or nonexistent id both fail
with the identical generic "the selected guardian id is invalid" `422`
validation message, the same non-disclosure property applied to a body
field instead of a path segment.

Nesting follows `RoomController`/`AcademicTermController`'s exact
established split: index/store nest under the owning parent
(`GET/POST .../guardians/{guardian}/contacts`, `GET/POST
.../students/{student}/guardians`) because creation genuinely requires
knowing the parent; singular show/update/setPrimary/destroy-shaped
actions resolve by their own id via a flat, top-level path
(`.../guardian-contacts/{contact}/...`,
`.../student-guardian-relationships/{relationship}`), never
re-nested under the parent that created them.

### Route inventory

| Method | Path | Capability | Idempotent |
|---|---|---|---|
| GET | `/schools/{school}/students` | `students.view` | |
| POST | `/schools/{school}/students` | `students.manage` | ✓ |
| GET | `/schools/{school}/students/{student}` | `students.view` | |
| PATCH | `/schools/{school}/students/{student}` | `students.manage` | |
| POST | `/schools/{school}/students/{student}/status` | `students.manage` | ✓ |
| GET | `/schools/{school}/students/{student}/guardians` | `students.view` | |
| POST | `/schools/{school}/students/{student}/guardians` | `students.manage` **+** `guardians.manage` | ✓ |
| PATCH | `/schools/{school}/student-guardian-relationships/{relationship}` | `students.manage` **+** `guardians.manage` | |
| POST | `/schools/{school}/student-guardian-relationships/{relationship}/primary` | `students.manage` **+** `guardians.manage` | ✓ |
| DELETE | `/schools/{school}/student-guardian-relationships/{relationship}` | `students.manage` **+** `guardians.manage` | |
| GET | `/schools/{school}/guardians` | `guardians.view` | |
| POST | `/schools/{school}/guardians` | `guardians.manage` | ✓ |
| POST | `/schools/{school}/guardian-candidates` | `guardians.view` | |
| GET | `/schools/{school}/guardians/{guardian}` | `guardians.view` | |
| PATCH | `/schools/{school}/guardians/{guardian}` | `guardians.manage` | |
| POST | `/schools/{school}/guardians/{guardian}/status` | `guardians.manage` | ✓ |
| POST | `/schools/{school}/guardians/{guardian}/contacts` | `guardians.manage` | ✓ |
| POST | `/schools/{school}/guardian-contacts/{contact}/primary` | `guardians.manage` | ✓ |
| POST | `/schools/{school}/guardian-contacts/{contact}/deactivate` | `guardians.manage` | ✓ |

`idempotent` follows the established precedent exactly: every
create/state-transition POST gets it (matching `academic-years.store`/
`.activate`/`.close`, `campuses.store`); PATCH updates never do
(matching `campuses.update`); DELETE never does (an already-deleted
resource 404s harmlessly on retry — the same natural idempotency
`WebhookSubscriptionController::destroy()` relies on).

Every GET action authorizes via `$this->authorizeCapability(...)`
called as the controller method's first line (matching
`AcademicYearController::index()`/`show()`); every mutation action
authorizes via **both** the `capability:` route middleware above
**and** the identical `$this->authorizeCapability(...)` call inside the
controller — deliberately redundant defense-in-depth, the same
double-check `CampusController::store()`/`update()` already perform.
The dual-capability relationship-mutation routes stack two separate
`capability:` middleware entries (`'capability:students.manage',
'capability:guardians.manage'`) — no new middleware was written;
`EnsureCapability` already supports being applied more than once per
route with different arguments.

### Student HTTP surface

**List** (`GET .../students`) — filters: `student_number` (exact
match), `name` (`ILIKE '%term%'` against `first_name`/`last_name` —
deliberately simple; no `pg_trgm`/GIN index was added, since no
existing search implementation in this codebase established that
pattern yet and adding one would be new infrastructure for a need this
checkpoint doesn't yet have evidence of — documented here as a known
future scaling concern, not a gap), `status` (`active`/`inactive`),
`per_page`. Response rows: `id`, `studentNumber`, `firstName`,
`middleName`, `lastName`, `status` — **deliberately excludes
`dateOfBirth`** (a summary/list view of Highly Sensitive children's
data, `docs/security/DATA-CLASSIFICATION.md`: "minimized default
visibility... not included in broad list/summary views by default").

**Show** (`GET .../students/{student}`) — full detail: adds
`dateOfBirth`, `createdAt`, `updatedAt` to the list row's fields — a
single-record view is exactly the case DATA-CLASSIFICATION.md
describes as appropriate for a Highly Sensitive field, and it is
already gated by the same `students.view` capability check.

**Create** (`POST .../students`) — `student_number`, `first_name`,
`middle_name` (nullable), `last_name` (nullable), `date_of_birth`
required/validated at the HTTP layer for shape only; the *duplicate
Student Number* domain check is `StudentService`'s alone (no
`Rule::unique()` duplicated here — the brief explicitly forbids
duplicating this logic in the controller). Returns the full detail
shape, `201`.

**Update** (`PATCH .../students/{student}`) — same fields, all
`sometimes`; never `id`/`school_id`/`created_at`/`status` (status has
its own endpoint).

**Status** (`POST .../students/{student}/status`) — `status` validated
`in:active,inactive` at the HTTP layer (a clean `422` for anything
else, before `StudentService::changeStatus()`'s own
`InvalidStudentStatusException` would even be reached — both layers
validate the same invariant, matching `CampusController`'s established
`status` field pattern).

### Guardian HTTP surface

**List** (`GET .../guardians`) — filters: `name` (ILIKE, same caveat as
Student), `status`, `per_page`. Rows: `id`, `firstName`, `middleName`,
`lastName`, `status` — no contacts, no relationships (a list view
should not eagerly expose every Guardian's contact information).

**Show** (`GET .../guardians/{guardian}`) — full detail: identity
fields **plus** `contacts` (every contact, see representation below)
**plus** `students` (one entry per linked `StudentGuardianRelationship`:
`relationshipId`, a minimal `student` summary, `relationshipType`, and
the four boolean authority flags).

**Create** (`POST .../guardians`) — `first_name`, `middle_name`
(nullable), `last_name` (nullable). Guardian creation and contact
creation remain two separate endpoints/calls (this checkpoint's brief:
"prefer understandable domain operations over a giant ERP payload") —
a future UI performs create-Guardian, then add-contact, then
link-to-Student as three explicit calls, not one nested mega-payload.

**Update** (`PATCH .../guardians/{guardian}`) — same fields,
`sometimes`.

**Status** (`POST .../guardians/{guardian}/status`) — identical shape
to Student's.

**Contact display** — approved fields only, explicitly listed (never
`GuardianContact::toArray()`): `id`, `type`, `value` (the *decrypted*
plaintext — reading `$contact->encrypted_value` directly returns the
decrypted value in-memory via Laravel's `encrypted` Eloquent cast; the
model's own `$hidden` only suppresses `toArray()`/`toJson()`, so
building the response array explicitly, field by field, is what
actually keeps ciphertext out — this checkpoint never calls
`toArray()`/`toJson()` on a `GuardianContact` anywhere), `label`,
`isPrimary`, `isActive`, `verifiedAt`. `encrypted_value`, `lookup_hash`,
and `lookup_key_version` are referenced nowhere in the HTTP layer.

**Contact mutation** (`POST .../guardians/{guardian}/contacts`,
`.../guardian-contacts/{contact}/primary`,
`.../guardian-contacts/{contact}/deactivate`) — thin wrappers over
`GuardianContactService`'s unchanged `create()`/`setPrimary()`/
`deactivate()` (Phase 1A.3, never rewritten). The controller's only
cryptography-adjacent responsibility is translating the two failure
modes that service can raise into the established validation response
shape rather than a raw `500`: `InvalidArgumentException` (malformed
email/phone from `EmailNormalizer`/`PhoneNormalizer`) and
`Illuminate\Database\UniqueConstraintViolationException` (duplicate
contact, or an existing active primary of the same type) both become
`Illuminate\Validation\ValidationException::withMessages([...])` — no
new exception classes were added, and `GuardianContactService` itself
is untouched. No verification workflow exists — `verified_at` remains
metadata only, exactly as Phase 1A.3 left it. Phone input is **never**
silently converted (`9876543210` is rejected, not rewritten to
`+919876543210`) — `PhoneNormalizer`'s E.164-only contract is
unchanged; a future UI may offer country-aware formatting help, this
backend boundary does not guess.

**Candidate lookup** (`POST .../guardian-candidates`) — `type`
(`email`/`mobile`, validated via `Rule::enum(ContactType::class)`),
`value`. Calls `GuardianContactService::findCandidatesBySchool()`
unchanged: same-School only, exact HMAC-keyed match, never decrypts
every contact row to search, may legitimately return multiple
Guardians (household sharing), never auto-merges. Response is a bare
array of Guardian identity summaries (`id`/`firstName`/`middleName`/
`lastName`/`status`) — no contact values, no `lookup_hash`, no
relationship data; just enough for staff to recognize and pick an
existing record.

### Relationship HTTP surface

`App\Domain\Guardians\Http\Controllers\StudentGuardianRelationshipController`
is a thin wrapper over `StudentGuardianRelationshipService` (Phase
1A.4) — the only sanctioned mutation path, unchanged.

- **List** (`GET .../students/{student}/guardians`) — every
  relationship for one Student; requires only `students.view` (reading
  a Student's own relationships is part of reading that Student, not a
  Guardian-management concern).
- **Link** (`POST .../students/{student}/guardians`) —
  `guardian_id` (`Rule::exists` scoped to the current School, see
  above), `relationship_type` (`Rule::enum(RelationshipType::class)`),
  the three non-primary authority flags. Deliberately does **not**
  accept `is_primary` — mirrors `StudentGuardianRelationshipService::link()`'s
  own split (`setPrimary()` is the only path to promotion). The
  database's `unique(school_id, student_id, guardian_id)` violation
  (translated by the service into `DuplicateRelationshipException`)
  renders automatically as a clean `422` with code
  `DUPLICATE_STUDENT_GUARDIAN_RELATIONSHIP` — no controller-level catch
  needed, since that exception already defines `getStatusCode()`/
  `errorCode()`.
- **Update** (`PATCH .../student-guardian-relationships/{relationship}`)
  — `relationship_type`, the three flags; never `is_primary`.
- **Set primary** (`POST .../student-guardian-relationships/{relationship}/primary`)
  — thin wrapper over `setPrimary()`'s atomic demote-then-promote.
- **Unlink** (`DELETE .../student-guardian-relationships/{relationship}`)
  — thin wrapper over `unlink()`'s hard delete; returns `204` with no
  body (matching `WebhookSubscriptionController::destroy()`'s exact
  precedent).

Every mutation action requires **both** `students.manage` and
`guardians.manage` (Phase 1A.4's accepted design) — proven by
`StudentGuardianRelationshipApiTest::students_manage_alone_is_denied`/
`guardians_manage_alone_is_denied`, which construct a role holding
exactly one of the two capabilities and confirm the request is still
`403`.

### Error behavior

No new error envelope — every domain exception already defined by
Phase 1A.3/1A.4 (`DuplicateStudentNumberException`,
`InvalidStudentStatusException`, `InvalidGuardianStatusException`,
`DuplicateRelationshipException`, `CrossSchoolRelationshipException`,
`ConcurrentPrimaryGuardianConflictException`) defines `getStatusCode()`/
`errorCode()` and is rendered automatically by `bootstrap/app.php`'s
existing generic exception handler — this checkpoint added **zero**
new `render()` logic. `ContactLookupKeyNotConfiguredException`
(missing `CONTACT_LOOKUP_HMAC_KEY`) deliberately has **no**
`getStatusCode()`/`errorCode()` and therefore falls through to a plain
`500` — correct, since a missing server secret is an operator
misconfiguration, not a client-correctable input error. `RelationshipType`/
`ContactType`/Student-and-Guardian-`status` invalid values are caught
by `Rule::enum(...)`/`Rule::in(...)` at the HTTP layer before ever
reaching a domain exception, producing a standard `422` validation
response with per-field `errors`. No raw `QueryException`/SQLSTATE/
constraint name is ever returned to a client — proven directly by
`StudentApiTest::a_duplicate_student_number_is_mapped_to_a_clean_422`
asserting the response message never contains `SQLSTATE` or the
constraint's name.

### Sensitive response exclusions (summary)

Never present anywhere in an HTTP response, for any capability, in
this checkpoint: `encrypted_value`, `lookup_hash`, `lookup_key_version`,
a raw candidate-lookup digest, `school_id` on Student/Guardian/contact/
relationship responses (the caller already supplied it in the URL —
echoing it back adds nothing), any RLS/`TenantContext` internal state,
audit-log internals. `dateOfBirth` is present only in the single-record
Student `show`/`create`/`update` responses, never in the list.

### Audit / RLS regression

No new audit infrastructure and no schema/migration changes in this
checkpoint — every mutation's audit event is the one Phase 1A.4/1A.3
already defined (`StudentService`/`GuardianService`/
`StudentGuardianRelationshipService`/`GuardianContactService` audit
internally; **no controller calls `AuditRecorder` directly**, avoiding
a duplicate audit entry per request). The existing raw-PostgreSQL RLS
suites (`StudentGuardianRlsIsolationTest`,
`StudentGuardianRelationshipIntegrityTest`,
`GuardianContactIntegrityTest`) were re-run unmodified and remain
green — this checkpoint proves the HTTP layer adds no privileged
database connection or tenancy bypass on top of the RLS foundation
Phase 1A/1A.2/1A.3 already established; RLS remains the authoritative
defense-in-depth layer beneath every capability check above it.

## Administrative UI (Phase 1A.6)

### Architecture: a second, session-authenticated controller layer

The Vue/Inertia UI does **not** call the `/api/v1` JSON surface Phase
1A.5 built. That surface is deliberately the Bearer-token,
Flutter/external-consumer API (`docs/architecture/API.md`); the
established, already-documented convention for the Inertia web
console is a **separate** session-authenticated controller layer under
`App\Http\Controllers\App\*`, calling the exact same Application
services — `SchoolSetupController` already does this for Campuses/
Academic Years/Grade Levels/Subjects, explicitly documenting itself as
"NOT the Bearer-token JSON API under /api/v1." Three new controllers
follow that identical shape:

- `App\Http\Controllers\App\StudentController`
- `App\Http\Controllers\App\GuardianController`
- `App\Http\Controllers\App\StudentGuardianRelationshipController`

Every mutation delegates to `StudentService`/`GuardianService`/
`StudentGuardianRelationshipService`/`GuardianContactService` (Phase
1A.3/1A.4) — the same services the JSON API controllers call — never
duplicated. Both controller layers are thin wrappers around one
authoritative service layer; neither is a "real" backend the other
proxies. Tenant context comes from `TenantContext::requireSchool()`
(session-resolved, `App\Http\Middleware\ResolveSchoolContext`), not a
`{school}` route parameter — matching `SchoolSetupController` exactly,
not the API layer's `{schoolId}` path segment.

One real integration difference required explicit handling: the JSON
API's domain exceptions (`DuplicateStudentNumberException`, ...)
render automatically via their own `getStatusCode()`/`errorCode()`
through `bootstrap/app.php`'s `/api/*`-only exception envelope, but
that envelope explicitly does not apply to `/app/*` routes. Inertia's
client-side form-error handling (`form.errors`) only recognizes
Laravel's own `ValidationException`. `StudentController::store()`/
`update()` and `StudentGuardianRelationshipController::linkExisting()`
therefore catch the specific domain exceptions a user can plausibly
trigger (`DuplicateStudentNumberException`, `DuplicateRelationshipException`,
`CrossSchoolRelationshipException`, `ConcurrentPrimaryGuardianConflictException`)
and translate them to `ValidationException::withMessages(...)` —
without touching the underlying service. `GuardianController::storeContact()`
does the identical translation for `GuardianContactService`'s two raw
failure modes (`InvalidArgumentException`, `UniqueConstraintViolationException`),
exactly mirroring the JSON API's `GuardianContactController`.

### Routes

Under the existing `Route::middleware('auth')` group in `routes/web.php`
(session cookies, not Bearer tokens):

| Method | Path | Purpose |
|---|---|---|
| GET | `/app/students` | List/search |
| GET/POST | `/app/students/create`, `/app/students` | Create |
| GET | `/app/students/{student}` | Detail (identity + Guardians) |
| GET/PUT | `/app/students/{student}/edit`, `/app/students/{student}` | Edit |
| POST | `/app/students/{student}/status` | Status change |
| GET | `/app/students/{student}/guardians/add` | "Add guardian" page |
| GET | `/app/students/{student}/guardians/search` | JSON name search |
| GET | `/app/students/{student}/guardians/candidates` | JSON exact-contact lookup |
| POST | `/app/students/{student}/guardians/link` | Link an existing Guardian |
| POST | `/app/students/{student}/guardians` | Create a new Guardian and link |
| PUT/DELETE | `/app/relationships/{relationship}` | Edit / unlink |
| POST | `/app/relationships/{relationship}/primary` | Set primary Guardian |
| GET | `/app/guardians` | List/search |
| GET/POST | `/app/guardians/create`, `/app/guardians` | Create |
| GET | `/app/guardians/{guardian}` | Detail (contacts + linked Students) |
| GET/PUT | `/app/guardians/{guardian}/edit`, `/app/guardians/{guardian}` | Edit |
| POST | `/app/guardians/{guardian}/status` | Status change |
| POST | `/app/guardians/{guardian}/contacts` | Add contact |
| POST | `/app/contacts/{contact}/primary`, `/deactivate` | Contact mutation |

`students.view`/`guardians.view` gate every GET; `students.manage`/
`guardians.manage` gate every mutation; relationship link/update/
setPrimary/unlink/the two search endpoints require **both**
`students.manage` and `guardians.manage` (Phase 1A.4's accepted
design — see `authorizeBoth()` in
`StudentGuardianRelationshipController`). Every action calls
`$this->authorizeCapability(...)` explicitly — permission-aware
rendering (below) is UX only, never the actual enforcement.

### Student UI

- **Index** (`Students/Index.vue`) — server-driven filters
  (`student_number` exact, `name` ILIKE, `status`), paginated (20/page,
  Laravel's standard paginator shape), debounced (300ms) text filters,
  immediate status-select filter, responsive table→card collapse at
  `md`. Rows deliberately **exclude `dateOfBirth`** (Highly Sensitive
  children's data, `docs/security/DATA-CLASSIFICATION.md`) — a
  **chosen option A** per this checkpoint's brief §7/§54: Guardian/
  contact summary columns were considered and deliberately **not**
  added (see "Backend changes" below) rather than silently expanding
  the list endpoint.
- **Empty state** — distinguishes "no Students exist" (with an "Add
  student" CTA, `students.manage` only) from "no Students match this
  search" (no CTA, since creating one wouldn't address a search
  filter).
- **Create/Edit** (`Students/Create.vue`/`Edit.vue`) — a single shared
  `Components/StudentIdentityFields.vue` field group (`defineModel`
  per field, not a mutated prop — see "Accessibility" below), the five
  supported identity fields only. `date_of_birth` uses a native
  `<input type="date">` bound directly to a plain `'YYYY-MM-DD'`
  string end to end (form field → POST body → `Student::date_of_birth`
  cast → `toDateString()` on display) — no `Date` object is ever
  constructed from it anywhere in this checkpoint's Vue code, so no
  JS-timezone shift is possible (this checkpoint's brief, §38).
  Proven by `StudentAdminUiTest::date_of_birth_round_trips_exactly_with_no_timezone_shift`.
- **Detail** (`Students/Show.vue`) — identity description list, a
  status-toggle button, and the Guardians section (below).
- **Status** — a single toggle button posting the current inverse
  value; the two-value enum is hard-coded in Vue (`active`/`inactive`)
  matching the two values `StudentService::changeStatus()` actually
  supports — no metadata endpoint was added solely to serve this list
  (this checkpoint's brief §39: "do not create an API endpoint solely
  for status metadata unless justified" — two fixed values isn't).

### Guardian UI

- **Index** (`Guardians/Index.vue`) — name/status filters, paginated.
  Shows a **linked-student count** (`withCount('studentRelationships')`
  — one extra aggregate query for the whole page, not one query per
  row, proven directly by `GuardianAdminUiTest::index_reports_linked_student_count_without_n_plus_one`)
  but deliberately **omits a "primary contact" column** — decrypting
  and displaying every Guardian's phone/email in a broad list view
  would be the same over-exposure this checkpoint already avoided for
  Student DOB; contact values are shown only on the Guardian detail
  page (a single-record view).
- **Create/Edit** — `Components/GuardianIdentityFields.vue`, name
  fields only. A Guardian is deliberately creatable with **no**
  contact information (the domain decision that Guardian ≠ login/
  contact record, unchanged since Phase 1A.3) — "Add contact" is a
  separate, optional follow-up action from the Guardian's own page.
- **Detail** (`Guardians/Show.vue`) — identity, a Contact information
  section (add/set-primary/deactivate, expanded inline on demand, not
  a separate page), and a Students section listing every linked
  Student with `RelationshipFlags`.
- **Contact display** — the exact same five approved fields the JSON
  API exposes (`id`/`type`/`value`/`label`/`isPrimary`/`isActive`/
  `verifiedAt`); `value` is the *decrypted* plaintext (`encrypted`
  Eloquent cast decrypts on direct property access, unaffected by the
  model's own `$hidden`) — `encrypted_value`/`lookup_hash`/
  `lookup_key_version` are referenced nowhere in
  `GuardianController`/any Vue file (grep-confirmed before commit).
- **Add contact** — type select (Email/Mobile), a human-readable mobile
  hint ("Include country code, e.g. +91 9876543210") without assuming
  every School is in India, and never silently rewrites `9876543210`
  to `+919876543210` — `PhoneNormalizer`'s E.164-only contract
  (Phase 1A.3) is unchanged; the frontend only *explains* the
  requirement, never guesses a country to satisfy it.

### Relationship UX ("Add guardian")

A dedicated page (`Students/AddGuardian.vue`), not a modal, with two
tabs:

- **Find existing guardian** — a debounced (300ms) same-School name
  search (`GET .../guardians/search`, a plain JSON endpoint, not an
  Inertia page) and a separate, explicit-button (not debounced) exact
  contact lookup (`GET .../guardians/candidates`, thin wrapper over
  the unchanged `GuardianContactService::findCandidatesBySchool()`).
  A contact match shows the neutral copy **"Existing guardians use
  this contact information"** — never "duplicate guardian found" (this
  checkpoint's brief §26: shared household contacts are legitimate,
  never flagged as an error) — followed by candidate identities for
  staff to review; no automatic selection, no merge. Selecting a
  candidate reveals the relationship form
  (`RelationshipType` + the three authority flags from
  `resources/js/relationshipTypes.ts`, the single source for this enum's
  display labels), submitting to `POST .../guardians/link`.
- **Create new guardian** — identity fields + the same relationship
  form, submitting to `POST .../guardians` (`StudentGuardianRelationshipController::linkNew()`),
  which orchestrates `GuardianService::create()` then
  `StudentGuardianRelationshipService::link()` — two already-atomic
  service calls in sequence, not duplicated logic. Deliberately never
  collects contact information on this form (this checkpoint's brief:
  "do not implement one gigantic nested Student+Guardian+Contact
  payload") — contact remains a separate follow-up from the Guardian's
  own page.

On the Student detail page, each linked Guardian shows via
`Components/RelationshipFlags.vue`: the relationship type plus only
the *true* authority flags, joined compactly ("Mother · Primary ·
Legal guardian"), never a noisy list of every boolean. "Make primary"
posts to the dedicated `.../primary` action (`StudentGuardianRelationshipService::setPrimary()`'s
atomic demote-then-promote — never a frontend-only assumption).
"Remove from student" requires confirmation with explicit wording that
only the relationship is removed:

> Remove this Guardian from {name}? This removes their relationship
> with this student. The Guardian record and links to other students
> will remain.

— never "Delete guardian" (this checkpoint's brief §20). Implemented
via `window.confirm()` (no dialog/modal component exists anywhere in
this codebase yet; introducing one for a single confirmation would be
the kind of premature abstraction this checkpoint's brief §35 warns
against).

### Authorization UX

`canManage`(`Students`/`Guardians`)/`canManageStudents`/
`canManageGuardians` props, computed via `CapabilityResolver` in each
controller (never inferred from a role name), gate every mutation
control in Vue — matching `SchoolSetupController`'s established
`canManage` prop pattern exactly. Relationship mutation controls
(Add guardian, Make primary, Edit, Remove) render only when **both**
`canManageStudents` **and** `canManageGuardians` are true. This is
UX only: every corresponding action independently re-checks
authorization server-side regardless of what the page received,
proven directly — `StudentAdminUiTest::the_add_student_action_is_not_rendered_for_a_view_only_member`
hides the button *and* calls the create page/store action directly,
asserting `403` both times (this checkpoint's brief §29: never rely on
a hidden button as the only protection). The Dashboard's `nav` prop
gained `canViewStudents`/`canViewGuardians`, following the exact
pattern its `canViewSchoolSettings` already established.

### Duplicate-candidate UX

See "Relationship UX" above for the exact wording/behavior. Summary:
exact-match only (never fuzzy), same-School only, may legitimately
return multiple Guardians, never auto-selected, never auto-merged,
never described as a "duplicate."

### Privacy

`encrypted_value`/`lookup_hash`/`lookup_key_version` are referenced
nowhere under `resources/js/` (grep-confirmed before commit — see
"Security review" in the Phase 1A.6 checkpoint report).
`localStorage`/`sessionStorage` are not used anywhere in this
checkpoint's Vue code — contact search terms (`nameQuery`,
`contactValue`) live only in-memory `ref()`s for the lifetime of the
`AddGuardian` page component and are never persisted.

### Accessibility and responsive design

Every input has a real `<label for>`; date-of-birth/student-number
error text is `aria-describedby`-linked; invalid fields set
`aria-invalid`; `StatusBadge` pairs a colored dot (`aria-hidden`) with
a text label so status is never color-only; tables use
`<th scope="col">`/a `sr-only` "Actions" header; `Pagination.vue` sets
`aria-current="page"`; all interactive elements are real `<button>`/
`<a>` (no clickable `<div>`s anywhere in this checkpoint). No custom
modal/dialog exists (see "Remove from student" above), so there is no
bespoke focus-trap to get wrong. Student/Guardian list and detail
pages collapse from a table to a card list below Tailwind's `md`
breakpoint; forms use responsive grid columns (`sm:grid-cols-3`) that
stack to one column on narrow viewports.

### Frontend field-group components introduced

`Components/{StatusBadge,EmptyState,Pagination,RelationshipFlags,
StudentIdentityFields,GuardianIdentityFields}.vue` — six small,
genuinely-reused components (each used by ≥2 pages); no shared page
layout/shell/component library was introduced, since none exists
elsewhere in this codebase yet (`SchoolSetup/*.vue` pages are each
still self-contained `<main>` blocks) — matching this checkpoint's
brief §35 ("do not create an abstract component library during this
checkpoint").

### Backend changes made for this checkpoint

Two small, backward-compatible read-side additions, both new code
inside this checkpoint's own new controllers (not edits to the Phase
1A.5 JSON API):

- `StudentController@show` (web) eager-loads
  `guardianRelationships.guardian.contacts` and includes each Guardian
  relationship's primary/first-active contact in its response — the
  JSON API's `StudentController@show` (Phase 1A.5) does not expose
  Guardian relationships at all today. This is additive to the web
  controller only.
- `GuardianController@index` (web) adds
  `withCount('studentRelationships')` for the "linked students" list
  column.

Both are new code within Phase 1A.6's own controllers, not
modifications to Phase 1A.5's accepted JSON API contract or its
services — no existing test was changed, and new tests cover both
(`StudentAdminUiTest::show_renders_identity_and_guardian_relationships`,
`GuardianAdminUiTest::index_reports_linked_student_count_without_n_plus_one`).
Capability enforcement, tenant safety, and PII rules are unchanged by
either.

### Deferred by this checkpoint

Vue equivalents of everything already deferred in "Deferred (not yet
implemented)" above (StudentIdentifier, Address, portal login,
verification workflows, key rotation, domain events, Admissions/
academics/attendance/fees/etc.) — none render any UI for those
concerns, matching the backend's own scope exactly.
