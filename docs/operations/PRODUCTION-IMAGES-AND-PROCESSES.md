# Production images and processes

ADR 0050 §1, §3, §4, §12. Provider-neutral: any container platform that
can run these images, inject environment variables from a secret store and
supervise processes will do. No orchestrator manifest is committed.

## Images

| Image | Dockerfile | Build context | Base |
|---|---|---|---|
| Application | `infrastructure/docker/production/app.Dockerfile` | `apps/platform` | runtime `debian:13.7-slim@sha256:a99cfc51…` with a **repository-built PHP 8.3.35** (curl 8.22.0, libxml2 2.15.4) since Phase 0O.6D — [CUSTOM-PHP-RUNTIME.md](CUSTOM-PHP-RUNTIME.md); build-only `php:8.3.35-fpm-trixie@sha256:e0623b71…`, `node:22.23.3-bookworm-slim@sha256:43ac6c60…`, `composer:2.10.2@sha256:4d71c3c2…` |
| AI Gateway | `infrastructure/docker/production/ai.Dockerfile` | `services/ai` | `python:3.14.7-slim-trixie@sha256:51dafde8…` — Debian 13 since Phase 0O.6B, CPython 3.14 since Phase 0O.6C |

Since Phase 0O.6A (ADR 0052) every base image is pinned **by digest**
(`image:exact-version@sha256:…`; the tag is a readable alias) and a digest
update is an ordinary reviewed change that re-runs full release
qualification. Releases are built, scanned, attested and verified by
`infrastructure/release/qualify` and `verify-artifact`
([RELEASE-QUALIFICATION.md](RELEASE-QUALIFICATION.md)); no image is
published or promoted by the repository.
The local development Dockerfiles (`infrastructure/docker/*.Dockerfile`)
stay local-only.

**Application image.** Multi-stage: PHP extensions (`pdo_pgsql`, `pgsql`,
`bcmath`, `gd`, `opcache`, `pcntl`, `zip`) → `composer install --no-dev`
from `composer.lock` with **no Composer plugin and no package script**
(`--no-scripts --no-plugins`; Laravel's `package:discover` is the one
explicit step) and an optimized autoloader → `npm ci` with the repository
`.npmrc` in effect (`ignore-scripts=true`), then `npm run build` → a runtime stage
with nginx and PHP-FPM only (Phase 0O.6B: the PHP base image's extension
build toolchain, libc headers, curl CLI and xz-utils are purged from it). It contains no `.env`, tests, PHPUnit
configuration, dev Composer packages, `node_modules`, demo seeders (only
`DemoEnvironmentGuard` remains, the class that refuses demo behaviour),
the test-database reset command or cached configuration
(`apps/platform/.dockerignore`). It runs as `www-data`, OPcache on with
timestamp validation off, `expose_php` off, and never uses
`php artisan serve`. Image defaults: `APP_ENV=production`,
`DB_CONNECTION=pgsql`, `APP_MAINTENANCE_DRIVER=cache`,
`APP_MAINTENANCE_STORE=database`, `LOG_CHANNEL=stderr`,
`REDIS_CLIENT=predis` — no secret.

**AI Gateway image.** Runtime dependencies only, installed from the fully
resolved, hash-locked `requirements.lock` (`pip install --require-hashes
--no-deps --only-binary=:all:` — every file hash-checked, wheels only, no
source build; never `requirements-dev.txt`), non-root user `gateway`, `ENVIRONMENT=production`,
no provider SDK (NullProvider only). It refuses to start without
`SERVICE_TOKEN` or with the development token; readiness is 503 when the
configuration is unsafe.

## Roles (one application image, selected by command)

`apps/platform/deploy/entrypoint.sh`:

| Command | Process | Notes |
|---|---|---|
| `web` | nginx (port 8080) + PHP-FPM (loopback 9000) | Builds config/route/view caches from the injected environment first — the production guard refuses unsafe configuration before anything is served. Exits if either process dies; drains gracefully on SIGTERM. Only `public/index.php` executes; dotfiles are never served. |
| `worker <queue>` | `queue:work --queue=<queue> --timeout=60 --max-time=3600` | Only `default`, `integrations`, `notifications` are accepted. `--timeout` 60 < `retry_after` 90 (rule 58). No `--force`: workers pause during maintenance. |
| `scheduler` | `schedule:work` | Exactly one per deployment. |
| `console <artisan args>` | one artisan command | Release steps and operator commands. |

