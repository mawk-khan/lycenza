# School OS — Master Roadmap

This roadmap sequences future phases by dependency, not by business
priority alone — a module cannot be built correctly before what it
depends on exists (`docs/architecture/DOMAIN-MAP.md`). Every phase
after 0B is **not started** as of this document; nothing here is a
commitment to timing, only to order and scope.

## Phase 0A — Architectural Foundation (complete)

Repository structure, ADRs, domain map, tenancy/API/event/AI/security
design docs, local dev environment, CI foundation, and tiny primitives
proving the stack (Laravel+Inertia+Vue+TS request chain, versioned API
+ error envelope, AI Gateway + capability-gated tool authorization,
contract → generated-types pipeline). No business module implemented.

## Phase 0B — Identity, Access, and Tenancy (complete)

The one phase every later module structurally depends on
(`docs/architecture/DOMAIN-MAP.md` Layer 0) — this checkpoint made
Phase 0A's tenancy/authorization design **real**, not just documented:

- Real tenant model: School/Campus/Group entities (`schools`,
  `campuses`, `school_groups`, `school_group_members`,
  `school_domains`), a real tenant-resolution middleware chain
  (`ResolveSchoolContext`), real PostgreSQL RLS policies (enabled and
  forced on every tenant-owned table), and real tenant-aware queue/
  cache/storage/log/AI plumbing — see `docs/architecture/TENANCY.md`
  and ADR 0004, ADR 0020–0024.
- Real Identity & Access: `users` (central identity, deliberately
  narrow — see `docs/security/AUTHORIZATION.md`), a capability catalog,
  system-defined roles (`school_admin`, `principal`,
  `platform_super_admin`), and `CapabilityResolver` as the one
  authoritative resolution service — capabilities, not hard-coded role
  checks, enforced via a Gate + route middleware + a controller trait,
  with allow *and* deny authorization tests from the first commit.
- Platform Super Admin bootstrap (`platform_role_assignments`, DB-
  trigger-enforced scope separation from School roles) — the
  foundation only; no "enter a School's context as platform admin"
  elevation workflow yet (deliberately, per the checkpoint's brief: no
  invisible cross-tenant bypass).
- Durable, RLS-protected, database-privilege-enforced append-only audit
  (`platform_audit_events`, `school_audit_events`) — see ADR 0017's
  Phase 0B update.
- A minimal login/dashboard/school-switch/settings Inertia UI and one
  Sanctum-authenticated API endpoint, proving the web and mobile
  authentication chains actually work, not just the data model.
- The Laravel↔AI Gateway boundary strengthened with signed context
  tokens (ADR 0023), proven with a live, unmocked, cross-container
  round trip in addition to the automated test suite.

95 Laravel tests + 10 Python tests, all passing against real
PostgreSQL (ADR 0024) — see the Phase 0B Final Report for the full
verification record, including the PostgreSQL isolation proof and
security review.

## Phase 0C — Reliability & Integration Substrate (in progress)

Re-sequenced ahead of the originally-planned "Organizational Structure"
phase (now Phase 0D, below): every later business module needs to
reliably trigger notifications, integrations, automation, analytics,
and AI without losing work, duplicating dangerous side effects,
crossing tenants, or tightly coupling to external infrastructure —
retrofitting that substrate after several modules already exist would
be far more expensive than building it first, the same reasoning that
put Phase 0B ahead of every business module. Landing in checkpoints:

- **Phase 0C (core substrate, in progress):** durable domain events +
  transactional outbox (ADR 0025), event-consumer idempotency
  (`EventConsumerReceipt`), reliable queued dispatch (`SKIP LOCKED`
  outbox dispatcher), notification infrastructure (fake/local
  providers only), webhook infrastructure (HMAC signing, SSRF
  protection, retry/dead-letter), service identities distinct from
  User, feature flags, School settings foundation, and the AI Gateway
  durable-audit write-back closing the Phase 0B audit debt. Three
  required end-to-end proofs completed: Proof A (event → outbox →
  dispatcher → consumer → idempotency receipt → audit), Proof B (event
  → webhook → real local HTTP delivery → HMAC verification → no
  duplicate on replay), Proof C (Laravel → signed context → FastAPI →
  authorized operation → durable audit returned to Laravel, live
  cross-container, plus all four required denial scenarios).
- **Phase 0C.2 — API Idempotency Foundation (complete):** a
  reusable, opt-in `idempotent` route middleware
  (`App\Http\Middleware\EnsureIdempotent`) plus
  `App\Support\Idempotency\IdempotencyGuard`, giving any future
  consequential mutation a School/actor/route-scoped
  `Idempotency-Key` contract backed by real PostgreSQL uniqueness —
  see `docs/architecture/RELIABILITY.md` ("API idempotency") for the
  full design, guarantees, and documented limits.
