<?php

namespace App\Support\Operations;

use App\Support\Retention\RetentionAnchors;
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
        // OPF.1 (ADR 0067 §12): Transport's fee-selection provenance is insert-only Finance evidence.
        'transport_fee_selections',
        // OPF.2 (ADR 0067 §28): so is Hostel's.
        'hostel_fee_selections',
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
        // OPF.1 (ADR 0067 §12): Transport's fee-selection provenance is insert-only.
        'transport_fee_selections',
        // OPF.2 (ADR 0067 §28): so is Hostel's.
        'hostel_fee_selections',
    ];

    /**
     * E21.3A (ADR 0064 §3): the one narrow Finance definer function, the
     * backfill's NULL -> containing-open-period assignment. Same shape rules
     * as the retention functions.
     */
    public const FINANCE_FUNCTIONS = ['finance_assign_journal_entry_period'];

    /**
     * E21-RH.5: the Payroll and LMS unit functions, executable only by the
     * retention identity and called inside its whole-unit transaction. Each
     * refuses any other session user and any active platform or School hold
     * (dry runs included). `retention_expire_lms_resource` is the whole
     * destructive LMS unit (its Documents, then the LMS function).
     */
    public const UNIT_RETENTION_FUNCTIONS = [
        // E21.3F (E21-D9): one Employee's posted payroll evidence, separation-floored; one emptied payroll run
        'retention_expire_payroll_employee_evidence', 'retention_expire_payroll_run',
        // E21.3D (E21.2G A1): one LMS resource with its audiences, year- and D6-floored; the unit with its Documents
        'retention_expire_learning_content', 'retention_expire_assignment', 'retention_expire_lms_resource',
        // E21-RH.6: one settled Finance unit (D8); one Student's core evidence (D7); one Guardian's consent events
        'retention_expire_finance_unit', 'retention_expire_student_processing_authorizations', 'retention_expire_student_consent_events',
        'retention_expire_guardian_consent_events',
        // E21-RH.6: the one UPDATE the Communications purge needs (break the announcement <-> message cycle)
        'retention_unlink_announcement_message',
    ];

    /**
     * E21-RH.6 (ADR 0066 §14): the tables the PHP retention units delete
     * from as the retention identity. The role holds DELETE (and SELECT) on
     * exactly these, never INSERT or UPDATE, and each carries the hold guard
     * `retention_guard_retention_delete` (a retention-session delete of a
     * held School or under a platform hold is refused in PostgreSQL).
     */
    public const RETENTION_DELETES = [
        'students', 'student_enrollments', 'student_subject_enrollments', 'student_guardian_relationships', 'student_guardian_account_links',
        'attendance_records', 'enrollment_rollover_items', 'library_loans', 'transport_student_assignments', 'hostel_residency_assignments',
        'admission_applications', 'applicants', 'communication_domain_preferences', 'documents',
        'guardians', 'guardian_contacts',
        'employees', 'employment_records', 'employee_assignments', 'employee_addresses', 'employee_emergency_contacts', 'employee_notes',
        'employee_qualifications', 'employee_experience_records', 'employee_certifications', 'employee_personal_details', 'employee_documents',
        'employee_compensation_assignments', 'employee_statutory_identifiers', 'employee_tax_profile', 'employee_pf_status', 'employee_esi_coverage',
        'curriculum_deliveries', 'attendance_sessions', 'timetable_entries', 'transport_route_assignments', 'automation_executions',
        'visitors', 'visitor_visits', 'identity_account_invitations',
        'communication_deliveries', 'communication_messages', 'communication_announcements', 'communication_threads',
        'email_messages', 'email_provider_references', 'email_events', 'domain_event_outbox', 'event_consumer_receipts', 'webhook_deliveries', 'failed_jobs',
    ];

    /** E21-RH.6: where the product (or the framework) deletes too, so the runtime role keeps DELETE. */
    public const RUNTIME_PRODUCT_DELETES = [
        'student_guardian_relationships', 'employee_addresses', 'employee_emergency_contacts', 'employee_notes',
        'employee_qualifications', 'employee_experience_records', 'employee_certifications', 'failed_jobs',
    ];

    /** E21-RH.6: tables whose rows only retention removes, in the database since E21-RH.5 (runtime DELETE revoked). */
    public const RUNTIME_DELETES_REVOKED_EXTRA = ['learning_content', 'assignments', 'student_processing_authorizations'];

    /** E21-RH.6: read-only tables the moved units read whole (production readers); table-level SELECT only. */
    public const RETENTION_TABLE_READS = [
        'schools', 'academic_years', 'subject_offerings', 'sections', 'school_memberships', 'school_settings',
        'student_processing_authorizations', 'communication_domain_consent_events',
        'financial_periods', 'financial_period_account_balances', 'financial_period_expiries', 'financial_period_charge_states',
        'journal_entries', 'journal_lines', 'ledger_accounts', 'charges', 'payments', 'payment_allocations', 'payment_receipt_counters',
        'late_fee_assessments', 'payroll_run_postings', 'payroll_statutory_run_postings',
        'fee_adjustments', 'fee_assessments', 'fee_heads', 'fee_structure_installments',
        'communication_approval_requests', 'communication_attachments',
        // The email prune keeps an event a current suppression still rests on (it reads only whether one exists)
        'email_suppressions',
    ];

    /** E21-RH.6: the database guards against manufactured retention eligibility and the User-erasure hold boundary. */
    public const ELIGIBILITY_GUARDS = [
        'employment_records' => 'trg_employment_records_eligibility_guard',
        'student_enrollments' => 'trg_student_enrollments_eligibility_guard',
        'erasure_cases' => 'trg_erasure_cases_guard_transition',
        'users' => 'users_guard_minimization_hold',
    ];

    /**
     * E21-RH.2 / RH.5: the exact column-level SELECTs of the retention
     * identity -- what its whole-unit PHP reads need, nothing else (no
     * table-level grant, no write). table => sorted columns.
     */
    /** E21-RH.7: the irreversible security migrations a rollback must never have passed. */
    public const RETENTION_FENCES = [
        '2026_12_01_090000_fence_retention_eligibility_guards',
        '2026_12_01_090100_anchor_retention_eligibility_clocks',
    ];

    public const RETENTION_SELECTS = [
        // E21-RH.2: the HRX units
        'employees' => ['id', 'school_id'],
        'employment_records' => ['employee_id', 'ends_on', 'id', 'status'],
        'leave_ledger_entries' => ['employment_record_id'],
        'leave_policy_assignments' => ['employment_record_id'],
        'leave_requests' => ['employee_id'],
        'leave_year_close_items' => ['employment_record_id'],
        'staff_attendance_records' => ['employee_id'],
        // E21-RH.5: the Payroll units
        'payroll_adjustments' => ['employment_record_id'],
        'payroll_lwf_annual_charges' => ['employment_record_id'],
        'payroll_run_results' => ['employee_id', 'payroll_run_id'],
        'payroll_runs' => ['id', 'posted_at', 'results_expired_at', 'run_kind', 'school_id', 'status'],
        // E21-RH.5: the LMS units
        'academic_years' => ['ends_on', 'id', 'school_id'],
        'assignment_section_audiences' => ['assignment_id', 'school_id', 'section_id'],
        'assignments' => ['id', 'owner_employee_id', 'school_id', 'subject_offering_id'],
        'documents' => ['assignment_id', 'id', 'learning_content_id'],
        'learning_content' => ['id', 'owner_employee_id', 'school_id', 'subject_offering_id'],
        'learning_content_section_audiences' => ['learning_content_id', 'school_id', 'section_id'],
        // E21-RH.7: + the database-recorded anchors the PHP LMS checks read.
        'subject_offerings' => ['academic_year_id', 'id', 'retention_recorded_at', 'school_id'],
        'teaching_assignments' => ['employee_id', 'ends_on', 'retention_recorded_at', 'school_id', 'section_id', 'subject_offering_id'],
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
    public const RETENTION_READ_FUNCTIONS = [
        'retention_hold_active_scopes',
        // E21-RH.6: the lock-only row locker and the read-only dependency probe (identity-checked, never mutate)
        'retention_lock_rows', 'retention_first_reference',
    ];

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
        // E21-RH.6 (H1 closed): the runtime role executes NO retention_* function -- every destructive one
        // belongs to the retention identity, the helpers to their owner or the retention identity.
        $unexpected = array_filter($functions, fn ($f) => $f->runtime_exec);
        $results[] = CheckResult::of('retention_functions_narrow', $unexpected === [], $unexpected === [] ? '' : count($unexpected).' runtime-executable');
        $privileged = [...self::PRIVILEGED_RETENTION_FUNCTIONS, ...self::STANDALONE_RETENTION_FUNCTIONS, ...self::UNIT_RETENTION_FUNCTIONS];
        $prologued = [...self::STANDALONE_RETENTION_FUNCTIONS, ...self::UNIT_RETENTION_FUNCTIONS];
        $closed = array_filter($functions, fn ($f) => in_array($f->proname, $privileged, true)
            && $f->prosecdef && str_contains($f->config, 'search_path=') && $f->owner !== $role && ! $f->runtime_exec && ! $f->public_exec
            && (! in_array($f->proname, $prologued, true) || (str_contains($f->src, 'retention_assert_retention_identity()') && str_contains($f->src, 'retention_assert_not_held('))));
        $holds = DB::selectOne("select has_table_privilege(?, 'retention_holds', 'SELECT,INSERT,UPDATE,DELETE,TRUNCATE') as any", [$role]);
        $results[] = CheckResult::of('privileged_retention_functions_closed', count($closed) === count($privileged) && ! $holds->any);
        // E21-RH.6: the retention identity's read helpers are definers, pinned, identity-checked, never runtime/PUBLIC.
        $helpers = array_filter($functions, fn ($f) => in_array($f->proname, ['retention_lock_rows', 'retention_first_reference'], true)
            && $f->prosecdef && str_contains($f->config, 'search_path=') && ! $f->runtime_exec && ! $f->public_exec
            && str_contains($f->src, 'retention_assert_retention_identity()'));
        $results[] = CheckResult::of('retention_read_helpers_closed', count($helpers) === 2);
        $results = [...$results, ...$this->retentionBoundaryChecks($role)];
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
    /**
     * E21-RH.6 (ADR 0066 §14): the retention-delete hold boundary, the end of
     * runtime retention-only DELETE, and the eligibility-source guards.
     *
     * @return list<CheckResult>
     */
    private function retentionBoundaryChecks(string $runtime): array
    {
        $guarded = DB::select(
            "select c.relname from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_proc p on p.oid = t.tgfoid
              where p.proname = 'retention_guard_retention_delete' and t.tgenabled = 'O' and c.relnamespace = 'public'::regnamespace",
        );
        $guardedTables = array_column($guarded, 'relname');
        $guardFn = DB::selectOne("select p.prosecdef, coalesce(array_to_string(p.proconfig, ','), '') as config,
                (p.proacl is null or exists (select 1 from aclexplode(p.proacl) a where a.grantee = 0 and a.privilege_type = 'EXECUTE')) as public_exec
             from pg_proc p where p.pronamespace = 'public'::regnamespace and p.proname = 'retention_guard_retention_delete'");
        // E21-RH.7: every anchored table a retention path deletes from is guarded, and the guard enforces the recorded anchor.
        $mustGuard = [...self::RETENTION_DELETES, ...array_diff(array_keys(RetentionAnchors::TABLES), RetentionAnchors::INPUTS)];
        $guardBody = (string) DB::selectOne("select p.prosrc from pg_proc p where p.pronamespace = 'public'::regnamespace and p.proname = 'retention_guard_retention_delete'")?->prosrc;
        $results = [CheckResult::of('retention_deletes_guarded', $guardFn !== null && $guardFn->prosecdef && str_contains($guardFn->config, 'search_path=') && ! $guardFn->public_exec
            && str_contains($guardBody, 'retention_anchor') && array_diff($mustGuard, $guardedTables) === [])];

        $revoked = array_values(array_diff([...self::RETENTION_DELETES, ...self::RUNTIME_DELETES_REVOKED_EXTRA], self::RUNTIME_PRODUCT_DELETES));
        $stillDeletable = array_filter($revoked, fn (string $table) => (bool) DB::selectOne('select has_table_privilege(?, ?, ?) as p', [$runtime, 'public.'.$table, 'DELETE'])->p);
        $results[] = CheckResult::of('runtime_retention_deletes_revoked', $stillDeletable === [], $stillDeletable === [] ? '' : count($stillDeletable).' table(s)');

        $missing = array_filter(self::ELIGIBILITY_GUARDS, fn (string $trigger, string $table) => DB::selectOne(
            "select exists (select 1 from pg_trigger where tgrelid = ('public.'||?)::regclass and tgname = ? and tgenabled = 'O') as x", [$table, $trigger],
        )->x === false, ARRAY_FILTER_USE_BOTH);
        $results[] = CheckResult::of('retention_eligibility_guards', $missing === [], $missing === [] ? '' : count($missing).' missing or disabled');

        return [...$results, ...$this->retentionAnchorChecks()];
    }

    /**
     * E21-RH.7 (ADR 0066 §15): every retention-relevant table carries the
     * database-recorded anchor, stamped by the one enabled trigger with
     * exactly its tracked columns; every expiry function declares its
     * cutoff; and the security fences against rollback are recorded.
     *
     * @return list<CheckResult>
     */
    private function retentionAnchorChecks(): array
    {
        $fn = DB::selectOne("select p.oid, p.prosecdef, coalesce(array_to_string(p.proconfig, ','), '') as config, p.prosrc,
                (p.proacl is null or exists (select 1 from aclexplode(p.proacl) a where a.grantee = 0 and a.privilege_type = 'EXECUTE')) as public_exec
             from pg_proc p where p.pronamespace = 'public'::regnamespace and p.proname = 'retention_stamp_anchor'");
        $rows = DB::select(
            "select c.relname, a.attnotnull, format_type(a.atttypid, a.atttypmod) as type, t.tgenabled, t.tgfoid,
                    case when t.oid is null then null else pg_get_triggerdef(t.oid) end as def
               from pg_class c
               left join pg_attribute a on a.attrelid = c.oid and a.attname = ? and not a.attisdropped
               left join pg_trigger t on t.tgrelid = c.oid and t.tgname = ? and not t.tgisinternal
              where c.relnamespace = 'public'::regnamespace and c.relkind = 'r'",
            [RetentionAnchors::COLUMN, RetentionAnchors::TRIGGER],
        );
        $live = [];
        foreach ($rows as $row) {
            $live[$row->relname] = $row;
        }
        $broken = [];
        foreach (RetentionAnchors::TABLES as $table => $tracked) {
            $row = $live[$table] ?? null;
            if ($row === null || $row->attnotnull !== true || $row->type !== 'timestamp without time zone'
                || ! in_array($row->tgenabled, ['O', 'A'], true) || $fn === null || (int) $row->tgfoid !== (int) $fn->oid
                || ! str_ends_with((string) $row->def, 'retention_stamp_anchor('.implode(', ', array_map(fn (string $c) => "'{$c}'", $tracked)).')')) {
                $broken[] = $table;
            }
        }
        $results = [CheckResult::of('retention_anchors_recorded', $fn !== null && ! $fn->prosecdef && str_contains($fn->config, 'search_path=') && ! $fn->public_exec
            && str_contains($fn->prosrc, 'session_user') && $broken === [], $broken === [] ? '' : count($broken).' table(s)')];

        $undeclared = DB::select("select p.proname from pg_proc p where p.pronamespace = 'public'::regnamespace and p.proname like 'retention\\_expire\\_%'
            and p.prosrc not like '%app.retention_anchor_cutoff%'");
        $results[] = CheckResult::of('retention_functions_declare_cutoff', $undeclared === [], $undeclared === [] ? '' : count($undeclared).' function(s)');

        $fenced = DB::table('migrations')->whereIn('migration', self::RETENTION_FENCES)->count();
        $results[] = CheckResult::of('retention_rollback_fences', $fenced === count(self::RETENTION_FENCES), $fenced === count(self::RETENTION_FENCES) ? '' : 'a fence is not recorded');

        return $results;
    }

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

        // E21-RH.6: no write privilege except DELETE on exactly RETENTION_DELETES (never INSERT, UPDATE,
        // TRUNCATE, REFERENCES or TRIGGER; no column-level write).
        $deletes = array_column(DB::select(
            "select c.relname from pg_class c cross join lateral aclexplode(c.relacl) a where a.grantee = ? and a.privilege_type = 'DELETE' order by 1",
            [$attrs->oid],
        ), 'relname');
        $approvedDeletes = self::RETENTION_DELETES;
        sort($approvedDeletes);
        $writes = DB::selectOne(
            "select (select count(*) from pg_class c cross join lateral aclexplode(c.relacl) a where a.grantee = ? and a.privilege_type not in ('SELECT', 'DELETE'))
                  + (select count(*) from pg_attribute t cross join lateral aclexplode(t.attacl) a where a.grantee = ? and a.privilege_type <> 'SELECT') as n",
            [$attrs->oid, $attrs->oid],
        )->n + ($deletes === $approvedDeletes ? 0 : 1);
        $results[] = CheckResult::of('retention_role_writes_exact', (int) $writes === 0, (int) $writes === 0 ? '' : $writes.' unexpected write grant(s)');

        // E21-RH.5: and its reads are exactly the approved columns (no table-level SELECT at all).
        $selects = [];
        foreach (DB::select(
            "select c.relname as tbl, t.attname as col from pg_attribute t join pg_class c on c.oid = t.attrelid
               cross join lateral aclexplode(t.attacl) a where a.grantee = ? and a.privilege_type = 'SELECT' and c.relnamespace = 'public'::regnamespace
              union all
             select c.relname, '*' from pg_class c cross join lateral aclexplode(c.relacl) a where a.grantee = ? and a.privilege_type = 'SELECT'",
            [$attrs->oid, $attrs->oid],
        ) as $row) {
            $selects[$row->tbl][] = $row->col;
        }
        ksort($selects);
        $selects = array_map(function (array $columns): array {
            sort($columns);

            return $columns;
        }, $selects);
        $approvedSelects = self::RETENTION_SELECTS;
        foreach ([...self::RETENTION_DELETES, ...self::RETENTION_TABLE_READS] as $table) {
            $approvedSelects[$table] = [...($approvedSelects[$table] ?? []), '*'];
        }
        $approvedSelects = array_map(function (array $columns): array {
            sort($columns);

            return $columns;
        }, $approvedSelects);
        ksort($approvedSelects);
        $results[] = CheckResult::of('retention_role_selects_exact', $selects === $approvedSelects, $selects === $approvedSelects ? '' : 'differs from the approved read set');

        // EXECUTE on exactly the approved destructive retention functions; never a legacy one.
        $executable = array_column(DB::select(
            "select p.proname from pg_proc p where p.pronamespace = 'public'::regnamespace and p.proname like 'retention\\_%'
                and has_function_privilege(?, p.oid, 'EXECUTE')",
            [$role],
        ), 'proname');
        sort($executable);
        $approved = [...self::PRIVILEGED_RETENTION_FUNCTIONS, ...self::STANDALONE_RETENTION_FUNCTIONS, ...self::UNIT_RETENTION_FUNCTIONS, ...self::RETENTION_READ_FUNCTIONS];
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
