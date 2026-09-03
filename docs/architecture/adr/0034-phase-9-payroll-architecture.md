# ADR 0034: Phase 9 Payroll Architecture

- Status: Accepted
- Date: 2026-08-29

## Context

`docs/roadmap/MASTER-ROADMAP.md` sequences Payroll as the second half of
**"Phase 0J — HR and Payroll."** Phase 8A (ADR 0028) already extracted
and closed the HR half (Employee/EmploymentRecord/EmployeeAssignment
records) under an externally-numbered label, explicitly leaving
"Payroll (the remainder of this Phase 0J entry) ... not started."
Following that exact precedent, this ADR assigns the remaining,
still-undelivered scope the external label **"Phase 9"** — there is no
pre-existing "Phase 9" anywhere in the repository to recover; this is a
new label for old, well-scoped, already-documented work, the same way
"Phase 8A" was a new label for `0J`'s HR half.

`docs/architecture/DOMAIN-MAP.md` already commits Payroll to `HR,
Finance` as its only dependencies, never the reverse, and
`docs/modules/FINANCE.md`'s "Payroll boundary" section (written during
Phase 0G, before any Payroll code existed) already states the intended
split: Payroll calculates obligations, Finance posts the resulting
accounting facts via its existing `LedgerService`. This ADR formalizes
the concrete schema, state machine, and integration decisions needed to
actually build that boundary, resolving several points `DOMAIN-MAP.md`
left implicit or, in one case, in direct tension with itself (the
statutory/Compliance ownership question below).

A three-gate architecture audit and reconciliation (Phase 9 audit,
Phase 9.0 reconciliation, Phase 9.0B correction) preceded this ADR and
resolved a number of contradictions the initial sketch contained. This
ADR records the *decisions*, not the process that reached them.

## Decision

### Scope and dependency direction

Phase 9 delivers the Payroll remainder of `Phase 0J`, exactly as scoped
by ADR 0028 and `DOMAIN-MAP.md:108`: salary structures, payroll runs,
and (gated, see below) statutory deductions. Payroll depends on HR and
Finance; neither depends on Payroll. Payroll never writes HR or Finance
tables directly — it reads HR's `Employee`/`EmploymentRecord` via HR's
Application layer, and posts to Finance exclusively through
`App\Domain\Finance\Application\LedgerService::post()`/`reverse()`.

### Payroll vs. Compliance ownership (resolving a real contradiction)

