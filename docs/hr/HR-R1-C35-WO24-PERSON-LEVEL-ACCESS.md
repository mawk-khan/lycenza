# HR.R1-C35 WO-24: unlinking never clears a separation's School access (WO20-N1, RF1-N2, WO20-N7, WO18-N1; 2026-11-20)

**HR.R1 stays OPEN.** This implements WO-24, the correction owner decision D-RF2-2 requires (`HR-R1-C35-RF2-RESIDUAL-DECISIONS.md` §5). An Employee's unresolved School access can no longer be hidden by changing which account is currently linked. Genuine mistaken-identity corrections still release, and historical separation evidence is untouched. R-HR12-3 is **not amended**.

| | |
|---|---|
| Baseline | `5b1cf382` = `main` = `origin/main` (WO-23 evidence); WO-23's runtime code `8ce263c5` (`d11a4537` + WO23-E2); clean tree, one worktree; the historical stash (`stash@{0}`, 2026-08-27) and `integration/e16-r2` preserved; 401 migrations |
| Authorized | D-RF2-2 (2026-11-20, HR and Identity owners) and the owner's WO-24 dispatch (2026-11-20): the bounded correction of RF2 §5.3, **item 1a included** (kept access recorded person-level, the one item RF2 §13 flagged for the owner at WO-24's start); code, tests, one migration proven on the isolated test database, an independent review, protected-main publication |
| Excluded by the dispatch | A person-identity master, name or attribute matching, automatic revocation or disabling (rule 92 stays the off-boarding path), Organization RBAC changes, rewriting a completed separation, a general unlink redesign, any change to R-HR12-3, new fresh MFA, WO-25, the ordinary DDEV migration (D-RF2-22: after WO-24 publishes), synthetic UAT data (D-RF2-23), HR.R1-C36 baseline selection, staging, production |
| Not changed | Lock order and lock set; the case state graph; capabilities; the WO-18, WO-20, WO-21, WO-22 and WO-23 contracts except as stated in §3; the development database (398 of 402 migrations; WO-21, WO-22, WO-23 and WO-24 pending there) |

## 1. The findings (verbatim, RF2 §5.1)

- **WO20-N1** (`HR-R1-C35-WO20-RETAINED-ACCESS-ROLE-SET.md:108`; LOW at WO-20 → **MEDIUM, so MAJOR**, D-RF2-2): "An unrecorded case, or a legacy record, still reads only the Employee's CURRENT link: unlinking the Employee turns OPEN into CLEAR, so completion can certify while the old account keeps its roles. Older than WO-20 (HR.15) and never a false RETAINED; closing it person-level (uncorrected links, rule 152) for every state is an HR and Identity owner decision".
- **WO20-N7** (:114; INFO, closed with WO20-N1): "An older, ended, uncorrected account of the person whose membership holds none of the kept grants is not checked (as for non-kept cases); closing it is WO20-N1's person-level decision".
- **WO18-N1** (`HR-R1-C35-WO18-OWN-CASE.md:93`; INFO, corrected by RF2 §5.3 item 4): "the deceased direct end (`EmployeeLifecycleService::separate()`) still compares the current link only."
- **RF1-N2** (`HR-R1-C35-RF1-RESIDUAL-FINDINGS.md:247-251`; LOW → **MEDIUM-associated, MAJOR** with WO20-N1): "the unlink self-check is current-link only. `unlinkUser()` refuses only `refuseSelfLink($actor, $locked->user_id)` … An older, uncorrected account of the same person (rule 152's "known same person") can unlink them".
- **R-HR12-3** (gate register :434), preserved: "a separated person keeps School staff access until someone off-boards them; completion of the case is blocked meanwhile | by owner decision (flag, never automatic)". Its cell is annotated with this record only.

## 2. Reproduced first (at `5b1cf382`, before any change)

A throwaway test (`Wo24ThrowawayReproTest`, deleted, never committed) on the isolated test database at the published 401 migrations. Every step went through the real services. The older link was written by a third party through `linkUser()`/`unlinkUser()` (rule 95), so this reproduction is complete where RF2's was partial (RF2 used a fixture update).

