# School OS — Integration Security (Outbound Webhooks)

Phase 0C.3's security reference for the outbound webhook delivery
subsystem (`docs/architecture/INTEGRATIONS.md` is the architectural
reference; this document is the security-specific one — SSRF, secret
handling, and the signature contract third-party developers implement
against). See ADR 0018 (integration/webhook architecture), ADR 0026
(webhook delivery semantics), ADR 0027 (SSRF destination policy).

## Threat model

A webhook endpoint URL is **user-controlled input** from a School
administrator, submitted through an authenticated, capability-gated
API. This is exactly the shape of input that enables Server-Side
Request Forgery (SSRF): if unvalidated, a malicious or compromised
School admin account could register a "webhook" pointing at
`http://169.254.169.254/` (a cloud metadata endpoint) or an internal
service on the platform's own private network, using School OS's own
server as a proxy to reach infrastructure the admin has no direct
network access to.

## SSRF destination policy (ADR 0027)

Enforced by `App\Support\Webhooks\SsrfSafeUrlValidator`, called **both**
at endpoint creation/update time and again at every delivery attempt
(DNS can change between the two).

**Rejected outright:**
- Any scheme other than `http`/`https` (`file://`, `gopher://`,
  `ftp://`, `data:`, `javascript:`, ...).
- Credentials embedded in the URL (`https://user:pass@host/`).
- A malformed URL, or one missing a host.
- A host that fails to resolve.
- A host whose resolved address falls in a private, loopback,
  link-local, or otherwise reserved range: `127.0.0.0/8`, `::1`,
  `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`
  (which covers the `169.254.169.254` cloud metadata address),
  `fc00::/7`, `0.0.0.0/8`, multicast, and other PHP
  `FILTER_FLAG_NO_PRIV_RANGE`/`FILTER_FLAG_NO_RES_RANGE`-covered
  ranges — including IPv4-mapped IPv6 forms of the same addresses
  (e.g. `::ffff:127.0.0.1`).

**DNS rebinding:** the validator resolves the hostname once and
returns the validated IP; `App\Jobs\DeliverWebhookJob` pins the actual
HTTP connection to that exact IP via `CURLOPT_RESOLVE` while still
presenting the original hostname for TLS certificate validation. This
narrows, but does not perfectly eliminate, the window between
validation and connection (an attacker would need to win a race
between our resolve call and our own immediate connect, using a DNS
answer whose TTL expires in that same instant) — documented here as a
known, accepted residual risk rather than a claimed absolute guarantee.

**Redirects are never followed.** `allow_redirects: false` on every
outbound request. A redirect response (3xx) is classified as a
permanent delivery failure (see `docs/architecture/INTEGRATIONS.md`),
never re-validated-and-followed — a redirect target has not itself
passed SSRF validation.

**Local/testing override:** `WEBHOOKS_ALLOW_LOOPBACK_FOR_TESTS=true`
permits **loopback only** (`127.0.0.0/8`, `::1`) — not private,
link-local, or any other reserved range — and only when
`app()->environment(['local', 'testing'])` is also true (a real
config-flag-plus-environment double guard, the same pattern as
`App\Http\Middleware\DevOnlySchoolHeaderResolver`). Setting this flag
in a production-configured environment has no effect; the environment
check alone still blocks it. This exists solely so the Proof B local
receiver process (a real, separate PHP process bound to `127.0.0.1`)
can be exercised in tests — see
`tests/Support/LocalWebhookReceiver.php`.

## Secret generation, storage, and display

- **Generation** (`App\Support\Webhooks\WebhookEndpointService::
  generateSecret()`): `bin2hex(random_bytes(32))` — PHP's CSPRNG,
  never `uniqid()`, `mt_rand()`, or a UUID (a UUID is designed to be
  unique, not unguessable).
- **Storage**: `webhook_endpoints.secret_encrypted`/
  `previous_secret_encrypted` use Laravel's built-in `encrypted`
  Eloquent cast (APP_KEY-based AES-256-CBC via the framework's
  Encrypter) — not a custom cryptographic primitive. Encrypted, not
  hashed: unlike `ServiceIdentity.credential_hash` (verification-only),
  Laravel must retrieve the raw secret to compute an outbound HMAC, and
  a one-way hash cannot be used to compute a new signature.
