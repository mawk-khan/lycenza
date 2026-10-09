# Production process and release contract

**Status: repository contract (Phase 0O.1, 2026-09-25; hosting, secrets,
storage and recovery decided by ADR 0050, Phase 0O.4 — this document stays
the process and release runbook).** This document
states what a production deployment of `apps/platform` and `services/ai`
must run, in what order a release happens, and which invariants every
release keeps. It is **not** a runbook for a specific host: the hosting
model, process manager (cron, systemd, Kubernetes, ...), worker counts,
secrets manager, object-storage provider and backup policy are still open
decisions (`PHASE-0O-READINESS.md` §8: O3, O4, O8, O10, O12). Nothing
here has been deployed, provisioned or configured anywhere (CLAUDE.md
rule 16).

## 1. Components

| Component | What it runs | Notes |
|---|---|---|
| Web | `apps/platform/public/index.php` behind a PHP-FPM-style server | `php artisan serve` is local-only |
| Queue workers | `php artisan queue:work` for **all three** queues in use: `notifications`, `default`, `integrations` (`App\Support\Observability\QueueName`) | One worker may list all three (`--queue=notifications,default,integrations`, as DDEV does) or they may be split; a queue nobody works silently stops its feature (Communications, outbox consumers/automation, webhooks). `ai`, `low`, `critical` are reserved and carry no jobs today |
| Scheduler | Exactly **one** scheduler: `php artisan schedule:run` every minute, or one long-running `php artisan schedule:work` | No `onOneServer()` exists (single-scheduler assumption, `routes/console.php`) |
| AI Gateway | `services/ai` under uvicorn, `NullProvider` only | Optional subsystem (CLAUDE.md rule 56); `REAL_PROVIDERS_ENABLED` stays unset (Phase 0M gate) |
| PostgreSQL 16 | Forced RLS on every tenant table | Two roles, §4 |
| Redis | Cache, sessions, queues, locks | |
| S3-compatible object storage | Documents (`DOCUMENTS_DISK`), `TenantStoragePath` keys | Provider and bucket policy: O8 |

The eight scheduled commands (`routes/console.php`), all
`withoutOverlapping()`:

| Command | Frequency | Purpose |
|---|---|---|
| `platform:outbox-dispatch` | every minute | Domain-event outbox → consumers |
| `platform:webhook-deliveries-redispatch` | every minute | Due webhook retries (`integrations`) |
| `platform:communication-deliveries-redispatch` | every minute | Due Communications deliveries (`notifications`) |
| `communications:publish-scheduled` | every minute | Scheduled announcements |
| `automation:executions-redispatch` | every minute | Due automation executions (`default`) |
| `platform:expire-school-elevations` | every minute | Records expiry of overdue platform elevations |
| `platform:idempotency-prune` | daily 02:10 | Expired `api_idempotency_keys` |
| `platform:webhook-deliveries-prune` | daily 02:20 | Deletes nothing until a legally approved retention is set |

Health: Laravel `GET /api/health/live` and `/api/health/ready`; the AI
Gateway `GET /health/live` and `/health/ready` (**503 when not ready**,
Phase 0O.1). Liveness never checks dependencies (rule 55).

## 2. Production configuration and the boot check

