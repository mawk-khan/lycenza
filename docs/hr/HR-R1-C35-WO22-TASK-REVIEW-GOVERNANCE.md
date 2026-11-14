# HR.R1-C35 WO-22: staff-task review is a governed workflow (TRR-6; 2026-11-14)

**HR.R1 stays OPEN.** This implements WO-22, the consequence of owner decision D-C35-5 (`HR-R1-C35-P3-DISPOSITIONS.md` §9). A reviewed staff task's review is registered as a domain-owned decision workflow. It reads the reviewer's authority fresh, and refuses a reviewer known to be the assignee, in the service and the database. It is the last of the four approved P3 work orders.

| | |
|---|---|
| Baseline | `cb57c399` = `main` = `origin/main`; `f03aa6aa` the WO-21 code; clean tree; the historical stash and `integration/e16-r2` preserved |
| Authorized | D-C35-5 (2026-11-13, Architecture owner) and the owner's WO-22 dispatch: code, tests, a migration on the isolated test database, an independent review, protected-main publication |
| Not changed | Submission, self-completion, assignment, reassignment, cancellation, generation, task terms, capabilities, the task state graph, history; no fresh MFA (not decided); the development database (398 migrations; WO-21's and WO-22's migrations not applied there) |

## 1. The finding

**TRR-6** (`HR-R1-0-AUDIT-REGISTER.md`, P3, VERIFIED, Approval and Tasks), verbatim: "task review (submitted → completed/returned) is a request/decide step outside `ApprovalWorkflowRegistry`. It reads authority through cached `CapabilityResolver::can()` (`TaskAuthority.php:36-39`; cache at `CapabilityResolver.php:62,169`). The registry's regex does not detect `completed_by_user_id` (`ApprovalWorkflowRegistryTest.php:24`). **Disposition:** for HR.R1, register it or declare it exempt with a reason."

**D-C35-5:** "Register task review as a domain-owned workflow (`makerChecker: domain`, backstop `trg_workforce_tasks_guard`), with a fresh authority read under the task lock and the known-same-person refusal rule 152 requires of an HR-family workflow." WO-22's scope adds "service and database; the registry test detects `completed_by_user_id`". C35's review had rejected the first recommendation, to declare the review exempt, because a second person's decision is a workflow.

**Reproduced first** at `cb57c399` (a throwaway test, deleted):
- an account with an ended, uncorrected link to the assignee's Employee completed the review;
- a reviewer whose grant had been revoked behind the warmed cache completed one too.

## 2. The workflow

- **Owner:** WorkforceTasks (HR family, HR.13). The single writer is `TaskWorkflowService` (rule 151).
- **Lifecycle** (unchanged):
  - assigned or returned → submit → completed (self mode) or submitted (reviewed mode);
  - submitted → **review: complete** → completed;
  - submitted → **review: return** → returned (once);
  - any open → cancel → cancelled;
  - reassignment cancels and creates a linked task (`TaskAssignmentService`).
- **The governed decision** is `review()` (complete or return) only. Submission is the assignee's own action. Self-completion is no review. Cancel and reassign are administrative, keep their existing rules, and are out of scope.
- **Actors:**
  - the assignee (the task's employment's Employee; the submitter is its current account);
  - the reviewer, on the administrative path (`hr.tasks.manage`) or the manager path (`hr.tasks.team` and the CURRENT HR.3A line).
- **Protected relation:** the reviewer is never the assignee, by the current account (as before) or as a person known to be the assignee (rule 152).
- **Entry points:** web `POST /app/hr/tasks/{task}/review` (`HrTaskController::review`, route `capability:hr.tasks.manage|hr.tasks.team`), the one caller of `review()`. There is no API route, job or command that reviews a task; the runtime role's direct writes are the database guards' (§3).

## 3. What was built

