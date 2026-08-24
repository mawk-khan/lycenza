# Phase 5A.4 — Communication Templates & Scheduling Foundation

## 1. Objective

Two closely related capabilities, both extending the EXISTING
Announcement aggregate and delivery pipeline (Phases 5A.1–5A.3) rather
than building a second one:

- **Reusable Communication Templates** — School-owned source content
  an Announcement can be started from.
- **Deterministic Scheduled Announcements** — `DRAFT → SCHEDULED →
  (due) → PUBLISHED`, with the audience resolved authoritatively at
  publication time, not at scheduling time.

## 2. Architecture audit (before writing anything)

- **Announcement states** (Phase 5A.2/5A.3): `draft|published|cancelled`,
  enforced by a Postgres CHECK constraint, with an atomic conditional-
  UPDATE claim in `AnnouncementService::publish()`/`::cancel()`. This
  checkpoint widens both the CHECK and the claims — never a second
  state machine.
- **`AnnouncementService`/`CommunicationDeliveryFactory`/
  `communication_announcement_channels`** (Phase 5A.2/5A.3): fully
  reused. `publish()` now also accepts a due `scheduled` row; channel
  selection is untouched.
- **Scheduler precedent**: `routes/console.php` already runs
  `platform:outbox-dispatch`, `platform:webhook-deliveries-redispatch`,
  `platform:communication-deliveries-redispatch` every minute with
  `withoutOverlapping()`, each backed by its own atomic-claim command
  (`App\Console\Commands\RedispatchDueWebhookDeliveries`/
  `RedispatchDueCommunicationDeliveries`) using a per-School
  `TenantContext::withSchool()` loop. `communications:publish-scheduled`
  mirrors this exactly.
- **Timezone**: `schools.timezone` (Phase 0B, `App\Models\School`)
  is a REAL, per-School, user-editable field (default `Asia/Kolkata`),
  validated only as `required|string|max:64` — not restricted to
  actual IANA identifiers. `config('app.timezone')` is `UTC`; every
  existing `timestamp()` column in this schema is implicitly UTC.
  Campus has no timezone field (deliberately, per its own migration's
  docblock). This means the brief's "preferred model" (input in School
  timezone → stored UTC → compared UTC → displayed in School timezone)
  is directly buildable — see §11.
