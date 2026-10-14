# Staging infrastructure architecture (PPR.2 plan, ADR 0074 §27)

**Status: PLAN ONLY. NOTHING IN THIS DOCUMENT EXISTS.**

> No resource in this plan may be created or changed without a separate
> rule-16 owner approval for that specific operator action.

Each later instruction names the exact step in
[STAGING-PROVISIONING-RUNBOOK.md](STAGING-PROVISIONING-RUNBOOK.md) and the
evidence id it will produce
([STAGING-EVIDENCE-PLAN.md](STAGING-EVIDENCE-PLAN.md)).

**Contracts this plan implements:**
- [STAGING-ENVIRONMENT.md](STAGING-ENVIRONMENT.md) (PPR.1);
- ADR 0050 (infrastructure, secrets, recovery);
- ADR 0052 (artifacts);
- [PRODUCTION-IMAGES-AND-PROCESSES.md](PRODUCTION-IMAGES-AND-PROCESSES.md)
  and `apps/platform/deploy/processes.json`, the **authoritative** process
  and secret-group manifest.

**Vendor-neutral.** No cloud, orchestrator, secret store, registry or
observability vendor is chosen (ADR 0074 §22). Each term below is a role a
chosen provider must fill.

## 1. Zones and exposure

```text
                 Internet
                    │ 443 (HTTPS)  [80 → 301 to 443 only]
            ┌───────▼────────┐
            │  EDGE (TLS)    │  public zone: the ONLY public listener
            └───────┬────────┘
                    │ private  (HTTP 8080; X-Forwarded-* added by the edge)
   ┌────────────────▼────────────────────────────────────────────────┐
   │ APPLICATION ZONE (private)                                      │
   │  web ×1..n (8080; metrics 9102 → collector only)               │
   │  worker-default · worker-integrations · worker-notifications    │
   │  scheduler ×1 (singleton)   release/console (one-shot tasks)    │
   │  email-capture (SMTP STARTTLS; web UI operator-only)            │
   └───────┬──────────────────────┬─────────────────────┬────────────┘
           │ 5432 TLS             │ 6379 (auth)         │ HTTPS
   ┌───────▼──────┐       ┌───────▼──────┐      ┌───────▼──────────┐
   │ DATA ZONE    │       │ DATA ZONE    │      │ OBJECT STORAGE   │
   │ PostgreSQL 16│       │ Redis-compat │      │ private bucket   │
   └──────────────┘       └──────────────┘      └──────────────────┘
   Operator access: through an authenticated, audited management path
   (bastion/VPN/provider console) — never a public DB/Redis port.
```

**Network rules (deny by default):**

| From → To | Port / protocol | Purpose |
|---|---|---|
| Internet → edge | 443/TCP (80/TCP → redirect only) | the only public ingress |
| edge → web | 8080/TCP | proxied HTTP; the edge sets `X-Forwarded-For/-Host/-Port/-Proto` |
| collector → web | 9102/TCP | `GET /metrics`, bearer token; never through the edge |
| web, workers, scheduler, release/console → PostgreSQL | 5432/TCP, TLS | runtime role; release and console also the admin role |
| web, workers, scheduler, release/console → Redis | 6379/TCP, authenticated | queues, locks, cache |
| web, workers, release/console → object storage | 443/TCP, HTTPS | Documents and attachments |
| application roles → email capture | its STARTTLS port | captured mail only |
| operator management path → data stores | provider-specific | admin and bootstrap only |
| application zone → internet egress | **denied by default** | allow-list only what a later slice needs (registry pull, NTP, time/DNS); no mail relay; no AI provider |

**Never public:**
- PostgreSQL and Redis;
- the object-storage administrative API;
- the metrics port;
- the email-capture SMTP and web UI;
- the AI Gateway, which is not deployed: E26 is disabled.

**Health paths:**
- **Edge and load balancer:** route on `GET /api/health/ready`. Liveness is
  `GET /api/health/live` (always 200, including during maintenance).
- **Exposure:** both stay reachable through the edge (no detailed data,
  rule 55). Neither needs any exception to the hardening.

## 2. Edge and HTTPS