- **Phase 0C.3 — Webhook & External Integration Delivery Foundation
  (complete):** the production-grade webhook subsystem — four distinct
  tenant-owned/RLS-protected tables (endpoint, subscription, delivery,
  attempt), an externally-publishable event registry
  (`App\Support\Webhooks\WebhookEventRegistry`), secure secret
  generation/rotation/one-time display, HMAC-SHA256 signing with
  timestamp replay protection (ADR 0026), re-validated-per-attempt SSRF
  protection with IP pinning (ADR 0027), a lease-based concurrency-safe
  delivery/retry state machine (`App\Jobs\DeliverWebhookJob`,
  `App\Console\Commands\RedispatchDueWebhookDeliveries`), capability-
  gated management API (`integrations.webhooks.view`/`.manage`,
  idempotency-key-protected mutations), and manual redelivery. Real
  local cross-container proof covers 2xx/500-retry-recovery/429-
  Retry-After/404-permanent/timeout/redirect-not-followed/tampered-
  signature/duplicate-event, plus a genuine two-OS-process concurrency
  proof. Full design: `docs/architecture/INTEGRATIONS.md`,
  `docs/security/INTEGRATION-SECURITY.md`.

- **Phase 0C.3A — Test-Database Safety & Transactional-Outbox
  Architecture Closure (complete):** fail-closed `TestDatabaseGuard`
  (`App\Support\Testing\TestDatabaseGuard`) preventing a testing
  command from ever silently resolving to the development database;
  `platform:test-db-reset` / `composer test:reset-db`; ADR 0025
  formally documenting the transactional-outbox pattern already in use
  since Phase 0C's core substrate.
- **Phase 0C.4 — Health, Scheduler, Queue Operations & Observability
  Completion (complete):** Laravel (`/api/health/live`,
  `/api/health/ready`) and FastAPI (`/health/live`, `/health/ready`)
  liveness/readiness; scheduler and queue heartbeat/staleness
  detection reusing the existing `scheduler_heartbeats` table
  (`App\Support\Observability\SchedulerHeartbeatRecorder`); a queue
  health model that never reports an idle queue as stalled
  (`App\Support\Observability\OperationalStatusService::queues()`);
  read-only failed-job visibility
  (`App\Support\Observability\FailedJobInspector`,
  `php artisan platform:failed-jobs`); documented job timeout/
  retry_after invariants; a tenant-aware named rate-limiter foundation
  (`App\Providers\RateLimiterServiceProvider`) — including a real bug
  this checkpoint's own tests caught and fixed in how those limiters
  key School-scoped routes (see `docs/architecture/RELIABILITY.md`);
  structured-logging sanitization, an error-reporter abstraction, a
  metrics abstraction with high-cardinality safety rules, and a W3C
  Trace-Context-compatible tracing abstraction spanning Laravel and
  the AI Gateway (ADR 0015's documented fallback, not a full
  OpenTelemetry SDK); authenticated cross-tenant internal diagnostics
  (`GET /api/internal/operations/status`, `platform:operations-status`
  CLI). Full design: `docs/architecture/OBSERVABILITY.md`.

Remaining Phase 0C work not yet started as of the 0C.4 checkpoint:
webhook delivery/attempt retention pruning, and the full core-substrate
closeout report consolidating 0C/0C.2/0C.3/0C.3A/0C.4 into one
checkpoint record.

## Phase 0D — Organizational & Academic Structure Foundation (complete)

The first real School ERP domain checkpoint — Schools, Campuses,
Academic Structure (Academic Years/Terms, Grade Levels, Sections,
Subjects, Academic Departments, Rooms, Subject Offerings, and a
platform Education Board catalog) — Layer 1 reference data nearly
every later module reads. (Originally sequenced as "Phase 0C" before
the reliability substrate was moved ahead of it — see above.)

School → Campus → Academic Year → Grade → Section → Subjects is fully
configurable via API and a minimal Inertia UI, with real PostgreSQL
RLS isolation on every School-owned table, composite foreign keys
structurally preventing any cross-School parent reference, a
database-enforced single-active-Academic-Year invariant proven safe
under genuine concurrent activation (two real OS processes racing),
and Section/SubjectOffering historical-safety (AcademicYear-scoped,
never mutated/reused across years) so future Students/SIS, Admissions,
Attendance, Exams, Timetable, and Finance modules can reference this
structure without a redesign. `app/Domain/AcademicStructure/*` is the
first module using the `Domain/Application/Infrastructure/Http`
layout `apps/platform/app/Domain/README.md` reserved for exactly this.
Full design: `docs/modules/ORGANIZATION.md`,
`docs/modules/ACADEMIC-STRUCTURE.md`.