| Finding | Before | After WO-24 (same test) |
|---|---|---|
| WO20-N1 | OPEN → unlink by the same `hr.employees.manage` + `hr.separations.manage` holder → **CLEAR**, completion **accepted**, the grant still active, membership active | OPEN after the unlink; completion `SEPARATION_OBLIGATIONS_OPEN`; status `finalized` |
| WO20-N7 | a bound kept record (current account's grant), the older ended uncorrected account granted a role → **RETAINED**, completion **accepted** | OPEN; completion refused |
| RF1-N2 | link history `subject (ended), older (ended), subject (current)`, none corrected: the older account's unlink **accepted**, access then CLEAR | `HR_KNOWN_SAME_PERSON`; nothing written |
| WO18-N1 | the older account records the person's death directly: **accepted**, employment `deceased` | `HR_KNOWN_SAME_PERSON`; employment `active` |
| Database | as `school_os_app`, a raw `finalized → completed` with a live grant: **`ok`** | refused, `hr_separation_completion_access` |

## 3. What was built

**Identity's answer, for several accounts (`StaffAccessState`).** `activeSchoolGrantHolders(School, userIds)` returns, in one query, every live `school`-scope grant on an ACTIVE membership in this School of any named account, as `grant id => holder`, sorted as PostgreSQL sorts uuids. `activeSchoolGrantIdsOfAccounts()` returns the ids alone. WO-20's holder-of-bound-grants reader and the now-uncalled single-account reader are removed (review W24-I1). Identity still depends on no other domain.

**The person (`SeparationObligations::personAccounts()`).** Every account with an uncorrected link, current or ended, to the Employee (`HrSelfAdministrationGuard::uncorrectedLinkedAccounts()`, rules 145, 152), plus the current link. The current link is always in the history; adding it means a missing history row can only widen the answer. A link corrected as another person's leaves; an unlink, relink or replacement never does. Only an ended link can be corrected (`employee_account_link_corrections_apply`), so the current account is always counted.

**The obligation, every state (RF2 §5.3 item 1, WO20-N1, WO20-N7).** `schoolAccess()` reads the live grants of every person account in every case state:
- not kept, with a grant: ATTENTION before finalization, OPEN after;
- a pre-WO-20 record (no set): OPEN/ATTENTION (D-WO20-2 unchanged);
- a bound record: RETAINED only while every live grant of every person account is in the set. A bound record made before WO-24 holds the then-current account's set, so an older account's role reads OPEN until off-boarded (D-WO20-1).
- The obligation shows a count, never which account holds what. Its text now says "an account of this person".

**Kept access, person-level (item 1a).** `recordAccessRetained()` binds the live grants of every person account, read under Identity's School access lock (first, rule 141) and the Employee FOR SHARE (`lockCase()`), so no grant, link change or correction interleaves. Kept access can therefore be recorded after an unlink. The audit `hr.separation.access_retained` now also names each kept grant's holder (`holderUserIds`, ids only): the holder provenance. A grant never changes membership (`membership_role_assignments` history-keeping trigger), so a grant id names its holder for good. Still recorded once (D-WO20-1); a pre-WO-20 record still binds nothing (D-WO20-2).

**A correction still releases (item 2).** A link corrected as another person's (rule 145: two linked staff, fresh MFA) leaves the person set in every state, at the service and in the database. The unrelated person's roles are never touched.

**Person-level unlink (item 3, RF1-N2).** `EmployeeService::unlinkUser()` keeps `refuseSelfLink()` first (`HR_SELF_ADMINISTRATION`), then calls `HrSelfAdministrationGuard::refuseKnownToBeEmployee()`, after the Employee row lock. That refuses an actor who, or whose known-same account, holds an uncorrected link to the Employee: `HrKnownSamePersonException`, 403 `HR_KNOWN_SAME_PERSON`. The message names no account. The actor is the authenticated User the controller passes (`$context->actor()` on the web, `$request->user()` on the API); no actor id is ever read from input. No fresh MFA was added (RF2 §5.3 item 6).

**Person-level death record (item 4, WO18-N1).** `EmploymentService::end()` keeps `refuseOwnEmployee()` first, then calls `refuseKnownToBeEmployee()` (`HR_KNOWN_SAME_PERSON`), after the Employee FOR SHARE.
- **Callers:** `end()` has two callers, `EmployeeLifecycleService::separate()` (the direct record of a death) and the separation finalization. The finalization already refused the same person in `lockEmployment()` (`SEPARATION_OWN_CASE`), which runs first, so it is unchanged.
- **No database half.** `employment_records` records no actor; the end's actor is known only to the application and the audit ledger. PostgreSQL has no recorded actor to judge (rule 102).
- **Lawful third parties.** An independent administrator still records a death (tested on the service and the web route).

**Database backstop (item 5).** Migration `2027_02_27_090000_bind_separation_access_to_the_person` patches two clauses of `hr_separation_retained_grants_guard` in its live definition (exactly-once anchors; never restated, rule 146). It adds no table, trigger or data change.
- **The recorded set (item 1a):** the guard's set requirement reads the same person set as the application: uncorrected links joined through the case's employment, UNION `hr_separation_subject_user()`. A set that omits a person account's grant is refused.
- **Completion (new):** a transition to `completed` is refused (`hr_separation_completion_access`) while a person account holds a live School grant outside `COALESCE(access_retained_grant_ids, '{}')`. With nothing kept, or a legacy record, any grant refuses.
  - It judges the transition only (`OLD.status IS DISTINCT FROM 'completed'`), so no completed case is ever re-judged or rewritten.
  - It needs no actor identity (rule 102, ADR 0076 §64).
- **Mode and context:** the function stays SECURITY INVOKER with `search_path = pg_catalog, public, pg_temp`, and every reference is schema-qualified. Each read is bound to `NEW.school_id` or to the case's own employment (`hr_separation_subject_user()`, under RLS), and the case row itself is visible only in its School's context (forced RLS), so another School's context or none updates no row.
- **Service mapping:** `SeparationCaseService::transition()` maps the refusal to `SEPARATION_OBLIGATIONS_OPEN`. Trigger order is unchanged: the case guard (`…_guard`) fires before `…_retained_grants`, so WO-18's own-case refusal still judges first.

**Verifier.** `SEPARATION_RETAINED_GRANTS_GUARD_BODY` is re-pinned (`de19aa79…` → `0dd9ef00…`), under `hr_separation_retained_access_binding`. The trigger definition is unchanged, so `HR_FAMILY_TRIGGERS` keeps 112 triggers on 45 tables.

**Rules and documents.**
- CLAUDE.md: rule 137 (kept access, person-level; "Unlinking never clears School access") and rule 152 (unlink and employment end).
- ADR 0028: a WO-24 amendment.
- `docs/security/AUTHORIZATION.md` and `docs/modules/HR.md`: `HR_KNOWN_SAME_PERSON`.
- The gate register's R-HR12-3 cell (annotation only), the rollback inventory, the audit register and the closure contract.

## 4. Evidence

**New tests (as `school_os_app`).**
- **`SeparationPersonLevelAccessTest`** (20 tests):
  - §5.2's sequence becomes a refusal: OPEN after the unlink, completion `SEPARATION_OBLIGATIONS_OPEN`, grant and membership intact, then CLEAR and completed once off-boarded;
  - every state: recorded and approved read ATTENTION, finalized OPEN, completed shows a later role as OPEN without rewriting the completion (version, completer and time unchanged);
  - a legacy record after an unlink: OPEN;
  - WO20-N7 (a bound set without the older account's later role): OPEN, never recorded again, RETAINED once off-boarded;
  - kept access recorded after an unlink, binding the older account's grant, with its holder in the audit;
  - two accounts' grants bound together, an unrelated account's never; same count, different set across accounts: OPEN;
  - a correction releases in the approved and finalized states, and the unrelated person keeps their role; an unlink is recorded as ended, never corrected;
  - another School's roles are not counted, a Campus-scoped role is, a suspended membership is not; a never-linked Employee says so;
  - unlink: older account and known-same alias `HR_KNOWN_SAME_PERSON` with no `UPDATE "employees"` attempted, the current account `HR_SELF_ADMINISTRATION` first, a corrected older account allowed; API and web routes deny and allow;
  - death record: older and alias `HR_KNOWN_SAME_PERSON`, the current account `HR_SELF_ADMINISTRATION`, missing authority and another School's administrator refused, an independent administrator allowed, a corrected older account allowed; the web route denies and allows;
  - raw: completion refused by `hr_separation_completion_access` (not kept, after an unlink, an older account's role, outside a kept set), with off-boarding and a correction as positive controls; a recorded set omitting either account refused by `hr_separation_retained_grants_guard`, the exact person set accepted;
  - a grant written after the service's read is caught by the database, with the service's UPDATE observed (review W24-R2);
  - another School's context, and no context, update no row (rule 28); the web completion denies, then completes after off-boarding.
- **`SeparationPersonLevelAccessConcurrencyTest`** (10 tests, committed data, two OS processes, the contender observed waiting on a lock):
  - an unlink held → the waiting completion is refused; a completion held → the waiting unlink proceeds and the completion stands;
  - a grant to an older account held → the waiting completion is refused; a completion held → the grant proceeds, the completed case shows OPEN, nothing rewritten;
  - a correction held → the waiting completion completes; a completion held → the correction is applied after it;
  - a grant to an older account held → the waiting recording binds both accounts' grants; a recording held → the later grant reopens the case;
  - a correction held → the older account's waiting death record is then allowed (before it: `HR_KNOWN_SAME_PERSON`);
  - a pre-WO-20 record (written below the guards): after an unlink the runtime role's completion is refused by the database.
- `tests/Support/acting-employee-op.php` gains a `deceased` operation.

**Contract changes in existing tests (recorded, not weakened).**
- `SeparationOwnCaseTest` (two tests): the separating person's OLD account holds School roles. Completion was accepted only because the current account held none. It is now refused (service and database) until an independent officer records kept access, then completes; WO-18's own-case refusal still judges OLD first.
- `SeparationRetainedAccessBindingTest::no_role_no_account_and_no_authority_record_nothing`: after an unlink the old account's role is still access (OPEN), so "nothing to keep" now needs that role off-boarded (item 1a).

**Suites, on the isolated test database (sequential, before the review fixes):** HR 1,566, Identity 199, Authorization 236, Postgres 1,259, Operations 15: all green. After the review fixes: HR/Separation 120, App 961, Leave 238, Payroll 583, Staff Attendance 76, Retention 291, Organization 97, WorkforceTasks 59, Architecture 9, Routing 1, Platform 283, Http 43, Demo 28, TeachingAssignments 77, Unit 501 (one skip: ESI-12, the legal deferral); the two new files again after the recheck fixes (30). All green. Identity ran through the isolated `bin/safe-test` stack, not `ddev test`, so the development DDEV project was never started or touched.

**Static gates.** `/publish-gate` PASS on the candidate tree: Pint, Larastan, whitespace (untracked included), gitleaks on the changed files. No contract or shared-type change.

**Planted service probes** (5; each source restored, the restored tree re-run green), each failing the new tests:
- the obligation reads the current link only (14 failures);
- corrected links counted (2);
- the person-level unlink check removed (2);
- the person-level end check removed (3);
- the recording binds the current account only (3: the database refuses the set).

**Planted schema probes** (9, on the isolated database, each followed by `platform:verify-database` and a restore to the pinned body `0dd9ef00…`):
- the completion clause disabled, reduced to the current link, or ignoring ended links: the verifier's body pin and the four raw tests;
- its School filter removed: the body pin;
- the trigger dropped, disabled, narrowed to INSERT: `hr_family_trigger_functions` and `hr_separation_invariants` (and the raw tests, for the drop);
- `search_path` reset, SECURITY DEFINER: `hr_family_trigger_functions` and `hr_separation_retained_access_binding`.

Final verifier `failed=0` (the connection-encryption operator row is expected outside production).

**Rollback/reapply.** `/rollback-proof 1`: IDENTICAL schema after rollback and re-apply (25 schema lines removed by the rollback); `platform:verify-database` failed=0. Rolling back restores WO-20's current-link reading and reopens WO20-N1; rule 143 confines rollback to local and testing.

**Performance** (throwaway probe, deleted). One Employee with 5 linked accounts (4 ended, 1 current), 8 grants each, among 300 other staff:
- the obligation read took 12.3 ms (13 queries);
- recording kept access took 56.6 ms (38 queries; 40 grants bound);
- completion took 62.1 ms (30 queries).

The grant read is one query whatever the number of accounts or grants, so there is no N+1. WO-20's single-account figures were 64 ms (36) and 13.8 ms (14). On this small, unanalyzed fixture the planner used the `school_id` indexes; plan quality at School scale is a staging measurement (D-7). No index was added.

**Human identity.** The database binds the person set and refuses the completion without knowing who acted (rule 102). The unlink and death checks are application-only: the death has no recorded actor, and the unlink's history row records `ended_by_user_id` from a caller-set session value that a raw writer may omit (§6, WO24-N2). Two accounts are never proof of two people, and unknown is never proof (C33).

## 5. Independent review

An independent reviewer read the working tree, read only (no tests, database or containers). It is a review, not an owner, Security or Legal approval. **No HIGH, no MEDIUM.**

| # | Rating | Finding | Treatment |
|---|---|---|---|
| W24-R1 | LOW | A link-correction approval of a CURRENT link calls `unlinkUser()`, so a reviewer known to be the Employee through another account is now refused there (`HR_KNOWN_SAME_PERSON`), while the same reviewer approving the correction of an ENDED link is not. The page offers an action the service refuses | **Recorded, WO24-N1.** Fails closed. Extending `mayDecide()` to person level for both link states amends rule 145's correction contract: an owner decision |
| W24-R2 | LOW | The database-backstop test could not tell which layer refused | **Fixed:** the service's UPDATE is observed |
| W24-R3 | LOW | The unlink check has no database half, though the link history records `ended_by_user_id` | **Recorded, WO24-N2:** the value comes from a session setting the caller sets (`app.link_actor_user_id`), which a raw writer may omit (NULL is admitted). Judging it would judge the writer's claim; RF2 §5.3 item 3 asked the service check only |
| W24-R4 | LOW | An account known to be the person only through an alias (shares a link to ANOTHER Employee with one of this Employee's accounts) is refused as the Employee, but its roles are not read for this Employee's access | **Recorded, WO24-N3.** Conforms to RF2's wording (`uncorrectedLinkedAccounts()`); widening the access set is an owner decision |
| W24-R5 | LOW | The throwaway benchmark sat in the test tree | **Fixed:** deleted before staging |
| W24-R6 | LOW | HTTP allow cases missing for the death record and completion | **Fixed:** web allow for the death record; a web completion deny/allow pair |
| W24-I1 | INFO | `activeSchoolGrantIds()` had no caller | **Fixed:** removed |
| W24-I2 | INFO | The backstop is not serialized against a raw grant committing concurrently (READ COMMITTED); the application path is, by `SchoolAccessLock` | Recorded; acceptable for a backstop |
| W24-I3 | INFO | This record did not exist yet; kept roles of another account of the person are listed on the case page without naming the account | Written; display unchanged |

**Confirmed by the reviewer:**
- the same person set at the service and in the database; corrected links leave both; an unlink never acts as a correction;
- the recorded set's filters match the guard exactly (School, active membership, unrevoked, `school` scope, Campus grants included, uuid order, no duplicates), read under Identity's lock, the placement lock and the Employee FOR SHARE;
- the backstop fires on any UPDATE into `completed`; a legacy record blocks on any role; NULL handling; explicit School filters; trigger order; no case where the database refuses what the application allows;
- the new refusals after the row lock, the current-link refusal first, independent actors allowed; the no-actor claim for the death record;
- no new lock or lock order; no N+1;
- the migration's anchors, symmetry, INVOKER and `search_path`, qualified references, the verifier pin and the rollback inventory;
- no account named in refusals or obligations; audit ids only;
- the tests prove their claims; the three edited tests are genuine contract changes; nothing beyond scope (no MFA, no revocation, R-HR12-3 only annotated).

**Final-state recheck** (the same reviewer, read only, of every change made after the review). No new HIGH or MEDIUM. All five fixes (W24-R2, R5, R6, I1 and the record) confirmed: the backstop test now separates the database refusal from the service's and from an UNAVAILABLE obligation; nothing calls the removed reader; §3 to §6 check out against the code (corrections only of ended links, grant membership immutable, trigger order, actor binding, the WO24-N2 reason). Found and fixed:
- **W24-R7 (LOW):** the web completion deny case accepted any refusal; it now asserts the access obligation's own message.
- **D1 (LOW):** rule 152 and the ADR gave the death record's no-database-half reason for the unlink too; each now has its own.
- **D2 (LOW):** ADR 0028's WO-20 amendment named the removed reader; it now names its WO-24 successor.
- **D3 (INFO):** "each read filters on `NEW.school_id`" overstated the subject-user branch; reworded.

## 6. Dispositions

| Item | Status |
|---|---|
| **WO20-N1** (MEDIUM, MAJOR) | **CLOSED for development on publication** |
| **RF1-N2** (MEDIUM-associated, MAJOR) | **CLOSED for development on publication** |
| **WO20-N7** (INFO) | **CLOSED** with WO20-N1 (item 1) |
| **WO18-N1** (INFO) | **CLOSED** (item 4; no database half, reason in §3) |
| R-HR12-3 | Decision unchanged; cell annotated |
| WO24-N1 (LOW) | Correction approval of a current link is person-level, of an ended link is not (W24-R1). Owner decision (rule 145) |
| WO24-N2 (LOW) | Unlink self-check is application-only (W24-R3) |
| WO24-N3 (LOW) | Alias-only accounts refused as the Employee but not read for access (W24-R4). Owner decision |
| WO20-N5 (LOW) | Unchanged: display only; may appear more often now (RF2 §5.3 item 6) |
| WO20-N6 | Stays VERIFIED CLOSED (D-RF2-19): completion holds the Employee FOR SHARE, link changes FOR UPDATE; the races above confirm it for unlink and correction |
| Development MAJOR inventory | **None open** |
| WO-25 | Pending (D-RF2-7), before the closure regression; not started |
| RF2's 81 decisions, the 361 capability rows, the 61 audit identifiers, RG-ID-1 (open), R1-02 (PARTIAL), C33-H1..H5, Legal/DPO gates | Unchanged |

## 7. Sequencing kept

- **DDEV:** not touched. D-RF2-22's operator step runs after this publishes and must name every pending migration: `2027_02_24`, `2027_02_25`, `2027_02_26` and `2027_02_27`.
- **Synthetic UAT** (D-RF2-23): after the DDEV step; not started.
- **WO-25, HR.R1-C36, staging, production:** not started.

## 8. Publication

- **Candidate `e7d7ee02`, CI run 44766606712: green on attempt 1, no reruns, no code correction after push.**
  - **11,998 tests (11,968 + the 30 new), 467,779 assertions, 0 failures, 1 skip (ESI-12)**;
  - four shards (3,000 / 3,000 / 2,999 / 2,999), the coverage union proven equal to the canonical inventory (1,419 files; selection sha256 `0d8fd41c…`);
  - static and frontend, full-history secrets, supply chain, repository integrity and production images all green.
- **Assertions:** 241 below WO-23's 468,020 although 30 tests were added. Data-dependent counts vary between runs (RF2 §8: no monotonic assertion total is required). The test count rose by exactly the 30 new tests, and no test was removed.
- **Committed-range gitleaks** (`5b1cf382..e7d7ee02`, the pinned image and repository allowlist): no leaks.
- **Published** with `lycenza-ci publication-check --candidate e7d7ee02… --push` on 2026-11-20: fast-forward, gate check run 136403490618, no problems. `origin/main` = `e7d7ee02`. E16 (`OWNER-0O-E16-2026-11-01`) valid through 2026-11-28.
- **Main CI** on `e7d7ee02`: run 44770047131, green on attempt 1.
- **Regression counter:** the exact-SHA full suite of a code-lane candidate is the canonical regression (ADR 0072 §28.8), so this is a **remote checkpoint at `e7d7ee02`; counter 0/5.**
- The integration branch was deleted after the merge; `integration/e16-r2` and the historical stash are preserved.

**Verdict: WO-24 IMPLEMENTED — PERSON-LEVEL RETAINED ACCESS DEVELOPMENT VERIFIED.** No development MAJOR is open. HR.R1 stays **OPEN**: WO-25, the DDEV migration (D-RF2-22, which must name `2027_02_24` to `2027_02_27`), the synthetic UAT (D-RF2-23) and HR.R1-C36 remain, none started.
