# ADR 0050: Production Infrastructure, Secrets & Recovery Contract (Phase 0O.4)

- Status: Accepted (contract only — nothing implemented, provisioned or
  deployed; Phase 0O.4A implements the repository side).
- Date: 2026-09-25
- Resolves: Phase 0O decisions **O3** (hosting/deployment model), **O4**
  (production secret handling), **O6** (runtime PostgreSQL role name),
  **O8** (production object storage) and **O10** (backup, RPO/RTO,
  restore drills) (`docs/architecture/PHASE-0O-READINESS.md` §8).
- Relationship to other ADRs: builds on ADR 0016 (secrets/configuration),
  ADR 0021 (two database roles), ADR 0023 (AI context signing key), ADR
  0024 (real PostgreSQL), ADR 0046/0049 (Phase 0O.1A root boundary, 0O.3
  external surface) and the production release contract
  (`docs/architecture/PRODUCTION-RELEASE.md`). It does not touch O2, O5,
  O9, O12–O16 or Phase 0M.

## Context

The production release contract (Phase 0O.1) lists the processes but
leaves hosting, secrets, storage policy and recovery undecided. Every claim
below was re-verified on `79e0e30`.

### What exists

- **Infrastructure directory.** `infrastructure/terraform/` holds only a
  README ("empty by design"). `infrastructure/docker/platform.Dockerfile`
  and `ai.Dockerfile` are **local-development images** (their headers say
  so: `php artisan serve`, dev dependencies, root user).
  `docker-compose.yml` and `.ddev/` are local only; MinIO runs
  `minio/minio:latest` with local root credentials.
- **Processes** (`PRODUCTION-RELEASE.md` §1, re-verified): web; queue
  workers for `default`, `integrations`, `notifications`
  (`App\Support\Observability\QueueName`; the other three names carry no
  job); one scheduler; AI Gateway (`NullProvider`); PostgreSQL 16; Redis;
  S3-compatible storage. Scheduled commands (`routes/console.php`), all
  `withoutOverlapping()`: `platform:outbox-dispatch`,
  `platform:webhook-deliveries-redispatch`,
  `platform:communication-deliveries-redispatch`,
  `communications:publish-scheduled`, `automation:executions-redispatch`,
  `platform:expire-school-elevations` (every minute);
  `platform:idempotency-prune` (02:10), `platform:webhook-deliveries-prune`
  (02:20). No `onOneServer()`. `docker-compose.yml`'s worker still works
  only `default` (local-only gap); DDEV works all three queues.
- **Health.** `GET /api/health/live` (no dependency), `GET
  /api/health/ready` (PostgreSQL + Redis; 503 when not healthy; body
  `ok`/`degraded` only); the framework's `/up` also exists. The AI Gateway
  `/health/ready` returns 503 when unhealthy (Phase 0O.1).
- **Database roles.** `infrastructure/docker/postgres/init/01-roles.sql`
  creates `school_os_app` (LOGIN, NOSUPERUSER, NOCREATEDB, NOCREATEROLE,
  NOINHERIT, NOREPLICATION, NOBYPASSRLS) and grants it table/sequence
  privileges through `ALTER DEFAULT PRIVILEGES FOR ROLE school_os` — i.e.
  **the runtime role's privileges on every new table come from the
  migrating role's default privileges.** The name `school_os_app` is
  fixed in: `App\Support\Tenancy\TenantRls::makeAppendOnly()` /
  `revokeDelete()` defaults (31 + 13 migration calls), explicit `GRANT
  DELETE … TO school_os_app` in the `down()` of
  `2026_10_16_090100`, `2026_10_17_090100` and `2026_10_18_090000`,
  `01-roles.sql`, `03-test-database-roles.sql`, `.ddev/config.yaml`,
  `.ddev/commands/web/{demo-reset,test}`, `apps/platform/bin/safe-test`,
  `.github/workflows/ci.yml` and tests asserting the runtime role's
  attributes. RLS policies (`TenantRls::enable`, 148 calls) are not
  role-specific; the Phase 0O.1A out-of-band-grant boundary uses
  `pg_has_role(current_user, <table owner>, …)` and names no role.
