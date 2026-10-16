# Local UAT — Readiness Reassessment (2026-10-16)

## About this record

**What it is:** an evidence record. It tests, measures, classifies and
rescores. It fixes nothing. Each finding is recorded and reproduced only.

**Stage:** local UAT on the DDEV demo. This is not staging, pilot or
production qualification.

**Code under test:** published `main` at `3184d981`. Its product code is
UAT-C4 `78b68d6d`; later commits are tests and documentation only. The
held E16-R2 candidate `fb29aff5` was **not** used.

**Baseline:** [LOCAL-UAT-2026-10-15.md](LOCAL-UAT-2026-10-15.md). That
record and its correction sections W–Z are history and are not rewritten
here.

**Contract:** [ADR 0075](../architecture/adr/0075-v11-commercial-readiness-programme-contract.md)
§14–§17 (UAT-C1 to UAT-C4).

**Legal and PPR gates** are recorded as gates, never as defects.

## A. Method

**Environment:** fresh `ddev demo-reset` before each run. The School
timezone is Asia/Kolkata. All times below are UTC; the run spanned
16 Oct 12:58–14:15 UTC (18:28–19:45 IST).

**Browser:** Chromium (Playwright) with real sign-in and real TOTP MFA
enrolment. A monitor recorded every 5xx response, page error and failed
request.

**Three passes:**
1. **Anchors.** The C3/C4 re-test scripts (`c3b`, `c3c`, `c4`) on a fresh
   demo, plus a refund/reversal tail.
2. **Original journeys.** Every original journey script with no
   hard-coded record id, re-run in its original order on a fresh demo
   (29 scripts). They ran together with the C1 re-test, the C2 rollover
   re-test (which **executes** the rollover) and a new security-negative
   script.
3. **Targeted re-runs** with dynamic lookups. These replaced the scripts
   that had hard-coded 2026-10-15 ids (admission, payment, fee-run,
   charge, other-child, invitation and rollover-plan ids), and covered
   the steps that crashed in pass 2. They were:
   - Data Health trigger/repair;
   - staff invitation and HR link;
   - attendance exceptions;
   - Library dates;
   - FIN-20 fee run;
   - the open items;
   - crafted JSON posts.

**Coverage:** 190 observations, 169 distinct ids, 35 role/persona
contexts, including 115 School Admin pages crawled at 390 px and every
Home link of 11 personas.

**Reconciliation:** the 2026-10-15 scripts encode that day's UI. Since
then UAT-C1–C4 changed labels, links and flows, and several old scripts
hard-code a verdict for a descriptive row. Each raw non-PASS row was
therefore traced to one of three outcomes:
- a product behaviour (recorded below);
- a harness artefact, re-verified directly, with the evidence named in §M;
- a still-open known item.

## B. Former S1 findings

| ID | Result | Evidence (2026-10-16) |
|---|---|---|
| UAT-YR-07 | **PASS** | The 2027-28 row reads "Can be activated from 2027-04-01", with no Activate button. A crafted JSON POST, sent even after the rollover executed, returned 422: "This Academic Year starts on 2027-04-01 and cannot be activated before that date." Years unchanged (AY2026 active, AY2027 draft). For a year that has already started, the confirmation names the year that will close and says it cannot be reopened. |
| UAT-HR-20 / 20b | **PASS** | Pooja Sharma was created as EMP-000013 on a fresh demo. Employment and the TGT assignment were added. A second create with the same work email gives a clean field error, not a 500. |
| UAT-FIN-05 | **PASS** | Payment page → Correct this payment → Move to another charge. The amount is prefilled (5,000.00). The original payment and allocation are unchanged. History and current standing are shown. |
| UAT-FIN-24 | **PASS** (improved since C3) | "Cancel charge" is withheld while payments stand on a charge. The page explains instead: "move the money to the right charge … then cancel this charge." No raw id. |
| UAT-FIN-27 | **PASS** | The duplicate charge was cancelled after the move. The 5,000 now counts on Term 1. The statement shows the correction (no concession). The receipt lists "Later corrections". Isha owes 20,000.00. |
| UAT-FIN-08 | **PASS** | Finance → Outstanding fees: 15 rows, 240,260.00 outstanding, 240,000.00 overdue as of School date 2026-10-16, 12 overdue. Filters and the "Isha Nair" search work. |
| UAT-YR-01b | **PASS** | The 38-Student rollover was decided and executed with 25 decision operations, against about 152 per-Student actions at Local UAT. Bulk preview, unresolved rows shown, saves keep the page and filters. |

