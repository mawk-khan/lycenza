# ADR 0049: External API & Browser Hardening Contract (Phase 0O.2)

- Status: Accepted (contract only — nothing implemented; Phase 0O.3
  implements it). Five numeric values are **owner values still required**
  before 0O.3 can freeze its configuration (section 13).
- Date: 2026-09-25
- Resolves: Phase 0O decisions **O7** (API client/token model, partner API
  keys) and **O11** (browser security headers and CORS)
  (`docs/architecture/PHASE-0O-READINESS.md` §8).
- Relationship to other ADRs: builds on ADR 0009 (REST/OpenAPI, `/api/v1`),
  ADR 0016 (configuration and secrets), ADR 0017 (audit), ADR 0021/0022
  (RLS), ADR 0026/0027 (outbound webhooks), ADR 0037 (MFA),
  ADR 0044/0045/0046 (platform, Group and root authority), ADR 0047 (School
  lifecycle). It amends `docs/architecture/API.md` §Authentication,
  §Rate limiting and §API audit. It does not change the Phase 0C.2
  idempotency contract (`docs/architecture/RELIABILITY.md`) — section 8
  restates it as an invariant.

## Context

Phase 0O's readiness audit (`PHASE-0O-READINESS.md` §3, §6, §9) found the
external surface unhardened. This contract was written after re-verifying
every claim on `b63ed08` (code, route table and a running DDEV instance).

### What exists (verified on `b63ed08`)

**API authentication.** Human API access uses **Laravel Sanctum personal
access tokens**: `User` uses `HasApiTokens`; the `personal_access_tokens`
table (`2026_08_22_082246`) has `tokenable` (UUID morph), `name`, `token`
(sha256, unique), `abilities`, `last_used_at`, `expires_at` (nullable) —
no RLS (a platform table, like `service_identities`). Routes use
`auth:sanctum`. `/api` runs **no session middleware** (`bootstrap/app.php`
configures no `statefulApi()`), so every `/api` request authenticates by
bearer token only; the Inertia frontend calls only `/app/*` web routes.
The Sanctum guard reads the token row from the database on every request
(`last_used_at` is updated) — there is no token cache.

Why tokens are weak today:

- **No issuance surface.** Nothing in `app/` calls `createToken()`; tokens
  are minted only in tests (67 test files use `Sanctum::actingAs` or
  `createToken`).
- **No expiry.** `config/sanctum.php` `'expiration' => null` and
  `expires_at` is nullable; a token issued without `expires_at` never
  expires.
- **No scopes.** Sanctum's `createToken()` defaults `abilities` to `['*']`;
  no route checks an ability.
- **Disabled users still authenticate.** `auth:sanctum` does not check
  `users.is_disabled`; the capability resolver returns nothing for a
  disabled user, but the bearer token itself still authenticates.
- **Bearer tokens can carry platform authority.** `GET
  /api/internal/operations/status` is `auth:sanctum` plus an in-controller
  `platform.operations.view` check — a root's bearer token could call it
  with no MFA (no `/api` route carries the `mfa` middleware).

**School authority per request.** `school-membership`
(`EnsureSchoolMembershipContext`) re-reads the active membership and
`schools.status = 'active'` from the database on every School route and
answers a non-disclosing `404` otherwise. Capabilities come from
`CapabilityResolver` with a **60-second** tenant-aware cache, forgotten
explicitly on grant changes. Controllers authorize through
`AuthorizesCapability`, i.e. against the authenticated **User**.

**Partner keys.** None exist. Service identities (`service_identities`,
Phase 0C, `docs/security/AUTHORIZATION.md`) are the internal AI Gateway's machine identity — a different
system (O5).

**Route table (production mode, `APP_ENV=production`, recomputed on
`b63ed08`).** 386 `/api` routes: 380 `/api/v1` (150 GET, 230 mutations),
2 health, 4 internal. Of the 380: **240 throttled, 140 unthrottled — 139
GET and one POST** (`POST /api/v1/schools/{school}/guardian-candidates`,
an exact-match contact lookup). 93 routes carry `idempotent`; 99 enforce
capability inside the controller/service rather than by route middleware.
In local mode the table has 388 `/api` routes, 143 unthrottled, 141 of them
GET — the readiness audit's figures, which include the two local-only demo
routes (the idempotency demo and webhook test events).

