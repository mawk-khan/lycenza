# Phase 5A.11 — Communication Audit & Delivery Analytics Foundation

## 1. Objective

Build an operationally useful, tenant-safe **audit** and **delivery
analytics** layer over the evidence Phase 5A.1–5A.10 already produced,
without a second delivery pipeline, a duplicate event log, or a data
warehouse. Two distinct questions are answered:

- **Audit**: who performed an action, and what state/policy transition
  occurred?
- **Delivery analytics**: what happened to recipient/channel delivery
  after publication?

## 2. Architecture audit (discovered)

- `App\Support\Audit\AuditRecorder` is the one writer of
  `App\Models\SchoolAuditEvent` (tenant-owned, RLS-protected,
  append-only via `TenantRls::makeAppendOnly`). Communications already
  records: `announcement.created`, `.updated`, `.scheduled`,
  `.rescheduled`, `.schedule_cancelled`, `.cancelled`, `.published`,
  `.emergency_published`, `.emergency_declared`,
  `communication.emergency_quiet_hours_bypass_used`,
  `communication.message.created`, `communication.thread.created`,
  `communication.participant.{added,removed,archived,unarchived}`,
  `communication_attachment.{uploaded,removed,downloaded}`,
  `communication_template.{created,updated,...}`,
  `communication.channel_policy.updated`,
  `communication.preference.updated`, plus the timing-policy update
  event. No new event names were introduced.
- Delivery state lives in `communication_deliveries` (one row per
  logical `(recipient, channel)` delivery; closed status set
  `pending|queued|sending|accepted|sent|delivered|read|failed|bounced|
  rejected|expired|cancelled`) and `communication_delivery_attempts`
  (append-only, one row per real send attempt).