- **Admin connection.** `config/database.php` defines `pgsql_admin` in
  every process; `DB_ADMIN_USERNAME`/`DB_ADMIN_PASSWORD` fall back to the
  runtime credentials when unset. At runtime only
  `PlatformRootProvisioningService` (operator console; the DDEV demo seed)
  opens it; migrations and `platform:test-db-reset` are operator commands.
- **Storage.** The `s3` disk (`AWS_*`), Documents disk `DOCUMENTS_DISK`
  (default `local`); keys `schools/{school_id}/…` via
  `TenantStoragePath`; downloads are streamed through the application
  (`DocumentController::content()`), never public or signed URLs; no
  visibility/ACL setting is used. Object deletes: compensating deletes of
  an orphaned upload when its metadata transaction fails (Documents,
  Communication attachments) and removal of a draft Communication
  attachment; Documents are archived by status, not deleted.
- **Redis** (`CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` = redis):
  cache (tenant-aware entries, 60 s capability cache), rate-limiter
  counters (incl. `api-auth-failure`), `TenantLock` locks, sessions (with
  MFA assurance and elevation pointers), queue payloads, heartbeats.
  `failed_jobs` is in PostgreSQL (`database-uuids`). Every queued job
  (`ProcessOutboxEventJob`, `DeliverWebhookJob`,
  `ProcessCommunicationDeliveryJob`, `RunAutomationExecutionJob`) carries
  only identifiers; the state lives in PostgreSQL.
- **Migrations.** 231 migrations; 24 contain column drops, renames or
  type changes, and many rewrite triggers or tighten constraints. There is
  no expand/contract policy.
- **Secrets today** (ADR 0016, `.env.example`): `APP_KEY` (also encrypts
  `webhook_endpoints.secret_encrypted` and TOTP secrets via the
  `encrypted` cast); runtime and admin DB passwords; `REDIS_PASSWORD`;
  `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`; `MAIL_*` (log driver, O13);
  `AI_GATEWAY_CONTEXT_SIGNING_KEY`; `AI_GATEWAY_SERVICE_TOKEN` + the
  Gateway's `SERVICE_TOKEN` (same value, hashed in `service_identities`);
  `CONTACT_LOOKUP_HMAC_KEY` and `STATUTORY_IDENTIFIER_LOOKUP_HMAC_KEY`
  (+ versions); per-endpoint webhook signing secrets and partner/human API
  credential hashes (database, not configuration).
- **Recovery.** Nothing exists — no backup, restore procedure, drill, RPO
  or RTO. Retention periods remain **[LEGAL REVIEW REQUIRED]**
  (`DATA-CLASSIFICATION.md`); no document sets a backup retention period,
  so a 35-day operational window contradicts nothing.

### Redis durability finding (audited before freezing O10)

Redis holds **no irreplaceable business record** — every job's state is in
PostgreSQL. But losing Redis is **not self-healing today**:

- `platform:outbox-dispatch` marks an outbox row `dispatched` before its
  job runs, and nothing re-claims a stale `dispatched` row;
- a new webhook delivery starts `pending` with no `next_attempt_at`, and
  `platform:webhook-deliveries-redispatch` only re-claims `retrying` (due)
  and lease-expired `delivering` rows;
- an immediate Communication delivery starts `pending`, and
  `platform:communication-deliveries-redispatch` only re-claims `queued`
  (with `next_attempt_at`) and lease-expired `sending` rows.

Automation executions do recover (created `pending` with a
`next_attempt_at` grace). So a Redis loss can leave events, webhooks and
notifications **stranded (not lost)** until someone re-queues them. This
does not trigger the "irreplaceable data" stop condition, but it makes a
PostgreSQL-driven reconciliation a **production prerequisite** (section
10).

## Decision

### 1. O3 — hosting and deployment model (owner decision)

A **provider-neutral, containerized, single-primary** production model:

- a TLS-terminating reverse proxy / load balancer — the only public entry;
- a stateless web/API application process (PHP-FPM behind an HTTP server,
  or equivalent — never `php artisan serve`);
- queue workers consuming every queue that carries jobs (section 3);
- exactly one scheduler mechanism (section 3);
- the AI Gateway on `NullProvider` (Phase 0M stays blocked);
- PostgreSQL 16, Redis, private S3-compatible object storage.