`APP_ENV=production` is the only production signal (the framework's own).
On **every** boot in that environment — web request, queue worker,
scheduler, any artisan command — `App\Support\Configuration\
ProductionConfigurationGuard` (called from `AppServiceProvider::register()`)
reads the resolved configuration (so it holds under `config:cache`) and
refuses to start on any of:

| Code | Refused when |
|---|---|
| `app_debug_enabled` | `APP_DEBUG` is on |
| `app_key_missing` / `app_key_invalid` | `APP_KEY` is empty, or not a key the configured cipher accepts |
| `session_cookie_not_secure` | `SESSION_SECURE_COOKIE` is not exactly true |
| `ai_context_signing_key_missing` / `_placeholder` | `AI_GATEWAY_CONTEXT_SIGNING_KEY` is empty, or one of the committed development/test values |
| `ai_legacy_service_token_configured` | The retired shared `AI_GATEWAY_SERVICE_TOKEN` is set to any value (ADR 0053: there is no fallback to it) |
| `ai_gateway_url_not_https` | The AI Gateway is configured (`AI_GATEWAY_BASE_URL` set) but its URL is not `https://` |
| `ai_service_signing_key_missing` / `_invalid` / `_development` / `_expired` | With the Gateway configured: Laravel's `platform` signing key (`AI_GATEWAY_SERVICE_SIGNING_KEY`, one RFC 8037 OKP JWK) is absent, malformed, a committed development key (kid prefix or public-key fingerprint), or older than 90 days |
| `ai_service_verification_keys_missing` / `_invalid` / `_development` / `_too_many` / `_duplicate_kid` / `_steady_key` / `_private_material`, `ai_service_verification_key_transition_expired` / `_too_long` | With the Gateway configured: the ring of `ai-gateway` public keys (`AI_GATEWAY_INBOUND_VERIFICATION_KEYS`) is absent, malformed, holds a development key, breaks the 1-2 key / one-steady-key rule, holds private material, or has a transitional key past or more than 24 h beyond `not_after` |
| `ai_service_replay_store_not_redis` | With the Gateway configured: the store consuming each inbound `jti` once (`AI_GATEWAY_REPLAY_STORE`, else the default cache) is not Redis |
| `trusted_proxies_unsafe` (0O.4A) | the resolved proxy list is trust-all (`TRUSTED_PROXIES` itself refuses `*`, `**`, `0.0.0.0/0`, `::/0`, over-broad prefixes, hostnames and malformed entries at configuration load) |
| `environment_not_separated` (0O.4A) | `APP_URL` is empty, `localhost`/loopback or a `.ddev.site`/`.test`/`.local`/`.localhost` host, or either connection names the test database |
| `maintenance_mode_not_shared` (0O.4A) | `APP_MAINTENANCE_DRIVER` is not `cache` (a per-container `file` flag) |
| `database_connection_not_pgsql` (0O.4A) | `DB_CONNECTION` is not `pgsql` |
| `database_tls_not_required` (0O.4A) | `DB_SSLMODE` is not `require`, `verify-ca` or `verify-full` (runtime and admin connections) |
| `redis_password_missing` (0O.4A) | `REDIS_PASSWORD` is empty or `null` |
| `storage_disk_not_s3` (0O.4A) | `DOCUMENTS_DISK` or `COMMUNICATION_ATTACHMENTS_DISK` is not `s3` |
| `storage_bucket_not_production` (0O.4A) | `AWS_BUCKET` is empty, `school-os-local` or `school-os-test` |
| `storage_endpoint_not_https` (0O.4A) | `AWS_ENDPOINT` is set and not `https://` |
| `storage_credentials_invalid` (0O.4A) | exactly one of `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` is set, or either is a committed local value |
| `storage_public_visibility` (0O.4A) | the S3 disk's visibility is `public` |

Provider-side encryption, versioning and the public-access block are
**not** configuration flags (a flag could lie): `platform:verify-storage`
checks them against the bucket, and the independent backup copy is
operator evidence.

The refusal names codes only, never a value. A refused web request gets a
plain 500 (debug is forced off for the refusal, so no debug page is ever
rendered); PHP's `display_errors` must be off in production, as in
`php.ini-production`. Independently of the environment, the AI context
signing key fails closed everywhere: without it no context token is issued
or accepted (`AiContextSigningKeyNotConfiguredException`).

The AI Gateway (`services/ai/app/core/startup.py`) refuses to start, and
reports 503 on `/health/ready`, in these cases (ADR 0053):
- in any environment: without a valid `SERVICE_SIGNING_KEY` (its own
  `ai-gateway` Ed25519 key) and `PLATFORM_VERIFICATION_KEYS` (the ring of
  `platform` public keys), or with the retired `SERVICE_TOKEN` set;
- unless `ENVIRONMENT` is exactly `local` or `testing`: with a committed
  development key, a signing key older than 90 days, or a non-`https`
  `ERP_CONTRACT_BASE_URL`.

`ENVIRONMENT` defaults to `production`; no key has a default.

**External API and browser hardening (Phase 0O.3, ADR 0049):**
`CORS_ALLOWED_ORIGINS` lists exact `https://` origins allowed to call the
API from a browser — empty by default (none); a wildcard or malformed
value makes the application refuse to boot. Responses carry the security
header baseline and an enforced CSP. **HSTS** (`max-age=31536000`, no
subdomains, no preload) is emitted only when Laravel knows the request is
HTTPS; behind a TLS-terminating proxy that needs the proxy's addresses in
`TRUSTED_PROXIES` (Phase 0O.4A) — never "trust every proxy". Partner API
credentials exist, but no partner route is enabled.

**O5 (ADR 0053) is implemented in Phase 0O.7A.** The shared service token
used to live in three places: Laravel `AI_GATEWAY_SERVICE_TOKEN`, the
Gateway `SERVICE_TOKEN`, and a hash in `service_identities`. It is gone.

Every internal call now carries a per-request Ed25519 service assertion,
with one keypair per calling service:
- `platform` signs for Laravel → Gateway;
- `ai-gateway` signs for Gateway → Laravel.

Each receiver holds only a public verification ring. The production guards
refuse a leftover shared-token variable, the committed development keys and
non-HTTPS internal URLs. Deployment evidence (real keys, rotation and
revocation drills) is still outstanding.

### Decided by ADR 0050 (Phase 0O.4)

- Provider-neutral containers behind a TLS-terminating proxy that is the
  only public entry; explicit `TRUSTED_PROXIES` (0O.4A; never `*`).
- Secrets from an external managed store, injected before the boot check
  and `config:cache`; **`DB_ADMIN_*` only in the release step and the
  operator console**, never in web/worker/scheduler processes.
- v1 releases are **single-version with a maintenance window**: rolling
  mixed-version deploys are unproven (no expand/contract policy).
- A Redis loss no longer strands queued work: since Phase 0O.4A the
  scheduled sweeps rebuild it from PostgreSQL
  (`docs/operations/REDIS-LOSS-RECOVERY.md`); Redis persistence is not
  required for correctness.
- Backups: PostgreSQL PITR (RPO 15 min, RTO 4 h, 35-day window); object
  storage versioning + independent copy (RPO 24 h, RTO 8 h); quarterly
  isolated restore drills.

**Implemented by Phase 0O.4A:** production images and the process
manifest (`docs/operations/PRODUCTION-IMAGES-AND-PROCESSES.md`), the
database bootstrap (`docs/operations/DATABASE-BOOTSTRAP.md`), the
maintenance-window sequence (`docs/operations/MAINTENANCE-WINDOW-RELEASE.md`)
and backup/restore (`docs/operations/BACKUP-AND-RESTORE.md`). With the
production image, steps 1 and 4–5 are the image build and the `web`/
`worker`/`scheduler` entrypoint; step 3 is `console down`; the admin
credentials reach only the `release` and `operator-console` processes.

## 3. Release order

Each step's invariant is in bold. Every step that talks to a database
names the database it is about to touch first (rule 54).

1. **Build once, from a clean checkout of the released commit** — since
   Phase 0O.6A this is release qualification (`infrastructure/release/qualify`,
   `docs/operations/RELEASE-QUALIFICATION.md`): the production images are
   built from the commit with digest-pinned bases, lock-only installs (no
   Composer plugins or package scripts, npm `ignore-scripts`, hash-verified
   Python wheels) and verified to **VERIFIED** before anything else happens.
   No `.env` file is part of the artifact.
2. **Configuration is injected by the environment** (mechanism: O4). No
   secret is committed or baked into an image (ADR 0016).
3. **Stop background processing** that would run old code against a new
   schema: stop or pause the scheduler; let workers finish (step 9
   restarts them).
4. `php artisan config:cache` — **with the production environment**; the
   boot check (§2) refuses an unsafe configuration here, before anything
   runs.
5. `php artisan route:cache` — **with `APP_ENV=production`**: local/testing
   routes (`internal/mfa-demo/ping`, the idempotency demo, webhook test
   events) are registered by environment at load time, so a cache built
   under another environment would ship them. `view:cache` is optional.
6. `php artisan migrate --database=pgsql_admin --force` — **only through
   the migration/admin connection** (ADR 0021), whose credentials exist
   only for this step and the operator console, never in web/worker/
   scheduler processes. The runtime role cannot and must not migrate.
7. `php artisan db:seed --force` (`DatabaseSeeder`) — **production-safe
   catalogs only**: capabilities and roles, education boards, statutory
   rule versions; idempotent; no accounts, no credentials, no School data.
   Service identities are a closed code catalog with configured keys (ADR
   0053); nothing about them is seeded. `DemoSeeder` refuses
   anywhere but a developer's DDEV (`DemoEnvironmentGuard`).
   **SR.1 (ADR 0071 §23.4):** the runtime role can no longer write the role
   catalogue, so `CapabilityAndRoleSeeder` writes `capabilities`, `roles`
   and `role_capabilities` through `pgsql_admin`. This step therefore needs
   the admin connection's credentials, exactly as step 6 does.
8. **Operator-only, first release only:**
   - `php artisan platform:bootstrap-root` — interactive, creates the first
     platform account and provisions root (§5); later root accounts use
     `php artisan platform:provision-root <exact email or user id>` for an
     account that already exists;
   - only if the AI Gateway is deployed: generate the two ADR 0053 service
     keypairs **outside** request processing
     (`php artisan platform:service-key-generate platform|ai-gateway
     --output=<dir outside the app>`, or the secret store's own generator).
     Install each private JWK in the managed secret store (Laravel:
     `AI_GATEWAY_SERVICE_SIGNING_KEY`; Gateway: `SERVICE_SIGNING_KEY`) and
     each public JWK in the other side's ring (Laravel:
     `AI_GATEWAY_INBOUND_VERIFICATION_KEYS`; Gateway:
     `PLATFORM_VERIFICATION_KEYS`), then destroy the files. Check with
     `php artisan platform:verify-service-auth`, which prints no key
     material ([SERVICE-KEY-ROTATION](../operations/SERVICE-KEY-ROTATION.md)).
9. Start the new web processes; `php artisan queue:restart` so every worker
   reloads code and cached configuration; restart `schedule:work` (a
   per-minute `schedule:run` picks up new code by itself).
10. Start/restart the AI Gateway with its service keys. It refuses to start
    without valid keys, with a development key, with a plaintext Laravel
    URL or with the retired `SERVICE_TOKEN` set (ADR 0053).
11. **Verify:** Laravel `/api/health/ready` and the Gateway `/health/ready`
    return 200; `php artisan platform:operations-status` shows workers
    and the scheduler heartbeating.

Rollback: redeploy the previous artifact and rebuild its caches (steps
4–5). A schema rollback is a separate, deliberate `migrate:rollback
--database=pgsql_admin` against a positively identified database — every
migration has a `down()` (rule 10), but financial and audit records are
corrected forward, never rolled back (ARCHITECTURE.md §10).

## 4. Database roles

- **Runtime role:** `school_os_app` — NOSUPERUSER, NOBYPASSRLS, no
  `DELETE` on the tables that forbid it; used by web, workers and
  scheduler. **The name is a fixed v1 production contract** (O6 resolved
  by ADR 0050); production bootstrap:
  `infrastructure/postgres/production-bootstrap.sql`, verified by
  `platform:verify-database`.
- **Migration/admin role:** owns the tables, creates policies and
  triggers; used only by step 6 and the operator console commands that
  require it (`platform:provision-root`). Set `DB_ADMIN_USERNAME`/
  `DB_ADMIN_PASSWORD` explicitly — without them the admin connection falls
  back to the runtime credentials, and root provisioning refuses to run.
- No platform role ever receives a database privilege (rule 26).
- **The runtime role must never be a member of (or inherit) the owner
  role.** Since Phase 0O.1A the database accepts an out-of-band platform
  grant — how root arrives — only from a role holding the table owner's
  privileges; membership would hand root provisioning to every web
  request.

## 5. Operator console commands (Phase 0O.1)

| Command | Connection | Effect | Audit |
|---|---|---|---|
| `platform:bootstrap-root` | `pgsql_admin` | First boot only (no active root): creates one enabled account — name, email, password through two hidden prompts (no visible fallback, never an argument), `Password::defaults()` — and provisions root, in one transaction; interactive only; no School membership or Group grant | `platform.role_grant.provisioned` (actor null, `method: console`); never the password or its hash |
| `platform:provision-root {user} [--force]` | `pgsql_admin` | Grants the root platform role to one existing, enabled account named by exact email or id; interactive confirmation (type the account's email) unless `--force`; idempotent | `platform.role_grant.provisioned` (actor null, subject the assignment, `role_key`, `user_id`, `method: console`) |
| `platform:service-key-generate {platform\|ai-gateway} --output=<dir> [--kid=] [--non-production]` | none | Phase 0O.7A (ADR 0053): one Ed25519 keypair; the private JWK goes **only** to `<dir>/<kid>.private.jwk` (0600), never printed; the directory must be outside the application; never overwrites; `--non-production` forces the `dev-local-only-` prefix that production refuses. Replaces the retired `platform:service-identity-issue/-disable` | none (keys never enter the database; rotation evidence is operator evidence, [SERVICE-KEY-ROTATION](../operations/SERVICE-KEY-ROTATION.md)) |
| `platform:verify-service-auth` | none | Read-only: legacy token absent; Gateway configured; https; signing kid and age; ring kids, ages and transition end; development keys; Redis replay store; rotation drill = operator evidence. Prints no key material | none |
| `platform:user-password-reset {user}` | runtime | Phase 0O.10A (ADR 0056 §14): sets a new password for one account named by exact email or id — two hidden prompts, `Password::defaults()`, confirmation; interactive only. Through `CredentialChangeService`: every session on every host ends, the remember token is cycled, human personal access tokens are revoked, an elevation ends (`credential_reset`); MFA is unchanged; a security notice follows. **Root's only recovery path** | `auth.password_reset_by_operator`; never the password |
| `platform:account-recovery-status {user}` | runtime | Metadata-only view of one account's recovery requests (ids, times, state, reason, email state); never a secret, hash, link or password | `auth.account_recovery_status_viewed` |

None has an HTTP route or UI. A non-interactive run without `--force`
refuses; `--force` is for trusted operator automation only.

Both root commands write through the migration/admin connection because
the database refuses a grantor-less grant from anything else (Phase
0O.1A). First-account bootstrap is not password reset. Since Phase 0O.10A
(ADR 0056) a lost password is recovered with `platform:user-password-reset`
(root's only path) or, for eligible non-root accounts once
`ACCOUNT_RECOVERY_ENABLED` is deployed with its evidence, by self-service
email recovery.

**Built in Phase 0O.12B (ADR 0059):**
- **The operator bootstrap-account command,
  `platform:provision-school-admin-account {email} [--name=] [--school=] [--hours=24]`:**
  - interactive only (no `--force`); runs only where the operator (admin)
    database connection is configured;
  - typed-email confirmation;
  - creates one credential-less User, or re-issues the link for one still
    credential-less;
  - displays one activation link (secret in the fragment, 1–72 h, default
    24 h) once, on the terminal only;
  - no membership, role, Employee, password or grant;
  - audit `platform.account.provisioned` /
    `platform.account.activation_reissued` (actor null, ids only);
  - runbook: `docs/operations/STAFF-ACCOUNTS.md`.
- **First-School sequence** (ADR 0059 §8, proven by
  `FreshInstallProvisioningTest`):
  1. `platform:bootstrap-root`, then root MFA;
  2. the bootstrap-account command, and its activation;
  3. root creates the School naming that account;
  4. root activates it (needs an activated administrator,
     `admin_not_activated` otherwise);
  5. the School Admin signs in and enrolls MFA;
  6. staff are invited through Settings → Staff accounts (O13 email).
- **Staff off-boarding** (Settings → Staff accounts): suspension revokes the
  School roles; reactivation grants newly chosen roles. No platform path
  after activation.
- **New scheduled task:** `staff-account-credentials-prune` (hourly).
- **E21.2A retention (2026-10-01):**
  - **New daily tasks:** `outbox-prune` and `failed-jobs-prune`. Both are
    covered by OBS-06 with the daily window.
  - **Production sets** the project-adopted periods from
    `docs/security/E21-RETENTION-DETERMINATION.md` §6 (pending legal
    ratification): `MAIL_RETENTION_DAYS=180`,
    `WEBHOOKS_DELIVERY_RETENTION_DAYS=30`,
    `WEBHOOKS_FAILED_DELIVERY_RETENTION_DAYS=90`,
    `OUTBOX_RETENTION_DAYS=30` and `FAILED_JOBS_RETENTION_DAYS=30`.
  - **Holds.** `RETENTION_HOLD_SCHOOL_IDS` lists any School under a legal
    hold.
  - **Before enabling a prune**, run it once with `--dry-run`.
- **E21.2B retention (2026-10-01):**
  - **New daily tasks:** `audit-prune` (03:00), `email-suppressions-prune`
    (03:10) and `authority-history-prune` (03:20).
  - **Privilege model (ADR 0050 database roles).** The runtime role gains
    no DELETE. Migration `2026_11_06_090000` adds narrow `SECURITY DEFINER`
    retention functions: fixed table and predicate, a database age floor, a
    tenant tie, EXECUTE for the runtime role only.
    `platform:verify-database` checks them (`retention_functions_narrow`)
    and still refuses any runtime DELETE on the protected ledgers. The
    scheduler needs no admin credential.
  - **Production sets** `AUDIT_RETENTION_YEARS=7`,
    `AUTHORITY_HISTORY_RETENTION_YEARS=7` and
    `MAIL_RELEASED_SUPPRESSION_RETENTION_YEARS=1` (project-adopted, pending
    ratification).
  - **Holds.** `RETENTION_HOLD_PLATFORM=true` holds every School-less
    record.
- **E21.2C retention (2026-10-01):**
  - **New daily tasks:** `communications-prune` (03:40) and
    `storage-orphans-prune` (04:10).
  - **Privilege model.** Communications content and deliveries are
    ordinary School rows, deleted under RLS. Append-only delivery policy
    decisions use one more narrow function from migration
    `2026_11_07_090000` (`retention_functions_narrow` covers it).
  - **Production sets** `COMMUNICATIONS_CONTENT_RETENTION_YEARS=3`,
    `COMMUNICATIONS_DELIVERY_RETENTION_YEARS=1` and
    `STORAGE_ORPHAN_RETENTION_DAYS=30` (project-adopted, pending
    ratification). `RETENTION_ORPHAN_SCAN_LIMIT` defaults to 10000.
  - **Documents are never purged by age.** Every owner type is deferred to
    E21.2D/E21.2E. Object-store lifecycle expiry of current objects stays
    disabled (D5/D12).
  - **Before enabling,** run both with `--dry-run` and review the
    `unresolved` count: a School whose Academic Years leave gaps or overlap
    keeps that content until its calendar is corrected.
- **E21.2D retention (2026-10-01):**
  - **New daily task:** `student-retention-prune` (04:30).
  - **Privilege model.** Unchanged: ordinary School rows under RLS, no new
    function, grant or migration.
  - **Production sets** `STUDENT_OPERATIONAL_RETENTION_YEARS=7` and
    `STUDENT_CORE_RETENTION_YEARS=25` (project-adopted, pending
    ratification). The core purge also needs
    `AUTHORITY_HISTORY_RETENTION_YEARS` before a revoked Student account
    link may go.
  - **Before enabling,** run it with `--dry-run`:
    - `unresolved exit` counts inactive Students without a dated
      departure, for example never placed or last placement
      `transferred`. They are kept until staff record the departure;
    - `dependency-blocked` counts Students another retained record still
      needs.
- **E21.2E retention (2026-10-01):**
  - **New daily task:** `employee-retention-prune` (04:50).
  - **Privilege model.** Unchanged: no new function, grant or migration.
  - **Production sets** `EMPLOYEE_ANCILLARY_RETENTION_YEARS=2` and
    `EMPLOYEE_EVIDENCE_RETENTION_YEARS=8` (project-adopted, pending
    ratification).
  - **Finance (D8):** there is no setting and no expiry. Posted financial
    evidence is kept until a financial-year close design exists.
  - **Before enabling,** run it with `--dry-run`:
    - `unresolved separation` counts Employees with no employment record,
      or with a terminal one missing its end date;
    - `dependency-blocked` counts Employees another retained record still
      needs (payroll results, teaching history, a linked User).
- **E21.2F (2026-10-01):**
  - **Erasure cases** are operator console only:
    - `platform:erasure-case-open`, `-decide`, `-execute [--dry-run]` and
      `-status`;
    - always `--dry-run` before executing;
    - nothing is scheduled.
  - **Production sets** `ERASURE_CASE_RETENTION_YEARS=7` (project-adopted)
    for closed-case expiry.
  - **School closure** is Close/Reopen on the platform School page (MFA).
    `platform:school-closure-status <school>` is read-only. There is no
    School purge.
- **E21.2G closure audit (2026-10-01):** the complete production retention
  configuration is `docs/security/E21-CLOSURE-AUDIT.md` §9:
  - every retention setting;
  - holds;
  - the PITR window and bucket lifecycle (D12);
  - log and metric backend periods (D13);
  - **NTP-synchronised clocks** on application and database hosts;
  - the scheduler and alerts.

  All technical-TTL prunes now support `--dry-run`.
- **E21.3A financial period close (2026-10-02, ADR 0064):**
  - **Migrations** `2026_11_09_090000` / `090100`:
    - `financial_periods`;
    - `journal_entries.financial_period_id`, with its assigning trigger;
    - the baseline tables;
    - the narrow `finance_assign_journal_entry_period`.

    `platform:verify-database` checks it (`finance_period_functions_narrow`).
    Their `down()` refuses once any period is closed or any baseline exists.
  - **Release step, once per environment, after migrating:** run
    `platform:finance-periods-backfill --dry-run`, review the counts, then
    run it without `--dry-run`. Entries that stay `ambiguous` keep their
    year closable only after an explicit decision. Then run
    `platform:finance-balances-verify`, which must report
    `verification=passed`.
  - **Seeder:** `finance.periods.manage` (School Admin) must be seeded
    (`DatabaseSeeder`).
  - **Behaviour change:** the financial-year start month freezes at a
    School's first posting, not its first receipt.
  - **Closing a year** is an irreversible School Admin action (typed key +
    fresh MFA) under Finance → Financial periods. Nothing is scheduled.
  - **No Finance expiry exists.** Finance retention needs E21.3A2.
- **E21.3A2 Finance retention cutover (2026-10-02, ADR 0064 §14–§24):**
  - **Migration** `2026_11_10_090000`:
    - `financial_period_expiries`;
    - `retention_expire_finance_unit`, checked by `platform:verify-database`
      (`retention_functions_narrow`);
    - the three guard patches.

    Its `down()` refuses once any unit has expired.
  - **Reads switch at deploy:** carry-forward + later detail is the only
    production path. Run `platform:finance-balances-verify` after deploying.
  - **New daily task:** `finance-retention-prune` (05:10), covered by OBS-06.
    It is a clean no-op until **both** `FINANCE_RETENTION_YEARS=8` (>= 8;
    less is refused) and `FINANCE_RETENTION_ENABLED=true` are set (default
    off).
  - **Enable only by the runbook** `docs/operations/FINANCE-RETENTION.md`:
    1. backfill;
    2. resolve every ambiguous entry;
    3. close the historical years (the clock starts at that close);
    4. verify;
    5. configure and enable;
    6. dry run, review, run, verify.
  - **Holds:** `RETENTION_HOLD_SCHOOL_IDS` stops it per School.
  - **Payroll-linked detail is never expired by D8** (D9 evidence; ADR 0064
    §21).
- **E21.3B Student-linked residual expiry (2026-10-02):**
  - **Migration** `2026_11_11_090000`: the two Student-core evidence
    functions (`retention_expire_student_processing_authorizations`,
    `retention_expire_student_consent_events`, checked by
    `platform:verify-database` `retention_functions_narrow`) and the
    processing-authorization guard patch. Its `down()` removes only the
    mechanism and restores the original guard.
  - **`student-retention-prune` widens** under the existing
    `STUDENT_*_RETENTION_YEARS`: returned Library loans and ended
    Transport/Hostel assignments go with the operational phase (7 y); the
    core record (25 y) takes its processing authorizations, converted
    admissions, Student-subject consent and preferences with it. Run it
    with `--dry-run` first: an open loan or assignment now shows as
    `dependency-blocked` (close the loan/assignment or leave the Student).
  - **New daily task:** `portal-invitations-prune` (04:20), covered by
    OBS-06. Production sets `PORTAL_INVITATION_RETENTION_DAYS=7`
    (project-adopted, pending ratification); unset deletes nothing.
  - **Holds:** `RETENTION_HOLD_SCHOOL_IDS` stops both per School.
- **E21.3C Admissions and Guardian lifecycle markers (2026-10-02):**
  - **Migration** `2026_11_12_090000`: `admission_applications.terminal_at`
    and `guardians.no_relationship_since` (existing rows start NULL), their
    triggers, and `retention_expire_guardian_consent_events` (checked by
    `platform:verify-database`). Its `down()` refuses once any marker
    carries evidence.
  - **Before enabling,** run `platform:lifecycle-markers-backfill --dry-run`,
    then without it: rows without trustworthy audit evidence stay
    unresolved and kept (closure readiness reports
    `retention_trigger_unresolved`).
  - **New daily tasks:** `admissions-retention-prune` (04:35) and
    `guardian-retention-prune` (04:40), covered by OBS-06. Production sets
    `ADMISSIONS_TERMINAL_RETENTION_YEARS=1` and `GUARDIAN_RETENTION_YEARS=1`
    (project-adopted, pending ratification); the Guardian run also needs
    `AUTHORITY_HISTORY_RETENTION_YEARS` before a revoked account link may
    go. Unset deletes nothing; run each with `--dry-run` first.
  - **Holds:** `RETENTION_HOLD_SCHOOL_IDS` stops both per School.
- **E21.3D year-bound academic operations (2026-10-02):**
  - **Migration** `2026_11_13_090000`: `academic_years` dates become
    immutable (trigger), and the two LMS functions
    `retention_expire_learning_content` / `retention_expire_assignment`
    (checked by `platform:verify-database`). Its `down()` removes only the
    mechanism.
  - **New daily task:** `academic-retention-prune` (04:45), covered by
    OBS-06. Production sets `ACADEMIC_OPERATIONS_RETENTION_YEARS=7`
    (project-adopted, pending ratification); teacher-owned LMS also needs
    `AUTHORITY_HISTORY_RETENTION_YEARS`. Unset deletes nothing; run it with
    `--dry-run` first (a register header with Student records left, or an
    entry a header references, shows as dependency-blocked).
  - **Holds:** `RETENTION_HOLD_SCHOOL_IDS` stops it per School.
- **E21.3E communications and platform residuals (2026-10-02):**
  - **Migration** `2026_11_14_090000`: `retention_expire_api_client_credentials`
    (checked by `platform:verify-database`). Its `down()` drops only the
    function.
  - **`communications-prune` gains `--only=residual`** (never-sent
    cancelled/rejected announcements, empty threads) under
    `COMMUNICATIONS_ABANDONED_RETENTION_YEARS=1`.
  - **`authority-history-prune` gains ended API credentials** under the
    existing `AUTHORITY_HISTORY_RETENTION_YEARS=7`.
  - **New daily task:** `operations-retention-prune` (04:48), covered by
    OBS-06: `OPERATIONS_DRIVER_ASSIGNMENT_RETENTION_YEARS=7`,
    `OPERATIONS_VISIT_RETENTION_YEARS=1`,
    `OPERATIONS_AUTOMATION_RETENTION_YEARS=1` (project-adopted, pending
    ratification). Unset skips a category; run with `--dry-run` first
    (visits never checked out show as unresolved).
  - **Holds:** `RETENTION_HOLD_SCHOOL_IDS` stops all of them per School.
- **E21.3F payroll evidence retention (2026-10-02):**
  - **Migration** `2026_11_15_090000`: `retention_expire_payroll_employee_evidence`
    and `retention_expire_payroll_run` (checked by `platform:verify-database`),
    the guarded `payroll_runs.results_expired_at` marker and the three
    flag-gated freeze-trigger allowances. Its `down()` removes the
    mechanism and refuses once any run carries the marker.
  - **New daily task:** `payroll-retention-prune` (05:20), covered by
    OBS-06. It uses the existing `EMPLOYEE_EVIDENCE_RETENTION_YEARS=8`
    (project-adopted, pending ratification): **code complete is not
    production configured**, and once that value is set this task also
    expires posted payroll evidence. It refuses a value under 8; unset
    deletes nothing. Run it with `--dry-run` first (a draft/approved run,
    a late reversal or correction, or unposted statutory results show as
    dependency-blocked).
  - **Finance:** released payroll journal entries expire only through
    `finance-retention-prune` (`FINANCE_RETENTION_ENABLED`), never through
    Payroll.
  - **Holds:** `RETENTION_HOLD_SCHOOL_IDS` stops it per School.
- **E21.4 User identity minimization (2026-10-03):**
  - **Migration** `2026_11_16_090000`: revokes the runtime role's DELETE on
    `users` (checked by `platform:verify-database`), adds
    `users.minimized_at` with its shape and immutability rules, and the
    not-minimized guards. Its `down()` refuses once any User is minimized
    and never re-grants DELETE.
  - **No new task or setting:** minimization runs only from an approved
    platform erasure case (`platform:erasure-case-execute`); it is never
    scheduled. Run `platform:erasure-case-execute --dry-run` first.
  - **Operators:** a minimized User cannot be restored; a returning person
    is invited as a new User.

## 6. Seeding: production-safe versus demo

| Seeder | Production | Local / testing |
|---|---|---|
| `CapabilityAndRoleSeeder`, `EducationBoardSeeder`, `StatutoryRuleVersionSeeder` | Yes (via `DatabaseSeeder`) | Yes |
| `ServiceIdentitySeeder` (dev token → `ai-gateway`) | **Refuses** (throws) | Yes |
| `DatabaseSeeder`'s test user | No | Yes |
| `Demo\DemoSeeder` | **Refuses** | DDEV only (`ddev demo-reset`) |

## 7. What stays open

O2–O16 are resolved (ADR 0049 to ADR 0057). O9's, O13's and O14's
repository implementations are Phases 0O.8A, 0O.9A and 0O.10A, with
deployment evidence outstanding. O2 (the first real payment gateway) and
O15 (broader third-party integrations and production partner API scopes)
were resolved by ADR 0057 (Phase 0O.11), mostly by explicit deferral. Its
one required v1 correction, manual/offline payment recording, is Phase
0O.11A — **COMPLETE (repository)**. It is an ordinary authenticated School
browser action: no provider, credential, callback or configuration switch
to deploy, and no new production environment variable. Still open: O1
(definition of done — including a real restore drill).

**O1 (Phase 0O.12, ADR 0058, 2026-09-28): defined, not satisfied.**
ADR 0058 §5 is the definition of done and §6 the evidence register. Before
any production publication or promotion:
- **`main` must actually be protected on GitHub.** On 2026-09-28 it was not
  (`"protected": false`, no rulesets). The release workflow proves only
  that a commit is on `main`.
- **A fresh qualification must run after protection is active.** The
  `c4b1b6c`, `e52c4c4` and `be69b55` (latest, E16) artifacts are not the
  release to promote. Their vulnerability exceptions (since 2026-09-29,
  `OWNER-0O-E16-2026-09-29`) expire 2026-10-29, with no automatic renewal.

Closing Phase 0O never authorizes go-live: that is a separate rule-16
decision.

(Corrected 2026-09-28: this section previously said O14's implementation
had not started and called O15 "partner integrations".)

**Account recovery (ADR 0056, implemented in 0O.10A).** Self-service
recovery exists but is off by default. `platform:user-password-reset` is the
operator console path, and root's only one:
- `ACCOUNT_RECOVERY_ENABLED` is `false` by default;
- enabling it in production is refused while critical email is disabled
  (`account_recovery_email_disabled`);
- nothing is issued while `criticalEmailAvailable()` is false;
- deploying it signs every user out once (the credential-version
  middleware).

**Email (ADR 0055, implemented in 0O.9A).** Every business email goes through
the durable email layer.

**Disabled mode.** `MAIL_PROVIDER=none` (the default) is a complete,
explicit mode: messages wait and nothing claims to be sent.

**Always refused in production:**
- `mail_provider_fake` / `mail_provider_invalid`;
- `mail_events_adapter_fake` / `mail_events_adapter_invalid`;
- `mail_unsafe_mailer_default` (a failover/roundrobin/sendmail default
  mailer);
- `mail_provider_debug_enabled`;
- `mail_tracking_enabled`.

**Also refused when email is enabled:**
- `mail_laravel_default_is_sink` (a log/array default);
- `mail_sending_domain_missing` / `_invalid`;
- `mail_from_not_catalog`;
- `mail_suppression_keys_invalid`.

**For SMTP:**
- `mail_smtp_host_missing` / `mail_smtp_host_local`;
- `mail_smtp_tls_not_required`;
- `mail_smtp_credentials_missing`;
- `mail_smtp_timeout_unbounded`.

**With an event adapter:** `mail_event_secrets_invalid`.

**At run time**, nothing is submitted while the sending domain is not
reserved (`sending_domain_not_reserved`) or `MAIL_SENDING_VERIFIED` is false
(`sending_not_verified`); messages wait durably.

See `docs/operations/EMAIL-DELIVERABILITY.md`.

**Hosts and custom domains (ADR 0054, implemented in 0O.8A):** every Host is
classified exactly; anything unexpected answers 421. Production refuses to
boot (codes only) with a shared `SESSION_DOMAIN` (`session_domain_shared`),
a non-https or malformed `APP_URL` (`platform_url_invalid`), the development
Host or fake DNS/TLS switches (`domain_development_hosts_enabled`,
`domain_fakes_enabled`), a malformed host list (`domain_host_list_invalid`),
a non-Redis handoff store (`session_handoff_store_not_redis`), or the AI
Gateway configured without `INTERNAL_HOSTS` (`internal_hosts_missing`). Only
with `CUSTOM_DOMAINS_ENABLED=true` does it also require the edge target
(`domain_edge_target_missing`/`_invalid`, public `DOMAIN_EDGE_ADDRESSES`),
`DOMAIN_PROBE_KEY` (≥ 32 characters, an `app_runtime` secret;
`domain_probe_key_invalid`) and public `DOMAIN_DNS_RESOLVERS`
(`domain_dns_resolvers_missing`/`_invalid`). Disabled is a complete, safe
mode. See `docs/operations/CUSTOM-DOMAINS.md`. **No production image is pushed to a registry or promoted
before Phase 0O.6A's repository controls are complete**, and then only by an
authorized operator. Those controls now exist (Phase 0O.6A): **NO PRODUCTION
REGISTRY IS CONFIGURED, NO REAL SIGNING IDENTITY/KEY IS CONFIGURED, NO
PRODUCTION IMAGE HAS BEEN PUSHED, NO PRODUCTION IMAGE HAS BEEN PROMOTED.**

**Supply chain (ADR 0052):** a release is one immutable OCI image digest per
image, built once from a commit on protected `main` and qualified by the
complete regression; its SBOM, scan, provenance and signature are verified by
one fail-closed verifier **before** promotion and **before** the maintenance
window opens (ADR 0052 §3.19). Only a PROMOTED digest is deployed; rollback
redeploys an earlier promoted digest after re-verification — never a rebuild.

**Observability (ADR 0051, implemented in 0O.5A):** production refuses to
boot unless logs are JSON (`log_format_not_structured`) and
`METRICS_SCRAPE_TOKEN` is a real secret of at least 32 characters
(`metrics_scrape_token_invalid`). A deployment must provide a collector
that reads container stderr (JSON logs, kept ≥ 30 days) and scrapes the web
role's private port 9102 `/metrics` with the bearer token (metrics kept
≥ 90 days), load `docs/operations/alerts/lycenza-alerts.rules.yml`
(re-rendered with its operator values by `console platform:alerts-export`),
route notifications, and mount the backup/drill evidence file
(`OBSERVABILITY_DEPLOYMENT_EVIDENCE_FILE`). **None of this is active yet.** Signing-key
rotation and a key id remain deferred (ADR 0023). `docker-compose.yml`'s
local worker still works only `default` (a local-development gap recorded
by the readiness audit, not changed here).
