# Payroll

Living architecture document for Phase 9 (the Payroll remainder of
`docs/roadmap/MASTER-ROADMAP.md`'s "Phase 0J — HR and Payroll"). See
`docs/architecture/adr/0034-phase-9-payroll-architecture.md` for the
formal decision record this document elaborates.

## Checkpoint roadmap

**Status summary (Checkpoint 9.12 closure):** engineering implementation
complete through 9.5 and 9.7–9.12 (non-statutory scope). **Statutory
Payroll (9.6) remains deferred, pending `[LEGAL REVIEW REQUIRED]`** — see
`docs/security/DATA-CLASSIFICATION.md`. This is a legally-gated deferred
scope item, not an engineering failure, and Phase 9 is **not** described
as "fully complete" anywhere in this document while 9.6 remains open. See
"Phase 9.12 Closure" below for the full validation record.

- **9.0 — Architecture Decisions & Legal Boundary** [implemented]:
  ADR 0034, this document, roadmap/domain-map annotations. No schema.
- **9.1 — Compensation Schema Foundation** [implemented]: `salary_components`,
  `salary_structures`, `salary_structure_components`,
  `employee_compensation_assignments`, `compensation_assignment_values`,
  plus `payroll_periods`, `payroll_runs`, `payroll_run_results`,
  `payroll_run_result_lines`, `payroll_adjustments`,
  `payroll_accounting_configurations`, `payroll_run_postings`.
- **9.2 — Compensation Application Services** [implemented]: structure
  draft/activate/supersede, compensation assignment, overlap
  enforcement, real concurrency proofs.
- **9.3 — Payroll Periods & Calculation Kernel** [implemented]: period services, run
  creation, deterministic calculation, `Money::multiplyByRate()`,
  golden fixtures, the fail-closed partial-period rule.
- **9.4 — Run Lifecycle, Snapshotting & Separation of Duties** [implemented]: state
  machine, approval immutability, actor persistence, concurrency
  proofs.
- **9.5 — Finance Configuration, Posting & Reversal** [implemented]: accounting
  configuration, the posting algorithm, correction-run posting,
  reversal, transactional/idempotency integration with
  `LedgerService`.
- **9.6 — Statutory Payroll Boundary — LEGAL GATE** [**IN PROGRESS —
  legal review cleared, implementation underway**]: legal sign-off
  received (`SCH/PAY/REG/2026-9.6`, effective 1 April 2026, Telangana
  jurisdiction), accepted subject to the binding correction addendum
  in `docs/architecture/adr/0035-phase-9-6-statutory-payroll-architecture.md`.
  Implemented on a new, separately-numbered, post-publication branch
  (`feature/phase-9-6-statutory-payroll`, ADR 0028/ADR 0034's own
  precedent) — Phase 9's already-published non-statutory history is
  never rewritten. **Not yet complete** — see "Phase 9.6 Checkpoints"
  below for the exact 9.6A–9.6H status; do not treat this row as
  statutory-complete until 9.6H's own closure record says so. The
  ESI disability special threshold (₹25,000) remains
  `DEFERRED — ADDITIONAL LEGAL CLARIFICATION REQUIRED` regardless of
  9.6's own completion.
- **9.7 — Authorization, Sensitive Reads & Audit** [implemented].
- **9.8 — API / OpenAPI** [implemented].
- **9.9 — Administrative UI** [implemented].
- **9.10 — Events / Payslip Rendering** [implemented].
- **9.11 — Security / Concurrency / Migration Closure** [implemented]:
  closed a real gap (`payroll_periods` transition guard — see its own
  "Phase 9.11 Closure" section below).
- **9.12 — Integration / Publication Readiness** [implemented]: main
  reconciliation, migration-compatibility proof against current main,
  full behavioral/concurrency/security/UI closure, baseline-vs-branch
  regression attribution. See "Phase 9.12 Closure" below.

## Purpose

Calculate and record what a School owes its Employees for a pay period
and hand the resulting accounting facts to Finance. Payroll is a
calculation and record-keeping module — it never disburses money
itself (bank payment execution is explicitly out of scope) and never
duplicates HR's or Finance's own authoritative facts.

## Scope

Salary components (semantic identity), salary structure revisions
(immutable, versioned formulas), Employee-specific compensation values,
monthly payroll periods, payroll runs (regular and correction) with an
approval-gated state machine, per-Employee-engagement results, and
Finance posting/reversal through the existing ledger.

## Non-goals (this phase)

Bank-account storage (no in-scope consumer — deferred until bank
payment execution is itself in scope), Attendance/Leave integration
(not a documented dependency; both unbuilt), Employee self-service (no
self-service authorization boundary exists anywhere in this codebase
yet), a generic payroll rules engine (fixed `fixed_amount`/
`percentage_of_base` vocabulary only, no formulas/scripts), off-cycle
payroll runs beyond a reserved `run_kind` value, employee loans/
advances, expenses/reimbursements, multi-currency/multi-country
payroll, tax-declaration/filing workflows, complex retro-pay (a single
correction run replaces it — see "Correction model" below), AI access
to payroll data.

## Dependency position

Payroll depends on HR (`Employee`, `EmploymentRecord` — read-only, via
HR's Application layer) and Finance (`LedgerService::post()`/
`reverse()` — the only sanctioned write path into `journal_entries`).
Neither HR nor Finance depends on Payroll. Payroll never writes to
`employees`, `employment_records`, `journal_entries`, or `journal_lines`
directly.

## Core entities

| Table | Owner | Mutability |
|---|---|---|
| `salary_components` | Payroll | Deactivate, never delete |
| `salary_structures` | Payroll | `draft` mutable; `active`/`superseded` frozen |
| `salary_structure_components` | Payroll | Frozen when parent leaves `draft` |
| `employee_compensation_assignments` | Payroll | Effective-dated, append-only |
| `compensation_assignment_values` | Payroll | Immutable once the assignment exists |
| `payroll_periods` | Payroll | Locked once any run references it |
| `payroll_runs` | Payroll | Frozen at `approved` |
| `payroll_run_results` / `payroll_run_result_lines` | Payroll | Frozen at `approved` |
| `payroll_adjustments` | Payroll | Append-only |
| `payroll_accounting_configurations` | Payroll | Mutable configuration |
| `payroll_run_postings` | Payroll | Append-only |

No bank-account table. No statutory-identifier table (gated, 9.6). No
payslip table (rendered on demand from `payroll_run_result_lines`).

## Compensation model

A `salary_structures` row is one immutable revision — not a mutable
document with hidden history. Logical identity is `(school_id, code)`;
`version` increments per revision; `status` is `draft` (fully mutable,
including its `salary_structure_components`), `active` (frozen from
the instant of activation — the freeze boundary, not first assignment),
or `superseded` (terminal). At most one revision per `(school_id,
code)` is `active` at a time, via a partial unique index mirroring
`academic_years_one_active_per_school`.

`salary_structure_components` carry the calculation shape:
`fixed_amount` or `percentage_of_base` (a decimal-fraction `rate`,
itself structure-level policy — e.g. "HRA = 40% of Basic" is the same
for every Employee on that revision). A percentage component's
`base_component_id` must reference an earlier-ordered component in the
same revision, preventing cycles by construction.

Employee-specific numbers live in `compensation_assignment_values` —
one row per `(assignment, fixed-amount component)`. Percentage
components are never separately stored; they are always derived from
their base component's resolved amount at calculation time. This is
what lets many Employees share one structure revision without forcing
a unique structure per Employee.

`employee_compensation_assignments` reference `EmploymentRecord`, never
bare `Employee` — an Employee may have multiple historical
EmploymentRecords via rehire, each with independent compensation
history. Two assignments for the same EmploymentRecord may not
overlap; enforced by a parent-row lock (`EmploymentRecord FOR UPDATE`)
plus an Application check inside one transaction, backed by a database
trigger that independently re-validates the same rule at INSERT time.
No `EXCLUDE USING gist`/`btree_gist` — see ADR 0034's rationale (direct
precedent in `academic_years`/`employment_records` explicitly rejects
that mechanism for this exact shape of problem).

