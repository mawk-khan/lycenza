# HR.R1-C35 WO-18: "never one's own" is person-level for every separation write and administrative leave withdraw and cancel (C34-N3, C34-N5, C34-N2) (2026-11-13)

**HR.R1 stays OPEN.** WO-18 implements the work order the owner approved in C35 (D-C35-9; `HR-R1-C35-P3-DISPOSITIONS.md` §7). It amends OD-HR-25 for HR administration: rule 145's current-link answer no longer decides "one's own" for these writes.

| | |
|---|---|
| Baseline | `b3c0e5f8` = `main` = `origin/main`; `023c57f8` the WO-17 code; clean tree; the historical stash and `integration/e16-r2` preserved |
| Authorized | The owner's WO-18 dispatch, 2026-11-13, on D-C35-9 |
| Not changed | Separation states and prerequisites, the decision checks and codes of C34, Leave formulas, ledger, balances and policies, self-service leave, administrative leave submission, Payroll, WO-19 to WO-22, RG-ID-1, the handoffs |

## 1. The finding

**C34-N3** (`HR-R1-C34-WO16-KNOWN-SAME-PERSON.md` §6, `HR-R1-C35-P3-DISPOSITIONS.md` §4):
- **Separation:** record, cancel, finalize, access-retained, clearance clearing and complete went through `lockEmployment()` → `refuseOwnEmployee()` (current link), and the database's `v_subject` (current link).
- **Leave:** administrative withdraw and cancel went through `ownsEmployment()` (current link).
- So an ended-link or known-same account of the person could cancel their own approved separation, or cancel their own started leave and recover the balance.

**C34-N5:** the Separation corrected-link test showed no refusal before the correction and exercised `reject` only.

**C34-N2 (rest):** no two-session tests for separation against a relink, or for a decision against a voiding correction.

**Reproduced before the fix.** The 8 new behaviour tests ran with the service changes set aside and the migration not applied. **All 8 failed because the action was accepted.** In those runs the old account recorded the case, withdrew it and changed its last day, and withdrew and cancelled its own leave; every raw write was accepted. The 5 C34 decision tests, including the strengthened C34-N5 test, already passed. The concurrency tests were written after the fix and were not part of the reproduction.

## 2. What was built

**Actions covered.**

| Action | Service | Database |
|---|---|---|
| Separation record, withdraw, change last day, cancel, finalize, access-retained, complete | `SeparationCaseService::lockEmployment()`, one point for every write; code `SEPARATION_OWN_CASE` | `hr_employment_separation_cases_guard`: the recorder at INSERT; every later recorded actor other than the decider, each newly set column on its own. "Change last day" records no actor, so it is service-only (as the existing current-link rule was) |
| Separation clearance clearing | the same `lockEmployment()` | `hr_separation_clearance_items_guard` |
| Separation approve and reject | unchanged: C34's `assertMayDecide()` and `SEPARATION_SAME_PERSON_DECISION` (`lockEmployment()` skips these two) | unchanged C34 clause |
| Leave administrative withdraw and cancel | `LeaveRequestService::refuseKnownToBeRequester()`, after the existing `refuseOwnEmployment()`; code `LEAVE_OWN_LEAVE` | `leave_decisions_refuse_submitter`: an `administrative` `withdrawn` or `cancelled` decision. This also gives the CURRENT link a database backstop here for the first time (the service already refused it) |

**The rule.** The actor, or an account known to be the same person (rule 152), holds an uncorrected link, current or ended, to the subject Employee: `HrSelfAdministrationGuard::isKnownToBeEmployee()` in PHP, and the same predicate through `employee_account_link_same_person_accounts()` in SQL.
- The current account keeps its existing refusal and code (`HR_SELF_ADMINISTRATION`, `LEAVE_SELF_ADMINISTRATION`), checked first.
- Unknown is never refused.
- A corrected link is no evidence.
- Refusals name no account.

**Migration** `2027_02_21_090000_refuse_known_same_person_own_case` adds four clauses to three guards in their LIVE definitions (exactly-once anchors) and removes exactly those in `down()`. It reads and changes no row.

**Registry and verifier.**
- `ApprovalWorkflowRegistry`: separation and Leave declare `ownCaseCheck` and `ownCaseGuards`, and `KnownSamePersonCoverageTest` requires them.
- `known_same_person_guards`: now ten triggers (adding `trg_separation_clearance_items_guard`).
- Bodies re-pinned: `leave_decisions_refuse_submitter` (`3823d9e8` → `3e7a2304`) and `hr_employment_separation_cases_guard` (`0d361816` → `f3d23f6a`). Added: `hr_separation_clearance_items_guard` (`ace7fa43`).

