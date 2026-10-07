# RES-L2 — Assigned-Teacher StudentMark Processing: Review Request

**Status at drafting (2026-10-07): DRAFT REQUEST — NOT SENT, NOT ANSWERED.**
This is the request the product owner sends to the approving authority. It
records no approval, and nothing in it may be read as one. The answer, when
received, is recorded verbatim in a separate dated document
(`docs/security/RES-L2-TEACHER-STUDENTMARK-DETERMINATION.md`) and in the ADR
0058 register row **E37** (RES-L2). Until then **RES.4 (teacher marks entry)
is NOT AUTHORISED** and does not start.

- **To:** Lead Privacy Counsel & Data Protection Officer — India Operations
- **From:** the product owner, Lycenza School OS
- **Subject:** Whether the authorised internal StudentMark processing scope
  extends to assigned teachers acting within verified teaching ownership
- **Register item:** RES-L2 (ADR 0058 row E37). **Related, separately
  recorded:** E35 / RES-L0 re-review
  (`RES-L0-TEACHER-STUDENTMARK-REVALIDATION-REQUEST.md`) and E33 / TCH-L1
  (`TCH-L1-TEACHER-ATTENDANCE-REVIEW-REQUEST.md`).
- **Engineering contract:** ADR 0068 §9.3, §19, §20, §21, §22
  (`docs/architecture/adr/0068-assessment-results-reopening-contract.md`);
  teacher ownership: ADR 0063.
- **Prior determinations:** `STUDENTMARK-CHILDRENS-DATA-DETERMINATION.md`
  (3 September 2026); `RES-L0-STUDENTMARK-REVALIDATION-DETERMINATION.md`
  (7 October 2026, CURRENT WITH CHANGES — administrative staff only; its §6
  makes no determination about teachers).

---

## Request text

Dear Lead Privacy Counsel,

On 7 October 2026 you confirmed, with conditions, that StudentMark may be
designed and developed for **School-internal recording by authorised
administrative staff** (the School administrator and Principal roles). You
stated that the determination makes no determination about teacher marks
entry, that teacher processing is governed by RES-L2 (and, for production,
ADR 0063 §40 / E33), and that administrative permission must not be inherited
by, or bootstrapped into, teacher permission.

We now ask the RES-L2 question formally:

> **Does the authorised internal StudentMark processing scope extend to
> assigned teachers acting within verified teaching ownership, and if so,
> under what conditions?**

We have not built teacher marks entry and will not start until we have your
answer. We are asking for an explicit, recordable decision.

### 1. The processing we propose (narrow)

1. **Who.** A teacher who is School staff with an eligible Employee record
   (an active School membership, an enabled user account, a linked active
   Employee, and exactly one current eligible employment record).
2. **Which Students.** Only a Student who, on the examination paper's
   scheduled date, is placed in a Section that the teacher was assigned to
   teach for that paper's **required** subject on that same date (a dated
   TeachingAssignment record). The paper itself covers every Section of the
   subject; the teacher's authority is decided per Student, never for the
   whole paper.
3. **What.** Record or change, for those Students only, the existing
   StudentMark shape: `present` with a number from 0 to the paper's maximum,
   `absent` or `exempt`. No free-text remark.
4. **What the teacher may see.** Only the existing marks of those same
   Students on that paper, as needed to enter or update them. No School-wide
   or paper-wide view, and no count or existence signal for Students the
   teacher does not own.
5. **When.** Only while the paper's marks are `open`. A locked paper changes
   only through the administrative maker/checker correction path, which
   teachers do not get.
6. **Not included:** locking a paper; requesting or approving a correction;
   any result, grade, percentage, rank or pass/fail; publication; Student or
   Guardian access; report cards; transcripts; export, search, reporting,
   analytics or AI; electives (no dated teacher-ownership fact exists for
   them yet, so they stay out even if you approve the rest).
7. **Unchanged controls.** Every condition of your 7 October 2026
   determination still applies: Highly Sensitive classification; deny by
   default; School isolation; capability checks; a currently qualifying
   ADR 0038 processing basis for each Student at the time of each write,
   permanently recorded on the mark; immutable value history; every read and
   write audited (actor, action, record, time); no values in logs, errors or
   audit metadata; no outbox, webhook or integration.
8. **A separate permission.** Teachers would hold a new, separate owned-scope
   permission. They would never receive the administrative marks permission,
   and no administrative grant is reused.