## Period model

`payroll_periods.period_month` (normalized to the first of the month)
is the true identity; `starts_on`/`ends_on` are derived and validated
against it. `UNIQUE(school_id, period_month)` makes overlap impossible
by construction. Monthly only — no configurable-frequency calendar
engine. `payment_date` is independent of the covered month.

## Run kinds, state machine, and correction model

`payroll_runs.run_kind` is `regular` or `correction`.
`UNIQUE(school_id, payroll_period_id) WHERE run_kind='regular'`
guarantees one authoritative regular run per period. State machine:
`draft -> calculated -> approved -> posted`. Recalculation (whole
result-set replacement, never a partial patch) is allowed while
`draft`/`calculated`; `approved` is the sole immutability boundary —
there is no separate `finalized` state.

A correction run references the original *regular*, already-*posted*
run (`corrects_payroll_run_id`; no correction-of-correction chains) and
is built entirely from explicit `payroll_adjustments` delta lines —
never a re-run of the automatic kernel. This is a deliberate,
deltas-only design: it keeps correction a targeted fix rather than a
retro-pay engine. One original run may have multiple correction runs.
A correction run may only be created once the original is `posted`.

Correction adjustments carry a positive `amount` and an explicit
`increase`/`decrease` direction — never an arbitrary signed literal.
The posting algorithm converts a signed effect into the correct
positive debit/credit line for `LedgerService`. A zero-effect
correction is rejected. Regular-run results must be non-negative
(gross, deductions, net); correction-run aggregate effects may be
positive or negative.

## Partial-period policy

Automatic calculation fails closed — it refuses to produce a result —
for any EmploymentRecord whose employment starts after the period's
first day, ends before the last day, or whose compensation changed
inside the period. No proration is ever computed automatically. Such
an EmploymentRecord requires an explicit `manual_override` adjustment
(distinct in purpose from a correction run's `correction_delta`
adjustments) providing the complete result for that engagement before
the run may move to `calculated`. A run may never represent an
unresolved EmploymentRecord as calculated.

## Accounting / posting model

