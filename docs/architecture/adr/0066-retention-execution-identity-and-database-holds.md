# ADR 0066: Retention Execution Identity and Database-Enforced Holds

- Status: **Accepted as the E21-RH target architecture (E21-RH.1,
  2026-10-04).** The slices E21-RH.2–RH.6 (§9) implement it; until each
  slice lands, the current state in §2 stands for what it has not reached.
  **E21-RH.2 implemented (2026-10-04, §10):** the dedicated retention
  identity exists, and HRX runs on it. **E21-RH.3 implemented (2026-10-04,
  §11):** PostgreSQL retention holds are authoritative, and the platform
  hold is global (amends §6.3). **E21-RH.4 implemented (2026-10-05,
  §12):** the eleven standalone legacy functions run only as the retention
  identity and enforce holds in the database; the eight coupled ones are
  unchanged (RH.5/RH.6). **E21-RH.5 implemented (2026-10-05, §13):** the
  Payroll and LMS units run whole as the retention identity; the four RH.6
  functions are unchanged. **E21-RH.6 implemented (2026-10-05, §14):** no
  retention function is runtime-executable; every PHP retention unit runs
  whole as the retention identity, and PostgreSQL refuses each of its
  deletes under an active hold; the named eligibility sources are
  database-guarded. **E21-RH is NOT closed:** E21-RH.7 (§14.9,
  database-stamped write times on the remaining retention tables) is a new
  pre-production blocker. **E21-RH.7 implemented (2026-10-05, §15):**
  retention eligibility counts only from times PostgreSQL recorded; the
  RH.6 eligibility guards and the RH.7 anchors are fenced against rollback.
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
  *(E21-RH.4, §12: eleven of them are now hardened; eight remain.)*

## 12. E21-RH.4 as built (2026-10-05)
**The eleven standalone legacy functions run only as the retention
identity and refuse an active hold inside PostgreSQL. The eight coupled
functions are unchanged. E21-RH remains a pre-production blocker.**

### 12.1 Re-audit and inclusion
The 18 legacy functions were re-inventoried from the installed database
and the source tree. A function was included only when its retention
operation is genuinely standalone:
- no PHP transaction must span it and runtime-role writes;
- no lock on the runtime connection must stay held across it;
- no PHP mutation must be atomic with it;
- no shared orchestration needs the same connection;
- it already performs the destructive mutation itself.

| Function | Scope | Caller | Slice | Reason |
|---|---|---|---|---|
| `retention_expire_school_audit_events` | School | `platform:audit-prune` | **RH.4** | bulk autocommit batches, `SKIP LOCKED` only |
| `retention_expire_membership_role_assignments` | School | `platform:authority-history-prune` | **RH.4** | same |
| `retention_expire_teaching_assignments` | School | `platform:authority-history-prune` | **RH.4** | same; inline 7-year floor and `ends_on < cutoff` kept |
| `retention_expire_school_elevations` | School | `platform:authority-history-prune` | **RH.4** | same; the audit-reference dependency is in the function |
| `retention_expire_communication_delivery_policy_decisions` | School | `platform:communications-prune` | **RH.4** | called after, never inside, the deliveries' own transactions |
| `retention_expire_api_client_credentials` | School | `platform:authority-history-prune` | **RH.4** | same as above |
| `retention_expire_platform_audit_events` | School-less | `platform:audit-prune` | **RH.4** | same |
| `retention_expire_released_email_suppressions` | School-less | `platform:email-suppressions-prune` | **RH.4** | same |
| `retention_expire_group_role_assignments` | School-less | `platform:authority-history-prune` | **RH.4** | same; the elevation dependency is in the function |
| `retention_expire_platform_role_assignments` | School-less | `platform:authority-history-prune` | **RH.4** | same |
| `retention_expire_erasure_cases` | School-less | `platform:audit-prune` | **RH.4** | same (see §12.6) |
| `retention_expire_payroll_employee_evidence` | School | Payroll evidence unit | RH.5 | one Employee unit; its locks and transaction belong to the HR/Payroll orchestration |
| `retention_expire_payroll_run` | School | Payroll run unit | RH.5 | row lock on `payroll_runs` taken by PHP in the same transaction |
| `retention_expire_learning_content` | School | `LmsResourceRetention` | RH.5 | PHP row lock and Document purge in the same unit transaction |
| `retention_expire_assignment` | School | `LmsResourceRetention` | RH.5 | same |
| `retention_expire_finance_unit` | School | Finance retention | RH.6 | REPEATABLE READ transaction, period-maintenance advisory lock, before/after accounting readings |
| `retention_expire_student_processing_authorizations` | School | Student core purge | RH.6 | runtime DELETEs of the Student and its enrollments in the same transaction |
| `retention_expire_student_consent_events` | School | Student core participant | RH.6 | same Student transaction; runtime DELETE of preferences |
| `retention_expire_guardian_consent_events` | School | Guardian purge participant | RH.6 | Guardian transaction; runtime DELETE of preferences |

The re-audit confirmed the E21-RH.1 classification; nothing moved between
slices.

### 12.2 Database
Migration `2026_11_28_090000_harden_standalone_retention_functions`:
- **`retention_assert_retention_identity()`** (new): invoker, `STABLE`,
  search path pinned, owner-executable only (in
  `HOLD_MAINTENANCE_FUNCTIONS`). It refuses unless `session_user =
  'school_os_retention'` (`retention_privilege`), the same exact-login
  rule as HRX (§10). Neither `current_user`, role membership,
  `app.current_school_id` nor any client setting can satisfy it.
