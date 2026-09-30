# ADR 0062: Fee Structures, Assessment Runs, Concessions and Receipts Contract

- Status: Accepted as a contract (documentation only; nothing is
  implemented). Implementation starts at FEE.1 **only after** the owner
  decisions marked "needed for FEE.1" in §2.1 are recorded.
- Date: 2026-09-29 (FEE.0, the first post-foundation product checkpoint)
- Programme: **FEE — Fee Management** (`docs/roadmap/MASTER-ROADMAP.md`,
  "Post-foundation product programmes").
- Builds on, and does not rewrite:
  - ADR 0030 (ledger);
  - ADR 0031 (payments, settlement, allocation; 0O.11A manual recording);
  - ADR 0057 (payment gateway and refunds deferred);
  - `docs/modules/FINANCE.md` (0G.0 contract and 0G.1–0G.8 as-built).
- Will amend, when its checkpoint is built (by note, never by rewrite):
  - ADR 0031 — the allocation capacity rule counts posted adjustments (FEE.3,
    §15) and settled Payments issue receipts (FEE.4, §17).
- Related: ADR 0025 (outbox), ADR 0017 (audit), ADR 0047 (School lifecycle),
  CLAUDE.md rules 3–11, 17–19, 21, 29–33, 58–60, 70, 74, 82, 86, 91.

## 1. Context — as-built audit (2026-09-29)

Audited on branch `docs/fee-0-fee-structures-assessment-contract`, based on
`origin/main` `d5d85bc` plus the pending revert `5d8dce3` (documentation
only; no executable difference from `13cfba3`).

### 1.1 Charges (`App\Domain\Fees`)

- **Entity.** `charges` (migration `2026_09_02_090000_create_charges_table`)
  is the only receivable entity. Columns: `student_id`, `academic_year_id`,
  `description`, `amount NUMERIC(14,2)`, `currency`, `due_date` (nullable),
  `receivable_ledger_account_id`, `revenue_ledger_account_id`,
  `journal_entry_id` (NOT NULL), `cancelled_at`,
  `cancellation_journal_entry_id`.
- **Constraints.** `amount > 0`; `currency = 'INR'`; distinct accounts;
  cancellation pair set together. Composite FKs to `students`,
  `academic_years`, `ledger_accounts` and `journal_entries` (the last two
  pin `currency`). `TenantRls::enable` + `TenantRls::revokeDelete`.
- **Immutability.** `charges_immutability_trigger` makes every recognized
  field immutable. The only legal UPDATE is the one-time cancellation pair,
  which must name the real reversal of the charge's own journal entry.
- **No idempotency or source columns.** Uniques are only `(id, school_id)`,
  `(school_id, journal_entry_id)` and `(school_id,
  cancellation_journal_entry_id)`. FINANCE.md "Generic assessment
  idempotency: DEFERRED" says no uniqueness exists on purpose.
- **`ChargeService`** (trusted core, not an authorization boundary):
  - `assess(School, AssessChargeData, ?User)` posts Dr receivable / Cr
    revenue through `LedgerService::post()`, inserts the charge, audits
    `charge.assessed` and emits `ChargeAssessed` (`charge.assessed.v1`), all
    in one transaction under `TenantContext::withSchool()`.
  - `cancel(...)` locks the charge `FOR UPDATE`, reverses through
    `LedgerService::reverseById()`, sets the pair. It is refused when
    allocations exist (`charges_payment_allocation_guard_trigger`, owned by
    Payments).
  - `lockChargeForAllocation(...)` is the one sanctioned way Payments locks
    and reads a charge. `uncancelledChargesForStudent(...)` lists open
    charges oldest first.
- **`AssessChargeData`** carries `studentId`, `academicYearId`,
  `description`, `amount` (Money), both ledger account ids and `dueDate`.
  The caller chooses the accounts on every call.
- **Authorization.** `ChargeAdministrationService` (`finance.charges.manage`)
  and `ChargeReadService` (`finance.charges.view`).
- **Consumers.** Staff (web `app/finance/charges*`, API
  `schools/{school}/charges*`) and Canteen fulfilment
  (`CanteenOrderService::fulfill()`), whose exactly-once guarantee comes from
  its own order-row lock and `canteen_orders_charge_id_unique`, not from
  Fees. Canteen picks accounts from its singleton
  `canteen_billing_configurations` row and validates asset/income types at
  run time (`CanteenBillingConfigurationService::resolveValidated()`).

### 1.2 Ledger (`App\Domain\Finance`)

- `ledger_accounts` types `asset|liability|equity|income|expense`, status
  `active|inactive`, INR only, code unique per School case-insensitively.
- `journal_entries` and `journal_lines` are append-only. Balance is
  checked by a deferred constraint trigger. Reversal is a new entry
  (`journal_entries_reversal_of_unique`). `posted_at` is always now; there
  is no effective or back-dated posting date.
- **Journal entries carry no source columns.** A subledger links to its
  entries only through its own table's `journal_entry_id` column.
- `LedgerService::post()` does not refuse an inactive account. That
  decision is deferred (FINANCE.md 0G.2 "Account validation and lookup").
  `LedgerService::activeAccountsOfType()` exists.
- **Finding F1 — no production chart of accounts.** No application path
  creates a `ledger_accounts` row. There is no seed, no CRUD route, and
  ledger-account CRUD is deferred (FINANCE.md "Deferred register"). Only
  factories and the DDEV demo seeder create accounts. A production School
  therefore cannot map a fee to any account today (§6).
- `Money` (`App\Support\Money\Money`): `of`, `add`, `negated`,
  `multiplyByRate(rate, scale=2)` (rounds half away from zero), `amount()`
  as a decimal string. There is no float anywhere.

### 1.3 Payments (`App\Domain\Payments`)

- **Tables.** `payments` is immutable and has no status column. Source is
  `provider|manual`; manual methods are `cash|bank_transfer|cheque`.
  `payment_allocations` is append-only and allocations are explicit (no
  automatic oldest-first).
- **Allocation rules.** The allocations must sum exactly to the payment
  amount (`payments_fully_allocated_check`). Cumulative allocations may not
  exceed the charge amount; cancelled charges are refused
  (`payments_lock_and_validate_charge_allocation()`, which also locks the
  charge `FOR UPDATE`). There is no unapplied cash, no overpayment, no
  refund and no reversal (ADR 0031, ADR 0057, rule 91).
- **Recording.** `SettledPaymentRecorder::record(...)` is the one settlement
  core for provider and manual payments: lock charges in ascending id order
  → check capacity → post Dr settlement / Cr each charge's receivable →
  insert payment and allocations → audit → `payment.settled.v1`.
- **Manual idempotency.** `ManualPaymentRecordingService` uses a
  server-issued key `payments_manual_idempotency_unique (school_id,
  idempotency_key)` plus a per-key advisory lock and a strict replay.
- **Outstanding balance is always derived**, never stored
  (`ManualPaymentRecordingService::outstandingChargesForStudent()`).
- **No receipt exists.** The success page "is not a statutory or formal
  receipt (receipts stay deferred)" (FINANCE.md 0O.11A). No receipt,
  invoice or voucher numbering exists anywhere in Finance, Fees or Payments.

### 1.4 Students and Academic Structure

- **`student_enrollments`.** Status `active|completed|withdrawn|transferred|
  cancelled`, all terminal except `active`. `starts_on`/`ends_on` are
  inclusive; every terminal transition sets `ends_on`. There is at most one
  active enrollment per Student per year
  (`student_enrollments_one_active_per_student_year`). Context unique
  `(id, school_id, academic_year_id, campus_id, grade_level_id,
  section_id)`. There is no admission-date column.
- **Read services** (authorization-neutral):
  - `StudentEnrollmentReadService` (`currentFor`, `historyFor`, `detail`,
    `directory`);
  - `StudentEnrollmentRosterReadService::membersAsOf(...)` (per **Section**,
    temporal predicate `starts_on <= d AND (ends_on IS NULL OR ends_on >=
    d)`).
  - **No read by AcademicYear × GradeLevel (× Campus) exists**, and Students
    emits **no** outbox events.
- **Academic Structure.**
  - `AcademicYear`: `draft|active|closed`, one active per School.
  - `AcademicTerm`: dated, sequenced, **no status**.
  - `GradeLevel` is School-wide with a `sequence` and a status.
  - `Section` belongs to one year, campus and grade.
  - `CurrentAcademicYearResolver` is the only published structure read
    service.

### 1.5 Conventions reused

- **Precedents.**
  - `EmployeeNumberAllocator`: a per-School counter row locked `FOR UPDATE`
    inside the caller's transaction, gap-free because a rollback also rolls
    back the increment. This is the only numbering precedent.
  - Enrollment rollover plans: `draft → validated → executing →
    completed|completed_with_errors|cancelled`, `configuration_version`
    drift detection, per-item uniqueness and status, a conditional-UPDATE
    claim, bounded batches. This is the run precedent; it is not queued.
  - Payroll run approval: `payroll_runs_sod_check`, conditional
    `WHERE status=...` updates.
  - Communications approval requests.
- **Primitives.** `TenantLock::forSchool()`, `pg_advisory_xact_lock
  (hashtextextended(...))`, the `TenantScoped` job trait with `$tries` and
  `$timeout` (queue `retry_after` is 90), `SchoolOperationalGuard`,
  `NormalizesCode`/`NormalizesCodeInput`, `TenantRls::enable`/
  `revokeDelete`/`makeAppendOnly`.
- **Naming.** Audit `AuditRecorder::school(...)` with names like
  `charge.assessed`. Outbox types are `<aggregate>.<past_verb>.v1`, and none
  is webhook-registered.
- **Capabilities** today: `finance.ledger.view|post|reverse`,
  `finance.charges.view|manage`, `finance.payments.view|record`. All are
  granted to `school_admin` and the demo `demo.finance_officer`; **`principal`
  holds no Finance capability**.

### 1.6 Absent today (none of this exists)

- fee heads, catalogue or templates;
- fee structures, lines or instalments;
- bulk or enrolment-based assessment;
- any assessment idempotency;
- optional-fee selection;
- concessions, scholarships, waivers or any adjustment;
- late fees;
- receipts or receipt numbering;
- student fee statements;
- ledger-account administration;
- a financial-year or accounting-period concept;
- GST or tax handling.

An idea found only in a document (for example FINANCE.md's "Every School
gets the same seeded set of core accounts", which the as-built record
contradicts) is not treated as an existing requirement.

## 2. Decision summary

- **Charges stay the receivable unit (§4).** FEE adds configuration (fee
  heads, structures, instalments), generation (assessment runs), adjustments
  (concessions) and evidence (receipts) around charges. It never edits or
  reinterprets an existing charge or payment.
- **Idempotency is a database key per billing period (§11).** Every
  structure-generated charge is recorded in `fee_assessments` under a unique
  key over `(school, student, academic year, fee head, billing period)`,
  among un-voided rows. Repeated or concurrent runs, and amended successor
  structures, cannot double-bill.
- **Concessions are approved decisions (§14).** They produce explicit
  `fee_adjustments`: their own journal entries, cancelled only by reversal.
  A charge amount is never changed.
- **Balance-dependent work is owned by Payments (§3).** Outstanding
  balances, statements, receipts and late-fee evaluation all depend on
  `payment_allocations`. Payments already depends on Fees, so no reverse
  dependency is created.
- **Receipts are issued atomically (§17).** Receipt numbers come from a
  per-School counter row locked inside the settlement transaction, so they
  are gap-free by construction. There is never a `MAX()+1`.

### 2.1 Decision register

Status values:
- **DECIDED** — by this ADR, from repository evidence or engineering
  necessity.
- **OWNER DECISION REQUIRED** — the recommendation shown is not adopted
  until the owner confirms it.
- **LEGAL REVIEW REQUIRED** — needs a qualified legal answer.

