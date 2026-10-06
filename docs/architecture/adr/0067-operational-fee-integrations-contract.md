# ADR 0067: Operational Fee Integrations Contract (OPF)

- Status: **Accepted (OPF.0, 2026-10-05).** Owner decisions D1–D9 (§25)
  are adopted. OPF.1–OPF.5 (§26) implement it, each behind its own
  checkpoint. **OPF.1 implemented (2026-10-05, §27):** Transport fee
  selection through the trusted FEE seam. **OPF.2 implemented (2026-10-05,
  §28):** Hostel fee selection through the same seam. **OPF.3 implemented
  (2026-10-06, §29):** the Admission fee at Student conversion through the
  same seam. **OPF.4 implemented (2026-10-06, §30):** Library overdue fines
  as event charges through a second, narrow FEE event-charge seam.
  **OPF.0–OPF.5 — PUBLISHED / CLOSED (development, 2026-10-06, §31).**
  Development closure is not production readiness: E21, E30, E31, E32 and
  E34 stay open production gates (§22, §31.8).
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
| OPF.1 | Transport fee selection: the trusted seam, Transport link rows, route → fee-head mapping, carry-forward, retention registration | **Published (2026-10-05, §27)** |
| OPF.2 | Hostel fee selection (reuses the seam; no deposits) | **Published (2026-10-05, §28)** |
| OPF.3 | Admission fee at Student conversion | **Published (2026-10-06, §29)** |
| OPF.4 | Library overdue fines: versioned policy, event charge, waiver and void, Finance retention amendment, E34 | **Published (2026-10-06, §30)** |
| OPF.5 | Closure audit and full regression | **Published / closed (2026-10-06, §31)** |

## 27. OPF.1 as built (2026-10-05)

**Transport records FEE optional-selection intent from the Student
assignment lifecycle through the trusted seam. Only Finance assessment runs
make money. FEE never reads Transport.** §1–§26 are unchanged. The dated
clarifications below record how the contract was realized.

### 27.1 The trusted seam (FEE)
`App\Domain\Fees\Application\Sources\FeeSourceSelectionService`:
- **`selectForSource(School, studentId, academicYearId, feeHeadId,
  FeeSelectionSource, ?User)`** returns `FeeSourceSelectionResult`:
  `created` or `reused` with the selection id, or `not_applicable` with one
  of `fee_head_unavailable`, `no_enrollment_in_year`, `no_active_structure`
  or `no_optional_line`.
- **`withdrawForSource(School, selectionId, FeeSelectionSource, ?User)`**
  returns `false` when the selection was already withdrawn.
- **`selectableFeeHead(School, feeHeadId)`:** an active head's summary (no
  accounts, no amounts), for tier-mapping validation.
- **`FeeSelectionSource`** is a closed catalogue (`transport` only so far).
  Its module and source id are written into FEE's own
  `fee_optional_selection.created` / `.withdrawn` audit events with the
  actor, who is also recorded as `selected_by_user_id` /
  `withdrawn_by_user_id`.
