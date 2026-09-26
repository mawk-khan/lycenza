# School OS — API Architecture

For the decision record, see ADR 0009. This document is the operational
reference for building against the `/api/v1` surface.

Contract source of truth:
`packages/contracts/openapi/school-os-api.yaml`. Phase 0A implemented
`GET /api/v1/system/status`; Phase 0B adds
`GET /api/v1/schools/{schoolId}/context`
(`apps/platform/app/Http/Controllers/Api/V1/SchoolContextController.php`),
proving the full mobile/API chain end to end: Sanctum identity →
membership → School context → capabilities → response (section 40).
Phase 0D adds the first real business-domain surface: School profile
(`/schools/{schoolId}`), Campuses, Rooms, Academic Years/Terms
(including the `activate`/`close` lifecycle actions), Grade Levels,
Sections, Subjects, Academic Departments, Subject Offerings, and the
platform-level `/education-boards` catalog — see
`docs/modules/ACADEMIC-STRUCTURE.md` for the domain design and the
OpenAPI spec for the full request/response shapes.

## Authentication

- Web console (Inertia): standard Laravel session auth — not part of
  the `/api/v1` surface's concern.
- Mobile, third-party integrations, future public developer API: Bearer
  token via **Laravel Sanctum** personal access tokens
  (`$user->createToken(...)`, `auth:sanctum` route middleware) —
  implemented in Phase 0B for the mobile-facing chain above. **ADR 0049
  (Phase 0O.2 contract, implemented by Phase 0O.3)** fixes the external
  credential model: human tokens always expire, carry closed scopes
  (`api.read`/`api.write`), are issued with fresh MFA and never carry
  platform or Group authority; **partner API clients** are separate,
  School-bound, non-human credentials on an explicitly registered
  `/api/v1/partner` surface (deny by default, no `{school}` parameter).
  **Built in Phase 0O.3:** tokens are issued at **Account > API tokens**
  (`/app/account/api-tokens`, fresh MFA code, shown once, 30 days by
  default, at most 90; format `<id>|lyc_pat_…`), carry `api.read` and/or
  `api.write` (method-based, no implication, 403 `API_SCOPE_INSUFFICIENT`
  otherwise), stop working when revoked, expired or the account is
  disabled (generic 401), and never exercise platform or Group
  capabilities (so `/api/internal/operations/status` refuses bearer
  tokens). Partner credentials (`lyc_pk_<key_id>.<secret>`) are managed
  under School **Integrations > API clients**; **no partner route is
  enabled in production** (the only one is a local/testing probe).
