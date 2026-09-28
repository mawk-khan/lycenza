# Backup and restore

ADR 0050 §9 (O10) and §11. **Deploy-gated throughout.** No backup policy
has been activated and **no real restore drill has been performed —
REAL RESTORE DRILL STILL OUTSTANDING.** A backup counts only once a
restore from it has succeeded.

## Recovery objectives (owner decision O10)

| Store | RPO | RTO |
|---|---|---|
| PostgreSQL | ≤ 15 minutes | ≤ 4 hours |
| Object storage | ≤ 24 hours | ≤ 8 hours |
| Redis | not backed up (see `REDIS-LOSS-RECOVERY.md`) | rebuilt, then reconciled |

These are operational objectives, not legal retention periods.
**Operational backup window: 35 days.** That window does not authorize
deleting business records; a future DPDP erasure/retention decision must
state how copies inside it are handled.

## PostgreSQL backup requirements

1. **Automated base backups plus point-in-time recovery** — WAL archiving
   or the managed service's equivalent — with an archive interval that
   meets RPO ≤ 15 minutes.
2. **Encrypted backup storage**; the backup set classified **Highly
   Sensitive** (it contains everything, including children's data).
3. **Separate credentials:** the application's runtime and admin database
   credentials can neither read nor delete backups; backups sit under a
   distinct account/role with deletion protection where offered.
4. Retention: 35 days of PITR.
5. **Monitoring (O12 open):** until an alerting vendor exists, the
   operator checks backup status on a schedule and records it.
   *(Corrected 2026-09-28: O12 was resolved by ADR 0051. Backup freshness
   reaches monitoring through the deployment evidence file (OBS-20–22,
   "Feeding backup and drill evidence to monitoring" below). A manual
   schedule is only a stopgap until a real backend is active.)*

Checklist for the operator configuring it: PITR enabled ☐ · archive
interval ≤ 15 min ☐ · encrypted ☐ · separate credentials ☐ · deletion
protection ☐ · 35-day retention ☐ · first restore drill scheduled ☐.

## Object-storage backup requirements

1. The live bucket: private, public access blocked, provider encryption at
   rest, **versioning enabled** (overwrite/delete recovery — not a backup).
2. An **independent** copy — replication or a scheduled backup to a
   separate encrypted destination under separate administrative control —
   with RPO ≤ 24 hours, so recovery never depends on the live bucket's own
   history.
3. Lifecycle rules never expire current objects, or noncurrent versions of
   records whose legal retention is unresolved.
4. `console platform:verify-storage` checks what the S3 API can prove
   (reachability, versioning, default encryption, public-access block);
   the independent copy is always `OPERATOR_EVIDENCE_REQUIRED` — record it
   from the provider.

## Restore procedure (always into an ISOLATED environment)

Never restore over production. A drill and a real recovery follow the
same steps; a real recovery then repoints traffic (deploy-gated).

1. **Choose the recovery point** (a PITR timestamp) and record it.
2. **Provision an isolated environment**: its own database, Redis, bucket
   and secrets — never production's (ADR 0050 §16). Production secrets are
   not needed: use environment-specific ones.
3. **Restore PostgreSQL** to the chosen point with the provider's PITR
   tooling into the isolated database. The roles come with a cluster
   restore; for a logical restore, run `DATABASE-BOOTSTRAP.md` first.
4. **Restore objects**: from the independent copy (or the versioned bucket
   at the matching time) into the isolated bucket — at least the objects
   referenced by the sample the validation will check.
5. **Boot** the application image against the restored data with the
   isolated environment's configuration (the production guard applies —
   TLS, Redis password, non-local bucket).
6. **Validate** (read-only):

   ```bash
   console platform:verify-restore --sample-documents=50
   console platform:verify-storage
   ```

   `platform:verify-restore` checks: the application boots; the database
   role model (`platform:verify-database`); every migration applied;
   readiness healthy; RLS fails closed without a School and isolates
   Schools; a random sample of active Documents per School exists in object
   storage with its recorded size; the **observed recovery point** (newest
   platform audit/outbox timestamp) and the validation duration. Output is
   codes, counts and timestamps only — never payloads, filenames or keys.
7. **Queued work:** Redis in the isolated environment starts empty; the
   scheduler (or `console platform:recover-queued-work`) rebuilds queued
   work from PostgreSQL. In a drill, keep outbound channels disabled or
   pointed at sinks so no real message or webhook leaves.
8. **Record** the drill (`RESTORE-DRILL-RECORD.md`): date, recovery point
   requested vs observed, restore duration, validation output, result.
9. **Tear down** the isolated environment and its copies (they are Highly
   Sensitive), and record that.

## Quarterly drill

Every quarter, one drill per ADR 0050 §11, recorded with the template. The
first successful drill in a real non-production environment is part of
the Phase 0O definition of done (ADR 0050 §20) and is **outstanding**.

**O1 hard blocker (ADR 0058 §4.5, row E11).** A local or simulated run
never counts. The drill must restore PostgreSQL from PITR or a snapshot, and
restore or reconcile objects from the independent copy or versioning. It
must then:
- boot the application against the restored environment;
- pass `platform:verify-restore` and `platform:verify-database`;
- validate a Document sample;
- measure the achieved recovery point and the RPO/RTO outcome;
- append the result to `RESTORE-DRILL-RECORD.md` (evidence only);
- set `restore_drill` in the evidence file so OBS-23 clears.

## Feeding backup and drill evidence to monitoring (Phase 0O.5A)

The application cannot know whether a backup succeeded. The deployment's
backup tooling (and the operator, after each drill) writes one JSON
document to a path mounted **read-only** into the `web` role and named by
`OBSERVABILITY_DEPLOYMENT_EVIDENCE_FILE`; the metrics listener re-exposes
it as `lycenza_backup_*` / `lycenza_restore_drill_*` (alerts OBS-20…OBS-23).
Unix timestamps in seconds; unknown keys, wrong types, future or pre-2020
times make the whole document rejected (and counted as a collection error).

```json
{
  "schema": "lycenza.deployment-evidence/v1",
  "backups": {
    "postgresql": {"last_success_at": 1790000000, "recovery_point_at": 1790003500, "last_failure_at": null},
    "objects": {"last_success_at": 1789990000, "last_failure_at": null}
  },
  "restore_drill": {"record_id": "DRILL-2026-Q4-1", "last_result": "PASS",
                    "last_success_at": 1789000000, "duration_seconds": 5400,
                    "recovery_point_gap_seconds": 90}
}
```

`restore_drill` is `null` until a real drill has been recorded in
`RESTORE-DRILL-RECORD.md` — which is the case today (**REAL RESTORE DRILL
STILL OUTSTANDING**), so OBS-23 will report the drill as overdue. The file is
operator-controlled, not signed; its authenticity rests on who can write it.