| # | Decision | Status | Recommendation | Needed for |
|---|---|---|---|---|
| A | Invoice entity | **DECIDED — owner confirmed 2026-09-29** | No `invoices` entity; charges + receipts + statements (§4) | FEE.1 |
| B | Structure scope | **DECIDED — owner confirmed 2026-09-29** | AcademicYear × GradeLevel, optional Campus override; no Section-specific structures (§7.1) | FEE.1 |
| C | Frequencies | **DECIDED — owner confirmed 2026-09-29** | Stored instalments are authoritative; `one_time`/`term`/`monthly` are authoring generators; "optional" is a separate flag (§7.3) | FEE.1 |
| D | Mid-year admission | **DECIDED — owner confirmed D1, 2026-09-29** | No proration in v1; skip instalments whose billing period ended before `starts_on`; current and future periods assessed in full; staff may exclude items at preview (§10.2) | FEE.2 |
| E | Optional fees | **DECIDED — owner confirmed 2026-09-29** | Explicit per-Student selection before assessment (§8) | FEE.1 (schema), FEE.2 |
| F | Concession approval | **DECIDED — owner confirmed 2026-09-29** | Maker/checker mandatory for every concession, database-enforced; no amount thresholds in v1 (§14.4) | FEE.3 |
| F2 | Concession posting account | **DECIDED — owner confirmed 2026-09-29** | One School-level `expense` account "Fee concessions and scholarships" (§14.5) | FEE.3 |
| G | Concession after payment | **DECIDED — owner confirmed G1, 2026-09-29** | Capped at the charge's current outstanding; never creates credit or refund (§14.6) | FEE.3 |
| H | Late-fee policy | **DECIDED — owner confirmed 2026-09-30** (product decision only; legal: DEVELOPMENT AUTHORISED — PROD LEGAL SIGN-OFF REQUIRED, ADR 0058 E31) | Fixed or percentage-of-outstanding, grace days, optional cap, one late fee per overdue charge per rule; no tiers or recurrence in v1 (§16) | FEE.5 |
| I | Receipt numbering | **DECIDED — owner confirmed 2026-09-30** | School + financial year, FY start month configurable (default April), format `<PREFIX>/<FY>/<000001>` (§17.2) | FEE.4 |
| I2 | Existing payments | **DECIDED — owner confirmed 2026-09-30** | Explicit, audited, idempotent one-time backfill in `(settled_at, id)` order (§17.4) | FEE.4 |
| J | Receipt statutory/GST form | **LEGAL REVIEW REQUIRED — DEVELOPMENT AUTHORISED, PROD LEGAL SIGN-OFF REQUIRED (owner, 2026-09-30)** | FEE.4 ships a payment acknowledgement only, with no tax fields (§17.5) | FEE.4 |
| K | Ledger-account provisioning (finding F1) | **DECIDED — owner confirmed K1, 2026-09-29** | FEE.1 includes minimal Finance-owned account administration (§6) | FEE.1 |
| L | Role grants | **DECIDED — owner confirmed 2026-09-29** | `school_admin` gets all; `principal` gets none by default; demo finance officer gets all except approve (§19) | FEE.1 |
| M | Concession categories and notes | **DECIDED — owner confirmed 2026-09-29** | Closed catalogue `concession`, `scholarship`, `waiver`; no free-text note (§14.2) | FEE.3 |
| N | Percentage rounding | DECIDED | `Money::multiplyByRate`, 2 dp, half away from zero; never exceeds the base (§14.3) | FEE.3/5 |

## 3. Module ownership and dependency directions

The dependency directions in `docs/architecture/DOMAIN-MAP.md` do not
change.

| Concern | Owner | Why |
|---|---|---|
| Fee heads, structures, lines, instalments, optional selections, late-fee rules (configuration) | **Fees** | Receivable configuration; Fees already owns charges |
| Assessment runs, `fee_assessments`, `fee_concessions`, `fee_adjustments` | **Fees** | Create or adjust charges; need only Students, Academic Structure and Finance |
| Allocation capacity (charge − posted adjustments ≥ allocations) | **Payments** (database trigger) | Payments already owns `payments_lock_and_validate_charge_allocation()` and `charges_payment_allocation_guard_trigger`; Payments → Fees is the legal direction |
| Receipts and receipt numbering | **Payments** | Evidence of a settled Payment, issued inside `SettledPaymentRecorder` |
| Staff student fee statement | **Payments** (read-only composition) | Needs charges, adjustments and allocations; Payments may read Fees through Fees' Application layer |
| Late-fee evaluation and late-fee runs | **Payments** | "Overdue" needs outstanding balance (allocations). Payments assesses the late-fee charge through `ChargeService` |
| Ledger-account administration (K) | **Finance** | Finance owns the chart of accounts |

**Rule 4 holds throughout.**
- Fees never reads `payment_allocations`.
- Payments never reads Fees' Eloquent models; it uses published Fees
  Application methods, extended as §15 and §18 describe.
- Fees reads enrollments only through a new Students-owned read method
  (§10.1).

## 4. Invoice decision (A)

**Decision:** there is no `invoices` entity. The receivable unit remains the
charge.
- One structure-generated charge = one Student × one fee head × one billing
  period.
- The parent-facing grouping is the **statement** (§18).
- The evidence of money received is the **receipt** (§17).

