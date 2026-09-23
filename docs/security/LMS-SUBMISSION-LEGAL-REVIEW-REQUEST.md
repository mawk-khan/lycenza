# LMS Submission — Qualified Legal & Product Governance Review Request (as issued)

> ## STATUS: CLOSED — FEATURE CANCELLED / OUT OF SCOPE (2026-09-05)
>
> This request received **no qualifying response** from qualified legal
> counsel or product governance before the product owner cancelled the
> Submission capability entirely on 2026-09-05, as an independent
> product-scope decision. The 20-question set below remains **intact as
> historical provenance** — it is not withdrawn, answered, or resolved
> by the cancellation. Further legal/product review is no longer
> required while Submission remains out of scope; this is not legal
> clearance of any of the processing described below. See
> `docs/architecture/adr/0037-lms-domain-contract.md`'s Submission
> cancellation addendum and `docs/security/LMS-SUBMISSION-LEGAL-REVIEW.md`
> for the full governance record.

- Issued: 2026-09-05, following Phase 0I.4
- Engineering source documents: `docs/security/LMS-SUBMISSION-LEGAL-REVIEW.md`,
  `docs/architecture/adr/0037-lms-domain-contract.md` §4/§5
- Status: **CLOSED, unanswered — see banner above.** This document
  records the request as formally issued to qualified legal counsel and
  product governance outside this engineering repository. It was never
  itself a decision and never changed the gate's status; the gate was
  ultimately retired by feature cancellation, not by any response to
  this request.

> This is a record of the request, not an answer to it. Engineering has
> not responded to, resolved, or pre-empted any question below — doing
> so would repeat exactly the failure Phase 0I.4 was written to guard
> against (engineering opinion is not a substitute for qualified legal/
> product-governance review; see `LMS-SUBMISSION-LEGAL-REVIEW.md` §18).
> The repository gate remains `[LEGAL REVIEW REQUIRED]` until an actual
> named, dated, jurisdiction-specific decision is returned and recorded
> per §22 below.

---

## Context

The School System ERP is implementing an internal LMS domain. Learning
Content and Assignments are already architecturally defined and
implemented on feature branches. The next proposed capability is
student coursework Submission.

Engineering has completed Phase 0I.4 (Submission Legal-Gate Review).
Repository decision record: `docs/security/LMS-SUBMISSION-LEGAL-REVIEW.md`.
Related architecture decision: `docs/architecture/adr/0037-lms-domain-contract.md`.

Phase 0I.4 concluded: **BLOCKED — SUBMISSIONS REQUIRE QUALIFIED LEGAL REVIEW.**
Engineering has deliberately not created any Submission schema,
application code, file-storage owner relationship, API, UI, capability,
or event. A qualified legal and product-governance decision is required
before implementation may proceed.

## Proposed processing

The LMS Submission capability would potentially process: Student
identity and School/Assignment linkage; Student-authored written
coursework; uploaded coursework files; submission timestamps and
status; submission/revision history; submitting-actor identity; teacher
feedback/comments; file metadata; audit metadata.

It explicitly does **not** own: official marks, official grades,
examination results, report-card values, or transcript values. Numeric
LMS scoring is currently deferred.

## Current engineering classification (provisional)

- Submission records and linkage: **Sensitive**
- Student-authored content: **Sensitive**, with potential to contain
  **Highly Sensitive** material
- Teacher feedback: **Sensitive**
- Coursework Documents: **Sensitive minimum**, effective classification
  dependent on actual content

This classification is provisional pending qualified legal confirmation.

## Known actor-model constraints

- Guardian self-service authentication exists.
- Direct Student authentication does not currently exist.
- Guardian authority to submit coursework on behalf of a Student has
  not been established.
- Staff authority to submit coursework on behalf of a Student has not
  been established.
- A canonical Teacher-to-SubjectOffering ownership/access model does
  not currently exist.

These are product/technical issues to be resolved separately; legal
guidance is requested on which actor models are permissible.

## Questions requiring qualified decision

