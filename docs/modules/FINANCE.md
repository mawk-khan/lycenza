# Finance Module

Begins `docs/roadmap/MASTER-ROADMAP.md`'s **Phase 0G — Finance and
Fees**. This document is the Finance module's architecture/domain
contract, written before any code exists (checkpoint **0G.0 — Finance
Architecture & Module Plan**, documentation/architecture only). Read
`docs/architecture/adr/0030-finance-ledger-accounting-architecture.md`
("ADR 0030") first — it settles the one decision this document does not
re-argue: Finance is a true double-entry ledger, not a subledger or a
mutable-balance charge/payment tracker. This document does not repeat
ADR 0030's rationale, only builds the fuller domain contract on top of
it.

## Checkpoint roadmap

```
0G.0  Finance Architecture & Module Plan          (this document + ADR 0030)               [implemented]
0G.1  Ledger Schema Foundation                    (ledger_accounts, journal_entries, journal_lines)   [not started]
0G.2  Posting & Reversal Application Services      (LedgerService, balance/derivation reads)           [not started]
0G.3  Finance Authorization & Administrative Read Model   (finance.* capabilities, allow/deny tests)   [not started]
0G.4  Fees / Receivables Foundation                (charges, invoices — post through the ledger)       [not started]
0G.5  Payments & Idempotent Callback Integration   (payment records, gateway adapter, inbound webhook idempotency)   [not started]
0G.6  HTTP/API Transport                           (thin controllers over 0G.2-0G.5 services)          [not started]
0G.7  UI / Finance Workspace                       (Inertia pages: chart of accounts, ledger, receivables)   [not started]
0G.8  Hardening & Closure                          (cross-tenant tests, full regression, closure report)   [not started]
```

This sequence refines, rather than blindly copies, the tentative shape
from Phase 0G's kickoff discovery — it is derived from what a
double-entry ledger with a receivables layer on top actually needs
(schema → posting logic → authorization → receivables → payments →
transport → UI → closure), which happens to resemble the template shape
other modules used, but for module-specific reasons stated at each
checkpoint below, not because the template was forced onto it.

## What already existed before this checkpoint

Discovered by inspection before this document was written (branch base
`main` at `0b556aaa906244ee55b3322e6cedf64a12cbfb73`):

- **No Finance code of any kind exists anywhere in the repository.** A
  repository-wide search for `finance|invoice|ledger|chart_of_accounts|
  journal_entr|payroll|charge_item` across `apps/`, `docs/`, and
  `packages/` returns no migration, model, service, controller, route,
  OpenAPI path, or test — only documentation mentions (`ARCHITECTURE.md`
  §10, `DOMAIN-MAP.md`'s Layer 3 row, ADR 0017/0018/0019, the
  Communications/HR/Documents module docs referencing "Finance" as a
  future dependency, and `AUTHORIZATION.md`'s pre-listed "Accountant"
  actor category). This confirms Finance is genuinely **not started**,
  not merely under-documented.
