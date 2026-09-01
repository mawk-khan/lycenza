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

## Phase 0E — Cross-Cutting Infrastructure (complete)

Documents (ADR 0012) and Communications — built early, deliberately,
because Layer 2–3 modules depend on them and retrofitting a shared
Documents/Communications module after several other modules have
already invented their own file-handling or notification logic is
expensive to unwind. Communications now builds directly on Phase 0C's
notification infrastructure rather than inventing its own.

**Communications is complete** (Phase 5A/5B, `docs/communication-hub/`).

**Documents is complete (Phase 0E.1–0E.7):** foundation schema, write
path, authorized read/content streaming, owner-scoped listing, and
HTTP/API transport are implemented and verified end-to-end against
isolated infrastructure (0E.7 closure — RLS, owner-integrity, real
MinIO clean-room, full regression, zero unresolved P0/P1/P2). The ADR
0028 `employee_documents` reconciliation obligation is discharged by
ADR 0029: the two tables stay permanently separate, no merge. See
`docs/modules/DOCUMENTS.md` for the full domain contract, the accepted
P3 residual, and what remains deferred as non-blocking future work
(Student/Guardian owner activation, signed URLs, retention policy,
malware scanning, checksum/integrity, orphan cleanup, storage quota,
UI) — none of these were closure-critical for the generic Documents
infrastructure this phase scoped.

**Both halves of Phase 0E (Documents and Communications) are complete.**
"Complete" here means this phase's own cross-cutting infrastructure
scope is closed, matching Phase 0D's precedent — it does not mean every
possible future enhancement to either module has been built; those are
tracked as each module's own deferred/future work, not as open Phase 0E
obligations.

## Phase 0F — People

Students/SIS, Guardians, Admissions. First real domain events
(`StudentAdmitted`, `GuardianLinked`, `AdmissionLeadCreated` —
`docs/architecture/EVENTS.md`) get real producers here, flowing through
the Phase 0C outbox/consumer substrate rather than a bespoke mechanism.

## Phase 0G — Finance and Fees (in progress)

Finance (core ledger), Fees, Payments — including the first real
payment-gateway integration and inbound-webhook idempotency (ADR 0018,
extended by Phase 0C's webhook infrastructure and Phase 0C.2's
documented payment-readiness distinction between client API idempotency
and payment-provider/webhook idempotency), built against the
financial-correctness rules in `docs/architecture/ARCHITECTURE.md` §10
from day one. This is a security- and correctness-critical phase;
expect the heaviest testing and review bar of any phase so far.

**0G.0 — Finance Architecture & Module Plan (implemented):**
architecture/domain-contract checkpoint, no code. Settled the one
decision `ARCHITECTURE.md` §10 left open — Finance is a true
double-entry ledger with a chart of accounts, not a subledger or a
mutable-balance charge/payment tracker (ADR 0030). Full domain
contract, checkpoint sequence (0G.1-0G.8), and security register:
`docs/modules/FINANCE.md`.

**0G.1 — Ledger Schema Foundation (implemented):** the persistence
kernel only — `ledger_accounts`, `journal_entries`, `journal_lines`
(RLS-protected, same-School+same-currency composite foreign keys,
`NUMERIC(14,2)` money, no float anywhere), the deferred
constraint-trigger enforcing "debits equal credits" per entry, the
structural (partial-unique-index-backed) reversal relationship proven
safe under real two-process concurrency, and the first-party
`App\Support\Money\Money` value object. Full as-built detail:
`docs/modules/FINANCE.md` ("0G.1 as-built").

