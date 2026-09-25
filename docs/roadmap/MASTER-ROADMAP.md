# School OS — Master Roadmap

This roadmap sequences future phases by dependency, not by business
priority alone — a module cannot be built correctly before what it
depends on exists (`docs/architecture/DOMAIN-MAP.md`). Each phase below
records its own status; a phase with no recorded status or
implementation notes has not been started. Nothing here is a
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

## Phase 0C — Reliability & Integration Substrate (complete)

Re-sequenced ahead of the originally-planned "Organizational Structure"
phase (now Phase 0D, below): every later business module needs to
reliably trigger notifications, integrations, automation, analytics,
and AI without losing work, duplicating dangerous side effects,
crossing tenants, or tightly coupling to external infrastructure —
retrofitting that substrate after several modules already exist would
be far more expensive than building it first, the same reasoning that
put Phase 0B ahead of every business module. Landing in checkpoints:

- **Phase 0C (core substrate, complete):** durable domain events +
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

**Phase 0C closeout (2026-09-23, complete):** the two items that
remained after 0C.4 are done — webhook delivery/attempt retention
pruning (`platform:webhook-deliveries-prune`, scheduled daily; the
retention PERIOD itself stays unset pending the [LEGAL REVIEW REQUIRED]
retention decision) and the consolidated closeout report
(`docs/architecture/PHASE-0C-CLOSEOUT.md`). The closeout also scheduled
`platform:idempotency-prune`, which Phase 0C.2 had deferred to "the rest
of Phase 0C's operational-safety work". Outbox retention remains
deliberately deferred (ADR 0025).

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

**Status: complete and published to `main`** — built as the separately
numbered "Phase 1" initiative (1A–1H: Student/Guardian identity,
enrollment and rollover, subject enrollment, admissions, lifecycle
decision, electives, subject rollover, elective administration UI) and
merged as `cfb2796` ("Merge final Phase 1 student foundation").
`docs/students/PHASE-1-FINAL-COMPLETENESS-AUDIT.md` records zero missing
or partial Phase 1 requirements; its two formatting-only closure
blockers were resolved in `351f440`
(`docs/students/PHASE-1-CLOSURE-QUALITY-CORRECTION.md`). Items that audit
marks as explicitly deferred remain deferred.

## Phase 0G — Finance and Fees (complete)

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
(0G.0–0G.8) is complete and **published to `main`** — `c4652ca`
("Phase 0G.8: resolve Finance integration and close Phase 0G"), which
merges `feature/phase-0g-finance-foundation` (`1413113`).

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

**Curriculum Delivery (Phase 0H.3B) is complete** — the second concrete
Academics fact: `CurriculumDelivery`, the record that one `Section` has
COVERED one `SyllabusUnit` — when that Section began it, and when, if
yet, it finished. Actual instructional coverage by a cohort, the
counterpart to `SyllabusUnit`'s catalogue of expected content; it
records nothing about an individual lesson, nothing about who taught it,
and nothing about any Student. **Section-specific** (per-Section
variation is precisely what the Offering-wide syllabus deferred to
delivery) and **required-SubjectOffering-only in v1** — an elective is a
Student-level enrollment choice, not a Section-wide cohort, which is
Timetable v1's identical restriction and rationale. **Cross-parent
integrity is fully database-authoritative**: two 5-column composite
foreign keys pin the Section and the SubjectOffering to the same
AcademicYear/Campus/GradeLevel, and a third pins the SyllabusUnit to
that exact Offering (consuming one additive, non-destructive
`syllabus_units_offering_context_unique` key added to Phase 0H.3A's
table), so a Section teaching one Subject can never record delivery
against another Subject's unit even by raw SQL. One mutable state row
per Section × SyllabusUnit (`in_progress`/`completed`; **`not_started`
is deliberately the absence of a row**, so consumers LEFT JOIN from
`syllabus_units` rather than counting deliveries), School-local dates
validated as non-future and inside the AcademicYear, expected-status
compare-and-swap transitions under a row lock — proven with two real
separate OS processes — a closed two-edge state machine, and no delete
route. An Application service is required here, unlike SyllabusUnit,
because real invariants exist. `curriculum.delivery.view`/`.manage`
capabilities, a sibling of `syllabus.*` rather than an extension of it;
five `/api/v1` operations with a COMPLETE OpenAPI contract and
regenerated shared types in the same branch; and a session-authenticated
Inertia surface at `/app/syllabus-delivery`. **No teacher identity, no
Timetable dependency, no Attendance dependency, no Student data, no
`academic_term_id`, zero domain events.** Classified **Confidential** —
it stores no personal data at all. See `docs/modules/ACADEMICS.md` §18
for the complete as-built record.

**Academics is NOT complete.** Syllabus Foundation and Curriculum
Delivery are two of its three scoped concerns: **Lesson Planning remains
deferred**, pending a real requirement and the platform's first
ownership-based authorization model, which still does not exist. Phase
0H.3B deliberately stores nothing at lesson granularity, so Lesson
Planning remains fully necessary rather than redundant.

