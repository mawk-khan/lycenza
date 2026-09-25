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
| O3 | Hosting / deployment model (and therefore process manager, container runtime, Terraform target) | No | Product + operations | S3 infrastructure, images, runbook |
| O4 | Secrets manager or host secret injection | ADR 0016 requires one before production; which one is open | Security + operations | S3 |
| O5 | Service-to-service auth: keep the shared token (with rotation) or move to per-request signed tokens / mTLS | ADR 0016 "revisit" | Security | S3 (AI Gateway deployment) |
| O6 | Runtime role name: keep `school_os_app` as a production contract, or generalize the code | ADR 0021 (model only) | Engineering | Production DB provisioning |
| O7 | API client model: who gets `/api/v1` tokens and how (mobile login token endpoint? partner keys? OAuth?), expiry, abilities | API.md defers partner keys | Product + security | S1 |
| O8 | Object storage: provider, region, encryption, versioning, lifecycle | No | Security + operations | S3 |
| O9 | Custom School domains in production: ownership verification, TLS | No | Product + operations | Domain routing in production |
| O10 | Backup policy, RPO/RTO, restore drills | No | Security + operations | Any production deployment |
| O11 | Browser security headers (CSP, HSTS, frame-ancestors…) and CORS policy | No | Security | S1 hardening |
| O12 | Observability backend and log/metric retention | ADR 0015 (model only) | Operations + security | S2 |
| O13 | Email provider, from-domain and domain authentication; invitation send outside the transaction? | No | Product + operations | Real email |
| O14 | Password reset for production accounts | ADR 0037: none exists | Product + security | Production operations |
| O15 | Which "broader third-party integrations" (ADR 0018 list) are in 0O | No | Product | S4 |
| O16 | Dependency/vulnerability audit and image pinning policy | No | Security | Supply chain |

No vendor or provider is chosen by this audit.

## 9. Readiness matrix (roadmap items)

| Requirement | Evidence | Status | Missing work | Gate |
|---|---|---|---|---|
| S1 API hardening — rate limiting | 19 named limiters; no default API limiter; 143 unthrottled `/api` routes; IP limits break behind an untrusted proxy | PARTIAL | Default API limiter; proxy trust; per-account login protection | O11 |
| S1 API hardening — partner API keys / client access | No token issuance, no expiry, no abilities, no partner keys | BLOCKED | Client model, then implementation | O7 |
| S2 Real observability backend | Instrumentation only; no exporter; plain logs; sanitizer unused | BLOCKED (backend) / PARTIAL (in-repo prep) | JSON logs, sanitizer wiring, ops-status resilience, exporter wiring | O12, O3 |
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
