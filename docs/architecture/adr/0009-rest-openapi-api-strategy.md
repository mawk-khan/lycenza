# ADR 0009: Versioned REST + OpenAPI as the External API Strategy

- Status: Accepted
- Date: 2026-08-22

## Context

Mobile (ADR 0007), third-party integrations, future school-group
systems, and a future public developer API all need one well-defined,
stable way to talk to the ERP core that is independent of Laravel
internals and independent of the Inertia web console's server-driven
model (ADR 0005).

## Decision

School OS exposes a **versioned REST API under `/api/v1`**, described
by an **OpenAPI 3.1 contract** at
`packages/contracts/openapi/school-os-api.yaml`, which is the source of
truth consumed by `packages/shared-types` to generate TypeScript
bindings. Full conventions (auth, pagination, filtering, idempotency,
rate limiting, error format, request IDs, webhooks, API audit) are
documented in `docs/architecture/API.md`; this ADR records the
technology and versioning decision.

## Rationale

- REST + OpenAPI is a well-understood, broadly tooled choice that every
  consumer type here (Flutter, third-party integrators, future public
  API consumers) can work with without needing a specialized client
  runtime.
- A single contract file as source of truth (rather than
  documentation written after the fact) lets us generate types (ADR
  0005's `shared-types` package) and catch drift in CI (the
  `shared-types` CI job fails the build if generated types are stale
  relative to the spec).
- Path-based versioning (`/api/v1`, future `/api/v2`) is simple to
  reason about for a small team and for third-party integrators, and
  is what Laravel's routing natively supports without extra tooling.

## Alternatives considered

1. **GraphQL.** Rejected for the primary external API: adds a query
   language, a resolver layer, and a different authorization model to
   reason about, for consumers (mobile, third-party integrators) that
   are well served by conventional REST resources. May be reconsidered
   later for a specific internal reporting/analytics need, but not as
   the default.
2. **gRPC.** Rejected: poor fit for third-party/public API consumers
   and browser-based clients (without a proxy layer), and no internal
   requirement yet justifies it over HTTP/JSON.
3. **Header-based or query-param API versioning instead of path-based.**
   Rejected: path-based versioning is simpler for third-party
   integrators to reason about, cache, and route, at the cost of some
   URL duplication across versions — an acceptable tradeoff.
4. **No formal contract file; document the API only in prose.**
   Rejected: without a machine-readable contract, `shared-types`
   generation and contract-drift detection in CI wouldn't be possible,
   and "prose docs vs. actual behavior" drift is a common, avoidable
   failure mode.

## Consequences

- Every new public/partner-facing endpoint must be added to the
  OpenAPI spec *before or alongside* its Laravel implementation, or CI's
  drift check (`shared-types` job) and code review should catch the gap.
- Backward-incompatible changes to `/api/v1` require a new version
  (`/api/v2`) or an additive, non-breaking change — not a silent
  breaking change to existing consumers (mobile clients in the field
  cannot be force-upgraded instantly).
- The Inertia web console is not required to route through this API
  (ADR 0005) but may for any feature that's easier to share with the
  documented contract than to special-case.

## Future extraction/evolution path

A future public developer API (third-party school-management
integrations, ed-tech partners) is additive on top of this same
contract and versioning discipline — it does not require a new API
strategy, only broader documentation, partner-facing API keys, and
stricter rate limiting for that consumer class (see
`docs/architecture/API.md`).
