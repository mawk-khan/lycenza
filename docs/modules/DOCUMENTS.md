# Documents Module

Completes `docs/roadmap/MASTER-ROADMAP.md`'s **Phase 0E** (the
Communications half of 0E is already implemented — Phase 5A/5B,
`docs/communication-hub/`). This document is the Documents module's
as-built companion to `docs/architecture/adr/0012-file-document-storage-architecture.md`
("ADR 0012"), which already fixed the module's architectural shape
before any implementation existed. Read ADR 0012 first — this document
does not repeat its rationale, only records how Phase 0E.1
operationalized it.

## Checkpoint roadmap

```
0E.1  Documents Domain Foundation   (documents table, Document model — this checkpoint) [implemented]
```

Later checkpoints (write service, download authorization, HTTP/API,
retention policy, `employee_documents` reconciliation, ...) are not
numbered yet — see "Deferred / out of scope" below. They will be
sequenced here as they're planned, the same way `docs/modules/HR.md`'s
own checkpoint roadmap grew one entry at a time.

## What already existed before this checkpoint

Discovered by inspection before any code was written (current main
`f9ca925d5e3cf7c27cda47fc96e999e76193958d`), matching this repository's
established "read existing platform patterns first" discipline:

- **ADR 0012** (Accepted, unimplemented) — fixes the Document-as-
  first-class-record shape, the "no module touches object storage
  directly" rule, and the per-request download-authorization
  requirement.
- **ADR 0011** (object storage strategy) — S3-compatible object
  storage (MinIO in local dev, already running as `school-os-minio-1`)
  is the fixed storage *technology*; this checkpoint does not touch it
  — no file bytes are written or read anywhere in Phase 0E.1.