`DOMAIN-MAP.md:108` states statutory compliance (PF/ESI/TDS) is "a
Layer 5 (Compliance) concern layered on top, not owned here" — but
Layer 5 is separately defined as read-mostly and consumer-only ("no
Layer 1-4 module may require Compliance to function"), and Compliance
does not exist yet. A payroll run's net pay mathematically requires the
statutory deduction amount at calculation time; Payroll cannot
functionally depend on an unbuilt, read-only module for its own core
output. **Decision:** Payroll owns and embeds the statutory
*calculation* logic (rate tables, formulas) as its own versioned
domain logic, gated behind the legal-review boundary below. A future
Compliance module consumes Payroll's already-calculated results
read-only, for regulatory reporting/filing — it never gates or
participates in calculation. This preserves `DOMAIN-MAP.md`'s
dependency direction while resolving the functional contradiction.

### Salary structure revision model

Each `salary_structures` row is one immutable revision, not a mutable
document with a hidden version history: logical `code` (School-scoped),
integer `version`, `status` (`draft`|`active`|`superseded`). A revision
is fully mutable while `draft` and permanently frozen the instant it is
`active` — activation is the freeze boundary, not first assignment, so
there is no window where an "active" revision a UI already shows as
current could still silently change. Only one revision per `(school_id,
code)` may be `active` at a time, enforced by a partial unique index,
directly mirroring `academic_years_one_active_per_school`'s
already-proven pattern (ADR 0004-era Academic Structure). Assignments
reference an exact revision id, never the logical `code`, so historical
payroll remains reproducible even after a new revision is activated.

### Where employee-specific compensation values live

`salary_components` (Basic, HRA, PF-Employee, ...) carry semantic
identity only — no monetary value. `salary_structure_components`
define the calculation shape per revision — `fixed_amount` or
`percentage_of_base` (a decimal-fraction rate, itself structure-level
policy, e.g. "HRA = 40% of Basic"), ordered so a percentage component's
base must be an earlier-ordered component in the same revision (cycle
avoidance by construction, no cycle-detection code). The actual
Employee-specific numbers live in a new `compensation_assignment_values`
table — one row per `(assignment, fixed_amount component)` — so many
Employees can share one structure revision while each supplies their
own negotiated fixed amounts; percentage-derived components are never
separately stored, only computed from their base at calculation time.

### Compensation effective-dating and overlap

`employee_compensation_assignments` reference `EmploymentRecord`, never
bare `Employee` (an Employee may have multiple historical
EmploymentRecords via rehire). Two assignments for the same
EmploymentRecord may not have overlapping effective ranges. Following
the explicit, twice-documented precedent in
`create_academic_years_table.php` and `create_employment_records_table.php`
— both of which deliberately reject `EXCLUDE USING gist`/`daterange`
as premature complexity for low-write-rate, human-initiated records,
and note no such Postgres extension exists anywhere in this codebase —
Payroll does **not** introduce `btree_gist` either. Overlap is enforced
by a parent-row lock (`EmploymentRecord` `FOR UPDATE`) plus an
Application-layer check inside one transaction, backed by a database
trigger that independently re-validates the same invariant at INSERT
time (the trigger is Payroll's own addition, giving overlap a real
structural backstop the two precedent tables do not have, without
requiring the rejected extension).

### Monthly period model

`payroll_periods.period_month` (normalized to the first of the month)
is the true identity; `starts_on`/`ends_on` are derived and
CHECK-validated against it, never independently arbitrary.
`UNIQUE(school_id, period_month)` makes overlap impossible by
construction — two distinct calendar months can never overlap — with
no exclusion constraint of any kind. `payment_date` is independent.

### Run kinds, correction model, and posting

`payroll_runs.run_kind` is `regular` or `correction`.
`UNIQUE(school_id, payroll_period_id) WHERE run_kind='regular'`
guarantees one authoritative regular run per period; a correction run
(`corrects_payroll_run_id` referencing the original *regular*, already
*posted* run — no correction-of-correction chains) is a separate row
built entirely from explicit `payroll_adjustments` delta lines, never
a re-run of the automatic kernel — this is what keeps correction a
targeted delta rather than a retro-pay engine. State machine:
`draft -> calculated -> approved -> posted`, with `approved` as the
sole immutability boundary (no separate `finalized` state). Posting
uses `payroll_run_postings` (append-only, `posting_kind`
`original`|`reversal`) with a partial unique index guaranteeing at
most one original posting per run and at most one reversal per
posting. A run's own `status` never becomes `reversed` — reversal is a
derived read, computed from whether a reversal posting row exists,
never a mutation of run history.

### Deduction accounting

`salary_components.liability_ledger_account_id` (composite FK to
Finance's `ledger_accounts`) names each deduction's Finance
destination; `payroll_accounting_configurations` names the two fixed
accounts (salary expense, salary payable). A run posts one aggregate
debit (total GROSS earnings, to salary expense), one aggregate credit
(total net pay, to salary payable), and one aggregate credit per
deduction component with a nonzero total (to its liability account) —
balanced by construction, since `gross = net + deductions` holds for
every result. A deduction with a nonzero total and no configured
mapping blocks posting entirely (no suspense-account fallback).

> **Amendment (Checkpoint 9.5 accounting-integrity review, 2026-08-29):**
> the paragraph above originally continued: *"The resolved account for
> every line is snapshotted onto `payroll_run_result_lines` at
> calculation time, frozen at `approved` alongside the rest of the
> result, so a later reconfiguration can never alter what an
> already-approved run will post."* That snapshot-at-calculation-time
> policy for the DEDUCTION account was inconsistent with the
> salary-expense/salary-payable accounts, which were always resolved
> and validated LIVE at posting time
> (`PayrollAccountingConfigurationService::resolveValidated()`) — a
> mixed policy discovered during 9.5's accounting-integrity
> verification. Resolved as **posting-time resolution for ALL THREE
> account categories**, uniformly: `PayrollPostingService` now resolves
> a deduction's liability account live from
> `SalaryComponent::liability_ledger_account_id` at posting time (same
> existence/active/correct-`type` validation the expense/payable pair
> already received), exactly like the other two accounts. The
> `payroll_run_result_lines.resolved_ledger_account_id` column is
> unchanged and still populated at calculation time — it remains a
> useful point-in-time record of what was configured when the run was
> calculated — but is no longer read by `PayrollPostingService`; it is
> informational only, not the posting-time source of truth. This
> keeps ADR 0034's "`approved` is the sole immutability boundary"
> principle scoped to monetary amounts and component identity (which
> line existed, for how much) — never to which Finance account a
> component happens to be mapped today, which a School must be able to
> correct going forward exactly as it already could for the
> expense/payable pair.

### Separation of duties

`payroll_runs` persists `prepared_by_user_id`, `approved_by_user_id`,
`posted_by_user_id`. The preparer of a run may never approve it —
enforced at the Application layer (mirroring the one existing
repository precedent for this exact rule,
`App\Domain\Communications\Application\Approval\CommunicationApprovalService`'s
`SelfApprovalNotAllowedException`) and backed by a database CHECK
constraint, since this specific two-column comparison is cheaply
expressible unlike a cross-row invariant. An approver may also post or
reverse — no repository precedent requires further restriction.
`payroll.runs.post` and `payroll.runs.reverse` are distinct
capabilities, mirroring Finance's own `finance.ledger.post`/`.reverse`
split.

### Statutory legal gate

PF/ESI/TDS calculation, government/statutory identifiers, and any
claim of legal/statutory compliance remain **`[LEGAL REVIEW REQUIRED]`**
per `docs/security/DATA-CLASSIFICATION.md` and ADR 0028. This gate
blocks only Checkpoint 9.6. It does not block Finance posting or a
complete, production-usable *non-statutory* Payroll foundation
(Checkpoints 9.1-9.5) — conflating "post a computed gross/net amount to
the ledger" (an accounting operation) with "calculate PF/ESI/TDS
correctly" (the actually-gated question) would incorrectly withhold
unrelated, already-safe engineering work. Non-statutory Payroll output
must never be represented, in code, UI copy, or documentation, as
legally compliant payroll for any jurisdiction.

### Explicit exclusions (not deferred by omission — deferred by decision)

Bank-account storage: excluded from Phase 9 entirely — no in-scope
consumer exists (bank payment execution/files/reconciliation are all
out of scope), so no encrypted-PII schema is built ahead of a real
need (rule 2). Attendance integration: not a dependency —
`DOMAIN-MAP.md:108` lists only HR and Finance, and Attendance is not
built. Employee self-service: deferred — no self-service authorization
boundary exists anywhere in this codebase yet to build on safely.
Generic payroll rules engine: rejected — the calculation vocabulary is
fixed to `fixed_amount`/`percentage_of_base`, no formulas, scripts, or
expression languages.

## Rationale

Every decision above traces to either (a) direct repository precedent
already proven under real concurrency elsewhere in this codebase
(Academic Year activation, Communications approval, Finance/Payments
posting and reversal), or (b) an explicit rejection of a more general
mechanism (`btree_gist`, a rules engine, bank-account storage,
self-service) for lacking a demonstrated current need, per CLAUDE.md
rule 2. Where the initial sketch of this architecture proposed a more
general mechanism than the precedent actually supports — most notably
`EXCLUDE USING gist` for date-range overlap — this ADR follows the
smaller, already-established pattern instead, once the precedent
tables' own migration docblocks were read directly and found to
explicitly reject exactly that mechanism for an analogous case.

## Alternatives considered

1. **A separate `salary_structure_versions` table alongside
   `salary_structures`.** Rejected: a structure revision *is* a
   structure row: giving it its own row (not a child version table)
   is the smaller model and still satisfies every required property
   (immutability, historical reproducibility, no per-Employee
   structure explosion).
2. **`EXCLUDE USING gist` + `btree_gist` for both period non-overlap
   and compensation-assignment non-overlap.** Rejected for both:
   monthly periods don't need a range constraint at all once
   `period_month` is the identity (`UNIQUE` suffices); compensation
   overlap would be the first exception to an explicit, twice-stated
   repository decision against this extension for directly analogous
   cases (`academic_years`, `employment_records`).
3. **A single `payroll.runs.post` capability covering both posting and
   reversal.** Rejected: Finance's own `finance.ledger.post`/`.reverse`
   split is strong, direct precedent for treating reversal as a
   distinct, separately-grantable, higher-risk operation.
4. **Storing a rendered payslip as a persisted Document from day
   one.** Rejected for v1: `payroll_run_results`/`_lines` are already
   the reproducible source of truth; a stored artifact is only needed
   once a real distribution requirement (email, self-service download)
   exists, per rule 2. Render-on-demand only for now.
5. **Fixing the pre-existing orphan-trigger-function defect in
   `ResetTestDatabase`/`migrate:fresh` (see closure notes for
   Checkpoint 9.1) as part of this ADR's scope.** Rejected: the
   defect spans Finance/Payments/Fees/Identity migrations Payroll does
   not own, and a schema-level fix risks disturbing role/privilege
   bootstrapping other phases depend on — reported as a pre-existing
   test-infrastructure defect instead, out of Phase 9's boundary.

## Consequences

- `docs/modules/PAYROLL.md` is the binding reference for every Phase 9
  checkpoint's schema/API/authorization/privacy decisions, mirroring
  how `FINANCE.md`/`HR.md` are treated as living documents for their
  own phases.
- `docs/roadmap/MASTER-ROADMAP.md`'s Phase 0J entry is annotated (not
  rewritten) pointing to this ADR, mirroring ADR 0028's own precedent.
- `docs/architecture/DOMAIN-MAP.md`'s Payroll row is annotated to
  record that Phase 9 is in progress under this ADR, without claiming
  completion.
- Every Phase 9 migration follows the composite-FK `(id, school_id)` /
  `TenantRls::enable()`-and-forced / partial-unique-index patterns
  already established since Phase 0D — no new tenancy mechanism is
  introduced.
- Checkpoint 9.6 (statutory) cannot close without an explicit legal
  sign-off or an explicit, user-approved scope-narrowing decision to
  defer it — it is never silently skipped, and Phase 9 is never
  represented as fully closed while it remains open, per
  `MASTER-ROADMAP.md`'s own closure-note convention (see ADR 0028's
  closure note for the exact precedent this follows).
