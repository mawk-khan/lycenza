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

(2026-09-28: resolved by ADR 0057. The gateway is deferred from Phase 0 /
production v1; manual/offline payment recording is the required v1
correction, Phase 0O.11A.)

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
| O1 | Phase 0O definition of done per scope item | **RESOLVED AS DEFINITION OF DONE — NOT SATISFIED — ADR 0058 (Phase 0O.12, §41)**: one authoritative definition and evidence register (E01–E29) supersedes §9; closure needs repository, provider, deployment, legal and governance evidence, including a real restore drill, protected `main` and a staff/School-admin provisioning path (E24 REPOSITORY_COMPLETE, §44) | Product | Phase closure |
| O2 | Is the first real payment gateway Phase 0O scope (roadmap premise is false)? | **RESOLVED — ADR 0057 (Phase 0O.11)**: the first real payment gateway is **DEFERRED** from Phase 0 / production v1 (no processor, checkout, callback, credential, refund or PCI-bearing UI; its own future ADR). Manual/offline payment recording is a **required v1 Finance correction**, **implemented in Phase 0O.11A (COMPLETE — repository, §39)** | Product | S4 |
| O3 | Hosting / deployment model (and therefore process manager, container runtime, Terraform target) | **RESOLVED — ADR 0050 (Phase 0O.4)** | Product + operations | S3 infrastructure, images, runbook |
| O4 | Secrets manager or host secret injection | **RESOLVED — ADR 0050 (Phase 0O.4)** | Security + operations | S3 |
| O5 | Service-to-service auth: keep the shared token (with rotation) or move to per-request signed tokens / mTLS | **RESOLVED — ADR 0053 (Phase 0O.7)**: per-request Ed25519 service assertions, one keypair per calling service, 24 h rotation overlap, 90-day keys; **repository implementation COMPLETE (Phase 0O.7A)**, deployment evidence outstanding | Security | S3 (AI Gateway deployment) |
| O6 | Runtime role name: keep `school_os_app` as a production contract, or generalize the code | **RESOLVED — ADR 0050 (Phase 0O.4)** | Engineering | Production DB provisioning |
| O7 | API client model: who gets `/api/v1` tokens and how (mobile login token endpoint? partner keys? OAuth?), expiry, abilities | **RESOLVED — ADR 0049 (Phase 0O.2)**; lifetimes V1–V4 approved and frozen 2026-09-25 (30 / 90 / 90 / 365 days; ADR 0049 implementation amendment; corrected 2026-09-28) | Product + security | S1 |
| O8 | Object storage: provider, region, encryption, versioning, lifecycle | **RESOLVED — ADR 0050 (Phase 0O.4)** | Security + operations | S3 |
| O9 | Custom School domains in production: ownership verification, TLS | **RESOLVED — ADR 0054 (Phase 0O.8)**: browser-only School surface; persistent DNS TXT ownership; explicit lifecycle; edge-owned TLS proven by a domain probe; host-only sessions; 421 for unknown/non-active hosts. **Repository implementation COMPLETE (Phase 0O.8A, §30)**; deployment evidence outstanding | Product + operations | Domain routing in production |
| O10 | Backup policy, RPO/RTO, restore drills | **RESOLVED — ADR 0050 (Phase 0O.4)** | Security + operations | Any production deployment |
| O11 | Browser security headers (CSP, HSTS, frame-ancestors…) and CORS policy | **RESOLVED — ADR 0049 (Phase 0O.2)**; HSTS `max-age` (V5) approved and frozen 2026-09-25 (31,536,000; corrected 2026-09-28) | Security | S1 hardening |
| O12 | Observability backend and log/metric retention | **RESOLVED — ADR 0051 (Phase 0O.5)**; vendor-neutral backend, logs 30 d, metrics 90 d, no tracing in v1 | Operations + security | S2 |
| O13 | Email provider, from-domain and domain authentication; invitation send outside the transaction? | **RESOLVED — ADR 0055 (Phase 0O.9)**: Lycenza-controlled, deployment-configured sending domain (never a School web domain); closed From mailbox catalog, sanitized School display name, no School Reply-To in v1; one provider-neutral adapter at a time; one durable email layer (message/attempt/event/suppression) beneath invitations (outbox, outside the transaction) and Communications; authenticated, deduplicated provider events; global suppression; SPF/DKIM alignment and DMARC ≥ `p=quarantine` at readiness. **Repository implementation COMPLETE (Phase 0O.9A, §33)**; deployment evidence outstanding | Product + operations | Real email |
| O14 | Password reset for production accounts | **RESOLVED — ADR 0056 (Phase 0O.10)**: identity-level self-service password recovery on the canonical platform host only; eligible = active, non-root human Users with a local password (root stays console/operator-only); enumeration-resistant generic response with asynchronous issuance; 256-bit selector+secret credential (SHA-256 stored, secret in the URL fragment), 30 min, single-use, ≤ 3 active, never consumed by GET; ADR 0055 critical `account_recovery` email only when critical email is available; the reset bumps `users.credential_version` (every session on every host ends), revokes human personal access tokens and elevations, preserves MFA, never auto-logs in. **Implemented in the repository by Phase 0O.10A** (§36); deployment evidence (ADR 0056 §20) outstanding | Product + security | Production operations |
| O15 | Which "broader third-party integrations" (ADR 0018 list) are in 0O | **RESOLVED — ADR 0057 (Phase 0O.11)**: covers the ADR 0018 categories **and** production partner API scopes. Email in v1 (O13); SMS, WhatsApp, push, government/board, Tally/accounting and other school software DEFERRED; SSO not in v1; LMS interoperability CANCELLED; **no production partner scope** (catalog stays empty) | Product | S4 |
| O16 | Dependency/vulnerability audit and image pinning policy | **RESOLVED — ADR 0052 (Phase 0O.6)**; digest-pinned bases, SHA-pinned actions, hash-verified locks, SPDX SBOM, SLSA-style provenance, cosign-compatible signing, fail-closed verification, build-once/promote-digest; repository controls COMPLETE (0O.6F: runtime security contract, approved exceptions `OWNER-0O6E-2026-09-26`), deployment evidence outstanding | Security | Supply chain |

No vendor or provider is chosen by this audit.

## 9. Readiness matrix (roadmap items)

> **Superseded 2026-09-28 by ADR 0058** (Phase 0O.12, §41). This table
> records the pre-0O.1 baseline and its proposed definition of done. The
> authoritative definition of done and evidence register are ADR 0058 §5–§6.

| Requirement | Evidence | Status | Missing work | Gate |
|---|---|---|---|---|
| S1 API hardening — rate limiting | 19 named limiters; no default API limiter; 143 unthrottled `/api` routes; IP limits break behind an untrusted proxy | PARTIAL | Default API limiter; proxy trust; per-account login protection | O11 |
| S1 API hardening — partner API keys / client access | No token issuance, no expiry, no abilities, no partner keys | BLOCKED | Client model, then implementation | O7 |
| S2 Real observability backend | Instrumentation only; no exporter; plain logs; sanitizer unused. Contract fixed by ADR 0051 (Phase 0O.5) | CONTRACT DONE / in-repo foundation next (0O.5A) / backend DEPLOY-GATED | JSON logs, central sanitizer, request-id bounds, metrics endpoint, heartbeats, resilient ops status, alert specs (0O.5A); real backend, retention, alert routing (operator) | rule 16 |
| S3 Production secrets | Env-only; unsafe AI fallbacks; no validation; no rotation for service identities or signing key | PARTIAL | Fail-closed checks (0O.1); secrets manager integration | O4, O5 |
| S3 Production infrastructure | No production image, no IaC, no runbook, no backup | BLOCKED + DEPLOY-GATED | Hosting model, images, runbook, backup | O3, O8, O10, rule 16 |
| S3 Root provisioning (ADR 0046 §2) | Built in 0O.1 (`platform:provision-root`); database-enforced boundary and first-boot `platform:bootstrap-root` in 0O.1A | **DONE (0O.1 + 0O.1A)** | — | none |
| S4 Broader third-party integrations | Outbound webhooks production-grade; email implemented (O13); no other provider; no production partner scope | **SCOPE RESOLVED (ADR 0057)**: email in v1, every other category deferred / not in v1 / cancelled | none — 0O.11A manual/offline payment recording is **COMPLETE (repository, §39)**; it is a Finance correction, not an integration | none for the scope; legal for any future provider |

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

## 29. Phase 0O.8 — Custom School Domains & TLS Contract (2026-09-27)

**O9 is RESOLVED as a contract by ADR 0054**
(`docs/architecture/adr/0054-custom-school-domains-tls-contract.md`).
This checkpoint is documentation only: no executable change, no DNS,
TLS, ACME or edge vendor, and no deployment.

**Audit.**
- **Data model.** `school_domains` has `domain` unique across all rows,
  `type`, an unconstrained `is_primary`, and `verified_at` as its only
  state. There is no challenge, lifecycle, audit, capability, route or
  service; nothing manages domains, and no demo School has one.
- **Resolution.** `ResolveSchoolContext` matches the exact,
  non-normalized host against `verified_at IS NOT NULL` in **both** the
  web and api groups, and a verified domain overrides the session School.
