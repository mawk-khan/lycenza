# Phase 0M AI Platform — Readiness & Provider Legal/Compliance Gate

**Status: BLOCKED (recorded 2026-09-24).** No real model provider may
receive School data, no real agent may run, and no AI write tool may be
built until the decisions in section 18 are recorded in the form section
19 requires. This document states what must be decided; it decides
nothing. It is written by engineering, not legal counsel, and makes no
claim that any provider, contract or configuration satisfies any law
(`docs/security/DATA-CLASSIFICATION.md`, opening note).

**Update 2026-09-24:** the four engineering gaps G1–G4 (section 3) are
**ENGINEERING HARDENING COMPLETE** — with the offline `NullProvider`
only; no provider, SDK or credential was added. That does not unblock
Phase 0M: every provider/legal and product decision in section 18 is
still open, and the status below is unchanged.

> **OWNER PRODUCT DECISION — POST-v1 DEFERRAL (2026-09-29, ADR 0061).**
> The owner deferred Phase 0M's real model providers, real agents and AI
> write tools to post-v1. Phase 0M is **CLOSED FOR PHASE ZERO — REAL
> PROVIDERS / REAL AGENTS DEFERRED POST-v1**. The deferral is a product
> decision only.
> - **This gate is not passed or cleared.** Every section 18 decision
>   (18A, 18B and the open 18C rows) stays unresolved and becomes the
>   **required reopening gate** for any post-v1 AI initiative (ADR 0061
>   §3.5).
> - **The fail-closed state of section 3 stays in force:** `NullProvider`
>   only, no SDK or credentials, no production caller,
>   `REAL_PROVIDERS_ENABLED` off, no real agent or AI write tool.
> - **No School data may reach a real provider** until this gate's
>   decisions are recorded in the form section 20 requires.
>
> The status line above cites "section 19" for that form. Section 20
> ("Required form of approval") is the correct reference; the line is left
> unedited.