- **`App\Support\Tenancy\TenantStoragePath::for(School, string $path)`**
  — laid down ahead of need specifically for this module (its own
  docblock: "Does NOT implement the Documents module (ADR 0012) --
  only the safe path-building rule"). By the time this checkpoint
  landed it already had two real production callers from OTHER
  domains — `App\Domain\HR\Application\EmployeeDocumentService` and
  `App\Domain\Communications\Application\CommunicationAttachmentService`
  — so "zero callers" no longer describes the helper itself, only the
  Documents module's own (non-)use of it. Phase 0E.1 ships no write
  service, so it has no production caller of its own yet; this
  checkpoint's only use of `TenantStoragePath::for()` is in a test,
  proving the integration will work correctly once a real write path
  exists (`DocumentSchemaTest::storage_path_is_always_tenant_prefixed_via_tenant_storage_path`).
  Physical object storage (MinIO) is not touched anywhere in this
  checkpoint — `TenantStoragePath` only constructs a path *string*, it
  performs no I/O itself.
- **`docs/security/DATA-CLASSIFICATION.md`**'s four canonical
  classification tiers (`Public`/`Internal`/`Sensitive`/`Highly
  Sensitive`) and its explicit instruction for this module: "the
  Documents module (ADR 0012) must tag each stored document with its
  actual classification, not a blanket default."
- **`employee_documents`** (Phase 8A.7, published, closed) — HR's own
  narrow, HR-scoped metadata table, built as an explicitly accepted
  workaround for this module not existing yet (ADR 0028, HR.md
  "Documents — narrow scope, not a parallel system"). HR.md's own text
  already promised: "when Phase 0E's real Documents module is
  eventually built, it will need its own reconciliation/migration step
  ... That is deliberately not decided now." **This checkpoint does
  not perform that reconciliation** — see "Deferred / out of scope."
  Phase 8A is published and closed; this checkpoint does not modify
  any HR file.

## Domain contract

### Entity

**Document** — a tenant-owned metadata record describing a stored
file. `documents` is the only table this checkpoint adds.

- `id` — UUIDv7 primary key (`GeneratesUuidV7`, ADR 0019).
- `school_id` — the tenant, RLS-enforced (`TenantRls::enable`,
  `BelongsToSchool`).
- **Owning entity** — exactly one of `employee_id`/`student_id`/
  `guardian_id` is set (see "Owning-entity boundary" below).
- `classification_tier` — `public`/`internal`/`sensitive`/
  `highly_sensitive` (DATA-CLASSIFICATION.md's canonical vocabulary),
  database-CHECK-constrained, no default.
- `storage_disk`/`storage_path` — WHERE a file lives; metadata only,
  never file bytes. `storage_path` must always be built via
  `TenantStoragePath::for()` by any future writer (not enforced by
  this migration — this checkpoint ships no writer; the next
  checkpoint that adds one is responsible for calling it correctly).
- `original_filename`/`mime_type`/`size_bytes` — descriptive metadata
  only, never used to derive `storage_path` (rule 23 precedent).
- `uploaded_by_user_id` (nullable, `nullOnDelete`) / `uploaded_at`.
- `status` — `active`/`archived`, database-CHECK-constrained, default
  `active`. Never hard-deleted once created (rule 73's reference-
  entity-lifecycle pattern) — there is no delete path in this
  checkpoint at all, only the schema's own archival shape.

### Owning-entity boundary — the dangerous conflation this checkpoint had to resolve

ADR 0012's own example phrasing describes the owner reference as
"polymorphic: student, guardian, employee, invoice, ...". Taken
literally (a single `owner_type` + `owner_id` column, Laravel's
standard `morphTo`), this cannot carry a real foreign key against more
than one parent table — Postgres foreign keys always target exactly
one table. That would mean `documents` could reference a deleted or
cross-School entity with no structural guarantee at all, purely
relying on application discipline — directly contradicting CLAUDE.md
rule 70 ("Cross-School parent/child relationships require a structural
database constraint, not just an application check"), which every
other tenant-owned child table in this repository already follows
(`Section→AcademicYear`, `EmployeeAssignment→EmploymentRecord`, ...).

**Resolved as an "exclusive arc":** three separate nullable columns
(`employee_id`, `student_id`, `guardian_id`), each with its own
composite foreign key to `(id, school_id)` on its owning table (the
exact established pattern), plus a database CHECK constraint requiring
exactly one to be set. A Document with zero or multiple owners is
rejected at the database level, not just by application code —
verified by `DocumentSchemaTest`'s zero/two/three-owner tests.

This is the Documents-module analogue of HR's "User ≠ Employee"/
"Position ≠ authorization" conflations this repository's established
review discipline requires calling out explicitly for every new
domain.

**`invoice_id` (ADR 0012's fourth named example) is intentionally
absent.** No `invoices` table exists anywhere in this repository yet
(Finance/Phase 0G is unstarted) — a column that cannot reference
anything real would be exactly the speculative-field pattern CLAUDE.md
rule 2 forbids. Adding a fourth (or fifth, ...) owner arm is a small,
additive, forward-compatible migration whenever a real owner table
exists; it is never a reason to guess ahead now.

### Classification — inherited from content, never a blanket default

Per DATA-CLASSIFICATION.md's own "Documents/files" row ("a birth
certificate scan is Highly Sensitive; a public event photo may be
Public"), `classification_tier` has **no default value** at the
database level and must be supplied explicitly by whatever future
write path creates a row. This checkpoint's factory/fixtures always
set it explicitly for the same reason (`DocumentFactory`'s own
docblock).

This is deliberately the CANONICAL four-tier vocabulary
(`public`/`internal`/`sensitive`/`highly_sensitive`), not
`employee_documents.classification_tier`'s narrower `restricted`/
`highly_sensitive`-only vocabulary — that was HR's own domain-specific
choice (appropriate for HR's Sensitive-or-higher baseline, since HR
data is never ordinary broadly-visible content), not a shared-module
requirement. A general Documents module must be able to hold a Public
school event photo just as validly as a Highly Sensitive medical
record.

### Non-goals of this checkpoint (0E.1)

Explicit, not merely absent by oversight:

- **No write/create service.** No `App\Domain\Documents\Application\*`
  exists yet. Rows in this checkpoint exist only via the factory in
  tests, proving the schema/constraints are correct in isolation from
  any not-yet-designed authorization/upload flow.
- **No HTTP/API surface, no UI.** Per this checkpoint's own brief
  ("Do NOT automatically build API or UI in the first checkpoint...
  preferred: no HTTP, no UI, no mobile API unless specifically
  required") and matching every other domain's own foundation-first
  precedent.
- **No capabilities.** No `documents.*` capability exists in
  `CapabilityAndRoleSeeder` yet. There is no action to authorize —
  schema/RLS is the only protection this checkpoint provides, and RLS
  (tenant isolation) is deliberately never treated as a substitute for
  capability-based authorization once a real write/read service exists
  (root CLAUDE.md rule 6). Which capability model the future write
  path uses — a single `documents.manage`, or reusing/complementing
  each owning module's own document capability
  (`hr.employees.documents.manage` already exists) — is an open design
  question for that later checkpoint, not decided here.
- **No audit wiring.** No mutation occurs in this checkpoint
  (`SchoolAuditEvent`/`AuditRecorder` audit real actions; a schema
  migration is not an auditable actor action).
- **No download/signed-URL authorization**, no object-storage read or
  write of any kind, no retention/expiry policy, no malware scanning,
  no checksum/content-hash column (ADR 0012's own deferred list,
  restated here for this specific checkpoint).
- **No `employee_documents` reconciliation.** HR.md/ADR 0028 explicitly
  promised this reconciliation would happen "when Phase 0E's real
  Documents module is eventually built" — that module now exists in
  foundation form, but the reconciliation itself (whether
  `employee_documents` gets absorbed into `documents` or stays a
  domain-specific metadata table) is deliberately **not** decided in
  this checkpoint. Phase 8A is published and closed; this checkpoint
  touches no HR file. Deciding the reconciliation now, before a second
  real owner-module consumer of `documents` exists to validate the
  shape, would be exactly the premature cross-module design root
  CLAUDE.md rule 2 warns against.

## Data classification

| Data | Tier | Notes |
|---|---|---|
| `classification_tier` value itself, `original_filename`, `mime_type`, `size_bytes` | Internal | Metadata about a file, not the file's content. |
| The underlying file's actual classification | Inherited from content (per-row `classification_tier`, explicitly set, never defaulted) | A document's own row states its tier; this table does not infer it. |
| `storage_path` | Internal | A tenant-namespaced path fragment, not itself secret, but never exposed to an unauthenticated caller (no HTTP surface exists in this checkpoint to expose it through). |

## Security register (Phase 0E.1)

| Risk | Severity | Resolution |
|---|---|---|
| Cross-School IDOR via a guessable owner id | P0 if ever shipped without it | Structural: RLS (enabled+forced) plus three composite foreign keys, each scoped to `(id, school_id)`. Verified by `DocumentRawIsolationTest` (raw SQL, unprivileged role) and `DocumentSchemaTest`'s cross-School composite-FK rejection tests. |
| A Document referencing zero or multiple owners (ambiguous/unsafe ownership) | P1 if unaddressed | Database CHECK constraint, not application discipline. Verified by three dedicated tests (zero/two/three owners). |
| A future write path defaulting `classification_tier` to something broadly-visible | P2 (deferred — no write path exists yet) | No database default exists; every insert must state it explicitly. Flagged here for the checkpoint that adds a write service to carry forward. |
| RLS treated as sufficient authorization once a real write/read service exists | P2 (deferred — no service exists yet) | Explicitly flagged in "Non-goals" above: capability-based authorization is still required once any mutation/read action exists (root CLAUDE.md rule 6); RLS alone is tenant isolation, not authorization. |

No P0/P1 open as of this checkpoint — Phase 0E.1 ships no runtime
write/read action, only schema.

## Test strategy

Authoritative selector for this domain:

```
php artisan test tests/Feature/Documents tests/Feature/Postgres/DocumentRawIsolationTest.php
```

Never `--filter=Documents` (repository-wide convention, CLAUDE.md rule
14/every prior checkpoint's own report).

- `tests/Feature/Documents/DocumentSchemaTest.php` — model/schema/
  constraint proof (UUIDv7, tenant scoping, exclusive-arc owner
  constraint, classification/status CHECKs, composite foreign keys,
  `TenantStoragePath` integration, ordinary Eloquent scoping).
- `tests/Feature/Postgres/DocumentRawIsolationTest.php` — raw-SQL RLS
  isolation under the unprivileged `school_os_app` role, independent
  of Eloquent's `SchoolScope`.

No concurrency test is included in this checkpoint — no allocator,
uniqueness rule, or one-current-row invariant exists yet (the
exactly-one-owner CHECK is enforced per-row at INSERT time, not a
race-prone cross-row invariant like `AcademicYear`'s one-active-per-
School or `EmployeeAssignment`'s one-primary-open-per-employment). A
future write/allocation-shaped checkpoint should re-evaluate this.