- **Prologue.** Injected right after `BEGIN` in each of the eleven,
  before every existing check:
  - `PERFORM public.retention_assert_retention_identity();`
  - for a destructive call only (`NOT p_dry_run`),
    `retention_assert_not_held(p_school_id)` for the six School-scoped
    functions and `retention_assert_not_held(NULL)` for the five
    School-less ones. The RH.3 helper is reused; no hold SQL is
    duplicated.
- **A dry run still counts under a hold.** It deletes nothing, and the
  PHP path needs the count to report rows as `held`.
- **Everything else is byte for byte.** The body diff is pure additions:
  floors, cutoffs, `retention_assert_tenant`, every `school_id =
  p_school_id` predicate, dependency checks, the `5000` batch cap and
  `FOR UPDATE SKIP LOCKED`.
- **ACLs** (owner unchanged, SECURITY DEFINER, `search_path = pg_catalog,
  pg_temp`):

  | | Before | After |
  |---|---|---|
  | owner | EXECUTE | EXECUTE (refused by the prologue: not the retention login) |
  | `school_os_app` | EXECUTE | **none** |
  | `school_os_retention` | none | **EXECUTE** |
  | PUBLIC | none | none |

- **No new table privilege.** The retention identity gains no SELECT,
  UPDATE or DELETE. Every read and write is inside the definers.

### 12.3 Holds
- **School-scoped:** refuses while a platform hold OR a hold of
  `p_school_id` is active. Another School stays processable.
- **School-less:** refuses while a platform hold is active. A School hold
  does not hold School-less rows (§6.3).
- Release is explicit (RH.3), and a released scope is processed again,
  subject to every floor and dependency.
- **Serialization** is the RH.3 hold lock: each batch statement takes it
  shared until it commits. A placement in flight makes the waiting batch
  refuse; a batch already past the check finishes before the placement
  lands.

### 12.4 Application
- `RetentionExpiry::forSchool()` / `forPlatform()` run the whole category
  (count and batches) on `pgsql_retention`. The School's tenant context is
  set on that connection. They first prove the connection authenticates
  as exactly `school_os_retention`, unelevated.
- With an unset, unreachable or mismatched credential, nothing runs. It
  logs `retention.unit_refused` with a closed reason and counts one
  `error`. It never falls back to `pgsql` or `pgsql_admin`. The
  non-destructive phases of the same command still run.
- A hold placed after the PHP check (between the count and a batch) is
  refused by the database. The run reports the remaining rows as `held`.
- **PHP checks kept as defence in depth:** `RetentionHolds::isHeld()` /
  `platformHeld()` (configured OR database hold, fail closed) still skip
  a held scope before any destructive call. They also keep the
  unreconciled-configuration reading of §6.3. The database is
  authoritative.
- **Attribution:** each run with any outcome logs
  `retention.standalone_expired`: category, School (null when
  School-less), `dry_run`, counts, and `identity` = the maintenance login.
  That names the execution identity, never a person. The existing command
  logs and metrics are unchanged.
- **Credential:** these commands now need the `database_retention`
  credential (scheduler, operator console): `platform:audit-prune`,
  `platform:authority-history-prune`, `platform:email-suppressions-prune`,
  and the policy-decision step of `platform:communications-prune`.

### 12.5 Verifier
- New `STANDALONE_RETENTION_FUNCTIONS` (the eleven).
- `RETENTION_FUNCTIONS` (runtime-executable, temporary) now holds only the
  eight coupled functions.
- `privileged_retention_functions_closed` covers HRX plus the eleven:
  - definer, pinned search path, not owned by the runtime role;
  - no runtime or PUBLIC EXECUTE;
  - for the eleven, the identity and hold prologue present.
- `retention_role_functions_exact` = HRX + the eleven +
  `retention_hold_active_scopes`.
- `retention_functions_narrow` = exactly the eight still runtime-executable.
- `retention_role_read_only` is unchanged: no destructive table grant.

### 12.6 `erasure_cases`
- Migrated for EXECUTE and the platform hold only.
- `erasure_cases` is a platform compliance record (E21 §5.5).
  School-scope cases are expired by the School-less function, so a School
  hold does not hold them; only the platform hold does. This is unchanged
  from before RH.4 and is recorded for the RH.6 review.
- **Eligibility forgery stays open (RH.6):** the runtime role can still
  UPDATE `status`, `decided_at` and `completed_at`, the columns the
  function's eligibility reads (`status IN ('denied','completed')` and
  `COALESCE(completed_at, decided_at) < cutoff`). The function's own
  7-year floor and the hold still apply.

### 12.7 Rollback
- `down()` removes exactly the injected prologue (refusing if it is not
  present exactly once), revokes the retention EXECUTE and drops the
  identity helper.
- It deliberately does **not** re-grant EXECUTE to the runtime role (the
  RH.1 convention: an unsafe privilege is never restored by a rollback).
  After a rollback only the owner holds EXECUTE, so these categories fail
  closed until migrated again.
- Verified on the DDEV test database:
  - migrate, then rollback: bodies equal the RH.3 bodies byte for byte
    (md5 of `pg_get_functiondef`), and the eleven are owner-only;
  - migrate again: ACLs, bodies, ownership and settings equal the first
    migration;
  - `platform:verify-database` passes.