1. Is processing student coursework for normal educational purposes
   permitted, and what is the applicable lawful basis?
2. Does the legal treatment differ for students who are minors?
3. Is Guardian consent required for processing student coursework?
4. If consent is required, what evidence of consent must the system
   retain?
5. May a Guardian submit coursework on behalf of a Student?
6. May authorized School staff submit coursework on behalf of a
   Student, for example for accessibility, offline, or administrative
   reasons?
7. What retention period applies to: Submission records; student-
   authored text; uploaded coursework; teacher feedback; prior
   submission versions; audit history?
8. What deletion, correction, or erasure rights apply to Submission
   data?
9. Must prior submissions/resubmissions remain retained after a Student
   replaces or corrects work?
10. Are teacher comments and feedback considered part of the same
    protected educational record as the Student's coursework?
11. Are students permitted to upload images, audio, video, PDFs, office
    documents, spreadsheets, presentations, archives? Are any
    categories prohibited or subject to additional safeguards?
12. What requirements apply if coursework contains unexpectedly
    sensitive information such as health information, family
    information, identity documents, photographs of children, precise
    location, religious or political views, or third-party personal
    information?
13. May School administrators with broad School-level permissions
    access all student Submissions?
14. What restrictions should apply to Teacher access? In particular,
    must access be limited to Students/classes/SubjectOfferings for
    which the Teacher has an authoritative teaching relationship?
15. May coursework be processed by AI systems, generative AI,
    embeddings/vector indexes, automated profiling, analytics systems,
    plagiarism detection services, or external third-party processors?
    If yes, under what separate conditions or approvals?
16. Are there data-localization, residency, or cross-border-transfer
    requirements for student coursework?
17. What security or incident-response obligations specifically apply
    if coursework data is breached?
18. Does the existing School System ERP Documents security/storage
    architecture provide an acceptable baseline for coursework, subject
    to adding malware scanning for student uploads?
19. Are age-specific notices, consent UX, or privacy notices required
    before a Student or Guardian submits coursework?
20. What records of processing, authorization, consent, or Guardian
    delegation must be retained for audit/compliance purposes?

## Decisions requested from Product Governance

- Whether direct Student authentication is required before Submissions
  launch.
- Whether Guardian-on-behalf submission is supported.
- Whether staff-on-behalf submission is supported.
- Whether multiple submission attempts are supported.
- Whether previous attempts remain visible.
- Whether Teachers may return work for revision.
- Whether Teacher access must be scoped through an authoritative
  class/SubjectOffering relationship.
- Which coursework file types the product intends to support.
- What user-facing warnings or acceptable-use language should apply to
  sensitive uploads.

## Engineering conditions already identified

Even if legal clearance is granted, engineering currently considers the
following prerequisites unresolved:

**Technical** — Student authentication, if Students must submit
directly; malware/virus scanning for untrusted coursework uploads;
authoritative Teacher-to-class/SubjectOffering access scoping if
required.

**Product policy** — Guardian-on-behalf submission; staff-on-behalf
submission; sensitive-content policy; Teacher access boundaries;
resubmission/version behavior.

**Legal/data governance** — retention; deletion/erasure; lawful
basis/consent; minor-specific treatment; downstream/AI/third-party
usage.

## Required form of approval

A **named, dated, jurisdiction-specific recorded decision**, comparable
to the repository precedent used for Payroll legal clearance (ADR 0036),
explicitly identifying:

- reviewing authority/counsel;
- jurisdiction/legal regime reviewed;
- date;
- scope of Submission processing approved;
- unresolved or prohibited processing;
- retention/deletion requirements;
- consent requirements;
- actor/delegation requirements;
- access restrictions;
- file/media restrictions;
- downstream/AI/third-party restrictions;
- any technical controls required before production use.

Engineering will record the resulting decision as a dedicated ADR or
legal-review addendum before any Submission implementation begins.

**Until that record exists, the repository gate remains
`[LEGAL REVIEW REQUIRED]`, and no Submission implementation will
proceed.**