**Evidence.** 0G.4 resolved this question (FINANCE.md 0G.4 "Scope resolution
…": "No `invoices`/`invoice_lines` entity — `charges` alone is the
receivables unit … not rejected forever"). Canteen and manual payments
already depend on charge-level allocation.

| | Charges + statement + receipt (chosen) | Distinct Invoice entity |
|---|---|---|
| Existing data | Unchanged | Every existing charge needs an invoice or a nullable link |
| Allocation | Unchanged (payment → charge) | Either allocate to invoices (rewrites ADR 0031) or keep charges and add a redundant layer |
| Numbering | Receipts only | Invoice numbers too, which pulls in the statutory tax-invoice question (§17.5) |
| Parent view | Statement groups charges by year and period | Invoice document per period |
| Cost | Low | High; touches Payments, Canteen and history |

An invoice-style *document* (a demand notice listing one period's charges)
can be added later as a **rendering** of the statement without a new
financial entity. The owner may override A. That would need an ADR 0031
amendment and is not recommended.

## 5. FeeHead

`fee_heads` is a School-owned reference entity, for example Tuition,
Admission, Examination, Laboratory, Transport, or the late-fee head (§16).

| Column | Rule |
|---|---|
| `id` | UUIDv7 |
| `school_id` | `BelongsToSchool`; RLS |
| `code` | `NormalizesCode` (uppercase on assign) + `NormalizesCodeInput`; unique `(school_id, code)` (rule 74) |
| `name` | Display name, required, ≤ 120 characters |
| `description` | Optional, ≤ 500 characters, operational text only (never Student data) |
| `status` | `active|inactive` (CHECK); **deactivate, never delete** (rule 73); `TenantRls::revokeDelete` |
| `receivable_ledger_account_id` | Composite FK `(id, school_id, currency)` → `ledger_accounts`; must be an **active `asset`** account |
| `revenue_ledger_account_id` | Same FK shape; must be an **active `income`** account |
| `currency` | `'INR'` (CHECK); pins both FKs |
| timestamps | |

- **CHECK:** receivable ≠ revenue.
- **Account-type validation** follows the Canteen precedent. It runs in the
  Application layer at create/update through Finance's
  `LedgerService::activeAccountsOfType()`, and **again at every assessment
  execution**, where an inactive or wrong-type account fails that item
  closed. FINANCE.md left type validation open for ad-hoc charges; for fee
  heads it is decided here because a head is reusable configuration.
- **Mapping changes are allowed and audited** (before/after account ids).
  Charges snapshot their account ids at assessment, so history never moves.
- **No tax classification column.** Any GST/HSN/SAC attribute waits for
  legal approval (§17.5, §27).
- **Deactivation:** an inactive head cannot be added to a draft structure
  and fails an active structure's future assessment items closed. It never
  touches existing charges.

## 6. Ledger-account prerequisite (K — finding F1)

FEE.1 is unusable in production without ledger accounts. Options:

| Option | Scope | Assessment |
|---|---|---|
| **K1 (recommended)** | FEE.1 adds minimal **Finance-owned** administration: create an account (code, name, type from the existing CHECK), activate/deactivate, no delete, no type change after first posting. New capability `finance.accounts.manage`; view stays `finance.ledger.view` | Smallest safe path; Finance keeps ownership; audited `ledger_account.created`/`.status_changed` |
| K2 | A separate Finance checkpoint before FEE.1 | Same content, one more unit |
| K3 | Console/seeder-only provisioning | Rejected: operators would need shell access for routine accounting setup |

Not in any option:
- a School-configurable chart-of-accounts *template*;
- account hierarchy;
- accounting periods;
- `LedgerService::post()` refusing inactive accounts. That stays FINANCE.md's
  deferred decision; FEE validates status itself (§5).

## 7. Fee structures, lines and instalments (B, C)

### 7.1 FeeStructure

`fee_structures` is School-owned.

| Column | Rule |
|---|---|
| `academic_year_id` | Composite FK `(id, school_id)` → `academic_years` |
| `grade_level_id` | Composite FK → `grade_levels` |
| `campus_id` | Nullable. NULL = School-wide default for that grade; set = a campus override (composite FK → `campuses`) |
| `code`, `name` | `NormalizesCode`; unique `(school_id, academic_year_id, code)` |
| `status` | `draft → active → retired` only (CHECK plus a transition trigger); never back to `draft` |
| `supersedes_fee_structure_id` | Nullable composite self-FK; the amendment chain |
| `activated_at`/`activated_by_user_id`, `retired_at`/`retired_by_user_id` | Provenance |

- **One active structure per scope** by construction: a partial unique index
  on `(school_id, academic_year_id, grade_level_id, COALESCE(campus_id,
  '00000000-0000-0000-0000-000000000000'::uuid)) WHERE status = 'active'`.
- **Resolution** for an enrollment `(year, campus, grade)`: the active
  campus-specific structure if one exists, otherwise the active School-wide
  default for that grade, otherwise none. No structure means the item is
  excluded (`no_structure`) at preview.
- **No Section-specific structures (B).** A Section is a teaching group, and
  a fee that differs by Section would bill siblings in the same grade
  differently for the same service. The owner may request them. That would
  add a nullable `section_id` and change resolution, not the key in §11.

### 7.2 Lines

`fee_structure_lines` has one row per fee head in a structure.

| Column | Rule |
|---|---|
| `fee_structure_id` | Composite FK |
| `fee_head_id` | Composite FK; unique `(fee_structure_id, fee_head_id)` |
| `is_optional` | Boolean (E, §8) |
| `amount` | Total for the year: `NUMERIC(14,2)`, `> 0`; `currency = 'INR'` |
| `frequency` | `one_time|term|monthly|custom`, **descriptive only** (C) |

### 7.3 Instalments

`fee_structure_installments` is the authoritative billing schedule.

| Column | Rule |
|---|---|
| `fee_structure_line_id` | Composite FK |
| `sequence` | Unique per line |
| `label` | For example "Term 1", "July 2026" |
| `billing_period_key` | Normalized code, for example `ANNUAL`, `T1`, `2026-07`; unique per line; **the idempotency period (§11)** |
| `period_starts_on`, `period_ends_on` | Inclusive; must lie inside the AcademicYear; `starts ≤ ends` |
| `due_date` | Must be `≥ period_starts_on`; copied to the charge |
| `academic_term_id` | Nullable composite FK; informational for `term` lines |
| `amount` | `NUMERIC(14,2)`, `> 0` |

- **Frequency (C).** The generators (`one_time` = one instalment,
  `term` = one per AcademicTerm, `monthly` = one per calendar month of the
  year) only *propose* instalment rows in the draft editor. Staff may edit
  them. The stored rows are the truth.
- **Sum rule.** The instalment amounts of a line must equal the line amount.
  This is checked at activation by the service **and** by a database guard
  that refuses `draft → active` when any line's instalment sum differs.

### 7.4 Immutability and amendment

- Lines and instalments are editable **only while the parent is `draft`**,
  enforced by a trigger that rejects INSERT/UPDATE/DELETE of children of a
  non-draft structure (the payroll-results precedent).
- **Amendment = successor.** Copy to a new `draft` with
  `supersedes_fee_structure_id`, edit it, then activate it. Activation
  retires the predecessor **in the same transaction**; a unique-violation
  race becomes a typed conflict (the AcademicYear-activation precedent).
- **Nothing already assessed changes.** A Student already charged for
  `(head, period)` under the predecessor is **not** re-charged by the
  successor, because the §11 key is period-based, not instalment-based.
  Correcting such a Student is explicit: a concession (§14) or cancel and
  re-assess (§11.3).
- `retired` structures cannot be assessed. They remain readable forever.

## 8. Optional fees (E)

A line with `is_optional = true` is assessed **only** for Students with an
active selection. Recommended; the owner confirms.

- **Table.** `fee_optional_selections`: `student_id`,
  `fee_structure_line_id`, `academic_year_id`, `status active|withdrawn`,
  `selected_by_user_id`, `withdrawn_by_user_id`, timestamps.
- **Invariant.** Partial unique `(school_id, student_id,
  fee_structure_line_id) WHERE status = 'active'`.
- **History.** A withdrawal affects only *future* assessment and never
  cancels an existing charge.
- **Why explicit.** Inferring selection from another module (Transport
  assignment, Hostel residency) is the future **OPF** programme, which will
  create selections through this same service. FEE never reads those
  modules.

## 9. Assessment runs

### 9.1 Scope of a run

One run = one active FeeStructure × one of its instalment periods
(`billing_period_key`), covering every line of the structure that has that
period.

The UI may create many runs at once (for example "Term 1 for every grade"),
but each run is independent. There is **one open run per (structure,
period)**, enforced by a partial unique index on `(school_id,
fee_structure_id, billing_period_key) WHERE status IN
('draft','previewed','executing')`.

### 9.2 Lifecycle

This follows the rollover precedent.

```
draft ──preview──► previewed ──execute──► executing ──► completed
  ▲                    │                     │      └──► completed_with_errors
  └──── drift ─────────┘                     └──(resumable while executing)
draft|previewed ──cancel──► cancelled
```

- **`draft`.** Created with `configuration_version = 1`; nothing is computed.
- **`preview`** computes and persists one `fee_assessment_run_items` row per
  (target enrollment × line). Each item records:
  - `preview_result`: `ready`, `already_assessed`, `excluded`, `blocked`;
  - `reason` (closed catalogue: `no_structure`, `optional_not_selected`,
    `period_before_enrollment`, `enrollment_cancelled`, `student_inactive`,
    `head_inactive`, `account_invalid`, `staff_excluded`);
  - the computed gross amount, concession adjustments (FEE.3+) and net.

  Preview also stores `previewed_configuration_version` and totals (counts
  and amounts), then sets `previewed`.
- **Staff exclusion.** Staff may mark `ready` items `staff_excluded` before
  execution. This bumps `configuration_version` and returns the run to
  `draft`, so a fresh preview is required. That is the drift rule.
- **`execute`.** A claim moves `previewed → executing` by a conditional
  `UPDATE … WHERE status = 'previewed' AND configuration_version =
  previewed_configuration_version`. Zero rows means a typed conflict.
- **Per item,** in its own transaction (§12):
  - `execution_status`: `pending → succeeded | skipped_already_assessed |
    failed`;
  - `failure_reason` from a closed catalogue;
  - `fee_assessment_id` on success.
- **Finalize** re-counts under the run lock and sets `completed` (no
  failures) or `completed_with_errors`. Failed items are reported, never
  silently retried into a different outcome.
- **Terminal.** `completed`, `completed_with_errors` and `cancelled` are
  terminal. A new run for the same (structure, period) may be opened
  afterwards; items already assessed simply preview as `already_assessed`.
- **Provenance.** `created_by`, `previewed_by`, `executed_by` and the
  matching timestamps are stored. Runs are never deleted
  (`TenantRls::revokeDelete`); items become immutable once the run is
  terminal (trigger).

## 10. Target population and mid-year admission

### 10.1 Target population (decided)

- **Source.** A new Students-owned read method,
  `StudentEnrollmentFeeTargetReadService::qualifyingForGradeAsOf(School,
  academicYearId, gradeLevelId, ?campusId, asOfDate)`, is
  authorization-neutral like its siblings. It returns DTOs (`enrollmentId`,
  `studentId`, `campusId`, `sectionId`, `startsOn`, `endsOn`, `status`,
  `studentIsActive`). Fees never queries `student_enrollments` or
  `students` (rule 4).
- **Predicate.** Same School, year and grade (and campus when the structure
  is a campus override; a School-wide default run excludes campuses that
  have their own active override). Temporal qualification uses the roster
  predicate as of the **reference date**: `starts_on ≤ ref AND (ends_on IS
  NULL OR ends_on ≥ ref)`.
- **Reference date.** `max(period_starts_on, the run's as-of date)`, with the
  as-of date defaulting to the execution date.
- **Excluded:**
  - `status = 'cancelled'` enrollments regardless of dates (`cancelled` means
    the enrollment should not have continued; billing it would be wrong),
    reason `enrollment_cancelled`;
  - Students whose `students.status = 'inactive'`, reason
    `student_inactive`. Staff can still bill them with an ad-hoc charge.
- **Transfers.** A same-year transfer creates a new enrollment. The §11 key
  is per Student, so a transfer between campuses with different structures
  can never bill the same `(head, period)` twice.

### 10.2 Mid-year admission (D — DECIDED: D1, owner confirmed 2026-09-29)

| Option | Behaviour | Assessment |
|---|---|---|
| **D1 (recommended)** | Instalments whose `period_ends_on < enrollment.starts_on` are excluded (`period_before_enrollment`). The period containing `starts_on` and all later ones are assessed **in full**. Staff may exclude items at preview | Deterministic; no rounding; matches common school practice; reversible by concession |
| D2 | Prorate the current period by days | Needs a rounding and day-count rule per head; hard to explain on a receipt; pulls in legal fee-regulation questions |
| D3 | Staff select instalments per Student | Correct but laborious; a special case of D1's preview exclusion |
| D4 | School-configured policy per structure (D1/D2) | Most flexible; the largest FEE.2 |

**Decided (owner, 2026-09-29): D1.** The rules FEE.2 implements are in
"Owner decision D for FEE.2" at the end of this ADR. D2 (proration), D3
and D4 are not adopted. This paragraph originally read: "Until D is
decided, FEE.2 cannot freeze its preview rules. FEE.1 is unaffected.".

## 11. Assessment idempotency (by construction)

### 11.1 The invariant

`fee_assessments` is a Fees-owned, append-only-except-void link table:
- the key columns: `student_id`, `academic_year_id`, `fee_head_id`,
  `billing_period_key`;
- `fee_structure_installment_id`, `student_enrollment_id`,
  `fee_assessment_run_id` (nullable for a future single-Student path);
- `charge_id`, NOT NULL, unique, composite FK `(charge_id, school_id)` →
  `charges`;
- `voided_at`, `void_reason`.

```
UNIQUE (school_id, student_id, academic_year_id, fee_head_id, billing_period_key)
  WHERE voided_at IS NULL          -- fee_assessments_one_live_per_period
```

This is **stronger** than `(school_id, installment_id, student_id)`. It also
blocks double billing across successor structures, campus overrides and
transfers.

### 11.2 Why it is idempotent by construction

In each item transaction, `ChargeService::assess()` (ledger post + charge
insert) and the `fee_assessments` insert happen together. If a concurrent or
repeated attempt already holds the key, the insert raises
`UniqueConstraintViolationException`. The whole item transaction rolls back,
including the journal entry and the charge, and the item is recorded as
`skipped_already_assessed` in a fresh transaction.

There is **no check-then-insert** (rule 30). The preview's
`already_assessed` result is advisory only.

### 11.3 Voiding (cancel and re-assess)

Cancelling a structure-generated charge goes through a Fees path that, in
**one transaction**:
1. cancels the charge (`ChargeService::cancel`, still refused when
   allocations exist); and
2. sets `fee_assessments.voided_at`.

This frees the period key for a deliberate re-assessment. A direct
`ChargeService::cancel()` of a linked charge without voiding is refused by a
database guard: a charge with a live `fee_assessments` row may be cancelled
only when that row is voided in the same transaction, checked by a deferred
constraint trigger.

### 11.4 Late fees

Late fees use their own key (§16.3).

## 12. Concurrency

- **Run level.**
  - The claim is a conditional UPDATE (§9.2).
  - One open run per (structure, period) is enforced by a partial unique
    index.
  - Structure activation, retirement and succession lock the structure row
    `FOR UPDATE`.
  - Execution re-checks under `FOR SHARE` that the structure is still
    `active` in each item transaction, so a concurrent retirement stops new
    items and never produces half-applied items.
- **Item level.**
  - `pg_advisory_xact_lock(hashtextextended('fees.assessment:' ||
    school || ':' || student || ':' || year || ':' || head || ':' || period,
    0))` serializes identical keys cheaply. The partial unique index remains
    the **only** authority.
  - The enrollment is re-read under `FOR SHARE` through a Students-owned
    method, so a concurrent withdraw/transfer/cancel either commits first
    (and the item re-evaluates its temporal qualification) or waits.
  - Charges from different Students never contend.
- **Lock order** is always: run row → structure row (share) → advisory key
  → enrollment (share) → ledger (inside `LedgerService`). Concession grants
  are read-only in this transaction.
- **`TenantLock`** (a Redis lock) is **not** used for correctness. It may
  only throttle a second executor.
- **Required proofs** (real PostgreSQL, separate OS processes, forced overlap
  as in the existing race tests; never SQLite):
  1. the same run executed twice concurrently;
  2. two different runs whose targets overlap (default and campus override;
     predecessor and successor);
  3. a retry after a failure partway through a batch;
  4. an enrollment withdraw/transfer/cancel racing item execution;
  5. structure retirement racing execution;
  6. (FEE.3) concession approval racing a payment allocation on the same
     charge;
  7. (FEE.4) two settlements issuing receipts concurrently: gap-free and
     unique;
  8. (FEE.5) a late-fee run executed twice concurrently.

## 13. Execution and queue (decided)

- **Queued, bounded batches.** `ExecuteFeeAssessmentRunJob`:
  - uses `TenantScoped`;
  - `$tries = 1` (the run owns its own resumption; rule 59 forbids stacking
    queue retries on domain retry);
  - `$timeout = 60`, below `retry_after = 90` (rule 58);
  - processes at most 100 pending items, each in its own transaction, then
    re-dispatches itself while pending items remain.

  A crashed or timed-out job leaves items `pending`. The authorized "resume"
  action (and each re-dispatch) only ever selects `pending` items, and
  §11.2 makes any duplicate attempt harmless.
- **School lifecycle (rule 86).** Each batch calls
  `SchoolOperationalGuard::requireOperational()` inside its transaction. A
  suspended School **pauses**: the run stays `executing`, no items change,
  and it is resumable after reactivation. `SchoolLifecycleArchitectureGuardTest`
  must list the job with this behaviour.
- **Durability.** The per-item audit and outbox writes happen in the item's
  transaction (§20). The run-completion event is written in the finalize
  transaction.
- The same job shape serves late-fee runs (§16), which are owned by Payments.

## 14. Concessions, scholarships and waivers (F, F2, G, M)

### 14.1 Model

- **`fee_concessions`** is the approvable decision. Its scope is either:
  - **standing:** `student_id` + `academic_year_id` + `fee_head_id`
    (nullable = every head) + `valid_from`/`valid_to` (inclusive, inside the
    year), applied at assessment time; or
  - **targeted:** one `charge_id`, applied at approval.

  A CHECK makes the two scopes mutually exclusive. The value is `kind fixed`
  (`NUMERIC(14,2) > 0`) or `kind percentage` (`NUMERIC(5,2)`, `0 <
  value ≤ 100`). Targeted concessions are `fixed` only.
- **`fee_adjustments`** is the financial fact:
  - `charge_id`, `fee_concession_id`, `amount > 0`, INR;
  - `debit_ledger_account_id` (the concession account, §14.5) and the
    credited account = the charge's receivable account;
  - `journal_entry_id` NOT NULL;
  - the cancellation pair `cancelled_at`/`cancellation_journal_entry_id`,
    with the same immutability trigger semantics as `charges` (§1.1);
  - `TenantRls::revokeDelete`.
- **Posting.** Each adjustment posts Dr concession account / Cr receivable
  through `LedgerService::post()`. Cancelling one reverses that entry
  (`reverseById`). A charge's amount is **never** changed.

### 14.2 Categories and notes (M — DECIDED, owner confirmed 2026-09-29)

- **Category** comes from a closed catalogue: `concession`, `scholarship`,
  `waiver` (recommended). Sibling, staff-ward and RTE labels are **not**
  added without the owner and the legal answer in §27.
- **No free-text note** (recommended). A reason can reveal a family's
  financial or personal circumstances (§21). If the owner wants notes, they
  need a classification decision first.

### 14.3 Amounts (N, decided)

- **Percentage.** `Money::multiplyByRate(value/100, 2)` of the charge amount
  (half away from zero), capped at the charge amount.
- **Fixed.** The fixed value, capped the same way.
- **Several concessions on one charge.** They apply in `approved_at`, then
  `id`, order, each capped at what remains. Total adjustments can never
  exceed the charge amount (database guard, §15).

### 14.4 Lifecycle and approval (F — DECIDED, owner confirmed 2026-09-29)

```
pending ──approve──► approved ──revoke (standing only)──► revoked
   ├──reject──► rejected
   └──withdraw (requester)──► withdrawn
```

- **Recommended rule.** Every concession needs a second person:
  - `CHECK (decided_by_user_id IS NULL OR decided_by_user_id <>
    requested_by_user_id)` (the payroll SoD precedent), plus a typed
    `SelfApprovalNotAllowedException`;
  - capability `finance.fee_concessions.approve`;
  - no amount or percentage thresholds in v1. The owner may ask for
    thresholds; they would add a School-level setting and a second
    approval stage.
- **Decisions** are conditional `UPDATE … WHERE status = 'pending'`
  statements. Approving a **targeted** concession posts its adjustment in
  the same transaction.
- **Revoking** a standing concession stops *future* application only. Posted
  adjustments stay; undoing one is an explicit adjustment cancellation.
- **Requests** carry a server-issued idempotency key (the manual-payment
  precedent): unique `(school_id, idempotency_key)` with a strict replay.
- **Standing application.** When an assessment item creates a charge for a
  Student with an approved standing concession covering that head and the
  instalment's `period_starts_on`, the adjustment is posted **in the same
  item transaction**. Preview shows gross, adjustment and net.

### 14.5 Posting account (F2 — DECIDED, owner confirmed 2026-09-29: one School-level `expense` account)

The recommendation is one School-level Fees setting (a singleton
`fee_settings` row, following the `canteen_billing_configurations`
precedent) naming an **active `expense`** account.

Contra-income (an `income` account debited) is the alternative. Either way
the ledger stays balanced, and the choice changes only the reporting
presentation, so the accountant decides.

### 14.6 Concession after payment (G — DECIDED: G1, owner confirmed 2026-09-29)

| Option | Behaviour |
|---|---|
| **G1 (recommended)** | A targeted concession's amount, and each standing application, is capped at the charge's **current outstanding** (amount − allocations − posted adjustments), computed under the charge row lock. A fully paid charge **refuses** a concession (`ChargeFullyPaidException`). No credit balance, no refund |
| G2 | Allow a credit exceeding outstanding into a Student credit balance | Needs the unapplied-cash/credit model FINANCE.md deferred, a new liability account type, and Payments changes |
| G3 | Refund the excess | Out of scope; refunds are deferred (ADR 0057; rule 91) |

History is never mutated under any option.

### 14.7 Charge cancellation with adjustments

Cancelling a charge that has live (uncancelled) adjustments is **refused**
(database guard, `ChargeHasActiveAdjustmentsException`). Staff cancel the
adjustments first, each as its own reversal.

## 15. The Payments capacity seam (ADR 0031 amendment, FEE.3)

Today `payments_lock_and_validate_charge_allocation()` enforces
`SUM(allocations) ≤ charges.amount`. FEE.3 replaces the rule, **inside a
Payments-owned migration**, with:

```
SUM(allocations) + SUM(uncancelled fee_adjustments) ≤ charges.amount
```

- **Enforcement.** The rule is enforced at insert of **either** an
  allocation or an adjustment. Both paths first lock the charge row `FOR
  UPDATE`, which the existing trigger already does, so the two serialize.
- **Why Payments owns it.** Payments → Fees is the permitted direction, and
  Payments already owns the charge-side guard trigger.
- **Application layer.**
  - `ChargeAllocationSnapshot` gains the posted-adjustment total, so
    `SettledPaymentRecorder`'s capacity pre-check and the manual-payment
    UI's "outstanding" use net amounts.
  - Fees' adjustment path translates the trigger's error into
    `ChargeFullyPaidException`/`AdjustmentExceedsOutstandingException`.
- **Rollback.** `down()` restores the previous function body verbatim.
- **Proof.** Concurrency proof 6 in §12.

## 16. Late fees (H — DECIDED, owner confirmed 2026-09-30; legal sign-off required for production)

### 16.1 Rules (configuration, Fees-owned)

`fee_late_fee_rules`:

| Column | Rule |
|---|---|
| scope | `fee_structure_id` (composite FK) + optional `fee_head_id` (NULL = every line of the structure) |
| `late_fee_head_id` | A FeeHead whose revenue account receives late fees |
| `grace_days` | Integer `≥ 0` |
| `kind` | `fixed` (amount `> 0`) or `percentage` (of the source charge's outstanding at evaluation, `0 < p ≤ 100`) |
| `max_amount` | Optional cap |
| `status` | `active|inactive` |

- Rules are editable only while inactive and never deleted.
- **Recommended v1:** fixed or percentage, grace days and an optional cap;
  **one late fee per overdue charge per rule**.
- Tiered and recurring (per-month) late fees are owner options. They would
  add a `period_key` to §16.3's key.

### 16.2 Execution (explicit, Payments-owned)

- **A late-fee run** is created and executed by staff with an
  `evaluation_date`. It uses the same lifecycle and job shape as §9 and §13.
  There is **no scheduler or automation dependency** (ADR 0043 tiers are not
  involved).
- **Candidates:** uncancelled structure-generated charges (live
  `fee_assessments` rows, read through a Fees Application method) with
  `due_date + grace_days < evaluation_date` and outstanding `> 0`, computed
  by Payments under the charge lock.
- **The late fee** is a new charge assessed through `ChargeService::assess()`
  on the late-fee head's accounts, `due_date` = evaluation date,
  description `"Late fee: <source label>"`.

### 16.3 Idempotency

`late_fee_assessments` (Payments-owned) holds `source_charge_id`,
`late_fee_rule_id`, `charge_id` (unique), `run_id` and `voided_at`:

```
UNIQUE (school_id, source_charge_id, late_fee_rule_id) WHERE voided_at IS NULL
```

It follows the same insert-rolls-back-everything semantics as §11.2. Voiding
follows §11.3.

**[LEGAL REVIEW REQUIRED]:** whether state fee-regulation law or RTE limits
late fees for some Students (§27). FEE.5 must not start until that is
answered or the owner accepts the risk in writing.

## 17. Receipts (I, I2, J)

### 17.1 What a receipt is

A receipt is **evidence that a Payment was settled**, one per Payment. It is
never a second ledger: it records no new amount and references
`payment_id`, from which it reads amount, date, method, allocations and
Student.

`payment_receipts` (Payments-owned):
- `payment_id` unique, composite FK;
- `receipt_number` (text, unique per School);
- `series_key` (for example `2026-27`);
- `sequence_value` (bigint);
- `issued_at`, `issued_by_user_id` (nullable for provider settlements);
- append-only (`TenantRls::makeAppendOnly`).

### 17.2 Numbering (I — DECIDED, owner confirmed 2026-09-30)

- **Counter.** `payment_receipt_counters (school_id, series_key,
  next_value)`, unique `(school_id, series_key)`. It follows
  `EmployeeNumberAllocator`: `insertOrIgnore` the series row, lock it `FOR
  UPDATE`, read and increment, **inside the settlement transaction**.
- **Gap-free by construction.** A rolled-back settlement rolls back its
  increment. A backstop `unique (school_id, series_key, sequence_value)`
  exists and is never retried silently. Never `MAX(number)+1`, never a
  PostgreSQL `SEQUENCE` (sequences are not transactional and leave gaps).
- **Series (recommended I).** School + financial year. The FY is derived
  from the Payment's `settled_at` date in the School's timezone and a
  School-level `financial_year_start_month` setting (default 4 = April).
  The alternative is a single continuous School-wide series.
- **Format.** `<PREFIX>/<FY>/<6-digit zero-padded sequence>`, prefix default
  `RCPT`. The prefix goes in `fee_settings` and may not change once a
  receipt exists in the current series. The padding is fixed at a minimum
  of six digits and is not a setting (corrected 2026-09-30, see "FEE.4
  pre-implementation corrections" below).
- **Voids.** Payments are immutable and have no void in v1, so receipts have
  no void or cancel either. If a future correction contract (rule 91) adds
  one, it must issue a new, linked document and never renumber.

### 17.3 Issuance

`SettledPaymentRecorder::record()` issues the receipt in the same
transaction for **both** ingresses (manual and a future provider). One
receipt per payment is enforced by the unique `payment_id`. A replayed
manual payment returns the existing receipt.

### 17.4 Existing payments (I2 — DECIDED, owner confirmed 2026-09-30)

Payments settled before FEE.4 have no receipt. The recommendation is an
explicit, audited, idempotent one-time command,
`finance:receipts-backfill {school}`. It issues receipts in `(settled_at,
id)` order, skipping payments that already have one, and records
`payment_receipt.backfilled`. The alternative is to leave legacy payments
without receipts.

### 17.5 Statutory form and GST (J — **LEGAL REVIEW REQUIRED**)

Whether a School must issue a specific statutory receipt or tax invoice,
show a GSTIN, HSN/SAC or tax lines, or treat any fee head as taxable is
**not decided** and must not be invented.

Until a qualified answer is recorded, FEE.4 renders a **payment
acknowledgement** only. It shows School name, receipt number, date,
Student, amount, method, allocations by fee head and period, and no tax
field. It is labelled "Payment receipt", never "Tax invoice".

## 18. Staff student fee statement

- **Owner.** `StudentFeeStatementReadService` in Payments, read-only. It is
  computed on read from authoritative rows with **no stored balance**:
  charges and cancellations, fee head and period from `fee_assessments`,
  adjustments, allocations with their Payments and receipt numbers, and
  outstanding per charge and in total (amount − allocations − adjustments).
- **Scope.** One Student, filterable by AcademicYear.
- **Authorization.** Both `finance.charges.view` **and**
  `finance.payments.view`; no new capability.
- **Audit.** `fee_statement.viewed` (a read audit, following the
  `charge.detail_viewed` precedent).
- **Fees side.** Fees exposes the charge, adjustment and assessment facts
  through Application methods such as
  `ChargeService::statementLinesForStudent()`. Payments composes them.
- **Out of scope.** A printable or exported statement and any
  Guardian/Student-facing view. The portal is the future POR programme.

## 19. Capabilities and roles (L)

All are School-scope `finance.*` keys, following the existing convention
(Fees already uses `finance.charges.*`; there is no `fees.*` namespace).

| Capability | Grants | Checkpoint |
|---|---|---|
| `finance.accounts.manage` | Create and activate/deactivate ledger accounts (K1) | FEE.1 |
| `finance.fee_structures.view` | Read fee heads, structures, rules, optional selections | FEE.1 |
| `finance.fee_structures.manage` | Manage fee heads, draft/activate/retire structures, optional selections, late-fee rules | FEE.1 |
| `finance.fee_assessments.run` | Create, preview, exclude, execute, resume and cancel assessment and late-fee runs | FEE.2 |
| `finance.fee_concessions.view` | Read concessions (Highly Sensitive, §21) | FEE.3 |
| `finance.fee_concessions.request` | Create and withdraw concession requests | FEE.3 |
| `finance.fee_concessions.approve` | Approve, reject, revoke; never one's own request | FEE.3 |

- **Reused, not duplicated:**
  - run results and charges → `finance.charges.view`;
  - receipts → `finance.payments.view`;
  - statements → `finance.charges.view` + `finance.payments.view`.
- **No candidate** `fees.assessment.view`, `fees.receipts.view` or
  `fees.statements.view` is created; each is covered above.
- **Recommended grants (L):**
  - `school_admin`: all;
  - `principal`: none by default (principal holds no Finance capability
    today). The owner may choose `finance.fee_concessions.view` +
    `.approve` for the principal;
  - `demo.finance_officer`: all except `.approve`, so the DDEV demo shows
    maker/checker.
- **Enforcement.** Every mutation checks its capability in the Application
  administrative facade (the `ChargeAdministrationService` pattern). API
  routes add `capability:` middleware. No code branches on a role name
  (rule 24).
- **HTTP idempotency (rule 29):**
  - run execution, concession requests and late-fee execution are
    idempotent by construction (claims, unique keys);
  - request creation uses server-issued keys;
  - no endpoint relies on the generic `idempotent` middleware alone for a
    financial effect (rule 33).

## 20. Audit and events

**Audit** (`AuditRecorder::school`, metadata ids and codes only, never
amounts or names):
- `ledger_account.created`, `ledger_account.status_changed`;
- `fee_head.created`, `.updated` (with before/after account ids),
  `.deactivated`;
- `fee_structure.created`, `.activated`, `.retired`, `.superseded`;
- `fee_optional_selection.created`, `.withdrawn`;
- `fee_assessment_run.created`, `.previewed`, `.item_excluded`,
  `.execution_started`, `.completed`, `.completed_with_errors`,
  `.cancelled`, `.paused`;
- `fee_assessment.voided`;
- `fee_concession.requested`, `.approved`, `.rejected`, `.withdrawn`,
  `.revoked`;
- `fee_adjustment.posted`, `.cancelled`;
- `late_fee_rule.created`, `.status_changed`;
- `late_fee_run.*` (the same set as assessment runs);
- `payment_receipt.issued`, `.backfilled`;
- `fee_statement.viewed`.

The per-charge `charge.assessed` and `journal_entry.posted` audits continue
unchanged.

**Outbox events** (`ShouldBeOutboxed`, payload ids plus currency only,
matching the existing Finance events; **none registered in
`WebhookEventRegistry`**; external publication needs its own reviewed
decision, rule 45/77):

| Class | Type |
|---|---|
| `FeeStructureActivated` | `fee_structure.activated.v1` |
| `FeeAssessmentRunCompleted` | `fee_assessment_run.completed.v1` (also for `completed_with_errors`, with the status) |
| `FeeConcessionApproved` / `FeeConcessionRejected` | `fee_concession.approved.v1` / `fee_concession.rejected.v1` |
| `FeeAdjustmentPosted` / `FeeAdjustmentCancelled` | `fee_adjustment.posted.v1` / `fee_adjustment.cancelled.v1` |
| `LateFeeAssessed` | `late_fee.assessed.v1` |
| `PaymentReceiptIssued` | `payment_receipt.issued.v1` |

`charge.assessed.v1` is still emitted per charge. Per-item events are
emitted inside the item transaction; run events inside the finalize
transaction.

## 21. Classification and privacy

- **Highly Sensitive.** Charges, adjustments, concessions, receipts,
  statements and run items are Student-linked financial data (the
  DATA-CLASSIFICATION "Financial data" and "Children's data" rows).
  Concessions can additionally reveal a family's circumstances.
- **Confidential.** Fee heads, structures, instalments and late-fee rules
  are School operational pricing with no personal data (the Inventory and
  Canteen catalogue precedent). They are still capability-gated.
- **Minimized lists.** List views omit money fields where the Canteen
  precedent does. Run lists show counts and totals only; item lists require
  `finance.charges.view`.
- **Never logged or audited:** amounts, Student names, concession reasons,
  receipt contents.
- **Implementation.** FEE.1–FEE.4 add their rows to
  `docs/security/DATA-CLASSIFICATION.md` when built.
- **Retention.** Governed by the pending E21 legal decision. FEE adds no
  purge.

## 22. Tenancy and RLS

- **Every new table is School-owned:** `school_id` NOT NULL, `BelongsToSchool`
  model, `TenantRls::enable()` (RLS enabled **and forced**; rule 18), UUIDv7
  ids, `unique(id, school_id)` for child FKs.
- **Cross-School references are structurally impossible.** Every reference
  is a composite FK `(x_id, school_id)` (rule 70); references to money
  tables also pin `currency`.
- **Deletes.** `TenantRls::revokeDelete` on every financial or
  configuration table. `makeAppendOnly` on `fee_adjustments` history
  columns and `payment_receipts` where no legitimate UPDATE exists.
- **Jobs** carry the tenant through `TenantScoped` (rules 5, 21, 57).
- **Tests** cover Eloquent-layer and raw-PostgreSQL/RLS cross-School denial,
  and missing tenant context failing closed (rule 28).

## 23. Migration and rollback plan

All migrations are **additive**. No existing column of `charges`,
`payments`, `payment_allocations`, `journal_*` or `ledger_accounts` changes
meaning or data.

| Checkpoint | New migrations (in order) |
|---|---|
| FEE.1 | (K1: none; ledger accounts table exists) → `fee_heads` → `fee_settings` (singleton: concession account nullable until FEE.3; receipt prefix/FY month nullable until FEE.4) → `fee_structures` (+ transition trigger, active partial unique) → `fee_structure_lines` → `fee_structure_installments` (+ draft-only child trigger, activation sum guard) → `fee_optional_selections` |
| FEE.2 | `fee_assessment_runs` (+ open-run partial unique) → `fee_assessment_run_items` (+ terminal immutability trigger) → `fee_assessments` (+ live-key partial unique) → charge-void guard (deferred constraint trigger on `charges` cancellation requiring a voided link) |
| FEE.3 | `fee_concessions` (+ SoD CHECK, scope CHECK, idempotency unique) → `fee_adjustments` (+ immutability trigger, active-adjustment cancellation guard on `charges`) → **Payments-owned** replacement of `payments_lock_and_validate_charge_allocation()` + adjustment-capacity trigger (§15) |
| FEE.4 | `payment_receipt_counters` → `payment_receipts` |
| FEE.5 | `fee_late_fee_rules` (Fees) → `late_fee_runs`, `late_fee_run_items`, `late_fee_assessments` (Payments) |

- Each `down()` drops triggers and functions before tables, disables RLS
  (`TenantRls::disable`) and restores any replaced function body verbatim
  (rule 10).
- Every migration runs on `pgsql_admin` (rule 54).
- Indexes are declared explicitly for every FK and for each run's
  `(school_id, status)` and `(run_id, execution_status)`.

## 24. Explicitly out of scope (FEE.0–FEE.5)

- Payment reversal, refunds, voids and manual-payment correction (rule 91;
  its own correction contract).
- Credit balances, overpayment and unapplied cash.
- The payment gateway, PCI and provider work (ADR 0057).
- Any Guardian/Student portal or statement (future POR).
- Transport fee linkage, Hostel fees and deposits, Library fines and
  admissions application fees (future OPF). OPF creates optional selections
  and structures through FEE's services.
- Tally or any accounting integration.
- GST or tax calculation.
- Government integrations.
- AI.
- Scheduled or automated runs of any kind.
- Invoices (§4).
- Proration unless D2/D4 is chosen.
- Accounting periods or a chart-of-accounts template.
- Printable statements and exports.
- Automation-tier notifications.
- SMS/WhatsApp/email reminders (E17/E18 and provider gates).

## 25. Checkpoint plan

Each checkpoint follows rule 82 (focused tests, gates, DDEV review, commit,
publish) and records its unit number since the last full regression.

| | Migrations | Services | Authorization | API / UI | Audit / events | Key tests and proofs |
|---|---|---|---|---|---|---|
| **FEE.1 Fee heads & structures** (+ K1) | §23 FEE.1 | `LedgerAccountAdministrationService` (Finance); `FeeHeadService`; `FeeStructureService` (draft edit, generators, activate, retire, successor); `FeeOptionalSelectionService`; `FeeStructureReadService` | `finance.accounts.manage`, `finance.fee_structures.view|manage` | `/api/v1/schools/{school}/fee-heads`, `/fee-structures` (+ lines/installments/activate/retire/succeed), `/fee-optional-selections`; Inertia Finance → Fee setup; no DELETE routes | `fee_head.*`, `fee_structure.*`, `ledger_account.*`; `fee_structure.activated.v1` | allow/deny for every action; cross-School Eloquent + RLS; composite-FK refusal; sum guard; draft-only child trigger; one-active race (two processes); code normalization 422; money precision (14,2, no float) |
| **FEE.2 Assessment runs** | §23 FEE.2 | Students `StudentEnrollmentFeeTargetReadService`; `FeeAssessmentRunService` (create, preview, exclude, claim, finalize, resume, cancel); `FeeAssessmentItemExecutor`; `ExecuteFeeAssessmentRunJob`; void path | `finance.fee_assessments.run`; view via `finance.charges.view` | runs API + UI (preview totals, item list, execute, progress, resume) | `fee_assessment_run.*`, `fee_assessment.voided`; per-charge `charge.assessed.v1`; `fee_assessment_run.completed.v1` | idempotency (re-execute, re-run, successor structure, transfer); proofs 1–5 (§12); retry after job kill; suspended-School pause; outbox cardinality; D rules |
| **FEE.3 Concessions** | §23 FEE.3 | `FeeConcessionService` (request/approve/reject/withdraw/revoke); `FeeAdjustmentService` (post/cancel); standing application inside the item executor; Payments trigger amendment; `ChargeAllocationSnapshot` net | `finance.fee_concessions.view|request|approve` | concessions API + UI (request, approval queue, per-charge adjustments) | `fee_concession.*`, `fee_adjustment.*` | SoD CHECK + typed error; G cap; proof 6; cancellation guard; ADR 0031 amendment tests (allocation refused beyond net); rounding (N) |
| **FEE.4 Receipts & statements** | §23 FEE.4 | `ReceiptIssuer` inside `SettledPaymentRecorder`; `StudentFeeStatementReadService`; backfill command (I2) | `finance.payments.view`; statement = charges.view + payments.view | receipt view/print (acknowledgement, §17.5); statement page | `payment_receipt.*`, `fee_statement.viewed`; `payment_receipt.issued.v1` | proof 7 (gap-free, unique under concurrency, rollback leaves no gap); FY series boundary; replay returns same receipt; statement arithmetic equals ledger |
| **FEE.5 Late fees** (after the §27 legal answer) | §23 FEE.5 | `LateFeeRuleService` (Fees); `LateFeeRunService` + job (Payments) | `finance.fee_structures.manage` (rules), `finance.fee_assessments.run` (runs) | rules UI; late-fee run UI | `late_fee_rule.*`, `late_fee_run.*`; `late_fee.assessed.v1` | proof 8; grace boundary; cap; percentage of outstanding; void and re-run |

**Next checkpoint: FEE.1**, once A (confirmation), B, C, E, K and L are
decided. D, F, F2, G, H, I, I2 and M are needed only by their later
checkpoints.

## 26. Test obligations (every FEE checkpoint)

- Allow **and** deny tests for every capability-gated action and route
  (rule 13).
- Cross-School denial at the Eloquent layer **and** the raw
  PostgreSQL/RLS layer (`RawIsolationTest` pattern), plus missing tenant
  context failing closed (rule 28).
- Database invariant tests for every CHECK, trigger, partial unique and
  composite FK, including raw SQL bypass attempts.
- Real-process concurrency tests (§12) using the repository's race-script
  pattern with forced overlap. **No SQLite, no sequential simulation.**
- Idempotency and retry tests: repeated execution, killed job, resumed run,
  replayed request.
- Audit tests (event name, actor, subject, minimized metadata) and outbox
  tests (type, payload, cardinality, same-transaction rollback).
- Money precision tests: `NUMERIC(14,2)`, string amounts, no float anywhere
  in PHP or TypeScript, rounding (N), caps.
- School lifecycle: suspended-School behaviour for every job and command
  (rule 86).
- Architecture guards: Fees never reads `payment_allocations` or
  `student_enrollments` directly; Payments never reads Fees models; no role
  branching.

## 27. Legal questions (LEGAL REVIEW REQUIRED)

1. **Receipt form and GST (J).** Is a statutory receipt format or tax
   invoice required? Are any school fee heads taxable? Which identifiers
   must appear? This blocks any tax field. FEE.4 ships an acknowledgement
   only.
2. **Fee regulation and late fees.** Do applicable state fee-regulation
   laws cap or prohibit late fees, fee increases within a year, or specific
   fee heads? This blocks FEE.5.
3. **RTE and statutory free seats.** Must Students admitted under a
   statutory quota be excluded from specific heads? Should that be modelled
   as an exclusion or a 100% waiver category? This must be answered before
   any RTE-specific category exists; until then, staff use ordinary
   concessions with no statutory label.
4. **Retention** of financial records, concessions and receipts: E21, the
   existing pending decision.

## 28. Documentation drift recorded (not fixed here)

**Finance-related** (noted in `FINANCE.md` by a dated note, history
unchanged):
- the design-text claim that every School gets seeded core accounts (§1.2
  F1);
- `Money`'s docblock "No subtract()/multiply()" beside `multiplyByRate`;
- ADR 0031's trigger-function names and its `processSettlement()`
  reference, which now lives in `SettledPaymentRecorder::record`;
- the 0G.8 migration inventory missing `2026_10_27_090000`;
- the 0O.11 bullet still saying "corrects mistakes append-only".

**Outside FEE** (separate cleanup, not done here):
- `MASTER-ROADMAP.md` GradeScale "NOT YET PUBLISHED" wording;
- TRANSPORT/HOSTEL/ADMISSIONS saying Finance or Documents are not built;
- DOMAIN-MAP Admissions/0M/Examinations drift;
- `docs/product/README.md` and `docs/modules/README.md` "Empty in Phase
  0A";
- the 0F promise of Student/Guardian event producers (none exist).

## 29. Consequences

- **Gains.** Schools get year/grade fee structures, bulk billing, approved
  concessions, receipts and statements without any change to the meaning of
  existing charges, payments or journal entries.
- **Double billing is prevented structurally.** One live charge per Student
  × head × period, across reruns, amendments and transfers.
- **Payments grows.** It takes a larger read and evidence role (receipts,
  statements, late-fee evaluation) to keep the dependency direction
  one-way. Fees stays free of settlement data.
- **Accepted costs:**
  - a Payments trigger amendment in FEE.3 (an ADR 0031 note at build time);
  - a new Students read method in FEE.2;
  - minimal ledger-account administration in FEE.1 (K1).
- **Deferred items remain deferred** and are listed in §24: refunds,
  credits, gateway, portal, OPF integrations and taxes.

## Owner decisions for FEE.1 (2026-09-29)

The owner confirmed the FEE.1 decisions in §2.1 exactly as recommended:
- **A.** No invoice entity. Charges, receipts and statements remain the
  financial model (§4).
- **B.** AcademicYear × GradeLevel with an optional Campus override. No
  Section-specific structures (§7.1).
- **C.** Stored instalment rows are authoritative. `one_time`, `term` and
  `monthly` are authoring generators only; "optional" is a separate flag
  (§7.3).
- **E.** An optional fee is assessed only for Students with an explicit
  selection (§8).
- **K.** K1: FEE.1 includes minimal Finance-owned ledger-account
  administration (§6).
- **L.** `school_admin` receives the FEE.1 capabilities by default;
  `principal` receives none. The demo finance officer keeps its documented
  separation (all Finance capabilities except concession approval, §19).

D, F, F2, G, H, I, I2 and M remain OWNER DECISION REQUIRED for their later
checkpoints.

## Implementation note — FEE.1 as built (2026-09-29)

FEE.1 implements §5–§8 and §6 (K1) as contracted. The full as-built record
is in `docs/modules/FINANCE.md` ("FEE.1 as-built"). These refinements
amend the contract text above:

- **§8 selection identity.** A selection is unique per Student x
  AcademicYear x **fee head** (`fee_optional_selections_one_active`), not
  per line. The line is kept as provenance and must be an optional line of
  that head and year. Keying on the line would have silently dropped every
  Student's choice when a successor structure replaced the line ids
  (§7.4).
- **§7.4 audit semantics.**
  - Retirement by a successor's activation writes `fee_structure.superseded`
    (on the predecessor), not `fee_structure.retired`. The latter is
    reserved for an explicit retirement.
  - Draft edits write `fee_structure.updated`.
  - Listing selections writes `fee_optional_selection.list_viewed` (§21's
    Highly Sensitive read audit).
- **Successor after an explicit retirement.** A successor whose
  predecessor was already retired (not superseded) may still activate. It
  simply retires nothing. At most one successor of a predecessor ever
  leaves draft (`fee_structures_one_live_successor`).
- **Draft lines.** The one DELETE route removes a line of a draft
  structure, refused by the database afterwards and while a selection
  references it. Row locks (line FOR UPDATE on removal, FOR SHARE on
  selection) make that check race-free.
- **K1 database scope.**
  - An unposted account's type can still change at the database level, but
    no application path offers a type change.
  - A posted account's type, currency and School are frozen.
  - An account mapped by a fee head cannot change type.
- **Scope checks.** A structure needs a draft or active AcademicYear and an
  active GradeLevel (and Campus, if given). A schedule has 1–24 rows.
- **Concurrency.** Proven with real processes and forced, verified overlap
  (`FeeStructureConcurrencyTest`):
  - two same-scope activations;
  - two successor activations;
  - a raw instalment insert racing an activation, refused by the trigger's
    FOR SHARE parent lock.
- **Not started.** FEE.2 (assessment runs) waits for decision D.

## Owner decision D for FEE.2 (2026-09-29)

The owner confirmed **D1** (§10.2) for FEE.2 assessment eligibility. The
earlier notes that list D as pending were true when written and stay
unchanged.

1. **No proration in v1.** No day-, month-, percentage- or formula-based
   proration exists. Every assessed instalment is charged at its stored
   amount.
2. **Periods that ended before enrollment are skipped.** For a Student
   whose enrollment `starts_on` falls after the AcademicYear begins, an
   instalment whose `period_ends_on` is before that `starts_on` is not
   assessed. The preview reason is `period_before_enrollment`. No
   backdated charge is ever generated for such a period.
3. **Joining during a period means the full instalment.** If `starts_on`
   falls inside an instalment's billing period, that instalment is
   assessed in full.
4. **Later instalments are assessed in full.**
5. **Preview before execution.** Every run's preview shows the resulting
   items (§9.2) before anything is assessed.
6. **Explicit staff exclusion.** Staff holding `finance.fee_assessments.run`
   may exclude an individual preview item for an operational exception.
   The exclusion:
   - is explicit run state and provenance: the item's `staff_excluded`
     reason, the actor and the time, plus an audit event;
   - returns the run to `draft` and requires a fresh preview (§9.2);
   - never alters the Fee Structure or its instalments;
   - never creates a prorated or reduced amount.
7. **Scope.** D decides FEE.2 eligibility only. It does not decide
   concessions, credits, refunds or adjustments; those remain F, F2, G and
   the deferred refund/correction contract.

**Still OWNER DECISION REQUIRED:** F, F2, G, H, I, I2 and M, each for its
own later checkpoint.

**Still [LEGAL REVIEW REQUIRED]** (§27), unchanged by D:
- the receipt/GST form;
- fee-regulation limits on late fees and in-year changes;
- statutory free seats (RTE);
- retention (E21).

FEE.2 may now start. Proration remains a rejected v1 option, never a
silent default.

## Implementation note — FEE.2 as built (2026-09-29)

FEE.2 implements §9–§13 and the §11.3 void path under owner decision D1.
The full as-built record is in `docs/modules/FINANCE.md` ("FEE.2
as-built"). Refinements that amend the text above:

- **§10.1 eligibility uses the billing period, not an as-of date.** A
  single as-of reference date contradicted D1 for periods assessed in
  advance: a Student joining partway through the period would not qualify
  on the reference date, although D1 bills that period in full. As built:
  - Candidates are the grade's enrollments (campus rules as above) that had
    not ended before the period started.
  - Per Student and line, the non-cancelled enrollment that **overlaps**
    the instalment's billing period (latest start) is used.
  - An enrollment that starts only after the period ended gives
    `period_before_enrollment`.
  - Only-cancelled overlapping enrollments give `enrollment_cancelled`.
  - The Students method is
    `StudentEnrollmentFeeTargetReadService::candidatesForGrade(...)`, plus
    `lockForFeeAssessment()` for the execution-time FOR SHARE re-read.
- **`no_structure`** stays in the reason catalogue but no FEE.2 path
  produces it. A run is always scoped to one active structure, and its
  population is the one that structure resolves for: a School-default run
  excludes campuses with an active override. Resolution drift after the
  preview fails the item at execution (`structure_not_resolved`).
- **Execution failure catalogue (closed):**
  - `structure_not_active`;
  - `structure_not_resolved`;
  - `enrollment_not_qualifying`;
  - `student_inactive`;
  - `optional_not_selected`;
  - `head_inactive`;
  - `account_invalid`;
  - `error`.

  Execution re-checks every preview fact and fails closed.
- **Staff exclusion** is stored on the item: `staff_excluded`, actor and
  time. It carries over into every later preview of that run. A suspended
  School's pause is an audit event (`fee_assessment_run.paused`), not a
  status.
- **§11.3 guards.**
  - `charges_fee_assessment_guard_trigger` is **immediate** (BEFORE UPDATE
    of `cancelled_at`): a fee-assessed charge cannot be cancelled while its
    assessment is live. `ChargeService::cancel` maps it to
    `ChargeIsFeeAssessedException` (409).
  - `fee_assessments_void_requires_cancelled_charge` is the **deferred**
    half: a voided assessment's charge must be cancelled by commit.
  - The void path (`FeeAssessmentService::void`) voids first, then cancels
    through `ChargeService::cancel`, under `finance.charges.manage`.
- **Race proofs.** §12 proofs 1–5 are covered by `FeeAssessmentConcurrencyTest`
  (real processes, forced and verified overlap), plus concurrent run
  creation.
- **Not built:** FEE.3+, proration, automatic or scheduled runs.

## Owner decisions F, F2, G and M for FEE.3 (2026-09-29)

The owner confirmed the §14 recommendations for FEE.3. The earlier notes
listing F, F2, G and M as pending were true when written and stay
unchanged. Options G2/G3 and contra-income (§14.5) are kept above as
history and are **not** adopted.

### Request and approval lifecycle (F)

- **Two separate things.** A concession is a request (`fee_concessions`);
  its financial effect is a separate posted adjustment (`fee_adjustments`,
  §14.1). The request itself is never a financial posting.
- **Approval before any effect.** Every request needs approval before it
  can affect any charge. There is no amount threshold below which approval
  is skipped, and no automatic approval for any role (School Admin and
  Principal included).
- **Lifecycle (§14.4).** `pending -> approved | rejected | withdrawn`;
  `approved -> revoked` for standing concessions only.
  - Rejection leaves no financial adjustment.
  - A requester may withdraw a pending request.
  - Revocation stops future application only.
- **Posting on approval.** Approving a targeted concession posts its
  adjustment through the FEE.3 financial path in the same transaction. An
  approved standing concession posts its adjustments when assessment
  creates the matching charges (§14.4).
- **Out of scope in v1:** multi-stage approval, approval chains and
  threshold rules.

### Separation of duties (F)

- **Requester ≠ approver, always.** Self-approval is prohibited whatever
  the actor's roles or capabilities.
- **Enforced twice:**
  - the Application service throws a typed `SelfApprovalNotAllowedException`;
  - the database enforces
    `CHECK (decided_by_user_id IS NULL OR decided_by_user_id <>
    requested_by_user_id)` (the `payroll_runs_sod_check` precedent).
- **Capabilities, never role names.** Authorization is
  `finance.fee_concessions.request` / `.approve` (§19). No code branches on
  a role name (rule 24).

### Financial posting (F2)

- **The account.** Each adjustment posts Dr the School's concession
  account / Cr the charge's own receivable account through
  `LedgerService::post()`.
  - The concession account is one School-level **`expense`** account
    (purpose: "Fee concessions and scholarships"), configured in the FEE.1
    `fee_settings` singleton (`concession_ledger_account_id`).
  - It is never inferred from the fee head.
- **Validated before every posting.** FEE.3 checks the account exists,
  belongs to the School, is INR, is active and is of type `expense`. A
  missing or invalid account refuses the posting (fail closed).
- **History stays put.**
  - The charge's revenue account (the fee head mapping at assessment) is
    never changed.
  - Each adjustment stores its own debit account id, so changing the
    configured concession account later never moves posted history.
- **Not in v1:**
  - per-fee-head, category, grade, campus or Student concession accounts;
  - a chart-of-accounts template;
  - any tax treatment through this account.

### Current-outstanding cap (G, option G1)

- **The rule.** An adjustment may be posted only up to the charge's
  **current outstanding** at posting time: amount − valid payment
  allocations − already-posted (uncancelled) adjustments. Existing payment
  allocations take precedence.
- **Refused, never reduced.** An adjustment that would exceed the
  outstanding **fails closed** (`ChargeFullyPaidException` /
  `AdjustmentExceedsOutstandingException`). It is never silently reduced.
  - This decision fixes how "capped" in §14.1, §14.3 and §14.6 is read: a
    limit that refuses the posting, not an automatic reduction.
  - §14.3's percentage arithmetic still computes the requested value
    exactly (N).
  - A standing concession whose computed value exceeds what remains
    refuses its adjustment, and the assessment item fails closed. FEE.3
    adds the failure reason to the closed catalogue.
- **Never creates:** a Student credit, an unapplied balance, a refund
  entitlement or a negative charge balance.
- **Concurrency is resolved structurally.** The Payments-owned capacity
  guard (§15), amended in FEE.3 exactly as §15 authorizes, enforces
  `SUM(allocations) + SUM(uncancelled adjustments) ≤ charges.amount`. Both
  the allocation path and the adjustment path lock the charge row first, so
  a payment and a concession can never consume the same outstanding
  capacity.
  - Payment ownership does not move into Fees, and Fees never reads
    `payment_allocations`.
- **Out of scope in FEE.3:** refunds, payment reversal, credits and
  overpayments.

### Closed category catalogue (M)

- **Category is required**, from a closed catalogue: `concession`,
  `scholarship` or `waiver` (a database CHECK). There are no custom or
  School-configurable categories in v1.
- **No free-text note or reason** is stored on a concession.
  - Audit and event metadata carry ids and the closed category only.
  - Sibling, staff-ward and RTE labels stay out until the owner and the §27
    legal answer decide them.
- **Kept distinct.** The ADR's only other free-text "reason" is the
  existing charge/adjustment **cancellation** reason passed to
  `LedgerService` reversals (and the FEE.2 assessment `void_reason`). It is
  a workflow note on a reversal, not a concession category. FEE.3 does not
  widen it.
- **Later changes** (configurable categories, narrative notes) need a later
  explicit decision, and a classification decision for notes.

### Still open

- **OWNER DECISION REQUIRED:**
  - H (late fees; also behind the fee-regulation legal gate, so FEE.5 does
    not start);
  - I (receipt numbering);
  - I2 (backfilling receipts for existing payments).
- **[LEGAL REVIEW REQUIRED]** (§27):
  - the receipt/GST form;
  - fee-regulation limits on late fees and in-year fee changes;
  - statutory free seats (RTE);
  - retention (E21).

**FEE.3 — Concessions / Scholarships / Waivers is unblocked.**

## Implementation note — FEE.3 as built (2026-09-30)

FEE.3 implements §14 and §15 under owner decisions F, F2, G1 and M. The
full as-built record is in `docs/modules/FINANCE.md` ("FEE.3 as-built").
Refinements that amend or make precise the text above:

- **G1 fixes the reading of "capped" (§14.1, §14.3, §14.6).** An
  adjustment is posted whole or refused. §14.3's "each capped at what
  remains" is therefore a refusal, never a reduction: several standing
  concessions apply in decision order (`decided_at`, then id), and if one
  does not fit, the whole assessment item fails closed.
- **Two new closed item failure reasons** (FEE.2 catalogue, migration
  `2026_10_31_090300`):
  - `concession_exceeds_outstanding`;
  - `concession_account_invalid` (F2, fail closed).

  Either one rolls the item's savepoint back: no charge, no assessment, no
  adjustment, no journal entry. Items with no applicable concession never
  need the concession account.
- **The §15 seam, as built** (Payments-owned migration `2026_10_31_090200`):
  - `payments_lock_and_validate_charge_allocation()` now adds live
    adjustments to the total, so allocations + live adjustments ≤ amount.
  - `payments_lock_and_validate_charge_adjustment()`
    (`fee_adjustments_capacity_trigger`) locks the charge row FOR UPDATE
    and raises "is fully settled" or "would exceed its outstanding amount".
    Fees maps these to `ChargeFullyPaidException` and
    `AdjustmentExceedsOutstandingException`.
  - PostgreSQL fires same-event BEFORE triggers by name, so the capacity
    trigger runs before Fees' `fee_adjustments_guard_trigger` and Fees'
    validation runs under the charge lock.
  - `down()` restores the 0G.5 function body verbatim (guarded by
    `FeeSetupArchitectureGuardTest`).
  - `ChargeAllocationSnapshot` gains `adjustedTotal`/`netAmount()`.
    `SettledPaymentRecorder` pre-checks the net amount, and the manual
    payment form's outstanding is net (`OutstandingCharge::$adjusted`).
    Totals come from `ChargeService::liveAdjustmentTotalsFor`, so Fees still
    never reads `payment_allocations`.
- **The concession account setting** (§14.5) had no writer in FEE.1.
  FEE.3 adds `FeeSettingsService::setConcessionAccount` under
  `finance.fee_structures.manage` (Fee setup authority), audited as
  `fee_settings.concession_account_changed`.
- **Self-decision.** The SoD CHECK covers every decision. A requester can
  neither approve **nor reject** their own request; they withdraw it
  instead.
- **Adjustment cancellation** is under `finance.fee_concessions.approve`
  and takes an optional reason. That reason goes only to the Finance
  reversal, like a charge cancellation reason; it is not a concession note.
- **Read audit.** Concession reads are audited once per call:
  `fee_concession.list_viewed`, `fee_concession.viewed` and
  `fee_adjustment.list_viewed`. The run page's concession preview is also
  audited as `fee_concession.list_viewed`.
- **Preview (§14.4).** The run page shows gross, concession and net per
  ready, not-yet-executed item, only to holders of
  `finance.fee_concessions.view`. It also flags items that would fail
  closed. This is a projection only; execution re-reads everything under
  its locks.
- **Race proofs.** §12 proof 6 and the FEE.3 races are covered by
  `FeeConcessionConcurrencyTest` (real processes, forced and verified
  overlap):
  - payment vs. concession, both orders;
  - two approvals of one concession;
  - two concessions on the final capacity;
  - revocation vs. standing application, both orders;
  - double adjustment cancellation;
  - charge cancellation vs. approval, both orders.
- **Not built:** H (late fees), I/I2 (receipts), statements, refunds,
  credits, overpayments, thresholds or multi-stage approval, configurable
  categories and notes.

## Owner decisions I and I2 for FEE.4 (2026-09-30)

The owner confirmed the §17.2 and §17.4 recommendations. The earlier notes
listing I and I2 as pending were true when written and stay unchanged. The
§17.2 alternative (one continuous School-wide series) and the §17.4
alternative (leave legacy payments without receipts) are kept above as
history and are **not** adopted. **FEE.4 is not implemented by this
decision.**

### Receipt numbering (I)

- **Series.** One independent series per **School × financial year**. No
  receipt number is shared across Schools.
  - **Not in v1:** campus, payment-method, fee-head or user-specific series;
    several concurrent configurable series; arbitrary number templates.
    Each needs a later explicit decision.
- **Financial year.**
  - School-configurable starting month in the FEE.1 `fee_settings`
    singleton (`financial_year_start_month`, already reserved). The default
    is **4 (April)**.
  - A Payment's financial year comes from its **`settled_at` date in the
    School's timezone** and that starting month.
  - The financial year is **never inferred from an AcademicYear**; the two
    are separate concepts.
  - **Label.** The FY's starting year, a hyphen, and the last two digits of
    the next year: April 2026 – March 2027 is `2026-27`. It is also the
    receipt's `series_key`. The same rule applies to **every** start month,
    January included (corrected 2026-09-30, see "FEE.4 pre-implementation
    corrections"; the earlier single-year January label is withdrawn).
- **Format.** `<PREFIX>/<FY>/<sequence>`, for example
  `RCPT/2026-27/000001`.
  - The prefix is School-configurable (`fee_settings.receipt_prefix`,
    default `RCPT`). It cannot change once a receipt exists in the current
    series.
  - The sequence is numeric and zero-padded to **six digits**. A value past
    999999 prints in full; it never wraps.
  - The padding is **fixed**, not a setting, consistent with "no arbitrary
    templates" (§17.2 now says the same).
  - Each School × FY series starts at 1 and increases monotonically.
- **Gap-free allocation.** The §17.2 counter model is frozen:
  - `payment_receipt_counters (school_id, series_key, next_value)`,
    unique `(school_id, series_key)`;
  - the series row is created if missing, locked `FOR UPDATE`, read and
    incremented **inside the same transaction that creates the receipt**;
  - a rolled-back transaction consumes no number, so an application
    transaction failure leaves **no committed gap**;
  - concurrent issuers serialize on the counter row and receive unique,
    consecutive numbers;
  - backstop: `unique (school_id, series_key, sequence_value)`, never
    silently retried;
  - never a PostgreSQL `SEQUENCE` (non-transactional; it leaves gaps on
    rollback) and never `MAX(number) + 1`.
- **Immutability.** Once issued, none of these can change: the receipt
  number, the payment link, the series and sequence value, or the issue
  facts.
  - A receipt is never deleted or renumbered, and counters are never reset.
  - Any future correction or void contract (rule 91) issues a **new,
    linked document** and never rewrites the original. That is outside
    FEE.4.

### Existing payments (I2)

- **What.** Payments settled before FEE.4 receive receipts only through an
  explicit, audited, idempotent one-time command:
  `finance:receipts-backfill {school}` (§17.4).
- **How it runs.**
  - An operator invokes it for one named School at a time.
  - It never runs inside a migration, a deploy step, or automatically for
    every School.
  - It processes settled Payments in deterministic **`(settled_at, id)`**
    order.
  - It skips any Payment that already has a receipt. Re-running it is safe
    and never creates a duplicate: one receipt per Payment (unique
    `payment_id`) is the authoritative guard.
- **Numbering.**
  - Each backfilled Payment takes the next number from the **same**
    School × FY counter as live issuance.
  - Its FY is derived from its **historical `settled_at`**.
  - Receipts already issued are never renumbered and counters are never
    reset around them.
  - *Intended behaviour, not an anomaly:* numbers follow issuance order.
    If live FEE.4 issuance has already numbered receipts in an FY,
    backfilled receipts for earlier settlements in that FY follow them.
- **Provenance.**
  - `issued_at` is the **real** time of the backfill operation. It is never
    backdated to the settlement time.
  - The settlement time stays on the Payment, and the two are shown as
    distinct facts.
  - Each backfilled receipt records an explicit audit event,
    `payment_receipt.backfilled`, with ids only.
- **What it never does.**
  - It never edits a Payment row: a receipt is a separate Payments-owned
    row.
  - It never leaves legacy Payments permanently unreceipted under the v1
    policy.
- **School lifecycle (rule 86).** The command's suspended-School
  behaviour is decided and tested when FEE.4 is built.

### Still open

- **[LEGAL REVIEW REQUIRED] J — statutory receipt / GST form (§17.5).**
  Unresolved. None of these may be decided by engineering judgment:
  - whether a School must issue a prescribed receipt or tax invoice;
  - whether it must show a GSTIN, HSN/SAC, taxable value or tax lines;
  - how any fee is treated (taxable or exempt);
  - any mandatory statutory wording or numbering.

  Until a qualified answer is recorded, FEE.4 may render only a **payment
  acknowledgement** labelled "Payment receipt", never "Tax invoice", with
  no tax fields and no tax calculation.
- **OWNER DECISION REQUIRED: H** (late fees). FEE.5 also stays behind the
  fee-regulation legal gate.
- **[LEGAL REVIEW REQUIRED] (§27):**
  - fee-regulation limits on late fees and in-year changes;
  - statutory free seats (RTE);
  - retention (E21).

**FEE.4 product decisions are resolved. FEE.4 remains subject to the J
legal gate and has not started.**

## FEE.4 pre-implementation corrections and legal-gate operating rule (owner, 2026-09-30)

The owner made three contract corrections before FEE.4 implementation, and
set how unresolved legal gates are carried. The corrected sentences above
are marked with this date. Nothing else in the dated sections changes.

### Corrections

- **Financial-year label, every start month.** One algorithm for all
  configured start months: `<starting year>-<last two digits of the next
  year>`.
  - April 2026 → `2026-27`; July 2026 → `2026-27`; January 2026 →
    `2026-27`.
  - The label is a stable **series identifier**. It does not assert that
    the configured financial period extends into the next calendar year.
  - The earlier single-year January label (`2026`) is withdrawn.
- **Padding.** The sequence prints with a minimum of six digits (`000001`).
  - It is fixed in v1 and is not a School setting.
  - Only `fee_settings.receipt_prefix` is configurable.
  - Values above 999999 print in full and never wrap.
  - §17.2 is corrected to say the same, so the ADR no longer contradicts
    itself.
- **Numbering chronology.**
  - Receipt numbers represent **issuance order** inside a School × FY
    series; the Payment's `settled_at` represents settlement chronology.
  - The backfill processes its candidates in `(settled_at, id)` order, but
    never renumbers receipts already issued.
  - An older Payment backfilled later may therefore carry a higher number.
    This is intentional.

### Legal-gate operating rule

- **The rule.** The owner authorised development to continue through
  unresolved legal-review items, to be resolved in the final application
  production-readiness review. **This is not a legal determination.**
- **For every such item:**
  - the legal question stays open, and no compliance is claimed;
  - only the conservative behaviour already approved here is built;
  - the item is marked **DEVELOPMENT AUTHORISED — PROD LEGAL SIGN-OFF
    REQUIRED**;
  - it is carried in the production-readiness evidence register (ADR 0058
    §6).
- **J under this rule.** FEE.4 renders only a payment acknowledgement,
  titled "Payment receipt":
  - never "Tax invoice";
  - no GSTIN, HSN/SAC, taxable value, tax rate or tax lines;
  - no GST calculation;
  - no statement that any fee is taxable or exempt.

  Production must not treat this as legally cleared until J has a
  qualified answer.
- **Other legal gates.** They get the same distinction when their
  checkpoints are reached: fee regulation (late fees, in-year changes),
  RTE statutory free seats, and retention (E21).
- **H is unaffected.** The rule does not supply the missing product
  decision H: FEE.5 still waits for the owner.

## Implementation note — FEE.4 as built (2026-09-30)

FEE.4 implements §17 and §18 under owner decisions I and I2 and the
corrections above. J stays **LEGAL REVIEW REQUIRED — DEVELOPMENT
AUTHORISED, PROD LEGAL SIGN-OFF REQUIRED**. The full record is in
`docs/modules/FINANCE.md` ("FEE.4 as-built"). Refinements to the text
above:

- **Counter prefix snapshot.** `payment_receipt_counters` stores the
  prefix the series started with, so every receipt of one series has one
  format.
  - The §17.2 lock ("may not change once a receipt exists in the current
    series") is database-enforced by the Payments-owned
    `fee_settings_receipt_numbering_guard_trigger`.
  - A past series keeps its own prefix if the setting later changes.
- **Start month lock.** The financial-year start month is locked once any
  receipt exists. Changing it would redefine existing series; §17.2 was
  silent on this, so this is the conservative reading.
- **Gap-free by construction, in the database:**
  - a receipt insert must take exactly the counter's `next_value`, in the
    exact `payments_receipt_number()` format, in the FY of its Payment
    (`payments_fy_series_key()`);
  - a counter only moves by +1, and only once the receipt for the old value
    exists; a series starts at 1;
  - receipts can never be updated or deleted (runtime privileges plus a
    trigger).
- **One event type for both issuance modes.** `payment_receipt.issued.v1`
  is emitted for live issuance and for the backfill, with
  `issuance: settlement|backfill`. The audit events stay distinct:
  `payment_receipt.issued` and `payment_receipt.backfilled`.
- **Suspended School (rule 86) — backfill.** It is refused unless the
  School is active, and each receipt re-checks the lifecycle inside its own
  transaction (`SchoolOperationalGuard`). The operator re-runs it after the
  School resumes; nothing is replayed automatically.
- **Read audits.** `payment_receipt.viewed` (receipt) and
  `fee_statement.viewed` (statement), once per successful read, ids only.
- **Receipt form.** The page is titled "Payment receipt" and states it is
  an acknowledgement, not a tax invoice. It shows no GSTIN, HSN/SAC,
  taxable value, tax rate or tax lines. Printing uses the browser; no PDF
  is stored.
- **Not built:**
  - H (late fees), tax/GST, tax invoices;
  - receipt void or correction, refunds, credits;
  - statement export/PDF/email, Guardian/Student views, portal.

## Owner decision H for FEE.5 (2026-09-30)

The owner confirmed the §16 recommended v1 model. The earlier notes listing
H as pending were true when written and stay unchanged. The §16.1 options
(tiered and recurring per-month late fees) are kept as history and are
**not** adopted. **FEE.5 is not implemented by this decision.**

**H is a product decision only.** The owner is not declaring any late-fee
policy legally permitted. See "Legal status" below.

### Calculation

- **Two rule kinds, no others:**
  - **fixed** — a positive `NUMERIC(14,2)` INR amount;
  - **percentage** — `0 < p ≤ 100` (two decimals) of the source charge's
    **current outstanding** at execution.
- **Excluded in v1:** tiers, recurring or per-period penalties, escalating
  percentages, per-day rates, interest (simple or compound), compounding,
  and arbitrary formulas.
- **Fixed:** the late fee is the configured amount, subject only to the
  cap.
- **Percentage:**
  - `Money::multiplyByRate(p/100)` of the current outstanding — decision N:
    scale 2, half away from zero, never a float;
  - never computed on the original charge amount when the outstanding is
    lower.
- **Optional cap (`max_amount`).**
  - Positive `NUMERIC(14,2)` when set.
  - No cap: the calculated amount stands.
  - With a cap: the late fee is the lesser of the calculated amount and the
    cap.
  - Applying the cap is the intended rule. It is **not** decision G1's
    "refuse rather than reduce", which governs concessions only.
- **Never zero or negative.** If the final amount is not strictly positive
  (for example a percentage that rounds to 0.00), no late fee is assessed.
  The run item records a closed non-assessment reason.

### Current outstanding (the base and the eligibility test)

- `charge amount − valid payment allocations − live posted adjustments`:
  the FEE.3/§15 model.
- It is **computed by Payments under the source charge's row lock** at
  execution (§16.2). Fees never reads `payment_allocations`, and Payments
  reads Fees facts only through Fees' Application layer.

### Grace boundary (frozen)

- **The rule.** A source charge is eligible only when
  `evaluation_date > due_date + grace_days` — the same condition as §16.2's
  `due_date + grace_days < evaluation_date`.
- **Dates are School-calendar dates:**
  - `due_date` is the charge's date;
  - `evaluation_date` is the run's date in the School's timezone.
- **The final grace date** is `due_date + grace_days`. No late fee is
  assessed on or before it.
  - Example: due 2026-06-10 with 5 grace days → final grace date
    2026-06-15 → first eligible evaluation date 2026-06-16.
  - Example: 0 grace days → the first eligible date is the day after the
    due date.
- **Grace days** are an integer `≥ 0`.

### Eligibility at execution (all required, re-checked under locks)

- the rule is active and in scope;
- the source charge is a live structure-generated charge (a live
  `fee_assessments` row) and is not cancelled;
- it has a due date, and the grace period has expired (boundary above);
- its current outstanding is `> 0`, so a charge fully covered by payments
  and live adjustments gets no late fee;
- no live late fee exists for the same source charge and rule;
- every reference is same-School.

### Scope (unchanged from §16.1)

- **What a rule covers.** A Fees-owned rule is scoped by `fee_structure_id`
  plus an optional `fee_head_id` (NULL = every line of that structure).
  `late_fee_head_id` names the fee head whose accounts carry the late fee.
- **Not added:** no Section, Student, payment-method, concession-category,
  demographic or arbitrary-query dimension.
- **Status.** Rules are `active|inactive`, editable only while inactive,
  and never deleted.

### One late fee per source charge per rule (non-recurrence)

- **The key.** `UNIQUE (school_id, source_charge_id, late_fee_rule_id)
  WHERE voided_at IS NULL` on `late_fee_assessments` (§16.3).
  - Re-running a run, or running again later, never creates a second live
    late fee for the same source charge and rule.
  - There is no recurrence in v1.
- **Two rules on one charge.** Each is keyed separately (§16.1, as frozen).
- **No compounding.** A late fee is itself a charge without a fee
  assessment, so it is never a late-fee candidate.
- **Voiding (§16.3 → §11.3).**
  - The late-fee assessment is voided and its late-fee charge is cancelled
    through `ChargeService::cancel` (a Finance reversal) in one
    transaction. This is refused when a payment is allocated to that
    charge.
  - The original rows are kept and audited, and the reversal happens once.
  - Voiding frees the key, so one deliberate later run may assess that
    source charge and rule again. That is the only re-assessment path.
  - No refund or payment reversal exists.

### Posting (unchanged from §16.2)

- **The late-fee charge.** A **new charge**, through the trusted
  `ChargeService::assess()`:
  - Dr the late-fee head's receivable account / Cr its revenue account;
  - `due_date` = evaluation date;
  - description `"Late fee: <source label>"`;
  - linked to its source charge by the `late_fee_assessments` row.
- **Never.** The source charge is never edited or appended to. There is no
  invoice and no mutable "late balance" field.

### Execution and authorization (unchanged)

- **Runs.** Dedicated `fee_late_fee_rules` (Fees) and `late_fee_runs`,
  `late_fee_run_items`, `late_fee_assessments` (Payments), staff-triggered,
  with the §9/§13 lifecycle and job shape.
  - No scheduler and no automatic recurrence.
  - Payments owns execution because eligibility depends on allocations.
- **Capabilities.** Rules need `finance.fee_structures.manage`; runs need
  `finance.fee_assessments.run`. No new capability, no role-name branching,
  and default grants are unchanged.

### Legal status

- **The rule.** Under the owner's 2026-09-30 legal-gate operating rule
  (above), FEE.5 is **DEVELOPMENT AUTHORISED — PROD LEGAL SIGN-OFF
  REQUIRED**. That rule is the owner's written acceptance, for development
  only, that §16.3 asks for.
- **Questions that stay open** (ADR 0058 **E31**, with RTE in **E32**):
  - whether late fees are permitted at all;
  - the maximum amount or rate;
  - mandatory grace periods;
  - restrictions on fee changes during an AcademicYear;
  - rules for regulated, private or aided Schools;
  - statutory free-seat / RTE interactions.
- **Production** must not enable late fees until E31 has a qualified
  answer.

## FEE.4 numbering behaviour confirmed as final v1 contract (owner, 2026-09-30)

The owner confirmed the FEE.4 as-built numbering behaviour (see the FEE.4
implementation note) as the final v1 contract. The implementation already
matches it, so no code changed.

1. **The start month is locked.** `fee_settings.financial_year_start_month`
   is locked once the School's first receipt exists
   (`fee_settings_receipt_numbering_guard_trigger`).
2. **Each series keeps its prefix.** A receipt series keeps, permanently,
   the prefix it started with: the counter row's `prefix` snapshot is
   immutable.
3. **A prefix change affects only new series.** It applies only to series
   created after the change: the current FY's series if it has no receipt
   yet, and every future FY. It is refused while the current series has
   receipts.
4. **Backfills keep the historical prefix.** A historical FY backfilled
   later (I2) continues its existing series with that series' prefix. A
   historical FY that has no series yet starts with the prefix in force at
   the backfill.