### 12.8 Still open
- **RH.5:** Payroll employee evidence, payroll run, learning content,
  assignment.
- **RH.6:**
  - Finance unit, Student processing authorizations, Student consent and
    Guardian consent events;
  - the eligibility-source guards (`employment_records` separation, the
    `erasure_cases` columns of §12.6);
  - the LMS runtime DELETE review;
  - the decision on database holds for PHP direct-delete retention paths.
- E21-RH stays a pre-production blocker until RH.6 closes. HRX closure is
  unchanged.
- *(E21-RH.5, §13: the RH.5 functions are done.)*

## 13. E21-RH.5 as built (2026-10-05)
**The Payroll and LMS retention units run WHOLE as the retention identity,
on one connection, and their functions refuse any other session user and
any active platform or School hold. The four RH.6 functions are unchanged.
E21-RH remains a pre-production blocker.**

### 13.1 Before (re-audited from the live database and source)
Payroll evidence (`platform:payroll-retention-prune`, phase 1, one Employee):
```
pgsql (runtime): read Employees with evidence
  BEGIN
    SELECT employees ... FOR UPDATE                (PHP lock)
    read employment_records (separation recheck)
    SAVEPOINT; retention_expire_payroll_employee_evidence(dry)  (locks Employee,
      EmploymentRecords, runs FOR UPDATE; re-proves floor, posting, statutory)
    RELEASE
    retention_expire_payroll_employee_evidence(destructive)
      (sets app.payroll_retention; the freeze guard also needs the owner's
       privileges -> only the definer's current_user passes)
  COMMIT
```
Payroll run (phase 2, one regular run with its corrections):
```
pgsql (runtime): read posted, emptied, old regular runs
  BEGIN
    SELECT payroll_runs ... FOR UPDATE             (PHP lock)
    SAVEPOINT; retention_expire_payroll_run(dry); RELEASE
    retention_expire_payroll_run(destructive)      (locks the group, deletes postings and runs)
  COMMIT
```
LMS Learning Content / Assignment (`platform:academic-retention-prune`, one
resource):
```
pgsql (runtime): read resources of ended years
  BEGIN
    SELECT resource ... FOR UPDATE                 (PHP lock)
    PHP blockers: references, Documents, D6 owner authority (reads)
    DELETE FROM documents ... (runtime DELETE)     (DocumentParentRetention)
    retention_expire_<kind>(destructive)           (locks resource; floor, year, D6,
                                                    no Document left; audiences, resource)
  COMMIT; then Document bytes deleted (ObjectDeletion)
```
No advisory lock in any of them; READ COMMITTED; tenant context per School
(`app.current_school_id`); every table under forced RLS. No audit events
(counts-only logs and metrics). Documents: no FK cascade (the function
refuses while one remains), no Document service event.

### 13.2 Database
Migration `2026_11_29_090000_harden_payroll_lms_retention_units`:
- **Prologue** right after `BEGIN` in the four functions, before every
  existing check:
  - `PERFORM public.retention_assert_retention_identity();` (the RH.4
    helper: `session_user = 'school_os_retention'`);
  - `PERFORM public.retention_assert_not_held(p_school_id);`
    **unconditionally, dry runs included**, as HRX does.

  These are unit functions whose dry run validates the unit inside the
  destructive transaction. The shared hold lock is therefore taken at the
  first check and held to commit, and a held unit is kept as a whole. RH.4's
  bulk counts, by contrast, still count under a hold.
- **Everything else byte for byte:** the body diff is the three added lines.
  The Employee/EmploymentRecord/run locks, the 8- and 7-year floors,
  separation, posting age, statutory postings, `results_expired_at`,
  Academic Year, D6 authority, the Document dependency, School predicates,
  dry-run counts, the freeze-guard interaction
  (`payroll_retention_delete_allowed()` still requires the owner's
  privileges) and `retention_assert_payroll_employee_floor` are unchanged.
- **`retention_expire_lms_resource(kind, school, id, cutoff)`** (new, SECURITY
  DEFINER, search path pinned, retention identity only) is the whole
  destructive LMS unit, so the retention identity needs no DELETE on
  `documents`. In order:
  1. the identity and hold prologue, then the tenant check;
  2. lock the resource `FOR UPDATE` (a concurrent Document attach waits,
     then fails on its FK);
  3. delete the resource's Document rows, keeping their storage locations;
  4. call the LMS function above, which re-proves the floor, year, D6
     authority and "no Document left" and deletes audiences and resource;
  5. return the deleted storage locations.

  Any refusal rolls the Document deletion back with it, so a direct call can
  never strip an ineligible resource.
- **ACLs** (owner unchanged):

  | | Before | After |
  |---|---|---|
  | owner | EXECUTE | EXECUTE (refused by the prologue) |
  | `school_os_app` | EXECUTE | **none** |
  | `school_os_retention` | none | **EXECUTE** (the four, and the new unit function) |
  | PUBLIC | none | none |

