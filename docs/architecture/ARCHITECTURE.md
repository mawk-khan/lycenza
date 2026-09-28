# School OS — Architecture Overview

Status: Phase 0B (identity, tenancy, and authorization made real).
No business modules (SIS, Admissions, Fees, Academics, Attendance, HR,
Transport, ...) are implemented yet. Phase 0A established the
architectural foundation (repo structure, ADRs, design docs); Phase 0B
built real School/Campus/Identity/membership/capability data models,
enabled PostgreSQL Row-Level Security for real, and proved tenant
isolation against real Postgres — see
`docs/architecture/TENANCY.md`, `docs/security/AUTHORIZATION.md`, and
ADRs 0019–0024. This document describes the foundation future business
modules will be built on.

For the reasoning behind each decision below, see the ADRs in
`docs/architecture/adr/`. This document is the map; the ADRs are the
territory.

## 1. System shape

```
                        ┌─────────────────────┐
                        │   Flutter mobile     │  apps/mobile
                        │  (parents, teachers,  │
                        │   students)           │
                        └──────────┬───────────┘
                                   │ HTTPS, versioned REST (/api/v1)
                                   │
┌──────────────┐   Inertia    ┌────▼─────────────────────┐
│  Vue 3 + TS   │◄────────────┤   Laravel (PHP 8.3+)      │
│  web console  │  server-    │   apps/platform            │
│ (in-process)  │  driven     │                            │
└──────────────┘              │  Authoritative system of  │
                               │  record — see ADR 0002    │
        3rd-party integrations│                            │
        (webhooks, /api/v1) ◄─┤  Owns: business rules,    │
                               │  transactions, authz,      │
                               │  tenancy, financial/       │
                               │  student/compliance state, │
                               │  audit                     │
                               └──────────┬─────────────────┘
                                          │ authenticated HTTP only
                                          │ (never direct DB access)
                               ┌──────────▼─────────────────┐
                               │  AI Gateway (Python/FastAPI)│
                               │  services/ai                │
                               │                              │
                               │  Provider-independent;      │
                               │  capability-gated tools;    │
                               │  never writes ERP tables    │
                               │  directly — see ADR 0013,   │
                               │  0014                        │
                               └──────────┬─────────────────┘
                                          │
                          ┌───────────────┴────────────────┐
                          │  Model providers (pluggable)     │
                          │  Anthropic / OpenAI / Google /   │
                          │  Azure OpenAI / local models     │
                          └───────────────────────────────────┘

Shared infrastructure: PostgreSQL (ADR 0003) · Redis/Valkey (ADR 0006)
· S3-compatible object storage (ADR 0011) · OpenTelemetry-compatible
observability (ADR 0015)

Shared contracts: packages/contracts (OpenAPI + event schemas)
→ packages/shared-types (generated TypeScript)
```

## 2. Fundamental architecture rule

**Laravel is the single authoritative system of record.** It owns
business rules, transactions, authorization, tenancy, domain workflows,
financial state, student state, compliance state, and auditability.
See ADR 0002.

**Python/FastAPI provides intelligence, never authority.** AI agents
may invoke explicitly exposed ERP tools/actions through authenticated
application contracts, gated by capability (ADR 0014). AI must not
bypass Laravel domain services, must not write directly to application
tables, and must never hold ERP database credentials. See ADR 0013,
0014.

This rule is not a style preference — it is the load-bearing security
and correctness property of the whole system, and every future module
and every future AI capability must be reviewed against it.

## 3. Deployment shape

One modular monolith (`apps/platform`), one separate AI service
(`services/ai`), one web client embedded in the monolith (Inertia), one
independently built mobile client (`apps/mobile`). See ADR 0001 for why
this shape was chosen over microservices, and its extraction path if a
specific module later needs to be pulled out.

## 4. Module boundaries

See `docs/architecture/DOMAIN-MAP.md` for the full bounded-context map
and dependency directions, and `apps/platform/app/Domain/README.md` for
the concrete code-layout convention (`Domain/Application/
Infrastructure/Http` per module) every future module must follow.

## 5. Tenancy