**Examination Foundation (Phase 0H.4A) is complete** — the first
Examinations fact: `Examination`, one named assessment WINDOW that a
School holds within one AcademicYear ("Mid-Term Examination 2026-27,
10–20 September). **A window/container, NOT a paper**: it owns only its
identity and the date range it spans, and owns no Subject,
SubjectOffering, Section, paper, per-paper sitting date/time or max
marks, no Student, enrollment, teacher or invigilator, and no mark,
grade, result, publication state, report card or transcript. Exactly two
parents (School and a composite-FK RESTRICT `AcademicYear`); no Campus
or GradeLevel — per-campus/per-grade variation is a paper concern — and
no `academic_term_id` ("Midterm" is a name, not a term reference;
AcademicTerm still has no lifecycle status and no consuming domain).
**Two deliberate departures from Curriculum Delivery**: future dates are
permitted and expected (an examination is scheduled ahead, exactly as
AcademicYears and AcademicTerms already are), and overlapping windows
are permitted (examinations partition nothing) — so this module
introduces no lock, no exclusion constraint and no concurrency test,
there being no multi-row invariant at all; the AcademicYear need not be
active, so planning next year's examinations inside a draft year is
supported. Case-insensitive code uniqueness within one AcademicYear
enforced by an unconditional PostgreSQL expression index (so an inactive
Examination keeps reserving its code, which is why there is no
activate/deactivate command); `active`/`inactive` through the ordinary
PATCH and no delete route; an Application service because the
AcademicYear range check needs a parent lookup;
`examinations.definitions.view`/`.manage` capabilities, deliberately
depth-2 so a later marks or result-publication family can never be
granted by the same key; four `/api/v1` operations with a COMPLETE
OpenAPI contract and regenerated shared types in the same branch; and a
session-authenticated Inertia surface at `/app/examinations`. Classified
**Confidential** — it stores no personal data at all. See
`docs/modules/EXAMINATIONS.md` and ADR 0032 for the complete as-built
record and the decomposition rationale.