| Aspect | Plan |
|---|---|
| TLS termination | at the edge; certificate for the staging platform host (OD-PPR-8), owned and renewed by the edge/provider, private key never in the application or the repository |
| HTTP | port 80 answers only with a redirect to HTTPS |
| HSTS | set by the **application** on secure responses (PPR.1: staging is hardened; `max-age` 31,536,000). The edge must not strip it; it may add none of its own. *Because HSTS lasts a year, staging uses its own host name, never a parent domain that other non-HTTPS services share (`includeSubDomains` is not set by the application).* |
| Proxy headers | the edge sets `X-Forwarded-For`, `-Host`, `-Port`, `-Proto` and overwrites any client-supplied value |
| Trusted proxies | `TRUSTED_PROXIES` = the edge's private address range(s) only (explicit IPs/CIDRs; never `*`, never broader than /8) |
| HTTPS detection | from `X-Forwarded-Proto` through the trusted proxy only; secure cookies and forced HTTPS URLs depend on it |
| Real client IP | from `X-Forwarded-For` through the trusted proxy; rate limiters use it |
| Custom School domains | **off** in staging (`CUSTOM_DOMAINS_ENABLED=false`) until the separate E22 exercise (PPR.7) |

The deployment satisfies the application. No application hardening is
relaxed to fit an edge.

## 3. PostgreSQL 16

| Aspect | Plan |
|---|---|
| Topology | single primary, PostgreSQL **16**; no replica required for staging (§12) |
| Network | data zone, private; reachable only from the application zone and the operator management path |
| TLS | server TLS on; clients `DB_SSLMODE=verify-full` where the provider CA is distributable (else `require`, the guard minimum), runtime **and** admin |
| Authentication | password (SCRAM) per role from the secret store; no trust/peer auth over the network |
| Roles (ADR 0021, 0050 §6–§7) | **migration/admin role**: provider-named, schema owner, migrations, grants and bootstrap; **`school_os_app`**: runtime, LOGIN, NOSUPERUSER, NOBYPASSRLS, NOCREATEDB, NOCREATEROLE, NOINHERIT, NOREPLICATION, never a member of the owner role; **`school_os_retention`**: retention (E21-RH.2), used by the scheduler only; **backup**: the provider's mechanism with its own credentials, never an application credential |
| Bootstrap | `infrastructure/postgres/production-bootstrap.sql` + `DATABASE-BOOTSTRAP.md` (default privileges FOR ROLE the migration role) |
| Extensions | none: no migration creates one; PostgreSQL 16 core suffices |
| Connections | web PHP-FPM pool + 3 workers + scheduler + release; size `max_connections` for the pool total plus headroom; `DB_CONNECT_TIMEOUT` 5 s |
| Time | server `timezone = 'UTC'` (the application stores UTC; School-local dates are application logic) |
| Durability | provider-managed durable storage; encryption at rest on |
| Backups | PITR/WAL archiving (RPO ≤ 15 min), base backups, 35-day window, encrypted, separate credentials, deletion protection (ADR 0050 §9). Configured in PPR.5 |
| Monitoring | readiness (OBS-02), provider engine metrics to the collector (PPR.4) |

## 4. Database credentials by process

| Credential | Secret group | Given to | Grants |
|---|---|---|---|
| `DB_USERNAME=school_os_app` / `DB_PASSWORD` | `app_runtime` | web, 3 workers, scheduler, release, operator console | CONNECT; schema USAGE; DML via default privileges; no DDL, no DELETE where revoked, no BYPASSRLS |
| `DB_ADMIN_USERNAME` / `DB_ADMIN_PASSWORD` | `database_admin` | **release and operator console only** (manifest; `ProductionProcessManifestTest`) | schema owner: migrations, grants, root bootstrap |
| `DB_RETENTION_USERNAME` / `DB_RETENTION_PASSWORD` | `database_retention` | **scheduler and operator console only** | the retention functions only (E21-RH.2) |
| Backup credentials | provider-native (not in the application) | the backup service | read for backup; no application role can read or delete backups |

**`DB_ADMIN_*` is never injected into a long-running role.** If it is unset,
`config/database.php` falls back to the runtime credentials for the admin
connection, which then has no admin rights. That is safe, but the release
step fails, as intended.

## 5. Redis-compatible service