- Service-to-service (Laravel ↔ AI Gateway): a separate mechanism, not
  this bearer scheme — see ADR 0013, ADR 0016, ADR 0023 (now
  implemented: a shared service token for "is this the trusted
  gateway/platform" plus a short-lived signed context token for "which
  actor/School/capability" — `App\Support\Ai\AiContextTokenService`).

## Authorization

Every authenticated endpoint enforces capability-based authorization
(`docs/security/AUTHORIZATION.md`) server-side, scoped to the caller's
tenant (`docs/architecture/TENANCY.md`). No endpoint may rely on a
client to only request data it's "supposed to" see.

**Cross-tenant probing (implemented, section 38):**
`GET /api/v1/schools/{schoolId}/context` returns `404`, not `403` or a
membership-detail-bearing error, for a School the caller has no active
membership in — indistinguishable from a School that doesn't exist at
all. See `tests/Feature/Api/V1/SchoolContextTest.php`. This is the
concrete instance of the general rule: a protected resource a caller
cannot access resolves as "not found," not "forbidden," wherever
revealing existence would itself leak information.

## Versioning

Path-based: `/api/v1`, future `/api/v2`. A breaking change requires a
new version; additive, backward-compatible changes land in the current
version. Mobile clients in the field cannot be force-upgraded, so `/v1`
compatibility is a hard constraint once real consumers exist.

## Pagination

Reusable OpenAPI parameters `page` / `per_page` (see the spec's
`components.parameters`) — cursor-based pagination will be introduced
if/when a specific endpoint's data volume or consistency-under-mutation
requirements need it; offset-based (`page`) is the Phase 0A default
convention.

## Filtering

Not yet exercised by any real endpoint. Convention for future
endpoints: query-string filters scoped explicitly per resource
(documented in that resource's OpenAPI path), never a generic
"pass-through query language" that could be abused to bypass
authorization scoping.

## Idempotency

Required on unsafe (POST/PATCH/DELETE) requests that are not naturally
idempotent — most importantly payment-related endpoints (see
`docs/architecture/ARCHITECTURE.md` §10 and ADR 0018). Convention: an
`Idempotency-Key` request header (documented in the OpenAPI spec's
`components.parameters.IdempotencyKey`); the server must recognize a
repeated key and return the original result rather than repeating the
side effect.

**Implemented as of Phase 0C.2**: a reusable, opt-in `idempotent` route
middleware (`App\Http\Middleware\EnsureIdempotent`,
`App\Support\Idempotency\IdempotencyGuard`) that future endpoints
explicitly add to their middleware stack — it is never applied
globally, and not every POST needs it. See
`docs/architecture/RELIABILITY.md` ("API idempotency") for the full
design: storage model, School/actor/route-scoped uniqueness (backed by
a real PostgreSQL constraint, not an application-level check),
canonicalized request fingerprinting, replay/conflict/in-progress
semantics, the exact crash-window guarantee (and what it does NOT
guarantee), expiration/pruning, and the documented distinction from
payment-provider and webhook delivery idempotency (separate
mechanisms, not interchangeable with this one). The demonstration
endpoint proving this end to end
(`POST /schools/{schoolId}/idempotency-demo/increment`) is
local/testing-only, not a production route.

## Rate limiting

Implemented in Phase 0C.4 — see `docs/architecture/RELIABILITY.md`
("Rate limiting interaction") for the full named-limiter list, the
`throttle:*` middleware ordering caveat, and per-tenant keying.
Summary: `login` (6/min, IP-keyed), `public-api` (120/min, IP-keyed),
`school-api-mutations` (60/min, School+actor-keyed), `webhook-admin`
(20/min, School+actor-keyed), `internal-service` (300/min, service
identity-keyed), `internal-diagnostics` (12/min, user-keyed).
`/api/health/live` and `/api/health/ready` are deliberately exempt.

**Phase 0O.3 (ADR 0049 §7): every `/api/v1` route is throttled.** A route
without its own limiter gets `api-read` (120/min, safe methods) or
`api-mutation` (60/min), keyed by (School, user) or (School, partner
client) — never IP alone for authenticated traffic;
`guardian-candidates` uses `api-sensitive-read` (20/min); 20 failed
authentications per minute for one (IP, credential id) are refused with
429 before authentication (`api-auth-failure`). Responses carry
`X-RateLimit-Limit`/`X-RateLimit-Remaining`; a 429 carries `Retry-After`.
The Phase 0C.2 ordering and replay semantics are unchanged.

**CORS and browser headers (Phase 0O.3, ADR 0049 §10–11):** cross-origin
browser access only from the exact origins in `CORS_ALLOWED_ORIGINS`
(empty by default = none; never `*`, never credentials). API responses
carry `X-Content-Type-Options: nosniff` and
`Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox`.

## Health & internal diagnostics

`GET /api/health/live` and `GET /api/health/ready` — unauthenticated,
infrastructure-facing liveness/readiness probes; see
`docs/architecture/OBSERVABILITY.md`. `GET /api/internal/operations/status`
— authenticated (`platform.operations.view` capability), cross-tenant
operational diagnostics for every subsystem this project tracks
(database, Redis, storage, scheduler, queues, outbox, webhooks, AI
Gateway). None of these are versioned under `/api/v1` — they are
infrastructure/operations surfaces, not the public business API.

## Error format

A single consistent JSON envelope for the whole `/api/*` surface,
already implemented in Phase 0A (`apps/platform/bootstrap/app.php`'s
exception rendering):

```json
{
  "error": {
    "message": "string",
    "status": 422,
    "code": "IDEMPOTENCY_KEY_CONFLICT",
    "requestId": "uuid-or-null",
    "errors": { "field": ["validation message"] }
  }
}
```

`errors` is present only for validation failures (HTTP 422); it is
`null` otherwise. `code` (added Phase 0C.2) is a stable
machine-readable string present only for exceptions that define one —
`null` otherwise; every
`App\Support\Idempotency\Exceptions\IdempotencyException` subclass
defines one (`IDEMPOTENCY_KEY_REQUIRED`, `IDEMPOTENCY_KEY_INVALID`,
`IDEMPOTENCY_KEY_CONFLICT`, `IDEMPOTENCY_REQUEST_IN_PROGRESS`). This
extends the existing envelope; it is not a parallel error shape. See
`apps/platform/tests/Feature/Api/V1/SystemStatusTest.php` for a test
asserting this shape on a 404.

## Request IDs

Every request (web and API) receives an `X-Request-Id` — supplied by
the caller or generated — via `AssignRequestId` middleware
(`apps/platform/app/Http/Middleware/AssignRequestId.php`), echoed back
on the response and available to every downstream log/audit entry.
Every API request also receives a W3C-compatible `traceparent` header
(`App\Http\Middleware\AssignTraceContext`, Phase 0C.4) — see
`docs/architecture/OBSERVABILITY.md` for the full correlation/tracing
model (`request_id`/`correlation_id`/`causation_id`/`trace_id`/`span_id`)
and how a trace survives a Laravel → AI Gateway → Laravel round trip.

ADR 0051 (Phase 0O.5) bounds the inbound value: an `X-Request-Id` is
honoured only if it matches `^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$` (UUIDs
and ULIDs do); anything else is replaced by a server-generated UUID, which
is what the response echoes. Implemented in Phase 0O.5A; today any value
is accepted.

## Webhooks

See ADR 0018, ADR 0026, ADR 0027. **Outbound** webhook delivery
(School OS notifying a School's own registered endpoint) is
implemented as of Phase 0C.3 — see `docs/architecture/INTEGRATIONS.md`
for the full design and `docs/security/INTEGRATION-SECURITY.md` for
the signing/SSRF contract. The School-facing management API lives
under `/api/v1/schools/{schoolId}/webhook-endpoints` and
`/webhook-deliveries` (capability-gated: `integrations.webhooks.view`/
`.manage`; endpoint creation, secret rotation, and manual redelivery
are `idempotent`-middleware-protected). **Inbound** webhooks (an
external system, e.g. a payment gateway, notifying School OS) remain
not yet implemented — dedicated, per-provider-authenticated endpoints
outside the general `/api/v1` surface, with mandatory idempotent
handling, per ADR 0018; no such integration exists yet.

## API audit

Amended by ADR 0049 §15: API calls are **not** audited per request (that
was never built). Request ids, each module's own access audits (e.g.
Documents' Highly Sensitive reads) and credential `last_used_at` apply;
credential issuance, rotation, revocation and security-significant
authentication denials are audited (`auth.api_token.*`,
`integrations.api_client.*`), identifiers and codes only.

## OpenAPI generation

`packages/contracts/openapi/school-os-api.yaml` is hand-authored
(source of truth), not generated from Laravel route annotations — this
keeps the contract intentional rather than accidentally shaped by
whatever the implementation currently does.
`packages/shared-types` generates TypeScript bindings from it
(`npm run generate` in that package); CI
(`.github/workflows/ci.yml`'s `shared-types` job) fails the build if
generated types drift from the spec, so the contract and its generated
consumers cannot silently diverge.
