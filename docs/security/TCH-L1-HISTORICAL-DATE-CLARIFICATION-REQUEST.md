# TCH-L1 — Historical-date teacher Attendance after ownership ends: Clarification Request

**Status at drafting (2026-10-08): DRAFT REQUEST — NOT SENT, NOT ANSWERED.**
This asks for a clarification of an existing determination. It records no
approval, changes nothing in the E33 determination, and alters no teacher
Attendance production status.

- **To:** Lead Privacy Counsel & Data Protection Officer — India Operations
- **From:** the product owner, Lycenza School OS
- **Register item:** ADR 0058 row **E33 (TCH-L1)**, DETERMINED — APPROVED
  WITH CONDITIONS. This clarification does not reopen it. It is **not** part
  of POR-L1 (E46).
- **Determination:** `TCH-L1-TEACHER-ATTENDANCE-DETERMINATION.md`
- **Engineering record:** ADR 0063 §42–§44, §47.5–§47.6.
- **Timing:** owner decision 2026-10-08. This must be clarified before E33
  production re-verification, unless the determination already clearly
  authorises the behaviour below.

## The behaviour
The determination requires "a verified, current authorised
teaching/Attendance relationship" (§1), excludes "unrestricted historical
access", and states that when an assignment ends, "future authority derived
from it ends".

The implementation judges ownership on the register's `attendance_date`.
So a teacher who is currently employed and holds the teacher capability may
still read, submit a missing non-future register for, or correct registers
dated within a period when they owned that class, even after that
assignment ended. This includes after a rehire, or after being reassigned
elsewhere.

The repository records control 7 ("assignment revocation ending future
authority") as PASS, reading it as future-dated, and leaves the question
open (ADR 0063 §47.6).

## Question
Does the determination permit a teacher to submit or correct Attendance for
dates within a past ownership period after that ownership has ended?
- If yes: within what window, and with what audit or approval?
- If no: should authority attach only to **current** ownership, so that
  corrections of past registers after an assignment ends go through
  administrative (Tier 1) staff only?

Please answer: **PERMITTED / PERMITTED WITH CONDITIONS / NOT PERMITTED**,
with the date, your name and role, and any conditions.

Curriculum Delivery (non-personal, Confidential) and the RES.4 marks
paper-date rule (owner-adopted; RES-L2 Q5/Q10 open) are **not** part of
this question.

## Recording the answer (engineering instructions)
- Record the answer verbatim as a dated status note in
  `TCH-L1-TEACHER-ATTENDANCE-DETERMINATION.md`. Do not edit the
  determination's text.
- Amend ADR 0063 §47.6 and update E33 by a dated, reviewed change.
- Engineering never fills in or infers the answer.
