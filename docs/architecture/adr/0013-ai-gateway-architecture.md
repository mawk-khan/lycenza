# ADR 0013: Provider-Independent AI Gateway

- Status: Accepted
- Date: 2026-08-22

## Context

The product will eventually integrate one or more LLM providers
(Anthropic, OpenAI, Google, Azure OpenAI, and potentially local/
open-source models for cost or data-residency reasons). No ERP domain
code should ever depend on a specific provider's SDK, API shape, or
pricing model — that dependency belongs in exactly one place so it can
change without touching business logic, and so a provider outage or
policy change doesn't become a cross-cutting product incident.

## Decision

`services/ai` implements an **AI Gateway** with the following internal
components, each with a narrow, explicit responsibility (implemented
today as the Phase 0A skeleton in `services/ai/app/`, with only an
offline `NullProvider` wired up for real traffic):

- **Model Provider Registry + Router** (`app/gateway/router.py`) —
  resolves a request to a concrete `ModelProvider` implementation.
  Selection logic (by agent, capability, cost, data residency) lives
  here, not in any calling code.
- **Provider interface** (`app/providers/base.py`) — the only contract
  calling code depends on; concrete providers (a future Anthropic
  adapter, OpenAI adapter, etc.) implement it and are otherwise
  invisible to the rest of the system.
- **Agent Registry** (`app/agents/registry.py`) — the fixed set of
  capabilities each named AI agent may request (see ADR 0014).
- **Tool Registry** (`app/tools/registry.py`) — explicitly exposed ERP
  actions an agent may invoke, each gated by a required capability (ADR
  0014).
- **Prompt Registry** (`app/prompts/`, not yet implemented) — versioned,
  reviewable prompt templates rather than prompts inlined ad hoc in
  business logic.
- **Knowledge/RAG layer** (not yet implemented) — will sit behind the
  same provider-independence principle: embeddings/retrieval providers
  are swappable the same way completion providers are.
- **AI Usage Ledger / AI Audit Log** (`app/audit/ledger.py`) — records
  every model call and tool invocation; see ADR 0017 for how this
  relates to the ERP's broader audit architecture.
- **Safety/Policy Engine** and **Evaluation Framework** — not yet
  implemented; their hook points (capability checks in the Tool
  Registry, the audit ledger) already exist so they can be added
  without restructuring the gateway.

Laravel (`apps/platform`) never calls a model provider directly and
never holds model-provider credentials — it calls this gateway over
authenticated HTTP (ADR 0002).

## Rationale

- Provider lock-in is a real business risk (pricing changes, rate
  limits, model deprecation, data-residency requirements that differ by
  customer). Isolating provider-specific code behind one interface
  means a provider swap or multi-provider strategy is a `services/ai`
  change, not a product-wide one.
- A single gateway is also the natural place to enforce **capability-
  gated tool access** (ADR 0014) uniformly, regardless of which agent
  or which provider is involved — security policy doesn't get
  reimplemented per integration.
- Centralizing usage/audit logging here gives one place to reason about
  AI cost, data exposure, and behavior across every future agent,
  instead of each agent integration inventing its own logging.
- Building the skeleton now, before any real provider integration
  exists, means the *shape* of provider independence is established
  and testable (see `services/ai/tests/`) before the pressure of a
  real integration deadline tempts a shortcut that couples a specific
  provider into business logic.

## Alternatives considered

1. **Call model provider SDKs directly from Laravel.** Rejected: ties
   PHP application code to a specific vendor SDK/API shape, makes
   provider changes a cross-cutting refactor, and — critically —
   removes the one clean boundary at which "AI must never write to ERP
   tables directly" (ADR 0002, 0014) can be enforced structurally.
2. **A third-party LLM-orchestration SaaS as the gateway.** Rejected:
   reintroduces a different form of vendor lock-in at the orchestration
   layer, and doesn't give the same fine-grained control needed for the
   capability/tool authorization model (ADR 0014), which is specific to
   this product's school-domain risk profile (financial actions,
   children's data).
3. **One gateway per provider (no shared abstraction).** Rejected: this
   is provider lock-in with extra steps — whichever provider's
   integration ships first becomes the de facto interface everything
   else depends on.

## Consequences

- Every new model-provider integration is additive: implement
  `ModelProvider`, register it in the router — no change required to
  agent or tool code that only depends on the interface.
- The gateway is a genuine network/trust boundary (ADR 0002, 0008) —
  this adds latency and an extra service to operate, an accepted
  tradeoff for the isolation and auditability it buys.
- No real provider is integrated in Phase 0A; `NullProvider` exists
  specifically so the gateway's shape can be built, tested, and
  reviewed without requiring API keys, external network calls, or
  spending on a real provider before the architecture is validated.

## Future extraction/evolution path

Adding real providers, a real Prompt Registry, a real Knowledge/RAG
layer, and a real Safety/Policy Engine are all future-phase work that
plugs into the interfaces already defined here. None of them change
this ADR's core decision: ERP domain code depends only on the gateway's
contract, never on a specific provider.
