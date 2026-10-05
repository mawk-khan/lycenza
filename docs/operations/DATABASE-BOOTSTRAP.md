# Production PostgreSQL bootstrap

ADR 0021, ADR 0050 §6–§7. **Deploy-gated:** creating roles and granting
privileges on a real database is performed by an authorized operator.

## Roles

| Role | Name | Attributes | Used by |
|---|---|---|---|
| Migration/admin | any name the deployment chooses (e.g. the managed service's owner role) — **not** `school_os_app` | owns the schema; can CREATE in `public` | `pgsql_admin`: the release step and the operator console only |
| Runtime | **exactly `school_os_app`** (fixed v1 contract, O6) | `LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION`, never a member of the migration role | `pgsql`: web, workers, scheduler |
| Retention (E21-RH.2, ADR 0066) | **exactly `school_os_retention`** | same attributes; a member of no role (nor the migration role nor `school_os_app`); owns nothing; **no default privileges** -- migrations grant it only EXECUTE on the approved retention functions, SELECT on what its units read, and SELECT + DELETE (never INSERT or UPDATE) on the tables its units delete from, each delete held in the database (E21-RH.6) | `pgsql_retention`: the destructive steps of scheduled retention (scheduler) and reviewed erasure cases (operator console) |

Production requires PostgreSQL 16+ and TLS (`DB_SSLMODE=require` or
stricter for both connections; the application refuses to boot otherwise).

## Procedure (once per database, before the first migration)

1. Create the database and the migration role with the provider's tooling
   (not scripted: provider-specific).
2. Run the bootstrap **as the migration role**:

   ```bash
   psql "host=<host> dbname=<database> user=<migration role> sslmode=verify-full" \
        -v ON_ERROR_STOP=1 -v migration_role=<migration role> -v app_database=<database> \
        -f infrastructure/postgres/production-bootstrap.sql
   ```

   It refuses a migration role named `school_os_app`, a missing role, a
   database mismatch, a pre-PostgreSQL-16 server, and an existing
   `school_os_app` with unexpected attributes (it verifies; it never
   silently "fixes" a role). It removes any membership of `school_os_app`
   in the migration role, grants `CONNECT` and schema `USAGE`, and sets
   `ALTER DEFAULT PRIVILEGES FOR ROLE <migration role>` so migrated tables
   and sequences are usable by the runtime role. It is idempotent.
3. Set the runtime password **out of band**, interactively, and store it
   only in the secret store: `psql … -c '\password school_os_app'` (or the
   provider's credential mechanism). The script never sets a password.
   Do the same for the retention identity (E21-RH.2):
   `psql … -c '\password school_os_retention'`, stored only as
   `DB_RETENTION_USERNAME`/`DB_RETENTION_PASSWORD` in the
   `database_retention` secret group (scheduler, operator console). The
   bootstrap creates `school_os_retention` like `school_os_app` (verifies an
   existing one, refuses any membership, grants only `CONNECT`/`USAGE`), and
   the migrations refuse to run until it exists.
   - Without the retention credential, destructive retention units refuse
     (counted as errors, nothing deleted). They never fall back to the
     migration or runtime login.
   - Since E21-RH.4 (ADR 0066 §12) that includes the standalone expiries of
     `platform:audit-prune`, `platform:authority-history-prune`,
     `platform:email-suppressions-prune` and the policy-decision step of
     `platform:communications-prune`. Each reports one error per category
     and deletes nothing there.
   - Since E21-RH.5 (ADR 0066 §13) it also includes
     `platform:payroll-retention-prune` and the LMS steps of
     `platform:academic-retention-prune`. Their destructive runs also refuse
     while a configured hold is not yet reconciled into the database.
   - Since E21-RH.6 (ADR 0066 §14) every retention unit needs it:
     `platform:student-retention-prune`, `platform:employee-retention-prune`,
     the Guardian, academic, operational, Communications, Admissions,
     portal-invitation and Finance prunes, erasure execution, and
     `platform:email-prune`, `platform:outbox-prune`,
     `platform:webhook-deliveries-prune`, `platform:failed-jobs-prune`.
     The runtime login no longer holds DELETE on the tables only retention
     deleted from. `platform:lifecycle-markers-backfill` runs on the
     migration connection (operator console only).
   - Retention holds are database state (E21-RH.3, ADR 0066 §6). See
     [RETENTION-HOLDS.md](RETENTION-HOLDS.md) for placing, releasing and
     reconciling them; configuration removal never releases one.
4. Release step: `console migrate --database=pgsql_admin --force`, then
   `console db:seed --force` (production-safe catalogs,
   `PRODUCTION-RELEASE.md` step 7).
5. Verify on the runtime connection: `console platform:verify-database` —
   every check `PASS`, including `runtime_connection_encrypted`.

**Never run a blanket `GRANT … ON ALL TABLES` afterwards.** Migrations
revoke `DELETE`/`UPDATE` from the runtime role on history tables
(`schools`, audit ledgers, platform role assignments, API clients, …);
a blanket grant would silently undo that. The bootstrap only sets default
privileges, so re-running it on a migrated database changes nothing on
existing tables.

## Proof (repository only)

`infrastructure/postgres/verify-production-bootstrap.sh` runs the whole
procedure against a **throwaway** `postgres:16` container with TLS and a
migration role deliberately not named `school_os`, through the production
application image: refusals, idempotency (bootstrap three times, the last
after migrations), attributes, no membership, migrations and seeding as
production runs them, `platform:verify-database` all green, TLS confirmed
by the server, `DELETE` revocations intact after a re-run. Everything is
removed on exit.

## What the verification command checks

`platform:verify-database` (read-only, runtime connection): server
version ≥ 16; connected as `school_os_app`; the TLS state of that
connection (FAIL in production if unencrypted); role attributes; not the
owner and not a member of the owner role; `CONNECT`/`USAGE` but no
`CREATE` on `public`; working table privileges; no `DELETE` on the
history tables; default privileges for the migration role; forced RLS on
every table with a `school_id` except the documented platform-resolvable
bootstrap tables (`api_client_credentials`, `api_clients`,
`domain_event_outbox`, `event_consumer_receipts`, `school_domains`,
`school_elevations`, `school_group_members`, `school_memberships` —
guard-tested against the real schema); the 0O.1A root-grant boundary
trigger enabled.
