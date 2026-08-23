# School OS — Student & Guardian Identity (Phase 1A)

Status: `students`, `guardians`, their first-class relationship
(`student_guardian_relationships`, Phase 1A.2), and Guardian contact
information with a searchable-encrypted-PII architecture
(`guardian_contacts`, Phase 1A.3) exist. No API, UI, capabilities,
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
plus `App\Domain\Guardians\Application\GuardianContactService`
— two separate modules (matching `docs/architecture/DOMAIN-MAP.md`'s
Layer 2 split of Students/SIS and Guardians into distinct rows), each
following the `Domain/Application/Infrastructure/Http` convention
(`apps/platform/app/Domain/README.md`). Guardians now has a populated
`Application` layer (Phase 1A.3's `GuardianContactService` — the first
Phase 1A checkpoint that needed one, since normalization/encryption/
hashing genuinely must not be duplicated across future call sites);
Students still has only `Infrastructure` — no `Http` layer exists yet
in either module, since no API/controller has been built. Generic
reusable pieces (`EmailNormalizer`, `PhoneNormalizer`,
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
- **API, UI, capabilities (`students.*`/`guardians.*`), domain events**
  — none exist yet (Phase 1A.3 added minimal audit events for
  GuardianContact mutations only, per its brief — no domain events).
  The established capability-naming convention in this codebase is a
  `.view`/`.manage` pair per resource (e.g. `academics.years.view`/
  `.manage`), not the four-verb `.view`/`.create`/`.update`/`.archive`
  set sometimes used as illustrative examples — a future checkpoint
  adding `students.view`/`students.manage` and
  `guardians.view`/`guardians.manage` should follow the two-verb
  pattern already established, not introduce a new one.
