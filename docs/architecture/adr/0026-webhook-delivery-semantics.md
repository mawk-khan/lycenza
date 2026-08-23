# ADR 0026: Webhook Delivery Semantics — At-Least-Once and HMAC Signing

- Status: Accepted
- Date: 2026-08-23

## Context

ADR 0018 established that outbound webhooks are modeled as
subscriptions to domain events, delivered asynchronously and
HMAC-signed. Phase 0C.3 implements that pattern concretely and must fix
two durable decisions before any real integration is built against it:
what delivery guarantee School OS actually makes to a receiver, and the
exact signing contract a receiver implements against. Both are
expensive to change once a real third-party integration exists, so
they need to be right — and honestly described — from the first real
consumer.

## Decision

**Delivery is at-least-once, never exactly-once, and this is stated
explicitly rather than implied.** A receiver can accept an HTTP request
and even finish processing it before the TCP connection breaks and
School OS observes only a failure — School OS then retries, and the
receiver genuinely receives the same event twice. School OS's own
internal bookkeeping guarantees exactly one *logical* delivery row per
`(webhook_endpoint_id, event_id)` pair (a database unique constraint),
but that internal guarantee does not — and cannot — extend across an
unreliable network to a guarantee about how many times the receiver's
HTTP endpoint is actually invoked. Every receiver is required, in
documentation delivered alongside the signing contract, to deduplicate
using the event id or delivery id header.

**Signing is HMAC-SHA256 over `{timestamp}.{deliveryId}.{rawBody}`**,
sent as separate headers (`X-SchoolOS-Timestamp`,
`X-SchoolOS-Delivery-Id`, `X-SchoolOS-Signature-Version: v1`,
`X-SchoolOS-Signature`) rather than one combined header — chosen for
receiver-side simplicity (no header parsing beyond reading distinct,
well-named values) and because it keeps replay protection (the
timestamp-tolerance check) and delivery-binding (the delivery id in the
signed material) each independently visible in the request without
needing to decode a compound value first. `App\Support\Webhooks\
WebhookSigner` is the single authoritative implementation, used both
to sign outbound and to verify in the receiver reference/tests — no
second implementation of the same algorithm exists.

**A logical delivery may have many real HTTP attempts, each recorded
as its own durable, append-only row** (`webhook_delivery_attempts`),
separate from the logical delivery's own row (`webhook_deliveries`).
This is what lets School OS show a receiver's operator (via the
delivery-history API) exactly what happened on each attempt — status,
timing, classification — without conflating "how many times did we try"
with "what is the current logical state."

## Rationale

- Claiming exactly-once delivery over HTTP is not achievable without
  a receiver-side transactional handshake School OS does not control;
  claiming it anyway would be a documentation lie that surfaces as a
  production incident the first time a real receiver double-processes
  a payment-adjacent event. At-least-once, honestly stated, with
  documented receiver-side deduplication, is the same approach every
  major webhook provider (Stripe, GitHub, ...) actually uses.
- Separate headers (vs. Stripe's combined `t=...,v1=...` single header)
  is a readability/simplicity choice for THIS codebase's own outbound
  implementation; `WebhookSigner::header()` is kept as a documented,
  tested alternative in case a future integration is more naturally
  modeled the other way, but is not what `DeliverWebhookJob` sends.
- Splitting delivery (logical) from attempt (physical) mirrors the
  same "claim record vs. execution record" separation already proven
  useful in `App\Support\Idempotency\IdempotencyGuard` — a delivery's
  current state answers "what should happen next," while its attempt
  history answers "what actually happened," and conflating them into
  one row (an earlier, simpler version of this schema did exactly
  that) makes both questions harder to answer correctly under retries.

## Alternatives considered

1. **Claim exactly-once delivery via a receiver-acknowledgment
   handshake (School OS waits for an explicit "processed" callback
   before considering a delivery done).** Rejected: this is a much
   larger protocol than a webhook, effectively requires the receiver to
   implement a second endpoint, and does not eliminate the underlying
   TCP-connection-breaks-after-receiver-processes race — it only moves
   where the ambiguity lives.
2. **A single combined signature header (Stripe-style).** Considered,
   not chosen as the primary contract, for the readability reason
   above; kept as a documented alternative rather than discarded.
3. **Store attempt history inline on the delivery row (a JSON array
   column, or simply overwriting "last attempt" fields).** Rejected:
   loses full attempt history (durations, per-attempt outcomes) needed
   for real operational debugging, and — more importantly — makes the
   attempt-numbering concurrency guarantee (section 43/44) harder to
   enforce with a real database constraint; a separate table with
   `unique(webhook_delivery_id, attempt_number)` is straightforward,
   a JSON array column with the equivalent guarantee is not.

## Consequences

- Every future integration built on this subsystem inherits the
  at-least-once contract and must document receiver-side deduplication
  to its own third-party consumers — this is not optional per
  integration, it is the platform's fixed guarantee.
- `webhook_delivery_attempts` is append-only at the database privilege
  level (no `UPDATE`/`DELETE` grant to the runtime role, mirroring the
  audit-ledger pattern in ADR 0021) — a future feature needing to
  "correct" an attempt record must create a new record, never edit
  history.
- A future integration with genuinely different signing needs (e.g. a
  third-party mandating their own scheme for inbound webhooks TO School
  OS) is a *different* ADR 0018 concern (inbound webhooks, not covered
  here) — this ADR governs School OS's own outbound signing only.
