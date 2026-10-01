<?php

namespace App\Support\Operations;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 0O.4A (ADR 0050 sections 6-7): read-only verification of the
 * production database role model, with PostgreSQL itself as the source of
 * truth. Runs on the RUNTIME connection (no admin credential needed) and
 * mutates nothing. Results are codes and statuses only.
 */
class DatabaseRoleVerifier
{
    public const RUNTIME_ROLE = 'school_os_app';

    public const MINIMUM_SERVER_VERSION = 160000;

    /**
     * The documented platform-resolvable bootstrap/platform records that
     * carry a school_id WITHOUT row-level security (TENANCY.md, ADR 0049/0050).
     * Any other table with a school_id must have forced RLS.
     */
    public const NON_RLS_SCHOOL_TABLES = [
        'api_client_credentials', 'api_clients', 'domain_event_outbox', 'email_provider_references', 'event_consumer_receipts',
        'school_domains', 'school_elevations', 'school_group_members', 'school_memberships',
        // E21.2F: platform compliance cases (operator console; scoped by School in code).
        'erasure_cases',
    ];

    /** Tables whose history the runtime role must never delete. */
    public const NO_RUNTIME_DELETE = [
        'schools', 'platform_role_assignments', 'school_groups', 'api_clients', 'api_client_credentials',
        'platform_audit_events', 'school_audit_events', 'email_suppressions',
        'membership_role_assignments',
        // E21.2B: authority history expired only through the retention functions.
        'teaching_assignments', 'group_role_assignments', 'school_elevations',
        // E21.2F: erasure cases expire only through their retention function.
        'erasure_cases',
    ];

    /**
     * E21.2B: the narrow retention functions (migration 2026_11_06_090000).
     * They are the ONLY sanctioned way the runtime role removes protected
     * history: SECURITY DEFINER, `search_path` pinned, not owned by the
     * runtime role, executable by it and never by PUBLIC.
     */
    public const RETENTION_FUNCTIONS = [
        'retention_expire_school_audit_events', 'retention_expire_platform_audit_events',
        'retention_expire_released_email_suppressions', 'retention_expire_membership_role_assignments',
        'retention_expire_teaching_assignments', 'retention_expire_school_elevations',
        'retention_expire_group_role_assignments', 'retention_expire_platform_role_assignments',
        // E21.2C
        'retention_expire_communication_delivery_policy_decisions',
        // E21.2F
        'retention_expire_erasure_cases',
    ];

    /**
     * @return list<CheckResult>
     */
    public function verify(): array
    {
        try {
            return $this->checks();
        } catch (Throwable) {
            return [CheckResult::of('database_reachable', false)];
        }
    }

