# ADR 0014: Capability-Gated AI Tool/Action Boundary

- Status: Accepted
- Date: 2026-08-22

## Context

AI agents will eventually take actions with real consequences (drafting
fee reminders, flagging attendance anomalies, surfacing admissions
leads, and more over time). School data includes financial records and
children's/guardian's personal data. An AI agent must never be able to
take an action, or see data, beyond a narrow, explicitly granted, and
auditable set — regardless of what a model "decides" to do, including
under adversarial prompt injection from user-supplied content the agent
processes (a message, a document, a form field).

## Decision

Every AI-driven action follows this fixed chain, with a hard stop at
any link that fails:

```
Agent -> Capability -> Tool request -> Authorization -> Policy
      -> Approval (if required) -> Domain service -> Audit
```

Concretely, in the Phase 0A skeleton (`services/ai/app/`):

- An **Agent** (`app/agents/registry.py`) has a fixed, explicitly
  granted set of **capabilities** (e.g. `invoice.read`,
  `communication.draft`) — it cannot request a capability it wasn't
  granted, and it cannot grant itself new ones.
- A **Tool** (`app/tools/registry.py`) declares the single capability
  it requires. `ToolRegistry.invoke()` checks the caller's granted
  capabilities against the tool's requirement **before** the tool's
  handler ever runs — an agent cannot bypass this by calling the
  handler directly, since the handler is only reachable through
  `invoke()`.
- A tool's handler is the **only** thing allowed to call back into
  Laravel, and only through an explicitly exposed "AI tool contract"
  endpoint (not yet implemented) — never a direct database connection,
  never a shared write-capable credential (ADR 0002, 0013).
- Actions with financial or irreversible consequence (waiving a fee,
  deleting a record, sending an external communication) require a
  **human approval step** before the domain service executes them —
  an agent may *draft* or *propose*, a human or an explicit
  pre-approved policy authorizes execution.
- Every model call and every tool invocation is written to the **AI
  Audit Log** (`app/audit/ledger.py`), tied to tenant, agent, and (for
  tool calls) the capability exercised — see ADR 0017.

**Worked example** (illustrative — no agent is implemented yet): an
"AI Fee Collection Agent" may be granted `invoice.read`,
`communication_history.read`, `reminder.draft`, and
`message.request_delivery`. It is never granted `invoice.write`,
`fee.waive`, or `transaction.delete` — so no prompt, no user input, and
no model behavior can make it perform those actions, because the
capability simply isn't in its granted set. See
`services/ai/tests/test_tool_authorization.py` for the mechanism
proven in isolation.

## Rationale

- LLM behavior is not fully predictable or fully resistant to prompt
  injection from content it processes. The security boundary therefore
  **cannot** live in "the prompt tells it not to" — it must be an
  enforced, capability-based authorization check that runs regardless
  of what the model outputs, which is exactly what `ToolRegistry.invoke`
  does structurally (see the passing/denied tests in
  `services/ai/tests/test_tool_authorization.py`).
- Capability-based (not role-based) authorization means an agent's
  permissions are a small, explicit, reviewable set — easy to audit
  "what can this agent actually do" without reasoning through role
  hierarchies (this mirrors the human authorization model, ADR-adjacent
  `docs/security/AUTHORIZATION.md`).
- Requiring human approval for irreversible/financial actions accepts a
  small UX cost (a human clicks "approve") in exchange for eliminating
  an entire class of incident ("the AI waived $10,000 of fees") that
  would otherwise be possible by construction.
- A single audit ledger for all AI activity gives one place to answer
  "what did AI do, for which tenant, under which capability, when" —
  essential both for debugging and for any future compliance review.

## Alternatives considered

1. **Trust the system prompt / model instructions as the only
   safeguard** ("tell the model what it's allowed to do"). Rejected:
   prompt-level instructions are not a security boundary against
   adversarial or even just unpredictable model behavior; they're UX
   guidance, not enforcement.
2. **Role-based (not capability-based) agent permissions**, reusing the
   human RBAC-ish model directly. Rejected as the primary mechanism:
   the actor categories in `docs/security/AUTHORIZATION.md` intentionally
   avoid hard-coding behavior around role names for humans, and the
   same reasoning applies more strongly to AI agents, where the
   permitted-action set needs to be even narrower and more explicit
   than a typical human role.
3. **Let agents call Laravel's normal authenticated API as any other
   client would**, without a separate tool/capability layer. Rejected:
   Laravel's normal API authorization answers "is this *user* allowed",
   not "is this *specific agent, with this specific narrow mandate*,
   allowed to take this specific action right now" — the tool/capability
   layer is a stricter, additional gate specific to AI-initiated action,
   not a replacement for normal authorization.
4. **No human-approval step; fully autonomous agents from the start.**
   Rejected for anything financial or irreversible; may be revisited
   *per action type*, deliberately, once an agent's track record and a
   specific policy justify it — never as a default.

## Consequences

- Building a new AI agent capability always means: define the tool,
  define its required capability, implement its Laravel-side contract
  endpoint with its own authorization, and decide whether it needs
  human approval — this is more upfront design work than "just let the
  agent call an API," which is the point.
- The AI Gateway cannot take any action Laravel hasn't explicitly
  exposed as a tool — by construction, there is no generic "AI, do
  whatever" pathway into the ERP.
- Every future agent must be reviewed for its granted capability set as
  carefully as a new human role would be — this is a security review
  checklist item going forward, not a one-time decision.

## Future extraction/evolution path

The Policy Engine and Evaluation Framework named in ADR 0013 sit on top
of this boundary: they will add richer policy conditions (e.g.
per-tenant limits, time-of-day restrictions, spend caps) and automated
evaluation of agent behavior, without changing this ADR's core
enforcement mechanism — capability-gated tool invocation with
mandatory audit.