- **Column-level SELECT only, for exactly what the moved PHP reads.** No
  table-level grant, no write.

  | Table | Columns | Read by |
  |---|---|---|
  | `payroll_run_results` | `employee_id, payroll_run_id` | the Employees-with-evidence filter; the emptied-run filter |
  | `payroll_adjustments`, `payroll_lwf_annual_charges` | `employment_record_id` | the Employees-with-evidence filter |
  | `payroll_runs` | `id, school_id, run_kind, status, results_expired_at, posted_at` | the run selection and its plain recheck |
  | `learning_content`, `assignments` | `id, school_id, subject_offering_id, owner_employee_id` | the selection, plain recheck and D6 blocker |
  | `subject_offerings` | `id, school_id, academic_year_id` | the ended-year filter |
  | `academic_years` | `id, school_id, ends_on` | the ended-year filter and D6 year end |
  | `documents` | `id, learning_content_id, assignment_id` | the Document blocker (`idsOwnedBy`) |
  | `*_section_audiences` | `school_id, <resource>_id, section_id` | the D6 blocker |
  | `teaching_assignments` | `school_id, employee_id, subject_offering_id, section_id, ends_on` | the D6 blocker |

  Narrower designs were rejected. Moving these reads into new definers would
  duplicate the PHP eligibility rules, and table-level SELECT would expose
  every column. The functions take every row lock, so the role needs no
  UPDATE (`FOR UPDATE` would require it).

### 13.3 After
Every statement of both Payroll phases and of each LMS unit runs on
`pgsql_retention` (`RetentionExpiry::privileged()`): reads, unit
transactions, savepoints and function calls. There is no split connection.
- The PHP rechecks are plain reads that only decide whether to call. The
  functions lock and re-prove.
- The School's tenant context is set on that connection. It is visibility
  only, never authorization.
- LMS calls the unit function in a savepoint. A resource a concurrent
  worker already removed (`retention_lms`), or a hold placed after the PHP
  check (`retention_hold`), is kept, never counted as an error.
- A Payroll hold refusal is kept as `dependency_blocked` (`retention_holds`),
  as HRX does.
- Like HRX, a destructive run now refuses while a configured hold is not yet
  recorded in the database (`retention_hold_state_stale`). Reconcile with
  `platform:retention-holds-reconcile`.
- **Attribution:** `retention.unit_run` (category, School, counts, dry run,
  `identity` = the maintenance login, never a person) per School and
  category with any outcome. The command logs and metrics are unchanged.
- **PHP checks kept as defence in depth:** the commands' `isHeld()` (a held
  School is counted only). The database is authoritative.
- **Credential:** `platform:payroll-retention-prune` and the LMS steps of
  `platform:academic-retention-prune` need `database_retention`. Without
  it, those units refuse (one error each) and the other academic categories
  still run.

### 13.4 Proof
- **Raw identity:** on the retention session, `session_user = current_user =
  school_os_retention`.
  - It can neither DELETE `payroll_run_results` (even with
    `app.payroll_retention=on`), nor UPDATE or lock `payroll_runs`, nor
    DELETE `documents`.
  - Yet the definers' deletes pass the freeze guard, which needs the owner's
    privileges. Inside them `current_user` is the owner.
- A query listener sees every unit statement on `pgsql_retention`.
- **RLS:** another School's context, or none, sees no row on the retention
  connection.
- **Runtime:** all five functions return "permission denied for function".
  The owner/migration login is refused by the prologue
  (`retention_privilege`). `SET ROLE school_os_retention` is denied.
- **Holds:** a database-only School hold refuses the four functions and
  the unit function, dry runs included. The platform hold blocks every
  School. Release restores; another School is independent.
- **Atomicity:** an ineligible LMS unit (young year) is refused, and its
  Document deletion rolls back.
- **Races** (two real OS processes, an observed lock wait):
  - Payroll: two retention workers on one unit (the second waits, then
    deletes nothing; no error); a hold placed first (the waiting unit keeps
    everything); a unit past the hold check (the placement waits); plus the
    existing rehire, reversal, correction and Finance in-flight races, now
    on the retention identity.
  - LMS: two workers on one resource (the second keeps quietly); hold
    placement in both orders (never half-purged); a Document attach in both
    orders; another School's unit runs to completion while one is in flight.
  - No self-deadlock: every step of a unit is on one session.

### 13.5 Verifier
- `UNIT_RETENTION_FUNCTIONS` = the four plus `retention_expire_lms_resource`:
  closed (definer, pinned path, no runtime or PUBLIC EXECUTE, prologue
  present).
- `RETENTION_FUNCTIONS` (still runtime-executable, temporary) = the four RH.6
  functions only.
- `retention_role_functions_exact` = HRX + RH.4 + RH.5 + the read helper.
- **New `retention_role_selects_exact`:** the retention identity's SELECTs
  equal `RETENTION_SELECTS` (RH.2 + RH.5 columns) exactly, with no
  table-level grant.

### 13.6 Rollback
- `down()` removes the column SELECTs, drops the unit function, removes
  exactly the injected prologue (refusing if it is not present exactly once)
  and revokes the retention EXECUTE.
- It deliberately does **not** re-grant runtime EXECUTE. Payroll and LMS
  retention fail closed (owner only) until migrated again.
- Verified on the DDEV test database:
  - rollback: bodies byte-identical to the RH.4 state; the four functions
    owner-only;
  - migrate again: identical function and table/column ACL snapshots;
  - the verifier passes.

### 13.7 Still open (RH.6) — RH.5 does NOT close these
- **EmploymentRecord eligibility-source integrity.** The runtime role can
  still UPDATE `employment_records` (`status`, `ends_on`), which the D9 floor
  reads. Payroll's invocation and hold boundary is hardened; the source of
  its separation is not.