- **Display**: the plaintext secret is returned in the API response
  body exactly twice in its lifetime — once at creation
  (`POST .../webhook-endpoints`), once per rotation
  (`POST .../rotate-secret`) — and never again afterward. It is never
  included in `GET` responses, logs, audit metadata, error responses,
  or OpenAPI examples.
- **Rotation** (section 9): `previous_secret_encrypted` +
  `previous_secret_expires_at` give a no-downtime overlap window
  (default 24h, `WEBHOOKS_SECRET_ROTATION_OVERLAP_HOURS`) — outbound
  signing always uses the CURRENT secret; a receiver that has not yet
  updated its stored secret is expected to try both during the overlap
  window (see the verification steps below).

## Signature contract (ADR 0026)

HMAC-SHA256 over the canonical string `{timestamp}.{deliveryId}.{rawBody}`
(`App\Support\Webhooks\WebhookSigner::sign()`), sent across three
dedicated headers (never a single combined header in this codebase's
own outbound implementation — see below):

| Header | Value |
|---|---|
| `X-SchoolOS-Event-Id` | The domain event's id. |
| `X-SchoolOS-Delivery-Id` | This logical delivery's id — binds the signature to one specific delivery; replaying a captured signature against a *different* delivery id fails verification. |
| `X-SchoolOS-Event-Type` | e.g. `school.setting.changed.v1`. |
| `X-SchoolOS-Timestamp` | Unix seconds at send time. |
| `X-SchoolOS-Signature-Version` | Currently always `v1`, so the algorithm can evolve later without breaking existing integrations mid-migration. |
| `X-SchoolOS-Signature` | The raw hex HMAC-SHA256 digest. |

**Receiver verification steps** (also documented in
`App\Support\Webhooks\WebhookSigner`'s own docblock, which is the
canonical, tested reference implementation for local
verification/tests, and the basis for any future SDK):

1. Read `X-SchoolOS-Timestamp`, `X-SchoolOS-Delivery-Id`, and
   `X-SchoolOS-Signature`.
2. Reject if `abs(now() - timestamp) > tolerance` (default 300 seconds,
   `WEBHOOKS_SIGNATURE_TOLERANCE_SECONDS`) — this is the **replay
   protection** mechanism: a captured, valid request replayed later is
   rejected once its timestamp ages out, regardless of whether the
   signature itself still "matches."
3. Recompute `HMAC-SHA256("{timestamp}.{deliveryId}.{rawBody}", secret)`
   using your stored webhook secret.
4. Compare using a constant-time comparison (`hash_equals()` or
   equivalent) against `X-SchoolOS-Signature`. Reject on mismatch.
5. During a secret rotation window, try **both** your old and new
   secret if you were issued both.

`App\Support\Webhooks\WebhookSigner::header()` also exists as a
documented, tested **alternative** Stripe-style combined single-header
format (`t=<timestamp>,v1=<signature>`) for a possible future
integration modeled that way — it is not what this codebase's own
`DeliverWebhookJob` currently sends.

Never invent a bespoke signature scheme for a future integration
without an explicit, reviewed reason — HMAC-SHA256 with a bound
delivery id and a tolerance window is the default for every future
outbound webhook this codebase adds.

## Response handling

The webhook client never deserializes or trusts arbitrary response
content. Only HTTP status code, duration, and (for 429/503) the
`Retry-After` header are read. Response bodies are never persisted.

## What is logged, audited, and NOT logged (section 27/28 of the API
idempotency checkpoint's rules apply equally here)

- **Never logged or audited**: the webhook secret (current or
  previous), the full request/response body, the raw signature value.
- **Logged** (`webhook.delivery.*` structured log lines): School id,
  event id, delivery id, endpoint id, attempt number, outcome, HTTP
  status, duration, correlation id.
- **Audited** (`SchoolAuditEvent`, ADR 0017): administrative changes
  only — endpoint created/disabled/enabled, secret rotated,
  subscription created/deleted, manual redelivery requested. Never one
  event per automatic HTTP attempt (that would flood the audit log with
  operational noise that belongs in structured logs/metrics instead).

## Partner API credentials (ADR 0049 — contract)

