# Maintenance-window release (v1)

ADR 0050 §14: the migration history contains destructive changes and no
expand/contract policy, so **v1 releases are single-version, inside a
maintenance window**. Rolling or zero-downtime releases are not supported.
The full checklist is `docs/architecture/PRODUCTION-RELEASE.md` §3; this
runbook is the process-level sequence. **Deploy-gated.**

## How maintenance mode behaves (audited, Phase 0O.4A)

`php artisan down` (console role) with the production defaults
`APP_MAINTENANCE_DRIVER=cache`, `APP_MAINTENANCE_STORE=database`:

| Surface | Behaviour | Proof |
|---|---|---|
| Flag scope | One flag for **every** container, stored in PostgreSQL's `cache` table — survives a Redis loss. A `file` driver (per container) is refused at boot (`maintenance_mode_not_shared`). | `ProductionConfigurationGuardTest` |
| Liveness `/api/health/live` | **200** — the process is alive and must not be restarted (rule 55) | `MaintenanceWindowTest` |
| Readiness `/api/health/ready` | 503 — the load balancer stops routing | `MaintenanceWindowTest` |
| Pages and API (`/login`, `/api/v1/*`) | 503 (JSON for API clients) | `MaintenanceWindowTest` |
| Queue workers | Pause: they take no job while down (no `--force` in the entrypoint) and resume afterwards | `MaintenanceWindowTest` |
| Scheduler | Runs no event: no scheduled command opts into maintenance mode (including the heartbeat — diagnostics will show a stale heartbeat during the window, which is expected) | `MaintenanceWindowTest` |
| AI Gateway | Unaffected (separate process); its ERP tool calls get 503 and fail safely | — |

Because workers and the scheduler stand still, a half-migrated schema is
never worked on.

## Sequence

0. **Artifact verification comes first (ADR 0052 §3.19).** The images to
   deploy are PROMOTED digests whose SBOM, scan, provenance and signature
   passed the aggregate verifier (`infrastructure/release/verify-artifact`,
   [RELEASE-QUALIFICATION.md](RELEASE-QUALIFICATION.md)). Never open a
   maintenance window to discover an unsigned or failing image.
1. **Build once** — release qualification (`infrastructure/release/qualify`)
   builds both images from the released commit, runs `verify-images.sh`
   against exactly those images and produces their evidence. Publishing to
   a registry is deploy-gated and happens only for a VERIFIED digest, by
   digest (a digest-preserving copy — never a rebuild).
2. **Announce** the window.
3. `console down --retry=60` (old image). Readiness goes 503; workers pause;
   the scheduler idles.
4. Wait for in-flight jobs to finish (≤ the worker `--timeout`, 60 s), then
   **stop** the old workers and the scheduler.
5. **Backup checkpoint:** note the current PITR position (or take an
   on-demand snapshot) so a failed migration can be rolled back to it.
6. **Release** (new image, `release` role — the only process with
   `database_admin`): `console migrate --database=pgsql_admin --force`,
   then `console db:seed --force`.
7. **First release only:** `console platform:bootstrap-root`
   (interactive). **Only if the AI Gateway is deployed:** generate the two
   ADR 0053 service keys with `console platform:service-key-generate
   platform|ai-gateway` and install them through the secret store
   (`PRODUCTION-RELEASE.md` step 8; `SERVICE-KEY-ROTATION.md`).
   `platform:service-identity-issue` and the symmetric `SERVICE_TOKEN` were
   retired in Phase 0O.7A. (Corrected 2026-09-29.)
8. Start the new `web` processes (they build their caches from the
   injected environment; an unsafe configuration refuses here). Liveness
   200, readiness 503 while down.
9. `console up`. Readiness 200.
10. Start the new workers (`default`, `integrations`, `notifications`) and
    the single scheduler. `console queue:restart` is only needed if an old
    worker is still running.
11. If the AI Gateway is deployed: start/restart it if its image, service
    keys or verification ring changed.
12. **Verify:** readiness 200 (web and Gateway),
    `console platform:verify-database`, `console platform:operations-status`,
    a sign-in, one queued flow (e.g. a settings change reaching its
    consumers). `console platform:recover-queued-work` is safe to run at any
    time and re-dispatches nothing that is already being processed.

## Rollback

**Rollback contract (ADR 0074 §26.7, PPR.1; one contract for every
hardened environment, staging and production).** Application rollback
never runs a migration `down()`.

- **Application rollback.** Redeploy the previous **promoted digest**, with
  the database state preserved, after re-verifying the digest's retained
  evidence (`RELEASE-QUALIFICATION.md` → Rollback). Never rebuild an old
  commit, and never roll back automatically. Allowed **only** when the
  earlier image is compatible with the **current** schema. Decide before
  step 6 whether the release is application-rollback-safe; a release whose
  migrations the previous image cannot run against is not.
- **Database correction.** A defect introduced by a migration is corrected
  by:
  - an explicitly reviewed **forward corrective migration** (preferred); or
  - for a true recovery event, an approved restore to the step-5 PITR
    checkpoint (`BACKUP-AND-RESTORE.md`, a rule-16 operator action), then a
    redeploy of the previous image.

  Financial and audit records are always corrected forward, never rolled
  back (ARCHITECTURE.md §10).
- **`down()`.** CLAUDE.md rule 10 still requires a working (or deliberately
  refused) `down()`. It is a development and test guarantee, proven by the
  migration rollback tests on disposable databases, and **never** the
  routine rollback path in staging or production. Two retention-fence
  migrations refuse `down()` by design (`DATABASE-BOOTSTRAP.md`).