Application processes run from **immutable images** (section 12). No cloud
vendor, orchestrator or process manager (AWS/Azure/GCP, Kubernetes, ECS,
systemd, Nomad, cron…) is part of this contract; a deployment chooses one
later without changing the application.

### 2. Network, TLS and trusted proxies

- **Public:** only the web/API edge (HTTPS). **Never public:** PostgreSQL,
  Redis/the queue backend, the admin database connection, the
  object-storage administrative API, the AI Gateway (a private
  service-to-service network, both directions).
- **TLS** terminates at the trusted proxy/load balancer; HTTP is
  redirected there. Platform TLS is O3; School custom domains and their
  certificates remain **O9**.
- **Trusted proxies (implementation contract, 0O.4A):** one explicit
  configuration value (proposed `TRUSTED_PROXIES`, a comma-separated list
  of IPs/CIDRs, or a deployment-native equivalent rendered into such a
  list) passed to Laravel's `trustProxies(at: …)` with the
  `X-Forwarded-For/-Host/-Port/-Proto` headers. Empty means trust none.
  `*`, `**`, `0.0.0.0/0` and `::/0` are refused: the Phase 0O.1
  production guard gains a violation for them (and for an HTTPS-terminated
  production deployment with none configured, whenever the deployment
  declares that topology). Arbitrary clients' `X-Forwarded-*` headers are
  never trusted. This is what makes client IPs (rate limits), HTTPS
  recognition (HSTS, secure cookies) and generated URLs correct.
- **Hosts:** no wildcard host trust; host allowlisting that includes
  verified School domains waits for O9.

### 3. Processes, queues and the scheduler

- **Queues:** `default`, `integrations` and `notifications` each must be
  consumed by a defined worker process in every production deployment.
  `ai`, `low`, `critical` need no worker until a job uses them. 0O.4A adds
  a guard that fails when a `QueueName` carrying dispatched jobs has no
  process definition. The compose/DDEV worker commands are not a
  production topology.
- **Worker sizing** is capacity planning, not architecture.
- **Scheduler:** exactly **one** invocation mechanism runs
  `php artisan schedule:run` each minute (or one long-running
  `schedule:work`). The deployment's runbook must show that no second one
  exists (the eight commands above rely on it; `withoutOverlapping()` is an
  efficiency safeguard, not a duplicate-scheduler defence).

### 4. O4 — production secrets (owner decision)

- Production secrets come from an **external managed secret store**,
  injected into the runtime and release environment. **No vendor is
  selected** — that is a deployment choice, not an application gate.
- Never committed to git, never baked into an image or into frontend
  assets (only `VITE_*` values reach the bundle, and none is secret),
  never deliberately written to Terraform state or outputs, never printed
  in logs, readiness output or refusals (Phase 0O.1 already guarantees
  codes-only refusals).
- **Injection precedes** the production configuration guard and
  `config:cache` (`PRODUCTION-RELEASE.md` §3 steps 2–4); the same image
  runs in every environment with different injected configuration.
- **Least exposure per process:** web, workers and scheduler receive the
  runtime DB credentials only; **`DB_ADMIN_*` is injected only into the
  release/migration step and the operator console** (root provisioning,
  service-identity issuance). The AI Gateway receives only `SERVICE_TOKEN`
  (and its own settings) — never database credentials (rule 8).

Secret inventory:

| Secret | Consumers | Notes |
|---|---|---|
| `APP_KEY` | web, workers, scheduler, console | Also encrypts webhook secrets and TOTP secrets; rotation via `APP_PREVIOUS_KEYS` needs a separate re-encryption/session decision |
| Runtime DB password (`school_os_app`) | web, workers, scheduler | |
| Migration/admin DB password | release step, operator console only | |
| `REDIS_PASSWORD` | web, workers, scheduler | Required in production (private network is not a substitute) |
| Object-storage keys (`AWS_*`) | web, workers | Production-only credentials, least privilege on the one bucket |
| `AI_GATEWAY_CONTEXT_SIGNING_KEY` | Laravel only | Fails closed (0O.1); custody target below |
| Laravel↔Gateway service credential | Laravel, Gateway, `service_identities` hash | Issued by `platform:service-identity-issue`; rotation is O5 |
| `CONTACT_LOOKUP_HMAC_KEY`, `STATUTORY_IDENTIFIER_LOOKUP_HMAC_KEY` (+ versions) | Laravel | Versioned per row |
| `MAIL_*` credentials | Laravel | Only once O13 chooses a provider |
| Webhook signing secrets, partner/human API credentials | database (encrypted or hashed) | Rotation already built (webhooks, partner keys) |

