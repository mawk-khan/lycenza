# Automation (Phase 0L.5 contract)

Status: **domain contract only — nothing is implemented.** Decision
record: ADR 0043 (`docs/architecture/adr/0043-automation-domain-contract.md`).
This document is the living reference: what exists today, the open
decisions, the first-implementation candidates and the plan. No
migration, model, service, job, schedule entry, route, capability seed or
page exists for Automation.

## 1. Scope in one paragraph

Automation runs **code-catalogued, School-scoped rules**: each rule type
has one trigger (a domain event or a schedule), one fixed condition that
reads source modules through their read contracts, and one action that
calls one approved Application-layer entry point (or, in the lowest
tier, records an Automation review item). Schools enable, parameterize
and own rule instances; they do not write rules. An execution acts as
the rule's accountable owner, re-verified every time, never with more
than the owner's current authority intersected with the action's
declared capability (ADR 0043 §5).

## 2. Existing automation-like behavior (audit of `b67aecc`, 2026-09-24)

Class: **A** source-module background processing · **B** integration/event
delivery infrastructure · **C** cross-domain automation · **D** scheduled
maintenance.

| Existing behavior | Owning module | Trigger | Effect | Class |
|---|---|---|---|---|
| `platform:outbox-dispatch` → `App\Jobs\ProcessOutboxEventJob` | Platform events (ADR 0025) | Scheduler, every minute | Claims pending `domain_event_outbox` rows; runs registered consumers through `IdempotentConsumerGuard` | B |
| `App\Support\Events\Consumers\WebhookFanoutConsumer` → `App\Jobs\DeliverWebhookJob` | Integrations | Outbox event registered in `WebhookEventRegistry` | Claims `webhook_deliveries`; one signed HTTP attempt per job (`tries=1`, domain retry) | B |
| `platform:webhook-deliveries-redispatch` | Integrations | Every minute | Re-dispatches due retries and expired leases | B |
| `App\Jobs\ProcessCommunicationDeliveryJob` | Communications | After commit of `AnnouncementService::publish()` / `CommunicationMessageService::send()`; redispatch command | One channel attempt; domain retry; quiet-hours deferral | A |
| `platform:communication-deliveries-redispatch` | Communications | Every minute | Re-dispatches due/deferred deliveries and expired leases | A |
| `communications:publish-scheduled` | Communications | Every minute | Publishes due scheduled announcements **as `scheduledBy ?? createdBy`** | A |
| `NotifyActorOfSettingChangeConsumer` → `App\Support\Notifications\NotificationDispatcher` | Platform (Phase 0C demo) | `school.setting.changed.v1` | Writes a `notifications` row via fake/in-app providers, bypassing Communications policy | C (demo only — `SchoolSettingsService`, its only producer, has no production caller) |
| `platform:idempotency-prune` | Platform | Daily 02:10 | Deletes expired `api_idempotency_keys` | D |
| `platform:webhook-deliveries-prune` | Integrations | Daily 02:20 | No retention period is set — deletes nothing | D |
| `App\Listeners\RecordQueueHeartbeat`, `SchedulerHeartbeatRecorder` | Observability | Queue job events; each every-minute command | Health telemetry | D |
| `App\Jobs\RecordSchoolAuditPingJob` | Tenancy (test primitive) | Tests only | `diagnostics.ping` audit row | — |
| Guardian invitation, enrollment rollover, Payroll lifecycle, Admissions, Attendance, Timetable, LMS | Their modules | HTTP requests | Synchronous; nothing asynchronous or scheduled | not async |

**Domain events available as triggers**: 41 outboxed event types —
Academic Structure (`academic_year.created/activated/closed.v1`, term,
grade level, section, subject, subject offering), Campuses, Schools,
Platform (`school.setting.changed.v1`, test event), Communications
(thread, participant, message, announcement lifecycle), Fees
(`charge.assessed/cancelled.v1`), Finance (`journal_entry.posted/reversed.v1`),
Payments (`payment.settled.v1`), HR (employee, employment, assignment),
Payroll (`payroll_run.*`, `employee_compensation.assigned.v1`). Only two
consumers exist (`notify-actor-of-setting-change`, `webhook-fanout`).
Students, Guardians, Identity, Admissions, Attendance, Timetable,
Examinations, LMS, Library and Layer 3 operations emit no events.

