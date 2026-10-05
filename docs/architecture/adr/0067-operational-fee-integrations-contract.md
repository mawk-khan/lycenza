# ADR 0067: Operational Fee Integrations Contract (OPF)

- Status: **Accepted (OPF.0, 2026-10-05). Contract only — no OPF code
  exists yet.** Owner decisions D1–D9 (§25) are adopted. OPF.1–OPF.5 (§26)
  implement it, each behind its own checkpoint.
- Date: 2026-10-05
- Programme: **OPF — Operational Fee Integrations** (Transport, Hostel,
  Library fines, Admissions fee). Roadmap "Post-foundation product
  programmes", order 4.
- Evidence: the OPF.0 read-only readiness audit at `41185ad` (2026-10-05).
- Builds on, and does not reopen:
  - ADR 0062 (FEE) and its §8 / §24 OPF statements;
  - ADR 0031 (Payments, rule 91);
  - ADR 0064 and ADR 0066 (E21 retention, E21-RH);
  - the closed Transport (10B), Hostel (10D), Library (10A) and Admissions
    modules.
- FEE, HRX, TCH, E21-RH and Phase 10 are not reopened. Each OPF slice
  integrates only through the seams this ADR names.

## 1. Context and motivation

FEE (ADR 0062) delivered fee heads, structures, instalments, optional
selections, assessment runs, concessions, receipts and late fees. It
deliberately stopped at the module boundary:
- **ADR 0062 §8:** "Inferring selection from another module (Transport
  assignment, Hostel residency) is the future **OPF** programme, which will
  create selections through this same service. FEE never reads those
  modules."
- **ADR 0062 §24:** Transport fee linkage, Hostel fees and deposits, Library
  fines and admissions application fees are future OPF. "OPF creates
  optional selections and structures through FEE's services."
- **The module docs** say the same. Transport: a future integration "must
  use the real Finance/Fees domain …, never parallel financial logic in
  Transport". Hostel: Fees integrates "by referencing
  `hostel_residency_assignments` by id, never by adding financial columns to
  this module's own tables". Library and Admissions: no fine, fee or
  payment field exists.

The audit found no hidden or partial OPF code. Today a school must select a
Transport or Hostel fee by hand for every Student (`FeeOptionalSelectionService`,
`finance.fee_structures.manage`), and Library fines and admission fees have
no path at all. OPF connects the operational lifecycle to FEE without
moving financial authority out of Finance.

## 2. Scope

| Integration | Primitive | Slice |
|---|---|---|
| Transport fee | Optional selection on a Transport fee line | OPF.1 |
| Hostel recurring fee | Optional selection on a Hostel fee line | OPF.2 |
| Admission fee at Student conversion | Optional selection created at conversion | OPF.3 |
| Library overdue fine | Event charge through `ChargeService::assess()` | OPF.4 |

OPF.5 is the closure audit.

## 3. Explicit exclusions

Not in OPF (each needs its own contract and ADR):
- refunds, credits, unapplied cash, payment voids or reversal (rule 91;
  ADR 0062 §24);
- **Hostel refundable deposits** (D2): a deposit is a liability, not
  revenue, and needs a liability / refund / credit contract;
- **pre-conversion application fees** (D1): no `applicant_id` subject on
  `charges`;
- Library **lost or damaged** item charging, replacement costs, and any new
  loan states;
- proration (FEE decision D1 stands);
- a generic FEE price-dimension or variant concept (D7);
- Guardian- or Student-facing fee views (future POR), gateway payments
  (ADR 0057), GST or tax calculation (E30), and invoices;
- automatic cancellation or modification of any assessed charge by an
  operational module.

## 4. Dependency direction

```text
Transport / Hostel / Admissions / Library   (own the operational lifecycle, the link rows, the tier mapping)
        │  calls trusted FEE seams only
        ▼
Fees application services                   (selections, assessment, charges, concessions)
        │  existing, unchanged
        ▼
Finance ledger / Payments                    (journal entries, allocations, receipts)
```

- **FEE never queries an operational module** to decide whether a fee
  applies. No Fees, Finance or Payments class reads a Transport, Hostel,
  Library or Admissions table or service. An architecture guard test
  enforces this in every OPF slice (as `FeeSetupArchitectureGuardTest`
  does for Payments → Fees).
