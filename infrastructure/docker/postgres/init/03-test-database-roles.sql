-- Applies the same runtime-role default privileges (see 01-roles.sql)
-- to the school_os_test database -- ALTER DEFAULT PRIVILEGES is
-- per-database, so it must be granted again here even though the
-- school_os_app role itself is cluster-wide.
\c school_os_test

GRANT CONNECT ON DATABASE school_os_test TO school_os_app;
GRANT USAGE ON SCHEMA public TO school_os_app;

ALTER DEFAULT PRIVILEGES FOR ROLE school_os IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO school_os_app;

ALTER DEFAULT PRIVILEGES FOR ROLE school_os IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO school_os_app;

-- E21-RH.2 (ADR 0066): the dedicated retention identity (created in
-- 01-roles.sql) may connect; it gets no default privileges.
GRANT CONNECT ON DATABASE school_os_test TO school_os_retention;
GRANT USAGE ON SCHEMA public TO school_os_retention;
