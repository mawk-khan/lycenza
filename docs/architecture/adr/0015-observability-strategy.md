# ADR 0015: OpenTelemetry-Compatible Observability

- Status: Accepted — **amended by ADR 0051** (Phase 0O.5, 2026-09-26)
- Date: 2026-08-22

## Context

A system spanning Laravel, a Python AI service, Vue/Inertia, and
Flutter needs one consistent way to answer "what happened, where, and
how long did it take" across component boundaries — especially across
the Laravel <-> AI Gateway boundary (ADR 0013), which is the one real
network hop in the system today. This decision should be made before
production traffic exists, not retrofitted after an incident.

## Decision

School OS standardizes on the **OpenTelemetry (OTel)** data model and
wire protocol (traces, metrics, logs) as the observability contract
every component emits against, rather than committing to one specific
backend/vendor at this stage. The **request id** already implemented in
Phase 0A (`AssignRequestId` middleware in `apps/platform`, propagated as
`X-Request-Id`) is the correlation key that ties a request across
Laravel, its logs, and (once implemented) its AI Gateway calls and OTel
trace/span IDs.

No specific OTel backend (self-hosted, a SaaS APM vendor, a cloud
provider's native offering) is chosen in Phase 0A — that is an
infrastructure/deployment decision deferred per the stop gates in
`docs/roadmap/MASTER-ROADMAP.md`.

## Rationale

- OTel is vendor-neutral by design — committing to its data model
  (not a specific backend) avoids repeating the provider-lock-in
  mistake this product deliberately avoids elsewhere (ADR 0011, 0013)
  for observability tooling instead.
- Both the PHP and Python ecosystems have mature OTel SDKs/auto-
  instrumentation, so this doesn't create a stack-specific gap.
- Correlating a single request across Laravel and the AI Gateway (a
  real network + trust boundary, ADR 0013) is exactly the kind of
  cross-service tracing problem OTel is built for — and exactly where
  ad hoc logging would first break down as the AI Platform grows.
- Establishing the request-id convention now, in Phase 0A's tiny
  primitive (`AssignRequestId`, echoed in both the web and API error
  envelope), means every future log line and future OTel span has a
  natural correlation key from day one instead of a later retrofit.

## Alternatives considered

1. **Committing to a specific vendor APM SDK (proprietary, not OTel)
   now.** Rejected: locks instrumentation code to a vendor before any
   production requirement (cost, retention, region) has actually been
   evaluated; OTel keeps that choice open without extra cost today.
2. **No structured observability strategy yet; plain application
   logs only.** Rejected: cross-service correlation (Laravel <-> AI
   Gateway) and future multi-module tracing needs more than unstructured
   logs from the start, or the gap compounds as more modules and the
   AI Platform grow.
3. **Deploying a full observability backend (e.g. self-hosted Grafana/
   Tempo/Loki stack) as part of Phase 0A.** Rejected per the Phase 0A
   stop gates — no cloud resources or production infrastructure are
   provisioned in this checkpoint; the *contract* (OTel-compatible
   instrumentation) is established now, the backend is a later,
   deployment-time decision.

## Consequences

- Future instrumentation work (adding OTel SDKs to `apps/platform` and
  `services/ai`) should emit spans/metrics/logs in the OTel data model
  from the start, rather than a bespoke format that would need
  translation later.
- No observability backend exists yet — Phase 0A's only concrete,
  verifiable observability primitive is the request-id
  propagation/correlation convention; this is intentionally a small,
  honest scope for this checkpoint.
- Every future service (and any extracted module per ADR 0001) inherits
  this same OTel-compatible contract rather than choosing its own.

## Future extraction/evolution path

Choosing and standing up an actual OTel-compatible backend (self-hosted
or managed), plus adding SDK instrumentation to `apps/platform` and
`services/ai`, is explicitly out of scope for Phase 0A and belongs to a
future infrastructure phase — see `docs/roadmap/MASTER-ROADMAP.md`.

## Amendment (ADR 0051, Phase 0O.5, 2026-09-26)

Not superseded. The OpenTelemetry data model remains the compatibility
target and the request id remains the correlation key. For production v1,
ADR 0051 fixes: metrics as an OpenMetrics endpoint on a private port,
scraped by a deployment-provided collector (an OpenTelemetry Collector or
any Prometheus-compatible backend ingests it); logs as structured JSON on
stderr; **no distributed tracing** (spans are not exported; the W3C
`traceparent` correlation primitive stays). OTel SDKs are not required in
v1. Retention: logs 30 days, metrics 90 days. See
`docs/architecture/adr/0051-production-observability-alerting-contract.md`.
