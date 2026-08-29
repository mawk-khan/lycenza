# Payroll

Living architecture document for Phase 9 (the Payroll remainder of
`docs/roadmap/MASTER-ROADMAP.md`'s "Phase 0J — HR and Payroll"). See
`docs/architecture/adr/0032-phase-9-payroll-architecture.md` for the
formal decision record this document elaborates.

## Checkpoint roadmap

- **9.0 — Architecture Decisions & Legal Boundary** (this checkpoint):
  ADR 0032, this document, roadmap/domain-map annotations. No schema.
- **9.1 — Compensation Schema Foundation**: `salary_components`,
  `salary_structures`, `salary_structure_components`,
  `employee_compensation_assignments`, `compensation_assignment_values`,
  plus `payroll_periods`, `payroll_runs`, `payroll_run_results`,
  `payroll_run_result_lines`, `payroll_adjustments`,
  `payroll_accounting_configurations`, `payroll_run_postings`.
- **9.2 — Compensation Application Services**: structure
  draft/activate/supersede, compensation assignment, overlap
  enforcement, real concurrency proofs.
- **9.3 — Payroll Periods & Calculation Kernel**: period services, run
  creation, deterministic calculation, `Money::multiplyByRate()`,
  golden fixtures, the fail-closed partial-period rule.
- **9.4 — Run Lifecycle, Snapshotting & Separation of Duties**: state
  machine, approval immutability, actor persistence, concurrency
  proofs.
- **9.5 — Finance Configuration, Posting & Reversal**: accounting
  configuration, the posting algorithm, correction-run posting,
  reversal, transactional/idempotency integration with
  `LedgerService`.
- **9.6 — Statutory Payroll Boundary — LEGAL GATE**: PF/ESI/TDS.
  Blocked pending `[LEGAL REVIEW REQUIRED]` sign-off
  (`docs/security/DATA-CLASSIFICATION.md`). Not started until that
  clears or is explicitly, visibly deferred by user decision.
- **9.7 — Authorization, Sensitive Reads & Audit.**
- **9.8 — API / OpenAPI.**
- **9.9 — Administrative UI.**
- **9.10 — Events / Payslip Rendering.**
- **9.11 — Security / Concurrency / Migration Closure.**
- **9.12 — Integration / Publication Readiness.**

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
No `EXCLUDE USING gist`/`btree_gist` — see ADR 0032's rationale (direct
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
An earlier draft of this document (and of ADR 0032) described the
deduction account as snapshotted at calculation time and frozen at
`approved`; that was inconsistent with the expense/payable pair, which
were always resolved live, and has been corrected in favor of the
single, coherent policy described here (see ADR 0032's amendment note
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

Implemented at Checkpoint 9.7. Nine capabilities, all school-scoped:
`payroll.structures.view`/`.manage` (formula/policy shape only —
component names, calculation types, rates, ordering, never individual
data), `payroll.compensation.sensitive.view`/`.manage` (the Highly
Sensitive family — gates `compensation_assignment_values.amount` and
every field on `payroll_run_results`/`_lines`, as a whole row, never a
partial field, enforced in `PayrollRunResultReadService`, never in
Vue), `payroll.periods.manage`, `payroll.runs.manage` (create,
calculate, AND approve — Separation of Duties for approval is an
ACTOR-level rule, not a capability-level one: the preparer of a run may
never approve it, enforced by `SelfApprovalNotAllowedException` +
the database CHECK `payroll_runs_sod_check`, mirroring
`CommunicationApprovalService`'s identical precedent; an approver may
also post or reverse), `payroll.runs.post` and `payroll.runs.reverse`
(deliberately distinct from `.manage` and from each other, mirroring
`finance.ledger.post`/`.reverse`), and `payroll.accounting.manage`.
`school_admin` receives all nine by default; `principal` and every
other school-scoped role receive none (`PayrollCapabilityRegistryTest`).

Every Payroll Application-layer core service (`SalaryComponentService`,
`SalaryStructureService`, `CompensationService`, `PayrollPeriodService`,
`PayrollRunService`, `PayrollPostingService`,
`PayrollAccountingConfigurationService`) remains capability-check-free
by design, exactly like `App\Domain\Finance\Application\LedgerService`/
`ChargeService` — a dedicated Administration wrapper per family
(`PayrollStructureAdministrationService`,
`PayrollCompensationAdministrationService`,
`PayrollPeriodAdministrationService`, `PayrollRunAdministrationService`,
`PayrollPostingAdministrationService`,
`PayrollAccountingAdministrationService`) is the ONLY place a
capability check happens, mirroring
`LedgerAdministrationService`/`ChargeAdministrationService`'s identical
split. No transport may call a core service directly; a future
controller (Checkpoint 9.8) depends on the Administration layer.

## Statutory boundary

PF/ESI/TDS calculation, government/statutory identifiers, and any
statutory-compliance claim remain `[LEGAL REVIEW REQUIRED]`
(`docs/security/DATA-CLASSIFICATION.md`). Payroll owns the statutory
*calculation* once cleared (ADR 0032's Compliance-ownership
resolution) — a future Compliance module only consumes results,
read-only. This boundary blocks only Checkpoint 9.6.

## Sensitive-data handling

Salary/compensation data is Highly Sensitive per
`docs/security/DATA-CLASSIFICATION.md`. Never in logs, audit metadata,
event payloads, or an unauthorized read: raw amounts, rates, gross,
deductions, net pay. Audit records the fact (which run, which action,
which actor) by reference, never the value — verified directly:
`PayrollRunResultReadService::listResults()`'s own audit call carries
only `resultCount`, and `PayrollRunResultReadServiceTest` asserts no
amount-shaped key ever appears in the recorded metadata.
`PayrollRunResultReadService` (`payroll.compensation.sensitive.view`)
is Checkpoint 9.7's sole read path for an individual Employee's actual
result — every field returned as a typed `PayrollRunResultDetail`/
`PayrollRunResultLineDetail` DTO, never the raw
`PayrollRunResult`/`PayrollRunResultLine` Eloquent model, mirroring
`App\Domain\Payments\Application\PaymentReadService`'s identical
disclosure boundary (including the "no oracle" cross-School
not-found uniformity via `PayrollRunNotFoundException`).
