# SIS 11.5 — Student Compliance, Documents & Verification Hub

Status: **IMPLEMENTED (local DDEV)** · 2026-10-31 · branch `integration/sis-11-5`

SIS 11.5 adds a safe compliance layer to the existing Documents
architecture (ADR 0012, SIS.EXP-3). It adds no second file store, no
document directory and no content search. The core SIS score stays 96/100.

## 1. Owner decisions (2026-10-31)

| # | Decision |
|---|---|
| D1 Capabilities | **`students.documents.verify`** (school_admin, principal) verifies or rejects. **`admissions.documents.view` / `.manage`** (school_admin, principal, admissions_officer) cover pre-admission documents, Campus-scoped by the application's own Campus like the rest of pre-conversion Admissions. Student documents stay whole-School. Requirement rules use `students.documents.manage`. After conversion, admissions staff get **no** Student-document access. |
| D2 Types | New **Birth certificate** type (proof of age for admission). It is a civil record: no identifier is captured, and it is Highly Sensitive like every Student document. Requirements may target exactly: birth certificate, prior school record, enrollment paperwork and consent form. There are no health, photo or identity-document types. |
| D3 Retention | Applicant documents follow the existing Admissions E21 retention. On conversion they **become the Student's documents** (the same Document row and file, never a copy). A rejected or withdrawn application's documents leave with its applicant, one year after `terminal_at`, through the existing prune. |
| D4 Cohort checklist | Visible only to holders of whole-School `students.documents.view` (with whole-School `students.view`) and current MFA. Campus readers never see it. |

## 2. Architecture

```
student_document_requirements (rules; one writer)
        │  applicability: rule year × the Student's non-cancelled placements
        ▼  (Campus/Grade when set; ends_on ≥ effective_from)
DocumentCompliance (derived, never stored)
        │  per category: newest non-archived document → missing / pending / verified / rejected
        ├── Student documents page (one Student)
        ├── Applicant documents page (one application)
        └── DocumentComplianceCohort (cohort checklist; states only)

documents (ONE repository; exclusive-arc owner: … student | applicant | …)
   ├── student_documents   (Students; + verification, source_applicant_document_id)
   └── applicant_documents (Admissions; moved_to_student_* on conversion)
```

- **Ownership.**
  - Students owns rules, verification of Student records and compliance.
  - Admissions owns `applicant_documents` through `ApplicantDocumentService`.
  - Documents owns the bytes, through these entry points only:
    `createForApplicant`, `archiveForApplicant`, `applicantContent`,
    `moveApplicantDocumentsToStudent`.
  - Students never depends on Admissions: `adoptFromApplicant()` takes
    plain values.
- **Derived, never stored.** There is no `compliant` flag, score or
  percentage. A retired rule stops applying at once, and its evidence stays.
  A Student who left before a rule's `effective_from` is never out of
  compliance with it.
- **Verification is evidence quality, not authority.**
  - States are `pending` → `verified`, or `pending` → `rejected` with a closed
    reason (illegible, wrong document, incomplete, not valid, wrong person).
  - A decision is final. A rejected document is fixed by uploading a
    replacement, which supersedes the old one (`superseded`).
  - Each decision needs `students.documents.verify` (whole School), a fresh
    MFA code and the record version the decider saw (stale = refused).
  - Each decision is audited (`student_document.verified` / `.rejected`,
    `applicant_document.verified` / `.rejected`) with ids and closed codes
    only.
  - The database enforces the shape of each state (decider and time
    present, reason only on rejection).
- **Advisory.** Nothing refuses acceptance, conversion, placement or
  rollover because of a missing or rejected document.

## 3. Pre-admission documents and conversion

- A record is bound to the **one application** it was collected through
  (`admission_application_id`, with a database key to an application of the
  same Applicant and School). When one Applicant applies on two Campuses, a
  Campus actor never sees what the other Campus's application collected.
- Only an **open** application (draft, submitted, accepted) collects
  documents. A closed one is read-only.
