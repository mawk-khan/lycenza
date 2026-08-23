# ADR 0010: In-Process Domain Events, No Distributed Event Platform Yet

- Status: Accepted
- Date: 2026-08-22

## Context

Future modules need to react to things that happen elsewhere in the
system without being tightly coupled to each other (e.g. Communications
reacting to `StudentAbsent`, Analytics reacting to `PaymentReceived`,
a future AI agent reacting to `ParentConcernCreated`). We need an event
model now, defined before business modules are written, even though no
module is implemented yet in Phase 0A.

## Decision

School OS uses **Laravel's built-in event system** (in-process
event dispatch, with queued listeners for anything that shouldn't block
the triggering request) as the domain-event mechanism, with events
following the envelope defined in `packages/contracts/events/` and
`docs/architecture/EVENTS.md`. **No distributed event platform (Kafka,
NATS, etc.) is introduced at this stage.**

Every domain event: has a stable `eventType` name (e.g.
`StudentAdmitted`), an `eventVersion`, is tenant-scoped (`tenantId`,
`schoolId`), and is published through the owning module's Application
layer — never dispatched directly from an Eloquent model or a
controller.

## Rationale

- Laravel's event system, backed by the Redis queue (ADR 0006) for
  queued listeners, already gives us: decoupled listeners, retries,
  and delayed/background processing — the properties that matter for
  automation, notifications, analytics, and future AI-agent triggers —
  without adding a new piece of infrastructure to operate.
- Because the whole ERP core is one deployable (ADR 0001), in-process
  events reach every module's listeners without a network hop; a
  distributed broker would add latency and operational cost to solve a
  problem (cross-service delivery) this deployment shape doesn't have.
- Defining the **event envelope and contract now** (even with zero real
  producers/consumers yet — see the illustrative example schema in
  `packages/contracts/events/`) means future modules don't each invent
  their own event shape, which would make cross-module automation and
  the future AI Platform's event consumption inconsistent.

## Alternatives considered

1. **Kafka or another distributed streaming platform from day one.**
   Rejected per the explicit Phase 0A instruction and on the merits:
   nothing about the current deployment shape (one monolith, one AI
   service) requires distributed pub/sub yet, and it would be pure
   operational overhead — extra infrastructure to run, monitor, and
   secure — for a team at this stage.
2. **A generic outbox-pattern table with a separate relay process.**
   Rejected for now: valuable mainly once cross-service (not just
   cross-module) delivery is a real requirement; premature while
   everything is in-process.
3. **No formal event system; ad hoc method calls between modules.**
   Rejected: this is exactly the bidirectional coupling
   `docs/architecture/DOMAIN-MAP.md` prohibits — modules would end up
   calling into each other directly instead of through the enforced
   dependency direction.

## Consequences

- Every module that produces a meaningful business fact should define
  and publish a domain event for it, even before there are any
  consumers, so the automation/notification/analytics/AI layers built
  later have something to subscribe to without a retrofit.
- Event listeners that are not safe to run synchronously within the
  triggering request (sending a notification, calling out to the AI
  Gateway, updating analytics) must be queued, not inline.
- Because there is no distributed broker, events do not currently cross
  process/service boundaries — the AI Platform does not currently
  consume Laravel events directly; it is invoked through the tool/agent
  contract (ADR 0013, 0014) instead. If a future need arises for the AI
  Platform to react to events asynchronously, that is a webhook/queue
  integration to design explicitly, not an assumption already built in.

## Future extraction/evolution path

If/when a specific integration need requires reliable cross-service or
cross-process event delivery (e.g. a school-group system consuming
events from multiple School OS tenants, or the AI Platform subscribing
to a live event stream instead of being invoked synchronously), that is
the point to introduce an outbox pattern and/or a message broker —
justified by that concrete requirement, not spun up speculatively now.
