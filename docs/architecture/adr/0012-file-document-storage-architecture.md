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

## Amendment — Student owner activation (SIS.EXP-3, 2026-10-30)

**Status:** Accepted (owner decision OD-SIS-1, 2026-10-30, including the
two new capabilities and their role grants).

Phase 0E deferred the `student` owner arm because no capability named
Student documents and `students.view`/`students.manage` must not be
stretched to cover them. That reasoning stands. SIS.EXP-3 activates the arm
through dedicated authority rather than reinterpretation:

1. **Two new School capabilities:** `students.documents.view` and
   `students.documents.manage`.
   - Granted only to the `school_admin` and `principal` system roles.
   - Whole-School only and never Campus-adopted.
   - Classed `children`; they map to the core Student Records module through
     the `students.` prefix.
   - No Teacher, Guardian, Group, Network or platform role holds them.
2. **Narrow entry points, never the generic ones:**
   - `DocumentService::createForStudent()` / `archiveForStudent()` write
     the file, and `DocumentReadService::studentContent()` reads it.
   - `DocumentListingService::studentFacts()` reads the file facts.
   - The generic `create()`, `archive()`, `metadata()`, `content()` and
     `list()` keep refusing `student`. No generic route, and no API client,
     can therefore reach a Student file.
   - Each entry point decides the capability itself. The Students domain
     decides Student visibility (whole-School `students.view`) first.
3. **One tier and a narrow type list:**
   - Every Student-owned Document is `highly_sensitive` (children's data).
     Reads need current MFA; writes need a fresh code; opened streams and
     listings are audited.
   - Accepted types are PDF, JPEG and PNG only, a subset of the Documents
     allow-list. There is no malware scanning, so no macro-capable format is
     accepted, and never SVG.
4. **Domain boundary:**
   - The Students domain owns `student_documents`: the closed category, a
     plain title, a document date and the record's archive with its closed
     reason. It is written inside the Document's own transaction (a
     `$within` callback), so storage compensation covers both.
   - A composite key `(document_id, student_id, school_id)` →
     `documents(id, student_id, school_id)` keeps the record on the
     Document's own Student and School.
   - Documents never reads a Student model, and Students never reads the
     `documents` table.
5. **Retention:**
   - The record cascades with its Document and Student inside the Student
     core purge (E21.2D, D7). It is never removed on its own.
   - The runtime role cannot DELETE it, and archive is final.

Unchanged: the Guardian arm stays deferred. There is still no generic
`documents.*` capability, signed or public URL, share link, OCR or AI
processing. `docs/modules/DOCUMENTS.md` ("Student owner activation") and
`docs/modules/STUDENT-GUARDIAN-IDENTITY.md` ("Student documents") hold the
detail.

## Amendment — Applicant owner and document compliance (SIS 11.5, 2026-10-31)

**Status:** Accepted (owner decisions 2026-10-31).

1. **A sixth owner arm, `applicant`:**
   - `documents.applicant_id`, with a composite `(applicant_id, school_id)`
     key, cascading with the Applicant.
   - Reached only through `createForApplicant()`, `archiveForApplicant()`
     and `applicantContent()`. Each caller (Admissions'
     `ApplicantDocumentService`) authorizes the application's Campus scope
     first. The generic paths and every API keep refusing it.
   - `highly_sensitive`, with PDF/JPEG/PNG only, like the Student arm.
2. **Owner re-point, never a copy:**
   - On admission conversion, `moveApplicantDocumentsToStudent()` moves
     the Applicant's current Documents to the new Student, inside the
     conversion transaction: the same row and storage path, with the owner
     columns swapped.
   - This is the only owner change the repository allows, and only
     applicant → Student of the same School.
3. **Retention:** the Applicant arm inherits Admissions retention (E21.3B/C)
   through `DocumentParentRetention::purgeWithOwner('applicant')`, with the
   stored objects deleted after commit.
4. **Unchanged:** there is still no generic `documents.*` capability, signed
   or public URL, share link, OCR, AI, content hash or malware scanning. The
   Guardian arm stays deferred.
