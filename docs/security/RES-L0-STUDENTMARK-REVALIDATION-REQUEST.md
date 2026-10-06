# RES-L0 — StudentMark Determination Revalidation Request

**Status: DRAFT REQUEST — NOT SENT, NOT ANSWERED.** This document is the
request the product owner sends to the approving authority. It records no
approval, and nothing in it may be read as one. The answer, when received,
is recorded as a dated, separate document and in the ADR 0058 register row
E35 (RES-L0); until then RES.2 (StudentMark implementation) does not start.

- **To:** Lead Privacy Counsel & Data Protection Officer — India Operations
- **From:** the product owner, Lycenza School OS
- **Subject:** Re-confirmation of the StudentMark children's-data
  determination of 3 September 2026
- **Register item:** RES-L0 (ADR 0058 row E35)
- **Engineering contract:** ADR 0068
  (`docs/architecture/adr/0068-assessment-results-reopening-contract.md`)
- **Prior determination:**
  `docs/security/STUDENTMARK-CHILDRENS-DATA-DETERMINATION.md`

---

## Request text

Dear Lead Privacy Counsel,

On 3 September 2026 you approved the **architecture and backend
implementation** of StudentMark (Examinations marks entry) for **internal
staff processing only**, with conditions: a recorded processing basis for
each Student before marks entry, its provenance, and MFA for recording or
ending that basis. You withheld production enablement, Student- and
Guardian-facing marks, calculated-result publication, report cards and
transcripts.

The project deferred StudentMark on 29 September 2026 and has now reopened
it. Our governance (ADR 0061 §2.4) requires us to confirm with you that
your determination is still current before any implementation. **We are
asking for an explicit, recordable decision.** We have not started
implementation and will not start it until we receive your answer.

### 1. The processing we propose

Narrower than, or equal to, what you approved:
1. **School-internal marks entry only.** Authorized administrative staff
   of the School (initially the School administrator and Principal roles)
   record, for one examination paper, whether each Student was `present`
   (with a numeric mark between 0 and the paper's maximum), `absent` or
   `exempt`. No free-text remark is stored.
2. **No exposure** to Students, Guardians, parents or any portal, email,
   notification or external system.
3. **No result calculation, grading, percentage, rank, pass/fail or
   publication.** Marks are not converted into results.
4. **No report cards and no transcripts.**
5. **No teacher access in this phase.** Whether assigned teachers count as
   "internal staff" is a separate question (RES-L2) that we will put to you
   separately.
6. **Processing-basis provenance.** Before a mark is recorded for a
   Student, the platform checks, inside the same database transaction,
   that a qualifying processing authorization exists for that Student
   (the ADR 0038 registry: Guardian consent under 18, the Student's own
   consent at 18 or over, or a statutory / legitimate School purpose
   asserted by authorized staff). The mark permanently records which
   authorization it relied on.
7. **Classification.** Marks and their correction history are treated as
   **Highly Sensitive** children's data: tenant-isolated at the database,
   minimized in every view, never logged, and every read and write is
   audited.
8. **Security.** Marks routes require the user's role capability and a
   current MFA session; locking a paper's marks and approving a correction
   require a fresh MFA code. After a paper is locked, a change requires a
   second staff member's approval, and the previous value is kept.
9. **Retention is not yet decided** (RES-L8). Until it is, no marks are
   deleted, and a Student with marks is not purged.

### 2. Questions

Please answer each:
1. **Is the 3 September 2026 determination still current** for the
   processing described in §1? If anything in law, rules or guidance has
   changed since, please identify it.
2. **Is the ADR 0038 processing-basis model still appropriate:** the three
   bases (Guardian consent under 18, adult Student consent, statutory /
   legitimate School purpose asserted by the School as Data Fiduciary), and
   the platform recording the assertion without adjudicating it?
3. **Withdrawal (also RES-L3):** we assume that withdrawing or revoking an
   authorization stops *new* marks for that Student but does not
   invalidate marks already recorded on a then-valid basis. Is that
   assumption acceptable?
4. **Conditions:** are there any further conditions on development (for
   example, data minimization, audit, access-review or test-data rules)?
5. **Validity:** does the determination carry an expiry or a re-review
   trigger (a date, a change in scope, a change in law)?

### 3. Requested form of answer

So that it can be recorded exactly, please state one outcome:
- **CURRENT** — the determination stands for §1, with any conditions;
- **CURRENT WITH CHANGES** — it stands, with the changes or conditions
  stated;
- **NOT CURRENT** — a new assessment is needed before implementation;

and give: the date, your name and role, the conditions, the answers to
questions 2–5, and any expiry or re-review trigger.

This request does **not** ask about production enablement (RES-L1),
teacher access (RES-L2), results or publication (RES-L4), report cards
(RES-L5), transcripts (RES-L6), Student or Guardian access (RES-L7),
retention (RES-L8) or statutory academic rules (RES-L9). Each will be a
separate request.

Thank you.

---

## Recording the answer (engineering instructions)

- Record the answer verbatim in a new dated document
  `docs/security/RES-L0-STUDENTMARK-REVALIDATION-DETERMINATION.md`,
  naming the authority and date. Do not edit this request.
- Update ADR 0058 row E35 by a dated, reviewed change. A `CURRENT` or
  `CURRENT WITH CHANGES` answer clears RES-L0 for **development** of RES.2
  only; it is not production approval (RES-L1).
- Any condition the answer adds is applied to ADR 0068 by a dated
  amendment before RES.2 starts.
- A `NOT CURRENT` answer keeps RES.2 blocked; ADR 0068 §11 is amended to
  say so.
