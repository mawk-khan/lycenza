# ADR 0008: Python/FastAPI as the AI Platform's Implementation Stack

- Status: Accepted
- Date: 2026-08-22

## Context

The AI Platform (agents, LLM routing, RAG, embeddings, prediction,
document intelligence) needs a technology stack with strong ML/LLM
tooling and library support, and is the one component intentionally
run as a separate service from the Laravel ERP core (ADR 0001).

## Decision

`services/ai` is built in **Python 3.12 with FastAPI**, deployed
independently from `apps/platform`, communicating with it only over
authenticated HTTP (ADR 0013, 0014) — never a shared database
connection, never a shared filesystem, never in-process calls.

## Rationale

- Python has, by a wide margin, the deepest ecosystem for LLM
  orchestration, embeddings, RAG tooling, and ML/data-science libraries
  — the natural language for this workload.
- FastAPI gives async I/O (important for an I/O-bound gateway
  fanning out to external model providers), automatic OpenAPI schema
  generation for its own internal contracts, and Pydantic-based typed
  request/response models that catch a whole class of shape bugs early
  — a good match for a boundary service where correctness of the
  contract matters as much as the logic behind it.
- Running it as a separate deployable (rather than, say, shelling out to
  Python from PHP, or embedding a Python runtime) keeps the trust
  boundary described in ADR 0002/0014 real at the infrastructure level,
  not just at the code-review level: the AI service can be given a
  narrower database-less credential set by construction.

## Alternatives considered

1. **Implement AI orchestration in PHP inside the Laravel monolith.**
   Rejected: PHP's LLM/ML tooling ecosystem is far thinner, and
   collapsing the AI workload into the same process as the authoritative
   ERP core would make the "AI never writes to ERP tables directly"
   boundary (ADR 0002, 0014) a code-review convention instead of an
   enforceable service boundary.
2. **Node.js/TypeScript for the AI service** (sharing a language with
   the web frontend). Considered for ecosystem-consistency reasons;
   rejected because Python's LLM/RAG/ML library maturity is materially
   ahead of Node's for this specific workload, which matters more than
   language consistency across services that don't share code anyway.
3. **A managed/no-code AI orchestration platform instead of a
   first-party service.** Rejected: the AI Gateway's core value is
   provider independence and tight, auditable capability control (ADR
   0013, 0014) tailored to school-domain constraints — a generic
   third-party orchestration platform would fight that requirement or
   reintroduce a provider dependency at a different layer.

## Consequences

- The team maintains a third application-layer language/runtime
  (alongside PHP and TypeScript, and Dart for mobile).
- `services/ai` needs its own dependency management, testing, and CI
  pipeline (`.github/workflows/ci.yml`'s `ai-service` job), separate
  from `apps/platform`'s.
- Every AI-to-ERP interaction crosses a real network boundary with real
  auth (ADR 0013), which is an intentional latency/complexity cost paid
  for a genuine security and blast-radius benefit.

## Future extraction/evolution path

If specific AI workloads (e.g. a heavy batch embedding/indexing job)
later need different scaling characteristics than the interactive
gateway, they can become their own workers/services behind the same
`app.gateway` boundary described in ADR 0013, without changing this
ADR's core decision that Python/FastAPI is the AI Platform's language
and that it stays outside the ERP's authoritative write path.
