# School OS — AI Platform Architecture

For the decision record, see ADR 0013 (AI Gateway) and ADR 0014 (AI
tool/action boundary). This document is the operational map of
`services/ai`.

## Boundary statement

Laravel (`apps/platform`) is the authoritative system of record (ADR
0002). The AI Platform (`services/ai`) provides intelligence — it never
holds authority. Concretely: no AI code has ERP database credentials,
no AI code writes to an ERP table, and every AI-initiated action that
has a real effect goes through an explicitly exposed, authenticated
Laravel contract with its own authorization (ADR 0014).

This extends to availability, not just data access (Phase 0C.4): the
AI Gateway is an **optional** subsystem from the core ERP's point of
view. Laravel's own readiness (`GET /api/health/ready`) never checks
the AI Gateway at all, and an unreachable Gateway is reported
`Degraded`, never `Unhealthy`, in internal diagnostics
(`App\Support\Observability\OperationalStatusService::aiGateway()`) —
see `docs/architecture/OBSERVABILITY.md`. The AI Gateway being down
must never take down School management, authentication, or any other
core ERP function.

## Components (current implementation status)

| Component | Location | Status |
|---|---|---|
| **AI Gateway** (HTTP entrypoint) | `services/ai/app/main.py` | Implemented: `/health/live`, `/health/ready` (Phase 0C.4, unauthenticated — see `docs/architecture/OBSERVABILITY.md`), `/v1/complete`, `/v1/tools/invoke` (Phase 0B, service-token authenticated). Since the 2026-09-24 fail-closed hardening `/v1/complete` additionally requires a Laravel-verified context token and an agent `completion_capability`, audits every model call durably (no output without its audit), and never echoes input or bodies in errors — see `docs/security/AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md` §3. |
| **Model Provider Registry / Router** | `services/ai/app/gateway/router.py` | Implemented, with one registered provider. Fail-closed: an external provider (any `ModelProvider` not declaring `external = False`) can be neither registered nor selected, and the app will not start with one, while `REAL_PROVIDERS_ENABLED` is off (the default). |
| **Provider interface** | `services/ai/app/providers/base.py` | Implemented (`ModelProvider` ABC). |
| **Offline provider** | `services/ai/app/providers/null_provider.py` | Implemented — deterministic, no network call, used for dev/tests. **No real model provider is integrated yet.** |
| **Agent Registry** | `services/ai/app/agents/registry.py` | Implemented; one demo registration (`phase0b-proof-agent`, capability `school.echo.invoke`) proving the mechanism — not a real agent. |
| **Tool Registry** | `services/ai/app/tools/registry.py` | Implemented, capability-gated `invoke()`; one demo tool (`school.echo`, `services/ai/app/tools/school_echo.py`) that relays to Laravel — proves the chain, not a business feature. |
| **Prompt Registry** | — | Not implemented. |
| **Knowledge/RAG layer** | — | Not implemented. |
| **AI Usage Ledger / AI Audit Log** | `services/ai/app/audit/ledger.py` | Implemented as an in-process stub; **not durable** — must move to persistent storage before production use (see ADR 0017's future-extraction note). Laravel's own audit trail (`AuditRecorder`, `school_audit_events`) IS durable and RLS-protected, and now records every AI tool invocation that reaches Laravel's side (`ai.tool_invoked` events) — see `docs/ai/AI-SECURITY.md`. |
| **Signed context tokens** (Phase 0B, ADR 0023) | `App\Support\Ai\AiContextTokenService`, `App\Support\Ai\AiGatewayClient` | Implemented — see below. |
| **Safety/Policy Engine** | — | Not implemented. The capability check in the Tool Registry, plus the context-token capability claim on Laravel's side, are the current enforcement points it will extend. |
| **Evaluation Framework** | — | Not implemented. |

## Provider independence

Every model call goes through `ModelRouter.resolve()` →
`ModelProvider.complete()`. No calling code (an agent, a tool handler,
the HTTP layer) imports or references a specific provider's SDK. Adding
a real provider (Anthropic, OpenAI, Google, Azure OpenAI, or a local
model) means implementing `ModelProvider` and registering it — no
change to anything that calls the router. See ADR 0013.

## How Laravel calls the AI Gateway

1. Laravel authenticates to the AI Gateway with a per-request Ed25519
   **service assertion** (ADR 0053, Phase 0O.7A):
   - it is sent as `Authorization: Lycenza-Service …`, signed with
     Laravel's own `platform` private key, and bound to the method, the
     exact path and the SHA-256 of the exact body bytes, with a lifetime
     of at most 120 s;
   - the Gateway verifies it against its ring of `platform` **public**
     keys, before any routing or body parsing
     (`services/ai/app/core/security.py`, `ServiceAuthMiddleware`).

   This proves "the caller is the trusted platform", nothing more.

   **No fallback.** There is no shared token and no `X-Service-Token`; the
   Gateway refuses to start (and `/health/ready` returns 503):
   - without valid keys;
   - with the committed development keys outside `local`/`testing`;
   - with a plaintext Laravel URL outside `local`/`testing`;
   - with the retired `SERVICE_TOKEN` set.

   `ENVIRONMENT` defaults to `production` (`services/ai/app/core/startup.py`).
   Laravel's production guard is the mirror image
   (`docs/architecture/PRODUCTION-RELEASE.md` §2).
2. For `/v1/tools/invoke` (ADR 0023), `App\Support\Ai\AiGatewayClient`
   first verifies the calling actor actually holds the required
   capability in the target School (via `CapabilityResolver`) — **before**
   minting anything — then mints a short-lived HMAC-signed context
   token binding `school_id` + `actor_id` + the one capability being
   exercised, and sends it alongside `school_id`/`agent`/`tool`. There
   is no ambient "current tenant" on the AI Gateway side (mirrors the
   tenant-aware-AI-requests rule in `docs/architecture/TENANCY.md`).
3. The AI Gateway resolves a provider/tool, gets a result, and records
   the call to its own audit ledger — every model call and tool
   invocation is logged there, independent of whatever Laravel-side
   audit trail a tool's handler also produces.

## How the AI Gateway calls back into Laravel (Phase 0B: one proof tool)

`services/ai/app/tools/school_echo.py` is the first (and, in Phase 0B,
only) tool whose handler calls back into Laravel, via
`POST /api/internal/ai/tools/school-echo`
(`App\Http\Controllers\Api\Internal\AiToolController`). It establishes
the pattern every future real tool must follow:

- Lives under `/api/internal/ai/...`, never the general `/api/v1`
  surface a human/mobile client uses.
- **Service assertion.** Guarded by
  `App\Http\Middleware\AuthenticateServiceAssertion` (`service-auth`,
  ADR 0053). It requires a per-request `ai-gateway` Ed25519 assertion,
  signed with the Gateway's **own** key: the reverse direction uses a
  separate keypair from step 1.
- **Replay.** Laravel consumes each assertion's `jti` once, in Redis.
- **Route scope.** The route must be in the closed catalog
  (`App\Support\ServiceAuth\ServiceAuthContract::ROUTE_SCOPES`). This
  proves the caller is the trusted gateway, and nothing about a School:
  `ResolveSchoolContext` resolves no School on a service route.
- Then verifies the **context token** (`AiContextTokenService::verify()`):
  signature, expiry, and that the token's capability claim matches what
  this specific tool requires (`school.settings.view`) — proves the
  caller is entitled to act as this specific actor/School/capability.
  Only after both checks does it call `TenantContext::set()` and run a
  trivial, read-only, already-tenant-scoped read.
- Records a `school_audit_events` row (`ai.tool_invoked`) before
  clearing context in a `finally` block.
- Reached, on the `services/ai` side, only through
  `app/tools/registry.py`'s `invoke()`, so the local capability check
  (ADR 0014) is not bypassable by calling the handler function
  directly.

## What Phase 0B proves, concretely

`services/ai/tests/` (10 tests) and the Laravel-side
`tests/Feature/Ai/AiGatewayClientTest.php` +
`tests/Feature/Api/Internal/AiToolControllerTest.php` (11 tests, all
real Postgres — ADR 0024) prove, without any real model provider:

- An unauthenticated caller cannot use the gateway (either direction).
- A tool invocation succeeds only when the agent's own granted
  capabilities include the tool's required capability (services/ai
  side) **and** the context token's capability claim matches what the
  Laravel-side tool contract requires (Laravel side) — two independent
  gates, both required.