**ExaminationPaper / Scheduling (Phase 0H.4B) is complete** — the second
Examinations fact: `ExaminationPaper`, one SubjectOffering assessed
within one Examination, with its scheduled sitting (date/time range) and
maximum obtainable marks. Offering-wide, never Section-specific. An
additive `examinations_context_unique` (`id, school_id,
academic_year_id`) plus the pre-existing `subject_offerings_context_unique`
let ExaminationPaper declare TWO composite FKs sharing the same stored
`academic_year_id` column, structurally guaranteeing
`Examination.academic_year_id == SubjectOffering.academic_year_id` even
by raw SQL — proven in `Tests\Feature\Postgres\ExaminationPapersRlsIsolationTest`.
Exactly one Paper per `(school_id, examination_id, subject_offering_id)`
(unconditional unique constraint, so an inactive Paper keeps reserving
the pair). Both required AND elective SubjectOfferings supported
identically. Creation requires both parents active; ordinary corrections
never re-check parent activity, but reactivating a withdrawn Paper does.
School-local same-day sittings, positive `max_marks`
(`NUMERIC(6,2)`), overlaps across different Offerings permitted (no
lock, no concurrency test — mirroring Examination's own reasoning).
`examinations.papers.view`/`.manage` capabilities; four more `/api/v1`
operations with a COMPLETE OpenAPI contract and regenerated shared
types; a session-authenticated drill-down UI at
`/app/examinations/{examination}/papers`; zero domain events. Classified
**Confidential**. See `docs/modules/EXAMINATIONS.md` §18 and ADR 0033
for the complete as-built record.

**GradeScale / GradeBand mapping (Phase 0H.4C) is implemented but NOT
YET PUBLISHED to `main`** — the third Examinations fact:
`GradeScale`/`GradeBand`, a named, School-owned percentage-to-grade
mapping wholly independent of the Examination chain. GradeBand stores
ONLY a lower-bound threshold; overlap-freedom is a plain
`UNIQUE(grade_scale_id, min_percentage)` constraint, coverage/gap-
freedom is a single "band exists at 0.00" check — no PostgreSQL range
type, exclusion constraint or `btree_gist`. Lifecycle
`draft/active/inactive`, exactly three legal transitions; GradeBands
mutable only while `draft`, frozen forever once ever `active`. Every
mutating operation reloads the target GradeScale with a parent-row
`lockForUpdate()` — an aggregate-local lock, deliberately NOT
`TenantLock` (a corrected design from the original architecture-gate
recommendation) — proven safe with two real, separate OS processes in
`Tests\Feature\Examinations\GradeScaleConcurrencyTest`.
`examinations.grade_scales.view`/`.manage` capabilities; seven
`/api/v1` operations (no GradeScale delete; GradeBand removal is the
sole delete route) with a COMPLETE OpenAPI contract and regenerated
shared types; a session-authenticated Inertia surface at
`/app/examinations/grade-scales`; zero domain events. Classified
**Confidential**. See `docs/modules/EXAMINATIONS.md` §19 and ADR 0035
for the complete as-built record. Pending: integration/publication gate
to merge onto `main`.

**Examinations has STARTED but is NOT complete.** Examination
Foundation, ExaminationPaper/Scheduling and GradeScale/GradeBand
mapping are its first three checkpoints: **marks, result calculation,
result publication, report cards and transcripts are all not
implemented**. The checkpoint that first introduces Student marks
crosses from Confidential into Sensitive personal data and must undergo
a dedicated privacy/security architecture audit — including the
children's-data **[LEGAL REVIEW REQUIRED]** gate in
`docs/security/DATA-CLASSIFICATION.md` — before implementation.

**Phase 0H as a whole is NOT complete** — Timetable, Attendance,
Syllabus Foundation, Curriculum Delivery, Examination Foundation,
ExaminationPaper/Scheduling and GradeScale/GradeBand mapping are done
(GradeScale/GradeBand published to `main`); Academics still
lacks Lesson Planning, and Examinations still lacks marks, results,
report cards and transcripts.

**Phase 0H.4D-P1 — Staff MFA Foundation — is implemented and published
to `main`** (ADR 0037): generic, TOTP-only, User-global multi-factor
authentication infrastructure, built as a mandatory platform
prerequisite identified by the StudentMark engineering-readiness audit
— independent of StudentMark's own legal/compliance status.

**Phase 0H.4D-P2 — Student Processing Authorization Registry — is
implemented and published to `main`** (ADR 0038; registry plus its
locked-state revalidation correction): the second
Students/SIS platform prerequisite — `StudentProcessingAuthorization`,
an append-only, School/Student-scoped record of the processing basis
(Guardian consent, adult Student consent, or statutory School purpose)
authorizing a Student's data processing for a given purpose, gated by
`students.processing_authorizations.view`/`.manage` composed with
`mfa` — the first real production route pairing a capability with MFA.
Phase 0H.4D-P3 (elective historical eligibility, not yet started)
remains fully independent.

Neither P1 nor P2 gates anything yet on its own (no Examinations/marks
route exists to consume either). **StudentMark itself remains NOT
implemented.** StudentMark architecture/backend processing has legal
approval with conditions, but implementation remains blocked by unmet
platform/engineering prerequisites (P2 published, P3 not yet started),
and production enablement remains separately withheld — this
checkpoint does not start StudentMark implementation and does not
itself constitute production approval; see
`docs/security/DATA-CLASSIFICATION.md` and
`docs/security/STUDENTMARK-CHILDRENS-DATA-DETERMINATION.md` for the
current classification and conditions.

**"Phase 0H Attendance" means Student class attendance.** Staff/
Employee attendance remains outside this Phase 0H checkpoint and
belongs to the separately-scoped HR/Phase 0J concern, unless future
authoritative roadmap work changes that boundary.

## Phase 0I — LMS

**Status: COMPLETE.** Active scope is Learning Content (0I.2) +
Assignment (0I.3), both implemented. Submission was reviewed (0I.4) and
then **cancelled as a product-scope decision on 2026-09-05** — see
below. Phase 0I.4A (Submission legal decision incorporation) is **NOT
REQUIRED — FEATURE CANCELLED**. No further Phase 0I checkpoint is
planned.

**Phase 0I.1 (2026-09-04): architecture contract frozen — complete.**
See ADR 0039 (`docs/architecture/adr/0039-lms-domain-contract.md`) for
the full decision record and `docs/modules/LMS.md` for the living
operational reference.

**Phase 0I.2 (2026-09-04): Learning Content Foundation implemented —
complete.** `App\Domain\LMS` — `LearningContent`/`learning_content`
(`draft→published→archived` lifecycle, `lms.content.view`/`.manage`
capabilities, six `/api/v1` operations plus a session-authenticated
Inertia surface at `/app/learning-content`), and the Documents module's
fourth exclusive-arc owner column (`learning_content_id`, `internal`
tier only) — see `docs/modules/LMS.md` §13 for the full as-built
record.

**Phase 0I.3 (2026-09-04): Assignments implemented — complete.** Same
Confidential/staff-authored posture as Learning Content — six `/api/v1`
operations (`draft→published→closed` lifecycle, `lms.assignments.view`/
`.manage`), a session-authenticated Inertia surface at
`/app/assignments`, and the Documents module's fifth exclusive-arc
owner column (`assignment_id`, `internal` tier only).

**Phase 0I.4 (2026-09-05): Submission legal-gate review conducted —
completed as a governance review; gate never cleared.** A dedicated
legal/data-governance review (`docs/security/LMS-SUBMISSION-LEGAL-REVIEW.md`)
classified Submission Sensitive/`[LEGAL REVIEW REQUIRED]`, performed a
detailed per-category data-inventory/classification exercise, and
formally escalated an 18-question set to qualified legal counsel and
product governance (`docs/security/LMS-SUBMISSION-LEGAL-REVIEW-REQUEST.md`).
**No qualifying response was ever received.**

**Submission capability — CANCELLED / OUT OF SCOPE (2026-09-05).** The
product owner made a final scope decision that LMS student Submission
functionality (coursework submission, Submission records/text/file
uploads, revisions/resubmissions, teacher review of submitted
coursework, Guardian-on-behalf or staff-on-behalf submission,
Submission grading/scoring, the Submission Documents owner arm, and all
Submission events/APIs/UI) is not required for the intended product
(Indian school-system ERP market) and is intentionally cancelled. **This
is a product-scope cancellation, not legal clearance** — the
`[LEGAL REVIEW REQUIRED]` gate is retired because the underlying
feature no longer exists, not because a qualified legal answer was ever
obtained; the historical legal/data-governance concerns Phase 0I.4
raised remain unresolved and must not be assumed settled if this scope
is ever reopened. Both legal-review documents are retained, marked
`CLOSED — FEATURE CANCELLED / OUT OF SCOPE`, as historical governance
records (see `docs/architecture/adr/0039-lms-domain-contract.md`'s
Submission cancellation addendum for the authoritative decision text).

**Phase 0I.4A (Submission legal decision incorporation): NOT REQUIRED
— FEATURE CANCELLED.** There is no legal decision to incorporate; the
feature it would have gated no longer exists.

**Proposed "Phase 0I.5 — Submission implementation": REMOVED /
CANCELLED.** No such checkpoint will be scheduled. Reopening Submission
in the future requires a fresh architecture and governance review from
first principles, not a resumption of this cancelled scope.

No external LMS integration or standard (Canvas, Moodle, Google
Classroom, Microsoft Teams, OneRoster, LTI, QTI, SCORM, xAPI, Common
Cartridge, Caliper) was ever authorized for Phase 0I and none is
authorized now.

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

**Resequencing note (2026-08-29):** Payroll — the remainder of this
Phase 0J entry — is now being built under a separately-numbered
initiative ("Phase 9") on `feature/phase-9-payroll`, mirroring Phase
8A's own exception. See ADR 0034 and `docs/modules/PAYROLL.md` for the
full decision record and scope boundary. Checkpoint 9.6 (statutory
PF/ESI/TDS) remains gated on the `[LEGAL REVIEW REQUIRED]` flag in
`docs/security/DATA-CLASSIFICATION.md` and will not close without an
explicit legal sign-off or an explicit user-approved scope-narrowing
decision — this entry will not be marked complete while that checkpoint
remains open.

**Closure (2026-09-03):** Phase 9 (9.0–9.5, 9.7–9.12) is engineering-
implementation complete — see `docs/modules/PAYROLL.md`'s "Phase 9.12
Closure" section for the full reconciliation/migration-compatibility/
regression-attribution record, including a rigorous zero-Phase-9-
regression proof against a genuinely separate `main` environment.
**Checkpoint 9.6 (statutory PF/ESI/TDS) remains BLOCKED/DEFERRED**,
gated on the `[LEGAL REVIEW REQUIRED]` flag in
`docs/security/DATA-CLASSIFICATION.md` — this Phase 0J entry is
therefore **not** marked fully complete; it is "engineering
implementation complete through non-statutory scope, statutory Payroll
deferred." (Historical as of that closure.)

**Publication (2026-09-03):** Phase 9 non-statutory Payroll was published
to `main` (`99cb641`, "merge: publish Phase 9 Payroll (non-statutory
scope) into main"). Checkpoint 9.6 (statutory PF/ESI/PT/LWF/TDS,
9.6A–9.6K) was then implemented under the accepted legal basis
`SCH/PAY/REG/2026-9.6` (ADR 0036) and published (`a8916cd`, "merge:
publish Phase 9.6 Statutory Payroll to main"). One statutory item
remains deliberately deferred: the ESI disability special threshold
(₹25,000), `DEFERRED — ADDITIONAL LEGAL CLARIFICATION REQUIRED` (ADR
0036). Phase 0J is therefore engineering-complete including statutory
scope, with that single disclosed legal deferral.

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
Deliberately excludes fines/Finance integration (Finance/Phase 0G was
not yet on `main` when this checkpoint closed), reservations/holds/renewals, Documents-module
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
Transport fees/Finance integration (Finance/Phase 0G was not yet on
`main` when this checkpoint closed),
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

**Phase 0L.1 (2026-09-05): Analytics Domain Contract frozen, zero
implementation.** Analytics only — see ADR 0040
(`docs/architecture/adr/0040-analytics-domain-contract.md`) for the
full ten-decision record and `docs/modules/ANALYTICS.md` for the living
operational reference. No migration, model, controller, service,
route, or capability exists yet. Single-School (tenant-local) Analytics
only in v1; cross-School/platform-wide Analytics explicitly deferred to
its own future checkpoint. Aggregate/derived data inherits the
strongest classification tier among its sources by default
(`docs/security/DATA-CLASSIFICATION.md`); a minimum-cohort-size
suppression threshold for small-group re-identification risk is
recorded as an explicit, still-open **[LEGAL/PRODUCT/SECURITY REVIEW
REQUIRED]** gate — no Analytics read model capable of producing a
small-cohort cell may ship until it is set. Compliance and Automation
remain wholly unscoped by this checkpoint and require their own future
contracts.

**Recommended next checkpoint: Phase 0L.2 — Analytics Foundation**
(one or two already-stable Layer 0–4 data sources, a real, minimal,
capability-gated, single-School read surface, following ADR 0040
exactly — no dashboard/API/schema exists yet). **Readiness gate
recorded 2026-09-23 — still BLOCKED** on the owner decisions listed in
`docs/security/ANALYTICS-SMALL-COHORT-POLICY-GATE.md` §7 (minimum cohort
size, suppression mode, policy scope, counsel review for Student-data
sources, capability grants, first source); implementation does not
start until they are recorded.

**Phase 0L.2-1 — Analytics Foundation + Curriculum Coverage: COMPLETE
(2026-09-23).** Owner-approved interim scope (ADR 0040 2026-09-23
amendment): `App\Domain\Analytics` (read gate, read-model declaration,
fail-closed person-cohort policy, registry), `analytics.view` for School
Admin and Principal, `analytics.export` seeded but unused, and one
non-person report — Curriculum Coverage (`/app/analytics/curriculum-coverage`)
— reading Curriculum Delivery's new aggregate contract. **Person-counting
Analytics remains BLOCKED** on gate decisions 7.1–7.4; no export; no
cross-School Analytics. The full regression checkpoint that followed it
passed and was published at `8eb5b74`.

**Phase 0L.2 — Analytics Foundation: COMPLETE (2026-09-23).** Closed
with the one non-person source built in 0L.2-1 — ADR 0040 asks for "one
or two already-stable Layer 0–4 sources", and export was optional and
not chosen (gate decision 7.6). Completeness matrix and evidence:
`docs/architecture/PHASE-0L2-ANALYTICS-FOUNDATION-CLOSEOUT.md`. This
closes the non-person foundation only: **person-counting Analytics
remains BLOCKED** on gate decisions 7.1–7.4 as a future, separately
gated checkpoint, and Analytics export, cross-School Analytics,
Compliance and Automation remain unscoped. No later Phase 0L checkpoint
is defined or authorized by this closeout.

**Phase 0L.3 (2026-09-24): Compliance Domain Contract frozen, zero
implementation.** The product owner chose Compliance ahead of
Automation and named this checkpoint. ADR 0042
(`docs/architecture/adr/0042-compliance-domain-contract.md`) is the
decision record and `docs/modules/COMPLIANCE.md` the living reference
(inventory, gates, plan). Compliance owns read-only evidence and
regulatory-reporting views over records other modules keep; it owns no
source record, calculates and files nothing (Payroll keeps statutory
calculation and its data-preparation exports — ADR 0034/0036, which
also supersede the Phase 0J line above about "Compliance module
involvement"), deletes nothing, sets no retention and claims no legal
compliance. Single-School only; `compliance.*` reserved, not seeded;
audit-log review uses the existing `school.audit.view`. Retention,
legal holds, data-subject requests and statutory-reporting scope remain
**[LEGAL REVIEW REQUIRED]**. **Proposed next (not started, needs its
own go-ahead): Phase 0L.4 — Compliance Foundation: School Audit-Log
Review**, gated on the audit-record classification/metadata decision
and confirmation of the `school.audit.view` grant
(`COMPLIANCE.md` §5–§6). Automation remains unscoped and needs its own
contract.

**Phase 0L.4 — Compliance Foundation: School Audit-Log Review: COMPLETE
(2026-09-24).** Owner-approved decisions (ADR 0042 amendment): School
audit records Highly Sensitive for this surface (conservative v1), empty
metadata allowlist, `school.audit.view` for School Admin and Principal
only. `App\Domain\Compliance` (read-only) plus the ledger's read contract
`App\Support\Audit\SchoolAuditEventReader`; `/app/compliance/audit-log`
shows envelope fields only, newest first, keyset-paginated, and audits
each review. No migration, capability seed, filter, export, API, platform
or cross-School view. The page does not assert legal compliance. No
further Compliance checkpoint is scheduled: the remaining candidates
(`COMPLIANCE.md` §6) are each blocked on their own gates.

**Phase 0L.5 (2026-09-24): Automation Domain Contract frozen, zero
implementation.** The product owner set the number and title; ADR 0043
(`docs/architecture/adr/0043-automation-domain-contract.md`) is the
decision record and `docs/modules/AUTOMATION.md` the living reference.
Automation runs code-catalogued, School-scoped rules (one domain-event or
schedule trigger, one fixed condition over source read contracts, one
action through an approved Application-layer entry point); Schools
enable and own rule instances but never author rules. Executions act as
the rule's accountable owner, re-verified every run and limited to the
action's declared capability — no new principal, no service identity.
Only informational review items are allowed in v1; internal
notifications and automation-safe source commands are gated;
approval-required actions are out of v1; financial, payroll,
student/employee status, grants, deletion, consent, external or
Emergency communications are prohibited. `automation.*` reserved, not
seeded; execution-log retention **[LEGAL/POLICY REVIEW REQUIRED]**.
**Proposed next (not started, number to be assigned): Automation
Foundation**, blocked on the owner decisions in `AUTOMATION.md` §5 (first
rule type, roles, authority-model confirmation, opt-in).

**Phase 0L.6 — Automation Foundation: Academic Year Setup Review:
COMPLETE (2026-09-24).** Owner decisions (ADR 0043 amendment): the one
rule `academic_year.setup_review` (existing `academic_year.activated.v1`
event → tier 0 review item in Automation's own table), accountable-owner
authority re-verified per execution, `automation.view` for School Admin
and Principal and `automation.manage` for School Admin only, School
opt-in flag `automation.rules` default off (Demo School on, Annexe off).
Proves one trigger, one tier 0 effect, School scope, authority
re-check/suspension, idempotency (unique execution, lease claim, bounded
domain retry) and audit — not general Automation. Also fixed a latent
`FeatureFlagResolver` worker-scope defect. **A full regression
checkpoint is required before the next development unit.**

**Phase 0L — Oversight: COMPLETE (2026-09-24).** Each Layer 5 module has
a published contract and foundation: Analytics (0L.1/0L.2), Compliance
(0L.3/0L.4), Automation (0L.5/0L.6). The full regression after 0L.6
passed at `8a9825f` (5,457 tests, 0 failures, only the ESI-12 legal
skip). Everything not built is optional future scope or blocked on a
recorded decision — person-counting Analytics (gate 7.1–7.4), Compliance
retention/legal holds/data-subject requests/statutory reporting, and
Automation tiers 1–3 among them — and none is scheduled. Closeout,
matrix, gates and known debt:
`docs/architecture/PHASE-0L-CLOSEOUT.md`. Next dependency: Phase 0M,
gated on the provider data-handling legal/compliance review; its
readiness-gate document is the next task, not Phase 0M itself.

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

**Readiness gate recorded 2026-09-24 — BLOCKED.**
`docs/security/AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md` sets out the
reusable per-provider review matrix, the data that could reach a
provider (none may before approval), the fail-closed state today (only
`NullProvider`; no production caller of `AiGatewayClient`), four gaps to
close before any real provider (`/v1/complete` without a context token,
model calls not durably audited, response bodies in gateway logs/errors,
no off-by-default provider switch), and the provider/legal, product and
security decisions that must be recorded before implementation. No
provider is selected. (Clarifies the audit note above: tool calls are
written through durably; model calls are not yet.)

**AI Gateway fail-closed hardening (2026-09-24, NullProvider only):**
the four gaps are closed — completions need a Laravel-verified context
token, every model call is audited durably (no output without its
audit), gateway logs and errors carry no bodies, and an external
provider can be neither registered nor selected while
`REAL_PROVIDERS_ENABLED` is off (its default). Phase 0M remains
**BLOCKED** on the provider/legal and product decisions.

## Phase 0N — Multi-School Management

Group/Trust cross-school administration and reporting, building on
Phase 0B's explicit-elevation model (ADR 0004) and its structural
foundation (`school_groups`, `school_group_members`) — the actual
"enter a member School's context as a group/platform admin" workflow is
still unbuilt after Phase 0B, deliberately (see that phase's own
summary above).

**Readiness audit recorded 2026-09-24 — BLOCKED.**
`docs/architecture/PHASE-0N-READINESS.md` records what exists (multi-School
membership and switching, RLS with no runtime bypass, a Platform Super
Admin with capabilities but no School scope and no UI, group tables with
no behaviour), that the elevation workflow, group principal model and
group reporting are unspecified, and the architecture, product and
security decisions required first. It also records a prerequisite found
during the audit: with no School selected — every user after sign-in —
135 of 141 School pages return 500. The proposed first checkpoint fixes
that and adds a platform-scope landing, without elevation.

**Phase 0N.1 — Safe School Context & Platform Landing (done, 2026-09-24).**
D9(a) and D10(a) approved and implemented: every School-scoped web route
requires a valid selected School before anything School-scoped runs
(`school-context`, `RequireSchoolContext`; pages return to `/app`,
mutations/JSON get 409 `school_context_required`), stale selections are
cleared, `/app` is a context-neutral landing (explicit selection, or a
neutral state for accounts with no School, including the Platform Super
Admin), and a route guard test keeps new School pages inside the
boundary. No elevation, group, reporting or platform-management work.
Phase 0N itself stays **BLOCKED** on D1–D8 and D11–D18.

**Phase 0N.2 — Cross-Tenant Elevation Contract (done, 2026-09-24,
documentation only).** The owner approved D2–D8, D14 and D17;
**ADR 0044** freezes the contract for a platform actor temporarily
establishing one School's context: explicit, confirmed, reason-coded,
MFA-assured, bounded, visible, audited; a persistent elevation record
(one active per actor, no reactivation); elevation establishes tenant
context but grants **no** School capability and authorizes **zero**
source modules — School routes refuse elevated context unless an
operation opts in through its own ADR; web session only, never
`/api/v1`, internal APIs or AI; not cross-School reporting, group
administration or School lifecycle. Nothing is built. Before the
proposed substrate checkpoint (Phase 0N.3, ADR 0044) the owner must set
the elevation duration, the reason-code catalog, MFA re-verification vs.
re-login and target selection. Phase 0N stays **BLOCKED** on D1, D11,
D12, D13, D15, D16 and D18.

**Phase 0N.3 — Platform Elevation Substrate (done, 2026-09-24).** Owner
values: fixed 30 minutes; a fresh in-session MFA re-verification on every
start; reason codes `operational_support`, `security_investigation`,
`configuration_assistance`, `incident_response`; exact target (verified
School domain or School UUID), no directory. Built: the
`platform.schools.elevate` capability (Platform Super Admin only), the
database-guarded `school_elevations` record (one active per actor, 30-
minute CHECK, no reactivation, undeletable), start/confirm/exit, the
elevated-access banner, per-request validation with forced termination,
an expiry sweep, the five platform audit events and
`school_audit_events.elevation_id`. **Zero** School or source-module
routes accept elevated context: an elevated Platform Admin gets 403 on
every School page and API route. Phase 0N stays **BLOCKED** on D1, D11,
D12, D13, D15, D16 and D18.

**Phase 0N.4 — Group/Trust Governance Contract (done, 2026-09-24,
documentation only).** The owner approved D1 and D18; **ADR 0045**
records a distinct Group scope (`group` roles, `group.*` capabilities, a
new `group_role_assignments` grant — never a platform role, School role or
membership), Group authority that grants no School capability,
platform-governed Group membership and grants (a Group Admin changes
neither; no self-grants), multiple Groups per School with single-Group
attribution, Group archive instead of deletion, and Group-derived School
entry only through ADR 0044 elevation recording the authorizing Group and
grant, ended immediately by removal, revocation or archive. No
cross-School reporting. Proposed next: Phase 0N.5 Group Authority
Foundation, gated on classifying Group records (ADR 0045 §13a). Phase 0N
stays **BLOCKED** on D11, D12, D13, D15 and D16.

**Phase 0N.5 — Group Authority Foundation (done, 2026-09-24).** Owner
classifications: Group details, Group membership and member-School identity
Confidential; human Group grants Sensitive. Built: exactly three role
scopes enforced by the database (and roles may hold only their own scope's
capabilities); the `group_admin` role (`group.schools.view`,
`group.schools.elevate`); history-keeping, never-self-granted
`group_role_assignments`; Groups archived, never deleted; platform
governance (`platform.school_groups.view`/`.manage`,
`platform.school_group_grants.manage`) with its seven audit events; the
Group Admin's read-only Group view; and Group-derived ADR 0044 elevation
recording the authorizing Group and grant, checked by the database at
start and ended immediately by removal, revocation or archive (proven
against real concurrent processes). A School may belong to several Groups.
**Zero** School routes accept elevated context. Phase 0N stays **BLOCKED**
on D11, D12, D13, D15 and D16.

**Phase 0N.6 — Platform Authority & Audit Governance Contract (done,
2026-09-24, documentation only).** The owner approved D12 and D16;
**ADR 0046** records: `platform_super_admin` as the root/bootstrap role,
never granted or revoked in-app; School creation root-only (its meaning
stays D11); runtime-assignable, code-approved non-root platform roles
only — v1 `platform_auditor` — with no self-grant and root-reserved
capabilities (`platform.role_grants.manage`, `platform.schools.manage`);
history-keeping platform role grants; and the platform audit review
(`platform.audit.view`, context-neutral, Highly Sensitive, seven envelope
fields, empty metadata allowlist, keyset paging, one
`platform.audit_log.viewed` event per review, no export). Proposed next:
Phase 0N.7 Platform Authority & Audit Foundation. Phase 0N stays
**BLOCKED** on D11, D13 and D15.

**Phase 0N.7 — Platform Authority & Audit Foundation (done, 2026-09-25).**
Owner decisions: platform audit review requires the existing MFA
assurance (no fresh code per page); the metadata allowlist stays empty;
the root stays out of band. Built: `platform.audit.view`,
`platform.role_grants.manage` (root only), the `platform_auditor` role
(the only runtime-assignable one); `roles.runtime_assignable` with
database guards keeping root-reserved capabilities off it; history-keeping
`platform_role_assignments` (no self-grant/-revoke, no runtime grant or
revocation of the root role, no deletion, RESTRICT FKs);
`/app/platform/roles` to grant/revoke the auditor with audited refusals;
and `/app/platform/audit-log` — seven envelope fields, keyset pages of 50,
one `platform.audit_log.viewed` per review, no School context. Phase 0N
stays **BLOCKED** on D11, D13 and D15.

**Phase 0N.8 — School Lifecycle & Bootstrap Administration Contract
(done, 2026-09-25, documentation only).** The owner decided D11 and D13
for v1; **ADR 0047** records: the existing `schools.status` as the
lifecycle (new `provisioning` value; `provisioning → active → suspended →
active`; `archived` kept with no transition; database-enforced
transitions); root-only CREATE with a mandatory bootstrap School Admin (a
real ordinary membership and `school_admin` assignment for an exact,
existing, enabled user, established or replaced only while
`provisioning`, closed permanently at first activation); explicit
ACTIVATE requiring a qualifying active admin; SUSPEND with closed reason
codes, eager elevation termination (`school_suspended`) and
execution-time enforcement per substrate (webhooks/communications
deferred, automation skipped, announcements held, AI and invitation
acceptance refused, platform safety work continuing); RESUME with no
global replay; `platform.schools.manage` plus fresh MFA re-verification
and confirmation for every lifecycle action; platform-ledger-only audit;
no ongoing platform membership administration; no application School
delete and a revoked runtime `DELETE` on `schools`; archive/delete behind
a retention/legal decision. Proposed next: Phase 0N.9 School Lifecycle
Foundation. Phase 0N stays **BLOCKED** on D15 only.

**Phase 0N.9 — School Lifecycle Foundation (done, 2026-09-25).** Built
ADR 0047: `schools.status` CHECK, default `provisioning` and a transition
trigger; `REVOKE DELETE ON schools` from the runtime role (test teardowns
moved to the admin connection); `SchoolLifecycleService` and
`SchoolBootstrapAdministrationService` behind `platform.schools.manage`,
explicit confirmation and a fresh MFA code, with audited refusals;
`/app/platform/schools` (create, bootstrap administrator while
provisioning, activate, suspend with reason code, resume); eager
termination of elevations (`school_suspended`) and an elevation INSERT
guard; `SchoolOperationalGuard` execution-time checks (webhooks and
communications deferred without consuming attempts, automation skipped,
announcements held, AI minting and internal AI endpoints refused, Guardian
invitations unusable); real two-process race proofs. No archive, delete,
break-glass or platform membership administration. Next: Phase 0N.10
Cross-School Reporting Contract (D15). Phase 0N stays **BLOCKED** on D15.

**Phase 0N.10 — Cross-School Reporting Contract (done, 2026-09-25,
documentation only).** The owner decided D15; **ADR 0048** records a
narrow Group reporting model: explicit Group authority
(`group.reporting.view`, v1 held by `group_admin`; never implied by
platform roles, School roles, multi-School membership or elevation); an
Analytics-owned, code-registered Group-safe report registry containing only
`curriculum.coverage` (syllabus-unit counts, Confidential, no persons);
per-School execution with exactly one `TenantContext` at a time, member
Schools re-checked FOR SHARE and only `active` ones read; sums of counts and
a recomputed unit-weighted percentage (never averaged percentages); current
MFA assurance; `platform.school_group_report.viewed` / `.failed` on the
platform ledger; failures fail closed (partial results only for expected
unavailable Schools); no persistence, no export, no Compliance, Automation
or AI cross-School access; no RLS change. ADR 0048 is also ADR 0040 §4's
cross-School ADR for that one report. **Architecture decisions complete —
first cross-School report implementation remains:** Phase 0N stays in
progress until Phase 0N.11 Group Curriculum Coverage Reporting Foundation
is built.

**Phase 0N.11 — Group Curriculum Coverage Reporting Foundation (done,
2026-09-25).** Built ADR 0048: `group.reporting.view` (held by
`group_admin` only); Analytics' separate Group-safe registry
(`curriculum.coverage` only), `GroupSafeReportGate` and an active-year-only
School summary; `GroupCurriculumCoverageReportService` observing each
member School in its own transaction (Group, grant and membership FOR
SHARE; School FOR SHARE; exactly one `TenantContext`, cleared after each);
unit-weighted totals recomputed from summed counts; `unavailable` and
`no active academic year` states; fail-closed authority loss (404) and
source failure (503), audited; `/app/groups/{schoolGroup}/reports/curriculum-coverage`
with current MFA assurance; no export, cache or persistence; real
two-process race and raw-SQL RLS proofs. **Phase 0N implementation
complete — ready for the Phase 0N closeout audit.**

**Phase 0N — Multi-School Management: COMPLETE (2026-09-25).** Every
roadmap item is built and every readiness decision (D1–D18) has a recorded
disposition: the safe no-School landing (0N.1), explicit temporary
platform elevation granting no School capability and accepted by no School
route (ADR 0044, 0N.3), Group/Trust authority with Group-derived elevation
(ADR 0045, 0N.5), platform authority and the MFA-protected platform audit
review (ADR 0046, 0N.7), School lifecycle with bootstrap administration and
execution-time suspension (ADR 0047, 0N.9), and the first Group
cross-School report, Curriculum Coverage (ADR 0048, 0N.11). The full
regression passed at `fc6d799` (5,685 tests, 0 failures, only the ESI-12
legal skip). Everything not built is future scope deferred by an ADR or
gated on a recorded decision — School archive/delete, break-glass
recovery, further Group reports and export, cross-School Compliance,
Automation and AI, person-counting Analytics among them — and none is
scheduled. Closeout, ledger, decision matrix and deferred register:
`docs/architecture/PHASE-0N-CLOSEOUT.md`. Next roadmap phase: Phase 0O,
which has no readiness audit yet; Phase 0M remains BLOCKED on its
legal/compliance gate.

## Phase 0O — External Surface and Production Readiness

Public developer API hardening (rate limiting, partner API keys,
building on Phase 0C.2's documented rate-limit/idempotency
interaction), a real observability backend (ADR 0015, building on
whatever instrumentation-only foundation Phase 0C's core substrate
lands), production secrets/infrastructure (ADR 0016,
`infrastructure/terraform`), and broader third-party integrations
(ADR 0018) beyond the payment gateway from Phase 0G.

**Readiness audit recorded 2026-09-25 — PARTIALLY READY; SOME
CHECKPOINTS MAY START.** `docs/architecture/PHASE-0O-READINESS.md` maps
the four scope items against what exists (a production-grade outbound
webhook subsystem, instrumentation-only observability, env-only
configuration, strong tenancy/RLS and authorization foundations) and what
does not: no `/api/v1` token issuance or expiry, no partner keys, no
default API limiter, no trusted-proxy/security-header/CORS policy, no
observability backend, no production images, IaC, release runbook or
backup/restore, no fail-closed production configuration (the AI context
signing key and the AI Gateway service token have unsafe fallbacks), and
no production root-provisioning command. It records sixteen owner,
security, provider and operator decisions (O1–O16), a deploy-gated register
under the stop gates below, and a roadmap discrepancy: no Phase 0G payment
gateway was ever integrated. Only **0O.1 — Production Bootstrap &
Fail-Closed Configuration Foundation** is ready to start; the rest waits on
those decisions. Phase 0M stays BLOCKED and must not be approached through
Phase 0O.

**0O.1 — Production Bootstrap & Fail-Closed Configuration Foundation:
COMPLETE (2026-09-25).** Operator-only root provisioning
(`platform:provision-root`, ADR 0046 §2), a production boot check that
refuses unsafe configuration, a fail-closed AI context signing key, an AI
Gateway that refuses the development token outside local/testing and
reports 503 when not ready, a guarded `ServiceIdentitySeeder` plus operator
issue/disable commands, the CI role-provisioning fix, and the production
process and release contract (`docs/architecture/PRODUCTION-RELEASE.md`).
Phase 0O stays PARTIALLY READY; decisions O1–O16 remain open
(`PHASE-0O-READINESS.md` §13).

**0O.1A — Root Bootstrap Boundary Correction (2026-09-25).** 0O.1 was
first published with a residual: the runtime database role could insert a
grantor-less root assignment, and production had no first-account path.
The database now accepts an out-of-band platform grant only from the
administrative (table-owner) role, and `platform:bootstrap-root` creates the
first platform account on a fresh installation (interactive, hidden
password, first boot only). 0O.1 is COMPLETE; O14 password reset stays
open (`PHASE-0O-READINESS.md` §14).

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
