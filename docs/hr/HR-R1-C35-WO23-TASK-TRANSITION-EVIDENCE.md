# HR.R1-C35 WO-23: a task's submission or return needs its versioned event (WO22-N1, RF1-N1, WO23-N1; 2026-11-20)

**HR.R1 stays OPEN.** This implements WO-23, the correction owner decisions D-RF2-1 and D-RF2-1a require (`HR-R1-C35-RF2-RESIDUAL-DECISIONS.md` §4). A staff task can no longer commit as `submitted` or `returned` without the timeline event that documents that exact task version. WO23-N1, a MEDIUM gap in the same guard found by this unit's independent review, is fixed here too, by the owner's choice. WO-24 (WO20-N1, RF1-N2) remains an open MAJOR.

| | |
|---|---|
| Baseline | `33166cd3` = `main` = `origin/main`; the WO-22 code `5b78154c`; clean tree; the historical stash and `integration/e16-r2` preserved; 400 migrations |
| Authorized | D-RF2-1/1a (2026-11-20, Architecture and HR owner); the owner's WO-23 dispatch (2026-11-20): code, tests, one migration on the isolated test database, an independent review, protected-main publication. WO23-N1 added to the scope by the owner on 2026-11-20 after the review |
| Not changed | The task state graph, authority (fresh reads, paths, capabilities), the WO-22 known-same-person refusals and their precedence, completion, cancellation, assignment, reassignment, generation, retention, runtime privileges. The development database (398 of 400 migrations; WO-21, WO-22 and now WO-23 are not applied there; DDEV migration is sequenced after WO-24, D-RF2-22). No synthetic UAT data |

## 1. The findings

**WO22-N1** (`HR-R1-C35-WO22-TASK-REVIEW-GOVERNANCE.md:104`), verbatim: "The runtime role can set a task `returned` without its `returned` event; the event is the only record of who returned it, so a raw return escapes the identity guard. The service always writes both in one transaction. Inside rule 102's boundary (who acted); a deferred trigger or a `returned_by_user_id` column would close it."

**RF1-N1** (`HR-R1-C35-RF1-RESIDUAL-FINDINGS.md:144`), verbatim: "the same holds for `submitted`: a raw transition to `submitted` with no `submitted` event is accepted. WO-22's submitter exclusion (`isKnownToBeSubmitter`) reads the `submitted` events, so such a task has no recorded submitter. The assignee check still applies."

**One invariant (RF2 §4.2).** `submitted` and `returned` are the two task states whose actor lives only on the event; the task row has no submitted-by or returned-by column. The findings are its two halves:
- **WO22-N1:** a raw return loses the reviewer and skips WO-22's check on the `returned` event.
- **RF1-N1:** a raw submission loses the submitter, so WO-22's submitter exclusion cannot fire.

**Severity:** MEDIUM, so MAJOR under C27-C (D-RF2-1, the ADR 0076 §64.12 precedent and rule 102: "If PostgreSQL can enforce a structural rule without knowing which human acted (... an evidence row's existence ...), enforce it in the database").

**Root cause** (code at `33166cd3`):
- `hr_workforce_tasks_guard` checks transitions, versions and return counts, but never an event.
- `hr_workforce_task_events_guard` read the task only for its assignee, and never its status.
- The pairing of state and event existed only in `TaskWorkflowService`, which updates the task, then inserts the event, in one transaction.
- Events had no version column and no unique index.

## 2. Reproduced first (at `33166cd3`, before the migration)

These are the new tests, run on the isolated test database at the published 400 migrations, with the published service:
- `a_raw_return_with_no_event_is_refused_at_commit_and_the_task_stays_submitted`: expected the WO-23 refusal, **got `ok`** (the raw return committed).
- `a_raw_submission_with_no_event_is_refused_at_commit`: **got `ok`**.

WO23-N1 and review L2 were reproduced against the published guard text, temporarily restored in the isolated database and then put back to the pinned body (`2a55e962…`):
- a reassigned task's assignee rewritten together with the link clearing: **`ok`**;
- a cancelled task's title and reason rewritten the same way: **`ok`**;
- a bare version bump: **`ok`**.

## 3. What was built

**Migration `2027_02_26_090000_bind_task_events_to_task_version`.** The guards are patched in their live definitions through exactly-once anchors, never restated (rule 146).
- **`workforce_task_events.task_version`:**
  - integer, NULL on every existing event (nothing back-filled, no historical event rewritten);
  - `CHECK (task_version IS NULL OR (task_version >= 1 AND kind IN ('submitted', 'returned')))`;
  - unique `workforce_task_events_one_per_version (school_id, task_id, task_version) WHERE task_version IS NOT NULL`.
