# ADR 0012: File/Document Domain Architecture

- Status: Accepted
- Date: 2026-08-22

## Context

ADR 0011 fixes the underlying storage *technology* (S3-compatible
object storage). This ADR addresses the layer above it: how a file
relates to a domain entity (a student's admission document, a fee
receipt, an exam script), how access to it is authorized, and how it's
classified/retained — because "just call the S3 SDK from wherever" is
how file access-control bugs happen in ERPs that handle children's
records.

## Decision

Files are modeled as first-class **Document** records owned by a
(future) `Documents` module, not as bare object-storage keys scattered
across other modules' tables:

- Every stored file has a corresponding **Document row**: tenant id,
  owning entity reference (polymorphic: student, guardian, employee,
  invoice, ...), storage path, data-classification tag (see
  `docs/security/DATA-CLASSIFICATION.md`), uploader, and audit
  timestamps.
- **No module reads or writes object storage directly.** A module that
  needs to attach a file to one of its records goes through the
  Documents module's Application-layer contract, the same way any
  other cross-module interaction must (see
  `docs/architecture/DOMAIN-MAP.md`).
- File **download access is always authorized per-request** (a
  signed/short-lived URL or an authenticated streaming endpoint) —
  never a permanently public bucket URL for anything above the
  "Public" data classification tier.
- Storage paths are namespaced by tenant (`{tenant_id}/...`) so a
  storage-level audit or migration can reason about tenant boundaries
  even though the bucket/credential is shared (ADR 0011).

## Rationale

- Centralizing file metadata in one Documents module gives one place to
  enforce classification-aware access control, retention, and audit,
  instead of every module reinventing "who can see this file" logic
  independently and inconsistently.
- Treating the object-storage key as private to the Documents module
  (not something other modules pass around and construct URLs from
  directly) prevents the class of bug where a file becomes reachable
  through a guessable or unauthenticated path.
- School data includes children's documents (birth certificates,
  photos, health records) — the highest-sensitivity data classes this
  product handles (`docs/security/DATA-CLASSIFICATION.md`). Centralized,
  consistently-authorized file access is the architectural control that
  makes that classification meaningful in practice, not just on paper.

## Alternatives considered

1. **Each module manages its own file uploads/storage paths
   independently** (e.g. Admissions handles its documents, HR handles
   its own separately, with no shared Documents module). Rejected:
   guarantees inconsistent access-control and retention logic across
   modules, and duplicates a lot of near-identical code.
2. **Publicly readable bucket with obscure/random paths as the only
   protection ("security by obscurity").** Rejected outright — not an
   acceptable control for children's data or financial documents.
3. **Store small files as base64 in Postgres alongside their owning
   record.** Rejected: reintroduces the BLOB-in-database problems ADR
   0011 already ruled out, without even the benefit of a shared file
   model.

## Consequences

- The Documents module becomes a dependency for every module that
  handles file uploads — this is an intentional, accepted coupling
  (matches the dependency-direction conventions in
  `docs/architecture/DOMAIN-MAP.md`).
- Every file access path must resolve authorization (tenant + capability
  + classification) before generating a URL or streaming bytes — this
  is a mandatory review checklist item once the Documents module is
  implemented.
- No implementation exists yet in Phase 0A; this ADR fixes the shape
  future implementation must follow.

## Future extraction/evolution path

If file volume/traffic later justifies it, the Documents module's
Infrastructure layer (not its Application contract) could be optimized
independently (e.g. a CDN in front of authorized, time-limited URLs)
without changing how other modules interact with it.
