# Phase 10 / Phase 0K — School Operations Closure & Deferred-Scope Record

This document is a process/closure record, not a module design. It
exists to state, in one authoritative place, exactly what Phase 0K
delivered, what remains, and why — so that no future reader has to
reconstruct the answer from scattered roadmap prose.

## Phase

Phase 0K / Phase 10 — Operational Modules ("School Operations"), as
originally scoped in `docs/roadmap/MASTER-ROADMAP.md`'s Phase 0K
entry: *"Transport, Library, Inventory, Canteen, Hostel, Health,
Visitor, Safety."* Eight modules, named together, not strictly
ordered by dependency.

## Closure state

**Closed with explicitly deferred legally/security-blocked scope.**

This is a distinct status from "complete." It means: every module in
Phase 0K's original scope that was NOT blocked by an unresolved
legal/security prerequisite has been implemented and closed on its
own terms, and there is currently no further Phase 0K engineering
work authorized to start. It does **not** mean all eight originally
scoped modules exist, and it must never be read, quoted, or
summarized as "Phase 0K complete," "Phase 10 complete," or "all
Phase 0K modules delivered."

## Integrated modules (six, each individually complete)

| Module | Status | High-level implemented responsibility | Module doc |
|---|---|---|---|
| Library | Complete (Phase 10A) | Bibliographic Title/Copy catalogue + physical circulation (checkout/check-in), database-enforced single-active-loan-per-Copy invariant. | `docs/modules/LIBRARY.md` |
| Transport | Complete (Phase 10B) | Routes + ordered Stops, Vehicles, Route↔Vehicle↔Driver assignment, Student transport assignment with one-active-per-Student invariant. | `docs/modules/TRANSPORT.md` |
| Visitor | Complete (Phase 10C) | Visitor directory, check-in/check-out Visit lifecycle, one-active-Visit-per-Visitor invariant. | `docs/modules/VISITOR.md` |
| Hostel | Complete (Phase 10D) | Hostel/Room/Bed structure, Student residency assignment, one-active-per-Bed/one-active-per-Student invariants. | `docs/modules/HOSTEL.md` |
| Inventory | Foundation complete (Phase 10E) | Item/Location catalogue, quantity stock lifecycle (receive/issue/transfer), single-writer stock service. | `docs/modules/INVENTORY.md` |
| Canteen | Foundation complete (Phase 10F) | Outlet/catalogue/recipe, Student order lifecycle (place/fulfill/cancel), charge-at-fulfillment through Fees, additive Inventory `issueMany()`. | `docs/modules/CANTEEN.md` |

Each module's own doc is the authoritative record of its scope,
deferred sub-features, tests, and closure evidence — this document
does not duplicate that content, only points to it.

## Deferred modules (two, wholly unimplemented)

### Health

- **Original scope module:** YES — named in Phase 0K's original
  eight-module list.
- **Implemented:** NO. No `App\Domain\Health` directory, migration,
  capability, route, or module doc exists anywhere in this
  repository.