| Aspect | Plan |
|---|---|
| Product | any Redis-protocol service the code's `predis` client supports (Redis 7 / Valkey). E12 recovery is unchanged: Redis holds **no** irreplaceable record; PostgreSQL reconciliation rebuilds queued work |
| Network | data zone, private; application zone only |
| Authentication | `REDIS_PASSWORD` required (guard `redis_password_missing`); ACL user if the service supports it |
| TLS | **not required** by the hardened contract (ADR 0050 §6: private network + auth). *RECOMMENDED where the provider offers it;* `predis` speaks TLS through a `tls://` `REDIS_URL`, which **is not yet proven in this repository** and would need a verification step before being relied on |
| Logical databases | `0` = queues, locks, rate limits, the session handoff store; `1` = cache (`REDIS_DB`, `REDIS_CACHE_DB`); key prefix from `APP_NAME` |
| Eviction | **`noeviction`**: queue and lock keys must never be silently evicted. Size memory so the cache cannot fill it |
| Persistence | optional (AOF/RDB). Not required, because reconciliation exists. A loss signs users out (sessions/MFA assurance) and is exercised as E12 |
| Monitoring | readiness (OBS-03) and the provider's memory/eviction metrics (PPR.4) |

## 6. Object storage

| Aspect | Plan |
|---|---|
| Bucket | one staging bucket, never `school-os-local`/`school-os-test`, never shared with production |
| Access | **private**; public-access block on; no public ACL or policy; no anonymous URL. Downloads are application-mediated (the application never generates public URLs) |
| Encryption | at rest (provider-managed keys acceptable, ADR 0050 §8); TLS for every request (`AWS_ENDPOINT` https or provider default) |
| Versioning | **on** |
| Lifecycle | **never** expire current objects; noncurrent versions may expire after ≤ 35 days (E21-D12) |
| Identity | a staging-only identity with least privilege on this bucket (get/put/delete/list); credentials in `app_runtime` or the platform's credential chain (both set or both absent) |
| Tenancy | the `schools/{school_id}/…` prefix stays the boundary; never one bucket per School |
| Backup | an independent copy (replication or backup) under separate administrative control, encrypted (RPO ≤ 24 h). Configured in PPR.5 |
| Content | synthetic files only (STAGING-ENVIRONMENT §4) |
| Verification | `platform:verify-storage` (versioning, encryption, public-access block where the API exposes them). The independent copy is always operator evidence |

## 7. Secret store (E08) — vendor-neutral requirements

**The selected mechanism (OD-PPR-4) must provide:**

| # | Requirement |
|---|---|
| S1 | Per-process injection of the manifest's secret groups (`app_runtime`, `database_admin`, `database_retention`, `gateway`) as environment variables, **before** the process boots (the guard runs at boot) |
| S2 | Separate **staging** and **production** namespaces (or instances); no identity reads both |
| S3 | Operator-controlled writes; automated read-only access for the runtime |
| S4 | Audited access where the product supports it |
| S5 | Versioning, rotation and revocation of a value without an image rebuild |
| S6 | Least privilege: each process identity can read only its own groups (release and console are separate identities that alone read `database_admin`) |
| S7 | No plaintext in Git, images, IaC state outputs, logs or the frontend bundle |

### 7.1 Secret inventory

