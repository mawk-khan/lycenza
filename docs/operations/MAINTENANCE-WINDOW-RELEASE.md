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
   passed the aggregate verifier (Phase 0O.6A). Never open a maintenance
   window to discover an unsigned or failing image.
1. **Build once** from the released commit (both images), run
   `verify-images.sh` against them in CI or locally. Push to the registry
   (deploy-gated; after ADR 0052's verification, by digest).
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
   (interactive) and `console platform:service-identity-issue ai-gateway …`
   (`PRODUCTION-RELEASE.md` step 8).
8. Start the new `web` processes (they build their caches from the
   injected environment; an unsafe configuration refuses here). Liveness
   200, readiness 503 while down.
9. `console up`. Readiness 200.
10. Start the new workers (`default`, `integrations`, `notifications`) and
    the single scheduler. `console queue:restart` is only needed if an old
    worker is still running.
11. Start/restart the AI Gateway if its image or token changed.
12. **Verify:** readiness 200 (web and Gateway),
    `console platform:verify-database`, `console platform:operations-status`,
    a sign-in, one queued flow (e.g. a settings change reaching its
    consumers). `console platform:recover-queued-work` is safe to run at any
    time and re-dispatches nothing that is already being processed.

## Rollback

Code-only rollback (no migration in the release): redeploy the previous
image the same way. **With migrations:** there is no general `down()`
rollback in production — restore to the step-5 checkpoint
(`BACKUP-AND-RESTORE.md`) and redeploy the previous image. Decide before
step 6 whether the release is reversible.
