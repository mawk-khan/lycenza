# Phase 5A.9 — Quiet Hours & Delivery Timing Policy Foundation

## 1. Objective

Introduce a server-authoritative timing-policy layer that answers: *may
this allowed secondary-channel delivery execute now, or must it wait
until a later permitted time?*

```
Communication -> Recipient -> Requested Channel -> Channel Policy (5A.5)
   -> ALLOW -> Delivery Timing Policy (5A.9) -> SEND_NOW | DEFER_UNTIL
   -> existing CommunicationDelivery -> existing queue/redispatch pipeline
```

No second delivery subsystem was created. Quiet hours are a new
*precedence step* inserted after Phase 5A.5's channel-policy ALLOW,
feeding the exact same `CommunicationDelivery` row and the exact same
`App\Jobs\ProcessCommunicationDeliveryJob` /
`App\Console\Commands\RedispatchDueCommunicationDeliveries` pipeline
Phase 5A.3 already built.

## 2. Existing delivery/retry architecture (discovered)

- `CommunicationDelivery` (`app/Domain/Communications/Infrastructure/CommunicationDelivery.php`)
  already carries `next_attempt_at` and `processing_lease_expires_at` —
  reserved by Phase 5A.3 for retry backoff. `status` is a plain string
  closed by a Postgres CHECK constraint
  (`pending, queued, sending, accepted, sent, delivered, read, failed,
  bounced, rejected, expired, cancelled`), not a PHP enum class.
- `App\Jobs\ProcessCommunicationDeliveryJob` claims a delivery via a
  conditional `UPDATE ... WHERE status IN ('pending','queued') AND
  (lease NULL OR expired)`, attempts transport once (`$tries = 1`,
  `$timeout = 15`), and on a retryable failure sets `status = 'queued'`
  + `next_attempt_at` (an *existing* status value — no new one).
- `App\Console\Commands\RedispatchDueCommunicationDeliveries`
  (`platform:communication-deliveries-redispatch`, scheduled
  `everyMinute()->withoutOverlapping()`) scans, per School, for
  `status = 'queued' AND next_attempt_at <= now()` (or a stale
  `sending` lease), locks with `for update skip locked`, and dispatches
  `ProcessCommunicationDeliveryJob` for each due row. It required **zero
  changes** for this checkpoint — see §13.
- `App\Domain\Communications\Application\Policy\CommunicationChannelPolicyService::evaluate()`
  is the one authoritative ALLOW/SUPPRESS decision path for a
  (recipient, channel); Phase 5A.5's own documentation explicitly
  named quiet hours as the next precedence step to plug in here.
- `App\Support\Tenancy\SchoolTimezone::resolve($school): DateTimeZone`
  is the one existing timezone helper (falls back to `config('app.timezone')`
  on a corrupted `schools.timezone` value, never throws) — reused
  unchanged.

## 3. Publication vs. transport timing

Phase 5A.4 controls when an announcement is **published** (the
canonical Communication Hub record). Phase 5A.9 controls only when an
already-ALLOWed secondary channel (EMAIL today) may **transport** that
published communication. These are structurally separate: publishing
during quiet hours always succeeds immediately; only the EMAIL
delivery row is affected.

> Quiet hours delay secondary-channel transport; they do not delay
> publication of the canonical Communication Hub record.

## 4. IN_APP canonical semantics

`CommunicationDeliveryTimingPolicyService::evaluate()` returns
`SEND_NOW` unconditionally for any channel other than `email` — IN_APP
is never evaluated against a quiet-hours window at all, matching how
`CommunicationChannelPolicyService::evaluate()` already treats IN_APP
as unconditionally canonical.

## 5. Timing-policy model

`App\Domain\Communications\Application\Policy\CommunicationDeliveryTimingPolicyService`
is the one authoritative decision path, called from exactly two
places:

- `AnnouncementService::publish()` — first-attempt eligibility,
  evaluated **once per requested channel per publish() call**, not
  once per recipient.
