# Restore drill record

ADR 0050 §11. One entry per drill, appended — never edited afterwards
(a correction is a new entry). Classified **Confidential**: dates,
results, durations and recovery points only. **Never** paste restored
payloads, filenames, object keys, personal data, credentials, hostnames
or connection strings.

## Status

**No drill has been performed. REAL RESTORE DRILL STILL OUTSTANDING.**
The repository provides the procedure (`BACKUP-AND-RESTORE.md`) and the
validation tooling (`platform:verify-restore`, `platform:verify-storage`,
`platform:verify-database`); a drill requires a real, isolated
non-production environment restored from real backups, performed by an
authorized operator. Nothing below may be filled in from a local or
simulated run.

## Template

```text
Drill id:                  DRILL-YYYY-QN-<n>
Date (UTC):
Operator(s) (role, not name):
Authorization reference:
Environment (isolated, non-production):   yes / no
Backup source:             PostgreSQL PITR / snapshot <id>; objects: independent copy / versioned bucket

Recovery point requested (UTC):
Recovery point observed (platform:verify-restore observed_recovery_point):
Data loss window (requested vs observed):          <= 15 min target
PostgreSQL restore start / end (UTC):
Object restore start / end (UTC):
Application boot (UTC):
Validation duration (validation_duration_seconds):
Total time to validated service:                   <= 4 h (PostgreSQL) / <= 8 h (objects) target

platform:verify-database   failed=  operator_evidence_required=
platform:verify-restore    failed=  operator_evidence_required=   documents checked/ok:
platform:verify-storage    failed=  operator_evidence_required=
Independent object copy evidence (provider, date):
Queued work rebuilt (recover-queued-work output counts):
Outbound channels disabled/sunk during the drill:  yes / no

Result:                    PASS / FAIL
Findings and follow-ups:
Environment torn down (UTC), copies destroyed:     yes / no
```

## Drills

_None yet._
