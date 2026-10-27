# ADR 0079: One Student-Number Policy and a Duplicate-Risk Warning

- Status: **Accepted and implemented (2026-10-27).** SIS 2.3.
- Date: 2026-10-27
- Module: Students (Student identity), with Admissions conversion and Campus
  onboarding as callers.
- Amends:
  - ADR 0076 §46.1/§46.2 (ORG.7F-2): "web and API create and Admissions
    conversion take a typed number (legacy, unchanged)" and "Campus
    onboarding: the ONLY path that uses the allocator". The allocator is now
    the only source of a new Student's number on every path.
  - `docs/modules/ADMISSIONS.md` §8 ("`student_number` staff-supplied, Phase
    1A has no allocator") and §10 ("no mechanism to detect the same child").
    Conversion now takes no number, and it warns about a likely duplicate.

## 1. Context

The SIS 1.1 audit (`docs/product/SIS-CURRENT-STATE-AUDIT-2026-10-26.md`
§23.2) found two numbering rules in one School:
- web/API create and Admissions conversion took a typed number;
- Campus onboarding used `StudentNumberAllocator`.

It also found no warning when a new Student's name and date of birth matched
an existing Student.

**Why the typed path existed.** Phase 1A had no allocator. Admissions §8 made
the number staff-supplied *because* none existed, and left the allocator as an
open question. ORG.7F-2 built the allocator and recorded the typed paths as
"legacy, unchanged". No document makes operator-chosen numbers a product
feature. **Classification: legacy drift (outcome B).** There is no governed
override concept to preserve.

## 2. Decision: one numbering policy

- **`StudentService::create()` allocates.** It is the only way a Student is
  created (web, API, Admissions conversion, Campus onboarding). Inside its
  transaction it:
  1. takes the number from `StudentNumberAllocator` (the School's counter row,
     FOR UPDATE);
  2. runs the duplicate check (§3);
  3. writes the Student and the audit.
- **Callers never supply a number.**
  - Passing `student_number` to the service is a `LogicException`.
  - The API and the conversion endpoints refuse a supplied `student_number`
    (422, field error).
  - The browser form has no number field.
  - An architecture test pins StudentService as the only allocator caller
    and the only code that creates a Student row.
- **The allocator is unchanged** (ADR 0076 §46.2):
  - School-wide plain decimals, forward-only and never reused;
  - transactional, so a refused creation holds no number;
  - the `(school_id, student_number)` unique index is the final backstop.
- **Concurrency.** The counter lock serializes Student creation within one
  School. Two real processes on DDEV interleaved 30 creations as 6–35 with no
  gap and no duplicate. Schools never contend.
- **No renumbering.** Existing numbers (`LDS-0001`, `0041`, ...) stay as they
  are. The counter already starts above every digits-only number, and
  alphanumeric numbers never count.
- **Correction stays.** Edit (web/API `update`) may still correct an existing
  Student's number:
  - it keeps the same unique rule (field error);
  - the counter trigger moves allocation past any digits-only value written
    there.

  This is correction of a record, not a second creation policy.
- **No schema change.** Per-School uniqueness was already database-enforced.
- **Gaps.** A refused creation holds no number (no gap). A committed number is
  never reused.

## 3. Decision: duplicate risk is a warning, never a merge

- **Rule** (`StudentDuplicateCandidates`): same School, same date of birth,
  AND either the same first and last name or the same full name.
  - Names are compared after lowercasing and removing every space, hyphen,
    apostrophe and other punctuation.
  - "Riya Kapoor" matches "RIYA kapoor" and "Riya Devi Kapoor".
  - "Kabir Das" + "Gupta" matches "Kabir" + "Das Gupta".
  - No phonetic, edit-distance, AI or cross-School matching.
  - No Guardian or contact data (ADR 0041 unchanged).
- **Inactive Students are candidates**, so a returning child is noticed. The
  warning shows their status. Nothing is reactivated automatically.
- **Refusal, not block.** A match raises `PossibleDuplicateStudentException`
  (409 `POSSIBLE_DUPLICATE_STUDENT`) and nothing is written. Conversion rolls
  back atomically, including any Guardian it was creating.
- **Acknowledgement.**
  - Resubmitting with the `duplicate_acknowledgement` from the warning creates
    a distinct Student.
  - It is an HMAC of the School, the normalized identity and the sorted
    candidate ids, and it is never stored.
  - It covers exactly that identity and candidate set. A changed name or a
    new matching Student since the warning means a fresh warning.
  - `true` or any invented value is not an acknowledgement.
- **Re-evaluated at commit, under the counter lock.**
  - A request never trusts the warning it showed earlier.
  - Of two simultaneous identical requests, the second waits for the first,
    sees it and is warned (two real processes).
  - There is no database uniqueness on name and date of birth: different
    children may share both.
- **Audit.** `student.created` records `duplicateWarningAcknowledged` and the
  candidate ids, only when there were candidates. A shown warning is not
  audited.
- **Who sees what** (`StudentDuplicateCandidateReader`, read at render time
  under the actor's own authority):
  - **Whole-School surfaces (Add Student, conversion):**
    - name, number, date of birth and status with whole-School
      `students.view`;
    - the current placement within the actor's `enrollments.view` scope;
    - otherwise a count only.
  - **Campus onboarding:**
    - compares only with Students in the actor's `students.view` Campus
      scope, so a match elsewhere is never revealed;
    - never shows a date of birth (F2-D13).
  - **API:** the count and the acknowledgement only, never names or ids (the
    Admissions Guardian-candidate precedent).
- **Idempotency.** The 409 is stored and replayed under its Idempotency-Key
  like any response. The acknowledged resend is a new request with a new key.

## 4. Rollover roll numbers (inspected, carried forward)

A Grade-level mapping of two source Sections into one target Section makes
preserved roll numbers collide. The contract already refuses to guess:
- the dry run blocks every colliding item (`roll_number_conflict_in_plan`);
- bulk Promote leaves every colliding Student undecided ("never first wins");
- execution invalidates the plan if a collision appears late.

There is no School roll-number ordering policy. `STUDENT-ENROLLMENT.md` and
ADR 0075 decided "explicit or preserve; no auto-generation". So the safe,
non-guessing behaviour exists. What remains is bulk UX: setting explicit
target roll numbers for many colliding items at once, together with SIS 5.2's
open bulk Repeat / Manual Review. That is a rollover-UX checkpoint, not
numbering, so it is carried forward unchanged.

## 5. Alternatives rejected

- **Keep typed numbers as an option.** Rejected: no product evidence that it
  is deliberate, and two rules in one School is the defect itself.
- **A School-configurable format or prefix.** No configuration exists, and
  inventing a numbering subsystem is speculative (rule 2).
- **Hard-blocking duplicates, or a database constraint on name + DOB.**
  Rejected: twins and namesakes are real.
- **Automatic linking or reactivation of the candidate.** Out of scope. The
  re-admission design stays open (ADMISSIONS §10).
