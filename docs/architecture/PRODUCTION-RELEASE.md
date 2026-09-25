# Production process and release contract

**Status: repository contract (Phase 0O.1, 2026-09-25).** This document
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
| `ai_service_token_development_value` | `AI_GATEWAY_SERVICE_TOKEN` is the public `dev-local-only-token` (an empty token is allowed: the Gateway is optional) |

The refusal names codes only, never a value. A refused web request gets a
plain 500 (debug is forced off for the refusal, so no debug page is ever
rendered); PHP's `display_errors` must be off in production, as in
`php.ini-production`. Independently of the environment, the AI context
signing key fails closed everywhere: without it no context token is issued
or accepted (`AiContextSigningKeyNotConfiguredException`).

The AI Gateway (`services/ai/app/core/startup.py`) refuses to start — and
reports 503 on `/health/ready` — without `SERVICE_TOKEN` in any
environment, or with the public development token unless `ENVIRONMENT` is
exactly `local` or `testing`. `ENVIRONMENT` defaults to `production` and
`SERVICE_TOKEN` has no default.

One service-token value is used in three places (decision O5 may change
this): Laravel `AI_GATEWAY_SERVICE_TOKEN`, the Gateway `SERVICE_TOKEN`, and
the hashed `ai-gateway` row in `service_identities`.

## 3. Release order

Each step's invariant is in bold. Every step that talks to a database
names the database it is about to touch first (rule 54).

1. **Build once, from a clean checkout of the released commit:**
   `composer install --no-dev --optimize-autoloader`, `npm ci && npm run
   build`. No `.env` file is part of the artifact.
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
   In production `DatabaseSeeder` does not call `ServiceIdentitySeeder`,
   which itself refuses outside `local`/`testing`. `DemoSeeder` refuses
   anywhere but a developer's DDEV (`DemoEnvironmentGuard`).
8. **Operator-only, first release only:**
   - `php artisan platform:bootstrap-root` — interactive, creates the first
     platform account and provisions root (§5); later root accounts use
     `php artisan platform:provision-root <exact email or user id>` for an
     account that already exists;
   - `php artisan platform:service-identity-issue ai-gateway
     --capability=ai.tools.invoke --capability=ai.audit.write` — prints the
     credential once; the operator installs it as both
     `AI_GATEWAY_SERVICE_TOKEN` and the Gateway's `SERVICE_TOKEN`.
9. Start the new web processes; `php artisan queue:restart` so every worker
   reloads code and cached configuration; restart `schedule:work` (a
   per-minute `schedule:run` picks up new code by itself).
10. Start/restart the AI Gateway with the same token; it refuses to start
    if the token is absent or the development value.
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
  scheduler. **The name is fixed by the implementation** (`TenantRls`
  defaults and migrations grant to it by name); whether to keep it as a
  production contract or generalize it is decision **O6, still open**.
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
| `platform:service-identity-issue {slug} --capability=… [--name=] [--force]` | runtime | New identity; capabilities limited to `ai.tools.invoke`, `ai.audit.write`; credential shown once, stored only as a hash; existing slug refused (no rotation — O5) | `platform.service_identity.issued` (slug, capabilities; never the credential) |
| `platform:service-identity-disable {slug} [--force]` | runtime | Disables an identity; its calls fail at once; idempotent | `platform.service_identity.disabled` |

None has an HTTP route or UI. A non-interactive run without `--force`
refuses; `--force` is for trusted operator automation only.

Both root commands write through the migration/admin connection because
the database refuses a grantor-less grant from anything else (Phase
0O.1A). First-account bootstrap is not password reset: O14 (reset and
account recovery) stays open, so an operator who loses the first
account's password has no in-app recovery yet.

## 6. Seeding: production-safe versus demo

| Seeder | Production | Local / testing |
|---|---|---|
| `CapabilityAndRoleSeeder`, `EducationBoardSeeder`, `StatutoryRuleVersionSeeder` | Yes (via `DatabaseSeeder`) | Yes |
| `ServiceIdentitySeeder` (dev token → `ai-gateway`) | **Refuses** (throws) | Yes |
| `DatabaseSeeder`'s test user | No | Yes |
| `Demo\DemoSeeder` | **Refuses** | DDEV only (`ddev demo-reset`) |

## 7. What stays open

O2–O16 in `PHASE-0O-READINESS.md` §8 are unchanged by this contract —
among them the hosting model and process manager (O3), secrets manager
(O4), service-token rotation or replacement (O5), the runtime role name
(O6), API client model (O7), object storage (O8), backup/restore (O10),
security headers and CORS (O11) and observability (O12). Signing-key
rotation and a key id remain deferred (ADR 0023). `docker-compose.yml`'s
local worker still works only `default` (a local-development gap recorded
by the readiness audit, not changed here).
