# School OS — Domain Event Architecture

For the decision record, see ADR 0010. This document is the operational
reference for defining and consuming domain events.

## Mechanism

Laravel's built-in event system (`Event::dispatch()` / event classes +
listeners), with queued listeners (ADR 0006) for anything that
shouldn't block the triggering request. **No distributed broker
(Kafka, NATS, etc.) exists in this system today** — see ADR 0010 for
why, and the future-extraction path if that ever changes.

## Event envelope

Every domain event carries this shape, mirrored in
`packages/contracts/events/` as JSON Schema so the contract is
machine-checkable, not just documented in prose:

| Field | Type | Required | Notes |
|---|---|---|---|
| `eventId` | uuid | yes | Unique per occurrence. |
| `eventType` | string | yes | Stable name, e.g. `StudentAdmitted`. Never reused for a semantically different event. |
| `eventVersion` | integer | yes | Bump when the payload shape changes incompatibly. |
| `occurredAt` | datetime | yes | When the business fact became true, not when a listener happened to run. |
| `tenantId` | uuid | yes | See `docs/architecture/TENANCY.md` — every event is tenant-scoped. |
| `schoolId` | uuid | yes | |
| `actorId` | uuid | no | The user or AI agent that triggered it, if any (system-triggered events may have none). |
| `payload` | object | yes | Event-specific fields — kept minimal; consumers that need more should fetch it via the owning module's Application layer, not expect the event to carry the world. |

See `packages/contracts/events/student-admitted.example.schema.json`
for a concrete, illustrative example of this envelope — it is not wired
to any producer or consumer yet.

## Publishing rules

- Events are published from a module's **Application layer**, never
  from an Eloquent model's `booted()`/observer as the sole mechanism,
  and never from a controller directly — the Application service that
  performs the state change is what knows the business fact actually
  occurred and is responsible for publishing it.
- A module should publish an event for any state change another module,
  Automation (`docs/architecture/DOMAIN-MAP.md` Layer 5), Analytics, or
  a future AI agent might plausibly need to react to — even before
  any listener exists yet, so later modules don't require a retrofit of
  the producer.
- Event payloads carry identifiers and the minimal facts needed to act,
  not full denormalized snapshots of related entities — a listener that
  needs more fetches it explicitly through the owning module's
  Application layer.

## Consuming rules

- Listeners that are not safe to run synchronously within the
  triggering request/transaction (sending a notification, calling the
  AI Gateway, updating an analytics projection) **must** be queued.
- A listener may only act on the event's payload and calls back into
  other modules only through their Application-layer contracts —
  the same cross-module discipline as anywhere else in
  `docs/architecture/DOMAIN-MAP.md`.
- Listener failures must not silently swallow the event — queued
  listener failures follow Laravel's standard job-retry/failed-job
  handling, with monitoring to be wired up once ADR 0015's
  observability backend exists.

## Illustrative future events (not implemented)

These are examples of the *kind* of event each future module will
publish — named here so future modules don't each invent inconsistent
naming, not as a commitment to their exact final shape:

`StudentAdmitted` · `StudentTransferred` · `GuardianLinked` ·
`InvoiceCreated` · `PaymentReceived` · `AttendanceMarked` ·
`StudentAbsent` · `ExamPublished` · `AdmissionLeadCreated` ·
`EmployeeAbsent` · `BusDelayed` · `ParentConcernCreated`

## Relationship to webhooks and the AI Platform

- Outbound webhooks (ADR 0018, ADR 0026/0027, Phase 0C.3) are built as
  subscriptions to specific event types —
  `App\Support\Events\Consumers\WebhookFanoutConsumer` is an ordinary
  `EventConsumer`, `IdempotentConsumerGuard`-wrapped exactly like any
  other consumer; it only claims a durable delivery row and queues the
  real HTTP send (`App\Jobs\DeliverWebhookJob`), never making the
  external call itself. Not every event type is externally
  subscribable — see `App\Support\Webhooks\WebhookEventRegistry` and
  `docs/architecture/INTEGRATIONS.md` for the full webhook delivery
  design (retry policy, signing, SSRF policy, at-least-once semantics).
- The AI Platform does **not** currently consume Laravel events
  directly (there is no cross-process event delivery mechanism yet,
  ADR 0010) — AI-initiated actions go through the tool/capability
  contract instead (ADR 0013, 0014). If a future need arises for an AI
  agent to react to events asynchronously (e.g. "draft a reminder when
  `InvoiceCreated` fires"), that requires an explicit integration
  design (most likely: a queued listener that calls the AI Gateway,
  itself subject to the same capability-gated tool boundary as any
  other AI-initiated action) — not an assumption already built into
  the event system today.

## Implemented events (Phase 0C / Phase 0D)

`App\Domain\Platform\Events\SchoolSettingChanged` (Phase 0C, the
transactional-outbox proof event) and, as of Phase 0D:

`App\Domain\Schools\Events\SchoolProfileUpdated` ·
`App\Domain\Campuses\Events\CampusCreated` · `CampusUpdated` ·
`App\Domain\AcademicStructure\Events\AcademicYearCreated` ·
`AcademicYearActivated` · `AcademicYearClosed` · `AcademicTermCreated` ·
`GradeLevelCreated` · `SectionCreated` · `SubjectCreated` ·
`SubjectOfferingCreated`

All implement `App\Support\Events\ShouldBeOutboxed` via the
`OutboxedEventDefaults` trait and are recorded through the same
transactional-outbox mechanism `RecordDomainEventToOutbox` provides
generically (ADR 0025) — no Phase 0D event required any change to that
listener. Per section 44 of the Phase 0D brief, **none of these are
registered in `App\Support\Webhooks\WebhookEventRegistry`** — they
remain internal-only for now; external publication stays an explicit,
reviewed decision per event type, not automatic. Not every
state change gets an event: trivial updates to low-consequence
reference data (e.g. `AcademicDepartment` create/update) deliberately
emit no event (section 43: "do not emit events for every trivial field
read/write").

## What is NOT yet implemented

No listener beyond the generic `RecordDomainEventToOutbox` consumes any
Phase 0D event yet — no Automation/Analytics/Compliance module exists
to react to them. `packages/contracts/events/student-admitted.example.schema.json`
remains illustrative for the first Students/SIS event, still not
implemented.