- **`erasure_cases` eligibility source** (§12.6).
- **The LMS runtime DELETE review.** `school_os_app` still holds DELETE on
  `learning_content` and `assignments` (and `documents`). No application
  path uses those grants on the LMS tables, but they remain.
- **The Finance unit, Student processing authorizations, Student consent and
  Guardian consent functions and their orchestration.**
- **The decision on database holds for PHP direct-delete retention paths.**
- E21-RH stays a pre-production blocker until RH.6 closes. HRX closure is
  unchanged.

## 14. E21-RH.6 as built (2026-10-05)
**No destructive retention function is executable by the runtime role. Every
PHP retention unit runs WHOLE as the retention identity, and PostgreSQL
itself refuses each of its deletes while a platform hold or a hold of the
affected School is active. The named eligibility sources (EmploymentRecord
separation, Student exit, `erasure_cases`, the Guardian and Admissions
lifecycle-marker backfills) can no longer be forged by the runtime role.
E21-RH is NOT closed: E21-RH.7 (§14.9) is a new pre-production blocker.**

Owner decisions (2026-10-05): retention counts from the LATER of an end date
and the database-recorded date of that end (§14.3); the E21.2A housekeeping
prunes (email, outbox, webhook deliveries, failed jobs) are held in the
database too, while idempotency keys, account-recovery requests and staff
credentials stay hold-exempt (short-lived security state that a hold must
not keep alive); the hold boundary for
PHP deletes is a retention-session statement guard (§14.4); the general
"database-stamped write time on every retention table" is split out as
E21-RH.7.

### 14.1 The last four functions
Migration `2026_11_30_090000_harden_finance_student_guardian_retention_functions`
moves `retention_expire_finance_unit`,
`retention_expire_student_processing_authorizations`,
`retention_expire_student_consent_events` and
`retention_expire_guardian_consent_events` exactly as §13.2 moved Payroll and
LMS: the identity and hold prologue right after `BEGIN` (dry runs included),
EXECUTE from `school_os_app` to `school_os_retention`, every other line byte
for byte. The Finance unit's plan, dual-read verification and readings run
on the retention connection with SELECT on the D8 tables (§14.4 READS).

### 14.2 Every PHP retention unit on the retention identity
`RetentionExpiry::retained(category, dryRun, school, zero, operation)` runs a
whole unit on `pgsql_retention` (the `privileged()` contract of §13.3: no
fallback, the configured-hold stale check, `retention.unit_run`
attribution) and returns one error, with nothing done, when it refuses.
Wrapped:
- Students (core, enrollment rollover), Guardians (record, relationship),
  Employees (ancillary, evidence), Payroll per-employment configuration;
- Attendance records and sessions, Library loans, Transport and Hostel
  assignments, Driver assignments, Curriculum deliveries, Timetable entries,
  Automation executions, Visitors;
- Communications (deliveries, content, both residual paths), terminal
  Admissions applications, portal invitations, the Finance unit;
- the commands `platform:email-prune` (School and platform scopes),
  `platform:outbox-prune`, `platform:webhook-deliveries-prune` and
  `platform:failed-jobs-prune`;
- erasure execution, through the same unit services (`ErasureCaseService`
  → `DataSubjectErasurePlanner`).

Row locks the units took with `FOR UPDATE` (which needs UPDATE) go through
`retention_lock_rows`; `ReferencingRows::first()` uses
`retention_first_reference` on the retention connection; the published
announcement ↔ message cycle is broken by
`retention_unlink_announcement_message`. `platform:lifecycle-markers-backfill`
runs on the maintenance (owner) connection (§14.3).

### 14.3 Eligibility sources (`2026_11_30_090100_guard_retention_eligibility_sources`)
- **EmploymentRecord / StudentEnrollment.** `ended_recorded_at` is stamped
  from the database clock when `ends_on` is first set and never changes.
  Once ended, `ends_on` and `status` are fixed and the end is never
  reopened; an end needs a terminal status (Employee: separated,
  terminated, retired, deceased; Student: not `active`); identity columns
  and `starts_on` are fixed (`retention_guard_end_record`,
  `retention_eligibility_guard`). The D9 floor
  (`retention_assert_payroll_employee_floor`, shared by Payroll and HRX) and
  the D7 core floor additionally require `ended_recorded_at < cutoff`; the
  PHP eligibility (`EmployeeRetentionEligibility`,
  `StudentRetentionEligibility`) counts from the later of the two dates.
  Existing ended rows are backfilled from `updated_at`, else `created_at`.
- **`erasure_cases`.** `erasure_cases_guard_transition` admits only the
  workflow's transitions, stamps `requested_at`, `decided_at`,
  `execution_started_at` and `completed_at` from the database clock once
  each, and fixes the decision after it. A case can no longer be backdated
  or reopened to reach `retention_expire_erasure_cases` early (closes
  §12.6).
- **Lifecycle markers.** The past-dated backfill branches of the Guardian
  `no_relationship_since` and Admissions `terminal_at` guards are the schema
  owner's only.
- The schema owner is exempt from these guards (migrations, operator data
  repair).

