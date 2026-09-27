# ADR 0053: Service-to-Service Authentication & Rotation Contract

- Status: Accepted (contract only; implementation is Phase 0O.7A)
- Date: 2026-09-27 (Phase 0O.7)
- Resolves: **O5** (`docs/architecture/PHASE-0O-READINESS.md` §8)
- Amends:
  - ADR 0016: the "revisit the Laravel↔AI Gateway shared token" item.
  - ADR 0023: service authentication is split from the context token.
  - ADR 0050 §4–§5: the service-credential inventory and rotation status.
- Related:
  - ADR 0013/0014: the AI tool boundary.
  - ADR 0049: external API credentials, which are **not** used here.
  - ADR 0051: observability.
  - ADR 0052: supply chain.

## 1. Context — what exists today (audited 2026-09-27, `origin/main` `f28c6df`)

### 1.1 Call directions

Both directions exist. Neither is assumed from the network topology; both
were read from code.

| # | Caller | Receiver | Endpoints | Current auth | Current credential | Purpose |
|---|---|---|---|---|---|---|
| A | Laravel (`App\Support\Ai\AiGatewayClient`) | AI Gateway | `POST /v1/tools/invoke`, `POST /v1/complete` | `X-Service-Token: <value>` → `services/ai/app/core/security.py` `require_service_token` (constant-time compare) | `AI_GATEWAY_SERVICE_TOKEN` (Laravel) = `SERVICE_TOKEN` (Gateway) | Invoke an AI tool or a model completion on behalf of a Laravel-verified actor. **No production code path calls `AiGatewayClient` yet** (tests only; Phase 0M is blocked), but it is the sanctioned client and stays in scope. |
| B | AI Gateway (`app/tools/school_echo.py`, `app/audit/laravel_audit.py`, `app/gateway/completion_auth.py`) | Laravel `/api/internal/ai/*` | `POST /tools/school-echo` (`ai-service:ai.tools.invoke`), `POST /audit` (`ai-service:ai.audit.write`), `POST /completions/authorize` (`ai-service:ai.tools.invoke`) | `Authorization: Bearer <value>` → `VerifyAiGatewayServiceToken` → `ServiceIdentityAuthenticator` (SHA-256 of the value looked up in `service_identities.credential_hash`, enabled, then a `service_identity_capabilities` check) | the Gateway's same `SERVICE_TOKEN` | Relay a context token to an AI tool contract, verify a completion's context, write the durable AI audit record. |

Unauthenticated endpoints:
- the Gateway's `GET /health/live` and `/health/ready` (configuration only);
- Laravel's health endpoints.

Laravel calls the Gateway's health endpoints without a credential
(`OperationalStatusService`, `MetricsExporter`).

### 1.2 The shared credential today

- **One symmetric value, three places, both directions:**
  - Laravel `AI_GATEWAY_SERVICE_TOKEN` (sent in direction A);
  - the Gateway `SERVICE_TOKEN`, both the value it expects in A and the one it sends in B (`app/core/config.py`: "in both directions");
  - its SHA-256 in the `ai-gateway` row of `service_identities`, the Laravel receiver in B.
- **Who can impersonate whom:**
  - Laravel's environment holds the plaintext, so Laravel (or anyone with its environment) can impersonate the Gateway to Laravel.
  - The Gateway holds the same value, so it can impersonate Laravel to itself.
  - A compromise of either side compromises both directions.
- **Expiry and rotation:** there is no expiry. There is no rotation:
  `platform:service-identity-issue` refuses an existing slug, and the
  Gateway reads one value.
- **Production guards today:**
  - The Gateway refuses to start with a blank token, and refuses the public
    `dev-local-only-token` outside `local`/`testing`.
  - Laravel's `ProductionConfigurationGuard` refuses the development value;
    an empty value is allowed because the Gateway is optional.
- **Logs:** the value never appears in logs.
  - Laravel: `LogSanitizer` redacts `service_token` and `context_token`.
  - Gateway: its redaction list covers `authorization`, `service_token`,
    `context_token`, `Bearer …` and the development value.
  - Both sides refuse with codes only.
- **Service authorization today:**
  - Laravel's receiver maps the identity to two service scopes,
    `ai.tools.invoke` and `ai.audit.write`. They are stored as rows in the
    **human** `capabilities` catalog (namespace `platform`; no role holds
    them) and linked through `service_identity_capabilities`.
  - An authenticated identity that lacks the scope gets **401**, not 403.
  - The Gateway receiver has no per-route service authorization.

### 1.3 The AI context token (ADR 0023) — audited, unchanged

- **Signing:** `App\Support\Ai\AiContextTokenService`, HMAC-SHA256 with
  `AI_GATEWAY_CONTEXT_SIGNING_KEY`. Laravel mints and verifies; the Gateway
  only relays.
- **Payload:** `school_id`, `actor_id`, `capabilities`, `request_id`, `jti`,
  `iat`, `exp`, with a 60 s TTL. There is no `kid`, no audience and no
  purpose claim.
- **Minting:** only after `CapabilityResolver::canInSchool` and
  `SchoolOperationalGuard` pass.
- **Verification:** each internal controller verifies the signature and
  expiry, checks the capability claim, re-checks the School is active, and
  (for completions) re-checks the capability live. It sets `TenantContext`
  only from verified claims.
- **Security defect relevant to O5:** none found. Observations are recorded
  separately in §12.

### 1.4 Crypto available

- **PHP:** the repository-built runtime ships `sodium`
  (`php-modules.expected`), i.e. libsodium's `crypto_sign` (Ed25519).
- **Gateway:** its lock
  (`fastapi`, `starlette`, `pydantic`, `httpx`, `uvicorn`, …) has **no**
  crypto library, and CPython's standard library cannot sign or verify
  Ed25519.
- **Candidates checked for CPython 3.14 `manylinux` wheels (2026-09-27):**
  - `cryptography` 50.0.1 (abi3), which needs `cffi` 2.1.1 (cp314) and
    `pycparser` 3.0;
  - `pynacl` 1.6.2 (abi3), which also needs `cffi`.

## 2. Decision summary

Production v1 authenticates every Laravel↔Gateway request with a
**short-lived, per-request, request-bound, asymmetric signed service
assertion**:
- Ed25519, `alg` = `EdDSA`, in JWS compact serialization;
- sent in a dedicated `Authorization: Lycenza-Service …` scheme;
- one independent keypair **per calling service**;
- verified against a bounded, deployment-controlled public-key ring that
  supports staged rotation.

TLS and the private network stay mandatory transport controls; mTLS is not
the v1 identity mechanism. The shared bearer secret, `X-Service-Token`, and
the `service_identities.credential_hash` authentication path are removed by
Phase 0O.7A.

## 3. Identity, keys and custody

### 3.1 Closed service-identity catalog