| Secret | Group | web | workers | scheduler | release | console | operator-only | Staging value |
|---|---|---|---|---|---|---|---|---|
| `APP_KEY`, `APP_PREVIOUS_KEYS` | app_runtime | ✓ | ✓ | ✓ | ✓ | ✓ | | staging-generated |
| `METRICS_SCRAPE_TOKEN` | app_runtime | ✓ (serves 9102) | guard | guard | guard | guard | | staging-generated; the collector holds a copy |
| `DB_PASSWORD` | app_runtime | ✓ | ✓ | ✓ | ✓ | ✓ | | staging |
| `REDIS_PASSWORD` | app_runtime | ✓ | ✓ | ✓ | ✓ | ✓ | | staging |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | app_runtime | ✓ | ✓ | ✓ | ✓ | ✓ | | staging bucket identity (or none: credential chain) |
| `AI_GATEWAY_CONTEXT_SIGNING_KEY` | app_runtime | ✓ | guard | guard | guard | guard | | staging-generated (required by the guard even with the Gateway off) |
| `AI_GATEWAY_SERVICE_SIGNING_KEY` | app_runtime | — | — | — | — | — | | **not set** (Gateway not deployed, E26) |
| `CONTACT_LOOKUP_HMAC_KEY`, `STATUTORY_IDENTIFIER_LOOKUP_HMAC_KEY` | app_runtime | ✓ | ✓ | ✓ | ✓ | ✓ | | staging-generated (see finding P2-F2) |
| `DOMAIN_PROBE_KEY` | app_runtime | — | — | — | — | — | | **not set** (custom domains off until PPR.7) |
| `MAIL_PASSWORD` | app_runtime | ✓ | ✓ | ✓ | ✓ | ✓ | | the capture service's SMTP credential |
| `MAIL_SUPPRESSION_HMAC_KEY` (+ previous) | app_runtime | ✓ | ✓ | ✓ | ✓ | ✓ | | staging-generated |
| `MAIL_PROVIDER_EVENT_SECRET` (+ previous) | app_runtime | — | — | — | — | — | | **not set** (`MAIL_PROVIDER_EVENTS=none`) |
| `MAIL_SENDGRID_API_KEY` | app_runtime | — | — | — | — | — | | **never set in PPR.2** (SendGrid dormant; PPR.6) |
| `DB_ADMIN_USERNAME` / `DB_ADMIN_PASSWORD` | database_admin | | | | ✓ | ✓ | | staging migration role |
| `DB_RETENTION_USERNAME` / `DB_RETENTION_PASSWORD` | database_retention | | | ✓ | | ✓ | | staging retention role |
| `SERVICE_SIGNING_KEY` | gateway | | | | | | | **not deployed** |
| Registry pull credential | platform | | | | | | ✓ (runtime platform pulls images) | PPR.3 |
| Secret-store admin, DB admin bootstrap superuser, provider console access | — | | | | | | ✓ | operator-only; never in a process |
| Edge TLS private key | edge | | | | | | ✓ | held by the edge/provider only |

"guard" = not used by the role's own work, but required at boot by
`ProductionConfigurationGuard`, which runs in every hardened process.

### 7.2 Finding P2-F1 — per-process narrowing beyond the manifest

**The authoritative manifest gives every long-running application role the
same `app_runtime` group.** The guard also requires several of its secrets
in every hardened process: `APP_KEY`, the metrics token, the AI context key
and, with email on, the mail secrets.

**Narrowing within `app_runtime`, per role,** is therefore **not possible
without a code change.** Two examples:
- the metrics token only for web;
- a future SendGrid key only for `worker-notifications`.

That change would make the guard process-role-aware (`PROCESS_ROLE`) and
split the group in `processes.json` and `ProductionProcessManifestTest`.

**What PPR.2 does.**
- **Plans to the existing contract:** E08's "per-process secret groups;
  `DB_ADMIN_*` only in release/console" is met by it.
- **Records the decision:** finer narrowing is OD-PPR-22 (RECOMMENDED
  before PPR.6 enables a provider key; not required for E08).
- **Leaves out** every secret the staging configuration does not need
  (table above).

## 8. Application runtime topology

**One immutable application image. Each role is selected by command**
(`deploy/entrypoint.sh`), with the runtime security contract on every
container (`runtime-security.json`):
- non-root;
- ALL capabilities dropped;
- no-new-privileges;
- never privileged.

| Process | Command | Count | Long-running | Secret groups | Health | Notes |
|---|---|---|---|---|---|---|
| web | `web` (nginx 8080 + PHP-FPM) | ≥ 1 (1 is enough for staging) | yes | app_runtime | live/ready; metrics 9102 | builds caches from injected env; guard at boot; exits if nginx or FPM dies |
| worker-default | `worker default` | 1 | yes | app_runtime | process supervision | outbox dispatch/reconciliation, automation |
| worker-integrations | `worker integrations` | 1 | yes | app_runtime | process supervision | webhook fan-out and delivery (no webhook endpoints are registered in staging unless a later slice does so) |
| worker-notifications | `worker notifications` | 1 | yes | app_runtime | process supervision | email and Communication delivery → the capture sink |
| scheduler | `scheduler` (`schedule:work`) | **exactly 1** | yes | app_runtime, database_retention | process supervision; OBS-05/06/07 | the 30 scheduled tasks |
| release | `console migrate --database=pgsql_admin --force` | one-shot per release | no | app_runtime, database_admin | exit code | inside the maintenance window |
| operator-console | `console <artisan …>` | one-shot | no | app_runtime, database_admin, database_retention | exit code | bootstrap and support commands (§10) |
| email-capture | TLS SMTP capture service (§9) | 1 | yes | its own TLS key + SMTP credential | its own | staging-only, non-application process |
| ai-gateway | — | **0** | — | — | — | not deployed (E26 CONDITIONAL_DISABLED) |
| static assets | served by the web role's nginx from the image | — | — | — | — | no separate CDN required |

