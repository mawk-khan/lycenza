# Retention holds (E21 legal / retention hold)

E21-RH.3, ADR 0066 §6 and §11. **PostgreSQL hold state is authoritative.**
Configuration may add holds during the transition, but configuration
removal never releases one. Release requires an explicit, audited operator
action.

## What a hold does
- **School hold:** no destructive retention for that School.
- **Platform hold:** no destructive retention anywhere -- every School and
  every School-less record.
- **HRX functions** (Leave, Staff Attendance) refuse in the database
  itself.
- **Legacy retention commands** count a held School (or, under a platform
  hold, everything) as `held` and delete nothing.
- **Fail closed:** if the hold state cannot be read, everything counts as
  held.

## Commands (operator console only)
They run on the migration/owner connection (`database_admin`), never from
the scheduler or a web request.

| Action | Command |
|---|---|
| Place a School hold | `console platform:retention-hold-place --school=<school id> --reason=<code> --reference=<ticket>` |
| Place the platform hold | `console platform:retention-hold-place --platform --reason=<code> --reference=<ticket>` |
| Release a School hold | `console platform:retention-hold-release --school=<school id> --reason=<code> --reference=<ticket>` |
| Release the platform hold | `console platform:retention-hold-release --platform --reason=<code> --reference=<ticket>` |
| Inspect | `console platform:retention-holds [--history]` |
| Reconcile configuration (add-only) | `console platform:retention-holds-reconcile` |

### Reason codes
- **Place:** `litigation`, `regulatory_inquiry`, `audit`, `investigation`,
  `configuration_transition`, `other`.
- **Release:** `matter_concluded`, `inquiry_closed`, `audit_closed`,
  `placed_in_error`, `other`.

### Command behaviour
- **References** are change or ticket tokens (letters, digits, `. _ : / -`,
  at most 64 characters). Never free text or personal data.
- **Placing is idempotent:** an active hold for that scope is reported and
  never duplicated.
- **Releasing asks you to type the scope** (the School id, or `platform`).
  `--force` skips that, for trusted operator automation only. Releasing
  with no active hold fails and changes nothing.

## Attribution and history
- The database records who placed or released each hold
  (`placed_by_login` / `released_by_login`, from `session_user`), plus:
  - the path it came through (`operator_command`,
    `configuration_reconciliation` or `migration`);
  - the reason codes;
  - the references;
  - the timestamps.
- A shared maintenance login does **not** identify a person. The change
  reference is the human attribution, so always give one.
- Every change is also a platform audit event:
  - `platform.retention_hold.placed`;
  - `platform.retention_hold.released`;
  - `platform.retention_hold.reconciled`.
- History is never rewritten or deleted (database-enforced for every role).
  A new hold after a release is a new row.

## Transition from configuration
- `RETENTION_HOLD_SCHOOL_IDS` / `RETENTION_HOLD_PLATFORM` are an
  **add-only** input. After changing them, run
  `platform:retention-holds-reconcile`, which places what they name and
  releases nothing.
- **Until you reconcile**, destructive runs of the migrated functions
  refuse (`retention_hold_state_stale`), and legacy commands still treat
  the configured value as held.
- **Removing a value from configuration** keeps the database hold active
  until `platform:retention-hold-release`.
- **A configured id that is not an existing School** makes the reconcile
  fail. Correct the configuration.

## Who can do what

| Identity | Holds |
|---|---|
| `school_os_app` (runtime) | nothing: no read, no write, no function |
| `school_os_retention` (scheduled retention) | reads the active scopes only (`retention_hold_active_scopes()`); cannot place, release or read history |
| migration/owner (operator console) | places, releases and inspects through the commands above |

## Concurrency
- Placing or releasing takes the hold lock exclusively.
- Every destructive check takes it shared and keeps it until its
  transaction ends. So:
  - a placement waits for purges already past the check;
  - a purge that checks after a placement commits sees it and refuses;
  - two operators placing the same hold create exactly one.
