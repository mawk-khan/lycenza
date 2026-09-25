# Production PostgreSQL bootstrap

ADR 0021, ADR 0050 §6–§7. **Deploy-gated:** creating roles and granting
privileges on a real database is performed by an authorized operator.

## Roles

| Role | Name | Attributes | Used by |
|---|---|---|---|
| Migration/admin | any name the deployment chooses (e.g. the managed service's owner role) — **not** `school_os_app` | owns the schema; can CREATE in `public` | `pgsql_admin`: the release step and the operator console only |
| Runtime | **exactly `school_os_app`** (fixed v1 contract, O6) | `LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION`, never a member of the migration role | `pgsql`: web, workers, scheduler |

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