- **Host validation and URLs.** None exists, and in-request absolute URLs
  follow the Host (the synchronous guardian invitation link included).
- **Unchanged and correct.** The 0O.7A service-route exclusion,
  host-only cookies (`SESSION_DOMAIN=null`), bearer-only Sanctum on
  `/api/v1`, exact-origin CORS, the `'self'` CSP and HSTS without
  subdomains or preload.
- **Missing facilities.** No `intl`, no PSL facility, and no DNS client
  with response codes or timeouts.

**Decision:**
- **Surface.** A custom domain is a **browser School surface only**. The
  platform, Group, `/api/v1`, internal, health and storage routes answer
  404 on it; `/api/v1` stays on the platform host (model B).
- **Hostnames.**
  - Hostnames are canonical lowercase ASCII LDH. IDN (`xn--`) is refused
    in v1.
  - Public suffixes are refused through a pinned PSL snapshot.
  - Reserved hosts are deployment-configured.
  - There is one claiming row per hostname (a partial unique index), and
    pending claims reserve the hostname for 24 h.
  - A School may hold up to 3 domains, with exactly one primary among
    `active` ones; aliases 308-redirect to it.
- **Lifecycle.** `pending_verification`, `verified`, `tls_pending`,
  `active`, `suspended`, `revoked`, `expired`, with database-enforced
  transitions and no deletions.
- **Ownership.** A persistent TXT record,
  `_lycenza-verification.<host>` = `lycenza-domain-verification=<43-char
  base64url 256-bit token>`, matched exactly with split strings joined.
  It is re-verified daily and never removed.
- **Routing and TLS.** Routing must reach the deployment-configured edge
  target (CNAME or an address set; private IPs are refused). The edge
  owns TLS, and the application only proves it: an IP-pinned, publicly
  trusted TLS ≥ 1.2 probe with an HMAC nonce response gates `active`.
- **Drift.** Confirmation rules suspend on conclusive failures and never
  on a single timeout.
- **Requests.**
  - Exact host classification returns **421** for unknown and non-active
    hosts alike.
  - The host decides the School, and membership is still required.
  - Switching navigates to the target's canonical origin.
  - Every absolute URL, background or in-request, comes from the stored
    canonical origin, never the Host.
- **Management.** `school.domains.view` / `.manage` with a fresh MFA code;
  a `domain-checks` rate limit.
- **Observability.** Metrics with closed labels and alerts
  **OBS-28–OBS-30**; one School's domain never affects global readiness.

**Next:** Phase 0O.8A — Custom School Domains & TLS Foundation
(repository only). It adds a PSL library and a DNS client under O16, and
closes the four recorded findings (ADR 0054 §15).

**Exception clock:** the VERIFIED artifacts' exception records expire
**2026-10-10** and **2026-10-26**; O9 renews nothing.

- Decisions **O1, O2, O13, O14, O15** remain open.
- Phase 0O: **PARTIALLY READY — SOME CHECKPOINTS MAY START**.
- Phase 0M: **BLOCKED — LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS
  REQUIRED**.
- The **real restore drill is still outstanding**.

## 30. Phase 0O.8A — Custom School Domains & TLS Foundation (COMPLETE — repository, 2026-09-27)

ADR 0054 is **implemented in the repository** (implementation amendment in
the ADR; runbook `docs/operations/CUSTOM-DOMAINS.md`; CLAUDE.md rule 88).
Custom domains are **off by default** (`CUSTOM_DOMAINS_ENABLED=false`, a
complete, safe mode); DDEV and the test suite run them with double-guarded
fake DNS and TLS.

**The four ADR 0054 §15 findings, each reproduced by a test on the pre-fix
code first, are closed:**
1. any Host was accepted and the guardian invitation link followed it —
   now one fixed **421** before any session, and every School link comes
   from the stored canonical origin;
2. `/api/*` took a School from the Host — never now;
3. a verified domain overrode the session School — never on the platform
   host; a School host refuses a session naming another School;
4. the weak `school_domains` model — replaced.

**What exists now:**
- **Host boundary:** exact classification right after trusted proxies
  (platform + aliases, `INTERNAL_HOSTS`, ACTIVE School domain, alias → 308,
  probe path, IP-literal health); a closed School-host surface; no domain
  lookup for health on a named host (rule 55).
- **Domains:** canonical ASCII-only hostnames, the pinned PSL
  (`jeremykendall/php-domain-parser` 6.4.0; snapshot sha256 verified before
  use), reserved hosts; one claiming row per hostname, ≤ 3 per School; the
  database-enforced lifecycle; the primary invariant (row CHECK + partial
  unique index + a deferred COMMIT-time constraint trigger under a per-School
  advisory lock, with an advisory-first lock order found by a real-process
  deadlock); history kept (`ON DELETE RESTRICT`, no runtime DELETE).
- **Evidence:** persistent DNS TXT ownership through a bounded DNS client
  (`mikepultz/netdns2` 2.0.8), separate routing validation (edge CNAME or
  address set; any non-public answer blocks), an IP-pinned TLS ≥ 1.2 probe
  with a per-deployment HMAC; drift suspension on the frozen thresholds and
  automatic recovery; queued, rate-limited checks.
- **Sessions:** host-only cookies kept; a one-time, 60 s, server-side
  **cross-host sign-in handoff** for School switches (continuity, never
  authority; login-CSRF defence; never logged; exactly one of 8 real-Redis
  redemptions succeeds).
- **Management and operations:** `school.domains.view`/`.manage` with fresh
  MFA, the School page, `platform:domains-edge-desired`,
  `platform:domain-probe`, `platform:domain-revoke`, OBS-28–30, production
  guards, `verify-images.sh` checks.

**Deployment evidence OUTSTANDING (rule 16):** the real edge target; a real
non-production domain verified; a certificate issued and renewed; HTTP →
HTTPS at the edge; private-key custody outside the application; drift
monitoring active; one revoke/re-add drill.

**Qualification:** §31.

- Decisions **O1, O2, O13, O14, O15** remain open.
- Phase 0O: **PARTIALLY READY — SOME CHECKPOINTS MAY START**.
- Phase 0M: **BLOCKED — LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS
  REQUIRED**.
- The **real restore drill is still outstanding**.

## 31. Phase 0O.8A — VERIFIED digests (2026-09-27)

Final qualification of the published `main` commit **`e981f25`**
(`e981f252eda7`, run `local-20260927T175753Z-e63a82a1`; Grype database
refreshed for the run):
- **Same-run complete regression:** 6,199 tests, 0 failures, only the
  deliberate ESI-12 skip (144 new tests since 0O.7A).
- **`verify-images.sh`:** 126/126 checks (111 before), including the Host
  boundary (421 before any cookie), the probe dot-path reaching Laravel, the
  access log without query strings, the production refusals of the fakes,
  development hosts, a shared `SESSION_DOMAIN` and a malformed host list, the
  enabled-mode edge/probe-key/resolver requirements, and the pinned PSL and
  DNS client working without `intl`/`ext-sockets`.
- **Language audits:** 0 advisories (including `jeremykendall/php-domain-parser`
  6.4.0 and `mikepultz/netdns2` 2.0.8). **Secret scans:** 0 findings.

| Image | Manifest digest | Config digest | `verify-artifact` |
|---|---|---|---|
| Application | `sha256:49b9be956d764381b1124e2e46ae5b06aefd9f7a890790c89aa5d89900ae9d37` | `sha256:e138f35e…e95c` | **VERIFIED**: `exception_conditions_proven`, 0 blocking, 48 excepted |
| AI Gateway | `sha256:d89f24f10bfce6fd79b21873a3a38a7564f2a63b80a503874a219adc1f9635ef` | `sha256:c23bd656…4ee2` | **VERIFIED**: `exception_conditions_proven`, 0 blocking, 49 excepted |

Both residual sets are the reviewed `OWNER-0O6E-2026-09-26` set (same counts
and records as 0O.7A); the new PHP dependencies add no finding. Evidence
bundle sha256 `8368c4c86dbbdacab4a3c6080deb147df0f4e74edadc0861009065367e406269`,
signed with an ephemeral **non-production** key. **PUBLISHED = NONE,
PROMOTED = NONE.**

**What the qualification runs found and fixed (each published separately):**
fixture tokens the source secret scan flagged (now computed at run time); the
nginx access log recording the `/index.php` rewrite instead of the path; the
image route check matching the one production probe route; two
host-load-sensitive test timing margins; and a pre-existing Library test
fixture flake (`library_loans` check-in before check-out across a second
boundary). The shared `school-os` Compose test database was found with a
drifted `school_os_app=UC` public-schema grant (not produced by any
repository code; the database role verifier flagged it); the qualification
ran against the isolated project and the shared database was left untouched.

**Exception clock:** the records expire **2026-10-10** / **2026-10-26**;
nothing was renewed.

## 32. Phase 0O.9 — Production Email & Deliverability Contract (2026-09-27)

**O13 is RESOLVED as a contract by ADR 0055**
(`docs/architecture/adr/0055-production-email-deliverability-contract.md`).
This checkpoint is documentation only: no executable change, no provider
account or key, no DNS, no SPF/DKIM/DMARC publication, no webhook secret
and no sending.

