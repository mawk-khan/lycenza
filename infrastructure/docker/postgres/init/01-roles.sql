-- Local/dev database role separation. See docs/architecture/adr/0021-postgresql-runtime-vs-migration-roles.md
--
-- The bootstrap role created by POSTGRES_USER (school_os) is the
-- database's initial superuser -- it owns every table and, being a
-- superuser, always bypasses Row-Level Security. It is used ONLY for
-- migrations/maintenance (Laravel's `pgsql_admin` connection).
--
-- school_os_app is a separate, deliberately unprivileged role used by
-- every runtime application connection (web requests, queue workers,
-- scheduled commands -- Laravel's default `pgsql` connection). It is
-- NOSUPERUSER and NOBYPASSRLS, so RLS policies actually apply to it.
--
-- ALTER DEFAULT PRIVILEGES below means any table/sequence the migrator
-- creates from this point forward automatically grants the app role
-- exactly SELECT/INSERT/UPDATE/DELETE (never DDL, never role/grant
-- management) -- no per-migration grant statements required. Audit
-- tables additionally REVOKE UPDATE/DELETE for this role in their own
-- migrations (see the school_audit_events / platform_audit_events
-- migrations) so the append-only guarantee is enforced by the database,
-- not just by application code discipline.

DO $$
BEGIN
   IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = 'school_os_app') THEN
      CREATE ROLE school_os_app
        LOGIN
        PASSWORD 'school_os_app_local_only_password'
        NOSUPERUSER
        NOCREATEDB
        NOCREATEROLE
        NOINHERIT
        NOREPLICATION
        NOBYPASSRLS
        CONNECTION LIMIT -1;
   END IF;
END
$$;

GRANT CONNECT ON DATABASE school_os TO school_os_app;
GRANT USAGE ON SCHEMA public TO school_os_app;

ALTER DEFAULT PRIVILEGES FOR ROLE school_os IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO school_os_app;

ALTER DEFAULT PRIVILEGES FOR ROLE school_os IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO school_os_app;
