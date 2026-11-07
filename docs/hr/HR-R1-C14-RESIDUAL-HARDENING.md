# HR.R1-C14: WO-10 residual hardening (2026-11-07)

**This is not the closure audit, and nothing here is an approval.** D-1 to D-10 and R1-01 stay pending. Baseline `e3df37b3`. `HR-R1-CLOSURE-CONTRACT.md` stays the authority, and `HR-R1-0-AUDIT-REGISTER.md` holds the findings. WO-10 is the one row in `HR-R1-C12-DECISION-DOCKET.md` §16: C11-N2 reproduction, C11-N3 assertion, the `php -l` range check, C12-N3.

## 1. Outcome

| Item | Class | Disposition |
|---|---|---|
| C11-N2 | AUTHORIZED-INDEPENDENT | **CONFIRMED AND CORRECTED** for what it names |
| C11-N3 | AUTHORIZED-INDEPENDENT, test only | **CONFIRMED AND CORRECTED** |
| C12-N3 | AUTHORIZED-INDEPENDENT | **CONFIRMED AND CORRECTED** at its five sites and two searches |
| `php -l` range check | PLATFORM-OWNER-REQUIRED | Proposal in §6. Nothing built |
| C13 delta | EVIDENCE-ONLY | Reviewed. Three defects found in §5, all fixed, one of them only in part (C14-N5) |
| Rule 86 in Payroll | NEW, not in scope | **Confirmed, recorded, not fixed:** C14-N6 |

No migration, table, capability, route, Vue file or capability-map status changed.

**Three framings in the brief did not match the source.** C11-N3 is a substring search over a whole page, not a uniqueness or fixture collision. C12-N3 is about a record's existence, not a School's status. C12-N3 also has a second half: two searches with unescaped terms and no order.

## 2. C11-N2: what a key remembers

**Exact finding.** "An idempotent HR API write that reaches the guard in the gap after the request-time check stores its 404 under the key, so a retry with that key after the School resumes replays the 404. Read, not executed. The generic middleware does this for Leave too."

**Reproduced by execution on `e3df37b3`,** on a real route: the 404 was stored, and the same key after the School resumed replayed it.

**Cause.** Laravel's routing pipeline renders an exception into a response before it reaches route middleware (`Pipeline::prepareDestination`, `handleException`). So `EnsureIdempotent`'s catch blocks never fire on a real request, and everything went to `complete()`. No test crossed that boundary: `IdempotencyGuardTest` calls `release()` directly.

**Correction.** The middleware reads the exception attached to the response, and releases a `SchoolNotOperationalException`. On no idempotent route does this refusal reach the response after a commit, so nothing of the effect committed; a fresh MFA code, spent outside the transaction, stays spent. The ordering differs by service (`InventoryStockService` and `ChargeService` ask after other work in the same transaction), and five idempotent routes do commit item by item. Four never reach a guard, and the import absorbs the refusal into a row result. The invariant is that a route where this can reach the response after a commit stays off `idempotent`. `/accrual-runs` is that case, and is pinned; the invariant as a class is not, and the trace behind it is static (§10). `releaseIfStillClaimed()` deletes only the row this request still holds, because a slow request can outlive its in-flight timeout and be reclaimed.

**Covered for Leave too.** Every self-service path asks `requireOperational()` first, so a real refusal is this exception. `a_school_refusal_after_the_request_check_is_not_replayed_once_the_school_resumes` proves it on the real route.

**Not changed.** `EmployeeImportService` turns the refusal into a row result in a 201, which stays remembered: earlier rows of that batch may have committed, so releasing it could write them twice. A new key is a new attempt.

### A wider fact: C14-N2, for the Platform owner

The same mechanism means **a rendered 5xx is stored and replayed for 48 hours.** `RELIABILITY.md` said the claim was released. It never was.

My first correction released it, to match the document. **The independent review showed that was wrong, and it was removed.** A handled 5xx can follow a commit, and some routes have no atomic completion, so a retry would run the effect twice:

| Route | Shape |
|---|---|
| `POST /webhook-endpoints`, `rotate-secret` | Writes with no transaction, then an audit that can fail. A retry makes a second endpoint, or loses the customer's known secret from the overlap window |
| `inventory-stock/receive`, `issue`, `transfer` | Commits, then `load()`. A retry writes another movement |

Other default-path create routes may share this: not walked. `RELIABILITY.md` now says what is true, and a test pins it.

