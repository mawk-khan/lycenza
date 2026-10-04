# ADR 0066: Retention Execution Identity and Database-Enforced Holds

- Status: **Accepted as the E21-RH target architecture (E21-RH.1,
  2026-10-04).** The slices E21-RH.2–RH.6 (§9) implement it; until each
  slice lands, the current state in §2 stands for what it has not reached.
  **E21-RH.2 implemented (2026-10-04, §10):** the dedicated retention
  identity exists, and HRX runs on it. **E21-RH.3 implemented (2026-10-04,
  §11):** PostgreSQL retention holds are authoritative, and the platform
  hold is global (amends §6.3).
- Date: 2026-10-04
- Programme: **E21-RH — retention privilege hardening.** A
  pre-production security blocker, separate from HRX (closed at
  `dc8b50a`).
- Evidence: the E21-RH read-only audit (2026-10-04) at `dc8b50a`.
- Amends: ADR 0021 (dated amendment), and ADR 0065 §27.10 (HRX execution
  identity marked temporary).
- Builds on, and does not rewrite:
  - ADR 0021/0022 (runtime vs migration roles, forced RLS);
  - ADR 0064 (Finance retention);
  - ADR 0065 §27 (HRX retention);
  - `docs/security/E21-RETENTION-DETERMINATION.md`. Its periods,
    triggers, ratification status and configuration status are
    unchanged.

## 1. Context

The audit found three structural gaps.

1. **Runtime-executable destructive functions.** 18 SECURITY DEFINER
   retention functions are executable by the runtime role
   `school_os_app`. They re-prove tenant consistency, age floors and
   dependencies in the database, but every legal/retention hold is
   checked only in PHP. A direct call bypasses it.
2. **Runtime-writable evidence.** The runtime role could DELETE/UPDATE
   posted payroll evidence (`payroll_lwf_annual_charges`) directly.
   Closed by E21-RH.1.
