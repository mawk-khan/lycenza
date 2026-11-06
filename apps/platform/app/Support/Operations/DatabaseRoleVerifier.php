<?php

namespace App\Support\Operations;

use App\Support\Configuration\EnvironmentPolicy;
use App\Support\Database\RollbackSafeMigrator;
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
        // TCH-E (ADR 0063 section 45): elective teaching ownership, the same authority history.
        'elective_teaching_assignments',
        // ORG.9D-3 (ADR 0076 §75): School calendar Events -- published history; expired only by their retention functions.
        'communication_events',
        // E21.2F: erasure cases expire only through their retention function.
        'erasure_cases',
        // E21.3A (ADR 0064): financial periods and their close baselines.
        'financial_periods', 'financial_period_account_balances', 'financial_period_charge_states',
        // E21.3A2: Finance ledgers lose detail only through retention_expire_finance_unit.
        'financial_period_expiries', 'journal_entries', 'journal_lines', 'charges', 'payments', 'payment_allocations', 'payment_receipts',
        // UAT-C3: payment corrections are append-only Finance history.
        'payment_corrections', 'payment_correction_lines',
        // ORG.8C (ADR 0076 §61): correction requests are approval history; unexecuted ones leave only with their Payment.
        'payment_correction_requests',
        // ORG.8E (ADR 0076 §63): lifecycle and attendance-correction evidence is append-only; it leaves only with its parent.
        'student_lifecycle_events', 'attendance_record_corrections',
        // HR.1A: employment terms and named placement changes are append-only; they go only with their employment.
        'employment_terms', 'employee_placement_changes',
        // HR.3A: reporting lines are ended or voided, never deleted; they go only with their subordinate employment.
        'reporting_relationships',
        // HR.12: separation cases, their timeline and clearance go only with their employment; requirements are retired.
        'employment_separation_cases', 'employment_separation_events', 'separation_clearance_items', 'separation_clearance_requirements',
        // HR.13: staff tasks, their timeline and configuration are never deleted at runtime.
        'workforce_task_categories', 'workforce_task_templates', 'workforce_task_template_versions', 'workforce_tasks', 'workforce_task_events', 'workforce_task_generation_runs',
        // HR.2A (S-16, OD-HR-13): ancillary profile records are archived, never deleted; HR document
        // records are archived; reference codes are retired. Only retention purges any of them.
        'employee_addresses', 'employee_emergency_contacts', 'employee_notes', 'employee_qualifications',
        'employee_experience_records', 'employee_certifications', 'employee_documents', 'employee_reference_codes',
        // E21.3B: Student consent evidence leaves only with its core record, through its retention function.
        'communication_domain_consent_events',
        // E21.3F: posted payroll adjustments and postings leave only through the payroll retention functions.
        'payroll_adjustments', 'payroll_run_postings', 'payroll_statutory_run_postings',
        // E21-RH.1: posted LWF annual charges leave only through retention_expire_payroll_employee_evidence.
        'payroll_lwf_annual_charges',
        // ORG.8B (ADR 0076 §60): who calculated a run is approval provenance; it leaves only with its run.
        'payroll_run_calculations',
        // HR.R1-C1: the compensation a calculation rested on is approval provenance; it leaves only with its calculation.
        'payroll_run_calculation_sources',
        // HR.R1-C4: a posting attestation is approval and posting evidence; it leaves with its posting.
        'payroll_posting_attestations',
        // HR.R1-C6: reconciliation evidence leaves with its run.
        'payroll_reversal_reconciliations', 'payroll_reversal_reconciliation_reviews',
        // HR.R1-C7: account link history leaves with its Employee (FK cascade).
        'employee_account_links',
        // E21.4 (F1): a User is never hard-deleted; a raw delete would null audit/grant actors and cascade memberships.
        'users',
        // OPF.1 (ADR 0067 §12): Transport's fee-selection provenance is insert-only Finance evidence.
        'transport_fee_selections',
        // OPF.2 (ADR 0067 §28): so is Hostel's.
        'hostel_fee_selections',
        // OPF.3 (ADR 0067 §29): and Admissions'.
        'admission_fee_selections',
        // OPF.4 (ADR 0067 §30): Library fine evidence, its voids and the immutable policy versions.
        'library_fines', 'library_fine_voids', 'library_fine_policies',
        // RES.2 (ADR 0068 §6.1, §19.3 a): StudentMark is never runtime-deleted; its value history is insert-only.
        'student_marks', 'student_mark_revisions',
        // RES.3 (ADR 0068 §21): the per-paper marks lock and the append-only correction requests.
        'examination_paper_mark_states', 'student_mark_corrections',
        // ORG.7H-1R (ADR 0076 §55): Inventory Location-use evidence, so a used Location never looks unused again.
        'stock_movements', 'inventory_stock_balances', 'canteen_orders',
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
        // ORG.8B (ADR 0076 §60): a recorded calculation never changes.
        'payroll_run_calculations',
        // HR.R1-C1: recorded compensation provenance never changes.
        'payroll_run_calculation_sources',
        // HR.R1-C4: a recorded attestation never changes.
        'payroll_posting_attestations',
        // HR.R1-C6: an obligation and its review never change; there is no status to update.
        'payroll_reversal_reconciliations', 'payroll_reversal_reconciliation_reviews',
        // HR.R1-C7: written only by the trigger on employees.user_id and the correction function.
        'employee_account_links',
        // ORG.8E (ADR 0076 §63): evidence rows are never rewritten.
        'student_lifecycle_events', 'attendance_record_corrections',
        // OPF.1 (ADR 0067 §12): Transport's fee-selection provenance is insert-only.
        'transport_fee_selections',
        // OPF.2 (ADR 0067 §28): so is Hostel's.
        'hostel_fee_selections',
        // OPF.3 (ADR 0067 §29): and Admissions'.
        'admission_fee_selections',
        // OPF.4 (ADR 0067 §30): Library fine evidence, its voids and the immutable policy versions.
        'library_fines', 'library_fine_voids', 'library_fine_policies',
        // RES.2 (ADR 0068 §19.3 a): StudentMark value history is never rewritten.
        'student_mark_revisions',
        // ORG.7H-1R (ADR 0076 §55): the Inventory movement ledger is append-only.
        'stock_movements',
        // HR.1A: an employment's terms and its named placement changes are never rewritten.
        'employment_terms', 'employee_placement_changes',
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
        // HR.2A (S-16): the six HR profile tables left this list -- their records are archived, never deleted.
        'student_guardian_relationships', 'failed_jobs',
    ];

    /** E21-RH.6: tables whose rows only retention removes, in the database since E21-RH.5 (runtime DELETE revoked). */
    public const RUNTIME_DELETES_REVOKED_EXTRA = ['learning_content', 'assignments', 'student_processing_authorizations'];

    /** E21-RH.6: read-only tables the moved units read whole (production readers); table-level SELECT only. */
    public const RETENTION_TABLE_READS = [
        'schools', 'academic_years', 'subject_offerings', 'sections', 'school_memberships', 'school_settings',
        'student_processing_authorizations', 'communication_domain_consent_events',
        'financial_periods', 'financial_period_account_balances', 'financial_period_expiries', 'financial_period_charge_states',
        'journal_entries', 'journal_lines', 'ledger_accounts', 'charges', 'payments', 'payment_allocations', 'payment_receipt_counters',
        // UAT-C3: the charge-state read the finance retention unit runs includes payment corrections.
        'payment_corrections', 'payment_correction_lines',
        'late_fee_assessments', 'payroll_run_postings', 'payroll_statutory_run_postings',
        'fee_adjustments', 'fee_assessments', 'fee_heads', 'fee_structure_installments',
        'communication_approval_requests', 'communication_attachments',
        // The email prune keeps an event a current suppression still rests on (it reads only whether one exists)
        'email_suppressions',
        // SIS 11.5: which Documents an expiring application's pre-admission records name.
        'applicant_documents',
    ];

    /**
     * HR.1A: the database backstops of the effective-dated workforce model --
     * overlap (employments, primary assignments), append-only terms and
     * placement history, the terms projection, Employee identity and the
     * Department hierarchy. trigger => table; each must exist and be enabled.
     */
    public const HR_WORKFORCE_GUARDS = [
        'trg_employment_records_no_overlap' => 'employment_records',
        'trg_employment_records_initial_terms' => 'employment_records',
        'trg_employment_records_terms_projection_guard' => 'employment_records',
        'trg_employee_assignments_primary_no_overlap' => 'employee_assignments',
        'trg_employment_terms_append_guard' => 'employment_terms',
        'trg_employment_terms_immutable' => 'employment_terms',
        'trg_employment_terms_project' => 'employment_terms',
        'trg_employee_placement_changes_guard' => 'employee_placement_changes',
        'trg_employee_placement_changes_immutable' => 'employee_placement_changes',
        'trg_employees_identity_guard' => 'employees',
        'trg_hr_departments_no_cycle' => 'hr_departments',
    ];

    /**
     * HR.3A: the reporting-line backstops -- one line per subordinate per day,
     * never oneself, within both employments, no loop on any day, immutable
     * parties and start, the end only narrows; and an employment never ends
     * while a live line outlives it. trigger => table.
     */
    public const HR_REPORTING_GUARDS = [
        'trg_reporting_relationships_guard' => 'reporting_relationships',
        'trg_employment_records_reporting_bounds' => 'employment_records',
    ];

    /**
     * HR.12: the separation backstops -- the case graph, evidence, maker/checker
     * and "never the subject"; clearance snapshots cleared once; requirements
     * retired, never edited; notice_period only under an approved case.
     * trigger => table.
     */
    public const HR_SEPARATION_GUARDS = [
        'trg_employment_separation_cases_guard' => 'employment_separation_cases',
        'trg_separation_clearance_items_guard' => 'separation_clearance_items',
        'trg_separation_clearance_requirements_guard' => 'separation_clearance_requirements',
        'trg_employment_records_separation_notice' => 'employment_records',
    ];

    /** HR.12: the tenant-owned separation tables (forced RLS each). */
    public const HR_SEPARATION_TABLES = ['employment_separation_cases', 'employment_separation_events', 'separation_clearance_requirements', 'separation_clearance_items'];

    /**
     * HR.9 (ADR 0065 §28): the leave policy backstops -- a version's
     * eligibility rules are immutable, never beyond its declared count and
     * never ambiguous against another active version; the declared count is
     * complete at commit; an eligibility-run header never changes.
     * trigger => table.
     */
    public const LEAVE_POLICY_GUARDS = [
        'trg_leave_eligibility_rules_guard' => 'leave_eligibility_rules',
        'trg_leave_policies_rules_complete' => 'leave_policies',
        'trg_leave_eligibility_runs_immutable' => 'leave_eligibility_runs',
        'trg_leave_policies_immutable' => 'leave_policies',
        // HR.10 (ADR 0065 §29): the accrual and opening checks live in the ledger guard; runs move forward only; run evidence is append-only.
        'trg_leave_ledger_guard' => 'leave_ledger_entries',
        'trg_leave_accrual_runs_guard' => 'leave_accrual_runs',
        'trg_leave_accrual_run_items_guard' => 'leave_accrual_run_items',
        'trg_leave_policy_assignments_accrual_bounds' => 'leave_policy_assignments',
    ];

    /**
     * HR.2A: the staff-profile backstops -- the archive guard on each ancillary
     * record table, reference codes immutable and never reused, HR document
     * records bound to a governed Document. trigger => table.
     */
    public const HR_PROFILE_GUARDS = [
        'trg_employee_addresses_archive_guard' => 'employee_addresses',
        'trg_employee_emergency_contacts_archive_guard' => 'employee_emergency_contacts',
        'trg_employee_notes_archive_guard' => 'employee_notes',
        'trg_employee_qualifications_archive_guard' => 'employee_qualifications',
        'trg_employee_experience_records_archive_guard' => 'employee_experience_records',
        'trg_employee_certifications_archive_guard' => 'employee_certifications',
        'trg_employee_reference_codes_guard' => 'employee_reference_codes',
        'trg_employee_documents_guard' => 'employee_documents',
    ];

    /** HR.3A: the indexes the reporting readers rely on (bounded lookups by subordinate, manager and date). */
    public const HR_REPORTING_INDEXES = [
        'reporting_relationships_subordinate_idx', 'reporting_relationships_manager_idx',
        'reporting_relationships_school_date_idx', 'reporting_relationships_open_idx',
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
        // TCH-E (ADR 0063 section 45): created with the RH.4 prologue already in place.
        'retention_expire_elective_teaching_assignments',
        // ORG.9D-3 (ADR 0076 §75.12): School calendar Events, created in the same shape.
        'retention_expire_communication_events', 'retention_expire_cancelled_communication_events',
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
        // PPR.1 (ADR 0074 section 26): a FAIL in every hardened environment.
        $ssl = (bool) (DB::selectOne('select ssl from pg_stat_ssl where pid = pg_backend_pid()')->ssl ?? false);
        $results[] = EnvironmentPolicy::isHardened() || $ssl
            ? CheckResult::of('runtime_connection_encrypted', $ssl)
            : new CheckResult('runtime_connection_encrypted', CheckResult::EVIDENCE, 'not encrypted outside a hardened environment');

        $attrs = DB::selectOne('select rolcanlogin, rolsuper, rolbypassrls, rolcreatedb, rolcreaterole, rolinherit, rolreplication from pg_roles where rolname = ?', [$role]);
        $results[] = CheckResult::of('runtime_role_exists', $attrs !== null);
        $results[] = CheckResult::of('runtime_role_attributes', $attrs !== null
            && $attrs->rolcanlogin && ! $attrs->rolsuper && ! $attrs->rolbypassrls && ! $attrs->rolcreatedb
            && ! $attrs->rolcreaterole && ! $attrs->rolinherit && ! $attrs->rolreplication);

        $owner = DB::selectOne("select relowner::regrole::text as owner from pg_class where oid = 'platform_role_assignments'::regclass")->owner;
        $member = DB::selectOne('select pg_has_role(?, ?, ?) as m', [$role, $owner, 'MEMBER'])->m;
        // HR.R1-C5 (PAY-15, ADR 0021): the role that owns the schema must see every row, or a migration or rollback guard is
        // reading a filtered table; and rollback must run through the fail-closed migrator.
        $schemaOwner = DB::selectOne("select r.rolsuper or r.rolbypassrls as sees_all from pg_class c join pg_roles r on r.oid = c.relowner where c.oid = to_regclass('public.migrations')");
        $results[] = CheckResult::of('migration_owner_sees_every_row', $schemaOwner !== null && (bool) $schemaOwner->sees_all);
        $results[] = CheckResult::of('rollback_guards_fail_closed', app('migrator')::class === RollbackSafeMigrator::class);
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

        // HR.1A: the workforce backstops are present and enabled (a disabled
        // trigger would let the runtime role write overlapping or rewritten history).
        $missingHr = array_filter(self::HR_WORKFORCE_GUARDS, fn (string $table, string $trigger) => DB::selectOne(
            "select exists (select 1 from pg_trigger where tgrelid = ('public.'||?)::regclass and tgname = ? and tgenabled = 'O') as x", [$table, $trigger],
        )->x === false, ARRAY_FILTER_USE_BOTH);
        $results[] = CheckResult::of('hr_workforce_invariants', $missingHr === [], $missingHr === [] ? '' : count($missingHr).' missing or disabled');

        // HR.3A: the reporting-line backstops, the table's forced RLS and its indexes.
        $missingReporting = array_keys(array_filter(self::HR_REPORTING_GUARDS, fn (string $table, string $trigger) => DB::selectOne(
            "select exists (select 1 from pg_trigger where tgrelid = ('public.'||?)::regclass and tgname = ? and tgenabled = 'O') as x", [$table, $trigger],
        )->x === false, ARRAY_FILTER_USE_BOTH));
        $rls = DB::selectOne("select relrowsecurity and relforcerowsecurity as x from pg_class where oid = 'public.reporting_relationships'::regclass");
        if ($rls === null || ! $rls->x) {
            $missingReporting[] = 'rls';
        }
        foreach (self::HR_REPORTING_INDEXES as $index) {
            if (! DB::selectOne("select exists (select 1 from pg_indexes where schemaname = 'public' and tablename = 'reporting_relationships' and indexname = ?) as x", [$index])->x) {
                $missingReporting[] = $index;
            }
        }
        // HR.2A: the staff-profile backstops, forced RLS on reference codes and the closed checks.
        $missingProfile = array_keys(array_filter(self::HR_PROFILE_GUARDS, fn (string $table, string $trigger) => DB::selectOne(
            "select exists (select 1 from pg_trigger where tgrelid = ('public.'||?)::regclass and tgname = ? and tgenabled = 'O') as x", [$table, $trigger],
        )->x === false, ARRAY_FILTER_USE_BOTH));
        $codesRls = DB::selectOne("select relrowsecurity and relforcerowsecurity as x from pg_class where oid = 'public.employee_reference_codes'::regclass");
        if ($codesRls === null || ! $codesRls->x) {
            $missingProfile[] = 'rls';
        }
        foreach (['employee_documents_category_check', 'employee_documents_storage_shape_check', 'employee_reference_codes_kind_check', 'employee_reference_codes_value_unique'] as $constraint) {
            if (! DB::selectOne('select exists (select 1 from pg_constraint where conname = ?) as x', [$constraint])->x) {
                $missingProfile[] = $constraint;
            }
        }
        $results[] = CheckResult::of('hr_profile_invariants', $missingProfile === [], $missingProfile === [] ? '' : count($missingProfile).' missing or disabled');

        $results[] = CheckResult::of('hr_reporting_invariants', $missingReporting === [], $missingReporting === [] ? '' : count($missingReporting).' missing or disabled');

        // HR.12: the separation backstops, maker/checker, one live case, forced RLS.
        $missingSeparation = array_keys(array_filter(self::HR_SEPARATION_GUARDS, fn (string $table, string $trigger) => DB::selectOne(
            "select exists (select 1 from pg_trigger where tgrelid = ('public.'||?)::regclass and tgname = ? and tgenabled = 'O') as x", [$table, $trigger],
        )->x === false, ARRAY_FILTER_USE_BOTH));
        foreach (self::HR_SEPARATION_TABLES as $table) {
            $forced = DB::selectOne('select relrowsecurity and relforcerowsecurity as x from pg_class where oid = (?)::regclass', ["public.{$table}"]);
            if ($forced === null || ! $forced->x) {
                $missingSeparation[] = "{$table}:rls";
            }
        }
        foreach (['employment_separation_cases_sod_check', 'employment_separation_cases_dates_check', 'separation_clearance_items_status_check'] as $constraint) {
            if (! DB::selectOne('select exists (select 1 from pg_constraint where conname = ?) as x', [$constraint])->x) {
                $missingSeparation[] = $constraint;
            }
        }
        if (! DB::selectOne("select exists (select 1 from pg_indexes where schemaname = 'public' and indexname = 'employment_separation_cases_one_live') as x")->x) {
            $missingSeparation[] = 'employment_separation_cases_one_live';
        }
        // HR.13: the staff task backstops, idempotent occurrences, forced RLS.
        $missingTasks = [];
        if (! DB::selectOne("select exists (select 1 from pg_trigger where tgrelid = 'public.workforce_tasks'::regclass and tgname = 'trg_workforce_tasks_guard' and tgenabled = 'O') as x")->x) {
            $missingTasks[] = 'trg_workforce_tasks_guard';
        }
        if (! DB::selectOne("select exists (select 1 from pg_trigger where tgrelid = 'public.workforce_task_events'::regclass and tgname = 'trg_workforce_task_events_guard' and tgenabled = 'O') as x")->x) {
            $missingTasks[] = 'trg_workforce_task_events_guard';
        }
        foreach (['workforce_tasks_one_occurrence'] as $index) {
            if (! DB::selectOne("select exists (select 1 from pg_indexes where schemaname = 'public' and indexname = ?) as x", [$index])->x) {
                $missingTasks[] = $index;
            }
        }
        foreach (['workforce_tasks_path_check', 'workforce_tasks_outcome_check', 'workforce_task_template_versions_schedule_check'] as $constraint) {
            if (! DB::selectOne('select exists (select 1 from pg_constraint where conname = ?) as x', [$constraint])->x) {
                $missingTasks[] = $constraint;
            }
        }
        foreach (['workforce_task_categories', 'workforce_task_templates', 'workforce_task_template_versions', 'workforce_tasks', 'workforce_task_events', 'workforce_task_generation_runs'] as $table) {
            $forced = DB::selectOne('select relrowsecurity and relforcerowsecurity as x from pg_class where oid = (?)::regclass', ["public.{$table}"]);
            if ($forced === null || ! $forced->x) {
                $missingTasks[] = "{$table}:rls";
            }
        }
        $results[] = CheckResult::of('hr_task_invariants', $missingTasks === [], $missingTasks === [] ? '' : count($missingTasks).' missing or disabled');

        $results[] = CheckResult::of('hr_separation_invariants', $missingSeparation === [], $missingSeparation === [] ? '' : count($missingSeparation).' missing or disabled');

        // HR.9: the leave policy backstops, forced RLS on the two new tables and their closed checks.
        $missingLeavePolicy = array_keys(array_filter(self::LEAVE_POLICY_GUARDS, fn (string $table, string $trigger) => DB::selectOne(
            "select exists (select 1 from pg_trigger where tgrelid = ('public.'||?)::regclass and tgname = ? and tgenabled = 'O') as x", [$table, $trigger],
        )->x === false, ARRAY_FILTER_USE_BOTH));
        foreach (['leave_eligibility_rules', 'leave_eligibility_runs', 'leave_accrual_runs', 'leave_accrual_run_items'] as $table) {
            $rls = DB::selectOne('select relrowsecurity and relforcerowsecurity as x from pg_class where oid = (\'public.\'||?)::regclass', [$table]);
            if ($rls === null || ! $rls->x) {
                $missingLeavePolicy[] = "{$table}:rls";
            }
        }
        foreach (['leave_policies_rules_check', 'leave_eligibility_rules_staff_type_check', 'leave_policy_assignments_provenance_check', 'leave_eligibility_runs_count_check',
            'leave_policies_entitlement_check', 'leave_accrual_runs_shape_check', 'leave_accrual_run_items_shape_check', 'leave_ledger_entries_shape_check'] as $constraint) {
            if (! DB::selectOne('select exists (select 1 from pg_constraint where conname = ?) as x', [$constraint])->x) {
                $missingLeavePolicy[] = $constraint;
            }
        }
        $results[] = CheckResult::of('leave_policy_invariants', $missingLeavePolicy === [], $missingLeavePolicy === [] ? '' : count($missingLeavePolicy).' missing or disabled');

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

        $results = [...$results, ...$this->roleCatalogueChecks($role)];

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
        $results[] = $this->payrollApprovalProvenanceCheck();
        $results[] = $this->payrollReconciliationCheck();
        $results[] = $this->employeeAccountLinkCheck();
        $results[] = $this->employmentLifecycleAdmissionCheck();

        return $results;
    }

    /**
     * HR.R1-C2 (HRC-1, HRC-2): an employment never begins with a planned end
     * (the eligibility guard's INSERT branch), and only an employment end
     * records a placement as never effective (the empty-interval trigger).
     */
    private function employmentLifecycleAdmissionCheck(): CheckResult
    {
        $guard = DB::selectOne("select p.prosrc as src from pg_proc p where p.proname = 'retention_guard_end_record' and p.pronamespace = 'public'::regnamespace");
        // The placement guard must watch BOTH dates (tgattr holds two columns): the CHECK admits the empty interval however it arises.
        $placement = DB::selectOne("select array_length(string_to_array(t.tgattr::text, ' '), 1) as columns, p.prosrc as src from pg_trigger t join pg_proc p on p.oid = t.tgfoid where not t.tgisinternal and t.tgenabled = 'O' and t.tgname = 'trg_employee_assignments_never_effective'");
        $afterDeath = (int) DB::selectOne("select count(*) as c from pg_trigger where not tgisinternal and tgenabled = 'O' and tgname = 'trg_employment_records_after_death'")->c;

        return CheckResult::of('employment_lifecycle_admission_guarded', $guard !== null && str_contains($guard->src, 'never begins with a planned end')
            && $placement !== null && (int) $placement->columns === 2 && str_contains($placement->src, 'never took effect is final') && str_contains($placement->src, 'past its end')
            && $afterDeath === 1);
    }

    /**
     * HR.R1-C1 (PAY-2/3/4/5): the payroll approval guard enforces recorded
     * provenance, freshness, first-paid author exclusion and reconciliation;
     * compensation writes take the School's compensation lock; calculated
     * results and lines are never rewritten.
     */
    /**
     * HR.R1-C7 (OD-HR-25): no link change without history, and the runtime role reads it only.
     */
    private function employeeAccountLinkCheck(): CheckResult
    {
        $triggers = (int) DB::selectOne("select count(*) as c from pg_trigger where not tgisinternal and tgenabled = 'O' and tgname in ('trg_employees_record_account_link_insert', 'trg_employees_record_account_link_update')")->c;
        $definer = (int) DB::selectOne("select count(*) as c from pg_proc where pronamespace = 'public'::regnamespace and proname in ('employees_record_account_link', 'employee_account_link_void') and prosecdef and proconfig is not null")->c;
        $writes = (bool) DB::selectOne("select has_table_privilege('school_os_app', 'employee_account_links', 'INSERT') or has_table_privilege('school_os_app', 'employee_account_links', 'UPDATE') or has_table_privilege('school_os_app', 'employee_account_links', 'DELETE') as w")->w;

        $void = (string) DB::selectOne("select coalesce((select prosrc from pg_proc where pronamespace = 'public'::regnamespace and proname = 'employee_account_link_void'), '') as src")->src;
        $pinned = str_contains($void, 'pg_advisory_xact_lock') && str_contains($void, 'l.user_id = p_actor) THEN') && str_contains($void, 'hr.employees.manage');

        return CheckResult::of('employee_account_links_guarded', $triggers === 2 && $definer === 2 && ! $writes && $pinned);
    }

    /**
     * HR.R1-C6 (PAY-18, PAY-19): a reversal cannot commit without its obligation, a review
     * stays independent, nobody decides on their own first-paid change, and a link change is
     * ordered against every decision. Every trigger enabled, and the deferred one still deferred.
     */
    private function payrollReconciliationCheck(): CheckResult
    {
        $enabled = (int) DB::selectOne(
            "select count(*) as c from pg_trigger where not tgisinternal and tgenabled = 'O' and tgname in
             ('trg_payroll_reversal_reconciliations_guard', 'trg_payroll_run_postings_reconciliation_required', 'trg_payroll_reversal_reconciliation_reviews_guard',
              'trg_payroll_runs_self_beneficiary', 'trg_payroll_posting_attestations_self_beneficiary', 'trg_employees_user_link_payroll_lock')"
        )->c;
        $deferred = (int) DB::selectOne("select count(*) as c from pg_trigger where tgname = 'trg_payroll_run_postings_reconciliation_required' and tgdeferrable and tginitdeferred")->c;

        return CheckResult::of('payroll_reconciliation_guarded', $enabled === 6 && $deferred === 1);
    }

    private function payrollApprovalProvenanceCheck(): CheckResult
    {
        $guard = DB::selectOne("select p.prosrc as src from pg_proc p where p.proname = 'payroll_runs_approval_evidence_guard' and p.pronamespace = 'public'::regnamespace");
        $guarded = $guard !== null
            && str_contains($guard->src, 'payroll_calculation_compensation_fresh(')
            && str_contains($guard->src, 'payroll_run_first_paid_compensation(')
            && str_contains($guard->src, 'payroll_run_results_reconcile(')
            && str_contains($guard->src, 'compensation_sources_recorded')
            && str_contains($guard->src, 'pg_advisory_xact_lock_shared');
        // HR.R1-C3 (PAY-13): a reversed run, or one its author approved, never discharges the attribution.
        $firstPaid = DB::selectOne("select p.prosrc as src from pg_proc p where p.proname = 'payroll_run_first_paid_compensation' and p.pronamespace = 'public'::regnamespace");
        $guarded = $guarded && $firstPaid !== null && str_contains($firstPaid->src, "posting_kind = 'reversal'") && str_contains($firstPaid->src, 'o.approved_by_user_id IS DISTINCT FROM a.assigned_by_user_id') && str_contains($firstPaid->src, 'employee_account_links') && str_contains($firstPaid->src, 'voided_at IS NULL');
        $enabled = (int) DB::selectOne(
            "select count(*) as c from pg_trigger where not tgisinternal and tgenabled = 'O' and tgname in
             ('payroll_runs_approval_evidence_guard', 'trg_payroll_runs_posting_independence', 'trg_payroll_posting_attestations_guard', 'trg_payroll_runs_identity', 'trg_payroll_run_postings_reversal_lock', 'trg_employee_compensation_assignments_identity', 'trg_employee_compensation_assignments_payroll_lock', 'trg_payroll_run_results_reject_rewrite', 'trg_payroll_run_result_lines_reject_rewrite', 'trg_payroll_run_calculation_sources_guard')",
        )->c;

        $decided = (int) DB::selectOne("select count(*) as c from pg_constraint where conname = 'payroll_runs_decided_has_approver' and convalidated")->c;

        return CheckResult::of('payroll_approval_provenance_guarded', $guarded && $enabled === 10 && $decided === 1);
    }

    /**
     * SR.1 (ADR 0071 §11): the staff role catalogue's database foundation --
     * the runtime role cannot write the catalogue, a role cannot be deleted
     * out from under its grant history, role identity is immutable, the
     * retirement and grant-right columns exist, every new School-role grant
     * passes the grantor-coverage trigger (with its administrative-boundary
     * check), and the local/testing fixture seam exists only where it may.
     *
     * @return list<CheckResult>
     */
    private function roleCatalogueChecks(string $runtime): array
    {
        $writable = [];
        foreach (['roles', 'role_capabilities', 'capabilities'] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
                if (DB::selectOne('select has_table_privilege(?, ?, ?) as p', [$runtime, $table, $privilege])->p) {
                    $writable[] = "{$table}:{$privilege}";
                }
            }
        }
        $results = [CheckResult::of('role_catalogue_runtime_read_only', $writable === [], $writable === [] ? '' : count($writable).' grant(s)')];

        $fk = DB::selectOne("select confdeltype from pg_constraint where conname = 'membership_role_assignments_role_id_foreign'");
        $results[] = CheckResult::of('role_grant_history_restricted', $fk !== null && in_array($fk->confdeltype, ['r', 'a'], true));

        $triggers = DB::select(
            "select t.tgname, t.tgenabled = 'O' as enabled, p.prosrc as src from pg_trigger t join pg_proc p on p.oid = t.tgfoid
             where t.tgname in ('trg_roles_identity', 'trg_membership_role_assignments_grantor', 'trg_capabilities_grant_right')",
        );
        $byName = collect($triggers)->keyBy('tgname');
        $identity = $byName->get('trg_roles_identity');
        $grantor = $byName->get('trg_membership_role_assignments_grantor');
        $results[] = CheckResult::of('role_identity_immutable', $identity !== null && $identity->enabled && str_contains($identity->src, 'NEW.key IS DISTINCT FROM OLD.key'));
        $results[] = CheckResult::of('role_grantor_coverage_enforced', $grantor !== null && $grantor->enabled
            && str_contains($grantor->src, 'pg_has_role') && str_contains($grantor->src, 'retired_at') && str_contains($grantor->src, 'grant_right')
            && $byName->has('trg_capabilities_grant_right'));

        $columns = DB::selectOne(
            "select exists (select 1 from information_schema.columns where table_name = 'roles' and column_name = 'retired_at') as retired,
                    exists (select 1 from information_schema.columns where table_name = 'capabilities' and column_name = 'grant_right') as grant_right",
        );
        $results[] = CheckResult::of('role_lifecycle_columns', (bool) $columns->retired && (bool) $columns->grant_right);

        $seam = (bool) DB::selectOne("select exists (select 1 from pg_namespace where nspname = 'local_fixtures') as present")->present;
        $results[] = CheckResult::of('local_fixture_seam_absent_outside_development', ! $seam || app()->environment(['local', 'testing']));

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
