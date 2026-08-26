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
0E.1  Documents Domain Foundation   (documents table, Document model) [implemented]
0E.2  Document Write Path & Storage Integration   (DocumentService, real object storage, Employee owner activated) [implemented]
0E.3  Authorized Document Read & Content Access   (DocumentReadService, metadata + streamed content, Employee owner activated) [implemented]
0E.4  Owner-Scoped Document Discovery & Metadata Listing   (DocumentListingService, ordinary + Highly Sensitive listing, Employee owner activated) [implemented]
0E.5  Documents HTTP/API Transport   (DocumentController, six routes over 0E.2/0E.3/0E.4's services, Employee owner activated — this checkpoint) [implemented]
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

No concurrency test is included in the 0E.1 schema-only checkpoint — no
allocator, uniqueness rule, or one-current-row invariant exists yet
(the exactly-one-owner CHECK is enforced per-row at INSERT time, not a
race-prone cross-row invariant like `AcademicYear`'s one-active-per-
School or `EmployeeAssignment`'s one-primary-open-per-employment).
0E.2's own `archive()` operation is likewise a plain idempotent status
update, not a race-prone invariant — see its own section below for why
sequential-call simulation is sufficient there too.

## Document Write Path & Storage Integration (0E.2, implemented)

The Documents module's first production write path — `DocumentService`
(`App\Domain\Documents\Application\DocumentService`). Structurally
mirrors `App\Domain\Communications\Application\CommunicationAttachmentService`,
the one existing production precedent in this repository for
"validate, write a real object, then persist metadata with
compensation" (Phase 5A.6) — adapted for the exclusive-arc multi-
owner-type schema (0E.1) and owner-domain (not participant-based)
authorization.

### Typed input, not an unbounded array

`CreateDocumentData` (owner descriptor + `classificationTier` + a real
Laravel `UploadedFile`) is the only way to call
`DocumentService::create()` — there is no
`Document::create($request->all())`-shaped path anywhere. Caller-
controlled fields are exactly: which owner, which classification tier,
and the file itself. Everything else (`school_id`, `storage_disk`,
`storage_path`, `uploaded_by_user_id`, `status`, and the server-
sniffed `mime_type`/`size_bytes`) is derived server-side and cannot be
overridden by a caller — proven by
`DocumentServiceCreateTest::server_derived_metadata_cannot_be_overridden_by_the_caller`.

### Owner descriptor — application-layer only, closed to three types

`DocumentOwner` (`App\Domain\Documents\Application\DocumentOwner`) is
a final class with a private constructor and exactly three named
factory methods (`::employee()`/`::student()`/`::guardian()`) — there
is no way to construct one with an arbitrary `$type` string, a model
class name, or a Laravel morph-map key. `DocumentService` converts it
internally into exactly one of the 0E.1 exclusive-arc columns; this is
an application-boundary convenience, not a return to a polymorphic
database schema (the `documents` table itself is completely unchanged
by 0E.2).

### Active write owner types

**Employee: ACTIVATED.** Reuses HR's own already-established two-tier
authorization boundary verbatim
(`App\Domain\HR\Application\EmployeeDocumentService::assertClassificationCapability()`,
Phase 8A.10/8A.11) rather than inventing a new Documents-specific
policy: `hr.employees.documents.manage` for `public`/`internal`/
`sensitive`, `hr.employees.sensitive.manage` for `highly_sensitive`.
This module's four-tier vocabulary collapses to that same two-way
split for Employee owners specifically — it is not a new capability
and not a new policy, only this checkpoint's first real caller of an
authorization boundary that already existed.

**Student: DEFERRED. Guardian: DEFERRED.** Neither
`students.manage`/`students.view` nor `guardians.manage`/
`guardians.view` names documents/attachments anywhere in their own
capability labels (`CapabilityAndRoleSeeder`) — they are broad
identity-management capabilities ("create, update, status, Guardian
links" / "Guardians, Guardian contact information, and Guardian
links"), and this checkpoint's own governing brief explicitly warns
against stretching a broad identity capability to cover document
management, or inferring write authority from a Guardian/Student
relationship. Rather than invent an unreviewed capability, 0E.2
activates writes for Employee only and documents Student/Guardian as
an explicit, deliberate deferral — `DocumentOwnerTypeNotSupportedException`
is thrown for either, before any storage or database side effect,
proven even when the actor holds the corresponding broad
`students.manage`/`guardians.manage` capability (so the deferral is a
real authorization boundary, not merely "no test exercises it yet").
Schema support (the `student_id`/`guardian_id` columns and their
composite foreign keys) and write-service activation are deliberately
not the same thing — activating either later is an additive change to
`DocumentService` alone, no migration required.

**No generic `documents.*` capability was added.** Option A from this
checkpoint's own decision framework (existing owner-domain capability
sufficient) applied cleanly for the one owner type this checkpoint
activates; inventing a capability "for symmetry" with Student/Guardian
before their own authorization boundary is actually decided would have
been exactly the premature design root CLAUDE.md rule 2 warns against.

### Classification

Required, no default, validated against the exact same canonical
four-tier vocabulary 0E.1 established
(`InvalidDocumentClassificationException` for a missing or invalid
value) — the database CHECK constraint remains the final defense in
depth, this is the first line. `highly_sensitive` carries the extra
authorization requirement described above; the other three tiers do
not currently distinguish between each other for authorization
purposes (all three need only the ordinary `hr.employees.documents.manage`
capability) — this is an explicit, evidence-based decision (HR's own
capability model has no finer split than "ordinary vs. highly
sensitive"), not an oversight.

### File validation

`config/documents.php`: `disk` (default `local`, ADR 0011's S3-
compatible technology reached via `DOCUMENTS_DISK=s3` in a real
deployment — independent of `config('filesystems.default')`, matching
`config('communications.attachments.disk')`'s own precedent so an
unrelated future default-disk change never silently relocates Document
storage), `max_file_size_mb` (default 10), and `allowed_mime_types` (a
real, server-sniffed MIME type mapped to its allowed declared
extension(s) — never the client-supplied Content-Type header; a
mismatch between sniffed type and declared extension is rejected
exactly like a disallowed type). Deliberately excludes SVG (can embed
script) and every macro-enabled Office format, mirroring
`config('communications.attachments.allowed_mime_types')`'s own
exclusions exactly.

### Storage

Private only — `local` (`storage_path('app/private')`) or `s3`
(MinIO/S3-compatible, ADR 0011), never `public`. 0E.2 is the Documents
module's own first production caller of
`App\Support\Tenancy\TenantStoragePath::for()` — HR's
`EmployeeDocumentService` and Communications'
`CommunicationAttachmentService` already called it in production
before this checkpoint, so "zero callers" only ever described the
Documents module's own prior non-use of it, not the helper itself (see
0E.1's own corrected note above). The persisted key is always
`TenantStoragePath::for($school, "documents/{ownerType}/{ownerId}/{serverGeneratedUuid}.{extension}")`
— never a caller-supplied path, never the raw original filename.
Object-key uniqueness comes from a fresh UUIDv7 per upload, not
filename uniqueness — two uploads with an identical original filename
for the identical owner produce two distinct keys and two distinct
`documents` rows, proven by
`DocumentServiceCreateTest::two_uploads_with_the_same_original_filename_produce_distinct_object_keys_and_rows`.
`original_filename` is preserved only as sanitized (basename, control-
characters stripped, length-capped) DISPLAY metadata — never used to
build the storage key, defeating path traversal via a crafted
filename.

### Upload sequence and storage/database compensation

PostgreSQL transactions cannot atomically cover a MinIO/S3 object
write, so the ordering is deliberate (identical shape to
`CommunicationAttachmentService::store()`):

1. Resolve the declared owner under the trusted School (fails closed,
   identically, for "does not exist" and "exists in a different
   School" — `DocumentOwnerNotFoundException` never distinguishes the
   two).
2. Authorize the actor for that owner type + the requested
   classification tier — before any I/O.
3. Cheap, no-I/O validation (classification tier, MIME/extension
   allow-list, size limit).
4. Write bytes to the configured disk under the server-generated key.
5. Create the `documents` row + `document.created` audit event inside
   one DB transaction.

If step 5 throws, the object written in step 4 is deleted
(compensation) before the exception propagates — proven, not merely
asserted, by `DocumentServiceCompensationTest`, which forces a real
transaction failure (a mocked `AuditRecorder` throwing mid-transaction)
after a genuine successful object write, and confirms: no `documents`
row, no committed audit event, and the object physically removed from
storage. If step 4 throws, no DB write is ever attempted (proven by a
faked-disk failure test and a real-network-failure test against actual
MinIO in `DocumentMinioStorageTest`).

**Compensation-failure operational limitation (documented, not
solved):** if the compensating `Storage::delete()` call in step 5's
catch block itself fails (e.g. storage becomes unreachable at the
exact moment compensation runs), the result is a genuinely orphaned
object with no `documents` row referencing it. This checkpoint does
not build an orphan-reaper/cleanup-queue subsystem for that bounded,
rare failure mode — matching this checkpoint's own explicit scope
limit ("do not build a full orphan-reaper subsystem unless ADR
requires it"). No application-level access path to the orphan was
demonstrated, no `documents` metadata row exists to expose it, and the
object remains private under the same storage policy as any other
object on the disk — but this is still a genuine residual data-
retention/privacy/operational risk, not a resolved one, until some
future storage-level cleanup actually removes it. Classified P3 (Low)
accordingly, not "no impact."

### Metadata provenance

`mime_type`/`size_bytes` are always the server's own observation of
the uploaded file (Symfony's real content-based MIME sniffing,
`UploadedFile::getSize()`), never a caller-declared claim.
`uploaded_by_user_id` is the trusted `$actor` argument's id, exactly
like `EmployeeDocumentService::register()`'s own provenance rule — a
caller cannot forge who uploaded a Document.

### Document status on create / archive semantics

Every `create()` begins `active` — there is no path to create a
Document already `archived`. `archive()` is the only status
transition: matches `documents`' 0E.1 never-hard-deleted lifecycle
exactly (`EmployeeDocumentService::archive()`'s identical shape) —
status only, object physically untouched, metadata otherwise
unchanged. Repeated archive is deterministic (idempotent). Archive
re-derives the correct owner-domain capability from the Document's own
persisted owner column (never trusts a caller-supplied classification/
owner claim), and is denied cross-School exactly like create. No
`update()`/owner-switch/classification-downgrade operation exists —
the exclusive-arc columns and `classification_tier` are immutable
after creation in this checkpoint; introducing either is a distinct,
deliberate later decision, not an oversight.

### Audit

Two events, `App\Support\Audit\AuditRecorder::school()`, no new audit
table: `document.created` and `document.archived`. Metadata carries
`documentId`/`ownerType`/`classificationTier`/`sizeBytes`/`mimeType` —
never `storagePath`/`storage_path` (least disclosure, proven by
`DocumentServiceCreateTest::a_successful_create_produces_exactly_one_audit_event_with_no_storage_path`).
An authorization or validation failure produces zero audit events
(proven for both create and archive).

### What 0E.2 still does not do

No download/signed URL, no HTTP/API, no UI, no checksum (still no
`checksum` column — ADR 0012 does not mandate it at initial upload,
and platform convention does not provide it "essentially for free" for
this table the way `CommunicationAttachment`'s own separate
`checksum_sha256` column does for its own table), no malware scanning,
no retention policy, no `employee_documents` reconciliation (ADR
0028's promise remains unperformed — `EmployeeDocumentService` is
untouched, confirmed by the existing HR regression suite passing
unchanged), and no `update()`/owner-switch/classification-transition
operation. `App\Domain\Communications\Application\CommunicationAttachmentService`
is unaffected — `TenantStoragePath` itself was not modified, confirmed
by the full Communications regression suite passing unchanged.

### Test strategy (0E.2 addition)

Authoritative selector unchanged in form, now covering more files under
the same two paths:

```
php artisan test tests/Feature/Documents tests/Feature/Postgres/DocumentRawIsolationTest.php
```

New files: `DocumentServiceCreateTest.php` (owner resolution/
authorization/classification/file-validation/audit/metadata-provenance/
cross-School/multi-School-capability-isolation/RLS-vs-RBAC-independence),
`DocumentServiceArchiveTest.php` (archive semantics/authorization/
audit), `DocumentServiceCompensationTest.php` (the critical storage-
success-then-DB-failure compensation proof), and
`DocumentMinioStorageTest.php` — a REAL object-storage integration
test against actual MinIO (not `Storage::fake()`), proving the `s3`
disk/`TenantStoragePath` wiring genuinely works end-to-end and that a
real unreachable-endpoint failure still leaves zero `documents` rows.

### Security register (0E.2)

| Risk | Severity | Resolution |
|---|---|---|
| Cross-domain authorization bypass (a capability for one owner domain granting Document write access to another) | P0 if ever shipped without it | Structurally impossible for the activated Employee type (only `hr.employees.*` capabilities are checked); Student/Guardian are not activated at all, so there is no capability to bypass into for them. Verified by `DocumentServiceCreateTest::cross_domain_authorization_is_not_bypassed_by_a_generic_documents_capability`. |
| Unauthorized object write (storage I/O before authorization) | P0 if ever shipped without it | Authorization is step 2 of the upload sequence, strictly before any Storage:: call. Verified by `authorization_failure_writes_no_object_and_no_row`. |
| Storage-succeeds/DB-fails orphan object | P1 if unaddressed | Explicit compensation, proven (not merely asserted) by `DocumentServiceCompensationTest`. |
| Same-User multi-School capability bleed | P1 if unaddressed | `CapabilityResolver`'s existing School-scoped cache/resolution reused as-is; verified by a dedicated test. |
| Path traversal / storage-key collision via filename | P2 if unaddressed | Original filename never used to build the storage key (server-generated UUIDv7 component); display name sanitized. Verified by dedicated tests. |
| Compensation itself failing (bounded orphan-object operational risk) | P3 (documented, not solved) | No cleanup-queue subsystem built for this checkpoint — see "compensation-failure operational limitation" above. No application-level access path demonstrated and no metadata row exists to expose the orphan, but this remains a genuine residual data-retention/privacy/operational risk, not a resolved one, until storage-level cleanup occurs. |

No open P0/P1 as of 0E.2.

## Authorized Document Read & Content Access (0E.3, implemented)

The Documents module's first read/content-access path —
`DocumentReadService` (`App\Domain\Documents\Application\DocumentReadService`).
Deliberately a separate class from `DocumentService` (mutation
orchestration) — this class only ever reads, never mutates a
`documents` row.

**Central invariant:** knowing a Document UUID is never sufficient to
access its metadata or bytes. Every public method resolves the
Document under the trusted School first (fails closed, identically,
for "does not exist" and "exists in a different School" —
`DocumentNotFoundException` never distinguishes the two), derives
owner type/classification from that already-persisted row — never from
caller input, there is no `DocumentOwner` parameter anywhere on this
class, unlike `DocumentService::create()` — and only then authorizes
the actor. Storage is never touched before authorization succeeds.

### Metadata vs. content — two distinct operations

`metadata(School, documentId, User): DocumentMetadata` — a safe DTO
(`documentId`/`ownerType`/`ownerId`/`classificationTier`/`status`/
`originalFilename`/`mimeType`/`sizeBytes`/`uploadedAt`), never a raw
`Document` Eloquent model, never `storage_disk`/`storage_path`.
Deliberately excludes `uploaded_by_user_id` too — matching the
narrower precedent `App\Domain\HR\Application\EmployeeProfileDocumentEntry`
already established for the closest analogous read path in this
repository; a caller needing "who uploaded this" belongs at the audit
log, not this DTO.

`content(School, documentId, User): DocumentContent` — opens the
private stored object only after the identical authorization gate
metadata() uses, returning `documentId`/`originalFilename`/`mimeType`/
`sizeBytes` plus an open PHP stream (`Storage::readStream()`, never
`Storage::get()` — proven structurally by
`DocumentReadServiceStructuralTest::content_never_buffers_the_whole_file_via_storage_get`,
so a future larger file size limit never couples to process memory).
A metadata-only caller never triggers a storage read — proven by
`DocumentReadServiceMetadataTest::metadata_lookup_does_not_touch_storage`.
Stream ownership: the caller closes it (documented on `DocumentContent`
itself) — `DocumentReadService` never closes a stream it just handed
back, since doing so would make the result useless.

### Owner activation (read)

Identical to 0E.2's write-side decision, reconfirmed independently for
reads: **Employee: ACTIVATED. Student: DEFERRED. Guardian: DEFERRED.**
`students.manage`/`students.view` and `guardians.manage`/
`guardians.view` still do not name documents/attachments anywhere in
their capability labels — the same evidence that justified deferring
writes justifies deferring reads. A Student/Guardian-owned Document
(reachable in the database purely because 0E.1's schema always
supported all three owner arms) is rejected by
`DocumentOwnerTypeNotSupportedException` before any capability check
or storage access is attempted, regardless of which broad
identity-management capability the actor holds.

### Authorization

Reuses HR's existing two-tier READ boundary verbatim — the exact
read-side counterpart of 0E.2's write-side reuse:
`hr.employees.documents.view` for `public`/`internal`/`sensitive`,
`hr.employees.sensitive.view` for `highly_sensitive`. **No new
`documents.*` capability was added** — same Option A reasoning as
0E.2. An actor holding only a Student-domain capability cannot read an
Employee-owned Document (proven by
`DocumentReadServiceMetadataTest::cross_domain_authorization_is_not_bypassed_by_a_generic_documents_capability`-equivalent
coverage) — there is no generic capability for it to bypass into.

**"public" classification is a data-classification tag, not a public
object ACL.** A `documents.classification_tier = 'public'` row is
still a tenant-owned private object behind `DocumentReadService`'s
full authorization gate — `public` only means "safe for a broader
audience *within* the School if a future policy grants it," never
"reachable without authentication/authorization." This checkpoint
introduces no unauthenticated/anonymous access path of any kind.

### Archived Documents remain readable — evidence-based decision

Neither `App\Domain\HR\Application\EmployeeProfileWorkspaceService`'s
Restricted-tier document query nor
`App\Domain\HR\Application\EmployeeSensitiveDocumentReadService`'s
Highly Sensitive query filters by `status` — both already read
archived rows identically to active ones in production. This
checkpoint follows that same established precedent (Option A from
this checkpoint's own decision gate) rather than inventing a new
policy: an authorized actor can read an archived Document's metadata
and content exactly like an active one. No object is ever physically
removed on archive (0E.2), so there is no "content disappeared" race
to reason about between a concurrent archive and a read.

### Audit policy — evidence-based, not mechanically "audit everything"

`App\Domain\HR\Application\EmployeeProfileWorkspaceService`'s
ordinary/Restricted-tier document read is **not audited at all**;
only `EmployeeSensitiveDocumentReadService`'s Highly Sensitive read
is (`hr.employee_document.sensitive_viewed`). `DocumentReadService`
reuses that exact boundary: `public`/`internal`/`sensitive` metadata/
content access is not audited; `highly_sensitive` metadata/content
access is, on success only, via two distinct events —
`document.sensitive_metadata_viewed` and
`document.sensitive_content_accessed` — never on authorization denial,
a nonexistent/cross-School Document, an unsupported owner type, a
missing object, or a storage failure. Neither event's metadata
includes `storagePath`/`storage_path` (proven by a dedicated test).

**Audit semantic, stated precisely (there is no HTTP layer to observe
completion yet):** `document.sensitive_content_accessed` means "an
authorized content stream was successfully opened," **not** "the
file's bytes were fully delivered to an end user" — this checkpoint
never claims a download-completion guarantee it cannot actually prove.
No event is named `document.downloaded` or `document.download_completed`
for this reason.

### Storage read failure / missing object

A single safe exception, `DocumentContentUnavailableException`, covers
every content-read failure mode identically: a missing object
(operational failure, manual storage corruption, an external
lifecycle mistake), an unreachable disk, or an unknown/misconfigured
disk name. Never the raw provider exception (no S3 XML, no MinIO
internal error, no filesystem path), never `storage_path`/
`storage_disk` in the message. No successful-access audit event is
ever written for a failed read. Proven against both a faked-disk
failure and a real unreachable MinIO endpoint
(`DocumentReadMinioIntegrationTest`).

### No database transaction around stream consumption

Resolution and authorization complete first as plain reads (no row
locking — a read has no reason to lock `documents` rows); the object
stream opens afterward, entirely outside any `DB::transaction()`. No
Document field is mutated by a read of any kind — no
`last_accessed_at`/`view_count`/`download_count`, none of which exist
in the 0E.1 schema and none of which this checkpoint adds.

### Orphan non-addressability (existing 0E.2 P3 residual)

The 0E.2 compensation-failure residual (a DB-transaction failure whose
own compensating object-delete also fails can leave a private orphan
object with no `documents` row referencing it) remains open,
unrelated to and unaffected by this checkpoint. `DocumentReadService`'s
entire public API starts from a Document UUID resolved under tenancy —
there is no method accepting a raw bucket/key/path
(`DocumentReadServiceStructuralTest` proves this by reflection: no
public parameter name contains "path"/"key"/"disk"). An orphan object
with no referencing row is therefore structurally unreachable through
this service, not merely unreachable by convention.

### What 0E.3 still does not do

No HTTP/API, no UI, no signed/temporary URL (an internal stream
remains the transport-neutral primitive; ADR 0012 does not mandate
presigned URLs at this foundation stage), no listing/search (a
Document must already be known by id), no Student/Guardian read
activation, no `employee_documents` reconciliation, no checksum, no
malware scanning, no retention. `EmployeeDocumentService`/
`EmployeeSensitiveDocumentReadService` remain completely untouched
(confirmed by the unchanged HR regression suite), and
`CommunicationAttachmentService`/`TenantStoragePath` remain unaffected
(confirmed by the unchanged Communications regression suite).

### Test strategy (0E.3 addition)

Authoritative selector unchanged in form:

```
php artisan test tests/Feature/Documents tests/Feature/Postgres/DocumentRawIsolationTest.php
```

New files: `DocumentReadServiceMetadataTest.php` (authorization order,
not-found/cross-School, cross-domain bypass, unsupported owners,
same-User multi-School, RLS-vs-RBAC, full classification matrix,
audit policy, archived readability), `DocumentReadServiceContentTest.php`
(stream correctness, authorization-before-storage, missing object,
storage failure, archived content, audit policy, no DB mutation),
`DocumentReadMinioIntegrationTest.php` (a REAL end-to-end write-then-
read round trip against actual MinIO, plus a real post-authorization
storage-read failure), and `DocumentReadServiceStructuralTest.php`
(reflection-based proof of the public API surface, no raw-path/key/
disk parameters, no signed-URL method, no `Storage::get()` whole-file
buffering).

### Security register (0E.3)

| Risk | Severity | Resolution |
|---|---|---|
| Known-UUID-alone access (Document existence/metadata/content reachable without real authorization) | P0 if ever shipped without it | Every public method resolves under trusted School + authorizes from the Document's own persisted owner/classification before any data is returned. Verified across the full metadata/content test suites. |
| Cross-domain authorization bypass (a capability for one owner domain granting read access to another) | P0 if ever shipped without it | Structurally impossible for the activated Employee type (only `hr.employees.*` capabilities checked); Student/Guardian are not activated, so there is no capability to bypass into for them. |
| Highly Sensitive existence/metadata/content leak to an ordinary-capability actor | P1 if unaddressed | The identical authorization gate covers both metadata and content for every tier — an ordinary `hr.employees.documents.view` holder cannot learn a highly_sensitive Document's filename, size, MIME, or bytes. Verified by the classification-matrix test. |
| Orphan object (0E.2 residual) becoming addressable via the new read path | P2 if unaddressed | Structurally impossible — see "Orphan non-addressability" above. |
| Storage-path/disk/provider-error leakage on failure | P2 if unaddressed | Single generic `DocumentContentUnavailableException` for every failure mode; verified no message/audit metadata contains `storage_path`/`storage_disk`. |
| Successful-access audit recorded on a failed/denied read | P2 if unaddressed | Audit calls sit strictly after both authorization and the storage read succeed; verified for denial, not-found, missing-object, and storage-failure paths. |
| 0E.2 compensation-cleanup residual (private orphan object, no metadata row) | P3 (documented, not solved, carried forward unchanged from 0E.2) | Unrelated to and unaffected by 0E.3 — no new exposure introduced or removed by this checkpoint. |

No open P0/P1 as of this checkpoint.

## Owner-Scoped Document Discovery & Metadata Listing (0E.4, implemented)

The Documents module's first discovery/listing path —
`DocumentListingService` (`App\Domain\Documents\Application\DocumentListingService`).
A third, separate class from `DocumentService` (mutation) and
`DocumentReadService` (known-id direct access) — listing begins from a
known *owner*, not a known Document id, so it needed its own query
projection rather than either of the other two classes. Never
implemented as a loop calling `DocumentReadService::metadata()` per
row — every operation here is one bounded SQL query with the
classification predicate applied inside the `WHERE` clause, before
PostgreSQL ever computes a count or a page.

### Two explicit, separately-authorized operations

Mirrors the exact split `EmployeeProfileWorkspaceService` (ordinary)
/ `EmployeeSensitiveDocumentReadService` (Highly Sensitive) already
established — never one operation that mixes tiers by actor privilege:

- `list()` — `public`/`internal`/`sensitive` only, requires
  `hr.employees.documents.view`. `highly_sensitive` rows are excluded
  by the SQL `WHERE classification_tier IN (...)` clause itself, so
  they never reach `total()`, never occupy a page slot, and never
  affect ordering — proven by
  `DocumentListingServiceOrdinaryTest::highly_sensitive_documents_are_completely_hidden_from_the_ordinary_list`
  (2 ordinary + 3 Highly Sensitive Documents for one Employee; the
  ordinary actor sees exactly 2 items, `total()` = 2) and
  `..._highly_sensitive_documents_do_not_create_extra_pages` (20
  ordinary + 30 Highly Sensitive at `perPage=10`: `total()` = 20,
  `lastPage()` = 2, and page 3 — which would exist if the 30 hidden
  rows counted — is empty, never a sparse page).
- `listSensitive()` — `highly_sensitive` only, requires
  `hr.employees.sensitive.view`. The two capabilities are never
  assumed to imply each other (matching `DocumentService`'s/
  `DocumentReadService`'s own two-tier split): ordinary-only cannot
  call `listSensitive()`, sensitive-only cannot call `list()` and see
  ordinary rows, and an actor holding both still gets two independent,
  non-merged results depending on which method they call.

**No new `documents.*` capability was added** — Option A applied
again, identically to 0E.2/0E.3.

### Owner activation, resolution, and archived policy — unchanged from 0E.2/0E.3

Only Employee is activated (`DocumentOwnerTypeNotSupportedException`
for Student/Guardian, thrown before any query). The owner is resolved
under the trusted School exactly like `DocumentService`'s write path
(`DocumentOwnerNotFoundException`, identical for nonexistent and
cross-School ids — never `DocumentNotFoundException`, which is
0E.3's distinct "a *Document* id didn't resolve" exception; listing
never resolves a Document id, only an owner id, so it necessarily uses
the owner-resolution exception). Archived Documents are never excluded
— matching the exact precedent both HR listing services already
established (neither filters by `status`) — so `DocumentListingQuery`
has no status field at all; there is no repository precedent for one.

### Visible-total-only — the hard privacy gate

`total()`/`lastPage()`/page contents are computed from a query that
already excludes the hidden tier, via Eloquent's `paginate()` running
`COUNT(*)` against the SAME filtered `WHERE` clause the page query
uses — never "paginate everything, then strip hidden rows in PHP,"
which would leak hidden-row existence through inflated totals and
sparse pages. `classification_tier` is never caller-controlled on
either operation (no filter parameter exists on `DocumentListingQuery`
that could smuggle `highly_sensitive` into `list()`) — each operation
hardcodes its own tier predicate.

### Pagination

Reuses the exact `DEFAULT_PER_PAGE`/`MAX_PER_PAGE` (25/100) convention
`EmployeeDirectoryQuery`/`EmployeeActivityTimelineQuery` already
established, and the exact `LengthAwarePaginator`-wrapping-safe-DTOs
pattern `EmployeeActivityTimelineService::get()` already established
(construct a fresh `LengthAwarePaginator` from the mapped
`DocumentMetadata` collection plus the original paginator's
`total()`/`perPage()`/`currentPage()` — never the raw Eloquent
paginator, which would carry `Document` models). `page`/`perPage` are
clamped in `DocumentListingQuery`'s constructor (page ≥ 1, perPage ∈
[1, 100]) — no unbounded/negative/zero input reaches the query.
Ordering is fixed (`uploaded_at DESC, id DESC`), never caller-supplied
— no sort-injection surface exists.

### No search, no filters beyond pagination

No filename/full-text search, no arbitrary classification/MIME/date
filters — discovery is strictly "known owner → bounded metadata list."
Free-text search and any cross-owner/global discovery remain explicitly
out of scope for this checkpoint (their own privacy/security design
questions, deferred).

### Safe DTO shape — unchanged

Reuses `DocumentMetadata` (0E.3) as-is for both operations' items — no
new list-specific DTO was needed, since its existing field set was
already safe for discovery (no `storage_disk`/`storage_path`,
no uploaded_by_user_id). No raw `Document` Eloquent model ever crosses
either method's return boundary.

### No storage I/O

Listing operates entirely from PostgreSQL metadata — no
`Storage::readStream()`/`get()`/`exists()`/`files()`/`allFiles()`
anywhere in `DocumentListingService`. A missing physical object does
not remove a row from a listing (0E.3's `content()` is what safely
handles that case, at actual access time, not discovery time).

### Audit policy — reused precedent, not per-row noise

`list()` (ordinary) is never audited, matching
`EmployeeProfileWorkspaceService`'s own un-audited ordinary document
read. `listSensitive()` audits exactly once per successful call that
returns at least one row (`document.sensitive_list_viewed`, via
`AuditRecorder::school()`, subject = the owning Employee, metadata
limited to `ownerType`/`ownerId`/`count` — never a filename, storage
path, or the full Document-id list) — the same "audit only if
something sensitive was actually exposed" rule
`EmployeeSensitiveDocumentReadService` already established, extended
to listing: an empty authorized sensitive listing writes zero audit
rows (nothing was actually exposed), and a 15-row sensitive listing
still writes exactly one audit row, never fifteen — proven by
`DocumentListingServicePerformanceTest::sensitive_listing_writes_at_most_one_audit_row_regardless_of_result_size`.

### Query performance and index findings

`EXPLAIN ANALYZE` was run via the ordinary `pgsql` connection (with
the exact `app.current_school_id` GUC `DocumentListingService` sets at
runtime — never the `pgsql_admin`/BYPASSRLS connection, which would
silently skip the RLS predicate the planner always evaluates in
production and could show a misleadingly different plan) against a
representative fixture: 5 Schools × 30 Employees × 40 Documents each
(6,063 rows total, mixed classification tiers, mixed active/archived
status). Both the ordinary-list query and the sensitive-list query
use `Index Scan using documents_employee_id_index` (0E.1's existing
single-column index) — sub-millisecond execution (0.08–0.2ms) at this
scale, since the `employee_id` index alone already narrows to one
Employee's ~40 rows before the classification/status predicates ever
need to filter in-memory. **No migration or new index was needed** —
0E.1's existing indexes are sufficient; no speculative composite index
was added.

A dedicated differential query-count test
(`DocumentListingServicePerformanceTest::ordinary_listing_query_count_does_not_grow_with_result_size`)
confirms the query count itself stays flat (a small, fixed number)
whether an owner has 3 or 63 Documents — proving no N+1 independent of
the PostgreSQL plan evidence above.

### Employee Document / Communication Attachment independence — unchanged

`DocumentListingService` never queries `employee_documents` or
`communication_attachments` — confirmed by grep, zero references.
`EmployeeDocumentService`/`EmployeeSensitiveDocumentReadService`/
`CommunicationAttachmentService` remain completely untouched (the
unchanged HR and Communications regression suites confirm this).

### What 0E.4 still does not do

No HTTP/API, no UI, no signed/temporary URL, no global/cross-owner
search, no Student/Guardian activation (write, read, or listing), no
`employee_documents` reconciliation, no checksum, no malware scanning,
no retention. The 0E.2 compensation-cleanup P3 residual remains open
and unaffected — an orphan object (no `documents` row) is not listable,
not countable, and not addressable through this checkpoint either,
since listing operates exclusively on persisted `documents` rows.

### Test strategy (0E.4 addition)

Authoritative selector unchanged in form:

```
php artisan test tests/Feature/Documents tests/Feature/Postgres/DocumentRawIsolationTest.php
```

New files: `DocumentListingServiceOrdinaryTest.php` (authorization,
cross-domain bypass, cross-School/same-User-multi-School isolation,
RLS-vs-RBAC, the Highly-Sensitive-hiding acceptance gate and its
pagination-oracle variant, archived inclusion, DTO shape, deterministic
ordering, bounded-pagination-input clamping, unsupported owners),
`DocumentListingServiceSensitiveTest.php` (the mirrored authorization/
isolation matrix for `listSensitive()`, the both-capabilities-stay-
separate test, audit policy — success/empty/denied — and audit-metadata
privacy), and `DocumentListingServicePerformanceTest.php` (the N+1
differential query-count proof and the one-audit-row-not-per-row proof).

### Security register (0E.4)

| Risk | Severity | Resolution |
|---|---|---|
| Highly Sensitive row/count/page-count leak through the ordinary list | P0 if ever shipped without it | Classification predicate is inside the SQL `WHERE` clause used for both `COUNT` and `SELECT`; proven by the hard acceptance-gate tests (exact `total()`/`lastPage()`/item-count assertions, sentinel-filename absence). |
| Cross-domain authorization bypass via a listing operation | P0 if ever shipped without it | Structurally impossible for the activated Employee type; Student/Guardian are not activated, so there is no capability to bypass into. |
| Sparse-page / inflated-total leak from PHP-side post-filtering | P1 if unaddressed | Never implemented that way — classification filtering happens in SQL before `paginate()` runs, not after. |
| Per-row sensitive-audit amplification (N events for one list call) | P2 if unaddressed | Exactly one audit call per successful non-empty `listSensitive()` invocation, verified at 15-row scale. |
| Misleading empty-result sensitive audit | P2 if unaddressed | No audit event written when zero Highly Sensitive rows are returned. |
| Storage-path/disk leakage in listed metadata or audit | P2 if unaddressed | `DocumentMetadata` (reused from 0E.3) carries neither field; audit metadata is limited to `ownerType`/`ownerId`/`count`. |
| Query-plan degradation / accidental full-table scan at scale | P3 if unaddressed | Verified via `EXPLAIN ANALYZE` at 6,063-row representative scale — existing 0E.1 index sufficient, no migration needed. |
| 0E.2 compensation-cleanup residual (private orphan object, no metadata row) | P3 (documented, not solved, carried forward unchanged) | Unaffected by 0E.4 — listing only ever operates on persisted `documents` rows. |

No open P0/P1 as of this checkpoint.

## Documents HTTP/API Transport (0E.5, implemented)

Exposes 0E.2/0E.3/0E.4's already-authoritative Application services
(`DocumentService`, `DocumentReadService`, `DocumentListingService`)
over real HTTP, via a single new `App\Domain\Documents\Http\Controllers\DocumentController`.
No new business logic exists at this checkpoint — every authorization
decision, storage access, audit write, owner resolution, classification
policy, and visible-total-filtering rule documented in the 0E.2/0E.3/0E.4
sections above is reused unchanged. This checkpoint's job is exclusively
transport: routes, request validation, response shaping, rate limits,
cache headers, and HTTP-level error mapping.

### Endpoint inventory

Six routes, all under `/api/v1/schools/{school}`:

```
POST  /employees/{employee}/documents              storeForEmployee   — upload
GET   /employees/{employee}/documents               indexForEmployee   — ordinary listing
GET   /employees/{employee}/documents/sensitive      sensitiveIndexForEmployee — Highly Sensitive listing
GET   /documents/{document}                          show               — direct metadata
GET   /documents/{document}/content                  content            — streamed binary content
POST  /documents/{document}/archive                  archive            — archive (204)
```

Student routes: **NONE**. Guardian routes: **NONE**. This checkpoint
activates HTTP transport only for the Employee owner type, exactly
matching 0E.2/0E.3/0E.4's own activation boundary — there is no
structural blocker to adding Student/Guardian routes later, but doing
so is a deliberate future decision, not an oversight here.

The existing HR route `/employees/{employee}/sensitive-documents`
(`EmployeeSensitiveDocumentController`) remains completely separate and
unchanged — no route collision, no shared controller. `documents/sensitive`
(a static third path segment) cannot collide with
`employees/{employee}/documents` (a two-segment path) or with the
differently-named `employees/{employee}/sensitive-documents` HR route:
distinct segment counts and literals, no routing precedence ambiguity.

### Layering — the controller is transport-only

`DocumentController` validates input, calls exactly one Application
service method per action, and shapes the response. It owns none of:

- domain authorization (every service method already performs its own
  capability check against the real authenticated actor before any
  query or storage I/O runs — rule 3/24)
- storage access (`DocumentReadService`/`DocumentService` are the only
  callers of the `Storage` facade in the Documents module; the
  controller never calls it)
- audit writes (`AuditRecorder` never appears in the controller — every
  audit event listed below is written by the service layer)
- owner resolution (`DocumentOwner::employee($id)` is constructed from
  the raw route parameter and handed to the service, which is the only
  layer that resolves/authorizes it against a real row)
- classification policy (which tier gates which capability, which tier
  is hidden from the ordinary listing) — entirely inside
  `DocumentListingService`/`DocumentReadService`/`DocumentService`
- visible-total filtering (the SQL-level classification predicate from
  0E.4 is unchanged and untouched by this checkpoint)
- object compensation (the upload/cleanup sequencing from 0E.2 is
  unchanged)

This is proven, not just asserted: `DocumentControllerStructuralTest`
greps the controller's own source for `Storage::`/`->readStream(`/
`->put(`/`->delete(`/`temporaryUrl`/`signedUrl` (storage), `Gate::authorize`/
`->can(`/`authorizeCapability` (authorization), and `AuditRecorder`
(audit) — none of them appear.

### Authentication / tenancy

- `auth:sanctum` gates the entire route group these six routes live in
  (the same group `EmployeeSensitiveDocumentController` and the rest of
  the HR/Academic Structure routes already use).
- `EnsureSchoolMembershipContext` (or the group's existing equivalent
  school-membership middleware) resolves the trusted School context for
  `{school}` — never a caller-controlled `school_id` (rule 19/68); the
  School the request executes against is always the route/session-
  verified one.
- `{employee}` and `{document}` are always raw route-parameter strings,
  never implicit Eloquent route-model binding — resolution happens
  inside the Application services under tenant scope, matching
  `EmployeeProfileController`/`EmployeeSensitiveDocumentController`'s
  established pattern.
- A cross-School resource id fails exactly the same way a nonexistent
  one does: `DocumentNotFoundException`/`DocumentOwnerNotFoundException`
  never distinguish "doesn't exist" from "exists in a different
  School" (0E.2/0E.3's existing non-enumeration guarantee, unchanged;
  proven at HTTP level by `DocumentHttpDirectAccessTest::a_cross_school_document_id_is_a_safe_404_on_both_routes`).
- A malformed (non-UUID) `{employee}`/`{document}` is rejected via
  `abort_if(! Str::isUuid($id), 404)` **before** it ever reaches a
  query — the same guard `EmployeeProfileController`/
  `EmployeeSensitiveDocumentController` already use, and for the same
  reason: Eloquent's `find()`/a raw `WHERE id = ?` against a UUID-typed
  column raises a raw PostgreSQL `invalid input syntax for type uuid`
  (an uncaught 500) if a non-UUID string reaches it. Proven by
  `DocumentHttpDirectAccessTest::a_malformed_document_uuid_is_a_safe_404_not_a_raw_sql_error`.

### Upload contract

`POST /employees/{employee}/documents`, `multipart/form-data`, exactly
two accepted inputs:

| Field | Rule |
|---|---|
| `file` | `required`, `file` |
| `classification_tier` | `required`, `string` (validated against the real enum inside `DocumentService::create()`, not at the HTTP layer — `InvalidDocumentClassificationException` → 422) |

- **Owner**: the Employee named by the `{employee}` route parameter —
  never a body-supplied owner id or type.
- **School**: the trusted route/session context — never a body
  `school_id`.
- **Actor**: `$request->user()` (the real authenticated Sanctum user) —
  never a body-supplied actor/user id.

Not accepted from the caller, at any layer: `school_id`,
`employee_id` body override, `owner_type`, storage disk/path/object
key, `mime_type`, `size_bytes`, `uploaded_by_user_id`, `status`. Every
one of these is either derived server-side (trusted route context,
real authenticated user) or computed by the service from the actual
uploaded file (server-sniffed MIME type, real byte count) — there is
no request field that could override any of them.

Successful response: `201`, body `{"data": {...}}` where `data` is the
same safe `DocumentMetadata` shape every other endpoint returns (see
"Direct metadata" below) — no storage metadata (disk/path/key) in the
response. The create response is built directly from the
`Document` model `DocumentService::create()` returns, mapped through a
private `presentCreated()` helper that constructs a `DocumentMetadata`
inline — deliberately **not** a call to `DocumentReadService::metadata()`,
which would risk writing an unintended `document.sensitive_metadata_viewed`
audit event for a Highly Sensitive upload merely to serialize the
create response. No false sensitive-read audit is ever generated by
upload.

### Ordinary / sensitive listing

Two separate endpoints, mirroring 0E.4's two separate service methods
exactly:

- `GET .../employees/{employee}/documents` → `DocumentListingService::list()`
  — returns `public`/`internal`/`sensitive` tier Documents. `highly_sensitive`
  rows are excluded **at the SQL level** inside the Application service
  (the classification predicate lives in the same `WHERE` clause used
  for both `COUNT` and `SELECT` — 0E.4's existing hard privacy gate,
  untouched by this checkpoint).
- `GET .../employees/{employee}/documents/sensitive` → `DocumentListingService::listSensitive()`
  — returns `highly_sensitive` rows only, and requires the
  `hr.employees.sensitive.view` capability (or the equivalent source
  capability the actor's role grants) — `documents_view` alone is
  denied (`DocumentHttpListingTest::documents_view_alone_is_denied_the_sensitive_endpoint`
  and the mirrored direct-metadata/content denial tests).

**Visible-total-only pagination** is proven at the real-HTTP level, not
just at the service-unit level: seeding 20 `internal` Documents and 30
`highly_sensitive` Documents for the same Employee and paginating the
ordinary endpoint at `per_page=10` produces `meta.total = 20` on every
page (not 50), two pages of 10 real rows each, and an empty third page
— with the literal string `highly_sensitive` never appearing anywhere
in any of the three pages' raw JSON bodies
(`DocumentHttpListingTest::highly_sensitive_documents_are_completely_hidden_from_the_ordinary_http_listing`).
This is the HTTP-level restatement of 0E.4's own acceptance gate: no
hidden-row leakage through inflated totals, sparse pages, or an
extra page implying more rows exist than are ever actually returned.

### Direct metadata

`GET /documents/{document}` → `DocumentReadService::metadata()`,
response `{"data": {...}}` with the same safe `DocumentMetadata` JSON
shape used everywhere else in the module:

```
document_id, owner_type, owner_id, classification_tier, status,
original_filename, mime_type, size_bytes, uploaded_at
```

Never present: storage disk, storage path, object key, bucket, or
uploader identity — `DocumentMetadata` has never carried any of these
fields since 0E.3, and this checkpoint does not add them. A Highly
Sensitive Document's metadata remains gated by `hr.employees.sensitive.view`
(an ordinary-view-only actor gets `403`, proven by
`DocumentHttpDirectAccessTest::ordinary_document_view_alone_cannot_read_a_highly_sensitive_document_metadata`)
and a successful sensitive read writes exactly one
`document.sensitive_metadata_viewed` audit event, with no controller-level
duplicate (`DocumentHttpDirectAccessTest::highly_sensitive_metadata_is_audited_exactly_once_with_no_controller_duplicate`).

### Content delivery

`GET /documents/{document}/content` streams the Document's bytes via
`response()->streamDownload()` (Laravel/Symfony's framework-safe
helper), driven by the already-open stream `DocumentReadService::content()`
returns. The controller never performs its own Storage I/O and never
buffers the whole file in memory — the streaming callback only calls
`fpassthru($content->stream)` then closes the resource; `DocumentControllerStructuralTest`
confirms no `Storage::get(`/whole-file-read pattern exists in the
controller's source.

Safe response headers on success:

| Header | Value |
|---|---|
| `Content-Type` | The persisted, server-observed MIME type recorded at upload time |
| `Content-Disposition` | `attachment`, filename passed through Symfony's `HeaderUtils::makeDisposition()` (via `streamDownload()`'s own filename parameter) — percent-encoded/escaped, never raw-concatenated, so spaces/quotes/Unicode/control characters in a filename cannot corrupt or inject into the header (`DocumentHttpDirectAccessTest::content_disposition_is_safe_for_unusual_filenames`) |
| `Content-Length` | The persisted `size_bytes` recorded at upload time against the same object — never recomputed by buffering |
| `Cache-Control` | `private, no-store` (route-level `private-no-store` middleware, same as every other Documents route) |

The storage path/object key/disk is never exposed in any header or
body at any point on this route.

### Streaming semantic limitation (accept, do not overclaim)

`DocumentReadService::content()` proves that an **authorized storage
stream was successfully opened** — `DocumentContentUnavailableException`
is thrown at open time, before any HTTP response exists, if the
underlying object cannot be read (missing object, storage-provider
error) — so a storage failure discovered at open time always renders
as a clean JSON error, never a `200` with a broken body.

What this checkpoint's implementation **cannot** prove, and does not
claim to: that every byte was successfully delivered to the client
after HTTP headers/body transmission has begun. A mid-stream storage or
network failure after response start is not convertible into the
standard JSON error envelope by this or any HTTP layer — once headers
are sent, the response is committed. This is an inherent, bounded
transport limitation of HTTP streaming generally, not a defect
introduced by this implementation, and not something a future
implementation can fully eliminate without a fundamentally different
transfer protocol.

This is explicitly **not** described as: a completed download, a
transactionally guaranteed delivery, or a zero-risk transfer. It is
recorded here as an accepted, bounded limitation — see "Security
register" below, which does not create a new P-numbered finding for it
unless a future security review classifies it as one.

### Archive

`POST /documents/{document}/archive` resolves the Document
tenant-safely (a School-scoped `find()`, matching
`DocumentReadService::resolveAuthorizedDocument()`'s own pattern —
never an unscoped `findOrFail()`), then delegates to
`DocumentService::archive()`, which performs its own authorization,
the status transition, and the `document.archived` audit event exactly
as documented in the 0E.2 section above. Response: `204 No Content` —
no body, and deliberately no post-archive metadata read to build one,
which would otherwise risk an unintended Highly Sensitive
metadata-read audit merely to serialize a response. This is a status
transition only: no hard delete, no physical object deletion. Exactly
one `document.archived` event is written per successful call — the
controller writes none of its own.

### Error / non-enumeration policy

Every Documents domain exception now extends a new common base,
`App\Domain\Documents\Application\Exceptions\DocumentException`
(mirroring `App\Support\Idempotency\Exceptions\IdempotencyException`/
`App\Domain\AcademicStructure\Application\Exceptions\AcademicStructureException`'s
established shape), carrying its own `getStatusCode()` and a stable
`errorCode()` machine code, read generically by `bootstrap/app.php`'s
existing exception-render closure — no new per-controller try/catch,
no `bootstrap/app.php` change.

| Exception | HTTP status | Machine code |
|---|---|---|
| `DocumentNotFoundException` | 404 | `DOCUMENT_NOT_FOUND` |
| `DocumentOwnerNotFoundException` | 404 | `DOCUMENT_OWNER_NOT_FOUND` |
| `DocumentOwnerTypeNotSupportedException` | 404 | `DOCUMENT_OWNER_TYPE_NOT_SUPPORTED` |
| `InvalidDocumentClassificationException` | 422 | `INVALID_DOCUMENT_CLASSIFICATION` |
| `DocumentTooLargeException` | 422 | `DOCUMENT_TOO_LARGE` |
| `DocumentTypeNotAllowedException` | 422 | `DOCUMENT_TYPE_NOT_ALLOWED` |
| `DocumentStorageException` | 503 | `DOCUMENT_STORAGE_UNAVAILABLE` |
| `DocumentContentUnavailableException` | 503 | `DOCUMENT_CONTENT_UNAVAILABLE` |

`DocumentNotFoundException`/`DocumentOwnerNotFoundException`/
`DocumentOwnerTypeNotSupportedException` are the safe 404 family — all
three are indistinguishable from an ordinary "doesn't exist" outcome.
Notably, `DocumentOwnerTypeNotSupportedException`'s message no longer
interpolates the owner type (`"Document writes for owner type
\"{$ownerType}\" are not yet activated"`, the old Application-layer-only
wording): now that this exception is HTTP-transport-reachable (via a
raw fixture/seeded row whose `owner_type` isn't activated for reads —
the write path never produces one), its message was changed to the
generic `"No Document with that id was found in this School."` so an
HTTP caller can never learn a protected Document's owner type merely
from a 404 body. `$ownerType` remains available as a property for
internal/log use only.

Authorization failures use the framework's existing global
`Illuminate\Auth\Access\AuthorizationException` → `403` mapping — the
same one every other capability-gated endpoint in the codebase already
uses. This checkpoint does **not** collapse 403 into 404 for
"indistinguishability": the API deliberately returns 403 for an
authenticated, correctly-scoped actor who lacks the required
capability (proven by `DocumentHttpDirectAccessTest::ordinary_document_view_alone_cannot_read_a_highly_sensitive_document_metadata`/
`..._content`), and 404 only for the genuinely non-enumerable cases
above (wrong/nonexistent/cross-School id). Document the actual
behavior: 403 and 404 are both used, deliberately, for different
reasons — this is not a claim of blanket status-code
indistinguishability.

In all cases, error response bodies never disclose classification
tier, filename, storage information, or the existence of a
foreign-School resource.

### Cache control

The `private-no-store` middleware is applied to all six Documents
routes (the same middleware `EmployeeSensitiveDocumentController` and
the rest of the HR/Academic Structure routes already use). Every
successful metadata/content/list/archive response carries
`Cache-Control: private, no-store`, with no contradictory
public/shared directive. Tested 403 and 404 responses on the direct
Document routes also preserve `private, no-store`
(`DocumentHttpCacheControlTest`) — this checkpoint does not assert or
rely on any repository-wide pre-middleware behavior for 401/429
responses that wasn't already independently true.

### Rate limiting

Four named limiters, registered in `RateLimiterServiceProvider`,
keyed by School + authenticated actor via the same `tenantKey()`
convention every other tenant-aware limiter in this codebase uses
(rule 61) — never IP-only for an authenticated School API:

| Limiter | Limit | Applied to |
|---|---|---|
| `documents-reads` | 120/min | Ordinary Employee document listing |
| `documents-sensitive-reads` | 20/min | Sensitive Employee document listing, and the direct-by-id metadata route (`GET /documents/{document}`) uniformly — the route cannot know a Document's classification tier before the service resolves it, so the stricter bound is applied regardless of the actual tier, avoiding a side channel that would otherwise let rate-limit behavior itself reveal hidden classification |
| `documents-content` | 20/min | Streamed content download — its own dedicated, equally strict bound, since transferring file bytes is more expensive than any metadata-only read |
| `documents-writes` | 30/min | Upload and archive |

A request beyond the limit returns the standard `429` envelope with a
`Retry-After` header present (`DocumentHttpRateLimitTest::a_429_uses_the_standard_envelope_with_retry_after`),
and buckets are isolated per School — one School's actors exhausting a
limiter does not affect another School's. These are request-rate
limiters only, not storage quotas — they say nothing about how much
data a School may store.

### Audit

The controller writes zero audit events of its own
(`DocumentControllerStructuralTest::the_controller_never_writes_an_audit_event_directly`).
Every event below is written exactly once per successful triggering
call, entirely inside the Application service layer, unchanged from
0E.2/0E.3/0E.4:

| Event | Written by | When |
|---|---|---|
| `document.created` | `DocumentService::create()` | Every successful upload |
| `document.archived` | `DocumentService::archive()` | Every successful archive |
| `document.sensitive_list_viewed` | `DocumentListingService::listSensitive()` | Once per successful non-empty sensitive listing call |
| `document.sensitive_metadata_viewed` | `DocumentReadService::metadata()` | Once per successful Highly Sensitive direct metadata read |
| `document.sensitive_content_accessed` | `DocumentReadService::content()` | Once per successful Highly Sensitive content stream open |

`document.sensitive_content_accessed` means the storage stream was
successfully **opened** with authorization already confirmed — it does
not mean, and must never be represented as meaning, that the download
completed (see "Streaming semantic limitation" above).

### OpenAPI / generated types

`packages/contracts/openapi/school-os-api.yaml` gained five new path
items covering the six operations above (`POST`+`GET` share the
`.../employees/{employee}/documents` path item as two operations under
one path), a `Document` schema, a `DocumentId` path parameter
component, a documented `multipart/form-data` upload request body, and
a documented `type: string, format: binary` response for the content
route. YAML validation: **pass**. Codegen (`npm run generate` in
`packages/shared-types`): **pass**, with **no generated drift** — the
committed `packages/shared-types/src/generated/school-os-api.ts` matches
a fresh generation exactly. `packages/shared-types`' own `type-check`:
**pass**. `apps/platform`'s `vue-tsc` type-check: **pass**. Generated
TypeScript remains generator-authoritative — no hand edits were made
to the generated file.

### Routing

Exactly six new Documents transport routes were added (enumerated
above). The pre-existing HR route
`/employees/{employee}/sensitive-documents` remains separate and
unchanged — see "Endpoint inventory" above for why no collision is
possible. No route exists at this checkpoint for: Student Documents,
Guardian Documents, delete, generic/global search, or a signed/
presigned URL.

### Real HTTP validation

0E.5 was validated against a real `artisan serve` HTTP server, a real
Sanctum bearer token, real PostgreSQL, and real MinIO object storage —
not PHPUnit's in-process test client alone. Validated over real HTTP:
upload, ordinary listing, sensitive listing, direct metadata, binary
content download, archive, cross-School denial, malformed-UUID
handling, rate limiting (`429` + `Retry-After`), and `private, no-store`
cache headers. `DocumentHttpMinioIntegrationTest` specifically exercises
the real MinIO backend rather than `Storage::fake()`, to prove the
streaming content path works against the actual object-storage
provider this environment uses, not just an in-memory fake.

### Security register (0E.5)

| Risk | Severity | Resolution |
|---|---|---|
| Controller-level authorization/storage/audit bypass of the 0E.2–0E.4 service layer | P0 if ever introduced | Structurally prevented and continuously proven by `DocumentControllerStructuralTest` (source-grep for `Storage::`/`Gate::authorize`/`AuditRecorder`/etc.) — the controller has no code path capable of it. |
| Raw PostgreSQL UUID error (500) from a malformed route parameter | P1 if unaddressed | `Str::isUuid()` guard rejects malformed `{employee}`/`{document}` before any query runs, on every one of the six routes; proven by `DocumentHttpDirectAccessTest::a_malformed_document_uuid_is_a_safe_404_not_a_raw_sql_error`. |
| Owner-type disclosure via a 404 error message | P2 if unaddressed | `DocumentOwnerTypeNotSupportedException`'s message no longer interpolates `$ownerType` now that it is HTTP-reachable. |
| Storage disk/path/object-key/uploader-identity leakage via any response header or body | P2 if unaddressed | `DocumentMetadata` (0E.3) never carries these fields; content-route headers are limited to `Content-Type`/`Content-Disposition`/`Content-Length`/`Cache-Control`, none of which expose storage location. |
| Rate-limit bucket revealing hidden classification tier via differing limits per document | P3 if unaddressed | The direct metadata route uses the single stricter `documents-sensitive-reads` bound uniformly, regardless of the resolved Document's actual tier — no per-tier limiter exists to create the side channel. |
| Whole-file buffering causing memory exhaustion on large Documents | P3 if unaddressed | `streamDownload()`/`fpassthru()` only — no `Storage::get()`/buffering pattern exists in the controller (`DocumentControllerStructuralTest`). |
| Mid-stream transport failure after HTTP headers are sent | Bounded transport limitation, not a P-numbered finding | Documented above ("Streaming semantic limitation") as an inherent, accepted characteristic of HTTP streaming — not silently reclassified as a security defect absent an actual security-review finding to that effect. |
| 0E.2 compensation-cleanup residual (private orphan object, no metadata row) | P3 (carried forward, unchanged) | Unaffected by 0E.5 — not represented by `DocumentMetadata`, not addressable, not listable, not exposed through any of the six new routes. |

Checkpoint result: **P0: 0, P1: 0, P2: 0, P3: 1, P4: 0.** The single
carried P3 is the 0E.2 compensation-cleanup residual — no new P3 was
introduced by this checkpoint's own implementation, and the HTTP
streaming limitation above is recorded as a documented transport
limitation rather than manufactured into a second P3 absent an actual
security-review finding to that effect.

### What 0E.5 still does not do

No Student Documents operations (upload, read, or listing), no
Guardian Documents operations, no signed/presigned URLs, no HTTP
`Range`/`206` partial-content support, no global/cross-owner search, no
filename search, no UI, no retention policy, no malware scanning, no
checksum verification, no orphan cleanup/reaper job, no
`employee_documents` reconciliation, no Invoice owner-type support, and
no storage quota enforcement. None of these are implemented at this
checkpoint, and none should be inferred from anything above.

### Test strategy (0E.5 addition)

Authoritative selector, added to the existing suite:

```
php artisan test tests/Feature/Documents
```

New files: `DocumentControllerStructuralTest.php` (transport-only
proof — no Storage/authorization/audit calls, no signed URL, no Range
support, no whole-file buffering), `DocumentHttpUploadTest.php`,
`DocumentHttpListingTest.php` (including the 20-ordinary/30-Highly-
Sensitive visible-total-only HTTP proof), `DocumentHttpDirectAccessTest.php`
(metadata/content, 403 vs 404, malformed UUID, cross-School denial,
audited-once proofs), `DocumentHttpArchiveTest.php`,
`DocumentHttpCacheControlTest.php`, `DocumentHttpRateLimitTest.php`
(all four limiters, 429 envelope, `Retry-After`, per-School isolation),
and `DocumentHttpMinioIntegrationTest.php` (real MinIO, not
`Storage::fake()`).

Results carried forward from the implementation pass (documentation-only
continuation — not rerun for this edit, since no code/test/contract
file changed):

- Documents suite: **186 tests / 821 assertions / 0 failures**
- Full regression: **2331 tests / 7364 assertions / 0 failures**
- Pint: **pass**
- PHPStan: **pass**
- OpenAPI validation + generation + drift check: **pass, no drift**
- `packages/shared-types` type-check: **pass**
- `apps/platform` `vue-tsc` type-check: **pass**
- Real HTTP smoke (artisan serve + Sanctum + PostgreSQL + MinIO): **pass**

## Next checkpoint boundary

Not yet decided — to be established from ADR 0012 + this document's
own as-built state after 0E.5 is reviewed. Likely remaining
architectural gaps (explicitly not assigned to any checkpoint yet):
signed/temporary URLs (if ever justified beyond the internal stream),
global/cross-owner search, retention, malware
scanning, checksum, the `employee_documents` reconciliation, and a
deliberate Student/Guardian capability decision for write, read, and
listing activation.