Sources: `docs/roadmap/MASTER-ROADMAP.md` ("Phase 0M — AI Platform: Real
Agents"), ADR 0013 (provider-independent AI Gateway), ADR 0014
(capability-gated tool/action boundary), ADR 0023 (signed context
tokens), ADR 0016 (secrets), ADR 0017 (audit), ADR 0043 (Automation),
`docs/ai/AI-SECURITY.md`, `docs/ai/AI-PLATFORM.md`,
`docs/security/DATA-CLASSIFICATION.md`, `docs/security/AUTHORIZATION.md`,
`docs/architecture/TENANCY.md`, `docs/architecture/PHASE-0L-CLOSEOUT.md`.

## 1. Phase 0M scope (repository-defined)

| Requirement | Repository source | Current state | Gate / work required |
|---|---|---|---|
| First real model-provider integration | Roadmap Phase 0M; ADR 0013 | Only the offline `NullProvider` exists (section 2) | Provider review (sections 5, 18A) **completed first** — the roadmap says so explicitly |
| First real agent and tool with a Laravel-side *write* effect ("most likely fee-reminder drafting") | Roadmap Phase 0M; ADR 0014 worked example | One read-only proof tool (`school.echo`), one proof agent (`phase0b-proof-agent`) | Product choice of agent/tool (18B); fee reminders touch Financial and Guardian data (section 4) |
| First human-approval workflow for financial/irreversible actions | Roadmap Phase 0M; ADR 0014 "Approval"; `AI-SECURITY.md` step 6 | Not built ("no tool needing it exists yet") | Design and product decision (18B/18C); section 10 |
| AI Gateway durable audit | Roadmap: "no longer an open item" | Tool calls are written through to `school_audit_events` (`ai.gateway_action_recorded`); **model calls are not** (section 3) | Close before real provider (18C) |

Not in the repository's Phase 0M scope (and not assumed here): RAG /
embeddings, a Prompt Registry beyond what the first agent needs, the
Evaluation Framework, cross-School or platform AI, AI-authored
Automation rules.

## 2. Existing AI infrastructure

Class: **A** production-ready substrate · **B** test/mock-only ·
**C** architectural placeholder · **D** not implemented.

| Item | Where | Class |
|---|---|---|
| Provider interface `ModelProvider` / `CompletionRequest` / `CompletionResult` | `services/ai/app/providers/base.py` | A (interface only) |
| `NullProvider` (offline echo, no network) | `services/ai/app/providers/null_provider.py` | B |
| Real provider adapter (any vendor) | — | **D** |
| `ModelRouter` (registry; only `null` registered; unknown name raises) | `services/ai/app/gateway/router.py` | A (mechanism) |
| `/v1/complete` — since the G1–G4 hardening: Laravel-verified context token and agent completion capability, durable audit, sanitized errors, provider switch | `services/ai/app/main.py`, `app/gateway/completion_auth.py`; Laravel `AiCompletionAuthorizationController` | A (NullProvider only) |
| `/v1/tools/invoke` + `ToolRegistry.invoke()` capability check | `services/ai/app/main.py`, `app/tools/registry.py` | A |
| `school.echo` tool → Laravel `POST /api/internal/ai/tools/school-echo` | `app/tools/school_echo.py`, `App\Http\Controllers\Api\Internal\AiToolController` | B (proof, read-only) |
| Agent registry with `phase0b-proof-agent` | `app/agents/registry.py` | B (proof) |
| Signed context tokens (60 s, HMAC, Laravel-only key) | `App\Support\Ai\AiContextTokenService`, `App\Support\Ai\AiGatewayClient` | A — but `AiGatewayClient` has **no production caller** |
| Service identity for the gateway (`ai-gateway`, capabilities `ai.tools.invoke`, `ai.audit.write`) | `ServiceIdentitySeeder`, `App\Http\Middleware\VerifyAiGatewayServiceToken` | A (machine authentication only) |
| Durable audit write-back for tool calls | `app/audit/laravel_audit.py` → `App\Http\Controllers\Api\Internal\AiAuditController` | A |
| In-process audit ledger | `app/audit/ledger.py` | B (not durable) |
| Prompt Registry (`app/prompts/` is empty), RAG, Policy Engine, Evaluation Framework, human-approval workflow | — | D |
| AI feature flag / per-School opt-in | — | D |
| AI queue jobs (`QueueName::Ai` is reserved; "none exists yet") | `App\Support\Observability\QueueName` | C |
| Provider credentials config | — (Laravel holds only `services.ai_gateway.*`: base URL, service token, context signing key) | D |

## 3. Current fail-closed state

Evidence that no School data can reach an external model today:

- `services/ai/requirements.txt` has no model-provider SDK; the only
  outbound HTTP calls (`httpx`) go to `settings.erp_contract_base_url`
  (Laravel's internal AI routes).
- `ModelRouter` registers only `NullProvider`; `resolve()` of any other
  name raises.
- `App\Support\Ai\AiGatewayClient` has no caller in `app/` or `routes/`;
  nothing in the running ERP sends a request to the gateway.
- Laravel never holds provider credentials (ADR 0013).

Gaps that were harmless while only `NullProvider` existed but had to be
closed before any real provider is registered. **All four were closed on
2026-09-24 (engineering hardening, NullProvider only)** — see "As
hardened" below the table.

| # | Gap | Why it matters with a real provider |
|---|---|---|
| G1 | `/v1/complete` authorizes only the shared service token; `school_id` is caller-supplied and unverified; no context token, no capability claim | A prompt could be sent to a provider without the ADR 0023 School/actor/capability binding that tools already require |
| G2 | Model calls are recorded only in the in-process ledger; only tool calls are written through to Laravel | "Every model call ... is written to the AI Audit Log" (ADR 0014) would not hold durably |
| G3 | `laravel_audit.write_through` logs a rejected response `body`; `school_echo` puts the Laravel response text into `ToolExecutionError` (returned as the 502 detail) | Response bodies must never reach logs or callers once real data flows (section 8) |
| G4 | No provider enable switch exists | A real provider must be off by default and enabled per environment and per School only after approval (18B/18C) |

**As hardened (2026-09-24):**

- **G1** — `/v1/complete` requires, besides the service token, a signed
  context token and an agent that declares a `completion_capability`
  (the proof agent reuses `school.settings.view`; no new permission).
  Before the provider is called, the gateway asks Laravel
  (`POST /api/internal/ai/completions/authorize`,
  `App\Http\Controllers\Api\Internal\AiCompletionAuthorizationController`,
  service capability `ai.tools.invoke`) to verify signature, expiry and
  capability claim, match an optional `school_id`, and re-check that the
  actor still holds the capability in that School now. Laravel returns
  the only authoritative School and actor. Refusals are stable codes
  (`context_invalid`, `capability_denied`, `context_mismatch`,
  `authorization_unavailable`, `agent_not_allowed`).
  `App\Support\Ai\AiGatewayClient::complete()` mirrors `invokeTool()`
  (capability check before minting); it has no production caller.
- **G2** — every completion that reaches the provider is written through
  to `school_audit_events` (`ai.gateway_action_recorded`, action
  `model.complete`) with provider, model, outcome, latency and numeric
  token counts only; `AiAuditController` now accepts identifiers and
  bounded numbers only. **No output is released without its durable
  audit**: if the write-back fails the caller gets 503
  `audit_unavailable`; a provider failure is 502 `provider_error` and is
  audited as such when possible. A refused request is not audited as a
  model call (no model ran).
- **G3** — gateway logs carry status codes and exception class names,
  never response bodies; tool-relay errors carry the status code only;
  request-validation errors report location and type, never the input
  (FastAPI's default 422 echoes the body, which could hold a prompt or
  token); provider exceptions are normalized.
- **G4** — `REAL_PROVIDERS_ENABLED` (gateway setting
  `real_providers_enabled`) is off unless it is exactly `true`; missing,
  empty or malformed values are off and never stop the service booting.
  Every `ModelProvider` counts as external unless it declares
  `external = False` (only `NullProvider` does). `ModelRouter` refuses an
  external provider at registration, at selection, and at startup
  (`assert_fail_closed`). Turning the switch on is only permitted after
  the section 18/20 approvals, and no external provider exists anyway.

Tests: `services/ai/tests/test_complete_fail_closed.py` (refusals before
the provider, audit semantics, canaries in prompt/output/error bodies
absent from logs/responses/audit, switch, dependency and no-network
guards); Laravel `Tests\Feature\Api\Internal\AiCompletionAuthorizationTest`,
`AiAuditControllerTest`, `Tests\Feature\Ai\AiGatewayClientTest`.

## 4. Data that could reach a provider

Tiers exactly as in `DATA-CLASSIFICATION.md`. "Could reach" means a
future tool contract could return it into a prompt. **Before the section
18 decisions are recorded, no School data of any tier may be sent to a
real provider** — only synthetic test fixtures to `NullProvider`.

| Data category | Repository classification | Could reach provider? | Allowed before review? |
|---|---|---|---|
| Student data (general) | Sensitive | Yes, via a tool contract | No |
| Children's data specifically | Highly Sensitive — and any module processing Student data outside StudentMark's approved scope "must still be reviewed by qualified counsel" | Yes | No (separate counsel review too) |
| Guardian data | Sensitive | Yes (e.g. fee reminders) | No |
| Student attendance data | Sensitive | Yes | No |
| Academic performance (marks/results) | Highly Sensitive (children's data); StudentMark production not approved | Not until StudentMark is enabled | No |
| Syllabus/curriculum content; curriculum delivery; examination definitions/papers; grade scales; LMS content/assignments | Confidential | Yes | No |
| Admissions (applicants) | No dedicated row; applicants are children, so children's data — Highly Sensitive | Yes | No |
| Communications content (staff/Guardian messages) | Inherits the personal data it carries (no dedicated row) | Yes | No |
| Financial data (fees, invoices, payroll, payment references) | Highly Sensitive | Yes (roadmap's fee-reminder example) | No |
| Employee (HR) data | Sensitive | Yes | No |
| Payroll amounts / statutory identifiers | Highly Sensitive | Yes | No |
| Documents/files | Inherits what the document contains | Yes (excerpts) | No |
| Health data | Highly Sensitive (own legal gate; Health not built) | Not built | No |
| Operational data (inventory, canteen catalogue, timetable) | Confidential / Sensitive | Yes | No |
| Canteen orders | Highly Sensitive | Yes | No |
| Audit records | Highly Sensitive (v1 review treatment) | Yes | No |
| Automation / Analytics outputs | Inherit their sources | Yes | No |
| Government/statutory identifiers | Highly Sensitive | Yes | No |
| Authentication secrets | Highly Sensitive | Must never | Never |
| Free-text staff input | Inherits whatever it contains; must be treated as untrusted (section 16) | Yes | No |

Putting data into a prompt never lowers its tier (`AI prompts/context`
row).

## 5. Provider review — reusable approval matrix

Completed once **per provider candidate** (and per model/deployment
option where terms differ). No provider is selected, ranked or preferred
here.

| # | Review question | Required evidence | Approval owner | Blocking? |
|---|---|---|---|---|
| P1 | Does the provider retain prompts/outputs; for how long; can retention be set to zero or minimized? | Current contract/data-processing terms and configuration evidence for the exact account type | Legal/compliance | Yes |
| P2 | Are prompts/outputs used to train or improve models or services; is opt-out contractual and on by default for this account? | Contract clause; account setting | Legal/compliance | Yes |
| P3 | Is content subject to human review by provider personnel (e.g. abuse monitoring); under what conditions and retention? | Provider policy and contract | Legal/compliance | Yes |
| P4 | Where is data processed and stored; which subprocessors; is cross-border transfer involved? | Region commitments; subprocessor list; transfer terms | Legal/compliance (the repository already notes an open India data-localization exposure — ADR 0037) | Yes |
| P5 | Deletion: how and when is data deleted, and on request? | Terms; deletion mechanism | Legal/compliance | Yes |
| P6 | Confidentiality, security commitments and certifications offered | Contract; security documentation | Security + legal | Yes |
| P7 | Breach/incident notification obligations and timelines | Contract | Legal/compliance | Yes |
| P8 | Account/tenant isolation of our data from other customers | Provider documentation | Security | Yes |
| P9 | What the provider itself logs (request metadata, content) and for how long | Provider documentation | Security + legal | Yes |
| P10 | Data-processing agreement or equivalent terms available and executed | Signed agreement reference | Legal/compliance | Yes |
| P11 | Which data tiers (section 4) this provider is approved for; whether Highly Sensitive / children's data is permitted at all | Recorded decision (section 19) | Legal/compliance + counsel for Student data | Yes |
| P12 | Model/deployment option and rate/cost limits acceptable | Product decision | Product owner | Yes for enablement |

A "Yes" in *Blocking?* means integration with that provider may not
proceed until the row carries recorded evidence.

## 6. Data-minimization contract for the first real provider

- **Minimum necessary context**: a prompt contains only what the
  invoked tool contract returns for the current task, never a broad
  record dump (`AI-SECURITY.md`, "Data exposure controls").
- **System prompts**: versioned templates in the Prompt Registry
  (ADR 0013), reviewed like code; no School data embedded in templates.
- **User prompts / free text**: treated as untrusted input (section 16)
  and as carrying whatever personal data it contains.
- **Tool descriptions**: static, reviewed text; no data.
- **Identifiers**: opaque internal ids by default; names, contact
  details, government identifiers, payroll/financial values and document
  excerpts are each sent **only if** P11 approves that category for that
  provider.
- **Conversation history**: not retained or re-sent beyond one
  interaction unless a product decision (18B) and retention decision
  (18A) allow it.
- **Structured source data**: the tool contract's minimal DTO, never an
  Eloquent model serialization (the same rule as webhook payloads,
  CLAUDE.md rule 44).

## 7. Classification of AI artifacts

Prompts, context, tool arguments, tool results, model outputs and AI
audit metadata **inherit the strictest tier of the data they contain or
were derived from**; transformation into prose never lowers it. A model
output can itself contain Highly Sensitive data (e.g. a drafted fee
reminder naming a Guardian and an amount) and must be handled at that
tier. Provider request metadata (request id, token counts, latency,
model name) carries no content and is Internal.

## 8. Logging and observability contract

Logs and error reports may carry (Laravel's
`App\Support\Observability\LogSanitizer` is the backstop there; the AI
Gateway has **no** equivalent redaction layer yet, although
`DATA-CLASSIFICATION.md` refers to `redact` conventions in
`services/ai/app/core/config.py` that do not exist — adding one belongs
to 18C): School id, actor id, agent name,
tool name, capability, provider name, model name, provider request id,
latency, token counts, outcome code, correlation/trace ids. They must
**never** carry: prompts, context, model outputs, tool arguments or
results, HTTP request/response bodies to or from the provider or Laravel,
provider error bodies, API keys or tokens (including context tokens).
Metrics labels carry none of the high-cardinality ids (CLAUDE.md
rule 63). Gap G3 was closed on 2026-09-24 (section 3); a general
redaction layer on the gateway is still not built.

## 9. Secrets and provider credentials

Per ADR 0016 and CLAUDE.md rule 12: provider API keys live only in the
AI Gateway's runtime environment (never in Laravel, never in the
repository); `.env.example` gets an inert placeholder only in the
implementation phase; local/DDEV/CI use `NullProvider` and no real key;
production keys go through the secrets manager / platform secret
injection ADR 0016 requires before production; rotation procedure and
owner are part of 18C. No key is added by this checkpoint.

## 10. Authorization, tenancy and approval model

- **Who invokes**: a signed-in human in one School, holding the
  capability the tool requires; `AiGatewayClient` checks it with
  `CapabilityResolver` **before** minting the context token (ADR 0023).
  Which roles hold AI capabilities is a product decision (18B); none is
  seeded here.
- **Whose authority**: always the invoking human's, intersected with the
  agent's granted capability set (ADR 0014). The AI has no independent
  authority; the gateway's service identity authenticates the process
  only (`VerifyAiGatewayServiceToken`) and grants no School action.
- **Tenancy**: one School per interaction, bound in the context token;
  normal School context and RLS on Laravel's side; no cross-School
  context, no `BYPASSRLS`, no implicit Platform Super Admin AI access.
  Cross-School or platform AI needs its own ADR.
- **Configuration grants nothing** — the principle Automation (ADR 0043)
  already applies: enabling an agent or feature never widens the
  invoking human's authority.

| Action category | AI may propose? | AI may execute? | Human approval required? |
|---|---|---|---|
| Read-only answer from an approved tool's data | — | Yes, within the invoker's capability | No |
| Draft (text the human may edit/discard; nothing saved or sent) | Yes | — | The human decides what to do with it |
| Reversible internal write through a tool contract | Yes | Only if that action type is separately approved (18B) | Per action type (ADR 0014: "may be revisited per action type") |
| Financial action (charge, waiver, payment, payroll, posting) | Yes | **No** | Yes — always (ADR 0014) |
| Irreversible action (delete, archive, external send) | Yes | **No** | Yes — always (ADR 0014) |
| Sending an external communication | Yes (draft) | **No** | Yes; delivery still goes through Communications' own authorization, consent and approval |

## 11. Tool boundary

Every AI tool calls one explicitly exposed Laravel "AI tool contract"
endpoint under `/api/internal/ai/...` that verifies the service token
and the context token's capability claim, sets `TenantContext`, calls
the **owning module's Application-layer contract**, audits, and clears
context in `finally` (`AiToolController` is the pattern). Tools never
write tables, never bypass RLS, capabilities, source validation,
Automation restrictions or approval/legal gates, and never gain a second
authorization system — the context token plus the source capability is
the model.

## 12. Storage and retention of AI artifacts

The repository plans none of: stored prompts, responses, conversation
history or tool results. Durable AI audit exists as School audit events
with identifiers only. Default for Phase 0M: **do not store raw
prompt/response bodies**. Storing any of them, and for how long, is a
retention decision **[LEGAL/POLICY REVIEW REQUIRED]** (18A) — no period
is set here. Provider request ids and usage metrics (Internal) may be
kept with the audit trail.

## 13. Audit requirements

Every real-agent interaction must produce School audit events (ADR 0017,
identifiers and codes only — no prompts or outputs) recording: invoking
human, School, agent, tool, capability exercised, provider and model,
proposal made, approval requested / granted / refused (and by whom),
write executed (by the source module's own audit), and outcome. Model
calls are written through durably since the G2 hardening (section 3).

## 14. Relationship to Automation, Analytics and Compliance

- **Automation (ADR 0043)**: AI does not author, enable, disable or
  trigger rules; there is no AI tool contract for Automation; an agent
  gains no authority through Automation (rules run as their accountable
  human owner).
- **Analytics (ADR 0040)**: AI must not query raw data to evade the
  person-cohort gate, the Student-data counsel gate or the single-School
  limit; any Analytics input comes through `AnalyticsReadGate` as the
  invoking human.
- **Compliance (ADR 0042)**: AI never declares a School compliant,
  invents obligations, overrides retention/legal-hold decisions or
  mutates evidence; any Compliance input comes through its read
  contracts and source authorization.

## 15. First-provider enablement posture

Real provider **off by default** in every environment; enabled per
environment by configuration held outside the repository and per School
by an explicit opt-in (mechanism decided in 18B/18C; the existing
`FeatureFlagResolver` is available); `NullProvider` remains the default
for local, DDEV and CI.

## 16. Prompt-injection and tool-safety requirements

From `AI-SECURITY.md` (the boundary is enforced checks, never prompt
instructions): all user text, document content and tool results are
untrusted input; only allowlisted tools per agent (`ToolRegistry`
capability check) plus Laravel's context-token check; tool arguments
validated by the Laravel contract exactly like any request; model output
never executed or trusted as authorization; anything financial or
irreversible goes through human approval (section 10); outputs checked
against the expected shape before use.

## 17. Provider failure behavior

Unavailable, timeout, rate limit, invalid or malformed response, safety
refusal: the interaction fails cleanly with a stable error code and no
source-domain change (writes happen only in Laravel, after approval,
inside the owning module's transaction). The gateway stays an optional
subsystem (`Degraded`, never `Unhealthy`; CLAUDE.md rule 56). Bounded
timeouts as today (5 s); if asynchronous AI work is introduced, it
follows the existing job rules (explicit `$tries`/`$timeout`, one retry
owner, lease claims — CLAUDE.md rules 48, 58, 59). No retry design is
made here.

## 18. Decisions required to unblock Phase 0M

### 18A. Provider / legal

| Decision | Options / evidence | Approver | Blocks what? |
|---|---|---|---|
| Provider candidate approved | Section 5 matrix completed for a named provider | Legal/compliance | Any real-provider integration |
| Provider retention | P1, P5 | Legal/compliance | Same |
| Training / service-improvement use | P2 | Legal/compliance | Same |
| Human review by provider staff | P3 | Legal/compliance | Same |
| Regions, subprocessors, cross-border processing | P4 | Legal/compliance | Same |
| Contractual terms (DPA, confidentiality, breach) | P6, P7, P10 | Legal/compliance | Same |
| Data categories allowed per provider | P11 against section 4 | Legal/compliance | Any School data in a prompt |
| Whether Highly Sensitive / children's data may be sent at all | P11; `DATA-CLASSIFICATION.md` children's-data row requires counsel for Student data | Qualified counsel | Any Student, Guardian-with-child, financial or identifier data in a prompt |
| Retention of stored AI artifacts (if any are stored) | Section 12 | Legal/policy | Storing prompts/outputs/history |

### 18B. Product

| Decision | Options / evidence | Approver | Blocks what? |
|---|---|---|---|
| First agent use case | Section 19 candidates; roadmap's fee-reminder example | Product owner | First agent |
| First tool and write scope | Read-only first, or the roadmap's write tool | Product owner | First tool |
| Human-approval workflow for the first write | ADR 0014 approval step; who approves, where | Product owner + security | Any write tool |
| Who may invoke AI (capabilities, roles) | New capabilities, e.g. per agent | Product owner | Any user-facing AI |
| Per-School opt-in and default | Flag default off, as for Automation | Product owner | Enablement |
| Prompt/output storage need | Section 12 default: none | Product owner (then 18A retention) | Storage |

### 18C. Security / architecture

| Decision | Options / evidence | Approver | Blocks what? |
|---|---|---|---|
| Confirm authority model (invoking human ∩ agent capabilities, context token) | Section 10; ADR 0014/0023 | Security | Real agent |
| ~~Close G1~~ — **done 2026-09-24** (engineering; section 3) | Section 3 | Security | — |
| ~~Close G2~~ — **done 2026-09-24** | Section 13 | Security | — |
| ~~Close G3~~ — **done 2026-09-24** | Section 8 | Security | — |
| ~~Provider enable switch, off by default (G4)~~ — **done 2026-09-24**; turning it on still needs the approvals | Section 15 | Security + product | — |
| Provider-key custody and rotation | Section 9; ADR 0016 | Security | Any environment with a real key |
| Prompt Registry / output validation for the first agent | Sections 6, 16 | Security | Real agent |

## 19. First implementation candidates (after the gate; not ranked)

Each is School-scoped, read-only and involves no financial or
irreversible action. Each still needs the provider approved for its data
tier.

| Candidate | Source contract | Data tier | Notes |
|---|---|---|---|
| Explain a School's Curriculum Coverage report | Existing: `AnalyticsReadGate` + `CurriculumCoverageReadModel` over `App\Domain\CurriculumDelivery\Application\CurriculumCoverageReadService` (as the invoking human, `analytics.view`) | Confidential, no person data | The only candidate with a stable read contract today; reviewable in DDEV |
| Summarize a Subject Offering's syllabus units | **None yet** — the Syllabus module has no Application-layer read contract; it would add one under its own rules | Confidential | No person data |
| Answer questions about a School's academic set-up (years, terms, sections, offerings) | **None yet** — Academic Structure exposes write services and `CurrentAcademicYearResolver`, not a read contract suited to a tool | Confidential / Internal | No person data |

The roadmap's likely first *write* tool — fee-reminder drafting — would
send Financial (Highly Sensitive) and Guardian (Sensitive) data and needs
the human-approval workflow; it is a later candidate gated on every 18A
row and 18B's approval decision.

## 20. Required form of approval

Each 18A decision must be a **named, dated, jurisdiction-specific
recorded decision**, comparable to the ADR 0036 legal clearance and the
StudentMark determination, identifying: reviewing authority/counsel;
jurisdiction reviewed; date; the provider, account type and model
options reviewed; data categories approved and prohibited; retention,
training-use, human-review, region/subprocessor and deletion terms
accepted; required technical controls; and any conditions. 18B and 18C
decisions are recorded with owner and date. Engineering records the
result as an addendum to this document or a dedicated ADR before any
real-provider work begins.

**Until those records exist, this gate stays BLOCKED: no provider SDK is
added, no real provider is registered in `ModelRouter`, no School data
leaves the platform, and no real agent or AI write tool is built.**

## 21. Status

**BLOCKED — LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS REQUIRED.**
Unresolved blocking decisions: every row of 18A, the first-agent,
first-tool, approval-workflow, invoker-capability and opt-in rows of
18B, and the open rows of 18C (authority-model confirmation,
provider-key custody and rotation, Prompt Registry and output
validation). The provider-neutral hardening G1–G4 is complete
(2026-09-24); it does not unblock Phase 0M, which is about a real
provider and a real agent.

**Addendum (2026-09-29, ADR 0061).** Post-v1 product deferral. The BLOCKED
status above remains the state of this gate. Phase 0M is closed for Phase
Zero **only** as a scope decision. Reopening requires this gate's section
18 decisions (ADR 0061 §3.4–§3.5).