| Service id | What it is | Calls | Is called by |
|---|---|---|---|
| `platform` | every Laravel process that makes internal AI calls (web, workers) | the AI Gateway (A) | the AI Gateway (B) |
| `ai-gateway` | the AI Gateway (`services/ai`) | Laravel `/api/internal/ai/*` (B) | Laravel (A) |

- Service ids are stable machine names, defined **in code on both sides**.
- The catalog is closed: there is no runtime issuance, no user-configurable
  identity, and no identity derived from a hostname, IP or DNS name.
- There is no per-replica identity and no School-specific identity.
- A third service needs an ADR amendment.
- The `platform:service-identity-issue` / `-disable` commands and
  `ServiceIdentitySeeder` are retired by 0O.7A. The platform audit history
  of past issuance stays; the table's final shape is decided in 0O.7A
  (§11).

### 3.2 One keypair per caller and direction

| Direction | Signing (private) key held by | Verification (public) ring held by | Audience |
|---|---|---|---|
| A platform → Gateway | Laravel processes only | the Gateway only | `lycenza-ai-gateway` |
| B Gateway → platform | the Gateway only | Laravel processes only | `lycenza-platform-internal-ai` |

- **No sharing across services or directions:** no private key is ever
  shared between services or between directions. A receiver's verification
  material cannot sign, so a compromised receiver cannot impersonate its
  caller.
- **No reuse of other credentials.** The service keys are new and never
  derived from, or equal to, any of these:
  - `APP_KEY` / `APP_PREVIOUS_KEYS`;
  - `AI_GATEWAY_CONTEXT_SIGNING_KEY`;
  - webhook secrets, partner or human API credentials;
  - `METRICS_SCRAPE_TOKEN`;
  - the lookup HMAC keys;
  - the development `dev-local-only-token`.
- **Separate from the AI context key (hard rule).** Service authentication
  ("which internal service sent this request") and context authorization
  ("which actor, School and capability this request may act for") are
  different security domains. The two keys are separate, stored separately,
  rotated separately and revoked separately. Compromise or rotation of
  either never implies the other, and the two are **never merged into one
  token** (§6).

### 3.3 Custody (ADR 0050 §4)

- **Private signing keys** are **Highly Sensitive** secrets from the
  external managed secret store, injected before the boot guard and
  `config:cache`. Each is given only to the processes of its own service:
  Laravel web and workers for `platform` (the scheduler and console roles
  only if they ever make internal AI calls); the Gateway for `ai-gateway`.
  A private key is never:
  - committed;
  - in an image, in Terraform state or outputs, or in frontend assets;
  - in the application database;
  - in logs, errors, readiness output, audit metadata or metrics.
- **Public verification rings** are deployment configuration (Internal
  security configuration). They are not secret, but they are
  integrity-controlled: they come from the same reviewed deployment
  configuration channel. They are never fetched at runtime, never taken
  from a request, and never exposed through any API.
- **Key generation** happens outside application request processing: an
  operator tool or the secret store. Nothing is generated at boot.
  0O.7A may add a console command that generates a keypair for local/test
  use and validates operator-supplied key material. It never stores a
  private key in the database.
- **No KMS or vendor is selected** here (ADR 0050 keeps that a deployment
  decision). A later KMS-backed signer must produce the same assertion
  format.

### 3.4 Key representation

The key format is standard and interoperable: **RFC 8037 OKP JWK**, in which
`x` and `d` are the base64url raw 32-byte public key and seed.

- **Private (caller):** one JWK,
  `{"kty":"OKP","crv":"Ed25519","kid":…,"x":…,"d":…}`.
- **Verification ring (receiver):** a JSON array of public JWKs
  `{"kty":"OKP","crv":"Ed25519","kid":…,"x":…,"created":"YYYY-MM-DD","not_after":"<RFC 3339 UTC, optional>"}`.

Other rules:
- No custom serialization.
- No PEM or PKCS#8 parsing is required: PHP libsodium works from the seed,
  and the Python library loads raw bytes.
- Any other `kty`, `crv`, or extra key-type member is rejected.

**Proposed configuration names** (0O.7A finalizes them against the existing
style: `AI_GATEWAY_*` / `services.ai_gateway.*` in Laravel, plain pydantic
names in the Gateway):

| Side | Concept | Proposed name |
|---|---|---|
| Laravel | its `platform` private signing JWK | `AI_GATEWAY_SERVICE_SIGNING_KEY` |
| Laravel | ring of `ai-gateway` public keys | `AI_GATEWAY_INBOUND_VERIFICATION_KEYS` |
| Gateway | its `ai-gateway` private signing JWK | `SERVICE_SIGNING_KEY` |
| Gateway | ring of `platform` public keys | `PLATFORM_VERIFICATION_KEYS` |

## 4. The service assertion

### 4.1 Format (frozen for v1)

The assertion is a JWS Compact Serialization,
`BASE64URL(header) "." BASE64URL(payload) "." BASE64URL(signature)`,
unpadded. The signature is Ed25519 over the ASCII
`BASE64URL(header) "." BASE64URL(payload)`, as RFC 7515 and RFC 8037
specify.

**Protected header**: exactly these three members and nothing else.

| Member | Value |
|---|---|
| `alg` | `EdDSA` — the only accepted value. The key ring admits only `crv` = `Ed25519` keys, so `EdDSA` here always means Ed25519. |
| `typ` | `lycenza-service+jwt` (explicit typing: an assertion cannot be confused with any other JWT-shaped value) |
| `kid` | the signing key id (§5.1) |

The verifier rejects:
- any other member, including `crit`, `jku`, `jwk`, `x5u`, `x5c`, `x5t`,
  `cty` and `b64`;
- `alg` = `none` or any other value;
- a duplicate member;
- non-canonical base64url;
- a header or payload that is not a JSON object;
- an assertion longer than **2048** bytes.

There is **no algorithm negotiation**: the verifier never reads `alg` to
choose an algorithm; it checks it against the constant.

JOSE's newer fully-specified `Ed25519` algorithm identifier (which
deprecates the polymorphic `EdDSA`) is a possible later change, and only
through an amendment to this ADR.

### 4.2 Claims (closed set)

The payload holds exactly the members below. An unknown member makes the
assertion **malformed**.

| Claim | Type | Rule |
|---|---|---|
| `ver` | integer | `1` |
| `iss` | string | a catalog service id (§3.1); must equal the service of the ring entry that holds `kid` |
| `sub` | string | equal to `iss` (the service acts for itself, never for a user) |
| `aud` | string | exactly the receiver's audience (§5.3); a single string, never an array |
| `iat` | integer (Unix s) | issued-at |
| `nbf` | integer | set by producers equal to `iat` |
| `exp` | integer | `exp − iat` ≤ **120** |
| `jti` | string | 16 random bytes, base64url (22 chars) |
| `htm` | string | the HTTP method, uppercase (`POST`, `GET`) |
| `htp` | string | the canonical request target (§4.4) |
| `bsh` | string | base64url(SHA-256(exact body bytes)) (§4.5) |
| `rid` | string, optional | the caller's request/correlation id (UUID or the existing bounded request-id shape), for correlation only (rule 62) |

