# HR.R1-C34: WO-16 built. A known same person never takes the independent decision (2026-11-12)

**HR.R1 stays OPEN, and this is not the closure audit.** C34 implements WO-16 exactly as the Identity and Security owner decided it in HR.R1-C33 (`HR-R1-C33-IDENTITY-INDEPENDENCE-DECISION.md` §5, §6).

| | |
|---|---|
| Baseline | `cf965c7e` = `main` = `origin/main`; `63015a50` (C32 code) the last runtime ancestor; clean tree; the historical stash and `integration/e16-r2` preserved |
| Authorized | The owner's dispatch of the C34 work order, 2026-11-12, on the C33 decision of the same day |
| Not changed | The maker/checker policy and every existing refusal; Payroll, Leave and separation formulas and states; the link history (rule 145) and its correction workflow; capabilities; RG-ID-1; the five handoff domains. No identity data is collected, matched or merged, and no table is added |

## 1. The bypass, reproduced before the fix

The new tests were first run with the enforcement removed (the service checks and the migration taken out, the test database without the migration). **14 of 16 failed, each because the prohibited decision was accepted:**

| Workflow | What succeeded before WO-16 |
|---|---|
| Payroll approval | A second account known to be the preparer approved the run; a second account known to be a compensation author approved the run that pays that change first; the same approval written as raw SQL by the runtime role was accepted |
| Posting attestation | A second account known to be the approver was offered attestation (the page's reason was empty) and the raw attestation was accepted |
| Reconciliation review | A second account known to be the reverser recorded the review |
| Leave | A second account known to be the submitter approved and rejected; **the requester's own replaced account approved the requester's own leave** (no current link, so the current-link rule let it through); the raw decision was accepted |
| Separation | A second account known to be the recorder approved and rejected; **the separating person's own replaced account approved their own separation**; the raw decision was accepted |

The two tests that passed before the fix are the ones that must: a completed approval is not re-read, and another School's links prove nothing here. The C33 reproduction (`69ef41c0`, throwaway, never committed) stands as recorded.

## 2. What was built

**One relation, two forms.**
- **Application:** `HrSelfAdministrationGuard::knownSamePersonAccounts(school, user)` returns the OTHER accounts with an uncorrected link to an Employee the user also has an uncorrected link to. `knownSamePersonParticipation()` asks it against an item's participant map; `isKnownToBeEmployee()` asks whether the decider or such an account holds an uncorrected link, current or ended, to an Employee. Payroll reaches it only through this HR reader (rule 150 register updated: three compliant imports).
- **Database:** `employee_account_link_same_person_accounts(school, user)`: SECURITY INVOKER (forced RLS applies, so only the School in context is read), STABLE, schema-qualified, `search_path = pg_catalog, public, pg_temp`, EXECUTE for the runtime role only. `KnownSamePersonCoverageTest` proves it agrees with the C8 relation `employee_account_link_correction_same_person` on every pair of a fixture.

**Decisions** (each service check runs first, then the database guard backs it):

| Workflow | Refused when the decider is known to be the same person as | Service | Database guard (patched live) |
|---|---|---|---|
| Payroll approval | the creator, a calculator, an adjustment author, or an author of a change the run pays first | `PayrollRunService::assertMayDecide()`, under the run lock and the shared compensation lock | `payroll_runs_participant_approval_guard` |
| Posting attestation | the approver or any of those makers | `PayrollPostingIndependence::assertMayAttest()` | `trg_payroll_posting_attestations_guard` |
| Reconciliation review | the approver, the reverser, a maker, or an author in the obligation | `PayrollReversalReconciliationService::conflict()` | `trg_payroll_reversal_reconciliation_reviews_guard` |
| Leave approve/reject | the submitter; or the decider or such an account holds an uncorrected link, current or ended, to the requester's Employee | `LeaveRequestService::assertMayDecide()` | `trg_leave_decisions_participant_guard` |
| Separation approve/reject | the recorder; or the same, for the separating Employee | `SeparationCaseService::assertMayDecide()` | `trg_employment_separation_cases_guard` |

**Where the C33 table named another guard.** C33 listed `trg_payroll_runs_posting_independence` for the attestation. The attestor is identified on the attestation row, so the check sits in `trg_payroll_posting_attestations_guard`; the posting trigger already refuses a posting without that row by the poster. Nothing weaker results.

**Existing refusals are unchanged and checked first.** The decider's own account keeps its existing refusal and code (`PAYROLL_SELF_APPROVAL_NOT_ALLOWED`, `PAYROLL_POSTING_ATTESTOR_NOT_INDEPENDENT`, `PAYROLL_RECONCILIATION_REVIEWER_NOT_INDEPENDENT`, `LEAVE_PARTICIPANT_DECISION`, `LEAVE_SELF_DECISION`, `SEPARATION_PARTICIPANT_DECISION`, "never the subject"). The leave guard leaves the decider's CURRENT link to the requester to `leave_decisions_no_self_decision`, and the separation guard checks the current subject first.

**Refusals name no account.** `PAYROLL_SAME_PERSON_DECISION` (409), `LEAVE_SAME_PERSON_DECISION` and `SEPARATION_SAME_PERSON_DECISION` (403), each "one of your accounts is known to belong to ...". The approval packet shows `PAYROLL_SAME_PERSON_DECISION` before the decision; the posting and reconciliation pages show `same_person`. The database messages name no account either, and a database refusal that a link change caused after the service read is translated to the same code (Leave, separation).

**Registry and verifier.**
- `ApprovalWorkflowRegistry`: every workflow declares `knownSamePerson`. The HR family and Payroll name their check and guard (including the two existing C8/C18 corrections); Fees, Payments, Communications, Examinations and Erasure are exactly the handoffs C33-H1 to H5. Of the direct-assured actions, exactly posting (its attestation) and the reconciliation review.
- `platform:verify-database` gains `known_same_person_guards`: the relation's mode, volatility, path, owner and grants; each of the seven guards enabled, bound to its function, and calling the relation.

**Migration** `2027_02_19_090000_refuse_known_same_person_decisions`: creates the relation and adds one clause to each of the five guards in its LIVE definition (`pg_get_functiondef`, exactly-once anchor, refusing an unexpected shape), never restating a body; `down()` removes exactly that clause and drops the relation. It reads and changes no row.

## 3. Timing, concurrency and history

**Payroll** is ordered by the existing lock: a link change takes the School's compensation lock exclusively (`trg_employees_user_link_payroll_lock`); approval, attestation and review take it shared under the run row before reading participants. `PayrollKnownSamePersonConcurrencyTest` (real processes, holder held open, contender observed blocked):
- **link first:** the waiting approval reads the committed link and is refused;
- **approval first:** the waiting link commits after it; the approval stands and is never re-read.

**Leave and separation** take no lock against a link of another Employee and need none: the decision reads committed history only, and the link reads nothing the decision writes, so the outcome is the serial one (decision, then link). `LeaveKnownSamePersonConcurrencyTest`: with the link held open in another session, the decision cannot see it and decides; once it commits, the next decision by that account is refused; the first stands. A relink of the requester's or separating person's OWN Employee is an UPDATE of that Employee row, which the separation decision holds FOR SHARE.

**History.** Nothing reads or rewrites a completed decision. A link or correction after a decision changes only later decisions (`a_completed_approval_is_never_reread...`).

## 4. Evidence

| Proof | Test | Result |
|---|---|---|
| Each of the five decisions refuses a known-same account in the service, changes nothing (status, decider, evidence, ledger, postings, attestations, reviews, audit) and names no account; the decider's own account keeps its old refusal; an unknown account still decides | `PayrollKnownSamePersonTest`, `LeaveKnownSamePersonTest`, `SeparationKnownSamePersonTest` | pass |
| **A replaced account** (an ended, uncorrected link) of the requester or the separating person never decides their own leave or separation | same | pass |
| **A corrected link is no evidence:** after an approved two-person correction voids the old link, the second account decides (Payroll, Leave, separation) | same | pass |
| **Raw runtime role** (`school_os_app`, in the School's context): each forged decision is refused by its guard with the same-person message; nothing commits; the independent decider's row passes every guard (positive control). Attestation with a real journal entry and posting row, so only the attestation guard is under test | same | pass |
| **History:** an approval taken before the link that makes two accounts one person stands, unchanged, and posts | `PayrollKnownSamePersonTest` | pass |
| **School isolation:** the same two accounts linked to one Employee in another School are invisible here and refuse nothing | same | pass |
| **Concurrency:** Payroll, link first → refused; approval first → stands (holder held open, contender observed blocked). Leave, an uncommitted link is invisible and the decision stands; the next decision after commit is refused | `PayrollKnownSamePersonConcurrencyTest`, `LeaveKnownSamePersonConcurrencyTest` | pass |
| **Coverage:** every workflow declares it; HR family and Payroll enforce, exactly C33-H1 to H5 are handoffs; each declared check calls the HR rule; each guard enabled, bound and calling the relation; the relation agrees with the C8 relation on every pair | `KnownSamePersonCoverageTest` | pass |
| `platform:verify-database` `known_same_person_guards` (mode, volatility, path, owner, grants, bindings, whole-body hashes) | `OperatorVerificationCommandsTest`; the rollback proof's isolated database | pass |

**Planted bypasses** (temporary, restored byte-for-byte; the database restored and its pinned body hash re-read):

| Mutation | Detected by |
|---|---|
| The Payroll service check removed (account-id comparison only) | `KnownSamePersonCoverageTest`; `PayrollKnownSamePersonTest` (the database guard still refused, but as an unlabelled database error) |
| The Leave registry declaration removed | `KnownSamePersonCoverageTest` (2 tests) |
| The Leave guard's clause removed in the database | coverage, verifier (2 tests), and the raw runtime-role test; the service tests still passed, so each layer is proven on its own |
| The relation's `search_path` set to `pg_temp` first | coverage and verifier |
| The Leave guard trigger disabled | coverage and verifier |

**Suites** on the final service code before the review fixes: Payroll 553 (one failure, the review packet's query bound, below), Leave 209, HR 1,490, Staff Attendance 76, Postgres 1,259, Operations 15, Authorization 234. After the fixes: the 119 affected tests pass.

**Query bound.** `PayrollApprovalReviewLargeRunTest` caps the approval packet's queries. The packet's same-person notice first added nine (a second participant read). It now reads the reviewer's own links once and builds participants only when an account is known to be the same person: three constant queries (the read and its School context). The bound moved from 47 to 50, with the reason in the test.

**Rollback proof:** schema IDENTICAL after rollback and re-apply (78 schema lines removed by the rollback); `platform:verify-database` failed=0, operator evidence 1 (connection encryption outside production).

**Static:** Pint, Larastan, whitespace, gitleaks (publish gate PASS); vue-tsc, ESLint, Prettier, build.

**Performance** (DDEV, a School with 2,000 Employees and 4,000 link rows, analyzed): the relation is two index scans (`employee_account_links_user_idx`, then `employee_account_links_employee_user_idx`): 0.04 ms for the query, 0.23 ms through the SQL function, 1.5 to 2.9 ms through the PHP reader with its School context. The cost follows the decider's own links, not the run, the request or the School's size; a decision with no known alias stops after the first read. Payroll guards call `payroll_run_has_participant()` once per alias (normally none). Measured once, not a service-level objective.

## 5. Independent Security review

A separate reviewer read the final code (no file changed, nothing run). **No HIGH, no MEDIUM.**
- **L-1, fixed:** the verifier pinned only that each guard calls the relation; a later body keeping the call in a comment or dead branch would pass. It now pins each function's whole body (`KNOWN_SAME_PERSON_BODIES`), as rules 147 and 149 do.
- **L-2, fixed in part:** separation lacked the corrected-link and unknown cases (added). Attestation, review and separation have no two-session test of their own: they take the same compensation lock (attestation, review) or the Employee row (separation's own subject) as the proven paths. Recorded as C34-N2.
- **I-3, fixed:** the posting page's display read now sets the run's School context itself.
- **Recorded, not changed (C33 scope):** I-1 (C34-N3), I-2 (C34-N1), I-4 (the database checks first-paid authors for every run kind where the service lists them for regular runs only; a correction run records no sources, so unreachable), I-5 (refusals name nothing; the decider learns only that one of their own accounts is joined to a participant, which cannot be avoided).
- **Confirmed:** service and database enforce the same set (database equal or stricter); no raw path skips a trigger; RLS cannot fail open in a write (the row's School is the context's); INVOKER, pinned path, grants; races sound; no completed decision re-evaluated; `down()` safe.

## 6. Limits and findings recorded

- **Beneficiary through a second account (C34-N1, LOW).** C33 scoped the rule to participants. A Payroll decider known to be the same person as an account linked to an Employee the run pays first is caught only if the decider's own account is linked to that Employee (rule 145's self-beneficiary rule, unchanged). The remaining gap needs two Employee records for one person, one of them shared by both accounts. Not extended without an owner decision.
- **The database judges the recorded decider.** One runtime role serves every domain, so the guards refuse a forged decider column as that decider and cannot know which human acted (rule 102). The service is the authority on who acted.
- **Unknown stays unknown.** Accounts with no shared Employee are never refused and never proven to be two people. RG-ID-1 is unchanged and separate.
- **Source scan.** `KnownSamePersonCoverageTest` reads method text and `prosrc`; it proves the call is present, not every branch (the behaviour tests do).
- **Concurrency tests (C34-N2, INFO).** Two-session tests cover Payroll approval and Leave; attestation, review and separation rely on the same proven locks.
- **Other separation and leave actions (C34-N3, INFO).** Recording, cancelling, finalizing, clearing and completing a separation, and an administrative leave withdrawal or cancellation, still compare the CURRENT link only ("never one's own"). C33 scoped WO-16 to the decisions; an ended-link or second account of the separating person can still cancel or finalize their case. Recorded for an owner decision.

## 7. Dispositions

| Item | Status |
|---|---|
| **Identity MEDIUM** (development part) | **CLOSED for development on publication.** Its acceptance (C33 §6, "Done means"): WO-16 published with the tests above. The bypass is reproduced, and each of the five decisions refuses it in service and database |
| RG-ID-1 | **Open, unchanged.** A release gate before real Payroll; nothing here records or satisfies it, and no School is enabled |
| C33-H1 to H5 | Unchanged handoffs; Fees, Payments, Communications, Examinations and Erasure untouched |
| C34-N1 (LOW), C34-N2 (INFO), C34-N3 (INFO) | Open, recorded |
| Seven P3 findings (HRC-4, HRC-8, LVA-4, XRP-3, XRP-4, TRR-6, TRR-8), R1-02 PARTIAL, C32-N1 to N4, C30-N1/N2, C29-N1/N2, C28-N1/N3/N4, C24-N1, C20-N1/N2, C18-A12 | Unchanged |
| Legal, DPO and Security gates; capability map (361); 61 audit identifiers; five closure baselines | Unchanged; baselines unselected |

## 8. Publication

- **First candidate `6fddaf74`, CI run 43327932968:** all four shards and the coverage union green; **failed Repository integrity** only (`git diff --check`: a blank line at the end of this record, edited after the local whitespace gate ran). Not rerun: corrected by a new commit.
- **Candidate `6b881295`, CI run 43330907850: green on its first attempt, no reruns.**
  - **11,816 tests, 467,445 assertions, 0 failures, 1 skip (ESI-12)**; four shards; coverage union proven equal to the canonical inventory;
  - static and frontend, full-history secrets, supply chain, integrity and production images all green.
- **Published** with `lycenza-ci publication-check --candidate 6b881295… --push` on 2026-11-13. E16 last valid day 2026-11-28, no problems. `origin/main` = `6b881295`.
- **Regression counter 0/5:** this exact-SHA full suite is the canonical regression (11,792 → 11,816: the 24 new tests).
- **Development database:** unchanged. Every test ran on the isolated test database; the rollback proof on its own isolated project.
