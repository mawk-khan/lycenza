# HR.R1-C35 WO-20: kept School access bound to the exact role-grant set (HRC-4; 2026-11-14)

**HR.R1 stays OPEN.** This implements WO-20, the consequence of owner decision D-C35-2 (`HR-R1-C35-P3-DISPOSITIONS.md` §9): when a separation records that School access is kept, the record is bound to the exact set of School role grants at that moment.

| | |
|---|---|
| Baseline | `31a14420` = `main` = `origin/main`; `3786968e` the WO-19 code; clean tree; the historical stash and `integration/e16-r2` preserved |
| Authorized | D-C35-2 (2026-11-13, HR and Identity owner) and the owner's WO-20 dispatch; owner decisions D-WO20-1, D-WO20-2 and D-WO20-3 in this unit (§2) |
| Not changed | Roles, capabilities, the role catalogue, who may record kept access, off-boarding, the separation case graph, WO-18's own-case refusals, Leave, Payroll, WO-21 and WO-22 |

## 1. The finding

**HRC-4** (`HR-R1-0-AUDIT-REGISTER.md`, P3, owner-decision dependency, HR and Identity), verbatim: "**RETAINED is a one-off boolean, not bound to the grant set.** A role granted after access was recorded as kept stays non-blocking at completion." `SeparationObligations.php:127-131`. Recommendation: "Bind it to the grant count or set at recording time, or record it as a residual."

**D-C35-2** chose the set: "Bind RETAINED to the grant SET (the role-assignment ids) at recording; a grant outside that set returns the obligation to ATTENTION/OPEN. A revoke-then-grant keeps the count but not the set, so the set is what is bound."

**Reproduced first** at `31a14420` (a throwaway test, deleted):
- after "kept" was recorded with one role, a second role granted later read RETAINED (count 2) and completion **succeeded**;
- revoking the kept role and granting another (count still 1) read RETAINED.

## 2. Owner decisions in this unit (2026-11-13)

| Id | Question | Decision |
|---|---|---|
| D-WO20-1 | How is an OPEN case resolved once kept access was recorded? | **Off-board only.** The record stays immutable (the case guard already froze it) and is never recorded again; the case completes once the extra role is revoked under Staff accounts |
| D-WO20-2 | A kept record from before WO-20, with no set | **Fail closed: OPEN**, shown as recorded before the kept roles were stored; never RETAINED, never back-filled |
| D-WO20-3 | The development database (396 migrations, lacking `2027_02_22_090000`) | **Forward-migrate after WO-20 publishes**, through `pgsql_admin`, then verify |

The development database held no separation cases, so D-WO20-2 changes no local data.

## 3. The semantics

- **Workflow.** `SeparationCaseService::recordAccessRetained()` on a finalized case (`school.members.manage`), the only writer of `access_retained_*`. The obligation is `SeparationObligations::schoolAccess()`, read by the case page and by completion.
- **Subject.** The separating Employee's currently linked User (`employees.user_id`), the account whose access the obligation reports. Not the recorder; not other or past accounts. A relink to another account brings grants outside the set, which reopen the obligation.
- **School.** The case's School only: an active membership there, unrevoked grants of `school`-scope roles (a Guardian grant is not staff access). Campus-scoped grants are grants of that membership and are included.
- **Decision point.** The recording's transaction, after Identity's School access lock (taken first, rule 141); the set is read inside it and written with the recording. The page's earlier view is never used.
- **Source.** Identity's own rows through `StaffAccessState::activeSchoolGrantIds()`: HR never re-implements RBAC.
- **Representation.** `access_retained_grant_ids uuid[]`, the grant ids sorted as PostgreSQL sorts uuids, no duplicates. Grants, not roles' display names; roles, not capabilities; never a count. A grant row is never deleted and a re-grant is a new row (rule 92), so an id names one grant of one role for good; a role's key never changes (rule 93).
- **Empty versus unknown.** Recording needs live access: a confirmed empty set (no grant, no membership, no linked account) is refused (`SEPARATION_NO_ACTIVE_ACCESS`) and nothing is stored. An Identity read that fails makes the obligation UNAVAILABLE, never OPEN, so recording is refused too. Nothing unknown is ever stored as empty.
- **RETAINED.** Only while every live grant is in the bound set. A kept grant since revoked leaves the rest kept.
- **Live authority.** The record grants and keeps nothing: Identity's grants stay the only authority, and revoking or suspending works as before.

## 4. What was built

- **Migration** `2027_02_23_090000_bind_separation_retained_access_to_grant_set`:
  - the column;
  - a shape CHECK (a non-empty set only with a recording);
  - `trg_employment_separation_cases_retained_grants` → `hr_separation_retained_grants_guard()` (INVOKER, `search_path = pg_catalog, public, pg_temp`, schema-qualified). At recording it requires the stored set to EQUAL the account's live grants, read again from Identity's tables; it refuses a set at insert, a rewrite, an erasure and a back-fill of a legacy record.
  - `down()` drops exactly these. No existing row is changed.
