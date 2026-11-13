# HR.R1-C35 WO17-N1, Option A: after a decision, a known-same account of the paid Employee, or of the author, is that person (2026-11-13)

**HR.R1 stays OPEN.** This implements Option A of owner decision D-WO18-1 (`HR-R1-C35-WO18-OWN-CASE.md` §5): WO17-N1 rated MEDIUM, the post-decision independence functions treat an account known to be the same person as the paid Employee like the Employee's own.

| | |
|---|---|
| Baseline | `99ba7df0` = `main` = `origin/main`; `3c292e2a` the WO-18 code; clean tree; the historical stash and `integration/e16-r2` preserved; development database at 396 migrations (current until this change) |
| Authorized | D-WO18-1 (2026-11-13) and the owner's dispatch of this unit, the same day. The author side was added by owner decision D-WO17N1-1 (2026-11-13, §4) |
| Not changed | Payroll amounts, formulas, calculations, sources, approvals, postings, attestations, reversals and reconciliations already recorded; the decision-time checks (WO-16, WO-17); the attestation and review workflows; capabilities; WO-19 to WO-22; RG-ID-1 |

## 1. The finding and its root cause

**WO17-N1** (`HR-R1-C35-WO17-KNOWN-SAME-BENEFICIARY.md` §5, first LOW): after a decision, three functions asked "is this approver the Employee of a change" through the approver's own links only. Re-rated MEDIUM by the owner after the WO-18 reproduction.

**Root cause.** Four Employee-side clauses compared `employee_account_links.user_id` with the approver's id (the author side, found by this unit's review, is §4):
- `payroll_run_first_paid_compensation`: an approval by the change's Employee does not discharge it;
- `payroll_run_independence_exception`: the run's approver is the Employee of a change it now pays first;
- `payroll_reversal_affected_runs`: its "discharged" step and its candidate filter. With only the exception fixed, a posted alias-approved run would never reach the exception check, so no obligation would open.

`payroll_run_independence_version` needs no change: it reads the exception.