- **Registry:** `ApprovalWorkflowRegistry::WORKFLOWS['workforce_task_reviews']`:
  - domain WorkforceTasks; request `hr.tasks.self`; decide `hr.tasks.manage`, `hr.tasks.team`;
  - participants: the submitting event's actor; decider `workforce_tasks.completed_by_user_id`; `makerChecker: domain`; backstop `trg_workforce_tasks_guard`;
  - service `TaskWorkflowService`, decision `review`; no fresh MFA;
  - `knownSamePerson`: the check `TaskAuthority::isKnownToBeAssignee`, the guard `trg_workforce_tasks_guard`, and `trg_workforce_task_events_guard`.

  The registry test's decision-column pattern now includes `completed`. That classifies `employment_separation_cases.completed_by_user_id` in `NOT_WORKFLOWS`: completion executes an already approved case. The known-same-person coverage test includes WorkforceTasks.
- **Fresh authority:** `review()` decides under the task lock (FOR UPDATE) through `lockReviewable()`. `TaskAuthority::currentPathFor()` reads the path fresh (`holdsInSchoolNow()`: the active membership and its grants FOR SHARE to commit, the account and the module read fresh; the manager line FOR SHARE). The cached `pathFor()` only chooses the refusal: never on a path is the private "not found" (as before); authority lost behind the cache is a 403. The School stays FOR SHARE (rule 86).
- **Known same person:** after the own-account refusal (`TASK_SELF_ADMINISTRATION`, as before), `TASK_SAME_PERSON_REVIEW` refuses:
  - a reviewer known to be the assignee (`TaskAuthority::isKnownToBeAssignee()` → `HrSelfAdministrationGuard::isKnownToBeEmployeeOfEmployment()`): it holds the assignee's Employee row FOR SHARE, then `isKnownToBeEmployee()`, an uncorrected link of the reviewer, or of an account known to be the same person, to that Employee;
  - the account that submitted the task, or one known to be the same person as it (`TaskAuthority::isKnownToBeSubmitter()`, review L1). The submitter is the maker even after an erroneous link of it is corrected.

  The message names nobody.
- **Database** (migration `2027_02_25_090000_refuse_known_same_person_task_review`, live definitions patched at exactly-once anchors, rule 146):
  - `hr_workforce_tasks_guard` refuses a review completion recorded for such an account (known to be the assignee, or the submitter or known to be them);
  - `hr_workforce_task_events_guard` refuses a `returned` event by one, because a return records its reviewer only on the timeline;
  - both use `employee_account_link_same_person_accounts()` (INVOKER under RLS), with the refusal marker `hr_workforce_task_review_same_person`, which the service maps to the same code;
  - `down()` removes exactly the two clauses.
- **Verifier:** both triggers in `KNOWN_SAME_PERSON_GUARDS`, both bodies in `KNOWN_SAME_PERSON_BODIES`, both checked by `known_same_person_guards`.
- **Contracts:** the ADR 0028 WO-22 amendment, CLAUDE.md rules 139 and 152, `PAY-15-ROLLBACK-GUARD-INVENTORY.md`.

## 4. Evidence

| Proof | Test | Result |
|---|---|---|
| An independent current reviewer completes or returns | `WorkforceTaskReviewGovernanceTest` | pass |
| Complete and return: one's own account (`TASK_SELF_ADMINISTRATION`), an ended uncorrected link, and an account known to be the same person through ANOTHER Employee (`TASK_SAME_PERSON_REVIEW`): nothing recorded, no success audit | same (2 cases) | pass |
| The service refuses before any write is attempted (statement capture), not only the database | same | pass |
| The submitter whose link was then corrected never reviews (service and raw SQL); an independent reviewer does | same | pass |
| A corrected link no longer counts; a never-linked reviewer (unknown) is not refused | same | pass |
| Fresh authority, cache warmed then lost: grant revoked, membership suspended, account disabled (403); School suspended (not operational); nothing recorded | same (4 cases) | pass |
| A current manager reviews; a manager not of this employment finds nothing (private 404) | same | pass |
| A completed review is never rewritten by a later link; a self-completed task is unchanged | same | pass |
| Runtime role: a completion and a `returned` event by a known-same account refused; independent ones accepted | same | pass |
| Registered, domain-owned, never exempt; review decides through `lockReviewable()` with the fresh read and both identity checks | same; `ApprovalWorkflowRegistryTest`; `KnownSamePersonCoverageTest` | pass |
| Two processes: revocation before (refused) and after (the review stands); a link of the reviewer to the assignee's Employee before (refused) and after (stands, never rewritten); two reviewers (one decides, the other is stale) | `WorkforceTaskReviewConcurrencyTest` | pass |