- **`docs/architecture/DOMAIN-MAP.md`'s Layer 3 row** already commits
  Finance's dependency set (Schools, Identity & Access — both complete
  since Phase 0B/0D) and its conceptual scope ("core ledger, chart of
  accounts, financial-correctness primitives... foundational for Fees
  and Payroll; itself has no dependency on either").
- **`docs/architecture/ARCHITECTURE.md` §10** fixed seven financial-
  correctness rules ahead of any financial module (see ADR 0030's
  Context for the exact list) — this document and every future 0G
  checkpoint is built against those rules, not against generic ERP
  accounting assumptions.
- **ADR 0018** (integration/webhook architecture) already fixed the
  *pattern* inbound payment-gateway callbacks must follow (dedicated
  per-provider endpoint, provider-specific signature verification,
  idempotent by construction, keyed on the provider's own event/
  transaction id) — Finance/Payments (0G.5) implements against this
  pattern, it does not invent a new one.
- **Phase 0C.2's `docs/architecture/RELIABILITY.md`** ("Payment and
  webhook readiness") already documents the exact distinction this
  module must respect: client API `Idempotency-Key` idempotency (Phase
  0C.2's `EnsureIdempotent` middleware / `IdempotencyGuard`) is **not**
  the same mechanism as payment-provider webhook idempotency — a
  payment callback needs its own contract keyed on the provider's
  delivery/transaction identifier, independent of whether the
  originating client request also carried an `Idempotency-Key`. Both
  are needed; neither substitutes for the other.
- **Phase 0C.3's outbound webhook infrastructure** (`webhook_endpoints`/
  `webhook_subscriptions`/`webhook_deliveries`/`webhook_delivery_attempts`,
  `App\Support\Webhooks\WebhookEventRegistry`,
  `App\Jobs\DeliverWebhookJob`) is real and reusable, but it is built
  for **outbound** delivery (School OS notifying a subscriber of a
  domain event) — a payment gateway's **inbound** callback is a
  structurally different direction of traffic and needs its own
  narrow, provider-authenticated receiving endpoint and its own
  provider-event-id uniqueness table (0G.5's concern, see "Payment
  model" below). This module reuses the *pattern* (atomic claim via a
  database unique constraint, HMAC/signature verification,
  retry-safe), not the outbound tables themselves.
- **The Documents module's "exclusive arc" precedent**
  (`docs/modules/DOCUMENTS.md`, "Owning-entity boundary") is the
  directly relevant prior art for this module's own "who does a
  financial fact belong to" problem: a single polymorphic
  `subject_type`/`subject_id` column cannot carry a real foreign key
  against more than one parent table, which would let a financial
  record reference a deleted or cross-School entity with no structural
  guarantee — exactly the failure mode CLAUDE.md rule 70 exists to
  prevent. Finance follows the same resolved pattern (separate
  nullable, explicitly-FK'd columns) wherever a financial-subject/payer
  reference is needed — see "Financial subject vs. payer" below. 0G.1's
  core ledger tables need **no such column at all** (see "Core entities
  for 0G.1" below) — the problem is deferred, correctly, to 0G.4.
- **`docs/security/AUTHORIZATION.md`** already lists "Accountant" as an
  actor category ("Human, One School, financial-domain capabilities")
  — 0G.3 names real `finance.*` capabilities for this actor; none exist
  yet.
- **`docs/security/DATA-CLASSIFICATION.md`** already classifies
  "Financial data (fees, invoices, payroll, payment instrument
  references)" as **Highly Sensitive**, with an explicit instruction
  never to store raw payment-card data and a flagged
  **[LEGAL/COMPLIANCE REVIEW REQUIRED]** gate once a real payment
  gateway integration is designed (0G.5, not this checkpoint).
- **Admissions** (`feature/phase-1d-admissions-foundation`, active on a
  separate branch/worktree, not touched by this checkpoint) was
  inspected read-only for fee/deposit/Finance boundary references: its
  current schema-only checkpoint (`Applicant`/`AdmissionApplication`)
  has **no financial fields, migrations, or references at all** — a
  repository search for `fee|deposit|finance|payment` inside
  `docs/admissions/` returns nothing. There is no Admissions-side
  financial boundary to reconcile yet; "Admissions fee/deposit" is
  recorded here only as a **future integration seam** (see "Admissions
  boundary" below), not a current dependency in either direction.

## Purpose

Finance owns the core double-entry ledger and chart of accounts that
every other money-touching module (Fees, Payments, Payroll, and later
Inventory costing per `DOMAIN-MAP.md`) posts through, so that "what
actually happened financially" has exactly one authoritative,
append-only representation across the whole ERP.

## Scope

**In scope for Phase 0G overall:** the core ledger and chart of
accounts (0G.1/0G.2), Finance-specific authorization (0G.3), fee/
receivable definitions and invoicing that post through the ledger
(0G.4), payment recording and the first real payment-gateway
integration with inbound-webhook idempotency (0G.5), API/UI transport
for all of the above (0G.6/0G.7), and phase closure (0G.8).

**In scope for this checkpoint (0G.0) specifically:** architecture and
domain-contract decisions only — no schema, code, capability, route, or
UI.

## Non-goals

- **Payroll** is not owned here. Finance provides the posting substrate
  Payroll depends on (`DOMAIN-MAP.md`: `Payroll` depends on `HR,
  Finance`); Payroll owns salary structures, payroll runs, and
  statutory deductions itself, in its own Phase 0J checkpoint. See
  "Payroll boundary" below.
- **Admissions fees/deposits** are not designed here. See "Admissions
  boundary" below.
- **A School-configurable chart of accounts** is deferred past 0G.1
  (ADR 0030's Consequences) — 0G.1 ships system-managed accounts only.
- **Multi-currency / FX** is out of scope for the whole of Phase 0G as
  currently scoped — see "Currency" below.
- **Accounting-period close/lock** (a distinct concept from an academic
  year — see "Academic year vs. financial period" below) is deferred;
  0G.1 does not implement period locking.
- **Human-facing invoice/receipt numbering** (a School-scoped sequence
  with its own concurrency-safety design) is deferred to 0G.4/0G.5,
  explicitly flagged there as needing dedicated design, not a
  `MAX(number)+1` shortcut.
- **Financial reporting/analytics** is out of scope — a future Layer 5
  Analytics/Compliance consumer reads authoritative ledger data via an
  explicit read contract, per `DOMAIN-MAP.md`'s Layer 5 rule; Finance
  does not build a denormalized reporting schema itself.
- **PCI-DSS-scoped raw payment-card data** is never in scope for School
  OS's own database at any point — see "Prohibited payment data" below.

## Dependency position

Per `docs/architecture/DOMAIN-MAP.md` Layer 3: Finance depends on
**Schools** and **Identity & Access** only, both complete since Phase
0B/0D. Finance has no dependency on Fees, Payments, Payroll, Students/
SIS, Guardians, Admissions, or HR — those modules depend on *it*, never
the reverse (`DOMAIN-MAP.md` rule 1, no bidirectional coupling). This
is why Finance can start now, unblocked, independent of the
concurrently-active Admissions branch.

## Domain terminology

| Term | Meaning in this module |
|---|---|
| **Ledger account** | A named slot in the chart of accounts with a fixed type (asset/liability/equity/income/expense). Not a bank account — an accounting category. |
| **Journal entry** | One financial fact/transaction header — e.g. "fee payment received," "salary payable accrued." Immutable once created. |
| **Journal line (posting)** | One debit or credit row within a journal entry, against exactly one ledger account. |
| **Posting** | The act of creating a balanced journal entry (header + lines) inside one transaction. |
| **Reversal** | A new journal entry whose lines exactly invert a prior entry's, linked via `reversal_of_journal_entry_id`. Never an edit to the original. |
| **Charge** *(0G.4, not this checkpoint)* | An amount owed by a financial subject (e.g. a Student's tuition fee for a term). |
| **Payment** *(0G.5, not this checkpoint)* | A recorded settlement against one or more charges/invoices. |
| **Financial subject** | Who a charge is owed *by* (the beneficiary of the obligation — typically a Student, sometimes an Employee for a payroll deduction, sometimes an Applicant for an admissions fee). |
| **Payer** | Who actually pays (typically a Guardian, sometimes the Student or Employee themselves) — not assumed identical to the financial subject. See "Financial subject vs. payer" below. |

## Accounting model

**True double-entry ledger** — see ADR 0030 for the full decision and
rationale. Every journal entry's lines must sum to zero (debits ==
credits) in one currency before the entry is considered posted; there
is no other accounting model in use anywhere in this module.

## Tenant ownership

Every Finance table is School-owned, following the same pattern as
every other tenant-owned table in this repository — no exception:

- `App\Support\Tenancy\BelongsToSchool` on every Eloquent model (Layer
  1: `SchoolScope`, auto-filled `school_id` from `TenantContext`).
- `App\Support\Tenancy\TenantRls::enable($table)` in every migration
  (Layer 2: PostgreSQL RLS, enabled and forced).
- UUIDv7 primary keys (ADR 0019), generated by Laravel only.
- Composite same-School foreign keys — `(id, school_id)` — for every
  child→parent reference within Finance (`journal_lines` →
  `journal_entries`, `journal_lines` → `ledger_accounts`), following
  the precedent `Section→AcademicYear`/`membership_role_assignments`/
  `webhook_subscriptions` already established (rule 70). No Finance
  table relies on RLS/SchoolScope alone to prevent a cross-School
  reference at INSERT time.
- No financial identifier is ever globally addressable across Schools
  — a `journal_entry` id is an identifier, not authorization (same
  principle as every other module).

There is no ambient assumption of a single "base currency table" at
the School level yet — see "Currency" below for exactly what is and
isn't decided.

## Money

### Database representation

PostgreSQL `NUMERIC`, never `FLOAT`/`DOUBLE`/`REAL`, per
`ARCHITECTURE.md` §10 rule 1 (non-negotiable, repeated here only to
make this document self-contained). **Exact precision/scale
(`NUMERIC(p,s)`) is not fixed by this checkpoint** — 0G.1 must select
it from the actual currency/use-case bounds in play (today: INR only,
2 decimal places / paise). Recording this explicitly as an open,
narrow, 0G.1-scoped decision rather than guessing a number here that
0G.1 would just inherit uncritically.

### Application representation

**Decision: a first-party `App\Support\Money\Money` value object**
(immutable, wraps a decimal-string amount + an ISO 4217 currency code,
arithmetic via PHP's `bcmath`), not a new Composer dependency (e.g.
`brick/money`). Rationale:

- `ARCHITECTURE.md` §10 rule 1 already names this exact pair of options
  ("PHP's `bcmath`/a dedicated Money value object") — a first-party
  value object backed by `bcmath` satisfies both halves of that
  sentence with zero new dependencies.
- Root `CLAUDE.md` rule 2 ("no speculative frameworks... for a need
  that doesn't exist yet") argues against adding a general-purpose
  money library before a single financial module has used one — if
  `Money`'s real requirements outgrow a first-party value object (e.g.
  genuine multi-currency arithmetic with FX), that is a concrete,
  reviewable, future dependency decision, not a default made now.
- `bcmath` is a PHP core extension (no Composer dependency at all),
  consistent with how the rest of this codebase avoids adding packages
  for problems solvable with what's already available.

This is a recommendation this document settles conceptually; the
actual `Money` class is written in 0G.2 (Application services), not
0G.1 (schema) or this checkpoint.

### Float prohibition

No binary floating point in the database, PHP calculations, API
contracts, or JavaScript business calculations touching a monetary
value, anywhere in this module, ever — no exception carved out here.

## Currency

**Every monetary amount carries an explicit currency** — never
inferred from locale, browser, or a hardcoded assumption
(`ARCHITECTURE.md` §10 rule 2). Concretely: every table with a
monetary column also has an explicit `currency` column (ISO 4217 code,
e.g. `INR`), even though INR is the only currency in scope today.

### Multi-currency: out of scope for Phase 0G

Single-currency-per-School only. No FX conversion, no exchange-rate
table, no currency gains/losses account, and — as a direct consequence
— **cross-currency journal entries are rejected outright**: an 0G.2
posting operation that would create a journal entry mixing two
different currencies across its lines is a validation error, not a
supported (if awkward) case. If multi-currency support becomes a real
requirement later, it is a dedicated future checkpoint with its own
ADR, not an incidental extension of 0G.1's schema.

## Core entities

Classified per the discovery template (CORE 0G.1 / LATER 0G / CROSS-
DOMAIN / DEFERRED / UNNECESSARY) — deliberately narrow for 0G.1.

### CORE — 0G.1 (ledger schema foundation)

| Entity | Role |
|---|---|
| `ledger_accounts` | Chart of accounts. School-scoped, system-managed only in 0G.1 (see "Account model" below). |
| `journal_entries` | Transaction header. Append-only, no draft state (see ADR 0030). |
| `journal_lines` | Individual debit/credit postings against one `ledger_accounts` row each, within one `journal_entries` row. |

No other table is created in 0G.1. Specifically **no financial-subject
or payer column exists anywhere in these three tables** — the ledger
core deals only in accounts, not people; see "Financial subject vs.
payer" below for why that problem is correctly deferred, not
forgotten.

### LATER — Phase 0G, not 0G.1

| Entity | Target checkpoint | Role |
|---|---|---|
| `charges` | 0G.4 | An amount owed by a financial subject — references `ledger_accounts` indirectly (posts through the ledger service), and directly references its financial subject via an exclusive-arc FK set. |
| `invoices` / `invoice_lines` | 0G.4 | Optional receivables grouping on top of `charges` — whether this is a separate table or folded into `charges` is an 0G.4 design decision, not settled here (see "Invoice decision" below). |
| `payments` | 0G.5 | A recorded settlement — references the payer (exclusive-arc) and allocates against one or more `charges`/`invoices`. |
| `payment_allocations` | 0G.5 | Join structure: how much of one `payment` applies to which `charge`/`invoice` line. |
| `payment_provider_events` | 0G.5 | Provider-event-id uniqueness table for inbound webhook idempotency (see "Payment model" below) — the Finance-specific analog of `webhook_deliveries`' `(webhook_endpoint_id, event_id)` uniqueness, for the opposite (inbound) traffic direction. |
| `refunds` | 0G.5 | A specific kind of reversal with its own authorization requirement (`ARCHITECTURE.md` §10 rule 4) — modeled as a reversing `journal_entry` plus a `refunds` row carrying the business-level "why"/authorization reference. |

### CROSS-DOMAIN (owned elsewhere, referenced by Finance)

| Entity | Owning module | How Finance relates |
|---|---|---|
| Student | Students/SIS | `charges`' financial-subject FK (0G.4), composite `(id, school_id)` — never read via Students' Eloquent models directly. |
| Guardian | Guardians | `payments`' payer FK (0G.5), same composite-FK pattern. |
| Employee | HR | Payroll-originated postings' financial-subject reference (0G.5+/Payroll's own checkpoint) and a future payroll-deduction charge type. |
| Applicant | Admissions | Not referenced by any Finance table today — see "Admissions boundary" below; a future admissions-fee charge would add this FK only when Admissions actually needs it. |
| AcademicYear | Academic Structure | `charges`' fee-period reference (0G.4) — distinct from any future accounting-period concept; see "Academic year vs. financial period" below. |

### DEFERRED (real, not selected for Phase 0G's initial scope)

- School-configurable chart of accounts / custom sub-accounts.
- Accounting-period close/lock (`financial_periods` or equivalent).
- Multi-currency / FX / exchange-rate tables / currency gains-losses
  accounts.
- Human-facing invoice/receipt sequence numbering.
- Financial reporting/analytics schema.
- A materialized/cached balance table (allowed later only as an
  explicitly non-authoritative cache, per ADR 0030).

### UNNECESSARY (explicitly rejected, not merely deferred)

- A generic polymorphic `subject_type`/`subject_id` (or `payer_type`/
  `payer_id`) column on any Finance table — rejected for the same
  structural reason Documents' owning-entity boundary was rejected in
  its polymorphic form (rule 70; see "Financial subject vs. payer"
  below for the resolved pattern).
- A mutable `balance` column as canonical truth on `ledger_accounts` or
  anywhere else (ADR 0030).
- A second, Finance-specific audit table duplicating
  `school_audit_events` — see "Audit vs. ledger" below.
- A second webhook/idempotency framework parallel to Phase 0C's — 0G.5
  reuses the *pattern*, not a reinvention (see "Payment model" below).

## Ledger structure (0G.1 design target)

- `journal_entries`: `id` (UUIDv7), `school_id`, `posted_at`,
  `currency`, `description`, `reversal_of_journal_entry_id` (nullable,
  unique, self-referential composite FK against `(id, school_id)`),
  `created_at`. No `status`/`draft` column — existence means posted
  (ADR 0030).
- `journal_lines`: `id` (UUIDv7), `school_id`, `journal_entry_id`
  (composite FK), `ledger_account_id` (composite FK), `debit_amount`
  `NUMERIC` (nullable), `credit_amount` `NUMERIC` (nullable), a CHECK
  constraint requiring exactly one of the two to be non-null and
  positive (never both, never a signed single column — this removes
  sign-convention ambiguity from every future reader of this table),
  `currency` (must match the parent `journal_entries.currency` — 0G.1
  should consider whether to duplicate the column for query
  convenience or join for it; a precise call for 0G.1, not fixed here).
- **Balancing enforcement**: validated inside the Application-layer
  `DB::transaction()` before commit, at minimum. The target structural
  enforcement is a PostgreSQL `CONSTRAINT TRIGGER ... INITIALLY
  DEFERRED` that validates `SUM(debit_amount) = SUM(credit_amount)`
  across all `journal_lines` for one `journal_entry_id` at `COMMIT`
  time (a single-row `CHECK` constraint cannot express a multi-row
  invariant like this — noted here so 0G.1 doesn't attempt one). Exact
  trigger design is an 0G.1 task.

## Account model

- **Taxonomy**: the standard five-category chart-of-accounts types —
  `asset`, `liability`, `equity`, `income`, `expense` — database-CHECK-
  constrained on `ledger_accounts.type`, following the same "no
  default, explicit enum" pattern `documents.classification_tier`
  established.
- **School configurability**: **system-managed only in 0G.1.** Every
  School gets the same seeded set of core accounts (the exact seed set
  — e.g. "Accounts Receivable — Fees," "Cash/Bank," "Fee Income,"
  "Refunds Payable" — is an 0G.1 implementation decision once 0G.4/
  0G.5's real posting needs are concretely known; not fixed by this
  document). A `ledger_accounts.is_system` boolean marks these as
  non-deletable/non-renameable via any future School-facing UI. Real
  School-level chart-of-accounts customization is DEFERRED (see Core
  entities above).
- **Lifecycle**: `status` `active`/`inactive` (rule 73's reference-
  entity-lifecycle pattern — no delete path, ever, once an account may
  have postings against it).

## Financial subject vs. payer

**Not the same entity, and not assumed to be.** A Student who incurs a
fee (the financial subject) and the Guardian who pays it (the payer)
are structurally distinct roles — this document does not encode "a
Guardian owns all their linked Student's debt" as an architectural
assumption, because no household/family-billing model exists anywhere
in this repository yet, and inventing one here would be exactly the
kind of speculative modeling root `CLAUDE.md` rule 2 warns against.

- **Financial subject** (who the obligation is *against*): resolved,
  when `charges` is designed in 0G.4, via the same exclusive-arc
  pattern Documents established for its owning-entity problem —
  separate nullable, explicitly-composite-FK'd columns (e.g.
  `student_id`/`employee_id`/`applicant_id` on `charges`, exactly one
  non-null), never a polymorphic `subject_type`/`subject_id` pair. The
  exact column set is an 0G.4 decision (it depends on which subject
  types 0G.4 actually needs — Student fees are the near-certain first
  case; Employee/Applicant may or may not be needed at that point).
- **Payer** (who actually pays): resolved the same way, independently,
  when `payments` is designed in 0G.5 — a payer is not required to be
  the financial subject, and a single payer covering multiple
  subjects' charges (e.g. one Guardian paying for two linked Students'
  fees in one payment) is a real case `payment_allocations` (0G.5)
  must support, not an edge case to special-case later.

0G.1's ledger core needs neither column — see "Core entities for
0G.1" above.

## Fees / invoices scope

**0G.1 ships the ledger only — no fee/charge/invoice concept exists
until 0G.4.** This is a deliberate sequencing choice: the posting/
balancing/reversal mechanics (0G.1/0G.2) are the part every later
Finance-adjacent feature depends on, so they are proven correct in
isolation (with tests posting directly against system accounts) before
any receivables-specific product logic (fee schedules, due dates,
partial payments) is layered on top.

### Invoice decision

Whether Fees/Payments needs a distinct `invoices` entity (a receivables
document grouping one or more `charges`, matching what a School expects
to see as a single "invoice" to a parent) or whether `charges` alone
are sufficient is **not decided by this checkpoint** — it is explicitly
an 0G.4 design question, to be answered once 0G.4 is scoped against
real fee-structure requirements (per-term fee, one-time fee, optional
fee, ...). Recorded here only so 0G.1 does not accidentally pre-commit
to either shape.

## Payment model

Defined conceptually (0G.5 implements; nothing here is built in this
checkpoint):

- **Payment record**: one row per attempted/settled payment, carrying
  the provider's own reference, an explicit status (e.g.
  `pending`/`settled`/`failed`), currency, and amount.
- **Provider event vs. business posting are not conflated.** A payment
  gateway callback saying "paid" triggers an idempotent Application-
  layer *operation* (validate → record/settle the `payments` row → post
  the corresponding `journal_entry` inside the same transaction, using
  `IdempotencyGuard`-style `completeWithin()` semantics, per
  `RELIABILITY.md`'s guidance for consequential mutations that cannot
  tolerate the generic middleware's crash-window gap) — the gateway
  event itself never becomes a ledger row; it is the *trigger* for one.
- **Refund/reversal**: a refund is authorized as its own explicit
  domain operation (`ARCHITECTURE.md` §10 rule 4), producing a
  reversing `journal_entry` (ADR 0030) plus a `refunds` row carrying
  the business authorization reference — never a mutation of the
  original payment/charge amount.
- **Allocation**: `payment_allocations` (0G.5) is how one payment
  settles one or more charges/invoice lines — deliberately separated
  from the `payments` row itself so a single payment covering multiple
  charges (or a partial payment against one charge) doesn't force an
  awkward one-payment-per-charge model.

## Payment-callback idempotency

Reuses the pattern Phase 0C already proved, applied to the *inbound*
direction (see "What already existed before this checkpoint" above for
why the existing outbound `webhook_deliveries` table is not directly
reusable):

- A dedicated, narrowly-scoped inbound endpoint per payment provider
  (never the general `/api/v1` surface — ADR 0018), with that
  provider's own signature-verification scheme.
- A `payment_provider_events` table (0G.5) whose uniqueness constraint
  is scoped `(school_id, provider, provider_event_id)` — the direct
  analog of `webhook_deliveries`' `(webhook_endpoint_id, event_id)`
  uniqueness, but for the opposite traffic direction, and explicitly
  **not** the same table, since outbound and inbound webhook delivery
  are, per this repository's own rule 35, never conflated.
- Atomic claim via that unique constraint (`INSERT`, catch
  `UniqueConstraintViolationException`, treat as a safe duplicate-
  delivery no-op) — the same structural pattern
  `App\Support\Idempotency\IdempotencyGuard::claim()` uses for client
  API idempotency (rule 30: never a check-then-insert race), applied
  to a different table because the scoping key is different (provider
  event id, not a client-supplied `Idempotency-Key`).
- This is **separate** from, and does not replace, the client-facing
  `Idempotency-Key` contract (`EnsureIdempotent` middleware) that any
  Finance API endpoint reachable from a browser/mobile client that
  itself needs retry-safety (e.g. "initiate a payment") would also use
  independently — `RELIABILITY.md`'s "Payment and webhook readiness"
  section already documents that a future Fees/Payments module needs
  both, and this document does not relitigate that.

## Provider-agnostic boundary

No specific payment gateway is chosen anywhere in this document or by
this checkpoint. 0G.5's Infrastructure-layer adapter
(`app/Domain/Finance/Infrastructure/` or a dedicated
`app/Domain/Payments/Infrastructure/`, depending on how 0G.4/0G.5 split
Finance vs. Fees vs. Payments into separate `Domain/` folders — an
0G.4 decision) isolates the real provider the same way every other
external dependency in this codebase is isolated (ADR 0013's AI-
provider isolation is the direct precedent ADR 0018 already cites). No
provider SDK dependency, no provider secret, no provider-specific
schema exists as of 0G.0.

## Audit vs. ledger

Two distinct, non-overlapping records of "what happened," matching ADR
0017's existing human-actor/AI-actor split pattern extended to a third
axis:

- **The ledger** (`journal_entries`/`journal_lines`) is the business/
  accounting *truth* — what the money did.
- **`school_audit_events`** (ADR 0017, already implemented) is who
  *triggered* a Finance action and when, under what authorization —
  e.g. `finance.charge.created`, `finance.payment.recorded`,
  `finance.posting.reversed` as illustrative future event-name
  candidates, **not finalized here** — exact event naming is decided
  when the Application service that raises them is actually written
  (0G.2 onward), matching how this repository has consistently deferred
  exact capability-string/event-name decisions to the checkpoint that
  implements them rather than guessing early.

No second, Finance-specific generic audit table is created, and no
ledger line is duplicated into audit metadata wholesale — the two
serve different questions and are correlated by entity reference
(`school_audit_events.entity_type`/`entity_id` pointing at the
relevant `journal_entries`/`charges`/`payments` row), the same
correlation mechanism ADR 0017 already establishes between the human
and AI audit trails.

## Authorization architecture

**Capability-based, never role-based** (CLAUDE.md rule 24,
`AUTHORIZATION.md`) — `AUTHORIZATION.md` already lists "Accountant" as
a financial-domain actor category; this checkpoint does not create any
capability string (that is 0G.3's job), only the conceptual families a
future capability seeder would register:

| Conceptual family | Likely scope |
|---|---|
| `finance.ledger.view` | Read chart of accounts, journal entries/lines. |
| `finance.ledger.post` | Post/reverse journal entries directly (a narrow, high-trust capability — most staff post *indirectly* via Fees/Payments operations, not directly against the ledger). |
| `finance.accounts.manage` | Create/deactivate ledger accounts (system-managed in 0G.1, so this is a platform/dev-only path until School-level configurability, if ever, arrives). |
| `finance.charges.view` / `finance.charges.manage` | 0G.4-scoped, once `charges` exists. |
| `finance.payments.view` / `finance.payments.manage` | 0G.5-scoped. |
| `finance.refunds.manage` | 0G.5-scoped, deliberately separate from `finance.payments.manage` — refund authorization is named explicitly in `ARCHITECTURE.md` §10 rule 4 as needing its own authorization requirement, not inherited from general payment-management access. |

No single `finance.manage` god-capability is proposed — the smallest
coherent split above separates ledger administration, receivables, and
payments/refunds, because these are exactly the operations
`ARCHITECTURE.md` §10 and `DATA-CLASSIFICATION.md` single out as
needing distinct scrutiny (refunds especially). Further fragmentation
(e.g. separate view-vs-manage per sub-area) is exactly what 0G.3 will
decide once real controller/service boundaries exist to hang
capabilities off of — premature to fully fix here.

### Student/Guardian self-service access

Explicitly **not** part of Phase 0G's core scope. A future Student/
Guardian financial portal (viewing their own charges/payment history)
is a relationship-scoped authorization problem (same shape as
`students.view`'s "own linked student only" pattern
`AUTHORIZATION.md` already describes for Guardians), not a broad
`finance.*` staff capability grant — deferred until a concrete
checkpoint needs it.

## Payroll boundary

Per `DOMAIN-MAP.md`, `Payroll` depends on `HR, Finance` — never the
reverse. The split: **Payroll calculates obligations** (salary
structures, statutory deductions, payroll-run computation — entirely
HR/Payroll's own domain logic); **Finance posts the resulting
accounting facts** (a payable/expense journal entry per payroll run,
and later the payment/settlement of that payable) through its ordinary
Application-layer posting service — Payroll is just another caller of
`LedgerService`, like any future Fees/Payments code. No employee
compensation logic is implemented in Finance, now or later, and no
Payroll code is touched by this checkpoint — Phase 8A (HR) remains
closed and unmodified.

## Admissions boundary

No integration exists today, and none is created by this checkpoint —
confirmed by inspection (see "What already existed before this
checkpoint" above: Admissions' current schema has no financial fields).
The **future** seam, recorded here only so a later Admissions
checkpoint doesn't have to rediscover it: an application fee or
enrollment deposit would eventually become a `charges` row (0G.4) whose
financial-subject FK is an `applicant_id` (added to `charges`' 0G.4
exclusive-arc column set only if/when Admissions actually needs it —
not pre-added speculatively now). Admissions would call into Finance's
Application-layer service to create that charge, the same
"Module A calls Module B's Application layer, never its tables" rule
every other cross-module relationship in this repository follows.
Nothing about this checkpoint requires the active
`feature/phase-1d-admissions-foundation` branch to change.

## Student/enrollment boundary

Phase 1B/1C (Student Enrollment/Subject Enrollment) are complete and
merged. A future tuition/enrollment/subject fee is initiated by
whatever caller has the business context (e.g. a future Fees-module
command triggered by enrollment creation, or a manual staff action) —
**not** by Finance subscribing to a Students/SIS domain event and
auto-generating charges, and not by an inline call from
`StudentEnrollmentService` into Finance. Coupling direction stays one-
way and explicit: Fees (0G.4, itself depending on Students/SIS per
`DOMAIN-MAP.md`) is the module that would own "when a Student enrolls,
what fee is owed," not Finance and not Students/SIS. No event listener,
no service call, no code of any kind is added in this checkpoint.

## Academic year vs. financial period

**Two distinct concepts, not conflated.** A `charges` row (0G.4) will
likely reference an `academic_year_id` (Academic Structure, composite
FK) because fee obligations are naturally scoped to a School's academic
calendar — this is a Fees-layer concern, not a ledger-layer one.
**Accounting-period close/lock** (a distinct, deferred concept — see
"Core entities," DEFERRED) is about when a `journal_entries` range is
considered "closed" for reporting/audit purposes, independent of
whichever academic year a specific charge happens to belong to. 0G.1
does not implement accounting-period support at all; if/when it is
added, it is its own table/column, not an overload of
`academic_year_id`.

## Semantic date vocabulary

Defined now so 0G.1 (and 0G.4/0G.5 later) don't collapse distinct
meanings into one ambiguous timestamp:

| Field | Meaning | Where it applies |
|---|---|---|
| `created_at` | Row insertion time (framework-standard). | Every table. |
| `posted_at` | When a journal entry became a financial fact. | `journal_entries`. |
| `effective_date` | The accounting date a transaction is attributed to (may differ from `posted_at` if backdated within policy — 0G.1/0G.2 must decide whether backdating is even permitted; not decided here). | `journal_entries`, if 0G.1 concludes it's needed. |
| `due_date` | When a charge/invoice is expected to be paid. | `charges`/`invoices` (0G.4). |
| `paid_at` | When a payment settled. | `payments` (0G.5). |
| `occurred_at` (provider) | The payment provider's own timestamp for the event, distinct from when School OS received/processed it. | `payment_provider_events` (0G.5). |
| `received_at` | When School OS's endpoint received the provider callback. | `payment_provider_events` (0G.5). |

## Balance derivation

Every balance (an account's balance, a charge's outstanding amount, a
Student's total dues) is **derived from postings/allocations**, never
read from a stored mutable aggregate column, per ADR 0030. A future
cached/materialized balance for read performance is allowed only if
explicitly labeled as a rebuildable cache with a documented
invalidation/rebuild path — not introduced speculatively now (no such
cache exists in 0G.1's design).

## Concurrency risks (catalogued for 0G.1+, not solved here)

| Risk | Mechanism that will address it |
|---|---|
| Duplicate payment callback | `payment_provider_events` unique constraint + atomic claim (0G.5). |
| Two users posting the "same" transaction concurrently | Each posting is its own `journal_entries` row — no shared mutable state to race on; the risk is business-level duplication, not a database race, and is addressed by the calling module's own idempotency (e.g. a client `Idempotency-Key` on a "record payment" endpoint), not by the ledger itself. |
| Two allocations against a shrinking invoice/charge balance | Deferred — `payment_allocations` doesn't exist until 0G.5; that checkpoint must design this explicitly (likely a `SELECT ... FOR UPDATE` or a derived-balance recheck inside the allocation transaction). |
| Double reversal of the same journal entry | Structural: unique index on `reversal_of_journal_entry_id` (ADR 0030) — a second reversal attempt fails the constraint, not a check-then-insert race. |
| Concurrent fee assessment (bulk charge generation) | Deferred to 0G.4 — likely needs its own idempotency key per assessment run, not decided here. |
| Provider retry of an already-processed callback | Same mechanism as "duplicate payment callback" above. |

## Database constraint philosophy

Prefer PostgreSQL-enforced invariants wherever a real mechanism exists
(same-School composite FKs, non-negative amounts, exactly-one-of
debit/credit via CHECK, unique `reversal_of_journal_entry_id`, unique
`(school_id, provider, provider_event_id)`). The one invariant that
genuinely cannot be a simple single-row `CHECK` — "this journal entry's
lines balance" — is explicitly named in ADR 0030 as needing a deferred
constraint trigger, not promised as a plain CHECK, and not silently
left to application code alone either (application-layer validation is
the 0G.1 floor, the trigger is the 0G.1 target).

## Delete policy

No Finance table exposes an Application-layer delete path for a posted
fact, ever. `ledger_accounts` may be deactivated (`status: inactive`)
but never deleted once it may have postings against it (rule 73's
reference-entity-lifecycle pattern, same as Academic Structure's
entities). `journal_entries`/`journal_lines` have no delete path at
all, from their first migration onward — corrections are reversals
(ADR 0030), not deletions. Laravel `SoftDeletes` is not used anywhere
in this module — "append-only" is a stronger, different guarantee than
"soft-deleted," and applying `SoftDeletes` here would misrepresent that
guarantee to a future reader of the schema.

## Identifiers

UUIDv7 (ADR 0019) for every Finance table's primary key, generated by
Laravel only. Human-facing reference numbers (a receipt number, an
invoice number) are a **separate, deferred** concern (see "Core
entities," DEFERRED, and "Non-goals") — never conflated with the
internal UUID primary key, and never used as a primary key themselves.
When designed (0G.4/0G.5), that numbering scheme's own concurrency-
safety (a School-scoped sequence, not `MAX(number)+1`) is a dedicated
design task for whichever checkpoint adds it.

## Reporting

Out of scope for the whole of Phase 0G as currently sequenced. A
future Analytics/Compliance consumer (Layer 5,
`docs/architecture/DOMAIN-MAP.md`) reads authoritative ledger/
receivable data via an explicit read contract or domain events — no
denormalized analytics schema, dashboard, or reporting-specific table
is designed or built by this module.

## API strategy

None in 0G.0 or 0G.1. 0G.6 adds thin controllers over 0G.2-0G.5's
Application services, following `SystemStatusController`'s established
"stay close to this shape" precedent (CLAUDE.md rule 3) — `school_id`
from trusted route/session context only, never request payload (rule
19), with an explicit idempotency-requirement review (rule 29) for any
posting/payment-initiation endpoint a client could plausibly retry.

## UI strategy

None until 0G.7. When built, an Inertia chart-of-accounts view and
ledger entry list, following the existing Academic Structure/HR page
patterns — no UI decision is made by this checkpoint.

## Test strategy (for 0G.1 onward — none run by this checkpoint)

Per `ARCHITECTURE.md` §11's table, at minimum: unit (Money value object
arithmetic), domain (posting/reversal balancing logic against
in-memory fakes), feature (real Postgres), tenancy isolation
(`RawIsolationTest`-style, real RLS, for every new table), authorization
(allow *and* deny, once 0G.3 exists), concurrency (real, not simulated
— double-reversal race, duplicate-callback race, per the table above),
API contract (once 0G.6 exists), and security regression (added
alongside any fix). No test is written in this checkpoint — it is
documentation/architecture only.

## Security register

Risk classification per the discovery template (P0 highest). **No
unresolved P0/P1 blocks 0G.1** — every P0/P1 item below is either
structurally resolved by a decision this document/ADR 0030 makes, or
explicitly, correctly deferred to the checkpoint that actually
introduces the relevant table/code (not yet a risk because the
surface doesn't exist).

| # | Risk | Status |
|---|---|---|
| P0 | Cross-School financial data leak | Resolved by design: RLS + composite same-School FKs on every table (established repo-wide pattern); mandatory cross-tenant test for every new table at 0G.1/0G.6. |
| P0 | Floating-point money | Resolved: `NUMERIC` only, `Money` value object via `bcmath`, no float anywhere in this module by design. |
| P0 | Unbalanced journal entry | Resolved by design: application-transaction validation floor + deferred-constraint-trigger target (ADR 0030). |
| P0 | Mutable posted financial facts / hard-delete of posted facts | Resolved by design: no update/delete Application-layer path, ever, for `journal_entries`/`journal_lines` (ADR 0030, "Delete policy" above). |
| P0 | Duplicate payment callback / double refund / double reversal | Resolved by design: `payment_provider_events` uniqueness (0G.5) + unique `reversal_of_journal_entry_id` (0G.1/ADR 0030). Concrete code lands at 0G.1 (reversal) and 0G.5 (callback), not yet a live risk. |
| P0 | Raw provider payload / card / bank secret storage | Resolved: prohibited outright by this document ("Prohibited payment data" below) and `DATA-CLASSIFICATION.md`'s existing Highly Sensitive tier — provider tokens/references only. |
| P0 | Role-based authorization / generic admin bypass | Resolved by design: capability families only (no role-name checks), no `finance.manage` god-capability proposed. |
| P0 | Caller-controlled School (`school_id` from request payload) | Resolved: same standing rule 19/20 as every other module — no new risk introduced, no exception proposed here. |
| P1 | Implicit currency | Resolved: explicit `currency` column mandatory on every monetary table, no locale/browser inference. |
| P1 | Payer/financial-subject confusion | Correctly deferred, not yet live — no financial-subject/payer column exists until 0G.4/0G.5, at which point the exclusive-arc pattern above governs it. |
| P1 | Audit/ledger conflation | Resolved: explicit distinction documented above; no merged table. |
| P1 | Cross-School provider-reference collision | Resolved by design: `payment_provider_events` uniqueness scoped `(school_id, provider, provider_event_id)`, never `provider_event_id` alone. |
| P2 | Concurrent allocation races | Correctly deferred — `payment_allocations` doesn't exist until 0G.5, which must design its own locking/recheck strategy (catalogued above, not solved). |
| P2 | Unsafe invoice/receipt sequencing | Correctly deferred to 0G.4/0G.5, explicitly flagged (not a `MAX(number)+1` shortcut). |
| P3 | Mutable balance column as canonical truth | Resolved by decision: derived-from-postings only (ADR 0030); a future cache must be explicitly labeled non-authoritative. |
| P4 | Reporting/analytics exposure | Out of scope for Phase 0G; not a current risk. |

**Architecture blockers before 0G.1: NONE.** The one decision that
could have blocked 0G.1 (the accounting model) is settled by ADR 0030.
The exact `NUMERIC` precision/scale and the exact chart-of-accounts
seed set are explicitly left to 0G.1 itself (not blockers — narrow,
bounded implementation decisions, not open architectural ambiguity).

## Prohibited payment data

School OS's own database never stores: full card PAN, CVV, raw bank
account/routing credentials, or a payment provider's own API
secret/credential. Only provider-issued tokens/references (a gateway
transaction id, a tokenized payment-method reference) are stored, per
`DATA-CLASSIFICATION.md`'s existing instruction. `payment_provider_events`
(0G.5) storing a provider's webhook payload must be reviewed at that
checkpoint for exactly which fields to persist — indiscriminately
storing an entire raw provider payload is not assumed safe by default,
since a provider payload could itself carry payment-instrument-shaped
data depending on the provider. PCI-DSS scope considerations remain
**[LEGAL/COMPLIANCE REVIEW REQUIRED]** once a real gateway is chosen
(0G.5), per `DATA-CLASSIFICATION.md` — this document does not resolve
that review, only flags it forward, as that document already does.

## Data classification

Every Finance table is **Highly Sensitive** by default
(`DATA-CLASSIFICATION.md`'s existing classification of "Financial data
(fees, invoices, payroll, payment instrument references)") — minimized
default visibility (fetched only when a specific capability needs it,
never in a broad list/summary view by default, once 0G.6/0G.7 build
any listing surface), access audited (ADR 0017), never logged/included
in error messages (`LogSanitizer`, existing repo-wide backstop, plus
Finance-specific caller discipline).

## Deferred / out of scope (this checkpoint and near-term)

Everything in "Non-goals" above, plus: Phase 5C Communications closure
documentation, the stale Students row in `DOMAIN-MAP.md`, Documents P3
orphan cleanup, the test-harness `env_file` precedence issue, webhook
delivery/attempt retention pruning — all pre-existing, unrelated
repository debt this checkpoint does not touch (see the Phase 0G.0
implementation report for the full list; not repeated here as Finance
scope).

## Roadmap relationship

Implements the start of `docs/roadmap/MASTER-ROADMAP.md`'s **Phase
0G — Finance and Fees**. Phase 0G is marked **IN PROGRESS** as of this
checkpoint (0G.0 complete; 0G.1 onward not started) — see the
corresponding `MASTER-ROADMAP.md` edit in this same checkpoint.