- On conversion (inside `AdmissionConversionService`'s transaction),
  `ApplicantDocumentService::moveToStudent()` runs these steps:
  1. Lock the Applicant's current records.
  2. Documents re-points each Document's owner from applicant to Student
     (the same row and storage path).
  3. Students creates a `student_documents` row carrying the verification
     and `source_applicant_document_id`.
  4. The applicant record is marked moved (final).
  5. One `applicant_document.moved_to_student` audit is written (a count,
     no titles).
- Archived or superseded applicant history stays with the Applicant and
  follows Admissions retention.
- The Admissions page then shows only a count of moved documents. Admissions
  staff cannot list or download a moved file, because its owner is now the
  Student.

## 4. Authority matrix

| Surface | Authority | Assurance |
|---|---|---|
| Student documents page, download | whole-School `students.view` + `students.documents.view` | `mfa-page` |
| Student upload / replace / edit / archive | + `students.documents.manage` | fresh MFA code |
| Verify / reject (Student or Applicant) | + `students.documents.verify` (whole School) | fresh MFA code |
| Requirement rules (list, add, retire) | whole-School `students.documents.manage` | — (no personal data) |
| Applicant documents page, download | `admissions.documents.view` covering the application's Campus | `mfa-page` |
| Applicant upload / replace / archive | `admissions.documents.manage` covering the application's Campus | fresh MFA code |
| Cohort checklist | whole-School `students.view` + `students.documents.view` | `mfa-page` |

- An application outside the actor's Campus scope, or in another School, is
  **NOT FOUND**.
- Teachers, librarians, Guardians and platform, Group and Network actors
  reach nothing.

## 5. Cohort checklist

- Route: `/app/students/document-compliance`. Registered as the governed
  page entry `students.document_compliance` in `StudentReportRegistry`
  (`WHOLE_SCHOOL`, SR-21/SR-14). It is a declared whole-School operation of
  `students.view`.
- Rows: Students with a non-cancelled placement in one academic year (by
  default the active one).
- Filters: Campus, Grade and Section of the latest such placement; document
  type; state; the shared name/number search. A category or state filter
  narrows in SQL with the same applicability and current-document rule as
  `DocumentCompliance`. A foreign id matches nothing.
- Output: states, required/optional, and "N of M required verified". Never a
  title, file name, date, document id or download. A Student's name links to
  their own documents page, which carries the same MFA gate.
- **Browser-only.** It is not in `StudentReportRegistry::EXPORTERS`, so a
  CSV or print request is NOT FOUND. There is no percentage, trend or chart
  (ADR 0040).
- Bounded: a constant number of queries per page, independent of cohort
  size.

## 6. Schema (migration `2027_01_21_090000_add_document_compliance`)

**`documents`**
- `applicant_id` with a composite `(applicant_id, school_id)` key
  (cascade).
- The exclusive-arc owner check now has six owners.

**`student_documents`**
- New columns: `verification_status`, `verified_by_user_id` (restrict),
  `verified_at`, `rejection_reason` and `source_applicant_document_id`
  (unique; provenance only, no key).
- The category check now includes `birth_certificate`.
- Shape checks on verification.

**`applicant_documents`** (Admissions)
- Composite same-School keys. `(admission_application_id, applicant_id,
  school_id)` references a new unique key on `admission_applications`
  (cascade with the application).
- `moved_to_student_id` uses `ON DELETE SET NULL (moved_to_student_id)`
  (rule 97).
- Checks on the closed category, archive reason, title, version and the
  archive, move and verification shapes.
- The `applicant_documents_guard` trigger:
  - identity is immutable;
  - archived and moved records are final;
  - every change advances `version` by one;
  - the only exception is the retention SET NULL.
- RLS; runtime DELETE revoked.

**`student_document_requirements`**
- Closed subject and category; composite keys to year, Campus and Grade.
- An immutable-terms trigger; retired once.
- RLS; runtime DELETE revoked.

**Down migration.** `down()` refuses while applicant documents or birth
certificates exist (the data cannot be returned to the old shape).
Otherwise it reverses everything.

## 7. Retention

- `applicant_documents` is in the `admissions` family: it cascades with its
  application and Applicant. Whenever a retention unit deletes applications
  (`ApplicantDocumentRetention`), the Documents still owned by the Applicant
  and collected through them go too, even while the Applicant stays for
  another live application. `TerminalApplicationRetentionService` and
  `ConvertedApplicationRetentionService` purge the Applicant's Documents
  through `DocumentParentRetention::purgeWithOwner('applicant')`, and delete
  the stored objects after commit.
- `student_document_requirements` is School configuration (tenant
  lifetime).
- New user references are historical (`RETAIN_REFERENCE`): verifier,
  uploader, archiver, rule author and retirer.

## 8. Not built (brief exclusions, unchanged)

- Not built:
  - a School-wide document directory;
  - full-text or content search;
  - biometric, health, immunisation or medical files;
  - photos;
  - government identifiers (Aadhaar, passport, visa, FRRO);
  - Guardian self-service upload;
  - e-signature, a contract engine, or expanded certificate generation;
  - Documents exports, bulk ZIP or public links;
  - Finance concession evidence;
  - automatic Communications reminders;
  - Data Health checks for missing documents;
  - compliance trends or percentages.
- Malware scanning is still absent (as in SIS.EXP-3). Upload copy says so.