**Named rate limiters (19)** in `RateLimiterServiceProvider`: `login` 6/min
IP; `public-api` 120/min IP; `school-api-mutations` 60/min School+actor;
`webhook-admin` 20/min School+actor; `internal-service` 300/min service
identity; `internal-diagnostics` 12/min user; `hr-api-reads` 120/min;
`hr-api-sensitive-reads` 20/min; `documents-reads` 120/min;
`documents-sensitive-reads` 20/min; `documents-content` 20/min;
`documents-writes` 30/min (all School+actor); `guardian-invitation-accept`
20/min IP; `mfa-challenge`, `mfa-enrollment-confirm`, `mfa-recovery-code`,
`platform-elevation`, `platform-school-lifecycle` 8/min;
`mfa-password-confirmation` 6/min. School-scoped keys come from
`tenantKey()` = route `{school}` + authenticated user (never
`TenantContext`, which is not yet set when `ThrottleRequests` runs — the
0C.2/0C.4 finding). Responses carry `X-RateLimit-Limit` /
`X-RateLimit-Remaining`; a 429 carries `Retry-After`, which the `/api`
error envelope preserves.

**CORS.** No `config/cors.php`; the framework default applies:
`paths: ['api/*', 'sanctum/csrf-cookie']`, `allowed_origins: ['*']`,
`allowed_methods: ['*']`, `allowed_headers: ['*']`,
`supports_credentials: false`, via the framework's global `HandleCors`.
Confirmed live in DDEV: a preflight and a `GET` from
`Origin: https://evil.example` to `/api/v1/system/status` both receive
`Access-Control-Allow-Origin: *`.

**Security headers.** None. No `X-Content-Type-Options`,
`Referrer-Policy`, `X-Frame-Options`, `Content-Security-Policy`,
`Strict-Transport-Security` or `Permissions-Policy` anywhere in the
application, configuration, DDEV or infrastructure. What **does** exist and
must be kept: `PreventAuthenticatedPageCaching` (`Cache-Control: no-store,
private` on signed-in web pages), the opt-in API `private-no-store`
middleware, Inertia history encryption for signed-in users, and the logout
privacy work.

**Frontend (what a CSP must allow).** `app.blade.php` loads one Vite
stylesheet and one module script (`public/build/assets`: two files);
Inertia's page data is a non-executed `<script type="application/json">`
block. The build contains no `eval`/`new Function`, no external font, CDN
or script host, and no Vue `style`/`:style` attributes. One runtime style
injection exists: **Inertia's progress bar** (`progress.includeCSS`
defaults to true) appends an inline `<style>` element on navigation.

**Browser features.** No use of camera, microphone, geolocation, payment,
USB, clipboard or other powerful features in `resources/js`.

**Downloads.** `DocumentController::content()` (and Communications
attachments, statutory exports) stream `Content-Disposition: attachment`
with the stored MIME type; no `X-Content-Type-Options`.

**Logging.** No code logs the `Authorization` header or a bearer token;
log context carries identifiers only (`TenantContext` → `Context::add`);
`LogSanitizer` already redacts `authorization`-shaped keys (not yet wired
everywhere — O12/0O.5).

**API documentation.** `packages/contracts/openapi/school-os-api.yaml`
(hand-authored, `bearerAuth` scheme) covers 220 paths / 316 operations of
the 380 `/api/v1` routes.

## Decision

### 1. Two credential classes (O7, owner decision 2026-09-25)

| | Human API token | Partner API client |
|---|---|---|
| Represents | Exactly one real `User` | A non-human external integration |
| Is **not** | A membership snapshot, a role, a Group grant, elevation | A User, a service identity, a membership, a Group grant, elevation |
| Tenancy | Whatever Schools the User can reach **at request time** | Exactly **one** School, immutable |
| Substrate | Sanctum personal access tokens (existing — no parallel system) | New School-bound client + credential records (section 4) |
| Authority | User's current capabilities ∩ token scopes | Its approved API scopes only |
| Managed in | The user's Account/Security area | School administration → Integrations |

### 2. Human API tokens

- **Substrate:** Sanctum `personal_access_tokens` — reused, not replaced.
  0O.3 always sets `expires_at` and explicit abilities.
- **Scopes (closed catalog, section 6):** `api.read` (safe methods) and
  `api.write` (mutations; implies nothing else). A token may carry either
  or both. `*` is refused at issuance and at authentication.
- **Authority is re-checked on every request**, never snapshotted:
  User enabled (0O.3 makes the token guard refuse a disabled user —
  `401`), active membership and School `active` (existing
  `school-membership`, non-disclosing `404`), the route's capability
  (existing `capability:` / `AuthorizesCapability`, within the existing
  60-second capability cache), then the token scope. A role or membership
  change therefore takes effect as it does for a browser session.
- **No platform or Group authority through a token.** A token-authenticated
  request never exercises a `platform.*` or `group.*` capability, and never
  establishes elevation (already true: rule 83 never resolves elevation on
  `/api/*`). Platform authority is MFA-bound (ADR 0046) and a bearer token
  cannot carry MFA assurance. Consequence: `/api/internal/operations/status`
  stops accepting bearer tokens in 0O.3; the `platform:operations-status`
  CLI remains.