**Audit (ADR 0055 §1).**
- **Two senders exist.** The Guardian account invitation and the
  Communication Hub email channel. There are no Laravel mail
  notifications, no password-reset mail and no security notices; the
  `NotificationDispatcher` email provider is log-only.
- **Findings for 0O.9A:**
  1. the invitation is sent synchronously inside `DB::transaction()`
     (rule 38), with no delivery record or retry;
  2. production could silently sink mail: the default `log` mailer,
     the `hello@example.com` From, an unbounded SMTP timeout, the
     `failover → log` mailer, and no guard;
  3. no provider events, bounces, complaints or suppression; email
     Communications stops at `sent`;
  4. invitation send and resend are unthrottled.
- **No verified School address exists** (`schools.email` is free data;
  contact `verified_at` has no workflow).

**Decision:**
- **Identity.** Mail comes only from a Lycenza-controlled,
  deployment-configured sending domain (`MAIL_SENDING_DOMAIN`), From
  `notifications@` (a closed catalog), display name
  `"<sanitized School name> via <MAIL_FROM_NAME>"`, and no School Reply-To
  in v1. An active custom web domain (ADR 0054) authorizes nothing about
  email.
- **Classes.** `account_invitation`, `school_communication`, and reserved
  `account_recovery` (O14) and `security_notice`. Marketing is out of
  scope.
- **Provider.** One provider-neutral adapter at a time behind an
  `OutboundEmailGateway`; frozen provider requirements; no vendor chosen.
- **Durable layer.** `email_messages`, `email_submission_attempts`,
  `email_events` and `email_suppressions`.
  - Invitations write the message in their transaction and submit after
    commit.
  - Communications projects the email state onto
    `communication_deliveries`.
  - States are monotonic, and submission is never delivery.
- **Retries.** At most 6 attempts with jittered backoff over about 3 h,
  under a lease claim. The contract is at-least-once, with provider
  idempotency where available.
- **Events.** Platform-host webhook authenticated by the adapter against a
  1–2 secret ring, bounded, deduplicated on (`provider`, `event_key`); the
  School comes from the stored message only.
- **Suppression.** Global, HMAC-keyed: hard bounces suppress all classes;
  complaints suppress standard or all classes. Critical mail never
  bypasses it.
- **Authentication.** SPF, aligned DKIM, and DMARC staged
  `none → quarantine → reject`; at least `p=quarantine` is needed for
  readiness.
- **Operations.** Per-School submission budgets and an in-flight cap,
  tracking disabled, text-first content, conceptual metrics and alerts
  (IDs assigned in 0O.9A), and a production guard.

**Next:** Phase 0O.9A — Production Email & Deliverability Foundation
(repository only; ADR 0055 §23). **Not started.**

**Shared test database:** the drifted `school_os_app=UC` grant on the shared
`school_os_test` (§31) was **not** touched by this docs-only phase.

**Exception clock:** the VERIFIED artifacts' exception records expire
**2026-10-10** and **2026-10-26**; nothing is renewed.

- Decisions **O1, O2, O14, O15** remain open.
- Phase 0O: **PARTIALLY READY — SOME CHECKPOINTS MAY START**.
- Phase 0M: **BLOCKED — LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS
  REQUIRED**.
- The **real restore drill is still outstanding**.

## 33. Phase 0O.9A — Production Email & Deliverability Foundation (COMPLETE — repository, 2026-09-27)

ADR 0055 is **implemented in the repository**. The implementation amendment is
in the ADR, the runbook is `docs/operations/EMAIL-DELIVERABILITY.md`, and the
rule is CLAUDE.md rule 89. There is no vendor, credential, DNS record,
webhook exposure or real send.

**The four 0O.9 findings are closed:**
1. The invitation email is an outbox row in the invitation's own
   transaction, submitted after commit. A provider outage never fails the
   invitation.
2. Production cannot silently sink mail:
   - `MAIL_PROVIDER=none` is an explicit disabled mode;
   - log/array/failover defaults, the fake, Mailpit/local hosts, plaintext
     SMTP, an unbounded timeout, the placeholder From, tracking, a missing
     sending domain and a bad suppression key ring are all refused by
     `ProductionConfigurationGuard`;
   - SMTP has a 5 s bound.
3. Provider events, bounces, complaints and suppression exist.
   Communications `sent` now means "the provider accepted it" and later
   becomes `delivered`/`bounced`/`rejected` from provider evidence.
4. Invitation send/resend is limited to 10 per minute per admin and 200 per
   day per School.

**Built:**
- **Data.** Tables `email_messages` (RLS, trigger-enforced graph, immutable
  identity, database-enforced content purge), `email_submission_attempts`
  (RLS, append-only), `email_events` and `email_suppressions` (platform) and
  `email_provider_references` (platform routing index). There is one
  gateway for both producers.
- **Adapters and events.** The fake and hardened SMTP adapters. A
  provider-event webhook on the platform host only (bounded, authenticated
  by the adapter, deduplicated, School from stored data) with a test-only
  fake event adapter. Production event ingestion answers 404 until a
  vendor adapter exists.
- **Suppression.** Global HMAC suppression with a current + previous key
  ring.
- **Fairness.** Budgets with a reserved critical in-flight slot.
- **Operator commands.** `platform:mail-status`, `mail-verify-domain`,
  `mail-retry`, `mail-suppression-release`, `mail-suppression-rekey`,
  `email-messages-redispatch` and `email-prune`.
- **Observability.** Six metrics, alerts **OBS-31..OBS-38**, and an `email`
  Operations Status component (Degraded at worst; never readiness).
- **Local.** DDEV sends through the same layer to Mailpit, with the fake,
  signed event feed.

**Deployment evidence outstanding:**
- a selected provider account and adapter;
- the real sending domain, with SPF, DKIM and DMARC at `p=quarantine` or
  stricter after 14 days of aligned DKIM;
- credential custody and real webhook authentication;
- delivery, soft/hard bounce and complaint drills;
- a credential rotation;
- deliverability monitoring;
- the legal retention period (`MAIL_RETENTION_DAYS`, **[LEGAL REVIEW
  REQUIRED]**).

**O14 dependency:** `account_recovery` is a reserved purpose only. A future
recovery flow must require `EmailProviderResolver::criticalEmailAvailable()`.

- Decisions **O1, O2, O14, O15** remain open.
- Phase 0O: **PARTIALLY READY — SOME CHECKPOINTS MAY START**.
- Phase 0M: **BLOCKED — LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS
  REQUIRED**.
- The **real restore drill is still outstanding**.

## 34. Phase 0O.9A — VERIFIED digests (2026-09-27)

Final qualification of the published `main` commit **`5ed2734`**
(`5ed27344230a`, run `local-20260927T221142Z-77fb979a`; Grype database
refreshed for the run):

- **Same-run complete regression:** 6,320 tests, 0 failures, only the
  deliberate ESI-12 skip (121 new tests since 0O.8A).
- **Gates:** Pint, Larastan, vue-tsc, ESLint, Prettier, the frontend build,
  Gateway ruff/format/mypy/pytest and the release tooling tests all pass.
- **`verify-images.sh`:** 133/133 checks (126 before). New: production
  refuses the fake email provider, the fake event adapter, tracking and a
  failover default mailer; an enabled SMTP setup without a sending domain,
  TLS, credentials or a real suppression key is refused without printing
  the key; there is no log-only email notification provider.
- **Language audits:** 0 advisories. **Secret scans:** 0 findings.

| Image | Manifest digest | Config digest | `verify-artifact` |
|---|---|---|---|
| Application | `sha256:36a40be3ceb33ae33d9cdce4c0f6f7e0dfa5c7e14df56738a0ab68d6f03364aa` | `sha256:5da40917…eda7` | **VERIFIED**: `exception_conditions_proven`, 0 blocking, 48 excepted |
| AI Gateway | `sha256:7e284a3fdb42b49d2c739e2dabe2687ee0de1d9510f4de681d4c9b56f60f37c7` | `sha256:03f2e35f…5eb2` | **VERIFIED**: `exception_conditions_proven`, 0 blocking, 49 excepted |

- **Residual findings:** both sets are the reviewed `OWNER-0O6E-2026-09-26`
  records (same counts as 0O.8A). The email layer adds no dependency.
- **Evidence bundle:** sha256
  `2e98563d7a783e4c1447c48c9c85e7a0cff875a8580570a615cfbdfee0dbcbba`,
  signed with an ephemeral **non-production** key.
- **PUBLISHED = NONE, PROMOTED = NONE.**
- **Exception clock:** the records expire **2026-10-10** / **2026-10-26**;
  nothing was renewed.
- **Shared test database:** the shared `school-os` Compose test database's
  drifted `school_os_app=UC` public-schema grant (§31) was not touched; all
  runs used the isolated project.

## 35. Phase 0O.10 — Account Recovery Contract (2026-09-28)

**O14 is RESOLVED as a contract by ADR 0056**
(`docs/architecture/adr/0056-account-recovery-contract.md`).
This checkpoint is documentation only: no route, code, secret or email.

**Audit (ADR 0056 §1):**
- **One human model.** Every human (staff, Guardian, Group, platform,
  root) is one `users` row. There is no SSO; the service and partner
  principals are not Users.
- **Emails.** `users.email` is unique, and every writer stores it
  lowercased and trimmed, so a normalized email names at most one User
  (the stop condition does not apply).
