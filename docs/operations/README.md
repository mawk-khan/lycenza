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

## Status of operational evidence

| Evidence (ADR 0050 §20) | Status |
|---|---|
| Production images build and pass local verification | Repository-verified (`infrastructure/docker/production/verify-images.sh`) |
| Production database bootstrap on a clean PostgreSQL 16 | Repository-verified on a throwaway cluster (`infrastructure/postgres/verify-production-bootstrap.sh`) |
| Redis-loss reconciliation | Repository-verified (real Redis, `Tests\Feature\Recovery\RedisQueueLossRecoveryTest`) |
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