**Forbidden in the assertion**, whether as a claim or in any other form:
- School id, User or actor id;
- membership, capabilities, roles or elevation;
- the context token;
- prompt, body content or output;
- any secret.

### 4.3 Lifetime and clock

| Value | Frozen |
|---|---|
| Maximum lifetime (`exp − iat`) | **120 s**; a longer lifetime is rejected even when the assertion is otherwise valid |
| Producer default | **60 s** |
| Clock-skew allowance | **30 s** |
| Accept when | `iat − 30 ≤ now`, `nbf − 30 ≤ now`, `now < exp + 30`, and `iat ≤ nbf < exp` |

- Assertions are created **per request**, immediately before sending, and
  never cached or reused. A retry gets a new assertion.
- Production hosts rely on deployment-provided accurate time. Clock-health
  monitoring is an operations duty. The lifetime is **never** raised to hide
  a bad clock; a skew beyond 30 s fails closed.

### 4.4 Canonical request target (`htp`)

Internal endpoints are designed so that canonicalization has nothing to
decide:
- `htp` is the request's **absolute path exactly as the receiving
  application sees it**: for example `/v1/complete` at the Gateway, and
  `/api/internal/ai/audit` at Laravel.
- It must match `^/[A-Za-z0-9._/-]{0,255}$`.
- It must not have a trailing slash, an empty segment (`//`), a `.` or `..`
  segment, or any percent-encoding.
- **A query string is not allowed** on a service-authenticated request. A
  request with `?` is rejected.

The receiver compares `htm` and `htp` byte-for-byte with the method and path
it received, without decoding or normalizing either.

A deployment proxy that rewrites internal paths therefore fails closed.
Internal routes must be reached without path rewriting.

### 4.5 Body digest (`bsh`)

`bsh` is SHA-256 over the **exact transmitted body bytes**:
- The caller serializes the body once, signs the digest of those bytes, and
  sends those bytes unchanged. Laravel sends a raw body, not a re-encoded
  array; httpx sends `content=`.
- An empty or absent body uses SHA-256 of the empty byte string.
- Internal requests are sent with identity content-encoding; a compressed
  internal body is rejected.

The receiver hashes the raw received bytes **before** any JSON parsing, and
compares them to `bsh` in constant time. It never canonicalizes JSON and
signs different bytes. Existing body-size limits still apply.

### 4.6 Transport header

`Authorization: Lycenza-Service <compact JWS>`

- The auth-scheme is compared case-insensitively (RFC 9110); there is
  exactly one space, then the token68 value.
- This scheme is **only** accepted on the service-route catalog (§6.1), and
  those routes accept **nothing else**. They reject:
  - `Bearer` in any form: Sanctum personal tokens, partner credentials, or
    the old shared token;
  - `X-Service-Token`;
  - browser sessions;
  - any "internal" header or IP-based identity.
- **Compatibility**, checked for this ADR:
  - nginx passes `Authorization` to PHP-FPM through `fastcgi_params` (the
    metrics bearer check already relies on this);
  - uvicorn and Starlette expose the raw header;
  - Laravel's `bearerToken()` returns null for this scheme, so Sanctum and
    `auth:partner` never interpret it;
  - `ThrottleFailedApiAuthentication` applies only to `api/v1/*`.

## 5. Verification

### 5.1 Key id (`kid`)

- `kid` is not secret, but it is security metadata.
- It matches `^[a-z0-9][a-z0-9._-]{0,63}$`. The recommended form is
  `<service>-<yyyymmdd>-<n>`, for example `platform-20261001-1`.
- It is unique across all verification rings a receiver holds.
- An unknown `kid` is a generic authentication failure (§7).
- Keys are **never** fetched from a URL, from `kid`, from `jku`/`x5u`, or
  from any remote JWKS. Only the ring in deployment configuration counts.
- `kid` may appear in sanitized logs; it is never a metric label.

### 5.2 Verification ring (receiver)

- A ring holds **1–2** keys, all of the one calling service the receiver
  accepts.
- **Exactly one steady key** has no `not_after`.
- **At most one transitional key** (the "next" key before cutover, or the
  "previous" key after it) must carry `not_after`, and at load time
  `not_after` must be ≤ **now + 24 h**.
- After its `not_after`, a transitional key **stops verifying** even if the
  configuration was never updated. An old key can therefore never remain
  trusted indefinitely because it is listed as "previous".
- Every key carries `created`. A key older than **90 days** is refused for
  verification (§8.4).

### 5.3 Audiences

Each receiver accepts exactly one audience:
- `lycenza-ai-gateway` at the Gateway;
- `lycenza-platform-internal-ai` at Laravel's `/api/internal/ai/*`.

There is no generic `lycenza` audience. An assertion minted for one
receiver is rejected by the other, and the separate rings reject it too.

### 5.4 Order of checks (both receivers)

1. **Transport.** The request arrived over the approved private, TLS
   network. This is a deployment control, not an application check.
2. **Parse** the `Lycenza-Service` header: length, three base64url segments,
   and the header exactly as §4.1.
3. **Ring lookup and signature.** Look up `kid` in the ring (it must exist,
   and a transitional key must not be past `not_after` or 90 days old).
   Verify the Ed25519 signature with the audited primitive (§9).
4. **Claims:**
   - the closed claim set;
   - `ver`;
   - `iss` = `sub` = the ring's service, which is in the catalog;
   - `aud` = this receiver;
   - the time rules of §4.3.
5. **Request binding:** `htm` equals the method; `htp` equals the raw path,
   with no query; `bsh` equals the SHA-256 of the raw body.
6. **Replay:**
   - Laravel only: one-time `jti` consumption (§5.5).
   - Gateway: the best-effort local check.
7. **Service authorization.** The service's closed route scope must include
   this route (§6.2); otherwise 403.
8. **AI context token.** Only now is any School, actor or capability
   information parsed, through the existing ADR 0023 path:
   - Laravel: `AiContextTokenService::verify`;
   - Gateway: relay to Laravel.
9. **Operation authorization:**
   - Gateway: the agent and provider checks;
   - Laravel: capability-in-claim, active School, and the live capability
     re-check.
10. **Execute.** The Gateway's `NullProvider` or current behaviour; Laravel's
    domain handler.

Nothing from the request body or headers is trusted as School, User or
context before step 8.

### 5.5 Replay model (stated honestly)

A valid assertion is replayable **for the identical bound request** (same
method, path and body bytes, same receiver) until `exp + 30 s`, at most
150 s after `iat`, by anyone who captured it. Primary controls:
- the private network and TLS;
- assertions never logged;
- request binding;
- the 120 s hard lifetime;
- one assertion per request.

