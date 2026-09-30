# ADR 0031: Payments Settlement, Allocation and Idempotency Architecture

- Status: Accepted
- Date: 2026-09-09

## Context

Phase 0G.4 delivered `App\Domain\Fees` (`charges` — receivable
obligations posted immediately to the ledger, ADR 0030). FINANCE.md's
own 0G.0-era "Payment model" section sketched Payments only
conceptually and explicitly deferred every real decision to this
checkpoint ("nothing here is built in this checkpoint"). `docs/architecture/DOMAIN-MAP.md`
already named a separate **Payments** module (depends on Fees and
Finance) before any Payments code existed, but the following durable,
hard-to-reverse accounting/concurrency decisions were never actually
settled anywhere binding:

1. When does a Payment become a financially authoritative fact — on
   every provider callback, or only at settlement?
2. Are `PaymentProviderEvent` (external ingress), `Payment` (the
   School's settlement fact), `PaymentAllocation` (payment-to-charge
   assignment), and `JournalEntry` (the accounting effect) four
   genuinely separate concepts, or can any be collapsed?
3. May a Payment partially or fully allocate across multiple Charges,
   and may a Charge be paid off across multiple Payments?
4. What happens to money that cannot be fully explained by allocations
   (overpayment / unapplied cash)?
5. Where does the settlement (cash/bank) ledger account come from?
6. What happens to a Charge once money has been allocated against it —
   can it still be cancelled?
7. How is "the same provider event redelivered" distinguished from "a
   provider event that legitimately conflicts with what was already
   recorded," and how is either made safe under real concurrency?

These are exactly the kind of decisions ADR 0030 itself was written to
settle for the ledger one layer down — "a schema built as 'charges with
a balance column' cannot be turned into a double-entry ledger later
without a data migration touching every prior financial fact." The same
is true here: a Payments schema that conflates provider ingress with
business recognition, or that allows an allocation set to be revisited
after the fact, cannot be corrected later without touching already-
recognized financial history. This ADR settles these decisions before
0G.5 commits, exactly as ADR 0030 settled the ledger shape before 0G.1
committed.

## Decision

**Four structurally distinct concepts, never collapsed into one table
or one mutable status object:**

| Concept | Table | Owned by | Nature |
|---|---|---|---|
| Provider event | `payment_provider_events` | `App\Domain\Payments` | External ingress fact — pure immutable identity, no business meaning of its own |
| Payment | `payments` | `App\Domain\Payments` | The School's own settlement fact — immutable, created only when money is actually settled |
| Payment allocation | `payment_allocations` | `App\Domain\Payments` | The relationship assigning Payment value to one or more Charges — immutable, frozen the instant its owning Payment's transaction commits |
| Ledger posting | `journal_entries`/`journal_lines` | `App\Domain\Finance` | The accounting effect (ADR 0030), reached only through `LedgerService::post()` |

**Module ownership and dependency direction**: `App\Domain\Payments`
owns all three Payments tables and depends on `App\Domain\Fees` (via
`ChargeService::lockChargeForAllocation()`, never a direct read of
`charges`) and `App\Domain\Finance` (via `LedgerService::post()`, never
a direct `journal_entries`/`journal_lines` write). Neither Fees nor
Finance depends on Payments. No cycle.

**Settlement-only recognition**: a `payments` row is created ONLY when
a provider event represents actual settlement, atomically with its
allocations and its ledger posting, inside one database transaction.
There is no `pending`/`failed`/draft Payment row and no Payment status
column at all — once created, a Payment is immutable forever (no
Refund/void/correction path exists in 0G.5; see "Refund deferral"
below). This deliberately supersedes FINANCE.md's 0G.0-era conceptual
sketch, which had floated a mutable `pending`/`settled`/`failed` status
column on `payments` — that idea predates 0G.4's own "immediate
recognition, no draft" precedent for `charges` and is superseded by it
here, for the identical reason: a mutable financial-status machine is
exactly the kind of accidental complexity ADR 0030's "once it exists,
it is a posted fact" philosophy was chosen to prevent one layer down.
Non-settlement provider callback types (pending/authorized/failed) are
not modeled in 0G.5 at all.