    /**
     * @return list<CheckResult>
     */
    private function checks(): array
    {
        $role = self::RUNTIME_ROLE;
        $results = [];

        $version = (int) DB::selectOne('show server_version_num')->server_version_num;
        $results[] = CheckResult::of('postgres_version_supported', $version >= self::MINIMUM_SERVER_VERSION, (string) intdiv($version, 10000));

        $results[] = CheckResult::of('runtime_connection_uses_runtime_role', DB::selectOne('select current_user as u')->u === $role);

        // ADR 0050 section 7: TLS on the connection. Production refuses to
        // boot without sslmode >= require; this proves the server agreed.
        $ssl = (bool) (DB::selectOne('select ssl from pg_stat_ssl where pid = pg_backend_pid()')->ssl ?? false);
        $results[] = app()->isProduction() || $ssl
            ? CheckResult::of('runtime_connection_encrypted', $ssl)
            : new CheckResult('runtime_connection_encrypted', CheckResult::EVIDENCE, 'not encrypted outside production');

        $attrs = DB::selectOne('select rolcanlogin, rolsuper, rolbypassrls, rolcreatedb, rolcreaterole, rolinherit, rolreplication from pg_roles where rolname = ?', [$role]);
        $results[] = CheckResult::of('runtime_role_exists', $attrs !== null);
        $results[] = CheckResult::of('runtime_role_attributes', $attrs !== null
            && $attrs->rolcanlogin && ! $attrs->rolsuper && ! $attrs->rolbypassrls && ! $attrs->rolcreatedb
            && ! $attrs->rolcreaterole && ! $attrs->rolinherit && ! $attrs->rolreplication);

        $owner = DB::selectOne("select relowner::regrole::text as owner from pg_class where oid = 'platform_role_assignments'::regclass")->owner;
        $member = DB::selectOne('select pg_has_role(?, ?, ?) as m', [$role, $owner, 'MEMBER'])->m;
        $results[] = CheckResult::of('runtime_role_not_owner_member', ! $member && $owner !== $role);

        $schema = DB::selectOne("select has_database_privilege(?, current_database(), 'CONNECT') as connect, has_schema_privilege(?, 'public', 'USAGE') as usage, has_schema_privilege(?, 'public', 'CREATE') as create_", [$role, $role, $role]);
        $results[] = CheckResult::of('runtime_schema_access', $schema->connect && $schema->usage && ! $schema->create_);

        $tables = DB::selectOne("select has_table_privilege(?, 'schools', 'SELECT') and has_table_privilege(?, 'campuses', 'SELECT,INSERT,UPDATE') and has_table_privilege(?, 'domain_event_outbox', 'SELECT,INSERT,UPDATE') as ok", [$role, $role, $role]);
        $results[] = CheckResult::of('runtime_table_privileges', (bool) $tables->ok);

        $deletable = [];
        foreach (self::NO_RUNTIME_DELETE as $table) {
            if (DB::selectOne('select has_table_privilege(?, ?, ?) as d', [$role, $table, 'DELETE'])->d) {
                $deletable[] = $table;
            }
        }
        $results[] = CheckResult::of('runtime_destructive_privileges_restricted', $deletable === [], $deletable === [] ? '' : count($deletable).' table(s)');

        $defaults = DB::selectOne(
            "select bool_or(defaclobjtype = 'r' and defaclacl::text like '%' || ? || '=arwd/%') as tables,
                    bool_or(defaclobjtype = 'S' and defaclacl::text like '%' || ? || '=rU/%') as sequences
             from pg_default_acl where defaclrole = ?::regrole and (defaclnamespace = 'public'::regnamespace or defaclnamespace = 0)",
            [$role, $role, $owner],
        );
        $results[] = CheckResult::of('default_privileges_for_migration_role', (bool) $defaults->tables && (bool) $defaults->sequences);

        $unprotected = DB::select(
            "select c.relname from information_schema.columns col
             join pg_class c on c.relname = col.table_name and c.relnamespace = 'public'::regnamespace and c.relkind = 'r'
             where col.table_schema = 'public' and col.column_name = 'school_id' and not (c.relrowsecurity and c.relforcerowsecurity)",
        );
        $unexpected = array_diff(array_map(fn ($r) => $r->relname, $unprotected), self::NON_RLS_SCHOOL_TABLES);
        $results[] = CheckResult::of('tenant_tables_force_rls', $unexpected === [], $unexpected === [] ? '' : count($unexpected).' table(s)');

        $boundary = DB::selectOne(
            "select t.tgenabled = 'O' as enabled, position('pg_has_role' in p.prosrc) > 0 as owner_check
             from pg_trigger t join pg_proc p on p.oid = t.tgfoid where t.tgname = 'trg_platform_role_assignments_governance'",
        );
        $results[] = CheckResult::of('platform_root_boundary', $boundary !== null && $boundary->enabled && $boundary->owner_check);

        $functions = DB::select(
            "select p.proname, p.prosecdef, coalesce(array_to_string(p.proconfig, ','), '') as config,
                    pg_get_userbyid(p.proowner) as owner, has_function_privilege(?, p.oid, 'EXECUTE') as runtime_exec,
                    (p.proacl is null or exists (select 1 from aclexplode(p.proacl) a where a.grantee = 0 and a.privilege_type = 'EXECUTE')) as public_exec
             from pg_proc p where p.pronamespace = 'public'::regnamespace and p.proname like 'retention\\_%'",
            [$role],
        );
        $narrow = array_filter($functions, fn ($f) => in_array($f->proname, self::RETENTION_FUNCTIONS, true)
            && $f->prosecdef && str_contains($f->config, 'search_path=') && $f->owner !== $role && $f->runtime_exec && ! $f->public_exec);
        // Every retention_* function the runtime role can execute must be one
        // of the sanctioned, narrow ones (the assert helpers are not executable).
        $unexpected = array_filter($functions, fn ($f) => $f->runtime_exec && ! in_array($f->proname, self::RETENTION_FUNCTIONS, true));
        $results[] = CheckResult::of('retention_functions_narrow', count($narrow) === count(self::RETENTION_FUNCTIONS) && $unexpected === []);

        return $results;
    }
}
