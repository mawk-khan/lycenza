# HR.R1 — Final Cross-ERP Reconciliation & Closure Audit (reserved)

Status: **RESERVED by HR.0A** (2026-11-03). Not started. **Never
auto-start.**

## 1. Purpose and timing

HR.R1 runs only after the wider ERP programme has connected HR with each
of these:
- Organization;
- SIS;
- Finance / Payroll;
- Inventory;
- Transport;
- Timetable / Academic;
- IAM / security;
- retention / audit.

**Closure states:**
- **Implementation-complete:** HR may reach this at the end of HR.16.
- **Permanently closed:** HR is declared closed **only when HR.R1
  passes**.

## 2. Preconditions

- HR.1–HR.16 are each published, or each explicitly DEFERRED with an owner
  decision recorded in `HR-REFERENCE-CAPABILITY-MAP.md`.
- The HR reference pack fingerprint is unchanged
  (`HR-REFERENCE-CAPABILITY-MAP.md` §1), or a re-capture has been mapped.
- A green full regression exists on the HR.R1 baseline SHA.

## 3. Mandatory verifications (each PASS / FINDING with evidence)

| # | Verification | Method |
|---|---|---|
| R1-01 | **No duplicate source of truth.** Every row of `HR-DOMAIN-OWNERSHIP-MAP.md` §1 has exactly one writer | grep writers per table; architecture guards |
| R1-02 | **No orphaned integration.** Every cross-domain consumer reaches HR only through an Application reader; X-1 is closed; Payroll's `EmploymentRecord` coupling is resolved or recorded as an owner-approved exception | import scan (`use App\Domain\HR\Infrastructure` outside HR) |
| R1-03 | **No missing temporal relationship.** Assignment, category, employment type, manager, shift, calendar, policy and entitlement each answer an as-of-date query | as-of-date tests per concept |
| R1-04 | **No authorization gap.** Every HR route has a coverage contract (rule 98); manager relationship ≠ data visibility; export and column authority match the screen | `CoverageGuard`, deny tests |
| R1-05 | **No stale reporting contract.** Every HR report is in the registry with pinned filters, sorts and columns; none counts people outside the Analytics gate | registry pin tests |
| R1-06 | **No cross-tenant leakage.** Raw-SQL / RLS tests for every HR table, report and export | `RawIsolationTest` pattern |
| R1-07 | **No unresolved lifecycle issue.** Separation closes assignments, teaching ownership, reporting lines, shifts, Transport driver assignments and Timetable entries per each owner's contract | lifecycle integration tests |
| R1-08 | **Payroll / attendance / leave reconciliation.** Every payroll run's HR inputs reproduce from Leave and StaffAttendance evidence; post-approval source changes surface (`differenceState`); no LOP money effect without HRX-L4 | reconciliation tests |
| R1-09 | **Offboarding dependencies.** Inventory custody, Library loans, Finance clearance, Timetable substitution and Transport each answer the clearance question | clearance integration tests |
| R1-10 | **Gates intact.** HRX-L1–L4, ADR 0036, the ADR 0040 person-count gate and D-11: each still refused, or opened only by a recorded decision | gate register re-verification |
| R1-11 | **Retention.** Every HR table has a unit; purge and hold paths are tested | retention catalogue tests |
| R1-12 | **Security findings.** S-1…S-16 and X-1…X-3 are each FIXED, or a recorded residual with an owner decision | finding register |

**Evidence recorded (HR.14, 2026-11-05) for R1-05:** `HrReportRegistry` is the
closed catalogue (`Tests\Feature\HR\Reports\HrReportsTest`: 18 entries, owners,
authority, reference ids, no prohibited column, no headcount/birthday/biometric/
salary/staff-child/Inventory entry); counts are task occurrences by status only;
the ADR 0040 rows stay GATE. Integrations authoritative today: HR ↔ Leave
(balances), HR ↔ Staff Attendance (absentees, corrections), HR ↔ WorkforceTasks;
still blocked: HR ↔ SIS (staff children), Inventory, Finance, Payroll salary logs,
Timetable substitution, Analytics (headcount, rates).

## 4. Output

- An HR.R1 record in `docs/hr/` with a verdict of **PASS**, **PASS WITH
  CORRECTION** or **BLOCKED**.
- Rule for the verdict:
  - any BLOCKER or MAJOR finding means **not closed**;
  - corrections are their own published units, then HR.R1 is re-run.
