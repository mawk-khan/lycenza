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
0G.1  Ledger Schema Foundation                    (ledger_accounts, journal_entries, journal_lines)   [implemented]
0G.2  Posting & Reversal Application Services      (LedgerService, balance/derivation reads)           [implemented]
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

**Reconfirmed, not narrowed, by 0G.3**: when 0G.3 built the first real
listing surface (`App\Domain\Finance\Application\LedgerReadService`),
this classification was deliberately left exactly as committed here —
see 0G.3's own "Data classification and read audit" subsection below
for why a narrower reading (e.g. treating the current no-Fees/Payroll/
Payment-linkage ledger kernel as merely Confidential) was considered
and rejected as an undocumented architecture change this checkpoint
was not scoped to make. Every 0G.3 read is audited accordingly.

## Deferred / out of scope (this checkpoint and near-term)

Everything in "Non-goals" above, plus: Phase 5C Communications closure
documentation, the stale Students row in `DOMAIN-MAP.md`, Documents P3
orphan cleanup, the test-harness `env_file` precedence issue, webhook
delivery/attempt retention pruning — all pre-existing, unrelated
repository debt this checkpoint does not touch (see the Phase 0G.0
implementation report for the full list; not repeated here as Finance
scope).

## 0G.1 as-built (Ledger Schema Foundation)

Everything above this section is the 0G.0 architecture/domain contract,
written before any code existed. This section records what 0G.1
actually built against it — a persistence kernel only (no Application
posting service, no HTTP, no capabilities, no audit events; those
remain 0G.2+). No implementation claim below exists anywhere above
this section; "Ledger structure (0G.1 design target)" above is left
exactly as 0G.0 wrote it, as the historical target this section reports
against, not silently rewritten to match the final schema.

### Tables and migrations

Four migrations,
`apps/platform/database/migrations/2026_08_31_090000..090300_*.php`
(chosen after inspecting `database/migrations` and the concurrently
active `feature/phase-1d-admissions-foundation` branch's migrations,
both dated no later than `2026_08_30`; no filename/table-name
collision). All four were corrected directly (not superseded by new
"fix" migrations) during the 0G.1 closure review, while still
uncommitted — see "Posted line-set immutability" and "Currency scope"
below for what changed and why:

- `ledger_accounts` — `id` (uuid pk), `school_id`, `code`, `name`,
  `type`, `currency` (char(3)), `is_system` (bool, default false),
  `status` (default `active`), `created_at`/`updated_at`.
- `journal_entries` — `id`, `school_id`, `currency` (char(3)),
  `description`, `posted_at` (default now()),
  `reversal_of_journal_entry_id` (nullable), `created_at` only (no
  `updated_at` — rule 33), `posting_txid` (`xid8`, database-computed —
  see "Posted line-set immutability" below).
- `journal_lines` — `id`, `school_id`, `journal_entry_id`,
  `ledger_account_id`, `currency` (char(3)), `debit_amount`
  `NUMERIC(14,2)` nullable, `credit_amount` `NUMERIC(14,2)` nullable,
  `created_at` only.
- The fourth migration,
  `2026_08_31_090300_add_journal_entry_posting_invariants.php`
  (renamed from `add_balanced_journal_entry_enforcement` during the
  closure review, since it now covers three invariants, not one:
  balance enforcement, posted line-set immutability, and — added by
  the posting-txid hardening pass — the unconditional `posting_txid`
  assignment trigger), adds all three functions/triggers — kept
  separate from the table-creation migrations so the table structure
  and the cross-table-invariant enforcement are each a single,
  independently reviewable unit.

Verified directly against PostgreSQL's catalog (`\d <table>` via
`pgsql_admin`), not just by reading the migration source — see
"Postgres catalog verification" below.

### Money precision/scale (resolved, not left arbitrary)

`NUMERIC(14,2)` for `journal_lines.debit_amount`/`credit_amount` —
the only monetary columns 0G.1 creates (`ledger_accounts` carries no
amount column). Scale 2 comes directly from FINANCE.md's own "Money"
section ("today: INR only, 2 decimal places / paise"). Precision 14
(12 integer digits + 2 fractional, i.e. up to
999,999,999,999.99) was chosen with deliberate headroom over any
single posting or aggregate this repository's domain will plausibly
produce (a whole School's total annual fee revenue, or a Payroll run's
total payable, in INR), while still being an explicit, bounded limit —
not an unconstrained `NUMERIC`, and not a number picked merely because
it's common (`NUMERIC(10,2)`/`NUMERIC(15,2)` were both explicitly
rejected as arbitrary — see the `journal_lines` migration's docblock).

### Currency scope: INR-only, database-enforced (closure-review correction)

The first draft of this section claimed "single-currency enforcement:
composite FKs" and "School currency source: NONE" — accurate about the
FK mechanism but imprecise about SCOPE: composite FKs alone only prove
one journal transaction can't mix currencies across its own lines; they
do NOT prove a School couldn't have, say, one INR account and a
separate USD account both under the same `school_id`. The 0G.1 closure
review required re-reading the binding text precisely rather than
inferring. FINANCE.md's own words are unambiguous and stronger than
mere per-School single-currency: the "Money" section says **"today:
INR only"**, and the "Currency" section says **"even though INR is the
only currency in scope today"** — Phase 0G is INR-only, globally, for
every School, not merely single-currency-per-School (which would still
technically permit School A = INR and School B = USD). This is now
enforced directly, not just implied:

- `ledger_accounts_currency_inr_only_check` — `CHECK (currency =
  'INR')`.
- `journal_entries_currency_inr_only_check` — `CHECK (currency =
  'INR')`.
- `journal_lines.currency` needs no separate CHECK — the composite
  foreign keys already restrict it to values that exist on an
  already-INR-only-checked parent row (see "Tenancy" below).