- Suppression lives in `communication_delivery_policy_decisions`
  (append-only; only SUPPRESSED decisions are stored — an ALLOW
  decision's evidence IS the resulting `communication_deliveries` row).
- Quiet-hours deferral has **no dedicated column or table**. A
  quiet-hours-deferred delivery is created directly in `status =
  'queued'` with `attempts = 0` and a future `next_attempt_at`
  (`CommunicationDeliveryFactory::createDelivery()`). This checkpoint
  derives "deferred by quiet hours" as `status = 'queued' AND attempts
  = 0` — indistinguishable, by design, from a delivery simply not yet
  claimed, which is the correct semantics (both mean "not yet
  attempted, nothing to report as failure").
- Emergency evidence is entirely in the audit ledger:
  `announcement.emergency_declared` (justification, requirement),
  `announcement.emergency_published`, and
  `communication.emergency_quiet_hours_bypass_used` (one row per
  bypassed channel, metadata `{channel}`).
- IN_APP read state is `communication_deliveries.status = 'read'` /
  `read_at`, written only by
  `AnnouncementService::markRead()`/`CommunicationThreadService::markRead()`.
- `App\Domain\Communications\Application\CommunicationInboxReadModel`
  and `CommunicationDeliveryFailureReadModel` already establish the
  read-model convention this checkpoint follows: grouped `DB::table()`
  aggregate queries under `TenantContext::withSchool()`, never
  per-recipient loops.
- `AnnouncementController::channelDeliverySummary()` (Show page) already
  does a lightweight channel/status/failure_code group-by — left
  unchanged; the new richer, capability-gated surfaces are additive,
  not a replacement of that existing inline summary.

## 3. Audit vs. delivery analytics — the boundary

```
Audit:                Actor -> Action/Event -> Communication -> Timestamp -> Safe Metadata
Delivery analytics:    Communication -> Channel -> Delivery State -> Recipient Outcome
```

Audit answers "who did what, when, under what policy." Delivery
analytics answers "what happened to the message after it was sent."
Neither surface reads the other's underlying table directly — audit
never queries `communication_deliveries`, and delivery analytics never
queries `school_audit_events` **except** for the two pieces of evidence
that only exist in the audit ledger (emergency-bypass channel/count),
which is fetched read-only, never written.

## 4. Canonical data sources (no duplication)

No new event table, no new delivery table, no summary/materialized
table. Both read models query the existing tables listed in §2. The
only schema change is one additive index (§14).

## 5. Audit read model

`App\Domain\Communications\Application\CommunicationAuditReadModel`
projects `SchoolAuditEvent` rows for one Announcement into
`CommunicationAuditEntry` (event, label, actorName, occurredAt,
metadata, isEmergency). `label` normalizes presentation only — `event`
keeps the raw stored `event_type` string forever (old records are
never rewritten). An unmapped future event type falls back to a
readable default derived from the code, never a raw dump.

Safe metadata is an explicit allowlist (`audienceType`, `resolvedCount`,
`scheduledAt`, `previousScheduledAt`, `newScheduledAt`, `requirement`,
`channel`, `requestedChannels`, `justification`) — every other stored
key is dropped before reaching the UI.

## 6. Audit authorization

`communications.audit.view` (already seeded, already granted to
`school_admin` only) gates `CommunicationAuditController::show()`.
`communications.view` alone is insufficient — proven by
`CommunicationAuditHubTest::communications_view_alone_is_not_sufficient_for_the_audit_timeline`.

## 7. Private-conversation audit privacy

This checkpoint's audit UI covers **Announcements only** (§13's brief
scope: conversation analytics deliberately limited, no conversation
audit timeline built). The boundary is documented here for the audit
read model's own metadata allowlist: it can never surface `body`/
message content — only actor, timestamp, and the fixed structured
metadata keys above. A future conversation audit timeline must follow
the identical rule: "thread created" / "participant added" / "message
delivery failed" are operational facts distinct from the private
message body, and only the former belong in any audit-capable view.

## 8. Emergency audit

`announcement.emergency_declared` carries `requirement` and
`justification`. `justification` is shown **only** when the viewer
already satisfies `communications.manage OR communications.emergency`
— the exact same gate `AnnouncementController::presentDetail()` already
uses for `emergencyJustification` on the Show page
(`includeEmergencyJustification`). Audit privilege is never a wider
route to that content than the viewer already has (proven by
`CommunicationAuditReadModelTest::emergency_justification_is_redacted_for_an_unauthorized_viewer`).
`communication.emergency_quiet_hours_bypass_used` shows which channel
bypassed, with a distinct visual emphasis (`isEmergency: true`).

## 9. Delivery analytics read model

`App\Domain\Communications\Application\CommunicationDeliveryAnalyticsReadModel`
provides:

- `announcementSummary(School, CommunicationAnnouncement): array` — per-
  announcement bucket summary.
- `schoolOverview(School, Carbon $fromUtc, Carbon $toUtc): array` —
  School-wide, date-ranged aggregate.
- `deliveryDetail(School, CommunicationAnnouncement, array $filters,
  perPage, page): LengthAwarePaginator` — authorized drill-down.
- `attemptHistory(School, CommunicationDelivery): Collection` — append-
  only attempt history for one delivery.

It reuses `CommunicationDeliveryFailureReadModel::failureBreakdown()`
for the failure list rather than re-deriving it (one canonical failure
definition).

## 10. Per-announcement summary

```
recipients: int (announcement.recipient_count)
channels: {
  in_app:  { planned, available, read, unread }
  email:   { planned, sent, failed, inProgress, suppressed }
}
deferredQuietHours: { <channel>: count }
failures: [ {channel, failureCode, count}, ... ]
emergency: null | { declaredByUserId, declaredAt, quietHoursBypassChannels, eligibleDeliveryCounts }
```

## 11. School overview

```
published, recipients, channels (same shape as §10),
deferredQuietHours, suppressed,
emergency: { announcements, bypassEvents }
```

## 12. IN_APP read semantics

`planned` = every `in_app` delivery row created for the message.
`available` = rows in `delivered` or `read` (brief §50's "eligible
IN_APP recipients/deliveries" — the correct read-rate denominator,
never `planned`, since a still-`pending` row was never actually
available to read). `read` = rows in `read`. `unread = available -
read`. EMAIL read/open state is never inferred from IN_APP behavior.

## 13. EMAIL sent semantics

`sent` means **accepted by the configured application mail transport**
— never "delivered to mailbox," "opened," or "read." Every UI label
reads "Sent to transport." No delivery-rate percentage is ever labelled
"delivery rate" — where shown, it is "send rate," computed as `sent /
planned` with `planned` already excluding suppressed recipients (they
never have a delivery row at all).

## 14. Suppression semantics

Policy suppression (`communication_delivery_policy_decisions`) is
**never** counted in `failed`, `sent`, or `planned` — it is its own
bucket (`suppressed`), because no delivery attempt was ever made.
Proven by
`CommunicationDeliveryAnalyticsReadModelTest::a_summary_buckets_read_unread_sent_failed_and_suppressed_exactly_once`.

## 15. Deferred semantics

A delivery deferred by quiet hours is `status = 'queued' AND attempts =
0` at read time. It is bucketed in `inProgress` (the generic
"not-yet-terminal" bucket, alongside a delivery mid-retry-backoff) and
*additionally* surfaced under the dedicated `deferredQuietHours` key so
an operator can distinguish "genuinely still queued for the first time"
from a retry in flight. It is never counted as `failed`.

## 16. Failure semantics

`failed` = delivery rows whose status is `failed`, `bounced`,
`rejected`, or `expired` (only `failed` is reachable today — the others
are reserved for a future real provider). The failure-code breakdown
reuses `CommunicationDeliveryFailureReadModel`, grouped by
`(channel, failure_code)`, never a raw exception message/stack trace.

## 17. Attempt-history semantics

`communication_delivery_attempts` is append-only and every row is
preserved. `attemptHistory()` returns every attempt, ordered by
`attempt_number`, with `outcome` (`success | transient_failure |
permanent_failure`), `failureCode`, timestamps, and `durationMs` — never
a provider payload or credential.

## 18. Retry / current-state distinction

`announcementSummary()`/`schoolOverview()` bucket by the delivery's
**current** `status` column only — never by counting attempts. A
delivery whose first attempt transiently failed and whose second
attempt succeeded is counted exactly once, as `sent`, in the aggregate
buckets; its full two-attempt history remains separately visible via
`attemptHistory()`. Proven by
`CommunicationDeliveryAnalyticsReadModelTest::a_delivery_that_failed_then_succeeded_on_retry_counts_once_as_sent_with_full_attempt_history`.

## 19. Emergency bypass evidence

`emergencyEvidence()` (private, inside the read model) queries
`school_audit_events` for `communication.emergency_quiet_hours_bypass_used`
rows scoped to the one Announcement, extracts the bypassed channel(s)
from `metadata.channel`, and reports `eligibleDeliveryCounts` as that
channel's own `planned` count from the SAME summary — i.e. every
delivery that channel actually created (bypass means "sent now instead
of deferred," not "delivered successfully"; the `sent`/`failed` split
for that channel is still reported normally in `channels`). This is
presented as governance/timing evidence in the UI, explicitly never as
a "delivery success" metric.

## 20. Quiet-hour evidence

`deferredQuietHours` (per-channel counts, §15) is the delivery-analytics
side; `communication.emergency_quiet_hours_bypass_used` audit rows are
the audit side. Both are surfaced, kept distinct, and neither is
double-counted against the other (an Emergency-bypassed delivery is
never also counted as deferred — proven by
`an_emergency_bypass_is_visible_as_governance_evidence_not_a_success_metric`).

## 21. Filters / date ranges

School overview supports `today | 7d | 30d | custom` (custom is
explicitly bounded to 366 days,
`CommunicationAnalyticsController::resolveDateRange()`). Per-
announcement drill-down supports `channel`, `status`, `failure_code`,
each validated against a fixed allowlist server-side — never
interpolated into raw SQL.

## 22. Timezone behavior

Date-range boundaries are computed in the School's timezone
(`App\Support\Tenancy\SchoolTimezone::resolve()`, the same helper
Phase 5A.4 scheduling already uses) and converted to UTC before any
database comparison. Stored timestamps (`published_at`,
`occurred_at`) are always canonical UTC.

## 23. Tenant / RLS protection

Every read-model method runs inside
`TenantContext::withSchool($school, ...)`, matching every other
Communications read model. The School-overview query additionally
filters `school_id` explicitly on every joined table (defense in
depth, beyond RLS alone) given its cross-announcement aggregate shape.
Cross-School access is proven at both the read-model layer
(`CommunicationAuditReadModelTest::school_a_cannot_see_school_bs_audit_timeline`,
`CommunicationDeliveryAnalyticsReadModelTest::school_overview_aggregates_across_announcements_and_never_leaks_across_schools`)
and the HTTP layer (cross-School announcement id → 404, in both new Hub
test files). No new tenant-owned table was created, so no new
RLS-migration test was required — the existing
`Tests\Feature\Postgres\CommunicationsRlsIsolationTest` suite already
covers every table these read models query.

## 24. Authorization

| Surface | Capability |
| --- | --- |
| Audit timeline | `communications.audit.view` |
| School overview | `communications.manage` |
| Per-Announcement analytics + drill-down | `communications.manage` |

No new capability was introduced — both already existed and are
already granted only to `school_admin` (`principal` has neither, an
existing, deliberate trust boundary this checkpoint reuses unchanged).

## 25. Performance / query strategy

Every summary method issues a small, fixed number of grouped aggregate
queries (`GROUP BY channel, status` etc.), never one query per
recipient. `CommunicationDeliveryAnalyticsReadModelTest::summary_and_overview_queries_are_bounded_regardless_of_recipient_volume`
creates 25 recipients and asserts the total query count for
`announcementSummary()` stays under 10.

## 26. Indexes / query plans

One additive migration:
`2026_08_24_090000_add_subject_index_to_school_audit_events_table.php`
adds `(school_id, subject_type, subject_id)` to `school_audit_events` —
the exact shape the audit timeline's "every event for this
Announcement" query needs, which no existing index on that
general-purpose ledger covered. No other index was added: every other
query in this checkpoint joins through columns already indexed by
Phase 5A.1–5A.10 (`communication_messages(announcement_id, created_at)`,
the leftmost columns of `communication_recipients`' and
`communication_deliveries`' existing unique constraints, and
`communication_announcements(school_id, published_at)` /
`(school_id, dispatch_mode)`).

## 27. UI

- `/app/communications/analytics` — School overview (Hub page, HubNav
  tab, `communications.manage`-gated).
- `/app/communications/announcements/{id}/analytics` — per-Announcement
  delivery analytics + paginated, filterable drill-down (detail
  sub-page, no sidebar — matches `AnnouncementController::show()`'s own
  convention).
- `/app/communications/announcements/{id}/audit` — per-Announcement
  audit timeline (detail sub-page, same convention).
- The Announcement Show page gained `View analytics` / `View audit`
  links, each independently gated by its own capability flag
  (`canViewAnalytics`, `canViewAudit`).

Cards + tables throughout, no new charting dependency. "Sent to
transport" terminology and a standing disclaimer about provider-data
limitations appear on the School overview page.

## 28. Tests

New Phase 5A.11 test files (26 tests, 123 assertions):

- `tests/Feature/Communications/CommunicationDeliveryAnalyticsReadModelTest.php`
  (6) — bucket correctness, quiet-hours deferral, emergency bypass,
  retry non-double-counting, School overview + tenant isolation,
  bounded query count.
- `tests/Feature/Communications/CommunicationAuditReadModelTest.php` (5)
  — event ordering/labels, emergency-declared + justification
  redaction, quiet-hours-bypass event, cross-School isolation.
- `tests/Feature/App/CommunicationAnalyticsHubTest.php` (9) —
  authorization (allow/deny for both surfaces), cross-School 404,
  forged id 404, read-only guarantee.
- `tests/Feature/App/CommunicationAuditHubTest.php` (6) — same shape for
  the audit route.

## 29. Safety

- `COMMUNICATION_EMAIL_ENABLED` remains `false` by default; no real
  external email was sent (`Mail::fake()` throughout).
- No provider credentials, no SMS/WhatsApp/Push/voice code added.
- No staging/production changes; no deploys.
- No Phase 1A integration; no merge/rebase/push.
- Every new route is read-only — proven explicitly
  (`viewing_the_audit_timeline_never_mutates_announcement_state`,
  `viewing_analytics_never_mutates_announcement_state`).

## 30. Provider-data limitations

No delivered/bounced/opened/clicked/complaint data is available or
implied anywhere in this checkpoint's UI or read models — every EMAIL
success state is presented as "sent to transport," documented on the
School overview page itself.

## 31. Deferred work

Out of scope, per the brief, and not started: data warehouse/ETL,
provider delivery webhooks, bounce/open/click analytics, AI analytics
or anomaly detection, engagement/responsiveness/performance scoring,
bulk CSV export, approval workflow, emergency escalation (SMS/
WhatsApp/Push/voice), manual retry controls, Phase 1A integration.

## 32. Next recommendation

See the Phase 5A.11 final report's §R for the evaluated options
(Approval Workflow Foundation / Emergency Escalation Foundation /
Operational Delivery Retry Controls / Email Provider Event/Webhook
Foundation). Phase 5A.12 is not started here.