- **Clarification of §8, line resolution:** FEE resolves the line exactly
  as an assessment run does. It takes the Student's current open enrollment
  in that year (new `StudentEnrollmentFeeTargetReadService::currentForYear()`,
  Students' read boundary), then the active structure for its grade and
  campus (`FeeStructureResolver`), then that structure's optional line of
  the fee head. A source never names a line.
- **Race safety:** the seam inserts in a savepoint. Losing the
  `fee_optional_selections_one_active` race resolves to the committed
  winner (`reused`) and never surfaces an error.
- **Unchanged:** the human path, `FeeOptionalSelectionService`
  (`finance.fee_structures.manage`). The seam adds no FEE schema.

### 27.2 Transport (`2026_12_02_090000_create_transport_fee_selection_tables`)
- **`transport_route_fee_heads`:** route → fee head, one per route
  (`transport_route_fee_heads_one_per_route`), composite foreign keys to
  `transport_routes` and `fee_heads` in the same School, **no amount
  column**, RLS.
- **`transport_fee_selections`:** provenance, unique per assignment × year
  (`transport_fee_selections_one_per_year`). It holds the assignment, year,
  fee head, the FEE selection, `link_reason` (`assignment` /
  `carry_forward`) and `selection_outcome` (`created` / `reused`).
  - A BEFORE INSERT trigger proves the selection is the assignment
    Student's selection of that year and head.
  - It is insert-only for the runtime role (`makeAppendOnly`; the
    verifier's no-runtime-DELETE and no-runtime-UPDATE lists).
  - It has no actor column: the actor is in the audit trail and on FEE's
    selection, as Transport assignments carry none.
- **`TransportFeeSelectionService`** is the only writer:
  - **`recordForNewAssignment()`** runs inside
    `TransportStudentAssignmentService::assign()`'s transaction.
  - **`withdrawForEndedAssignment()`** runs inside `end()`'s transaction and
    withdraws every still-active selection the assignment recorded.
  - **`carryForward(School, academicYearId, ?User)`** locks each active
    assignment in its own transaction and returns linked / already_linked /
    unmapped / ended / not_applicable counts.
  - **`setRouteFeeHead()`** sets or clears the mapping (null clears it).
- **Clarification of §14, the year:** a new assignment records intent for
  the School's single active academic year (`CurrentAcademicYearResolver`,
  database-enforced as one per School). The Student's enrollment in that
  year then selects the structure (§27.1). Carry-forward names its target
  year explicitly: a draft or active year of the School (new
  `CurrentAcademicYearResolver::openYear()`).
- **"Says so" (§14):**
  - An **unmapped** route records nothing and audits nothing; the
    assignment proceeds as before.
  - A **mapped** route whose intent can't be recorded audits
    `transport.fee_selection.not_applicable` with the reason, for example
    no active year, no enrollment or no optional line. The assignment still
    proceeds.
- **API**, Transport capabilities only:
  - `GET` / `PUT /transport-routes/{route}/fee-head` (`transport.routes.view`
    / `.manage`);
  - `GET /transport-student-assignments/{id}/fee-selections`
    (`transport.assignments.view`);
  - `POST /transport-fee-selections/carry-forward`
    (`transport.assignments.manage`, `idempotent`).

  These are documented in the OpenAPI contract, with regenerated shared
  types. There is no Inertia page in OPF.1.
- **Audit (Transport):** `transport.fee_selection.linked` / `.withdrawn` /
  `.not_applicable` / `.carried_forward`, and
  `transport.route_fee_head.set` / `.cleared`, with ids only. **No outbox
  event** (§20).

### 27.3 Retention (clarification of §21)
- `transport_fee_selections` is **Finance ledger** in
  `TenantRetentionCatalog`. It is retained with the selection it explains:
  FEE's selections are already "retained, blocks: Finance D8" and are not
  deleted by the D8 unit.
  - It carries the E21-RH.7 anchor (its four links tracked) and the
    retention delete guard, and is registered in `RetentionAnchors::TABLES`.
  - It is classified in `StudentRetentionClassificationTest` as keeping its
    Transport assignment, as the selection keeps its Student.
  - It references no `charges` row, so `retention_expire_finance_unit` is
    unchanged.
- `transport_route_fee_heads` is **Finance configuration** (tenant
  lifetime), like `canteen_billing_configurations`.
- **Clarification:** the E21-RH.7 anchor applies to tables a retention path
  may delete or read for eligibility, and to Finance-evidence link rows.
  Tenant-lifetime configuration is registered in the catalog only, as
  `fee_heads` and `canteen_billing_configurations` are.

### 27.4 Proof
- **Behaviour (`TransportFeeSelectionTest`):**
  - one selection per Student × year × head, with actor and source
    recorded, and no charge;
  - a retry records nothing new;
  - an existing manual selection is reused;
  - an unmapped route records nothing;
  - a mapped route with no enrollment is audited and never fails the
    assignment.
- **Finance lifecycle:**
  - the Finance run charges the full T1 instalment, with no proration;
  - ending the assignment withdraws intent (idempotently) and never cancels
    the T1 charge;
  - T2 is not assessed;
  - withdrawing before a run means that run never assesses Transport.
- **Carry-forward:** links once, a rerun is idempotent, the prior year is
  untouched, ended assignments are skipped, and closed or foreign years are
  refused.
- **Mapping:** only an active head of the same School, enforced by the
  service and by the database foreign key.
- **Provenance in the database:** unique, consistency-checked and
  insert-only.
- **Authorization:**
  - Transport staff configure and carry forward;
  - Finance staff without Transport capabilities can't;
  - Transport staff can't create an assessment run;
  - another School gets 404.
- **Concurrency (`TransportFeeSelectionConcurrencyTest`)**, real processes
  with an observed lock wait:
  - two carry-forwards produce one link and one selection;
  - two seam selections produce one selection, with the loser `reused` and
    no error.
- **Architecture (`OperationalFeeSourceArchitectureGuardTest`):**
  - Fees, Finance and Payments never reference Transport or its tables;
  - only `TransportFeeSelectionService` calls the seam;
  - Transport never touches FEE infrastructure, Finance or Payments;
  - only the Transport fee service writes the two tables.
- **Migration:** rollback drops both tables and the guard function;
  reapplying reproduces the identical schema snapshot. `platform:verify-database`
  passes.

## 28. OPF.2 as built (2026-10-05)

**Hostel records FEE optional-selection intent from the residency lifecycle
through the same trusted seam as Transport. Only Finance assessment runs
make money. FEE never reads Hostel.** §1–§27 are unchanged. The dated
clarifications below record how §15 was realized. Deposits, refunds,
credits, damage charges and proration remain excluded (§3, D2, D5).

### 28.1 The seam (FEE)
- **`FeeSelectionSource`** admits `hostel` (`FeeSelectionSource::hostel($residencyId)`)
  beside `transport`. Nothing else in FEE changed: no second, Hostel-specific
  FEE API, no FEE schema change, the human path untouched.
- **Clarification of §8, callers:** the seam's callers are an explicit
  allow-list pinned by `OperationalFeeSourceArchitectureGuardTest`:
  `TransportFeeSelectionService` and `HostelFeeSelectionService`. Any other
  caller fails the guard, and Admissions and Library are asserted not to
  call the seam or name a selection source until their own slice adds them.

### 28.2 Hostel (`2026_12_03_090000_create_hostel_fee_selection_tables`)
- **Clarification of §15, the tier:** Hostel has no room-category concept,
  and adding one would be a Hostel schema change beyond this slice. The
  accommodation tier is therefore a **Hostel default** with an optional
  **per-room override**. A residency's tier is its bed's room override,
  else its Hostel's default. Beds and rooms cannot move (no update path
  changes `hostel_room_id` or `hostel_id`), so a residency's tier inputs are
  fixed for its lifetime.
- **`hostel_fee_heads`:** the tier → fee head mapping. **No amount column.**
  - `hostel_room_id` NULL is the Hostel default, one per Hostel
    (`hostel_fee_heads_one_per_hostel`, partial unique); a room override is
    one per room (`hostel_fee_heads_one_per_room`).
  - Composite foreign keys keep it in one School: `hostels (id, school_id)`,
    `fee_heads (id, school_id)`, and the room through
    `hostel_rooms (id, hostel_id, school_id)`, so an override is provably a
    room of the mapped Hostel. That key is a new unique constraint on
    `hostel_rooms` (`hostel_rooms_id_hostel_school_unique`); it adds no
    column, and rollback drops it.
  - RLS.