**All five distinct S1s re-test as PASS. No former S1 regressed.**

## C. UAT-C1 to UAT-C4 corrected items

| Item | Result | Evidence |
|---|---|---|
| ATT-10 (School-timezone today) | PASS | At 13:36 UTC (19:06 IST) the register date defaulted to 2026-10-16 and the register was submitted. |
| LIB-01 (due today) | PASS, with the C4 regression in §D | `min` = School today. Checkout due today is accepted, with no 500. |
| YR-04 (Section must match Grade) | PASS | The target list holds Grade 7 Sections only. Switching to Grade 8 clears a stale choice. A crafted wrong-grade mapping saved nothing. |
| A11Y-01 (keyboard pickers) | PASS | Library checkout, Timetable (five pickers), Inventory and Visitor were operated by keyboard only. Each has a combobox role, aria-expanded and aria-activedescendant, plus live "Selected: …" and no-result announcements. |
| SRCH-01 | PASS | "Isha Nair", "Nair Isha", "kapoor", "Riya Kapoor" and the fragments "0006" and "0103" all find the Student. |
| Labels (ATT-08 labels, YR-01c) | PASS | The register class list reads "Grade 6 · A" to "Grade 8 · B" in Grade order. Rollover rows name the Grade. |
| Year defaults (ADM-15/17, FIN-11) | PASS | "2026-27 — current year" is preselected, then upcoming draft, then closed. |
| Timestamps (UX-02, FIN-10) | PASS for instants; **regression for Library calendar dates (§D)** | Inventory "16 Oct 2026, 7:00 pm" (School time); no raw ISO on the charge. |
| Messages (UX-01, FIN-23) | PASS | "Section created." shows once. Fee head, structure, Teaching assignment and Data-Health-repair flashes appear. |
| Finance accounts (FIN-11) | PASS | Receivable lists asset accounts only; revenue lists income accounts only. |
| Duplicate warning (FIN-20 ad-hoc) | PASS | An ad-hoc same-name, same-year charge shows the similar charge and "assess it anyway". |

## D. Regressions

**RA-LIB-DATES — S2, display only. Introduced by UAT-C4 (`78b68d6d`).**

The server sends Library loan dates as School-local calendar dates
(`toDateString()`, a UAT-C1 decision). UAT-C4 then passed them through
`formatDateTime()`, which reads a bare date as midnight UTC. This
contradicts the header of `resources/js/datetime.ts`: "Values that are
already School-local calendar dates (due dates …) are not instants: show
them as they are".

| School timezone | Loans list, loan page, catalogue copy show |
|---|---|
| Asia/Kolkata | The correct day plus an invented time: "Due 16 Oct 2026, 5:30 am" |
| America/New_York (temporarily switched, then restored) | **The previous day**: a loan due 16 Oct shows "15 Oct 2026, 8:00 pm"; checked-out and returned dates likewise |

- **Affected:** five values on three pages:
  - Circulation index (`dueAt`, `returnedAt`);
  - Circulation show (`checkedOutAt`, `dueAt`);
  - Catalogue show (`activeLoan.dueAt`).
- **Not affected:** stored values, overdue status, fines and checkout
  validation, which are all server-side.
- **No other `formatDateTime` call site** receives a date-only value. All
  42 others carry ISO instants; Visitor `checkedOutAt` was checked.
- **How the C4 re-test missed it:** that re-test exercised the checkout
  picker, not the list rendering.

**No other regression was found.**

## E. Integrated journeys

### Finance

Every step passes:
1. setup (fee head, structure, schedule, activate);
2. assess (ad-hoc and run);
3. outstanding;
4. payment with MFA, rejecting overpayment;
5. receipt and statement;
6. reallocation, refund (RF-001, cash), and reversal (banner, receipt
   kept and marked REVERSED, "Record the correct payment" link for the
   same Student);
