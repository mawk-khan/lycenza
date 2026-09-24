# ADR 0043: Automation Domain Contract (Phase 0L.5)

- Status: Accepted
- Date: 2026-09-24 (Phase 0L.5)

## Context

`docs/architecture/DOMAIN-MAP.md` defines **Automation** in one Layer 5
row: *"Rules/workflow engine reacting to domain events (ADR 0010) |
Subscribes to events from any module; calls back only through modules'
Application-layer contracts | Automation acting on a module is
indistinguishable, from that module's perspective, from any other
authorized caller — it does not get a special bypass."* ADR 0040 §1 and
ADR 0042 §12 left it unscoped; ADR 0042 assigned it "a due-date or
reminder feature". **The product owner established "Phase 0L.5 —
Automation Domain Contract" on 2026-09-24** — the number and title only;
everything below is derived from the repository. Like ADR 0040 and
ADR 0042, this ADR changes no code.

A pre-contract audit of `main` at `b67aecc` (full inventory:
`docs/modules/AUTOMATION.md` §2) found:

- **No Automation exists, and nothing today is cross-domain
  automation.** Asynchronous work is module-owned background processing
  (Communications delivery, scheduled publish), integration
  infrastructure (outbox dispatch, webhook fan-out/delivery) and
  maintenance (idempotency/webhook prune). The only consumer that reacts
  to one module's event with another module's effect,
  `App\Support\Events\Consumers\NotifyActorOfSettingChangeConsumer`, is a
  Phase 0C demo whose triggering event has no production producer.
- **41 outboxed domain events** (Academic Structure, Campuses, Schools,
  Communications, Fees, Finance, Payments, HR, Payroll, Platform) go to
  the transactional outbox (ADR 0025); **39 have no consumer**. Students,
  Guardians, Identity, Admissions, Attendance, Timetable, Examinations,
  LMS, Library and the Layer 3 operations modules emit no events.
- **No reminder, task, alert or workflow concept exists** — only
  passive dates nothing acts on (`charges.due_date`,
  `library_loans.due_at`, `assignments.due_on`,
  `employee_certifications.expires_on`, `employee_documents.expires_on`,
  guardian invitation `expires_at`).
- **Every authority path is a human `User`.** `CapabilityResolver`, the
  `capability` Gate, `TenantContext::setActor()`, `AuditRecorder`
  (`actor_user_id` is a `users` FK) and Communications authorship
  (`created_by_user_id`, `sender_user_id` NOT NULL) all require a User.
  Service identities (`service_identities`, `service_identity_capabilities`)
  are **platform-level machine credentials** with no `school_id`, used
  only by the AI Gateway's internal routes; the issuer has no production
  caller. Existing background actions act **as a named human**:
  `PublishScheduledAnnouncements` publishes as `scheduledBy ?? createdBy`,
  and `AiGatewayClient::invokeTool()` checks the human's capability before
  minting a context token (ADR 0023).
- **Reliability substrate exists**: `EventConsumer` +
  `EventConsumerRegistry` + `IdempotentConsumerGuard` +
  `event_consumer_receipts` (`UNIQUE(consumer_name, event_id)`); outbox
  `correlation_id` (NOT NULL) and `causation_id` (`causedBy()`); the
  conditional-UPDATE lease claim with `tries=1` and domain-level retry
  (`DeliverWebhookJob`, `ProcessCommunicationDeliveryJob`, CLAUDE.md
  rules 48/58/59); `TenantLock`; the per-School scheduler pattern
  (`TenantContext::withSchool()` per School, heartbeat recorded).

## Decision

### 1. Automation is an internal Layer 5 bounded context — `App\Domain\Automation`

Namespace reserved, not created. A consumer only: no Layer 0–4 module
depends on it or breaks without it.

**What Automation owns** — and nothing else:

| Concept | Meaning |
|---|---|
| **Rule catalog** (code) | A closed, code-registered list of **rule types**. Each rule type declares its one trigger, its condition (a fixed, reviewed piece of code reading source contracts), its one action type, its bounded parameters, its risk tier (§4) and the capability its action needs. Like `WebhookEventRegistry` and `AnalyticsReadModelRegistry`, nothing outside the catalog can run. |
| **Rule instances** (School data) | A School's enablement of a catalog rule type: enabled/disabled, parameter values within the declared bounds, and its **accountable owner** (§5). |
| **Executions** | One record per (rule instance, trigger occurrence) — the idempotency unit (§6). |
| **Execution attempts** | Append-only record of each attempt: status, stable error code, timing. |
| **Review items** | Automation's own informational output (§3 tier 0): "this rule found this, look at it", referencing source records by id only. |

