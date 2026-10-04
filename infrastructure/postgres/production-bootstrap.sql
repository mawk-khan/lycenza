-- Phase 0O.4A (ADR 0050 section 7, ADR 0021): PRODUCTION PostgreSQL role
-- bootstrap. Run ONCE per database, by a human operator with explicit
-- authorization (ADR 0050 section 19), BEFORE the first migration, as the
-- migration/admin role itself (or a superuser):
--
--   psql "host=... dbname=<app database> user=<migration role> sslmode=require" \
--        -v ON_ERROR_STOP=1 \
--        -v migration_role=<migration role> \
--        -v app_database=<app database> \
--        -f infrastructure/postgres/production-bootstrap.sql
--
-- then set the runtime role's password out of band -- interactively, so it
-- never reaches a file, shell history or the process list:
--
--   psql ... -c '\password school_os_app'
--   psql ... -c '\password school_os_retention'   (E21-RH.2, ADR 0066)
--
-- (or whatever credential mechanism the managed service provides), and
-- store it only in the secret store (ADR 0050 section 4).
--
-- What it does -- and it is safe to run again (idempotent):
--   * refuses a migration role named school_os_app, a missing migration
--     role, or a database other than the one named;
--   * creates `school_os_app` (the FIXED v1 runtime role, ADR 0050
--     section 6) if absent: LOGIN, NOSUPERUSER, NOBYPASSRLS, NOCREATEDB,
--     NOCREATEROLE, NOINHERIT, NOREPLICATION, and NO password;
--   * if it already exists, VERIFIES those attributes and aborts on any
--     difference -- it never silently "fixes" an unexpected role (a
--     managed service may not even permit altering SUPERUSER/BYPASSRLS);
--   * removes any membership of school_os_app in the migration role;
--   * E21-RH.2 (ADR 0066): creates `school_os_retention` (the dedicated
--     retention identity) the same way -- LOGIN, NOSUPERUSER, NOBYPASSRLS,
--     NOCREATEDB, NOCREATEROLE, NOINHERIT, NOREPLICATION, NO password --
--     verifies an existing one, refuses any membership in the migration role
--     or in school_os_app, and grants it only CONNECT and USAGE. It gets NO
--     default privileges: migrations grant its few column-level SELECTs and
--     its EXECUTE on the retention functions explicitly;
--   * grants CONNECT on the database and USAGE on schema public;
--   * sets ALTER DEFAULT PRIVILEGES FOR ROLE <migration role> so every table
--     and sequence the migrations create is usable by the runtime role
--     (SELECT/INSERT/UPDATE/DELETE, USAGE/SELECT) -- migrations then revoke
--     DELETE/UPDATE where the design requires it;
--   * NEVER grants on tables that already exist: a blanket grant after
--     migrations would undo their DELETE/UPDATE revocations. Run it before
--     the first migration; on an already-migrated database it only
--     (re)asserts roles and default privileges.
--
-- It sets no password, contains no secret and creates no database, schema
-- or extension. `php artisan platform:verify-database` (runtime connection,
-- read-only) verifies the result after migrations.

\set ON_ERROR_STOP on

\if :{?migration_role}
\else
  \echo 'production-bootstrap: -v migration_role=<role> is required'
  \quit 3
\endif
\if :{?app_database}
\else
  \echo 'production-bootstrap: -v app_database=<database> is required'
  \quit 3
\endif

SELECT set_config('lycenza.migration_role', :'migration_role', false) AS lyc_migration_role,
       set_config('lycenza.app_database', :'app_database', false) AS lyc_app_database \gset

DO $$
DECLARE
    migration_role text := current_setting('lycenza.migration_role');
    app_database text := current_setting('lycenza.app_database');