- **Identity:** `StaffAccessState::activeSchoolGrantIds()` (the set) and `grantRoles()` (each bound grant's role key, current name and whether it has since been revoked, this School only).
- **HR:** the recording takes the School access lock first (`certifiesAccess`), reads the set, refuses an empty one, writes it with the recording, and audits the grant ids (`roleAssignmentIds`). A database refusal of the set becomes `SEPARATION_ACCESS_CHANGED`. `schoolAccess()` compares the live set with the bound one. The read service returns `accessRetention`: recorded at and by, bound or legacy, and the kept roles.
- **Page:** a "School access kept" section shows the time in the School's timezone, then either:
  - the kept roles, with any Campus scope and any since revoked; only for a viewer who may see staff role assignments (`school.roles.view` or `school.members.manage`, review M1), others see the number of kept grants;
  - or, for a legacy record, that it cannot show them and does not count as kept.

  It never shows today's roles in their place.
- **Holders (review M2, delta M-A):** a bound set is also checked against the live grants of the memberships holding the kept grants, so unlinking or relinking the Employee never turns kept access with an extra role into CLEAR. This is limited to accounts with an uncorrected link to the Employee (`HrSelfAdministrationGuard::uncorrectedLinkedAccounts()`, rules 145 and 152): an account corrected as someone else is never asked to be off-boarded.
- **Recording time (review L1):** recorded at and by are set together (`employment_separation_cases_retained_pair`), and the time never changes once set.
- **Verifier:** the trigger in `HR_FAMILY_TRIGGERS` (109 triggers) and `HR_SEPARATION_GUARDS`; a new check `hr_separation_retained_access_binding` pins the column, both CHECKs by table and whole definition (delta L-B), the function body (md5), INVOKER mode and path.
- **Contracts:** ADR 0028 WO-20 amendment, `docs/modules/HR.md`, CLAUDE.md rules 137 and 151, `PAY-15-ROLLBACK-GUARD-INVENTORY.md`.

## 5. Evidence

| Proof | Test | Result |
|---|---|---|
| The recording binds exactly the live set, server-read; audited as ids; completion succeeds | `SeparationRetainedAccessBindingTest` | pass |
| A role granted afterwards: OPEN, completion refused, never re-recorded; the extra role revoked, RETAINED and complete | same | pass |
| Same count, different set (revoke-then-grant): OPEN, evidence unchanged | same | pass |
| Later changes never rewrite the set: a kept role revoked (still kept, marked revoked), a role renamed (current name shown), membership suspended, account disabled | same | pass |
| A relink to an account with roles reopens; unlinking, or relinking to an account without roles, never clears an extra role of the kept account (M2) | same | pass |
| An account corrected as someone else is never held to the set (M-A) | same | pass |
| Role names only for `school.roles.view` / `school.members.manage`; a count otherwise (M1) | same | pass |
| No role, no linked account, no authority, another School's case: nothing recorded | same | pass |
| A grant injected between the service's read and its write: the database refuses (`SEPARATION_ACCESS_CHANGED`), nothing recorded | same | pass |
| The web form cannot supply the set (posted ids ignored) | same | pass |
| Runtime role: partial, padded, other-School, unordered, duplicate, empty and pre-set sets, a recording without a set, a time without a recorder, a rewritten or erased set, a rewritten time: all refused; the exact live set accepted | same | pass |
| Two processes (holder held open, contender observed blocked): grant before recording, recording before grant, revocation before recording, off-boarding before recording, two recordings, completion before recording | `SeparationRetainedAccessConcurrencyTest` | pass |
| A pre-WO-20 record (written below the guards on committed data): OPEN, legacy shown, completion refused, never re-recorded, never back-filled | same | pass |

**Planted bypasses** (each restored):
- **Detected:**
  - count instead of set (2 failures);
  - the set check removed (6);
  - the holders check removed (2);
  - the capture omitted (14);
  - the access lock dropped (5);
  - role names shown to every viewer (2);
  - the uncorrected-link filter removed (1);
  - the trigger disabled in the test database (3).
- **Not detected, by design:** the School filter removed from Identity's grant reader, because forced RLS already returns only the current School's rows. The filter is defence in depth behind it.

**Scale** (one run, 40 grants): recording 64 ms (36 queries), obligation 13.8 ms (14), case page 34 ms (24). The set is one query, never per grant.

**Rollback proof:** IDENTICAL schema after rollback and re-apply (56 schema lines removed); `platform:verify-database` failed=0.

**Suites** (run alone): HR 1,520, Identity 199, Authorization 236, Postgres 1,259, Operations 15, App 961, Leave 238, Payroll 583, Staff Attendance 76, Retention 287 (4 local skips: object storage), Organization 97. Two failures came only from runs that overlapped on the test database (a separation run started during the suite run): `ReportingMigrationTest`, `ReportingLineEmploymentEndConcurrencyTest` and `PayrollAccountLinkRetentionTest` pass when run alone.

**Independent reviews:**
1. **First review:** no HIGH. Two MEDIUM: M1 role names shown to separation viewers without `school.roles.view`; M2 an unlink or relink cleared kept access with an extra role. Four LOW: L1 the recording time unguarded; L2 duplicate rows per Campus; L3 no raw test of a recording without a set; L4 browser timezone. All fixed.
2. **Final-state delta review:** no HIGH. One new MEDIUM, M-A: the M2 fix held an account corrected as someone else, a false OPEN resolvable only by off-boarding an unrelated person. Fixed. Two LOW: L-A (§6) and L-B (CHECKs pinned by name only, fixed).
3. **Targeted recheck of the M-A fix:** no HIGH, no MEDIUM; no dependency cycle (Identity still depends on no other domain); the current link is always in the link history; WO20-N5 to N7 recorded.

## 6. Dispositions

| Item | Status |
|---|---|
| **HRC-4** (P3) | **CLOSED for development on publication** |
| WO20-N1 (LOW, delta L-A) | An unrecorded case, or a legacy record, still reads only the Employee's CURRENT link: unlinking the Employee turns OPEN into CLEAR, so completion can certify while the old account keeps its roles. Older than WO-20 (HR.15) and never a false RETAINED; closing it person-level (uncorrected links, rule 152) for every state is an HR and Identity owner decision |
| WO20-N2 (INFO) | The School filter in `StaffAccessState::activeSchoolGrantIds()` is defence in depth: forced RLS already limits it, so a probe removing it is not detected by a test |
| WO20-N3 (INFO) | `grantRoles()` silently omits a bound grant whose row retention has purged (it shows the rest) |
| WO20-N4 (INFO) | `activeSchoolGrantCount()` now has no caller (kept as Identity's public answer) |
| WO20-N5 (LOW, recheck) | After a rehire (the same Employee), a COMPLETED case whose kept account gains a role for the new job reads OPEN with "Off-board it" on that old case. Display only, nothing is blocked (completion is done), and completed non-kept cases already behaved so; following it would off-board a current employee |
| WO20-N6 (LOW, recheck) | Completion does not take the lock that link changes take (the compensation lock and the Employee row), so a relink committing during completion can be missed; the current-link read already had the gap. A concurrent correction can only remove holders, so seeing OPEN first is the safe direction |
| WO20-N7 (INFO, recheck) | An older, ended, uncorrected account of the person whose membership holds none of the kept grants is not checked (as for non-kept cases); closing it is WO20-N1's person-level decision |
| WO20-E1 (test defect, fixed) | `TeachingAssignmentArchitectureGuardTest` compared a grep's file list in filesystem order, which differs between runs; the first WO-20 candidate (`338785ba`, CI 43531875656, shard 4) failed on it. Now sorted. Test-only, not a WO-20 regression |
| Development MAJOR inventory | None open |
| WO-21, WO-22 | Not started |
| Development database | Forward-migrated after publication (D-WO20-3): §7 |
| Seven P3 decisions, other P3/LOW findings (WO17-N2, WO18-N1/N2, WO19-N1 to N6, ...), RG-ID-1, C33-H1 to H5, R1-02 PARTIAL, Legal/DPO gates, 361 capability rows, 61 audit identifiers | Unchanged; no baseline selected |

## 7. Publication and the development database

- **First candidate `338785ba`, CI 43531875656: failed** on one unrelated test in shard 4 (WO20-E1, a filesystem-order comparison); shards 1-3, static, secrets, supply chain, integrity and images green. Not rerun: fixed and replaced.
- **Candidate `2c66460c`, CI run 43534600597: green, no reruns.**
  - **11,906 tests (11,887 + the 19 new), 468,676 assertions, 0 failures, 1 skip (ESI-12)**;
  - four shards (2,976 / 2,977 / 2,977 / 2,976), coverage union proven equal to the canonical inventory;
  - static and frontend, full-history secrets, supply chain, integrity and production images all green.
- **The assertion total is 1,056 below WO-19's** although 19 tests were added: data-dependent counts vary between runs (+1,907 at WO-19). Not traced test by test: a recorded limitation.
- Committed-range gitleaks (`31a14420..338785ba`): no leaks.
- **Published** with `lycenza-ci publication-check --candidate 2c66460c… --push` on 2026-11-14. E16 last valid day 2026-11-28, no problems. `origin/main` = `2c66460c`.
- **Regression counter 0/5:** this exact-SHA full suite is the canonical regression.
- **Development database forward-migrated (D-WO20-3)** through `pgsql_admin` after publication:
  - **Target verified first:** environment `local`, database `db`, exactly two pending: `2027_02_22_090000` (WO17-N1) and `2027_02_23_090000`. Both applied.
  - **Data preserved:** migrations 396 → 398; every other count unchanged (employees 13, account links 1, separation cases 0, leave decisions 0, payroll runs 2, School audit events 869). The locked October 2026 Staff Attendance month row is byte-identical (md5 before and after).
  - **Verified:** `platform:verify-database` failed=0 (operator evidence 1: connection encryption outside production); `hr_separation_retained_access_binding`, `hr_family_trigger_functions`, `hr_family_runtime_privileges` and `known_same_person_guards` PASS.
  - The development database is now current with the repository.