- **Distributed locking**: `withoutOverlapping()` is the ONLY lock
  primitive used at the schedule level for this whole family of
  commands; real concurrency safety always comes from an atomic
  conditional `UPDATE ... WHERE` inside the domain service itself, not
  from any lock. `App\Support\Concurrency\TenantLock` exists but is not
  used here, matching precedent (webhook/delivery redispatch commands
  don't use it either).
- **Queue driver / after-commit**: unchanged Phase 5A.1 discipline —
  `ProcessCommunicationDeliveryJob::dispatch(...)->afterCommit()`,
  reused verbatim; scheduling introduces no new job.
- **Soft-delete/archive convention**: no model in this codebase uses
  `SoftDeletes`. Reference entities (`GradeLevel`/`Subject`/`Room`/...)
  use a plain `status` (`active`/`inactive`) column with no DELETE
  endpoint (root CLAUDE.md rule 73) — Templates follow this exact
  precedent.
- **Audit conventions**: `App\Support\Audit\AuditRecorder::school()`
  (append-only `SchoolAuditEvent` rows) plus, for significant lifecycle
  transitions only, an outboxed domain event
  (`App\Support\Events\ShouldBeOutboxed`) — `AnnouncementService::updateDraft()`'s
  own precedent (audit-only, no event, for a mere content edit) is
  followed for Template `update()` too.
- **Text sanitization**: unchanged from Phase 5A.3 — plain text only,
  no HTML rendering anywhere in Communication Hub. Templates inherit
  this; no new sanitization boundary was needed.
- **No prior versioning precedent** exists anywhere in this codebase
  to follow or diverge from — see §5.

## 3. Template domain

`communication_templates`: `id`, `school_id`, `created_by_user_id`,
`name`, `description` (nullable), `template_type` (CHECK-restricted to
`'announcement'` only — brief §5 explicitly warns against seeding
business-specific types), `subject` (nullable — maps to an
Announcement's `title` when applied), `body`, `priority` (nullable —
no suggested priority is a valid state), `status`
(`active`/`inactive`). RLS via `TenantRls`, composite `unique(id,
school_id)` for the same reason every other tenant-owned table has one
(root CLAUDE.md rule 70's downstream FK target).

`App\Domain\Communications\Application\CommunicationTemplateService`
is the sole write path: `create()`, `update()`, `setActive()` — each
validate → write → audit, mirroring `AnnouncementService`'s shape
exactly.

## 4. Template ownership

A Template belongs to exactly one School (RLS + `BelongsToSchool`,
proven at the raw-SQL layer in
`CommunicationTemplatesRlsIsolationTest`). No cross-School read, write,
or "apply" path exists anywhere — `CommunicationTemplateController`'s
every action re-derives the School from `TenantContext`, never from
request input, and `AnnouncementController::verifiedTemplateId()`
re-checks a client-supplied `source_template_id` against the current
School's `active` templates before ever persisting it (root CLAUDE.md
rule 19 applied to a template id exactly like a School id).

## 5. Template snapshot/version approach

**No `communication_template_versions` table.** Brief §7 explicitly
warns against over-engineering this. `communication_announcements`
already owns its own `title`/`body`/`priority` columns (Phase 5A.2);
applying a Template simply **copies** `subject`/`body`/`priority` into
those columns ONCE, at `createDraft()` time
(`AnnouncementController::store()` → `verifiedTemplateId()` →
`AnnouncementService::createDraft(..., sourceTemplateId: ...)`). A
plain `communication_announcements.source_template_id` column (a
composite FK against `communication_templates(id, school_id)`,
`restrictOnDelete()`) is kept purely as an informational reference —
never a live join, never re-read for content. Editing the Template
afterward touches only the `communication_templates` row; the
Announcement's own `title`/`body`/`priority` are physically separate
columns that were already written and are never re-synced.

Proven directly in
`AnnouncementSchedulingTest::template_content_is_copied_into_the_announcement_and_later_template_edits_never_change_it`
and, through the FULL scheduled-publication path, in
`PublishScheduledAnnouncementsTest`'s use of `createDraft(sourceTemplateId:
...)` + `schedule()` + the due-publication command (the mandatory §30
scenario: Template A → Announcement → schedule → edit Template to B →
due-publish → content still Template-A-derived).

## 6. Merge-tag decision

**Not implemented.** Brief §8 explicitly permits deferring this when
it would "significantly enlarge the checkpoint," and offers "a solid
template system without merge tags" as the preferred outcome over an
unsafe/speculative one. Student/Guardian/Class identity — the fields a
useful merge-tag set would need (`{{student.name}}`,
`{{guardian.name}}`, ...) — do not exist yet (Phase 1A). A `{{school.name}}`-only
engine was considered and rejected as not worth the added surface for
a single variable a sender can just type themselves. Deferred
entirely; documented here as a deliberate choice, not an oversight.

## 7. Permissions

New capability: `communications.templates.manage` (School-scoped),
granted to `school_admin` and `principal` — the same two roles
`communications.announce` already reaches. Deliberately separate from
`.announce`: authoring reusable content other senders will see and
pick from is a distinct, narrower-grantable right (brief §11) from
publishing an announcement. **Using** an existing active Template
(pre-filling the composer) requires only the EXISTING
`communications.announce` check the composer already enforces — no new
capability gates reading a template for that purpose.
Scheduling/rescheduling/cancelling reuse `communications.announce` /
the existing creator-or-`communications.manage` ownership check
`publish()`/`cancel()` already used — no new capability there either
(brief §36).

## 8. Template UI

`/app/communications/templates` (index, paginated, search-by-name,
active/inactive filter), `/create`, `/{template}/edit` (also handles
update + Activate/Deactivate + "Use Template" → navigates to
`/app/communications/announcements/create?template={id}`). "Templates"
is now a real Communication Hub nav link (previously only
Conversations/Announcements existed).

## 9. Announcement state model

`draft → scheduled → published`, `draft → published` (unchanged
manual path), `draft → cancelled`, `scheduled → cancelled`. The status
CHECK constraint widened from `draft|published|cancelled` to
`draft|scheduled|published|cancelled` (additive migration, per root
CLAUDE.md rule 15). `published → scheduled` and `cancelled →
published` remain structurally impossible — no code path attempts
either, and every transition is an atomic conditional `UPDATE ...
WHERE status = <required-source-status>`, so even a forged/racing
request cannot produce an invalid transition (see §14).

`CommunicationAnnouncement::isEditable()` (`isDraft() ||
isScheduled()`) is the ONE guard `AnnouncementService::updateDraft()`
now checks — a scheduled Announcement's own `title`/`body`/`priority`/
audience/channels remain editable right up until it is claimed for
publication (brief §31), through the SAME method that already handled
draft edits (not a new one) — its precondition was simply widened.

## 10. Scheduling model

Extends the EXISTING `communication_announcements` row (brief §14: "do
not create a separate scheduled_messages silo") with three columns:
`scheduled_at` (nullable `timestamp`, canonical UTC), `scheduled_by_user_id`
(FK `users`), `source_template_id` (see §5). **No `claimed_at`/
processing-lease column.** Unlike `ProcessCommunicationDeliveryJob`/
`DeliverWebhookJob` (which split "claim" from "do the work" across a
job that can itself crash mid-attempt, needing a lease to recover a
stale claim), `AnnouncementService::publish()` performs its
scheduled/draft → published claim INSIDE the same single
`DB::transaction()` as the entire publish body. A crash between claim
and commit is structurally impossible — the transaction either commits
whole (published) or nothing persists at all (still `scheduled`, never
actually claimed). A lease/marker column would be dead weight for this
design; see §17 for what DOES need recovery handling.

## 11. Timezone semantics (exact)

- **Input timezone**: the School's own `schools.timezone`
  (`App\Support\Tenancy\SchoolTimezone::resolve()`), read from an
  `<input type="datetime-local">` (a naive, timezone-less
  `YYYY-MM-DDTHH:mm` string) on the Show page's Schedule/Reschedule
  form.
- **Conversion point**: `AnnouncementController::schedule()`, ONCE,
  via `Carbon::parse($input, SchoolTimezone::resolve($school))->utc()`,
  before the value ever reaches `AnnouncementService`.
- **Stored timezone**: canonical UTC (`communication_announcements.scheduled_at`,
  a plain `timestamp` column — the same implicit-UTC convention every
  other timestamp column in this schema already uses).
- **Scheduler comparison timezone**: UTC only —
  `PublishScheduledAnnouncements`'s due-query
  (`scheduled_at <= now()`) and `AnnouncementService::publish()`'s
  claim WHERE clause both compare against PHP `now()` (UTC, since
  `config('app.timezone') = 'UTC'`), never re-interpreting the stored
  value through any timezone.
- **Display timezone**: `AnnouncementController::show()`/`index()`
  pass `schoolTimezone` (the raw IANA string) to Inertia;
  `Announcements/Show.vue` formats the stored UTC ISO string back into
  the School's local wall-clock time via the browser's native
  `Intl.DateTimeFormat(..., { timeZone: schoolTimezone })` — no
  timezone-conversion library needed client-side.
- **Limitation, documented explicitly (brief §18)**: `schools.timezone`
  is validated only as `required|string|max:64`, not restricted to
  `DateTimeZone::listIdentifiers()`. `SchoolTimezone::resolve()` never
  throws for a corrupted value — it falls back to `config('app.timezone')`
  (UTC), the same safe default every other timestamp in this codebase
  already assumes. A future School-profile checkpoint should tighten
  `timezone` validation at the source; this class's fallback is a
  documented safety net, not a fix for that gap.
- **Proven in `Tests\Feature\App\AnnouncementSchedulingTimezoneTest`**:
  a School-local time converts to the exact correct UTC instant; two
  Schools in different timezones scheduling the identical local
  wall-clock string resolve to UTC instants 5.5 hours apart; a real
  DST transition (`America/New_York`) converts correctly (proving
  `DateTimeZone`/Carbon do genuine DST-aware conversion, not a fixed
  offset); a School-local midnight crossing a UTC calendar-day
  boundary converts correctly.

## 12. Due-time audience resolution

Scheduling **never** resolves or persists a recipient snapshot (brief
§16/§17) — only the audience DEFINITION (already stored via
`audience_type`/audience members/`communication_announcement_channels`,
exactly as a draft already stores it). The Show page's audience
preview for a `scheduled` Announcement is computed the SAME live,
non-authoritative way a draft's already is
(`AnnouncementService::previewAudience()`, reused unchanged) — brief
§17: "not the historical authoritative recipient set." The
authoritative, immutable snapshot is produced only when `publish()`
actually runs at due time, reusing Phase 5A.2's audience-resolution
infrastructure completely unchanged.

Proven directly: a member who goes inactive between scheduling and due
time is excluded from the snapshot; a new member who joins in that
window is included (school-wide audience) — both in
`PublishScheduledAnnouncementsTest`.

## 13. Scheduler command

`php artisan communications:publish-scheduled`
(`App\Console\Commands\PublishScheduledAnnouncements`). Performs NO
claiming, audience resolution, recipient/delivery creation, or
message-body handling itself (brief §19) — for each School (via the
same `TenantContext::withSchool()` per-School loop as
`RedispatchDueWebhookDeliveries`), it reads due candidate ids
(`status = 'scheduled' AND scheduled_at <= now()`, ordered by
`scheduled_at`, bounded batch size from `config('communications.scheduling.batch_size')`)
and calls `AnnouncementService::publish()` for each — the EXISTING
publish path, reused, not reimplemented.

## 14. Atomic claiming

The SAME conditional-UPDATE claim in `AnnouncementService::publish()`
now serves both a manual "Publish Now" (`status = 'draft'`) and a
scheduler-driven due publication (`status = 'scheduled' AND
scheduled_at <= now()`) — one `WHERE` clause, one `UPDATE`, no new
claim mechanism. The command itself claims nothing; it relies entirely
on this. Two overlapping scheduler runs (or a scheduler run racing a
manual publish, or racing a cancel/reschedule) both calling `publish()`/
`cancel()`/`reschedule()` for the same announcement: exactly one
UPDATE's WHERE clause matches (Postgres READ COMMITTED semantics — the
loser's WHERE re-evaluates against the winner's already-committed
state), the other affects 0 rows and is handled as either an idempotent
no-op (`publish()` sees `status === 'published'` and returns the fresh
state, matching Phase 5A.2's proven re-publish behavior) or a rejected
transition (`cancel()`/`reschedule()` throw
`InvalidAnnouncementTransitionException`).

## 15. Idempotency

Rerunning `communications:publish-scheduled` after a successful run
creates zero duplicate recipient snapshots, `CommunicationRecipient`
rows, or channel deliveries — proven in
`PublishScheduledAnnouncementsTest::rerunning_the_command_creates_no_duplicate_recipients_or_deliveries`,
via the exact same idempotency chain Phase 5A.2/5A.3 already
established (`publish()`'s atomic claim → `unique(announcement_id,
user_id)` on the recipient snapshot → `unique(recipient_id, channel)`
on deliveries).

## 16. Cancellation / rescheduling

- **Cancellation**: `AnnouncementService::cancel()`'s claim widened
  from `status = 'draft'` to `status IN ('draft', 'scheduled')`. The
  audit event type is chosen from the PRE-cancellation status
  (`announcement.schedule_cancelled` vs `announcement.cancelled`) so
  the two cases stay distinguishable in the audit trail even though
  they share one method. A cancellation racing the scheduler's own
  claim is resolved by this SAME conditional UPDATE — if the scheduler
  already published it, cancellation's WHERE matches 0 rows and is
  correctly rejected (brief §25).
- **Rescheduling**: `AnnouncementService::reschedule()`, a dedicated
  method (distinct from `schedule()` so the audit event
  — `announcement.rescheduled`, capturing old/new `scheduled_at` in
  metadata — stays precise), claims `WHERE status = 'scheduled'`
  before writing the new time. Exposed through ONE HTTP endpoint
  (`POST .../{announcement}/schedule`) that dispatches to `schedule()`
  or `reschedule()` based on the Announcement's current status — one
  composer form covers both cases (brief §24's "if safe rescheduling
  adds unreasonable complexity, implement cancellation + new schedule"
  was evaluated and rejected: reusing the existing atomic-claim
  discipline made a real in-place reschedule no more complex than the
  alternative).

## 17. Failure recovery

The one genuine recovery need: a due Announcement whose `publish()`
call THROWS (e.g. `EmptyAudienceException`) rolls the WHOLE transaction
back to `scheduled`, with its ORIGINAL (still-past) `scheduled_at` —
without intervention, the next run (one minute later) would retry
immediately and likely fail identically, forever. `PublishScheduledAnnouncements::backOff()`
pushes `scheduled_at` forward by `config('communications.scheduling.failure_backoff_seconds')`
(default 900s / 15 minutes) via its own defensive conditional
`UPDATE ... WHERE status = 'scheduled'` — a no-op if the row was
cancelled or somehow published concurrently in the meantime. This
turns a potential infinite tight retry loop into a bounded-rate retry;
it is deliberately NOT a hard failure cap, since a genuinely transient
condition (an empty audience) can resolve differently once School
membership changes — see
`PublishScheduledAnnouncementsTest::a_due_announcement_whose_audience_resolves_empty_is_backed_off_not_published`.
Every attempt (success or failure) is logged
(`communications.scheduled_publish.started/completed/failed`) with
`school_id`/`announcement_id`/a safe `error_class` label only — never
the raw exception message, message body, or recipient list.

## 18. Tenant context

`PublishScheduledAnnouncements` iterates Schools via `School::query()->orderBy('id')->chunk(100, ...)`,
calling `TenantContext::withSchool($school, ...)` around each School's
own due-query and `publish()` calls — `withSchool()`'s own
`finally`-restore (verified in `App\Support\Tenancy\TenantContext::withSchool()`)
guarantees one School's context (and the Postgres RLS session
variable it sets) cannot leak into the next School's work, and a
`Throwable` from one School's `publish()` call is caught locally
(per-announcement try/catch inside `publishDueForSchool()`) so it
cannot abort the whole command's remaining Schools. Proven directly
with two real Schools each holding a due Announcement in ONE command
run (`PublishScheduledAnnouncementsTest::two_schools_with_due_announcements_each_resolve_only_their_own_audience_in_one_run`)
— each School's recipient count reflects only its own membership.

## 19. RLS

`communication_templates` and the three new `communication_announcements`
columns follow the established conventions exactly: `TenantRls::enable()`,
composite `unique(id, school_id)`, and (for `source_template_id`) a
composite FK against `communication_templates(id, school_id)`. Proven
at the raw-SQL level: RLS enabled+forced, no-context sees zero rows,
School A cannot read/write School B's template
(`CommunicationTemplatesRlsIsolationTest`).

## 20. Channel semantics

Scheduling never alters what `communication_announcement_channels`
already governs (Phase 5A.3, unchanged) — a scheduled Announcement's
requested channels are set at draft time exactly as before, and
`publish()` (whether reached manually or via the scheduler) reads them
via the SAME `requestedChannels()` helper. Proven:
`an_in_app_only_schedule_creates_only_an_in_app_delivery` and
`an_in_app_plus_email_schedule_creates_both_channel_deliveries_safely`
in `PublishScheduledAnnouncementsTest`.

## 21. Partial channel failure at execution time

Unchanged from Phase 5A.3's semantics, reused as-is: if `email` is
disabled between scheduling and due execution,
`EmailChannelDriver::send()`'s own `email_channel_disabled` check
(re-evaluated at send time, not just at schedule time) fails that one
channel deterministically — the canonical Announcement still publishes,
its IN_APP delivery still succeeds, and the failed EMAIL delivery is a
durable, auditable record (never silently discarded), exactly like a
manually-published Announcement's partial email failure already
worked. No new code was needed for this — it is a direct consequence
of `publish()` being reused unchanged.

## 22. Audit events

`communication_template.created/updated/activated/deactivated`
(`CommunicationTemplateService`, `AuditRecorder::school()` only, no
domain event — mirrors `AnnouncementService::updateDraft()`'s own
audit-only precedent for a content edit).
`announcement.scheduled` (audit + new outboxed
`CommunicationAnnouncementScheduled` event, mirroring
`CommunicationAnnouncementCreated`'s shape), `announcement.rescheduled`
(audit only — a schedule-time adjustment, not a new lifecycle state),
`announcement.cancelled` / `announcement.schedule_cancelled` (one
`cancel()` method, audit event type chosen by pre-cancellation status —
see §16). The scheduler's own per-attempt operational trace
(`communications.scheduled_publish.started/completed/failed`) is a
STRUCTURED LOG line, not a SchoolAuditEvent/domain event — it is about
"the scheduler attempted X," not a new fact about the Announcement's
own audit trail (which already gets `announcement.published` from the
reused `publish()` call itself). None of the new events are registered
in `App\Support\Webhooks\WebhookEventRegistry` — internal-only, same
as every existing Announcement event.

## 23. Scaling

`PublishScheduledAnnouncements` never loads all due Announcements into
memory — bounded per-School batches (`config('communications.scheduling.batch_size')`,
default 100), deterministic ordering (`scheduled_at` ascending).
Recipient/delivery creation inside `publish()` continues to use Phase
5A.2's existing `CHUNK_SIZE = 500` batching, completely unchanged. No
new bulk-campaign orchestration was introduced.

## 24. Testing

56 new tests: `CommunicationTemplateServiceTest` (3),
`CommunicationTemplatesRlsIsolationTest` (4), `AnnouncementSchedulingTest`
(10, including the mandatory §30/§46 template-snapshot invariant),
`PublishScheduledAnnouncementsTest` (10, including multi-school tenant
isolation, membership-change-before-execution, empty-audience backoff,
and a direct racing-`publish()`-calls concurrency proof),
`AnnouncementSchedulingTimezoneTest` (4, including a real DST
transition), `AnnouncementHubTest` (+9: schedule/reschedule/cancel via
HTTP, forged cross-School scheduling, template pre-fill/cross-School
template rejection), `CommunicationTemplateHubTest` (8: CRUD,
authorization, tenant isolation, pagination/filtering).

## 25. External-send safety

Unchanged posture from Phase 5A.3 — `COMMUNICATION_EMAIL_ENABLED`
remains `false` by default; every test touching email uses
`Mail::fake()`; no live provider credential was added; scheduling an
`EMAIL`-channel Announcement in tests never contacts the public
internet, since the same `EmailChannelDriver`/`Mail::fake()` boundary
from Phase 5A.3 governs it unchanged.

## 26. Deferred work

Unchanged from the brief's explicit out-of-scope list (§52): SMS/
WhatsApp/push provider integration, delivery/bounce/open-tracking
webhooks, inbound email, Reply-To, custom sender domains, a
communication-preference center, approval workflow, campaign
automation, AI drafting/translation/summarization, emergency
escalation, Student/Guardian/Class/Section/Grade audiences,
fee/attendance/transport business workflows. Also specific to this
checkpoint: merge tags/variables (§6 above — a deliberate, documented
"not yet," not an oversight), and template versioning as a first-class
concept (§5 above).

## Recommended next checkpoint

**Phase 5A.5 — Communication Preferences & Channel Policy Foundation.**
Reasoning: this checkpoint (5A.4) completed the LAST piece of the
"sender controls everything" side of Communication Hub — who gets
targeted, what content, when. The next natural gap is the recipient's
own side: today a School member has no way to express "don't email me,
in-app only" or similar, and nothing in the delivery pipeline
currently checks for one. This is pure School OS-internal foundation
work (a `communication_preferences`-shaped table, checked once inside
`EmailChannelDriver`/wherever a channel driver resolves a destination,
analogous to how `EmailAddressResolver` already gates on address
validity) that doesn't require picking a real external email provider
first — unlike a bounce/webhook checkpoint (Phase 5A.3's own closing
recommendation still applies: no real provider is configured or
authorized yet, so there is still no genuine webhook payload shape to
build against without guessing). Attachments/rich communication is a
reasonable alternative next step but is more purely additive
(content-shape work) with fewer open cross-cutting questions than
preferences, which several other pieces (a future Student/Guardian
audience, automation workflows) will eventually need to respect.
