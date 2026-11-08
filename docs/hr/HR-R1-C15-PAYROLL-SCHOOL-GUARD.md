# HR.R1-C15: Payroll execution-time School check (2026-11-07)

**This is not the closure audit, and nothing here is an approval.** D-1 to D-10 and R1-01 stay pending. Baseline `1935712c`, C14 code `563bb5a0`. `HR-R1-CLOSURE-CONTRACT.md` stays the authority, and `HR-R1-0-AUDIT-REGISTER.md` the finding register.

## 1. Outcome

| Item | Disposition |
|---|---|
| C14-N6 | **CONFIRMED AND CORRECTED.** All 20 named methods reproduced writing on a suspended School, then refused |
| Count | 20, exactly as recorded. Four more writers take the guard for direct callers: see §3 |
| Severity | **P3 confirmed.** No production path reached the ledger: approve, post and reverse were already guarded on theirs |
| Rule 142 | Unchanged. The School claim is FOR SHARE and sits outside its cycle: §4 |
| Exceptions | None invented. Retention stays exempt, as rule 86 says |

No migration, table, capability, route, Vue file, calculation, approval rule or retention period changed.

## 2. The finding

C14-N6 (`HR-R1-C14-RESIDUAL-HARDENING.md` §5, P3, owner Payroll): no file in `app/Domain/Payroll` named `SchoolOperationalGuard`. Only approve, post, reverse and the reconciliation review reached it, through `ApprovalDecisionAuthority::requireCurrent()`. 20 writing methods in 11 services had no execution-time check; C14 proved two and read eighteen. A normal request was still refused at request time, so the exposure was the window after that check, and any direct call.

## 3. The writers

| Class | Methods | Before | Now |
|---|---|---|---|
| `PayrollRunService` | createRun, createCorrectionRun, recordCorrectionDelta, recordManualOverride, calculate | none | guard first |
| `PayrollRunService` | approve | its controller path held it, through `PayrollRunAdministrationService` | guard first as well, for a direct caller |
| `PayrollPeriodService` | createPeriod, open, close | none | guard first |
| `SalaryComponentService` | create, deactivate | none | guard first |
| `SalaryStructureService` | createDraft, addComponent, activate | none | guard first |
| `CompensationService` | assign | none | guard first, then the exclusive compensation lock |
| `PayrollAccountingConfigurationService`, `StatutoryAccountingConfigurationService` | configure | none | guard first |
| `EmployeeEsiCoverageAdminService`, `EmployeePfStatusAdminService`, `EmployeeTaxProfileAdminService`, `StatutoryIdentifierAdminService` | correct, configure, configure, set | none | guard first |
| `StatutoryPayrollCalculationService`, `StatutoryPayrollPostingService` | calculateForRun, post, reverse | none; **no production caller**, tests only | guard first, so wiring them later cannot reopen this |
| `PayrollPostingService` | post, reverse | `requireCurrent()` after the run lock | unchanged |
| `PayrollReversalReconciliationService` | the review | `requireCurrent()` after the run lock | unchanged |
| `PayrollCompensationProvenance`, `PayrollPostingIndependence` | record, recordAffectedBy | nested only: inside calculate, post and reverse | unchanged; pinned to guarded callers |
| `PayrollEmployeeRetentionService`, `PayrollEvidenceRetentionService` | retention | none | **exempt:** retention keeps running for a suspended School |

The `*AdministrationService` classes are delegators: each checks the capability and calls one of the canonical services above, outside any transaction, with one exception. `PayrollRunAdministrationService::approve` opens its own transaction, locks the run row, re-reads authority through `requireCurrent()` (which holds the School), then calls `PayrollRunService::approve` inside it. The guard therefore sits in the canonical service, where it covers the controller path and a direct caller alike.

## 4. Lock order

Rule 142 orders the run row, then the School's compensation lock: shared for a calculation or decision, exclusive for a reversal or a compensation write. Rule 146 adds the correction target, FOR SHARE, before the compensation lock.

The School claim is `holdOperational()`: `SELECT … FROM schools … FOR SHARE`. Placed first in each canonical transaction, the orders are:

- **Calculate:** School, run row, correction target, compensation (shared).
- **Approve, controller path:** run row, then the School through `requireCurrent()`, then the canonical guard again (a re-entrant FOR SHARE in a savepoint), correction target, compensation (shared). The same shape as post and reverse; unchanged by C15.
- **Approve, direct call of `PayrollRunService::approve`:** School, run row, correction target, compensation (shared). Only the guarded local demo and tests call it this way.
- **Compensation write:** School, compensation (exclusive), Employment row. This matches HR's account-link writer (rule 144).
- **Post, reverse:** run row, then the School through `requireCurrent()`, then the compensation lock. Unchanged.