## Process manifest

`apps/platform/deploy/processes.json` lists every process, its command,
health endpoints and the **secret groups** it receives. Guarded by
`Tests\Feature\Configuration\ProductionProcessManifestTest`:

- the worker queues equal exactly the queues the code dispatches to
  (`QueueName` references; a literal queue name in code fails the test);
- exactly one `scheduler`, marked `singleton`;
- long-running roles (web, workers, scheduler, Gateway) never receive
  `database_admin` (`DB_ADMIN_USERNAME`, `DB_ADMIN_PASSWORD`); only
  `release` and `operator-console` do;
- every secret-shaped setting the configuration reads is in a secret group;
- every recovery sweep (`RecoverQueuedWork::SOURCES`) is scheduled.

| Secret group | Contents | Given to |
|---|---|---|
| `app_runtime` | `APP_KEY`, `APP_PREVIOUS_KEYS`, `MAIL_PASSWORD`, `DB_PASSWORD`, `REDIS_PASSWORD`, `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` (or none: the platform's credential chain), `AI_GATEWAY_CONTEXT_SIGNING_KEY`, `AI_GATEWAY_SERVICE_TOKEN`, both lookup HMAC keys | web, workers, scheduler, release, operator console |
| `database_admin` | `DB_ADMIN_USERNAME`, `DB_ADMIN_PASSWORD` | release, operator console only |
| `gateway` | `SERVICE_TOKEN` | AI Gateway only |

Both PostgreSQL connections bound connection establishment with
`DB_CONNECT_TIMEOUT` (default 5 s, `PDO::ATTR_TIMEOUT`; pdo_pgsql otherwise
waits 30 s and ignores `PGCONNECT_TIMEOUT`), so an unreachable database
fails a request in seconds instead of holding a PHP-FPM worker.

## Runtime security contract (Phase 0O.6F, ADR 0052 amendment)

Every production container of **both** images, in every role, MUST be
started with the provider-neutral contract in
[`infrastructure/release/runtime-security.json`](../../infrastructure/release/runtime-security.json).
It is a deployment requirement, not a runtime default: do not assume a
platform's defaults are sufficient.

| Field | Required value |
|---|---|
| `privileged` | `false` — never a privileged container |
| `cap_drop` | `ALL` — every Linux capability dropped |
| `cap_add` | none — a capability may be added back only by a later ADR |
| `no_new_privileges` | `true` — setuid/setgid/file capabilities never raise privilege |
| user | the image's existing non-root user: `www-data` (uid 33) for the application, `gateway` (uid 10001) for the Gateway — never root, never a platform-chosen user override to 0 |

Reference invocation (Docker): `--cap-drop ALL --security-opt no-new-privileges:true`.
Equivalents:
- Compose: `cap_drop: [ALL]`, `security_opt: [no-new-privileges:true]`, no `privileged`/`cap_add`.
- Kubernetes: `securityContext` with `privileged: false`, `allowPrivilegeEscalation: false`, `capabilities.drop: [ALL]` and `runAsNonRoot: true`.
- Podman: `--cap-drop=all --security-opt=no-new-privileges`.

A platform that cannot express a field **blocks deployment**. The
contract's own `provider_equivalents` is authoritative. No orchestrator
manifest is committed for this.

**Image-level hardening.**
- `mount` and `umount` carry no setuid bit in either image. It is removed
  through Debian's `dpkg-statoverride`, recorded in the dpkg database; no
  Essential file is removed. Nothing in either image mounts filesystems.
- `/etc/fstab` has no active entry at all, and no user-mountable one
  (`user`, `users`, `owner`, `group`, `bind`, `X-mount.*`).
- The remaining setuid/setgid programs (`su`, `passwd`, `chfn`, `chsh`,
  `gpasswd`, `newgrp`, `chage`, `expiry`, `unix_chkpwd`) gain nothing under
  no-new-privileges. `verify-images.sh` proves it: `chage -l` reads
  `/etc/shadow` in an unhardened control and is refused under the contract.

**No root process.** No role runs anything as root:
- web: the entrypoint, nginx master and workers, the PHP-FPM master and pool;
- workers, scheduler, release and operator-console commands;
- the Gateway's uvicorn.

`verify-images.sh` checks `/proc/<pid>/status` for every process of the web,
worker and scheduler roles and of the Gateway:
- Uid non-root;
- CapPrm, CapEff, CapBnd and CapAmb all zero;
- NoNewPrivs 1.

**Why it is required.** Five approved vulnerability exceptions are
**conditional** on this contract (owner decision `OWNER-0O6E-2026-09-26`):
the util-linux mount advisories and the two acl advisories. `verify-artifact`
applies them only with the signed evidence that these checks passed for the
exact artifact. Deploying without the contract breaks the condition of that
approval.

**Writable paths / read-only root filesystem (future, not required).**
Audited with `docker diff` of hardened containers:
- The application writes `/tmp/nginx` (pid and temp paths),
  `/var/www/app/bootstrap/cache` (config/route caches) and
  `/var/www/app/storage/framework/views` (compiled views).
- The Gateway writes nothing.

A `--read-only` root with *empty* writable mounts over `storage` and
`bootstrap/cache` fails at start, because `view:cache` needs the
`storage/framework` tree. A future change must pre-create it, or warm the
caches at build time. The Gateway already runs read-only with a `/tmp` tmpfs.

## Logs and metrics (Phase 0O.5A, ADR 0051)

Every role logs one JSON object per line to stderr (`LOG_FORMAT=json`,
`LOG_LEVEL=info`, `process_role` from the entrypoint); the container
runtime collects them. The `web` role also listens on **port 9102** — the
private metrics listener: `GET /metrics` with `Authorization: Bearer
<METRICS_SCRAPE_TOKEN>`, Prometheus text format, operational values only.
Expose 9102 only to the deployment's collector (never through the public
TLS proxy); it keeps answering during a maintenance window. The AI Gateway
logs the same JSON schema.