**Planted bypasses** (each restored; triggers re-enabled), all detected:
- the fresh read replaced by the cached path (6 failures);
- the known-same checks removed (4);
- the submitter check removed (1, after the submitter test gained the before-any-write assertion; the database refused first, so the first version of the test missed it);
- the registry entry removed (1);
- the task guard disabled (2);
- the event guard disabled (2).

**Scale:** one review 27.6 ms, 27 queries (cold caches); the identity checks are bounded reads, never per row.

**Rollback proof:** IDENTICAL schema after rollback and re-apply (17 schema lines removed); `platform:verify-database` failed=0. `RollbackProductionShapeTest` (the non-superuser path) passes.

**Suites** (each run alone): WorkforceTasks 39, HR 1,537, Identity 199, Authorization 236, Postgres 1,259, Operations 15, App 961, Leave 238, Payroll 583, Staff Attendance 76, Retention 287 (4 local skips: object storage), Organization 97. `RollbackProductionShapeTest` failed once during the suite run because it installed the migration while I was editing it (a syntax error in the review-L1 clause, since fixed); it passes.

**Independent reviews:**
1. **First review:** no HIGH, no MEDIUM. Fixed:
   - L1: the submitter was not checked;
   - L3: no test saw the service check's removal;
   - L4: the refusal code was matched on Laravel's message.

   L2 is recorded below.
2. **Final-state recheck** of those fixes: no HIGH, no MEDIUM, no LOW. It confirmed the clauses' grouping and ids, the exactly-once anchors and the body pins, the SQLSTATE mapping, and that a self-completion and a reviewer of another task are never refused. Two INFO items were addressed: a misleading test line removed, and the submitter check noted in the registry entry.

## 5. Dispositions

| Item | Status |
|---|---|
| **TRR-6** (P3) | **CLOSED for development on publication** |
| WO22-N1 (LOW, review L2) | The runtime role can set a task `returned` without its `returned` event; the event is the only record of who returned it, so a raw return escapes the identity guard. The service always writes both in one transaction. Inside rule 102's boundary (who acted); a deferred trigger or a `returned_by_user_id` column would close it |
| WO22-N2 (INFO) | The registry's `ownCaseGuards` lists the event guard, which is the return's backstop rather than an "own case" guard; `participants` names the event actor column, which also holds other timeline actors (the check reads the `submitted` events only) |
| WO22-N3 (INFO) | Cancel and reassign keep the cached path and the current-account self check (not request/decide steps; rule 152 does not name them): an account known to be the assignee can still cancel or reassign its task |
| WO22-N4 (INFO) | In a fresh process a revoked reviewer gets the private 404, not the 403 (its cache is empty); both refuse |
| Seven original P3 findings | HRC-4 (WO-20), HRC-8 (WO-21), LVA-4 (WO-19) closed; **TRR-6 closed on publication**; XRP-3 deferred (TeachingAssignments checkpoint; staging or above 1,000 staff); XRP-4 and TRR-8 accepted as documented |
| Development MAJOR inventory | None open |
| Other findings (WO21-N1..N5, WO20-N1..N7, WO19-N1..N6, WO18-N1/N2, WO17-N2, C32, C30, C29, C28, C10/C14/C19/C26), RG-ID-1, C33-H1..H5, R1-02 PARTIAL, Legal/DPO gates, 361 capability rows, 61 audit identifiers | Unchanged; no baseline selected |
| Development database | 398 migrations; WO-21's `2027_02_24` and WO-22's `2027_02_25` not applied there (the owner's word needed) |
