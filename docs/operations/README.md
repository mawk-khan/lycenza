# Operations runbooks (Phase 0O.4A)

Repository-prepared procedures for a production deployment of Lycenza,
implementing ADR 0050 (`docs/architecture/adr/0050-production-infrastructure-secrets-recovery-contract.md`).
**Nothing here has been performed against real infrastructure.** Every
step that touches a real environment is deploy-gated (ADR 0050 §19,
CLAUDE.md rule 16): a human with explicit authorization runs it.

| Document | Covers |
|---|---|
| [PRODUCTION-IMAGES-AND-PROCESSES.md](PRODUCTION-IMAGES-AND-PROCESSES.md) | The two images, the process manifest, roles, secrets per process, health, local verification |
| [DATABASE-BOOTSTRAP.md](DATABASE-BOOTSTRAP.md) | Production PostgreSQL roles, default privileges, verification |
| [MAINTENANCE-WINDOW-RELEASE.md](MAINTENANCE-WINDOW-RELEASE.md) | The v1 single-version release sequence and how maintenance mode behaves |
| [REDIS-LOSS-RECOVERY.md](REDIS-LOSS-RECOVERY.md) | What a Redis loss costs and how queued work is rebuilt from PostgreSQL |
| [BACKUP-AND-RESTORE.md](BACKUP-AND-RESTORE.md) | PostgreSQL and object-storage backup requirements, the restore procedure, restore validation |
| [RESTORE-DRILL-RECORD.md](RESTORE-DRILL-RECORD.md) | The drill record template (no drill has been performed) |
| [RELEASE-QUALIFICATION.md](RELEASE-QUALIFICATION.md) | Release order, qualification to VERIFIED, exceptions, rollback re-verification, evidence retention, the scheduled re-scan (ADR 0052) |
| [CUSTOM-PHP-RUNTIME.md](CUSTOM-PHP-RUNTIME.md) | The repository-built PHP runtime (curl/libxml2): pins, ownership, rebuild triggers, update procedure |
| [SUPPLY-CHAIN-INCIDENTS.md](SUPPLY-CHAIN-INCIDENTS.md) | Compromised dependency, compromised CI action, leaked signing key/identity, malicious artifact, Critical CVE after deployment |

Observability and alerting are contracted by ADR 0051
(`docs/architecture/adr/0051-production-observability-alerting-contract.md`):
alerts OBS-01…OBS-26 link to the runbooks above and to those added in
Phase 0O.5A: [alert index](alerts/README.md) and generated rules,
[dashboard specification](dashboards.md),
[WEBHOOK-FAILURES](WEBHOOK-FAILURES.md),
[COMMUNICATION-FAILURES](COMMUNICATION-FAILURES.md),
[FAILED-JOBS](FAILED-JOBS.md), [TELEMETRY-COLLECTION](TELEMETRY-COLLECTION.md).
**No observability backend is connected and no alert routing is active.**

## Status of operational evidence

| Evidence (ADR 0050 §20) | Status |
|---|---|
| Production images build and pass local verification | Repository-verified (`infrastructure/docker/production/verify-images.sh`) |
| Production database bootstrap on a clean PostgreSQL 16 | Repository-verified on a throwaway cluster (`infrastructure/postgres/verify-production-bootstrap.sh`) |
| Redis-loss reconciliation | Repository-verified (real Redis, `Tests\Feature\Recovery\RedisQueueLossRecoveryTest`) |
| Release qualification (lock integrity, SBOM, vulnerability scan, secret/history scans, provenance, ephemeral signature, `verify-artifact`) | Repository-verified; **both images still FAIL the vulnerability policy after Phase 0O.6D, only on unapproved HIGH-without-fix findings** (0 CRITICAL, 0 HIGH with a fix in either) — no digest is VERIFIED ([remediation records](../security/release-remediation/0O.6D-CUSTOM-PHP-RUNTIME.md)) |
| Registry, signing custody, publication, promotion | **Not configured / not performed** |
| Real deployment, real secrets, real bucket policy | **Not performed** |
| Backup policy activation (PITR, object copy) | **Not performed** |
| **Restore drill in a real non-production environment** | **REAL RESTORE DRILL STILL OUTSTANDING** |

## Operator verification commands (read-only, codes only)

| Command | Connection | Proves |
|---|---|---|
| `php artisan platform:verify-database` | runtime (`school_os_app`) | Role attributes, no owner membership, schema/table privileges, DELETE revocations, default privileges, forced RLS, the 0O.1A root boundary, TLS on the connection |
| `php artisan platform:verify-storage` | the S3 disk | Disk/bucket/endpoint configuration, reachability, versioning, default encryption, public-access block where the API supports it; the independent copy is always operator evidence |
| `php artisan platform:verify-restore` | runtime | A restored environment: boot, database checks, migrations, readiness, RLS fails closed and isolates Schools, sampled Document objects present with their recorded size, the observed recovery point |
| `php artisan platform:recover-queued-work` | runtime | Runs every PostgreSQL-driven re-dispatch sweep once (they also run on the scheduler) |

Each prints one line per check — `PASS`, `FAIL` or
`OPERATOR_EVIDENCE_REQUIRED` — and exits non-zero on any `FAIL`. None
prints a credential, endpoint, error text, filename or payload.

## Supply chain (ADR 0052, implemented in Phase 0O.6A)

Release artifacts are immutable image digests: built once, verified
(SBOM, vulnerability scan, secret/history scan, provenance, signature),
then promoted — production deploys PROMOTED digests only. The repository
side exists: `infrastructure/release/qualify` (BUILT → VERIFIED),
`infrastructure/release/verify-artifact` (the one fail-closed verifier), the
policy manifest, the exception file, the Release-qualification and
SBOM-re-scan workflows, and the runbooks above.
**NO PRODUCTION REGISTRY IS CONFIGURED. NO REAL SIGNING IDENTITY/KEY IS
CONFIGURED. NO PRODUCTION IMAGE HAS BEEN PUSHED. NO PRODUCTION IMAGE HAS BEEN
PROMOTED.**