- **Event guard (`hr_workforce_task_events_guard`, BEFORE INSERT).** A `submitted` or `returned` event needs a non-NULL `task_version`, and the task, in the event's School, must be in exactly that status at exactly that version (`hr_workforce_task_event_transition`). The clause sits after WO-22's same-person clause, which still refuses first (`WorkforceTaskReviewGovernanceTest:341`).
- **Commit-time check.** `trg_workforce_tasks_transition_evidence` is `CONSTRAINT TRIGGER AFTER UPDATE OF status ... DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (OLD.status IS DISTINCT FROM NEW.status AND NEW.status IN ('submitted', 'returned'))`. It calls `hr_workforce_task_transition_evidence()`: SECURITY INVOKER, `search_path = pg_catalog, public, pg_temp`, qualified references. At commit the function:
  - refuses when the task is invisible under RLS (`hr_workforce_task_transition_context`; an empty answer proves nothing, PAY-15);
  - refuses unless an event exists with the same School, task, `kind = NEW.status` and `task_version = NEW.version` (`hr_workforce_task_transition_evidence`).

  Each UPDATE carries its own `NEW`, so two transitions in one transaction each need their own event.
- **Task guard (`hr_workforce_tasks_guard`).**
  - **WO23-N1:** the branch for the reassignment FK's SET NULL action now requires every other column unchanged (`(to_jsonb(NEW) - 'reassigned_from_task_id') = (to_jsonb(OLD) - 'reassigned_from_task_id')`). Anything else falls through to the immutability and finality checks.
  - **L2:** a version advances only with a status change (`hr_workforce_task_version_transition`).
- **`down()`** reverses all of it in reverse order: the trigger and function, the three patches, the index, the CHECK, the column. It is listed in `PAY-15-ROLLBACK-GUARD-INVENTORY.md`.

**Application.**
- `TaskWorkflowService::submit()` and `review()` set `task_version` to the saved version on the `submitted` and `returned` events only. Other kinds keep NULL.
- There is no other writer of those states, and statement order is unchanged.
- `TaskEvent` gains `task_version` in `$fillable`. It is catalogued append-only (`HrModelMassAssignmentCatalogueTest`), not a lifecycle model; RF2 §4.4 item 4 called it a lifecycle model, which was wrong.
- The service docblock states the context invariant: each method commits inside `withSchool()` with the School context still set. A caller wrapping it in an outer transaction that commits after the context is restored fails closed. The only caller, `HrTaskController`, runs with the School already set by its middleware.

**Verifier (`platform:verify-database`).**
- `HR_FAMILY_TRIGGERS` gains the trigger (111 → 112 triggers on 45 of the 57 family tables). Its definition hash covers timing, `OF status`, WHEN and deferral; `hr_family_trigger_functions` pins the binding, owner, INVOKER mode and `search_path`.
- `hr_task_invariants` adds:
  - the trigger enabled, `tgdeferrable` and `tginitdeferred`;
  - the function body (`TASK_TRANSITION_EVIDENCE_BODY`);
  - the index and the CHECK, by name and by definition hash (`TASK_EVENT_VERSION_INDEX_DEF`, `TASK_EVENT_VERSION_CHECK_DEF`).
- `KNOWN_SAME_PERSON_BODIES` re-pins both guards (`hr_workforce_tasks_guard` `2a55e962…`, `hr_workforce_task_events_guard` `7c56cdb8…`).

**Rules.** CLAUDE.md rule 139 gains "Transition evidence"; rule 151's counts are reconciled with the code (57 tables, 112 triggers on 45 of them). ADR 0028 gets a WO-23 amendment.

## 4. Evidence

**Tests (all as `school_os_app`).**
- `WorkforceTaskTransitionEvidenceTest` has 21 tests. Inside the test transaction, the commit is simulated by `SET CONSTRAINTS trg_workforce_tasks_transition_evidence IMMEDIATE` and reset to DEFERRED in `finally`; an IMMEDIATE check would refuse the writers' own order, which is why the trigger is deferred. Each test asserts the invariant's own marker:
  - raw return and raw submission with no event, refused at commit, with no refusal at the UPDATE itself;
  - the canonical submit → return → resubmit → complete chain, with events `submitted@2`, `returned@3`, `submitted@4`;
  - self-completion and cancellation, which carry and need no version;
  - a raw transition with its matching event, accepted (positive control);
  - an event written before its transition; for another version (2, 4, NULL); of another kind or a generic entry; of another task; of another School (and, under School B's context, School A's task can be neither moved nor documented);
  - an earlier event reused, or a past transition documented afterwards;
  - one event per version;
  - two transitions in one transaction;
  - the context-less commit refused (`…_context`);
  - append-only events and no runtime DELETE;
  - the recorded actor being the writer's claim, with the assignee still refused first;
  - a version bump with no transition;
  - the link clearing carrying another change (assignee, title, due date, mode, status), and the same on a finished task, while the link alone may still be cleared;
  - the verifier checks passing.