BEGIN
    IF current_database() <> app_database THEN
        RAISE EXCEPTION 'production-bootstrap: connected to database %, but app_database is % -- refusing', current_database(), app_database;
    END IF;
    IF migration_role = 'school_os_app' THEN
        RAISE EXCEPTION 'production-bootstrap: the migration role must not be the runtime role school_os_app';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = migration_role) THEN
        RAISE EXCEPTION 'production-bootstrap: migration role % does not exist -- create it first (provider/operator step)', migration_role;
    END IF;
    IF NOT (current_user = migration_role OR (SELECT rolsuper FROM pg_roles WHERE rolname = current_user)) THEN
        RAISE EXCEPTION 'production-bootstrap: run this as the migration role % (or a superuser), not %', migration_role, current_user;
    END IF;
    IF NOT has_schema_privilege(migration_role, 'public', 'CREATE') THEN
        RAISE EXCEPTION 'production-bootstrap: migration role % cannot CREATE in schema public -- it cannot run migrations', migration_role;
    END IF;
    IF current_setting('server_version_num')::int < 160000 THEN
        RAISE EXCEPTION 'production-bootstrap: PostgreSQL 16 or later is required';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'school_os_app') THEN
        CREATE ROLE school_os_app LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION;
        RAISE NOTICE 'production-bootstrap: created school_os_app (no password -- set it with \password)';
    END IF;

    IF EXISTS (
        SELECT 1 FROM pg_roles WHERE rolname = 'school_os_app'
           AND NOT (rolcanlogin AND NOT rolsuper AND NOT rolbypassrls AND NOT rolcreatedb
                    AND NOT rolcreaterole AND NOT rolinherit AND NOT rolreplication)
    ) THEN
        RAISE EXCEPTION 'production-bootstrap: school_os_app exists with unexpected attributes (needs LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION) -- fix it deliberately, then re-run';
    END IF;
END
$$;

-- Never a member of the migration/owner role (it would inherit ownership,
-- i.e. RLS bypass and DDL, through SET ROLE even with NOINHERIT).
SELECT format('REVOKE %I FROM school_os_app', :'migration_role')
 WHERE pg_has_role('school_os_app', :'migration_role', 'MEMBER') \gexec

DO $$
BEGIN
    IF pg_has_role('school_os_app', current_setting('lycenza.migration_role'), 'MEMBER') THEN
        RAISE EXCEPTION 'production-bootstrap: school_os_app is still a member of the migration role (indirect membership?) -- remove it deliberately';
    END IF;
END
$$;

-- E21-RH.2 (ADR 0066): the dedicated retention identity.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'school_os_retention') THEN
        CREATE ROLE school_os_retention LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION;
        RAISE NOTICE 'production-bootstrap: created school_os_retention (no password -- set it with \password)';
    END IF;
    IF EXISTS (
        SELECT 1 FROM pg_roles WHERE rolname = 'school_os_retention'
           AND NOT (rolcanlogin AND NOT rolsuper AND NOT rolbypassrls AND NOT rolcreatedb
                    AND NOT rolcreaterole AND NOT rolinherit AND NOT rolreplication)
    ) THEN
        RAISE EXCEPTION 'production-bootstrap: school_os_retention exists with unexpected attributes (needs LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION) -- fix it deliberately, then re-run';
    END IF;
    IF pg_has_role('school_os_retention', current_setting('lycenza.migration_role'), 'MEMBER')
       OR pg_has_role('school_os_retention', 'school_os_app', 'MEMBER')
       OR pg_has_role('school_os_app', 'school_os_retention', 'MEMBER') THEN
        RAISE EXCEPTION 'production-bootstrap: school_os_retention shares a membership with the migration role or school_os_app -- remove it deliberately';
    END IF;
END
$$;
SELECT format('GRANT CONNECT ON DATABASE %I TO school_os_retention', :'app_database') \gexec
GRANT USAGE ON SCHEMA public TO school_os_retention;

SELECT format('GRANT CONNECT ON DATABASE %I TO school_os_app', :'app_database') \gexec
GRANT USAGE ON SCHEMA public TO school_os_app;

SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO school_os_app', :'migration_role') \gexec
SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO school_os_app', :'migration_role') \gexec

\echo 'production-bootstrap: done. Next: set the school_os_app and school_os_retention passwords out of band, run migrations as the migration role, then php artisan platform:verify-database.'