- `ProcessCommunicationDeliveryJob::scheduleRetry()` — a freshly
  computed retry-backoff candidate is re-checked; if it would itself
  land inside quiet hours, it is pushed to the next permitted instant.

Output is `App\Domain\Communications\Application\Policy\CommunicationTimingDecision`:
`shouldDefer: bool`, `availableAt: ?Carbon` (always UTC),
`reason: CommunicationTimingReason` (`allowed_now` | `quiet_hours` — a
closed, stable, machine-readable enum, no free-form text).

Priority (`NORMAL/IMPORTANT/URGENT/CRITICAL`) and Requirement
(`OPTIONAL/REQUIRED`) are never consulted by this service. Neither
implies "bypass quiet hours" in this checkpoint — see §19/§20.

## 6. Policy storage

New table `communication_delivery_timing_policies`
(`school_id`, `channel`, `enabled`, `quiet_hours_start`,
`quiet_hours_end`, timestamps), unique on `(school_id, channel)`,
`channel` CHECK-restricted to `'email'` (the only channel a quiet-hours
window is meaningful for today), `App\Support\Tenancy\TenantRls`
enabled, standard `BelongsToSchool` model. No row for a
(school, channel) pair means "send immediately" — the exact
pre-5A.9 behavior.

`quiet_hours_start`/`quiet_hours_end` are stored as School-**local**
wall-clock `time` values (e.g. `"20:00:00"`), never as a UTC offset —
a fixed offset would silently drift across a DST transition.

## 7. Default behavior

No policy row, or `enabled = false`, or a null start/end: `evaluate()`
always returns `SEND_NOW`. This is the core regression invariant
(proven by `CommunicationDeliveryTimingPolicyServiceTest::no_policy_row_sends_immediately_regardless_of_time`
and `a_disabled_policy_row_sends_immediately_regardless_of_time`, plus
the integration-level `AnnouncementDeliveryTimingTest::a_disabled_timing_policy_matches_pre_5a9_immediate_send_behavior`):
deploying this checkpoint changes zero existing behavior for every
School that has not explicitly configured quiet hours.

## 8. Cross-midnight rules

For `start > end` (e.g. `20:00 -> 07:00`), the quiet period is
`now >= start OR now < end`. Quiet begins **inclusive** at start, ends
**exclusive** at end — exactly at the end boundary is `SEND_NOW`. The
next permitted instant is today's end time if `now` is in the
early-morning portion (`now < end`), or tomorrow's end time if `now`
is in the evening portion (`now >= start`).

## 9. Same-day rules

For `start < end` (e.g. `13:00 -> 15:00`), the quiet period is
`start <= now < end`; the next permitted instant is always today's end
time. An equal start/end is rejected at write time (§16) and treated
as "never quiet" as a defensive fallback in `evaluate()` itself — it
is never silently interpreted as a 24-hour block.

## 10. Timezone conversion

Evaluation converts the current UTC instant to the School's own
timezone via `SchoolTimezone::resolve($school)`, compares against the
stored local wall-clock window, and converts the resulting next
permitted local instant back to UTC before it is ever persisted to
`next_attempt_at`. An invalid `schools.timezone` value falls back to
`config('app.timezone')` via the existing, unchanged `SchoolTimezone`
helper — no second timezone-interpretation path was introduced.

## 11. DST semantics

The next permitted instant is computed via `Carbon::setTimeFromTimeString()`
+ `Carbon::addDay()` on a timezone-aware `Carbon` instance — real
calendar-day, wall-clock-preserving arithmetic, never a fixed
`+24 hours` UTC offset. Proven directly across both a spring-forward
transition (`America/New_York`, 2026-03-08) and a fall-back transition
(2026-11-01) in `CommunicationDeliveryTimingPolicyServiceTest` — a
naive fixed-duration add would be off by exactly one hour in both
cases; the actual implementation is not.

## 12. Delivery eligibility timestamp

