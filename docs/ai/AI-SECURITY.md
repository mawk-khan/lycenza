# School OS — AI Agent Security Model

For the decision record, see ADR 0014. This document is the concrete
security reference for anyone building an AI agent or tool.

## Threat model summary

An AI agent processes content it does not fully control: user messages,
uploaded documents, third-party data. That content can attempt to
manipulate the model into taking an action or disclosing data outside
its intended mandate (prompt injection). **The security boundary
therefore cannot be "the prompt tells the model what it may do" — it
must be an enforced check that runs regardless of model output.**

Other risks in scope: a compromised or over-permissioned agent
credential; a bug that grants a capability too broadly; an agent
reasoning its way into a technically-permitted-but-harmful action a
human would have refused; cross-tenant data exposure through a shared
agent process; and — like any system handling payment/personal data —
exfiltration of sensitive data through model outputs, logs, or provider
telemetry.

## The enforcement chain

```
Agent → Capability → Tool request → Authorization → Policy
      → Approval (if required) → Domain service → Audit
```

Every link is mandatory; none may be skipped for convenience.

1. **Agent** — a named, registered actor
   (`services/ai/app/agents/registry.py`) with a fixed capability set
   it was explicitly granted. An agent cannot request a capability
   outside its granted set, and nothing in the codebase lets an agent
   grant itself one.
2. **Capability** — a narrow, named permission (e.g. `invoice.read`,
   `communication.draft`, `reminder.draft`), not a role. Capabilities
   are small enough to review individually — "can this agent do X" has
   a direct, auditable answer.
3. **Tool request** — an agent asks the Tool Registry
   (`services/ai/app/tools/registry.py`) to invoke a named tool with a
   payload. The tool declares the one capability it requires.
4. **Authorization** — `ToolRegistry.invoke()` checks the agent's
   granted capabilities against the tool's requirement **before** the
   tool's handler runs. This is the enforcement point proven by
   `services/ai/tests/test_tool_authorization.py`: granted → allowed;
   empty or unrelated capability set → denied, every time, regardless
   of what the model "wants."
5. **Policy** — (not yet implemented) future conditions layered on top
   of the capability check: per-tenant limits, spend caps, time-of-day
   restrictions. The hook point is the same `invoke()` call.
6. **Approval** — for financial or irreversible actions, a human must
   approve before the domain service executes (not yet implemented —
   no such tool exists yet). An agent may *draft* or *propose* such an
   action as an unprivileged output; only a human-approved,
   Laravel-side action actually executes it.
7. **Domain service** — the tool's handler calls an explicitly exposed
   Laravel "AI tool contract" endpoint, which enforces Laravel's own
   normal authorization for that action, scoped to the declared tenant
   — the AI Gateway does not bypass Laravel's authorization, it adds a
   stricter, additional gate in front of it.
8. **Audit** — every model call and tool invocation is recorded in the
   AI Audit Log (`services/ai/app/audit/ledger.py`), tied to tenant,
   agent, and (for tool calls) the capability exercised.

## Worked example (illustrative — not implemented)

**AI Fee Collection Agent**