- **Issuance:** a web (session) page in the Account/Security area — the API
  itself has no MFA challenge. Issuing needs an **enrolled MFA factor and a
  fresh code** (`MfaReverificationService::reverify()`, the elevation and
  School-lifecycle precedent for creating reusable authority). A user
  without MFA cannot issue a token. The token is shown once.
- **Revocation:** the owner revokes from the same page with current session
  assurance (no fresh code — making revocation harder only helps an
  attacker); effective on the next request (the guard reads the database
  per request). A disabled user's tokens stop working at once.
- **Lifetime and renewal:** every token has an absolute `expires_at`
  between now and the maximum (owner values V1/V2, section 13). No sliding
  renewal: a user issues a new token (fresh MFA) before expiry.
- **Out of scope:** a first-party mobile password-to-token login endpoint
  (future explicit architecture), password reset (O14).

### 3. Partner API clients

- **One School, immutable.** A client and each of its credentials carry the
  School they were created in; the database refuses any change to it
  (0O.3: trigger, composite FK credential → client on `(id, school_id)`).
  Moving an integration means issuing a new client in the other School.
  No multi-School client exists in v1.
- **Management capabilities:** `integrations.api_clients.view` and
  `integrations.api_clients.manage` (School namespace). The name follows the
  existing School integration capabilities `integrations.webhooks.view` /
  `.manage` rather than the brief's conceptual `school.api_clients.manage`;
  `school.members.*`, `school.roles.*` and every `platform.*` capability are
  not reused. 0O.3 seeds both onto `school_admin`, like the webhook pair.
- **Management surface:** a School administration page (Inertia, session,
  School context selected by the normal trusted resolution). List (`.view`,
  current MFA assurance); issue, rotate, revoke (`.manage` **and a fresh MFA
  code** each). No platform-wide partner manager; Platform Super Admin gets
  no partner-secret visibility — secrets are never stored readable.
- **Authentication:** a Laravel **auth guard** (`auth:partner`), so it runs
  in the framework-prioritized authentication slot **before**
  `ThrottleRequests` (section 8) and the limiter can key by client.
  Per request it re-checks: credential exists, not revoked, not expired;
  client active; School `active` (else the non-disclosing `404`); the
  route's registered scope. Then it establishes exactly that School's
  normal `TenantContext` (actor: the client), cleared in `finally`.
- **Surface:** partner routes are a separate, explicitly registered group
  under **`/api/v1/partner/…`** — they take **no `{school}` parameter**:
  the School comes only from the credential (rules 19–20: never from client
  input). Existing human routes are never partner-reachable (their
  controllers authorize a `User` and require a User membership).
- **School suspension:** credentials are kept; while the School is not
  `active` every partner request gets the non-disclosing `404`. Resume
  mints nothing and restores access for unexpired credentials.

### 4. Partner persistence and the tenant boundary

Authentication happens before any `TenantContext`, exactly like
`school_domains`, `school_memberships` and `personal_access_tokens` —
authorization bootstrap records that are resolvable without RLS (verified:
none of them has RLS). So:

- `api_clients` (id, `school_id` immutable, name, scopes, status
  `active`/`revoked`, created/revoked by and at) and `api_client_credentials`
  (id, client, `school_id`, non-secret `key_id` unique, `secret_hash`,
  `expires_at`, `revoked_at`, rotation `overlap_ends_at`, `last_used_at`)
  are **platform-resolvable, School-bound bootstrap records**: no RLS, an
  immutable `school_id`, every management read filtered by the trusted
  School explicitly (as `school_memberships` is), no runtime `DELETE`
  (revocation keeps history).
- After authentication the request runs under that School's ordinary
  `TenantContext`; **every tenant-domain read stays under forced RLS** — the
  partner path adds no bypass, no `pgsql_admin`, no cross-School query.

### 5. Partner secrets, rotation, revocation, expiry

- Secret: 256 bits from a CSPRNG, presented as `<prefix><key_id>.<secret>`
  with a fixed, documented, non-secret prefix distinct from human tokens
  (so leaked credentials are recognisable by secret scanners; 0O.3 also
  sets `SANCTUM_TOKEN_PREFIX` for human tokens). Lookup by `key_id`,
  constant-time comparison of a SHA-256 of the high-entropy secret (a slow
  password hash is unnecessary for 256-bit random secrets; the same choice
  as Sanctum and `service_identities`).
- Shown **once** at issue/rotation; never stored in clear, never logged,
  never in audit metadata, never re-displayed. Never derived from or shared
  with `APP_KEY`, the AI service token, a webhook secret or a password.