**Not a generic engine.** Schools do not author conditions, scripts,
expressions, queries or workflows. There is no rule language, no
condition builder, no chaining, and no arbitrary "when X then Y". A new
behaviour is a new catalog entry, reviewed like any other code.

**What Automation must never own or do**: Student, Guardian, HR,
Payroll, Finance, Fees, Payments or other source records; Payroll
calculations or statutory rules; Communications messages, deliveries or
policies; audit ledgers; Compliance evidence; source-domain approval
state; legal rules, retention periods or consent. It never writes
another module's tables, never bypasses authorization, tenancy,
approval or legal gates, and never mutates a source aggregate except
through that module's Application-layer contract.

### 2. Triggers: domain events and schedules; nothing else in v1

- **Event triggers** — an outbox event type the catalog lists. Consumed
  by **one** Automation `EventConsumer` registered in
  `EventConsumerRegistry` for exactly the catalog's event types, run
  through `IdempotentConsumerGuard`. Like `WebhookFanoutConsumer`, it only
  claims execution rows and queues execution jobs; it never performs an
  action inline. An event type without a producer cannot be a trigger;
  adding an event is the source module's work.
- **Schedule triggers** — one scheduler entry (`routes/console.php`) that
  visits Schools one at a time inside `TenantContext::withSchool()`,
  records a heartbeat, and asks each enabled scheduled rule's condition
  for due occurrences **through the source module's read contract** —
  never by querying another module's tables. Bounded per run.
- **Not in v1**: manual "run now", arbitrary database polling, webhooks
  or external systems as triggers, AI-originated triggers, and rules
  triggered by another rule (§8).

A catalog entry has exactly one trigger. The first implementation
enables only what its first rule type needs.

### 3. Actions and risk tiers

An action is a catalog-declared call to **one** approved Application-layer
entry point of its owning module (or, for tier 0, Automation's own
review-item record). Every action type declares its tier:

| Tier | Meaning | v1 |
|---|---|---|
| 0 — Informational | Write an Automation review item (own table), no effect outside Automation | **Allowed** |
| 1 — Internal notification | Ask Communications to notify School staff (no Guardians/Students) through its own services, policies and approval rules | **Gated** — needs the §10 notification decision |
| 2 — Reversible low-impact source command | Call a source command the owning module has explicitly marked automation-safe | **Gated** — none exists; each needs the source module's own review and an amendment here |
| 3 — Approval-required | Any effect that a human must approve first | **Not in v1** — needs a human-approval design (shared with Phase 0M) and a per-action decision |
| 4 — Prohibited | Never autonomous | **Prohibited** |

**Tier 4 — never performed autonomously**: Payroll calculate/approve/
post/reverse; Finance posting or reversal; charge assessment or
cancellation; payment allocation; Student status, enrollment or
admission decisions; employee create/update/archive/lifecycle changes;
permission, role or membership grants; delete or archive of any record;
consent or processing-authorization records; external communications
to Guardians, Students or outside parties; Emergency communications
(`CommunicationDispatchMode` is "always an explicit human choice");
Required communications; statutory filings; changes to retention or
legal holds. Moving any of these to tier 3 needs an explicit product and
security decision and an amendment to this ADR.

Automation must never use `App\Support\Notifications\NotificationDispatcher`
(a Phase 0C demo that bypasses Communications policy, consent and quiet
hours).

### 4. Tenancy

One School per rule instance, per execution and per job. Automation
tables are tenant-owned (`BelongsToSchool`, `TenantRls::enable`; attempts
also `TenantRls::makeAppendOnly`). Event executions take the School from
the event row; scheduled ones run inside `withSchool()`; jobs carry the
School id explicitly and clear context in `finally` (CLAUDE.md rules 21,
57). No `BYPASSRLS`, no superuser path, no cross-School rule, no
platform-wide rule, no implicit Platform Super Admin access.
Cross-School or platform Automation needs its own ADR.

### 5. Authority: delegated, re-verified human authority — no new principal

**An execution acts as the rule instance's accountable owner, a real
School member, and never with more authority than that person holds at
the moment the action runs.**

- The owner is the human who enabled (or last re-enabled) the rule
  instance and must hold `automation.manage` then.
