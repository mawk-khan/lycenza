# Phase 0O — External Surface and Production Readiness: Readiness Audit

**Status: PARTIALLY READY — SOME CHECKPOINTS MAY START (recorded
2026-09-25, baseline `f8e4b07`).** One bounded repository checkpoint
(section 10, 0O.1) is fully specified by existing ADRs and can start. The
rest of the phase needs owner, security, provider or operator decisions
first (section 8), and everything that touches a real environment is
deploy-gated (section 7). This audit decided nothing, changed no code,
provisioned nothing and handled no secret values.

Every "current state" claim below was checked against code, configuration,
CI and a running DDEV instance on `f8e4b07` (section 12).

**Update 2026-09-25 — 0O.1 COMPLETE (after the 0O.1A correction).**
Production Bootstrap & Fail-Closed Configuration Foundation is built
(section 13). It was first published at `214d095` with a residual root
database bypass and no first-account path; Phase 0O.1A corrected both
(section 14). The phase status is
unchanged: **PARTIALLY READY — SOME CHECKPOINTS MAY START**; decisions
O2–O16 are all still open, and O1 (the phase's definition of done) is not
resolved by 0O.1. Sections 4–9 below describe the pre-0O.1 baseline;
section 13 records what changed.

**Update 2026-09-25 — 0O.2 contract recorded.** O7 and O11 are resolved by
ADR 0049 (External API & Browser Hardening Contract, section 15); Phase
0O.3 implements it once the owner supplies the five numeric values V1–V5.

**Update 2026-09-25 — 0O.3 COMPLETE.** The External API & Browser
Hardening Foundation is built with the owner values (30/90-day human
tokens, 90/365-day partner credentials, HSTS 31,536,000 s) — section 16.
No production partner route is enabled.

**Update 2026-09-25 — 0O.4 contract recorded.** O3, O4, O6, O8 and O10 are
resolved by ADR 0050 (Production Infrastructure, Secrets & Recovery
Contract) — section 17. Phase 0O.4A implements its repository side.

## 1. Title and repository-defined scope

`docs/roadmap/MASTER-ROADMAP.md`, "Phase 0O — External Surface and
Production Readiness", verbatim:

> Public developer API hardening (rate limiting, partner API keys,
> building on Phase 0C.2's documented rate-limit/idempotency interaction),
> a real observability backend (ADR 0015, building on whatever
> instrumentation-only foundation Phase 0C's core substrate lands),
> production secrets/infrastructure (ADR 0016, `infrastructure/terraform`),
> and broader third-party integrations (ADR 0018) beyond the payment
> gateway from Phase 0G.

Four roadmap items, plus prerequisites that earlier ADRs explicitly assign
to "before production" / Phase 0O:

| # | Scope item | Source |
|---|---|---|
| S1 | Public developer API hardening: rate limiting, partner API keys | Roadmap; ADR 0009; `API.md` ("partner API keys … not implemented yet") |
| S2 | A real observability backend | Roadmap; ADR 0015 ("No observability backend exists yet"; backend and SDKs deferred) |
| S3 | Production secrets and infrastructure | Roadmap; ADR 0016 "Before production deployment" (secrets manager or host secret injection, never `.env` on disk; revisit the Laravel↔AI Gateway shared token); ADR 0021 (production keeps the two-role DB model); ADR 0023 (signing-key KMS/rotation deferred); ADR 0046 §2 (production root-provisioning console command is "a production-readiness prerequisite (Phase 0O)") |
| S4 | Broader third-party integrations beyond the Phase 0G payment gateway | Roadmap; ADR 0018 (payment gateways, SMS/WhatsApp/email providers, government/board systems, other school/accounting software; inbound webhooks on dedicated per-provider endpoints) |

**Completion criteria:** the roadmap states none beyond the four items.
Unlike 0N, there is no written definition of done; section 9 proposes one
per item, and the owner must confirm it (decision O1).

**Roadmap discrepancy (recorded, not resolved):** S4 says "beyond the
payment gateway from Phase 0G", but no payment gateway was ever integrated
— `docs/modules/FINANCE.md` records the provider adapter as "Not
implemented, and not routed", no gateway was chosen, no inbound callback
route exists (`FinanceHttpArchitectureGuardTest` asserts none), and
`PaymentProviderEventService` is called only by internal/test code.
Whether the first real payment gateway belongs to Phase 0O is an owner
decision (O2).

**Stop gate (CLAUDE.md rule 16 and the roadmap's "Explicit stop gates"):**
no phase deploys, provisions cloud resources, purchases services,
configures production secrets, sends real external communications or
connects real school data without separate, explicit authorization at that
time. Phase 0O is the phase most exposed to that gate; section 7 separates
what the repository can do from what an operator must do.

## 2. Existing production foundations (built in earlier phases)

- **Tenancy and database:** forced RLS on every tenant table (144 on the
  audit date); a runtime role (`school_os_app`: `NOSUPERUSER`,
  `NOBYPASSRLS`, no create role/db) separate from the migration role
  (ADR 0021); `DELETE` revoked from the runtime role on the tables that
  hold evidence (schools, platform/School audit ledgers, platform and Group
  grants, elevations, Groups); `TestDatabaseGuard` and `bin/safe-test`
  protect test databases.
- **API:** `/api/v1` versioned by path, hand-authored OpenAPI contract with
  a CI drift check, one error envelope, `idempotent` middleware
  (Phase 0C.2), 19 named rate limiters on sensitive surfaces.
- **External HTTP:** production-grade outbound webhooks (HMAC signing with
  timestamp, secret rotation with overlap, per-attempt SSRF validation with
  IP pinning, no redirects, bounded retries, lease-based concurrency;
  ADRs 0026, 0027).
- **Instrumentation-only observability (Phase 0C):** `/api/health/live`,
  `/api/health/ready` (PostgreSQL + Redis only), `/api/internal/operations/status`
  and `platform:operations-status` / `platform:failed-jobs`, scheduler and
  queue heartbeats, request/correlation ids and W3C `traceparent`
  propagation, a log-only `MetricsRecorder`.
- **Security primitives:** MFA (ADR 0037), capability authorization,
  platform authority with a root that is never granted in-app (ADR 0046),
  School lifecycle with a database-enforced transition guard (ADR 0047),
  private no-store caching on authenticated pages and document downloads.
- **Demo separation:** `DemoEnvironmentGuard` fails closed unless local
  DDEV on the `db` database; the demo login panel and `ddev demo-reset` use
  the same guard; local-only routes (`/internal/mfa-demo/ping`, idempotency
  demo, webhook test events) exist only in `local`/`testing`; the dev
  School-header override needs a config flag **and** a local/testing
  environment.
- **CI:** `.github/workflows/ci.yml` runs gitleaks, Pint, Larastan,
  frontend type-check/lint/format/build, PHPUnit on PostgreSQL 16 + Valkey,
  the AI Gateway's ruff/mypy/pytest, Flutter (self-declared unverified) and
  the shared-types drift check.

## 3. External-surface inventory

| Surface | Audience | Auth boundary | Tenant boundary | Production-ready? | Missing / gate |
|---|---|---|---|---|---|
| Web app (`/`, `/login`, `/app…`) | School staff, Guardians, platform/Group operators | Session + CSRF, MFA where required, capabilities | Session School selection re-validated per request | Application yes | Trusted proxies, HTTPS-only cookies, security headers (§6) |
| `/api/v1` | Mobile client, future partners | Sanctum bearer tokens | School in the URL + membership | **No** | **No token issuance path exists** (no route/command creates a token); tokens never expire (`sanctum.expiration = null`); no abilities; no partner keys; no default API limiter (143 of 388 `/api` routes unthrottled, 141 of them GETs); wildcard CORS origin |
| Internal AI endpoints (`/api/internal/ai/*`) | AI Gateway only | Service identity (hashed token) + signed 60 s context token | Token binds one School; School re-checked active | Partly | Shared token (ADR 0016 revisit); signing key has no fail-closed check; no service-identity CLI/rotation |
| Operations diagnostics (`/api/internal/operations/status`) | Platform operators | Sanctum + `platform.operations.view` + 12/min | Platform | Partly | `full()` has unguarded checks (a DB outage yields 500); not all heartbeats watched |
| Health (`/api/health/live`, `/api/health/ready`, `/up`) | Orchestrator / load balancer | None (by design, rule 55) | None | Mostly | `/up` has no listeners (boot-only); AI Gateway `/health/ready` returns 200 with `unhealthy` |
| Outbound webhooks | Customer endpoints | HMAC signature | Per School | Yes | `http` URLs allowed (no HTTPS requirement); IPv4-only resolution; retention period legally gated |
| Email: Guardian invitation | Guardians | Single-use 64-char token, 7-day expiry | Per School | Application yes | No provider configured (default `log`), sent synchronously inside the invitation transaction (a transport failure rolls it back); from-address default |
| Email: Communications channel | School audiences | Delivery pipeline | Per School | Application yes (off by default) | Provider, domain authentication (SPF/DKIM/DMARC undocumented) |
| Invitation links (`/invitations/{school}/{token}`) | Guardians | Token, 20/min per IP | School active re-checked | Yes | — |
| Document / attachment downloads | School users | Capabilities, proxied through Laravel (no signed URLs) | Per School, tenant-prefixed keys | Mostly | No `X-Content-Type-Options`; attachment downloads unthrottled; no malware scanning (documented deferral) |
| Password reset | — | — | — | **Does not exist** (ADR 0037) | Product decision if production needs one |
| Inbound provider webhooks (payments, SMS…) | Providers | — | — | **Do not exist** | S4 / O2 |

Maximum data tiers are unchanged from `DATA-CLASSIFICATION.md`; no
external surface was found delivering data without an authorization and
classification decision. The gaps above are transport/hardening gaps, not
classification gaps.

## 4. Configuration and secrets

**Configuration comes only from environment variables** (ADR 0016); only
`apps/platform/.env.example` and `services/ai/.env.example` are committed
(`.ddev/config.yaml` and `docker-compose.yml` carry dev-only values).

| Secret | Consumers | Shared? | Rotation | Fail-closed today? |
|---|---|---|---|---|
| `APP_KEY` | web, queue, scheduler (sessions; `encrypted` casts on webhook and MFA secrets) | no | Laravel `APP_PREVIOUS_KEYS` only; undocumented | Only when first used |
| Runtime DB credentials | web, queue, scheduler | no | none | — |
| Migration DB credentials | migrations only | no | none | — (falls back to runtime credentials if unset) |
| Redis password | Laravel | no | n/a | — (dev: none) |
| Object-storage keys | Laravel | dev: same as MinIO root | none | — |
| Mail credentials | Laravel | no | none | — |
| Webhook signing secrets | delivery job (stored encrypted per endpoint) | no | **yes** (overlap window) | — |
| AI Gateway service token | Laravel → Gateway; Gateway → Laravel; hashed in `service_identities` | **one value in three places** | none | **No:** the Gateway defaults to the public `dev-local-only-token`; its readiness passes on it |
| AI context signing key | Laravel only | no | none (no key id; ADR 0023 defers KMS) | **No:** a missing key becomes `''` and HMAC still signs and verifies |
| Contact / statutory lookup HMAC keys | Laravel | no | version per row, no workflow | Yes (throw when unset) |
| Future AI provider keys | Gateway only | — | — | Phase 0M gate (§11) |

Other findings:

- **No production boot validation.** `AppServiceProvider` validates only
  the test database (`TestDatabaseGuard`, testing environment). Framework
  defaults are safe (`APP_ENV` → production, `APP_DEBUG` → false), but
  `.env.example` ships `APP_DEBUG=true`, `LOG_LEVEL=debug`, and several
  config defaults point at local services (`AI_GATEWAY_BASE_URL`,
  `MAIL_HOST`, `DB_USERNAME=root`, `DB_SSLMODE=prefer`).
- `ServiceIdentitySeeder` has **no environment guard** and runs in every
  `db:seed`; seeded from a `.env` copied from the example, it would
  register the public dev token as the `ai-gateway` identity.
- Service identities have issue/enable/disable in `ServiceIdentityIssuer`
  but **no command**, no rotation with overlap, and no expiry.
- The Gateway's `ENVIRONMENT` setting gates nothing; its token comparison
  is not constant-time.

## 5. Runtime: database, queues, scheduler, storage, email

- **PostgreSQL:** 16 everywhere (compose, CI, DDEV); no extensions beyond
  `plpgsql`. Roles come from `infrastructure/docker/postgres/init/01-roles.sql`
  (runtime role attributes as above; default privileges tied to the
  migrating role). **The runtime role name `school_os_app` is hard-coded**
  (`TenantRls` defaults; three migrations `GRANT DELETE … TO school_os_app`),
  although ADR 0021 says only the two-role *model* is durable — production
  must either use that exact name or the code must be generalized
  (decision O6). Migrations need a role that owns the tables and can create
  policies/triggers. No production DB provisioning, backup or restore
  procedure exists.
- **Queues:** Redis (Valkey 8 locally). Queues in use: `default`,
  `integrations`, `notifications` (plus reserved `ai`, `low`, `critical`).
  Every job declares `$tries`/`$timeout` (rule 58; all timeouts < 90 s
  `retry_after`). DDEV works `notifications,default,integrations`;
  **`docker-compose.yml`'s worker has no `--queue` flag and therefore only
  works `default`** (a local-dev defect; production needs all three queues
  worked). No document states production worker processes.
- **Scheduler:** eight commands (six every minute, `withoutOverlapping`;
  two daily prunes). All are School-safe while a School is suspended
  (ADR 0047). No `onOneServer()` (deliberate: single-server assumption; the
  claims use `SKIP LOCKED`). Production invocation (`schedule:run` via cron
  or `schedule:work`) is **undocumented**; compose has no scheduler service.
  The webhook prune deletes nothing until a legally approved retention is
  set.
- **Object storage:** S3-compatible via the `s3` disk; the Documents disk
  defaults to `local` (a deployment sets it); keys are
  `schools/{id}/…` through `TenantStoragePath`; downloads are proxied (no
  signed URLs). The real-MinIO test suite proves application integration,
  not a provisioned production bucket. Bucket encryption, versioning,
  lifecycle and backup are undocumented (decision O8).
- **Email:** default mailer `log`; no provider chosen; SPF/DKIM/DMARC
  undocumented; Guardian invitations send synchronously inside the
  invitation transaction; Communications email is queued, off by default,
  with bounded retries.

## 6. Network, session, headers, observability, release

- **Reverse proxy / TLS:** no trusted-proxy or trusted-host configuration
  exists; behind a TLS-terminating proxy Laravel would not see `https` or
  the client IP (IP rate limits would all share the proxy's address).
  `SESSION_SECURE_COOKIE` has no default (null). HSTS, CSP, frame-ancestors,
  `Referrer-Policy`, `Permissions-Policy` and `X-Content-Type-Options` are
  set nowhere (confirmed also by `HR.md`). Session: 120-minute lifetime,
  `HttpOnly`, `SameSite=lax`, not encrypted.
- **CORS:** no `config/cors.php`; the framework default applies:
  `api/*` and `sanctum/csrf-cookie` with **any origin**, no credentials.
- **School domains:** `school_domains` with `verified_at`; resolution uses
  verified rows only; there is **no domain-ownership verification flow**
  (rows are verified by other means), and TLS for custom domains is an
  operator concern (decision O9).
- **Error exposure:** debug defaults off; the API envelope hides the
  message only for 500s — any other status returns the exception message
  (acceptable for deliberate HTTP exceptions, but not reviewed as a policy).
  The Gateway returns tool-error messages verbatim.
- **Logging:** plain Monolog lines (no JSON formatter); request context
  (request/correlation/trace ids, School, actor, elevation) attached to
  every line. `LogSanitizer` exists but **nothing in production code
  calls it**. Some call sites log raw exception messages (which can include
  SQL bindings). No passwords, session ids, context tokens or prompts are
  logged deliberately. The inbound `X-Request-Id` is accepted without a
  length or format check.
- **Metrics / tracing:** log lines only; no exporter or backend (S2).
- **Known debt (Phase 0L closeout §7, unchanged, not Phase 0O unless it
  chooses to own it):** duplicate `RecordQueueHeartbeat` registration;
  `domain_event_outbox.status` never `failed`; operations status does not
  watch the Communications heartbeats or the `notifications` queue.
- **Release:** no production release, migration, rollback, worker-restart
  or `route:cache` procedure exists. Local commands only. Route caching
  must be built with the production `APP_ENV`, because local-only routes
  are registered by environment at load time.
- **Containers / IaC:** both Dockerfiles and `docker-compose.yml` state
  they are local-only; no production image; `infrastructure/terraform` is
  empty by design; images pinned by tag only (`minio/minio:latest`
  unpinned); no digests.
- **Supply chain:** Composer and npm lockfiles; Python pinned with `==`
  (no hashes, no transitive lock); no dependency/vulnerability audit in CI
  or elsewhere; GitHub Actions pinned by major tag. `Database\Seeders\`
  (including demo classes) is in the production autoload — guarded at
  runtime by `DemoEnvironmentGuard`.
- **CI (probable defect, not verified — CI results are not accessible from
  this environment):** CI runs `01-roles.sql` with `ON_ERROR_STOP=1`, and
  that script grants `CONNECT ON DATABASE school_os`, but the CI Postgres
  service creates only `school_os_test`. If confirmed, every CI run fails at
  role provisioning. Verify on GitHub before relying on CI.
- **Backup / restore / disaster recovery:** nothing exists — no policy,
  procedure, drill, RPO or RTO. The roadmap does not name them explicitly;
  they are production-operations decisions (O10), and a production
  deployment of Highly Sensitive children's data cannot be authorized
  without them.

## 7. Repository work vs operator action vs provider decision

| Category | Items |
|---|---|
| **A. Repository / application** (can be built and tested locally) | Root-provisioning console command (ADR 0046 §2); production fail-closed configuration checks; AI Gateway token/readiness fail-closed; service-identity operator commands; `ServiceIdentitySeeder` guard; API token issuance model, partner keys, token expiry, default API limiter, CORS policy, security headers, trusted-proxy support; JSON logging and sanitizer wiring; operations-status resilience; production process/release runbook (documentation); production container images (build only); CI fixes and dependency audit; inbound-webhook framework for a chosen provider |
| **B. Deployment / operator action** (deploy-gated) | Creating infrastructure; DNS; certificates/TLS; installing secrets in a manager; creating production database roles; running production migrations and seeders; running the root-provisioning command; worker and scheduler processes; bucket creation/policies; mail-domain authentication records; backup configuration and restore drills |
| **C. Provider / organization decisions** | Hosting/deployment model; secrets manager; observability backend; email provider; object-storage provider and region; SMS/WhatsApp provider; payment gateway; domain ownership and TLS authority; data residency |
| **D. Blocked / gated** | Retention periods (webhook deliveries, documents, audit) — legal review; real AI provider — Phase 0M gate; malware scanning — deferred decision; person-counting Analytics — cohort gate; production deployment itself — rule 16 |

### Deploy-gated register

| Item | Why deploy-gated | Repository work possible now? | Operator action later |
|---|---|---|---|
| Production infrastructure / Terraform | Provisions cloud resources | Only after the hosting decision (O3); no apply | Plan/apply with authorization |
| Production secrets | Configures real secrets | Document names, owners, rotation; never values | Install into the chosen manager |
| DNS / TLS / custom School domains | External systems | Proxy/host support in code | Records, certificates |
| Database provisioning and roles | Real data store | Provisioning script/runbook | Create roles, run migrations |
| First Platform Super Admin | Grants root in production | **Yes — the command (0O.1)** | Run it once, in production |
| Email / SMS / payment providers | Purchases services, real sends | Adapters behind feature flags, tests with fakes | Accounts, credentials, domain records |
| Observability backend | Purchases/provisions services | Exporter wiring behind config | Backend, alert routing |
| Backup / restore | Real data | Runbook | Configure, drill |

## 8. Decision matrix

| # | Decision | Existing decision? | Owner | Blocks |
|---|---|---|---|---|
| O1 | Phase 0O definition of done per scope item | No | Product | Phase closure, not 0O.1 |
| O2 | Is the first real payment gateway Phase 0O scope (roadmap premise is false)? | No | Product | S4 |
| O3 | Hosting / deployment model (and therefore process manager, container runtime, Terraform target) | **RESOLVED — ADR 0050 (Phase 0O.4)** | Product + operations | S3 infrastructure, images, runbook |
| O4 | Secrets manager or host secret injection | **RESOLVED — ADR 0050 (Phase 0O.4)** | Security + operations | S3 |
| O5 | Service-to-service auth: keep the shared token (with rotation) or move to per-request signed tokens / mTLS | **RESOLVED — ADR 0053 (Phase 0O.7)**: per-request Ed25519 service assertions, one keypair per calling service, 24 h rotation overlap, 90-day keys; **repository implementation COMPLETE (Phase 0O.7A)**, deployment evidence outstanding | Security | S3 (AI Gateway deployment) |
| O6 | Runtime role name: keep `school_os_app` as a production contract, or generalize the code | **RESOLVED — ADR 0050 (Phase 0O.4)** | Engineering | Production DB provisioning |
| O7 | API client model: who gets `/api/v1` tokens and how (mobile login token endpoint? partner keys? OAuth?), expiry, abilities | **RESOLVED — ADR 0049 (Phase 0O.2)**; lifetimes V1–V4 are owner values still required | Product + security | S1 |
| O8 | Object storage: provider, region, encryption, versioning, lifecycle | **RESOLVED — ADR 0050 (Phase 0O.4)** | Security + operations | S3 |
| O9 | Custom School domains in production: ownership verification, TLS | No | Product + operations | Domain routing in production |
| O10 | Backup policy, RPO/RTO, restore drills | **RESOLVED — ADR 0050 (Phase 0O.4)** | Security + operations | Any production deployment |
| O11 | Browser security headers (CSP, HSTS, frame-ancestors…) and CORS policy | **RESOLVED — ADR 0049 (Phase 0O.2)**; HSTS `max-age` (V5) is an owner value still required | Security | S1 hardening |
| O12 | Observability backend and log/metric retention | **RESOLVED — ADR 0051 (Phase 0O.5)**; vendor-neutral backend, logs 30 d, metrics 90 d, no tracing in v1 | Operations + security | S2 |
| O13 | Email provider, from-domain and domain authentication; invitation send outside the transaction? | No | Product + operations | Real email |
| O14 | Password reset for production accounts | ADR 0037: none exists | Product + security | Production operations |
| O15 | Which "broader third-party integrations" (ADR 0018 list) are in 0O | No | Product | S4 |
| O16 | Dependency/vulnerability audit and image pinning policy | **RESOLVED — ADR 0052 (Phase 0O.6)**; digest-pinned bases, SHA-pinned actions, hash-verified locks, SPDX SBOM, SLSA-style provenance, cosign-compatible signing, fail-closed verification, build-once/promote-digest; repository controls COMPLETE (0O.6F: runtime security contract, approved exceptions `OWNER-0O6E-2026-09-26`), deployment evidence outstanding | Security | Supply chain |

No vendor or provider is chosen by this audit.

## 9. Readiness matrix (roadmap items)

| Requirement | Evidence | Status | Missing work | Gate |
|---|---|---|---|---|
| S1 API hardening — rate limiting | 19 named limiters; no default API limiter; 143 unthrottled `/api` routes; IP limits break behind an untrusted proxy | PARTIAL | Default API limiter; proxy trust; per-account login protection | O11 |
| S1 API hardening — partner API keys / client access | No token issuance, no expiry, no abilities, no partner keys | BLOCKED | Client model, then implementation | O7 |
| S2 Real observability backend | Instrumentation only; no exporter; plain logs; sanitizer unused. Contract fixed by ADR 0051 (Phase 0O.5) | CONTRACT DONE / in-repo foundation next (0O.5A) / backend DEPLOY-GATED | JSON logs, central sanitizer, request-id bounds, metrics endpoint, heartbeats, resilient ops status, alert specs (0O.5A); real backend, retention, alert routing (operator) | rule 16 |
| S3 Production secrets | Env-only; unsafe AI fallbacks; no validation; no rotation for service identities or signing key | PARTIAL | Fail-closed checks (0O.1); secrets manager integration | O4, O5 |
| S3 Production infrastructure | No production image, no IaC, no runbook, no backup | BLOCKED + DEPLOY-GATED | Hosting model, images, runbook, backup | O3, O8, O10, rule 16 |
| S3 Root provisioning (ADR 0046 §2) | Built in 0O.1 (`platform:provision-root`); database-enforced boundary and first-boot `platform:bootstrap-root` in 0O.1A | **DONE (0O.1 + 0O.1A)** | — | none |
| S4 Broader third-party integrations | Outbound webhooks production-grade; no inbound provider endpoint; no provider chosen | BLOCKED | Provider choice and integration list | O2, O13, O15, legal |

## 10. Proposed sequence (not started)

1. **0O.1 — Production Bootstrap & Fail-Closed Configuration Foundation**
   (READY; repository-only, no provider, no deployment):
   - the operator-only root-provisioning console command exactly as ADR
     0046 §2 specifies: console only (no HTTP route), an exact existing
     enabled user, explicit confirmation, idempotent (an existing active
     root assignment is reported, not duplicated), writes the assignment
     and `platform.role_grant.provisioned` (actor null, `method: console`);
     no password or secret handled;
   - production fail-closed configuration: refuse to boot in `production`
     with `APP_DEBUG=true`, a missing `APP_KEY`, a missing or placeholder AI
     context signing key, the public dev service token, or non-secure
     session cookies (the exact list derived from `.env.example`); the AI
     context signing key fails closed everywhere, like the lookup hashers;
   - AI Gateway: refuse the default token outside local, readiness returns
     503 when unhealthy (infrastructure only — no provider, NullProvider
     unchanged);
   - an environment guard on `ServiceIdentitySeeder`, and operator commands
     to issue/disable service identities through the existing
     `ServiceIdentityIssuer` (rotation with overlap waits for O5);
   - a written production process and release contract (web, workers for
     `default`/`integrations`/`notifications`, one scheduler, migrations via
     the migration role, `route:cache` with the production environment,
     `queue:restart`) — documentation, no provisioning;
   - verify (and fix, if confirmed) the CI role-provisioning step.
2. **0O.2 — External API & Browser Hardening Contract** (ADR; needs O7,
   O11): client/token model, expiry and abilities, partner keys, default
   API limiter, trusted proxies/hosts, CORS, security headers, error-message
   policy.
3. **0O.3 — External API & Browser Hardening Foundation.**
4. **0O.4 — Production Infrastructure & Secrets Contract** (needs O3, O4,
   O6, O8, O10): hosting model, secrets manager, images, IaC shape, backup/
   restore, runbook; then its foundation (images and IaC written, never
   applied without authorization).
5. **0O.5 — Observability Backend** (needs O12): in-repo parts (JSON logs,
   sanitizer wiring, ops-status resilience, exporter behind config) can
   precede the vendor choice.
6. **0O.6 — Third-party integrations** (needs O2, O13, O15 and legal):
   first provider adapter and inbound-webhook endpoint for whichever
   integration the owner selects.
7. **Phase 0O closeout.** Actual production deployment remains a separate,
   explicitly authorized operation (rule 16), not a checkpoint.

## 11. Phase 0M boundary

Phase 0M remains **BLOCKED — LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS
REQUIRED** (`docs/security/AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md`). Phase 0O
must not select an AI provider, add a provider SDK, add provider
credentials, enable external model calls or create a real agent. The AI
Gateway's `NullProvider` stays the only provider, `REAL_PROVIDERS_ENABLED`
stays off (the Gateway refuses to start with an external provider while it
is off), and any Phase 0O work on the Gateway is deployment and
configuration hardening only.

## 12. How this was verified

- Baseline `origin/main` = `f8e4b07`, one worktree, clean.
- Read: the roadmap (Phase 0O, stop gates), ADRs 0009, 0012, 0015, 0016,
  0018, 0021, 0023, 0037, 0046; `API.md`, `INTEGRATIONS.md`,
  `OBSERVABILITY.md`, `RELIABILITY.md`, `FINANCE.md` (payments),
  `PHASE-0L-CLOSEOUT.md` §7, `PHASE-0N-CLOSEOUT.md`, CLAUDE.md rules 16,
  26, 54, 55–63.
- Code and config: `config/*.php`, both `.env.example` files,
  `docker-compose.yml`, `.ddev/config.yaml`, both Dockerfiles, CI workflow,
  `routes/*`, `bootstrap/app.php`, rate limiters, seeders and demo guards,
  service identities, AI Gateway `config.py`/`security.py`/`health.py`.
- DDEV (read-only): PostgreSQL 16.14, extensions, runtime role attributes,
  `DELETE` privileges, effective CORS configuration, forced-RLS count.
- Not verified: CI run results (not accessible from this environment).

## 13. Phase 0O.1 outcome (COMPLETE, 2026-09-25)

Repository work only — nothing deployed, provisioned or configured; no
secret value created or installed; no provider chosen; `NullProvider`
unchanged and `REAL_PROVIDERS_ENABLED` semantics unchanged.

| Section 10 item | Result |
|---|---|
| Root provisioning (ADR 0046 §2) | `php artisan platform:provision-root` — console only, `pgsql_admin`, exact existing enabled account, typed confirmation or `--force`, idempotent (partial unique index; two-process race test), audited `platform.role_grant.provisioned` (actor null, `method: console`). No trigger changed or bypassed |
| Production fail-closed configuration | `ProductionConfigurationGuard` in `AppServiceProvider::register()` when `APP_ENV=production`: debug, `APP_KEY` (missing or unusable), `SESSION_SECURE_COOKIE`, AI signing key (missing or committed placeholder), dev service token; reads resolved config (holds under `config:cache`); codes only, never values; a refused web request is a plain 500 with debug forced off |
| AI context signing key | Fails closed in every environment (no issue, no verify without a key) |
| AI Gateway | No default token; `ENVIRONMENT` defaults to `production`; refuses to start without a token, or with the dev token outside `local`/`testing`; `/health/ready` returns 503 when not ready; constant-time token comparison; an empty expected token never authenticates |
| Service identities | `ServiceIdentitySeeder` throws outside `local`/`testing` and `DatabaseSeeder` calls it only there; `platform:service-identity-issue` / `platform:service-identity-disable` (credential shown once, hash only, never logged or audited; no rotation — O5) |
| CI role provisioning (section 6) | **Defect confirmed locally and fixed:** a clean `postgres:16-alpine` with `POSTGRES_DB=school_os_test` fails `01-roles.sql` with `database "school_os" does not exist`. The CI service now creates `school_os` and runs the three init scripts in the local order; a faithful local reproduction then migrated (via the admin role), seeded and passed RLS tests. Remote GitHub Actions results were **not** observed from this environment |
| Production process and release contract | `docs/architecture/PRODUCTION-RELEASE.md` (components, eight scheduled commands, three queues, release order, roles, seeding, operator commands) |
| Route cache | Proven in production mode (subprocess, fake values): `config:cache` and `route:cache` succeed and the cached route table contains no local/testing or demo route |

Still open after 0O.1: **O1–O16 all remain open** (O1 is not resolved by
this checkpoint); O6 in particular — the runtime role name `school_os_app`
is fixed by the implementation and documented as such, not generalized.

Residuals recorded by 0O.1 at `214d095` (both corrected by 0O.1A,
section 14):

- The database accepted a grantor-less (out-of-band) grant of the root
  role from any connection, including the runtime role.
- Production had no way to create the first platform account that
  `platform:provision-root` needs.

Other residuals:

- Signing-key and service-token rotation stay deferred (ADR 0023, O5).
- `docker-compose.yml`'s local worker still works only `default` (local
  development; unchanged).

## 14. Phase 0O.1A — root bootstrap boundary correction (2026-09-25)

**Why:** 0O.1 was published (`214d095`, full regression green) with two
bootstrap-boundary defects: (1) the runtime role could insert a root
platform assignment by raw SQL when the grant named no grantor — the
governance trigger treated a NULL grantor as "out of band" without
checking who wrote it (reproduced on the real `school_os_app` role before
the fix); (2) a fresh installation had no approved way to create the first
platform account.

**Correction:**

| Item | Result |
|---|---|
| Root database boundary | A grantor-less platform grant is accepted only from a role holding the table owner's privileges (`pg_has_role(current_user, owner, 'USAGE')`); the runtime role is refused by Eloquent, the query builder and raw SQL; a forged grantor stays refused; the trigger stays enabled; no role name hard-coded (O6 open) |
| Runtime auditor governance | Unchanged and proven: `PlatformRoleGovernanceService` still grants `platform_auditor` on the runtime role |
| First production account | **Resolved as a repository bootstrap concern** (not O14): `php artisan platform:bootstrap-root`, interactive only, first boot only (no active root), hidden password prompts with no visible fallback, `Password::defaults()`, one administrative transaction for account + root grant + `platform.role_grant.provisioned`; no School membership, no Group grant, no HTTP route |
| Existing-account provisioning | `platform:provision-root` unchanged in contract; now serialized with bootstrap on the root role row |
| Fixtures and demo | Root fixtures committed through the admin test connection and purged after each test; the DDEV demo's Platform Admin is provisioned through the service (`method: demo_seed`) |
| Production smoke | Throwaway database, fake values, production mode: migrate, production seed (no identity, user or root), `config:cache`/`route:cache`, `bootstrap-root` through a real pty, sign-in with the chosen password, one root + one audit event, runtime raw root insert refused, `provision-root` for a second existing account, second bootstrap refused, no HTTP route |

**O14 (password reset / account recovery) stays OPEN** — first-account
bootstrap sets an initial password only; it resets nothing. O1 and O2–O16
remain open. With this correction **0O.1 is COMPLETE**; Phase 0O stays
**PARTIALLY READY — SOME CHECKPOINTS MAY START**.

## 15. Phase 0O.2 — External API & Browser Hardening Contract (2026-09-25)

Documentation only (ADR 0049); nothing implemented, no configuration
changed.

- **O7 resolved:** two credential classes — human API tokens (Sanctum,
  always expiring, `api.read`/`api.write`, authority re-checked per request,
  issued with fresh MFA in the Account/Security area, never carrying
  `platform.*`/`group.*` authority) and partner API clients (one immutable
  School, platform-resolvable bootstrap records with forced RLS unchanged
  for tenant data, hashed shown-once secrets, 24-hour rotation overlap,
  immediate revocation, `integrations.api_clients.view`/`.manage` with fresh
  MFA for issue/rotate/revoke, deny-by-default `/api/v1/partner` surface).
  No partner route is enabled until the owner approves a scope; the
  recommended first scope is read-only `academic_structure.read`.
- **O11 resolved:** explicit exact-origin CORS allowlist (empty by default,
  no credentials, no wildcard); enforced CSP with no `unsafe-eval` or
  `unsafe-inline` (after moving Inertia's progress CSS into the bundle);
  `nosniff`, `Referrer-Policy`, frame denial, a deny-list
  `Permissions-Policy`, download sandboxing; production-only HSTS without
  subdomains or preload.
- **Verified on `b63ed08`:** 380 `/api/v1` routes in production mode (140
  unthrottled: 139 GET + 1 POST); the audit's 388/143/141 is the local-mode
  table including two demo routes. CORS allows `*` today (confirmed live);
  no security header exists; bearer tokens never expire and have no issuance
  surface or scopes; a disabled user's token still authenticates; a root's
  bearer token can reach the platform operations-status API.
- **Owner values still required before 0O.3 freezes configuration:** V1/V2
  human token default/maximum lifetime, V3/V4 partner credential
  default/maximum lifetime, V5 HSTS `max-age`.
- Still open: O1 (0O.2 records only the API/browser component, ADR 0049
  §20), O2–O6, O8–O10, O12–O16. Next: Phase 0O.3 — External API & Browser
  Hardening Foundation.

## 16. Phase 0O.3 — External API & Browser Hardening Foundation (COMPLETE, 2026-09-25)

Implements ADR 0049 (see its implementation amendment). Owner values:
human token 30 days default / 90 maximum, partner credential 90 / 365,
HSTS `max-age=31536000` (no subdomains, no preload), rotation overlap ≤ 24
hours. Owner decision: `academic_structure.read` is **not** enabled — the
production partner scope catalog is empty and **no production partner
route exists**; the substrate is proven with a local/testing-only probe.

| Item | Result |
|---|---|
| Human tokens | Sanctum; safe-by-construction `createToken()`; per-request refusal of unexpiring, wildcard or disabled-owner tokens (401); `api.read`/`api.write` enforcement (403); Account page with fresh-MFA issuance; revocation effective on the next request; no platform/Group authority by bearer (operations-status API refuses tokens) |
| Partner clients | `api_clients` / `api_client_credentials` (documented no-RLS bootstrap records, immutable School, database-enforced lifecycle); `auth:partner` guard; bound-School TenantContext and RLS; generic 401s; bounded denial audit; School Integrations page with fresh MFA; `integrations.api_clients.*` on `school_admin` |
| Rate limits | Every `/api/v1` route throttled (was 140 unthrottled); `api-read` / `api-mutation` / `api-sensitive-read` / `api-auth-failure` / `credential-management`; ordering unchanged |
| CORS | Exact-origin allowlist from `CORS_ALLOWED_ORIGINS`, empty by default, never `*`, no credentials; malformed configuration refuses to boot |
| Browser headers | Enforced self-only CSP (no `unsafe-*`, Inertia progress CSS moved into the bundle), nosniff, referrer policy, frame denial, Permissions-Policy, sandboxed API/download policy, production-only HSTS |

Still open: O1 (overall definition of done), O2–O6, O8–O10, O12–O16. O7
and O11 stay resolved. Trusted-proxy configuration (needed for correct
client IPs and HTTPS detection behind a proxy) remains with O3; custom
School domains with O9.

## 17. Phase 0O.4 — Production Infrastructure, Secrets & Recovery Contract (2026-09-25)

Documentation only (ADR 0050); nothing provisioned, deployed or applied.

- **O3 resolved:** provider-neutral containerized single-primary model —
  TLS-terminating proxy (only public entry), stateless web, workers for
  `default`/`integrations`/`notifications`, exactly one scheduler, AI
  Gateway on `NullProvider`, PostgreSQL 16, Redis, private S3-compatible
  storage; explicit trusted-proxy list (never `*`); immutable images.
- **O4 resolved:** an external managed secret store (vendor not chosen),
  injected before the production guard and `config:cache`; admin DB
  credentials only in the release step and operator console. Rotation
  status recorded; O5 and signing-key custody stay future work.
- **O6 resolved:** `school_os_app` stays the fixed v1 runtime role name;
  production bootstrap must grant it default privileges for the actual
  migration role.
- **O8 resolved:** private, encrypted, versioned bucket per environment;
  application-mediated downloads; no lifecycle expiry while legal
  retention is unresolved.
- **O10 resolved:** PostgreSQL RPO ≤ 15 min / RTO ≤ 4 h (PITR, 35-day
  operational window); object storage RPO ≤ 24 h / RTO ≤ 8 h (versioning
  + independent copy); quarterly isolated restore drills; single-primary DR
  only.
- **Findings:** Redis holds no irreplaceable record, but a Redis loss
  strands `dispatched` outbox rows and `pending` webhook/Communication
  deliveries (no reclaim path) — 0O.4A must add PostgreSQL-driven
  reconciliation; rolling mixed-version releases are unproven (24
  destructive migrations, no expand/contract policy), so v1 releases use a
  maintenance window; `infrastructure/terraform` stays provider-neutral and
  resource-free until a provider is chosen.
- Still open: O1, O2, O5, O9, O12–O16. Next: Phase 0O.4A — Production
  Infrastructure & Recovery Foundation.

## 18. Phase 0O.4A — Production Infrastructure & Recovery Foundation (COMPLETE, 2026-09-25)

Repository work only, implementing ADR 0050 (implementation amendment
there). Nothing deployed, applied, provisioned or activated; no real
secret, DNS, TLS, bucket policy or backup policy; no cloud, secrets or
observability vendor chosen.

- **Images:** `infrastructure/docker/production/app.Dockerfile` (nginx +
  PHP-FPM, non-root, OPcache, built assets, no dev dependencies, tests,
  demo seeders or `.env`) and `ai.Dockerfile` (non-root, NullProvider,
  fails closed without a token); `verify-images.sh` passes all checks
  locally.
- **Processes:** role entrypoint (`web`, three workers, one scheduler,
  `console`), `deploy/processes.json` with per-process secret groups —
  admin database credentials only for the release step and operator
  console; guard-tested queue coverage.
- **Trusted proxies:** explicit `TRUSTED_PROXIES` IP/CIDR list, trust-all
  and hostnames refused; spoofing and HSTS-through-proxy tests.
- **Guard:** trusted proxies, PostgreSQL default connection, TLS on
  runtime and admin connections, Redis password, S3 disks, production
  bucket, HTTPS endpoint, storage credentials, no public visibility, shared
  maintenance mode, environment separation — codes only.
- **Database:** `infrastructure/postgres/production-bootstrap.sql` +
  `verify-production-bootstrap.sh` (throwaway PostgreSQL 16 with TLS,
  non-`school_os` migration role, migrations through the production image,
  all verification green); `platform:verify-database`.
- **Redis loss:** outbox `processed_at` + `OutboxReconciler`; stale
  `pending` webhook and immediate Communication deliveries re-dispatched;
  Automation's sweep reused; `platform:recover-queued-work`; proven on
  real Redis with exactly-once effects. Sessions (sign-in again), caches,
  locks and rate limits rebuild (`docs/operations/REDIS-LOSS-RECOVERY.md`).
- **Release:** maintenance-window runbook; liveness stays 200 during
  maintenance, workers pause, the scheduler idles, the flag is shared
  through PostgreSQL.
- **Backup/restore:** runbooks, drill record template,
  `platform:verify-restore`, `platform:verify-storage`.

**REAL RESTORE DRILL STILL OUTSTANDING.** Outstanding operational
evidence (ADR 0050 §20): a real deployment, real secrets in a secret
store, bucket and backup policy activation, and one successful restore
drill in a real, isolated non-production environment.

Decisions: O3, O4, O6, O7, O8, O10, O11 resolved; **O1, O2, O5, O9,
O12, O13, O14, O15, O16 open**. Phase 0O: **PARTIALLY READY**. Phase 0M:
**BLOCKED**.

## 19. Phase 0O.5 — Observability & Alerting Contract (2026-09-26)

Documentation only (ADR 0051); nothing implemented, exported, collected or
activated; no vendor chosen.

- **O12 resolved:** a vendor-neutral external observability backend
  (the application emits sanitized JSON logs, low-cardinality metrics,
  health signals, alert definitions and runbooks; the deployment
  collects, stores, evaluates and notifies); operational logs **30 days**;
  operational metrics **90 days**; **no distributed tracing in v1**;
  no tenant/person/record identifier as a metric label. Retention does not
  change audit, legal or backup retention.
- **ADR 0015 amended, not superseded:** the OTel data model stays the
  compatibility target; v1 metrics are an OpenMetrics endpoint on a
  private port scraped by a deployment collector (token = O4 secret; O5
  untouched); logs are JSON on stderr; spans are not exported.
- **Verified current state (79cfd5a):** logs are plain text; one metric
  family (idempotency) written as log lines; `LogSanitizer` and
  `ErrorReporter` have no production caller; raw exception text reaches
  logs and `scheduler_heartbeats.last_error` from five sites and the
  framework reporter; the production image keeps exception arguments in
  traces (`zend.exception_ignore_args = 0`); inbound `X-Request-Id` is
  unbounded (an over-255-character value would fail an audited write);
  `RecordQueueHeartbeat` is registered twice; operations status watches 2
  of 6 minute-cadence heartbeats and 2 of 3 queues, omits Communications,
  Automation and outbox stale/failed state, and 500s when PostgreSQL is
  down; the Gateway has no logging configuration. The historical "outbox
  never `failed`" debt is resolved by 0O.4A.
- **Contract:** fixed JSON log schema with stable event codes; central
  sanitizing processor (Laravel logging `tap`) with extended key and value
  redaction; safe exception logging; request ids
  `^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$` or a new UUID; process-class
  heartbeats with per-queue canary jobs; queue, outbox/reconciliation,
  webhook, Communication, Automation, API and dependency metrics;
  deployment-fed backup and restore-drill metrics; a three-level severity
  model (SEV-1/2/3) and a 26-alert catalog with sourced thresholds
  (O10-derived for backups: PITR age SEV-1 above 15 min, object copy SEV-1
  above 24 h, drill overdue after 92 days); resilient operations status
  over the same checks.
- **Next:** Phase 0O.5A — Observability & Alerting Foundation (repository
  only). Deploy-gated evidence for O12: telemetry reaching a real
  backend, 30/90-day retention configured, alerts active and routed,
  backup metrics connected, drill-overdue alert active.
- **O16 boundary:** no production image may be pushed to a registry or
  promoted before O16 is resolved.

Decisions: O3, O4, O6, O7, O8, O10, O11, **O12** resolved; **O1, O2, O5,
O9, O13, O14, O15, O16 open**. Phase 0O: **PARTIALLY READY — SOME
CHECKPOINTS MAY START**. Phase 0M: **BLOCKED**. REAL RESTORE DRILL STILL
OUTSTANDING.

## 20. Phase 0O.5A — Observability & Alerting Foundation (COMPLETE, 2026-09-26)

Repository work only, implementing ADR 0051 (implementation amendment
there). **NO REAL OBSERVABILITY BACKEND IS ACTIVE. NO ALERT ROUTING IS
ACTIVE. DEPLOY-GATED O12 EVIDENCE REMAINS.**

- **Logs:** central `StructuredLogTap` (JSON, fixed schema, stable event
  codes) with the central `LogSanitizer` (keys by word segment, secret-shaped
  values); safe exception logging (no raw messages, no SQL values, no stack
  arguments; `zend.exception_ignore_args = On` in the image); the Gateway
  logs the same JSON schema with G3 redaction.
- **Request ids:** the ADR pattern or a fresh UUID; the oversized-id audit
  failure is fixed.
- **Metrics:** closed `MetricCatalog`, best-effort shared Redis counters,
  scrape-time signals; a private listener (port 9102, bearer scrape token,
  no Laravel route, available during maintenance); no vendor SDK, no
  tracing.
- **Heartbeats:** per-queue worker canaries every minute; every scheduled
  task heartbeated and counted; the duplicate queue-heartbeat listener
  fixed; atomic heartbeat upserts.
- **Durable-work visibility without RLS bypass:** trigger-maintained
  `operational_work_backlog`; overdue means eligible and not picked up —
  legitimate backoff and deferred work never alert.
- **Operations status:** the same signals and thresholds; every task, all
  three queues, Communications, Automation, outbox, recovery; degrades per
  component during a PostgreSQL outage.
- **Alerts:** OBS-01…OBS-26 as deterministic conditions and a generated,
  provider-neutral rule file; operator-valued tiers disabled until set.
- **Evidence:** backup and restore-drill metrics only from a
  deployment-controlled, strictly validated file.
- **Runbooks/dashboards:** alert index, four dashboard views, runbooks for
  webhook, Communication, failed-job and telemetry failures.

O12: **repository portion COMPLETE**; operational evidence outstanding —
a real backend receiving telemetry, ≥ 30-day log and ≥ 90-day metric
retention, the alert rules active, routing active, backup metrics
connected, the restore-drill overdue alert active. REAL RESTORE DRILL STILL
OUTSTANDING. Decisions still open: **O1, O2, O5, O9, O13, O14, O15, O16**.
Phase 0O: **PARTIALLY READY — SOME CHECKPOINTS MAY START**. Phase 0M:
**BLOCKED**. No production image may be pushed or promoted before O16.

## 21. Phase 0O.6 — Supply Chain & Artifact Security Contract (2026-09-26)

Documentation only (ADR 0052); nothing built for release, signed, pushed or
promoted; no registry vendor, signing key or KMS chosen.

- **O16 resolved:** immutable OCI digests; build once, verify, promote the
  same digest (no per-environment rebuild); production bases pinned by
  digest; every workflow action pinned to a commit SHA; deterministic
  installs (Composer/npm lock gates, a hash-verified wheels-only Python
  lock); SPDX JSON SBOM from the final image (Syft); Grype image scan with a
  ≤ 24 h database plus `composer audit`/`npm audit`/`pip-audit`; release gate
  CRITICAL blocks, HIGH with a fix blocks, HIGH without a fix needs a ≤ 30-day
  exception; exceptions in a validated JSON file, never wildcard or
  indefinite; source, image-filesystem, history, label and environment
  secret scanning; in-toto/SLSA v1 provenance (no SLSA level claimed);
  cosign-compatible signatures and attestations with custody chosen at
  deployment; one aggregate fail-closed verifier and policy manifest; states
  BUILT → VERIFIED → PUBLISHED → PROMOTED, production accepts PROMOTED
  digests only; the complete regression qualifies every release commit.
- **Audit findings for 0O.6A:** all production bases tag-only (`composer:2`
  floating a major); all CI actions tag-referenced; the Gateway pins only 5
  of 23 resolved packages and verifies no hashes; the image build runs
  `npm ci` without the repository `.npmrc` (`ignore-scripts`); stale Composer
  `allow-plugins`; Composer GitHub dists carry no `shasum` (recorded limit);
  no image history/label check, SBOM, provenance, signing or vulnerability
  scan. CI is read-only (`contents: read`), has no `pull_request_target`,
  uploads nothing and holds no registry credential.
- **Next:** Phase 0O.6A — Supply Chain & Artifact Security Foundation
  (repository only). **No production image may be pushed or promoted before
  its repository controls are complete**; the first push stays deploy-gated.

Decisions: O3, O4, O6, O7, O8, O10, O11, O12, **O16** resolved (O12 and O16
with deployment evidence outstanding); **O1, O2, O5, O9, O13, O14, O15
open**. Phase 0O: **PARTIALLY READY — SOME CHECKPOINTS MAY START**. Phase 0M:
**BLOCKED**. REAL RESTORE DRILL STILL OUTSTANDING.

## 22. Phase 0O.6A — Supply Chain & Artifact Security Foundation (COMPLETE, 2026-09-26)

Repository implementation of ADR 0052 (amendment "Phase 0O.6A
implementation"). **NO PRODUCTION REGISTRY IS CONFIGURED. NO REAL SIGNING
IDENTITY/KEY IS CONFIGURED. NO PRODUCTION IMAGE HAS BEEN PUSHED. NO PRODUCTION
IMAGE HAS BEEN PROMOTED.**

- **Inputs:** production bases pinned by digest (the last-qualified digests);
  every workflow action pinned to a commit SHA with its version; all runners
  `ubuntu-24.04`; workflows `contents: read`, no `pull_request_target`, no
  repository secret (the gitleaks action replaced by the pinned image);
  Composer `--no-scripts --no-plugins` with `allow-plugins: false` (stale
  entries removed); `.npmrc` in effect for `npm ci` in the image; the Gateway
  installs a fully resolved hash lock (`services/ai/requirements.lock`, the
  same 22 packages + pip as the verified image) wheels-only with
  `--require-hashes --no-deps`.
- **Qualification (`infrastructure/release/qualify`):** lockfile integrity,
  the same-run complete regression (`regression-gates`), gitleaks source
  scan, `composer audit`/`npm audit`/`pip-audit` (unmodified reports),
  fresh-builder OCI-archive builds with OCI labels (→ BUILT),
  `verify-images.sh` (now 61 checks), Syft SPDX 2.3 SBOM, Grype with a fresh
  ≤ 24 h database, image filesystem/config/history/env/label secret scans,
  in-toto/SLSA v1 provenance (no level claimed), an evidence bundle signed
  with an ephemeral NON-PRODUCTION key, and `verify-artifact` (15 fail-closed
  checks) → VERIFIED or FAIL. States PUBLISHED/PROMOTED are refused.
- **CI:** PR subset (lockfile integrity, audits reported, release-tooling
  tests, image build + `verify-images.sh`, full-history secret scan, PHP
  guards); `Release qualification` (`workflow_dispatch` on protected `main`,
  one run, evidence upload 90 days, never keys/`.env`/archives); `SBOM
  re-scan` (daily; `retained-releases.json` is empty).
- **Runbooks:** `docs/operations/RELEASE-QUALIFICATION.md`,
  `docs/operations/SUPPLY-CHAIN-INCIDENTS.md`; CLAUDE.md rule 87.
- **Result:** both images pass every mechanical gate but **FAIL the
  vulnerability policy** (application 40 Critical/112 High; Gateway 10
  Critical/75 High incl. Starlette 0.47.3) — neither digest is VERIFIED. No
  exception was fabricated and nothing was upgraded to pass.
- **O16:** repository portion **COMPLETE**; deployment evidence (registry,
  custody, a signed and verified promoted release) outstanding, and a
  VERIFIED digest additionally needs the vulnerability remediation decision.

Decisions: O3, O4, O6, O7, O8, O10, O11, O12, O16 resolved (O12 and O16 with
deployment evidence outstanding); **O1, O2, O5, O9, O13, O14, O15 open**.
Phase 0O: **PARTIALLY READY — SOME CHECKPOINTS MAY START**. Phase 0M:
**BLOCKED**. REAL RESTORE DRILL STILL OUTSTANDING.

## 23. Phase 0O.6B — Release Vulnerability Remediation (BLOCKED — RELEASE VULNERABILITY, 2026-09-26)

Owner decisions R1–R7 applied with the unchanged ADR 0052 gate
(`docs/security/release-remediation/`). Both images now build on Debian 13
"trixie" (same official families, digest-pinned); the application runtime no
longer carries a compiler toolchain, perl, curl CLI or xz; the Gateway runs
FastAPI 0.133.0 / Starlette 1.3.1 (minimal security-only change, pip-audit
clean) on Python 3.12.14. Findings: application 40 C / 112 H → **9 C / 65 H**;
Gateway 10 C / 75 H → **0 C / 50 H**.

**Neither image is VERIFIED:**

- application — 9 genuine CRITICAL advisories in libcurl (8) and libxml2 (1),
  libraries the official PHP binary links, with no Debian 13 fix (stop
  condition; no exception permitted);
- Gateway — CVE-2026-82049 (CPython, HIGH, fixed only in 3.14; changing the
  Python minor needs an owner decision) and 12 HIGH-without-fix advisories
  whose exception records are prepared but **not approved**.

**NO PRODUCTION REGISTRY IS CONFIGURED. NO REAL SIGNING IDENTITY/KEY IS
CONFIGURED. NO PRODUCTION IMAGE HAS BEEN PUSHED. NO PRODUCTION IMAGE HAS BEEN
PROMOTED.** Decisions: O3, O4, O6, O7, O8, O10, O11, O12, O16 resolved;
**O1, O2, O5, O9, O13, O14, O15 open**. Phase 0O: **PARTIALLY READY — SOME
CHECKPOINTS MAY START**. Phase 0M: **BLOCKED**. REAL RESTORE DRILL STILL
OUTSTANDING.

## 24. Phase 0O.6C — Patched Runtime Libraries & Python 3.14 (BLOCKED — RELEASE VULNERABILITY, 2026-09-26)

- **AI Gateway — technical remediation complete:** CPython 3.14.7 on Debian
  13 (digest-pinned), pydantic 2.12.0 / pydantic-core 2.41.1 (forced by
  cp314 wheels), everything else unchanged; CVE-2026-82049 cleared; 0
  CRITICAL, 0 HIGH with a fix; 12 HIGH-without-fix advisories (Debian
  Essential packages) in an **inactive** decision pack awaiting human
  approval — so still **not VERIFIED**.
- **Application — blocked on an ABI decision:** curl 8.22.0 (signature
  verified) would clear every libcurl finding, but libxml2's HIGH fixes exist
  only in 2.15.4 (`libxml2.so.16`, not loadable by the official PHP binary).
  The unit stopped before forcing it; options and a recommendation (rebuild
  PHP 8.3.35 from source against the patched libraries) are recorded in
  `docs/security/release-remediation/0O.6C-PATCHED-LIBRARIES-PYTHON314.md`.

**NO PRODUCTION REGISTRY IS CONFIGURED. NO REAL SIGNING IDENTITY/KEY IS
CONFIGURED. NO PRODUCTION IMAGE HAS BEEN PUSHED. NO PRODUCTION IMAGE HAS BEEN
PROMOTED.** Decisions: **O1, O2, O5, O9, O13, O14, O15 open**. Phase 0O:
**PARTIALLY READY — SOME CHECKPOINTS MAY START**. Phase 0M: **BLOCKED**. REAL
RESTORE DRILL STILL OUTSTANDING.

## 25. Phase 0O.6D — Custom PHP Runtime & ABI Remediation (TECHNICAL REMEDIATION COMPLETE — AWAITING HIGH VULNERABILITY EXCEPTION DECISION, 2026-09-26)

The production application image now runs a **repository-built PHP 8.3.35**
against curl 8.22.0 and libxml2 2.15.4 (verified upstream sources, no PHP
source patch, extension parity with the official binary, native smoke inside
the image). **Lycenza maintains this runtime** (`docs/operations/CUSTOM-PHP-RUNTIME.md`).

- Application: 9 CRITICAL / 65 HIGH → **0 CRITICAL, 0 HIGH with a fix**, 48
  HIGH-without-fix matches.
- Gateway (unchanged, CPython 3.14.7): 0 CRITICAL, 0 HIGH with a fix, 49
  HIGH-without-fix matches.
- Both residual sets are the same 12 Debian 13 Essential-package advisories
  (util-linux, acl, glibc, ncurses, perl-base, zlib) in one **inactive**
  combined decision pack. **Neither image is VERIFIED** until those exceptions
  are individually approved and the real verifier passes.

**NO PRODUCTION REGISTRY IS CONFIGURED. NO REAL SIGNING IDENTITY/KEY IS
CONFIGURED. NO PRODUCTION IMAGE HAS BEEN PUSHED. NO PRODUCTION IMAGE HAS BEEN
PROMOTED.** Decisions: **O1, O2, O5, O9, O13, O14, O15 open**. Phase 0O:
**PARTIALLY READY — SOME CHECKPOINTS MAY START**. Phase 0M: **BLOCKED**. REAL
RESTORE DRILL STILL OUTSTANDING.

## 26. Phase 0O.6F — Runtime Hardening, Approved Exceptions & Artifact Requalification (2026-09-26)

Owner decision **`OWNER-0O6E-2026-09-26`**
(`docs/security/release-remediation/0O.6E-owner-security-decision.md`)
accepts the 12 residual Debian 13 HIGH-without-fix advisories exactly, per
advisory and per package. Five are conditional on runtime hardening, which
this phase implements.

- **Runtime security contract**
  (`infrastructure/release/runtime-security.json`, provider-neutral,
  schema-guarded):
  - never privileged; ALL capabilities dropped, none added; no-new-privileges; the existing non-root user;
  - `mount`/`umount` setuid removed; no user-mountable fstab entry;
  - proven from `/proc/<pid>/status` for every process of every role by `verify-images.sh` (95/95);
  - no root process in any role;
  - read-only root audited, recorded as future work.
- **Exceptions:** 97 records (48 + 49), activated only after the hardening
  evidence.
  - Tooling enforces the approval linkage, exact per-package status, the
    approved duration, and conditional activation against the signed
    evidence of the exact artifact.
  - A fix becoming available blocks again; expiry fails verification.
  - Expiry dates: **2026-10-10** (util-linux #1/#3/#4) and **2026-10-26** (others).
- The hardened images' fresh scan has exactly the reviewed residual set:
  0 CRITICAL, 0 HIGH with a fix, and no new advisory.
- Final qualification of `93b72e5`: **both images VERIFIED**. The
  application is `sha256:8f43e1c6…6311` and the Gateway
  `sha256:f9c1573a…3808`; both have `exception_conditions_proven`. Details
  are in `docs/security/release-remediation/0O.6F-RUNTIME-HARDENING-EXCEPTIONS.md` §5.

**O16: repository controls COMPLETE; local artifacts qualified (see §5 of the
0O.6F record). Deployment evidence outstanding:** the real platform applying
the runtime contract, a registry, a production signing identity, and
promotion. **PUBLISHED = NONE, PROMOTED = NONE.**

**NO PRODUCTION REGISTRY IS CONFIGURED. NO REAL SIGNING IDENTITY/KEY IS
CONFIGURED. NO PRODUCTION IMAGE HAS BEEN PUSHED. NO PRODUCTION IMAGE HAS BEEN
PROMOTED.**
- Decisions **O1, O2, O5, O9, O13, O14, O15** remain open.
- Phase 0O: **PARTIALLY READY — SOME CHECKPOINTS MAY START**.
- Phase 0M: **BLOCKED**.
- The **real restore drill is still outstanding**.

## 27. Phase 0O.7 — Service-to-Service Authentication & Rotation Contract (2026-09-27)

**O5 is RESOLVED as a contract by ADR 0053**
(`docs/architecture/adr/0053-service-to-service-authentication-rotation-contract.md`).
This checkpoint is documentation only: there are no executable changes,
no keys, and no deployment.

**Audit.**
- **Both directions exist:**
  - A, Laravel → Gateway: `/v1/tools/invoke`, `/v1/complete`, sent with
    `X-Service-Token`. `AiGatewayClient` has no production caller yet.
  - B, Gateway → Laravel: `/api/internal/ai/tools/school-echo`, `/audit`,
    `/completions/authorize`, sent with `Bearer`.
- Both use **one symmetric value** held in three places: Laravel's
  environment, the Gateway, and a hash in `service_identities`. It has no
  expiry and no rotation, and it serves both directions.
- The ADR 0023 context token is separate and has no O5-relevant defect.

**Decision:**
- **Assertion:** a short-lived, per-request, request-bound Ed25519 service
  assertion. It is a JWS compact serialization with `alg` = `EdDSA`,
  `typ` = `lycenza-service+jwt` and `kid`, sent as
  `Authorization: Lycenza-Service …`.
- **Identities and audiences:** a closed identity catalog, `platform` and
  `ai-gateway`, with one keypair per caller. The audiences are
  `lycenza-ai-gateway` and `lycenza-platform-internal-ai`.
- **Claims:** `ver`, `iss`, `sub`, `aud`, `iat`, `nbf`, `exp`, `jti`,
  `htm`, `htp`, `bsh` and an optional `rid`. There is no School, User,
  capability or elevation claim.
- **Lifetime:** at most 120 s (60 s by default), with 30 s of skew.
- **Rings and rotation:** receiver rings hold at most 2 keys; transitional
  keys carry `not_after` ≤ 24 h; keys live at most 90 days; emergency
  revocation is by key removal.
- **Replay:** Laravel consumes each `jti` once in the existing production
  Redis. The Gateway has no shared store, so its bounded same-request
  replay window is a documented residual risk.
- **Status codes:** 401 for authentication failure, 403 for route
  authorization failure. There is no fallback.
- **Separation:** the service assertion stays separate from the AI context
  token, and never sets `TenantContext`.
- **Transport:** TLS and the private network remain required; mTLS is
  optional defense in depth.

**Next:** Phase 0O.7A — Service-to-Service Authentication & Rotation
Foundation (repository only). It must bring:
- one new audited Python Ed25519 library, which must pass O16;
- the production guards;
- the rotation runbook and tests;
- requalification of both images.

Deployment evidence (real keys, rings on every replica, one routine
rotation, and an emergency-revocation drill in a non-production
environment) stays deploy-gated.

**Exception clock:** the VERIFIED artifacts' exception records expire on
**2026-10-10** and **2026-10-26**. O5 does not renew them.

**NO PRODUCTION REGISTRY IS CONFIGURED. NO REAL SIGNING IDENTITY/KEY IS
CONFIGURED. NO PRODUCTION IMAGE HAS BEEN PUSHED. NO PRODUCTION IMAGE HAS BEEN
PROMOTED. NO SERVICE KEY EXISTS.**
- Decisions **O1, O2, O9, O13, O14, O15** remain open.
- Phase 0O: **PARTIALLY READY — SOME CHECKPOINTS MAY START**.
- Phase 0M: **BLOCKED — LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS
  REQUIRED**.
- The **real restore drill is still outstanding**.

## 28. Phase 0O.7A — Service-to-Service Authentication & Rotation Foundation (COMPLETE, 2026-09-27)

ADR 0053 is **implemented in the repository** (implementation amendment in
the ADR). Both internal directions now use per-request, request-bound
Ed25519 service assertions (`Authorization: Lycenza-Service …`):
- Laravel signs as `platform` (audience `lycenza-ai-gateway`);
- the Gateway signs as `ai-gateway` (audience
  `lycenza-platform-internal-ai`).

The shared token is gone:
- no `X-Service-Token` or Bearer path, no fallback;
- `service_identities` dropped (reversible migration);
- both production guards refuse a leftover value.

**Closed route map:**

| Receiver | Route | Scope |
|---|---|---|
| Gateway | `/v1/tools/invoke` | `gateway.tools.invoke` |
| Gateway | `/v1/complete` | `gateway.complete` |
| Laravel | `school-echo` | `ai.tools.invoke` |
| Laravel | `completions/authorize` | `ai.completions.authorize` |
| Laravel | `audit` | `ai.audit.write` |

The service scopes left the human capability catalog; a database CHECK
keeps them out.

**Controls:**
- **Replay:** Laravel consumes each `jti` once in Redis (8 real processes,
  exactly one accepted). The Gateway's best-effort per-process cache leaves
  a documented cross-replica residual.
- **Keys:** rings of 1–2 keys with `not_after` ≤ 24 h; 90-day keys (a hard
  stop in production; OBS-27 warns from day 76). Rotation, rollback and
  emergency revocation are tested with controlled time.
- **Context separation:** a service route resolves no School from a
  verified domain or a session. This was a finding, now fixed.
- **Tooling:** `platform:service-key-generate` and
  `platform:verify-service-auth`, with the
  `docs/operations/SERVICE-KEY-ROTATION.md` runbook.
- **Gateway dependency:** the one new dependency is `cryptography` 50.0.1
  (with `cffi` and `pycparser`), which passes O16.

**Proof:** `verify-images.sh` has **111** checks (95 before) and proves both
signed directions across real containers.

Final qualification of the published `main` commit **`50cb6e9`**
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

**O5:** repository implementation **COMPLETE**. Deployment evidence
**OUTSTANDING**:
- real keys through managed secret custody;
- the ring on every replica;
- one routine rotation and one emergency revocation in non-production;
- the real private TLS network, including TLS in front of the Gateway.

Recorded, not fixed: the ADR 0023 context-token debt, and
`/v1/tools/invoke` answering 500 on an unknown agent or tool
(`docs/ai/AI-PLATFORM.md`).

**Exception clock:** the VERIFIED artifacts' exception records expire
**2026-10-10** / **2026-10-26**. Any qualification after those dates needs
fixed packages or a fresh owner decision; O5 never renews them.

**NO PRODUCTION REGISTRY IS CONFIGURED. NO REAL SIGNING IDENTITY/KEY IS
CONFIGURED. NO REAL SERVICE KEY EXISTS. NO PRODUCTION IMAGE HAS BEEN PUSHED
OR PROMOTED.**
- Decisions **O1, O2, O9, O13, O14, O15** remain open.
- Phase 0O: **PARTIALLY READY — SOME CHECKPOINTS MAY START**.
- Phase 0M: **BLOCKED — LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS
  REQUIRED**.
- The **real restore drill is still outstanding**.