- **Laravel's stock broker is dormant.** Its table and config exist, but
  there is no route or caller.
- **No password or email change path exists.** The password policy is the
  default `Password::defaults()` (at least 8 characters).
- **No remember cookie** is ever issued.
- **No global per-user session revocation** exists (sessions are host-only
  and stored in Redis in production).
- **Lost MFA** is handled by the existing root-only `platform.users.mfa.reset`.

**Decision:**
- **Scope.** Recovery is identity-level and runs on the canonical platform
  host only (404 on School hosts).
- **Eligibility.** Active, non-root human Users with a local password.
  Root recovery is `platform:user-password-reset` on the admin console.
- **Request.** One generic response for every well-formed request. The
  request path does uniform work; an encrypted queued job decides and
  issues.
- **Rate limits.** 10 per 15 minutes per IP, 1,000 per hour globally
  (generic 429); 3 per hour and 10 per day per keyed identity fingerprint
  (still the generic success response).
- **Credential.**
  - A 22-character selector and a 256-bit secret; only SHA-256 is stored.
  - The link is `/account-recovery/{selector}#{secret}`, so the secret
    never reaches a server URL, log or Referer.
  - 30 minutes, single use, at most 3 active.
  - A new request never kills an old one.
  - It is invalidated by any credential-version, email or eligibility
    change.
  - A GET never consumes it.
- **Reset.** One transaction, locking the User first. It:
  - applies `Password::defaults()`;
  - bumps the new `users.credential_version`, which ends every session on
    every host, the cross-host handoff included;
  - cycles the remember token;
  - revokes human personal access tokens (never partner or service
    credentials);
  - ends elevations;
  - is platform-audited `auth.password_recovered`, with a post-commit
    `security_notice` email.
- **MFA** is untouched. There is no auto-login: the browser goes to
  `/login`.
- **Email.** ADR 0055 `account_recovery` critical email, expiring with the
  credential and respecting suppression. Identity-level email rows get a
  nullable `school_id` and a sanctioned platform-email RLS scope.
- **Configuration.** `ACCOUNT_RECOVERY_ENABLED=false` by default, with a
  production guard, and the stock broker is removed.

**Findings for 0O.10A:** login is case-sensitive; a failed login audits the
raw email; there is no authenticated password change; no global session
revocation yet; self-service lost-MFA recovery is debt; and no production
staff-account onboarding path exists (ADR 0056 §17).

**Next:** Phase 0O.10A — Account Recovery Foundation (repository only).
**Not started.**

- **O13 legal retention:** `MAIL_RETENTION_DAYS` remains
  **[LEGAL REVIEW REQUIRED]**; not resolved here.
- **Exception clock:** the VERIFIED artifacts' exception records expire
  **2026-10-10** and **2026-10-26**; nothing is renewed.
- Decisions **O1, O2, O15** remain open.
- Phase 0O: **PARTIALLY READY — SOME CHECKPOINTS MAY START**.
- Phase 0M: **BLOCKED — LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS
  REQUIRED**.
- The **real restore drill is still outstanding**.

## 36. Phase 0O.10A — Account Recovery Foundation (COMPLETE — repository, 2026-09-28)

ADR 0056 is **implemented in the repository**. The implementation amendment
is ADR 0056 §24, the runbook is `docs/operations/ACCOUNT-RECOVERY.md` and
the rule is CLAUDE.md rule 90. `ACCOUNT_RECOVERY_ENABLED` is `false`
everywhere except DDEV. There is no real email, provider or production
enablement.

**Built:**
- **Identity.**
  - One canonical email form, enforced by `users_email_canonical_check`
    (the migration audits first and refuses on violating data).
  - Login now accepts any case.
  - A failed login audits a keyed fingerprint, never the typed address.
- **Recovery flow.**
  - Platform-host-only pages (404 on School hosts, 421 on unknown hosts)
    with one generic response for every case.
  - IP and global 429 limits; silent per-address limits keyed by an HKDF
    derivation of `APP_KEY`.
  - Encrypted asynchronous issuance; `account_recovery_requests`
    (selector + SHA-256 of a 256-bit fragment secret, 30 min, single use,
    at most 3 open, never invalidating older ones).
  - A GET never consumes; the reset is a CSRF-protected POST behind its
    own IP + selector limit.
- **Revocation.**
  - `users.credential_version`, bumped by the database on password, email
    or disable, which voids open credentials with a reason.
  - Stamped at every sign-in path; the global `EnforceCredentialVersion`
    signs stale sessions out on every host (the cross-host handoff and a
    pending MFA challenge included).
- **One password writer.** `CredentialChangeService`: remember token,
  human personal access tokens (partner/service untouched), elevation
  `credential_reset`, MFA untouched.
- **Notice and email.** A post-commit `security_notice`. Identity-level
  email (nullable `school_id`, `PlatformEmailScope`, its own RLS policy
  mode and fairness bucket).
- **Operator.** `platform:user-password-reset` (root's only path),
  `platform:account-recovery-status`, hourly `platform:account-recovery-prune`.
- **Observability.** Three counters and an enabled/available gauge;
  alerts **OBS-39..OBS-41**; the `account_recovery` Operations Status
  component (Degraded at worst; never readiness); a production guard
  (`account_recovery_email_disabled`).
- **Legacy removed.** Laravel's stock reset broker (table, default broker,
  notification; architecture-tested).
- **Tests.** Real-process concurrency for the same link, two links, and a
  reset racing a disable, an email change, an operator reset and a
  personal-access-token use.

**Findings (ADR 0056 §17):** case-sensitive login and the raw address in
failed-login audits are **fixed**; global session revocation is **fixed**.
**Recorded debt:** a signed-in password change UI (must call
`CredentialChangeService`), self-service lost-MFA recovery, and production
staff/School-admin account provisioning.

**Deployment evidence outstanding (ADR 0056 §20):**
- production critical email under ADR 0055 (O13 evidence);
- a deliberate `ACCOUNT_RECOVERY_ENABLED=true` on the real platform origin;
- a non-production drill (success, expiry, replay, concurrency, two-host
  sign-out, MFA still required);
- routed alerts and an exercised operator runbook.

- **O14:** repository implementation **COMPLETE**; deployment evidence
  **OUTSTANDING**.
- **O13 legal retention:** `MAIL_RETENTION_DAYS` remains
  **[LEGAL REVIEW REQUIRED]**.
- Decisions **O1, O2, O15** remain open.
- Phase 0O: **PARTIALLY READY — SOME CHECKPOINTS MAY START**.
- Phase 0M: **BLOCKED — LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS
  REQUIRED**.
- The **real restore drill is still outstanding**.

## 37. Phase 0O.10A — VERIFIED digests (2026-09-28)

Final qualification of the published `main` commit **`361c4b7`**
(`361c4b76cadb`, run `local-20260928T075015Z-83104eec`; Grype database
refreshed for the run). The first qualification of `90f2a37` stopped at its
Pint gate (a test added after the last local Pint run); the one-line style
fix was published as `9ece2d8` / `361c4b7` and qualified from scratch.

- **Same-run complete regression:** 6,375 tests, 0 failures, only the
  deliberate ESI-12 skip (55 new tests since 0O.9A).
- **Gates:** Pint, Larastan, vue-tsc, ESLint, Prettier, the frontend build,
  Gateway ruff/format/mypy/pytest and the release tooling tests all pass.
- **`verify-images.sh`:** 133/133 checks.
- **Language audits:** 0 advisories. **Secret scans:** 0 findings (source
  and both images).

| Image | Manifest digest | Config digest | `verify-artifact` |
|---|---|---|---|
| Application | `sha256:d2a2d0376189ccd7959d7a998e543158e6691f4c084c9f2d60f722d980882bd9` | `sha256:996d8ac0…7dff` | **VERIFIED**: `exception_conditions_proven`, 0 blocking, 48 excepted |
| AI Gateway | `sha256:67ec20ad2e5925fa474f1d9be11f236af7881939fa122d707d01846d070bc0ae` | `sha256:bab95799…4f2b` | **VERIFIED**: `exception_conditions_proven`, 0 blocking, 49 excepted |

- **Residual findings:** both sets are the reviewed `OWNER-0O6E-2026-09-26`
  records (the same counts as 0O.9A). Account recovery adds no dependency.
- **Evidence bundle:** sha256
  `23fba74c7cf86330d118d7909abe51f10215045fd8a78293cc9c19cc6fc8e00c`,
  signed with an ephemeral **non-production** key.
- **PUBLISHED = NONE, PROMOTED = NONE.** No real provider, no production
  recovery enablement, no real email or DNS change.
- **Exception clock:** the records expire **2026-10-10** / **2026-10-26**;
  nothing was renewed.
- **Full-regression checkpoint:** `361c4b7` (cadence counter reset).

## 38. Phase 0O.11 — Broader Third-Party Integrations & Payment Gateway Scope Contract (2026-09-28)

**O2 and O15 are RESOLVED by ADR 0057**
(`docs/architecture/adr/0057-broader-integrations-payment-gateway-scope-contract.md`).
This checkpoint is documentation only: no code, route, credential or
provider.