- **Expiry:** every credential has `expires_at` ≤ the maximum (owner values
  V3/V4). No permanent key.
- **Rotation:** issues a new credential and sets the old one's
  `expires_at` to at most **now + 24 hours**. An overlap is needed because
  an external integration cannot switch secrets atomically with issuance;
  24 hours is the repository's existing precedent for exactly this problem
  (`webhooks.secret_rotation_overlap_hours`, default 24, and
  `webhook_endpoints.previous_secret_expires_at`). At most two credentials
  of one client are ever usable, and only during an overlap.
- **Revocation** (credential or whole client): immediate — the next
  request fails; no grace period. Rotation overlap exists only inside a
  deliberate rotation.
- Partner-key lifecycle is **independent of O5** (internal service-identity
  rotation) — separate tables, separate code.

### 6. API scope model

- One closed, code-defined catalog; each scope names the principal type it
  applies to and the exact operations it permits. No `*`, `all`,
  `admin`/`administrator` or prefix/wildcard matching; unknown stored scopes
  fail closed at request time.
- Human scopes: `api.read`, `api.write` (section 2).
- Partner scopes: each maps to an explicit list of partner routes; a route
  is partner-reachable only when registered in the partner group with its
  scope.

#### 6.4 First partner surface

No product document names a partner integration (searched `docs/`:
ADR 0009/0018 describe "future" third-party access only). Therefore:

- 0O.3 builds the partner substrate and ships **no enabled partner route**
  in production; the lifecycle and guard are proven against a
  testing-only probe route (the idempotency-demo pattern).
- **Recommended first scope, pending owner approval:**
  `academic_structure.read` — read-only, non-personal School reference
  data: academic years and terms, grade levels, academic departments,
  subjects, sections, subject offerings (the offering itself — **not**
  `roster`, assignments, learning content, syllabus or curriculum
  deliveries), campuses and rooms. None of these rows stores a Student,
  Guardian or Employee (verified: Section and SubjectOffering fields;
  campus contact fields are organisational). Excluded: every person,
  attendance, finance, HR, documents, communications and exam-definition
  route. Enabling it is a one-line catalog/route registration in 0O.3 or
  later, once the owner approves.

### 7. Default rate-limit policy

Every production `/api/v1` route is throttled; 0O.3 adds a route-coverage
guard test that fails on any unthrottled `/api/v1` route (health and the
internal AI routes keep their existing, documented treatment). Numbers
come from the existing limiter conventions (section Context), not new
values:

| Class | Limit | Key | Applies to |
|---|---|---|---|
| `api-read` | 120/min | human: School + user (`tenantKey()`); partner: client id | every GET not already stricter |
| `api-mutation` | 60/min | as above | every mutation not already stricter (= existing `school-api-mutations`) |
| `api-sensitive-read` | 20/min | as above | person-lookup reads, starting with `POST …/guardian-candidates` |
| `api-auth-failure` | 20/min | IP, and partner `key_id` when parseable | failed human or partner authentication (recorded by the guard on failure — a rejected request never reaches `ThrottleRequests`) |
| credential management (web) | 8/min | user | issue/rotate/revoke pages (the `mfa-*`/`platform-*` precedent) |

Existing stricter limiters (`webhook-admin`, `documents-*`, `hr-api-*`)
stay. Authenticated traffic is never keyed by IP alone. Partner quota is
per **client**, so a rotation overlap does not double it; human quota is
per **user** in a School, so extra tokens do not multiply it.

### 8. Idempotency and ordering (Phase 0C.2, unchanged)

Restated from `RELIABILITY.md` ("Rate limiting interaction") as a frozen
invariant: routes declare `capability:` → `throttle:*` → `idempotent`;
at runtime framework-prioritized middleware (`Authenticate`, then
`ThrottleRequests`) run before the project's own (`school-membership`,
`capability`, `idempotent`). Therefore: (a) limiter keys never read
`TenantContext`; (b) authentication — including the new partner guard —
must be a framework auth guard so it precedes throttling; (c) an
idempotent **replay consumes quota** (a replay is not free; changing the
key is no way around the limit); (d) concurrent duplicates each consume
quota, and the loser gets the existing `IDEMPOTENCY_REQUEST_IN_PROGRESS`;
(e) quota is never keyed by `Idempotency-Key`. The idempotency scope gains
a partner actor type (`api_client`) when partner writes exist — never the
key alone (rule 31). The 93 existing idempotent routes are unchanged; any
future partner mutation must be `idempotent`. 0O.3 reorders nothing.

### 9. Rate-limit responses

`429` in the existing `/api` error envelope, with `Retry-After` (already
preserved) and the framework's `X-RateLimit-Limit` / `X-RateLimit-Remaining`
(and `X-RateLimit-Reset` on 429). Values describe only the caller's own
bucket; no credential id, key or other client's usage appears in any
header or body.