**Reproduced before the fix.** The new tests ran with the migration not applied. 5 of 7 failed for the expected reasons:
- alias exception rows 0 (the Employee's own account: 1);
- no obligation for the posted alias-approved run;
- an alias approval still discharged the raise;
- a raw posting without attestation was accepted.

The two controls passed: the own account, and an unknown or other-School approver.

## 2. What was built

Migration `2027_02_22_090000_treat_known_same_payroll_approver_as_employee` transforms the three functions' LIVE definitions (exactly-once anchors, rule 146). `down()` restores each exactly.

**One relation, asked once per approver.** Each function begins with a materialized set:

```
known AS MATERIALIZED (
    SELECT a.approver, k.user_id
      FROM (SELECT DISTINCT o.approved_by_user_id AS approver FROM payroll_runs o
             WHERE o.school_id = p_school AND o.run_kind = 'regular' AND o.status IN ('approved', 'posted')
               AND o.approved_by_user_id IS NOT NULL) a
     CROSS JOIN LATERAL employee_account_link_same_person_accounts(p_school, a.approver) k)
```

That is the distinct approvers of the School's standing regular runs (few), each asked the C34 relation once. Every per-row check is then an `EXISTS` against this small set, never a call of the non-inlinable function. The C7-R1 materialization of `payroll_reversal_affected_runs` is kept.

**The checks:**

| Function | Employee side (D-WO18-1) | Author side (D-WO17N1-1) |
|---|---|---|
| `payroll_run_first_paid_compensation` (discharge) | an approval by an account known to be the change's Employee does not discharge it | nor one by an account known to be its author |
| `payroll_run_independence_exception` | the run's approver is known to be the Employee of a change it pays first | or known to be its author |
| `payroll_reversal_affected_runs(uuid,uuid,uuid)` | "discharged": a reversed run's approval by a known Employee never discharged; candidates: runs approved by a known Employee | the same for the author |

`payroll_run_independence_version` and the evidence list in `PayrollPostingIndependence::details()` never filtered identity, even for one's own account (they list reversals as evidence); unchanged.

**Effect.** It is the same control path as one's own account (rules 144, 145):
- **Before posting:** an independent attestation is required.
- **Once posted:** a reversal that implicates the run opens an obligation.
- **Discharge:** such an approval never discharges.
- **Link changes:** a later link alone can create the exception before posting.
- **Nothing rewritten:** no approval, posting, attestation or reversal is rewritten.

**Pins.** `KNOWN_SAME_PERSON_BODIES` pins the three bodies, the overloaded `payroll_reversal_affected_runs(uuid,uuid,uuid)` by exact signature (review L-1), with INVOKER mode and `pg_temp` last. `KnownSamePersonCoverageTest` checks:
- one relation call per function;
- the materialized `known` set;
- 2, 2 and 4 lookups of it.

## 3. Evidence

| Proof | Test | Result |
|---|---|---|
| **Employee side.** The alias D approves November; October (which paid the raise first) is reversed. One exception row, as for the Employee's own account; posting without attestation is refused (`PostingAttestationRequiredException`) and nothing reaches Finance; D (the approver) cannot attest; an independent poster attests and the run posts; approval fields unchanged | `PayrollKnownSameIndependenceTest` | pass |
| The Employee's own account behaves as before (control) | same | pass |
| A posted alias-approved run gets a reconciliation obligation when the reversal lands | same | pass |
| An alias approval never discharges the change (first-paid set of the next run includes it) | same | pass |
| **The discharged step (review L-2).** Reversing a run an alias approved implicates nothing new: no obligation. Removing only that clause in the database makes the test fail | same | pass; probe detected |
| A corrected link stops counting (the exception goes); an unknown approver, or an alias joined only in another School, creates none | same | pass |
| **Raw runtime role:** posting the alias-approved run without its attestation is refused by `trg_payroll_runs_posting_independence` | same | pass |
| **Author side (D-WO17N1-1).** An account known to be the author approves November; after the reversal: one exception row, posting refused, an obligation once posted, never a discharge. The author's own account is unchanged (control) | same (4 tests) | pass |
| **Two sessions** (holder held open, contender observed blocked): the reversal first makes the waiting posting need an attestation; the posting first stands and the waiting reversal opens its obligation | `PayrollKnownSameIndependenceConcurrencyTest` | pass |
| **Scale (review M-1)**, 10,000 entries, analyzed, as the runtime role, under a 30 s timeout, on the expensive path (an independent run Z makes every alias check answer "no" for half the rows). The alias-approved run and the author-approved run are found, the independent one is not | `PayrollKnownSameLargePopulationTest` | pass |
| WO-17's history test now requires the attestation a later link creates, then posts (rule 145; was asserting the gap) | `PayrollKnownSameBeneficiaryTest` | pass |
| Coverage: one relation call per function, the materialized `known` set, 2/2/4 lookups; verifier body, mode and path pins (the overload by signature) | `KnownSamePersonCoverageTest`, `OperatorVerificationCommandsTest` | pass |

**Scale.** Measured once on DDEV, as the runtime role, at 10,000 entries:

| Version | Discovery | Exception | First-paid set |
|---|---|---|---|
| Per-row (first version) | over 30 s (timeout) | — | — |
| Final (per approver) | 414 ms | 106 ms | 93 ms |

The original `PayrollReconciliationLargePopulationTest` still passes with its query bounds.

**Planted bypasses** (database mutations on the test database, each restored and its hash re-read):
- the exception's Employee clause removed: 7 failures;
- the candidate filter's clause removed: 2;
- the discharged step's clause removed: 2;
- the exception's author clause removed: 5.

**Suites** (final version): Payroll 583, Postgres 1,259, Authorization 235, Operations 15, HR 1,501, Leave 213, all pass.

**Rollback proof:** schema IDENTICAL after rollback and re-apply (29 schema lines removed); `platform:verify-database` failed=0, after an isolated reset (the proof database held an earlier draft of this unpublished migration).

**Reproduction of the author side** (throwaway test, deleted, before the decision):
- November approved by the author's own account: 1 exception row, posting refused.
- November approved by an account known to be the author: 0 rows, **posted**.

## 4. Reviews and the author-side decision

**First independent review** (of the per-row version). **No HIGH.**
- **M-1 (scale), fixed:** the relation was called per row inside an `OR`, unmeasured at scale. Confirmed: over 30 s. Fixed by the per-approver `known` set; the new scale test reaches the expensive path.
- **M-2 (author side):** an account known to be the change's author still discharged it and kept a run independent; reproduced (§3). **Owner decision D-WO17N1-1 (2026-11-13): MEDIUM, fixed in this unit.**
- **LOWs, all fixed:**
  - L-1: the overload is pinned by signature;
  - L-2: a behaviour test of the discharged step;
  - L-3: rules 142, 144 and 152 and the gate register updated;
  - L-4: the history test renamed (`…never_rewritten…`).

**Final-state delta review** (of the redesign, the author side and the fixes). **No HIGH, no MEDIUM.** It confirmed:
- every approver looked up is in `known` (no fail-open), and a NULL author still discharges as before;
- the four author sites mirror one's own account; no new lock;
- the anchors, `down()` and the signature pin;
- that the scale fixture reaches the expensive path.

Its five LOW documentation items were fixed: the inventory row (11 replacements), the stale gate-register residual, rule 142's cross-reference, the verifier comment, and this record.

## 5. Dispositions

| Item | Status |
|---|---|
| **WO17-N1** (MEDIUM) | **CLOSED for development on publication** |
| **Author side** (MEDIUM, D-WO17N1-1) | **CLOSED for development on publication** |
| Development MAJOR inventory | None open after publication |
| WO17-N2 (LOW), WO18-N1, WO18-N2 (INFO) | Open, unchanged |
| INFO from the delta review | The two-argument `payroll_reversal_affected_runs` wrapper only delegates and is not body-pinned; the author-side path is tested functionally, not at 10,000 entries; `known` reads every historical approver of a School (small in practice, linear per row in its size) |
| WO-19 to WO-22 | Not started |
| Seven P3 decisions, other P3/LOW findings, RG-ID-1, C33-H1 to H5, R1-02 PARTIAL, Legal/DPO gates, 361 capability rows, 61 audit identifiers | Unchanged; no baseline selected |
| Development database | 396 migrations; this unit's `2027_02_22_090000` is not applied there (D-WO18-2 covered the three earlier ones). Forward-migrating it needs the owner's word |

## 6. Publication

- **Candidate `f58b2eeb`, CI run 43462474515: green on its first attempt, no reruns.**
  - **11,861 tests (11,845 + the 16 new), 467,825 assertions, 0 failures, 1 skip (ESI-12)**;
  - four shards, coverage union proven equal to the canonical inventory;
  - static and frontend, full-history secrets, supply chain, integrity and production images all green.
- **The assertion total is 593 below WO-18's** although 16 tests were added. Recent canonical runs moved by -22 (WO-17), +995 (WO-18) and -593 here: tests that assert per data row vary between runs. That explanation is not traced test by test; it is recorded as a limitation, not proven.
- **Published** with `lycenza-ci publication-check --candidate f58b2eeb… --push` on 2026-11-13. E16 last valid day 2026-11-28, no problems. `origin/main` = `f58b2eeb`.
- **Regression counter 0/5:** this exact-SHA full suite is the canonical regression.
- **Development database:** unchanged at 396; this unit's migration is not applied there (needs the owner's word).