- **The operational module owns the intent and the evidence:** its
  lifecycle event, its link rows and its tier mapping. It asks FEE to
  record or withdraw intent, or (Library only) to assess one charge.
- **There is no parallel ledger and no parallel charge.** Every amount
  reaches the books only through `ChargeService::assess()`, either inside a
  FEE assessment run or (Library) through the Library fine service.
- **One known database exception:** the Finance retention expiry function
  must name source-link tables that reference `charges` (§21), as it already
  does for `canteen_orders`. That is retention plumbing, not a business read.

## 5. Charge subject: Student only

`charges.student_id` stays NOT NULL, the only financial subject (ADR 0062;
`FINANCE.md` "Financial subject: Student only"). OPF adds no `applicant_id`,
`employee_id` or polymorphic subject. Admissions charges exist only after
the applicant has become a Student (D1). The `FINANCE.md` exclusive-arc
sketch stays the pattern for any future subject, behind its own ADR.

## 6. Amount ownership

There is exactly one authoritative source for every amount:

| Integration | Amount source | Must not exist |
|---|---|---|
| Transport, Hostel, Admission fee | `fee_structure_installments.amount` of the selected fee line (FEE) | any rate, price or amount column on Transport, Hostel or Admissions tables |
| Library fine | the Library-owned **versioned fine policy** (D3) | Library fine amounts in FEE late-fee rules; tuition late-fee rules as the fine engine |

- Price tiers (route or room category) are separate fee heads or lines
  (D7). The operational module owns only the mapping from its tier to a
  fee head, never an amount.
- Ledger accounts come from the fee head (FEE), or for Library from a fee
  head / account mapping validated as FEE does (asset receivable, income
  revenue, distinct, active, same School).
- Money rules apply throughout: NUMERIC, bcmath, INR, positive amounts, and
  corrections as new records (CLAUDE.md rule 9).

## 7. Optional-selection semantics (unchanged FEE behaviour)

- One **active** selection per School × Student × academic year × fee head
  (`fee_optional_selections_one_active`). A withdrawn selection is final;
  re-selecting creates a new row.
- A selection is **intent, not money.** An assessment run charges a period
  only if the selection is active when the run item executes
  (`FeeAssessmentItemExecutor`); otherwise the item fails as
  `optional_not_selected`.
- Withdrawal affects future runs only and never touches an existing charge.
- One live assessment per Student × year × head × billing period
  (`fee_assessments_one_live_per_period`) prevents double billing across
  reruns.

## 8. The trusted source-selection seam (built in OPF.1, not now)

FEE gains a minimal trusted seam beside the human path, for example
`FeeOptionalSelectionService::selectForSource()` / `withdrawForSource()`:
- **Callers:** source-module application services only (Transport, Hostel,
  Admissions), never a controller and never a route of its own.
- **Authorization:** the caller has already checked its own operational
  capability (D9). The seam performs no `finance.*` check and grants none,
  and it records the initiating actor and the source (module and source-row
  reference) in its audit event.
- **Inputs:** Student, academic year, fee structure line (resolved by the
  caller from its tier mapping), actor, source descriptor.
- **Idempotent:** an existing active selection for the same Student × year
  × head is returned, not duplicated. A concurrent insert that loses the
  unique-index race resolves to the winner, never to an error or a second
  row.
- **Validation is FEE's own:** the line must be an optional line of that
  head in a structure for that year, as the existing trigger enforces.
- **What it never does:** assess, cancel, void or adjust anything, and it
  never reads the source module.

The human path (`select`/`withdraw`, `finance.fee_structures.manage`) stays
unchanged.

## 9. Trigger semantics (D5, D6)

| Integration | Creates or activates intent | Withdraws intent | Money |
|---|---|---|---|
| Transport | Student assignment start (`TransportStudentAssignmentService::assign`) | assignment end | assessment runs |
| Hostel | residency start (`HostelResidencyService::assign`) | residency end | assessment runs |
| Admissions | successful conversion (`AdmissionConversionService::convert`), when the School configured an Admission fee line | not applicable (one-time) | assessment runs |
| Library | (no selection) | (none) | one event charge at assessment (§17) |