### 10. CORS (O11, owner decision)

- A committed `config/cors.php` replaces the framework default:
  `paths: ['api/*']` (the unused `sanctum/csrf-cookie` entry is dropped),
  `allowed_origins` from one explicit production setting — **empty by
  default, meaning no cross-origin browser access**; exact origins only (no
  patterns, suffixes, `*.domain`, user-supplied regex, or automatic School
  custom domains — O9); `supports_credentials: false`; never
  `Access-Control-Allow-Origin: *` in production.
- Methods: `GET, POST, PUT, PATCH, DELETE` (the verbs `/api/v1` uses).
  Request headers: `Authorization`, `Content-Type`, `Accept`,
  `Idempotency-Key`, `X-Request-Id`. Exposed headers: `X-Request-Id`,
  `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`. `max_age`
  a modest fixed value chosen in 0O.3 (a cache hint, not a security
  boundary).
- Browser session cookies are never cross-origin API credentials: `/api`
  stays session-less (no `statefulApi()`).

### 11. Security headers (O11, owner decision)

An application-owned middleware adds, without replacing the existing
`Cache-Control` / history / logout headers:

- every response: `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: strict-origin-when-cross-origin`,
  `X-Frame-Options: DENY`,
  `Permissions-Policy: camera=(), microphone=(), geolocation=(),
  payment=(), usb=(), serial=(), bluetooth=(), hid=(), midi=(),
  display-capture=()` (every one unused by the ERP today);
- HTML responses — **enforced** CSP:
  `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self';
  font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self';
  form-action 'self'; frame-ancestors 'none'`. No `unsafe-eval`, no
  `unsafe-inline`, no external host. **Prerequisite in 0O.3:** turn off
  Inertia's injected progress CSS (`progress.includeCSS: false`) and ship
  the equivalent rules in the built stylesheet — the one inline-style source
  found. If 0O.3 finds another genuine need, it amends this ADR rather than
  loosening silently. Local development may add only the Vite dev-server
  origin (scripts, styles, HMR websocket) while `public/hot` exists;
- API JSON and downloads: `Content-Security-Policy: default-src 'none';
  frame-ancestors 'none'; sandbox`, plus `nosniff` — downloads keep their
  `attachment` disposition and authorization unchanged (no malware
  scanning here);
- **HSTS:** production only, only when the request is known to be HTTPS,
  `max-age` = owner value V5, **no `includeSubDomains`, no `preload`**
  while O9 (custom School domains/TLS) is open.

### 12. Proxies, hosts, custom domains

Correct client-IP rate limiting and HTTPS detection (HSTS, secure cookies)
behind a reverse proxy need explicit trusted-proxy configuration, which
depends on the hosting topology (**O3**). 0O.3 must not trust every proxy
(`trustProxies(at: '*')`) as a shortcut. Host allowlisting and CORS for
School custom domains wait for a verified-domain contract (**O9**); 0O.3
adds no wildcard host trust.

### 13. Owner values required before 0O.3 freezes configuration

The repository has no precedent for these, so none is invented here:

| # | Value | Notes |
|---|---|---|
| V1 | Human API token default lifetime | Absolute; no sliding renewal |
| V2 | Human API token maximum lifetime | Upper bound of the issuance form |
| V3 | Partner credential default lifetime | |
| V4 | Partner credential maximum lifetime | Rotation overlap is fixed at ≤ 24 h (section 5) |
| V5 | HSTS `max-age` | Production only; no subdomains/preload |

Also pending owner approval (not blocking 0O.3): enabling the recommended
first partner scope `academic_structure.read` (section 6.4).

### 14. Errors and non-disclosure

| Status | When |
|---|---|
| `401` | Missing, malformed, unknown, expired or revoked credential; disabled user — one generic body, no reason detail |
| `403` | Authenticated principal, accessible School, but the route's capability or the credential's scope is missing (code `API_SCOPE_INSUFFICIENT` for scope) |
| `404` | No such School, no active membership, School not `active` (human, existing); partner School not `active`; resource not visible — indistinguishable from nonexistence |
| `429` | Section 9 |

No error names another School, a membership, another School's client id,
or why a credential was revoked. Every error uses the existing `/api`
envelope (`API.md` §Error format).

### 15. Credential logging and audit

No bearer credential appears in logs, exception reports, request/log
context, audit metadata or error responses; 0O.3 adds canary tests for each
new path. Events (identifiers and codes only):

- human, platform audit (actor = the user): `auth.api_token.issued`
  (token id, scopes, expiry), `auth.api_token.revoked` (token id);
