# ADR 0018: Integration and Webhook Architecture

- Status: Accepted
- Date: 2026-08-22

## Context

School OS will eventually integrate with external systems: payment
gateways (fee collection), SMS/WhatsApp/email providers
(Communications), government/board compliance systems, and other
school-management or accounting software a school-group may already
use. These integrations need a consistent inbound (webhooks) and
outbound (calling third parties) pattern, decided before the first
integration is built, so each integration doesn't invent its own
conventions.

## Decision

- **Outbound webhooks** (School OS notifying an external system, e.g. a
  school-group's own reporting system, or a future public developer
  API's customers) are modeled as subscriptions to specific
  **domain events** (ADR 0010) — a webhook subscription names the
  event types it wants, and delivery is a queued job (ADR 0006) with
  retry and eventual dead-lettering, carrying the same event envelope
  defined in `packages/contracts/events/`. Every delivery is HMAC-signed
  so the receiver can verify authenticity.
- **Inbound webhooks** (an external system notifying School OS, e.g. a
  payment gateway's payment-confirmation callback) land on dedicated,
  narrowly-scoped endpoints — never the general `/api/v1` surface — are
  **authenticated per-provider** (signature verification specific to
  that provider's scheme), and are **idempotent by construction**: a
  webhook handler must safely no-op on a duplicate delivery, identified
  by the provider's own idempotency/event-id where available (this is
  the same idempotency principle `docs/architecture/API.md` requires
  for payment callbacks generally).
- **Outbound calls to third-party APIs** (e.g. calling a payment
  gateway to initiate a charge, calling an SMS provider to send a
  message) go through the owning module's Infrastructure layer
  (`app/Domain/<Module>/Infrastructure/`) as an adapter — never called
  ad hoc from a controller or another module — so the third-party
  dependency is swappable the same way AI providers are (ADR 0013)
  applies the same isolation principle to a different class of external
  dependency.
- No specific third-party integration is implemented in Phase 0A — this
  ADR fixes the pattern the first integration (most likely a payment
  gateway, given the Finance/Fees module's priority) must follow.

## Rationale

- Building outbound webhooks on the existing domain-event model (ADR
  0010) means integrations get event delivery "for free" once the
  event system exists, instead of a second, parallel notification
  mechanism.
- Idempotent inbound webhook handling is not optional for anything
  touching money: payment gateways retry callbacks, and a non-idempotent
  handler double-processing a payment is a direct financial-correctness
  bug (`docs/architecture/ARCHITECTURE.md`'s financial rules).
- Isolating third-party API calls behind each module's Infrastructure
  layer means a provider swap (a different payment gateway, a different
  SMS provider) is a contained change, and — just as important for a
  school ERP — means external-service outages degrade gracefully
  (the module can catch and handle the adapter's failure) rather than
  leaking vendor-specific exceptions into domain logic.
- Per-provider signature verification (not a generic shared-secret
  scheme) matches how real-world payment/communication providers
  actually authenticate webhooks, and is the difference between a
  webhook endpoint that's genuinely secure and one that only looks
  secure.

## Alternatives considered

1. **A generic "webhook relay" endpoint shared across all providers.**
   Rejected: different providers have incompatible authentication and
   payload schemes; forcing them through one generic endpoint either
   weakens verification or requires provider-specific branching inside
   a supposedly generic handler — better to have dedicated endpoints
   with dedicated verification from the start.
2. **Synchronous outbound webhook delivery (deliver inline during the
   triggering request).** Rejected: couples the triggering request's
   latency/reliability to a third party's availability; queued delivery
   (matching ADR 0010's event-listener pattern) keeps the ERP responsive
   even when a subscriber endpoint is slow or down.
3. **No idempotency requirement; rely on providers "usually" not
   double-sending.** Rejected outright for anything financial — this is
   exactly the kind of assumption that causes double-charged or
   double-recorded payments.

## Consequences

- The first real integration (very likely a payment gateway for Fees)
  must implement both an inbound webhook endpoint following this
  pattern and an outbound Infrastructure-layer adapter — this is
  additional upfront design work per integration, in exchange for a
  consistent, auditable integration surface.
- Webhook delivery failures/retries need monitoring once the first
  integration exists (ties into ADR 0015's observability strategy).
- Every inbound webhook handler is a security-sensitive code path
  (external, often unauthenticated-until-verified input) and should be
  covered by the security-regression testing layer described in
  `docs/architecture/ARCHITECTURE.md`'s testing strategy.

## Future extraction/evolution path

If outbound webhook volume/reliability requirements later exceed what a
Redis-backed queue comfortably handles, that delivery mechanism can be
swapped for a more specialized delivery service without changing the
subscription model (event-type based) integrations are built against.
