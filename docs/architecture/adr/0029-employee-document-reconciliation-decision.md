# ADR 0029: Employee Document Reconciliation Decision

- Status: Accepted
- Date: 2026-08-26

## Context

ADR 0028 (Phase 8A HR Resequencing) built HR ahead of Phase 0E because
no shared Documents module existed yet, and explicitly recorded a debt:

> Phase 0E, whenever it is eventually built, inherits an explicit,
> written obligation (this ADR) to reconcile `employee_documents`
> rather than silently ignoring it or duplicating its concerns.

`docs/roadmap/MASTER-ROADMAP.md`'s Phase 0E entry has carried "the
promised `employee_documents` reconciliation from ADR 0028" as an
explicitly-named open item ever since. Phase 0E.1's `documents` table
migration and Phase 0E.5's `docs/modules/DOCUMENTS.md` both flagged the
same debt again at every checkpoint rather than reopening it
prematurely. Now that the generic Documents module has a complete
write/read/list/HTTP surface (0E.1–0E.5), this ADR is where that
obligation is discharged: not by merging the tables, but by making — and
recording — the actual decision, with evidence, the way ADR 0028 asked
for.

### What actually exists on both sides, verified against the current codebase

| | `documents` (generic, Phase 0E) | `employee_documents` (HR, Phase 8A.7) |
|---|---|---|
| Purpose | Any owning entity's stored file, generically | HR-specific structured evidence catalog (id proof, qualification evidence, background-check evidence, ...) |
| Real file bytes | Yes — the only Employee-file system in this repository that has ever called `Storage::` (`DocumentService`/`DocumentReadService`) | **Never** — confirmed structurally: no `Storage::` call exists anywhere in `App\Domain\HR\Application\EmployeeDocumentService`'s source (`EmployeeDocumentTest::employee_document_service_has_no_file_upload_download_or_storage_write_capability`), and no Phase 8A checkpoint (8A.7 through 8A.16, closed) ever added one |
| Classification vocabulary | Canonical four-tier `public`/`internal`/`sensitive`/`highly_sensitive` (`docs/security/DATA-CLASSIFICATION.md`) | Narrower `restricted`/`highly_sensitive`-only, DB-CHECK-enforced — deliberately excludes `public`/`internal`/`directory` since no real HR document is ever that low-sensitivity |
| Employee-owner authorization | `hr.employees.documents.manage`/`.view` (ordinary), `hr.employees.sensitive.manage`/`.view` (Highly Sensitive) — **the exact same capability pair HR already uses**, deliberately reused by `DocumentService`/`DocumentReadService`/`DocumentListingService` rather than inventing a parallel policy | Same capability pair, native |
| Structured fields | None beyond safe metadata | `category`, `issued_on`/`expires_on` (expiry tracking for licences/ID proofs), `update()` (category/tier correction) |
| Audit events | `document.created`/`.archived`/`.sensitive_list_viewed`/`.sensitive_metadata_viewed`/`.sensitive_content_accessed` | `hr.employee_document.created`/`.updated`/`.archived` |
| Lifecycle | `active`/`archived`, no hard delete | `active`/`archived`, no hard delete |
| Storage-path derivation | `TenantStoragePath::for()`, server-derived only | `TenantStoragePath::for()`, server-derived only |
| API/HTTP surface | 0E.5 (six routes, Employee owner only) | None — no controller/route exists for `employee_documents` at all |

The authorization boundary was already unified for Employee owners
before this ADR (0E.2's `DocumentService::employeeDocumentCapability()`
docblock records this explicitly). What was never decided is whether
the two **tables** should become one, and — the actual practical
question a future engineer will hit — where a *new* real Employee HR
document upload capability should be built if HR ever needs one.

## Decision

**`employee_documents` and `documents` remain two permanently separate
tables. No data migration, no schema merge, no shared foreign key
between them is introduced by this ADR or planned as a result of it.**
Like every ADR in this repository, this decision is binding unless and
until a future ADR explicitly supersedes or amends it — it is not
scoped "for now" or "until reconciliation," and no such supersession is
currently planned.

This is not "reconciliation deferred again" — it is the reconciliation
decision ADR 0028 asked for, and it is a decision, not a non-decision,
for the following reasons:

1. **There is nothing to migrate.** `employee_documents` has never held
   a real stored file — verified structurally (no `Storage::` call has
   ever existed in `EmployeeDocumentService`, across all of Phase 8A).
   A "migration" would move rows describing files that were never
   actually written to any disk under this application's control. The
   supposed migration risk section 10 of the checkpoint brief warned
   about (dual-write, storage-path incompatibility, classification
   mapping during a live cutover) does not apply here, because there is
   no live file content on either side of a cutover to lose or
   corrupt.
2. **The two tables answer different questions.** `documents` answers
   "what file is attached to this owning entity, and can I read/stream
   it." `employee_documents` answers "what structured HR compliance
   evidence exists for this Employee, does it expire, what category is
   it" — `category` and `issued_on`/`expires_on` are real, load-bearing
   HR fields with no equivalent in the generic module, and adding them
   generically would be exactly the kind of Employee-specific
   speculative field CLAUDE.md rule 2 forbids on a cross-domain shared
   table (a Student's document has no "licence expiry").
3. **The classification vocabularies are deliberately different, not
   accidentally out of sync.** 0E.1's own migration docblock already
   recorded why: `employee_documents`' `restricted`/`highly_sensitive`
   floor is correct for HR's Sensitive-or-higher baseline, but would be
   wrong as the generic module's only vocabulary (a Public school event
   photo needs a real `public` tier `employee_documents` structurally
   cannot express). Forcing one shared vocabulary would either weaken
   HR's floor or make the generic module unable to hold genuinely
   low-sensitivity files for other owners.