A re-assignment (end, then new assignment) withdraws, then selects again.
Under the unique key, intent stays continuous; the source link rows record
both relationships.

## 10. Idempotency requirements

Charge creation stays **exactly once** through source-owned idempotency plus
FEE's existing constraints:
- **Selections:** the unique active selection key, plus a source-owned link
  row unique per (source relationship, academic year).
- **Assessed periods:** `fee_assessments_one_live_per_period` and FEE's
  advisory-locked executor.
- **Library fines:** a Library-owned link row unique per (loan, fine kind),
  holding a unique `charge_id`. The fine is created in the same transaction
  as the link row, under a lock on the loan row, mirroring Canteen
  (`canteen_orders_charge_id_unique` plus the row lock).
- **Retries:** HTTP retries use the `idempotent` middleware where routes
  exist (rule 29). The database constraints are the authority (rule 30).
- **Concurrency:** proven with real concurrent processes in every slice that
  creates a selection or a charge.

## 11. Lifecycle and withdrawal (D5)

- **Before assessment,** the operational lifecycle may create and withdraw
  intent freely.
- **At execution,** the selection state when a run item executes decides
  whether that period is assessed, in full: no proration (FEE D1).
- **After assessment,** ending, transferring or editing the operational
  record never cancels, voids or alters an assessed charge. The only
  corrections are explicit Finance actions: void the fee assessment and
  cancel the charge while unpaid (`finance.charges.manage`), or a targeted
  concession (maker/checker).

## 12. Historical-charge immutability

Operational lifecycle edits cannot mutate historical Finance evidence:
- charges are immutable, and cancellation is final and impossible once a
  payment is allocated (existing triggers);
- source link rows are append-only evidence: never updated in place, never
  deleted outside retention;
- the operational module never deletes or updates any FEE, Finance or
  Payments row.

## 13. Refund and reversal boundary

OPF adds no refund, credit, unapplied cash or payment reversal. Payments
stay immutable (rule 91). Corrections are limited to:
- voiding an unpaid assessment and cancelling its charge;
- a waiver or concession adjustment within the charge's outstanding;
- (Library) the Library fine void path (D4).

## 14. Transport contract (OPF.1)

- **Trigger:** assignment start selects, and end withdraws, the Transport
  fee line for the Student's academic year, through the seam (§8).
- **Tier mapping:** Transport owns a route (or route-tier) → fee head
  mapping. This is configuration that stores no amount, uses
  `transport.routes.manage`, and is audited. A route without a mapping
  selects nothing and says so; it never fails the operational assignment.
- **Academic year:** assignments carry none. OPF.1 derives the year from
  the Student's enrollment at the event, and records it on the link row.
- **Explicit carry-forward (D8):** an audited, idempotent, race-safe
  operation that creates or reuses next year's selection for every
  still-active assignment. Nothing carries itself silently.
- **Unchanged:** Transport never assesses money and never stores amounts.

## 15. Hostel contract (OPF.2)

The same contract as Transport, keyed on residency (residency start and
end; a room-category or hostel → fee head mapping, using
`hostel.directory.manage`; and explicit carry-forward).
- **Unchanged:** Hostel tables gain no financial or amount column
  (`HOSTEL.md` §16); OPF's link rows are separate, Hostel-owned tables
  referencing the residency by id.
- **Excluded:** deposits and refunds (D2). Damage charges stay manual
  ad-hoc Finance charges; no inspection concept is introduced.

## 16. Admissions contract (OPF.3)

- Only after a successful conversion: inside the conversion transaction,
  if the School configured an Admission fee line for the new Student's
  structure, the conversion selects it through the seam.
- The link row is per application, unique, and records the conversion.
- No charge, selection or financial row ever references an applicant or
  an unconverted application.
- A genuine pre-conversion application fee is deferred (D1).

## 17. Library contract (OPF.4)

- **Versioned fine policy, Library-owned (D3):** rate, grace and cap
  semantics, as immutable versions, snapshotted on each assessment. The
  ledger destination is a fee head / account mapping validated as FEE does.
- **Event charge:** an overdue loan is assessed at check-in, or explicitly
  by a librarian under a new narrow capability (proposed
  `library.fines.assess`). It is one fine per loan and kind, through
  `ChargeService::assess()`, with a Library-owned link row (§10). There is
  no daily accumulation of separate charges.