**Test support.** `tests/Support/separation-race.php` can now be held open (`HeldTransaction`, a no-op unless a test asks).

## 3. Evidence

| Proof | Test | Result |
|---|---|---|
| **Every separation write.** OLD (the separating person's replaced account, every key) is refused at record, withdraw, last-day change, cancel, clearance, finalize, access-retained and complete, and nothing moves. The decisions keep `SEPARATION_SAME_PERSON_DECISION`. An independent officer does each step to completion | `SeparationOwnCaseTest` | pass |
| **The service refuses before any write is attempted** (every statement sent to PostgreSQL is captured, refused ones included), so the database guard is the backstop and not the check | `SeparationOwnCaseTest`, `LeaveOwnLeaveAdministrationTest` | pass |
| A known-same account of OLD is refused; the CURRENT account keeps `HR_SELF_ADMINISTRATION` | `SeparationOwnCaseTest` | pass |
| **Corrected link:** refused before an approved two-person correction voids OLD's link, allowed after it | `SeparationOwnCaseTest` | pass |
| **Raw runtime role, separation:** INSERT, withdraw, clearance, cancel, access-retained and complete by OLD each refused by its guard; a write setting two actor columns at once is refused on the second; independent positive controls pass | `SeparationOwnCaseTest` | pass |
| **Leave:** a replaced account holding `hr.leave.manage` cannot withdraw its own request or cancel its own started leave; no ledger row moves. An independent administrator cancels; the requester withdraws through self-service | `LeaveOwnLeaveAdministrationTest` | pass |
| Leave: a known-same account refused; the current account keeps `LEAVE_SELF_ADMINISTRATION`; corrected link refused before and allowed after | same | pass |
| **Raw runtime role, Leave:** administrative `withdrawn` and `cancelled` by the old account refused, and by the CURRENT account (a new backstop); positive control passes | same | pass |
| **C34-N5:** the corrected-link case is refused BEFORE the correction on approve and reject, with nothing moving, and allowed AFTER it on both | `SeparationKnownSamePersonTest` | pass |
| **Two sessions, separation** (holder held open, contender observed blocked): a relink of the separating Employee first → the waiting cancellation is refused (an ordering proof: the existing current-link rule); the cancellation first → it stands. A voiding correction first → the waiting cancellation by the old account succeeds. A link of another Employee, held open, is invisible to the write, and governs the next one once committed | `SeparationOwnCaseConcurrencyTest` (4) | pass |
| **Two sessions, Payroll:** a voiding correction held open → the waiting approval by D (no longer known to be the paid Employee) approves | `PayrollKnownSameBeneficiaryConcurrencyTest` | pass |
| **Planted bypasses** (restored byte-for-byte): separation service check removed; Leave service check removed (first not detected, because the database refusal carries the same code; detected once the tests captured attempted statements); Leave guard clause removed in the database | as named | detected |
| Registry, coverage, verifier (bodies, mode, path), HR writer boundary, rollback inventory | `KnownSamePersonCoverageTest`, `ApprovalWorkflowRegistryTest`, `OperatorVerificationCommandsTest`, `HrWriterBoundaryTest`, `RollbackGuardInventoryTest` | pass |

**Suites** (before the review fixes): HR 1,500, Leave 213, Payroll 568, Postgres 1,259, Authorization 234, Operations 15, all pass. **After the review fixes:** the 163 affected tests pass.

**Rollback proof:**
- The first attempt after the review fix was **refused**: the isolated proof database held the earlier version of this unpublished migration, and `down()` refuses an unexpected shape (fail-closed, as designed).
- After the canonical isolated reset: schema IDENTICAL after rollback and re-apply (46 schema lines removed by the rollback); `platform:verify-database` failed=0.

**Performance.** `isKnownToBeEmployee()` is two indexed reads (C34 measured the relation at 0.04 ms, 0.23 ms through SQL and 1.5 to 2.9 ms through PHP at 4,000 link rows); one call per guarded write. No query in a loop.

## 4. Independent Security review

A separate reviewer read the code (read-only). **No HIGH, no MEDIUM.** It confirmed:
- the inventory: every separation write passes `lockEmployment()`; employment end, the notice period, events and the clearance snapshot run inside already-checked transactions;
- that skipping approve and reject leaves no gap;
- both Leave paths are covered, the self paths are unaffected, and `recordDecision()` is the only writer;
- tenant context, INVOKER, path and grants;
- no new lock order, and that the concurrency tests are valid;
- privacy, history, and the anchors and `down()`.

**LOWs, all fixed:**
- **L-1:** `record()` turned a database own-case refusal into a 500; it now maps it to `SEPARATION_OWN_CASE`.
- **L-2:** the case guard judged only the first newly set actor column; it now judges each.
- **L-3:** the relink race passes on the existing current-link rule. It now asserts that code and is described as an ordering proof; the reproduction count above names only the 8 behaviour tests.
- **L-4:** the raw-role tests now cover access-retained and complete as well, and the docblock says which writes are raw-tested.

**Recorded (INFO, outside C34-N3's scope):**
- **WO18-N1:** the deceased direct end (`EmployeeLifecycleService::separate()`) still compares the current link only.
- **WO18-N2:** administrative leave submission (`submitOnBehalf`) still compares the current link only. A decision on that request is still person-level.

**Final delta.** The fixes after the review (`record()` mapping, per-column guard, test strength, docs) were run against the 163 affected tests and are covered by the full exact-SHA suite. No second review was commissioned.

## 5. WO17-N1 decision intake (not implemented here)

**Verbatim** (`HR-R1-C35-WO17-KNOWN-SAME-BENEFICIARY.md` §5): the post-decision independence functions (`payroll_run_independence_exception`, `payroll_run_first_paid_compensation`, `payroll_reversal_affected_runs`) treat an approval as "by the Employee" only through the approver's own links.

**Reproduced** (throwaway test, deleted). Same scenario both times:
- U is linked to the paid Employee; D is known to be U.
- X raises the pay. October is independently approved and posted. November is approved, then October is reversed.

| November approved by | Exception rows | Attestation needed | Posting without attestation |
|---|---|---|---|
| U (the Employee's own account) | 1 | yes | refused (`PostingAttestationRequiredException`) |
| D (known to be U) | 0 | no | **posted** |

So a run that, after the reversal, pays the person's own raise first is posted with no independent check when their second account approved it. Had the run already been posted, no reconciliation obligation would open. This is not only a missing report: it skips a control the OD-HR-24 follow-up requires.

**Owner decision (D-WO18-1, 2026-11-13): MEDIUM, so MAJOR under C27-C; Option A authorized.** The two functions (and the discharge rule) treat a known-same account of the paid Employee like the Employee's own. Build it with rule 145's materialized-join care and a large-population test; never rewrite a completed approval.

**Not in WO-18:** a separate, smallest unit with its own regression. HR.R1 is NOT CLOSED until it publishes.

**WO17-N2** (an extra trigger on the Payroll guard tables is not detected): unchanged, LOW, recorded.

## 6. Development database (owner decision D-WO18-2)

The development database stops at `2027_02_18_090000`. The owner authorized forward-migrating it through `pgsql_admin` after WO-18 publishes: C34's `2027_02_19_090000`, WO-17's `2027_02_20_090000` and WO-18's `2027_02_21_090000`, followed by `platform:verify-database`. Its result is recorded in §8 (Publication).

## 7. Dispositions

| Item | Status |
|---|---|
| **C34-N3** | **CLOSED for development on publication.** Every separation write and administrative leave withdraw/cancel refuse the old or known-same account, in service and database |
| **C34-N5** | **CLOSED on publication.** Refused before and allowed after the correction, on both decisions |
| **C34-N2** | **CLOSED on publication.** Two-session evidence now covers approval, attestation and review against a link (WO-17), separation against a relink, separation against a voiding correction, a Payroll approval against a voiding correction, and the held-invisibility orders |
| **WO17-N1** | Open: MEDIUM (MAJOR), Option A authorized (D-WO18-1); a separate unit |
| WO17-N2 | Open, LOW |
| WO18-N1, WO18-N2 | Open, INFO |
| WO-19 to WO-22 | Not started |
| Seven P3 decisions, other P3/LOW findings, RG-ID-1, C33-H1 to H5, R1-02 PARTIAL, Legal/DPO gates, 361 capability rows, 61 audit identifiers | Unchanged; no baseline selected |