| Option | Effect |
|---|---|
| A. Keep remembering (recommended for now) | Safe against doubles. A client needs a new key after a 500 |
| B. Release when nothing committed | Needs a commit detector, transactions around the webhook writes, and `completeWithin` on inventory. A real piece of Platform work |

## 3. C11-N3: the payslip assertion

**Exact finding.** "`PayrollUiTest::the_payslip_page_renders_the_expected_props_for_an_approved_run` looks for `pf`, `esi` and `tds` in the whole page, which holds a random email. It failed once in CI on `gdapfw.varun.mistry@example.org`."

**Reproduced deterministically** by giving the viewer that address: `Not to contain: pf`, every run. **Root cause established:** a substring search over data. It was never a collision, a uniqueness failure, a seed, a parallelism or a cleanup problem, so no fixture design applies and no production code changed.

**Correction.** The test keeps that address on purpose. It walks every prop key, splits it into words at case and digit boundaries, compares words exactly against a list covering the presenter's own statutory keys (`employerEpf`, `employeeLwf`, `professionalTax` and the rest), asserts `statutory` is null, and asserts the only line is `Basic`. Keys are code, so it is deterministic. It is narrower than the old search in one way, a key word rather than any text, which is why the line assertion is there: `employeePfMandatory` and `employeePFAmount` are caught as words. `bankAccount` and `panNumber` keep their raw check, because a value cannot hold them by accident.

**Same class elsewhere: none found.** The other short-token assertions look for a seeded name in bounded audit metadata, which is what a leak test should do.

## 4. C12-N3: capability first

**Exact finding.** "Five web sites look up before the capability check, so a same-School member without it can tell 404 from 403 for a known UUID." Plus two searches.

| Site | Capability | Baseline | Now |
|---|---|---|---|
| `GET /app/payroll/compensation/{id}` | `payroll.compensation.view` | 404 or 403 by existence | 403 |
| `POST /app/payroll/compensation/{id}` | `payroll.compensation.sensitive.manage` | Same | 403 |
| `GET /app/payroll/statutory/employees/{id}` | `payroll.statutory.view` | Same | 403 |
| `POST /app/payroll/runs/{run}/manual-overrides` | `payroll.runs.prepare` | Same, for the run and the employment | 403 |
| `POST /app/payroll/runs/{run}/correction-deltas` | `payroll.runs.prepare` | Same | 403 |

A holder of the capability still gets the private 404 for an unknown or foreign id. Each service keeps its own check, so a direct call is unchanged. The capability matches what each service requires, and no Payroll key is Campus-adopted, so no lawful actor changes outcome.

**What was disproved.** None of these sites is a School-status oracle: a suspended School answers every member the same, whatever the id. That test passed on the baseline. An unknown id never spends an MFA code. That also held on the baseline, because the lookup precedes the code.

**Compatibility.** Web only. A member without the capability who sends an unknown id now gets 403 where they got 404. The API already had route-level `capability:`.

**Searches.** `%` and `_` are text now, and a bounded list has a defined order. It is by id, not by name, because ordering by name needs another HR reference, and D-2 is pending.

**Rule context: C14-N1.** No written contract requires capability-before-lookup. Out-of-scope = 404 is a Campus and ownership rule, and service authority is an accepted coverage class. So these sites were an observable difference, not a broken rule, and **17 more web Payroll actions have the same shape,** including `GET /app/payroll/runs/{id}`, so a run's existence is still testable. Whether this should become a rule is a Security and Architecture decision. If so, route-level `capability:` on the web Payroll group is the cheap fix.

## 5. C13 final-state review

C13's corrections shipped unreviewed. That gap is closed. The review read `841223c1..060743c4` and this checkpoint's code at `fd0b4074`, and found no HIGH.

| C13 correction | Result |
|---|---|
| Generic Documents route, run row, personal details, doubled docblocks, `AcademicDepartment`, timezone, rules 86 and 97, fixture values | Correct |
| Pins | Correct but incomplete. Now all 28 hardened models are exact |

**Three defects came out of this:**

- **An edited credential could stay verified.** C13's record said a material edit resets verification. True in sequence, false in a race: `update()` decided from a model read before its transaction, so an edit racing a verification wrote only its own columns and the new content came out `verified`. Reproduced with two real sessions. All three writers now decide on the locked row, and refuse an archived one there. **That closes one direction only** (C14-N5): an edit landing after a verification resets it, but a verification landing after an edit attests content the verifier may never have seen, because verify and reject name no version. A verification attests the row as it stands when it commits. Binding it to what was viewed needs a version token and a screen change, which is a new verification policy this checkpoint may not introduce. Owner: HR with Security. Consequence: no effect on local UAT, and a production-readiness item if a verification is to be relied on as evidence that a person checked that content.
- **An unauthorized member could see the School's 404.** C13's early check in `HrEmployeeDocumentPolicy` ran before the capability check, on a route with no `capability:` middleware. It now asks only of a holder, through the Gate, as rule 98 requires.
- **A refused direct generic upload** had no proof of no bytes and no row. It has one now.