**No cycle exists, whichever side the School sits on.** FOR SHARE never conflicts with FOR SHARE, so Payroll writers never wait on each other for it. It conflicts with a writer of the School row: FOR UPDATE (the lifecycle transitions, `SchoolIdentityService`, the bootstrap administrator) and a plain UPDATE, which takes FOR NO KEY UPDATE, or FOR UPDATE when it changes the unique `code` (`SchoolSettingsController::update`, `SchoolProfileService`). None of these takes a Payroll row or the compensation lock afterwards, so a Payroll writer waiting on the School never waits on something that waits on it.

**Effect on contention.** A lifecycle transition now waits for in-flight Payroll writers, which is the point: rule 86 says work claimed before a suspension completes as in-flight work. **New:** a School settings or profile save now also waits for an in-flight calculation or configuration change, and the reverse; before C15 those writers took only a foreign key's KEY SHARE on the School, which a plain UPDATE does not conflict with. Approval and posting already held the School FOR SHARE. Payroll writers do not serialize on the School. Measured: a period opening beside a held, uncommitted calculation finished in 1.01 s, where a bare child process boots and refuses in 0.45 s, and the holder was still uncommitted when it returned. The reversal path, and the C8 fan-out measurements behind it, are untouched.

## 5. Severity

**P3 confirmed.** The window let configuration, compensation, periods, runs, calculations and statutory profiles change on a suspended School. No production path could reach the ledger: approval refused at its controller path, and posting and reversal refused through `requireCurrent()`. For direct callers only, one unguarded ledger writer existed, the statutory posting service, which has no production caller; and the canonical `PayrollRunService::approve` (the guarded local demo calls it), which writes no journal entry and reaches the ledger only through the guarded posting path. A compensation change made in the window would still have needed a later approval, which refuses while suspended. No escalation is warranted.

## 6. Reversal and maintenance during suspension

No new exception was needed, and none was invented. Reversal on a suspended School was already refused, through `requireCurrent()`, since HR.R1-C1 (S-13, `852fb06a`); that is existing contract, kept. The dormant statutory reversal now behaves the same. No Payroll operation has a documented suspended-School exception; the existing path for one is to resume the School (rule 86). Retention is the one exemption.

## 7. Evidence