See `docs/architecture/TENANCY.md` and ADR 0004, ADR 0020–0022, ADR
0024. Summary: School is the tenant boundary; Campus is a sub-tenant
dimension; School Group/Trust is an explicit, audited cross-tenant
elevation, never a default. Isolation is enforced at three layers
(application scope via `SchoolScope`, Postgres RLS via `TenantRls`, and
tenant-aware queue/cache/storage/log/AI infrastructure) — **implemented
and proven against real Postgres in Phase 0B**, not only designed.

## 6. API surface

See `docs/architecture/API.md` and ADR 0009. Summary: versioned REST
(`/api/v1`) described by OpenAPI, consumed by mobile, third-party
integrations, and (optionally) the web console; the Inertia console
itself is mostly server-driven and doesn't need to go through this
surface for its own rendering.

## 7. Domain events

See `docs/architecture/EVENTS.md` and ADR 0010. Summary: in-process
Laravel events with queued listeners, no distributed broker yet, a
fixed envelope shape shared with `packages/contracts/events/`.

## 8. AI platform

See `docs/ai/AI-PLATFORM.md`, `docs/ai/AI-SECURITY.md`, ADR 0013, ADR
0014, ADR 0023. Summary: provider-independent gateway, capability-gated
tool invocation (double-gated: the AI Gateway's own agent-capability
check, plus Laravel's cryptographically signed context-token check),
mandatory audit, human approval for irreversible/financial actions (no
tool needing this exists yet). Proven end to end in Phase 0B, including
a live cross-container round trip — see `docs/ai/AI-PLATFORM.md`.

## 9. Security and data classification

See `docs/security/DATA-CLASSIFICATION.md` and
`docs/security/AUTHORIZATION.md`. Summary: a five-tier classification
model (Public → Highly Sensitive) covering student/guardian/employee/
financial/health data; capability-based (not hard-coded role-based)
authorization for both humans and AI agents.

## 10. Financial correctness rules

These rules apply to every future module that touches money (Fees,
Payments, Payroll, Inventory costing, etc.) — no financial module is
implemented in Phase 0A, but the rules are fixed now so no future module
is built against a different assumption:

1. **No floating-point money, ever.** All monetary values use exact
   decimal representation (Postgres `NUMERIC`, ADR 0003; PHP's
   `bcmath`/a dedicated Money value object — never `float`/`double` for
   currency).
2. **Amounts are stored with an explicit currency** — even though INR
   is the only currency in scope today, no monetary column or value
   object may assume a currency implicitly.
3. **Financial history is immutable and auditable where required by
   the domain**: a posted transaction is not edited in place; a
   correction is a new, explicit, linked adjustment/reversal record
   (see ADR 0017's audit architecture — this is the financial instance
   of that general principle).
4. **Refunds, waivers, and reversals are explicit domain operations**
   with their own authorization requirements — never a mutation of the
   original record's amount.
5. **Payment callbacks (webhooks) are idempotent by construction** —
   see ADR 0018. A duplicate gateway callback must never double-record
   a payment. This is a **separate** mechanism from the client-facing
   `Idempotency-Key` API contract (`docs/architecture/RELIABILITY.md`)
   — a payment callback needs its own idempotent-handling contract
   keyed on the provider's own delivery/transaction identifiers, not
   a client-supplied header. The existing Payments foundation already
   keys provider events on `(school_id, provider, provider_event_id)`
   (ADR 0031). A future real payment gateway — deferred from Phase 0 by
   ADR 0057 — still needs both, plus its own callback contract.
6. **Reconciliation is a first-class concern**: every module that
   records a payment must be designed, from the start, to support
   matching internal records against a payment gateway's/bank's
   external record — not bolted on after the fact.
7. **Authorization is complete before execution**: no financial
   action executes speculatively "pending" approval when the domain
   requires prior authorization — see `docs/security/AUTHORIZATION.md`
   and ADR 0014 (which applies the same principle to AI-initiated
   financial actions specifically).

No financial module is implemented against these rules yet — they exist
so the first one (most likely Fees/Payments) is built correctly from
its first commit.

## 11. Testing strategy

See root `CLAUDE.md` for the mandatory testing rules. Layers required
across the system as modules are built:

| Layer | Scope | Example |
|---|---|---|
| Unit | Pure domain logic, no framework/DB | A Money value object's arithmetic |
| Domain | Domain services against in-memory/fake infrastructure | An admission-to-enrollment workflow |
| Feature | Laravel HTTP layer, real (test) DB | `SchoolSettingsTest`, `SchoolSwitchTest`, `LoginTest` (Phase 0B) |
| API | Versioned `/api/v1` contract conformance | `SchoolContextTest` (Sanctum chain, Phase 0B) |
| Authorization | Every protected action, allow *and* deny cases | `CapabilityResolverTest`, `RoleScopeTriggerTest` (Phase 0B) |
| Tenancy isolation | Cross-tenant access is impossible, not just "not attempted" | `tests/Feature/Postgres/RawIsolationTest.php` — real Postgres, RLS blocks a query even with the Eloquent scope removed (Phase 0B) |
| Integration | Real Postgres/Redis/MinIO, not mocks, for anything DB/queue/storage-shaped | `QueueContextPropagationTest` (Phase 0B) |
| Concurrency | Genuinely parallel requests/processes, not sequential simulation, for anything claiming a database-level race guarantee | `IdempotencyRealConcurrencyTest` — real separate `curl` processes against a real `php -S` server subprocess (Phase 0C.2) |
| Contract | `packages/shared-types` generation matches `packages/contracts/openapi` | `shared-types` CI job's drift check |
| AI tool permissions | Capability grant/deny for every tool | `services/ai/tests/`, `AiGatewayClientTest`, `AiToolControllerTest` (Phase 0B) |
| Security regression | A fixed vulnerability never regresses | Added alongside any security fix |
| Mobile integration | Flutter app against a real/staging API | Not yet exercised — Flutter SDK unverified in this checkpoint |
| End-to-end | A full user journey across web/API/AI where relevant | Introduced once the first real workflow exists |

## 12. Observability

See ADR 0015 and, for the full Phase 0C.4 implementation, `docs/architecture/OBSERVABILITY.md`:
liveness/readiness endpoints (Laravel + FastAPI), scheduler/queue
heartbeat and staleness detection, failed-job visibility, a
W3C-Trace-Context-compatible tracing abstraction (deliberately not a
full OpenTelemetry SDK — ADR 0015 anticipated exactly this tradeoff),
structured logging with sanitization, an error-reporter abstraction,
and a metrics abstraction with high-cardinality safety rules. No
commercial monitoring/tracing/metrics backend is chosen or deployed.
The request-id correlation convention (`AssignRequestId` middleware,
echoed in both success and error JSON envelopes) is the Phase 0A
primitive all of this builds on.

## 13. What Phase 0B deliberately does not include

No SIS, Admissions, Fees, Payments, Academics, Attendance, Timetable,
Examinations, LMS, HR, Payroll, Transport, Library, Inventory, Canteen,
Hostel, Health, Compliance integrations, or customer-facing AI agents.
Phase 0B's own scope is deliberately narrow too: Identity, School
tenancy, Campus, capability-based authorization, tenant context
propagation, and durable audit — see `docs/roadmap/MASTER-ROADMAP.md`
for what Phase 0C builds on top of this.

## 14. Organization & Academic Structure (Phase 0D)

The first genuine business/domain module —
`app/Domain/AcademicStructure/*`, following the layout
`apps/platform/app/Domain/README.md` reserved for exactly this. Owns
School profile administration, Campus administration, Academic Years/
Terms (with a database-enforced single-active-year invariant and a
proven real-concurrency-safe activation transition), School-wide Grade
Levels/Academic Departments/Subjects, Campus-scoped Rooms, and the
AcademicYear-specific Section and Subject-Offering layers. Full design:
`docs/modules/ORGANIZATION.md`, `docs/modules/ACADEMIC-STRUCTURE.md`.
Still explicitly out of scope: Students/SIS, Guardians, Admissions,
Fees, Attendance, Examinations, Timetable, HR — every module Layer 2-3
of `docs/architecture/DOMAIN-MAP.md` names above this one.

See `docs/roadmap/MASTER-ROADMAP.md` for what comes next and in what
order.
