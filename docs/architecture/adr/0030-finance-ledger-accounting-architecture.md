# ADR 0030: Finance Ledger and Double-Entry Accounting Architecture

- Status: Accepted
- Date: 2026-08-26

## Context

`docs/architecture/DOMAIN-MAP.md`'s Layer 3 table already commits Finance
to owning "core ledger, chart of accounts, financial-correctness
primitives" — language chosen before this checkpoint, at Phase 0D. That
phrasing is a real constraint, not decorative: a "chart of accounts" is
a specific accounting artifact, and pairing it with "ledger" implies
postings recorded *against* accounts, not merely a table of charges and
payments with a running balance.

`docs/architecture/ARCHITECTURE.md` §10 fixed seven financial-
correctness rules ahead of any financial module (no float money,
explicit currency, immutable/append-only history via reversal, explicit
refund/waiver/reversal operations, idempotent payment callbacks,
reconciliation as first-class, authorization-before-execution) but does
not itself choose between a true double-entry ledger, a subledger-only
model, or a simpler charge/payment ledger with later general-ledger
(GL) integration. That choice is the one decision Phase 0G.0 cannot
leave to accumulate as accidental complexity in 0G.1's migrations — a
schema built as "charges with a balance column" cannot be turned into a
double-entry ledger later without a data migration touching every prior
financial fact, which conflicts with the immutability rule above. A
schema built as double-entry from day one, on the other hand, can still
support a simple, minimal chart of accounts under the hood without
forcing every future module (Fees, Payments, Payroll) to become
accounting-literate.

This ADR settles that choice, since `docs/architecture/ARCHITECTURE.md`
§10 does not, and every table 0G.1 creates depends on it.

## Decision

**School OS Finance is a true double-entry ledger.** Every financial
fact is recorded as a `journal_entry` (header) with two or more
`journal_line` rows (postings), each naming a `ledger_account` and a
debit or credit amount in a single explicit currency, and the lines of
any one `journal_entry` must sum to zero (total debits == total
credits) before the entry may be considered posted.

Three subordinate decisions, all downstream of the above:

1. **Postings are atomic and immediate — there is no separate "draft"
   journal-entry state in the ledger core (0G.1).** An Application-
   layer service either successfully posts a fully-balanced entry
   inside one database transaction, or the transaction rolls back and
   nothing is recorded. Draft/pending workflows belong to whatever
   calls the ledger (e.g. a future Invoice approval flow in 0G.4), not
   to the ledger itself — the ledger's own contract is "once it exists,
   it is a posted fact."