`salary_components.liability_ledger_account_id` (composite FK to
Finance's `ledger_accounts`) names each deduction's destination;
`payroll_accounting_configurations` names the salary-expense and
salary-payable accounts. A run posts one aggregate debit (total GROSS
earnings, to expense), one aggregate credit (total net pay, to
payable), and one aggregate credit per deduction with a nonzero total
(to its liability account) — balanced by construction. A deduction
with a nonzero total and no mapping blocks posting entirely; there is
no suspense-account fallback.

**Account resolution (amended at Checkpoint 9.5's accounting-integrity
review):** all three account categories — salary expense, salary
payable, and every deduction's liability account — resolve and
validate the CURRENT `LedgerAccount` LIVE, at posting time, uniformly.
An earlier draft of this document (and of ADR 0034) described the
deduction account as snapshotted at calculation time and frozen at
`approved`; that was inconsistent with the expense/payable pair, which
were always resolved live, and has been corrected in favor of the
single, coherent policy described here (see ADR 0034's amendment note
for the full reconciliation). `payroll_run_result_lines.resolved_ledger_account_id`
is still populated at calculation time as an informational record of
what was configured then, but `PayrollPostingService` does not read it
for posting.

Posting is owned by `App\Domain\Payroll\Application\PayrollPostingService`,
which opens the authoritative transaction, locks the run, re-validates
`approved`, resolves and validates every account live, builds the
balanced journal, calls `LedgerService::post()` (a proven
nested-transaction call — see `App\Domain\Payments\Application\PaymentProviderEventService`
for the existing precedent), records `payroll_run_postings`, transitions
the run to `posted`, and audits — all inside one transaction (proven by
Checkpoint 9.5's real two-process concurrency tests: a losing concurrent
poster is rejected before ever reaching `LedgerService`, never leaving
an orphan `JournalEntry`). Reversal uses `LedgerService::reverse()` and
never mutates the original journal entry or the run's own historical
result.

**Idempotency (explicitly scoped at Checkpoint 9.5):** the above is
Application-layer atomicity only — a single `DB::transaction()` call.
HTTP-transport idempotency (`Idempotency-Key` / `IdempotencyGuard::completeWithin()`,
`docs/architecture/RELIABILITY.md`) is NOT yet wired to `post()`/`reverse()`
because no HTTP endpoint exists for either operation yet — that
integration belongs to Checkpoint 9.8 (API / OpenAPI), once a real
consequential retryable endpoint exists to evaluate it against (rule
29). This document does not claim end-to-end HTTP crash-window closure
until that checkpoint connects it.

## Authorization

Implemented at Checkpoint 9.7, corrected at that same checkpoint's own
authorization review (an initial draft collapsed run create/calculate/
approve into one `payroll.runs.manage` and default-granted the Highly
Sensitive compensation family to `school_admin`; both were wrong and
were replaced outright before merge — never carried forward alongside
the correction). Thirteen capabilities, all school-scoped:

- `payroll.structures.view`/`.manage` — formula/policy shape only
  (component names, calculation types, rates, ordering), never
  individual data.
- `payroll.compensation.view` — non-sensitive assignment metadata
  (EmploymentRecord reference, salary structure/revision identity,
  effective_from/to, open/closed status) — never an amount.
- `payroll.compensation.sensitive.view`/`.manage` — the Highly
  Sensitive family: gates `compensation_assignment_values.amount` and
  every field on `payroll_run_results`/`_lines`, as a whole row, never
  a partial field, enforced in `PayrollCompensationReadService`/
  `PayrollRunResultReadService`, never in Vue.
- `payroll.periods.manage` — a single capability (no independently
  useful read-only tier exists yet for periods).
- `payroll.runs.view`/`.prepare`/`.approve`/`.post`/`.reverse` — FIVE
  distinct run-lifecycle capabilities, never collapsed into one
  `.manage`. Least-privilege authorization is a capability-level
  property; Separation of Duties for approval (`preparer != approver`)
  is a SEPARATE, actor-level rule (enforced by
  `SelfApprovalNotAllowedException` + the database CHECK
  `payroll_runs_sod_check`, mirroring `CommunicationApprovalService`'s
  identical precedent) that remains necessary even for an actor holding
  BOTH `.prepare` and `.approve` — it is never a substitute for the
  capability split, and the capability split is never a substitute for
  it. `.post`/`.reverse` mirror `finance.ledger.post`/`.reverse`'s
  identical "reversal is a materially higher-risk financial correction
  action" reasoning.
- `payroll.accounting.manage` — a single capability, mirroring
  `canteen.settings.manage`'s identical "financial account mapping"
  shape.
- `payroll.statutory.manage` — registered with NO functional
  implementation behind it while Checkpoint 9.6 remains
  `[LEGAL REVIEW REQUIRED]`; exists only to reserve the eventual slot,
  granted to nobody.

`school_admin` receives every capability above EXCEPT
`payroll.compensation.sensitive.view`/`.manage` and
`payroll.statutory.manage`, which are granted to NO default role —
mirroring `hr.employees.sensitive.*`'s identical "nobody by default"
treatment for Highly Sensitive per-Employee data; a School wanting a
role to see/assign actual salary figures must grant that explicitly.
`principal` and every other school-scoped role receive none of the
thirteen (`PayrollCapabilityRegistryTest`).

No `payroll.exports.generate` is registered — export/payslip
functionality is itself deferred (Checkpoint 9.10); a capability with
no real gated action would be dead configuration.

Every Payroll Application-layer core service (`SalaryComponentService`,
`SalaryStructureService`, `CompensationService`, `PayrollPeriodService`,
`PayrollRunService`, `PayrollPostingService`,
`PayrollAccountingConfigurationService`) remains capability-check-free
by design, exactly like `App\Domain\Finance\Application\LedgerService`/
`ChargeService` — a dedicated Administration/Read wrapper per family
(`PayrollStructureAdministrationService`,
`PayrollCompensationAdministrationService`,
`PayrollCompensationReadService`, `PayrollPeriodAdministrationService`,
`PayrollRunAdministrationService`, `PayrollRunReadService`,
`PayrollPostingAdministrationService`,
`PayrollAccountingAdministrationService`) is the ONLY place a
capability check happens, mirroring
`LedgerAdministrationService`/`ChargeAdministrationService`'s identical
split; `PayrollRunAdministrationService` itself checks a DIFFERENT
capability per method (`.prepare` for create/calculate/adjustments,
`.approve` for `approve()` only), mirroring
`PayrollPostingAdministrationService`'s own `.post`/`.reverse` split.
No transport may call a core service directly; a future controller
(Checkpoint 9.8) depends on the Administration/Read layer.

## Statutory boundary

PF/ESI/TDS calculation, government/statutory identifiers, and any
statutory-compliance claim remain `[LEGAL REVIEW REQUIRED]`
(`docs/security/DATA-CLASSIFICATION.md`). Payroll owns the statutory
*calculation* once cleared (ADR 0034's Compliance-ownership
resolution) — a future Compliance module only consumes results,
read-only. This boundary blocks only Checkpoint 9.6.

## Sensitive-data handling

Salary/compensation data is Highly Sensitive per
`docs/security/DATA-CLASSIFICATION.md`. Never in logs, audit metadata,
event payloads, or an unauthorized read: raw amounts, rates, gross,
deductions, net pay. Audit records the fact (which run/assignment,
which action, which actor) by reference, never the value — verified
directly: `PayrollRunResultReadService::listResults()`'s and
`PayrollCompensationReadService::getAssignmentValues()`'s own audit
calls carry only `resultCount`/`valueCount`, and
`PayrollRunResultReadServiceTest`/`PayrollCompensationReadServiceTest`
assert no amount-shaped key ever appears in the recorded metadata, and
that a DENIED sensitive-read attempt leaves zero audit rows (no
result leakage on denial).

Two Checkpoint 9.7 read paths reach Employee-specific compensation
data, split by sensitivity tier exactly like
`App\Domain\HR\Application\EmployeeDocumentService`'s classification-
aware authorization:

- `PayrollCompensationReadService::listAssignments()`
  (`payroll.compensation.view`) — non-sensitive identity/effective-
  dating only (`CompensationAssignmentSummary`, ADR 0034 "Sensitive
  values": "deliberately narrow ... never the Employee-specific
  monetary values"). This method structurally never queries
  `compensation_assignment_values` at all — not merely omits amounts
  from the DTO — verified by a dedicated test asserting the executed
  query log never mentions that table.
- `PayrollCompensationReadService::getAssignmentValues()`/
  `PayrollRunResultReadService::listResults()`
  (`payroll.compensation.sensitive.view`) — the ONLY paths to actual
  Employee monetary values outside of `assign()`'s own write-time
  return. Every field returned as a typed DTO
  (`CompensationAssignmentValueDetail`/`PayrollRunResultDetail`/
  `PayrollRunResultLineDetail`), never a raw Eloquent model, mirroring
  `App\Domain\Payments\Application\PaymentReadService`'s identical
  disclosure boundary (including "no oracle" cross-School not-found
  uniformity via `CompensationAssignmentNotFoundException`/
  `PayrollRunNotFoundException`).

`PayrollRunReadService` (`payroll.runs.view`) is a THIRD, wholly
non-sensitive read path — `payroll_runs` itself carries no monetary
column at all, so `PayrollRunSummary` is a complete, safe mirror of
the row (status, kind, prepared/approved/posted-by, timestamps).

## Payslip rendering

**Checkpoint 9.10.** A payslip is rendered **on demand only**, every
call, directly from `payroll_run_results`/`payroll_run_result_lines`
— the sole numerical authority. There is no `payslips` table, no
stored payslip Document, no Payroll→Documents linkage table, no
employee self-service delivery, and no emailed distribution. A
payslip must never become a second mutable financial authority; it is
a read projection, nothing else.

`App\Domain\Payroll\Application\PayslipReadService::render()` is the
sole authorized path, mirroring `PayrollRunResultReadService`'s exact
authorization/disclosure/audit shape: `payroll.compensation.sensitive.view`
is checked before any query runs — `payroll.runs.view` alone never
suffices, and no employee-self-service exception exists. Eligibility
is `PayrollRun::isApprovedOrLater()` (`approved` or `posted`), applied
identically to `regular` and `correction` runs — a `draft`/`calculated`
run is still freely recalculable and is never rendered as an
authoritative-looking payslip. That editable, in-progress figure
remains available separately as the explicitly labeled
**"Calculation preview"** on the Run page
(`App\Http\Controllers\App\Payroll\PayrollRunController::renderShow()`
/ `resources/js/Pages/App/Payroll/Runs/Show.vue`) — never presented as
a final payslip.

Content: School identity, Employee identity (full name, employee
number), the `EmploymentRecord` reference, payroll period/month,
payment date, earning lines, deduction lines, gross, total
deductions, net, and run/correction identification. A correction
result renders clearly labeled as a correction, referencing the
original run and its period — never merged into a fabricated
retroactive final statement (that merged view remains deferred). A
reversed posted run's payslip remains renderable from its immutable
historical result, with a derived `isReversed` indicator — amounts
are never rewritten to zero and the original result is never deleted.

Excluded, by construction (never present on `Payslip`/`PayslipLine`):
bank details, statutory identifiers, and any PF/ESI/TDS figure or
compliance claim — Checkpoint 9.6 remains `[LEGAL REVIEW REQUIRED]`,
and `Payslip::$statutoryDeductionsIncluded` is always `false` in Phase
9 so a renderer can show an explicit "does not include statutory
deductions" notice rather than silently omitting it.

Output format: an Inertia/HTML printable view
(`resources/js/Pages/App/Payroll/Payslips/Show.vue`, `window.print()`),
mirroring every other Payroll admin page's existing stack — no PDF
library exists anywhere in this repository, and none was added for
this checkpoint (root CLAUDE.md rule 2). A parallel JSON API endpoint
(`GET .../payroll-runs/{payrollRunId}/payslips/{employmentRecordId}`)
exists for API-surface consistency with every other Payroll read path.
Neither surface persists its output.

Sensitive-read auditing: `payroll.payslip.viewed` is recorded once per
`render()` call — actor, School, run reference, and the
EmploymentRecord id, never a component amount/gross/deductions/net or
the rendered body, matching `PayrollRunResultReadService`'s identical
audit discipline. `render()` guarantees the same exactly-once
semantics that discipline already establishes (audit happens exactly
once inside the single authorized read path; no separate audit call
exists anywhere else in the payslip surface, so no duplicate-audit
risk exists to guard against).

## Domain events

**Checkpoint 9.10.** Every event below implements
`App\Support\Events\ShouldBeOutboxed` and is dispatched via the plain
`event()` helper from *inside* the same `DB::transaction()` as its
triggering mutation — `App\Listeners\RecordDomainEventToOutbox`
(ADR 0025's transactional outbox) writes the `domain_event_outbox` row
synchronously, on the same connection, so it commits or rolls back
atomically with the business change. No second, Payroll-specific
outbox mechanism exists or was introduced.

| Event | `eventType()` | Producer | Emitted |
|---|---|---|---|
| `PayrollRunCreated` | `payroll_run.created.v1` | `PayrollRunService::createRun()`/`createCorrectionRun()` | Every run creation, regular or correction (`runKind`/`correctsPayrollRunId` distinguish them — one event type, not two, since one already-single method pair produces both). |
| `PayrollRunCalculated` | `payroll_run.calculated.v1` | `PayrollRunService::calculate()` | Every calculate()/recalculate() call, whether or not it transitions the run to `calculated` (mirrors the existing unconditional `payroll.run.calculated` audit call). |
| `PayrollRunApproved` | `payroll_run.approved.v1` | `PayrollRunService::approve()` | The single `calculated -> approved` transition; a losing concurrent approval never reaches the dispatch site. |
| `PayrollRunPosted` | `payroll_run.posted.v1` | `PayrollPostingService::post()` | The single `approved -> posted` transition; a replayed idempotent HTTP request never re-enters `post()` at all, so this event structurally cannot duplicate. |
| `PayrollRunReversed` | `payroll_run.reversed.v1` | `PayrollPostingService::reverse()` | The single reversal of a posted run's original posting; same replay-safety as above. |
| `EmployeeCompensationAssigned` | `employee_compensation.assigned.v1` | `CompensationService::assign()` | Every compensation assignment — first assignment (`previousAssignmentId` null) and supersession (`previousAssignmentId` set) alike, since `assign()` is itself already one method for both cases; a separate "Superseded" event class would only duplicate this one's shape. |

Payload minimization (root CLAUDE.md rule 9, ADR 0034 "Sensitive
values"): every payload carries identifiers and non-sensitive
operational metadata only — `schoolId`, run/period/assignment/
EmploymentRecord ids, `runKind`, structural counts (`resolvedCount`,
`unresolvedCount`, `lineCount` — a count of ledger LINES, never an
amount), actor ids, and (for the compensation event) `effectiveFrom`,
identical to what the corresponding audit call already logs. No
payload ever carries `gross_amount`/`total_deductions`/`net_amount`,
a component amount, a compensation-assignment amount, a bank detail,
a statutory identifier, or any payslip content — verified directly by
dedicated tests inspecting each event's serialized payload.

External publication: internal transactional events only. None of the
six event types above is registered in
`App\Support\Webhooks\WebhookEventRegistry`'s closed catalog, so none
is externally webhook-subscribable merely by existing in
`domain_event_outbox` (root CLAUDE.md rule 45) — that remains a
separate, explicit, future decision requiring an actual external
consumer.

## Phase 9.11 Closure (Security / Concurrency / Migration Closure, implemented)

Verification-and-hardening gate, mirroring HR's Phase 8A.16 closure
template exactly (`docs/modules/HR.md` "Phase 8A Closure"). Unlike
8A.16, this checkpoint found and closed one real, previously-open
concurrency gap — recorded below, not silently folded into 9.1-9.10's
own history.

**Clean-install validation.** `php artisan platform:test-db-reset
--force` against the shared `school_os_test` database migrated all 13
Payroll migrations (12 original + this checkpoint's own, below) and
ran the full `DatabaseSeeder` cleanly. `Tests\Feature\Postgres\PayrollSchemaInvariantsTest`
(existing, re-verified) confirms every Payroll table
`relrowsecurity = t`/`relforcerowsecurity = t`. Migration
reversibility proven twice: the original 12 migrations
(`migrate:rollback --step=12` then `migrate`) and this checkpoint's
own new migration individually (`migrate:rollback --step=1` then
`migrate`) both completed with zero errors.

**Gap found and closed: `payroll_periods` had no transition guard at
all.** Unlike `payroll_runs` (Application-layer conditional-UPDATE
claim *and* `trg_payroll_runs_validate_transition`, both from
Checkpoint 9.1/9.4) or `salary_structures`, `PayrollPeriodService::open()`/
`close()` were a bare, unconditional `$period->update(['status' =>
...])` — no up-front state check, no conditional-UPDATE concurrency
claim, no database trigger. Two concurrent `open()` calls, or an
`open()` racing a `close()`, would both silently "succeed" with no
conflict signal. Closed by:
- an up-front check (`draft` required for `open()`, `open` required
  for `close()`) throwing the new `InvalidPeriodTransitionException`;
- the same conditional-UPDATE claim shape `PayrollRunService::approve()`
  already established (`WHERE status = 'draft'`/`'open'`, verifying
  `$affected === 1`), throwing the new
  `ConcurrentPeriodTransitionConflictException` on a lost race;
- migration `2026_09_15_091200_add_transition_trigger_to_payroll_periods_table`
  adding `trg_payroll_periods_validate_transition`, the same
  defense-in-depth backstop `payroll_runs` already has against an
  invalid transition even via raw SQL bypassing the Application layer
  entirely (independently verified: a raw `UPDATE ... SET status =
  'closed'` against a `draft` period was rejected by the trigger
  itself, not merely by application code).
- `PayrollPeriodServiceTest` (6 tests: valid transitions, invalid
  transitions in both directions, reopen-after-close rejected) and
  `PayrollPeriodConcurrencyTest` (one REQUIRED real-two-process proof,
  mirroring `PayrollRunLifecycleConcurrencyTest::scenario_c`'s exact
  shape) — both new, both passing.

**Two other candidate gaps reviewed, found not to need a fix:**
- **`PayrollAccountingConfigurationService::configure()`'s
  `updateOrCreate()`** has the same narrow first-time-configure race
  as `App\Domain\Canteen\Application\CanteenBillingConfigurationService`
  (its own docblock: "mirrors ... identical shape exactly") — a
  concurrent first *ever* save for a School could raise an unhandled
  `UniqueConstraintViolationException` on the losing side. Low
  severity (a one-time, admin-only setup action; the loser can simply
  retry) and consistent with an intentionally-mirrored cross-module
  pattern shared by Canteen/Hostel/Library — fixing it unilaterally
  only in Payroll would itself be an inconsistency. Left as a
  documented, accepted, cross-module pattern, not a Payroll-specific
  defect.
- **Concurrent `createCorrectionRun()` against the same original
  run** is not a race bug: the schema deliberately allows multiple
  correction runs per original (no unique-per-original constraint),
  matching `PayrollCorrectionRunTest`'s own accepted coverage
  ("reversing an original with an existing correction is still
  allowed"). Two concurrent corrections simply create two rows, which
  is supported, intended behavior, not corruption.

**Security.** Grep audit of `app/Domain/Payroll` found: no
`Storage::` call (Payroll has no file storage need); two raw
`DB::table('payroll_runs')` writes (`PayrollRunService::calculate()`,
`PayrollPostingService::post()`), both reviewed — each follows an
Eloquent `lockForUpdate()` fetch (itself School-scoped by
`BelongsToSchool`) in the same transaction, and RLS applies at the
Postgres session level regardless of Eloquent vs. `DB::table()`, so
neither is a tenant-isolation bypass; no role-name string comparison
(every check is capability-based). No P0/P1 found beyond the period
gap closed above.

**API surface audit.** `php artisan route:list` for every
`payroll`/`payslip` route confirms only `GET|HEAD` for reads and
`POST` for mutations — no accidental verb, no unauthenticated route
(every route sits inside the authenticated `api`/`app` middleware
groups established in prior checkpoints).

**Fresh HTTP validation.** A real `php artisan serve` process (not
the PHPUnit harness) confirmed the app boots and Sanctum
authentication genuinely enforces over the wire: `/up` returned `200`;
an unauthenticated request to a Payroll endpoint returned `401`.
Further authenticated-request checks against this ad hoc harness hit
an unrelated container-networking Redis configuration issue outside
Payroll's own code and were not pursued further, given the exhaustive
in-process HTTP-layer coverage `PayrollApiTest`/`PayrollUiTest`
already provide (guest-401, capability-403, cross-School-404, and the
full lifecycle, all exercised through Laravel's real HTTP kernel and
middleware stack, just not a literal second OS process).

**Test results.** `php artisan test --filter=Payroll` → 212 passed,
847 assertions, 0 failures (up from 9.10's 205 — 7 new: the period
transition unit tests and its real-concurrency proof). Outbox/HR/
Webhook regression (`OutboxTransactionalityTest`,
`HrEmployeeDomainEventsTest`, `HrEmployeeDomainEventsRollbackTest`,
`WebhookEventRegistryTest`) re-verified passing in the same session
(9.10), unaffected by 9.11's Payroll-only changes. `vendor/bin/pint
--test` and `vendor/bin/phpstan analyse` both clean.

**Merge readiness.** Not attempted — 9.12 ("Integration / Publication
Readiness") is this module's own dedicated, separately-numbered
integration checkpoint, mirroring the Finance `0G.8`/Payroll
`9.11`-vs-`9.12` split rather than HR's single 8A.16 (which folded a
disposable rehearsal merge into its own closure). No merge, disposable
or otherwise, was rehearsed here.

**Files changed by 9.11:** `PayrollPeriodService.php` (transition
guards), two new exception classes, one new migration
(`2026_09_15_091200_add_transition_trigger_to_payroll_periods_table`),
two new test files (`PayrollPeriodServiceTest`,
`PayrollPeriodConcurrencyTest`), one new test-support script
(`tests/Support/open-payroll-period.php`), and this documentation
section. Unlike HR 8A.16, this was not a documentation-only closure —
a real, previously-open concurrency gap was fixed.

**PHASE 9.11 VERDICT: CLOSED.**

## Phase 9.12 Closure (Integration / Publication Readiness, implemented)

Reconciliation and final validation gate. **Does not merge Phase 9 into
`main`** — that remains a separate, explicitly-authorized future step.

**Reconciliation.** A normal `git merge` (not a rebase, preserving
already-published Phase 9 history) of the 19 commits that had landed on
`origin/main` (Attendance, Syllabus, Curriculum Delivery, Examinations
0H.4A/4B) since the original Phase 9 base. Three real conflicts, all
purely-additive-at-the-same-insertion-point, resolved by concatenation
and verified by an exact set-union check against both parents (zero
content lost, zero duplicated): `routes/api.php`,
`packages/contracts/openapi/school-os-api.yaml` (417 base + 56 Payroll
+ 78 main = 551 merged schema keys, exact), and the generated
`school-os-api.ts` (regenerated fresh from the reconciled OpenAPI
source, never hand-resolved, zero-diff on a second regeneration).
`CapabilityAndRoleSeeder.php` and `routes/web.php` auto-merged cleanly,
independently verified by the same exact-set-union method.

**ADR collision, corrected at Checkpoint 9.12 (actual main-integration
time):** `docs/architecture/adr/0032-examinations-decomposition-and-foundation-fact.md`
(arriving from `main`) collided in number with Payroll's own
pre-existing `0032-phase-9-payroll-architecture.md`. Since Phase 9 had
not yet been merged into `main`, the unpublished Payroll side was the
correct one to renumber (never the already-published Examinations
ADR): Payroll's ADR is now
`docs/architecture/adr/0034-phase-9-payroll-architecture.md` (0034
being the first genuinely unused number on fresh `main`, which tops
out at 0033), and every one of the ~76 files referencing "ADR 0032" to
mean Payroll was updated to "ADR 0034" (the OpenAPI-generated
`school-os-api.ts` was regenerated, never hand-edited). This
repository's other pre-existing, still-unresolved collision
(`0028-phase-8a-hr-sequencing-and-domain-foundation.md` vs
`0028-searchable-encrypted-pii.md`, both already on `main`) is
out of Phase 9's scope and was left untouched.

**Migration compatibility against current `main`.** Proven three ways
against the reconciled branch's exact 185-migration history: (1) clean
install from zero — all 185 migrations, including all 13 Payroll-owned
ones, applied without error; (2) upgrade proof — a database migrated to
current `main`'s exact 172-migration state, then the 13-migration
Payroll-forward delta applied on top, succeeded with zero historical
migration edits and zero trigger/function collisions; (3) rollback/
reapply of the complete 13-migration Payroll delta, verified clean both
times. (The pre-existing orphan-standalone-trigger-function defect in
`ResetTestDatabase`/`migrate:fresh` — recorded since Checkpoint 9.1,
see ADR 0034 §5 — remains explicitly out of Phase 9's scope; it did not
recur here because each reset in this checkpoint used a full schema
drop/recreate, not a bare `migrate:fresh` retry.)

**PostgreSQL structural verification.** Direct catalogue inspection
(`pg_class`/`information_schema.triggers`/`pg_constraint`), not test
inspection: all 12 Payroll-owned tables (excluding the platform-wide
reference tables) confirmed `relrowsecurity = t`/`relforcerowsecurity =
t`; all 12 expected triggers present (compensation-overlap, structure
freeze, run-result freeze, run-transition, period-transition,
correction-target, reversal-target validation); every expected
composite same-School FK, unique constraint, and CHECK constraint
present. Six raw-SQL negative proofs executed directly via `psql`
against a real seeded scenario (bypassing the Application layer and
Eloquent entirely): a cross-School FK reference, a compensation-overlap
bypass, a mutation of a posted run's frozen result, an invalid period
transition, a second "original" posting for the same run, and a second
reversal of the same posting — **all six rejected by the database
itself**, each with the expected constraint/trigger error.

**Full behavioral closure.** `php artisan test --filter=Payroll` → 212
passed (unchanged from 9.11 — zero regression from reconciliation).
Every category in the checkpoint's own risk list (compensation,
calculation, run lifecycle, Finance posting/reversal/correction, HTTP
idempotency, sensitive-data suppression, payslip/events) is covered by
name in the 27 passing test classes.

**Concurrency matrix** (every real multi-process proof, none replaced
by a sequential simulation):

| Competing operations | Test | Expected | Observed |
|---|---|---|---|
| Structure activation vs. activation | `CompensationConcurrencyTest::scenario_a` | Exactly one winner | Confirmed |
| Compensation assignment overlap | `CompensationConcurrencyTest::scenario_b` | Exactly one winner | Confirmed |
| Independent EmploymentRecords | `CompensationConcurrencyTest::scenario_c` | Both succeed independently | Confirmed |
| Raw SQL compensation-overlap bypass | `CompensationConcurrencyTest::scenario_d` | DB rejects | Confirmed |
| Recalculation vs. recalculation | `PayrollRunLifecycleConcurrencyTest::scenario_a` | Both succeed, no corruption | Confirmed |
| Calculate vs. approve | `PayrollRunLifecycleConcurrencyTest::scenario_b` | Never mutates an approved run | Confirmed |
| Approval vs. approval | `PayrollRunLifecycleConcurrencyTest::scenario_c` | Exactly one winner | Confirmed |
| Period open vs. open (same period) | `PayrollPeriodConcurrencyTest` | Exactly one winner, loser gets `ConcurrentPeriodTransitionConflictException` | Confirmed |
| Posting vs. posting (same run) | `PayrollPostingConcurrencyTest::scenario_a` | Exactly one original posting | Confirmed |
| Reversal vs. reversal (same posted run) | `PayrollPostingConcurrencyTest::scenario_b` | Exactly one reversal | Confirmed |
| HTTP idempotent duplicate POST /post | `PayrollIdempotencyRealConcurrencyTest` | Exactly one business effect, replay returns stored response | Confirmed |

Final DB state after every scenario: exactly one winning row/status
transition, the loser's attempt structurally rejected (never partially
applied), matching the "expected" column in every row.

**Authorization closure.** Exact capability catalogue confirmed via
direct query (`SELECT key FROM capabilities WHERE key LIKE
'payroll.%'`): exactly the 13 expected keys, no more, no fewer.
Default grants confirmed via direct query
(`role_capabilities`/`roles`): `school_admin` holds exactly the 10
non-Highly-Sensitive operational capabilities
(`.accounting.manage`, `.compensation.view`, `.periods.manage`,
`.runs.*`, `.structures.*`); zero roles hold
`payroll.compensation.sensitive.view`,
`payroll.compensation.sensitive.manage`, or `payroll.statutory.manage`
by default. No role-name string comparison anywhere in
`app/Domain/Payroll`.

**Security/privacy closure.** Re-ran the full grep sweep from
Checkpoint 9.11 plus the additional items this checkpoint specifies:
no direct `journal_entries`/`journal_lines` write (the sole
`journal_entries`/`journal_lines` hits are `PayrollPostingService`'s
own docblock explicitly documenting it never does this and calls
`LedgerService` instead); no direct HR authoritative mutation; no
role-name check; no `float`-typed monetary field; no bank-account
field; no PF/ESI/TDS implementation (the only hits are a component
*name* example in a docblock and the Payslip DTO's own explicit
exclusion note); no statutory-identifier field; no event payload
carries an amount-shaped key (verified both by grep and by
`PayrollDomainEventsTest`'s own payload-inspection assertions). No
P0/P1 found.

**API/OpenAPI/contract closure.** `route:list` re-confirmed on the
reconciled branch: same verbs, same middleware shape as 9.10/9.11,
zero stray routes from the merge. `npm run generate` against the
reconciled OpenAPI source produces a zero-diff `school-os-api.ts` a
second time (idempotent); `tsc --noEmit` clean.

**Administrative UI closure.** `format:check`, `lint` (0 errors, 2
pre-existing unrelated warnings), `type-check`, and `build` (861
modules, succeeds) all clean on the reconciled branch. `tests/Feature/App/`
(580 tests, every module's own UI suite, since no single centralized
navigation test file exists in this repository) shows the identical
failure set as an equivalent `main`-only run (see below) — zero
Payroll-attributable failures. Unauthorized-actor monetary-field
suppression re-confirmed by `PayrollUiTest`'s existing raw-payload
scans.

**Baseline-vs-branch regression attribution — the mandatory
requirement.** Ran the full repository suite (`php artisan test`, no
path filter) twice, in the same Docker image
(`school-os-platform-test:latest`), same PHP `memory_limit=1024M`,
same `platform:test-db-reset`-equivalent procedure, against two
*separate* PostgreSQL databases so the runs could execute truly in
parallel with no cross-contamination:

- **Phase 9 (reconciled branch):** 4814 passed, **13 failed**, 18266 assertions.
- **`main` alone** (a genuinely separate, unmodified worktree pinned at
  `origin/main`'s exact commit, `36c4a1ce9067d2e403c579179cdad5fcc3a9d629`):
  4596 passed, **13 failed**, 17123 assertions.

The two failure sets, compared by exact test name (not by count alone):
**byte-identical, 13-for-13.** `comm -23`/`comm -13` between the two
sorted failure-name lists both return empty. **Phase-9-specific
regression delta = zero.** The 218 additional passing tests on the
Phase 9 branch are exactly Payroll's own suite (212) plus a handful of
9.11 additions already counted elsewhere — no test that passes on
`main` fails on Phase 9, and no test fails on Phase 9 that passes on
`main`.

**Known infrastructure defects** (all reproduced identically on both
`main` and Phase 9 in this exact ad hoc verification harness; none
introduced by Phase 9; none block publication):

| Defect | Reproduced on `main`? | Reproduced on Phase 9? | Phase 9 introduced? | Blocks publication? |
|---|---|---|---|---|
| Orphan standalone PostgreSQL trigger functions surviving a bare `migrate:fresh` retry (documented since Checkpoint 9.1, ADR 0034 §5) | Yes (repo-wide, spans Finance/Payments/Fees/Identity migrations) | Yes (same root cause) | No | No — worked around per-reset by a full schema drop/recreate in this checkpoint and 9.10/9.11; a real fix is explicitly out of Payroll's boundary |
| `AnnouncementSchedulingTimezoneTest` (3 tests, Communications module) — timezone-dependent `null` `scheduled_at` | Yes | Yes | No | No — unrelated module, not exercised by any Payroll code path |
| `CurriculumDeliveryArchitectureGuardTest` (1 test, Academics module) | Yes | Yes | No | No — unrelated module |
| `Document*MinioIntegrationTest`/`DocumentStorageException` (5 tests) — this ad hoc container's `.env` `AWS_ENDPOINT=http://localhost:9000` does not resolve to the separately-networked `school-os-minio-1` container | Yes | Yes | No | No — a harness networking artifact of this verification session's containers, not application code; Documents module is untouched by Payroll |
| `ExaminationsRlsIsolationTest`/`ExaminationPapersRlsIsolationTest` (2 tests) — a closed-column-set assertion observing a different `information_schema.columns` ordering than hardcoded | Yes | Yes | No | No — unrelated module (0H.4A/4B), pre-existing on `main` |
| `HealthControllerTest`/`LoginThrottleTest` readiness-related (2 tests) — `/api/health/ready` returns `503` in this harness | Yes | Yes | No | No — this ad hoc container's dependency wiring (MinIO/Redis reachability) is incomplete for a full readiness check; unrelated to Payroll |

**Documentation closure.** This section, the roadmap-status summary
above, `docs/architecture/DOMAIN-MAP.md`'s Payroll row, and
`docs/roadmap/MASTER-ROADMAP.md`'s Phase 0J closure note were all
updated in the same commit as this checkpoint. No claim of "Phase 9
Payroll fully complete" appears anywhere — the accurate status is
"engineering implementation complete through non-statutory scope;
statutory Payroll (9.6) remains deferred pending
`[LEGAL REVIEW REQUIRED]`."

**Statutory 9.6 final status.** Re-checked `docs/security/DATA-CLASSIFICATION.md`
directly: the "Government/statutory identifiers" row remains tagged
`[LEGAL REVIEW REQUIRED]`, with no qualifying sign-off artifact
anywhere in the repository. A repository-wide grep for PF/ESI/TDS
implementation, statutory rate tables, or government-identifier
storage in `apps/platform/` returns zero hits. **9.6 remains BLOCKED /
DEFERRED — LEGAL REVIEW REQUIRED.**

**Git.** Merge commit `6cf4e88`; this closure's own commit follows it
on `feature/phase-9-payroll`. No merge into `main` was made or
attempted.

**PHASE 9.12 VERDICT: CLOSED — engineering implementation complete
through non-statutory scope. Statutory Payroll (9.6) remains deferred.
NOT merged into `main`; that remains a separate, explicitly-authorized
future step.**

(Historical record as of Checkpoint 9.12's own closure. Phase 9
non-statutory Payroll has since been published to `main` at `99cb641`,
with explicit publication permission — see the Phase 9 Final
Publication Report. Checkpoint 9.6 below is a new, separately
authorized post-publication initiative on its own branch.)

## Phase 9.6 Checkpoints (Statutory Payroll, in progress)

Legal basis: `SCH/PAY/REG/2026-9.6` (effective 1 April 2026, Telangana
jurisdiction), accepted subject to the binding correction addendum —
see `docs/architecture/adr/0035-phase-9-6-statutory-payroll-architecture.md`
for the full corrected contract. Implemented on
`feature/phase-9-6-statutory-payroll`, branched from published `main`
(`99cb641`) — Phase 9's own published non-statutory history above is
never rewritten by this work.

| Checkpoint | Status | Scope |
|---|---|---|
| 9.6A — Legal Addendum & Statutory Rule Contract | **[implemented]** | ADR 0035, this section |
| 9.6B — Golden Statutory Fixtures | **[implemented]** | 51 red fixtures: `PfCalculationServiceTest` (PF-01..12), `EsiCalculationServiceTest` (ESI-01..11, ESI-12 skipped/deferred), `ProfessionalTaxAndLwfCalculationServiceTest` (PT + LWF), `IncomeTaxSlabCalculatorTest` (TDS-01..12, TDS-20), `TdsMonthlyDeductionServiceTest` (TDS-13..19). Pure calculation-engine stubs under `app/Domain/Payroll/Statutory/Calculation/` throw until Checkpoint 9.6D/9.6E implements each against its docblock-documented algorithm. |
| 9.6C — Statutory Schema / Versioning / Privacy | **[implemented]** | 15 migrations: 7 platform reference tables (PF/ESI/LWF/PT+slabs/income-tax+slabs, no `school_id`, no RLS, matching `education_boards`), 8 School-owned RLS-enforced tables (component classifications, PF status, ESI coverage, tax profile, encrypted statutory identifiers, immutable calculation-result snapshots with a freeze trigger, statutory accounting configuration, LWF once-per-cycle charges). `StatutoryIdentifierLookupHasher` (its own HMAC key, ADR 0028's pattern). `StatutoryRuleVersionSeeder` seeds the 1-April-2026 rule versions matching the 9.6B golden fixtures' numbers exactly. |
| 9.6D — PF / ESI / PT / LWF Calculation Engine | **[implemented]** | `PfCalculationService`, `EsiCoverageDeterminationService`/`EsiContributionCalculationService`, `ProfessionalTaxCalculationService`, `LwfCalculationService` -- all pure/stateless, all 42 PF/ESI/PT/LWF golden fixtures green (1 ESI case correctly skipped/deferred). PF whole-INR rounding is half-up (`Money::multiplyByRate`); ESI rounding is upward-to-next-rupee (a dedicated ceiling helper, deliberately not half-up). |
| 9.6E — Annualized Salary TDS Engine | **[implemented]** | `IncomeTaxSlabCalculator` (annual liability: slabs, §87A rebate, marginal relief, table-driven surcharge with its own marginal relief, cess) and `TdsMonthlyDeductionService` (annualized spreading, prior-employer credit, over-withholding carry-forward, fail-closed insufficient-salary handling). All 20 TDS golden fixtures green, including the surcharge boundary (TDS-20). |
| 9.6F — Statutory Finance Posting | **[implemented]** | `StatutoryPayrollCalculationService` (Application-layer orchestration: resolves active rule versions/employee facts, groups `payroll_run_result_lines` by `payroll_salary_component_statutory_classifications`, calls the 9.6D/9.6E pure engines, persists one immutable `payroll_statutory_calculation_results` row per `payroll_run_results` row; fails closed on missing PF/tax-profile facts or an unclassified earning component -- never infers). `StatutoryPayrollPostingService` posts the FULL statutory GL effect (employee-side withholding debited against the SAME shared salary-payable account the main payroll entry uses; employer-side PF/ESI/LWF contributions as pure additional expense; every one of the 12 statutory figures appears exactly once as a debit and once as a credit) as ONE additional journal entry via `LedgerService::post()`/`reverseById()` only, alongside (never merged into) `PayrollPostingService`'s own entry -- new `payroll_statutory_run_postings` table (mirrors `payroll_run_postings`' one-original/one-reversal partial-unique-index shape). `StatutoryAccountingConfigurationService` mirrors `PayrollAccountingConfigurationService`'s configure/resolveValidated shape for the 14-account `payroll_statutory_accounting_configurations` table (corrected in this checkpoint to add the previously-missing dedicated PF-admin-charge and EDLI expense accounts -- see the 2026_10_06_090100 migration's own docblock). Balance proven end-to-end by a hand-verified feature test (`StatutoryPayrollCalculationAndPostingTest`). |
| 9.6G — ECR / ESI Worksheet / Form 138 Data Preparation | **[implemented]** | `StatutoryEcrExportService` (EPFO ECR: exact 11-field `#~#`-delimited row format, requires `payroll.statutory.exports.generate` AND `payroll.statutory.identifiers.view` since a row needs the real unmasked UAN, fails closed via `StatutoryIdentifierMissingException` rather than exporting a blank/fabricated UAN). `StatutoryEsiContributionWorksheetExportService` (contribution period, coverage-at-period-start, a derived continuity flag, statutory wage, employee/employer contribution; no identifier required). `StatutoryTdsDraftStatementExportService` (Form 138 draft data prep, April 2026+ only -- `StatutoryFormNotYetEffectiveException` otherwise; PAN masked by default, full value only with `.identifiers.view`; explicitly NOT a claim of exact NSDL/Form 138 file-format conformance -- see the class's own docblock). Four new capabilities registered (`payroll.statutory.view`, `.identifiers.view`, `.identifiers.manage` (reserved), `.exports.generate`), granted to NOBODY by default -- same treatment as `.statutory.manage`/`.compensation.sensitive.*`. Every export call is audited on success; no portal submission anywhere in this checkpoint. |
| 9.6H — Security / Concurrency / Migration / Publication Readiness | **[implemented]** | Capability catalogue: all 5 keys named by the checkpoint spec (`payroll.statutory.view`, `.manage`, `.identifiers.view`, `.identifiers.manage`, `.exports.generate`) are registered, granted to NOBODY by default. `StatutoryCalculationAlreadyPerformedException` closes a real duplicate-calculation race (`payroll_run_result_id`'s UNIQUE constraint translated cleanly, proven by a real two-process test). Real multi-process concurrency proofs: duplicate statutory calculation and duplicate statutory Finance posting (both mirror `PayrollPostingConcurrencyTest`'s exact pattern) -- see the Phase 9.6 Final Readiness Report for the full concurrency-scope reasoning, including which of the spec's 6 named scenarios have no real write path to race yet. Cross-School raw-SQL isolation proven for `employee_statutory_identifiers` (`PayrollStatutoryRawIsolationTest`, mirroring `RawIsolationTest`'s exact discipline). `payroll_statutory_run_postings`' append-only privilege set and structural one-original-per-run guarantee independently re-verified at the PostgreSQL catalogue level. Full 17-migration statutory batch rollback + reapply verified together (not just the most recent checkpoint's migrations). Full validation matrix run; see the Final Readiness Report for the complete P0-P4 register and regression comparison. |

| 9.6I — Administrative API / UI | **[implemented]** | Six new Admin services (`app/Domain/Payroll/Statutory/Application/Admin/`): `StatutoryRuleStatusReadService` (read-only, no runtime rule mutation -- rule content stays code/seed-controlled), `EmployeePfStatusAdminService`, `EmployeeEsiCoverageAdminService` (corrections refused once a period is consumed by a finalized calculation), `EmployeeTaxProfileAdminService`, `StatutoryIdentifierAdminService` (masked `list()` vs. audited, capability-separated `reveal()` -- the raw value is never bundled into any other response), `StatutoryAccountingAdministrationService` (wraps the existing 9.6F core service, mirroring `PayrollAccountingAdministrationService`'s established core-service/Administration-wrapper split). 15 new `/api/v1` JSON endpoints + a parallel session-authenticated `/app/payroll/statutory/*` Inertia UI (hub page, per-Employee combined page with reveal-on-demand identifiers and a statutory result breakdown, accounting configuration form, per-run export download page) -- both transports call the same Admin services. `StatutoryCalculationAlreadyPerformedException`-style translation via `abort()` added to every `/app/*` controller path (the JSON API's exception renderer doesn't cover Inertia). OpenAPI updated (15 new paths, ~20 new schemas) and `packages/shared-types` regenerated. Real two-process concurrency proof for PF-status mutation (`StatutoryPfStatusMutationConcurrencyTest` -- no state-machine transition to lose against here, so both writers succeed and the proof is "never a torn mixed state," not "one loses"). Comprehensive HTTP-level tests prove raw PAN/UAN values are structurally absent from both the JSON API and the Inertia page payload for an unauthorized actor -- never merely masked client-side (`StatutoryAdminApiTest`, `StatutoryPayrollUiTest`). |

**9.6 implementation is complete pending explicit publication
permission.** The ESI disability special threshold (₹25,000) remains
`DEFERRED — ADDITIONAL LEGAL CLARIFICATION REQUIRED` permanently (ADR
0035) -- this is a deliberate, disclosed legal gap, not an
implementation gap. See the Phase 9.6 Final Readiness Report for the
publication verdict.