7. recreate;
8. duplicate warning;
9. repair.

The MFA rules all hold:
- no code: "Enter a current authentication code.";
- wrong code: "That code is not valid." (JSON);
- a reused code is refused;
- a fresh code succeeds.

**One open gap, demonstrated live: FIN-20, fee runs (§K).**

### Rollover

These steps pass:
- draft year;
- 7 B and 8 B created;
- grade and Section mappings;
- bulk decisions;
- roll-number strategy;
- validation;
- execution.

Activation follows the date rule: it is refused until 2027-04-01. The
completed plan links the next steps, and the plan page fits at 390 px.

**Prior-year closure and the closed-year Data Health clearing cannot be
exercised in the browser before 2027-04-01. That is by design (C1
decision), and those paths keep their automated tests.** The closed-year
Data Health check ran and correctly lists nothing to complete.

### HR

| Step | Result |
|---|---|
| Employee | PASS |
| Employment and assignment | PASS |
| Leave (configuration, policy, allocation, weekly-off refusal, request, approval) | PASS |
| Leave on the staff register | PASS |
| Staff attendance | PASS |
| Self-service (my leave, my attendance, my payslips) | PASS |
| Invite → email → create account → sign in | PASS. Lands in the School; My Attendance asks for MFA; a reused link refuses on submit. |
| **Link the new account to its Employee** | **GAP**: API-only, no browser control (§F) |
| **Reporting manager** | **GAP**: free-text "Assignment UUID" field (§F) |

### Attendance and teacher

Registers 12–15 Oct are taken. The exceptions page lists Aarav (absent)
and Ananya (late).

Teachers:
- refused My Attendance until enrolled in MFA;
- then took their own register;
- had a future date refused.

ATT-10 passes.

### Library

These pass:
- due today;
- a future due date;
- checkout and check-in;
- the on-loan copy cannot be checked out again;
- a Teacher calling the Library Student picker endpoint gets 403.

Date display fails: see §D.

### Keyboard and accessibility

As §C. No browser accessibility errors were captured.

## F. New findings (not regressions)

| ID | Sev | Finding |
|---|---|---|
| **UAT-STF-05** (was BLOCKED by HR-20) | S2, **pilot-relevant** | An invited staff account can be linked to its HR Employee only through `POST /api/v1/schools/{school}/employees/{employee}/link-user` (ADR 0063 §6; ADR 0071 §26.1 "the User link over the JSON API"). No Employee, HR or Staff-accounts page offers it. Without the link a new teacher has no ActingEmployee, so My Attendance, leave and payslips do not work. The office can still take registers. Masked on 2026-10-15 because Employee create failed. |
| **UAT-HR-23** | S2 | Setting a reporting manager means typing "Manager Assignment id (blank clears it)" into an "Assignment UUID" text box, and assignment ids are shown nowhere. Workaround: leave requests without a manager are decided by HR (UAT-HR-13). Not reached on 2026-10-15. Code dates from Phase 8A (`b33af50a`). |
| UAT-DH-18 (label) | S3 | The Data Health teaching-assignment row still reads "C (6C)", while Teaching assignments reads "Grade 6 · C". |
| UAT-UX-Phone (new pages) | S3 | Two pages not seen overflowing before: `/app/analytics/curriculum-coverage` (517 px; its table has no scroll container) and `/app/subject-offerings` (427 px). Neither page changed since 2026-10-15; the cause is data or crawl reach. |

## G. Security negative checks (no breach)