**Reminder/task/alert concepts**: none. Passive dates only:
`charges.due_date`, `library_loans.due_at`, `assignments.due_on`,
`employee_certifications.expires_on`, `employee_documents.expires_on`,
guardian invitation `expires_at`.

**Authority**: every authorization, audit and Communications authorship
path requires a `User`. Service identities (`service_identities`,
`service_identity_capabilities`, `App\Support\ServiceIdentities\*`) are
platform-level credentials authenticating the AI Gateway only.

## 3. Boundary (summary of ADR 0043)

| | Automation |
|---|---|
| Owns | Rule catalog (code), School rule instances (enablement, bounded parameters, accountable owner), executions, append-only attempts, review items |
| Triggers | Outbox domain events listed by the catalog (one Automation `EventConsumer`); one scheduler entry visiting Schools via `withSchool()` and source read contracts. Not: manual run, DB polling, external/AI triggers, rule-to-rule chains |
| Actions | Tier 0 review item (allowed); tier 1 internal notification via Communications (gated); tier 2 automation-safe source command (gated, none exists); tier 3 approval-required (not v1); tier 4 prohibited (financial, payroll, student/employee status, grants, delete/archive, consent, external or Emergency/Required communications, filings, retention) |
| Authority | Rule owner (School member with `automation.manage`), re-verified each execution; effective authority = owner's current capabilities ∩ the action's declared capability; failure suspends the rule |
| Never | Owns source records, messages, audit ledgers, Compliance evidence, approval state or legal rules; writes other modules' tables; uses `NotificationDispatcher`; bypasses tenancy, authorization, approval or legal gates |
| Tenancy | One School per rule/execution/job; RLS; no cross-School or platform rule |

## 4. Reused infrastructure (no second system)

`EventConsumerRegistry`, `IdempotentConsumerGuard`, `event_consumer_receipts`;
outbox `correlation_id`/`causation_id` (`OutboxedEventDefaults::causedBy()`);
the lease claim and `tries=1` domain retry (`DeliverWebhookJob` pattern,
CLAUDE.md rules 48/58/59); `TenantLock::forSchool()`; the per-School
scheduler pattern (`PublishScheduledAnnouncements`, heartbeat);
`CapabilityResolver` for the owner's authority; `AuditRecorder` for
configuration audit; `FeatureFlagResolver` if the owner wants per-School
opt-in.

## 5. Decisions still required

| Decision | Options / evidence | Approval needed | Blocks the foundation? |
|---|---|---|---|
| First rule type | §6 candidates | Product owner | **Yes** |
| First trigger type | Event (outbox, 41 types) or schedule — follows from the first rule type | Product owner | **Yes** (implied by the rule type) |
| First action type | Tier 0 review item recommended by the contract; tier 1 needs the notification decision | Product owner | **Yes** |
| Authority model | ADR 0043 §5: delegated owner authority, re-verified per execution, intersected with the action capability; service identity rejected for v1 | Security review confirms | **Yes** |
| Who may view / manage | `automation.view` / `automation.manage` — e.g. School Admin; Principal? | Product owner | **Yes** |
| Per-School opt-in | Existing `FeatureFlagResolver` (flag default off) or always available | Product owner | Yes |
| Internal notifications (tier 1) | Communications requires a User author and applies approval policy; announcements authored "by" the rule owner vs a new system-originated path in Communications | Product + Communications owner | No (only for tier 1) |
| High-impact actions (tier 3/4) | Prohibited in v1; would need a human-approval design (shared with Phase 0M) | Product + security, per action | No |
| Execution-log retention | Repository-wide retention gate | Legal | No (nothing deleted) |
| Sensitive payload storage | Contract decides: ids/codes/timestamps only | — (decided) | No |
| Platform / cross-School Automation | Deferred like cross-School Analytics/Compliance | Architecture (own ADR) | No |
| Automation exports | None | Product + security | No |
| External recipients (Guardians/Students) | Prohibited in v1 (tier 4) | Product + legal | No |