**Mandatory full allocation, no unapplied cash**: every recognized
Payment's allocation set MUST sum to exactly its own settled amount,
enforced BOTH at the Application layer (a pre-check before any write)
and — because this is a genuine cross-row invariant no single-row
`CHECK` constraint can express — at the database layer via a
`CONSTRAINT TRIGGER ... DEFERRABLE INITIALLY DEFERRED` on `payments`
(the exact `journal_entries_balanced_check` precedent ADR 0030 already
established). A settlement whose allocation set cannot exactly consume
the settled amount without exceeding some named Charge's own remaining
balance is REJECTED outright — no unapplied-cash/customer-credit
account is invented to absorb the difference, and no charge is ever
allocated for less than what a caller supplied. True overpayment/
unapplied-cash accounting is explicitly deferred to a future
checkpoint, alongside Refunds.

**Frozen allocation set — database-authoritative, not merely
application-convention**: `payments.creation_txid xid8 NOT NULL`,
assigned unconditionally by an unconditional `BEFORE INSERT` trigger
(`payments_set_creation_txid` / `finance_payments_set_creation_txid()`)
exactly mirroring `journal_entries.posting_txid`'s established pattern
(ADR 0030, `add_journal_entry_posting_invariants` migration) — a
caller-supplied value is always overwritten, so it is never spoofable.
A `BEFORE INSERT` trigger on `payment_allocations`
(`payment_allocations_reject_post_commit_insert()`) compares the
CURRENT transaction's id (`pg_current_xact_id()`) against the target
Payment's `creation_txid`: equal means this insert is still part of the
original atomic settlement transaction (allowed); different means a
separate, later transaction is attempting to extend an already-
committed Payment's allocation set (rejected). This is the direct
allocation-layer analog of `journal_lines_reject_post_commit_insert()`
— the SAME structural mechanism, one layer up. `creation_txid` is
never exposed through any DTO, audit record, outbox payload, read
model, or provider-facing result — it exists purely as an internal
database-authoritative freeze mechanism.

