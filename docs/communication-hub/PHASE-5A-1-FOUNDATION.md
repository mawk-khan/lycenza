# Phase 5A.1 — Communication Hub Domain Foundation

Branch: `feature/phase-5a-communication-hub` (base `main` @ `6edbe2d882d9fba84bc4ac27323c6c507e4a0869`).

This document is written in two passes: an **architecture audit** (done
before any Communication Hub code was written) and an **implementation**
record (filled in as the foundation was built). See
`docs/roadmap/MASTER-ROADMAP.md` for how Phase 5A fits the wider roadmap.

---

## 1. Architecture audit

### 1.1 Identity — IMPORTANT DEVIATION FROM THE BRIEF

The Phase 5A brief assumes a "Student & Guardian Identity Foundation"
already exists and must be reused. **It does not exist anywhere in this
repository yet.**

- `apps/platform/app/Domain/` on `main` contains only `AcademicStructure`,
  `Campuses`, `Platform`, and `Schools` modules (confirmed via
  `app/Domain/README.md`, which still says "No business modules exist
  yet" for anything past what those four cover).
- A branch/worktree named `phase/1a-student-guardian-identity` exists
  (`.claude/worktrees/phase-1a-student-guardian-identity`, locked), but
  its HEAD is identical to `main`'s (`6edbe2d882d9fba84bc4ac27323c6c507e4a0869`)
  — i.e. it is an unstarted placeholder branch, not completed work.
- `grep -ril "guardian" apps/platform/app apps/platform/database` returns
  only `app/Models/User.php`'s own docblock, which explicitly documents
  the intended future shape: *"Central/platform data: authentication
  identity only... Deliberately does NOT carry employment/student/
  guardian fields — future domain records (Employee, Teacher, Guardian,
  Student) link to a User when they need login, they don't extend it."*
- There is no `Student`, `Guardian`, `Staff`, or `Teacher` model/table.

**Consequence for 5A.1:** the only real, persisted identity Communication
Hub can address today is `App\Models\User`, reached through
`App\Models\SchoolMembership` (central "who belongs to which School"
data) and `App\Models\MembershipRoleAssignment` (tenant-owned "what can
this membership do"). Every participant/sender/recipient in this
checkpoint is a `User` who has an active `SchoolMembership` in the
target School. The schema is deliberately shaped so that a future
Guardian/Student identity (once Phase 1A actually lands) can be added as
an additional recipient/participant *type* without a breaking migration
— see §2.3 — but nothing in 5A.1 pretends that identity exists today.
This is flagged as the single biggest deviation from the brief's stated
assumptions; the brief's Sections 6/7/19 ("guardian ↔ teacher",
"student ↔ teacher" threads) are therefore **out of reach for 5A.1** and
deferred until Phase 1A actually ships.

### 1.2 Multi-tenancy

Canonical implementation, all under `App\Support\Tenancy`:

- `BelongsToSchool` (trait) — every tenant-owned Eloquent model adds
  `SchoolScope` (Eloquent-level isolation) and auto-fills `school_id`
  from `TenantContext` on create.
- `TenantRls` — migration-time helper; `TenantRls::enable($table)` turns
  on Postgres RLS + FORCE RLS + the one standard policy reading the
  `app.current_school_id` session GUC; `TenantRls::makeAppendOnly($table)`
  revokes UPDATE/DELETE from the `school_os_app` runtime role for audit-
  ledger-shaped tables.
- `TenantContext` — the single authoritative runtime tenant-context
  object (container-scoped, not static). `requireSchool()` fails closed;
  `withSchool($school, $callback)` is the sanctioned way to read/write a
  specific School's RLS-protected data regardless of ambient context
  (used by `CapabilityResolver` and every `*Service::create/activate`
  method).
- `TenantScoped` (trait, for queue jobs) — `captureTenantContext()` in
  the job constructor + `SetTenantContextForJob` middleware, which sets
  context before `handle()` and clears it in `finally`.
- `SchoolMembership` is deliberately **central, not RLS-protected**
  ("which schools does this user belong to" is central discovery data);
  `MembershipRoleAssignment` (what a membership can do) **is**
  RLS-protected.
- `Campus` (`App\Models\Campus`) is a School sub-dimension, itself
  RLS-protected like any tenant-owned table (ADR 0004: Campus is not a
  separate isolation boundary).

Communication Hub tables all use `BelongsToSchool` + `TenantRls::enable()`,
exactly like Academic Structure did in Phase 0D.

### 1.3 Authorization

- Capability check: `Gate::authorize('capability', [$key, $school])`,
  defined in `App\Providers\AppServiceProvider` and backed by
  `App\Support\Authorization\CapabilityResolver` (per-user-per-school
  cache, platform vs. school capabilities resolved and cached
  separately, 60s TTL).
- Controller convenience: `App\Support\Authorization\AuthorizesCapability`
  trait → `$this->authorizeCapability('school.profile.manage', $school)`.
- Route middleware alternative: `App\Http\Middleware\EnsureCapability`
  (`capability:` prefix), used for simple read-only routes
  (`app.settings.show`).
- Capabilities/roles are seeded in `database/seeders/CapabilityAndRoleSeeder.php`
  (idempotent `updateOrCreate`/`sync`). New modules add their own
  namespaced pairs (`academics.structure.view/.manage`,
  `integrations.webhooks.view/.manage`, ...) and assign them to the
  existing `school_admin`/`principal` roles as appropriate — never a new
  role unless the product genuinely needs one.
- Role-name checks are explicitly forbidden (root CLAUDE.md rule 24);
  school-scoped vs. platform-scoped roles are DB-trigger-enforced
  (`membership_role_assignments` vs `platform_role_assignments`).

### 1.4 Queue architecture

- Driver-agnostic; queue **names** are a fixed enum,
  `App\Support\Observability\QueueName` — `Default`, `Integrations`
  (both have real traffic today), and three **reserved** names for
  future modules, including `Notifications = 'notifications'`,
  explicitly documented as *"future email/SMS/WhatsApp/in-app
  notification delivery jobs. None exist yet"* — this is the queue
  Communication Hub delivery jobs now use.
- Every tenant-scoped job: `TenantScoped` trait (capture in constructor)
  + its `SetTenantContextForJob` middleware (sets context before
  `handle()`, `clearAll()` in `finally`).
- Exemplar job: `App\Jobs\DeliverWebhookJob` — `$tries = 1` (the job owns
  its own retry/backoff state, so queue-level retry must not also
  apply — root CLAUDE.md rule 59), `$timeout = 30`. Delivery is claimed
  via one atomic conditional `UPDATE` (a "processing lease") **before**
  any side effect, so two workers racing the same delivery id cannot
  both act — this is the pattern Communication Hub's delivery job
  reuses (§2.5/§2.6).
- After-commit dispatch: `SomeJob::dispatch(...)->onQueue(...)->afterCommit()`
  (see `WebhookDeliveryService::redeliver()`,
  `DispatchOutboxEvents` command). Never dispatched inline inside the
  same transaction that produced the row.
- `App\Support\Idempotency\IdempotencyGuard` — client-facing API
  idempotency (`Idempotency-Key` header, `(school_id, actor_type,
  actor_id, route_action, idempotency_key)` scope). This is a
  *different* mechanism from internal delivery-creation idempotency
  (root CLAUDE.md rule 33/34/35) — Communication Hub uses a database
  unique constraint for delivery-creation idempotency (§2.5), the same
  pattern `webhook_deliveries`' `(webhook_endpoint_id, event_id)`
  uniqueness already established, not the HTTP-layer guard.

### 1.5 Notifications — a real, existing, narrower system

`App\Models\Notification` + `App\Support\Notifications\NotificationDispatcher`
+ `NotificationProvider` interface already exist
(`database/migrations/2026_08_23_081000_create_notifications_table.php`),
with five providers already registered
(`app/Support/Notifications/Providers/{InAppProvider,LogEmailProvider,
FakeSmsProvider,FakeWhatsAppProvider,FakePushProvider}.php`).

This is genuinely close to what Phase 5A.1 §10 ("Channel Abstraction")
asks for, and it was inspected carefully before deciding *not* to reuse
its table directly. It is a **simpler, different-purpose** system:

- One row per notification intent, single recipient (`recipient_user_id`),
  single channel, sent synchronously in the same call
  (`NotificationDispatcher::send()` creates the row and immediately
  attempts delivery) — there is no separate delivery-attempts table, no
  append-only attempt history, no idempotency key, and its status enum
  is only `pending|sent|delivered|failed`.
- It is used today for **system-generated, single-recipient** notices
  (e.g. `NotifyActorOfSettingChangeConsumer` — "your setting changed"),
  not human-to-human conversations.
- It cannot represent Phase 5A's required richer model: one logical
  message → many recipients → each recipient → many channel deliveries
  → each delivery → many auditable attempts, with the full
  PENDING/QUEUED/.../CANCELLED state machine root brief §11 requires,
  retry idempotency, or a thread/participant concept at all.

**Decision:** Communication Hub introduces its own delivery ledger
(`communication_deliveries` / `communication_delivery_attempts`, §2.5/
§2.6) rather than extending `notifications`, but deliberately **mirrors
its architectural shape** — the same `channel()`/`send()` provider
interface idea, the same "register a driver per channel, dispatch to
the right one" pattern — so a later checkpoint could unify them if a
concrete need arises, without today inventing a competing abstraction
with a different shape for no reason. `App\Domain\Communications\Application\Channels\CommunicationChannelDriver`
is the new interface; it is not the same interface as
`NotificationProvider` because a `NotificationProvider::send()` takes a
`Notification` model — reusing it directly for a `CommunicationDelivery`
model instead of a `Notification` model would misuse the existing
contract. `notifications` and `NotificationDispatcher` are left
completely untouched by this phase.

### 1.6 Domain events / transactional outbox

- `App\Support\Events\ShouldBeOutboxed` — every durable domain event
  implements this; `event()` dispatch writes the outbox row
  synchronously, in the same DB transaction, via a listener registered
  in `AppServiceProvider` (ADR 0025).
- `App\Support\Events\OutboxedEventDefaults` trait supplies
  `eventVersion()`/`campusId()`/`metadata()`/`causationId()` defaults so
  a simple event only implements `eventType()`, `schoolId()`,
  `payload()`.
- Exemplar: `App\Domain\AcademicStructure\Events\SectionCreated`
  (`section.created.v1`).
- `App\Support\Webhooks\WebhookEventRegistry` is the **closed catalog**
  gating which outboxed event types are externally webhook-subscribable
  — an event existing in `domain_event_outbox` does not make it
  reachable as a webhook subscription. Communication Hub's events are
  **not** added to this registry in 5A.1 (root CLAUDE.md rule 45;
  brief §24 excludes webhooks/external delivery entirely).

### 1.7 Audit system

`App\Support\Audit\AuditRecorder` (ADR 0017) — the one place code writes
audit events. `->school($school, $eventType, actor:, subject:, metadata:)`
writes to `App\Models\SchoolAuditEvent` (RLS-protected AND append-only
at the DB privilege level via `TenantRls::makeAppendOnly`, no
update/delete methods exist on the recorder at all). `->platform(...)`
is the platform-level equivalent (`PlatformAuditEvent`). Every Academic
Structure write action calls this inline, in the same transaction as
the state change (see `AcademicYearService`, `SectionController::store()`).
Communication Hub reuses `AuditRecorder::school()` exactly the same way
— no new audit table.

### 1.8 File/document storage

**No canonical file/document/attachment model exists yet.** There is a
`App\Support\Tenancy\TenantStoragePath::for($school, $path)` helper for
building tenant-namespaced storage paths, but no `Document`/`Attachment`/
`Media` Eloquent model or controller anywhere in the codebase. Brief §8
mentions attachments as a *future* capability the message schema should
not preclude; 5A.1 does not implement attachments (out of scope per
brief §24's spirit — no storage foundation exists to build on yet), but
`communication_messages` carries no attachment-shaped columns that would
need to be redesigned later; a future attachment feature would add a
join table referencing whatever the canonical Document model turns out
to be.

### 1.9 Frontend

- Laravel 13 + Inertia v3 (`@inertiajs/vue3` `^3.7`) + Vue 3.5 + Vite +
  TypeScript (`vue-tsc` for type-check), Tailwind utility classes
  inline (no separate component library / design-system package yet).
- `resources/js/app.ts` — standard Inertia bootstrap, page components
  auto-resolved from `resources/js/Pages/**/*.vue` by dotted component
  name (`Inertia::render('App/SchoolSetup/Index')` ↔
  `Pages/App/SchoolSetup/Index.vue`).
- **There is no shared app-shell/sidebar/navigation Vue component yet.**
  Every existing page (`Pages/App/Dashboard.vue`, `SchoolSettings.vue`,
  `SchoolSetup/*.vue`, `SystemStatus.vue`) is a self-contained
  `<script setup>` component with inline Tailwind styling and plain
  `<a href="...">` links for navigation — there is nothing to "plug
  into." Communication Hub's UI matches this same minimal, self-
  contained style rather than inventing a shell that doesn't exist
  elsewhere yet (consistent with root CLAUDE.md rule 2 — no speculative
  infrastructure).
- Routing: session-authenticated Inertia pages live under `/app/...` in
  `routes/web.php`, registered as plain `Route::get/post` calls grouped
  under `Route::middleware('auth')`. There is a **separate**,
  parallel Bearer-token JSON API under `/api/v1/schools/{school}/...`
  (`App\Http\Controllers\Api\V1\*`, tested with Sanctum tokens) intended
  for external/mobile consumers — `App\Http\Controllers\App\SchoolSetupController`'s
  own docblock states this split explicitly. Communication Hub 5A.1
  follows the `/app/...` Inertia pattern only (matching
  `SchoolSetupController`) since the deliverable is the Communication
  Hub *UI*; a JSON API surface is deferred to whichever future
  checkpoint actually needs external/mobile access.
- Controller pattern for this kind of page:
  `TenantContext::requireSchool()` → `authorizeCapability(...)` →
  `Inertia::render('App/...', [...])` for reads;
  `DB::transaction` → write → `AuditRecorder::school(...)` →
  `event(new ...)` → `redirect(...)` for writes (see
  `SchoolSetupController::storeCampus()`).

### 1.10 Migration conventions (from Academic Structure, Phase 0D)

- `TenantRls::enable('table')` in `up()`, `TenantRls::disable('table')`
  first thing in `down()`.
- Every child row referencing a School-scoped parent uses a **composite
  foreign key** against `(id, school_id)` on the parent, plus a
  `unique(['id', 'school_id'])` on the parent itself so the composite FK
  target exists — see `sections`' migration referencing
  `academic_years(id, school_id)`, `campuses(id, school_id)`,
  `grade_levels(id, school_id)`. This is what makes a cross-School
  parent reference impossible at INSERT time, not just at query time
  (root CLAUDE.md rule 70).
- Enum-shaped string columns get a `CHECK` constraint added via
  `DB::statement(...)` immediately after `Schema::create` (not a native
  Postgres `ENUM` type) — see `notifications_status_check`,
  `webhook_deliveries_status_check`.
- Append-only ledgers additionally call `TenantRls::makeAppendOnly($table)`.
- `code` columns: model uses `App\Support\NormalizesCode` (uppercases on
  assignment), controllers accepting a `code` input use
  `App\Support\NormalizesCodeInput` before validation.
- Primary keys: `App\Support\Identifiers\GeneratesUuidV7` (real RFC 9562
  UUIDv7 via `symfony/uid`, generated in PHP — not a DB default, not
  `Str::orderedUuid()`).

---

## 2. Communication domain model

### 2.1 Why these six tables, not one

Per brief §5, the domain is deliberately normalized rather than one
overloaded `communications` table, closely following the exemplar
`Communication → Audience → Recipient resolution → Message →
Delivery → Delivery attempt` pipeline:

```
communication_threads             -- conversational context (who can see/reply)
communication_thread_participants -- membership in a thread
communication_messages            -- one authored message inside a thread
communication_recipients          -- logical "this message is meant for this person"
communication_deliveries          -- one row per (recipient, channel) — may be many per recipient
communication_delivery_attempts   -- append-only, one row per real send attempt
```

### 2.2 Thread

`App\Domain\Communications\Infrastructure\CommunicationThread` —
tenant-owned (`BelongsToSchool` + `TenantRls`), optional `campus_id`
(composite FK to `campuses(id, school_id)`, `restrictOnDelete`),
`thread_type` (`direct|group` — only these two are needed to prove the
architecture; `class`/`support`/etc. from brief §6 are future thread
types, added later without a schema change since `thread_type` is
already an open string column with a `CHECK` constraint that can be
widened), nullable `subject`, `status` (`open|archived|closed`),
`created_by_user_id`, `last_activity_at`, timestamps.

### 2.3 Participant

`CommunicationThreadParticipant` — `(thread_id, school_id)` composite FK
to `communication_threads`, `user_id` (plain FK to central `users` —
see §1.1 on why only `User` is possible today), `joined_at`, `left_at`
(nullable — a participant who has left is not removed, just
timestamped, so message history stays intact), `last_read_at`
(nullable), `muted`/`archived` booleans. `unique(thread_id, user_id)` —
re-adding a user who left updates the same row rather than creating a
second one. A `recipient_type` value is deliberately **not** added to
this table yet: adding it prematurely for identity types that do not
exist (§1.1) would be exactly the kind of speculative field root
CLAUDE.md rule 2 warns against. When Guardian/Student identities land
(Phase 1A), the natural extension is an additional nullable
`guardian_id`/`student_id` column (or a polymorphic `participant_type` +
`participant_id` pair) added in its own migration — not something 5A.1
needs to pre-guess the shape of.

### 2.4 Message

`CommunicationMessage` — `(thread_id, school_id)` composite FK,
`sender_user_id` (plain FK to `users`), `message_type` (`text` only for
5A.1; `system`/`ai` reserved in the `CHECK` constraint's comment for
later, not yet accepted), `body` (text), `priority`
(`normal|important|urgent|critical` — brief §16; `critical` is included
now specifically so a later Phase 5H emergency-broadcast feature does
not need a schema change), `status` (`sent|failed` — no `draft`/
`scheduled` state in 5A.1; composing and sending are one atomic action),
`reply_to_message_id` (nullable self-referencing composite FK, for
future threaded replies — not surfaced in the 5A.1 UI, but the column
exists so it isn't a later migration), `edited_at` (nullable, unused in
5A.1 — no edit UI yet), timestamps.

### 2.5 Recipient

`CommunicationRecipient` — `(message_id, school_id)` composite FK,
`recipient_user_id` (plain FK to `users`), `unique(message_id,
recipient_user_id)`. One row per person a message is logically meant
for (every other active, non-muted participant of the thread at send
time) — **not** the same thing as a delivery; see §2.6.

### 2.6 Delivery ledger

`CommunicationDelivery` — `(recipient_id, school_id)` composite FK,
`channel` (`in_app|email|sms|whatsapp|push`), `status` — the full
closed state set from brief §11: `pending|queued|sending|accepted|sent|
delivered|read|failed|bounced|rejected|expired|cancelled`, enforced by
a `CHECK` constraint. `destination_snapshot` (nullable `jsonb` — where a
future channel would record the exact email/phone address a real
provider was asked to deliver to, for audit purposes even if the
person's stored address later changes; unused for `in_app` since there
is no external destination). `provider`/`provider_message_id` (nullable
strings, unused until a real adapter exists). `attempts`
(`unsignedInteger`, default 0). `processing_lease_expires_at` /
`next_attempt_at` (nullable — the same atomic-claim/backoff-scheduling
columns `webhook_deliveries` uses; not exercised by real retry logic in
5A.1 since `in_app` delivery cannot transiently fail, but present so the
**same job code path** `App\Jobs\ProcessCommunicationDeliveryJob` uses
today for `in_app` is exactly what a future real-provider channel reuses
without a schema change). `queued_at`/`sent_at`/`delivered_at`/
`read_at`/`failed_at` (nullable timestamps). `failure_code`/
`failure_reason` (nullable — never a raw exception message or stack
trace, matching `webhook_delivery_attempts`' `error_class` convention).

**Idempotent-by-construction delivery creation** (brief §21, root
CLAUDE.md rule 30): `unique(recipient_id, channel)` — the exact same
"unique constraint claimed before any side effect" shape
`webhook_deliveries`' `(webhook_endpoint_id, event_id)` uniqueness uses.
A repeated attempt to create a delivery for the same recipient+channel
hits the constraint, not a duplicate row; the service layer catches
`UniqueConstraintViolationException` and treats it as "already exists,"
never a `SELECT`-then-`INSERT` race.

### 2.7 Delivery attempts

`CommunicationDeliveryAttempt` — `(communication_delivery_id,
school_id)` composite FK, **append-only**
(`TenantRls::makeAppendOnly`), `attempt_number`
(`unique(communication_delivery_id, attempt_number)` — the same
authoritative-numbering guarantee root CLAUDE.md rule 40 requires,
derived from the delivery's own atomic processing-lease claim, never a
bare `count() + 1`), `started_at`/`completed_at`, `outcome`
(`success|transient_failure|permanent_failure` — mirrors
`webhook_delivery_attempts`' classification exactly, even though 5A.1's
only real driver, `in_app`, can only ever produce `success`),
`provider_reference` (nullable), `failure_code`/`failure_message`
(nullable), `duration_ms`.

### 2.8 Channel abstraction

`App\Domain\Communications\Domain\CommunicationChannel` (PHP backed
enum: `InApp = 'in_app'`, `Email = 'email'`, `Sms = 'sms'`,
`WhatsApp = 'whatsapp'`, `Push = 'push'`).

`App\Domain\Communications\Application\Channels\CommunicationChannelDriver`
(interface): `channel(): CommunicationChannel`,
`send(CommunicationDelivery $delivery): CommunicationDeliveryResult`.

`App\Domain\Communications\Application\Channels\InAppChannelDriver` is
the only concrete driver registered in 5A.1 — delivering "in-app" means
nothing more than the row existing and being visible in the recipient's
Communication Hub inbox, so `send()` does no I/O and always succeeds.
`Email`/`Sms`/`WhatsApp`/`Push` have no registered driver yet (brief
§10: "should remain adapter-ready" — the enum case and the interface
exist; no live send happens, matching brief §24's exclusion of every
real provider). Attempting to create a delivery for a channel with no
registered driver is rejected at the service layer with a clear
domain exception rather than silently doing nothing.

`App\Domain\Communications\Application\Channels\CommunicationChannelRegistry`
holds the `channel => driver` map (bound as a singleton in
`CommunicationServiceProvider`, mirroring `NotificationDispatcher`'s own
`registerProvider()` pattern from §1.5 — the same shape, deliberately
not the same class, since it maps `CommunicationDelivery`, not
`Notification`).

### 2.9 Delivery processing

`App\Jobs\ProcessCommunicationDeliveryJob` (queue:
`QueueName::Notifications`) — constructed with `(schoolId, deliveryId)`,
`TenantScoped`-captured, `$tries = 1`/`$timeout = 15`. `handle()`://
1. Sets tenant context (via `SetTenantContextForJob`, automatic).
2. Atomically claims the delivery (conditional `UPDATE ... WHERE status
   IN ('pending','queued') AND (lease NULL OR expired)`) — a second
   worker racing the same delivery id gets 0 affected rows and returns
   immediately, exactly `DeliverWebhookJob::claim()`'s pattern.
3. Resolves the driver for the delivery's channel from
   `CommunicationChannelRegistry`; if none is registered, records a
   `permanent_failure` attempt and marks the delivery `failed` with
   `failure_code = 'channel_not_supported'` (defensive — 5A.1 never
   actually dispatches this job for a non-`in_app` channel, since the
   message service only creates `in_app` deliveries today).
4. Calls the driver, records exactly one
   `CommunicationDeliveryAttempt` row, updates the delivery's status/
   timestamps from the result.
5. Dispatched via `->afterCommit()` from
   `CommunicationMessageService::send()`, never inline in the same
   transaction that created the delivery row (root CLAUDE.md rule 38).

---

## 3. Multi-tenant security

Every Communication Hub table uses `BelongsToSchool` (Eloquent-level
scope) **and** `TenantRls::enable()` (Postgres RLS, forced). Every
cross-table reference to a School-scoped parent is a composite FK
against `(id, school_id)`, so a cross-School reference is rejected by
the database at INSERT time, not just filtered out at query time.
`tests/Feature/Postgres/CommunicationsRlsIsolationTest.php` proves, at
the raw-SQL layer, independent of Eloquent: RLS is enabled+forced on
every table, no-context sees zero rows, School A cannot read School B's
rows, and cross-School writes affect zero rows — the same four-part
proof `AcademicStructureRlsIsolationTest` established for Academic
Structure.

## 4. Platform admin privacy

No new platform-level "read every School's messages" capability is
introduced. `communications.audit.view` (school-scoped) lets an
authorized School-level actor see the **audit trail** (thread created,
participant added, message created — timestamps/actors/subjects) via
the existing `SchoolAuditEvent` ledger, never message bodies (audit
metadata never includes `body`). No platform capability grants read
access to `communication_messages` content — a Platform Super Admin has
no database RLS bypass (root CLAUDE.md rule 26) and no application-layer
capability path to message bodies exists in this checkpoint. A future
platform-level *operational* metrics view (delivery success rates,
queue depth) would follow the same pattern
`OperationalStatusService`/`platform.operations.view` already
established — deferred, not needed to prove 5A.1's architecture.

## 5. Deferred work (per brief §24, unchanged)

No live email/SMS/WhatsApp/push provider, no webhooks, no bulk
announcements, no class/section audience resolution, no scheduling UI,
no templates, no AI, no emergency escalation. Additionally, specific to
this checkpoint's findings: no Guardian/Student participants (§1.1 — the
identity foundation does not exist yet), no attachments (§1.8 — no
canonical file/document model exists yet to attach to).

---

## Implementation

As-built record of what actually shipped on
`feature/phase-5a-communication-hub`.

### Schema

Six new migrations (`apps/platform/database/migrations/2026_08_23_1000{0..5}0_*`),
all via `TenantRls::enable()`, all with a reversible `down()`:

- `communication_threads` — `campus_id` composite-FK to `campuses(id, school_id)`.
- `communication_thread_participants` — composite-FK to `communication_threads`, `unique(thread_id, user_id)`.
- `communication_messages` — composite-FK to `communication_threads`, self-referencing composite-FK `reply_to_message_id`.
- `communication_recipients` — composite-FK to `communication_messages`, `unique(message_id, recipient_user_id)`.
- `communication_deliveries` — composite-FK to `communication_recipients`, `unique(recipient_id, channel)`.
- `communication_delivery_attempts` — composite-FK to `communication_deliveries`, **append-only** (`TenantRls::makeAppendOnly`), `unique(communication_delivery_id, attempt_number)`.

All six migrations were run against a real PostgreSQL test database via
the canonical `platform:test-db-reset` command, including the
self-referencing composite FK on `communication_messages` — confirmed
to migrate and roll back cleanly.

### Domain Models

`app/Domain/Communications/`:

- `Domain/CommunicationChannel.php`, `Domain/CommunicationPriority.php` — enums.
- `Infrastructure/{CommunicationThread,CommunicationThreadParticipant,CommunicationMessage,CommunicationRecipient,CommunicationDelivery,CommunicationDeliveryAttempt}.php` — Eloquent models, `BelongsToSchool` + `GeneratesUuidV7` + `HasFactory`.
- `Application/Channels/{CommunicationChannelDriver,CommunicationDeliveryResult,InAppChannelDriver,CommunicationChannelRegistry}.php` — the channel abstraction (§2.8).
- `Application/{CommunicationThreadService,CommunicationMessageService}.php` — the two write paths.
- `Application/Exceptions/{CommunicationException,NotThreadParticipantException,InvalidParticipantException,UnsupportedChannelException}.php`.
- `Events/{CommunicationThreadCreated,CommunicationParticipantAdded,CommunicationMessageCreated}.php` — outboxed, not webhook-registered.
- `Http/Controllers/CommunicationHubController.php` — the one, thin, Inertia controller.
- `app/Jobs/ProcessCommunicationDeliveryJob.php` — queue: `QueueName::Notifications`.

`CommunicationChannelRegistry` is bound as a singleton in
`App\Providers\PlatformServiceProvider` (extended, not a new provider —
matches how `NotificationDispatcher` is already wired there), with
`InAppChannelDriver` as the only registered driver.

### Services

- `CommunicationThreadService::createThread()` — creates the thread,
  adds the creator plus named participants (each validated against a
  real active `SchoolMembership`, root CLAUDE.md rule 19), one
  `communication.thread.created` audit event, one
  `CommunicationThreadCreated` domain event, all in one transaction.
  `addParticipant()`/`removeParticipant()` are separately callable
  (each independently audited).
- `CommunicationMessageService::send()` — verifies the sender is an
  active thread participant (checked *inside* `TenantContext::withSchool()`,
  not before — an early implementation bug caught by the service-layer
  tests, since the participant row is RLS-protected), then in one
  `DB::transaction()`: creates the message, one `CommunicationRecipient`
  + one `in_app` `CommunicationDelivery` per other active, non-muted
  participant, updates `last_activity_at`, writes one
  `communication.message.created` audit event and one
  `CommunicationMessageCreated` domain event. `ProcessCommunicationDeliveryJob`
  is dispatched per delivery only via `->afterCommit()`, after the
  transaction returns (root CLAUDE.md rule 38).

### Authorization

`communications.view`, `communications.send`, `communications.reply`,
`communications.manage`, `communications.audit.view` added to
`CapabilityAndRoleSeeder`. `school_admin` gets all five;
`principal` gets `view`/`send`/`reply` only (no `manage`/`audit.view`,
matching the existing pattern of Principal having narrower
administrative reach than School Admin). Every controller action uses
`AuthorizesCapability`; `CommunicationHubController::show()` additionally
requires the actor be either an active thread participant or hold
`communications.manage` (§4).

### Tenancy Behavior

Every table RLS-enabled and forced; every parent reference composite-FK
protected. Proven in
`tests/Feature/Postgres/CommunicationsRlsIsolationTest.php` (5 tests:
RLS enabled+forced, no-context isolation, cross-School read isolation,
cross-School write isolation, append-only enforcement for
`communication_delivery_attempts`) — all passing against real
PostgreSQL.

### Routes

```
GET  /app/communications              index   communications.view
POST /app/communications              store   communications.send
GET  /app/communications/{thread}     show    communications.view (+ participant or communications.manage)
POST /app/communications/{thread}/messages  storeMessage  communications.reply
```

Registered in `routes/web.php` under the existing `auth` middleware
group, following `SchoolSetupController`'s convention exactly. A
`canViewCommunications`-gated link was added to the existing
`App/Dashboard.vue`/`DashboardController` navigation.

### UI

`resources/js/Pages/App/Communications/{Index,Show}.vue` — plain
`<script setup>` Tailwind components matching the existing minimal
style (no shared app shell exists yet to plug into, §1.9). `Index.vue`
shows the thread list with an empty state and a compose form; disabled
"Announcements/Scheduled/Drafts/Failed" nav items are shown greyed out
with a tooltip, never as fake/mock data (brief §18). `Show.vue` shows
the message list, participant count, and a reply form gated on
`canReply`. `vue-tsc --noEmit`, ESLint, and Prettier all pass clean.

### Tests

25 new tests, all passing against real PostgreSQL (ADR 0024):

- `tests/Feature/Postgres/CommunicationsRlsIsolationTest.php` — 5 tests.
- `tests/Feature/Communications/CommunicationThreadServiceTest.php` — 5 tests (creation, audit, invalid-participant rejection, participant removal, cross-School membership rejection).
- `tests/Feature/Communications/CommunicationMessageServiceTest.php` — 5 tests (recipient+delivery creation, sender exclusion, non-participant rejection, delivery-creation idempotency, critical priority).
- `tests/Feature/Communications/ProcessCommunicationDeliveryJobTest.php` — 3 tests (successful processing, double-run idempotency, unsupported-channel handling).
- `tests/Feature/App/CommunicationHubTest.php` — 7 tests (guest redirect, no-role denial, empty state, full compose→send→view flow, principal-role bystander denial, non-participant denial, cross-School 404).

Full regression suite: **360/360 passing** (`composer test` equivalent
run via `php artisan test` with `QUEUE_CONNECTION=sync` and the test
database explicitly targeted — see the note below on an environment
pitfall this run surfaced). `vendor/bin/pint --test`, `phpstan analyse
--memory-limit=512M` (0 errors, 208/208 files), `npm run lint`, `npm
run format:check`, `npm run type-check` all clean.

**Environment note (not a code defect, but worth recording):** this
repository's `apps/platform/.env` sets `QUEUE_CONNECTION=redis`, and
the `platform`/`queue` Docker services load the entire `.env` via
`env_file`, which injects it as a real process environment variable —
`phpunit.xml`'s `<env name="QUEUE_CONNECTION" value="sync"/>` cannot
override an already-set environment variable (same class of pitfall as
the documented `DB_DATABASE`/`TestDatabaseGuard` incident, CLAUDE.md
rule 52, just for queue connection instead of database). Running tests
via `docker compose run` therefore requires explicitly passing
`-e QUEUE_CONNECTION=sync`, or `ProcessCommunicationDeliveryJob`
dispatches onto the real `redis` queue and a synchronous test assuming
immediate processing will see a stale `pending` delivery. This did not
affect application code — only how this checkpoint's test runs needed
to be invoked from a clean shell/container.

### Idempotency Design

Two independent layers, both database-constraint-driven, never
check-then-insert (root CLAUDE.md rule 30):

1. **Delivery creation**: `unique(recipient_id, channel)` on
   `communication_deliveries`. `CommunicationMessageService::createDelivery()`
   wraps the insert in its own `DB::transaction()` (a Postgres
   SAVEPOINT when nested inside an outer transaction) and catches
   `UniqueConstraintViolationException`, returning the existing row.
   Proven in `CommunicationMessageServiceTest::repeated_delivery_creation_for_the_same_recipient_and_channel_is_idempotent`.
2. **Delivery processing**: `ProcessCommunicationDeliveryJob::claim()`
   — an atomic conditional `UPDATE ... WHERE status IN ('pending','queued')
   AND (lease NULL OR expired)`, mirroring `DeliverWebhookJob::claim()`
   exactly. A second run against an already-terminal delivery affects 0
   rows and returns without creating a second attempt. Proven in
   `ProcessCommunicationDeliveryJobTest::running_the_job_twice_for_the_same_delivery_never_produces_a_second_attempt`.

### Delivery-State Design

See §2.6/§2.7 for the full state set. In this checkpoint, `in_app`
deliveries only ever transition `pending → sending → delivered` (the
`InAppChannelDriver` cannot fail) or `pending → sending → failed`
(defensive-only path, exercised when a delivery somehow targets a
channel with no registered driver —
`ProcessCommunicationDeliveryJobTest::a_delivery_on_a_channel_with_no_registered_driver_is_marked_failed_not_silently_dropped`).
The remaining states (`queued`, `accepted`, `sent` as distinct from
`delivered`, `read`, `bounced`, `rejected`, `expired`, `cancelled`) are
schema-ready but unreached until a real external channel exists.

### Deferred Features

Confirmed unchanged from §5: no live external providers, no webhooks,
no bulk announcements/audience resolution, no scheduling, no templates,
no AI, no emergency broadcast workflow, no attachments, no Guardian/
Student participants.

### Known Limitations

- §1.1: only `User` (via `SchoolMembership`) can be a thread
  participant/sender/recipient — the Guardian/Student identity
  foundation the original brief assumed does not exist anywhere in this
  repository yet (confirmed against both `main` and the empty
  `phase/1a-student-guardian-identity` branch).
- §1.8: no attachment support — no canonical file/document model exists
  to attach to yet.
- No retry/backoff is exercised for `in_app` (it cannot transiently
  fail) — the job's lease/backoff columns are schema- and code-ready
  but only proven for the immediate-success and permanent-failure
  paths in this checkpoint.
- No JSON API surface (`/api/v1/schools/{school}/communications/...`)
  — only the session-authenticated Inertia UI, matching the brief's
  "Communication Hub UI" deliverable; a future mobile/external
  integration would need its own `Api\V1` controller layer, same split
  Academic Structure already has.

### Next Checkpoint

**Phase 5A.2 — Announcement & Audience Resolution Foundation.**