**Worker settings.** The entrypoint fixes them:
- `queue:work --queue=<one> --sleep=3 --timeout=60 --max-time=3600`;
- Laravel's default memory limit of 128 MB, then restart;
- `retry_after` 90 > timeout 60 (rule 58);
- each job class declares its own `$tries`/`$timeout`, and domain-retried
  jobs use `$tries = 1` (rule 59).

**Restart and failure:**
- Restart policy: always, on any exit. `--max-time` recycles workers hourly.
- No `--force`: workers pause during maintenance.
- A failed job lands in `failed_jobs` (OBS-10, `FAILED-JOBS.md`).
- Concurrency is one process per worker role. Staging needs no more.

**Scheduler.**
- **Singleton:** one `schedule:work` per deployment (ADR 0050 §3). The
  orchestrator must enforce a single replica; `withoutOverlapping()` is not
  a duplicate-scheduler defence.
- **Timezone:** UTC.
- **Restart:** always.
- **Missed or stopped runs** are detected by the heartbeat alerts:
  - OBS-05: every minute-task stale;
  - OBS-06: one task stale;
  - OBS-07: a duplicate scheduler.
- **Task inventory:** 30 tasks (`routes/console.php`; the `scheduled_task`
  label of the metric catalogue): reconciliation and redispatch sweeps,
  retention prunes, elevation expiry, domain checks, worker canaries,
  scheduled publishing.

## 9. TLS email capture

| Aspect | Plan |
|---|---|
| Service | a Mailpit-equivalent capture service (Mailpit supports STARTTLS and SMTP authentication), **never** a relay: it has no upstream/forward/relay configuration and the application zone denies SMTP egress |
| Transport | STARTTLS required (`MAIL_SMTP_TLS=required`); a certificate for the service's private name issued by an operator-controlled internal CA or the provider; the application trusts it through the runtime's CA store (an image/trust-bundle question resolved at provisioning, without disabling verification) |
| Authentication | SMTP auth on; credential = `MAIL_USERNAME`/`MAIL_PASSWORD` in `app_runtime` |
| Reachability | application zone only; its web UI reachable only through the operator management path |
| Application settings | `MAIL_PROVIDER=smtp`, `MAIL_STAGING_DELIVERY=capture`, `MAIL_PROVIDER_EVENTS=none`, a staging `MAIL_SENDING_DOMAIN`, `MAIL_SENDING_VERIFIED` unset |
| Retention / purge | messages kept at most 7 days (capture-service setting), purged on every staging reset; no export |
| Recipients | synthetic and operator-controlled addresses only (STAGING-ENVIRONMENT §4) |
| No-external-delivery proof | the evidence in E09-plan items (capture configuration has no relay; egress rule denies SMTP; a test message appears in the capture UI and in no external mailbox) |

**Not created:** SendGrid. E17–E20 do not start.

## 10. Synthetic data population (OD-PPR-2)

**After a fresh, empty, migrated staging database**, data is created in this
order, each step an operator action (runbook R-17):
1. **Seeds:** `console db:seed --force` (`DatabaseSeeder`), the
   production-safe catalogues: capabilities, roles, education boards,
   statutory rule versions. It creates no account; its local/testing-only
   test user is skipped outside development, and the demo seeder refuses
   staging by design (`PRODUCTION-RELEASE.md` §3 step 7).
2. **Platform root:** `console platform:bootstrap-root` (first boot,
   interactive, synthetic identity), then root MFA enrolment.
