# ADR 0017: Audit Architecture

- Status: Accepted
- Date: 2026-08-22

## Context

A school ERP handles financial transactions, student records, and
compliance-relevant actions, and now also AI-initiated actions (ADR
0014). "Who did what, to which record, when, and under what
authorization" must be reconstructible after the fact — both for
operational debugging and for the kind of accountability a school,
parent, or regulator may reasonably expect, without this checkpoint
making any specific legal-compliance claim (see
`docs/security/DATA-CLASSIFICATION.md`'s legal-review flag).

## Decision

School OS treats audit logging as an **architectural requirement of
every significant state change**, not an optional add-on module bolted
on later:

- **Human-initiated changes:** every significant create/update/delete
  on a business record (defined per-module as it's built — financial
  transactions, student status changes, document access, permission
  grants are always "significant") is recorded with: actor, tenant,
  timestamp, entity reference, and a before/after or delta
  representation, via Laravel's Application-layer services (never
  inferred after the fact from database triggers alone).
- **AI-initiated changes and AI activity:** every model call and tool
  invocation is recorded in the AI Gateway's audit ledger
  (`services/ai/app/audit/ledger.py`, ADR 0013, 0014), tied to tenant,
  agent, and capability exercised — this is a *separate* ledger from
  the ERP's human-actor audit trail, but both must be correlatable by
  tenant and, where an AI tool call results in an ERP write, by the
  same entity reference the ERP-side audit trail uses.
- **Audit records are append-only** from the application's perspective
  — no Application-layer service exposes a way to edit or delete a past
  audit entry; corrections happen via new, explicit adjustment records
  (this mirrors the financial-correctness principle in
  `docs/architecture/ARCHITECTURE.md`: reversals, not silent edits).
- **Authorization decisions that deny access** to sensitive data
  (`docs/security/DATA-CLASSIFICATION.md`'s "Sensitive"/"Highly
  Sensitive" tiers) are themselves worth auditing in later phases, not
  just successful actions — noted here as a requirement to carry
  forward, not yet implemented.

No audit *storage implementation* (a dedicated audit table set, a
separate audit datastore) is built in Phase 0A — this ADR fixes the
requirement and shape; implementation lands with the first module that
needs it.

## Rationale

- Retrofitting audit logging after modules already exist is how audit
  trails end up with gaps (the module nobody remembered to instrument).
  Declaring it a mandatory checklist item now, in root `CLAUDE.md` and
  this ADR, keeps it in front of every future module's implementation.
- Separating the AI audit ledger from the human-actor audit trail
  reflects that they answer related but distinct questions ("what did
  this AI agent do" vs. "what changed on this record"), while still
  requiring correlation so a single investigation can follow an
  AI-initiated action through to its resulting ERP-side audit entry.
- Append-only audit records are what makes an audit trail trustworthy —
  an editable audit log is not meaningfully different from no audit log
  for accountability purposes.

## Alternatives considered

1. **Rely solely on Postgres's own write-ahead log / generic database
   auditing extensions instead of application-level audit records.**
   Rejected as the primary mechanism: database-level logs capture *that*
   a row changed, not *why* or under what business authorization —
   application-level audit records carry the business context a raw
   row diff cannot.
2. **A single shared audit trail for both human and AI actions.**
   Rejected as the initial design: the two have different shapes (an AI
   audit entry needs capability/agent/provider context a human audit
   entry doesn't) — better modeled as two correlated ledgers than one
   overloaded schema, revisit only if a concrete need for a truly
   unified view emerges.
3. **Defer audit design entirely to whichever module needs it first.**
   Rejected: audit is exactly the kind of cross-cutting concern that
   produces inconsistent, incomplete coverage if each module designs it
   independently and late.

## Consequences

- Every future module's Application-layer services must be written
  with audit recording as part of the state-change path, not a
  follow-up task.
- The AI audit ledger's current implementation
  (`services/ai/app/audit/ledger.py`) is in-process and non-durable —
  acceptable for Phase 0A's proof of the mechanism, explicitly not
  sufficient for production (see Future extraction below).
- Audit data itself is sensitive (it reveals who accessed what) and
  must be classified and access-controlled per
  `docs/security/DATA-CLASSIFICATION.md`, not treated as low-sensitivity
  operational log data.

## Future extraction/evolution path

Before production use: the AI audit ledger must move from the current
in-process stub to durable storage (its own store, or persisted via a
Laravel audit contract endpoint so it benefits from the same
tenant/RLS guarantees as ERP data, ADR 0004). The human-actor audit
trail's concrete schema is designed alongside the first module that
needs it (SIS or Finance are the most likely first movers), following
this ADR's append-only, actor/tenant/entity-reference shape.