- partner, School audit (actor = the managing user):
  `integrations.api_client.issued` (client id, credential id, scopes,
  expiry), `integrations.api_client.credential_rotated` (client id, new and
  previous credential ids, overlap end), `integrations.api_client.revoked`
  (client id, or credential id); `integrations.api_client.authentication_denied`
  (actor null; only for a known `key_id` presented with a wrong secret, or a
  revoked/expired credential; bounded by `api-auth-failure`).
- Successful calls are **not** audited per request: request ids, the
  modules' existing access audits (e.g. Documents' Highly Sensitive reads)
  and the credential's `last_used_at` apply unchanged. `API.md` §API audit
  is amended accordingly (it implied per-request API audit that was never
  built).

### 16. Data classification

| Data | Tier |
|---|---|
| Human token metadata (owner user, name, scopes, expiry, `last_used_at`) | Sensitive (links an identifiable person to access activity) |
| Partner client metadata (School, name, scopes, status, `key_id`, expiry, `last_used_at`) | Confidential |
| Token and credential hashes (`personal_access_tokens.token`, `secret_hash`) | Highly Sensitive (authentication secrets — hashing does not lower the tier) |
| Issuance/rotation/revocation/denial audit events | Highly Sensitive (the existing audit-record treatment, ADR 0046/0047) |

### 17. API documentation (part of Phase 0O completion)

Before any partner route is enabled: the partner authentication scheme,
the scope catalog, the partner routes, and the 401/403/404/429 and
rate-limit behaviour are in `packages/contracts/openapi/school-os-api.yaml`
and `API.md`. No undocumented partner scope may be enabled. Full OpenAPI
coverage of all 380 human routes is not required for Phase 0O.

### 18. Invariants

1. A human API token represents a User, never frozen permissions.
2. A partner client is non-human.
3. One partner client belongs to exactly one School.
4. No credential spans Schools.
5. Credential authority is re-checked on every request.
6. No non-expiring credential exists.
7. Secrets are stored only as hashes and shown once.
8. Partner access is deny-by-default; a route is partner-reachable only
   when registered with a scope.
9. API scopes are a closed code catalog with no wildcard.
10. School RLS is unchanged; the partner path adds no bypass.
11. Every production `/api/v1` route is throttled (guard-tested).
12. Authenticated limiter keys are the principal/client, never IP alone.
13. The Phase 0C.2 ordering and replay semantics are preserved (section 8).
14. Production CORS never allows origin `*`.
15. CORS origins are exact and configured.
16. Browser session cookies are never cross-origin API credentials.
17. CSP is enforced in production.
18. No `unsafe-eval`.
19. Framing is denied.
20. HSTS neither preloads nor includes subdomains while O9 is open.
21. Proxy topology stays with O3.
22. School custom domains stay with O9.
23. API credentials never appear in logs, audit or errors.
24. No API hardening changes Platform, Group or School authorization
    scopes; a bearer token never exercises `platform.*` or `group.*`.

### 19. Proposed Phase 0O.3 — External API & Browser Hardening Foundation (not implemented)

1. Human tokens: Account/Security page (issue with fresh MFA, list,
   revoke), explicit scopes and `expires_at` (V1/V2), guard refusal of
   disabled users and of `*`/unexpiring tokens (no production token has
   ever been issued, so existing test tokens are migrated, not preserved),
   no `platform.*`/`group.*` via token, `auth.api_token.*` events.
2. Partner substrate: `api_clients` / `api_client_credentials` (section 4),
   `auth:partner` guard, `/api/v1/partner` group, closed scope catalog,
   issue/rotate/revoke with fresh MFA on a School Integrations page,
   `integrations.api_clients.*` capabilities, audit, testing-only probe
   route; `academic_structure.read` only if the owner approves.
3. Throttling: the four API classes, `api-auth-failure`, the route-coverage
   guard, `guardian-candidates` → `api-sensitive-read`.
4. `config/cors.php` per section 10.
5. Security-header middleware, enforced CSP (with the Inertia progress-CSS
   change), download headers, production-only HSTS (V5).
6. Tests: allow/deny, cross-School, suspended School, expiry, revocation
   timing, rotation overlap, scope denial, ordering, CORS preflights,
   header presence including coexistence with no-store, canary redaction.
7. Docs: OpenAPI and `API.md` (section 17), classification, authorization.

### 20. Contribution to the Phase 0O definition of done (O1)

This contract defines only the API/browser-hardening component of O1: it is
complete when 0O.3 has shipped sections 2–11 and 14–17, the invariants in
section 18 are guard-tested, every `/api/v1` route is throttled, production
CORS is an explicit allowlist, the header set is enforced, and no partner
route is enabled without documented, owner-approved scope. O1 as a whole
remains open.

## Alternatives considered

