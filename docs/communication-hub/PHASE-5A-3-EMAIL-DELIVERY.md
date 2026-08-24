# Phase 5A.3 — Outbound Email Delivery Foundation

## 1. Objective

Activate the `EMAIL` channel of Phase 5A.1's adapter-ready channel
abstraction, reusing the existing `CommunicationMessage` →
`CommunicationRecipient` → `CommunicationDelivery` →
`CommunicationDeliveryAttempt` → `ProcessCommunicationDeliveryJob`
pipeline end-to-end — no second delivery system, no second retry
system, no second attempt ledger. `SMS`/`WhatsApp`/`Push` remain
unregistered adapter-ready boundaries, untouched by this checkpoint.

Announcements (Phase 5A.2) may now explicitly request `IN_APP` alone
(unchanged default) or `IN_APP + EMAIL`. Conversation-message email
selection is deliberately **not** wired into UI this checkpoint (§18
below) — the service/pipeline layer is channel-ready for it, but no
composer exposes it yet.

## 2. Existing mail architecture discovered

Before writing anything, the following was audited:

- **`config/mail.php`** — stock Laravel config, untouched. Default
  mailer is `log` (`MAIL_MAILER=log`), `MAIL_FROM_ADDRESS` defaults to
  `hello@school-os.test` in `.env.example`. No `reply_to` key exists
  anywhere in this config.
- **Mailables** — none existed. `grep`-ing `app/` for `Mail::`/
  `Mailable`/`Illuminate\Mail` turned up nothing.
- **A separate, older notification system already exists**:
  `App\Support\Notifications\NotificationDispatcher` +
  `NotificationProvider` (channel string → provider, with
  `LogEmailProvider`/`InAppProvider`/`FakeSmsProvider`/
  `FakeWhatsAppProvider`/`FakePushProvider`), backed by a generic
  `notifications` table (Phase 0C). Its own docblocks are explicit that
  it is a **different, incompatible contract** from Communication
  Hub's `CommunicationChannelDriver` — "not the same interface as
  `App\Support\Notifications\NotificationProvider` — that contract's
  `send()` takes a `Notification` model, a different (simpler,
  single-recipient, no-attempt-history) concept." This system is a
  Phase 0C platform-level notification primitive (e.g. the setting-
  change consumer), **not** Communication Hub. It was left completely
  untouched — building EMAIL on top of it would have been exactly the
  "second independent mail stack" the brief warns against.