### 5. Rotation and key custody boundary

Choosing a secret store does **not** provide application-level rotation.
Status: webhook-secret rotation — built; partner-credential rotation —
built (0O.3); service-to-service credential rotation — **O5, open**;
AI context signing key — the target is **managed key custody** (a
secret/KMS-backed mechanism with versions and a key id, ADR 0023), not
implemented here and no vendor chosen; `APP_KEY` rotation — needs its own
decision (re-encryption of `encrypted` columns, session invalidation).

### 6. O6 — runtime role name (owner decision)

**v1 keeps `school_os_app` as a fixed production contract.** Verified:
keeping it contradicts nothing — code and migrations already require it,
and the Phase 0O.1A boundary is role-name-independent. Generalizing it
would change 44 migration call sites' defaults for no security value. The
production database must contain a login role named exactly
`school_os_app`. A future ADR may generalize it.

### 7. Database roles and network

- **Two roles (ADR 0021):** the **migration/admin role** owns the schema,
  runs migrations, holds grant authority and performs controlled operator
  bootstrap; the **runtime role `school_os_app`** is LOGIN, NOSUPERUSER,
  NOBYPASSRLS, NOCREATEDB, NOCREATEROLE, NOINHERIT, NOREPLICATION, has no
  `DELETE` where migrations revoke it, can neither mint root platform
  authority (0O.1A) nor alter schema, and is **never a member of the
  owner/admin role**.
- **Bootstrap requirement (0O.4A):** production bootstrap must grant the
  runtime role `CONNECT`, schema `USAGE` and — critically —
  `ALTER DEFAULT PRIVILEGES FOR ROLE <migration role> IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES` and `USAGE, SELECT ON
  SEQUENCES` to `school_os_app`, for the actual migration role (the local
  scripts name `school_os`). Without it new tables are invisible to the
  application.
- **Network:** PostgreSQL private wherever hosting permits, TLS on the
  connection (`DB_SSLMODE` at least `require` in production), separate
  runtime and admin credentials.

### 8. O8 — object storage (owner decision)

S3-compatible, vendor-neutral, and:

- a **private** bucket with public access blocked; no anonymous or public
  object URLs; downloads stay application-mediated (the current model);
- **encryption at rest** by the provider (provider-managed keys acceptable
  if they meet organisational requirements; customer-managed keys are a
  later deployment decision);
- **versioning enabled** — for accidental overwrite/delete recovery; it is
  not a backup and old versions are never exposed through the application;
- TLS for all service communication; dedicated production credentials
  with least privilege on that bucket;
- **at least one dedicated bucket per environment** — production never
  shares the DDEV/test bucket (`school-os-local`); not one bucket per
  School: the `schools/{school_id}/…` prefix stays the tenancy boundary.
- **Lifecycle:** no rule may expire current objects or noncurrent versions
  of records whose legal retention is unresolved (the retention gate stays
  authoritative). Operational version retention is not legal retention.

This is compatible with the application as built: it writes, reads and
deletes by key only and never sets visibility or generates URLs.

### 9. O10 — recovery objectives (owner decision)

| Store | RPO | RTO |
|---|---|---|
| PostgreSQL | ≤ 15 minutes | ≤ 4 hours |
| Object storage | ≤ 24 hours | ≤ 8 hours |
| Redis | no durable business-record backup (section 10) | rebuilt, then reconciled |

These are operational recovery objectives, **not** legal retention
periods.

- **PostgreSQL backups:** automated base backups plus point-in-time
  recovery (WAL archiving or an equivalent) sufficient for ≤ 15 minutes;
  encrypted backup storage; backup credentials separate from application
  credentials. **Operational backup window: 35 days** (no stricter
  requirement exists; this does not authorize deleting business records,
  and any future DPDP erasure or retention decision must say how backup
  copies inside this window are handled).
