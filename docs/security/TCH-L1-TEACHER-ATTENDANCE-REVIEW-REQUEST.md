# TCH-L1 — Teacher Attendance Production Access: Review Request

*Status update (2026-10-07): answered — **APPROVED WITH CONDITIONS**; see
`docs/security/TCH-L1-TEACHER-ATTENDANCE-DETERMINATION.md`. The request below
is kept as drafted.*

**Status at drafting (2026-10-07): DRAFT REQUEST — NOT SENT, NOT ANSWERED.**
This is the request the product owner sends to the authorized
legal/compliance reviewer. It records no approval, and nothing in it may be
read as one. The answer is recorded in the decision record ADR 0063 §39.4
defines, as `docs/security/TCH-L1-TEACHER-ATTENDANCE-DETERMINATION.md`, and
by a dated change to ADR 0063 §26 and ADR 0058 row **E33**. Until then ADR
0063 §40 holds: **no production `teacher` role grants**.

- **To:** the authorized legal/compliance reviewer (for StudentMark this has
  been the Lead Privacy Counsel & Data Protection Officer — India
  Operations)
- **From:** the product owner, Lycenza School OS
- **Subject:** Production access of assigned teachers to identifiable Student
  attendance (TCH-L1)
- **Register item:** TCH-L1 (ADR 0058 row E33; ADR 0063 §26, §39, §40)
- **Facts for the reviewer:** ADR 0063 §39.2 (data, actions, controls) —
  attached by reference; the decision record to complete is ADR 0063 §39.4.
- **Why it is sent now:** teacher StudentMark processing (RES-L2, E37, and the
  RES-L0 re-review, E35) is being put to review at the same time. E33 is a
  **different question** — teacher Attendance — and is not answered by either
  of those. It matters to teacher marks only because the single production
  `teacher` role carries `attendance.teacher`, so no production teacher role
  of any kind is granted while E33 is open (ADR 0063 §40).

---

## Request text

Dear reviewer,

Teacher Attendance is built and closed for development (ADR 0063, TCH.4). It
widens access to existing identifiable Student attendance from School
administrators to assigned teachers, for their own classes and dates only.
It adds no data, purpose, recipient or transfer. ADR 0063 §39.2 sets out the
data teachers see, the actions they take, who can reach it and the technical
controls in place.

**The question (ADR 0063 §26):**

> Does expanding access to identifiable Student attendance, from the current
> administrative actors to assigned teachers, require an updated
> children's-data/privacy assessment, processing record or equivalent
> production approval — and may it be enabled in production?

Please complete the decision record in ADR 0063 §39.4:
- **Decision / Outcome:** APPROVED / APPROVED WITH CONDITIONS / REJECTED;
- **Scope**, **data**, **actions** and **technical controls reviewed** (the
  §39.2 list, with any additions);
- **Jurisdiction / policy basis**;
- **Conditions** (for example, a required processing record, notice or
  assessment);
- **Approving authority** and **decision date**;
- **Does not approve:** anything outside the stated scope.

**Please also confirm**, as separate statements:
1. that a TCH-L1 outcome concerns teacher **Attendance** only, and does not
   decide teacher **StudentMark** processing (that is RES-L2 / E37 and the
   RES-L0 re-review / E35);
2. that E33 remains a **production** gate only and does not block
   **development** of teacher marks entry (as ADR 0058 E33 records today).

If you answer this together with the RES-L2 and RES-L0 requests, please
record a **separate outcome for each register item**.

Thank you.

---

## Recording the answer (engineering instructions)

- Record the decision in `docs/security/TCH-L1-TEACHER-ATTENDANCE-DETERMINATION.md`
  in ADR 0063 §39.4's shape. Engineering fills in no field.
- Update ADR 0063 §26 and §40's state table, and ADR 0058 row E33, by a
  dated, reviewed change (ADR 0058 §6). Preserve historical text.
- Apply ADR 0063 §40's production role-grant rule to the outcome. An
  APPROVED outcome permits the existing `teacher` role in production only
  subject to E21 and the platform checklist (ADR 0058). It never grants any
  StudentMark permission: teacher marks still need RES-L2, the RES-L0
  re-review and RES-L1.