Deliberately deferred (not started): a dedicated UI screen for Academic
Terms, Sections, Academic Departments, Rooms, and Subject Offerings
(the API/backend for all of these is complete and tested; only the
Inertia page is pending — the existing pages follow an identical,
quick-to-replicate pattern).

## Phase 0E — Cross-Cutting Infrastructure

Documents (ADR 0012) and Communications — built early, deliberately,
because Layer 2–3 modules depend on them and retrofitting a shared
Documents/Communications module after several other modules have
already invented their own file-handling or notification logic is
expensive to unwind. Communications now builds directly on Phase 0C's
notification infrastructure rather than inventing its own.

## Phase 0F — People

Students/SIS, Guardians, Admissions. First real domain events
(`StudentAdmitted`, `GuardianLinked`, `AdmissionLeadCreated` —
`docs/architecture/EVENTS.md`) get real producers here, flowing through
the Phase 0C outbox/consumer substrate rather than a bespoke mechanism.

## Phase 0G — Finance and Fees

Finance (core ledger), Fees, Payments — including the first real
payment-gateway integration and inbound-webhook idempotency (ADR 0018,
extended by Phase 0C's webhook infrastructure and Phase 0C.2's
documented payment-readiness distinction between client API idempotency
and payment-provider/webhook idempotency), built against the
financial-correctness rules in `docs/architecture/ARCHITECTURE.md` §10
from day one. This is a security- and correctness-critical phase;
expect the heaviest testing and review bar of any phase so far.

## Phase 0H — Academic Operations

Attendance, Timetable, Academics, Examinations.

## Phase 0I — LMS

## Phase 0J — HR and Payroll

Including the **[LEGAL REVIEW REQUIRED]** statutory-compliance
questions flagged in `docs/security/DATA-CLASSIFICATION.md` (PF/ESI/
TDS and similar) — Compliance module involvement expected here, not
deferred.

## Phase 0K — Operational Modules

Transport, Library, Inventory, Canteen, Hostel, Health, Visitor/Safety
— roughly independent of each other, sequenced by product priority once
reached, not strict dependency order.

## Phase 0L — Oversight

Compliance, Analytics, Automation (Layer 5) — read-mostly consumers of
everything built so far; deliberately sequenced after there's
meaningful data/events for them to work with.

## Phase 0M — AI Platform: Real Agents

First real model-provider integration (ADR 0013, with the
**[LEGAL/COMPLIANCE REVIEW REQUIRED]** provider data-handling review
from `docs/security/DATA-CLASSIFICATION.md` completed first), first
real agent and tool with an actual Laravel-side *write* effect (most
likely fee-reminder drafting, given the worked example in
`docs/ai/AI-SECURITY.md` — Phase 0B's `school.echo` tool is read-only
by design), first real human-approval workflow for financial/
irreversible actions. AI Gateway-side durable audit is no longer an
open item here -- Phase 0C's audit write-back already closed it.

## Phase 0N — Multi-School Management

Group/Trust cross-school administration and reporting, building on
Phase 0B's explicit-elevation model (ADR 0004) and its structural
foundation (`school_groups`, `school_group_members`) — the actual
"enter a member School's context as a group/platform admin" workflow is
still unbuilt after Phase 0B, deliberately (see that phase's own
summary above).

## Phase 0O — External Surface and Production Readiness

Public developer API hardening (rate limiting, partner API keys,
building on Phase 0C.2's documented rate-limit/idempotency
interaction), a real observability backend (ADR 0015, building on
whatever instrumentation-only foundation Phase 0C's core substrate
lands), production secrets/infrastructure (ADR 0016,
`infrastructure/terraform`), and broader third-party integrations
(ADR 0018) beyond the payment gateway from Phase 0G.

## Cross-cutting, ongoing (not a single phase)

- Data classification and authorization reviews (root `CLAUDE.md`) on
  every module that touches Sensitive/Highly Sensitive data.
- Security regression tests added alongside every fix
  (`docs/architecture/ARCHITECTURE.md` §11).
- ADRs added/updated whenever a phase makes a decision this roadmap
  didn't anticipate.

## Explicit stop gates that apply to every phase

No phase — including Phase 0C onward — deploys to production, provisions
cloud resources, purchases services, configures production secrets,
sends external communications, or connects real school data without
separate, explicit authorization at that time. Each phase's own kickoff
should restate this, not assume it carries over silently.