**Audit (read-only, `origin/main` `48f4f3d`):**
- **Payments.** The Phase 0G foundation is internal only. The trusted,
  idempotent, INR-only `PaymentProviderEventService::recordSettlement()` has
  no route, no adapter and no signature verification, and its only
  non-test caller is the DDEV demo seeder.
- **No real money movement exists:** no processor, checkout, card or bank
  data, token, callback, refund, reconciliation or provider selection.
- **No human can record any payment**, not even cash:
  `finance.payments.manage` was deliberately never created.
- **Integrations.** Email and storage are implemented; outbound webhooks are
  implemented; the partner API is foundation only (empty production
  catalog). SMS, WhatsApp and push are local/testing fakes. Government,
  board and accounting systems are named only. SSO is absent; LMS
  interoperability is cancelled.

**Decisions:**
- **O2:** the first real payment gateway is **deferred** from Phase 0 /
  production v1. A future gateway needs its own ADR: provider, merchant
  scope, jurisdiction, PCI-DSS, hosted versus embedded checkout,
  idempotency, callback authentication, refunds and disputes,
  reconciliation, fees, suspended-School behaviour, evidence.
- **Manual/offline payment recording** is **required for v1**, as a Finance
  correction. It uses the existing immutable settlement, allocation and
  ledger model; a dedicated capability; a closed method catalog;
  `recorded_by`; `occurred_at` versus `recorded_at`; audit; duplicate
  prevention; append-only corrections. It never collects card or bank
  credentials. Implementation: **Phase 0O.11A**.
- **Refunds, voids, chargebacks and payment reversal** are not introduced.
  Only internal ledger reversal exists. There is no provider
  reconciliation.
- **O15** covers the ADR 0018 categories **and** production partner scopes:
  - email: IN V1 (O13);
  - payment gateway: DEFERRED (O2);
  - SMS, WhatsApp, push: DEFERRED;
  - government/board (UDISE+, DigiLocker, APAAR): DEFERRED; the statutory
    payroll exports are not integrations;
  - Tally/accounting and other school software: DEFERRED;
  - SSO: NOT IN V1;
  - LMS interoperability: CANCELLED;
  - **production partner scopes: none**. Issuance stays refused, with no
    partner writes, no partner webhooks and no `api_client` idempotency
    actor.
- **No generic provider relay.** Each future provider defines its own
  authentication and reuses the established patterns.
- **Credentials.** No new provider credential. The future merchant-account
  scope (platform, per School or other) is **undecided**.
- **Jurisdiction.** INR and Indian statutory/system references are facts.
  Merchant jurisdiction, business entity and payment tax (GST) treatment are
  not frozen, so provider selection is blocked.
- **PCI-DSS** stays **[LEGAL/COMPLIANCE REVIEW REQUIRED]** for a real
  gateway.

**Finding:** `platform.webhook_test.v1` can be subscribed to in production
(only its emitting route is local/testing). It is inert and a low-severity
implementation debt, to be environment-gated by a later executable webhook
change. The documentation is corrected.

- **O1 contribution:**
  - in v1: the ledger and Charges, manual/offline payment recording, email,
    and the surfaces completed elsewhere;
  - not required for v1: an online gateway, SMS, WhatsApp, push,
    government and accounting integration, partner scopes, SSO and LMS
    interoperability.
- **Remaining open decisions: O1 only.** Next: **Phase 0O.11A — Manual /
  Offline Payment Recording Foundation** (not started).
- **O13 legal retention:** `MAIL_RETENTION_DAYS` remains
  **[LEGAL REVIEW REQUIRED]**.
- **Exception clock:** records expire **2026-10-10** / **2026-10-26**;
  nothing is renewed.
- Phase 0O: **PARTIALLY READY — O1 CLOSEOUT STILL BLOCKED**.
- Phase 0M: **BLOCKED — LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS
  REQUIRED**.
- The **real restore drill is still outstanding**.

## 39. Phase 0O.11A — Manual / Offline Payment Recording Foundation (COMPLETE — repository, 2026-09-28)

ADR 0057 §3's required v1 Finance correction is **implemented in the
repository**. The design is ADR 0031's "Implementation amendment — manual /
offline settlement recording", the runbook is
`docs/operations/MANUAL-PAYMENT-RECORDING.md` and the rule is CLAUDE.md rule
91.

- **What it is.** An authorized School Finance user records a payment the
  School **already received outside Lycenza** (cash, bank transfer, cheque).
  Lycenza records the financial fact. It moves no money.
- **What it is not:** a gateway, checkout, card or bank credential
  collection, refund, void, payment reversal, reconciliation, unapplied
  cash or multi-currency.

**Owner decisions (2026-09-28):**
- **Posted manual Payments are immutable, with no correction action in v1.**
  ADR 0057 §3 requires an append-only correction mechanism that a later
  contract defines, and none exists. The correction contract is an **open
  Finance follow-up**.
- **Payment-owned journal entries can no longer be reversed through the
  generic ledger reversal.** Before this unit, `finance.ledger.reverse`
  could detach a Payment from its settlement entry.

**Built:**
- **Two ingresses, one core.** `SettledPaymentRecorder` is the shared
  settled-payment core. It was extracted unchanged from
  `PaymentProviderEventService`, whose behaviour and tests are preserved.
  `ManualPaymentRecordingService` is the authorized human ingress.
  - It never creates a `payment_provider_events` row.
  - `payments_source_shape_check` makes provider and manual provenance
    mutually exclusive.
- **Schema** (`2026_10_27_090000`): `payments` gains
  - `source` (`provider` | `manual`);
  - `method`, a closed catalog: `cash`, `bank_transfer`, `cheque`;
  - `manual_reference` (optional, 64 characters, narrow format);
  - `recorded_by_user_id`;
  - `idempotency_key` (unique per School);
  - `journal_entries_payment_reversal_guard`.

  `occurred_at` is `settled_at`: the start of the School-local day, never
  in the future. `recorded_at` is `created_at`: server-set, append-only.
- **Authorization.** `finance.payments.record`, granted to School Admin
  only, re-checked in the service.
  - Group and platform authority never reach it; an elevated session gets
    403.
  - A suspended School is refused, via `SchoolOperationalGuard` inside the
    transaction.
- **Duplicates.** A server-issued form key, claimed on the Payment itself,
  with a transaction advisory lock:
  - the same key, User and content → replay;
  - anything else → fail closed.

  No uniqueness on the reference. Real-world duplicate detection for cash
  is not claimed.
- **Ledger and events.** The same ledger posting as the provider path:
  debit a School-chosen **active asset** account, credit each Charge's
  receivable account. Also:
  - the audit event `payment.recorded_manually`, with minimal metadata;
  - `payment.settled.v1` unchanged, not webhook-registered.
- **Transport.** Browser routes only: `GET/POST /app/finance/payments/record`
  and a Student search. They use CSRF and the `finance-payment-recording`
  limiter (30 per minute per User). There is no `/api/v1` write.
- **UI.** Student → charges (one payment may cover several) → details → a
  confirmation summary → the immutable Payment, with a one-time notice.
  - The Payments list and detail show the source (provider or offline).
  - The API exposes the provenance (`source`, `method`, `manualReference`,
    `recordedByUserId`, `recordedAt`) but never the key.
- **Demo.** Offline demo fees are now real manual Payments. The earlier
  demo used a fake `demo-offline` provider. Four `demo-provider` examples
  remain.
- **Tests.**
  - Service, UI and authorization tests (allow and deny across the real
    role catalog, Group and elevation).
  - Raw PostgreSQL schema, RLS and immutability tests.
  - Atomicity under failure injection.
  - Real two-process concurrency: duplicate key, conflicting key,
    over-allocation, opposite multi-charge order, cancel races and suspend
    races.

**Not changed (existing debt, not opportunistically fixed):**
- the email webhook reads a chunked body before its own 256 KiB check;
- the production subscription residue of `platform.webhook_test.v1`;
- the partner-write idempotency actor is absent;
- `TRUSTED_PROXIES` dependence.

Also recorded: other subledger-owned journal entries (Charge recognition,
payroll, canteen) remain reversible through the generic action. A GET of a
non-UUID `/app/finance/payments/{id}` was a PostgreSQL error before this
unit and is now a 404.

- **O2:** RESOLVED — REAL PAYMENT GATEWAY DEFERRED (unchanged).
- **O15:** RESOLVED (unchanged).
- **Manual/offline recording:** production-v1 Finance capability
  **COMPLETE (repository)**.
- **Remaining open decisions: O1 only.**
- **O13 legal retention:** `MAIL_RETENTION_DAYS` remains
  **[LEGAL REVIEW REQUIRED]**.
- **Deployment evidence still outstanding:**
  - O5 (keys and rings);
  - O9 (edge, domain and TLS);
  - O13 (provider, DNS authentication, drills, retention);
  - O14 (enablement, drills);
  - O16 (registry, signing, publish, promote);
  - the **real restore drill**.
- Phase 0O: **PARTIALLY READY — O1 CLOSEOUT STILL BLOCKED**.
- Phase 0M: **BLOCKED — LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS
  REQUIRED**.

## 40. Phase 0O.11A — VERIFIED digests (2026-09-28)

Final qualification of the published `main` commit **`c4b1b6c`**
(`c4b1b6c094ea`, run `local-20260928T193902Z-8df07380`; Grype database
refreshed for the run). Three earlier attempts did not reach VERIFIED:

- **`04e0392`:** the tag-only `verify-images.sh` S3 helper
  (`minio/minio:RELEASE.2025-04-08T15-41-24Z`) could no longer be pulled from
  any registry. The helpers were repinned by digest (`versity/versitygw`,
  `redis:7.4.11-alpine`) in `fc64a29` / `7c32f56`, with a
  `SupplyChainGuardTest` rule that every helper is `image:version@sha256`.
- **`7c32f56`, first run:** the build stage stopped with
  `loaded_image_mismatch`. This was a host change, not a repository defect:
  Docker Desktop had restarted on the containerd image store, where
  `docker load` reports the manifest digest, not the config digest.
  **Qualification needs Docker's classic image store** until the tooling
  supports the containerd store (follow-up, not done here).
- **`7c32f56`, resumed on the classic store:** one `verify-images.sh` check,
  "worker-default role stays running", failed deterministically. It was a
  harness race: the unreachable database makes a worker exit after its 5 s
  connect timeout, and the check sampled after that. The four role
  containers now run with `DB_CONNECT_TIMEOUT=30` (`c4b1b6c`). The images
  themselves were unchanged. Both images failed only because the
  runtime-hardening-conditional exceptions were withdrawn; there were no new
  advisories.

Results on `c4b1b6c`:

- **Same-run complete regression:** 6,445 tests, 0 failures, only the
  deliberate ESI-12 skip.
- **Gates:** Pint, Larastan, vue-tsc, ESLint, Prettier, the frontend build,
  Gateway ruff/format/mypy/pytest and the release tooling tests all pass.
- **`verify-images.sh`:** 133/133 checks.
- **Language audits:** 0 advisories. **Secret scans:** 0 findings (source
  and both images).

| Image | Manifest digest | Config digest | `verify-artifact` |
|---|---|---|---|
| Application | `sha256:b7638488850a1dc511408850726b67c0b8be826d007a2b3c3dc79368a1eff804` | `sha256:5d6ca334…9dec` | **VERIFIED**: `exception_conditions_proven`, 0 blocking, 48 excepted |
| AI Gateway | `sha256:f46ef81166502864eeea0136a3cc3607edfcaa89d8a47a4d48a7898365f73905` | `sha256:68b71f7f…f5f` | **VERIFIED**: `exception_conditions_proven`, 0 blocking, 49 excepted |

- **Residual findings:** both sets are the reviewed `OWNER-0O6E-2026-09-26`
  records (the same counts as 0O.10A). Manual payment recording adds no
  dependency.
- **Evidence bundle:** sha256
  `550a6c13f94686cdfe667a113f262446146fddfdc8c8c6f70275bbf2bf85c154`,
  signed with an ephemeral **non-production** key.
- **PUBLISHED = NONE, PROMOTED = NONE.** No payment provider, merchant
  account, real integration, registry push or deployment.
- **Exception clock:** the records expire **2026-10-10** / **2026-10-26**;
  nothing was renewed.
- **Full-regression checkpoint:** `c4b1b6c` (cadence counter 0/5).

## 41. Phase 0O.12 — Production Readiness Definition & Closeout Contract (O1, 2026-09-28)

**ADR 0058** resolves O1 **as a definition of done**. It supersedes the §9
matrix and gathers every ADR's "contribution to O1" into one evidence
register (E01–E29). Documentation only: no code, configuration, workflow,
GitHub setting or infrastructure changed.

**What "Phase 0O complete" means.** The repository and readiness evidence
are complete enough to **authorize a separate production deployment
decision**. It never means production is live, real School data is
connected, optional features are on, or Phase 0M is unblocked. Go-live stays
a separate rule-16 authorization.

**Findings (each verified on 2026-09-28):**
- **`main` is not protected.** The GitHub API reports
  `"protected": false`, no rulesets and no rules for `main`. The release
  workflow and `verify-artifact` prove ancestry of `main`, never protection.
  Protection must be real, and a fresh qualification after it is active is
  required before the first promotion (ADR 0058 §4.6).
- **A fresh production install cannot create its first School.** The only
  production User factories are the first root and Guardian activation. A
  School needs an existing, non-operator bootstrap administrator, and
  activation needs a qualifying one. No path provisions staff or
  School-admin logins. This is now an **O1 blocker** (ADR 0058 §4.14).
- **Email is provider-neutral only.** There is no vendor event adapter, so
  the provider-event route answers 404. A bounded provider tail is mandatory
  once a provider is selected (ADR 0058 §4.11).
- **`TRUSTED_PROXIES` may be empty in production.** The guard refuses only
  trust-all. Real edge addresses are deployment evidence (§4.2).

**Owner decisions frozen by ADR 0058:**
- **Retention, option A.** Legal retention decisions for the categories
  used in v1 (mail, webhook deliveries, Documents, audit and others) are
  **mandatory before Phase 0O closeout**. `MAIL_RETENTION_DAYS` stays
  **[LEGAL REVIEW REQUIRED]**.
- **AI Gateway: not required for O1.** It stays not configured. If it is
  ever enabled, all of ADR 0053 §15 comes first.
- **Custom domains: not required in production.** Production may launch
  with `CUSTOM_DOMAINS_ENABLED=false`. One real **non-production** O9
  exercise is still mandatory, because O14's two-host drill needs it.
- **Email is mandatory for v1.** `MAIL_PROVIDER=none` or fake delivery
  cannot close O1.
- **Staff/School-admin provisioning is an O1 blocker.** Employee is not
  User, and Guardian activation is never a workaround.
- **Manual-payment correction stays recorded debt,** not a blocker.
- **The `c4b1b6c` artifacts are not indefinitely promotable.** Their
  exceptions expire 2026-10-10 / 2026-10-26, with no automatic renewal.

**Corrections recorded here (history above is not rewritten):**
- **V1–V5 are resolved.** The §8 rows O7 and O11 now say "approved and
  frozen 2026-09-25". §15's "owner values still required" is the historical
  0O.2 record. §16's "trusted-proxy configuration remains with O3" was
  resolved by ADR 0050 §2 and 0O.4A (`TrustedProxyList`).
- **§9 is superseded** (a note under its heading).
- **Operations README.** The "release qualification" row still named the
  0O.6F qualification (`93b72e5`). The current one is §40 (`c4b1b6c`).

**Mandatory blockers** (ADR 0058 §6):
- E02 — final qualification on protected `main`;
- E03 — `main` protection;
- E05 — S1 edge evidence;
- E07 — S2 backend and routing;
- E08 — secret store;
- E09 — ADR 0050 environment;
- E10 — backup policy;
- E11 — **real restore drill**;
- E12 — Redis reconciliation and window release;
- E13–E15 — registry, signing and promotion;
- E16 — exception validity;
- E17–E20 — email provider, adapter tail, DNS authentication, drills;
- E21 — **legal retention**;
- E22 — O9 non-production exercise;
- E23 — O14 drills;
- E24 — **staff/School-admin provisioning**;
- E29 — evidence hygiene.

**Conditional (disabled):**
- E26 — AI Gateway;
- E27 — production custom domains;
- E28 — deferred integrations.

**Status:**
- **O1:** RESOLVED AS DEFINITION OF DONE — NOT SATISFIED.
- **O2–O16:** resolved (unchanged).
- **Phase 0O:** **CLOSEOUT BLOCKED — DEPLOYMENT / LEGAL / GOVERNANCE /
  PROVISIONING EVIDENCE OUTSTANDING**.
- **Phase 0M:** **BLOCKED — LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS
  REQUIRED**.
- **Regression:** checkpoint `c4b1b6c`; this docs-only unit is #1 since it.

**Next (not started):**
1. **Phase 0O.12A — Staff / School-Admin Account Provisioning**: contract
   first, recovered from the Identity/HR boundaries, then implementation.
2. In parallel, outside the repository, the owner can begin the provider
   and infrastructure decisions for the evidence program and activate
   `main` protection.

## 42. Phase 0O.12A — Staff / School-Admin Account Provisioning Contract (2026-09-28)

**ADR 0059** makes the **decision** for ADR 0058 row **E24**. Documentation
only: no code, route, configuration or GitHub setting changed. E24 still
blocks O1 until **Phase 0O.12B** is built and qualified.

**Before-fix audit (verified in code):**
- Production creates Users only through `platform:bootstrap-root` (the
  first root) and Guardian invitation acceptance (active Schools only).
- `provision-root`, platform role grants, the operator password reset and
  the School bootstrap service all act on **existing** Users only.
- `EmployeeService` never creates Users.
- The deadlock:
  1. creating a School needs an existing, non-self, enabled bootstrap
     administrator;
  2. the only ordinary-User factory needs an active School;
  3. activation needs an administrator.

**Decisions:**
- **Flow A — platform-assisted bootstrap account.**
  - An interactive operator console command (no `--force`, typed-email
    confirmation) creates a **credential-less** User.
  - It displays a one-time activation link **once** (fragment secret,
    ≤ 72 h, default 24 h). No email is needed, so it works before O13.
  - No membership, role, Employee or platform grant. School authority
    still comes only from root's ADR 0047 create/replace path.
- **ADR 0047 amendment.** A qualifying administrator must have an
  established credential (`admin_not_activated`). MFA is still not
  required for activation.
