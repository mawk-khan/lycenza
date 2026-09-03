# ADR 0035: Phase 9.6 Statutory Payroll Architecture

- Status: Accepted
- Date: 2026-09-03

## Context

Checkpoint 9.6 of Phase 9 (ADR 0034) was `BLOCKED / DEFERRED — LEGAL
REVIEW REQUIRED` when Phase 9 published to `main` (see
`docs/modules/PAYROLL.md` "Phase 9.12 Closure"). That legal review has
now happened. The accepted legal/business source is:

> **Statutory Payroll Policy & ERP Configuration Specification**
> Document Ref: `SCH/PAY/REG/2026-9.6`
> Effective Date: 1 April 2026
> Jurisdiction: Telangana, India

The signed policy is accepted **subject to a binding legal/engineering
correction addendum** (below) that supersedes contradictory wording in
the original signed specification where the two disagree. This ADR is
that acceptance record. It is a new, standalone checkpoint on a new
branch (`feature/phase-9-6-statutory-payroll`, ADR 0028/ADR 0034's own
precedent for a separately-numbered post-publication initiative) —
Phase 9's own already-published, already-validated non-statutory
history (`feature/phase-9-payroll`, merged to `main` at `99cb641`) is
never rewritten.

ADR 0034 (§"Payroll vs. Compliance ownership") already decided that
Payroll owns and embeds statutory *calculation* logic as its own
versioned domain logic, gated behind legal review, with a future
Compliance module consuming results read-only. This ADR is that gate
clearing, for the specific rule set the signed policy and its
correction addendum define — not a reopening of that ownership
decision.

## Decision

### Binding legal/engineering correction addendum

The following corrections are authoritative over any contradictory
literal wording in `SCH/PAY/REG/2026-9.6`. Where the addendum and the
signed policy do not conflict, the signed policy's detail stands.

1. **PF membership vs. contribution ceiling.** Membership eligibility
   is determined from the **uncapped** statutory PF wage — never
   "calculate above ₹15,000, cap it, then decide membership from the
   capped figure." A new employee with no previous PF/UAN history,
   uncapped statutory PF wage above ₹15,000, and no approved Para
   26(6)/higher-wage participation is an **excluded employee**: PF
   contribution is zero. Existing membership continues above ₹15,000,
   but existing UAN/membership does **not** by itself authorize
   contribution on actual wages above ₹15,000 — a separate, explicit
   higher-wage approval is required. Modeled as four independent,
   never-inferred-from-each-other facts: previous/current PF
   membership, UAN, higher-wage contribution approval, EPS
   eligibility/higher-pension status.
2. **The 50% test is not five hardcoded components.** `Basic + DA +
   HRA + Special + Transport` is never treated as the complete
   remuneration universe. Every statutory-participating Payroll salary
   component carries an explicit, legally-versioned classification
   (PF core wage / 50%-test remuneration bucket / non-remuneration
   excluded / ESI wage treatment / TDS treatment) — never a fixed
   five-field formula, never a generic expression/rules engine.
3. **Income-tax law basis.** Salary paid from 1 April 2026 uses the
   **Income-tax Act, 2025**; salary TDS is the annualized/average-rate
   principle corresponding to **Section 392** of that Act — never the
   Income-tax Act, 1961 section numbers as the 2026 execution
   contract.
4. **Salary-TDS reporting target.** April 2026 onward targets **Form
   138** data preparation — never Form 24Q as the current-period
   target. Legacy historical (pre-April-2026) support is out of scope
   unless separately authorized later.
5. **New-regime section 87A rebate.** Qualifying total income
   threshold ₹12,00,000, maximum rebate ₹60,000, with marginal relief
   immediately above the threshold — never ₹70,00,000/₹7,000,000.
6. **Telangana Professional Tax.** ≤₹15,000 → ₹0; ₹15,001–₹20,000 →
   ₹150; >₹20,000 → ₹200.
7. **Telangana Labour Welfare Fund.** ₹2 employee + ₹5 employer,
   **once per statutory annual cycle** — never twice yearly (June +
   December). Applicability respects the Act's own employee
   definition; categories the Act excludes (e.g. managerial/
   apprentice/part-time, where the approved statutory classification
   determines exclusion) never get an LWF charge generated.