3. **First School:** platform UI (School lifecycle, synthetic School), then
   `console platform:provision-school-admin-account` for its first
   administrator (synthetic person, operator-controlled mailbox).
4. **Everything else:** through the application UI by testers, with
   intentionally synthetic values.

**A synthetic fixture generator** is not built now. If manual entry later
proves too slow for qualification, that is a separate owner decision
(OD-PPR-23), never a production-data import.

**Reset.** Rebuild by deprovisioning the database and bucket (§W in the
runbook) and repeating from step 1. There is no partial "clean-up" SQL.

## 11. Artifacts (dependency on PPR.3)

- **Image source.** Staging runs only the repository's application image,
  deployed **by digest** and VERIFIED by `infrastructure/release/qualify`
  and `verify-artifact` (ADR 0052). There is no Git-checkout deployment
  and no image built on the host.
- **Blocked until PPR.3:**
  - the registry (E13) and signing custody (E14) do not exist;
  - so the runtime-provisioning steps (R-12 to R-16) cannot start until
    PPR.3 provides a pullable, VERIFIED digest.
- **What may proceed before PPR.3,** each with its own approval:
  - network;
  - PostgreSQL;
  - Redis;
  - object storage;
  - secret-store namespaces;
  - the email capture service.

## 12. High availability, scale and cost

| Concern | Staging requirement |
|---|---|
| Security and configuration | **identical** to production (PPR.1) |
| Topology | **same shape**: edge, web, 3 workers, 1 scheduler, PG 16 TLS, Redis auth, private bucket, secret store |
| Scale / HA | **not required.** Single instances, no replicas, smallest sizes that boot the guard and pass verification |
| Later qualification | backup/restore (PPR.5) needs real PITR and the object copy; performance (PPR.8) may need a temporary scale-up. Both are possible without changing the shape |

**Cost drivers** (no vendor, so no figures):
- managed PostgreSQL, including PITR storage;
- Redis;
- object storage, plus versions and the independent copy;
- compute for web, workers and scheduler;
- the edge/load balancer;
- the secret store;
- the registry (PPR.3);
- monitoring ingest and retention (PPR.4);
- backup storage.

**Scale-down levers** that keep behaviour identical: one web instance,
smallest database and Redis tiers, short object-version retention within
the contract, and pausing the environment between qualification runs.

## 13. Monitoring hooks (for PPR.4)

| Hook | Source |
|---|---|
| Metrics | web `:9102/metrics` (bearer `METRICS_SCRAPE_TOKEN`), private to the collector; scrape job named `lycenza-metrics` (OBS-26) |
| Logs | JSON lines on stderr from every role, with `process_role` (`LOG_FORMAT=json`, the hardened default) |
| Service health | `/api/health/live`, `/api/health/ready` |
| Database / Redis health | readiness gauge `lycenza_readiness_status{dependency}` + provider engine metrics |
| Storage failures | `lycenza_storage_operation_failures_total`; `platform:verify-storage` |
| Workers / scheduler | queue heartbeats (`platform:dispatch-worker-canaries`), scheduler task heartbeats |
| Deployment evidence file | `OBSERVABILITY_DEPLOYMENT_EVIDENCE_FILE` mount point reserved (read-only; PPR.4/PPR.5 fill it) |

**No thresholds or routing here.** Those are PPR.4 (OD-PPR-7).

## 14. Backup hooks (for PPR.5)

| Store | Mechanism | Destination | Encryption | Retention | Access |
|---|---|---|---|---|---|
| PostgreSQL | provider PITR/WAL + base backups | provider backup storage under separate administrative control | at rest | 35-day operational window (ADR 0050 §9; not legal retention) | backup service only; no application credential can read or delete |
| Objects | versioning + independent copy/replication | a separate bucket or account | at rest | per E21-D12 for noncurrent versions | separate administrative identity |

**Planned only.** Backups are configured and evidenced in PPR.5, and no
restore drill happens in PPR.2.

## 15. Production-credential prohibition

PPR.2 and every staging step create staging-only values. **Never:**
- a production API key, SendGrid key or database credential;
- a DNS credential for production zones;
- a production certificate or private key;
- real School data.

Staging and production secrets live in separate namespaces (S2).