| Check | Result |
|---|---|
| **Another School (Annexe admin) — reads** | 404 for every Demo School Student, history, fee statement, payment, receipt and charge. |
| **Another School — Student searches** | Four Student-search endpoints return empty. The Students list and Data Health show no Demo School names. |
| **Another School — crafted mutations** | Charge cancel: 404. Correction POST: 302, which is the MFA-required redirect while the admin had no factor, the same for any id. With a **valid fresh code**, the foreign payment returns **404**, identical to a nonexistent payment, and nothing is written (RA-SEC-01b). |
| **Teacher on admin URLs** | 403 on all 12 admin, finance, Data Health, staff, rollover, year and payroll URLs; on three other modules' Student searches; and on a crafted charge. |
| **Principal — finance mutation** | Create charge, record payment, cancel charge, correct payment: all 403. |
| **Accountant — reversal/refund** | Crafted reversal 403 and refund 403. Only "Move to another charge" is offered. A move to another Student's charge is refused ("same Student"). |
| **Guardian — unrelated child** | The unrelated child's fees and attendance return 404. Own child plus someone else's payment returns 404. Staff URLs return 403/404. |
| **Data Health and search boundaries** | Accountant, Librarian, HR, Reception, Canteen and Student each get 403 on Data Health. Cross-module Student searches get 403. The Principal holds Data Health (200). |
| **Original matrix (UAT-SEC-*)** | Re-run: every role 403/404 as before. Student persona: landing page only. |

**S0: none.**

## H. Data Health trigger and repair

| Condition | Trigger | Repair | Result |
|---|---|---|---|
| Unplaced Student (admission without placement) | Listed with an Enroll link | Enrolled from the finding | PASS (DH-10/11) |
| Section with no timetable (academic setup) | New 2026-27 Section 6 C listed | — | PASS (DH-13) |
| Inactive Student with an active placement | One-click "Mark inactive" | Mark active | PASS (DH-14/15) |
| Placement in a deactivated Section | 6 C deactivated with a Student on it | Transfer to an active Section | PASS (DH-16/17) |
| Teaching assignment on a deactivated class | Listed | Assignment ended with today's last day | PASS. The finding truthfully stays listed until the end date passes (as on 2026-10-15). |
| Placement open in a closed year | Not triggerable before 2027-04-01 | — | Date-gated by design |

The history page records every step: admitted, placed, Guardian linked,
marked inactive and active, moved.

## I. Responsive check at 390 px

**Fit:**
- every daily-operations page: attendance (take, register, exceptions),
  finance (outstanding, payment, charge, receipt, statement, record),
  Library, Students, timetable, admissions, rollover plan;
- every teacher page;
- every Guardian portal page.

**Overflow:** 13 of about 130 pages.

| Page | Width | Class |
|---|---|---|
| `/app/automation` | 586 | P2 (administrator configuration) |
| `/app/analytics/curriculum-coverage` | 517 | P3 (report; table needs a scroll container) |
| `/app/settings/staff` | 484 | P2 (administrator; Staff accounts) |
| `/app/communications` | 449 | P2 (staff-facing daily for the Communications role) |
| `/app/communications/templates` | 435 | P3 |
| `/app/subject-offerings` | 427 | P3 |
| `/app/syllabus`, `/app/syllabus-delivery`, `/app/learning-content`, `/app/assignments`, `/app/canteen-outlets` | 416 | P3 |
| `/app/canteen-orders` | 406 (was 445) | P3 |

**Changes since 2026-10-15:**
- fixed: `/app/inventory-stock` (C4);
- improved: canteen orders;
- newly seen: two pages, in §F.

**UX-Phone overall: P2.** It does not block local UAT, staging or pilot.
Fix the four P2 pages before phone use by administrators is a pilot
expectation.

## J. Open items: reassessed

Blocking columns: local UAT / staging handoff / pilot / production.

| Item | Status 2026-10-16 | UAT | Staging | Pilot | Production |
|---|---|---|---|---|---|
| UX-Phone | Open: 13 admin/config pages (§I) | No | No | No (P2) | Yes, for the P2 pages |
| UX-03 (single-School auto-select) | Open. A one-School teacher's deep link `/app/my-leave` lands on `/app` with "Select a School". | No | No | No (S3) | No |
| UX-04 (portal money separators) | Open. "Charged 49075.00" on the portal; staff side shows "49,075.00 INR". | No | No | No | Should fix (parent-facing) |
| FIN-22 (raw canteen ids) | Open. "Canteen order 01a144d9-85cd-…" on staff and parent statements. | No | No | **Yes**, if canteen is piloted (parent-facing) | Yes |
| SRCH-02 (Guardian phone/email search) | Open. The Guardians page filters by name only. Privacy boundary: not implemented by design, and the decision is pending (D-05/D-06 family). | No | No | No | Decision needed |
| ATT-08 "mark all present" | Open. No control; each of up to 40 rows is clicked. | No | No | No (P2 friction) | No |
| FIN-20 remainder | Open, demonstrated (§K) | No | No | **Yes** (conditional) | Yes |
| DOC-01 (demo copy) | Open. Four "(no menu link)" descriptions are stale. | No | No | No | No (demo only) |
| UAT-STF-05 (Employee link) | New (§F) | No | No | **Yes**, unless an operator procedure is accepted | Yes |
| UAT-HR-23 (manager by UUID) | New (§F) | No | No | No (workaround) | Yes |
| RA-LIB-DATES | Regression (§D) | No | **Fix before handoff** | Yes | Yes |