A deferred delivery is created directly with `status = 'queued'` and
`next_attempt_at` set to the computed UTC instant — the *same* state
`ProcessCommunicationDeliveryJob::scheduleRetry()` already uses for a
retry backoff. No new delivery status was added.
`App\Domain\Communications\Application\CommunicationDeliveryFactory::createDelivery()`
gained one new optional `?Carbon $availableAt` parameter; `null` (the
default) preserves the exact pre-5A.9 immediate-send row shape.

## 13. Scheduler/redispatch integration

`RedispatchDueCommunicationDeliveries` required **zero code changes**.
Its existing `WHERE status = 'queued' AND next_attempt_at <= now()`
scan already picks up a quiet-hours-deferred delivery the moment it
becomes due — exactly the same mechanism that already re-dispatches a
retry backoff. No second `communications:quiet-hours-send` scheduler
was introduced.

## 14. Atomic claiming

Unchanged from Phase 5A.3: `ProcessCommunicationDeliveryJob::claim()`'s
conditional `UPDATE ... WHERE status IN ('pending','queued') AND
(lease NULL OR expired)` is the sole claim mechanism. Quiet hours only
ever influence *when* a delivery becomes eligible for that same claim
— never how the claim itself works.

## 15. Idempotency

Proven directly: `ProcessCommunicationDeliveryRetryTimingTest::a_quiet_hours_deferred_delivery_is_sent_exactly_once_even_if_redispatch_races`
runs `platform:communication-deliveries-redispatch` twice after a
quiet-hours-deferred delivery becomes due — exactly one email is sent,
`attempts` is `1`. The documented email-provider "effectively-once, not
provably-exactly-once" limitation (Phase 5A.3) is unchanged and not
re-litigated here.

## 16. Retry interaction

`ProcessCommunicationDeliveryJob::scheduleRetry()` computes its usual
backoff candidate, then calls `CommunicationDeliveryTimingPolicyService::evaluate()`
against that candidate timestamp; if it falls inside quiet hours, the
persisted `next_attempt_at` is the quiet-hours-adjusted instant
instead of the raw backoff. This does **not** re-evaluate the original
deferral decision made at delivery-creation time (§17's immutable-
planning invariant) — it only ever widens a freshly-computed retry
candidate for a delivery that has just genuinely failed.

## 17. Channel-policy interaction / immutable planning

Timing is evaluated strictly **after** `CommunicationChannelPolicyService`
already returned ALLOW — a SUPPRESSed (recipient-ineligible,
preference-disabled, school-policy-disabled) recipient/channel never
reaches the timing engine and never gets a delivery row at all, deferred
or otherwise (proven by
`AnnouncementDeliveryTimingTest::optional_email_disabled_by_preference_is_suppressed_before_timing_is_ever_considered`).
Once a delivery's timing decision is persisted, a later change to the
School's quiet-hours policy never rewrites it — only a subsequent
publish/retry re-evaluates against the *then-current* policy.

## 18. Preference interaction

An optional communication with the recipient's EMAIL preference
disabled is suppressed by `CommunicationChannelPolicyService` before
timing is ever considered — no delivery row, deferred or otherwise,
and no policy-decision row mentioning quiet hours.

## 19. Required communication behavior

**REQUIRED does not automatically bypass quiet hours.** A Required
communication's EMAIL delivery still defers during quiet hours exactly
like an Optional one — proven directly by
`AnnouncementDeliveryTimingTest::a_required_communication_still_defers_email_during_quiet_hours`.
Required only bypasses the *recipient's own preference opt-out*
(Phase 5A.5); it has no effect on this checkpoint's engine at all.

## 20. CRITICAL priority behavior

**CRITICAL does not automatically bypass quiet hours.** Priority is
never read by `CommunicationDeliveryTimingPolicyService` — proven
directly by `AnnouncementDeliveryTimingTest::critical_priority_does_not_bypass_quiet_hours`.
An emergency bypass is a deliberately deferred, separately-authorized
future concept (§30) — not implemented here, and not reachable by
setting priority alone.

