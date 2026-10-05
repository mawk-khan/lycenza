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

    /** E21-RH.2 (ADR 0066 §3.1): the dedicated retention identity. */
    public const RETENTION_ROLE = 'school_os_retention';

    /** E21-RH.2: the connection authenticated as RETENTION_ROLE (checked only where its credential is configured). */
    public const RETENTION_CONNECTION = 'pgsql_retention';

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
        // E21-RH.3: the authoritative retention holds; the runtime role has no privilege on them at all.
        'retention_holds',
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
        // E21.3A (ADR 0064): financial periods and their close baselines.
        'financial_periods', 'financial_period_account_balances', 'financial_period_charge_states',
        // E21.3A2: Finance ledgers lose detail only through retention_expire_finance_unit.
        'financial_period_expiries', 'journal_entries', 'journal_lines', 'charges', 'payments', 'payment_allocations', 'payment_receipts',
        // E21.3B: Student consent evidence leaves only with its core record, through its retention function.
        'communication_domain_consent_events',
        // E21.3F: posted payroll adjustments and postings leave only through the payroll retention functions.
        'payroll_adjustments', 'payroll_run_postings', 'payroll_statutory_run_postings',
        // E21-RH.1: posted LWF annual charges leave only through retention_expire_payroll_employee_evidence.
        'payroll_lwf_annual_charges',
        // E21.4 (F1): a User is never hard-deleted; a raw delete would null audit/grant actors and cascade memberships.
        'users',
    ];

    /**
     * E21-RH.1: tables whose rows the runtime role must never rewrite. The
     * destructive-privilege check covers both lists (privilege => tables);
     * a table joins this list only with evidence that no runtime path updates
     * it.
     */
    public const NO_RUNTIME_UPDATE = [
        // E21-RH.1: posted LWF annual charges are insert-once evidence.
        'payroll_lwf_annual_charges',
    ];

    /**
     * E21.3A (ADR 0064 §3): the one narrow Finance definer function, the
     * backfill's NULL -> containing-open-period assignment. Same shape rules
     * as the retention functions.
     */
    public const FINANCE_FUNCTIONS = ['finance_assign_journal_entry_period'];

    /**
     * E21.2B: the narrow retention functions (migration 2026_11_06_090000).
     * They are the ONLY sanctioned way the runtime role removes protected
     * history: SECURITY DEFINER, `search_path` pinned, not owned by the
     * runtime role, executable by it and never by PUBLIC.
     */
    public const RETENTION_FUNCTIONS = [
        // E21-RH.4 moved the eleven standalone functions to STANDALONE_RETENTION_FUNCTIONS; what remains
        // here is the coupled set the runtime role still executes until E21-RH.5 / RH.6 (temporary).
        // E21.3A2 (E21-D8): one settled Finance unit, period-floored in the database -- RH.6
        'retention_expire_finance_unit',
        // E21.3B (E21-D7 core): one Student's append-only core evidence, core-floored in the database -- RH.6
        'retention_expire_student_processing_authorizations', 'retention_expire_student_consent_events',
        // E21.3C (E21.2G G1): one Guardian's consent events, Guardian-floored in the database -- RH.6
        'retention_expire_guardian_consent_events',
        // E21.3D (E21.2G A1): one LMS resource with its audiences, year- and D6-floored in the database -- RH.5
        'retention_expire_learning_content', 'retention_expire_assignment',
        // E21.3F (E21-D9): one Employee's posted payroll evidence, separation-floored; one emptied payroll run -- RH.5
        'retention_expire_payroll_employee_evidence', 'retention_expire_payroll_run',
    ];

    /**
     * E21-RH.4: the standalone legacy functions now executable only by the
     * retention identity. Each refuses any other session user
     * (`retention_assert_retention_identity()`) and, destructively, any
     * active platform hold -- and, School-scoped, its School's hold
     * (`retention_assert_not_held()`).
     */
    public const STANDALONE_RETENTION_FUNCTIONS = [
        // School-scoped
        'retention_expire_school_audit_events', 'retention_expire_membership_role_assignments',
        'retention_expire_teaching_assignments', 'retention_expire_school_elevations',
        'retention_expire_communication_delivery_policy_decisions', 'retention_expire_api_client_credentials',
        // School-less
        'retention_expire_platform_audit_events', 'retention_expire_released_email_suppressions',
        'retention_expire_group_role_assignments', 'retention_expire_platform_role_assignments',
        'retention_expire_erasure_cases',
    ];

    /**
     * HRX.6 hardening / E21-RH.2: destructive retention functions the
     * runtime role must NOT execute -- the APPROVED set for the dedicated
     * retention identity, which must hold EXECUTE on exactly these and on no
     * other destructive retention function. SECURITY DEFINER, `search_path`
     * pinned, not owned by the runtime role, never PUBLIC; they refuse any
     * other session user and any held School themselves. Later E21-RH slices
     * extend this list (and move entries out of RETENTION_FUNCTIONS).
     */
    public const PRIVILEGED_RETENTION_FUNCTIONS = [
        // One Employee's Leave / Staff Attendance evidence, separation-floored and hold-checked
        'retention_expire_leave_employee_evidence', 'retention_expire_staff_attendance_employee_evidence',
    ];

    /**
     * E21-RH.3: read-only functions the retention identity also executes:
     * the active hold scopes (no history, no attribution) its PHP side reads.
     */
    public const RETENTION_READ_FUNCTIONS = ['retention_hold_active_scopes'];

    /** E21-RH.3: the hold writers -- owner (operator maintenance) only, never PUBLIC, runtime or retention. */
    public const HOLD_MAINTENANCE_FUNCTIONS = ['retention_hold_place', 'retention_hold_release', 'retention_assert_not_held', 'retention_assert_retention_identity'];

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

        $granted = [];
        foreach (['DELETE' => self::NO_RUNTIME_DELETE, 'UPDATE' => self::NO_RUNTIME_UPDATE] as $privilege => $tables) {
            foreach ($tables as $table) {
                if (DB::selectOne('select has_table_privilege(?, ?, ?) as d', [$role, $table, $privilege])->d) {
                    $granted[] = "{$table}:{$privilege}";
                }
            }
        }
        $results[] = CheckResult::of('runtime_destructive_privileges_restricted', $granted === [], $granted === [] ? '' : count($granted).' grant(s)');

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
            "select p.proname, p.prosecdef, coalesce(array_to_string(p.proconfig, ','), '') as config, p.prosrc as src,
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
        $privileged = [...self::PRIVILEGED_RETENTION_FUNCTIONS, ...self::STANDALONE_RETENTION_FUNCTIONS];
        $closed = array_filter($functions, fn ($f) => in_array($f->proname, $privileged, true)
            && $f->prosecdef && str_contains($f->config, 'search_path=') && $f->owner !== $role && ! $f->runtime_exec && ! $f->public_exec
            && (! in_array($f->proname, self::STANDALONE_RETENTION_FUNCTIONS, true) || (str_contains($f->src, 'retention_assert_retention_identity()') && str_contains($f->src, 'retention_assert_not_held('))));
        $holds = DB::selectOne("select has_table_privilege(?, 'retention_holds', 'SELECT,INSERT,UPDATE,DELETE,TRUNCATE') as any", [$role]);
        $results[] = CheckResult::of('privileged_retention_functions_closed', count($closed) === count($privileged) && ! $holds->any);
        $results = [...$results, ...$this->retentionIdentityChecks()];

        $finance = DB::select(
            "select p.proname, p.prosecdef, coalesce(array_to_string(p.proconfig, ','), '') as config,
                    pg_get_userbyid(p.proowner) as owner, has_function_privilege(?, p.oid, 'EXECUTE') as runtime_exec,
                    (p.proacl is null or exists (select 1 from aclexplode(p.proacl) a where a.grantee = 0 and a.privilege_type = 'EXECUTE')) as public_exec
             from pg_proc p where p.pronamespace = 'public'::regnamespace and p.prosecdef and p.proname like 'finance\\_%'",
            [$role],
        );
        $narrowFinance = array_filter($finance, fn ($f) => in_array($f->proname, self::FINANCE_FUNCTIONS, true)
            && str_contains($f->config, 'search_path=') && $f->owner !== $role && $f->runtime_exec && ! $f->public_exec);
        $results[] = CheckResult::of('finance_period_functions_narrow', count($narrowFinance) === count(self::FINANCE_FUNCTIONS) && count($finance) === count(self::FINANCE_FUNCTIONS));

        return $results;
    }

    /**
     * E21-RH.2 (ADR 0066 §3.1): the dedicated retention identity is narrow,
     * shares no role with the runtime or owner role, owns nothing, writes
     * nothing directly, executes exactly the approved destructive retention
     * functions -- and, where this process holds its credential, the
     * retention connection really authenticates as it.
     *
     * @return list<CheckResult>
     */
    private function retentionIdentityChecks(): array
    {
        $role = self::RETENTION_ROLE;
        $attrs = DB::selectOne('select oid, rolcanlogin, rolsuper, rolbypassrls, rolcreatedb, rolcreaterole, rolinherit, rolreplication from pg_roles where rolname = ?', [$role]);
        if ($attrs === null) {
            return [CheckResult::of('retention_role_narrow', false, 'missing')];
        }

        $owner = DB::selectOne("select relowner::regrole::text as owner from pg_class where oid = 'public.employees'::regclass")->owner;
        $shared = DB::selectOne(
            'select exists (select 1 from pg_auth_members m where m.member = ? or m.roleid = ?) as any,
                    pg_has_role(?, ?, ?) or pg_has_role(?, ?, ?) or pg_has_role(?, ?, ?) as reach',
            [$attrs->oid, $attrs->oid, $role, $owner, 'MEMBER', $role, self::RUNTIME_ROLE, 'MEMBER', self::RUNTIME_ROLE, $role, 'MEMBER'],
        );
        $owns = DB::selectOne(
            'select (select count(*) from pg_class where relowner = ?) + (select count(*) from pg_proc where proowner = ?)
                  + (select count(*) from pg_namespace where nspowner = ?) as n',
            [$attrs->oid, $attrs->oid, $attrs->oid],
        )->n;
        $results = [CheckResult::of('retention_role_narrow', $attrs->rolcanlogin && ! $attrs->rolsuper && ! $attrs->rolbypassrls && ! $attrs->rolcreatedb
            && ! $attrs->rolcreaterole && ! $attrs->rolinherit && ! $attrs->rolreplication && ! $shared->any && ! $shared->reach && (int) $owns === 0)];

        // Read-only: no table-level grant at all beyond SELECT, and no column-level write.
        // Raw ACLs: information_schema only shows grants involving the CURRENT role.
        $writes = DB::selectOne(
            "select (select count(*) from pg_class c cross join lateral aclexplode(c.relacl) a where a.grantee = ? and a.privilege_type <> 'SELECT')
                  + (select count(*) from pg_attribute t cross join lateral aclexplode(t.attacl) a where a.grantee = ? and a.privilege_type <> 'SELECT') as n",
            [$attrs->oid, $attrs->oid],
        )->n;
        $results[] = CheckResult::of('retention_role_read_only', (int) $writes === 0, (int) $writes === 0 ? '' : $writes.' grant(s)');

        // EXECUTE on exactly the approved destructive retention functions; never a legacy one.
        $executable = array_column(DB::select(
            "select p.proname from pg_proc p where p.pronamespace = 'public'::regnamespace and p.proname like 'retention\\_%'
                and has_function_privilege(?, p.oid, 'EXECUTE')",
            [$role],
        ), 'proname');
        sort($executable);
        $approved = [...self::PRIVILEGED_RETENTION_FUNCTIONS, ...self::STANDALONE_RETENTION_FUNCTIONS, ...self::RETENTION_READ_FUNCTIONS];
        sort($approved);
        $results[] = CheckResult::of('retention_role_functions_exact', $executable === $approved, $executable === $approved ? '' : count(array_diff($executable, $approved)).' unexpected, '.count(array_diff($approved, $executable)).' missing');

        $results[] = $this->authoritativeHoldsCheck($attrs->oid, (string) $owner);

        // Where this process holds the credential: the connection is really the retention login, unelevated.
        $username = config('database.connections.'.self::RETENTION_CONNECTION.'.username');
        if (! is_string($username) || trim($username) === '') {
            $results[] = new CheckResult('retention_connection_identity', CheckResult::EVIDENCE, 'not configured in this process');
        } else {
            try {
                $identity = DB::connection(self::RETENTION_CONNECTION)->selectOne(
                    'select session_user::text as login, r.rolsuper or r.rolbypassrls as elevated from pg_roles r where r.rolname = session_user',
                );
                $results[] = CheckResult::of('retention_connection_identity', $identity !== null && $identity->login === $role && ! $identity->elevated);
            } catch (Throwable) {
                $results[] = CheckResult::of('retention_connection_identity', false, 'unreachable');
            } finally {
                DB::purge(self::RETENTION_CONNECTION);
            }
        }

        return $results;
    }

    /**
     * E21-RH.3 (ADR 0066 §6): the authoritative hold store is owned by the
     * schema owner, granted to nobody (no PUBLIC, runtime or retention
     * privilege), its history guard is enabled, the writers and the assert
     * helper are owner-only invokers, and the retention identity's only hold
     * access is the read-only active-scopes definer.
     */
    private function authoritativeHoldsCheck(string $retentionOid, string $owner): CheckResult
    {
        $table = DB::selectOne(
            "select relowner::regrole::text as owner, exists (select 1 from aclexplode(relacl) a where a.grantee <> relowner) as granted,
                    (select count(*) from pg_trigger where tgrelid = c.oid and tgname in ('trg_retention_holds_guard', 'trg_retention_holds_no_truncate') and tgenabled = 'O') as guards
               from pg_class c where oid = to_regclass('public.retention_holds')",
        );
        if ($table === null) {
            return CheckResult::of('retention_holds_authoritative', false, 'missing');
        }

        $functions = collect(DB::select(
            "select p.proname, p.prosecdef, coalesce(array_to_string(p.proconfig, ','), '') as config, pg_get_userbyid(p.proowner) as owner,
                    has_function_privilege(?, p.oid, 'EXECUTE') as runtime, has_function_privilege(?::oid, p.oid, 'EXECUTE') as retention,
                    exists (select 1 from aclexplode(p.proacl) a where a.grantee = 0) as public
               from pg_proc p where p.pronamespace = 'public'::regnamespace and p.proname = any (?::text[])",
            [self::RUNTIME_ROLE, $retentionOid, '{'.implode(',', [...self::HOLD_MAINTENANCE_FUNCTIONS, ...self::RETENTION_READ_FUNCTIONS]).'}'],
        ))->keyBy('proname');

        $ok = $table->owner === $owner && ! $table->granted && (int) $table->guards === 2
            && $functions->count() === count(self::HOLD_MAINTENANCE_FUNCTIONS) + count(self::RETENTION_READ_FUNCTIONS);
        foreach ($functions as $name => $f) {
            $read = in_array($name, self::RETENTION_READ_FUNCTIONS, true);
            $ok = $ok && $f->owner === $owner && str_contains($f->config, 'search_path=') && ! $f->public && ! $f->runtime
                && (bool) $f->prosecdef === $read && (bool) $f->retention === $read;
        }

        return CheckResult::of('retention_holds_authoritative', $ok);
    }
}
