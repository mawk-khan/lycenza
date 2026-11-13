# HR.R1-C35 WO-17: a known-same account of a paid Employee never takes the Payroll decision (C34-N1, C34-N4) (2026-11-13)

**HR.R1 stays OPEN.** WO-17 implements the work order the owner approved in C35 (D-C35-1; `HR-R1-C35-P3-DISPOSITIONS.md` §7): the OD-HR-24 self-beneficiary refusal also covers an account known to be the same person, and the Payroll guard triggers' definitions are pinned (C34-N4).

| | |
|---|---|
| Baseline | `08b7cbf9` = `main` = `origin/main`; `6b881295` the published C34 commit (its parent `6fddaf74` holds the C34 code); clean tree; the historical stash and `integration/e16-r2` preserved |
| Authorized | The owner's WO-17 dispatch, 2026-11-13, on D-C35-1 |
| Not changed | Payroll formulas, results, compensation, approval states, maker/checker, first-paid attribution, the posting-independence exception, every existing refusal and its code; Leave and separation; WO-18 to WO-22; RG-ID-1; the five handoffs |
| Development database | Unchanged. It lacks C34's `2027_02_19_090000` and now WO-17's `2027_02_20_090000` (it stops at `2027_02_18_090000`); bringing it forward needs authorization. All tests ran on the isolated test database |

## 1. The finding and its root cause

**C34-N1** (`HR-R1-C34-WO16-KNOWN-SAME-PERSON.md` §6, rated MEDIUM by the owner in C35): a Payroll decider known to be the same person as an account linked to an Employee the run pays first was refused only if their own account was linked to that Employee.

**Root cause.** Every self-beneficiary check compared the beneficiary Employee's linked accounts with the decider's own id:
- service: `PayrollSelfBeneficiaries::of()` / `ofObligation()`, `b.user_id = actor`;
- database: `payroll_runs_self_beneficiary_guard`, `payroll_posting_attestations_self_beneficiary_guard`, and the reviews guard's `s.user_id = reviewer`.

The C34 relation was applied to the makers only.

**Reproduced before the fix.** The new tests ran with the service unchanged and the migration not applied. 4 of 8 failed because the prohibited decision went through:
- the alias approved in the service;
- the raw approval was accepted;
- the attestation page offered attestation, and the attestation was accepted;
- the alias recorded the review.

The four that passed are the controls: a replaced account of the Employee (already refused), the corrected link, a completed approval not re-read, and another School.

## 2. What was built

**One clause, one query.** `PayrollSelfBeneficiaries` gains `IS_ACTOR`:

```
(b.user_id = ? OR b.user_id IN (SELECT k.user_id FROM employee_account_link_same_person_accounts(?, ?) k))
```

It is used in `of()` and `ofObligation()`.
- The decision stays one query: the approval packet's bound (50) holds at 2,100 entries.
- Every caller inherits it: approval, the approval packet, attestation, the posting page, the review and the reconciliation page.
- The refusal is unchanged (`SelfBeneficiaryConflictException`, "a compensation change of your own"), as C35 required, naming no account, Employee or amount.

**Database.** Migration `2027_02_20_090000_refuse_known_same_person_beneficiaries` patches three guards in their LIVE definitions (exactly-once anchors), the same clause each:
- `payroll_runs_self_beneficiary_guard` (approval);
- `payroll_posting_attestations_self_beneficiary_guard` (attestation);
- `payroll_reversal_reconciliation_reviews_guard`'s subject check (review).

`down()` restores exactly that clause. It reads and changes no row.

**Registry and verifier.**
- `ApprovalWorkflowRegistry`: the three Payroll declarations name `beneficiaryCheck` and `beneficiaryGuard`, and `KnownSamePersonCoverageTest` requires them.
- `platform:verify-database` `known_same_person_guards`:
  - now nine triggers, adding the two self-beneficiary triggers;
  - each pinned by the md5 of its whole `pg_get_triggerdef` (timing, events, `UPDATE OF`, `WHEN`; **C34-N4**) as well as its binding and the relation call;
  - bodies pinned: the reviews guard re-pinned (`ae7a3cce` → `ad3751c7`), the two self-beneficiary guards added.

