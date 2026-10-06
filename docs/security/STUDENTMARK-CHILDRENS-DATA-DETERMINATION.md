# StudentMark Children's Data Determination

This document records an external legal/privacy determination as
project governance. It is not itself an engineering decision — see
`docs/architecture/adr/0037-staff-mfa-foundation.md` and
`docs/architecture/adr/0038-student-processing-authorization-registry.md`
for how it was translated into architecture. This document does not
grant production approval, and it must not be read as expanding beyond
the scope stated below.

## Approving authority

Lead Privacy Counsel & Data Protection Officer — India Operations.

## Decision date

September 3, 2026.

## Scope approved

**Architecture and backend implementation** of the future StudentMark
checkpoint (Examinations marks entry) — internal staff processing
only. This determination does **not** approve:

- production enablement of StudentMark for any real School;
- Student-facing marks access;
- Guardian-facing marks access;
- calculated-result publication;
- report cards;
- transcripts.

Each of the above remains separately withheld and requires its own
future determination before implementation begins.

## Processing-authorization requirement

The platform must be able to record the basis authorizing a specific
Student's data processing before marks entry is enabled for that
Student. Approved distinctions:

- Guardian consent, for a Student under 18;
- direct Student consent, for an adult Student (18 or over);
- statutory / legitimate authorized School purpose, where applicable
  (the School, as Data Fiduciary, is responsible for establishing this
  basis — the platform records that it was asserted by authorized
  staff, it does not itself adjudicate the law).

Required minimum provenance for every recorded authorization:

- the authorization type/basis;
- when it was recorded;
- who (which staff User) recorded it.

## MFA requirement

Because this determination concerns processing authorization for
protected, Highly Sensitive data belonging to (in the common case)
minors, recording, withdrawing, or revoking a processing-authorization
record requires MFA assurance in addition to ordinary capability
authorization — see ADR 0037 (Staff MFA Foundation) and ADR 0038
(Student Processing Authorization Registry).

## Current project state as of this document

- Phase 0H.4D-P1 (Staff MFA Foundation) — published, the platform
  prerequisite this determination itself requires.
- Phase 0H.4D-P2 (Student Processing Authorization Registry) — the
  direct implementation of this determination's processing-
  authorization requirement; see ADR 0038.
- StudentMark itself — **not implemented**. This determination approves
  its architecture/backend scope with conditions; it does not start
  implementation and does not itself constitute production approval.

## Do not reinterpret

Engineering must not treat this determination as broader than stated
above. Any future expansion (production enablement, Student-facing or
Guardian-facing surfaces, result publication, report cards,
transcripts) requires its own separate, explicit determination from
the approving authority before implementation begins.

## Project status note (2026-09-29, ADR 0061) — not part of the determination

The project owner deferred StudentMark and the rest of Phase 0H's
Examinations depth to **post-v1** (a product-scope decision). This note
records project governance only. It does not change or widen the
determination above.
- **Still withheld:** the determination is still limited to architecture
  and backend scope (internal staff processing, with conditions).
  Production, Student- and Guardian-facing access, results, report cards and
  transcripts stay withheld.
- **Historical input:** it is kept as input for the future post-v1
  checkpoint, which must first:
  1. verify with the approving authority that it is still current;
  2. re-verify the processing-authorization requirement (ADR 0038);
  3. re-verify the MFA requirement (ADR 0037);
  4. complete every prerequisite that still applies, including Phase
     0H.4D-P3;
  5. obtain every additional determination its scope needs.
- **Deferral is not legal clearance.**

## Project status note (2026-10-06, ADR 0068) — not part of the determination

RES.0B reopened internal StudentMark processing in contract only (ADR
0068). This note records project governance; it does not change or widen
the determination above.
- **Revalidation requested, not answered:** step 1 of the 2026-09-29 note is
  register item RES-L0 (ADR 0058 row E35). The request is
  `docs/security/RES-L0-STUDENTMARK-REVALIDATION-REQUEST.md`. StudentMark
  implementation does not start until the answer is recorded.
- **Still withheld,** each as register items RES-L1 and RES-L4 – RES-L7:
  production, result publication, report cards, transcripts, and Student-
  and Guardian-facing access.
