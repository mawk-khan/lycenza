# ADR 0002: Laravel as the Sole Authoritative System of Record

- Status: Accepted
- Date: 2026-08-22

## Context

The product spans a Laravel ERP core, a Python/FastAPI AI service, a
Vue/Inertia web client, and a Flutter mobile client. Financial,
student, and compliance state must have exactly one owner, or the
system will develop silent divergence bugs (two components disagreeing
about whether a fee was paid, whether a student was withdrawn, etc.),
which is unacceptable for school financial and academic records.

## Decision

**Laravel (PHP), specifically `apps/platform`, is the single
authoritative system of record** for all business rules, transactions,
authorization decisions, tenancy, domain workflows, financial state,
student state, compliance state, and auditability.

No other component — not the Vue/Inertia frontend, not the Flutter
app, and critically **not the Python/FastAPI AI service** — may write
directly to the tables Laravel owns, or make an authorization decision
that Laravel does not ultimately enforce server-side.

## Rationale

- A single writer per record eliminates an entire class of consistency
  bugs and audit gaps by construction.
- Laravel's Eloquent + migrations + policy/gate authorization + queued
  jobs give a mature, well-understood platform for exactly this kind of
  transactional business system, with a large hiring pool for the
  Indian market this product targets.
- Keeping "intelligence" (AI/ML) outside the authoritative boundary
  means an LLM hallucination, a bad model output, or a compromised AI
  service can, at worst, produce a *proposal* that a human or a
  Laravel-enforced policy must approve — never a fait accompli change
  to a student's record or a school's ledger. See ADR 0014.
- This also gives us one place to reason about auditability and
  compliance (see `docs/security/AUTHORIZATION.md`,
  `docs/architecture/adr/0017-audit-architecture.md`) instead of
  reconciling audit trails across services.

## Alternatives considered

1. **Let the AI service write directly to some tables it "owns"** (e.g.
   an AI-maintained `predicted_risk_score` column updated directly from
   Python). Rejected: this creates two writers to the same database and
   erodes the single-source-of-truth guarantee the moment it's allowed
   anywhere, even for "harmless" derived data. AI-derived data is
   instead written back through an explicit Laravel-owned Application
   service/tool contract (ADR 0013, 0014), which enforces validation and
   audit uniformly regardless of whether the writer was a human or an
   agent.
2. **CQRS with a separate read model service.** Rejected as premature —
   no scale requirement justifies it yet, and it multiplies the moving
   parts a small team must operate correctly.
3. **Event-sourced core instead of a conventional relational model.**
   Rejected for Phase 0A: much higher complexity for the team's current
   size and the product's current stage; domain events (ADR 0010) are
   used for integration/notification/automation, not as the system's
   primary persistence model.

## Consequences

- Every future module's Application layer is the only legitimate
  write path to its tables; Infrastructure/Eloquent models are not
  public API for other modules (see `docs/architecture/DOMAIN-MAP.md`).
- The AI Gateway (Python) must call back into Laravel through
  authenticated, explicitly exposed "AI tool" HTTP contracts to effect
  any change — never a direct DB connection, never a shared credential
  with write access to the ERP schema.
- This adds one network hop and one authorization check for AI-driven
  actions versus a hypothetical direct-write shortcut — an intentional,
  accepted cost.

## Future extraction/evolution path

If a future bounded context is extracted into its own service (see ADR
0001's extraction path), that new service becomes authoritative for
*its own* tables only, and continues to expose the same
Application-layer contract discipline to the rest of the system. The
"single writer per record, no direct AI writes" rule outlives any given
deployment topology and should be carried into every future ADR that
touches persistence.