- **Partner keys as Sanctum tokens on a synthetic "partner user".** Rejected:
  a partner would become a User with memberships and capabilities — exactly
  the conflation the owner decision forbids — and would reach every human
  route.
- **Partner routes reusing the human URIs with a School parameter.**
  Rejected: every human controller authorizes a User; accepting a
  client-supplied School id for a partner reintroduces a trust decision the
  credential already made.
- **RLS on partner credential tables with a `SECURITY DEFINER` lookup.**
  Rejected: authentication precedes tenant context; the repository's
  bootstrap records (`school_memberships`, `school_domains`,
  `personal_access_tokens`) are resolvable without RLS, and tenant data
  keeps forced RLS.
- **`style-src 'unsafe-inline'`** to tolerate Inertia's progress CSS.
  Rejected: the CSS can ship in the bundle.
- **HSTS with `includeSubDomains`/`preload` now.** Rejected until O9.
- **Per-request audit of every API call.** Rejected without evidence of
  need; module access audits and request ids already apply.

## Consequences

- Bearer tokens lose the ability to exercise platform authority (the
  operations-status API becomes CLI/web only).
- Users without MFA cannot create API tokens.
- The first partner integration needs an owner scope decision and OpenAPI
  documentation before it can be switched on.
- 0O.3 needs owner values V1–V5.

## References

`docs/architecture/PHASE-0O-READINESS.md` (§3, §6, §8, §15),
`docs/architecture/API.md`, `docs/architecture/RELIABILITY.md`
("Rate limiting interaction"), `docs/security/AUTHORIZATION.md`,
`docs/security/DATA-CLASSIFICATION.md`,
`docs/security/INTEGRATION-SECURITY.md`,
`apps/platform/app/Providers/RateLimiterServiceProvider.php`,
`apps/platform/app/Http/Middleware/Api/EnsureSchoolMembershipContext.php`,
`apps/platform/config/sanctum.php`.

## Appendix A — `/api/v1` route-family inventory (production route table, `b63ed08`)

"Human token scope" is the scope a token needs in addition to the user's
current capability; "Partner" is the v1 partner position.