- **Not the tuition late-fee engine:** FEE late-fee rules
  (`fee_late_fee_rules`, structure-scoped, E31) are not reused or extended.
- **Corrections (D4):**
  - a FEE targeted concession with category `waiver` (existing
    maker/checker);
  - an explicit Library fine void path that voids the link evidence and
    cancels the charge while it is cancellable and unpaid, guarded at the
    database like `charges_fee_assessment_guard_trigger`.

  No refund.
- **Excluded:** lost or damaged states, replacement charging, and
  copy-on-loan deactivation rules.
- **Legal:** a new register item (§22), not E31.

## 18. Authorization and separation of duties (D9)

| Action | Capability | Notes |
|---|---|---|
| Create or withdraw Transport selection intent | `transport.assignments.manage` (existing) | via the seam; no `finance.*` granted |
| Create or withdraw Hostel selection intent | `hostel.residency.manage` (existing) | as above |
| Admission fee selection at conversion | `admissions.manage` (existing) | inside conversion |
| Maintain route / room → fee-head mappings | `transport.routes.manage` / `hostel.directory.manage` | configuration, no amounts |
| Turn selections into money | `finance.fee_assessments.run` (existing) | assessment runs only |
| Void or cancel an assessed charge | `finance.charges.manage` (existing) | explicit Finance action |
| Waive (concession) | `finance.fee_concessions.request` / `.approve` (existing) | maker ≠ checker |
| Assess a Library fine; maintain the fine policy | new narrow `library.fines.*` (OPF.4) | never a role-name check |

Operational users are never granted generic Finance fee management, and no
broad `manage_fees` capability exists. Authorization is re-evaluated on
every call, including idempotent replay (rule 32).

## 19. Audit requirements

Each fact is owned and audited once, by its owning domain:
- **Operational module:** its link evidence (for example
  `transport.fee_selection.linked` / `.withdrawn`, `library.fine.assessed`
  / `.voided`, `hostel.fee_selection.*`, `admissions.fee_selection.linked`),
  with ids only;
- **FEE:** `fee_optional_selection.created` / `.withdrawn` (recording the
  source and actor), `charge.assessed`, `charge.cancelled`, and the
  concession events;
- **No person-identifying data** in metadata beyond ids (existing audit
  rules).

## 20. Outbox and event requirements

- FEE's existing `charge.assessed.v1` / `charge.cancelled.v1` stay the
  financial events.
- OPF adds **no new outbox event** until a real consumer exists (Transport
  and Hostel deliberately emit none today).
- Nothing becomes webhook-subscribable except through an explicit
  `WebhookEventRegistry` addition (rule 45).

## 21. Retention and classification requirements

- **Finance evidence:** a source-link row that establishes the provenance
  of a financial selection or charge is classified with the Finance ledger
  (D8), unless an existing retention ADR requires a more precise class.
  Tier mappings and fine policies are Finance configuration (tenant
  lifetime), as `canteen_billing_configurations` is.
- **Explicit registration,** for every new OPF table in its own slice:
  - the E21-RH.7 retention anchors (`RetentionAnchors::TABLES` and the
    anchor migration registration, with the table's clocks and links
    tracked);
  - `TenantRetentionCatalog` (no table may be unclassified);
  - Student retention classification tests (`StudentRetentionClassificationTest`)
    wherever a table references a Student-linked row;
  - **for any table referencing `charges`:** a forward amendment of
    `retention_expire_finance_unit`, so the Finance unit refuses or removes
    the link rows exactly as it does `canteen_orders`. Otherwise the D8
    unit fails instead of being cleanly dependency-blocked.
- **Purge interaction:** a link row referencing an assignment, residency,
  loan or application keeps that operational row `dependency_blocked`
  (`ReferencingRows`, fail-closed) until the Finance unit releases it. Each
  slice states and tests this.
- **E21-RH is extended through forward migrations, never reopened.** The
  RH.7 fences, the anchors, the delete guard and the retention identity
  apply unchanged.

## 22. Legal and production gates

Development of every OPF slice is permitted. Production stays gated:

| Item | Development | Production | OPF effect |
|---|---|---|---|
| E21 — retention decisions and configuration | permitted | gated until qualified ratification and configuration | every OPF table |
| E30 — receipt form / GST | permitted | gated | every OPF charge |
| E31 — fee regulation (late fees, in-year changes) | permitted | gated | any in-year fee change; **does not cover Library fines** |
| E32 — RTE / statutory free seats | permitted | gated | whether RTE Students may be charged Transport, Hostel or Admission fees |
| **E34 — Library fine / penalty regulation (new)** | permitted | gated until a qualified answer is recorded | OPF.4 |

E34 is recorded in the ADR 0058 register as LEGAL_REVIEW_REQUIRED. No answer
is assumed.

## 23. Testing requirements

Every slice ships with tests at the CLAUDE.md §11 layers:
- service tests: selection and withdrawal idempotency, retries, the tier
  mapping, carry-forward;
- PostgreSQL constraints and real-process concurrency (one selection, one
  charge);
- tenant isolation (RLS) and cross-School denial;
- authorization, allowed and denied, for the operational and the finance
  capabilities;
- an architecture guard that Fees, Finance and Payments never read the
  source module;
- financial-history preservation: ending a relationship never cancels a
  charge;
- audit and outbox assertions;
- retention registration: anchors, catalog, classification and, for
  `charges` links, the Finance expiry;
- migration rollback per policy.

The full regression runs on the rule 82 cadence and at OPF.5.

## 24. Deferred work

Refunds, credits and unapplied cash; Hostel deposits (with their liability
and refund contract); pre-conversion application fees (an `applicant_id`
exclusive-arc subject, its own ADR); Library lost or damaged charging and
replacement cost; proration; a FEE price-variant dimension; Guardian- or
Student-facing fee views (POR); gateway payments; GST / tax.

## 25. Adopted owner decisions (2026-10-05)

| Id | Decision |
|---|---|
| D1 | **Conversion-time charging.** An Admission fee applies only after the applicant has become a Student. Conversion may create an optional Admission fee selection. Pre-conversion application fees are deferred; no `applicant_id` on `charges`. |
| D2 | **Hostel deposits deferred.** Refundable deposits need a liability / refund / credit contract. They are never modelled as fee revenue. |
| D3 | **Library owns a versioned fine policy** (rate, grace, cap) and calculates the amount. FEE stays the charge and ledger boundary; the accounting destination is a fee head / account mapping. Tuition late-fee rules are not the fine engine. |
| D4 | **Library fine correction:** a FEE `waiver` concession (maker/checker), plus an explicit Library void path that voids the assessment evidence and cancels the charge while it is cancellable and unpaid. No refund. |
| D5 | **No proration, full period.** Start creates or activates intent; end withdraws future intent; the selection state at run execution decides the period; ending never cancels or modifies an existing charge. Corrections are explicit Finance actions. |
| D6 | **Primitives:** Transport, Hostel and the Admission fee use optional selections; Library fines use an event charge through `assess()`. |
| D7 | **Tiers are separate fee heads or lines.** The operational module owns the tier → fee-head mapping; FEE owns the amounts. No generic price dimension; no duplicate amounts. |
| D8 | **Explicit, audited, idempotent, race-safe academic-year carry-forward** for still-active Transport and Hostel relationships. Nothing carries itself silently. |
| D9 | **A trusted FEE source-selection seam.** The operational capability creates or withdraws intent and the seam records actor and source. Selection is not assessment; only `finance.fee_assessments.run` turns it into money. Operational users get no generic Finance fee capability. |

## 26. Implementation sequence

| Slice | Scope | Status |
|---|---|---|
| OPF.0 | This contract; module, roadmap and register alignment | **Published (2026-10-05)** |
| OPF.1 | Transport fee selection: the trusted seam, Transport link rows, route → fee-head mapping, carry-forward, retention registration | Not started |
| OPF.2 | Hostel fee selection (reuses the seam; no deposits) | Not started |
| OPF.3 | Admission fee at Student conversion | Not started |
| OPF.4 | Library overdue fines: versioned policy, event charge, waiver and void, Finance retention amendment, E34 | Not started |
| OPF.5 | Closure audit and full regression | Not started |