### 14.4 The database hold boundary for PHP deletes (`2026_11_30_090200_bound_retention_deletes`)
- **`retention_guard_retention_delete()`**: an `AFTER DELETE … FOR EACH
  STATEMENT` trigger with a transition table on each of the 51 tables the
  units delete from. When `session_user` is the retention identity it calls
  `retention_assert_not_held` for every School among the deleted rows (or
  the platform hold for a School-less table) and refuses the whole
  statement under a hold, whatever PHP decided. It takes the shared hold
  lock, so a placement waits for an in-flight unit and a unit after a
  placement is refused (RH.3 semantics). Deletes by any other role (product
  actions where the runtime keeps DELETE) are not retention and pass.
- **Grants.** `school_os_retention` gets SELECT + DELETE on those 51
  tables (never INSERT or UPDATE; table-level SELECT because the units read
  whole rows of what they may delete) and SELECT on 29 read-only tables.
- **Runtime DELETE revoked** wherever only retention used it — the 51 tables
  minus `student_guardian_relationships`, six HR profile tables and
  `failed_jobs` (product or framework deletes), plus `learning_content`,
  `assignments` (closes the §13.7 LMS review) and
  `student_processing_authorizations`.
- **Owner-run triggers.** `guardians_sync_no_relationship_since`,
  `sync_operational_work_backlog` and `sync_email_work_backlog` become SECURITY DEFINER (search path
  pinned; `public` precedes only `pg_temp`, and only the owner can create
  objects in `public`), so a retention delete keeps their projections without
  the role holding UPDATE.
- **User minimization** stays on the runtime role (it touches credential
  tables). `users_guard_minimization_hold` refuses setting `minimized_at`
  while the platform, or any School the User belongs to, is held.
- **PHP checks kept as defence in depth** (`isHeld()`); the database is
  authoritative. Object-storage bytes are deleted only after their rows'
  deletion committed, so a held row keeps its bytes.

### 14.5 Verifier (`platform:verify-database`)
- `retention_functions_narrow`: no `retention_*` function is
  runtime-executable (`RETENTION_FUNCTIONS` is gone);
  `UNIT_RETENTION_FUNCTIONS` adds the RH.6 four and
  `retention_unlink_announcement_message`.
- `retention_read_helpers_closed`: `retention_lock_rows`,
  `retention_first_reference`.
- `retention_deletes_guarded` (the guard is a pinned definer, enabled on
  every RETENTION_DELETES table), `runtime_retention_deletes_revoked`,
  `retention_eligibility_guards` (the four guard triggers present and
  enabled).
- `retention_role_writes_exact`: SELECT and DELETE only, the DELETE set
  exactly RETENTION_DELETES; `retention_role_selects_exact` includes the
  table-level grants.

### 14.6 Proof
`RetentionDeleteBoundaryTest` (raw sessions): the runtime role executes no
retention function and deletes from none of the revoked tables; every
retention-session delete is refused under an active School or platform hold
and passes after release; the lock and probe helpers refuse other sessions
and unlisted tables; the runtime role cannot forge or reopen an end, and the
floors count from the recorded date; erasure cases and lifecycle markers
cannot be backdated; minimization honours every member School's hold; the
verifier passes and detects each regression. The existing race classes now
hold their transactions on the retention identity (an observed lock wait).
- **Runtime boundary in the RLS suites.** A raw cross-School DELETE through
  the runtime role on a revoked table is now refused by privilege
  (`AssertsRuntimeDeleteRevoked`), before RLS is consulted; the retention
  identity's own RLS confinement is proven in the Retention suite.
- **Product paths unchanged.** HR `update()` already strips `starts_on`,
  `ends_on` and `status`, and `end()` refuses an ended record, so the
  eligibility guard refuses nothing the product does. The two
  self-service tests that rewrote an employment by hand now build the state
  they need instead.
- **Test harness.** Every test that runs a retention unit commits its
  fixtures (`CommitsRetentionFixtures`), because the retention connection is
  a separate session. `PurgesCommittedHrxFixtures` also removes the
  School-less email, outbox, receipt and failed-job rows and orphaned
  provider references a run left behind, and records a fixture's
  "years ago" end as recorded then (through the owner, a distinct
  connection alias). A configured test hold is also placed in the database.

### 14.7 Rollback
*Amended by §15.1 (E21-RH.7): the RH.6 eligibility guards are now fenced; a
rollback cannot reach `2026_11_30_090100`'s `down()`.*

None of the three `down()` methods re-grants a runtime privilege: retention
fails closed (owner only) until migrated again. A table-level `REVOKE SELECT`
also strips column-level SELECTs, so the boundary migration's `down()`
captures the retention identity's earlier column grants (RH.2/RH.5) first
and restores them exactly. Verified on the DDEV test database (rollback of
the three migrations, then migrate):
- after rollback: the four function bodies are byte-identical to RH.5 and
  owner-only; every table and column ACL equals the RH.5 snapshot except
  the runtime DELETEs deliberately not restored; no RH.6 function, trigger
  or column remains;
- after migrating again: functions (owner, definer, config, ACL, body),
  table and column ACLs, triggers and columns are identical to the first
  migration;
- `platform:verify-database`: no failure. Rolling
back the eligibility guards drops `ended_recorded_at`; a re-migration
backfills it from `updated_at`, which can only retain longer.

### 14.8 Credentials
Every unit in §14.2 needs `database_retention`. Without it each refuses
(one error, nothing deleted). `platform:lifecycle-markers-backfill` needs
the maintenance connection.