- `WorkforceTaskTransitionEvidenceCommitTest` (4 tests) runs on committed data with a real COMMIT:
  - the canonical submission and return commit;
  - a raw return with no event fails at the real COMMIT (the driver's `PDOException`) and leaves the task submitted;
  - two OS processes, with the holder held open and the contender observed blocked on the task row: two returns, and a return against a completion. One decides, the other is `TASK_STALE`, and exactly one event documents each version.
- `tests/Support/task-review-op.php` now sets the School context before its held transaction, as the School middleware does.
- `WorkforceTaskReviewGovernanceTest:345`'s former positive control (a bare `returned` event with no return) is now a refusal: the contract change of RF2 §4.6 item 14.
- `HrFamilyTriggerFunctionsVerifierTest` pins 112.

**Suites, on the isolated test database.** WorkforceTasks 59, HR 1,537, Leave 238, Payroll 583, Staff Attendance 76, Retention 291, Postgres 1,259, Authorization 236, Operations 15, Architecture 9: all green.

**Static gates.** Pint, Larastan, whitespace (untracked included) and gitleaks pass (`/publish-gate`).

**Planted schema probes** (14, committed in the isolated database, each followed by the verifier and an exact restore with identical pins). Each was detected by `hr_task_invariants`, `hr_family_trigger_functions` or `known_same_person_guards`:
- trigger disabled, NOT DEFERRABLE, INITIALLY IMMEDIATE, narrower WHEN, bound to the wrong function;
- the version comparison or the kind comparison removed from the body;
- `search_path` reset;
- the event guard's version comparison removed;
- the index dropped, or replaced non-unique under the same name;
- the CHECK dropped, or weakened under the same name;
- the task guard's SET NULL branch reopened.

The final verifier result is `failed=0` (the connection-encryption operator row is expected outside production).

**Planted service probes** (4, the source restored byte-identical by SHA-256), each failing the canonical tests:
- the return event omitted;
- an earlier version on the return event;
- no version increment on a return;
- no version on the submission event.

**Populated-state migration.**
- With WO-23 rolled back on the isolated database, a legacy task was written committed in the published service's shape: submitted, then returned, with events and no version column.
- After re-migrating: the legacy rows were unchanged (versions NULL, nothing back-filled), and the task resubmitted canonically to v4 at a real COMMIT (`assigned@-, submitted@-, returned@-, submitted@4`).
- The throwaway probe was deleted.

**Rollback/reapply.** `/rollback-proof 1` reported an IDENTICAL schema after rollback and re-apply (53 schema lines removed by the rollback) and `platform:verify-database` failed=0. Rolling WO-23 back restores the WO22-N1 and WO23-N1 gaps; rule 143 confines rollback to local and testing.

**Performance** (throwaway probe, deleted). 20,001 tasks and 20,001 versioned events in one School:
- 20,000 pending commit-time checks ran in 0.95 s (0.048 ms each);
- a canonical submission took 15.9 ms and a return 19.3 ms;
- the evidence lookup is an Index Scan on `workforce_task_events_one_per_version` (4 buffers, 0.03 ms), and the event guard's task lookup an Index Scan on `workforce_tasks_pkey`.

The 14 s raw bulk transition of 20,000 rows is the existing per-row task and WO-22 guards, not WO-23. No target exists (D-7).

**Human identity.** The database proves that the evidence exists and matches the transition. It does not prove who wrote it: the event's actor is the writer's claim (rule 102), and a raw actor can still name any User on a properly matching event (`the_recorded_actor_stays_the_writers_claim…`).

## 5. Independent review

An independent reviewer read the working tree, read only. It is not an owner or Security approval.

| # | Rating | Finding | Treatment |
|---|---|---|---|
| M-1 | MEDIUM (pre-existing) | **WO23-N1:** the task guard's FK SET NULL branch returned before the immutability and finality checks; a raw UPDATE also nulling `reassigned_from_task_id` could rewrite a reassigned task, even a finished one | **Owner: fix inside WO-23** (2026-11-20). Fixed, reproduced first, tests and a probe |
| L2 | LOW | A version bump with no transition minted a "current" version an extra event could claim | Fixed (`hr_workforce_task_version_transition`), reproduced first |
| L3 | LOW | Index and CHECK pinned by name only; no WO-23 removal probes | Definitions pinned; 14 probes run (§4) |
| L4 | LOW | A stray probe file in `storage/`; legacy compatibility not in the suite | File removed. The populated-state proof is recorded in §4 (it needs a pre-migration state, so it cannot be a suite test) |
| L5 | LOW | The other-School test accepted any error; no School-B case | Exact markers; School-B case added |
| L6 | LOW | The visibility branch shared the evidence marker | Distinct marker `…_context`, asserted |
| L7 | LOW | ADR cited this record before it existed | This record |
| I8 | INFO | `task_version` fillable against the contract's wording | `TaskEvent` is append-only, not lifecycle; RF2's wording corrected here |
| I9 | INFO | `AFTER UPDATE OF status` would miss a status set only by a BEFORE trigger | None exists and the runtime role cannot create one; the trigger definition is pinned. Recorded (WO23-N2, INFO) |
| I10 | INFO | Fail-closed behaviour under an outer transaction | Acceptable: one caller, with the School already set; documented |

**Confirmed by the reviewer:**
- No path for the runtime role to commit `submitted` or `returned` without the event for that exact version:
  - multiple updates, savepoints, `SET CONSTRAINTS`, a blank or foreign context at commit;
  - a forged, early, foreign, wrong-kind, NULL or duplicate event.
- Versions only increase.
- Every legitimate writer still works.
- INVOKER, a pinned path and qualified references; WO-22's precedence; `down()`; no data rewrite; no change to authorization, RLS, privileges or retention.

**Final-state review** (an independent read-only pass over the corrected tree):
- **Every fix confirmed:** WO23-N1, L2, L3, L4, L5, L6 and L7 FIXED, and I8 confirmed. The anchors are unique and their indentation is right; `down()` reverses the three patches in order; the jsonb comparison covers every column and no other trigger writes the table; rule 151's counts are 57, 45 and 112.
- **No HIGH or MEDIUM.**
- **New findings:**

| # | Rating | Finding | Treatment |
|---|---|---|---|
| WO23-N3 | LOW | A raw UPDATE that clears ONLY `reassigned_from_task_id` still commits: the FK SET NULL shape the retention purge needs. It removes a redundant provenance link, and no term can change with it (WO23-N1) | **Owner: ACCEPTED (recorded), 2026-11-20.** Provenance stays in the original task's own `reassigned` event, its `cancel_reason = reassigned`, and the append-only `hr.task.reassigned` audit event naming `fromTaskId`. Re-review if anything relies on the link for authority or reports |
| F-2 | INFO | A task moved to `submitted`/`returned` and then deleted in the same transaction fails at commit with the `_context` marker | Fails closed; no real path does it. Recorded |
| F-3 | INFO | The CHECK definition pin looked the constraint up by name only | Fixed: `conrelid = 'public.workforce_task_events'::regclass` |
| F-4 | INFO | A missing event under the service would surface at commit, outside `review()`'s translation, as a 500 | The service always writes the event; only a raw writer can cause it. Recorded |

**Test defect found and fixed (WO23-E1).** `HrSeparationAndTaskRawIsolationTest::a_row_of_this_school_cannot_point_at_another_schools_parent` ("task to template") failed once in a local Postgres run, then passed three times. Its `first()` had no ORDER BY, and the fixture holds a generated task (with a template) and a manual task (template NULL). An unordered read could return School A's manual task, so the copied row referenced no foreign parent. That is consistent with the one failure, whose message was not captured. Test-only fix: the test now reads a row that has the parent, in `id` order, and asserts one exists. Not caused by WO-23; no product change.

## 6. Dispositions

| Item | Status |
|---|---|
| **WO22-N1** (MEDIUM, MAJOR) | **CLOSED for development on publication** |
| **RF1-N1** (MEDIUM, with WO22-N1) | **CLOSED for development on publication** |
| **WO23-N1** (MEDIUM, MAJOR, found and fixed here) | **CLOSED for development on publication** |
| WO23-N3 (LOW) | **ACCEPTED** by the owner (2026-11-20): a link-only clearing is the FK shape; provenance is kept in the original's event, reason and audit |
| WO23-E1 (test defect) | Fixed: a deterministic row choice in `HrSeparationAndTaskRawIsolationTest` |
| WO23-N2 (INFO) | Recorded: `AFTER UPDATE OF status` relies on no BEFORE trigger setting status (none exists; definitions pinned). Re-review if a trigger on `workforce_tasks` ever writes `status` |
| Development MAJOR inventory after publication | WO20-N1 and RF1-N2 (WO-24). HR.R1 is NOT CLOSED |
| WO-25 (WO22-N5, C26-N2) | Pending, before the final closure regression |
| RF2's 81 decisions, RG-ID-1, R1-02 PARTIAL, C33-H1..H5, Legal/DPO gates, 361 capability rows, 61 audit identifiers, 104 finding identifiers | Unchanged. No baseline selected |

## 7. Sequencing kept

- The DDEV database was not touched: it stays at 398 of 400 repository migrations, and 401 with WO-23. Its forward migration, now including `2027_02_26`, runs after WO-24 (D-RF2-22).
- No synthetic UAT School was created (D-RF2-23, after WO-24).
- Staging stays PAUSED; production stays NO-GO.

## 8. Publication

Recorded after the exact-SHA `CI gate`, in a documentation commit on top of the code.
