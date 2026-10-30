# ADR 0081: Student Leaving and Transfer Certificates (SIS.EXP-4)

- Status: **Accepted and implemented for development (2026-10-30).**
  Issuing, voiding and reissuing are **refused in production** until the
  certificate wording is legally approved (§7).
- Date: 2026-10-30
- Programme: **SIS.EXP — Student Information System expansion**
  (`docs/sis/SIS-EXPANSION-ROADMAP.md`, wave SIS.EXP-4).
- Owner decisions:
  - OD-SIS-2.1–2.7, from the brief;
  - and two decisions made during the session, both 2026-10-30:
    - server-side PDF through **dompdf**, Latin script only;
    - one new capability, **`students.certificates.issue`**, granted to
      `school_admin` and `principal`.
- Builds on: ADR 0012 and its SIS.EXP-3 amendment (Student Documents);
  ADR 0076 §63/§65 (lifecycle evidence, placement terminal states); ADR 0071
  (role catalogue).

## 1. Context

Schools must issue a Leaving or Transfer certificate when a Student leaves.
The repository already records exits canonically:
- placements end as `withdrawn` (with a closed reason) or `completed`;
- a Student can be marked `inactive` with a closed reason.

It had no certificate, no numbering and no server-side PDF generation.
Receipts and payslips are browser-printed HTML.

## 2. Decision

1. **One issuance architecture for both types.**
   - `StudentCertificateType` holds `leaving` and `transfer`, code-owned and
     mirrored by a CHECK. Both certify the same canonical exit.
   - `transfer` additionally needs the closed exit reason `transferred_out`.
   - There is no School-defined type and no new lifecycle state.
2. **Eligibility from canonical history only** (`StudentCertificateEligibility`). A Student is eligible when:
   - no `active` placement exists;
   - the final non-cancelled placement ended `withdrawn` or `completed`;
   - the exit is recorded with a qualifying closed reason: a withdrawal for
     `left_school`, `transferred_out` or `guardian_request`, or a current
     deactivation for `left_school`, `transferred_out` or
     `completed_studies`.

   Not eligible:
   - a completed placement of a still-active Student (no recorded exit);
   - a deactivation for `duplicate_record` or `entered_in_error`;
   - a Student marked active again since.

   The audit ledger is never read, and exit-date rules are unchanged.
3. **An immutable snapshot** (`student_certificates`). The issued facts are
   bounded columns, never JSON:
   - Student name and number;
   - School name, legal name, affiliation and registration reference (the
     approved ORG.12C identity);
   - Campus, academic year, Grade and Section of the final placement;
   - joined and left dates, the closed exit reason, the issue date.

   No date of birth, Guardian, statutory identifier, caste, religion, health
   or Finance field exists. A trigger freezes everything except the single
   ISSUED → VOIDED transition. The runtime role cannot DELETE.
4. **Numbering.** `student_certificate_sequences` holds one forward-only
   counter per School and type.
   - The serial is allocated under the counter's row lock, inside the issuing
     transaction, so a failed issue consumes nothing (gaps are acceptable).
   - `(school_id, certificate_type, serial)` is unique.
   - The display number (`LC-000001`, `TC-000001`) is derived and
     CHECK-bound.
   - Numbers are never reused, and a void keeps its number.
5. **One issued certificate per exit and type.** A partial unique index
   covers Student, type and final placement where the status is `issued`.
   - **Correction:** void with a closed reason, then reissue. The replacement
     has a new serial and snapshot and names the original
     (`supersedes_certificate_id`, unique, same Student and type, and only an
     original voided as `replaced_by_corrected_certificate`).
   - The original row and file remain.
6. **Issue flow** (`StudentCertificateService`), each step a precondition:
   1. The legal production gate.
   2. A FreshMfaProof and an explicit confirmation.
   3. Whole-School `students.view`, else NOT FOUND.
   4. `students.certificates.issue`.
   5. Inside one transaction:
      1. the Student is locked FOR NO KEY UPDATE (serializing exits and
         returns);
      2. authority is re-read fresh;
      3. eligibility is re-evaluated, and the preview fingerprint must match;
      4. the serial is allocated;
      5. the PDF is rendered;
      6. Documents' `createCertificateForStudent()` stores the PDF, and its
         `$within` writes the certificate, the `issued_certificate` Student
         document record and the audit.

   A server-issued form key, bound to the request, makes a retry replay the
   first certificate. Finance never takes part (OD-SIS-2.5).
7. **Legal production gate** (`StudentCertificateAvailability`, the
   StudentMarkAvailability pattern).
   - The template is **generic** development wording. Issue, void and
     reissue run only in `local`/`testing`, and no flag opens them.
   - Lifting the gate is a reviewed code change once the jurisdiction, the
     wording and the fields are legally approved.
8. **Output: dompdf, locked down.**
   - Package `dompdf/dompdf` v3.1.6, LGPL-2.1, Composer lock-installed. Its
     dependencies: php-font-lib (LGPL-2.1+), php-svg-lib (LGPL-3.0+),
     masterminds/html5 (MIT), sabberworm/php-css-parser (MIT).
   - One code-owned Blade template with every value escaped.
   - No remote resources, PHP or PDF JavaScript; the filesystem is chrooted
     to the template directory; the bundled DejaVu Sans font only.
   - **Latin script only** (owner decision): a snapshot in another script is
     refused before any number or file is used, until a script-capable
     renderer is decided.
   - The output is a `highly_sensitive` Student Document, PDF only. Downloads
     go through the Student document route (current MFA, attachment,
     re-authorized each time).
9. **Authority.**
   - `students.certificates.issue` is a School key, classed `children`, held
     by `school_admin` and `principal`, whole-School and never
     Campus-adopted. It is never implied by `students.documents.manage`.
   - History is visible with `students.documents.view` or the issue key.
     Downloads need `students.documents.view`.
   - Refused: Teachers, Campus-scoped grants, Guardians, Students, platform
     and Network actors.
10. **Retention.** Certificates and their counters are retained.
    - Certificates go only with the Student's D7 core record; every parent
      key cascades.
    - Counters stay with the School.
    - Issuer and voider are retained user references.

## 3. Rejected

- **A browser-printed page with no stored file.** It contradicts
  OD-SIS-2.7.
- **A hand-written PDF writer.** It is Latin-1 only, with no font embedding,
  and more code to own.
- **A tenant template language, HTML, merge fields, QR or public
  verification, e-signature, a no-dues certificate, a School-wide register
  or export, and Finance on the certificate.**
- **Reusing `students.documents.manage`.** It would couple a legal act to
  document management.

## 4. Consequences

- dompdf is the repository's only PDF dependency and is used by this
  renderer alone. A second PDF need must reuse it, or reopen this decision.
- Non-Latin names cannot receive a certificate until a script-capable
  renderer is decided. This is a documented owner gap.
- Production enablement needs the legal review recorded in
  `docs/modules/STUDENT-ENROLLMENT.md`, "Leaving and Transfer certificates".