## Health

| Process | Liveness | Readiness |
|---|---|---|
| web | `GET /api/health/live` (never touches PostgreSQL/Redis; stays 200 during maintenance) | `GET /api/health/ready` (PostgreSQL + Redis over bounded, TLS-honouring probe connections; 503 during maintenance or when the PostgreSQL-held maintenance flag is unreadable) |
| AI Gateway | `GET /health/live` | `GET /health/ready` |
| workers, scheduler | process supervision (restart on exit) | — |

Load balancers route on readiness; the Gateway is never public.

## Required production configuration (refused at boot otherwise)

`ProductionConfigurationGuard` codes, never values (Phase 0O.1, 0O.3,
0O.4A): debug off; valid `APP_KEY`; secure session cookie; AI signing key
set and not a placeholder; no development service token; CORS allowlist
valid; `TRUSTED_PROXIES` explicit (never trust-all); `DB_CONNECTION=pgsql`;
`DB_SSLMODE` `require`/`verify-ca`/`verify-full` (runtime **and** admin);
`REDIS_PASSWORD` set; shared maintenance mode (`cache` driver); Documents
and Communication attachments on `s3`; a production bucket (never
`school-os-local`/`school-os-test`); an HTTPS endpoint (or none, for the
provider default); storage credentials both set or both absent and never
the committed local values; no public visibility; an `APP_URL` that is not
local/DDEV and a database that is not the test database.

## Local verification (no deploy)

```bash
infrastructure/docker/production/verify-images.sh --build   # builds lycenza-app:verify, lycenza-ai-gateway:verify
```

It inspects image contents, starts the web role against a throwaway
password-protected Redis with fake credentials, checks nginx/PHP-FPM
serving, headers, spoofed-proxy behaviour and HSTS through a trusted proxy,
starts every worker and the scheduler, checks the production route table
has no probe/demo route, and checks the Gateway's refusals and health. Since
Phase 0O.6F every application and Gateway container it starts uses the runtime
security contract's reference invocation, and it proves the contract from the
kernel (above). It pushes nothing and removes every container it started.