- **Flow B — School staff invitations.**
  - Issuing needs `school.members.manage` + `school.roles.manage` (both
    existing, `school_admin` only), fresh MFA and critical email (refused
    with `email_unavailable` otherwise).
  - Roles come from the closed School-scope catalog, within the issuer's
    own capabilities.
  - Each invitation is School-owned (RLS), bound to one canonical email,
    one pending per email, 7 days.
  - Acceptance on the School origin creates an `active` membership and the
    roles. A new User sets a password through `CredentialChangeService`
    with no auto-login; an existing User must be signed in.
  - Disabled Users, platform-role holders and existing members of that
    School are refused generically.
- **Anti-enumeration.** A School learns only its own members and
  invitations. Every other identity state gives the same accepted result.
- **Unchanged:** elevation (rule 83), Group authority (rule 84), ADR 0047
  D13, User ≠ Employee.
- **Credential-less Users** cannot sign in, are not recovery-eligible, and
  are refused by `platform:user-password-reset`.

**New O1 finding: staff off-boarding is DECISION REQUIRED.** No School-side
action suspends a staff membership or revokes a School role. The owner
decides whether it joins 0O.12B or becomes its own ADR 0058 row.

**Status:**
- **E24:** decision made; implementation (0O.12B) outstanding; still
  blocking.
- **O1:** RESOLVED AS DEFINITION OF DONE — NOT SATISFIED.
- **Phase 0O:** CLOSEOUT BLOCKED — DEPLOYMENT / LEGAL / GOVERNANCE /
  PROVISIONING EVIDENCE OUTSTANDING.
- **Phase 0M:** BLOCKED — LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS
  REQUIRED.
- **Regression:** checkpoint `c4b1b6c`; this docs-only unit is #2 since it.

**Next (not started):** **Phase 0O.12B — Staff / School-Admin Account
Provisioning Foundation**, one checkpoint (ADR 0059 §23), once the owner
has decided whether it includes staff off-boarding.

## 43. Phase 0O.12B — Staff / School-Admin Account Provisioning Foundation (2026-09-28)

ADR 0059 is implemented, plus the owner's off-boarding amendment. E24 now
covers the whole production staff-account lifecycle; there is no separate
register row. Executable unit: migrations, services, routes, UI and
tests.

**Before the fix (verified at `e65927c`):**
- no School route or service suspended, reactivated or revoked staff
  access;
- the only membership-status and role-grant writer was the platform
  bootstrap service, and only while a School was `provisioning`;
- `membership_role_assignments` had no revocation history (hard delete);
- `CapabilityResolver` already required an ACTIVE membership, and
  `AUTHORIZATION.md` already gave add/remove and grant/revoke to the School
  after activation.

**Built (ADR 0059 owner and implementation amendment):**
- **Flow A.** `platform:provision-school-admin-account` creates a
  credential-less User (password NULL, never a placeholder) and shows its
  activation link once. Activation happens at
  `/account-activation/{selector}` on the platform host. School activation
  refuses a credential-less administrator (`admin_not_activated`).
- **Flow B.** Settings → Staff accounts: invite, resend and revoke (fresh
  MFA, critical email, closed School role catalog within the issuer's
  capabilities). Acceptance at `/invitations/{school}/staff/{selector}`
  handles a new User (`CredentialChangeService`, no auto-login) or an
  existing User who is signed in.
- **Off-boarding and roles:**
  - suspend (membership `suspended`, all grants revoked as history);
  - explicit reactivation with newly chosen roles;
  - grant and revoke one role, with a re-grant as a new row;
  - no self-administration;
  - a concurrency-safe last-qualifying-administrator invariant (per-School
    advisory lock, evaluated after the change);
  - no global session or token revocation; Employee untouched.
- **Role-grant history.** Revoked rows are immutable, one active grant per
  membership and role, and the runtime role cannot DELETE.
- **Technical cleanup.** `platform:staff-account-credentials-prune`,
  hourly.

**Proof:**
- the fresh-install scenario through the real operator console;
- real-PostgreSQL races, including two administrators removing each other;
- raw-SQL invariants and RLS;
- authorization allow/deny, sessions, human PAT, and the platform/Group
  boundary;
- a DDEV smoke (16 checks) and a browser review of the three new pages
  (fragment capture, no CSP violations).

**Status:**
- **E24:** implemented. It becomes REPOSITORY_COMPLETE with this unit's
  full regression and O16 qualification (recorded in the follow-up
  section).
- **O1:** RESOLVED AS DEFINITION OF DONE — NOT SATISFIED.
- **Phase 0O:** CLOSEOUT BLOCKED — the remaining deployment, legal,
  governance and provider evidence.
- **Phase 0M:** BLOCKED — LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS
  REQUIRED.

## 44. Phase 0O.12B — VERIFIED digests; E24 REPOSITORY_COMPLETE (2026-09-29)

Final O16 qualification of the published `main` commit **`e52c4c4`**
(`e52c4c4c0be88f9b3cbba2684fce78c667727218`).

**The run:**
- **Run id:** `local-20260929T002558Z-08871c71`.
- **Timing:** started 2026-09-29T00:25:58Z; both images VERIFIED at
  2026-09-29T00:53:31Z.
- **Host:** Docker on the **classic** image store (overlay2, no containerd
  snapshotter; §40).
- **Scanner:** the Grype database was refreshed for the run.
- **No repository change** was made for or during the run. The tree was
  clean and HEAD was fixed throughout.

Results on `e52c4c4`:

- **Lock integrity:** the Composer, npm and Python (hash-locked,
  `pip-compile` byte-for-byte) locks are in sync.
- **Same-run complete regression:** 6,500 tests, 151,403 assertions,
  0 failures. The only skip is the deliberate ESI-12, the suite's single
  skip site. It ran through `SAFE_TEST_ISOLATED=1 apps/platform/bin/safe-test
  --reset-db` on real PostgreSQL, Redis and MinIO. The count matches the
  pre-publication run.
- **Gates:** all pass:
  - Pint, Larastan (0 errors), vue-tsc, ESLint, Prettier and the frontend
    production build;
  - Gateway ruff, ruff format, mypy and pytest (153 passed);
  - the release tooling tests (79).
- **`verify-images.sh`:** 133/133 checks. The runtime security contract,
  role/process checks, custom PHP runtime, service authentication, secret
  checks, digest-pinned helpers and the 30 s role-container DB timeout
  (`c4b1b6c`) all hold.
- **Language audits:** 0 advisories (Composer, npm, pip-audit). Nothing
  was changed or auto-fixed.
- **Secret scans:** 0 findings in source, and 0 in each image
  (filesystem, config, history and canaries).

| Image | Manifest digest | Config digest | `verify-artifact` |
|---|---|---|---|
| Application | `sha256:d03a3e4dd3400f89ea4ed98f94cfe27ecd2b937000dae001e47adfcd80159729` | `sha256:cd9a0d636a57a9054a2eb74cb625974d8ac5fd324924c268a680fe57d5bb4820` | **VERIFIED**: `exception_conditions_proven`, 0 blocking, 48 excepted |
| AI Gateway | `sha256:db5ae74d5a227fd1432d288cab525e3b8a427995207242672e5386de3524e502` | `sha256:6cf56cca3756245816262a708ccdcc8f614801a885d2f680e0164f3ebb4729cd` | **VERIFIED**: `exception_conditions_proven`, 0 blocking, 49 excepted |

- **SBOM (SPDX 2.3) sha256:**
  - app: `b0a9c258cdfc7eb7f8a32e7feec4d8713ab55c515922d2647c5399ac8a37f12c`;
  - Gateway: `839b73c8c5dcb9e04b7896d53cf8dbecb6c4e9e9e88b60c4c0bb9522f7476406`.
- **Scan matches:** app 184, Gateway 156.
- **Residual findings:** both sets are the reviewed `OWNER-0O6E-2026-09-26`
  records, the same counts as §40. Staff provisioning adds no dependency.
- **Evidence bundle:** sha256
  `c96803e05efac9da0fff2d9e3e6109cb3ed405b02572aceb076fc57a4544c580`,
  signed with an ephemeral **non-production** key. The private key was
  destroyed; only its public half is kept.
- **Evidence custody:** kept outside the repository. No key, token,
  credential or raw scan report is committed.
- **PUBLISHED = NONE, PROMOTED = NONE.** No registry push, production
  signing identity, production secret or deployment.
- **Exception clock:** checked on 2026-09-29. All 97 records are valid:
  54 expire **2026-10-10** and 43 expire **2026-10-26**. Nothing was
  renewed.

**E24 → REPOSITORY_COMPLETE.** This is the dated register move required by
ADR 0058 §6. It is recorded in the ADR 0058 note of 2026-09-29 and the ADR
0059 implementation amendment. Phase 0O.12B provides:
- first-School bootstrap account provisioning;
- ordinary staff invitations;
- credential activation;
- School membership and role creation;
- role-grant history;
- staff off-boarding by membership suspension;
- explicit reactivation;
- the last-qualifying-administrator invariant;
- tenant/RLS enforcement;
- the fresh-install proof and concurrency proof (§43).