**Still open from 2026-10-15, unchanged (S2/S3):**
- ADM-14: no applicant edit;
- ATT-06: one-click correction;
- ATT-07: no multi-day absence view;
- ATT-09: future dates offered;
- ENR-01: Enroll and Transfer list next-year Sections first, observed
  again in DH-11/17;
- STU-14;
- HR-12;
- LIB-05;
- INV-06;
- DH-12.

## K. FIN-20 remainder: classification

**Fee-run duplicate detection: open, S2 → pilot blocker (conditional).**

The demo already carries an ad-hoc "Term 1 tuition fee 2026-27" (25,000)
for each Grade 6 Student. A Grade 6 Term 1 fee run then previewed "12
ready · 0 already assessed" with no warning and charged 12 × 24,000 =
288,000.00 INR. Aarav Sharma's statement now shows:
- "Term 1 tuition fee 2026-27": 25,000, paid;
- "Tuition Term 1": 24,000, outstanding.

The run's "already assessed" recognises only its own structure-line
charges. The C4 similar-charge warning covers ad-hoc assessment only.

- **Browser remedy:** void the run items, or cancel those charges.
- **Why it is a pilot blocker:** any School that adopts mid-year with
  existing term charges is double-billed silently on its first run.
- **Why it is conditional:** a pilot whose fee history starts in Lycenza
  is unaffected.

**Mid-term proration: not a defect.** Owner decision D1 ("no proration
ever"; `FeeAssessmentRunService`, `FeeAssessmentItemExecutor`,
FINANCE.md). It stays a product option. Not a blocker at any stage.

## L. Error monitoring

| Signal | Count |
|---|---|
| HTTP 5xx (browser monitor, every session) | **0** |
| Page errors (uncaught JS) | **0** |
| Failed requests (excluding aborted navigations) | **0** |
| Console errors captured | 0 |
| Server log 13:00–14:59 UTC (`laravel.log`) | 439 entries, all INFO; **0 WARNING/ERROR** |

Local UAT on 2026-10-15 had recurring 500s (HR-20, LIB-01); none recur.

## M. Harness artefacts (re-verified, not product faults)