- **Laravel receiver (direction B): one-time `jti` consumption is
  required.**
  - Laravel already depends on Redis in production (readiness-critical,
    password-protected), and it is shared across replicas.
  - After steps 2–5 succeed, the receiver atomically adds
    `service-assertion:{iss}:{jti}` to the default cache store (`add`,
    i.e. Redis `SET NX EX`), with a TTL ≥ 150 s.
  - If the key already exists, that is `replayed` (401).
  - If the store fails, the internal request fails **closed**. That affects
    only internal AI routes (an optional subsystem); ERP readiness is
    unchanged.
  - This is replay protection, not idempotency (rules 30–33 are unaffected).
  - Tests use the array store.
- **Gateway receiver (direction A): no shared replay store exists.**
  - The Gateway has no Redis and no database (rule 8; ADR 0050).
  - Giving it a Redis credential only to consume `jti` would add a new
    secret and a new dependency to a lower-trust service. That is
    **rejected** as unjustified.
  - The Gateway keeps a **best-effort, per-process, bounded, in-memory
    seen-`jti` set** (entries expire at `exp + 30 s`). It stops a replay to
    the same process only; it does **not** stop a replay to another replica
    or after a restart.
  - **Residual v1 risk, accepted:** a same-request replay to direction A
    within ≤150 s re-runs the identical tool or completion call.
    - Its effects stay bounded by the replayed request's own context token
      (60 s TTL, re-verified by Laravel, capability re-checked live).
    - Its only additional effect is a duplicate AI audit record in Laravel.
      That record is written with a fresh direction-B assertion, so it is
      authentic, not forged.
    - `NullProvider` has no external cost.
  - The replay risk is reconsidered before Phase 0M activates a real
    provider (§13).

### 5.6 Failure semantics

- **One external response for every authentication failure:** **401**, body
  `{"error":{"code":"service_authentication_failed"}}`, with no
  `WWW-Authenticate` detail, no signature or claim detail, and no token
  echo. The receiver is never a key or claim oracle.
- **403**, code `service_not_authorized`: the assertion is fully valid, but
  the service is not authorized for that route (§6.2). Today the only such
  case is a catalog service presenting to a route outside its scope.
  0O.7A changes Laravel's current 401 to 403 for this case.
- **404** is never used as a disguise. Internal routes are not public
  (§10), and disclosure is not the control.
- **Internal reason codes** (closed, logs and metrics only):
  - `missing`, `malformed`;
  - `unknown_kid`, `key_expired`, `bad_signature`;
  - `wrong_audience`, `unknown_service`;
  - `expired`, `not_yet_valid`, `lifetime_exceeded`;
  - `request_mismatch`, `body_digest_mismatch`;
  - `replayed`, `replay_store_unavailable`;
  - `forbidden_route`.
- **Failure isolation:** a failure is always closed. There is **no
  fallback**:
  - to no authentication;
  - to the shared or development token;
  - to IP allowlisting alone;
  - to a User or API bearer credential.

  A broken service key disables AI calls only. Normal School ERP behaviour
  continues (rule 56).

## 6. Authorization boundaries

### 6.1 Internal service-route catalog (closed)

| Receiver | Route | Service scope | Allowed caller |
|---|---|---|---|
| Gateway | `POST /v1/tools/invoke` | `gateway.tools.invoke` | `platform` |
| Gateway | `POST /v1/complete` | `gateway.complete` | `platform` |
| Laravel | `POST /api/internal/ai/tools/school-echo` | `ai.tools.invoke` | `ai-gateway` |
| Laravel | `POST /api/internal/ai/completions/authorize` | `ai.tools.invoke` | `ai-gateway` |
| Laravel | `POST /api/internal/ai/audit` | `ai.audit.write` | `ai-gateway` |

- **New routes:** every new internal service route must name its scope in
  this catalog, through an amendment. Routes stay outside `/api/v1`: the
  existing `internal/ai` prefix is kept, and a guard test forbids a service
  route under `/api/v1`.
- **Health:** health endpoints need no service assertion (§10.2).
- **Other internal routes:** `internal/operations/status` and the internal
  health endpoints are human-authenticated or unauthenticated diagnostics
  (ADR 0051). They are **not** service routes, and they never accept a
  `Lycenza-Service` assertion.

### 6.2 Service authorization (separate from authentication)

- **A closed code mapping**, service id → set of route scopes:
  - `platform` → {`gateway.tools.invoke`, `gateway.complete`};
  - `ai-gateway` → {`ai.tools.invoke`, `ai.audit.write`}.
- **No wildcard.** Nothing may be `*`.
- **Separate from human authorization.** It never uses
  `CapabilityResolver`, School membership, platform role grants, Group
  authority or elevation. It never creates or uses a `User`.
- **Leaving the human catalog:** 0O.7A moves the two Laravel service scopes
  out of the human `capabilities` catalog, so no human role could ever be
  granted a service scope.
- **Rate limiting:** the `internal-service` limiter keys by the
  authenticated **service id** (a bounded value), not by a database row.

### 6.3 A service assertion is not tenant context

A valid assertion proves only that this request came from an authorized
internal service. It proves nothing about any of these:
- which School is active;
- which User initiated the operation, or what capabilities that User holds;
- whether elevation exists (never on internal routes: rule 83);
- whether the AI operation is authorized.

Consequences:
- **No School from the service layer.** Service authentication never sets
  `TenantContext` from a service id, header, query or claim.
- **The context token is the only source of School context.** School
  context comes only from the verified ADR 0023 context token (step 8).
  Changing an assertion therefore cannot select another School: School and
  actor are not in it, and the context token is signed with a different key
  that the Gateway never holds.
- **Two separate tokens, both required.** The assertion (service
  authentication, Ed25519, per request, audience-bound) and the context
  token (actor, School and capability, HMAC, Laravel-only key) stay two
  artifacts in two fields, and **both** are required.
- **The context token is not redesigned.** 0O.7A makes no change to it
  beyond what this separation needs (§12).

### 6.4 NullProvider

O5 changes nothing about Phase 0M. The Gateway stays on `NullProvider` in
the repository's production configuration. A valid service identity never
enables an external model provider: `REAL_PROVIDERS_ENABLED` and the legal
gate are unaffected.

## 7. Production configuration requirements (0O.7A implements them)

A deployment enables the Gateway integration explicitly. 0O.7A makes the
production default "not configured", so an unused Gateway needs no keys.
Once enabled, **Laravel refuses to boot or make internal calls**, and the
**Gateway refuses to start and reports not-ready**, with codes only, when:
- the private signing key for its caller role is missing, malformed, not
  `OKP`/`Ed25519`, or has no `kid`;