### 14.9 Still open — E21-RH.7 (new pre-production blocker)
Retention periods elsewhere still count from application-written times that
the runtime role can set (for example `created_at`, `occurred_at`,
`sent_at`, `closed_at` on communications, attendance, visitors, audit-like
and housekeeping tables). A compromised runtime could backdate such a row
into eligibility. RH.6 closed the named sources and their class (separation,
exit, erasure, lifecycle markers); RH.7 is the general rule: every
retention-relevant time on a retention table is database-stamped, never
caller-supplied. **E21-RH stays a pre-production blocker until RH.7 closes.**
HRX closure and the E21-L1 ratification status are unchanged.

## 15. E21-RH.7 as built (2026-10-05)
**Retention eligibility counts only from times PostgreSQL recorded. Every
table a retention path deletes from, and every eligibility input it reads,
carries a database-stamped anchor that the runtime role can neither choose
nor rewrite, and PostgreSQL refuses to delete a row recorded within the
unit's period. The RH.6 eligibility guards and the RH.7 anchors are fenced
against rollback.**

### 15.1 The RH.6 rollback fence (owner decision, 2026-10-05)
Security eligibility guards fail closed on rollback. `2026_11_30_090100` is
published and its `down()` drops its guards; published migration history is
not rewritten. Instead `2026_12_01_090000_fence_retention_eligibility_guards`
sits after it:
- `up()` verifies the five RH.6 guard triggers are enabled, both owner-only
  marker branches and the two guard functions are present, and both
  `ended_recorded_at` columns exist. It refuses to record itself otherwise.
- `down()` always throws. `migrate:rollback` stops there.

`2026_12_01_090100` (§15.3) is itself irreversible in the same way.
`migrate:fresh` (test and development resets) drops tables and never runs
`down()`. The operator recovery path is in
`docs/operations/DATABASE-BOOTSTRAP.md` ("Retention security fences"): a
defective guard is replaced by a reviewed forward migration and an ADR
amendment. Disabling a guard by hand is an incident, and the verifier fails.

### 15.2 Inventory and classification
Re-audited from the live database and source (every `retention_expire_*`
function, every PHP unit, the RH.6 direct-delete paths). Every
retention-relevant clock was application-written, and the runtime role could
INSERT it with any value. Most were also UPDATE-able, or immutable only
*after* a caller-chosen first write: `revoked_at`, `ended_at`, `released_at`,
`closed_at`, `posted_at`, `occurred_at`, `created_at`, `processed_at`,
`delivered_at`, `finished_at`, `completed_at`, `checked_out_at`,
`last_activity_at`, `updated_at`, ...

The foreign keys that place a row on an eligibility path (`academic_year_id`,
`subject_offering_id`, `student_enrollment_id`, ...) and the logical links
without a constraint (an outbox `event_id`) were re-linkable too.