| Raw row | What actually happened | Direct evidence |
|---|---|---|
| r1 YR-07 / YR-04 "NOT FIXED" | Chromium returns status 0 for a `redirect: 'manual'` fetch | Years unchanged; mappings still empty; the JSON 422 in §B |
| r1 HR-20 "NOT FIXED" | j14 had already created Pooja earlier in the same run | EMP-000013 created (j14); duplicate email refused cleanly |
| r1 / j15 LIB-01 / LIB-02 | The date regex met the §D display regression | Checkout accepted and listed |
| c3b UAT-C3-MFA (Accountant) "wrong code" | The second fill raced the form's `mfa_code` reset | JSON POST: 422 "That code is not valid." |
| c3b crash at `#payment-record-mfa` | Timing | Review step shows the code field (probe) |
| c3b-tail UAT-C3-REVERSE | The confirmation tick registered late, so the server answered the confirmation check first | Reversal completed with banner, marked receipt and replacement link; reused-code refusal shown in c3c |
| c3c UAT-C3-TENANT / RA-SEC-01 (302) | MFA validation runs before the lookup | RA-SEC-01b: 404 with a valid code, as for a nonexistent id |
| j5/j5b/j5c ATT-01/04 | The locator expected the pre-C4 label "A ·" | `j5x` with "Grade 6 · A": PASS, exceptions listed |
| j2 ADM-13 | Badge text check (the same as 2026-10-15) | Admissions list: Kavin Rejected, Sana Withdrawn |
| j7 FIN-01 / FIN-06 | The browser pattern blocks the submit (as 2026-10-15); the receipt link is the receipt number | Nothing created; receipt opened (c3b) |
| j9 FIN-12 "riya=true" | C4 now admits to the current year (ADM-15 fix), so Riya is correctly in 2026-27 | — |
| j13b HR-10 | The balance was already allocated (HR-07) | Approval correct |
| j16 INV-05 | Text slicing | Row read: MAIN-STORE 15.000 box |
| j17x GRD-04 | Wording "Due" (was "Outstanding") | Charged, Paid, Due correct (UX-04 still open) |
| j18 crash / STF-01 / STF-03 | The School Admin had no MFA yet in that run | j18x and j18c: invitation emailed, account created, sign-in lands in the School |
| j22 SRCH-01, j5c ATT-06/07/08, j3 STU-14 | Hard-coded 2026-10-15 verdict text, not a measurement | §C measurements |
| r3 UAT-SEC-YR-02 (teacher) | Teacher sign-in stopped at the MFA step | RA-SEC-02: Teacher `/enrollment-rollovers` 403 |
| j6 TCH-02 | otpauth URI not exposed (QR only), as before | Enrolment succeeded (TCH-03) |

## N. Weighted readiness score

**This is a new score.** It is not added to, or derived from, the 72 % of
2026-10-15.

**Weights, 100 points across the 22 applicable journeys (journeys 23
Marks and 24 Payroll stay legally gated and are excluded):**
- **Critical (7 each, 56 in total):** the daily core a School cannot run
  without. Admission to active Student, class attendance, teacher day,
  payment/receipt/statement, correcting a charge or payment, collections,
  year transition, and authorization and tenancy.
- **Important (4 each, 32 in total):** configuration, Student records and
  search, placement and repair, fee setup and runs, employee onboarding,
  staff invitation, Data Health, and phone width.
- **Supporting (2 each, 12 in total):** onboarding a School (platform),
  concessions, leave and staff attendance, Library, stores, and the
  Guardian portal (development only).

**Journey values:**

| Result | Value |
|---|---|
| PASS | 1.00 |
| PASS WITH FRICTION | 0.85 |
| PASS WITH MAJOR GAP | 0.70 |
| PARTIAL | 0.50 |
| BLOCKED | 0 |

**Open-defect deductions:** −3 per open S1, −2 per open regression.

| # | Journey | Weight | 2026-10-15 | 2026-10-16 |
|---|---|---|---|---|
| 1 | Onboard a new School | 2 | Friction | Friction |
| 2 | School configuration | 4 | Pass | Pass |
| 3 | Admission to active Student | 7 | Friction | Friction (ADM-14, ENR-01) |
| 4 | Student record, history, search | 4 | Friction | Friction (SRCH-02, STU-14) |
| 5 | Placement, transfer, Data Health repair | 4 | Pass | Pass |
| 6 | Class attendance (office) | 7 | Friction | Friction (mark-all, ATT-06/07/09) |
| 7 | Teacher day | 7 | Friction | **Pass** |
| 8 | Fee setup and assessment run | 4 | Friction | **Major gap** (FIN-20 run, now demonstrated at scale) |
| 9 | Payment, receipt, statement | 7 | Pass | Pass |
| 10 | Correct a charge or payment | 7 | Partial | **Pass** |
| 11 | Collections follow-up | 7 | Blocked | **Pass** |
| 12 | Concession maker-checker | 2 | Pass | Pass (carried; covered by C3 automated tests, not re-driven) |
| 13 | Onboard a new employee | 4 | Blocked | **Friction** (HR-23) |
| 14 | Leave and staff attendance | 2 | Friction | Friction |
| 15 | Staff invitation to working account | 4 | Pass | **Major gap** (Employee link API-only) |
| 16 | Library circulation | 2 | Friction | **Major gap** (C4 date regression) |
| 17 | Stores receive/issue | 2 | Pass | Pass |
| 18 | Guardian portal (development only) | 2 | Pass | Pass |
| 19 | Year transition | 7 | Partial | **Friction** (closure not exercisable before 2027-04-01) |
| 20 | Data Health | 4 | Pass | Pass |
| 21 | Authorization and tenancy | 7 | Pass | Pass |
| 22 | Phone width | 4 | Friction | Friction |

