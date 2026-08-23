# ADR 0001: Modular Monolith over Microservices for the ERP Core

- Status: Accepted
- Date: 2026-08-22

## Context

School OS is planned to eventually cover SIS, Admissions, Finance/Fees,
Academics, Attendance, Examinations, LMS, HR/Payroll, Transport, Library,
Inventory, Compliance, and more, serving individual schools and
multi-school trusts in India. The team starting this build is small.
We must decide, before any business module is written, whether the ERP
core is one deployable Laravel application internally divided into
modules ("modular monolith"), or a set of independently deployed
services (microservices) from day one.

Many of these future modules share the same transaction boundary: an
admission converts into a student record and (eventually) a fee
schedule inside one business transaction; attendance affects fee
concessions; exam results affect report cards and communications. A
premature service boundary between modules that must be transactionally
consistent creates distributed-transaction and eventual-consistency
problems the team does not yet have the operational maturity or need to
solve.

## Decision

The ERP core (Identity, Tenancy, Schools, SIS, Admissions, Finance,
Academics, Attendance, Examinations, HR, Transport, Library, Inventory,
Compliance, Communications, LMS, Documents, Analytics) is built as a
**single Laravel modular monolith**: one deployable application, one
PostgreSQL database (per tenant-isolation model, see ADR 0004), with
strict internal module boundaries enforced by code convention and
review, not by network calls.

The **AI Platform is the one service extracted from day one** (Python/
FastAPI, see ADR 0008), because its technology needs (ML/LLM tooling),
scaling profile, and blast-radius requirements (must never write to ERP
tables directly, see ADR 0014) are fundamentally different from the ERP
core's.

## Rationale

- Most future modules need strong consistency with each other far more
  often than they need independent scaling or independent deployment.
- A modular monolith gets us most of microservices' organizational
  benefit (clear ownership, enforced boundaries, replaceable modules)
  without distributed-systems cost (network partial failure, distributed
  tracing as a hard requirement from day one, eventual consistency bugs,
  N deployment pipelines).
- Indian K-12/multi-school customers are cost-sensitive; infrastructure
  and operational overhead directly affects unit economics and the
  price point the product can hit.
- A small team ships and debugs a monolith faster than a service mesh.
- The AI workload is a genuinely different shape (stateless inference,
  different scaling triggers, different language ecosystem, an explicit
  trust boundary we want anyway) — that is the one place a service
  boundary earns its cost immediately, not speculatively.

## Alternatives considered

1. **Full microservices from day one** (one service per bounded
   context). Rejected: distributed transactions across
   Admissions/SIS/Finance from day one, N times the deployment and
   observability surface, and no team of this size can operate it well
   at this stage.
2. **Monolith with no internal module boundaries** ("big ball of mud").
   Rejected: this is how ERPs become unmaintainable; we want the
   boundaries now even though the deployment is unified, so extraction
   later (if ever needed) is a refactor, not a rewrite.
3. **Monolith plus a separate Finance/Payments service** for PCI-style
   isolation. Rejected for Phase 0A as premature — no payment gateway
   integration exists yet; revisit if/when card-data handling requires
   it (see ADR 0009, "Future extraction path" below applies).

## Consequences

- All ERP business logic runs in one PHP process/deployment; a bug in
  one module can still affect the whole app's uptime (mitigated by
  tests, review, and the module-boundary conventions in root
  `CLAUDE.md`).
- Horizontal scaling scales the whole monolith, not one hot module
  in isolation, until/unless a module is extracted later.
- Internal module boundaries are enforced by convention + code review,
  not the compiler/runtime — this requires discipline documented in
  `docs/architecture/DOMAIN-MAP.md` and root `CLAUDE.md`.
- The AI Gateway service boundary (ADR 0008, 0013) must be respected
  strictly from day one since it is the one real network boundary in
  the system, and it's also the one boundary carrying real security
  consequences (data exfiltration, prompt injection blast radius).

## Future extraction/evolution path

Because module boundaries are enforced from day one (see
`app/Domain/README.md` and `docs/architecture/DOMAIN-MAP.md`'s
dependency directions), a module that later needs independent scaling
or a different technology (e.g. Communications under extreme send
volume, or a future real-time Attendance/Transport tracking service) can
be extracted by:

1. Confirming it communicates with the rest of the system only through
   its Application-layer contracts and domain events (never shared
   Eloquent models) — this should already be true by convention.
2. Standing up the new service and routing its traffic through the
   existing event bus / API gateway rather than direct in-process calls.
3. Migrating its data store only after (2) is stable in production.

No such extraction is planned or justified today. This ADR should be
revisited when a specific module has a measured, not speculative, need.