- **Current state:** DEFERRED / BLOCKED.
- **Primary blocker:** the unresolved `[LEGAL REVIEW REQUIRED]` gate
  on Health data recorded in `docs/security/DATA-CLASSIFICATION.md`
  (*"Medical conditions, allergies, incident records... may
  separately qualify as a sensitive personal data category under
  applicable law; a dedicated review is required before the Health
  module is implemented"*). No authoritative legal approval or
  resolution of this gate exists anywhere in this repository.
- **Secondary prerequisite:** the separate, also-unresolved general
  retention-period policy decision (`docs/security/DATA-CLASSIFICATION.md`,
  "Engineering controls this classification drives" § "Retention")
  materially affects Health specifically, since Health data is the
  paradigm case for needing an explicit retention rule rather than
  defaulting to indefinite retention.
- **No meaningful safe subset:** every Health data concept actually
  named in repository documentation (medical conditions, allergies,
  incident records, health records/incident logs) falls inside the
  gated category. No adjacent non-medical Health structural concept
  is documented anywhere in this repository to build as a legally
  clear foundation first — inventing one now would be speculative
  scaffolding, not a meaningful checkpoint.
- **Reopening trigger:** authoritative legal/security resolution
  (see "Reopening criteria" below).

### Safety

- **Original scope module:** YES — named in Phase 0K's original
  eight-module list.
- **Implemented:** NO. No `App\Domain\Safety` directory, migration,
  capability, route, or module doc exists anywhere in this
  repository.
- **Current state:** DEFERRED / BLOCKED.
- **Primary blocker:** no dedicated data-classification or
  legal/security decision exists for Safety at all —
  `docs/security/DATA-CLASSIFICATION.md` has no Safety row, by
  deliberate convention (see the Visitor-data row's own text: *"a
  Safety/incident-management module, if built, is a separate future
  classification decision, not covered by this row"*). This document
  does not invent one; see "DATA-CLASSIFICATION reconciliation"
  below for why.
- **Documented overlap:** `docs/architecture/DOMAIN-MAP.md`'s Safety
  row and `docs/modules/VISITOR.md` §18/§24 both record that
  "incident records" may fall under Health's own unresolved gate —
  Safety's own review is explicitly recommended to happen alongside
  Health's, not deferred indefinitely behind it as a separate,
  unrelated blocker.
- **No meaningful independent safe subset:** no repository evidence
  defines a Safety sub-scope (e.g. an evacuation-drill or
  facility-safety-event concept) that is documented as independent of
  the incident-record/Health overlap. The repository has explicitly
  deferred making that split, not made it.
- **Reopening trigger:** an authoritative Safety classification
  decision (see "Reopening criteria" below).

## Engineering readiness statement

Current platform primitives appear technically sufficient for future
Health and Safety work, including: tenancy/RLS (`App\Support\Tenancy`),
capability-based authorization (`App\Support\Authorization\AuthorizesCapability`),
audit (`App\Support\Audit\AuditRecorder`), the Students, Employees
(HR), Campuses (Academic Structure), Documents, Communications, and
Visitor domains, idempotency (`App\Support\Idempotency`), and the
domain-event outbox (ADR 0025) — all exist, are proven across six
independent modules, and would be directly reusable dependencies for
a future Health or Safety checkpoint.

**Technical readiness does not override legal/security authorization.**
The blockers recorded above are not engineering gaps — they are
unresolved legal/product decisions this document has no authority to
make. This statement records that the platform would not need new
foundational capability if Health/Safety were legally cleared; it
does not imply that clearance exists or that implementation may
begin.

## Explicit non-closure of deferred scope

This closure does not constitute implementation, acceptance, legal
approval, cancellation, or removal from scope of Health or Safety.
Both remain original Phase 0K scope, awaiting their recorded
prerequisites, not optional or retired features.

## Reopening criteria

**Health** may reopen only when repository evidence (an ADR, an
updated `docs/security/DATA-CLASSIFICATION.md` entry, or an
equivalent authoritative record) contains:

1. A resolved legal review of Health data as a sensitive-personal-data
   category, replacing the current `[LEGAL REVIEW REQUIRED]` flag with
   an actual recorded determination.
2. An approved data-classification/handling decision for the
   documented Health data concepts (medical conditions, allergies,
   incident records).
3. A retention-period decision applicable to Health data, where the
   general retention-policy gate requires one.

**Safety** may reopen only when repository evidence contains:

1. A dedicated Safety data-classification/legal-security decision —
   the currently-absent row or equivalent authoritative record.
2. A resolved determination of whether/how Safety incident records
   overlap Health's data category.
3. An approved handling/retention decision for whatever Safety data
   categories that classification decision defines.

In both cases: once reopening criteria are met, the next step is a
**fresh readiness/architecture gate** for that module — mirroring the
process every implemented Phase 0K module went through — never a
direct jump to implementation.

## DATA-CLASSIFICATION reconciliation

This closure does not add a new Safety row to
`docs/security/DATA-CLASSIFICATION.md`, and does not modify the
existing Health row. The repository's own established convention
(the Visitor-data row's text, unchanged since Phase 10C) already
treats an unresolved category as intentionally absent from that
table rather than represented as a speculative placeholder row with
no tier — adding one now would imply a level of formal review that
has not happened. Safety's blocker is instead recorded here and in
`docs/architecture/DOMAIN-MAP.md`, which this document judges the
less speculative choice.

## Related documents

- `docs/roadmap/MASTER-ROADMAP.md` — Phase 0K entry, closure status
  statement.
- `docs/architecture/DOMAIN-MAP.md` — Health and Safety rows.
- `docs/security/DATA-CLASSIFICATION.md` — the authoritative Health
  `[LEGAL REVIEW REQUIRED]` gate and the general retention-policy gate.
- `docs/modules/VISITOR.md` §18, §24 — the original source of the
  Safety/incident-record overlap note.
- `docs/modules/LIBRARY.md`, `TRANSPORT.md`, `VISITOR.md`, `HOSTEL.md`,
  `INVENTORY.md`, `CANTEEN.md` — the six implemented modules' own
  closure records.