### 2. Questions

Please answer each:
1. **Recording.** May an assigned teacher record and change StudentMark for
   the Students described in §1.2, within §1's limits?
2. **Reading.** May that teacher read existing marks of only those owned
   Students on that paper (§1.4)? Is any narrower read required (for example,
   only marks the teacher entered)?
3. **Co-teachers.** The ownership model allows more than one teacher to be
   assigned to the same Section and subject at the same time, with no
   "lead" or "assistant" distinction recorded. May every such co-assigned
   teacher record marks, or only some (and if so, which, and how would that
   be evidenced)?
4. **Cover / substitute / temporary teachers.** Temporary cover is recorded
   only as a short, dated teaching assignment; the platform cannot
   distinguish it from an ordinary assignment. Are cover, substitute or
   temporary teachers included? If they must be excluded or treated
   differently, we will keep them out of scope until the platform can record
   that distinction.
5. **Ownership date.** We propose that ownership is judged **on the paper's
   scheduled date** (the date the eligibility check and the mark already
   use), and that the teacher must be an eligible employee on the day of
   entry. Is that the correct date? Must ownership **also** exist on the day
   of entry (so a teacher reassigned after the examination can no longer
   enter marks for it)?
6. **Required vs elective subjects.** Do required and elective subjects need
   different treatment? (Electives are technically out of scope today; your
   answer will shape the prerequisite work.)
7. **Authentication.** Is the ordinary MFA session sufficient for teacher
   entry, or is a fresh MFA code required for each submission?
8. **Development before production.** May teacher entry exist in
   development and test environments, with synthetic data only, before any
   production approval?
9. **Exceptions.** Is any exceptional or override path permitted for
   teachers (for example, entering marks for an unowned Student)? We propose
   none.
10. **End of ownership.** When a teacher's assignment ends, or their
    employment or School access ends, must further teacher processing stop
    immediately (refused on the next write), and is there any obligation
    regarding marks the teacher already recorded (we propose they stay, with
    their recorded actor and processing basis)?
11. **E33 relationship.** E33 (TCH-L1, teacher Attendance) is recorded as a
    production gate only. Please confirm that E33 does **not** block
    **development** of teacher marks entry. We are not asking you to decide
    E33 here; it has its own request.

### 3. Requested form of answer

Please state one outcome for RES-L2:
- **AUTHORISED** — assigned-teacher processing within §1, as stated;
- **AUTHORISED WITH CONDITIONS** — within §1, with the conditions stated;
- **NOT AUTHORISED** — teachers may not process StudentMark;

and give: the date; your name and role; the answers to questions 1–11; the
conditions, separating **development** conditions from conditions you already
know will apply to **production**; any scope restriction (for example,
required subjects only, no cover teachers); and any expiry, re-review trigger
or jurisdictional restriction.

**Separate outcomes, please.** This request is RES-L2 only. The RES-L0
re-review for teacher processing (E35) and TCH-L1 (E33) are sent alongside
it. If you answer them together, please record a **separate outcome for each
register item**.

This request does **not** ask about production enablement (RES-L1, E36),
results (RES-L4), report cards (RES-L5), transcripts (RES-L6), Student or
Guardian access (RES-L7), retention (RES-L8) or statutory academic rules
(RES-L9).

Thank you.

---

## Recording the answer (engineering instructions)

- Record the answer verbatim in a new dated document
  `docs/security/RES-L2-TEACHER-STUDENTMARK-DETERMINATION.md`, naming the
  authority and date. Do not edit this request (add only a status line at
  the top).
- Update ADR 0058 row E37 by a dated, reviewed change, and append a dated
  ADR 0068 amendment applying the conditions. Preserve historical text.
- **AUTHORISED** or **AUTHORISED WITH CONDITIONS** clears RES-L2 for
  **development** only, and only for the scope and conditions stated. It is
  not production approval (RES-L1, E36; E33 / ADR 0063 §40 for any
  production `teacher` grant).
- RES.4 still needs the RES-L0 teacher revalidation (E35) to cover the same
  scope (ADR 0068 §22.9). A RES-L2 answer alone does not authorise RES.4.
- **NOT AUTHORISED** keeps RES.4 closed; ADR 0068 §22 is amended to say so.
- Engineering never fills in or infers any part of the answer.