The explicit `currency` column is retained on every table (per
FINANCE.md's own "never inferred... hardcoded assumption" rule) — the
CHECK bounds its allowed VALUE for Phase 0G, it does not remove the
column. No School-level currency source (e.g. on `schools`) exists or
was added — none is needed while the whole system is INR-only. A
future multi-currency checkpoint loosens these two CHECK constraints
via its own migration + ADR (FINANCE.md's "Multi-currency: out of
scope for Phase 0G" already names this as the intended path) — it is
not silently widened here, and this checkpoint does not design what
that future School-currency-source mechanism would look like.

Proven directly, both by CHECK-constraint rejection AND by composite-FK
rejection (`tests/Feature/Finance/LedgerAccountTest.php`,
`CurrencyAndTenancyConsistencyTest.php`,
`JournalReversalStructuralTest.php`): a non-INR `ledger_accounts` row
is rejected even when well-formed (e.g. `USD`); a non-INR
`journal_entries` row is rejected, including a reversal attempt; a
`journal_lines` row whose currency doesn't match its entry, or doesn't
match its account, is rejected. Because INR-only makes it structurally
impossible to construct a real non-INR parent row at all, the
line/account-currency-mismatch test proves the account-side composite
FK the same way the line/entry-currency-mismatch test proves the
entry-side one, rather than pairing two different-but-valid
currencies — see those tests' own docblocks for the precise reasoning.

### Account types and code uniqueness

Five-category taxonomy exactly as FINANCE.md names it — `asset`,
`liability`, `equity`, `income`, `expense` (not "revenue") — enforced
by a database CHECK, not merely documented.

`code` is unique per School, case-insensitively, and this is now
genuinely database-enforced (closure-review correction): the first
draft used `App\Support\NormalizesCode` (uppercases on Eloquent
assignment) plus a plain `unique(school_id, code)` constraint — the
same pattern `GradeLevel`/`Subject` already use elsewhere in this
repository, and one `NormalizesCode`'s own docblock explicitly defends
as sufficient there ("the existing plain-string unique index already
enforces case-insensitive uniqueness for free"). That defense relies on
"every writer normalizes", which a raw SQL insert bypassing the
Eloquent mutator does not — proven directly: before this correction, a
raw lowercase `'cash'` insert after `'CASH'` already existed did NOT
collide. For a financial reference invariant, 0G.1 does not rely on
that assumption: `ledger_accounts_school_id_code_ci_unique`, a
PostgreSQL expression unique index on `(school_id, upper(code))`,
replaces the plain constraint — true case-insensitive uniqueness
enforced by the database itself, immune to any caller bypassing the
model layer (`NormalizesCode` is kept on the model too, for consistent
storage/display, but is no longer the uniqueness guarantee). The same
normalized code may still be reused by a different School (proven both
via Eloquent and via a raw cross-School insert).

No automatic code generation (no `MAX(code)+1`) — 0G.1 does not
implement account numbering, matching FINANCE.md's explicit deferral.

### Tenancy: RLS, UUIDv7, composite foreign keys

`App\Support\Tenancy\BelongsToSchool`/`TenantRls::enable()` on all
three tables (RLS enabled AND forced — verified via
`pg_class.relrowsecurity`/`relforcerowsecurity`, not just migration
source); UUIDv7 primary keys via `App\Support\Identifiers\GeneratesUuidV7`.
Composite same-School foreign keys everywhere a child row references a
School-scoped parent — extended, per this document's own "strong
pattern" suggestion, to `(id, school_id, currency)` rather than just
`(id, school_id)`, so the SAME foreign key that proves tenant
consistency also proves currency consistency:

- `journal_lines.(journal_entry_id, school_id, currency)` →
  `journal_entries.(id, school_id, currency)` — `ON DELETE CASCADE`
  (an admin-only concern; the runtime role cannot delete a
  `journal_entries` row at all — see "Append-only enforcement" below).
- `journal_lines.(ledger_account_id, school_id, currency)` →
  `ledger_accounts.(id, school_id, currency)` — `ON DELETE RESTRICT`
  (an account with any postings against it can never be deleted, even
  by an admin connection).
- `journal_entries.(reversal_of_journal_entry_id, school_id,
  currency)` → `journal_entries.(id, school_id, currency)`
  (self-referential — see "Reversal relationship" below).

No global/nullable School anywhere. Proven under the real
`school_os_app` runtime role, not merely `pgsql_admin`:
`rolsuper = false`, `rolbypassrls = false`
(`tests/Feature/Postgres/FinanceRawIsolationTest.php`).

### Append-only enforcement

`TenantRls::makeAppendOnly()` — the SAME existing mechanism
`school_audit_events` already uses (ADR 0017) — `REVOKE UPDATE, DELETE`
from `school_os_app` on `journal_entries` and `journal_lines`.
Verified two ways: (1) `information_schema.role_table_grants` shows
only `INSERT`/`SELECT` for `school_os_app` on both tables; (2) a raw
`UPDATE`/`DELETE` attempt under that role, even against the caller's
OWN School's OWN row, fails with a real "permission denied"
`QueryException` — not merely zero rows affected (contrast with
`ledger_accounts`, which is ordinary mutable reference data and is
NOT append-only — ADR 0030 only requires this for posted facts).
`ledger_accounts` keeps ordinary Laravel `timestamps()`;
`journal_entries`/`journal_lines` use `created_at` only
(`const UPDATED_AT = null` on both models) — there is no legitimate
mutation path to justify an `updated_at` column on either.

### Balance enforcement mechanism

A `CONSTRAINT TRIGGER ... DEFERRABLE INITIALLY DEFERRED` on
`journal_entries` (fires once per inserted HEADER row, not per line —
this is what makes the zero-lines case detectable, since a trigger on
`journal_lines` could never fire when zero lines exist), calling a
`plpgsql` function that `SELECT`s `count(*)`/`sum(debit_amount)`/
`sum(credit_amount)` from `journal_lines` for that one
`journal_entry_id` and raises a `check_violation` if fewer than two
lines exist or debits ≠ credits. No `SECURITY DEFINER` — the function
runs as the invoking role (the default, `SECURITY INVOKER`), which
already has correct RLS access to its own School's `journal_lines`
rows once `TenantContext` has set `app.current_school_id` for the
transaction; no elevated privilege was needed or granted.

Proven directly against real PostgreSQL, under the real
`school_os_app` role, not by inspecting migration SQL
(`tests/Feature/Finance/JournalEntryBalanceEnforcementTest.php`,
`tests/Feature/Postgres/FinanceRawIsolationTest.php`):

| Case | Result |
|---|---|
| Balanced two-line entry | commits |
| Balanced multi-line (3-line) entry | commits |
| Unbalanced entry | rejected |
| Header with zero lines | rejected |
| Single-line entry | rejected |
| Two entries, each individually unbalanced but globally balanced (100/80 and 80/100) | BOTH rejected — proves this is a per-entry check, not an accidental global-sum check |
| Two independently balanced entries in one transaction | both commit independently |

**Important dependency for 0G.2, found during this checkpoint's own
testing (not a defect — a real constraint of the chosen SECURITY
INVOKER design, documented here so 0G.2 doesn't rediscover it the hard
way):** because the trigger's own internal `SELECT` against
`journal_lines` is itself RLS-scoped, `app.current_school_id` must
still be set to the posting School at the moment the deferred check
actually runs (real `COMMIT`, or an explicit `SET CONSTRAINTS ALL
IMMEDIATE`) — not merely at the moment the rows were inserted. This is
automatically true for any real request/job (`TenantContext` stays set
for the entire unit of work, and Laravel's `DB::transaction()` commits
while that context is still active), so 0G.2's `LedgerService` needs no
special handling for this as long as it does not clear `TenantContext`
before its own transaction commits. It was NOT automatically true for
this checkpoint's own tests, which run inside `DatabaseTransactions`
and use `TenantContext::withSchool()` scoped narrowly around fixture
creation — the test suite's `forceConstraintCheck()` helpers had to be
written to re-enter `withSchool()` around the forced check itself; see
`JournalEntryBalanceEnforcementTest`'s docblock for the full
explanation, kept there rather than repeated in full here.

### Posted line-set immutability (closure-review addition)

ADR 0030 requires posted accounting facts to be immutable. The
original 0G.1 implementation proved `journal_entries`/`journal_lines`
UPDATE/DELETE are rejected (append-only enforcement above) but left a
real gap: nothing stopped a LATER, separate transaction from INSERTing
an ADDITIONAL `journal_lines` row — even a balanced debit+credit
pair — against an entry a PRIOR transaction had already committed.
Every existing row would stay untouched while historical financial
truth was silently extended. Found and fixed during the 0G.1 closure
review, before any of this was committed.

Mechanism: `journal_entries.posting_txid` (`xid8`, `NOT NULL DEFAULT
pg_current_xact_id()`) records, permanently, the exact transaction that
inserted the header row — internal persistence metadata, never a
Finance business attribute, never application-set. A `BEFORE INSERT`
trigger on `journal_lines` (`finance_reject_post_commit_line_insert()`,
deliberately IMMEDIATE, not deferred — it must distinguish "still
inside the original posting transaction" from "a later transaction"
for every single line insert, including the legitimate ones during
initial posting) compares the CURRENT transaction's id
(`pg_current_xact_id()`) against the target entry's stored
`posting_txid`: equal means this insert is part of the same atomic
"header + lines" transaction that created the header (allowed);
different means a separate, later transaction is attempting to extend
an already-posted entry (rejected). `xid8` (not the classic 32-bit
`xid`/`txid_current()`) is used specifically because it is 64-bit and
does not wrap around — appropriate for a financial ledger meant to
stay correct indefinitely. No `SECURITY DEFINER` — same SECURITY
INVOKER reasoning as the balance trigger (the function's own
`journal_entries` SELECT only reads the same School's row the line
already belongs to). Concurrency safety comes from ordinary MVCC
read-committed visibility, not a lock this trigger takes itself: a
concurrent session can't even see an entry that's still uncommitted in
another session (the composite foreign keys already reject that on
their own), so the trigger only ever needs to tell "my own still-open
transaction" apart from "an entry that already committed".

**`posting_txid` itself was not yet spoof-proof when this section was
first written — hardened in a follow-up pass, see "Posting_txid is
database-authoritative" immediately below.**

Proven directly against real PostgreSQL, under the real `school_os_app`
role (`tests/Feature/Finance/JournalEntryLineSetImmutabilityTest.php`,
`JournalEntryLineSetImmutabilityConcurrencyTest.php`) — each scenario
below starts from a GENUINELY COMMITTED entry (these tests disable
`DatabaseTransactions`, like `JournalReversalConcurrencyTest`, because
the whole point requires a real commit, not a rolled-back test
transaction):

| Case | Result |
|---|---|
| One additional debit line, later transaction | rejected |
| One additional credit line, later transaction | rejected |
| A balanced debit+credit pair, later transaction | rejected (the first of the two lines already trips the trigger) |
| A balanced multi-line extension, later transaction | rejected |
| Two real concurrent processes both attempting to extend the same committed entry | BOTH rejected (deterministic, not a race — neither process's transaction id can ever equal the already-recorded `posting_txid`) |
| Initial atomic posting (header + lines, same transaction) | still succeeds, unaffected |

Combined with append-only UPDATE/DELETE revocation, this is what makes
"a posted journal entry's balanced state cannot be invalidated by any
later raw SQL" actually true: existing rows can't be changed or
removed, and the row SET itself can't be extended either.

### Posting_txid is database-authoritative, never caller-chosen (posting-txid hardening pass)

The line-set-immutability trigger above trusts `journal_entries.
posting_txid` completely — but a plain column `DEFAULT
pg_current_xact_id()` only applies when a caller OMITS the column
entirely; it does NOT stop a raw INSERT from explicitly supplying an
arbitrary `xid8` value. Before this pass, the original "database-
computed, never application-set" claim was true for every ordinary
caller (Eloquent never sets it) but not yet STRUCTURALLY true — a raw
SQL insert could have written any `posting_txid` it chose, including a
value engineered to collide with a transaction id a future insert
might present, which would have let that future transaction extend the
entry's line set as if it were still the original posting. Found and
fixed in a dedicated hardening pass, before any of this was committed.

Fix: `finance_set_journal_entry_posting_txid()`, an UNCONDITIONAL
`BEFORE INSERT` trigger on `journal_entries` (added alongside the other
two triggers in the same posting-invariants migration), assigns
`NEW.posting_txid := pg_current_xact_id();` for every single insert,
with no `IF NEW.posting_txid IS NULL THEN` guard — it always overwrites
whatever the caller provided (or didn't provide). The column's own
`DEFAULT` is RETAINED (not removed) purely as harmless, redundant
convenience — whatever value it fills in is immediately overwritten by
this trigger regardless, so keeping it costs nothing and removing it
would be schema churn with no invariant benefit — but it is no longer
what makes the column trustworthy; the trigger is the sole source of
truth. No `SECURITY DEFINER` — this trigger only ever touches the row
already being inserted (`NEW`), needing no cross-row access at all,
the simplest possible case for `SECURITY INVOKER`.

Proven directly against real PostgreSQL, under the real `school_os_app`
role, via a raw SQL spoof attempt
(`tests/Feature/Finance/JournalEntryPostingTxidHardeningTest.php`):

| Case | Result |
|---|---|
| Raw INSERT explicitly supplying `posting_txid = '1'::xid8` | the value is silently overwritten; the row's stored `posting_txid` equals the real `pg_current_xact_id()` of the actual inserting transaction, never `'1'` |
| A balanced entry created with a spoofed `posting_txid`, committed, then a later transaction attempts one additional debit line | rejected |
| Same spoofed-creation entry, later transaction attempts a balanced debit+credit pair | rejected |

The historical-bypass proof matters specifically: it is not enough that
the spoof gets overwritten at INSERT time in isolation — it must also
be true that the entry's stored (now-genuine) `posting_txid` still
correctly blocks a later extension attempt, i.e. the attacker gains
nothing by trying. Both tests confirm this.

`posting_txid` remains pure internal PostgreSQL transaction-identity
metadata for this whole mechanism — it is never exposed as Finance
business terminology, never in any model's `$fillable`
(`App\Domain\Finance\Infrastructure\JournalEntry`'s docblock states
this explicitly), and not a concept any future Finance DTO/API
contract should ever need to carry.

### Reversal relationship

Structural, per ADR 0030's "Reversal linkage direction": the reversing
entry's `reversal_of_journal_entry_id` points at the original; the
original is never written to again. All proven directly, all
IMMEDIATE constraints (no `SET CONSTRAINTS` needed —
`tests/Feature/Finance/JournalReversalStructuralTest.php`):

- Same School: required (composite FK).
- Same currency: required (the same composite FK, extended with
  `currency`).
- Self-reversal: rejected (`CHECK (reversal_of_journal_entry_id IS
  NULL OR reversal_of_journal_entry_id <> id)`).
- A second reversal of the same original: rejected (partial unique
  index `ON journal_entries (reversal_of_journal_entry_id) WHERE
  reversal_of_journal_entry_id IS NOT NULL`).
- A reversal of a nonexistent original: rejected (FK violation).
- Concurrent double reversal: proven with two REAL, separate OS
  processes (`Symfony\Process`, mirroring
  `AcademicYearActivationConcurrencyTest`'s established pattern —
  `tests/Feature/Finance/JournalReversalConcurrencyTest.php` +
  `tests/Support/reverse-journal-entry.php`) racing to reverse the
  SAME original entry: exactly one succeeds, the other fails with a
  real database exception (the partial unique index), and the database
  contains exactly one reversal afterward.
- Semantic correctness of a reversal's LINES (that they actually invert
  the original's debits/credits) is explicitly **not** proven here —
  there is no reversal Application service yet; 0G.2 owns that
  behavior and its own test coverage.

### Money value object

`App\Support\Money\Money` (`apps/platform/app/Support/Money/Money.php`),
implemented as committed: immutable (`final`, `readonly` properties),
amount stored as the exact decimal string given (never reformatted,
never routed through float), explicit ISO-4217-shaped currency.

**Composer/platform-requirement precision (closure-review correction):**
the first draft of this document said "no new dependency" without
distinguishing a PACKAGE from a PLATFORM REQUIREMENT — imprecise.
`composer.json`'s `require` block gained `"ext-bcmath": "*"`, which is
genuinely a NEW entry (confirmed by `git diff` — absent before 0G.1).
This is correctly still **not a new package dependency** (`bcmath` is
a PHP core extension, already compiled into the platform Docker image
per `infrastructure/docker/platform.Dockerfile`, pulling in no vendor
code) but it IS a new, explicit **platform requirement** — Composer
now enforces it. It is not premature: `Money::equals()`/`isZero()`/
`isPositive()`/`isNegative()` call `bccomp()` directly, today, and
`MoneyTest` exercises all four — this is real, currently-executed
0G.1 code, not a reservation for hypothetical 0G.2 arithmetic.
`composer.lock`'s `content-hash` and `platform` block were stale
relative to this `composer.json` change (`composer validate` failed
until corrected) — resynchronized with `composer update --lock`
(hash/platform-metadata only; verified via `git diff` that no package
version changed). `composer validate`: PASS.

Deliberately scoped to
representation/validation only, per this document's own "Application
representation" boundary — no `add()`/`subtract()`/`multiply()`;
`negated()` is the one exception, included because it is pure sign
manipulation (no arithmetic), useful to 0G.2 when building a
reversal's inverted lines. Real Money arithmetic is explicitly 0G.2's
concern (see `Money`'s own docblock for the stated boundary).

**A real defect was found and fixed during this checkpoint's own unit
testing**, worth recording precisely because it's a subtle PHP
semantics trap easy to reintroduce: the first draft typed
`of(string $amount, string $currency)` under `declare(strict_types=1)`
in `Money.php`, on the (incorrect) assumption that this would make a
`float` argument raise a `TypeError`. PHP's `strict_types` is
determined by the CALLING file's own declaration, not the declared
function's file — a caller without `declare(strict_types=1)` (proven
directly by `MoneyTest`, which deliberately has no such declaration)
would have its `float` argument silently coerced to a string BEFORE
`string $amount` ever got a chance to reject it, defeating the "no
float input" guarantee entirely regardless of what `Money.php` itself
declared. Fixed by typing `of()`'s parameters `mixed` and explicitly
checking `is_string()` before any other validation — the only
caller-independent way to guarantee this. `MoneyTest::
float_input_is_rejected_even_from_a_caller_without_strict_types`
exists specifically to keep this proven, not merely asserted.

Unit-tested in isolation (`tests/Unit/Support/Money/MoneyTest.php`,
34 assertions): valid/invalid decimal shapes (including scientific
notation, leading zeros, leading `+`, whitespace, `INF`/`NAN`), float
rejection (the case above), currency format/case validation, value
equality across different string forms (`"1"` == `"1.0"`), sign
predicates, `negated()`, and that JSON/string serialization preserves
the exact decimal string.

### No canonical mutable balance, no polymorphic financial subject

Both explicitly checked against the live PostgreSQL catalog, not just
asserted in prose (`information_schema.columns`,
`FinanceRawIsolationTest`/`LedgerAccountTest`): none of the three
tables has a `balance`/`current_balance`/`running_balance`/
`account_balance` column; neither `journal_entries` nor `journal_lines`
has a `subject_type`/`subject_id`/`payable_type`/`payable_id`/
`owner_type`/`owner_id` column.

### Models, factories, authoritative test selector

Models: `App\Domain\Finance\Infrastructure\{LedgerAccount,JournalEntry,JournalLine}`
— `BelongsToSchool`, `GeneratesUuidV7`, `HasFactory`; no posting/
reversal business methods (those are 0G.2's Application-layer
services, not model behavior). `JournalLine::debit()`/`credit()`
return `?Money`, built from the raw (uncast, never-through-float)
NUMERIC string column plus the row's own currency — deliberately NOT
using Laravel's built-in `decimal:2` Eloquent cast, which internally
routes its value through `sprintf('%.2F', ...)` (a float coercion) —
seemingly a minor implementation choice, but exactly the class of
mistake `ARCHITECTURE.md` §10 rule 1 exists to prevent, so called out
explicitly here.

Factories (`database/factories/{LedgerAccountFactory,JournalEntryFactory,JournalLineFactory}.php`)
are deliberately NOT safe to call bare for `JournalEntry`/`JournalLine`
— `JournalLineFactory`'s default nested associations resolve to three
DIFFERENT Schools, so a bare `->create()` fails immediately (an
IMMEDIATE, not deferred, FK violation) rather than silently producing
a cross-School line. `Tests\Concerns\CreatesFinanceFixtures::
postBalancedJournalEntry()` is the one intentional, safe way these
tests build a real, valid posted entry — see both files' docblocks.

Authoritative Finance test selector for future checkpoints:

```
php artisan test \
  tests/Feature/Finance \
  tests/Feature/Postgres/FinanceRawIsolationTest.php \
  tests/Unit/Support/Money
```

97 tests, 129 assertions, 0 failures (includes all three real
two-process/multi-statement proofs — reversal concurrency, post-commit
line-set extension concurrency, and the posting-txid raw-SQL spoof
proof). Full suite: 2540 tests / 8654 assertions / 0 failures — the
fresh pre-code baseline (2443/8525/0, captured in an isolated
`finance0g1-baseline` Docker stack before any 0G.1 file was written)
plus exactly these 97 new tests, no regressions elsewhere. (Two
intermediate counts existed briefly during this checkpoint's earlier
passes — 84/114/2527/8639 before the closure review, then
94/125/2537/8650 after it and before the posting-txid hardening pass
added 3 more tests — neither reported as a separate baseline, noted
here only so the numbers in git/session history stay self-consistent.)

### Migration reversibility

Proven on the isolated `finance0g1` PostgreSQL instance, re-verified
after both the closure-review corrections and the posting-txid
hardening pass: clean install of all four migrations in order;
`migrate:rollback --step=4` removes all three tables, all THREE
triggers, and all three trigger functions cleanly (confirmed via
`\dt`/`pg_proc`/`pg_trigger` showing nothing remains); reapplying
restores the identical schema/constraints/RLS/triggers/`posting_txid`
column/expression index. No migration predating Finance was touched;
the four Finance migrations themselves were corrected in place (not
superseded by new "fix" migrations) since they remained uncommitted
throughout every pass.

### Quality gates

Pint: 999 files, 0 style issues (7 issues auto-fixed across the two
earlier passes -- import ordering/unused imports in the first pass,
one `phpdoc_align` after the closure-review model docblock addition;
the posting-txid hardening pass introduced none). PHPStan/Larastan
(`--memory-limit=512M`, the repository's configured level): 0 errors,
no new baseline suppressions, across all three passes.

### What 0G.1 intentionally does not implement

No Application posting/reversal service (0G.2); no `finance.*`
capabilities/authorization (0G.3); no `charges`/`invoices`/`payments`/
`payment_provider_events`/`refunds` (0G.4/0G.5); no HTTP/API/OpenAPI
(0G.6); no UI (0G.7); no audit-event emission (no Application service
exists yet to raise one); no chart-of-accounts seed data (factories
only); no semantic proof that a reversal's lines actually invert the
original's amounts (structural relationship only — 0G.2's concern).

### Security findings (0G.1, updated after closure review and the posting-txid hardening pass)

No unresolved P0/P1/P2. Three real findings surfaced across the two
correction passes, all since resolved by schema correction (not merely
documentation):

- **P1 (resolved): posted line-set mutability.** A later transaction
  could INSERT additional lines — including a balanced pair — against
  an already-committed entry, silently extending historical financial
  truth while every existing row stayed untouched. Resolved by the
  `posting_txid`/`BEFORE INSERT` trigger mechanism ("Posted line-set
  immutability" above); proven rejected for single-line, balanced-pair,
  and balanced-multi-line extension attempts, including under real
  two-process concurrency.
- **P1 (resolved): `posting_txid` was caller-spoofable via raw SQL.**
  The line-set-immutability fix above trusted `journal_entries.
  posting_txid` completely, but that column's `DEFAULT
  pg_current_xact_id()` only fills a value when a caller omits the
  column — a raw INSERT could still explicitly supply an arbitrary
  `xid8`, which could have let a spoofed value defeat the very
  protection it was meant to provide. Resolved by
  `finance_set_journal_entry_posting_txid()`, an unconditional `BEFORE
  INSERT` trigger that overwrites `posting_txid` on every insert
  regardless of any caller-supplied value ("Posting_txid is
  database-authoritative" above); proven both that a raw spoof attempt
  is overwritten AND that the spoof cannot be used to later reopen the
  entry's line set.
- **P2 (resolved): currency scope overclaim.** The original report
  described "single-currency enforcement" backed only by composite
  FKs, which genuinely prevents one transaction from mixing currencies
  but does not prevent a School from holding several currencies across
  separate accounts/entries. Resolved by adding
  `ledger_accounts_currency_inr_only_check`/
  `journal_entries_currency_inr_only_check`, matching FINANCE.md's own
  literal "today: INR only" scope — not a design change, a database
  enforcement of what the binding text already said.
- **P3 (resolved): account-code case-insensitive uniqueness relied on
  Eloquent normalization alone.** A raw SQL insert bypassing
  `NormalizesCode` could create a duplicate account code differing only
  in case — the same latent gap `NormalizesCode`'s own docblock
  accepts for non-financial reference tables elsewhere in this
  repository, but not acceptable for a Highly Sensitive financial
  reference invariant. Resolved by the
  `ledger_accounts_school_id_code_ci_unique` expression unique index.
- **P4 (informational, resolved by documentation): composer.json
  characterization.** The original report said "no dependency change"
  without distinguishing a package from a platform requirement.
  `ext-bcmath` is a genuine new platform-requirement entry, correctly
  attributed and justified ("Money value object" above); `composer.lock`
  was resynchronized (`composer validate`: PASS).

Every other risk this checkpoint's security review considered remains
either resolved by the schema/trigger/grant design (cross-School leak,
float money, unbalanced posting, mutable posted facts, double
reversal/self reversal, role-based authorization, caller-controlled
`school_id`, mutable canonical balance, polymorphic financial subject)
or correctly out of scope because the relevant surface does not exist
yet (payment-provider idempotency, payer/financial-subject confusion,
allocation races, invoice numbering, reporting exposure — all 0G.4/
0G.5+ concerns, catalogued in "Core entities"/"Concurrency risks"
above, unchanged by this checkpoint). The SECURITY INVOKER/
TenantContext-timing dependency documented under "Balance enforcement
mechanism" above remains a genuine, non-blocking, documentation-
resolved dependency (the natural request/job lifecycle already
satisfies it) — the new line-set-immutability trigger shares the same
property and the same resolution.

### Next checkpoint boundary

0G.2 — Posting & Reversal Application Services: a `LedgerService` (or
equivalent) that performs the transactional "insert balanced header +
lines" operation this checkpoint's tests currently perform ad hoc
inline; reversal creation that also inverts and copies the original's
lines (proving amounts, not just structure); real Money arithmetic
where a caller genuinely needs it (e.g. verifying a proposed set of
lines balances before attempting to post, ahead of the database's own
authoritative check); domain events raised through the existing
transactional outbox (ADR 0025); `school_audit_events` entries for
posting/reversal operations once a real Application service exists to
raise them.

## 0G.2 as-built (Ledger Posting & Reversal Application Services)

Everything above this section (through "0G.1 as-built") is the
persistence kernel 0G.1 built and the closure/hardening corrections
applied to it. This section records what 0G.2 built on top of it: the
one sanctioned Application-layer write path for posting and reversing
journal entries. Zero migrations, zero schema changes — every
invariant 0G.1 established (RLS, append-only, `posting_txid`
hardening, line-set immutability, deferred balance trigger,
INR-only CHECKs, case-insensitive account-code uniqueness) is
preserved exactly; 0G.2 adds Application-layer pre-checks in front of
those defenses, it never replaces or weakens any of them.

### LedgerService

`App\Domain\Finance\Application\LedgerService`
(`apps/platform/app/Domain/Finance/Application/LedgerService.php`) —
the only sanctioned write path for `journal_entries`/`journal_lines`,
mirroring `App\Domain\AcademicStructure\Application\AcademicYearService`'s
established shape exactly (validate → write state → audit → emit
domain event, inside one transaction, ADR 0025). Two public methods:

- `post(School $school, PostJournalEntryData $data, ?User $actor = null): JournalEntryResult`
- `reverse(JournalEntry $original, ?User $actor = null, ?string $reason = null): JournalEntryResult`

(Closure-review correction: both originally returned the raw
`JournalEntry` Eloquent model; see "Application result boundary" below
for why and how this changed. `reverse()`'s input parameter is still
the real `JournalEntry` model — only the *return* type changed.)

**Not an authorization boundary.** `$actor` is passed through to
`AuditRecorder` purely for WHO-did-this provenance in the audit trail —
`LedgerService` performs no capability/permission check of any kind.
Actor provenance is not authorization; whether a caller is allowed to
invoke `post()`/`reverse()` at all remains entirely the calling layer's
responsibility until 0G.3 (Finance Authorization & Administrative Read
Model) exists. This distinction is stated explicitly in the class's own
docblock, not left implicit.

### Trusted School boundary

`post()` takes an explicit, trusted `School $school` parameter — no
line or the `PostJournalEntryData`/`JournalLineData` DTOs carry a
`school_id` field at all, so a caller cannot make one line claim a
different School than the posting School. `reverse()` takes an
already-resolved `JournalEntry $original` (the caller's own
responsibility to load correctly, e.g. via `JournalEntry::findOrFail()`
under the correct `TenantContext`) — since `JournalEntry` uses
`BelongsToSchool`'s `SchoolScope`, a cross-School id is simply "not
found" at that point, before `LedgerService` is ever reached; no
special-case code exists inside `LedgerService` for this, the framework
already makes it impossible. Both methods wrap their entire
validate-through-commit sequence in `TenantContext::withSchool()` —
see "TenantContext-through-commit" below.

### Typed posting input

`App\Domain\Finance\Application\PostJournalEntryData` (`currency`,
`description`, `list<JournalLineData> $lines`) and
`App\Domain\Finance\Application\JournalLineData` (`ledgerAccountId`,
`App\Domain\Finance\Domain\JournalSide $side`, `Money $amount`) —
matching `App\Domain\Documents\Application\CreateDocumentData`'s
established "typed input class, not an unbounded array" precedent.
`JournalSide` (`app/Domain/Finance/Domain/JournalSide.php`) is a native
PHP backed enum (`Debit`/`Credit`), matching the repository's existing
enum convention (`App\Domain\Communications\Domain\CommunicationPriority`
and siblings) — the side is always stated explicitly by the caller,
never inferred from a positive/negative amount sign (`Money` amounts
passed to `JournalLineData` are always the same-signed magnitude;
`LedgerService` maps `side` onto `debit_amount`/`credit_amount`
directly). Neither DTO carries `posted_at` — 0G.2 does not support
caller-specified backdating; `posted_at` is always the real database
`now()` at insert time (the column's existing `useCurrent()` default),
a deliberate, documented decision, not an oversight (FINANCE.md's
"Semantic date vocabulary" `effective_date` row remains unimplemented).

### Money arithmetic added

`App\Support\Money\Money::add()` — the one arithmetic operation
`LedgerService` actually needs (summing a proposed posting's lines to
prove they balance before ever reaching PostgreSQL). Exact (bcmath,
never float), requires matching currencies (throws
`InvalidMoneyException` otherwise — Money's own semantic safety is
independent of and additional to the database's INR-only scope
restriction), uses the larger of its two operands' own natural decimal
scale so results are exact and canonical (no fixed padding, no
rounding). No `subtract()`/`multiply()`/`divide()` were added —
`negated()` (0G.1) is sufficient for reversal's line-inversion needs,
which is a sign flip, never real subtraction. 41 assertions across 7
new `MoneyTest` cases, including the classic float-unsafe `0.10 + 0.20
== 0.30` combination, a `NUMERIC(14,2)`-boundary large amount, currency
mismatch rejection, and immutability.

### Account validation and lookup

`LedgerService::resolveAccounts()` issues exactly ONE query against
`ledger_accounts` (a single `whereIn('id', $ids)`) regardless of how
many lines reference how many distinct accounts — proven directly by
counting logged queries for 2-line, 20-line, and 100-line posts, all
exactly 1 (section 38/62's N+1 concern). `SchoolScope` (via the already
-active `TenantContext`) already restricts the result to the trusted
School's own accounts, so a missing id and a cross-School id are
indistinguishable — both raise the identical `LedgerAccountNotFoundException`
with the identical message shape (no oracle). **Account status
(`active`/`inactive`) is deliberately NOT checked by `post()`** —
FINANCE.md's "Account model" section defines `status` purely as a
lifecycle/no-delete-once-referenced field (rule 73's reference-entity
pattern); it never states whether an inactive account may still receive
new postings. Enforcing "inactive blocks new postings" would be
inventing a business rule 0G.0/0G.1 never committed to, which the 0G.2
brief explicitly warned against ("if status semantics do NOT yet
establish this: do not invent it"). This is recorded here as a
DEFERRED, NAMED decision for a future checkpoint (0G.3 or a dedicated
FINANCE.md amendment) to settle deliberately — not a silent gap.
Duplicate account ids across lines of the same posting are explicitly
ALLOWED (a real, valid accounting shape — balance is based on line
amounts/sides, never distinct-account count).

### Posting validation (Application layer, before any database write)

`LedgerService::assertValidShape()`/`assertLineAmountFitsLedgerSchema()`/
`assertBalanced()` run entirely BEFORE `DB::transaction()` even opens
(shape/currency/amount-format/balance checks) or as the very first
statement inside it (account resolution) — so an invalid posting
attempt writes nothing at all, not merely "rolls back nothing." Checked,
each with its own `InvalidJournalEntryException`/
`InvalidJournalCurrencyException`/`UnbalancedJournalEntryException`/
`LedgerAccountNotFoundException`:

| Check | Exception |
|---|---|
| Fewer than 2 lines | `InvalidJournalEntryException` |
| Empty or >255-character description | `InvalidJournalEntryException` |
| Zero-amount line | `InvalidJournalEntryException` |
| Negative-amount line | `InvalidJournalEntryException` |
| More than 2 decimal places (would be silently ROUNDED by `NUMERIC(14,2)`, not rejected, if let through) | `InvalidJournalEntryException` |
| More than 12 integer digits (`NUMERIC(14,2)` overflow) | `InvalidJournalEntryException` |
| Entry currency other than `INR` | `InvalidJournalCurrencyException` |
| A line's Money currency ≠ the entry's declared currency | `InvalidJournalCurrencyException` |
| Missing/cross-School ledger account | `LedgerAccountNotFoundException` |
| Total debits ≠ total credits (exact Money arithmetic) | `UnbalancedJournalEntryException` |

"Total > 0" (the 0G.2 brief's own phrasing) is NOT a separate check —
it is structurally implied by "≥2 lines" + "every line amount strictly
positive" + "debits == credits": if both totals are equal and at least
one line contributes a positive amount to one side, neither total can
be zero. Adding an explicit `isZero()` check after the other three
would be unreachable dead code, so it was deliberately omitted (with
this reasoning recorded in the service's own docblock, not just here).

**PostgreSQL's own defenses remain the mandatory, authoritative
enforcement regardless of whether this Application-layer pre-check ever
ran correctly** — the `..._exactly_one_side_check` CHECK, the
`..._currency_inr_only_check` CHECKs, the composite foreign keys, and
the deferred balance-check constraint trigger are all still in place,
unmodified, and still independently tested by 0G.1's raw-SQL suites.

### Database transaction boundary

Every `post()`/`reverse()` call is exactly one PostgreSQL transaction:
header insert, every line insert, the audit event, and the outbox event
all commit together or none of them do. Actual write order inside that
transaction: header → lines → audit → domain event/outbox — recorded
here because it IS observable (e.g. which write a failure-injection
trigger catches), but the order itself provides no atomicity guarantee
on its own; the enclosing `DB::transaction()` is the sole consistency
boundary regardless of order (`LedgerService::post()`'s own docblock
states this explicitly).

**Proven two ways, at two different strengths (closure-review
correction — the first proof alone was judged insufficient):**

1. **Outer-transaction-wrap proof** (weaker — proves transaction
   PARTICIPATION): mirroring
   `AcademicYearLifecycleTest::a_rolled_back_activation_leaves_neither_the_state_change_nor_the_event()`'s
   established pattern, wrapping a `post()`/`reverse()` call in an
   OUTER `DB::transaction()` that throws immediately afterward forces
   Laravel's savepoint nesting to roll back everything the service
   did. `LedgerServicePostTest::a_rolled_back_posting_leaves_no_entry_no_lines_and_no_event()`
   and `LedgerServiceReverseTest::a_rolled_back_reversal_leaves_the_original_intact_and_no_reversal_or_event()`
   still exist and still pass, but this alone does not prove that a
   failure INSIDE one specific write step rolls back the steps that
   already "succeeded" earlier in the SAME transaction.
2. **Real failure-injection proof** (stronger — proves the actual
   claim): `tests/Feature/Finance/LedgerServiceAtomicityTest.php`
   injects a GENUINE PostgreSQL failure at each specific step, using a
   temporary, uniquely-named `BEFORE INSERT` trigger (created via the
   `pgsql_admin` connection, guaranteed removed in a `finally` block —
   pure test-only DDL, no production schema/migration touched) that
   unconditionally `RAISE EXCEPTION`s on the target table:
   - `a_journal_line_insert_failure_rolls_back_the_header_and_every_other_line()`:
     the trigger targets `journal_lines`; the header insert succeeds
     first (within the still-open transaction), the first line insert
     then fails; asserts zero `journal_entries`, zero `journal_lines`,
     zero audit events, zero outbox events afterward.
   - `an_audit_recording_failure_rolls_back_the_entire_posting()`: the
     trigger targets `school_audit_events`; the header and every line
     insert succeed first; `AuditRecorder`'s own INSERT then fails;
     asserts the already-inserted entry and lines are rolled back too,
     not merely that the audit row is missing.
   - `an_outbox_recording_failure_rolls_back_the_entire_posting_including_the_already_written_audit_event()`:
     the trigger targets `domain_event_outbox`; header, lines, AND the
     audit event succeed first; `RecordDomainEventToOutbox`'s own
     INSERT then fails; asserts the entry, lines, AND the audit event
     that had already "succeeded" earlier in the same transaction are
     ALL rolled back — this is the specific claim ("financial truth
     must not commit if required outbox recording fails") the weaker
     proof could not establish.
   - `a_journal_line_insert_failure_during_reversal_leaves_the_original_untouched_and_no_reversal_artifacts()`:
     the same `journal_lines` trigger, applied to `reverse()` instead
     — captures the original entry's full row + line state before the
     attempt, asserts it is byte-identical afterward, and asserts zero
     reversal entry/audit/outbox rows exist.
   - `audit_and_outbox_models_use_the_same_default_connection_as_the_ledger_writes()`:
     confirms directly (`getConnectionName()`) that `SchoolAuditEvent`/
     `DomainEventOutbox`/`JournalEntry` all use the same default
     connection — the structural precondition that makes the four
     tests above meaningful at all (a different connection, an
     `afterCommit` hook, or a queued/asynchronous write would make
     none of this atomicity claim true, regardless of what the tests
     appeared to show).

   A real, non-obvious implementation pitfall was found and fixed
   while building this file: these tests deliberately do NOT use
   `Tests\TestCase`'s normal `DatabaseTransactions` wrapping
   (`$connectionsToTransact = []`, matching the existing real-
   concurrency tests' established pattern) — `CREATE TRIGGER` requires
   a `SHARE ROW EXCLUSIVE` lock, which conflicts with the `ROW
   EXCLUSIVE` lock any earlier INSERT into the same table already
   holds for the remainder of an open transaction. Under the ordinary
   wrapper, a test that first creates a real journal entry (inserting
   into `journal_lines`) and THEN tries to install a failing trigger ON
   `journal_lines` via the separate `pgsql_admin` connection would
   self-deadlock: the same single PHP process can never release that
   lock (by committing) while it is itself blocked waiting on the
   `pgsql_admin` statement to complete. Disabling the wrapper (so every
   statement genuinely commits immediately) removes this deadlock
   class entirely; manual cleanup (deleting the created School, which
   cascades) replaces the automatic rollback, exactly like
   `JournalReversalConcurrencyTest`/`JournalEntryLineSetImmutabilityConcurrencyTest`
   already do for the same underlying reason (real, separate
   committed state to reason about).

   A second, smaller bug was found and fixed in the same pass: these
   tests' own `domain_event_outbox` count queries initially forgot to
   filter by `school_id` (unlike `journal_entries`/`journal_lines`/
   `school_audit_events`, `DomainEventOutbox` carries no `SchoolScope`/
   RLS at all), so an unscoped count picked up OTHER tests' genuinely-
   committed outbox rows (real rows, correctly persisting per ADR
   0025's durability guarantee, not a rollback failure) and produced a
   false failure. Fixed by scoping every `DomainEventOutbox` query in
   this file to the specific School each test created.

**A note on what could not be independently exercised**: because
`assertBalanced()` makes it structurally impossible to submit an
unbalanced posting through `LedgerService::post()` itself (rejected
before any database write), the DEFERRED-TRIGGER-rejects-at-commit
scenario cannot be triggered through the service — it remains proven
exhaustively at the raw-SQL level by 0G.1's (unchanged, still
authoritative) `JournalEntryBalanceEnforcementTest`. This is the
correct, expected consequence of the Application-layer check being
airtight, not a gap in coverage.

### TenantContext-through-commit requirement

0G.1 discovered that Finance's `SECURITY INVOKER` triggers (the
balance check, the post-commit line-set-immutability check, the
`posting_txid` assignment) perform RLS-scoped queries, so
`TenantContext` must remain active through the actual PostgreSQL
`COMMIT`. `LedgerService` never clears/switches/restores
`TenantContext` before `DB::transaction()`'s closure returns — both
`post()` and `reverse()` wrap their ENTIRE validate-through-commit
sequence in one `TenantContext::withSchool()` call, with
`DB::transaction()` nested directly inside it (identical to
`AcademicYearService`'s own established shape). Every test in
`LedgerServicePostTest`/`LedgerServiceReverseTest` runs under
`Tests\TestCase`'s NORMAL `DatabaseTransactions` + the real `pgsql`
connection (`school_os_app` runtime role) — there is no special test
harness or superuser shortcut anywhere in these two files. Every
passing assertion in both files is therefore itself the proof that the
real, ordinary `TenantContext` lifecycle keeps working end-to-end
through a production service call, not merely through 0G.1's own
purpose-built raw-SQL scaffolding.

### Posting_txid: internal only, never caller-controlled, and now structurally unreachable from the return value too

`journal_entries.posting_txid` is never set, read for business logic,
or exposed by `LedgerService` at all — `PostJournalEntryData`/
`JournalLineData` have no `posting_txid` field at all (structurally
impossible to supply one), and the database's own unconditional
`BEFORE INSERT` trigger (`finance_set_journal_entry_posting_txid()`,
0G.1's posting-txid hardening pass) would overwrite it even if
something tried.

**Closure-review correction**: 0G.2's original implementation returned
the raw `JournalEntry` Eloquent model from `post()`/`reverse()` — safe
in practice (no caller ever read `posting_txid` off it), but not safe
*by construction*: any future caller could trivially reach
`$entry->posting_txid` because the model attribute was simply there.
`post()`/`reverse()` now return `App\Domain\Finance\Application\JournalEntryResult`,
an immutable, explicit-field DTO (`journalEntryId`, `schoolId`,
`currency`, `description`, `postedAt`, `reversalOfJournalEntryId`,
`lineCount`) built via `JournalEntryResult::fromModel()` — `xid8`
posting-transaction identity is not one of those fields, so it is not
merely undocumented, it does not exist on the type at all.
`LedgerServicePostTest::posting_txid_is_structurally_absent_from_the_result_but_still_database_correct()`
proves both halves: a `ReflectionClass(JournalEntryResult::class)` scan
confirms no `posting_txid`/`postingTxid` property exists on the result
type, while a separate direct query against the real `JournalEntry`
model in the same test confirms the column itself is still database-
correct (still overwritten by the trigger, still non-null) — the
guarantee is about the Application-layer return boundary, not about
weakening 0G.1's underlying database enforcement.
`LedgerServicePostTest::post_returns_a_typed_result_never_the_raw_eloquent_model()`
and the equivalent `LedgerServiceReverseTest` test additionally assert
`assertNotInstanceOf(JournalEntry::class, $result)` directly. `xid8`
remains pure internal PostgreSQL transaction-identity metadata — it is
not Finance business terminology, and no DTO, exception, audit
payload, or outbox payload in 0G.2 ever names it.

### Application result boundary: `JournalEntryResult`

`LedgerService::post()` and `LedgerService::reverse()` both return
`JournalEntryResult`, not `App\Domain\Finance\Infrastructure\JournalEntry`.
This is deliberately the *mutation operation's own result boundary*
only — it is not a read model, not a listing/detail query service, and
does not gain any new authorization/API/UI surface; nothing else in
0G.2's scope changed. Callers needing the persisted line rows (e.g.
tests) query `JournalLine` directly via the returned `journalEntryId`,
exactly as any other module's caller would query on an id it was
handed back rather than an eagerly-loaded relation. `JournalEntryResult`
carries: `journalEntryId`, `schoolId`, `currency`, `description`,
`postedAt` (a `Carbon` instance), `reversalOfJournalEntryId` (nullable
— null for an original posting, the original's id for a reversal), and
`lineCount` (an `int`, computed from the caller-supplied line count for
`post()` and from the original entry's line count for `reverse()` —
never re-queried from the database after the fact, since the result is
built from data already known within the same transaction).

### Semantic reversal

`LedgerService::reverse()` creates a NEW `journal_entries` row
(`reversal_of_journal_entry_id` pointing at the original) whose lines
exactly invert the original's: for every original line, the reversal
has a line against the SAME `ledger_account_id`, the SAME `currency`,
the SAME exact decimal amount, with debit and credit swapped — line
count matches exactly (no aggregation, no combining). Proven with
amounts chosen specifically to expose float-unsafe behavior (`0.10`/
`0.20`/`0.25`/`0.05` across 4 lines) — `LedgerServiceReverseTest::reversal_lines_exactly_invert_the_original()`
asserts `debit_amount`/`credit_amount` swap exactly, as raw stored
decimal strings, no float artifact. The original entry's own row and
every one of its lines are proven byte-for-byte unchanged after
reversal (`the_original_entry_and_its_lines_are_never_mutated_by_reversal()`).

**Reversal description**: `journal_entries.description` is `NOT NULL`
with no dedicated reversal-reason column (FINANCE.md does not require
one, and 0G.2 does not add one). `reverse()` accepts an optional
`?string $reason` — if given, it becomes the reversal's description
(validated non-empty, ≤255 characters, the same bound as posting); if
omitted, a description is derived automatically
(`"Reversal of journal entry {original id}."`).

### Reversing a reversal: resolved, not guessed

The 0G.2 brief explicitly required this to be settled from committed
architecture, not assumed. Neither ADR 0030 nor FINANCE.md states a
restriction either way. The 0G.1 schema itself applies its ONE
structural rule — at most one reversal per entry
(`journal_entries_reversal_of_unique`) — uniformly to every
`journal_entries` row, without distinguishing "is this row itself
already a reversal." Adding a NEW restriction here that neither the
schema nor the prose requires would itself be inventing policy (exactly
what the brief warned against), and reversing a reversal is ordinary,
legitimate double-entry practice (e.g. correcting an erroneous
reversal). `LedgerService::reverse()` therefore does not check
`$original->isReversal()` at all — this is recorded explicitly in the
service's own docblock, with the full reasoning, and directly proven by
`LedgerServiceReverseTest::a_reversal_entry_may_itself_be_reversed()`.

### Double reversal: deterministic service behavior

A duplicate reversal request **throws `JournalEntryAlreadyReversedException`**
— it does NOT return the existing reversal. This is a deliberate,
stated choice (0G.2 brief section 30): returning the existing reversal
would imply an idempotent-retry-returns-same-result CONTRACT this
service does not actually provide (see "Posting idempotency boundary"
below) — throwing is the honest behavior. Two paths raise the identical
exception: a sequential pre-check (`$original->reversedBy()->exists()`,
a clean domain error before ever attempting the insert, the common
case) and a caught `UniqueConstraintViolationException` (the genuine
concurrent-race case, mirroring
`ConcurrentActivationConflictException`'s identical established
pattern) — both produce the SAME typed exception, since from the
caller's perspective the outcome is identical either way.

**Real concurrent proof**: `tests/Support/reverse-journal-entry.php`
(the subprocess helper `JournalReversalConcurrencyTest` drives) was
updated in 0G.2 to call `LedgerService::reverse()` directly (0G.2 brief
section 51 — "prefer exercising `LedgerService::reverse()` rather than
only raw insert helpers") rather than issuing raw SQL. Two genuinely
separate OS processes racing to reverse the SAME committed original
through the real service: exactly one succeeds; the loser receives
`LedgerService::reverse()`'s own `JournalEntryAlreadyReversedException`
(not a raw database exception) — proving the service's race-mapping
itself is safe under real concurrency, not merely under a sequential
test. The database contains exactly one reversal afterward.

### Posting idempotency boundary — not overclaimed

`journal_entries` does not expose a generic Application idempotency
key (0G.0 deliberately reserved that architecture for later payment-
provider callbacks — ADR 0018/`RELIABILITY.md`, unchanged). Therefore:

- **Generic posting atomicity: YES** (one transaction, proven above).
- **Generic posting idempotency: DEFERRED.** A duplicate manual/
  internal call to `LedgerService::post()` with the same logical intent
  currently produces TWO distinct, independently valid journal entries
  — `post()` has no way to recognize "this is a retry of an earlier
  request," because 0G.1's schema gives it no key to recognize one by.
  This is not a bug to fix in 0G.2; it is an accurately documented
  scope boundary. A future caller (e.g. a 0G.6 HTTP endpoint) that
  needs retry-safety must use the existing, unrelated
  `App\Support\Idempotency\IdempotencyGuard`/`EnsureIdempotent`
  client-API-key mechanism at ITS layer — `LedgerService` itself
  remains deliberately unaware of it, exactly like every other
  Application service in this repository.
- **Reversal has stronger, structural at-most-once semantics** — not
  because of a generic idempotency key, but because of ADR 0030's own
  partial unique index. This is real, database-enforced, and does not
  need to borrow the payment-callback idempotency architecture at all.
- **Payment callback idempotency remains entirely deferred** to a
  future Payments checkpoint (0G.5), which will need its OWN
  provider-event-id-keyed mechanism (`payment_provider_events`,
  already named as a LATER entity in "Core entities" above) — 0G.2 adds
  no idempotency migration, and does not pretend `LedgerService::post()`
  is a substitute for that future mechanism.

### Audit events

Reuses `App\Support\Audit\AuditRecorder` exactly as committed — no
Finance-specific audit table, no per-line audit metadata. Event names
derived directly from the exact existing repository convention
(`AcademicYearService`'s own audit calls use `'academic_year.activated'`,
no version suffix, no module prefix) — Finance's are `'journal_entry.posted'`
and `'journal_entry.reversed'`. Metadata is minimal and safe: currency,
line count, and (for reversal) the original entry's id — never a full
line dump, never `posting_txid`, never a trigger/function name, never
raw SQL exception content. Cardinality is explicit and tested: a
successful `post()` produces exactly ONE `'journal_entry.posted'` audit
event; a successful `reverse()` produces exactly ONE
`'journal_entry.reversed'` audit event and explicitly NOT an
additional `'journal_entry.posted'` one — `reverse()` shares private
helper logic with nothing in `post()`'s own public entry point, so
there is no code path by which reversing could accidentally also emit
a posting audit event.
`LedgerServiceReverseTest::reversal_is_transactional_and_produces_exactly_one_outbox_event_and_one_audit_event()`
asserts the posting-audit count stays at exactly 1 (from the original
post, via `postBalancedJournalEntry()`) after a reversal, proving this
directly. A failed `post()`/`reverse()` produces zero audit events
(proven by the same rollback/atomicity tests above).

### Domain events / outbox

Reuses the existing platform transactional outbox (ADR 0025) exactly
as committed — `App\Support\Events\ShouldBeOutboxed` +
`App\Listeners\RecordDomainEventToOutbox` (auto-discovered against the
interface, no new listener registration needed, confirmed directly from
`AppServiceProvider::boot()`'s own docblock warning against double-
registration). No second Finance-specific outbox was created; outbound
webhook-delivery tables were not repurposed as an internal event
mechanism (the 0G.2 brief explicitly warned against both).

Two new event classes, both under `App\Domain\Finance\Events`:

- `JournalEntryPosted` — `journal_entry.posted.v1`, payload
  `{journalEntryId, currency, lineCount}`.
- `JournalEntryReversed` — `journal_entry.reversed.v1`, payload
  `{reversalJournalEntryId, originalJournalEntryId}`.

Event-type naming (`snake_case_noun.past_tense_verb.vN`) matches the
exact existing convention (`AcademicYearActivated::eventType()` returns
`'academic_year.activated.v1'`) — note this is a DIFFERENT vocabulary
from audit event names (no version suffix there), matched precisely to
each's own existing precedent rather than invented fresh. Both events
are recorded from inside the same `DB::transaction()` as the ledger
writes (`event()` called before the transaction closure returns) — the
outbox row is written in the SAME database transaction automatically,
by the existing mechanism, with no extra plumbing. **Deliberately
internal-only**: neither event is registered in
`App\Support\Webhooks\WebhookEventRegistry`, so neither is externally
webhook-subscribable merely by existing in `domain_event_outbox` (rule
45) — that remains a separate, future, reviewed decision, not a side
effect of this checkpoint. Cardinality proven exactly like audit events
above: exactly one outbox row per successful operation, zero on
failure/rollback, and `reverse()` never additionally emits a
`JournalEntryPosted`.

### Atomicity — summary

| Scenario | Result |
|---|---|
| Invalid shape (e.g. 1 line) | Nothing created — rejected before any DB write |
| Missing/cross-School account | Nothing created — rejected as the transaction's first statement, before any INSERT |
| Unbalanced input | Nothing created — rejected before any DB write (Application-layer check) |
| Simulated failure immediately after a successful `post()`/`reverse()` (outer-transaction rollback technique) | Nothing persists — entry, lines, audit event, and outbox event all roll back together |
| Real injected failure on the `journal_lines` INSERT during `post()` | Header AND every other line roll back together — proven via `LedgerServiceAtomicityTest::a_journal_line_insert_failure_rolls_back_the_header_and_every_other_line()` |
| Real injected failure on the `school_audit_events` INSERT during `post()` | Header and lines already inserted earlier in the SAME transaction roll back too — proven via `an_audit_recording_failure_rolls_back_the_entire_posting()` |
| Real injected failure on the `domain_event_outbox` INSERT during `post()` | Header, lines, AND the audit event already inserted earlier in the SAME transaction all roll back too — proven via `an_outbox_recording_failure_rolls_back_the_entire_posting_including_the_already_written_audit_event()` |
| Real injected failure on the `journal_lines` INSERT during `reverse()` | The original entry and its lines are byte-identical afterward; zero reversal entry/audit/outbox rows exist — proven via `a_journal_line_insert_failure_during_reversal_leaves_the_original_untouched_and_no_reversal_artifacts()` |
| Deferred balance-trigger rejection at commit | Cannot be reached through `LedgerService::post()` itself (Application check is airtight) — proven exhaustively at the raw-SQL level by unchanged 0G.1 tests instead |

### Concurrency

- Reversal race: `JournalReversalConcurrencyTest` (updated to exercise
  the real service, see above) — exactly one of two real concurrent
  processes succeeds.
- Post-commit line-set extension race:
  `JournalEntryLineSetImmutabilityConcurrencyTest` (unchanged, still
  exercises raw SQL directly against an already-committed entry) — both
  of two real concurrent processes are rejected. Not re-pointed at
  `LedgerService` because its subject is specifically "a caller
  bypassing the service/ORM entirely," which the raw-SQL shape
  correctly continues to represent.
- No new concurrency architecture was introduced — `reverse()`'s
  at-most-once guarantee is entirely ADR 0030's own partial unique
  index; `post()` needs no concurrency guarantee beyond ordinary
  transaction isolation, since every posting creates independent new
  rows with no shared mutable state to race on.

### Performance / query behavior

Account resolution is exactly one query regardless of line count
(proven for 2, 20, and 100 lines — see "Account validation and lookup"
above). Line inserts remain one `INSERT` per `journal_lines` row (there
is no batch-insert path for tenant-scoped Eloquent creates anywhere in
this repository's existing conventions, so this is not a new N+1, it
matches how every other module in this codebase writes child rows). No
speculative maximum line-count limit was added — FINANCE.md does not
specify one, and the 0G.2 brief explicitly warned against inventing one
("no speculative limit" — any real-world bound belongs to a future
API-layer validation decision, not this Application service).

### Tests

Authoritative Finance test selector (unchanged path, expanded
contents):

```
php artisan test \
  tests/Feature/Finance \
  tests/Feature/Postgres/FinanceRawIsolationTest.php \
  tests/Unit/Support/Money
```

0G.1 baseline: 97 tests / 129 assertions / 0 failures. 0G.2 added 39
new tests across three files
(`LedgerServicePostTest.php`: 22, `LedgerServiceReverseTest.php`: 10,
`MoneyTest.php` `add()` cases: 7) plus updated two existing files in
place (`CreatesFinanceFixtures::postBalancedJournalEntry()` now
delegates to `LedgerService::post()` instead of hand-rolled
DB::transaction() logic — section 46's "no two competing
implementations of posting semantics" — and
`JournalReversalConcurrencyTest`/`reverse-journal-entry.php` now
exercise `LedgerService::reverse()`). Final (before closure
correction): **136 tests / 220 assertions / 0 failures**. Full suite:
**2579 tests / 8745 assertions / 0 failures** — the 0G.1-close
baseline (2540/8654/0) plus exactly these 39 new tests, no regressions
elsewhere. (One pre-existing, unrelated
`tests/Feature/HR/HrEmployeeImportConcurrencyTest.php` failure was
observed once under full-suite load and confirmed flaky — it passed
3/3 times when rerun in isolation, and a clean full-suite rerun
afterward passed completely; it is real-OS-process concurrency test
timing sensitivity under system load, not a regression from this
checkpoint's changes, and this checkpoint did not touch HR code.)

**Closure correction pass** added 8 further tests: a new
`tests/Feature/Finance/LedgerServiceAtomicityTest.php` (5 real
failure-injection tests, see "Database transaction boundary" above)
plus 3 result-boundary tests split across `LedgerServicePostTest.php`
(`posting_txid_is_structurally_absent_from_the_result_but_still_database_correct()`,
`post_returns_a_typed_result_never_the_raw_eloquent_model()`,
`the_result_exposes_the_expected_safe_business_fields()`) and one
equivalent typed-result test in `LedgerServiceReverseTest.php`. Final,
current: **144 tests / 247 assertions / 0 failures**. Full suite:
**2587 tests / 8772 assertions / 0 failures** — exactly the prior
2579/8745 baseline plus these 8 new tests/27 new assertions, no
regressions elsewhere.

All raw 0G.1 invariant suites (RLS/FORCE RLS, account-code uniqueness,
INR-only CHECKs, append-only UPDATE/DELETE, posting_txid hardening,
post-commit line-set immutability, deferred balance checking,
double-reversal uniqueness) were rerun unchanged and remain fully
passing — 0G.2 added Application-layer checks in front of them, it
never modified or weakened any of them.

### Security findings (0G.2)

No unresolved P0/P1/P2. Reviewed explicitly against the 0G.2 brief's
full checklist (cross-School account posting/reversal, missing-account
oracle, inactive-account bypass, currency mismatch, non-INR Money,
float arithmetic, unbalanced service posting, database-balance bypass,
`posting_txid` exposure/control, post-commit line append, journal row
mutation, partial posting, missing audit/outbox on success, duplicate
audit/outbox, double reversal, concurrent double reversal, generic-post
retry duplication, `QueryException` leakage, mass assignment of
internal columns, `TenantContext` cleared before deferred commit,
unauthorized capability claims, accidental HTTP surface, payment/
idempotency-framework creep):

- Every item above is resolved by the design decisions documented in
  this section (cross-School: SchoolScope + no-oracle exception; float:
  Money/bcmath throughout, no float anywhere; database defenses:
  entirely retained and re-tested unchanged; `posting_txid`: 0G.1's
  hardening trigger remains sole authority, LedgerService never touches
  it; mass assignment: neither DTO nor the database column allows it;
  `TenantContext`: proven active through commit by every passing test
  running under the ordinary role/lifecycle).
- **Inactive-account posting is explicitly NOT blocked** — recorded
  above as a deliberate, named, deferred decision (not invented, not
  silently ignored) rather than a security gap: FINANCE.md never
  committed to this restriction, so 0G.2 does not invent it.
- **Generic posting retry duplication is a real, accurately documented
  boundary, not a defect**: `post()` has no idempotency key to detect a
  duplicate logical request, and this document says so explicitly
  above rather than overclaiming safety 0G.1's schema does not actually
  provide.
- No HTTP surface, no capability seeding, no policy checks exist
  anywhere in this checkpoint's diff — confirmed by inspection alongside
  the git diff review below.

**Closure-review correction findings** (this pass):

- **Atomicity proof gap (resolved)**: the original report's audit/
  outbox/line-write atomicity claims rested only on an outer-
  transaction-wrap simulation, which proves transaction participation
  but not that a genuine mid-transaction failure at a specific write
  step rolls back the writes that already "succeeded" earlier in that
  same transaction. Resolved with real PostgreSQL failure-injection
  tests (temporary, uniquely-named triggers, guaranteed removed) — see
  "Database transaction boundary" above. No production schema or
  migration was touched to build these tests.
- **Result-boundary hardening (resolved)**: the original `post()`/
  `reverse()` return type (`JournalEntry`, the raw Eloquent model) made
  `posting_txid` reachable by any future caller even though nothing
  used it. Resolved by introducing `JournalEntryResult`, an explicit-
  field DTO with no `posting_txid` property — see "Application result
  boundary" above. This is a mutation-result-boundary hardening only;
  it does not introduce a read model, query service, or any new
  authorization/API/UI surface.

### What 0G.2 intentionally does not implement

No `finance.*` capabilities/policies/authorization of any kind (0G.3);
no HTTP/API/OpenAPI/generated types (0G.6); no UI (0G.7); no `charges`/
`invoices`/`payments`/`payment_provider_events`/`refunds` (0G.4/0G.5);
no inactive-account posting restriction (deferred, named above); no
generic posting idempotency key (deferred, named above); no accounting-
period/backdating support; no multi-currency; no Payroll, Admissions,
or Student Enrollment integration.

### Next checkpoint boundary

0G.3 — Finance Authorization & Administrative Read Model: real
`finance.*` capability strings (0G.0's proposed families —
`finance.ledger.view`/`finance.ledger.post`/`finance.accounts.manage`
— finalized against current capability-naming convention, not assumed),
capability-gated wrapping around `LedgerService`'s existing methods (an
authorization LAYER in front of the existing service, not a rewrite of
it), an administrative read model for browsing the ledger/chart of
accounts, and the account-status-blocks-posting decision this document
deliberately deferred above.

## 0G.3 as-built (Finance Authorization & Administrative Read Model)

Establishes the first authorized administrative boundary around
Finance, on top of 0G.1's unchanged schema and 0G.2's unchanged
`LedgerService`. Zero migrations, zero schema changes, zero
composer changes, no HTTP/API/UI. `LedgerService`'s posting/reversal
semantics, Money arithmetic, audit architecture, domain-event/outbox
architecture, reversal-of-reversal policy, and generic-post
idempotency boundary are all unchanged.

### Capabilities

Exact registered strings (`database/seeders/CapabilityAndRoleSeeder.php`,
the existing sole capability/role catalog -- no data migration, no
parallel registration mechanism):

- `finance.ledger.view` -- view Ledger Accounts, journal history, and
  journal detail. One capability for every current read surface
  (section 9's "avoid one capability per DTO" guidance), mirroring
  `hr.employees.view`'s single Directory-tier read grant.
- `finance.ledger.post` -- post journal entries.
- `finance.ledger.reverse` -- reverse posted journal entries.

`finance.accounts.manage` is deliberately **not registered**: 0G.3
implements no Ledger Account create/update/deactivate. Registering an
unused capability now would be exactly the "speculative capability
catalog bloat" the brief warns against; that capability is reserved
for whichever future checkpoint actually adds Chart of Accounts
administration.

**Post vs. reverse are separate capabilities, and both are separate
from `.view`.** Reversal creates a new, permanent ledger fact
correcting a prior one -- a materially higher-risk financial
correction action than an ordinary posting. This mirrors two existing
precedents in the same catalog: `communications.emergency` kept
separate from `.announce`, and `enrollments.rollovers.manage` kept
separate from `enrollments.manage` (a bulk, higher-blast-radius
action earning its own grant). A caller holding only `.post` cannot
reverse; a caller holding only `.view` cannot post or reverse.

**Default role assignment**: `school_admin` receives all three
(`view`/`post`/`reverse`) -- the seeded catalog's top school-scoped
administrative role, which already holds every other domain's most
privileged pair. `principal` receives **none** of the three: unlike
Students/Guardians/Enrollments/Academics (which Principal already
operates day-to-day in this catalog), there is no established
precedent of Principal handling ledger postings or reversals, and
inventing one now would be exactly the "surprising broad default
assignment" the brief warns against. A School wanting a dedicated
Accountant-style role can configure one itself later without this
checkpoint inventing it. No role name is ever checked in code --
every check is `Gate::authorize('capability', [...])` via
`AuthorizesCapability` (the same mechanism every other module uses).

### Authorization architecture: trusted core vs. authorized administrative facade

`LedgerService` (0G.2) remains the TRUSTED CORE mutation kernel --
unmodified, and still performing no capability/permission check of
its own (`$actor` is provenance only, never authorization). 0G.3 adds
`App\Domain\Finance\Application\LedgerAdministrationService`, the
authorized ADMINISTRATIVE entry point a future HTTP/UI layer will
call:

- `post(School $school, PostJournalEntryData $data, User $actor): JournalEntryResult`
  -- authorizes `finance.ledger.post`, then delegates unchanged to
  `LedgerService::post()`.
- `reverse(School $school, string $journalEntryId, User $actor, ?string $reason = null): JournalEntryResult`
  -- authorizes `finance.ledger.reverse`, resolves the original entry
  fresh via a School-scoped query
  (`where('school_id', $school->id)->find($journalEntryId)`, never a
  caller-supplied model), throws `JournalEntryNotFoundException` if
  absent, then delegates unchanged to `LedgerService::reverse()`.

Neither method duplicates any validation, account lookup, Money
arithmetic, posting, reversal, audit, or outbox logic -- there remains
exactly one production posting/reversal implementation
(`LedgerService`) in this codebase; `LedgerAdministrationService` only
authorizes and delegates.

**Why the split, not embedding authorization directly in
`LedgerService`** (the shape most other modules use, e.g.
`App\Domain\HR\Application\EmployeeService`): a future TRUSTED internal
domain caller (Payroll settlement, a payment-provider reconciliation
job, or any other system-to-system Finance integration -- **none of
which exist yet, and none of which are implemented in this
checkpoint**) needs to call `LedgerService` directly through its own
explicit, reviewed integration boundary later, without being forced to
hold or impersonate a human staff member's Finance capability grant. A
human administrator (and, later, an HTTP controller acting on one's
behalf) always goes through `LedgerAdministrationService` instead --
never `LedgerService` directly. No transport may ever expose
`LedgerService` directly; a future controller depends on
`LedgerAdministrationService`.

### Administrative read service

`App\Domain\Finance\Application\LedgerReadService` -- the sole
authorized read path, mirroring
`App\Domain\HR\Application\EmployeeDirectoryService`'s disclosure-
boundary shape exactly. Three operations, all gated by
`finance.ledger.view`, checked BEFORE any query runs:

- `listAccounts(School $school, User $actor): Collection<LedgerAccountSummary>`
  -- the Ledger Account directory. Deliberately **unpaginated**,
  matching this repository's established convention for small, bounded
  reference data (GradeLevel/Subject are returned via a plain
  `->get()`, never a paginator) -- a School's chart of accounts is
  reference data of the same shape and size class, not a growing
  history. Ordered by normalized account code (`code` is already
  uppercased at write time by `NormalizesCode`, so a plain
  `orderBy('code')` is deterministic), `id` as a defensive tie-break.
- `listJournalEntries(School $school, JournalEntryQuery $query, User $actor): LengthAwarePaginator<JournalEntrySummary>`
  -- the journal history. Paginated, `perPage` clamped to
  `[1, LedgerReadService::MAX_PER_PAGE]` (100) both in the query DTO's
  constructor and again in the service. Stable-ordered
  `posted_at DESC, id DESC` (`id` is UUIDv7 -- time-ordered -- so this
  is a deterministic tie-break for entries sharing an identical
  `posted_at` second-precision timestamp).
- `getJournalEntryDetail(School $school, string $journalEntryId, User $actor): JournalEntryDetail`
  -- one journal entry's full detail, including every line.

All three run entirely under the caller's own `TenantContext`/RLS --
no privileged/admin database connection, no `pgsql_admin`.

### Read DTOs -- never a raw Eloquent model

Every result is a typed, immutable, `final` DTO (`LedgerAccountSummary`,
`JournalEntrySummary`, `JournalEntryDetail`, `JournalLineDetail`) --
never `LedgerAccount`/`JournalEntry`/`JournalLine` returned directly.
`posting_txid` remains structurally unreachable through the read side
too, the same result-boundary discipline 0G.2's `JournalEntryResult`
established for the write side (proven directly by
`LedgerReadServiceTest::listing_accounts_and_journal_entries_never_return_a_raw_eloquent_model()`).

- `LedgerAccountSummary`: `ledgerAccountId`, `code`, `name`, `type`,
  `currency`, `isSystem`, `status`, `createdAt`, `updatedAt`.
- `JournalEntrySummary`: `journalEntryId`, `currency`, `description`,
  `postedAt`, `reversalOfJournalEntryId`, `reversedByJournalEntryId`,
  `lineCount`. No `totalDebit`/`totalCredit` -- see "Balance / totals"
  below.
- `JournalEntryDetail`: the same entry-level fields plus
  `lines: list<JournalLineDetail>`.
- `JournalLineDetail`: `journalLineId`, `ledgerAccountId`,
  `accountCode`, `accountName`, `side` (`JournalSide`), `amount`
  (`Money`, exact NUMERIC-backed decimal, never a PHP float),
  `currency`. Exposes `side` + `amount`, never the raw
  `debit_amount`/`credit_amount` pair -- the same boundary
  `JournalLineData` already established on the write side, applied
  here to the read side.

`reversedByJournalEntryId` is derived read-only from immutable journal
history, never a persisted/mutable column: `listJournalEntries()`
computes it in the SAME single query as the page of entries via a
correlated scalar subquery (`addSelect` against a `journal_entries as
reverser` self-alias -- an unaliased self-referencing subquery here is
a real bug this checkpoint found and fixed: without the alias, the
subquery's `journal_entries.id` reference resolves to the SUBQUERY's
own FROM, not the outer row, silently returning null for every row);
`getJournalEntryDetail()` computes it via one bounded lookup query. No
schema change, no mutable `reversed`/`is_reversed` column anywhere.

### Balance / totals -- deliberately not implemented

No field named `balance`, `current_balance`, or `running_balance`
anywhere in 0G.3, and no `totalDebit`/`totalCredit` aggregate either,
even though the latter would have been a safe, non-"balance" exact
aggregate. ADR 0030/FINANCE.md have not yet settled normal-balance
sign conventions per account type (debit-normal vs. credit-normal),
and inventing that now would be exactly the kind of undocumented
accounting-semantics decision the brief prohibits. This is a named,
deliberate deferral, not an oversight -- a future checkpoint that
settles normal-balance semantics can add it without any 0G.3 rework,
since no DTO field name or query shape here assumes one.

### Pagination and filters

`JournalEntryQuery` (explicit, allow-listed input, mirroring
`EmployeeDirectoryQuery`'s shape): `postedFrom`/`postedTo` (filter on
`journal_entries.posted_at`, the real posting timestamp -- never
`created_at`; plain `>=`/`<=`, no accounting-period/end-of-day-
inclusive adjustment invented), `ledgerAccountId` (SQL-level
`whereExists` against `journal_lines`, scoped to the trusted School --
a cross-School id simply matches zero lines, never an error or an
oracle), `reversedOnly` (`true`/`false`/`null`), `search` (ILIKE
against `description`, mirroring `EmployeeDirectoryQuery`'s existing
search-filter convention), `page`, `perPage` (clamped to
`[1, 100]`). Every filter applies at the SQL level, before pagination
-- never loaded into PHP and filtered there. No arbitrary query DSL,
no user-selectable sort field (ordering is the one fixed
`posted_at DESC, id DESC`).

### Cross-School privacy

A nonexistent journal entry id and one belonging to a different School
produce the IDENTICAL `JournalEntryNotFoundException` (same message
shape, differing only by the id itself) -- both `LedgerReadService::
getJournalEntryDetail()` and `LedgerAdministrationService::reverse()`
resolve via a School-scoped query under the trusted School's own
TenantContext, never "found, but forbidden." A `ledgerAccountId`
filter for another School's account returns an empty result set, not
an error. Authorization is always checked BEFORE any Finance query
runs (never "query first, then decide") -- a caller lacking
`finance.ledger.view` learns nothing about the target School's Finance
data, not even a row count
(`LedgerReadServiceTest::missing_view_capability_denies_listing_journal_entries_and_reveals_no_count()`
asserts the denial message never contains a count that was present in
the fixture).

### Data classification and read audit

FINANCE.md's already-committed "Data classification" section (above)
classifies every Finance table as Highly Sensitive by default. This
checkpoint does **not** reopen or narrow that classification for the
current no-Fees/Payroll/Payment-linkage ledger kernel -- doing so
would itself be an undocumented architecture change, not a refinement
this checkpoint is scoped to make. Highly Sensitive's own documented
engineering control ("access to Sensitive/Highly Sensitive data is
itself auditable, not just changes to it") therefore applies exactly
as written: every successful read is audited, once per call, never
once per row -- mirroring
`App\Domain\Documents\Application\DocumentListingService`'s
`document.sensitive_list_viewed` (a list call, one event) and
`App\Domain\Documents\Application\DocumentReadService`'s
`document.sensitive_metadata_viewed` (a single-record call, one event)
precedents exactly:

- `ledger_account.list_viewed` -- metadata: `resultCount` only.
- `journal_entry.list_viewed` -- metadata: `resultCount` only (the
  page's item count, not the paginator's grand total).
- `journal_entry.detail_viewed` -- metadata: `journalEntryId`,
  `lineCount`; `subject` is the viewed `JournalEntry`.

No full row/line dump, no `posting_txid`, no SQL/filter internals, no
another School's id, ever appears in audit metadata (section 42's
audit-privacy rule). Nothing is audited on denial --
`AuditRecorder` is never reached before `authorizeCapabilityFor()` has
already succeeded (proven by
`LedgerReadServiceTest::a_denied_read_creates_no_audit_event()`).

### Authorization test matrix

`tests/Feature/Finance/LedgerAdministrationServiceTest.php` and
`tests/Feature/Finance/LedgerReadServiceTest.php` prove, for
post/reverse/view independently: the correct capability succeeds; a
wrong capability (view-only for post/reverse; post-only for reverse)
is denied; a non-member is denied; a capability granted only in a
DIFFERENT School never authorizes the current one; a denied
post/reverse creates zero new journal entries, zero journal lines,
zero audit events, and zero outbox events; a denied read creates zero
audit events. `tests/Feature/Finance/FinanceCapabilityRegistryTest.php`
proves the three capabilities are registered exactly once, school-
scoped (not platform), idempotent across repeated seeder runs, and
that `school_admin`/`principal` receive exactly the documented default
grants (mirroring `Tests\Feature\HR\HrCapabilityRegistryTest`'s shape).

### Query behavior

`listJournalEntries()`: one query for the page of entries (line count
via `withCount('lines')`, reversed-by id via a correlated subquery --
both in the SAME query, never a per-row query), plus the paginator's
own total-count query -- constant regardless of page size, proven for
10 vs. 50 entries
(`LedgerReadServiceTest::journal_listing_query_count_does_not_grow_per_row()`).
`getJournalEntryDetail()`: one query to resolve the entry, one for its
lines, one batched `whereIn` eager load for every referenced
`LedgerAccount` (never one lookup per line), one for the reversed-by
lookup -- constant regardless of line count, proven for 2 vs. 20 lines
with a warmed capability cache isolating the per-line signal from the
first-call capability-cache-miss cost
(`LedgerReadServiceTest::journal_detail_query_count_does_not_grow_per_line()`).
No index was added -- no query in this checkpoint exhibited a real,
observed performance problem against the existing `(school_id,
posted_at)`/primary-key indexes 0G.1 already created.

### Tests

Authoritative Finance suite (unchanged path): baseline **144 / 247 /
0** (0G.2 close). Added 53 new tests across three new files
(`FinanceCapabilityRegistryTest.php`: 7,
`LedgerAdministrationServiceTest.php`: 13,
`LedgerReadServiceTest.php`: 33). Final: **197 tests / 371 assertions
/ 0 failures**. Full suite: **2640 tests / 8896 assertions / 0
failures** -- the 0G.2-close baseline (2587/8772/0) plus exactly these
53 new tests, no regressions elsewhere. (The same pre-existing,
unrelated `tests/Feature/HR/HrEmployeeImportConcurrencyTest.php`
real-OS-process concurrency timing flake documented in the 0G.2
as-built section above was observed once under full-suite load in
this checkpoint too, and again confirmed flaky -- 3/3 passes in
isolation, and a clean full-suite rerun afterward passed completely;
this checkpoint did not touch HR code.)

### Security findings (0G.3)

No unresolved P0/P1/P2. Reviewed explicitly against the checkpoint
brief's full authorization/read-model checklist (role-based
authorization, capability in the wrong School, non-member with
capability-like state, cross-School journal/account id oracle,
authorization-after-data-access, denied operation causing a ledger
write, denied operation causing audit/outbox, posting capability able
to reverse, view capability able to post, reverse capability
overreach, a broad `finance.manage` god capability, raw Eloquent
return, `posting_txid` exposure, float conversion in the read model,
unbounded journal listing, pagination-total leakage, N+1 journal/
account reads, a mutable balance column, a mutable reversal-status
column, accidental Student/Guardian access, a system caller forced to
impersonate human staff, a shared-authorization-layer bypass,
read-audit overcollection):

- Every item above is resolved by the design decisions documented in
  this section -- no role-name check exists anywhere in this
  checkpoint's diff; every authorization check is a capability check
  via the existing `Gate::define('capability', ...)` mechanism; no new
  authorization primitive, permission cache, or capability-resolution
  path was introduced.
- Two real implementation bugs were found and fixed during this
  checkpoint's own validation (not merely inferred): (1) the
  unaliased self-referencing `reversed_by_journal_entry_id` correlated
  subquery silently returned null for every row -- fixed with an
  explicit `reverser` alias; (2) a query-count test comparing a
  cold-cache first authorization check against a warm-cache second one
  produced a misleading "query count grows" signal -- fixed by
  explicitly warming the capability cache before both measurements, so
  the test isolates line count alone.

### What 0G.3 intentionally does not implement

No Finance HTTP/API routes, controllers, OpenAPI, or frontend/UI (a
later transport checkpoint); no Ledger Account create/update/
deactivate (no `finance.accounts.manage` registered); no balance/
normal-side semantics or any `totalDebit`/`totalCredit` aggregate
(deferred, named above); no `fees`/`charges`/`invoices`/`payments`/
`refunds`/provider callbacks; no Payroll, Admissions, or Student
Enrollment integration; no multi-currency; no generic posting
idempotency (unchanged from 0G.2); no reporting/analytics subsystem;
no trusted internal Payroll/payment-settlement integration (documented
as a future, separate boundary -- not built here).

### Next checkpoint boundary

0G.4 onward (Fees/Receivables foundation, or whichever checkpoint the
committed roadmap defines next) is not yet started. The
account-status-blocks-posting decision (0G.2's deferred item, restated
in 0G.2's "Next checkpoint boundary" above) remains deferred; 0G.3 did
not revisit it.

## Roadmap relationship

Implements the start of `docs/roadmap/MASTER-ROADMAP.md`'s **Phase
0G — Finance and Fees**. Phase 0G is marked **IN PROGRESS** as of this
checkpoint (0G.0, 0G.1, and 0G.2 complete; 0G.3 onward not started) —
see the corresponding `MASTER-ROADMAP.md` edit in this same checkpoint.