8. **ESI wage definition.** Never legacy raw gross cash salary — the
   current statutory ESI wage is derived through the same effective-
   dated component-classification model as the PF 50% test, not a
   parallel ad hoc field. General threshold for this implementation:
   ₹21,000. Rates: employee 0.75%, employer 3.25%. Contribution
   periods Apr 1–Sep 30 and Oct 1–Mar 31; an employee covered at the
   start of a contribution period who later crosses the ceiling within
   that period continues contributing until the period ends.
9. **ESI disability special threshold (₹25,000) is explicitly
   deferred** — `DEFERRED — ADDITIONAL LEGAL CLARIFICATION REQUIRED`.
   The general ESI logic above is implemented in full; this one branch
   is not, and the golden fixture for it is a recorded skip, never an
   invented expected value.
10. **TDS insufficient-salary policy.** If outstanding TDS exceeds the
    salary legally available for withholding in a final/reconciliation
    payroll: never create negative net pay, never fabricate an
    employer-funded TDS payment. Withhold only the legally available
    amount and create a typed statutory compliance exception/residual
    obligation surfaced for authorized Payroll/Finance action — fail
    closed, matching this codebase's established "fail closed rather
    than silently misrepresent" discipline (CLAUDE.md rule 5 and
    Phase 9's own partial-period fail-closed rule).
11. **Statutory GL must balance.** Every employer PF/admin/EDLI/ESI
    obligation is a debit (employer statutory expense) with a
    corresponding liability credit — never a debit with no credit.
    School-scoped statutory liability account configuration, never a
    hardcoded ledger UUID, never a direct Finance-table write, never a
    name-lookup fallback.

### Component-classification architecture (replacing hardcoded field lists)

A new `salary_component_statutory_classifications` concept (Checkpoint
9.6C) attaches, per `SalaryComponent` and per effective-dated statutory
rule version, an explicit, typed classification: whether the component
counts toward PF core wage, the 50%-test remuneration bucket, ESI
wage, or TDS-relevant income, and whether it is excluded from a given
treatment entirely. The PF 50% rule becomes a genuine computation over
whichever components are classified that way in the *currently
effective* rule version — never a name-based `in_array(['Basic', 'DA',
...])` check. This is the one deliberate schema-level departure from
"prefer typed columns over JSON": classifications are still typed rows
(one classification dimension per row), never a JSON rule blob, and
never a general-purpose expression language (§1.2/§2 of the correction
addendum).

### Effective-dated statutory rules, never in-place mutation

Every legally-defined number in this ADR (PF rates/ceiling, ESI
rates/threshold, PT slabs, LWF amounts, TDS slabs/rebate/cess) is
stored as an effective-dated rule-version row (`effective_from`,
optional `effective_to`, `status`, a legal source reference), never a
literal constant in application code. A rule version, once used by any
finalized statutory calculation, becomes immutable — a future legal
change adds a new version with a later `effective_from`; it never edits
history. This is the same discipline Phase 9's own `SalaryStructure`
revision model and `AcademicYear` already established for "this fact
changes over time but history must never retroactively change."

### Immutable statutory-result snapshots, never self-recalculating history