- **Object-storage backups:** versioning plus an **independent**
  backup/replication copy (≤ 24 h RPO) to an encrypted destination, so
  recovery does not depend on the live bucket's history. Cross-region is
  not required unless the chosen hosting later needs it.
- **Separation:** the application's runtime credentials can neither read
  nor delete backup sets; recovery material sits under separate
  administrative control (a distinct account, role or storage with
  deletion protection where the provider offers it).

### 10. Redis and queue recovery (finding → requirement)

Redis is reconstructable infrastructure **provided** queued work can be
rebuilt from PostgreSQL. 0O.4A must therefore add a **reconciliation
path** (a scheduled sweep and/or an operator command) that re-dispatches,
after a grace period: outbox rows stale in `dispatched`, webhook
deliveries stale in `pending`, and immediate Communication deliveries
stale in `pending` — all safe to re-run (receipted consumers, leased
deliveries). Until that exists, a production deployment must either run
Redis with persistence (AOF) or include a manual re-queue step in its
restore runbook. Sessions (and with them MFA assurance and elevation
pointers) are lost on a Redis loss: users sign in again. Rate-limit
counters, locks and caches rebuild.

### 11. Restore drills and disaster-recovery scope

- **Quarterly** restore drills, always into an **isolated** environment,
  never over production. Each drill: restore PostgreSQL to a chosen point,
  restore or reconcile a sample of objects, boot the application against
  the restored data, verify RLS and security invariants (forced RLS, the
  runtime role's attributes, the 0O.1A boundary), verify critical files
  are readable. Record date, result, duration and the recovery point
  reached — never restored payloads in drill logs.
- A backup counts only once a **restore** has succeeded; a provider's
  "backup successful" is not evidence. 0O.4A adds a restore-validation
  runbook and tooling.
- **DR scope:** a recoverable **single-primary** deployment meeting the
  RPO/RTO. No active-active, multi-region or zero-downtime DR is promised.

### 12. Production images

Repository-owned (0O.4A): an **application image** (web, worker and
scheduler roles by command) and an **AI Gateway image** — reproducible,
production dependencies only (`composer install --no-dev`, built frontend
assets, no dev tools), no secrets, non-root where feasible, OPcache on,
pinned base images per the future O16 policy, health behaviour per role
(web: `/api/health/*`; Gateway: `/health/*`; workers/scheduler: process
supervision). The local Dockerfiles stay local.

### 13. Terraform / IaC

`infrastructure/terraform` stays **provider-neutral and empty of resources**
until a hosting provider is chosen: provider-neutral Terraform is not
meaningful, and generic modules would be decoration. What is fixed now:

- IaC is never applied by tests or CI; `plan` and `apply` are separate, and
  **apply is deploy-gated** (explicit operator authorization, rule 16);
- production state is **remote, encrypted, access-controlled and locked**,
  never committed, classified Highly Sensitive (it can contain connection
  details and, with some providers, secrets — which are never deliberately
  written to outputs);
- per-environment separation (`environments/<env>`), no hand-edited
  resources.

0O.4A defines the interface (the inputs a deployment must supply — network
ranges, trusted proxies, bucket, secrets references, backup policy
references) in documentation, not provider modules.

### 14. Release, migrations and compatibility

- Migrations run **only** through the migration/admin role in the release
  step (`PRODUCTION-RELEASE.md` §3 step 6), never `migrate --force` from a
  web process, never by the runtime role.
- **Finding:** the migration history includes destructive changes (24
  migrations with drops/renames/type changes) and many trigger/constraint
  tightenings, with no expand/contract policy. Rolling mixed-version
  releases are therefore **unproven**: v1 production releases are
  **single-version with a maintenance window** (stop the scheduler, drain
  workers, migrate, cache, start the new version, `queue:restart`, verify
  health). Zero-downtime deploys need a future expand/contract policy.
- Load balancers route on `/api/health/ready` (and the Gateway's
  `/health/ready`); liveness stays separate; no detailed health data is
  public.

### 15. Configuration validation additions (0O.4A)

The Phase 0O.1 guard stays authoritative and is only extended: refuse a
trust-all proxy value; require `DB_SSLMODE` ≥ `require`; require
`REDIS_PASSWORD`; require a non-local storage disk for Documents
(`DOCUMENTS_DISK=s3`), an HTTPS storage endpoint and a bucket other than
the local one; codes only, never values. Nothing weakens an existing check.

### 16. Environment separation and staging

Production, any staging, local/DDEV and test never share databases, Redis,
buckets, secrets or service credentials; no test or CI job may point at
production infrastructure. **Staging is recommended, not required**: the
repository nowhere requires it, so Phase 0O completion does not depend on
it.

### 17. Boundaries kept open

O12 (no logging/metrics/tracing/alerting vendor), O13 (no email
provider), O9 (no domain verification, wildcard TLS or School
certificates), O2/O15 (no payment or third-party provider), O16 (no
scanner or pinning policy — this contract only requires immutable
artifacts), O5 (service-to-service rotation), O14 (password reset).

### 18. Classification

| Item | Tier |
|---|---|
| Secret values | Highly Sensitive |
| Secret references (names/paths in a secret store) | Confidential |
| Production infrastructure configuration (topology, network ranges, trusted proxies) | Confidential |
| Terraform state | Highly Sensitive |
| Database and object-storage backups | Highly Sensitive (they contain everything, incl. children's data) |
| Backup manifests/catalogs | Confidential |
| Restore-drill evidence (dates, results, durations; no payloads) | Confidential |

### 19. Deploy-gated register (never performed by a repository agent)

Infrastructure apply (Terraform or console), database role creation and
bootstrap grants, production migrations, secret installation and
rotation, DNS/TLS changes, bucket creation and policies, backup policy
activation, restore drills against real backups, root provisioning,
service-identity issuance, worker/scheduler process activation. The
repository prepares them (scripts, runbooks, validation); a human with
explicit authorization performs them.

### 20. Contribution to the Phase 0O definition of done (O1)

For this component, Phase 0O cannot close until there is evidence of: this
production deployment model; safe secret injection (least exposure per
process); database role separation with the fixed runtime role and its
bootstrap grants; the production storage policy; the backup plan; a
restore-drill procedure **and** evidence of one successful drill in a
non-production environment; the Redis/queue reconciliation path; and the
deploy/runbook contract. O1 as a whole remains open.

### 21. Proposed Phase 0O.4A — Production Infrastructure & Recovery Foundation (not implemented)

Repository-only, no apply, no deploy:

1. Production images (application, AI Gateway), built and smoke-tested
   locally/CI only.
2. Trusted-proxy configuration and the section 15 guard extensions, with
   tests.
3. Queue/outbox reconciliation (section 10) with tests, and the
   queue-coverage process guard (section 3).
4. PostgreSQL bootstrap script/runbook for production (roles, default
   privileges, `school_os_app` attribute validation command).
5. Process definitions as documentation (web, three queues, one
   scheduler, Gateway) and the maintenance-window release runbook.
6. Backup/restore runbooks and restore-validation tooling (boot + RLS
   invariant checks against a restored database).
7. Storage policy validation (configuration checks; bucket policy as
   documented requirements, not provider code).
8. Documentation updates. No Terraform provider modules.

## Alternatives considered

- **Pick a cloud provider now.** Rejected by owner decision; nothing in
  the application needs it.
- **Generalize the runtime role name.** Deferred (O6 decision): 44 call
  sites, no security gain before production.
- **Treat Redis as fully disposable.** Rejected after the audit: three
  queued-work paths strand without reconciliation.
- **Generic Terraform modules.** Rejected as decoration without a provider.
- **Rolling zero-downtime releases.** Unproven with the current migration
  history; maintenance-window releases for v1.

## Consequences

- 0O.4A has concrete, testable repository work, including one real
  reliability fix (queue reconciliation).
- A deployment can choose any provider that meets sections 1–13 without
  code changes.
- Phase 0O closure additionally needs a successful restore drill — an
  operator activity that needs explicit authorization.

## References

`docs/architecture/PRODUCTION-RELEASE.md`, `PHASE-0O-READINESS.md`,
`RELIABILITY.md`, `TENANCY.md`, `docs/security/DATA-CLASSIFICATION.md`,
`docs/security/INTEGRATION-SECURITY.md`, ADR 0016, ADR 0021, ADR 0023,
ADR 0046, ADR 0049, `infrastructure/docker/postgres/init/01-roles.sql`,
`apps/platform/config/database.php`, `routes/console.php`.