| | Journey score | Deductions | **Score** |
|---|---|---|---|
| 2026-10-15 results, same weights (for comparison only) | 76.2 | 5 S1 → −15 | 61.2 |
| **2026-10-16 (this record)** | **91.5** | 1 regression → −2 | **89.5** |

**How to read the score:**

| Band | Meaning |
|---|---|
| 90–100 | Ready. Remaining items are cosmetic or optional. |
| 80–89 | **Ready with limitations.** Every core journey works in the browser. Named gaps have workarounds and a clear owner. |
| 70–79 | Usable for guided UAT only. Several core journeys have partial results. |
| 60–69 | Not ready for School-run UAT. Core gaps remain. |
| Below 60 | Not ready. |

**89.5 sits at the top of 80–89.** The single regression and the two
major gaps keep it below 90.

## O. Determinations

**Local UAT: READY WITH LIMITATIONS.** All 22 applicable journeys work
end to end in the browser on the demo. Zero S0, zero open S1, zero 5xx.
The limitations are:
- one S2 display regression (Library dates);
- two new S2 gaps (Employee link, manager by UUID);
- the FIN-20 run gap;
- the open S2/S3 list in §J.

**Product-side staging handoff: CONDITIONAL.** The one condition is the
RA-LIB-DATES fix. A regression should not be handed to staging. Every
other open item is a pilot or production condition, not a staging one.

**Actual staging: NO.** It is blocked independently of the product, and
neither blocker is a product defect or within this record's scope:
- **PPR:** there is no staging environment. R-00a was approved but is
  blocked, with no AWS session and nothing created (PPR.2D).
- **E16-R2 is HELD:** the application image still carries three Critical
  nginx advisories in the authoritative scanner. It cannot reach
  VERIFIED until a Debian-qualified fix exists.

**Pilot: NO.**
- **Product conditions:**
  - RA-LIB-DATES;
  - FIN-20 fee-run detection, or an adoption rule of no prior
    same-period charges;
  - a browser Employee↔User link, or an accepted operator procedure;
  - FIN-22, if canteen is piloted.
- **Legal gates, kept separate:** E21 qualified ratification pending;
  E33 conditions on production teacher grants; E35–E44 marks and
  results; E45/ESI-12 payroll; E17/E19/E20 deferred to pre-production
  email; E30–E34; E46 Student portal.
- **PPR and E16:** as for staging.
- **E16 dates:** the current decision `OWNER-0O-E16-2026-10-16` is valid
  through 2026-11-12.

## P. Is UAT-C5 next?

**No. UAT-C5 (DOC-01 demo copy and an administrator guide) is outranked**
by one regression and three operability gaps. Each of them affects staging
or pilot. DOC-01 affects neither.

**Exactly one next action (not implemented here):** the owner authorises
**UAT-C6, "pre-staging product corrections"**. Its scope:
1. **The RA-LIB-DATES regression.** Show calendar dates as dates. No
   decision needed.
2. **A browser Employee↔User link** on the Employee page. It uses the
   existing governed `EmployeeService::linkUser()`/`unlinkUser()` path
   (ADR 0063 §6, HR self-administration guard, rule 95). No new rule.
3. **A reporting-manager picker** in place of the UUID field.
4. **FIN-20 fee-run same-period detection.** This needs **one owner
   decision**: warn and confirm per item, or exclude automatically.

UAT-C5 follows UAT-C6.

## Q. Evidence location

- **Scratch harness and registers:** Claude scratchpad `uat/` (not
  committed):
  - `ra-anchors.jsonl`, `ra.jsonl`, `ra2-final.jsonl`;
  - error logs `errors-anchors.jsonl`, `errors.jsonl` (both empty).
- **Demo data:** demo data only, and nothing from UAT is committed. The
  demo was reset after testing.