A finalized statutory calculation result records which rule version(s)
it used and its calculated bases/contributions/tax as a frozen
snapshot — mirroring `payroll_run_results`/`_lines`' existing freeze-
trigger discipline exactly (ADR 0034). A historical payroll run is
never recalculated against a newer rule version; only a new run (or an
explicit correction run, Phase 9's existing correction model) picks up
a rule change.

### PF membership vs. contribution: four independent facts

`employee_pf_status` (Checkpoint 9.6C) tracks, per EmploymentRecord,
independently and without inference: PF membership (yes/no + since
date), UAN (identifier only — never implies anything else), higher-
wage contribution approval (yes/no + approval reference), and EPS
eligibility/higher-pension status. `PfMembershipDeterminationService`
resolves eligibility from the **uncapped** statutory wage plus these
four facts, per the correction addendum's §1.1 — the capped
contribution base is computed only *after* membership is already
established as a fact.

### ESI contribution-period continuity

`employee_esi_coverage` (Checkpoint 9.6C) tracks per-EmploymentRecord
coverage state keyed by contribution period (Apr–Sep / Oct–Mar). Once
covered at a period's start, coverage for that period does not turn
off mid-period even if wages cross ₹21,000 — modeled as a stored
per-period coverage decision, not a re-evaluated-every-payroll-run
boolean.

### Annual TDS projection, never a monthly flat-rate lookup

`StatutoryTdsProjectionService` (Checkpoint 9.6E) computes an annual
tax liability projection (current + projected future salary at current
employer, prior-employer salary/TDS if declared, other declared
income, regime-appropriate deductions/exemptions/87A/marginal relief/
surcharge/cess) and spreads the remaining liability over remaining
payroll cycles each run — never a static "X% of this month's salary."
A salary revision or regime switch triggers reprojection for future
cycles only; already-posted payroll history is never rewritten
(matching the correction addendum's TDS insufficient-salary and
reprojection rules, and Payroll's existing "approved run is immutable"
discipline).

### Statutory identifier privacy

PAN, UAN, PF Member ID, and ESIC IP Number are stored using the same
encrypted-value-plus-keyed-lookup-hash architecture ADR 0028 already
established for Guardian contact data (`encrypted` Eloquent cast for
the real value, a keyed HMAC-SHA-256 lookup digest for exact-match
duplicate detection where genuinely needed) — never bespoke
cryptography. A new, dedicated hasher
(`App\Support\Privacy\StatutoryIdentifierLookupHasher`) gets its own
domain-separation prefix (`statutory-identifier`), following ADR
0028's own explicit guidance that a second, unrelated exact-match-
lookup need should get its own class rather than overload
`ContactLookupHasher`'s guardian-contact-specific one. Every read of a
raw (unmasked) identifier requires a dedicated capability
(`payroll.statutory.identifiers.view`/`.manage`, Checkpoint 9.6H) —
`payroll.statutory.manage`/`.view` alone never suffices, mirroring
Phase 9's own `payroll.runs.view` vs.
`payroll.compensation.sensitive.view` precedent (ADR 0034). No raw
identifier is ever logged, placed in audit metadata, or placed in an
event payload — only the fact that a sensitive statutory read happened
is audited, matching Phase 9's existing "audit the fact, never the
value" discipline.

### Statutory Finance posting

Statutory employer obligations post through the same, sole sanctioned
path Phase 9 already established:
`App\Domain\Finance\Application\LedgerService::post()`/`reverse()` —
never a direct `journal_entries`/`journal_lines`/`ledger_accounts`
write. A new `PayrollStatutoryAccountingConfiguration` (Checkpoint
9.6F) extends `PayrollAccountingConfiguration`'s existing pattern
(School-scoped, composite-FK-pinned ledger account references, no
name lookup, no hardcoded UUID) to the additional statutory payable/
expense accounts §1.11 above lists. A missing mapping fails closed
before any Finance side effect, exactly like the existing
`PayrollAccountingNotConfiguredException` precedent.

### Export-only government filing boundary

Checkpoint 9.6G produces **data preparation only** — an EPFO ECR text
row set, an ESIC monthly worksheet, and Form 138 draft data. None of
these submits to any government portal, none produces a signed Form
16, and no banking/payment execution exists anywhere in this scope.
Every export requires an explicit capability
(`payroll.statutory.exports.generate`), is audited, and is built and
tested only against synthetic data — never a real employee identifier
in a fixture or test.

### Explicitly deferred, this checkpoint

- ESI disability special threshold (₹25,000) — `DEFERRED — ADDITIONAL
  LEGAL CLARIFICATION REQUIRED`.
- Automated EPFO/ESIC/income-tax portal submission.
- Form 16 PDF/digital-signature production.
- Bank-disbursement/payment-execution integration of any kind.
- Stored payslip Documents (still Phase 9's own deferred item, ADR
  0034 — statutory data extends the existing on-demand payslip, it
  does not reopen that deferral).
- A generic statutory scripting/rules engine, multi-country statutory
  Payroll, and any tax/legal advice the application itself generates
  beyond executing this ADR's own defined rules.

## Consequences

Checkpoint 9.6 is unblocked for implementation under this corrected
contract. `docs/modules/PAYROLL.md` records checkpoint-by-checkpoint
progress (9.6A–9.6H); 9.6 is not marked complete until 9.6H's full
validation, golden-fixture regression, and security/concurrency
closure all pass. If a golden fixture ever conflicts with newly
supplied qualified legal advice, the correct response is to stop that
rule area and report the conflict — never to silently weaken the
fixture to make code pass.