- An actor entitled only to School A can never cause a context token to
  be minted for School B — `AiGatewayClient::invokeTool()` checks real
  membership/role data before minting, and `services/ai` never holds
  the signing key needed to mint one itself (ADR 0023).
- Beyond the automated suite, this checkpoint additionally exercised
  the **full live round trip** — real Docker containers, real network
  hops, no mocks in either process — which caught and fixed two real
  bugs (a header-convention mismatch and a JSON object/array
  serialization mismatch between the two languages) that the mocked
  unit tests alone had missed; see the Final Report's Security Review
  and Technical Debt sections.

This is the mechanism the whole AI security model depends on
(`docs/ai/AI-SECURITY.md`).

## What is explicitly out of scope for Phase 0B

No real LLM provider integration, no Prompt Registry, no RAG/embeddings,
no real customer-facing agent, no real tool with a *write* effect on
ERP data (school-echo is read-only by design), no human-approval
workflow (ADR 0014's "Approval" step — no tool needing it exists yet),
no Safety/Policy Engine beyond the capability checks described above,
no Evaluation Framework, and no durable AI-side audit storage (Laravel's
own audit trail for AI-initiated Laravel-side effects IS durable; the
AI Gateway's own ledger is still the in-process Phase 0A stub).

## Recorded, not fixed (Phase 0O.7A)

These are tracked here deliberately; they are **not** part of ADR 0053's
scope.

- **AI context token (ADR 0023) debt:**
  - the context signing key has no `kid` or rotation ring (its managed
    custody is ADR 0050 §5's future item);
  - the token carries no purpose or audience claim (one token is accepted
    by school-echo, audit and completion-authorize, scoped by its
    capability claim);
  - `verify()` does not check `iat` sanity or a maximum lifetime (only
    Laravel signs, so this is not exploitable without the key).
- **Gateway reliability:** `POST /v1/tools/invoke` answers **500** (an
  unhandled `KeyError`) for an unknown agent or tool, after successful
  service authentication. `/v1/complete` answers 403 `agent_not_allowed`.
  This is a separate API-correctness fix, not a security boundary.

## Status note — Phase 0M deferred post-v1 (2026-09-29, ADR 0061)

- **Deferral:** the owner deferred real model providers, real agents and AI
  write tools to post-v1. Phase 0M is **CLOSED FOR PHASE ZERO**; it is not
  complete, and the provider legal/compliance gate is not cleared.
- **Unchanged:** everything above, including `NullProvider` only,
  `REAL_PROVIDERS_ENABLED` off, the context token, capability checks,
  durable audit and service authentication.
- **Reopening:** `docs/security/AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md`
  section 18 is the reopening gate.