- **`hostel_fee_selections`:** provenance, unique per residency × year
  (`hostel_fee_selections_one_per_year`). It holds the residency, year, fee
  head, the FEE selection, `mapping_scope` (`room` / `hostel`), `link_reason`
  (`residency` / `carry_forward`) and `selection_outcome` (`created` /
  `reused`).
  - A BEFORE INSERT trigger proves the selection is the residency Student's
    selection of that year and head.
  - It is insert-only for the runtime role (`makeAppendOnly`; the
    verifier's no-runtime-DELETE and no-runtime-UPDATE lists), with no actor
    column, as in §27.2.
- **`HostelFeeSelectionService`** is the only writer, mirroring §27.2:
  - **`recordForNewResidency()`** runs inside `HostelResidencyService::assign()`'s
    transaction, after the residency row (with its Student and Bed locked).
  - **`withdrawForEndedResidency()`** runs inside `end()`'s transaction and
    withdraws every still-active selection the residency recorded. It never
    touches a charge.
  - **`carryForward(School, academicYearId, ?User)`** into a named draft or
    active year, each active residency locked in its own transaction;
    linked / already_linked / unmapped / ended / not_applicable counts.
  - **`setHostelFeeHead()` / `setRoomFeeHead()`** set or clear a mapping
    (null clears it) under a lock on the Hostel or room row. A change affects
    only residencies started afterwards and later carry-forwards; recorded
    intent is never rewritten.
- **Clarification of §15, a move:** a move is `end()` + `assign()`. The old
  residency's intent is withdrawn and the new residency's tier is recorded.
  Charges already assessed stay as they are; the next period is billed for
  the new tier in full. Nothing is prorated or credited.
- **"Says so" (§14, as for Transport):** an unmapped tier records and audits
  nothing. A mapped tier whose intent can't be recorded audits
  `hostel.fee_selection.not_applicable` with the reason (no active year, no
  enrollment, no active structure, no optional line, fee head unavailable),
  and the residency still proceeds.
- **API**, Hostel capabilities only:
  - `GET` / `PUT /hostels/{hostel}/fee-head` and
    `GET` / `PUT /hostel-rooms/{room}/fee-head` (`hostel.directory.view` /
    `.manage`);
  - `GET /hostel-residency-assignments/{id}/fee-selections`
    (`hostel.residency.view`);
  - `POST /hostel-fee-selections/carry-forward` (`hostel.residency.manage`,
    `idempotent`).

  These are documented in the OpenAPI contract, with regenerated shared
  types. There is no Inertia page in OPF.2.
- **Audit (Hostel):** `hostel.fee_selection.linked` / `.withdrawn` /
  `.not_applicable` / `.carried_forward`, and `hostel.hostel_fee_head.set` /
  `.cleared` and `hostel.room_fee_head.set` / `.cleared`, with ids only. FEE
  audits `fee_optional_selection.created` / `.withdrawn` with source module
  `hostel`. **No outbox event** (§20).

### 28.3 Retention (as §27.3)
- `hostel_fee_selections` is **Finance ledger**: E21-RH.7 anchor (its four
  links tracked), retention delete guard, `RetentionAnchors::TABLES`, and
  classified in `StudentRetentionClassificationTest` as keeping its residency
  (`dependency_blocked` for the Hostel residency retention unit), as the
  selection keeps its Student. It references no `charges` row.
- `hostel_fee_heads` is **Finance configuration** (tenant lifetime),
  catalogued only.
- The forced-RLS table count is 195.

### 28.4 Proof
- **Behaviour (`HostelFeeSelectionTest`):**
  - a room override wins over the Hostel default; one selection, with actor
    and source (`hostel`) recorded, and no charge; a retry records nothing;
  - the Hostel default applies without an override; an unmapped Hostel
    records nothing; an existing manual selection is reused;
  - not-applicable cases (no enrollment, no optional line, no active year)
    are audited and never fail the residency.
- **Finance lifecycle:** the run charges the full T1 instalment; ending the
  residency withdraws intent (idempotently) and never cancels the charge;
  T2 is not assessed. A move (end + assign into a premium room) withdraws the
  old tier, records the new one, mutates no charge, and T2 bills the new tier
  in full.
- **Carry-forward:** links once with the room scope, a rerun is idempotent,
  the prior year is untouched, ended residencies are skipped, and closed or
  foreign years are refused.
- **Mapping in the database:** no amount; a foreign School's head, a second
  Hostel default, a second room override, or a room of another Hostel are all
  refused.
- **Provenance in the database:** unique, consistency-checked,
  scope-checked, insert-only, and both its residency and selection foreign
  keys RESTRICT a delete.
- **Authorization:**
  - Hostel managers configure and carry forward;
  - Hostel viewers and Finance staff without Hostel capabilities can't;
  - Hostel staff can't create an assessment run or make a human selection;
  - another School gets 404.
- **Concurrency (`HostelFeeSelectionConcurrencyTest`)**, real processes with
  an observed lock wait:
  - a residency link racing a carry-forward into the same year produces one
    link and one selection;
  - two carry-forwards produce one link and one selection;
  - two Hostel-source seam selections produce one selection, the loser
    `reused`, with no error.
- **Architecture:** Fees, Finance and Payments never reference Transport,
  Hostel or their tables; the seam's callers are exactly the allow-list;
  Hostel never touches FEE infrastructure, Finance or Payments; each
  source's fee service is the only writer of its tables. The Transport OPF
  suites pass unchanged.
- **Migration:** rollback drops both tables, the guard function and the
  rooms key; reapplying reproduces the identical schema snapshot.
  `platform:verify-database` passes.

## 29. OPF.3 as built (2026-10-06)

**A successful conversion records the converted Student's one-time
Admission-fee intent through the same trusted seam. Only Finance assessment
runs make money. FEE never reads Admissions. No applicant is ever a
financial subject.** §1–§28 and D1 are unchanged. The dated clarifications
below record how §16 was realized.

### 29.1 D1 reconfirmed
- The fee arises only after conversion: the provenance row requires a
  `converted` application and names the converted Student; nothing names an
  applicant or an unconverted application.
- `charges.student_id` stays the only charge subject; no `applicant_id` is
  added anywhere in Finance. There is no applicant payment, receipt,
  statement or portal billing, and no Admissions ledger.
- A genuine pre-conversion application fee stays deferred to its own ADR.

### 29.2 The seam (FEE)
- **`FeeSelectionSource`** admits `admissions`
  (`FeeSelectionSource::admissions($applicationId)`). No FEE schema change,
  no Admissions-specific FEE API, the human path untouched.
- **Clarification of §8, callers:** the allow-list is exactly three
  services: `TransportFeeSelectionService`, `HostelFeeSelectionService` and
  `AdmissionFeeSelectionService`. Library stays excluded until OPF.4. The
  guard also asserts no source module references `ChargeService`.

### 29.3 Admissions (`2026_12_04_090000_create_admission_fee_selection_tables`)
- **Clarification of §16, the mapping:** Admissions has no pricing
  dimension of its own. FEE's structure already varies by academic year,
  grade and campus, so the mapping is **one fee head per School**
  (`admission_fee_heads`, `admission_fee_heads_one_per_school`). No amount
  column; composite foreign key to `fee_heads (id, school_id)`; RLS;
  `admissions.view` / `.manage`. Writes serialize on a per-School
  transaction advisory lock.
- **`admission_fee_selections`:** provenance, one per application
  (`admission_fee_selections_one_per_application`). It holds the
  application, the converted Student, the academic year, the fee head, the
  FEE selection and `selection_outcome` (`created` / `reused`).
  - A BEFORE INSERT trigger proves the application is `converted` into that
    Student in that year, and the selection is that Student's selection of
    that year and head.
  - Composite foreign keys (all RESTRICT) to the application, Student,
    year, head and selection keep everything in one School.
  - Insert-only for the runtime role; no actor column (the actor is in the
    audit trail and on FEE's selection, as in §27.2).
- **Where in the conversion:** `AdmissionConversionService::convert()`
  calls `AdmissionFeeSelectionService::recordForConversion()` last, inside
  its one transaction, after the application is marked `converted` and the
  conversion is audited. A failed or rolled-back conversion leaves no
  selection, provenance or fee audit.
- **Clarification of §16, the year:** the application's own academic year,
  which is the year of the enrollment the conversion just created (the
  conversion requires the Section to match it). FEE resolves the line from
  that enrollment (`currentForYear()`), then the active structure for its
  grade and campus. It is not the School's active year.
- **Not applicable never fails the conversion; integrity failures do.**
  Not configured (`not_configured`), no active structure, no optional line
  of the head, or the head no longer active are audited as
  `admission_fee_selection.not_applicable`. Any other exception propagates
  and rolls the conversion back.
- **One-time, no lifecycle:** no withdrawal and no carry-forward. Later
  application or Student changes never rewrite the evidence. "One-time" is
  FEE's structure line (one instalment); Admissions holds no `is_one_time`
  flag and no amount.
- **Idempotent:** `recordForConversion()` re-locks the application row; a
  replay returns `already_linked`. An existing active selection (for
  example one Finance made by hand) is reused, never duplicated.
- **API**, Admissions capabilities only: `GET` / `PUT /admission-fee-head`
  (`admissions.view` / `.manage`) and
  `GET /admission-applications/{id}/fee-selection` (`admissions.view`).
  The intent itself is recorded only by the existing conversion endpoint.
  These are documented in the OpenAPI contract, with regenerated shared
  types. There is no Inertia page in OPF.3.
- **Audit (Admissions):** `admission_fee_selection.linked` /
  `.not_applicable` and `admission_fee_head.set` / `.cleared`, ids only.
  FEE audits `fee_optional_selection.created` with source module
  `admissions`. **No outbox event**, and the existing conversion audit is
  unchanged.

### 29.4 Retention (as §27.3)
- `admission_fee_selections` is **Finance ledger**: E21-RH.7 anchor (its
  five links tracked), retention delete guard, `RetentionAnchors::TABLES`,
  the verifier's no-runtime-DELETE and no-runtime-UPDATE lists, and
  classified under both `students` and `admission_applications` in
  `StudentRetentionClassificationTest`.
- `admission_fee_heads` is **Finance configuration** (tenant lifetime),
  catalogued only.
- **The dependency chain, stated:**
  - Provenance references only **converted** applications. A converted
    application is retained with its Student's core record (E21.3B), so the
    one-year terminal (rejected or withdrawn) clock is unaffected.
  - A converted application with provenance is `dependency_blocked` in the
    Student-core purge (`ConvertedApplicationRetentionService::blocker()`
    returns `admission_fee_selections`). The FEE selection already keeps
    the Student (Finance D8), so the effective retention of the Student and
    its converted application is unchanged: both wait for Finance evidence
    to be releasable.
  - No OPF.3 table references `charges`, so `retention_expire_finance_unit`
    is unchanged. No retention period changed.
- The forced-RLS table count is 197.

### 29.5 Production gates (unchanged)
E21 (retention), E30 (receipt / GST) and E32 (whether RTE / free-seat
Students may be charged an Admission fee) still gate production. OPF.3
draws no legal conclusion.

### 29.6 Proof
- **Behaviour (`AdmissionFeeSelectionTest`):**
  - a conversion records one selection (actor and source `admissions`), one
    provenance row and no charge; a replay records nothing;
  - an unconfigured School converts normally and audits `not_configured`;
  - no optional line, an inactive head and no active structure are audited
    and never fail the conversion;
  - a failed enrollment, a caller rollback after conversion, and an
    unaccepted application all leave no fee evidence;
  - Finance's run charges the one-time ADM instalment (2500.00) to the
    converted Student, once, including after a replay and a re-run;
  - an existing Finance selection is reused.
- **Database:** no amount or applicant column; a foreign School's head and
  a second School mapping are refused; provenance is unique,
  trigger-checked (unconverted application, wrong Student, wrong
  selection), insert-only, with RESTRICT foreign keys.
- **Retention dependency:** the converted application is
  `dependency_blocked` by the provenance; references are classified.
- **Authorization:**
  - Admissions managers configure and convert;
  - Admissions viewers and Finance-only staff can't;
  - Admissions staff can't create an assessment run or select by hand;
  - another School sees 404 or nothing.
- **Concurrency (`AdmissionFeeSelectionConcurrencyTest`)**, real processes
  with an observed lock wait:
  - two conversions of one application produce one Student, one provenance
    row and one selection; the loser gets
    `AdmissionApplicationAlreadyConvertedException`;
  - two fee-links of one converted application produce one row, the loser
    `already_linked`;
  - two Admissions-source seam selections produce one selection, the loser
    `reused`.
- **Architecture:** FEE, Finance and Payments never reference Admissions or
  its tables; the seam's callers are exactly the three; the Transport and
  Hostel OPF suites pass unchanged.
- **Migration:** rollback drops both tables and the guard function;
  reapplying reproduces the identical schema snapshot.
  `platform:verify-database` passes.

## 30. OPF.4 as built (2026-10-06)

**An overdue Library return is fined once, at check-in, under the School's
immutable fine policy version, as one Student EVENT charge through a narrow
FEE event-charge seam. Library never reaches `ChargeService`; FEE never
reads Library. Waivers are FEE concessions; an erroneous unpaid fine is
voided at its source; nothing is refunded.** §1–§29 and D3, D4, D6 are
unchanged. The dated clarifications below record how §17 was realized.

### 30.1 The event-charge seam (FEE)
- **`FeeSourceChargeService`** (`App\Domain\Fees\Application\Sources`),
  beside the selection seam, never instead of it:
  - `assessForSource(School, FeeSourceChargeData, FeeChargeSource, ?User)`:
    a positive INR amount (at most two decimals) for one Student and
    academic year, on the ledger accounts of an ACTIVE fee head of the
    School (`SourceChargeFeeHeadUnavailableException` otherwise), posted
    through `ChargeService::assess()`;
  - `cancelForSource(School, chargeId, FeeChargeSource, ?User, reason)`:
    `ChargeService::cancel()`, so a charge with payment allocations or live
    adjustments still refuses;
  - `chargeableFeeHead()`: an active head's summary, for a source's mapping
    validation.
- **`FeeChargeSource`** is a closed catalogue (`library`, the source id is
  the fine's id), separate from `FeeSelectionSource`. FEE audits
  `charge.source_assessed` / `charge.source_cancelled` with the source kind,
  source id and actor, beside `charge.assessed` / `charge.cancelled`.
- **No idempotency of its own,** like `ChargeService::assess()`: exact-once
  comes from the source's unique evidence row under the source's row lock,
  in the same transaction. A direct two-process race on the seam itself is
  therefore not a meaningful test; the races are on the Library lifecycle.
- Authorization-neutral, no route (D9).
- **`ChargeService::cancel()`** maps the new database guard to
  `ChargeIsSourceChargeException` (409, `CHARGE_IS_SOURCE_CHARGE`).

### 30.2 Architecture guard (clarification of §8)
`OperationalFeeSourceArchitectureGuardTest` now pins both seams:
- selection seam callers: exactly the Transport, Hostel and Admissions
  fee-selection services;
- event-charge seam callers: exactly `LibraryFineService` (assess and
  cancel; the only file naming a `FeeChargeSource`) and
  `LibraryFinePolicyService` (head validation only);
- Library never names the selection seam; selection sources never name the
  event-charge seam; Canteen, Inventory and Visitor join neither;
- no source module references `ChargeService`, `AssessChargeData` or
  `ChargeAdministrationService` (word-matched, so the seam's own name does
  not count); FEE, Finance and Payments reference no source module or table.

### 30.3 Library (`2026_12_05_090000_create_library_fine_tables`)
- **`library_fine_policies` (D3):** immutable numbered versions per School
  (`library_fine_policies_one_version`; insert-only for the runtime role).
  The highest version governs fines assessed from then on. An `active`
  version names the fee head (ledger destination), `daily_rate > 0`,
  `grace_days >= 0` and an optional `max_amount > 0`; a `disabled` version
  switches fines off and carries none. INR. Published by
  `LibraryFinePolicyService` under a per-School advisory lock.
- **`library_fines` (Finance evidence):** one per loan and kind
  (`library_fines_one_per_loan_kind`; v1 kind `overdue`), one per charge.
  It snapshots `due_at`, `checked_in_at`, overdue and chargeable days and
  the amount, and names the policy version, fee head, academic year, the
  charge and the assessing user. Composite foreign keys (all RESTRICT) keep
  everything in one School. The BEFORE INSERT trigger **re-derives** the
  days and amount from the policy version and the returned loan, and proves
  the charge is this Student's, year's, amount's and fee head's accounts'.
  Insert-only.
- **`library_fine_voids` (D4):** one per fine, insert-only; the fine row
  never changes. A deferred constraint trigger requires the fine's charge
  to be cancelled in the same transaction, and
  `charges_library_fine_guard_trigger` refuses cancelling a fine's charge
  any other way (as `charges_fee_assessment_guard_trigger` does for
  fee-assessed charges).
- **The formula (`LibraryFineService::calculate()`):**
  - overdue days = each started 24-hour period after `due_at`, i.e.
    ceil(exact elapsed time / 24 h), and 0 when returned at or before
    `due_at` (UTC instants, as `due_at` is stored);
  - chargeable days = max(overdue days − grace days, 0);
  - amount = chargeable days × daily rate, capped at `max_amount`, with
    exact decimals (bcmath, `NUMERIC`).
- **Clarification of §17, the trigger:** assessed **only at check-in**,
  inside `LibraryLoanService::checkIn()`'s transaction after the
  conditional return transition (whose row lock it holds), from the final
  overdue duration. There is **no explicit assessment path:** the
  repository has no legacy or migrated returned loans that owed a fine
  (no policy existed before OPF.4), so a manual path would only add
  surface.
- **Not applicable never fails a return:** an overdue return with no
  policy or a disabled one (`no_policy`), within grace (`within_grace`), no
  active academic year (`no_active_academic_year`), or a policy fee head no
  longer active (`fee_head_unavailable`) is audited as
  `library.fine.not_applicable`. Any other failure rolls the check-in back.
  An on-time return says nothing.
- **Clarification of §17, the year:** the School's active academic year at
  assessment (`CurrentAcademicYearResolver`), as Canteen charges and
  OPF.1/OPF.2's new intent use; loans carry no year.
- **Borrowers** stay Student-only, so the charge subject is always the
  loan's Student.
- **Void (D4):** `LibraryFineService::void()` locks the loan row, records
  the `LibraryFineVoid`, then `cancelForSource()`. A paid charge
  (allocations) or a waived one (live concession adjustment) is refused as
  `LIBRARY_FINE_NOT_VOIDABLE` and nothing is recorded; a second void is
  `LIBRARY_FINE_ALREADY_VOIDED`. Never a refund, never a deletion.
- **Waiver (D4):** a FEE targeted concession, category `waiver`, under the
  existing maker/checker; Library does nothing for it.
- **Lost and damaged** items, replacement cost, copy valuation and
  copy-on-loan deactivation remain deferred.

### 30.4 Authorization (clarification of §18)
| Action | Capability |
|---|---|
| Check in (and so assess an overdue fine) | `library.circulation.manage` (existing) |
| Read fines and the policy | `library.fines.view` (new) |
| Publish a policy version | `library.fines.manage` (new) |
| Void an erroneous unpaid fine (and so cancel its charge) | `library.fines.void` (new) |
| Waive (concession) | `finance.fee_concessions.request` / `.approve` (unchanged, maker ≠ checker) |

The void is a Library action whose cancellation FEE performs through the
trusted seam, restricted by the database to a fine being voided; the
generic `finance.charges.manage` cancel cannot cancel a fine's charge, and
Library staff gain no Finance capability. `library.fines.*` are seeded to
School Admin only (financial configuration, as `canteen.settings.*`).

### 30.5 API
`GET /library-fine-policy`, `GET` / `POST /library-fine-policy/versions`
(`idempotent`), `GET /library-loans/{loan}/fine`, and
`POST /library-fines/{fine}/void` (`idempotent`). There is no assess
endpoint; the check-in route is unchanged (its conditional transition makes
a retry safe). OpenAPI and shared types are regenerated.

### 30.6 Audit and events
`library.fine.assessed` / `.not_applicable` / `.voided` and
`library.fine_policy.published` (ids, version, days and amount only), plus
FEE's `charge.source_assessed` / `.source_cancelled`. **No outbox event:**
`ChargeAssessed` / `ChargeCancelled` stay canonical.

### 30.7 Retention (clarification of §21)
- `library_fines` and `library_fine_voids` are **Finance ledger**: E21-RH.7
  anchors (the fine's six links; the void's fine), delete guards,
  `RetentionAnchors::TABLES`, the verifier's no-runtime-DELETE and
  no-runtime-UPDATE lists, and classified under `students` and
  `library_loans`. `library_fine_policies` is **Finance configuration**
  (tenant lifetime; also on the verifier lists, being insert-only).
- **Finance expiry, forward-amended**
  (`2026_12_05_090100_refuse_library_fine_charges_in_finance_expiry`, a
  one-statement in-place amendment that keeps the RH.7 prologue, owner and
  grants): `retention_expire_finance_unit` refuses a unit whose charge a
  Library fine references, as `retention_finance_dependency`, exactly as it
  refuses `canteen_orders`. No unit deletes fine evidence in OPF.4 (none
  deletes a Canteen order either), so a fined charge stays with its fine.
- **Loan dependency:** a returned loan with a fine is `dependency_blocked`
  for the Library loan purge (7 years after the Student's exit); the fine
  also keeps its Student, as its charge already does. No retention period
  changed and no foreign key was weakened.
- **Users:** the three actor columns (`library_fine_policies.created_by_user_id`,
  `library_fines.assessed_by_user_id`, `library_fine_voids.voided_by_user_id`)
  are classified `RETAIN_REFERENCE` in `UserReferenceCatalog` (Finance
  operator references keep pointing at a minimized User's tombstone);
  minimization stays fail-closed for any unclassified reference.
- The forced-RLS table count is 200.

### 30.8 Legal (unchanged)
**E34 stays LEGAL_REVIEW_REQUIRED.** The code exists for development;
production use of Library fines waits for a qualified answer. E31 does not
cover Library fines; E30 (receipt / GST) applies to fine charges as to any
charge; E32's wording concerns Transport, Hostel and Admission fees, and
OPF.4 draws no conclusion about RTE Students and fines.

### 30.9 Proof
- **Formula:** not overdue, early, one second late (inside grace), the last
  grace day, the first chargeable day, several days, exactly the cap,
  beyond the cap, no grace and no cap with exact decimals.
- **Policy:** numbered immutable versions (runtime UPDATE and DELETE
  denied), disabled versions, and refusal of a foreign or inactive head and
  of a bad rate, grace or cap, in the service and the database.
- **Check-in:** on time, no fine; overdue, one fine and one Student charge
  on the head's accounts with the source audited; replays return the same
  fine; a second check-in is refused; the copy circulates again.
- **Not applicable:** all five reasons audited, every return completed.
- **Versioning:** a new version never rewrites an assessed fine.
- **Waiver:** maker ≠ checker enforced; the adjustment never rewrites the
  charge; a waived fine cannot be voided.
- **Void:** Finance's generic cancel is refused on a fine's charge; the
  source void cancels it with the evidence kept; a second void is refused;
  a paid fine is refused with nothing recorded and no refund.
- **Database:** derived amount, loan match, uniqueness, insert-only, and a
  void that does not cancel its charge cannot commit.
- **Authorization:** circulation staff can't publish, read or void;
  Finance-only staff can't touch Library fines; fines staff can't cancel
  charges, create runs or approve concessions; another School sees nothing.
- **Concurrency (`LibraryFineConcurrencyTest`)**, real processes with an
  observed lock wait:
  - two check-ins: one return, one fine, one charge; the loser gets
    `LoanAlreadyReturnedException`;
  - two voids: one void, one reversal; the loser gets
    `LibraryFineAlreadyVoidedException`.
- **Retention:** the Finance unit refuses the fined charge with
  `retention_finance_dependency`; the fine keeps its loan and Student.
- **Migration:** both migrations apply, roll back and reapply to the
  identical snapshot (rollback restores the Finance function without the
  new clause); `platform:verify-database` passes.

## 31. OPF.5 closure (2026-10-06)

**OPF.0–OPF.5 — PUBLISHED / CLOSED for development.** A closure audit of the
repository at `616fb02` checked §1–§30 against the code, migrations, schema
and tests. It found no product, schema or authorization defect, and no
deferred scope partially built. It found test-coverage and documentation
gaps, corrected here without new behaviour (§31.3). §1–§30 and D1–D9 are
unchanged.

### 31.1 As built, in one table
| Integration | Applicability | Tier / head mapping | Amount | Money made by | Correction | Provenance |
|---|---|---|---|---|---|---|
| Transport | assignment start / end (Transport) | route → fee head (Transport) | FEE instalment | Finance assessment run | Finance void / cancel, concession | `transport_fee_selections` (Transport) |
| Hostel | residency start / end (Hostel) | Hostel default + room override → fee head (Hostel) | FEE instalment | Finance assessment run | as Transport | `hostel_fee_selections` (Hostel) |
| Admission fee | successful conversion (Admissions) | one fee head per School (Admissions) | FEE instalment | Finance assessment run | as Transport | `admission_fee_selections` (Admissions) |
| Library fine | overdue check-in (Library) | the policy version's fee head (Library) | Library-owned immutable policy version | the event-charge seam, at check-in | FEE `waiver` concession; Library void (unpaid only) | `library_fines` / `library_fine_voids` (Library) |

- **Seams:** `FeeSourceSelectionService` (callers exactly the Transport,
  Hostel and Admission fee-selection services) and `FeeSourceChargeService`
  (callers exactly `LibraryFineService` and `LibraryFinePolicyService`), both
  pinned by `OperationalFeeSourceArchitectureGuardTest`. No source module
  references `ChargeService`; Fees, Finance and Payments reference no source
  module or table. The only database exceptions are the two §4 / §30.3
  ones: `charges_library_fine_guard_trigger` and the Finance expiry clause.
- **Student-only charging, no parallel ledger, no new outbox event:** OPF
  added no `applicant_id` or other subject, no ledger, no queued job or
  command, and no outbox or webhook event. `charge.assessed.v1` /
  `charge.cancelled.v1` stay canonical.
- **Exactly once is database-enforced** in every path: the source's unique
  key (one per assignment × year, residency × year, application, loan ×
  kind, fine void) under the source's row lock, plus FEE's
  `fee_optional_selections_one_active` with savepoint reuse. Every race was
  proven with real processes and an observed lock wait (§27.4–§30.9).

### 31.2 Clarification of §4: the Admissions layer exception
`DOMAIN-MAP.md` lets a module depend only on its own or a lower layer.
Admissions is Layer 2 and Fees Layer 3, so OPF.3's Admissions → Fees call
(D1, §8, §16) is the one sanctioned exception. It is confined to the single
trusted seam call inside the conversion transaction. Fees never reads
Admissions (guard-tested), so there is no cycle. No other Admissions →
Layer 3 dependency is sanctioned. Transport, Hostel and Library are Layer 3,
like Fees. The domain map records the exception and each source's Fees and
Academic Structure dependency.

### 31.3 Corrections made in OPF.5 (no new behaviour)
- **Rule 28 raw-SQL isolation** for all nine OPF tables
  (`Tests\Concerns\AssertsTenantRlsIsolation`, one test per source).
  Forced RLS hides School A's rows from School B and from a context-less
  session. School B can neither rewrite them nor insert one for School A. The
  Eloquent layer without context sees nothing.
- **Rule 13 allow / deny:** the denied read for the Transport route mapping,
  Transport assignment intent, Hostel room override and Library policy
  versions; and the cross-School 404 for the Transport route mapping
  (read / write), the Hostel and room mappings (write; room read) and the
  Library fine void (with no void recorded and the charge untouched).
- **§21 purge interaction, tested for Transport and Hostel** (Admissions and
  Library already were): an ended assignment or residency with provenance is
  `dependency_blocked`; without provenance it is not.
- **Library charge-shape clause** of `library_fines_guard` tested directly
  (another charge, a cancelled charge).
- **Rule 18:** the four OPF create migrations' `down()` now call
  `TenantRls::disable()` before dropping each table. Rollback was re-proven;
  `up()` is unchanged.
- **Wording:** the `finance_ledger` catalog decision names Library-fine-linked
  detail; stale module-doc sentences were corrected (no explicit Library
  assessment path; Transport and Hostel rows now referenced by Finance
  evidence; the Transport year; the Hostel tier).

### 31.4 Authorization (D9, final)
Operational capabilities create intent or fines; only Finance capabilities
make or correct money through Finance paths:
- Transport: `transport.routes.view` / `.manage` (mapping),
  `transport.assignments.view` / `.manage` (intent, carry-forward).
- Hostel: `hostel.directory.view` / `.manage` (mapping),
  `hostel.residency.view` / `.manage` (intent, carry-forward).
- Admissions: `admissions.view` / `.manage` (mapping, conversion).
- Library: `library.circulation.manage` (check-in, so assessment),
  `library.fines.view` / `.manage` / `.void` (School Admin only; no
  `library.fines.assess` exists).
- Finance, unchanged: `finance.fee_assessments.run`, `finance.charges.manage`
  (refused on a fine's charge by the database), `finance.fee_concessions.*`
  (maker ≠ checker).

OPF granted no operational role a `finance.*` capability and no Finance role
a source capability. The only seeder change was the three `library.fines.*`
capabilities for School Admin. Principal's existing Transport, Hostel,
Admissions and circulation capabilities now also record fee intent or
trigger a fine at check-in, by design (§18); Principal still holds no
`finance.*`.

### 31.5 Database and RLS
Nine tenant tables, all with forced RLS (pinned count 200), composite
same-School foreign keys (RESTRICT) and `school_id` CASCADE:
- **Configuration (tenant lifetime, catalog only):** `transport_route_fee_heads`,
  `hostel_fee_heads`, `admission_fee_heads` (runtime may update or clear a
  mapping) and `library_fine_policies` (insert-only versions).
- **Finance ledger evidence (anchored, delete-guarded, insert-only, on the
  verifier's no-runtime-UPDATE / DELETE lists):** `transport_fee_selections`,
  `hostel_fee_selections`, `admission_fee_selections`, `library_fines`,
  `library_fine_voids`.
- **Actor references:** three, all `RETAIN_REFERENCE` in
  `UserReferenceCatalog` (§30.7).

### 31.6 Retention, as built
- **Dependency graph:** each provenance row keeps its operational row
  (assignment, residency, converted application, loan) and its FEE selection
  or charge; FEE selections and fined charges keep the Student. The
  operational purges answer `dependency_blocked` through `ReferencingRows`
  (or `ConvertedApplicationRetentionService::blocker()`).
- **Finance expiry:** `retention_expire_finance_unit` refuses a unit whose
  charge a Library fine references (`retention_finance_dependency`), as for
  Canteen. No Transport, Hostel or Admissions table references `charges`.
- **Observation for E21 (not a change):** the D8 unit deletes neither FEE
  optional selections nor a fined charge, so OPF evidence, and the
  operational rows it keeps, stay as long as that Finance evidence. The
  Student was already kept by its selection or charge. Qualified E21
  ratification should note this.

### 31.7 Owner notes (accepted behaviour, no change)
- **Withdrawal of a reused selection.** When an assignment or residency
  reused an existing active selection (for example one Finance made by
  hand), ending it withdraws that selection: §27.2 and §28.2 withdraw
  "every still-active selection the source recorded". A Finance user may
  select again by hand.
- **Not tested as races, safe by locks:** void vs. payment allocation, void
  vs. concession approval, and end vs. carry-forward. Each takes the
  charge, or the assignment or residency, row lock and re-checks state.
- **Guard scope.** The guard pins both seams' callers across all of `app/`
  exactly. Its negative scans of the source modules cover `app/Domain/<module>`
  and name the FEE charge classes; tightening it to a positive allow-list
  of Fees imports is optional future hardening.

### 31.8 Production gates (unchanged)
E21 (retention ratification and configuration), E30 (receipt / GST), E31
(fee regulation; not Library fines), E32 (RTE / free seats) and E34 (Library
fines, LEGAL_REVIEW_REQUIRED) stay open. OPF is development-closed, not
production-ready, and draws no legal conclusion.

### 31.9 Deferred (unchanged, none partially built)
Hostel deposits; refunds, credits and unapplied cash; pre-conversion
application fees and any applicant charge subject; Library lost or damaged
charging, replacement cost and daily accumulation; proration; a FEE price
variant; gateway payments; GST / tax; Guardian- or Student-facing fee views.
Each needs its own contract and ADR.

### 31.10 Proof
The OPF suites, the architecture guard, retention, authorization, RLS and
database suites, the static and release gates, `platform:verify-database`,
a rollback and reapply of the five OPF migrations to an identical schema
snapshot, and the full canonical isolated regression on the closure tree.
The counts are recorded in the OPF.5 commit.