Inbound partner API keys are a separate system from outbound webhook
secrets and from internal service identities: School-bound, 256-bit random
secrets stored only as hashes and shown once, always expiring, rotated with
at most a 24-hour overlap (the same precedent as webhook secret rotation),
revoked immediately. A partner key is never derived from, or shared with,
a webhook secret, `APP_KEY` or the AI service token. Built in Phase 0O.3:
`api_clients` / `api_client_credentials` (hash CHECK, 365-day ceiling,
24-hour overlap, one current credential, no runtime `DELETE`), managed at
School **Integrations > API clients**; no partner route is enabled in
production.

## Production secret handling (ADR 0050, O4)

Production secrets — including every webhook-related key material the
application needs from configuration (`APP_KEY`, which encrypts
`webhook_endpoints.secret_encrypted`) — come from an external managed
secret store injected at runtime, never from git, images or Terraform
outputs. Webhook-secret and partner-credential rotation already exist in
the application; a secret store does not add rotation to anything else
(signing-key custody is future work). Service-to-service authentication and
rotation (O5) are decided by ADR 0053:
- per-request Ed25519 service assertions (`Authorization: Lycenza-Service …`);
- one keypair per calling service;
- verification rings with at most 24 h of overlap and 90-day keys;
- emergency revocation by removing the key.

They are implemented in Phase 0O.7A, and they replace the shared
`SERVICE_TOKEN`.

Phase 0O.4A: `apps/platform/deploy/processes.json` fixes which secret
group each process receives — the AI Gateway only its `SERVICE_TOKEN`
(after Phase 0O.7A, per ADR 0053: its own `ai-gateway` private signing key
and the public `platform` verification ring),
web/workers/scheduler the application runtime group, and the database
admin credentials only the release step and the operator console
(guard-tested). Images carry no secret and no committed development value
(`verify-images.sh`, `ProductionImageContractTest`). Webhook and
Communication deliveries lost with Redis are re-dispatched from
PostgreSQL; receivers must still assume at-least-once delivery (rule 39),
and the delivery's own lease claim keeps one logical send per row.
`X-Forwarded-*` headers are believed only from `TRUSTED_PROXIES`, so a
client cannot spoof its address for rate limiting or audit.

## What this document does not cover

- **Inbound webhooks** (a payment gateway calling School OS) are a
  separate, not-yet-implemented mechanism per ADR 0018 — dedicated,
  per-provider-authenticated endpoints outside `/api/v1`, never this
  outbound contract reused in reverse.
- **Payment-provider transaction/reference uniqueness** is a distinct
  idempotency concern from both this document and
  `docs/architecture/RELIABILITY.md`'s client `Idempotency-Key`
  contract — a future Fees/Payments module needs all three.

## Integration telemetry (ADR 0051, Phase 0O.5)

Webhook and partner-API telemetry never carries a destination URL,
customer hostname, School id, delivery id, client key id, secret or
signature as a metric label; per-delivery and per-client detail stays in
logs (Confidential, 30-day retention) and the existing audit trail.
Webhook alerts key on **overdue** eligible deliveries and on exhausted
outcomes in aggregate — never on one customer endpoint's failures, which
are legitimate and are that customer's concern. Partner authentication
failures are counted by the authenticator's bounded outcome codes only.
The metrics scrape token (0O.5A) is an ordinary production secret under
ADR 0050 §4, not a partner or service credential, and is never logged.

Implemented (Phase 0O.5A): webhook metrics carry only a bounded outcome or
state; partner authentication failures are counted by the authenticator's
seven outcome codes (`malformed`, `unknown_key`, `client_missing`,
`secret_mismatch`, `credential_revoked`, `credential_expired`,
`client_revoked`) — only for tokens carrying the partner prefix. The scrape
token is compared over SHA-256 digests in constant time and a missing,
wrong or unconfigured token receives the same empty 401.

## Supply chain (ADR 0052)

Third-party code enters only through committed lockfiles (Composer, npm,
hash-verified Python) from public sources; there is no private package, so
no dependency-confusion boundary exists today (one becomes mandatory if a
private package is ever added). CI runs untrusted pull-request code without
secrets (`contents: read`, no `pull_request_target`); signing and publish
authority exists only in a trusted release context on protected `main`.
Vulnerability exceptions are validated records of a human security decision,
never silent suppression.