- **At execution time**, before any action, Automation re-verifies: the
  owner's account is active, their School membership is active, they
  still hold `automation.manage`, and they hold **the action's declared
  capability** through the normal `CapabilityResolver`. The effective
  authority is the **intersection** of the owner's current capabilities
  and the action type's declared capability — never the owner's full
  capability set. If any check fails, the execution fails closed with a
  stable reason and the rule instance is **suspended** until someone
  with `automation.manage` re-enables it (becoming its owner).
- Configuring a rule grants nothing. Automation never holds, caches or
  mints a capability of its own; a revoked capability takes effect on
  the next execution.
- **Why not a service identity.** Service identities are platform-level
  credentials for authenticating another process (the AI Gateway), with
  no School scope and no audit-actor representation. Making one act
  inside a School would need a second principal type across
  `CapabilityResolver`, the Gate, `TenantContext`, `AuditRecorder` and
  Communications authorship — a second identity system this ADR refuses
  to build. **Why not the creator's authority as captured at setup**:
  authority captured once outlives role changes; re-verification closes
  that.

This is the delegation model the repository already uses
(`PublishScheduledAnnouncements`, ADR 0023), made stricter by per-run
re-verification and capability intersection.

### 6. Authorization

`automation.*`, spelling frozen, **not seeded** here:

| Capability | Scope |
|---|---|
| `automation.view` | See a School's rule instances, execution history and review items |
| `automation.manage` | Enable, disable, parameterize rule instances; become their owner; (later) retry a failed execution |
| `automation.platform.view` | Future cross-School view — spelling only; unusable until §4's deferral is resolved |

There is no `automation.execute`: executions are started by triggers,
not by people, and the action's own capability is what authorizes the
effect (§5). Which roles receive these is an owner decision (§10).
Viewing a review item never grants access to the referenced source
record — opening it goes through the source module's own capability.

### 7. Idempotency, retries and failure

- **Execution identity**: `(school_id, rule_instance_id, trigger_key)`
  with a PostgreSQL unique constraint as the sole authority (CLAUDE.md
  rule 30). `trigger_key` is the outbox `event_id` for event triggers,
  and a catalog-defined occurrence key (e.g. the source record id plus
  the due date it was evaluated against) for schedule triggers, so a
  re-run finds nothing new to do.
- **Consumer dedup**: the Automation consumer's own receipt in
  `event_consumer_receipts` (unchanged mechanism).
- **Action execution**: a queued job per execution, `tries = 1`, explicit
  `$timeout` below `retry_after`; the conditional-UPDATE lease claim
  (rule 48) so two workers never act twice; bounded domain-level retry
  with backoff for transient failures only (rule 59).
- **Terminal states**: `succeeded`, `failed` (permanent), `abandoned`
  (retries exhausted), `skipped` (authority re-verification failed, rule
  disabled, or the condition no longer holds when re-checked). No
  dead-letter table: the execution row is the record, and Laravel's
  `failed_jobs` catches crashes. Manual retry by `automation.manage` is a
  later feature.
- **Source-side safety**: an action passes the execution id to the
  source contract where it supports idempotency. Where it does not,
  the action type must be safe to repeat, or it is not automation-safe.
  Automation never claims exactly-once effects outside its own tables.

### 8. Loop, causation and flood protection

- Events emitted while an Automation action runs carry
  `causation_id` = the triggering event (or the execution's own id for
  schedule triggers) and the propagated `correlation_id`, and are marked
  in outbox `metadata` as Automation-originated with the execution id.
- **Depth 1 in v1**: the Automation consumer ignores any event marked as
  Automation-originated, so no rule can trigger another rule or itself,
  directly or through a chain.
- Execution idempotency (§7) absorbs duplicate event delivery and
  retries.
- Each rule type declares a maximum number of executions per School per
  run/window; exceeding it suspends the instance and records why.
- Correlation, causation and request ids stay diagnostic only (CLAUDE.md
  rule 62); none is consulted for authorization.

### 9. Audit and classification

- **Configuration**: enable, disable, parameter change and owner change
  are School audit events (`automation.rule.*`, actor = the human).
- **Executions and attempts**: recorded in Automation's own tenant
  tables (attempts append-only) — the execution history. Not also
  written row-by-row to the School audit ledger.
- **Effects**: the source module audits its own state change as it
  always does; actor = the rule owner, with metadata naming the rule
  instance and execution id, so the Compliance audit log (Phase 0L.4)
  shows who was accountable and that Automation acted.