| Test | Kind |
|---|---|
| `PayrollExecutionTimeSchoolCheckTest` (24 cases) | Each writer called directly: refused while suspended, with every Payroll and statutory table a migration creates, the journal, the audit and the outbox unchanged by content digest (the test derives the table set from the migrations and fails if one cannot be seen); then run after the School resumes, which is the positive control and the retry. **All 24 failed on `1935712c`:** 23 wrote, and the statutory calculation got through to its rule lookup |
| `PayrollSchoolLifecycleSuspensionTest` | Suspended and resumed through the real platform lifecycle (root, fresh MFA code). A direct call is refused, then runs. Over HTTP the request-time check refuses, before the idempotency middleware, so nothing is stored, and the same key creates the run after resumption. It does not exercise the execution-time refusal inside an idempotent route; C14's `IdempotencyFailureClassificationTest` does |
| `PayrollSchoolSuspensionRaceTest` (9 cases) | Two OS processes, the contender observed blocked in `pg_stat_activity`. A suspension committing first refuses a calculation, a compensation change, an approval, a posting and a reversal, with nothing written and no ledger effect; a calculation, a compensation change and a posting holding first finish, then the suspension proceeds. No deadlock. Two Payroll writers do not wait on each other |
| `PayrollSchoolGuardCoverageTest` | Structural, on the source without comments: every class under `app/Domain/Payroll` with a write shape (builder, model, relation, raw SQL, writing SQL function) answers rule 86; every transaction of a listed class asks first, arrow-function ones included; no public method of a listed class writes outside one (each transaction's span is cut out and the rest scanned); the authority-path classes still call `requireCurrent()` and write only inside a transaction; and any file that calls a nested writer's writing method is a guarded class |

**Suspension fixtures prove themselves.** In-process, the suspension asserts that exactly one row changed from `active` and that the guard now reads it as not operational. Across processes, the race's suspension commits in its own session. The lifecycle test uses the real platform route.

**Mutations, each restored afterwards.**

| Mutation | Result |
|---|---|
| Payroll code reverted to `1935712c` | Behaviour: 24 failed. Races: calculation, compensation and approval failed; posting and reversal passed, already guarded |
| Compensation guard removed | Coverage, behaviour and race failed |
| Calculation guard moved after the run lock | Coverage failed. Behaviour passed: the order is pinned structurally, since refusal alone cannot see it |
| Identifier guard removed | Coverage and behaviour failed |
| New Payroll class writing without the guard | Coverage failed |
| Guard removed from `ApprovalDecisionAuthority` | Coverage and the posting and reversal races failed |
| A public method writing outside a transaction, in a guarded class | Coverage failed |
| A write before a guarded transaction, in the same public method | Coverage failed |
| A new class writing only through a static `::updateOrCreate(` | Coverage failed |
| A public method of `PayrollPostingService` writing outside a transaction | Coverage failed |
| A new class writing only through an instance `->update([` | Coverage failed |
| `withinGuards()` called from outside Payroll | Coverage failed |
| An arrow-function transaction added to a guarded class | Coverage failed |

**Independent review.** Three read-only passes over the actual candidate. On `676e19b1`: no HIGH, three MEDIUM (three statutory tables missing from the digest, a coverage test that missed write shapes, approval's lock order misstated in the rule text) and five LOW; all corrected. On the corrected state: no HIGH or MEDIUM, four LOW and a nit; corrected. On that: no HIGH or MEDIUM, one LOW (the authority-path classes were not method-scanned); corrected afterwards, test only, with its own mutation, and **not reviewed again**. No runtime code changed after the first review.

**Published.** `8132f9c7`. Candidate CI run 42423542688, `main` CI run 42429835236. Four shards of 2,872: 11,488 cases, 462,648 assertions, 0 failed, 1 ESI-12 skip; the coverage union passed. This is the remote full-regression checkpoint, counter 0/5.

**The first candidate attempt was red (C15-N1).** Shard 2 failed one test outside C15: `AuditRetentionPruneTest::dry_run_counts_batches_and_reruns_are_safe`. The dry run counted 5 old School audit events, then the real prune's output did not say `Deleted 5 School`. It did not reproduce:

- the test alone and after each new C15 test, in DDEV;
- the exact shard 2 plan for this candidate, in one process, in order, on a freshly reset test database: 2,865 passed, 5 skipped (the ESI-12 deferral and the real object-storage tests, which skip locally).

The job re-run on the same SHA passed, and that is the attempt behind the green `CI gate`. The test had not failed in the previous 40 CI runs.

**A mechanism, not a cause.** `retention_expire_school_audit_events` deletes rows selected `FOR UPDATE SKIP LOCKED`, while the dry run counts without locking. A row any other session held locked at that moment would be skipped and under-counted, which matches the symptom. Which session held one in CI is unknown. **OPEN, LOW, owner Retention/Platform:** the test, or the prune's reporting, should tolerate a skipped row. Not changed here.

## 8. Publication hygiene and the Platform handoff

**What went wrong in C14.** A first candidate went to an integration branch, in a public repository, with a gitleaks finding (an inert UUID). The scan's exit status was read through a pipe, so the push ran anyway. `main` was never at risk: CI's `Secrets scan` covers full history, `CI gate` requires it (`lycenza_ci/gate.py`), and `publication-check` requires `CI gate`.

**Where the gap is.** The integration push runs before CI. The local publish gate (`.claude/skills/publish-gate/run.sh`) scans only changed, uncommitted files, so on a committed candidate it reports `gitleaks skipped: no changed files`, which is what it reported in C14. Nothing scans the committed range before it is uploaded.

**What C15 did.** It changed no shared tooling. It published through a local wrapper, kept outside the repository, that scans exactly `base..candidate` with the pinned image and pushes only if gitleaks itself exits 0. Proven in a throwaway local repository against a local bare remote: a clean range pushed; a range holding a fabricated AWS-shaped key was refused with exit 2, and the remote received no ref. A scanner that cannot run also exits non-zero, so the wrapper fails closed.

**Handoff, for the Platform owner (not approved, not built):** extend the publish gate with a committed-range scan, `gitleaks git --log-opts=<origin/main>..<candidate>`, failing on a non-zero exit, and document that the integration push follows it. A skipped scan on a committed candidate would then be a failure, not a note. This sits beside the C14 `php -l` proposal, and like it needs the Platform owner.

## 9. Limits

- **The statutory calculation's positive control is weaker.** Its rules begin in 2026 and the behaviour test's world is 2015, so on an active School the call is shown to pass the guard and reach its own rule lookup. The service has no production caller; its success path is covered by the existing statutory tests.
- **Races cover the classes, not every method.** They race calculation, compensation, approval, posting and reversal. The other writers share their placement and are proven by the behaviour test.
- **The race's suspension runs the lifecycle's own statements,** the School row FOR UPDATE and then the status, without its authority, MFA and audit, which the lifecycle test proves separately.
- **A source scan sees files, not paths.** The behaviour and race tests prove the paths. It scans `app/Domain/Payroll` only: no class outside it writes Payroll tables today, by review, not by a test. A private helper is not judged on its own; it is reached only through a public method of its class.
- **No browser run.** No screen changed.
- **Local state.** DDEV was started for this checkpoint and stopped at the end; its volume was kept. A first local shard reproduction through `bin/safe-test` started the shared `school-os` Compose stack, whose test database schema is out of date, so that run proved nothing (437 schema errors). Stopping that stack was refused by the session's permission policy, so it was left running for the owner to stop. Four `phpunit` processes seen on the host belonged to another project's DDEV and were not touched.
- **Contention was measured at fixture scale,** not at production volume. A suspension waits for in-flight School-guarded work, Payroll's and every other domain's. PostgreSQL grants a new FOR SHARE without queueing behind a waiting FOR UPDATE while only share locks are held, so a steady stream of overlapping guarded writers can delay a suspension beyond any one transaction. Neither was measured at scale.