3. **HRX on the migration connection.** HRX's privileged path
   (ADR 0065 §27.10) runs the scheduled `platform:employee-retention-prune`
   on `pgsql_admin`, the migration/owner connection, which is a
   superuser in Docker/DDEV. That contradicts ADR 0021 ("no runtime code
   path, including scheduled commands, selects `pgsql_admin`") and is
   broader than the task needs.

`retention_assert_tenant` compares the requested School with
`app.current_school_id`. The caller sets that variable itself, so the
check proves consistency, never authorization.

## 2. Current state (2026-10-04, after E21-RH.1)

| Item | State |
|---|---|
| 18 legacy retention definers | EXECUTE: owner + `school_os_app`; hold checked in PHP only |
| 2 HRX definers | EXECUTE: owner only; called on `pgsql_admin` (**temporary**); prologue checks session-user owner membership and `retention_school_holds` |
| `retention_school_holds` | config-synchronized mirror of `RETENTION_HOLD_SCHOOL_IDS`, written on `pgsql_admin` |
| Platform hold | `RETENTION_HOLD_PLATFORM` only; no database representation |
| `payroll_lwf_annual_charges` | runtime UPDATE/DELETE revoked (E21-RH.1); verifier-guarded |

## 3. Decision — three identities, never conflated

| Identity | Purpose | Must never |
|---|---|---|
| **Runtime application role** (`school_os_app`) | every HTTP request, queue worker and ordinary scheduled command | execute a destructive retention function; hold a DELETE/UPDATE privilege that exists only for retention; read or write hold state |
| **Migration/owner role** (`pgsql_admin`) | schema migrations; privileged maintenance run by an operator (e.g. root provisioning, hold placement §6) | be selected by any ordinary runtime or **scheduled retention** code path; be required by the application or the retention scheduler |
| **Dedicated retention identity** (introduced in E21-RH.2; working name `school_os_retention`) | executes the destructive retention functions for scheduled retention and reviewed erasure | own application tables; be a member of the owner role (or of `school_os_app`); hold SUPERUSER, BYPASSRLS, CREATEROLE or CREATEDB |

### 3.1 Required properties of the dedicated retention identity
- `NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION`.
- `LOGIN` with its own credential (or the deployment's equivalent
  service identity).
- Not the owner of any application table.
- Not a member of the owner/migration role, nor of `school_os_app`.
  Neither of those is a member of it.
- **Minimal grants only:**
  - `CONNECT`, schema `USAGE`;
  - `SELECT` on the tables its PHP orchestration reads to choose units;
  - `SELECT` on the hold tables;
  - `EXECUTE` on exactly the destructive retention functions migrated to
    it.
- Advisory locks need no grant.
- Any direct write privilege must be individually justified in the slice
  that adds it, with a verifier entry. The default is none.
- Subject to RLS (it is not the owner and has no BYPASSRLS). Its reads
  set `app.current_school_id` for **row visibility only** (§4).
- `DatabaseRoleVerifier` proves every property above, and proves that
  `school_os_app` and PUBLIC hold no EXECUTE on any destructive
  retention function.

### 3.2 Required properties of every destructive retention function
- SECURITY DEFINER, owned by the owner role.
- `search_path = pg_catalog, pg_temp`, every object schema-qualified.
- `EXECUTE` revoked from PUBLIC and `school_os_app`, granted only to the
  dedicated retention identity.
- **Authorization in the function:** the session user must be a member
  of the retention identity role (`pg_has_role(session_user, …,
  'MEMBER')`). `session_user` cannot be changed by `SET ROLE` or by any
  client setting. No client-settable session variable is ever
  authorization.
- **Enforced in PostgreSQL:**
  - School scope (§4);
  - retention eligibility and the period floor (cutoff);
  - legal/retention hold (§6);
  - dependency and causal-order checks;
  - row locks for concurrency.
- The PHP checks stay as defence in depth and for reporting.

## 4. Tenant authorization contract — Model B (platform maintenance identity, explicit School)

The retention identity is **intentionally cross-tenant**: one scheduled
run processes every School. Per-School credentials (Model A) would
multiply secrets without adding a boundary, because the same scheduler
process would hold them all.

Therefore, for every School-scoped destructive function:
1. **The School is an explicit argument** (`p_school_id`), never inferred
   from session state.
2. **Every target row is proven to belong to that School** inside the
   function. Every read, lock and delete predicate carries
   `school_id = p_school_id`, and composite same-School foreign keys
   keep children aligned. A row of another School is never selected,
   locked or deleted, whatever RLS does.
3. **`app.current_school_id` is not authorization.** The functions may
   keep `retention_assert_tenant` as a consistency check (the PHP
   orchestration's tenant context equals the requested School). It is
   documented as consistency only. Authorization is §3.2 (EXECUTE plus
   session-user role membership).
4. **Attribution.** Each School-scoped call is attributable to the
   requested School and the executing identity. Retention metrics and
   logs carry counts, category and School. A database-side execution
   record, if a slice adds one, records School, function, row count,
   `session_user` and time, never row content.
5. School-less (platform) functions take no School. They are authorized
   by §3.2 and gated by the platform hold (§6).

## 5. Execution path (scheduler credential contract)

- Ordinary requests, queue workers and every ordinary scheduled command
  continue to use `school_os_app` on `pgsql`.
- **Scheduled retention never selects `pgsql_admin`.** From E21-RH.2 it
  uses a dedicated connection (working name `pgsql_retention`)
  authenticated as the retention identity. Only the destructive steps
  move to it: each unit's reads, locks, transaction and function call
  run on that one connection, as the HRX participants do today.
- **Credentials:**
  - deployment provisions the retention credential only to the process
    that runs scheduled retention;
  - the web, queue and ordinary scheduler processes receive neither the
    retention nor the migration credential;
  - migration credentials are needed only by migration/maintenance
    operators.
- **Failing safe:**
  - When the retention credential is absent or unusable, every
    destructive retention participant refuses (counted as an error,
    nothing deleted).
  - The non-destructive phases of the same command still run.
  - The process never falls back to `pgsql_admin` or to
    `school_os_app`.
  - `TestDatabaseGuard` and `ProductionConfigurationGuard` cover the new
    connection the same way they cover `pgsql`/`pgsql_admin`.

## 6. Hold architecture

### 6.1 Source of truth: PostgreSQL
**PostgreSQL hold state is authoritative for destructive retention**
from E21-RH.3. Every destructive function reads it directly in the same
transaction that deletes, so no freshness window exists.

- Holds are placed and released only through an audited operator command
  on the maintenance path (the migration/owner connection, like root
  provisioning). Each change records reason code, actor, time, and the
  School or platform scope.
- The runtime role has no privilege on hold state. The retention
  identity has SELECT only. It can neither place nor release a hold.
- **Transition.** `RETENTION_HOLD_SCHOOL_IDS` / `RETENTION_HOLD_PLATFORM`
  become an **add-only** input. A configured hold missing from the
  database makes destructive retention **refuse to start**. Removing a
  value from configuration never releases a database hold; release is
  explicit only.
- **Fail closed.** If hold state cannot be read (missing table,
  permission error, unreadable row), the function raises and deletes
  nothing. A PHP pre-check that finds configuration and database
  disagreeing refuses the destructive phases.

### 6.2 School hold
- One active hold row per School (partial unique index).
- Releasing sets `released_at`/`released_by` once; rows are never
  deleted (history).
- Every School-scoped destructive function refuses with `retention_hold`
  while an active School hold exists.
- It replaces the HRX-era `retention_school_holds` mirror, whose rows
  migrate into it.

### 6.3 Platform hold
- The same table with `scope = 'platform'` and no School (a CHECK ties
  `school_id IS NULL` to the platform scope), at most one active.
- Every School-less destructive function (platform audit, released
  suppressions, Group and platform grants, erasure cases, and any future
  School-less category) refuses while it is active.
- School-scoped functions are governed by the School hold. A platform
  hold covers School-less rows only, as E21 §2 already defines.
- *Amended 2026-10-04 (E21-RH.3):* the **database** platform hold is
  **global**. It also blocks every School-scoped destructive operation, so
  one runs only when no platform hold AND no hold of its School is active.
  Only the transitional, unreconciled `RETENTION_HOLD_PLATFORM`
  configuration value keeps the legacy School-less-only reading in the PHP
  check of the legacy commands; once reconciled it becomes the global
  database hold.

## 7. Consequences
- The 18 legacy functions move only slice by slice (§9), because
  Payroll, Finance, Student, Guardian and LMS orchestration couple
  their locks and transactions to the runtime connection. Moving one
  function means moving its whole unit onto the retention connection.
- New destructive retention functions must follow §3.2 from day one.
- Docker, DDEV, CI and the test bootstrap must provision the retention
  role (E21-RH.2), with the same fail-closed test-database guard.

## 8. Alternatives considered
1. **Keep the migration/owner connection for retention** (the HRX
   interim). Rejected: superuser/owner breadth, it contradicts
   ADR 0021, and it puts migration credentials into a scheduled process.
2. **Keep runtime EXECUTE and add in-function checks only.** Rejected
   as the end state: the runtime role stays able to drive destructive
   primitives. It survives only as defence in depth (§3.2).
3. **Per-School retention credentials (Model A).** Rejected (§4).
4. **Configuration as hold source with a synchronized mirror.**
   Rejected as the end state: freshness and release-by-omission risks.
   It remains only as the add-only transition input (§6.1).

## 9. Implementation slices
- **E21-RH.1 (this ADR):** architecture; LWF runtime UPDATE/DELETE
  revoked and verifier-guarded.
- **E21-RH.2:**
  - create the retention identity, its connection, its provisioning
    (init SQL, CI, DDEV, test bootstrap, `.env.example`) and guards;
  - move HRX off `pgsql_admin`;
  - change HRX's prologue check from owner membership to retention-role
    membership;
  - remove `pgsql_admin` from scheduled code.
- **E21-RH.3:**
  - the authoritative hold table (School and platform), its audited
    maintenance command and the add-only configuration reconciliation;
  - migrate the mirror; HRX reads it.
- **E21-RH.4:** the standalone School bulk functions and the School-less
  functions (no outer locks): EXECUTE to the retention identity only,
  plus in-function authorization and holds.
- **E21-RH.5:** the Payroll evidence/run and LMS units, each whole unit
  on the retention connection.
- **E21-RH.6:**
  - the Student core, Guardian consent and Finance unit orchestration,
    after its own decision;
  - eligibility-source guards (`employment_records` separation,
    `erasure_cases.closed_at`);
  - the LMS runtime DELETE review;
  - the decision on database-level holds for PHP direct-delete retention
    paths.

E21-RH stays a pre-production blocker until E21-RH.6 closes.

## 10. E21-RH.2 as built (2026-10-04)
- **Identity.** `school_os_retention`: LOGIN, NOSUPERUSER, NOBYPASSRLS,
  NOCREATEDB, NOCREATEROLE, NOINHERIT, NOREPLICATION; a member of no role
  and nothing a member of it; owns nothing; no default privileges.
- **Provisioning** (role creation is cluster-level, never in a migration):
  - Docker init `01-roles.sql` / `03-test-database-roles.sql`;
  - `ddev test` and `ddev demo-reset`;
  - CI (which runs the init scripts);
  - `bin/safe-test` (re-asserts the init scripts on every run, because the
    isolated volume persists);
  - `infrastructure/postgres/production-bootstrap.sql`, plus its verifier;
  - `.env.example`, `.ddev/config.yaml`, `phpunit.xml`;
  - the `database_retention` secret group (scheduler, operator console).
- **Grants** (migration `2026_11_26_090000_move_hrx_retention_to_dedicated_identity`,
  which refuses while the role is missing or not narrow):
  - EXECUTE on the two HRX functions;
  - column-level SELECT on exactly what the HRX unit reads:
    - `employees(id, school_id)`;
    - `employment_records(id, employee_id, status, ends_on)`;
    - `leave_requests(employee_id)`;
    - `leave_policy_assignments`, `leave_ledger_entries` and
      `leave_year_close_items` (`employment_record_id`);
    - `staff_attendance_records(employee_id)`;
    - `retention_school_holds(school_id)`.
  - Nothing else.
- **Authorization.** The HRX prologue accepts exactly
  `session_user = 'school_os_retention'`, the authenticated login.
  Membership, `SET ROLE` and client settings cannot satisfy it, so the
  runtime role, the owner and any other login are refused
  (`retention_privilege`).
- **Execution.** `RetentionExpiry::privileged()` runs each HRX unit wholly
  on `pgsql_retention`, after proving that connection's
  `session_user = school_os_retention` and that it is unelevated.
  - There is no fallback. With an unset, unreachable or mismatched
    credential, the unit refuses, logs a closed reason and deletes nothing.
  - The PHP recheck is a plain read (`readSeparation()`). Row locks are
    taken only by the definer function (shared D9 floor plus HRX advisory
    locks), so the role needs no UPDATE privilege. The dry run's locks are
    kept, in the released savepoint, for the destructive call.
- **Hold transition** (§6.1, before RH.3):
  - Writing the `retention_school_holds` mirror is operator maintenance
    (`platform:retention-holds-sync`, migration connection).
  - A destructive scheduled run refuses while any configured hold is
    unrecorded (`retention_hold_state_stale`). A recorded hold stays
    enforced until the operator records its release.
- **`pgsql_admin`** is no longer used by any scheduled retention path.
- **Verifier:**
  - existing: `retention_functions_narrow`,
    `privileged_retention_functions_closed`;
  - new: `retention_role_narrow`, `retention_role_read_only`,
    `retention_role_functions_exact` (the approved set =
    `PRIVILEGED_RETENTION_FUNCTIONS`, extended by later slices) and
    `retention_connection_identity`.
- **Guards:**
  - `TestDatabaseGuard` covers `pgsql_retention`;
  - `ProductionConfigurationGuard` refuses a configured retention login
    equal to the runtime or migration login
    (`retention_identity_not_distinct`) and requires TLS.
- The 18 legacy functions are unchanged.

## 11. E21-RH.3 as built (2026-10-04)
**PostgreSQL hold state is authoritative. Configuration may add holds
during the transition. Configuration removal never releases a hold.
Release requires an explicit, audited operator action.**

- **Store.** `retention_holds` (migration
  `2026_11_27_090000_make_retention_holds_authoritative`) replaces the RH.2
  mirror.
  - Columns: `id`, `scope` (`school` | `platform`), `school_id` (FK
    `schools`, RESTRICT), `reason_code`, `reference`, `placed_via`
    (`operator_command` | `configuration_reconciliation` | `migration`),
    `placed_by_login`, `placed_at`, then `released_at`,
    `released_by_login`, `release_reason_code`, `release_reference`.
  - CHECKs: the scope shape (`school` ⇔ `school_id`), closed reason codes,
    constrained reference tokens, and an all-or-nothing release.
  - Partial unique indexes: one active hold per School, and one active
    platform hold.
  - Owned by the schema owner and granted to nobody (no PUBLIC, runtime or
    retention privilege).
- **History.** `trg_retention_holds_guard` / `trg_retention_holds_no_truncate`,
  for every role:
  - an INSERT is a placement, and the database sets `placed_by_login` from
    `session_user` and the time;
  - the only UPDATE is the one release of an active hold (database-set
    login and time);
  - DELETE and TRUNCATE are refused.
- **Functions** (search path pinned, never PUBLIC):

| Function | Kind | Executable by | Role |
|---|---|---|---|
| `retention_hold_place()` | invoker | owner only | the only placement path; exclusive hold lock |
| `retention_hold_release()` | invoker | owner only | the only release path; exclusive hold lock |
| `retention_assert_not_held(school)` | invoker | owner only | called inside the destructive definers; shared hold lock held to the end of the transaction; refuses an active platform hold or that School's hold (`retention_hold`); fails closed if unreadable |
| `retention_hold_active_scopes()` | definer, read-only | retention identity only | active (scope, School) pairs; no history or attribution |

- **HRX.** The prologue (`retention_lock_hrx_employee`) now calls
  `retention_assert_not_held(p_school_id)` in place of the mirror lookup.
  Everything else is unchanged: identity, tenant, floor, locks, age.
- **Operator commands** (operator console, migration connection; each
  change audited to `platform_audit_events`):
  - `platform:retention-hold-place --school=|--platform --reason= --reference=`
    (idempotent);
  - `platform:retention-hold-release ... [--force]` (typed confirmation;
    explicit only);
  - `platform:retention-holds [--history]`;
  - `platform:retention-holds-reconcile`, which is add-only: it places the
    configured School and platform holds and never releases anything.

  The `platform:retention-holds-sync` command of RH.2 is removed.
- **Attribution.** The maintenance login comes from `session_user`; the
  human attribution is the operator's change reference. A shared login is
  not presented as a person.
- **PHP.** `RetentionHolds` reads the authoritative state as the retention
  identity (`retention_hold_active_scopes()`):
  - `isHeld()` is the configured School, OR an active School hold, OR an
    active platform hold;
  - `platformHeld()` is the configured flag OR an active platform hold;
  - unreadable state means held (fail closed).

  `RetentionExpiry::privileged()` can only add refusals before a
  destructive run: hold state unreadable, or a configured hold not yet
  reconciled. The database alone decides what is held.
- **Concurrency** (two-process tests):
  - a placement concurrent with a purge makes the waiting purge refuse;
  - a purge already past the check finishes first, then the placement
    lands;
  - a concurrent release lets the waiting purge proceed;
  - two identical placements create one hold, and two releases release
    once.
- **Migration and rollback.** Every mirror row becomes an active School
  hold (`migration`), verified before the mirror is dropped. Rollback
  restores exactly the active School holds and the RH.2 prologue byte for
  byte, and refuses while a platform hold or any release history exists
  (a hold is never released by a rollback).
- **Verifier.** New check `retention_holds_authoritative`:
  - ownership, no grants, guards enabled;
  - writer and assert ACLs and security mode;
  - the read definer's ACL.

  The approved retention-identity function set gains
  `RETENTION_READ_FUNCTIONS`.
- **Unchanged.** The 18 legacy functions (owners, ACLs, bodies) and their
  PHP-only hold behaviour, which is still an RH.4–RH.6 blocker.