| Route family | GET | Mutations | Unthrottled today | Current limiters | Human token scope | Partner (v1) | Required rate class (0O.3) |
|---|---|---|---|---|---|---|---|
| `/education-boards` | 1 | 0 | 1 GET | — | `api.read` | No | api-read |
| `/system` | 1 | 0 | — | public-api | none (public, unauthenticated) | No | public-api (unchanged) |
| `academic-departments` | 2 | 2 | 2 GET | school-api-mutations | `api.read` / `api.write` | **Proposed**, listed GET routes only (§6.4); off until owner approval | api-read; api-mutation |
| `academic-terms` | 1 | 1 | 1 GET | school-api-mutations | `api.read` / `api.write` | **Proposed**, listed GET routes only (§6.4); off until owner approval | api-read; api-mutation |
| `academic-years` | 6 | 8 | 6 GET | school-api-mutations | `api.read` / `api.write` | **Proposed**, listed GET routes only (§6.4); off until owner approval | api-read; api-mutation |
| `admission-applications` | 2 | 6 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `applicants` | 3 | 1 | 3 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `assignments` | 2 | 4 | 1 GET | documents-reads, documents-writes, school-api-mutations | `api.read` / `api.write` | No | existing stricter read limiters kept; api-read for the rest; existing stricter mutation limiters kept |
| `attendance-records` | 0 | 1 | — | school-api-mutations | `api.read` / `api.write` | No | api-mutation |
| `attendance-sessions` | 4 | 1 | 4 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `campuses` | 3 | 3 | 3 GET | school-api-mutations | `api.read` / `api.write` | **Proposed**, listed GET routes only (§6.4); off until owner approval | api-read; api-mutation |
| `canteen-billing-configuration` | 2 | 1 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `canteen-items` | 3 | 5 | 3 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `canteen-orders` | 4 | 3 | 4 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `canteen-outlets` | 2 | 2 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `charges` | 2 | 2 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `compensation-assignments` | 1 | 0 | 1 GET | — | `api.read` | No | api-read |
| `curriculum-deliveries` | 1 | 2 | 1 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `documents` | 2 | 1 | — | documents-content, documents-sensitive-reads, documents-writes | `api.read` / `api.write` | No | existing stricter read limiters kept; api-read for the rest; existing stricter mutation limiters kept |
| `employee-categories` | 1 | 4 | 1 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `employee-imports` | 0 | 1 | — | school-api-mutations | `api.read` / `api.write` | No | api-mutation |
| `employees` | 6 | 41 | — | documents-reads, documents-sensitive-reads, documents-writes, hr-api-reads, hr-api-sensitive-reads, school-api-mutations | `api.read` / `api.write` | No | existing stricter read limiters kept; api-read for the rest; existing stricter mutation limiters kept |
| `employment-records` | 1 | 1 | 1 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `enrollment-rollovers` | 3 | 9 | 3 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `enrollments` | 2 | 4 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `examination-papers` | 1 | 1 | 1 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `examinations` | 2 | 2 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `grade-levels` | 2 | 2 | 2 GET | school-api-mutations | `api.read` / `api.write` | **Proposed**, listed GET routes only (§6.4); off until owner approval | api-read; api-mutation |
| `grade-scales` | 2 | 5 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `guardian-candidates` | 0 | 1 | 0 GET + 1 mutation | — | `api.read` / `api.write` | No | api-sensitive-read (POST lookup) |
| `guardian-contacts` | 0 | 2 | — | school-api-mutations | `api.read` / `api.write` | No | api-mutation |
| `guardians` | 2 | 4 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `hostel-beds` | 0 | 1 | — | school-api-mutations | `api.read` / `api.write` | No | api-mutation |
| `hostel-residency-assignments` | 2 | 2 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `hostel-rooms` | 1 | 2 | 1 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `hostels` | 3 | 3 | 3 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `hr-departments` | 1 | 5 | 1 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `inventory-items` | 2 | 2 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `inventory-locations` | 2 | 2 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `inventory-stock` | 2 | 3 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `journal-entries` | 2 | 2 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `learning-content` | 2 | 4 | 1 GET | documents-reads, documents-writes, school-api-mutations | `api.read` / `api.write` | No | existing stricter read limiters kept; api-read for the rest; existing stricter mutation limiters kept |
| `ledger-accounts` | 1 | 0 | 1 GET | — | `api.read` | No | api-read |
| `library-copies` | 0 | 1 | — | school-api-mutations | `api.read` / `api.write` | No | api-mutation |
| `library-loans` | 2 | 2 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `library-titles` | 3 | 3 | 3 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `payments` | 2 | 0 | 2 GET | — | `api.read` | No | api-read |
| `payroll-accounting-configuration` | 0 | 1 | — | school-api-mutations | `api.read` / `api.write` | No | api-mutation |
| `payroll-periods` | 1 | 4 | 1 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `payroll-runs` | 6 | 7 | 6 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `payroll-statutory` | 7 | 5 | 7 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `positions` | 1 | 4 | 1 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `rooms` | 1 | 1 | 1 GET | school-api-mutations | `api.read` / `api.write` | **Proposed**, listed GET routes only (§6.4); off until owner approval | api-read; api-mutation |
| `salary-components` | 1 | 2 | 1 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `salary-structures` | 2 | 3 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `schools/{school} (profile, context)` | 2 | 1 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `sections` | 1 | 1 | 1 GET | school-api-mutations | `api.read` / `api.write` | **Proposed**, listed GET routes only (§6.4); off until owner approval | api-read; api-mutation |
| `student-guardian-relationships` | 0 | 3 | — | school-api-mutations | `api.read` / `api.write` | No | api-mutation |
| `students` | 5 | 6 | 5 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `subject-enrollments` | 0 | 3 | — | school-api-mutations | `api.read` / `api.write` | No | api-mutation |
| `subject-offerings` | 6 | 5 | 6 GET | school-api-mutations | `api.read` / `api.write` | **Proposed**, listed GET routes only (§6.4); off until owner approval | api-read; api-mutation |
| `subjects` | 2 | 2 | 2 GET | school-api-mutations | `api.read` / `api.write` | **Proposed**, listed GET routes only (§6.4); off until owner approval | api-read; api-mutation |
| `syllabus-units` | 1 | 1 | 1 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `timetable-entries` | 7 | 4 | 7 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `timetable-periods` | 2 | 4 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `transport-route-assignments` | 0 | 1 | — | school-api-mutations | `api.read` / `api.write` | No | api-mutation |
| `transport-routes` | 4 | 4 | 4 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `transport-stops` | 0 | 1 | — | school-api-mutations | `api.read` / `api.write` | No | api-mutation |
| `transport-student-assignments` | 2 | 2 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `transport-vehicles` | 2 | 2 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `visitor-visits` | 2 | 2 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `visitors` | 2 | 2 | 2 GET | school-api-mutations | `api.read` / `api.write` | No | api-read; api-mutation |
| `webhook-deliveries` | 2 | 1 | 2 GET | webhook-admin | `api.read` / `api.write` | No | api-read; existing stricter mutation limiters kept |
| `webhook-endpoints` | 2 | 6 | 2 GET | webhook-admin | `api.read` / `api.write` | No | api-read; existing stricter mutation limiters kept |

Totals (production route table): 150 GET, 230 mutations, 74 families.