- **`communication_deliveries` schema (Phase 5A.1)** already reserved
  everything this checkpoint needed: `destination_snapshot` (jsonb,
  documented as "reserved for a future channel to record exactly what
  address/number a provider was asked to deliver to"), `provider`,
  `provider_message_id`, `failure_code`, `failure_reason`,
  `next_attempt_at`, `processing_lease_expires_at`. **Zero new columns
  were added to this table.** Its `status` CHECK constraint already
  included `sent` alongside `delivered`/`failed`/etc.
- **`App\Jobs\ProcessCommunicationDeliveryJob`** already had the exact
  "atomic claim → driver call → append-only attempt record" shape
  `App\Jobs\DeliverWebhookJob` established — extended, not
  reimplemented (§9 below).
- **Existing retry/backoff precedent**: `App\Jobs\DeliverWebhookJob` +
  `App\Console\Commands\RedispatchDueWebhookDeliveries` +
  `config/webhooks.php`. Mirrored exactly for communication deliveries
  (§11).

## 3. Email channel driver

`App\Domain\Communications\Application\Channels\EmailChannelDriver`
implements the existing `CommunicationChannelDriver` contract
(`channel(): CommunicationChannel`, `send(CommunicationDelivery):
CommunicationDeliveryResult`) and is registered into the existing
`CommunicationChannelRegistry` singleton in
`App\Providers\PlatformServiceProvider`, alongside `InAppChannelDriver`.
It is reached **only** through `ProcessCommunicationDeliveryJob` —
never called directly by a controller or service.

## 4. Enablement gate

`config/communications.php`:

```php
'channels' => [
    'email' => [
        'enabled' => (bool) env('COMMUNICATION_EMAIL_ENABLED', false),
        'mailer' => env('COMMUNICATION_EMAIL_MAILER'),
    ],
],
```

Defaults to `false` in every environment (`.env.example` documents
this explicitly). A configured `MAIL_MAILER` does **not** by itself
activate Communication Hub email — this is Communication Hub's own
independent switch, checked twice: once by
`AnnouncementController::validateComposer()` (a forged HTTP payload
requesting `email` while disabled is rejected with a 422, never
reaches the service) and again by `EmailChannelDriver::send()` at the
moment the queued job actually runs (covers the legitimate race where
an operator disables email between publish and the job executing).

## 5. Address resolution

`App\Domain\Communications\Application\Channels\EmailAddressResolver`
is the one place a canonical address is derived from a `User` —
`trim()` + `FILTER_VALIDATE_EMAIL`, returning `null` (never throwing)
for anything unusable. Identity resolution stays exactly on
`User`/`SchoolMembership` (root CLAUDE.md constraint) — no Student/
Guardian model was introduced. `AnnouncementService::publish()` calls
it once per chunk (a single batched `User::whereIn('id', $chunk)`
query, never per-recipient N+1) only when `EMAIL` was actually
requested.

## 6. Destination snapshot

Reused Phase 5A.1's existing `communication_deliveries.destination_snapshot`
jsonb column verbatim — **no migration added a new column for this**.
`AnnouncementService::publish()` resolves and snapshots
`{"email": "..."}` (or `null` if unresolvable) at delivery-CREATION
time; `EmailChannelDriver::send()` reads only
`$delivery->destination_snapshot['email']`, **never** re-resolving a
live `User.email`. Proven end-to-end in
`Tests\Feature\Communications\EmailDestinationSnapshotTest`: a
recipient's email changes between two Announcements; the first
delivery's snapshot (and the address Mail::fake() recorded it sent to)
still shows the OLD address, while a delivery created after the change
resolves the NEW one.

## 7. Sender/from handling

`EmailChannelDriver` builds the envelope from the existing global
`config('mail.from.address')`/`config('mail.from.name')` — no tenant-
managed sender domain, no free-form staff-entered From address. The
display name incorporates the School name only:
`"{$school->name} via " . config('mail.from.name')`, e.g. `"Lincoln
High via School OS <hello@school-os.test>"`. Custom verified sender
domains remain explicitly deferred to a later phase (§13 of the
brief).

## 8. Reply-To

Omitted entirely. `config/mail.php` has no `reply_to` key and no
inbound-mailbox architecture exists — inventing one would be a fake
address nobody reads. Documented as deferred (§14/§33 of the brief,
§14 of this document's deferred-work list).

## 9. Content rendering

`communication_messages.body` is plain text (Phase 5A.1) — so this
checkpoint sends plain-text-only email, avoiding any HTML sanitization/
XSS surface entirely (brief §11). `App\Domain\Communications\Application\Channels\CommunicationMail`
is a `Illuminate\Mail\Mailable` receiving only an immutable
`CommunicationEmailPayload` DTO (subject, body text, from address/name)
— it does zero recipient resolution, authorization, tenant lookup, or
provider selection itself (brief §12). Its `content()` renders
`resources/views/emails/communications/message.blade.php`, which uses
raw `{!! $bodyText !!}` deliberately (not `{{ }}`) — this is the
`text/plain` MIME part, read literally by mail clients; Blade's
default HTML-entity escaping would be *wrong* here (e.g. it would
corrupt `<3` into `&lt;3` in what must stay plain text).

Subject: an Announcement's `title` maps directly to the email subject.
A (currently UI-unreachable, but service-ready) thread message falls
back to `$thread->subject ?? 'New message in Communication Hub'` — a
deterministic, non-AI-generated derivation (brief §10).

## 10. Delivery lifecycle

`App\Domain\Communications\Application\Channels\CommunicationDeliveryResult`
gained a `status` field distinguishing two honest terminal successes:

- `InAppChannelDriver` still returns `::delivered()` — in-app
  "delivery" genuinely is complete the instant the row exists, no
  external step.
- `EmailChannelDriver` returns `::sent()` on a successful transport
  handoff. **`SENT` means the configured application mail transport
  accepted the send operation without throwing — it does NOT mean
  mailbox delivery.** No `DELIVERED`/`READ`/`BOUNCED`/`REJECTED` is
  ever claimed for email in this checkpoint; those require provider-
  level evidence (bounce/delivery webhooks) that does not exist yet
  (§30 of the brief — explicitly out of scope). `ProcessCommunicationDeliveryJob`
  now sets the delivery's terminal status from `$result->status ??
  'delivered'` rather than hardcoding `'delivered'`.

## 11. Attempt ledger

Every send attempt — success or failure, retried or terminal — still
produces exactly one row in the existing, append-only
`communication_delivery_attempts` table, using its existing
`outcome` vocabulary (`success`/`transient_failure`/`permanent_failure`
— already the exact values `webhook_delivery_attempts` uses, no schema
change needed). Never stores credentials, full provider payloads, or
raw exception messages/traces — only a short, safe failure code and a
generic human-readable reason.

## 12. Retry behavior

`config/communications.php`'s `delivery` block (channel-agnostic, not
`email`-specific, so a future SMS/WhatsApp/Push driver reuses it
unchanged):

```php
'delivery' => [
    'max_attempts' => 3,
    'retry_backoff_seconds' => [30, 120, 300],
    'processing_lease_seconds' => 30,
],
```

`CommunicationDeliveryResult::failed()` now carries a `retryable` bool.
A **deterministic** failure (`recipient_email_missing`,
`recipient_email_invalid`, `email_channel_disabled`) is always
`retryable: false` — retrying it changes nothing, so
`ProcessCommunicationDeliveryJob` marks it `failed` immediately, one
attempt, no retry storm. A **transient** transport exception (any
`Throwable` from `Mail::mailer(...)->send()` — deliberately broad and
conservative, since a generic Mailable/transport failure cannot be
reliably classified from this layer) is `retryable: true`: the job
schedules a backed-off re-attempt (`status = 'queued'`, an *existing*
value in the delivery's closed status set — no new status was added —
with `next_attempt_at` set), mirroring `DeliverWebhookJob::scheduleRetryOrAbandon()`
exactly. `App\Console\Commands\RedispatchDueCommunicationDeliveries`
(scheduled `everyMinute()` in `routes/console.php`, alongside the
webhook equivalent) is what actually re-dispatches a due retry or
recovers a delivery whose processing lease expired from a crashed
worker — `ProcessCommunicationDeliveryJob` itself never self-schedules
(no delayed requeue on the `sync` queue connection tests use, ADR
0024). Once `max_attempts` is exhausted, a still-retryable failure
falls through to the same terminal `failed` handling as a
non-retryable one.

`in_app` never exercises either new path — `InAppChannelDriver` always
succeeds immediately — so today only `email` deliveries can ever reach
`queued`-with-`next_attempt_at` or a retried attempt.

## 13. Idempotency and its limitations

Delivery **creation** remains idempotent exactly as Phase 5A.1/5A.2
established: `communication_deliveries` has `unique(recipient_id,
channel)`, and `App\Domain\Communications\Application\CommunicationDeliveryFactory::createDelivery()`
(generalized from `createInAppDelivery()`, same idempotent-creation
logic reused for every channel — brief §6) catches
`UniqueConstraintViolationException` and returns the existing row.
Combined with `AnnouncementService::publish()`'s atomic
`draft → published` claim, re-publishing an Announcement never creates
a second logical `email` delivery (proven in
`AnnouncementEmailDeliveryTest::republishing_does_not_duplicate_the_email_delivery`).

**Honest limitation, stated explicitly per the brief's instruction**:
generic SMTP/API email transport cannot guarantee exactly-once
external delivery. If the process loses its connection after a
provider has genuinely accepted a message but before this application
receives that confirmation, the internal delivery row could end up
retried (since the driver would classify the ambiguous failure as
transient) — a real recipient could, in a narrow window, receive a
duplicate email. This checkpoint does not claim otherwise. A future
provider-specific adapter using an idempotency-key-aware API (e.g.
Postmark/SES message deduplication) can close this gap; the seam is
already there — `CommunicationDelivery.id` is a stable UUID a future
driver can pass as an idempotency key, and `provider_message_id`
exists on the schema to record a provider's own returned identifier
once a real provider adapter exists.

## 14. Tenant isolation

- No new tenant-owned table needed RLS except `communication_announcement_channels`
  (§16 below) — `communication_deliveries`/`communication_delivery_attempts`
  already had it (Phase 5A.1).
- `EmailChannelDriver` and `AnnouncementService` never accept a
  request-supplied destination email — the only address ever used
  comes from `EmailAddressResolver::resolve()` against a `User` row
  reached through an already-tenant-validated `SchoolMembership`
  (never a raw `whereIn('id', ...)` over unscoped input).
  `ProcessCommunicationDeliveryJob::handle()` sets `TenantContext`
  from its own explicit `$schoolId` constructor argument (captured at
  dispatch time by the dispatching service, not ambient) before doing
  anything, `finally`-clears it after — unchanged Phase 5A.1 discipline,
  reused by the retry/redispatch path too.
- `communication_announcement_channels` follows the identical composite-FK
  pattern as `communication_announcement_audience_members`: a row
  genuinely owned by School A but pointing `announcement_id` at School
  B's real Announcement is rejected by the FK against
  `communication_announcements(id, school_id)` — proven at the raw-SQL
  level in `CommunicationAnnouncementsRlsIsolationTest::a_cross_school_announcement_channel_row_is_rejected_at_insert_time`.

## 15. Authorization

No new capability was introduced. `communications.announce` (already
gating Announcement creation/publish since Phase 5A.2) is reused
verbatim — channel selection is just another field on the same
authorized action, so there is no separate "can this user trigger
email" check to bypass. `AnnouncementController::validateComposer()`'s
dynamic `Rule::in($allowedChannels)` rejects `email` outright (422)
when the config gate is off, regardless of who is asking.

## 16. Privacy / logging

- `AnnouncementController::channelDeliverySummary()` returns only
  aggregate counts (`channel`, `status`, `failure_code`, `count`) —
  never an individual delivery row, never a destination address.
- `EmailChannelDriver`'s only log line
  (`communications.delivery.email.transport_failed`) carries
  `school_id`/`delivery_id`/`channel` — never the destination address,
  never the message body, never the raw exception message (which may
  embed provider request/response detail).
- The attempt ledger (§11) is the durable record; it too never stores
  the destination address (that lives only in
  `communication_deliveries.destination_snapshot`, itself an ordinary
  RLS-protected column with no additional encryption-at-rest beyond
  the deployment's disk encryption — the same posture Phase 5A.2's
  idempotency-response columns already documented).

## 17. Routes / UI

No new routes. The existing `POST /app/communications/announcements`
and `PUT /app/communications/announcements/{announcement}` now accept
an optional `channels: string[]` field. `GET .../create` and
`GET .../{announcement}` (show) both now pass `emailChannelEnabled:
boolean`.

- **`Create.vue`**: a "Delivery" section with a fixed, disabled,
  always-checked "In-app" checkbox and an "Email" checkbox that is
  disabled (with a small explanatory label) whenever
  `emailChannelEnabled` is false.
- **`Show.vue`** (draft): a "Delivery channels" summary; when `email`
  is a requested channel, the audience preview additionally shows
  "Email eligible" / "Missing email" counts (from
  `AnnouncementController::emailEligibility()`, reusing
  `EmailAddressResolver` — never re-implementing the validation rule a
  second time).
- **`Show.vue`** (published): a per-channel delivery summary — in-app
  shows `succeeded / total`; email shows `sent`/`pending`/`failed`/
  `unavailable` counts, where "unavailable" buckets the deterministic
  failure codes (`recipient_email_missing`/`recipient_email_invalid`/
  `email_channel_disabled`) separately from a genuine transport
  `failed`. No individual address is ever rendered.

Conversation-message email selection (brief §18) was deliberately
**not** added to `CommunicationHubController`/its composer — doing so
would have meant non-trivial UX work unrelated to proving the
one-to-many Announcement email path, which the brief itself says is
"sufficient... for 5A.3." The service/pipeline layer underneath
(`CommunicationDeliveryFactory::createDelivery()`, the channel
registry, the driver) is already channel-ready for it; only UI
enablement is deferred.

## 18. Test strategy

35 new tests across 6 new/extended files:

- `Tests\Feature\Communications\Channels\EmailChannelDriverTest` (6) —
  driver registration, disabled-channel refusal, missing/invalid
  destination refusal (both non-retryable), snapshot-not-live-email
  resolution, `sent`-not-`delivered` semantics. Every test uses
  `Mail::fake()`.
- `Tests\Feature\Communications\AnnouncementEmailDeliveryTest` (5) —
  in-app-only creates no email delivery, requesting both creates
  exactly one delivery per channel per recipient, republish doesn't
  duplicate, one recipient's email failure doesn't affect another
  recipient's in-app delivery (or the Announcement's own `published`
  status), a missing/invalid address still produces a durable, visible
  failed delivery rather than being silently skipped.
- `Tests\Feature\Communications\ProcessCommunicationDeliveryRetryTest`
  (3) — a transient transport exception schedules a backed-off retry
  (not immediate terminal failure), max-attempts exhaustion converts
  it to terminal, `RedispatchDueCommunicationDeliveries` requeues a due
  retry and leaves a not-yet-due one alone.
- `Tests\Feature\Communications\EmailDestinationSnapshotTest` (1) —
  the full old-address/new-address scenario described in §6 above.
- `Tests\Feature\Postgres\CommunicationAnnouncementsRlsIsolationTest`
  (+1, extended) — `communication_announcement_channels` added to the
  generic RLS-enabled/no-context/cross-school-read table list, plus a
  dedicated composite-FK cross-school rejection test.
- `Tests\Feature\App\AnnouncementHubTest` (+4, extended) —
  `emailChannelEnabled` reflects config on the composer, a forged
  `channels: ['in_app', 'email']` payload is rejected (422) while
  disabled, an authorized sender selecting email gets real per-channel
  delivery summaries after publish, and — critically — omitting
  `channels` from the request entirely still produces `['in_app']`
  only with zero mail sent (brief §15's core invariant).

One existing Phase 5A.1 test
(`ProcessCommunicationDeliveryJobTest::a_delivery_on_a_channel_with_no_registered_driver_is_marked_failed_not_silently_dropped`)
was updated to use `sms` instead of `email` as its fixture channel —
its original intent ("a channel with NO registered driver") no longer
holds for `email` now that `EmailChannelDriver` is registered; `sms`
genuinely has none, preserving the test's real purpose.

## 19. External-send safety

- Every test uses `Mail::fake()` (or, for the two retry tests that
  need a thrown exception, `Mail::shouldReceive('mailer')->andThrow(...)`)
  — neither performs any real network I/O regardless of what
  `MAIL_MAILER`/`COMMUNICATION_EMAIL_MAILER` happen to be configured
  to.
- No live SMTP/SES/Postmark/Resend/Mailgun/SendGrid credential was
  added anywhere. `config/mail.php` is untouched;
  `COMMUNICATION_EMAIL_MAILER` defaults to empty (uses
  `config('mail.default')`, itself `log` by default).
- `.env.example` gained only `COMMUNICATION_EMAIL_ENABLED=false` and
  `COMMUNICATION_EMAIL_MAILER=` — a non-secret placeholder default,
  same pattern every other flag in that file already follows.
- No real deployment `.env` was modified.
- `COMMUNICATION_EMAIL_ENABLED` defaults to `false` everywhere; email
  cannot leave the system in any environment without an explicit,
  separate operator decision to flip it, independent of whatever
  `MAIL_MAILER` happens to be configured for.

## 20. Deferred work

Unchanged from the brief's explicit out-of-scope list (§45): SMS,
WhatsApp, push, email provider delivery/bounce/open-tracking webhooks,
inbound email/replies, school-owned sender-domain verification,
communication preferences, template administration, AI drafting/
translation, approval workflow, complex scheduling, emergency
escalation, Student/Guardian/Class/Section/Grade audiences. Also
explicitly still deferred from this checkpoint specifically:

- **Conversation-message email UI** (§17 above) — the pipeline is
  ready, the composer is not wired.
- **Reply-To** — no inbound mailbox architecture exists to make one
  honest.
- **A provider-specific idempotency key for email** — the seam exists
  (`CommunicationDelivery.id`, `provider_message_id`) but no real
  provider adapter uses it yet, since only the `log`/`array`/generic
  Laravel mailer transports are exercised in this checkpoint.
- **Distinguishing transport-exception subtypes** (e.g. "recipient
  rejected by the server" vs "connection timed out") — every
  `Throwable` from the transport is currently treated uniformly as
  retryable `email_transport_unavailable`; a real provider adapter
  will need finer classification (mirroring
  `DeliverWebhookJob::handleResponse()`'s HTTP-status-based
  classification) once one is actually integrated.

## Recommended next checkpoint

**Phase 5A.4 — Communication Templates & Scheduling Foundation**, not
the delivery-events/bounce-webhook checkpoint, for a concrete reason
discovered while building this one: no real external email provider
is configured or authorized yet (§19 above — everything here runs
against `log`/`array`/fake transports by design), so there is no
genuine provider webhook to receive delivery/bounce/open events *from*
yet. Building bounce-webhook handling now would mean guessing a
provider's exact payload shape rather than integrating a real one —
speculative infrastructure the project's own conventions
(root CLAUDE.md rule 2) explicitly reject. Templates/scheduling, by
contrast, are pure School OS-internal foundation work (a `scheduled_at`
minimal field already exists as a reserved-but-unused column
description in the Phase 5A.2 brief; a template concept builds
directly on the now-stable `CommunicationMail`/`CommunicationEmailPayload`
rendering boundary) that doesn't require picking a real provider first.
A provider-webhook checkpoint becomes the natural Phase 5A.5 once an
actual transactional provider is chosen and authorized for a real
environment.