| Model | Meaning | Treatment |
|---|---|---|
| A, event time | when the row was written (`created_at`, `occurred_at`, `processed_at`, `received_at`, ...) | the anchor is the database's own record; the domain column keeps its value |
| B, transition time | when a state ended (`revoked_at`, `ended_at`, `closed_at`, `posted_at`, `released_at`, `completed_at`, ...) | the anchor is re-recorded at the transition; a value written in the past buys nothing |
| C, business date | a legitimately historical date (`academic_years.ends_on`, `teaching_assignments.ends_on`, `transport_route_assignments.ends_on`, Student/Employment `ends_on`) | kept as is; eligibility needs the business date AND the anchor (RH.6's `ended_recorded_at` for exits and separations) |
| D, maintenance/import | a legacy time with provenance | only the schema-owner login may set the anchor explicitly; the lifecycle-marker backfill carries the anchor of the audit event it maps from |

### 15.3 Database architecture (`2026_12_01_090100_anchor_retention_eligibility_clocks`)
- **`retention_recorded_at`** on 91 tables (`RetentionAnchors::TABLES`): the
  88 tables a retention path deletes from (the RH.6 51, minus the eight the
  runtime may delete for a product reason, plus the 45 the expiry functions
  delete from), and three inputs no retention path deletes
  (`subject_offerings`, `communication_approval_requests`,
  `financial_periods`). NOT NULL, defaulting to the database clock.
- **`retention_stamp_anchor()`** (one trigger, `zzz_retention_anchor`, firing
  after every other BEFORE trigger; search path pinned; not a definer):
  - INSERT records the database clock, whatever the caller supplied.
  - An UPDATE that sets a tracked column to a new non-NULL value re-records
    it. Tracked columns are the table's clocks and statuses, plus every link:
    each foreign key and each logical `*_id` link, other than user, role,
    request or provider attribution.
  - Any other UPDATE keeps the old record. A caller-supplied anchor is
    ignored.
  - Clearing a value (an `ON DELETE SET NULL` action, an unlink) can only
    remove eligibility, so it does not re-record. Setting a value again does.
  - Only a session whose **login** (`session_user`) is a member of the table
    owner may set the anchor explicitly. A SECURITY DEFINER function called
    by a lower role is never exempt.
- **The delete guard, extended:** `retention_guard_retention_delete()` now
  sits on all 96 tables a retention path deletes from (the RH.6 51 plus the
  45 function-deleted). For a retention-session delete of an anchored table:
  - the unit must have declared its cutoff (`app.retention_anchor_cutoff`) on
    its own session;
  - every deleted row must have been recorded before it;
  - otherwise PostgreSQL refuses (`retention_anchor`).

  Cascaded deletes are checked the same way.
- **Declarations:**
  - Every `retention_expire_*` function declares `p_cutoff` right after its
    identity and hold prologue. The two HRX functions, whose identity check
    is their lock call, declare right after `BEGIN`. The Finance unit
    declares `now() - 8 years`.
  - `RetentionExpiry::retained()` takes a required `$recordedBefore`, which
    is the most recent cutoff a unit applies (Student and Guardian core:
    their authority cutoff). It sets the declaration on the retention session
    for the unit and resets it in `finally`.
  - `privileged()` units (Payroll, LMS, HRX) delete only inside functions,
    which declare their own.
- **Selection** (skip, rather than fail a batch):
  - the eleven bulk expiry functions require `retention_recorded_at < p_cutoff`
    beside each clock;
  - the LMS functions require the offering's anchor, and treat a teaching
    assignment recorded on or after the cutoff as still open;
  - the Finance unit refuses a period recorded within eight years;
  - in PHP, `RetentionAnchors::recordedBefore()` / `recordedOnOrAfter()` (the
    declared cutoff) in `RetentionBatch` and the visitor, portal-invitation,
    admissions, Communications, email, outbox and webhook selections, an
    explicit anchor comparison in the LMS pre-checks, and the Finance
    `period_too_young` blocker.
- **Refusal semantics:** a `retention_anchor` refusal (a late child, or a
  clock written in the past) keeps the unit as `dependency_blocked`, logged as
  `retention.recorded_within_period`. It is never an error.
- **Grants:** the retention identity reads the two new anchor columns the PHP
  LMS checks need (`subject_offerings`, `teaching_assignments`, column-level).
  It has no INSERT or UPDATE anywhere.

### 15.4 Existing rows
There is no trustworthy record of when an existing row was written, so every
pre-existing anchor is this migration's time. Nothing existing becomes
eligible before a full period after RH.7. Uncertainty extends retention; it
never shortens it. (RH.6's `ended_recorded_at` backfill, from `updated_at`,
is superseded in effect: an end now also needs the anchor.)

### 15.5 Behaviour preserved, and the deliberate change
- Product writes are untouched: no clock is rejected or rewritten, and
  business dates keep their meaning.
- A row written in the normal course (before its eligibility event) is
  eligible exactly as before.
- **Deliberate:** a row written or re-linked *recently* now keeps its unit for
  the full period from that write. A Document attached today to an old
  resource keeps it, as does a processing authorization recorded today for a
  long-gone Student.
- **Backfills** (`platform:lifecycle-markers-backfill`) carry the database
  record of their audit evidence, so a genuine legacy decision keeps its true
  age.
- **Hold exemptions are unchanged:** idempotency keys, account recovery and
  staff credentials. The runtime role deletes those itself, so a forged clock
  adds nothing.

### 15.6 Verifier
New checks:
- `retention_anchors_recorded`: every registered table has the anchor, NOT
  NULL, with the one enabled trigger carrying exactly its tracked columns;
  the stamping function is pinned, not a definer, not PUBLIC-executable, and
  checks `session_user`.
- `retention_functions_declare_cutoff`: every expiry function declares its
  cutoff.
- `retention_rollback_fences`: both fence migrations are recorded.

`retention_deletes_guarded` now covers every anchored table that is deleted,
and requires the anchor check in the guard. `retention_role_selects_exact`
includes the two anchor columns.

### 15.7 Rollback
- Both RH.7 migrations throw in `down()` and change nothing. Proven by calling
  each `down()`: the migrations row count and the verifier are unchanged.
- The verifier detects each regression:
  - a disabled anchor trigger or guard trigger;
  - restored runtime EXECUTE on an expiry function;
  - a restored runtime DELETE;
  - a missing fence row.

### 15.8 Remaining runtime DELETE (re-audited)
- **Product deletes:** `student_guardian_relationships`, six HR profile
  tables, `failed_jobs` (RH.6).
- **Inputs never retention-deleted** (`subject_offerings`,
  `communication_approval_requests`): history is kept by RESTRICT
  references, and a runtime delete is a product action, not a retention.
- **Guarded by their own DELETE triggers:** `payroll_run_results` (freeze),
  `fee_assessment_run_items`, `late_fee_run_items`.
- **`payroll_runs`:** an unposted run is discarded by the product; a posted
  run is referenced by its postings (RESTRICT).
- **No TRUNCATE anywhere.**
- **Runtime-executable definers:**
  - `finance_assign_journal_entry_period` (L3, out of scope);
  - `guardians_sync_no_relationship_since`, which stamps the database clock
    or clears, never backdates;
  - the two backlog trigger functions, callable only as triggers.

**E21-RH is closed** when the closure gate (§15.9) is met.

### 15.9 Closure gate
- runtime destructive retention EXECUTE: none (`retention_functions_narrow`);
- runtime direct retention bypass: none known (§15.8);
- holds: enforced in the database on every destructive retention path (§14.4);
- eligibility: cannot be accelerated by a runtime-written clock or re-link
  (§15.3);
- retention identity: narrow, consumes and never records;
- rollback: fenced (§15.1);
- production verification and the full isolated regression: recorded in the
  E21-RH.7 completion report.