- the verification ring for its receiver role is empty, malformed, holds a
  duplicate `kid`, or holds a key of the wrong type;
- the ring has more than 2 keys, not exactly one steady key, or a
  transitional key with `not_after` more than 24 h ahead;
- a configured algorithm, lifetime or skew is not the frozen value.
  Lifetime, skew, overlap and algorithm are **constants in code, not
  configuration**. Any attempt to configure them is refused.
- the committed **development/test keys** (§11) are configured outside
  `local`/`testing`, detected by their fixed `kid` prefix `dev-local-only-`
  **and** by their public-key fingerprints;
- a shared-token variable (`AI_GATEWAY_SERVICE_TOKEN` / `SERVICE_TOKEN`) is
  still set. This fails closed, so a leftover token can never become a
  fallback;
- the peer URL is not `https://` in production: `AI_GATEWAY_BASE_URL` in
  Laravel and `ERP_CONTRACT_BASE_URL` in the Gateway. DDEV and local
  Compose keep HTTP.

## 8. Rotation and revocation

### 8.1 Routine rotation (staged, zero-downtime)

1. **Generate** the new caller keypair outside application runtime, through
   the operator tool or the secret store. Record the `kid` and `created`
   date.
2. **Stage the verifier.** Add the new public key to the receiver ring as a
   transitional entry (`not_after` ≤ 24 h), and deploy it to **every
   receiver replica**. Confirm every replica loaded the ring (§8.6).
3. **Install the signer.** Install the new private key for the caller.
4. **Switch.** Switch the caller's active signing key, and deploy it to
   every caller replica.
5. **Observe** successful traffic on the new key: auth-success metrics and
   logs with the new `kid`, and zero `unknown_kid`/`bad_signature`.
6. **Retire the old verifier.** Promote the new key to the steady entry.
   The old key becomes transitional with a short `not_after`, just long
   enough for in-flight assertions (≥ 150 s), or is removed.
7. **Destroy** the old private key in the secret store, and remove the old
   key from the ring.
8. **Record** the evidence: date, service, old and new `kid`, operator,
   and the metrics observed. Record `kid`s only, never key material, in the
   deployment's security evidence (§8.7).

### 8.2 Overlap

- The key-overlap window is at most **24 h**, enforced by `not_after`.
- Normal rotation should take minutes.
- The overlap window is **not** the assertion lifetime:
  - assertions live ≤ 120 s;
  - the overlap exists only so replicas can converge and in-flight
    assertions can finish.
- Once an old public key is removed, its assertions fail at once, even if
  their `exp` has not passed.

### 8.3 Emergency revocation (compromise)

1. **Remove the compromised key** from the receiver ring on every replica
   immediately. There is no 24 h overlap and no wait.
2. **Replace the caller key.** Install a new caller key: the new public key
   in the ring, the new private key at the caller. Restart or reload the
   affected processes safely.
3. **Verify** that the old `kid` is refused: `unknown_kid` in the receiver's
   logs, from a deliberate test assertion in a non-production environment,
   or from observed traffic.
4. **Inspect** the bounded telemetry for use of the compromised `kid`
   (logs carry `kid`) during the exposure window.
5. **Scope the compromise by host.** A compromised Gateway host held only
   the `ai-gateway` private key and the public `platform` ring. A
   compromised Laravel host also held the `platform` private key **and** the
   separate AI context signing key: rotate both, the context key through its
   own procedure (ADR 0050 §5).

No token-revocation database is needed. Removing the key revokes every
assertion it signed. **There is no rollback to a compromised key**, ever.

### 8.4 Cadence

- Each service signing key has a maximum life of **90 days** from its
  `created` date. If a security or deployment standard later requires
  shorter, the shorter period wins.
- The 90-day limit is enforced:
  - receivers refuse a key older than 90 days;
  - there is a warning alert from day 76 (§9.2).

  A missed rotation disables AI calls only, never the ERP.
- Emergency rotation can happen at any time.
- This cadence is unrelated to the per-assertion expiry.

### 8.5 Rotation rollback

- **Rolling back:** if step 4 produces authentication failures, switch the
  callers back to the still-valid old signer. The receiver still trusts
  both keys, so this is a caller-only redeploy.
- **Retiring:** retire the old key only after new-key success is observed.
- **Compromise:** emergency revocation is different; there is no rollback
  to a compromised key.

### 8.6 Multi-replica and concurrency

- **One identity per service.** Every replica of a service uses the same
  service id and, after convergence, the same active signing `kid`.
- **Rings before signers.** Every receiver replica has the full ring
  **before** any caller replica switches signer (step 2 precedes step 4).
  Therefore no moment exists with zero accepted keys.
- **In-flight requests.** A request signed just before cutover still
  verifies while its key is steady or transitional. After removal it fails
  closed, and the caller's retry signs anew.
- **Runtime reload.** Rings and keys are read from configuration at process
  start (Laravel `config:cache`; Gateway settings). Changing either is a
  deployment restart; there is no runtime key mutation. 0O.7A tests the
  "both keys accepted, then old removed" sequence and concurrent signing
  across a cutover.

### 8.7 Audit trail

- Per-request authentication outcomes go to logs and metrics, **not** to
  School audit events; a success writes no audit row.
- Key generation, staging, rotation and emergency revocation are
  operator/deployment security evidence (Confidential):
  - the runbook record of §8.1 step 8 and §8.3;
  - the secret store's own access history.

  They are not application audit rows, because keys never enter the
  application database.
- No audit metadata ever contains an assertion, key material or a context
  token.

## 9. Logging, metrics and data classification

### 9.1 Logs

Allowed stable event codes:
- `service_auth.succeeded`;
- `service_auth.failed`;
- `service_auth.config_invalid`.

Allowed fields: `service`, `direction`, `kid`, `outcome`/`reason` (closed
code), `route` (the catalog route name), and `request_id`/`trace_id` as
today.

**Never logged:**
- the assertion or any segment of it;
- the signature;
- private or public key material;
- `bsh`;
- `jti` (not needed for diagnosis; the replay store holds it transiently);
- the context token;
- the body.

Success is logged at debug level or sampled, at the implementer's choice;
failures are always logged. The existing sanitizers stay the backstop, and
0O.7A adds the `Lycenza-Service` pattern to them. The Gateway's existing
`authorization` key redaction already covers the header field.

### 9.2 Metrics (bounded; ADR 0051 rules)

Laravel metrics, computed at scrape time:

| Metric | Labels (closed catalogs) |
|---|---|
| `lycenza_service_auth_total` (counter) | `direction` (`gateway_to_platform`, `platform_to_gateway`), `service` (catalog id), `outcome` (`success` or a §5.6 reason) |
| `lycenza_service_signing_key_age_days` (gauge) | `service` (Laravel's own `platform` signing key) |
| `lycenza_service_verification_key_max_age_days` (gauge) | `service` (the `ai-gateway` ring) |

- **Direction A:** Laravel records the outcome it observes as the caller:
  `success`, or `rejected_by_receiver` for a Gateway 401/403.
- **The Gateway** has no metrics endpoint (ADR 0051). It emits the same
  closed-code log events, and log-based counting is a deployment option.
- **Never used as labels:** `kid`, School, User, request id, `jti` and the
  route path.
- **Alerts** (0O.7A adds them to the ADR 0051 catalog; SEV-3 at most,
  because the Gateway is optional):
  - sustained `outcome != success`;
  - `unknown_kid`/`bad_signature` above zero;
  - key age ≥ 76 days.

### 9.3 Classification (`DATA-CLASSIFICATION.md`)

| Item | Class |
|---|---|
| Service private signing key (JWK `d`) | **Highly Sensitive** (authentication secret) |
| Signed service assertion | **Highly Sensitive**: an ephemeral bearer credential while valid, and never ordinary request metadata |
| Public verification key / ring | Internal (security configuration, integrity-controlled) |
| `kid`, service id, audience | Internal security metadata |
| Rotation and revocation records | Confidential (operational security evidence) |

## 10. Network, TLS and health

### 10.1 Private network and TLS (ADR 0050 stays authoritative)

- **The Gateway is never public.** Service assertions are **not** a reason
  to expose the Gateway, or `/api/internal/ai/*`, to the internet. Both
  controls, private network and assertion, are required.
- **TLS on every internal hop.** Production service traffic uses TLS in
  both directions, even on the private network:
  - no `http://` Gateway or Laravel internal URL in production (§7);
  - the Gateway image serves plain HTTP on 8100, so TLS in front of it
    (a sidecar, a platform listener, or uvicorn TLS) is deployment
    evidence.
- **Laravel's internal routes over TLS.** The Gateway reaches
  `/api/internal/ai/*` through a **private TLS listener**, never the public
  load balancer, and without path rewriting (§4.4).
- **Local development.** DDEV and local Compose keep plain HTTP, as today.

### 10.2 Health endpoints

- **No assertion needed.** Gateway `/health/live` and `/health/ready`, and
  Laravel's health endpoints, need **no** service assertion. They reveal
  status only, and probes and orchestrators must reach them without
  credentials.
- **Private network only.** They are reachable only on the private network
  (the Gateway entirely; Laravel per ADR 0050/0051). Omitting
  authentication is not exposure.
- **Readiness.** Gateway readiness reports 503 when its service-auth
  configuration is invalid (§7), with no reason in the body.

### 10.3 mTLS

- **Not required in v1**, and certificate issuance and rotation are **not**
  in O5's repository scope.
- **Possible later.** A deployment may later require mTLS **in addition to**
  the signed assertion, as defense in depth, without changing this
  application identity contract. The two are independent layers.

## 11. Development token disposition and migration (0O.7A)

End state: no shared-token code path exists anywhere.

- **Removed:**
  - `X-Service-Token`;
  - `require_service_token`;
  - `VerifyAiGatewayServiceToken`'s bearer lookup;
  - `ServiceIdentityAuthenticator`;
  - the issue/disable commands;
  - `ServiceIdentitySeeder`;
  - `AI_GATEWAY_SERVICE_TOKEN` and `SERVICE_TOKEN`.
- **`service_identities` / `service_identity_capabilities`:**
  - they stop being an authentication path;
  - 0O.7A decides between retaining them read-only for history and a
    reversible removal migration (rule 10);
  - `platform_audit_events` history is untouched either way.
- **Local convenience is kept:**
  - DDEV, local Compose and `.env.example` get committed
    **development-only Ed25519 keypairs**, one per service, with `kid`
    `dev-local-only-platform-1` and `dev-local-only-ai-gateway-1`, exactly
    as the development token is committed today;
  - both production guards reject them by `kid` prefix and public-key
    fingerprint;
  - the source secret-scan allowlist gets **exact** entries (one path plus
    one value, rule 87);
  - neither key can enter a production image, because both `.dockerignore`
    files already exclude `.env*`;
  - tests generate **ephemeral keypairs at runtime** and commit no
    production-capable private key.

Migration inside 0O.7A (no deployed system exists, so there is no live
compatibility period and no unsafe flag day in the suite):
1. Add assertion signing and verification. The old token remains only as a
   local/test compatibility path.
2. Switch the client and server tests and the production-mode tests to
   assertions.
3. The production guards reject shared-token configuration.
4. Remove the shared-token path, configuration and seeder entirely.
   `verify-images.sh` proves the images refuse an unsigned or
   shared-token call.

## 12. Context-token observations (reported separately; not redesigned)

1. The context signing key has **no `kid` or rotation ring**. Its managed
   custody and rotation remain ADR 0050 §5's future item, independent of
   O5.
2. The context token has **no purpose or audience claim**. One token is
   accepted by school-echo, audit and completion-authorize, and scoping
   comes from its capability claim. The audit reuse is by design (ADR 0023,
   AI-SECURITY.md).
3. `verify()` checks `exp` but not `iat` sanity or a maximum lifetime. Only
   Laravel signs, so this is not exploitable without the key.
4. Unrelated robustness issue: the Gateway's `/v1/tools/invoke` raises an
   unhandled `KeyError` (500) for an unknown agent or tool, after service
   authentication. `/v1/complete` returns 403 `agent_not_allowed`. This is
   a small fix candidate for 0O.7A, or separately; it is not a security
   defect.

## 13. Residual risks (v1)

- **Same-request replay to the Gateway** within ≤150 s (§5.5), bounded by
  the context token and the private TLS network. Revisit before Phase 0M
  activates a real, costly or data-bearing provider.
- **Holder of the `platform` private key.** Anyone with the `platform`
  private key can call the Gateway as `platform`. A caller of Laravel's
  routes still needs a valid context token, which needs the Laravel-only
  HMAC key.
- **Clock skew beyond 30 s** fails closed (availability, not security).
- **The 90-day hard stop** disables AI calls if rotation is missed. This is
  deliberate: it fails closed and is alerted from day 76.

## 14. Library boundary and supply chain

- **No hand-written cryptography.** Application code only base64url-encodes
  the JWS signing input and calls an audited Ed25519 primitive:
  - PHP: libsodium through the existing `sodium` extension
    (`sodium_crypto_sign_seed_keypair`, `sodium_crypto_sign_detached`,
    `sodium_crypto_sign_verify_detached`);
  - Python: one audited library, **preferred `cryptography` (PyCA)**, with
    `pynacl` as the alternative. 0O.7A makes the final choice by the
    smallest audited path.
- **No general JWT framework.** Header and claim validation is the strict,
  closed, fixed-shape logic of §4–§5.
- **The new Gateway dependency passes O16 in full**
  (`cryptography` or `pynacl`, plus `cffi` and `pycparser`):
  - hash-locked with pip-compile;
  - `pip-audit`;
  - SBOM and Grype;
  - full artifact qualification of both images.

  Being a security library earns no policy waiver.
- **Exception expiry dates.** The current VERIFIED artifacts rest on
  `OWNER-0O6E-2026-09-26` records that expire **2026-10-10** (util-linux
  CVE-2026-76642/78409/78410) and **2026-10-26** (the rest). O5 contract
  work is unaffected. Any qualification after those dates needs fixed
  packages or a fresh owner/security decision. **O5 never renews them.**

## 15. Definition of done

**Repository (Phase 0O.7A):**
1. Both call directions are cataloged in code (§3.1, §6.1).
2. Shared bearer and `X-Service-Token` auth are removed, and production
   rejects them.
3. Short-lived Ed25519 assertions are generated per request.
4. Assertions are request-bound (`htm`, `htp`, `bsh`).
5. The service assertion and the AI context token stay separate; tests
   prove that changing an assertion cannot change School or actor.
6. Exact audiences and the closed route authorization (401 vs 403) are
   enforced.
7. The private/public key custody boundary holds: no private key in the
   database or image, and the separate context key is untouched.
8. Rings of steady plus transitional keys support staged rotation, with
   `not_after` and 90-day enforcement.
9. Emergency revocation works: removing a key refuses its assertions at
   once.
10. Production guards reject every unsafe or fallback configuration of §7.
11. Logs and metrics are sanitized and bounded (§9).
12. Tests pass for rotation, concurrency and replay: the Laravel `jti`
    store, the Gateway best-effort check, and a cutover with in-flight
    assertions.
13. Both production images requalify under O16, including the new Python
    crypto dependency.

**Deployment evidence (deploy-gated, rule 16):**
- real service keys installed through managed secret custody;
- every replica shown to hold the correct ring;
- one routine rotation performed successfully in a non-production
  environment;
- the emergency revocation procedure demonstrated;
- the real private TLS network in place (including TLS in front of the
  Gateway).

## 16. Alternatives considered

1. **Keep the shared token and add rotation.** Rejected, because the
   symmetric secret lives in three places and one compromise
   impersonates both directions.
2. **HMAC per direction** (two symmetric keys). Rejected, because the
   receiver can still mint the caller's credentials, so there is no
   compromise isolation.
3. **mTLS as the application identity.** Rejected for v1. It
   authenticates connections, not requests, and brings PKI issuance and
   rotation into scope. It remains optional defense in depth.
4. **One combined token carrying service identity and School/actor
   context.** Rejected as a hard boundary (§6.3). It would let the
   service key's custody and rotation decide tenant authority, and it
   would put the Gateway into the context-signing domain.
5. **A remote JWKS endpoint or `jku`.** Rejected, because runtime key
   fetch widens the trust boundary to whoever serves the keys.
6. **Redis for the Gateway's `jti`.** Rejected as an unjustified new
   secret and dependency for a lower-trust service (§5.5).
7. **A general JWT library.** Rejected, because the fixed-shape strict
   validation is smaller and avoids algorithm-confusion surfaces.

## 17. Consequences

- A compromised Gateway can no longer authenticate *to itself* as Laravel,
  and a compromised verifier can never sign as its caller. Each key
  compromise is confined to one direction.
- O5 is **resolved as a contract**. Implementation is Phase 0O.7A.
- O1 remains open. Phase 0O cannot close while internal authentication
  depends on an unrotated shared secret, a development token, an unbounded
  bearer credential, or an undocumented private-network assumption. After
  0O.7A and its deployment evidence, none of these remains.
- Still open: **O1, O2, O9, O13, O14, O15**.

## Amendment — Phase 0O.7A implementation (2026-09-27)

The repository portion of O5 is **implemented**. Deployment evidence
(§15) is still outstanding. Decisions made during implementation, each
within this contract:

1. **Route scopes.** The route catalog gives
   `POST /api/internal/ai/completions/authorize` its own scope,
   **`ai.completions.authorize`**, instead of reusing `ai.tools.invoke`
   (§6.1 table amended). The final closed map:

   | Receiver | Route | Scope | Caller |
   |---|---|---|---|
   | Gateway | `POST /v1/tools/invoke` | `gateway.tools.invoke` | `platform` |
   | Gateway | `POST /v1/complete` | `gateway.complete` | `platform` |
   | Laravel | `api.internal.ai.tools.school-echo` | `ai.tools.invoke` | `ai-gateway` |
   | Laravel | `api.internal.ai.completions.authorize` | `ai.completions.authorize` | `ai-gateway` |
   | Laravel | `api.internal.ai.audit.store` | `ai.audit.write` | `ai-gateway` |

   **Code:**
   - Laravel: `App\Support\ServiceAuth\ServiceAuthContract::ROUTE_SCOPES`
     and `SERVICE_SCOPES`, keyed by route name, with no prefix inference;
   - Gateway: `app/core/service_auth.py`.

   A route with `service-auth` that is missing from the catalog is a 403.
   `ServiceAuthArchitectureTest` proves that the catalog equals the real
   routes and that both implementations share every constant.

2. **Keys.**
   - **Format:** the private JWK also carries `created`
     (`{kty,crv,kid,x,d,created}`), so a signer can enforce its own 90-day
     life.
   - **Scope of the age rule:** it is enforced outside `local`/`testing`,
     where the committed development keys are refused anyway.
   - **Injection:** keys are injected as single-line JSON in environment
     variables, consistent with every other secret (ADR 0050, the process
     manifest's secret groups). A single-line JWK needs no multiline
     quoting; a file-path variant was not added.
   - **At rest:** under `config:cache` the private key sits in the cached
     configuration file inside the running container, exactly like
     `APP_KEY`. It is never printed. The smoke test proves that
     `config:cache`, `route:cache` and `route:list` output carries no key
     material.

3. **Libraries.**
   - **PHP:** libsodium (`sodium_crypto_sign_seed_keypair`,
     `sodium_crypto_sign_detached`, `sodium_crypto_sign_verify_detached`).
   - **Python:** **`cryptography` 50.0.1** (PyCA), plus its `cffi` 2.1.1
     and `pycparser` 3.0, hash-locked wheels only. PyNaCl would need the
     same `cffi`; `cryptography` is the more widely audited library.
   - Neither side has hand-written cryptography or a JWT framework.

4. **Receiver placement.** The Gateway verifies in a pure ASGI middleware
   (`ServiceAuthMiddleware`) **before** routing and body parsing. A FastAPI
   dependency would let a malformed body answer 422 before authentication.
   The middleware buffers the exact body, verifies its digest, then
   replays it unchanged.

5. **A finding fixed.** `ResolveSchoolContext` (in the `api` middleware
   group) resolved a School from a **verified School domain** or the
   session on every API request, internal AI routes included. That lookup
   ran before service authentication, and on a School's verified domain it
   established `TenantContext` from the Host. On a `service-auth` route it
   now resolves no School. The development-only `X-School-Id` resolver is
   excluded from the internal AI group. The first real-container smoke
   exposed this; `ServiceAssertionMiddlewareTest` guards it.

6. **Replay.**
   - **Laravel:** consumes each `jti` in `AI_GATEWAY_REPLAY_STORE` (default
     cache when unset). Production requires Redis. The key is
     `service-assertion:{iss}:{sha256(jti)}`, with TTL `exp + 30 − now`,
     bounded to at most 150 s. A store failure is a 401.
     `ServiceAssertionReplayConcurrencyTest` releases 8 OS processes on one
     `jti` against the real Redis: exactly one is accepted.
   - **Gateway:** keeps a 10 000-entry, expiry-aware, lock-protected
     in-process set. A test demonstrates the accepted residual explicitly
     (§5.5): two replicas both accept.

7. **Rate limiting.** The `internal-service` limiter keys by source
   address. `ThrottleRequests` is framework-prioritized ahead of the
   service middleware (rule 61), so no authenticated service is known yet.
   This also bounds unauthenticated attempts.

8. **Enablement and the legacy token.**
   - **Enablement:** the Laravel integration is enabled only by
     `AI_GATEWAY_BASE_URL`, which now has no default. Unset means no keys
     are needed, and the operations status reports `not_configured`.
   - **Legacy token:** `AI_GATEWAY_SERVICE_TOKEN` (Laravel) and
     `SERVICE_TOKEN` (Gateway) are read **only** to refuse a non-empty
     leftover.

9. **Schema** (`2026_10_23_090000_retire_shared_service_credentials`,
   reversible).
   - **Dropped:** `service_identities` and `service_identity_capabilities`,
     which only held the shared secret's hash and which nothing reads.
   - **Removed:** the capability rows `ai.tools.invoke`, `ai.audit.write`,
     `platform.service_identities.view` and `.manage` (the last two only
     administered the retired identities; no route used them).
   - **Added:** CHECK `capabilities_not_service_scope`, so no service scope
     can ever be a human capability.
   - **Rollback:** `down()` restores the schema and catalog rows. Retired
     credential hashes are deliberately not restorable.
   - **Retired code:**
     - `platform:service-identity-issue` and `-disable`;
     - `ServiceIdentitySeeder`, `ServiceIdentityIssuer` and
       `ServiceIdentityAuthenticator`;
     - `VerifyAiGatewayServiceToken`, `X-Service-Token` and the `ai-service`
       middleware.

10. **Operator tooling.**
    - **`platform:service-key-generate`:** the private JWK goes only to a
      0600 file outside the application; it never overwrites, and
      `--non-production` forces the `dev-local-only-` prefix.
    - **`platform:verify-service-auth`:** read-only; kids, ages and
      transition only, never key material.
    - **Runbook:** `docs/operations/SERVICE-KEY-ROTATION.md`.

11. **Observability.**
    - **Metrics:** `lycenza_service_auth_total{direction,service,outcome}`,
      `lycenza_service_signing_key_age_days{service}` and
      `lycenza_service_verification_key_max_age_days{service}`.
    - **Alert OBS-27** (SEV-3, never pages): `unknown_kid`,
      `bad_signature`, `key_expired` or `rejected_by_receiver`, or a key at
      76 days or more.
    - **Logs:** `service_auth.failed` and `service_auth.succeeded` carry
      `peer_service`, `direction`, `outcome`, the route and `kid`. Both
      sanitizers redact `Lycenza-Service …`, JWS-shaped values, private-JWK
      `d` members, and `jti`/`bsh`/`assertion`/`verification_keys` keys.

12. **Secret hygiene.**
    - **gitleaks:** its default rules do not recognise a JWK, and the
      source-scan policy forbids custom rules, so there is no allowlist
      entry.
    - **Source guard:**
      `infrastructure/release/tests/test_service_key_hygiene.py` fails on
      any committed Ed25519 private JWK other than the development keys at
      their three exact files.
    - **Image scan:** the new `ed25519_private_jwk` shape, plus both
      development seeds as canaries allowed nowhere.
    - **`verify-images.sh`:** proves both images carry neither seed.

13. **Proof across real containers** (`verify-images.sh`, 111 checks).
    - **Keys:** each run generates non-production keys.
    - **Laravel → Gateway:** the app image's own signer calls the running
      Gateway. It authenticates, and a Bearer or replayed call is refused.
    - **Gateway → Laravel:** the Gateway image's own signer calls a hardened
      web role. It authenticates, and a Bearer call, the wrong audience and
      a replay (Redis) are refused.
    - **Refusals:** the Gateway refuses to start without keys, with the
      legacy token, with a development key or with a plaintext URL; the
      application refuses the legacy token.

14. **Qualified.** Final qualification of the published `main` commit **`50cb6e9`**
    (`50cb6e99ef8d`, run `local-20260927T110726Z-5c4b1fb9`; Grype 0.100.0, fresh
    database built 2026-09-27T06:30Z):
    - **Same-run complete regression:** 6,055 tests, 0 failures, only the
      deliberate ESI-12 skip.
    - **`verify-images.sh`:** 111/111.
    - **Language audits:** 0 advisories (including the new `cryptography` 50.0.1,
      `cffi` 2.1.1 and `pycparser` 3.0).
    - **Secret scans:** 0 findings.

    | Image | Manifest digest | Config digest | `verify-artifact` |
    |---|---|---|---|
    | Application | `sha256:42b0df7da85c5ec1ee5ed440bd66147a6c264a881595596342d9af701a53a3e3` | `sha256:5cc22da2…6b29` | **VERIFIED**: `exception_conditions_proven`, 0 blocking, 48 excepted |
    | AI Gateway | `sha256:c1604da880f2ec5e1f233aaa969aa7c1664fc8cca3b3228b37a1d43e53e0ca99` | `sha256:6e76d817…8958` | **VERIFIED**: `exception_conditions_proven`, 0 blocking, 49 excepted |

    Both residual sets are **identical** to the reviewed `OWNER-0O6E-2026-09-26`
    set, and the new Python dependencies add no finding. So these successor
    digests meet all four conditions of the decision record's §4. The evidence
    bundle's sha256 is `7c5bb5a9e21444b55f911823b6efe21594e3a45758ab7e1c213802c338e87dc5`,
    signed with an ephemeral **non-production** key. **PUBLISHED = NONE,
    PROMOTED = NONE.**

**Recorded, not fixed** (outside O5 scope; `docs/ai/AI-PLATFORM.md`):
- the ADR 0023 context-token debt of §12 (no `kid` or rotation, no
  purpose or audience claim, no `iat` sanity check);
- `/v1/tools/invoke` answering 500 on an unknown agent or tool.