## 21. Scheduled announcements

Quiet hours are evaluated at actual due-publication time (inside
`AnnouncementService::publish()`), never at schedule-creation time —
the same "evaluate at due time" property Phase 5A.5's own preference/
channel-policy evaluation already established. A policy change made
between `schedule()` and the due publish is picked up automatically,
with no extra code (`AnnouncementDeliveryTimingTest::scheduled_publication_inside_quiet_hours_defers_email_and_redispatch_sends_it_exactly_once`).

## 22. Manual publication

Manually publishing during quiet hours behaves identically to a due
scheduled publish — same code path, same single integration point —
publication and IN_APP delivery are immediate; EMAIL defers.

## 23. Policy-change snapshot semantics

Once a delivery has been planned (its `next_attempt_at` persisted), a
later quiet-hours policy change never rewrites that already-planned
row — a deliberate, documented immutable-planning choice (§17).
Policy changes made *before* a scheduled announcement's due time are
picked up naturally, because timing (like channel policy) is only ever
evaluated at actual publish/retry time, never at schedule- or
retry-computation-time-minus-one-step.

## 24. Tenant/RLS protection

`communication_delivery_timing_policies` uses
`App\Support\Tenancy\TenantRls::enable()` exactly like every other
tenant-owned Communication Hub table. Proven with the mandatory raw-SQL
suite (`CommunicationDeliveryTimingPoliciesRlsIsolationTest`): RLS
enabled+forced, zero rows visible with no School context, School A
cannot `SELECT`/`UPDATE`/`INSERT-as` School B's row.

## 25. Multi-school behavior

Quiet-hours policy belongs to the School that owns the communication,
never to the acting User. A User who is a member of two Schools with
different quiet-hours configuration sees the *correct, independent*
outcome for each School at the same real-world instant — proven by
`AnnouncementDeliveryTimingTest::a_multi_school_users_email_is_deferred_for_one_school_and_immediate_for_another_at_the_same_moment`
and, at the pure-evaluation level, by
`CommunicationDeliveryTimingPolicyServiceTest::two_schools_in_different_timezones_evaluate_the_same_utc_instant_differently`.

## 26. Settings UI

Extended the existing Channels settings page
(`/app/communications/settings/channels`,
`App/Communications/Settings/Channels.vue`) with a new "Email delivery
timing" section — Enabled toggle, From/Until `<input type="time">`
fields, and a note naming the School's own timezone. Saved via a
separate `PUT /app/communications/settings/timing` endpoint
(`CommunicationDeliveryTimingPolicyController`), capability-gated by
`communications.manage` exactly like the channel-policy write path.
Only EMAIL is exposed; IN_APP is not offered a quiet-hours control at
all (§4); SMS/WhatsApp/Push remain absent from the UI, matching every
prior checkpoint's "functional controls only for implemented channels"
rule.

## 27. Operational visibility

A deferred delivery is `status = 'queued'` with a future
`next_attempt_at` — the *same* representation an ordinary retry
backoff already uses. Phase 5A.8's Failed surface
(`App\Domain\Communications\Application\CommunicationDeliveryFailureReadModel`)
only ever looks for `status = 'failed'` rows, so a quiet-hours-deferred
delivery is structurally impossible to misclassify as Failed — no
change was needed there. No new top-level Inbox navigation section was
added for "Deferred"; this checkpoint deliberately did not extend the
Phase 5A.8 operational surfaces further than required.

## 28. Query/performance strategy

