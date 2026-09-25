# Automation (Phase 0L.5 contract; Phase 0L.6 foundation)

Status: **one rule is implemented — Academic year set-up review (Phase
0L.6, §9).** It proves one trigger, one tier 0 effect, School scope,
per-execution authority re-verification, idempotency and auditability;
it is not general Automation. Decision record: ADR 0043
(`docs/architecture/adr/0043-automation-domain-contract.md`, with its
Phase 0L.6 amendment). Phase 0L closed on 2026-09-24
(`docs/architecture/PHASE-0L-CLOSEOUT.md`); tiers 1–3, retention,
cross-School scope and exports remain gated and unscheduled.

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
| First rule type | §6 candidates | Product owner | **Decided 2026-09-24**: academic year set-up review |
| First trigger type | Event (outbox, 41 types) or schedule — follows from the first rule type | Product owner | **Decided**: event (`academic_year.activated.v1`) |
| First action type | Tier 0 review item recommended by the contract; tier 1 needs the notification decision | Product owner | **Decided**: tier 0 only |
| Authority model | ADR 0043 §5: delegated owner authority, re-verified per execution, intersected with the action capability; service identity rejected for v1 | Security review confirms | **Approved 2026-09-24** |
| Who may view / manage | `automation.view` / `automation.manage` | Product owner | **Decided**: School Admin view + manage; Principal view |
| Per-School opt-in | Existing `FeatureFlagResolver` (flag default off) or always available | Product owner | **Decided**: `automation.rules`, default off |
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

## 7. Automation Foundation checkpoint — delivered as Phase 0L.6 (§9)

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

## 9. Phase 0L.6 — Automation Foundation: Academic Year Setup Review (as built, 2026-09-24)

Owner decisions and implementation choices: ADR 0043 amendment.

### Flow

`AcademicYearService::activate()` → `academic_year.activated.v1` in the
outbox (same transaction) → `platform:outbox-dispatch` →
`ProcessOutboxEventJob` → `AutomationTriggerConsumer` (via
`IdempotentConsumerGuard`) → `automation_executions` row (pending) →
`RunAutomationExecutionJob` (after commit) → `AutomationExecutionService`
→ one `automation_review_items` row.

The consumer skips: no School; `metadata.automationExecutionId` present
(loop guard); School flag `automation.rules` off; no enabled instance, or
one enabled after the event occurred; a payload without a valid
`academicYearId`; the 24-hour cap (suspends). The job re-checks the flag
and rule status (skip) and re-verifies the owner (skip + suspend) before
creating the item.

### Code

| Piece | Where |
|---|---|
| Rule catalog | `App\Domain\Automation\Application\Catalog\AutomationRuleCatalog`, `AcademicYearSetupReviewRule` (`academic_year.setup_review`, tier 0, needs `automation.manage` + `academics.years.view`, cap 20/day) |
| Configuration | `AutomationRuleService` (`enable`, `disable`, `takeOwnership`, `suspend`) |
| Authority | `OwnerAuthorityVerifier` (fresh `CapabilityResolver` read; codes `owner_missing`, `owner_disabled`, `owner_no_school_authority`, `owner_capability_missing`) |
| School opt-in | `AutomationFeatureGate` (`automation.rules`) |
| Trigger | `AutomationTriggerConsumer` (`automation-trigger`), registered in `PlatformServiceProvider` |
| Execution | `AutomationExecutionService`, `App\Jobs\RunAutomationExecutionJob`, `automation:executions-redispatch` (every minute) |
| Read model | `AutomationReadService` (`automation.view`) |
| HTTP/UI | `App\Http\Controllers\App\Automation\AutomationController`; `GET /app/automation`, `POST /app/automation/rules/{ruleType}/enable|disable|take-ownership`; page `App/Automation/Index`; Dashboard link `nav.canViewAutomation` |
| Tables | `automation_rule_instances`, `automation_executions`, `automation_execution_attempts` (append-only), `automation_review_items` (append-only); flag seeded by migration |

Records hold identifiers, codes, statuses and timestamps only. The review
item stores `subject_type = academic_year` and the year's id (from the
School's own outbox event; no foreign key into Academic Structure). The
page shows the id and a link to School setup; it never shows an event
payload and never claims anything was set up automatically.

### Tests

- `Tests\Feature\Automation\AutomationExecutionPipelineTest` — real
  activation events → exactly one execution and item each; duplicate
  delivery (receipt, bypassed receipt, repeated job) → one item; flag
  off / other School's flag / rule disabled → nothing; never
  retroactive; flag switched off between trigger and run → skipped, not
  suspended; owner disabled / membership inactive / capability revoked
  → skipped + suspended + audited once, then re-enabled by another
  manager; loop guard; interrupted attempt + redispatch → one item;
  unexpected failures → backoff, then abandoned after 3 attempts; daily
  cap → suspended.
- `Tests\Feature\Automation\AutomationExecutionConcurrencyTest` — two
  real OS processes, verified overlap: one acts, one item, one attempt.
- `Tests\Feature\Automation\AutomationManagementTest` — grants (only
  `school_admin`/`principal`), School Admin manages, Principal views
  only, every other persona and Platform Admin refused, guests, unknown
  rule type 404, audit once per change with ids/codes, owner
  eligibility, flag grants nothing, cross-School and multi-School.
- `Tests\Feature\Automation\AutomationArchitectureGuardTest` — allowed
  imports only, no sending/foreign tables/service identity/platform
  path, no other domain depends on Automation, only the registered tier 0
  rule type.
- `Tests\Feature\Postgres\AutomationRlsIsolationTest` — RLS enabled and
  forced on all four tables, per-School raw reads, nothing without
  context, composite FKs refuse cross-School references, append-only.
- `Tests\Feature\FeatureFlags\FeatureFlagResolverWorkerScopeTest` — the
  long-running-worker regression (below).

### Findings fixed on the way

- **`FeatureFlagResolver` singleton vs scoped `TenantContext`** (real
  defect, found in DDEV): in a long-running queue worker the second and
  later jobs got a stale context from the resolver, and its
  `withSchool()` reset the RLS session variable, so the consumer silently
  saw no rule instance. Now `scoped`; the consumer resolves
  tenant-dependent collaborators per event.
- **Column-set guard tests** queried `information_schema.columns` without
  `ORDER BY` and asserted order; adding four tables changed the catalog
  plan and made them fail. They now `order by ordinal_position` (the
  pattern `GradeScalesRlsIsolationTest` already used).

## 10. A suspended School (Phase 0N.9, ADR 0047 section 8)

`AutomationTriggerConsumer` creates no execution for a School that is not
`active` (the outbox row is still recorded as dispatched), and an
execution that reaches `AutomationExecutionService::run()` while its
School is not active is recorded `skipped` with `outcome_code =
'school_suspended'` -- terminal, never retried, never replayed on RESUME
(the same shape as `automation_disabled_for_school`). The check reads the
School row FOR SHARE inside the evaluation transaction, so it serializes
with a suspension. `automation:executions-redispatch` keeps walking
suspended Schools so each pending execution reaches that terminal state
exactly once.