**Charge-row locking protocol, shared between allocation and
cancellation**: a `BEFORE INSERT` trigger on `payment_allocations`
(`payment_allocations_lock_and_validate_charge()`) takes a `SELECT ...
FOR UPDATE` lock on the target Charge row, verifies the Charge is not
cancelled, computes its existing authoritative allocation total, and
rejects if adding the new allocation would exceed the Charge's own
amount — all before the INSERT itself is permitted. Because an ordinary
`UPDATE charges SET cancelled_at = ...` (Fees' own cancellation path)
already takes an equivalent row-level lock on the same row as part of
the `UPDATE` statement itself, these two code paths — a new allocation
insert and a Charge cancellation — genuinely serialize against each
other at the database level: whichever reaches the row first wins, and
the other observes the final, already-committed state before deciding.
This closes the exact class of raw/future-caller race a purely
`DEFERRED` constraint trigger (checked only at commit time, with no
shared lock) cannot close, because two concurrent transactions could
each compute a Charge's remaining capacity without ever seeing the
other's still-uncommitted insert.

At scale (one settlement allocating across multiple Charges), every
named Charge is locked in ascending `chargeId` order
(`App\Domain\Payments\Application\PaymentProviderEventService::processSettlement()`)
before any write begins, preventing deadlock between two settlements
that happen to name an overlapping set of Charges in different orders.

**Charge cancellation interlock**: a Charge with any recognized
`payment_allocations` row can never be cancelled — enforced at the
Application layer (`ChargeService::cancel()` locks the Charge row and
is taught to translate the database's rejection into a typed
`ChargeHasPaymentAllocationsException`) and, authoritatively, by
`charges_payment_allocation_guard_trigger` — a trigger OWNED by this
module's own migration but physically attached to Fees' `charges`
table, the same "structural DB constraint crossing a module boundary"
pattern the `payment_allocations.charge_id` composite FK against
`charges(id, school_id)` already uses (rule 70's established
precedent), never a reverse Fees→Payments Application-layer call.

**Settlement accounting**: `LedgerService::post()` (ADR 0030) is
reused exactly as-is — never a direct `journal_entries`/`journal_lines`
write from `App\Domain\Payments`. One journal entry per settlement: a
single debit line against a caller-supplied, explicit
`settlementLedgerAccountId` (the same "no magic lookup" discipline
`charges.receivable_ledger_account_id`/`revenue_ledger_account_id`
already established) for the full settled amount, and one credit line
PER allocation against that allocation's own Charge's
`receivable_ledger_account_id` — correctly handling a settlement
spanning Charges on different receivable accounts. `payments.journal_entry_id`
is a structural, School-scoped-unique one-to-one FK link (never
description/audit/outbox-only linkage).

**Provider event idempotency**: `payment_provider_events` is a pure,
immutable ingress-identity record — no mutable processing-state column
exists at all, because `PaymentProviderEventService::recordSettlement()`
always creates the provider-event row and its resulting Payment
together, in one atomic transaction; a mid-transaction failure at any
step rolls back the event claim too, leaving the same `provider_event_id`
genuinely retryable with no separate release/recovery mechanism needed.
`unique(school_id, provider, provider_event_id)` is the durable claim
key — the direct inbound analog of `webhook_deliveries`'
`(webhook_endpoint_id, event_id)` uniqueness (rule 35: never the same
table, since inbound and outbound webhook delivery are different
directions). The ATOMIC-CLAIM PATTERN `App\Support\Idempotency\IdempotencyGuard::claim()`
already established (INSERT, catch `UniqueConstraintViolationException`,
never check-then-insert) is reused; the CLASS itself is not, because it
is structurally bound to HTTP request/response replay semantics
(`ApiIdempotencyKey.response_body`/status/headers) that have no meaning
for a trusted internal provider-event ingestion boundary with no HTTP
request or response of its own. This is not a second generic
idempotency framework — it is the identical proven pattern, applied to
a differently-scoped durable key, exactly as `RELIABILITY.md`'s
"Payment and webhook readiness" section already anticipated.

Conflicting-content detection compares every normalized, business-
meaningful field the event carries — `provider`, `provider_event_id`,
`event_type`, `provider_payment_reference`, `amount`, `currency`, and
`occurred_at` (at whole-second precision, matching what is actually
persisted) — never a raw payload comparison, since no raw payload is
ever stored.

**No exactly-once external delivery claim**: a provider may redeliver
the same event at least once; this architecture guarantees an
idempotent BUSINESS EFFECT under that assumption, never that the
provider itself delivered exactly once — the same framing
`RELIABILITY.md` already uses for outbound webhook delivery, applied to
the inbound direction.

**Provider-payment-reference identity, separate from provider-event
identity**: `payments.(school_id, provider, provider_payment_reference)`
is independently unique — a provider may emit several distinct events
over one underlying transaction's lifecycle, but at most one Payment
may ever be recognized per (School, provider, transaction), even if a
second settlement event arrives under a different `provider_event_id`.

**Refund deferral**: no Refund, payment reversal, payment deletion,
payment status mutation, allocation reassignment, unapplied-cash, or
credit-balance mechanism is implemented in 0G.5. A Payment, once
recognized, is permanent financial history; correcting it is a future
checkpoint's explicit compensation mechanism, not an in-place mutation.

**No raw provider payload, no card/bank data**: only normalized,
minimal fields are ever accepted by `NormalizedProviderEvent` or
persisted — no full provider payload, no PAN/CVV/track data/PIN/bank
credential/provider API secret/webhook signing secret/private key is
ever a column anywhere in this schema. Provider signature/authenticity
verification is explicitly DEFERRED to a future provider/HTTP adapter
(0G.6+) — `PaymentProviderEventService` trusts its caller completely,
exactly as `LedgerService::post()`/`ChargeService::assess()` already
trust theirs, and is never reachable from an HTTP route in 0G.5.

## Rationale

Every decision above follows the SAME underlying principle ADR 0030
already established one layer down: once a financial fact is
recognized, it must never require rewriting, and any invariant a plain
`CHECK` constraint cannot express (a cross-row SUM, a "this insert must
happen inside the same transaction as that other insert" ordering, a
shared lock across two independent write paths) gets a database
trigger, not merely an Application-layer convention — because an
Application convention only prevents a MISTAKE by code that remembers
to call it; it does nothing against a raw SQL path, a future
maintainer who doesn't know the convention exists, or two genuinely
concurrent transactions racing each other. Mandatory full allocation
was chosen over inventing unapplied-cash accounting specifically
because FINANCE.md never defines that accounting treatment, and
inventing one silently here — under a checkpoint whose actual charter
is idempotent provider integration, not general ledger design — would
be exactly the kind of accidental architectural drift CLAUDE.md rule 2
and this repository's "read an ADR before proposing the thing it
already considered" discipline both warn against.

## Alternatives considered

1. **Mutable Payment status (pending/settled/failed)**, as FINANCE.md's
   0G.0 sketch originally floated. Rejected: reopens exactly the
   "mutable financial-status machine" problem 0G.4 already closed for
   `charges`, and gives a raw/future caller a write path into a
   financially load-bearing status field with no invariant protecting
   it.
2. **Allow overpayment, credit the excess to an "unapplied cash"/
   liability account.** Rejected for 0G.5 specifically: this is a real,
   legitimate accounting pattern, but FINANCE.md never defines its
   chart-of-accounts treatment, and inventing one now — under time
   pressure, without the same scrutiny a dedicated checkpoint would
   give it — risks getting the accounting wrong in a way that is
   expensive to unwind later (ADR 0030's own core argument). Rejecting
   the operation outright until a future checkpoint deliberately
   designs this is safer than guessing.
3. **Rely solely on the deferred `payments_fully_allocated_check`
   constraint trigger for allocation-set integrity, without the
   `creation_txid` freeze.** Rejected: a deferred constraint trigger
   proves the allocation set is correct AT THE MOMENT ITS OWNING
   TRANSACTION COMMITS, but says nothing about a LATER transaction
   inserting an additional row against an already-committed Payment —
   the identical gap `journal_lines_reject_post_commit_insert()` was
   built to close for journal lines one layer down. Both mechanisms are
   required together, exactly as they are for `journal_entries`/
   `journal_lines`.
4. **Rely solely on Application-layer `SELECT ... FOR UPDATE` locking
   for Charge over-allocation, without a database-level lock inside the
   `payment_allocations` trigger.** Rejected: this protects only the
   ONE Application code path that remembers to take the lock. A raw or
   future Application path inserting into `payment_allocations` without
   going through `ChargeService::lockChargeForAllocation()` first would
   bypass it entirely, and PostgreSQL's MVCC would then let two such
   concurrent transactions each compute the Charge's remaining capacity
   without seeing the other's uncommitted row.
5. **Reuse `App\Support\Idempotency\IdempotencyGuard` directly for
   provider-event claims.** Rejected: that class's public contract
   (`claim()`/`complete()`/`completeWithin()`/`failDeterministically()`)
   is built entirely around HTTP request/response replay — storing and
   replaying a `response_status`/`response_body`/`response_headers` —
   which has no meaning for a trusted internal ingestion call with no
   HTTP request of its own. Reusing the ATOMIC-CLAIM PATTERN it
   established, against a dedicated table with a dedicated key shape,
   is the correct level of reuse (rule 34: payment-provider idempotency
   is its own contract, separate from the client API `Idempotency-Key`
   contract).

## Consequences

- Every Payment/allocation/provider-event row, once its owning
  transaction commits, is permanent, unforgeable financial history —
  correcting a mistake requires a future Refund/compensation
  checkpoint, never an `UPDATE`.
- A future Refunds checkpoint must design its own compensation model
  from first principles (a reversing `JournalEntry` plus an explicit
  authorization record, per `ARCHITECTURE.md` §10 rule 4) rather than
  reopening any 0G.5 row.
- A future "unapplied cash" checkpoint, if ever built, must introduce
  its own explicit ledger account type and Application-layer model —
  0G.5 deliberately leaves no half-built scaffolding for it to
  accidentally inherit.
- Every future caller of `PaymentProviderEventService::recordSettlement()`
  (a provider/HTTP adapter, an administrative reconciliation tool, a
  test) is bound by the SAME database-enforced invariants regardless of
  how carefully it is written — the protection does not depend on
  every future caller remembering the Application-layer convention.

## Future extraction/evolution path

If a future checkpoint needs partial/negotiated settlement, unapplied
cash, or Refunds, it extends this architecture (a new `refunds` table,
a new deliberately-designed unapplied-cash ledger treatment) — it does
not need to rewrite `payment_provider_events`/`payments`/
`payment_allocations`' own immutability guarantees, which remain valid
regardless of what compensating mechanism is layered on top later,
exactly as ADR 0030 anticipated for the ledger itself.

## Amendment note (ADR 0057, Phase 0O.11, 2026-09-28)

- **This ADR is the payment-provider ingestion foundation. No real gateway
  exists.**
  - ADR 0057 defers the first real gateway from Phase 0 / production v1.
  - `PaymentProviderEventService::recordSettlement()` stays a trusted,
    unrouted boundary.
  - Provider signature verification remains deferred to that future
    gateway's own ADR.
- **ADR 0057 also requires manual/offline payment recording for v1** — a
  human path, unlike the provider boundary. Phase 0O.11A will record its
  design as an amendment here before any code. It must keep this ADR's
  database-enforced invariants:
  - immutable settlement;
  - exact allocation;
  - ledger posting in the same transaction;
  - a correction is never an `UPDATE`.

  It introduces no refund, void or payment reversal.

## Implementation amendment — manual / offline settlement recording (Phase 0O.11A, 2026-09-28)

ADR 0057 §3 requires manual/offline payment recording for production v1. This
amendment is its design. ADR 0057 remains authoritative for v1 scope.

### 1. Two ingresses, one settled-payment core

- **Manual recording is a trusted human/application ingress.** An
  authenticated School user records a payment that has **already happened
  outside Lycenza** (cash, an offline bank transfer, a cheque). Lycenza moves
  no money, contacts no bank or processor, and holds no payment credential.
- **Provider settlement ingress stays separate and unchanged.**
  `PaymentProviderEventService::recordSettlement()` keeps its trusted SYSTEM
  boundary, its `payment_provider_events` claim, its provider-reference
  uniqueness and its replay/conflict semantics.
- **Both ingresses call one internal core,
  `App\Domain\Payments\Application\SettledPaymentRecorder`** ("record an
  already-settled payment"). Before this amendment that logic was inside the
  provider service. The core:
  - locks every named Charge in ascending id order;
  - refuses cancelled and over-allocated Charges;
  - posts the settlement `JournalEntry`;
  - creates the `Payment` and its allocations;
  - writes the caller-named audit event;
  - dispatches `PaymentSettled`.

  The core never authorizes, never verifies a provider, and never knows how
  its caller established authority. It must run inside the caller's
  transaction and `TenantContext`.
- **Manual recording never manufactures a payment-provider event.**
  `payment_provider_events` means external-provider evidence only. A manual
  Payment has `provider`, `provider_payment_reference` and `provider_event_id`
  all `NULL`, enforced by the database (§3).

### 2. Semantics kept from this ADR

- **Posting and immutability.** A manual Payment is posted like any other:
  immediate recognition, with no draft or pending state.
  - `payments` and `payment_allocations` stay append-only
    (`TenantRls::makeAppendOnly()`).
  - The allocation set is frozen at commit (`creation_txid`).
  - Allocations sum exactly to the amount; no Charge is ever over-allocated;
    nothing is allocated to a cancelled Charge (all database-enforced).
  - There is no edit, PATCH or delete path.
- **No correction action in v1 (owner decision, 2026-09-28).** There is no
  refund, void, payment reversal or "correction" action, and no negative
  Payment. ADR 0057 §3 requires an append-only correction mechanism that a
  later contract defines, and none exists. The project owner accepted
  immutable posted manual Payments without a correction action for 0O.11A.
  The correction contract is an explicit open Finance follow-up. Mitigation:
  the pre-post confirmation step (§8).
- **Payment-owned journal entries are not reversible directly (owner
  decision, 2026-09-28).** Before this amendment, `finance.ledger.reverse`
  could reverse a Payment's settlement `JournalEntry` through the generic
  journal-reversal action. That left the Payment and its allocations intact
  and detached from the ledger. Now:
  - Payments owns the trigger `journal_entries_payment_reversal_guard`
    (`BEFORE INSERT ON journal_entries`). It refuses any reversal of a journal
    entry named by `payments.journal_entry_id`, for every role.
  - `LedgerService::reverse()` maps the refusal to
    `JournalEntryNotReversibleException` (409
    `JOURNAL_ENTRY_NOT_REVERSIBLE`).
  - Charge cancellation and ordinary journal reversal are unaffected.
  - The same class of gap for other subledger-owned entries (Charge
    recognition, payroll, canteen) is recorded as existing debt.
- **Ledger posting** is identical for both ingresses:
  - debit the explicit settlement asset account;
  - credit each allocated Charge's receivable account.

  For manual recording the School user chooses the settlement account. It
  must be an **active `asset` ledger account** of the School, in INR (e.g. the
  School's own "Cash" or "Bank" account). No clearing, cash or bank account
  is invented. The provider path keeps its trusted-caller account semantics
  unchanged.

### 3. Schema (`2026_10_27_090000_add_manual_payment_recording`)

`payments` gains:

| Column | Type | Rule |
|---|---|---|
| `source` | `provider` \| `manual` | Defaults to `provider`, so existing rows and every 0G.5-shaped insert stay valid; the shape check means a manual row can never pass as `provider` |
| `method` | varchar | Closed catalog (§4) |
| `manual_reference` | varchar(64) | Optional; bounded format (§5) |
| `recorded_by_user_id` | FK `users`, `RESTRICT` | The recording User |
| `idempotency_key` | uuid | §6 |

- **`provider`, `provider_payment_reference` and `provider_event_id` become
  nullable.** `payments_source_shape_check` makes the two shapes exclusive:
  - a `provider` row has every provider column and no manual column;
  - a `manual` row has `method`, `recorded_by_user_id` and `idempotency_key`
    and no provider column.
- **Existing constraints are unchanged.** Provider-reference and
  provider-event uniqueness never see manual rows, because `NULL`s are
  distinct.
- **`occurred_at` is the existing `settled_at` column.** It is when the School
  says the money was received.
  - Manual entry is a calendar date. It is stored as the start of that day in
    the School's timezone.
  - The date must not be later than today in the School's timezone. There is
    no historical lower bound, because Finance has no accounting-period
    contract.
  - It is never replaced with "now".
- **`recorded_at` is the existing `created_at`.** It is server-set in the
  committing transaction and immutable (append-only). It is never
  user-supplied.
- **No payer column.** Every Charge already names its Student, and Guardian
  context derives from the Student. A payer column would duplicate identity
  truth.

### 4. Payment-method catalog (closed)

`cash`, `bank_transfer`, `cheque` — `App\Domain\Payments\Domain\ManualPaymentMethod`,
mirrored by the database CHECK.

- There is no card, wallet, UPI-provider, online-gateway or processor method.
- There is no free-form method.
- Adding a method needs an amendment here.

### 5. Reference

- **One optional bounded reference, for every method** — for example a cheque
  number or a bank transfer UTR.
- **Format:** 1–64 characters, `^[A-Za-z0-9]([A-Za-z0-9 ./_-]*[A-Za-z0-9])?$`,
  enforced in the application and the database.
- **Not mandatory for any method.** No repository product rule establishes a
  mandatory reference per method.
- **It is not a provider transaction id.** It must never hold card data, bank
  credentials or a full account number: the form says so, the character set
  keeps it narrow, and it is classified Highly Sensitive like all financial
  data.
- **It is never logged, and never written to audit metadata or the outbox.**
- **No uniqueness is enforced on it.** Cheque numbers repeat across banks, and
  no evidence supports a rule that could reject a legitimate payment.

### 6. Request idempotency and duplicate guarantees

- **Every manual record carries a server-issued opaque UUID `idempotency_key`.**
  - It is issued when the recording form is rendered and reused for every
    retry of that form.
  - It is stored on the Payment itself, under `payments_manual_idempotency_unique
    (school_id, idempotency_key)`.
  - It is atomic with the Payment by construction, so there is no crash window
    (rule 33). This is the ADR 0031 dedicated-claim pattern (rule 34), not the
    generic `Idempotency-Key` middleware.
- **Order: authentication → membership/TenantContext → capability →
  operational School → idempotency** (rule 32).
- **Inside the transaction** the service:
  1. takes `SchoolOperationalGuard::requireOperational()` (FOR SHARE);
  2. takes a transaction-scoped advisory lock on (School, key), so two
     requests with the same key serialize;
  3. looks the key up.
- **Outcomes for a key that already exists:**
  - same School, same recording User and identical content (amount, method,
    reference, occurred date, settlement account, allocation set) → the
    existing Payment is returned (`duplicate_replay`), with no new effect;
  - any difference, including a different User → fail closed
    (`ManualPaymentIdempotencyConflictException`, 409).
- **The unique constraint remains the authoritative guarantee** (rule 30). A
  violation is resolved the same way.
- **What this guarantees.** One logical recording request (double click,
  network retry, browser resubmit) records at most once. It does **not**
  detect a real-world payment recorded twice through two separate forms.
  Duplicates are never inferred from amount, date or Charge.

### 7. Authorization, tenancy, lifecycle

- **Capability `finance.payments.record`** (school namespace) is granted by
  default to `school_admin` only, the same as every other Finance mutation
  capability.
  - It is not `finance.payments.view`, and `finance.payments.manage` does not
    exist.
  - Group and platform authority never grant it (ADR 0044/0045). An elevated
    platform session is refused on every School route.
- **Requirements:** an authenticated User, an active membership, the
  session-resolved `TenantContext` and the capability.
  - No `school_id` is accepted from input.
  - Every Charge and the settlement account are resolved inside the School.
    Cross-School ids behave exactly like nonexistent ones.
- **A suspended or non-operational School refuses manual recording**
  (`SchoolOperationalGuard` inside the transaction). The ADR 0047 exception
  for future provider evidence does not apply to human entry.

### 8. Audit, events, receipts

- **One School audit event, `payment.recorded_manually`**, with the Payment
  as subject. Metadata: `method`, `currency`, `occurredOn`,
  `allocationCount`, `hasReference`.
  - There is no amount, reference value, idempotency key or payload, mirroring
    `payment.settled`'s minimization.
  - The actor is the recording User.
- **`recorded_by_user_id` on the Payment is the single payment-level
  provenance.** Provider Payments never gain a User.
- **`PaymentSettled` (`payment.settled.v1`) is dispatched unchanged for both
  ingresses.** It is not webhook-registered, and no new external event is
  added (O15 not broadened).
- **The UI confirms before posting,** showing a summary of amount, method,
  date, reference and allocations. After success it shows the immutable
  Payment. This is not a statutory or formal receipt; receipts stay deferred
  (0G).

### 9. Transport

- **Session-authenticated Inertia routes only:**
  - `GET /app/finance/payments/record`;
  - `GET /app/finance/payments/record/students/search`;
  - `POST /app/finance/payments/record` — `web` group, CSRF, throttle
    `finance-payment-recording` at 30 per minute per User.
- There is no `/api/v1` write, partner route, provider callback or
  integration route.

### 10. Concurrency

- **Charges** are locked in ascending id order (the core), so two recordings
  over overlapping Charges serialize without deadlock. The allocation trigger
  remains the backstop.
- **The same key** serializes on the advisory lock.
- **Suspension** serializes with recording on the School row (FOR SHARE vs
  UPDATE).
- **Charge cancellation** serializes with recording on the Charge row lock.

Real two-process PostgreSQL tests prove each case
(`Tests\Feature\Payments\ManualPaymentConcurrencyTest`).

## Amendment — charge capacity includes fee adjustments (FEE.3, 2026-09-30)

ADR 0062 §15 (owner decision G1) amends Invariant B. It is implemented in
the Payments-owned migration
`2026_10_31_090200_amend_payment_charge_capacity_for_fee_adjustments`.

- **The rule.** `SUM(payment_allocations) + SUM(uncancelled fee_adjustments)
  ≤ charges.amount`.
- **Where it is enforced.** At the insert of either row, under the same
  charge row lock (FOR UPDATE):
  - `payments_lock_and_validate_charge_allocation()` now counts live
    adjustments;
  - the new `payments_lock_and_validate_charge_adjustment()`
    (`fee_adjustments_capacity_trigger`) guards the adjustment side.

  A payment and a concession can never consume the same outstanding
  capacity. Real-process proofs are in `FeeConcessionConcurrencyTest`.
- **Application layer.** `SettledPaymentRecorder`'s pre-check uses
  `ChargeAllocationSnapshot::netAmount()`. The manual-payment form shows the
  outstanding net of concessions. Both read Fees' own totals
  (`ChargeService::lockChargeForAllocation`/`liveAdjustmentTotalsFor`), so
  the dependency stays Payments → Fees.
- **Unchanged:**
  - no refund, credit, reallocation or payment reversal;
  - allocations are still immutable;
  - `ChargeAllocationExceedsChargeAmountException` is still the typed
    refusal.
- **Rollback.** `down()` restores the 0G.5 function body verbatim.

## Amendment — receipts issued with every settlement (FEE.4, 2026-09-30)

ADR 0062 §17 (owner decisions I, I2) is implemented in the Payments-owned
migrations `2026_11_01_090000`/`090100`.

- **Issued with the settlement.** `SettledPaymentRecorder::record()` issues
  the Payment's receipt through the trusted `ReceiptIssuer`, in the same
  transaction, for every ingress (manual, and the provider foundation).
  - A receipt failure rolls the settlement back.
  - A replay resolves its own claim first and never reaches the recorder,
    so it consumes no number.
- **What a receipt is.** Evidence only: one per Payment, immutable, no
  amount of its own, no ledger posting.
- **Numbering.** Gap-free per School × financial year: a counter row
  locked in the settlement transaction, database-checked.
  - *FEE closure remediation (2026-09-30):* before creating or locking the
    series, the issuer takes the School's Fees settings lock SHARED
    (`FeeSettingsService::receiptNumberingForIssuance()`), so a first
    receipt and a receipt-numbering change serialize.
  - Settlement lock order: Charges (ascending) → settings lock SHARED →
    counter row (ADR 0062 "Development closure").
- **Payments settled before FEE.4** are receipted only by the explicit,
  idempotent `finance:receipts-backfill {school}`.
- **Unchanged:**
  - Payments stay immutable; no Payment row is edited;
  - no refund, void, reversal or tax logic;
  - `PaymentSettled` (`payment.settled.v1`) is unchanged, and
    `payment_receipt.issued.v1` is a separate internal event.
- **Reads.** `PaymentDetail` gains `receiptId`/`receiptNumber`.
  `PaymentReceiptReadService` and `StudentFeeStatementReadService` (Payments)
  read Fees facts only through `ChargeService` — still Payments → Fees.

## Amendment — late-fee runs are Payments-owned (FEE.5, 2026-09-30)

ADR 0062 §16 (owner decision H) is implemented in migrations
`2026_11_02_090100`–`090300`.

- **What Payments owns.** `late_fee_runs`, `late_fee_run_items` and
  `late_fee_assessments`. Eligibility depends on allocations, which
  Payments owns.
- **How the outstanding is computed.** It is `amount − allocations − live
  adjustments`. `ChargeOutstandingReader` computes it under the source
  charge's row lock (Fees' `lockChargeForAllocation`), so a payment,
  concession or late fee racing on one charge serializes.
- **How the late fee is posted.** A new charge, through the trusted
  `ChargeService::assess` on the rule's late-fee head accounts. The source
  charge, payments and allocations are never changed.
- **The charge guard.** `charges_late_fee_guard_trigger` sits on Fees'
  `charges` — the same cross-boundary structural pattern as
  `charges_payment_allocation_guard_trigger`.
- **Still Payments → Fees.**
  - Rule and charge facts come only through Fees' Application layer
    (`LateFeeRuleService::snapshot`, `ChargeService::lateFeeCandidates`,
    `lateFeeSource`).
  - Fees never reads `payment_allocations` or any late-fee table.
- **Legal.** DEVELOPMENT AUTHORISED — PROD LEGAL SIGN-OFF REQUIRED (ADR
  0058 E31).