**0G.2 — Ledger Posting & Reversal Application Services
(implemented):** `App\Domain\Finance\Application\LedgerService` — the
one sanctioned write path for posting and reversing journal entries
(`post()`/`reverse()`), inside one PostgreSQL transaction each,
audited (`App\Support\Audit\AuditRecorder`) and emitted through the
existing transactional outbox (ADR 0025) exactly once per successful
operation. Application-layer balance/currency/account validation sits
in front of, and never replaces, every 0G.1 database defense. Not an
authorization boundary — no capabilities, no HTTP, no UI. Zero new
migrations. Full as-built detail: `docs/modules/FINANCE.md` ("0G.2
as-built").

**0G.3 — Finance Authorization & Administrative Read Model
(implemented):** real `finance.ledger.view`/`.post`/`.reverse`
capabilities (`database/seeders/CapabilityAndRoleSeeder.php`, no data
migration — the existing sole capability/role catalog), granted by
default to `school_admin` only (not `principal`).
`App\Domain\Finance\Application\LedgerAdministrationService` is the
authorized administrative facade wrapping the still-unmodified,
still-unauthorized `LedgerService` trusted core; `App\Domain\Finance
\Application\LedgerReadService` is the sole authorized read path for
Ledger Accounts, journal history, and journal detail, returning only
typed DTOs (never a raw Eloquent model, never `posting_txid`). Every
successful read is audited (Highly Sensitive tier, unchanged from
`docs/modules/FINANCE.md`'s already-committed classification). Zero
new migrations, zero composer changes, no HTTP/API/UI, no Ledger
Account CRUD. Full as-built detail: `docs/modules/FINANCE.md` ("0G.3
as-built").

**0G.4 — Fees / Receivables Foundation (implemented):** a new module,
`App\Domain\Fees` (not `App\Domain\Finance` — DOMAIN-MAP.md's separate
Finance/Fees dependency rows required it, see `docs/modules/FINANCE.md`
"0G.4 as-built", "Module boundary"), adds `charges` (one migration,
`NUMERIC(14,2)`, INR-only, same-School/same-currency composite foreign
keys against `students`/`academic_years`/`ledger_accounts`/
`journal_entries`) — the receivable obligation entity; no `invoices`/
fee-definition entity in this checkpoint. `App\Domain\Fees\Application\ChargeService`
(`assess()`/`cancel()`) posts through Finance's existing `LedgerService`
(one small addition, `reverseById()`, so Fees never reads Finance's
`JournalEntry` model directly) inside one atomic transaction — never a
direct `journal_entries`/`journal_lines` write. `finance.charges.view`/
`.manage` capabilities, granted to `school_admin` only, gate
`ChargeAdministrationService`/`ChargeReadService`. No payments,
payment allocations, refunds, API, or UI. Full as-built detail:
`docs/modules/FINANCE.md` ("0G.4 as-built").

**0G.5 — Payments / Allocation / Idempotent Provider Integration
(implemented):** a new module, `App\Domain\Payments`
(`docs/architecture/adr/0031-payments-settlement-allocation-and-idempotency-architecture.md`,
DOMAIN-MAP.md's own row: depends on Fees and Finance, neither depends
on it — no cycle), adds three tables — `payment_provider_events` (pure
immutable provider-callback ingress identity, `(school_id, provider,
provider_event_id)` unique, the durable idempotency claim),
`payments` (the School's immutable settlement fact, created ONLY at
settlement — no pending/failed rows, a deliberate refinement of this
document's own earlier conceptual sketch toward 0G.4's immediate-
recognition precedent), and `payment_allocations` (immutable payment-
to-charge join, `charges(id, school_id)` composite FK). Settlement is
mandatory-fully-allocated (sum of allocations must equal the settled
amount, both application-checked and database-enforced via a deferred
constraint trigger mirroring `journal_entries_balanced_check`'s
precedent) — true overpayment/unapplied-cash accounting remains
explicitly deferred, alongside Refunds. A Payment's allocation set is
additionally frozen the instant its own transaction commits
(`payments.creation_txid`, mirroring `journal_entries.posting_txid`) —
a later, separate transaction can never insert an additional
allocation row, regardless of remaining Charge capacity — and Charge
over-allocation is prevented by an IMMEDIATE `BEFORE INSERT` trigger
that takes a `SELECT ... FOR UPDATE` lock on the Charge row before
validating capacity, closing a genuine concurrent-transaction race a
deferred-only check could not (both proven under real two-process
concurrency, including a raw path that bypasses the Application
service entirely). `App\Domain\Payments\Application\PaymentProviderEventService::recordSettlement()`
posts through Finance's existing `LedgerService::post()` (one debit
line for the settlement account, one credit line per charge
allocation) and through Fees' new `ChargeService::lockChargeForAllocation()`
(a `SELECT ... FOR UPDATE` lock, never a direct `charges` table read)
— all inside one atomic transaction. A Charge with any recognized
allocation can never be cancelled (`charges_payment_allocation_guard_trigger`,
a Payments-owned trigger physically attached to Fees' `charges` table,
sharing the SAME Charge-row lock protocol as allocation insertion so
the two paths genuinely serialize against each other;
`App\Domain\Fees\Application\ChargeService::cancel()` translates its
rejection to a typed exception). `finance.payments.view` only (no
`.manage` — provider ingestion is a trusted system boundary, never a
human capability). No signature verification, HTTP/provider adapter,
refunds, or UI. Full as-built detail: `docs/modules/FINANCE.md` ("0G.5
as-built").

**0G.6 — Finance / Fees / Payments HTTP & API Transport (implemented):**
thin HTTP controllers exposing the already-authorized 0G.2-0G.5
Application boundary over this repo's canonical `/api/v1` transport —
`LedgerAccountController`/`JournalEntryController`
(`App\Domain\Finance\Http\Controllers`), `ChargeController`
(`App\Domain\Fees\Http\Controllers`), `PaymentController`
(`App\Domain\Payments\Http\Controllers`, READ-ONLY). Every controller
calls only its module's already-authorized facade/read service
(`LedgerAdministrationService`/`LedgerReadService`,
`ChargeAdministrationService`/`ChargeReadService`, `PaymentReadService`)
— never `LedgerService`/`ChargeService`/`PaymentProviderEventService`
or a raw Eloquent model directly, proven both by review and by a
static source-grep guard (`FinanceHttpArchitectureGuardTest`). Eleven
routes total: Ledger Accounts (read), journal entries (read/post/
reverse), Charges (read/assess/cancel), Payments (read only — no
`finance.payments.manage` capability exists, no human Payment mutation
route of any kind). No provider-specific webhook/callback route (no
provider was selected in this checkpoint's scope, per ADR 0031's own
deferral); `PaymentProviderEventService` remains unreachable from any
route. Money is always an exact decimal string over the wire, never a
float. Zero new migrations, zero new capabilities, zero new
dependencies. Full as-built detail: `docs/modules/FINANCE.md` ("0G.6
as-built").

**0G.7 — Finance / Fees / Payments UI (implemented):** the
administrative Inertia UI over 0G.6's boundary — Ledger account
directory, journal history/detail/post/reverse, Charge list/detail/
assess/cancel, read-only Payment list/detail. New session-authenticated
Inertia controllers (`App\Http\Controllers\App\Finance\*`), calling the
SAME already-authorized Application-layer services 0G.6's `/api/v1`
controllers call — never a raw Eloquent model, never `LedgerService`/
`ChargeService`/`PaymentProviderEventService` directly — mirroring the
established Students/Guardians/Communications pattern, NOT the 0G.6
Bearer-token JSON API (which the browser cannot authenticate against
without new Sanctum stateful-SPA infrastructure this checkpoint
deliberately did not introduce; see FINANCE.md "0G.7 as-built" for the
full contract-gap writeup). No new capability, no new migration, no
OpenAPI/generated-type change; the 0G.6 `/api/v1` surface is
unmodified. Money stays an exact decimal string end to end (no
JavaScript `Number`/float, including the client-side journal-balance
preview, which uses exact `BigInt`-cents arithmetic). No Refund UI, no
Payment mutation UI, no provider configuration UI, no reporting
dashboard. Full as-built detail: `docs/modules/FINANCE.md` ("0G.7
as-built").

**0G.8 — Phase 0G Closure / Integration Readiness (feature work
complete; publication pending):** `origin/main` advanced 32 commits
(Admissions, Library, Transport, Visitor, and other unrelated work)
while Finance was under construction, producing seven textual merge
conflicts (`DashboardController.php`, `Dashboard.vue`,
`CapabilityAndRoleSeeder.php`, `routes/api.php`, `routes/web.php`, the
OpenAPI contract, and its generated TypeScript) — all additive-only on
both sides, none a real naming/semantic collision. Resolved on a
dedicated `integration/phase-0g-finance-resolution` branch (never the
Finance feature branch, never `main`) by keeping both sides everywhere
except the generated TypeScript file, which was discarded and
regenerated from the merged OpenAPI source rather than hand-merged.
Validated against the merged candidate: clean-install AND upgrade-path
migration proof (133 migrations = 125 from `origin/main` + Finance's 8,
zero collisions either way), RLS/trigger/SECURITY-DEFINER catalog
spot-check, zero duplicate routes/capabilities, full application
regression (3429 tests / 11305 assertions / 0 failures / 0 errors),
and all frontend/static quality gates green. Full as-built detail:
`docs/modules/FINANCE.md` ("0G.8 as-built"). Phase 0G's feature work
(0G.0–0G.8) is complete; **publication** (pushing the feature branch
and integrating into `main`) remains a separate, explicitly authorized
gate — not yet performed.

## Phase 0H — Academic Operations

Attendance, Timetable, Academics, Examinations.

**Timetable Foundation (Phase 0H.1) is complete** — recurring-weekly
class scheduling: `TimetablePeriod` (reusable named time slots, a
`TenantLock`-enforced non-overlap invariant) and `TimetableEntry`
(SubjectOffering × Section × HR Employee-as-teacher × optional Room ×
Period × day-of-week, three database partial-unique conflict indexes,
required-SubjectOffering-only v1 scope), `timetable.periods.*`/
`timetable.schedule.*` capabilities, an `/api/v1` administrative
surface, and a session-authenticated Inertia UI — 110 tests / 298
assertions, 3 real two-process concurrency races proven non-flaky, a
full security review against the module's own checklist, and a
definitive full-regression run. See `docs/modules/TIMETABLE.md` for
the complete as-built record.

**Attendance Foundation (Phase 0H.2) is complete** — Student class
attendance: `AttendanceSession` (the immutable header of one SUBMITTED
class register, carrying an immutable snapshot of the class context it
was instantiated from — AcademicYear/Campus/GradeLevel/Section/
SubjectOffering/teacher/Period plus the Period's wall-clock times — so
later Timetable or Period edits can never rewrite history;
`timetable_entry_id` is provenance only) and `AttendanceRecord` (one
StudentEnrollment's status, bound to its Session by DUAL composite
context foreign keys that make a wrong-Section/year/campus/grade row
structurally impossible), a complete-register submission discipline
over a Students/SIS-owned as-of-date placement roster, `present`/
`absent`/`late`/`excused` with no reason or free-text field anywhere,
expected-status compare-and-swap correction, `attendance.view`/
`attendance.manage` capabilities, an `/api/v1` command surface with a
COMPLETE OpenAPI contract shipped in the same branch, and a
session-authenticated Inertia UI. Phase 0H.2 also added a
Section-before-Enrollment lock order to the existing
`StudentEnrollmentService` so Attendance and Students/SIS serialize on
one shared Section row — a synchronization discipline that changed no
Students/SIS domain outcome. Five real two-process concurrency proofs
(one of which caught and closed a genuine deadlock before it shipped),
raw-PostgreSQL structural-integrity proofs from both FK directions, and
historical-mutation proofs against TimetableEntry edits, Period
retiming and backdated SIS changes. See `docs/modules/ATTENDANCE.md`
for the complete as-built record.

**Syllabus Foundation (Phase 0H.3A) is complete** — the first concrete
Academics fact: `SyllabusUnit`, one ordered unit of instructional
content that a `SubjectOffering` is EXPECTED to cover. A catalogue of
expected content only — it records nothing about what was actually
taught, nothing about an individual lesson, and nothing about any
Student. Exactly one parent (`SubjectOffering`, composite-FK
RESTRICT, no denormalized context); no `Curriculum` entity, no
`academic_term_id`, no `section_id`; required AND elective Offerings
both supported; case-insensitive code uniqueness enforced by an
unconditional PostgreSQL expression index (so an inactive unit keeps
reserving its code, which is why the entity needs no
activate/deactivate command); lifecycle through the ordinary PATCH and
no delete route; `syllabus.view`/`syllabus.manage` capabilities —
deliberately NOT under Academic Structure's `academics.*` root, with
the Academics → Syllabus → `syllabus.*` mapping recorded in
`docs/modules/ACADEMICS.md`; four `/api/v1` operations with a COMPLETE
OpenAPI contract and regenerated shared types in the same branch; and a
session-authenticated Inertia surface at `/app/syllabus`. Classified
**Confidential** — it stores no personal data at all. See
`docs/modules/ACADEMICS.md` for the complete as-built record.

**Academics is NOT complete.** Syllabus Foundation is the first of its
three scoped concerns: **Curriculum Delivery is not implemented** and
**Lesson Planning is deferred** (the latter pending a real requirement
and the platform's first ownership-based authorization model, which
does not exist yet).

**Examinations remains not started.** Phase 0H as a whole is **not**
complete — Timetable and Attendance are done, Academics has only its
Syllabus foundation, and Examinations has no implementation at all.

**"Phase 0H Attendance" remains Student class attendance only.** Staff/
Employee attendance is untouched by Phase 0H.2 and stays a Phase 0J/HR
concern.

**"Phase 0H Attendance" means Student class attendance.** Staff/
Employee attendance remains outside this Phase 0H checkpoint and
belongs to the separately-scoped HR/Phase 0J concern, unless future
authoritative roadmap work changes that boundary.

## Phase 0I — LMS

## Phase 0J — HR and Payroll

Including the **[LEGAL REVIEW REQUIRED]** statutory-compliance
questions flagged in `docs/security/DATA-CLASSIFICATION.md` (PF/ESI/
TDS and similar) — Compliance module involvement expected here, not
deferred.

**Resequencing note (2026-08-23):** the HR half of this phase's scope
(Employee master record, employment history, assignments, org
structure, directory, documents, lifecycle/rehire — everything short
of Payroll) is being built now, ahead of Phases 0E–0I, under a
separately-numbered initiative ("Phase 8A") on
`feature/phase-8a-hr-employee-records`. This is a deliberate, reviewed
exception — see ADR 0028 and `docs/modules/HR.md` for the full decision
record, scope boundary, and the one accepted cost (Phase 8A.7's
Employee Documents has no Phase 0E Documents module to build on yet, so
it uses a narrow HR-scoped table instead). Payroll itself is untouched
by this note and remains scoped to this Phase 0J entry, in its
originally documented order.

**Closure (2026-08-24):** Phase 8A (8A.0–8A.16) is complete — see
`docs/modules/HR.md`'s "Phase 8A Closure (8A.16, implemented)" section
for the full regression/closure record. Payroll (the remainder of this
Phase 0J entry) remains not started.

## Phase 0K — Operational Modules

Transport, Library, Inventory, Canteen, Hostel, Health, Visitor, Safety
— roughly independent of each other, sequenced by product priority once
reached, not strict dependency order.

**Phase 10A — Library (complete):** the first Phase 0K checkpoint —
catalogue (Title/Copy) + physical circulation (checkout/check-in), a
database-enforced single-active-loan-per-Copy invariant proven under
real concurrency, `library.catalogue.*`/`library.circulation.*`
capabilities, `/api/v1` administrative API, and a session-authenticated
Inertia UI. Full design and closure record: `docs/modules/LIBRARY.md`.
Deliberately excludes fines/Finance integration (Finance/Phase 0G is
not on `main`), reservations/holds/renewals, Documents-module
integration, and any Guardian/Student-facing surface — all explicitly
deferred, not gaps in this checkpoint's own closure.

**Phase 10B — Transport (complete):** the second Phase 0K checkpoint —
Routes + ordered Stops, Vehicles, the historical Route↔Vehicle↔Driver
operational assignment (auto-replace semantics, driver = existing HR
Employee referenced by id, never duplicated), and Student Transport
assignment (explicit-end-required semantics, a database-enforced
Stop-belongs-to-Route composite FK, and a one-active-assignment-per-
Student invariant proven under real concurrency),
`transport.routes.*`/`transport.vehicles.*`/`transport.assignments.*`
capabilities, `/api/v1` administrative API, and a session-authenticated
Inertia UI. Full design and closure record: `docs/modules/TRANSPORT.md`.
Deliberately excludes GPS/live-tracking (Student Transport location is
Sensitive data — a dedicated privacy/architecture review is required
before any future checkpoint attempts it), bus boarding/attendance,
Transport fees/Finance integration (Finance/Phase 0G is not on `main`),
Documents-module integration for vehicle/driver documents, and any
Guardian/Student-facing surface — all explicitly deferred, not gaps in
this checkpoint's own closure.

**Phase 10C — Visitor (complete):** the third Phase 0K checkpoint — a
Visitor directory (reference records) and check-in/check-out Visit
lifecycle against a Campus with an optional HR Employee host
(referenced by id, never duplicated), a database-enforced one-active-
Visit-per-Visitor invariant proven under real concurrency,
`visitor.directory.*`/`visitor.visits.*` capabilities, `/api/v1`
administrative API, and a session-authenticated Inertia UI. Full design
and closure record: `docs/modules/VISITOR.md`. Deliberately excludes
government-ID numbers/scans, biometrics/facial recognition, retained
photographs, blocklist/watchlist/risk-scoring (`status=inactive` is an
ordinary reference-lifecycle flag, not a security blocklist —
`docs/modules/VISITOR.md` §5), billing, public kiosk/self-registration/
pre-registration flows, Documents/Communications integration, and any
Guardian/Student-facing surface — all explicitly deferred, not gaps in
this checkpoint's own closure. Safety/incident management was
deliberately NOT built as part of this checkpoint — see below.

**Phase 10D — Hostel (complete):** the fourth Phase 0K checkpoint —
a Hostel directory belonging to exactly one Campus, HostelRoom and
HostelBed (capacity/occupancy always derived from active Bed/
residency-assignment rows, never a stored counter), and Student
residency assignment (explicit-end-required semantics, database-
enforced composite FKs at every level of the Campus → Hostel →
HostelRoom → HostelBed → HostelResidencyAssignment hierarchy — all
RESTRICT on delete, never CASCADE, per the Phase 10C Visitor
historical-integrity correction), two database-enforced invariants
(one active residency per Bed AND per Student) proven under real
concurrency with a documented deterministic lock order,
`hostel.directory.*`/`hostel.residency.*` capabilities, `/api/v1`
administrative API, and a session-authenticated Inertia UI. Full
design and closure record: `docs/modules/HOSTEL.md`. `HostelRoom` is a
deliberately independent model, not a reuse of Academic Structure's
teaching-space `Room`. Deliberately excludes Hostel fees/billing/
deposits (no Hostel↔Fees integration was built in this checkpoint,
regardless of Finance/Fees now being on `main` — see below), warden/
staff management, meal plans/Canteen integration, Health/Safety data,
Documents/Communications integration, and any Guardian/Student-facing
surface — all explicitly deferred, not gaps in this checkpoint's own
closure.

**Phase 10E — Inventory (complete):** the fifth Phase 0K checkpoint —
an Item catalogue and Location directory (Location's Campus optional,
mirroring Library Copy/Transport Route's precedent, not Hostel's
required-Campus special case), a quantity stock lifecycle (receive/
issue/transfer) backed by a stored, authoritative
`InventoryStockBalance` (one row per Item x Location) reconciled by
construction against an immutable, append-only `StockMovement` ledger
— proven never to drift by a dedicated reconciliation test. A
database-enforced non-negative-stock invariant, a concurrency-safe
missing-balance-row creation primitive (`INSERT ... ON CONFLICT DO
NOTHING` then re-read, never a naive `firstOrCreate()`+
`lockForUpdate()`), and a deterministic ascending-balance-id transfer
lock order (never a fixed source-then-destination role order, which
would deadlock opposing concurrent transfers) are each proven under
real two-process concurrency — three required scenarios: over-issue,
concurrent first-ever receipts, and opposing concurrent transfers, all
passing with no deadlock. `inventory.directory.*`/`inventory.stock.*`
capabilities, `/api/v1` administrative API (command-style receive/
issue/transfer only, never a generic movement-creation endpoint), and
a session-authenticated Inertia UI. Full design and closure record:
`docs/modules/INVENTORY.md`. Deliberately scoped to quantity/
consumable stock only — individually tracked assets, custody
(Employee/Student), procurement/suppliers/purchase orders, costing/
valuation/Finance journal posting, Fees/Payments, Canteen consumption
integration, barcode/RFID/mobile scanning, reorder automation, and any
Guardian/Student-facing surface all remain deliberately deferred, not
gaps in this checkpoint's own closure — see `docs/modules/INVENTORY.md`
§25 for the full list.

**Phase 10F — Canteen (complete):** the sixth Phase 0K checkpoint — an
Outlet directory (each backed by exactly one InventoryLocation,
structurally immutable after creation, with a database-enforced
Campus-consistency composite FK), a menu Item catalogue with a
per-Item recipe evaluated AT FULFILLMENT time (never snapshotted at
placement — a deliberate, documented asymmetry with the price/location
snapshot Order placement DOES take), and a Student order lifecycle
(place → fulfill → cancel) where fulfillment is the cross-domain
orchestration boundary: `CanteenOrderService::fulfill()` calls a new,
additive `InventoryStockService::issueMany()` method (issuing multiple
Items' stock against one Location atomically, deterministic
ascending-balance-id lock order, proven deadlock-free under real
concurrency — `docs/modules/INVENTORY.md` §5.1) and Fees'
`ChargeService::assess()`, inside one outer transaction, with no
internal capability re-check. A database-enforced one-Charge-per-Order
partial unique index, a one-Movement-claimed-by-one-Order consumption
link, and four concurrency scenarios (double fulfillment, scarce-stock
racing, cancel/fulfill race, recipe-mutation-vs-fulfillment torn read)
are each proven under real two-process concurrency.
`canteen.directory.*`/`canteen.orders.*`/`canteen.settings.*`
capabilities (the settings pair deliberately School-Admin-only by
default, mirroring `finance.charges.*`), `/api/v1` administrative API,
and a session-authenticated Inertia UI. During closure, a
capability-boundary bug the UI-building checkpoint had honestly
flagged (two picker endpoints reusing Inventory's own
`inventory.stock.manage`-gated search routes rather than a
Canteen-scoped one) was fixed with regression tests, and a full
security-review pass against the checkpoint's own checklist found no
other real issues. Full design and closure record:
`docs/modules/CANTEEN.md`. Deliberately excludes wallets/prepaid
balances, dietary/allergen/medical data, refunds/financial reversal of
a fulfilled Order, recipe versioning, and any Guardian/Student-facing
ordering surface — all explicitly deferred, not gaps in this
checkpoint's own closure — see `docs/modules/CANTEEN.md` §16 for the
full list.

The remaining Phase 0K modules (Health, Safety) are **not started**.
Health remains blocked on the `docs/security/DATA-CLASSIFICATION.md`
[LEGAL REVIEW REQUIRED] gate; and Safety is blocked pending its own
legal/security readiness decision, since "incident records" may fall
under that same unresolved Health gate (`docs/modules/VISITOR.md` §18,
§24) — ideally resolved alongside Health's own review rather than
separately. Neither Health/Safety legal blocker is resolved by
Canteen's closure.

**Phase 0K status: Closed with explicitly deferred legally/security-blocked
scope** (2026-08-29 readiness audit, `docs/modules/PHASE-10-CLOSURE.md`).
This is not the same thing as "complete" — Phase 0K's originally
documented scope was eight modules, and two of them (Health, Safety)
remain wholly unimplemented, blocked on the unresolved legal/security
prerequisites recorded above, not on remaining engineering work. This
status means: the six modules that were not legally/security-blocked
(Transport, Library, Inventory, Canteen, Hostel, Visitor) are each
individually complete per their own module docs, and there is
currently no further Phase 0K engineering work authorized to start —
Health and Safety each require their own recorded legal/security
resolution (see `docs/modules/PHASE-10-CLOSURE.md` for the exact
reopening criteria) before a fresh readiness gate, not implementation,
can begin for either. Phase 0K as a whole is **not** complete, and
Health/Safety are not cancelled, removed from scope, or retroactively
optional.

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