## 3. Evidence

| Proof | Test | Result |
|---|---|---|
| **C34-N1:** D (known to be A, the paid Employee's account, through a duplicate Employee) is refused at approval, as A is; the run stays calculated with no audit; the packet shows `PAYROLL_SELF_BENEFICIARY_CONFLICT` without an amount; an independent approver approves | `PayrollKnownSameBeneficiaryTest` | pass |
| A replaced account (an ended, uncorrected link of its own) and the new account both stay refused | same | pass |
| **Corrected link:** after an approved two-person correction voids A's link to the duplicate, D is unknown and approves; A stays refused | same | pass |
| **Raw runtime role:** the approval as D is refused by `payroll_runs_self_beneficiary_guard`, nothing commits; the independent approver's row passes (positive control) | same | pass |
| **Attestation:** the posting page shows `self_beneficiary` to D; the service refuses; a raw attestation (with a real journal entry and posting row) is refused by `payroll_posting_attestations_self_beneficiary_guard`; the poster's row passes | same | pass |
| **Review:** D is refused; the reconciliation page shows `self_beneficiary`; the raw review is refused by the reviews guard; an independent reviewer closes it | same | pass |
| **History:** an approval taken before the link that makes D the Employee stands, unchanged, and posts | same | pass |
| **School isolation:** A and D joined only in another School: D approves here | same | pass |
| **Two sessions** (holder held open, contender observed blocked): approval, attestation and review each refused when the link commits first, and standing when the decision commits first | `PayrollKnownSameBeneficiaryConcurrencyTest` (6) | pass |
| **C34-N4 negative proofs** (isolated test database, each restored and re-read): `trg_payroll_runs_self_beneficiary` moved to AFTER, its `UPDATE OF` changed, its `WHEN` changed, rebound to another function, disabled; its function's body changed | `KnownSamePersonCoverageTest`, `OperatorVerificationCommandsTest`: 3 (body: 2) failures each; 13 pass after restore with the pinned hashes `910f4490…`, `082cd431…` | pass |
| **Planted source bypasses** (restored byte-for-byte): `IS_ACTOR` reduced to the decider's own account → 3 behaviour tests fail; the registry's beneficiary declaration removed → 2 coverage tests fail | as named | detected |
| Registry, coverage, verifier, Payroll–HR register | `KnownSamePersonCoverageTest`, `ApprovalWorkflowRegistryTest`, `OperatorVerificationCommandsTest`, `PayrollHrBoundaryRegisterTest` | pass |

**Suites:** Payroll 567, Postgres 1,259, Authorization 234, Operations 15: all pass. The C34 known-same-person tests (approval, attestation, review, Leave, separation) are inside them.

**Rollback proof:** schema IDENTICAL after rollback and re-apply (3 schema lines removed by the rollback: the three patched clauses); `platform:verify-database` failed=0 on the isolated database.

**Performance** (DDEV, one run, measured once): `PayrollSelfBeneficiaries::of()` 3.2 ms with a known alias, 2.8 ms with none, 4 statements in both cases, including the School context. The approval packet's bound of 50 holds at 2,100 entries (`PayrollApprovalReviewLargeRunTest`). The relation is an uncorrelated subquery, evaluated once per call.

**Static:** Pint, Larastan, whitespace, gitleaks (publish gate); no frontend file changed.

## 4. Limits recorded

- **The database judges the recorded decider** (rule 102). One runtime role serves every domain; the service records the authenticated user.
- **Unknown is not proof.** Accounts sharing no Employee are never refused; RG-ID-1 stands.
- **Out of WO-17's scope:** the post-decision independence functions (WO17-N1, §5).

## 5. Independent Security review

A separate reviewer read the final code (read-only). **No HIGH, no MEDIUM.** It confirmed:
- the binding order in `of()` and `ofObligation()`;
- that service and database enforce the same set for all three decisions, under the shared compensation lock;
- no RLS fail-open (every caller runs in its School's context);
- the predicate equals `isKnownToBeEmployee()`;
- migration and `down()` safety;
- privacy, and no history touched.

**LOWs:**
- **L-1 (WO17-N1, recorded):** the post-decision independence functions (`payroll_run_independence_exception`, `payroll_run_first_paid_compensation`, `payroll_reversal_affected_runs`) still treat an approval as "by the Employee" only through the approver's own links. Scenario:
  1. D, known to be the Employee's account, approves a run that does not yet pay the change first; that approval is correct.
  2. A later reversal makes that run pay the change first.
  3. Nothing then flags it, where the Employee's own account would have needed an attestation or an obligation (rules 144, 145).
  4. The same applies to alias approvals made before WO-17.

  Out of WO-17's scope (C35 §7). It changes financial-structure functions, which need the materialized-join care rule 145 records. **For an owner decision.**
- **L-2:**
  - **Fixed in part:** the verifier now also pins each pinned function's mode and path (INVOKER, `pg_catalog, public, pg_temp`), which `prosrc` does not carry.
  - **Recorded (WO17-N2):** an EXTRA BEFORE trigger on `payroll_runs`, `payroll_posting_attestations` or `payroll_reversal_reconciliation_reviews` that sorted after a guard could rewrite the decider column after the check. The HR family pins its full trigger set; these Payroll tables do not. Needs DDL privilege.
- **L-3, fixed:** rules 144 and 152 and the gate register now say what WO-17 changed.

**INFO:** the reconciliation conflict loop runs `IS_ACTOR` once per alias (correct, wasteful); the verifier has no committed tamper test (the negative proofs above were run by hand on the test database).

**Final delta.** The mode and path pin and the documentation fixes followed the review. They were run against the verifier tests (22 pass) and are covered by the full exact-SHA suite; no second review was commissioned for them.

## 6. Dispositions

| Item | Status |
|---|---|
| **C34-N1** | **CLOSED for development on publication.** Reproduced, then refused at approval, attestation and review, in service and database, with races, history and isolation tested. HR.R1 has no other open MAJOR |
| **C34-N4** | **CLOSED on publication.** Nine triggers pinned by definition; mutations detected |
| WO17-N1 (LOW) | Open, owner decision (post-decision independence by known-same accounts) |
| WO17-N2 (LOW) | Open, recorded (Payroll guard tables' full trigger set) |
| C34-N2 | Approval, attestation and review races now covered (here); separation-against-relink and approval-against-voiding-correction races remain for WO-18. Stays INFO |
| C34-N3, C34-N5 | Unchanged, WO-18 |
| WO-18 to WO-22 | Not started |
| Seven P3 decisions, the other open P3/LOW findings, RG-ID-1, C33-H1 to H5, R1-02 PARTIAL, Legal/DPO gates, 361 capability rows, 61 audit identifiers | Unchanged; no baseline selected |

## 7. Publication

- **Candidate `023c57f8`, CI run 43415630607: green on its first attempt, no reruns.**
  - **11,830 tests (11,816 + the 14 new), 467,423 assertions, 0 failures, 1 skip (ESI-12)**;
  - four shards, coverage union proven equal to the canonical inventory;
  - static and frontend, full-history secrets, supply chain, integrity and production images all green.
  - The assertion total is 22 below C34's although 14 tests were added: some existing tests assert per data row, so their count varies between runs. Recorded, not explained away.
- **Published** with `lycenza-ci publication-check --candidate 023c57f8… --push` on 2026-11-13. E16 last valid day 2026-11-28, no problems. `origin/main` = `023c57f8`.
- **Regression counter 0/5:** this exact-SHA full suite is the canonical regression.
- **Development database** unchanged: it lacks `2027_02_19_090000` and `2027_02_20_090000` until authorized.