4. **Merging now would not reduce risk, it would introduce it.** A
   forced schema merge to close a documentation debt, for tables that
   already share their real security boundary (capability names) and
   have no real data-loss exposure, is the "premature refactor of
   stable architecture without a genuine defect" section 19 of the
   checkpoint brief explicitly warns against.

### What this ADR actually commits the codebase to going forward

- `employee_documents` remains HR's own structured, metadata-only
  compliance-evidence catalog. It is not deprecated, and this ADR does
  not schedule its removal.
- **If HR ever needs to store real file bytes for an Employee document**
  (the one gap `employee_documents` has always had, by design, since
  8A.7), that capability MUST be built on `DocumentService`/
  `DocumentReadService` (the generic Documents module), using the
  Employee owner arc `documents` already supports — never by adding a
  new `Storage::` call directly into `EmployeeDocumentService` or any
  other HR class. This is the concrete, enforceable form of ADR 0012's
  "no module reads or writes object storage directly" rule, applied to
  the one place HR has historically been tempted to special-case it
  (the 8A.7 migration's own docblock already flagged this as the
  reason a shared module didn't exist yet — it now does).
- `employee_documents.category`/`issued_on`/`expires_on` remain HR-only
  fields. If a future cross-domain need for expiring documents emerges
  for a non-Employee owner, that is evaluated as its own generic
  Documents feature at that time — not solved retroactively by this
  ADR.
- The two independent audit trails (`hr.employee_document.*` vs
  `document.*`) both remain on `AuditRecorder`/`SchoolAuditEvent` (no
  second audit table either side) and are not merged or cross-linked.
- `App\Domain\HR\Application\EmployeeProfileWorkspaceService`'s existing
  `employee_documents` read (part of the Employee profile aggregate)
  and the generic Documents module's Employee-scoped read/list/content
  endpoints (0E.3–0E.5) both continue to exist, independently, for
  their own distinct purposes (structured compliance-evidence summary
  vs. general file storage/retrieval). This ADR does not unify them
  into one read surface.

## Consequences

- `docs/roadmap/MASTER-ROADMAP.md`'s Phase 0E entry is updated to
  reference this ADR instead of carrying "the promised reconciliation"
  as an open item — the obligation is discharged by this decision, not
  by further code.
- `docs/modules/HR.md`'s `employee_documents` section gains a
  cross-reference to this ADR (HR.md is ADR 0028's own designated
  "binding reference" for Phase 8A decisions — the reconciliation
  decision must be visible from there too, not only from
  `docs/modules/DOCUMENTS.md`).
- A permanent structural regression test (both directions — HR's
  `EmployeeDocumentService` never touches `documents`, and the generic
  Documents module never touches `employee_documents`) encodes this
  decision so a future change cannot silently drift from it without a
  test failure forcing a deliberate ADR update.
- Nothing about Phase 0E's remaining deferred scope changes: Student/
  Guardian owner activation, signed URLs, retention, malware scanning,
  checksum, orphan cleanup, and storage quota are all untouched by this
  ADR and remain exactly as deferred as `docs/modules/DOCUMENTS.md`
  already records.

## Alternatives considered

1. **Migrate `employee_documents` rows into `documents`, mapping
   `restricted` → `sensitive`.** Rejected: there are no real files to
   migrate (see Decision, point 1), so this would move inert metadata
   rows for no operational benefit while permanently losing
   `category`/`issued_on`/`expires_on` (no equivalent columns exist on
   `documents`, and adding them there would be the wrong-direction
   speculative-field problem — see point 2).
2. **Add a `document_id` FK from `employee_documents` to `documents`,
   letting HR reference a generic Document for its actual bytes while
   keeping HR-specific fields locally.** Rejected as this ADR's design:
   no real upload capability for `employee_documents` exists today, so
   this FK would reference nothing, and this ADR does not schedule
   adding one. This section records the shape a *future* ADR would
   most plausibly take **if** a genuine requirement for real
   `employee_documents` file storage ever emerges — such an ADR would
   need to explicitly supersede or amend ADR 0029, informed by whatever
   real requirement triggers it, and would still reuse `DocumentService`
   directly (this ADR's own forward guidance) rather than a bespoke
   FK-plus-separate-write-path hybrid. Naming that shape here is not
   the same as planning or scheduling it.
3. **Do nothing and leave the obligation open indefinitely.** Rejected:
   this is exactly the "silently ignoring" outcome ADR 0028 wrote down
   in advance as unacceptable — an explicit decision, even a "stay
   separate" one, is required, and this ADR is that decision.
4. **Unify the two audit-event vocabularies
   (`hr.employee_document.*`/`document.*`) into one shared set now.**
   Rejected: no consumer of either audit trail currently needs
   cross-referencing them, and forcing one vocabulary would require
   rewriting either HR's or Documents' already-shipped, tested audit
   contract for a benefit no current requirement names.

## Future extraction/evolution path

This ADR's separation is binding unless and until a future ADR
explicitly supersedes or amends it — it is not a placeholder awaiting a
scheduled merge. If a real cross-domain need for HR file uploads ever
emerges, the path is already fixed by this ADR: build it against
`DocumentService`'s Employee owner arc, not a new HR-local storage
integration. Alternative 2 names the FK-referencing shape
`employee_documents` would most plausibly evolve toward in that case,
but adopting it is a decision for that future ADR to make, informed by
whatever real requirement triggers it — not something this ADR plans,
schedules, or treats as the eventual default outcome.