2. **Corrections are new entries, never edits.** A `journal_entry` is
   reversed by creating a second `journal_entry` whose lines exactly
   invert the original's debits/credits, linked via a
   `reversal_of_journal_entry_id` column **on the reversing entry**
   (never a mutated column on the original — see "Reversal linkage
   direction" below).
3. **Balances are always derived from postings, never stored as a
   canonical mutable column.** A `ledger_accounts.balance`-style column
   is exactly the kind of "check-then-update" shape rule 30 (idempotency)
   and the append-only principle both already prohibit for adjacent
   problems — the same reasoning applies here. A future cached/
   materialized balance for read performance is allowed only as an
   explicitly-labeled, rebuildable cache, never the source of truth.

### Reversal linkage direction

The reversing entry points at the original (`reversal_of_journal_entry_id`
on the new row), not the other way around. This means the original
`journal_entry` row is **never written to again** after its initial
insert — reversal status is a derived fact (`EXISTS (SELECT 1 FROM
journal_entries WHERE reversal_of_journal_entry_id = :original_id)`),
not a stored flag that would otherwise require an UPDATE against a
posted row. A unique index on `reversal_of_journal_entry_id` (nullable)
prevents more than one reversal ever being linked to the same original,
closing the double-reversal race structurally rather than by
application-level check-then-insert.

## Rationale

- **The domain map already named "chart of accounts" as Finance's
  scope.** Building anything less than an accounts-based ledger would
  silently narrow a decision Phase 0D already made in a document every
  later module is told to trust (`DOMAIN-MAP.md`'s own "living
  document, update it when a dependency changes" rule implies the
  inverse too — don't quietly contradict it without saying so).
- **A subledger-only or charge/payment-ledger design defers the hard
  problem (reconciling money across Fees, Payments, and eventually
  Payroll) to whichever module hits it first**, almost certainly under
  time pressure, and without the cross-module context this ADR has.
  ARCHITECTURE.md §10 rule 6 ("reconciliation is a first-class
  concern... not bolted on after the fact") argues directly for
  building the reconciliation-capable structure now.
- **True double-entry gives the balance invariant a structural home.**
  "Debits equal credits" is enforceable per-transaction in a way that
  "the running balance column is correct" never fully is under
  concurrent writes — this directly serves rule 1 (no float money) and
  rule 3 (immutable financial history) by giving both a single
  mechanism (the journal) rather than scattered per-table balance
  bookkeeping.
- **Payroll and Fees both eventually need to post financial facts that
  are not natively "a charge against a student"** — an employee salary
  payable, a bank transfer batch, a refund reversing a prior fee
  payment. A chart-of-accounts ledger gives all of these one shared
  vocabulary (asset/liability/equity/income/expense accounts) instead
  of each Layer 3 module inventing its own partial mini-ledger, which
  is exactly the kind of module-boundary violation
  `docs/architecture/DOMAIN-MAP.md`'s "no bidirectional coupling" rule
  exists to prevent.

## Alternatives considered

1. **Subledger-only (charges/invoices/payments with a per-invoice
   balance, no double-entry journal underneath).** Rejected: cheaper to
   build first, but does not deliver what `DOMAIN-MAP.md` already
   promised ("chart of accounts"), and every later addition (Payroll
   postings, refund accounting, bank reconciliation) would eventually
   need journal-style postings anyway — building it twice is more
   expensive than building it once, correctly, now.
2. **Charge/payment ledger with later GL integration ("bolt on
   double-entry later").** Rejected for the same reason spelled out in
   Context: a later migration from mutable-balance charges to
   immutable journal postings cannot preserve the append-only
   guarantee retroactively — every already-posted charge/payment fact
   would need to be backfilled into journal form, which is either a
   lossy approximation or a large one-time reconciliation project. Pay
   the double-entry design cost once, at 0G.0/0G.1, instead.
3. **Full School-configurable chart of accounts from day one** (Schools
   can define their own account taxonomy, sub-accounts, groups).
   Rejected for 0G.1 specifically (not rejected forever): the first
   ledger slice only needs enough system-managed accounts to post real
   Fees/Payments/Payroll transactions correctly; School-level
   configurability is real scope, deferred to a later Finance
   checkpoint once real usage patterns exist to design against (root
   `CLAUDE.md` rule 2 — no speculative flexibility for a need that
   doesn't exist yet).

## Consequences

- Every future Finance-adjacent module (Fees, Payments, Payroll,
  Inventory costing per `DOMAIN-MAP.md`) posts through Finance's
  Application-layer ledger service — it does not maintain its own
  balance columns as financial truth. A Fees "amount due" is a
  **read-model projection** computed from ledger postings (or a
  narrower Receivables-specific structure built in 0G.4 on top of the
  ledger), never an independently-mutated integer.
- `journal_entries`/`journal_lines` become, from 0G.1 onward, an
  append-only table pair with no `UPDATE`/`DELETE` Application-layer
  path at all — enforced by the Application service surface, not
  merely by convention (mirrors how Documents 0E.1 gave `documents` no
  delete path in its first checkpoint).
- The exact chart-of-accounts seed set (which system accounts exist:
  Accounts Receivable, Cash/Bank, Fee Income, Refunds Payable, and so
  on) is an 0G.1 implementation decision, not fixed by this ADR — this
  ADR fixes the *shape* (accounts have a type from the standard
  five-category taxonomy: asset/liability/equity/income/expense), not
  the seed data.
- A per-`journal_entry` balance check (sum of debits == sum of credits)
  must be enforced before any entry is considered posted. 0G.1 must
  enforce this at minimum inside the Application-layer transaction; a
  database-level deferred constraint trigger (`CONSTRAINT TRIGGER ...
  INITIALLY DEFERRED`, validating at `COMMIT` time across the
  just-inserted line rows for one `journal_entry_id`) is the target
  structural enforcement and is an 0G.1 design task, not decided
  precisely by this ADR.

## Future extraction/evolution path

School-configurable chart of accounts, accounting-period close/lock,
multi-currency postings and FX handling, and human-facing document
numbering (receipt/invoice sequences) are all real future extensions
that fit inside this shape without a structural rewrite — they add
rows/columns and new Application-layer operations, they do not
contradict the double-entry/append-only/derived-balance decisions made
here. If a future requirement genuinely cannot fit (e.g. a regulatory
need for a fundamentally different ledger shape in some jurisdiction),
that requires a new ADR explicitly superseding this one, not a quiet
schema drift.