- **Classification**: Automation records inherit the strictest tier of
  the source entities and actions they concern. Rule parameters,
  execution records and review items hold **identifiers, codes and
  timestamps only** — no source payload, snapshot, name, contact detail,
  amount or free text. Failure details are stable codes, never exception
  messages carrying data (`LogSanitizer` remains the backstop for logs).

### 10. Relationships

- **Compliance (ADR 0042)**: Compliance defines evidence and reportable
  conditions and stays read-only. Automation may later call a
  Compliance read contract as a rule condition and raise a review item
  or (tier 1) an internal notification. Automation never decides legal
  compliance, invents retention or legal obligations, or alters
  Compliance evidence.
- **Analytics (ADR 0040)**: no rule condition uses Analytics in v1. A
  future condition may use only an Analytics result obtained through
  `AnalyticsReadGate` as the rule owner (who must hold `analytics.view`);
  the cohort policy, the gated Student-person Analytics and the
  single-School limit apply unchanged.
- **Communications**: Automation never sends email, SMS, push or in-app
  messages itself. A tier 1 action calls Communications' Application
  services as the owner, subject to its channel, consent, quiet-hours
  and approval rules; Communications owns every message and delivery.
- **Phase 0M AI**: Automation is deterministic, catalog-bound and
  rule-based. AI agents do not author, enable or change rule instances,
  there is no AI tool contract for Automation, and no trigger or action
  type involves the AI Gateway in v1. An agent can therefore gain no
  authority through Automation; any future AI-proposed rule would still
  need a human with `automation.manage` to enable it, and would run as
  that human (§5). Phase 0M's own provider/legal gates are unaffected.

### 11. Retention

Execution records, attempts and review items will need a retention
period. It is **[LEGAL/POLICY REVIEW REQUIRED]** like every other
category (`DATA-CLASSIFICATION.md`); none is set here. When one is
decided, Automation implements it with a prune command that has no
default period, the `platform:webhook-deliveries-prune` precedent.

### 12. Open decisions (owner / security / legal)

See `docs/modules/AUTOMATION.md` §5 for the full matrix. The contract
itself decides the boundary, catalog model, trigger types, tiers, the
delegated-authority model, idempotency, loop protection, audit shape and
the no-payload rule. The owner still decides, before the foundation:
which catalog rule type comes first; which roles get `automation.view`/
`automation.manage`; confirmation of the §5 authority model by security
review; whether tier 1 notifications are wanted and how they are
authored/approved; per-School opt-in (a `FeatureFlagResolver` flag,
default off, is available). Retention (legal), tier 3 approval flows,
platform/cross-School scope and exports stay deferred.

## Rationale

- A closed, code-reviewed catalog gives Schools useful behaviour without
  creating a user-programmable privileged engine that sits outside every
  module's review.
- Delegated, re-verified human authority reuses every existing control
  (capabilities, tenancy, audit attribution, Communications authorship)
  and fails closed when a person's access changes.
- Reusing the outbox consumer, receipts, lease claims and domain retry
  keeps one reliability model across webhooks, Communications and
  Automation.
- Tier 0 review items let the first implementation prove triggers,
  tenancy, authority, idempotency and audit without changing any source
  module.

## Alternatives considered

1. **User-authored rules (condition builder or rule language).**
   Rejected: arbitrary conditions would read across modules without each
   module's review and become the privileged bypass `DOMAIN-MAP.md`
   forbids.
2. **A School-scoped service identity as the execution principal.**
   Rejected for v1: it needs a second principal type throughout
   authorization, audit and Communications; the delegated model achieves
   the same bounded authority with existing mechanisms.
3. **Run with the creator's authority captured at setup.** Rejected:
   authority must track the person's current access.
4. **Generic database polling for schedule triggers.** Rejected: reads go
   through source read contracts only.
5. **Automation sends notifications itself** (e.g. via
   `NotificationDispatcher`). Rejected: it would bypass Communications
   policy, consent, quiet hours and approval.
6. **Allow high-impact actions behind a setting.** Rejected: tier 4 is
   prohibited until a human-approval design and an explicit decision
   exist.

## Consequences

- `docs/modules/AUTOMATION.md` becomes the living reference (inventory,
  gates, candidates, plan).
- `DOMAIN-MAP.md`'s Automation row points here; its dependency column is
  unchanged.
- `AUTHORIZATION.md` records the reserved `automation.*` keys and the
  delegated-authority rule; `DATA-CLASSIFICATION.md` records the
  classification and no-payload rule.
- `MASTER-ROADMAP.md` gains the Phase 0L.5 entry.
- No code, migration, route, capability seed or configuration change.