**One review claim did not survive checking.** It said `/my/leave` turned a School refusal into a private 404. A mutation showed the fix changed nothing: every caller of `holdActing()` asks the School first in the same transaction. The fix was reverted.

### C14-N6: Payroll has almost no execution-time School check

The last review noted two statutory services with no School check. Checking that showed it is wider. No file in `app/Domain/Payroll` names `SchoolOperationalGuard`. Its only guarded paths run through `ApprovalDecisionAuthority::requireCurrent()`: approve, post, reverse and the reconciliation review.

**Proof.** A throwaway test, not committed, suspended a School and called the controller-level services:

| Call | Result on a suspended School |
|---|---|
| `PayrollRunAdministrationService::createRun` | **Wrote** |
| `PayrollRunAdministrationService::calculate` | **Wrote** |
| `PayrollRunAdministrationService::approve` (control) | Refused: `SchoolNotOperationalException` |

**20 writing methods in 11 services lack the check: 2 proven, 18 by reading:** correction runs, manual overrides, correction deltas, periods, salary components and structures, compensation assignment, Payroll and statutory accounting configuration, and the ESI, PF, tax-profile and statutory-identifier administration services. `StatutoryPayrollCalculationService` and `StatutoryPayrollPostingService` have no production caller, tests only, so they add nothing today and need the check if they are ever wired up. `PayrollEvidenceRetentionService` is exempt: retention never takes the guard.

**Exposure.** Rule 86 asks for this check at execution. A normal request is still stopped at request time, so the gap is the window after that check, plus any direct call. Same class and severity as C11-N1. P3 suits a money domain here because approve, post and reverse are guarded, so nothing reaches the ledger on a suspended School through this gap.

**Why not fixed here.** It is not in C14's authorized list, it is 20 writing methods, and the guard must fit rule 142's order (run row, then compensation lock). It needs its own checkpoint, with the same coverage test the HR family has. Owner: Payroll.

## 6. Platform proposal: `php -l` over a range

**Status: PROPOSED. Not built, and no Platform-owner approval exists.**

**The gap.** `publication-check` proves the candidate tip. `a1c32780` does not parse, and it reached `main` inside a fast-forward range, because a later commit in the range fixed it. No published tip was affected, but a cherry-pick of it would break.

| Question | Proposal |
|---|---|
| Where | `infrastructure/ci/lycenza_ci/publication.py`, in `decide()`: a new entry in `problems` |
| Which files | The PHP files each commit in `main..candidate` changes, at that commit |
| Enforcement | Each commit, at publication. CI already covers the tip |
| Failure | Names the commit and the file. It refuses, and the operator squashes or fixes |
| Cost | About 75 ms a file through DDEV, measured on 60 files. The largest of the last 20 commits touched 79 |
| Replaces | Nothing. Pint, Larastan and the suite stay. Parsing proves nothing about behaviour |
| Ruleset | No change. It runs in the local publication step |
| Limit | It is local tooling, so it binds only someone using `publication-check`. The ruleset needs only the tip |

**Current practice already narrows it:** C11 and C13 squashed to one reviewed commit.

## 7. Evidence

| Test | Kind |
|---|---|
| `IdempotencyFailureClassificationTest` | Proves C11-N2 over real HTTP, reproduced on the baseline. It also pins C14-N2 |
| `HrCredentialVerificationRaceTest` | Two real sessions, the contender observed blocked. The two edit-after-verify races were reproduced before the fix. One test pins the open half of C14-N5. Six cases race every writer behind an archive, and each failed with the check removed |
| `a_member_without_the_capability_gets_the_same_403...` | Proves C12-N3: failed at all five sites on the baseline |
| `a_holder_of_the_capability_still_gets_the_private_404...` | Regression only |
| `a_suspended_school_answers_every_member_the_same...` | Regression only: there was never an oracle |
| `an_unknown_employment_never_spends_a_fresh_code...` | Regression only |
| `the_search_treats_a_wildcard_as_text...` | Proves the search half, on the baseline |

