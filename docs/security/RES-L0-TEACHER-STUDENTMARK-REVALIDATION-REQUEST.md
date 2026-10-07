# RES-L0 Re-review — Teacher StudentMark Processing: Revalidation Request

**Status at drafting (2026-10-07): DRAFT REQUEST — NOT SENT, NOT ANSWERED.**
This is the request the product owner sends to the approving authority. It
records no approval, and nothing in it may be read as one. The answer, when
received, is recorded verbatim in a separate dated document
(`docs/security/RES-L0-TEACHER-STUDENTMARK-REVALIDATION-DETERMINATION.md`) and
by a dated change to ADR 0058 row **E35**. Until then the 7 October 2026
determination covers **administrative staff only**, and **RES.4 is NOT
AUTHORISED**.

- **To:** Lead Privacy Counsel & Data Protection Officer — India Operations
- **From:** the product owner, Lycenza School OS
- **Subject:** Re-review of the RES-L0 determination of 7 October 2026 for
  assigned-teacher StudentMark processing
- **Register item:** RES-L0 re-review (ADR 0058 row E35). **Related,
  separately recorded:** RES-L2 / E37
  (`RES-L2-TEACHER-STUDENTMARK-REVIEW-REQUEST.md`) and TCH-L1 / E33
  (`TCH-L1-TEACHER-ATTENDANCE-REVIEW-REQUEST.md`).
- **Why this is required:** your determination's §5 lists "teacher marks
  entry or assigned-teacher processing" as a revalidation trigger, and its §6
  says administrative permission must not be inherited by teachers.
- **Engineering contract:** ADR 0068 §19 (your conditions, binding), §20–§22.
- **Prior determination:** `RES-L0-STUDENTMARK-REVALIDATION-DETERMINATION.md`.

---

## Request text

Dear Lead Privacy Counsel,

Your determination of 7 October 2026 (**CURRENT WITH CHANGES**) confirmed the
3 September 2026 StudentMark determination for School-internal recording by
**authorised administrative staff**, under sixteen conditions. It lists
assigned-teacher processing as a re-review trigger. We are proposing that
change, so we ask you to re-review before any implementation. We are not
treating your earlier determination as extending to teachers.

### 1. What is already approved, and built (unchanged)

- **Who:** administrative staff (School administrator, Principal) only.
- **What:** per examination paper, `present` (0 to the paper's maximum),
  `absent` or `exempt`; no remark.
- **Controls:** your sixteen conditions (ADR 0068 §19). In particular: a
  currently qualifying ADR 0038 processing basis for each Student at each
  write, recorded on the mark; no basis means no new marks and marks withheld
  from reads; full value history; every read and write audited; MFA on every
  marks route; fresh MFA to lock a paper or decide a correction; a locked
  paper changes only by a correction one administrator requests and another
  approves.
- **Status:** development only. Production waits for RES-L1.

### 2. What we propose to add (teacher scope)

Strictly narrower than administrative access:
1. **Assigned-teacher entry.** A teacher records or changes marks only for a
   Student who, on the paper's scheduled date, is placed in a Section the
   teacher was assigned (by a dated teaching assignment) to teach for that
   paper's **required** subject on that date. The decision is made per
   Student, inside the same database transaction as the write.
2. **Ownership-scoped reads.** The teacher sees only those owned Students'
   marks on that paper, as needed for entry. No administrative or
   paper-wide view; no count or existence signal for other Students.
3. **Verified identity and ownership.** A separate teacher permission, the
   user's MFA session, a verified Employee (active membership, enabled
   account, active Employee, exactly one eligible employment), and the dated
   teaching assignment — each checked independently; none substitutes for
   another.
4. **No administrative authority.** No lock, no correction request or
   approval, no administrative grid, no results permission.
5. **No widening.** No results, publication, Student or Guardian exposure,
   report cards, transcripts, export, reporting, search, analytics or AI.
   No electives (no dated teacher-ownership fact exists for them).
6. **No production enablement** unless separately authorised (RES-L1; and
   E33 / ADR 0063 §40 for any production `teacher` grant).

### 3. Questions

Please answer each:
1. **Extension.** May your 7 October 2026 determination be extended to the
   teacher scope in §2, with or without changes?
2. **Conditions.** Do all sixteen conditions apply unchanged to teachers?
   Please state any additional or changed condition, in particular on:
   - **ownership verification** (the dated assignment as the only evidence;
     no timetable, Section membership or self-declaration);
   - **date anchoring** (ownership on the paper's scheduled date; whether it
     must also hold on the day of entry);
   - **co-teachers** (several teachers assigned at once, with no lead or
     assistant distinction recorded);
   - **cover or substitute teachers** (recorded only as short dated
     assignments, indistinguishable from ordinary ones);
   - **electives** (out of scope until an ownership fact exists);
   - **processing-authorization provenance** (whether the mark should also
     record which teaching assignment authorised the teacher);
   - **audit** (whether teacher reads must be audited per access, as
     administrative reads are);
   - **MFA** (MFA session, or a fresh code per submission);
   - **withdrawal of teacher ownership** (refusal on the next write; marks
     already recorded stay);
   - **production restrictions** you already know will apply.
3. **Re-review triggers.** Should any trigger be added or changed for the
   teacher scope (for example, adding electives, cover-teacher rules, or
   teacher correction requests)?
4. **Validity.** Any expiry, periodic review or jurisdictional restriction
   for the teacher scope?

### 4. Requested form of answer

Please state one outcome for the teacher scope in §2:
- **CURRENT** — the determination extends to §2 as stated;
- **CURRENT WITH CHANGES** — it extends to §2 with the changes or conditions
  stated;
- **NOT CURRENT** — a new assessment is needed before teacher processing;

and give: the date, your name and role, the conditions (development and,
where known, production), the answers to questions 1–4, and any expiry,
re-review trigger or jurisdictional restriction.

**Separate outcomes, please.** RES-L2 (E37) and TCH-L1 (E33) are sent
alongside. If you answer them together, please record a separate outcome for
each register item.

This request does not ask about production enablement (RES-L1), results
(RES-L4), report cards (RES-L5), transcripts (RES-L6), Student or Guardian
access (RES-L7), retention (RES-L8) or statutory academic rules (RES-L9).

Thank you.

---

## Recording the answer (engineering instructions)

- Record the answer verbatim in a new dated document
  `docs/security/RES-L0-TEACHER-STUDENTMARK-REVALIDATION-DETERMINATION.md`.
  Do not edit this request (add only a status line at the top), and do not
  edit the 7 October 2026 determination.
- Update ADR 0058 row E35 by a dated, reviewed change that keeps its existing
  text, and append a dated ADR 0068 amendment applying any condition.
- **CURRENT** or **CURRENT WITH CHANGES** satisfies the RES-L0 re-review for
  **development** of the stated teacher scope only. RES.4 also needs RES-L2
  (E37) to be affirmatively determined (ADR 0068 §22.9). Neither is
  production approval.
- **NOT CURRENT** keeps RES.4 closed.
- Engineering never fills in or infers any part of the answer.