## 6. First implementation candidates (not ranked)

Each is School-scoped, deterministic, tier 0 (review item only), and
touches no Payroll/Finance mutation or legal decision.

| Candidate | Trigger | Source contract | Data | Dependencies |
|---|---|---|---|---|
| **New academic year activated → set-up review item** | Event `academic_year.activated.v1` (`App\Domain\AcademicStructure\Events\AcademicYearActivated`, already outboxed) | The event itself; the review item links the academic year by id | Confidential; no person | None beyond the foundation; the action capability would be an Academic Structure view capability |
| **Abandoned webhook deliveries → integration review item** | Schedule | New bounded read method in `App\Support\Webhooks` for recently abandoned deliveries (`WebhookDeliveryService` has only `redeliver()` today) | Confidential integration metadata; no person | That read contract; action capability `integrations.webhooks.view` |
| **Expired, unaccepted Guardian invitations → review item** | Schedule | New Identity read contract for expired pending invitations (`AccountInvitationService` exposes only `currentPendingInvitation()` per Guardian) | Sensitive (Guardian identity) — review item stores the invitation id only | That read contract; action capabilities `guardians.manage` + `school.members.manage`; classification review of the review-item surface |

## 7. Proposed next implementation checkpoint (number assigned by the owner)

**Automation Foundation** — the smallest checkpoint proving the whole
contract with one rule type:

- `App\Domain\Automation` with the catalog, a School-scoped rule-instance
  table and execution/attempt tables (tenant-owned, RLS, attempts
  append-only), and a review-item table; migrations with working
  `down()`.
- **One** trigger path (whichever the chosen rule type needs): either the
  Automation `EventConsumer` or the scheduler entry.
- **One** tier 0 action: create a review item.
- Owner re-verification and suspension (§5 of the ADR); execution
  idempotency by unique constraint; lease-claimed `tries=1` job;
  depth-1 loop guard; per-run cap.
- `automation.view` / `automation.manage` seeded for the approved roles;
  an Inertia page to enable/disable the rule and see executions and
  review items; configuration audited.
- Tests: allow/deny per persona; cross-School (Eloquent and raw RLS);
  duplicate event delivery and scheduler re-run create one execution;
  owner losing a capability → skipped + suspended; no payload stored;
  loop guard; architecture guard (no foreign-table access, no
  `NotificationDispatcher`, no tier 4 action types).
- DDEV review: enable the rule as School Admin, cause the trigger, see
  the review item; other personas 403; Annexe isolated.

## 8. Findings recorded, not fixed by Phase 0L.5

Observed during the audit; owned elsewhere; none blocks the contract.

- `App\Listeners\RecordQueueHeartbeat` is registered twice (explicit
  `Event::listen` in `AppServiceProvider` plus event discovery;
  `php artisan event:list` shows `handleFailed` twice), so each queue
  failure is recorded and logged twice.
- Nothing sets `domain_event_outbox.status = 'failed'`; an exhausted
  `ProcessOutboxEventJob` leaves the row `dispatched` (ADR 0025 documents
  `failed_jobs` as the record).
- `OperationalStatusService` does not watch the Communications scheduler
  heartbeats or the `notifications` queue.
- `docs/architecture/EVENTS.md`'s envelope table omits `correlationId`,
  `causationId` and `requestId`, which the outbox and ADR 0025 carry; it
  also says events are never emitted from controllers, but several
  Academic Structure/Campus/Schools controllers emit them.
- `App\Models\ServiceIdentity`'s docblock cites a non-existent
  "ADR 0026 service identities"; `platform.service_identities.*` and
  `platform.feature_flags.*` capabilities have no route or UI.
- Already tracked elsewhere and out of scope here: stale Payroll 9.6
  wording, the unchecked `students.processing_authorizations` flag
  (`COMPLIANCE.md` §7), the JSON `fetch()` session-expiry UX
  (`AUTHORIZATION.md`), and the open Compliance gates.