Timing policy is evaluated **once per requested channel per
publish() call** — never once per recipient — computed before the
audience-chunk loop begins and reused for every recipient of that
channel. Proven directly:
`AnnouncementDeliveryTimingTest::timing_policy_lookup_is_not_repeated_once_per_recipient`
publishes to 31 recipients and asserts exactly **one**
`communication_delivery_timing_policies` query total. The service also
keeps a private in-memory per-instance cache (mirroring
`CommunicationChannelPolicyService`'s own shape) for the rarer path
where `evaluate()` is called more than once per instance (e.g. a retry).

## 29. Tests

New Phase 5A.9 test files and counts:

- `CommunicationDeliveryTimingPolicyServiceTest` — 12 tests (29
  assertions): disabled/no-row regression, IN_APP never deferred,
  cross-midnight boundaries, same-day boundaries, equal start/end,
  DST spring-forward + fall-back, multi-timezone.
- `AnnouncementDeliveryTimingTest` — 8 tests (32 assertions): manual
  publish, scheduled publish + redispatch idempotency, preference
  suppression precedence, Required behavior, Critical behavior,
  disabled-policy regression, multi-school isolation, query-count
  scale proof.
- `ProcessCommunicationDeliveryRetryTimingTest` — 3 tests (9
  assertions): retry pushed out of quiet hours, retry left unmodified
  outside quiet hours, exactly-once redispatch idempotency.
- `CommunicationDeliveryTimingPoliciesRlsIsolationTest` — 5 tests (9
  assertions): RLS enabled+forced, zero-context isolation,
  cross-School select/insert/update rejection.
- `CommunicationDeliveryTimingPolicySettingsHubTest` — 9 tests (32
  assertions): guest redirect, default-disabled display, authorized
  update, unauthorized (403), equal-start/end rejection, invalid
  format rejection, disable-without-window, invalid channel rejection,
  cross-School isolation.

**37 new tests, 111 new assertions**, run and confirmed individually
by class. Full application regression (`composer test`, from a clean
shell): **665 passed, 2020 assertions, 0 failed** (one pre-existing,
unrelated `AcademicYearActivationConcurrencyTest` flake reproduced
failing once under full-suite load and passing in isolation — a
two-real-process race test in the Academic Structure module, untouched
by this checkpoint; not a regression).

## 30. Safety

No real external email was sent (`Mail::fake()`/`Mail::shouldReceive()`
throughout); `COMMUNICATION_EMAIL_ENABLED` remains `false` by default
and untouched; no SMTP/SES/Postmark/Resend/Mailgun/SendGrid
credentials were added; no SMS/WhatsApp/Push provider code was added;
no staging/production configuration was touched; no Phase 1A code was
read, referenced, or merged; the feature branch was never merged,
rebased, or pushed.

## 31. Deferred emergency bypass

Explicitly not implemented in this checkpoint: an emergency/urgent
bypass of quiet hours. §19/§20 above prove neither Requirement nor
Priority currently provide one. A future, separately-authorized
explicit bypass concept could slot into
`CommunicationDeliveryTimingPolicyService::evaluate()` as an additional,
clearly-named precedence step (and a new `CommunicationTimingReason`
case) — not implemented, not scaffolded, not hinted at in the UI.

Also explicitly deferred, none implemented: per-user/recipient quiet
hours, recipient-local-timezone delivery, weekend/holiday/academic-
calendar scheduling, category-specific delivery windows, approval
workflows, email provider webhooks, SMS/WhatsApp/Push providers,
inbound email, AI-driven scheduling/urgency classification,
Student/Guardian/Class-specific timing policies, and any Phase 1A
integration.

## 32. Next recommendation

Evaluated but **not started**, per instruction:

- **Phase 5A.10 — Approval Workflow Foundation**
- **Phase 5A.10 — Communication Audit & Delivery Analytics**
- **Phase 5A.10 — Emergency Communication Policy Foundation**
- **Phase 5A.10 — Email Provider Event/Webhook Foundation**

Emergency Communication Policy is now the natural next layer — it has
a clean seam to plug into (§31: an explicit bypass step in this
checkpoint's own `evaluate()`), and quiet hours existing at all is
precisely what makes an emergency bypass meaningful to design well.
Communication Audit & Delivery Analytics remains a reasonable
alternative if operational reporting is the more urgent product need.