**20 changes were tried against the tests.** 19 mutations failed a test. The 20th, a proposed fix, survived, proved dead and was reverted.

| Mutation | Result |
|---|---|
| Remember every 5xx in the release path; remember a suspended School; release every failure | Failed |
| Remove the capability from compensation show; from manual override; unescape the search | Failed |
| Run the Document pre-check for everyone | Failed |
| Remember the School refusal; release every 5xx; release unconditionally | Failed |
| Decide a qualification on the stale model; leave the certification edit unlocked | Failed |
| Put a payslip key mid-string; give `WorkforceTask` back `department_id` | Failed |
| Drop the locked archive check, qualifications; the same, certifications | Failed: three races each |
| Add `employeePFAmount` to the payslip; drop a model from the exact list; put `idempotent` on `/accrual-runs` | Failed |
| Add the School check to `holdActing()` | **Survived:** every caller asks first, so the change was dead and was reverted |

**Direct service boundary.** `refuseOwnRecord` has two callers, both inside a transaction with a School in context. The generic writers are reached only from `DocumentController`, and the Employee ones only from `EmployeeDocumentService`.

**Published.** Code `563bb5a0`, candidate CI run 42410127957, `main` CI run 42412289842. Four shards, 2,862 each: 11,448 cases, 461,705 assertions, 0 failed, 1 ESI-12 skip. The coverage union passed. This is the remote full-regression checkpoint, counter 0/5.

**Assertions fell by 752 while cases rose by 38.** Compared per test against C13's run 42398199405: no test disappeared, and the 38 new cases add 149 assertions. 19 existing tests changed by -901 net. Most are concurrency tests whose barriers assert inside a polling loop (`ServiceAssertionReplayConcurrencyTest` -549, `CrossHostHandoffConcurrencyTest` -306). `navigation_never_names_a_role` (-180) counts once per row in `roles`, and it moved from shard 3 to shard 4. That leftover fixture roles differ by shard is an inference, not a measurement.

**First candidate.** `8577daee` was pushed with a gitleaks finding: an inert UUID in a test constant, `KEY`, which the generic rule matched. Nothing real was exposed. The check that should have stopped the push read the wrong exit status. Its run was cancelled, the literal was replaced with the repository's `test-key-` convention, no allowlist was touched, and `563bb5a0` replaced it.

**Reviews.** Four independent read-only passes, the last on `e0d93d19`. After it only comment and document wording and one test constant changed, and those were not reviewed again. None found a HIGH. Their MEDIUMs were corrected or, for C14-N5's open half, given an owner. The reviewer's trace of idempotent routes is static.

## 8. The C13 local anomaly

**Mechanism proven.** `ddev test` keeps running inside the container after its client is killed: 35 seconds later it was still alive. It then shares `school_os_test` with the next run, and unrelated tests deadlock or leave rows behind. It happened again in this checkpoint, when a `pkill` killed its own shell before reaching PHPUnit, and it produced the same symptoms: five HR failures and one documentation guard. All passed when rerun alone. That the C13 failures had this cause is likely, not proven. `TEST-ENVIRONMENT-AND-WORKTREE-SAFETY.md` records the check to make.

## 9. Findings

| Id | Status |
|---|---|
| C11-N2 | **CLOSED, C14.** C14-N2 and the import batch stay recorded |
| C11-N3 | **CLOSED, C14** |
| C12-N3 | **CLOSED, C14,** for its sites. C14-N1 holds the rest of the class |
| C14-N1 | OPEN, a decision for Security and Architecture |
| C14-N2 | OPEN, a decision for the Platform owner |
| C14-N3 | Recorded: `ddev test` survives its client |
| C14-N4 | Recorded: a batch of refused import rows is remembered. Current behaviour, no decision recorded |
| C14-N5 | **PARTIAL, C14.** An edit after a verification resets it. A verification after an edit attests unseen content: OPEN, MEDIUM, a decision for HR and Security |
| C14-N6 | **OPEN, new, P3.** Rule 86 in Payroll. Proven on two writers, recorded for one bounded corrective checkpoint |
| C11-N4, C12-N2, C12-N4, C12-N5, GOV-6 | Unchanged |

## 10. Limits

- **Browser:** none run. A refusal's status changed for one narrow case, and no screen changed.
- **Idempotent routes:** 130 routes across 56 controllers, traced statically for a commit before a School refusal: direct guard calls and known throw sites, not dynamic dispatch, and not every synchronous listener. None was found.
- **Rule 86 outside the HR family and Payroll** was not assessed. Payroll: C14-N6.