**Status:**
- **Phase 0O.12B:** **COMPLETE**.
- **E24:** **REPOSITORY_COMPLETE**.
- **O1:** **RESOLVED AS DEFINITION OF DONE — NOT SATISFIED**.
- **Phase 0O:** **CLOSEOUT BLOCKED — DEPLOYMENT / LEGAL / GOVERNANCE /
  PROVIDER EVIDENCE OUTSTANDING**. The mandatory blockers are E02, E03,
  E05, E07–E23 and E29.
- **Phase 0M:** **BLOCKED — LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS
  REQUIRED**.
- **The `e52c4c4` digests are not the release to promote.** `main` is
  still unprotected (E03), and E02 needs a fresh qualification after
  protection and the email provider tail.
- **Full-regression checkpoint:** `e52c4c4` (cadence counter **0/5**).

**Next (not started):** a fresh ADR 0058 O1 evidence-register review. It
separates:
- repository work;
- owner/provider choices;
- governance changes;
- legal decisions;
- non-production deployment evidence.

## 45. Phase 0O.13 — Transactional Email Provider Selection & Integration Contract (E17, 2026-09-29)

Documentation only (ADR 0060). Baseline `origin/main` `4daa955`; the
executable checkpoint stays `e52c4c4`.

**Selection:**
- **Evaluated against official documentation:** Amazon SES, Postmark,
  Twilio SendGrid and Mailgun.
- **Rejected:** Postmark. Its DKIM is 1024-bit and its webhooks carry no
  signature.
- **Selected:** **Twilio SendGrid**, code name `sendgrid`:
  - HTTPS v3 Mail Send API with a restricted Mail-Send-only key;
  - Signed Event Webhook: ECDSA/SHA-256 over timestamp + raw body, with an
    operator-configured public key and a ±300 s window;
  - `sg_event_id` deduplication (hashed into `event_key`);
  - `X-Message-Id` → `sg_message_id` prefix linkage;
  - 24 h provider retries;
  - automated-security DKIM (a new custom selector, 2048-bit) and a custom
    return path;
  - all tracking off, account-wide and per message.
- **Fallback:** Amazon SES, through a new amendment.
- **Not decided here:** Lycenza's jurisdiction is not inferred.

**Contract changes:**
- **ADR 0055 §11.2** gains per-adapter event bounds: `sendgrid` allows
  1 MiB and 5,000 events, because SendGrid batches up to ~768 KB. One
  malformed element never rejects a batch.
- **Idempotency:** none from SendGrid, so at-least-once stands.
- **Suppression:** two independent lists. Lycenza's table stays
  authoritative, and a release also clears the SendGrid list (an operator
  step).
- **Sandbox gates:** nine NOT VERIFIED facts must be proven in the
  non-production account before E18 merges (ADR 0060 §21).

**Legal/processor review: OUTSTANDING.**
- **E17:** PROVIDER_REQUIRED → **LEGAL_REVIEW_REQUIRED**.
- **E18:** blocked on E17's review. Its scope is frozen as **Phase 0O.13A —
  SendGrid Transactional Email Adapter** (ADR 0060 §22), and it does not
  start.

**ADR 0058 corrections:**
- **E02** now requires E03, E18, every other mandatory executable change,
  and E16 valid on the date.
- **§4.1** cross-references now point to §4.11 and §4.14.
- **Status pointer** added for the out-of-date body sections.

**Drift corrected:**
- `MAINTENANCE-WINDOW-RELEASE.md`: the retired
  `platform:service-identity-issue` becomes `platform:service-key-generate`,
  Gateway only.
- ADR 0050: a `SERVICE_TOKEN` correction note.
- `CUSTOM-DOMAINS.md`: the routing/TLS probe exercise item.
- `TELEMETRY-COLLECTION.md`: the scrape job must be named
  `lycenza-metrics`.
- New `OPERATOR-EVIDENCE-RECORD.md` template (E29).
- **Deferred:** the stale `ExportAlertRules` description and
  `ApplySecurityHeaders` docblock are executable files. They are left for an
  executable checkpoint.

**Timing:**
- **E19** needs ≥ 14 days of 100 % aligned DKIM pass. It can start before
  or during E18, but only on the owner's rule-16 authorization.
- **E16:** the exceptions expire 2026-10-10 and 2026-10-26. The last
  passing dates are 2026-10-09 and 2026-10-25, so E02 will follow both.
  A fresh scan, then a refresh or a fresh owner/security decision, is
  required. Nothing is renewed.

**E03 CAN AND SHOULD BE COMPLETED NOW.** Put a real branch protection rule
or ruleset on `main` that refuses force-pushes and deletion and routes
changes through the controlled integration path. Then run all future work
through protected `main`. `main` was unprotected on 2026-09-29 (public
API: `protected: false`, no rulesets).

**Status:**
- **O1:** RESOLVED AS DEFINITION OF DONE — NOT SATISFIED.
- **Phase 0O:** CLOSEOUT BLOCKED — DEPLOYMENT / LEGAL / GOVERNANCE /
  PROVIDER EVIDENCE OUTSTANDING.
- **Phase 0M:** BLOCKED — LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS
  REQUIRED.
- **Regression:** checkpoint `e52c4c4`. This docs-only unit is #1 since it
  (counter **1/5** once published).

**Next (not started):** the qualified legal/processor review of SendGrid,
recorded as an ADR 0060 amendment. Only then does Phase 0O.13A start.

## 46. Phase Zero scope closure — Phase 0H / Phase 0M post-v1 deferrals (ADR 0061, 2026-09-29)

Documentation only. Baseline `origin/main` `b305b26`; the executable
checkpoint stays `e52c4c4`.

**Owner decisions (product scope, not completion or legal clearance):**
- **Phase 0H:** **CLOSED FOR PHASE ZERO — REMAINING SCOPE DEFERRED
  POST-v1.**
  - Delivered as built: Timetable, Student class Attendance, Syllabus,
    Curriculum Delivery, Examination Foundation, ExaminationPaper/Scheduling,
    GradeScale/GradeBand, Staff MFA and the Student Processing Authorization
    Registry.
  - Deferred: Lesson Planning, 0H.4D-P3, StudentMark, results, report cards,
    transcripts and Student/Guardian-facing surfaces.
  - The StudentMark determination is unchanged and not widened.
- **Phase 0M:** **CLOSED FOR PHASE ZERO — REAL PROVIDERS / REAL AGENTS
  DEFERRED POST-v1.**
  - The fail-closed `NullProvider` state and all AI hardening stay.
  - The AI provider legal/compliance gate is **not** cleared. Its section 18
    decisions are the reopening gate.

**Current-status correction for this document.** Every earlier "Phase 0M:
BLOCKED — LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS REQUIRED" line (§11
onward, up to §45) stays true **for its date**. From 2026-09-29 the current
status is:

> **Phase 0M: CLOSED FOR PHASE ZERO — REAL PROVIDERS / REAL AGENTS DEFERRED
> POST-v1 BY OWNER DECISION ON 2026-09-29.**

The gate's own status (BLOCKED) is unchanged.

**Effect on Phase 0O:**
- **Only active area.** Phase 0O is now the **only active Phase Zero
  closeout area**.
- **Not reopened by 0O.** No 0H or 0M item is an O1 blocker, and 0O
  closeout never reopens 0H or 0M.
- **Unchanged:** E26 (AI Gateway in production) stays
  CONDITIONAL_DISABLED.

**E16 fresh-scan outcome (read-only audit, 2026-09-29):**
- **Scan.** The retained `e52c4c4` SBOMs were re-scanned with the newest
  published Grype database (built 2026-09-28 06:42Z). Both images PASS with
  0 blocking; the 48 + 49 High findings are identical to qualification.
- **Language audits:** 0 advisories.
- **No fix available:**
  - no newer version in Debian trixie, trixie-updates, trixie-security or
    trixie-proposed-updates;
  - the tracker marks them `no-dsa`, postponed or unfixed;
  - no newer `debian:13-slim` or `python:3.14-slim-trixie` digest exists.
  - So no legitimate refresh removes any of the 12 advisories.
- **Expiry is fail-closed** (`exceptions.py`: `expires <= today`), and one
  expired record invalidates the whole file. All 97 records therefore stop
  working on 2026-10-10; the last passing day is 2026-10-09.
- **E16: DECISION_REQUIRED.** A fresh owner/security decision is needed
  first. Nothing is renewed.

**Current Phase 0O statuses (carried forward):**

| Item | Status |
|---|---|
| E03 | GOVERNANCE_REQUIRED: `main` still unprotected (public API 2026-09-29: `protected: false`, 0 rulesets) |
| E16 | DECISION_REQUIRED (above) |
| E17 | LEGAL_REVIEW_REQUIRED: Twilio SendGrid selected (ADR 0060); processor/legal review outstanding |
| E18 | Blocked on E17 (Phase 0O.13A not started) |
| E21 | LEGAL_REVIEW_REQUIRED |
| O1 | RESOLVED AS DEFINITION OF DONE — NOT SATISFIED |
| Phase 0O | ACTIVE — CLOSEOUT BLOCKED (O1 evidence outstanding) |
| Phase Zero | **NOT COMPLETE** (Phase 0O/O1 remains) |

**Regression:** checkpoint `e52c4c4`. This docs-only unit is #2 since it
(counter **2/5** once published).