May be granted:
- `invoice.read` — read permitted invoice status
- `communication_history.read` — inspect approved communication history
- `reminder.draft` — draft reminders
- `message.request_delivery` — request message delivery (subject to
  Communications module's own send authorization/approval)

Must **never** be granted:
- `invoice.write` — arbitrarily change invoices
- `fee.waive` — waive fees
- `transaction.delete` — delete transactions

Because these are capabilities, not instructions, no prompt injection
in a parent's message, no adversarial document content, and no model
"reasoning" can make this agent waive a fee or delete a transaction —
the capability simply does not exist for it to exercise, and
`ToolRegistry.invoke()` would reject the attempt even if the model
requested it.

An agent also **may not** access an unrelated tenant: every tool
invocation is scoped to the tenant declared on the originating request
(`docs/architecture/TENANCY.md`, "tenant-aware AI requests") — there is
no cross-tenant capability grant in this model.

## Data exposure controls

- Only data a granted capability's tool explicitly returns reaches the
  model — an agent cannot query the ERP database directly (ADR 0002)
  and has no broader read access than its capabilities define.
- What data a tool is allowed to return is itself a design decision for
  that tool's Laravel-side contract, informed by
  `docs/security/DATA-CLASSIFICATION.md` — a tool built for a
  fee-collection agent should not return Health-classified data even if
  it happens to be adjacent in the database.
- Provider-side data handling (what a given LLM provider does with
  submitted content, retention, training-data use) is a per-provider
  legal/contractual concern to evaluate before integrating any real
  provider — **flagged here as requiring legal/compliance review before
  a real provider handling real school/student data is selected**, not
  a decision made in this document. The questions that review must
  answer, the approval form it must take, and the Phase 0M decisions
  that follow are in `docs/security/AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md`
  (status: BLOCKED).

## Phase 0B: the tenant-binding half (ADR 0023)

Phase 0A proved capability-gating in isolation, on the `services/ai`
side only. Phase 0B closes the other half of the threat model this
document opens with: **a compromised or buggy `services/ai` process
must not be able to fabricate or widen which School/actor/capability an
action runs as.**

`App\Support\Ai\AiContextTokenService` (Laravel-only — the signing key
is never given to `services/ai`) issues a short-lived (60s), HMAC-signed
token binding `school_id` + `actor_id` + the exact capability being
exercised, minted only after `App\Support\Ai\AiGatewayClient` has
verified real membership/role data grants that capability in that
School. `services/ai`'s tool handlers relay this token unchanged; they
never decode, alter, or construct one. Laravel's inbound AI-tool-contract
endpoints (`App\Http\Controllers\Api\Internal\AiToolController`) verify
the signature and capability claim before doing anything.

**Phase 0O.1: the signing key fails closed in every environment.** With
`AI_GATEWAY_CONTEXT_SIGNING_KEY` unset or blank the service neither issues
nor verifies a token (`AiContextSigningKeyNotConfiguredException`) —
before, a missing key became an empty HMAC key that anyone could use to
forge a token. Production additionally refuses to boot with the committed
placeholder values. Rotation and a key id stay deferred (ADR 0023).

Consequence: even a fully compromised `services/ai` process can, at
worst, replay a token Laravel already issued for an already-authorized
action within its 60-second window — it cannot mint a new one for a
different School or a capability the original actor didn't hold. This
is the concrete implementation of "no cross-tenant data exposure
through a shared agent process" from this document's threat model.

## Model completions fail closed (2026-09-24)

`/v1/complete` now follows the same rule as tools: the invoking human's
capability is checked before a context token is minted
(`AiGatewayClient::complete()`), the gateway has Laravel verify that
token and re-check the capability before the provider is called
(`/api/internal/ai/completions/authorize`), the School always comes from
the verified token, every model call is audited durably with
identifiers and numbers only, and no output is returned if that audit
cannot be written. Gateway logs and errors never carry prompts, outputs
or bodies (ADR 0051 §8 keeps this for production telemetry: JSON logs with
the verified context token's `request_id` and the shared `traceparent`
trace id for correlation, never prompts, outputs, bodies, credentials or
tokens; implemented in Phase 0O.5A by `services/ai/app/core/logging.py`,
which also drops uvicorn access lines' client address and query string),
and an external provider stays off unless
`REAL_PROVIDERS_ENABLED` is exactly `true` — which is only permitted
after the recorded approvals in
`docs/security/AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md`. Only the offline
`NullProvider` exists; Phase 0M remains BLOCKED.

## What Phase 0B proves vs. what remains to be built

**Proven now** (passing tests — `services/ai/tests/`,
`tests/Feature/Ai/AiGatewayClientTest.php`,
`tests/Feature/Api/Internal/AiToolControllerTest.php`, plus a live,
unmocked, cross-container round trip exercised during this checkpoint):

- Capability-gated tool invocation on the `services/ai` side (Phase 0A,
  unchanged): allows a correctly-permissioned call, denies empty/
  unrelated capability sets.
- Context-token verification on the Laravel side: rejects missing,
  forged, tampered, expired, or wrong-capability tokens; a valid token
  correctly sets `TenantContext`, executes a real RLS-protected read,
  and clears context afterward.
- An actor entitled only to School A cannot obtain a context token for
  School B — proven both by a unit test and by the live round trip.

**Not yet built:** any real customer-facing agent, any real tool with a
*write* effect on ERP data (the one Phase 0B tool, `school.echo`, is
deliberately read-only), the Policy Engine, the human-approval workflow
(no tool needing it exists yet), the Evaluation Framework, and durable
storage for the AI Gateway's own audit ledger (Laravel's side of the
audit trail, `school_audit_events`, is already durable and RLS-protected
— see `docs/architecture/adr/0017-audit-architecture.md`). Every one of
these must be designed and reviewed against this document before the
first real AI agent ships.
