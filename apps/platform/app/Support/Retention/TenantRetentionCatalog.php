<?php

namespace App\Support\Retention;

/**
 * E21.2F (E21-D11, docs/security/E21-RETENTION-DETERMINATION.md): every
 * tenant table (every table with a `school_id`) mapped to the retention
 * category that governs it.
 *
 * This is a literal closed list, never a prefix pattern, so a new table is
 * UNCLASSIFIED until someone adds it here. TenantClosureReadiness then
 * fails closed (`TenantRetentionCatalogTest` pins it to the live catalog).
 *
 * It is a READ-ONLY map for closure readiness. Nothing here, or in
 * TenantClosureReadiness, deletes or writes anything.
 *
 * Statuses:
 * - `adopted`: an implemented retention period expires these rows;
 * - `technical_blocker`: cannot expire yet (D8: every balance is derived
 *   from all postings; no financial-year close exists);
 * - `mechanism_pending`: a project period is adopted (E21.2G closure audit,
 *   pending ratification) but its expiry mechanism ships in a named follow-up
 *   checkpoint (E21.3B-E21.3E). The rows are kept until it does;
 * - `policy_unresolved`: no adopted period;
 * - `tenant_lifetime`: School configuration that lives as long as the
 *   School itself. It goes only with a future authorized tenant purge.
 *
 * E21.3B: a few tables hold rows of two kinds, one implemented and one
 * still waiting for a follow-up checkpoint (a Student subject's consent
 * vs a Guardian subject's; a converted application vs a rejected one).
 * Each table stays in ONE category; PENDING_ROWS names, as a fixed literal
 * predicate, the rows whose mechanism is still pending, so readiness keeps
 * reporting them `mechanism_pending` and never clears an E21.3C-E blocker.
 * (E21.3C implemented the last such rows; the list is empty, the rule
 * stays for the next mixed table.)
 *
 * E21.3C: UNRESOLVED_ROWS names, the same way, the rows of an implemented
 * category whose trigger is unknown (legacy rows the marker backfill found
 * no trustworthy evidence for). They are kept forever until resolved, and
 * readiness reports them `unresolved` (gate `retention_trigger_unresolved`).
 */
final class TenantRetentionCatalog
{
    public const ADOPTED = 'adopted';

    public const TECHNICAL_BLOCKER = 'technical_blocker';

    public const POLICY_UNRESOLVED = 'policy_unresolved';

    public const MECHANISM_PENDING = 'mechanism_pending';

    public const TENANT_LIFETIME = 'tenant_lifetime';

    /** category => [status, decision, tables] */
    public const CATEGORIES = [
        'finance_ledger' => [self::ADOPTED, 'D8: 8 y after the financial period closes; settled, dependency-safe units expire through platform:finance-retention-prune (E21.3A2); unsettled, payroll-linked and canteen-linked detail stays', [
            'financial_period_charge_states',
            'journal_entries', 'journal_lines', 'charges', 'fee_adjustments', 'fee_assessments', 'fee_assessment_runs',
            'fee_assessment_run_items', 'fee_concessions', 'fee_optional_selections', 'late_fee_assessments', 'late_fee_runs',
            'late_fee_run_items', 'payments', 'payment_allocations', 'payment_provider_events', 'payment_receipts',
            'payment_receipt_counters', 'canteen_orders', 'canteen_order_lines', 'canteen_order_stock_consumptions',
        ]],
        'finance_period_evidence' => [self::TENANT_LIFETIME, 'D8: financial periods, their cumulative account baselines and the expiry lineage carry every later balance; kept with the School', [
            'financial_periods', 'financial_period_account_balances', 'financial_period_expiries',
        ]],
        'payroll_ledger' => [self::TECHNICAL_BLOCKER, 'D8 x D9: posted payroll records are D9 evidence and keep their journal entries; no Payroll D9 expiry mechanism exists yet (E21.3A2 recorded intersection)', [
            'payroll_periods', 'payroll_runs', 'payroll_run_results', 'payroll_run_result_lines', 'payroll_run_postings',
            'payroll_statutory_calculation_results', 'payroll_statutory_run_postings', 'payroll_adjustments', 'payroll_lwf_annual_charges',
        ]],
        'finance_configuration' => [self::TENANT_LIFETIME, 'interprets the retained ledger', [
            'ledger_accounts', 'fee_heads', 'fee_structures', 'fee_structure_lines', 'fee_structure_installments', 'fee_late_fee_rules',
            'fee_settings', 'payroll_accounting_configurations', 'payroll_statutory_accounting_configurations',
            'payroll_salary_component_statutory_classifications', 'salary_components', 'salary_structures', 'salary_structure_components',
            'canteen_billing_configurations',
        ]],
        'audit' => [self::ADOPTED, 'D1: 7 y after the event', ['school_audit_events']],
        'erasure_cases' => [self::ADOPTED, 'D10: a closed erasure case, 7 y after it closed', ['erasure_cases']],
        'email' => [self::ADOPTED, 'D2: 180 d after the terminal state', ['email_messages', 'email_submission_attempts', 'email_provider_references']],
        'communications' => [self::ADOPTED, 'D3: content 3 y after its Academic Year; telemetry 1 y; cancelled deliveries go with their content. E21.2G: cancelled/rejected never-sent announcements and empty threads, 1 y after cancellation/rejection/last activity (mechanism E21.3E); live drafts are working state', [
            'communication_announcements', 'communication_announcement_academic_cohorts', 'communication_announcement_audience_members',
            'communication_announcement_channels', 'communication_announcement_domain_audience_members', 'communication_announcement_recipients',
            'communication_approval_requests', 'communication_attachments', 'communication_messages', 'communication_recipients',
            'communication_threads', 'communication_thread_participants', 'communication_deliveries', 'communication_delivery_attempts',
            'communication_delivery_policy_decisions',
        ]],
        'communication_consent' => [self::ADOPTED, 'E21.2G C4/C5: consent evidence and domain preferences follow their subject: a Student subject\'s go with the Student core record (25 y, E21.3B, implemented); a Guardian subject\'s with Guardian personal data (1 y, E21.3C, implemented)', [
            'communication_domain_consent_events', 'communication_domain_preferences',
        ]],
        'communication_configuration' => [self::TENANT_LIFETIME, 'School configuration', [
            'communication_approval_policies', 'communication_channel_policies', 'communication_conversation_policies',
            'communication_delivery_timing_policies', 'communication_templates', 'communication_preferences',
        ]],
        'notifications' => [self::TENANT_LIFETIME, 'E21.2G: Phase 0 demo with no production producer (not applicable); a producer must adopt a D13 period first', ['notifications']],
        'integrations' => [self::ADOPTED, 'D4: deliveries 30/90 d, outbox 30 d', ['webhook_deliveries', 'webhook_delivery_attempts', 'domain_event_outbox', 'event_consumer_receipts']],
        'integration_configuration' => [self::TENANT_LIFETIME, 'School configuration', ['webhook_endpoints', 'webhook_subscriptions', 'api_clients']],
        'api_credentials' => [self::MECHANISM_PENDING, 'E21.2G (D6): an ended credential, 7 y after it was revoked, superseded or expired; mechanism E21.3E', ['api_client_credentials']],
        'technical_ttl' => [self::ADOPTED, 'technical TTL (48 h idempotency)', ['api_idempotency_keys']],
        'authority' => [self::ADOPTED, 'D6: 7 y after the authority ends', ['membership_role_assignments', 'teaching_assignments', 'school_elevations']],
        'identity' => [self::TENANT_LIFETIME, 'E21.2G: memberships are authority provenance (suspended, never deleted, rule 92); ended staff invitations are a technical TTL (7 d, implemented)', [
            'school_memberships', 'staff_account_invitations', 'staff_account_invitation_roles',
        ]],
        'identity_subject_links' => [self::ADOPTED, 'E21.2G I2/I4: ended portal invitations 7 d after they ended (platform:portal-invitations-prune, E21.3B, implemented); Student account links go with the Student core record; revoked Guardian links past D6 with Guardian personal data (E21.3C, implemented); an active link keeps its subject', [
            'identity_account_invitations', 'student_guardian_account_links',
        ]],
        'student_core' => [self::ADOPTED, 'D7: 25 y after final exit', ['students', 'student_enrollments', 'student_subject_enrollments']],
        'student_operational' => [self::ADOPTED, 'D7: 7 y after final exit', ['attendance_records', 'enrollment_rollover_items', 'student_guardian_relationships']],
        'student_rollover_configuration' => [self::TENANT_LIFETIME, 'School configuration', ['enrollment_rollover_plans', 'enrollment_rollover_mappings', 'enrollment_rollover_subject_mappings']],
        'processing_authorizations' => [self::ADOPTED, 'E21.2G P1: legal-basis evidence for the academic record, kept with the Student core record (25 y) and removed in its unit through a core-floored function (E21.3B, implemented)', ['student_processing_authorizations']],
        'admissions' => [self::ADOPTED, 'E21.2G AD1/AD2: converted applications (and applicants with nothing else) with the Student core record (E21.3B, implemented); rejected/withdrawn 1 y after their canonical terminal_at (platform:admissions-retention-prune, E21.3C, implemented; undated legacy rows unresolved and kept); live applications are working state', ['applicants', 'admission_applications']],
        'guardians' => [self::ADOPTED, 'E21.2G G1: Guardian personal data 1 y after the Guardian last had a Student relationship (durable guardians.no_relationship_since) and nothing retained needs it (platform:guardian-retention-prune, E21.3C, implemented; unmarked legacy Guardians unresolved and kept)', ['guardians', 'guardian_contacts']],
        'documents' => [self::ADOPTED, 'D5: inherits its owner (Student, Employee); others kept with their parent', ['documents']],
        'academic_operations' => [self::MECHANISM_PENDING, 'E21.2G: year-bound academic operations, 7 y after the end of their Academic Year (LMS with the D6 owner/audience minimum; a register header only once it holds no record); mechanism E21.3D', [
            'curriculum_deliveries', 'learning_content', 'learning_content_section_audiences', 'assignments', 'assignment_section_audiences',
            'timetable_entries', 'attendance_sessions',
        ]],
        'academic_configuration' => [self::TENANT_LIFETIME, 'E21.2G: syllabus and examination schedules, School academic configuration without personal data', [
            'syllabus_units', 'examinations', 'examination_papers',
        ]],
        'academic_structure' => [self::TENANT_LIFETIME, 'School configuration', [
            'academic_years', 'academic_terms', 'academic_departments', 'campuses', 'grade_levels', 'sections', 'subjects',
            'subject_offerings', 'elective_groups', 'rooms', 'grade_scales', 'grade_bands', 'timetable_periods',
        ]],
        'hr_evidence' => [self::ADOPTED, 'D9: 8 y after final separation', [
            'employees', 'employment_records', 'employee_assignments', 'employee_personal_details', 'employee_documents',
            'employee_compensation_assignments', 'compensation_assignment_values', 'employee_statutory_identifiers',
            'employee_tax_profile', 'employee_pf_status', 'employee_esi_coverage',
        ]],
        'hr_ancillary' => [self::ADOPTED, 'D9: 2 y after final separation', [
            'employee_addresses', 'employee_emergency_contacts', 'employee_notes', 'employee_qualifications',
            'employee_experience_records', 'employee_certifications',
        ]],
        'hr_configuration' => [self::TENANT_LIFETIME, 'School configuration', ['employee_categories', 'hr_departments', 'positions', 'hr_employee_number_counters']],
        'student_operational_modules' => [self::ADOPTED, 'E21.2G O1: D7 operational history, 7 y after the Student\'s final exit; returned loans and ended assignments expire through platform:student-retention-prune (E21.3B, implemented); an open loan or assignment keeps the Student', [
            'hostel_residency_assignments', 'library_loans', 'transport_student_assignments',
        ]],
        'operational_logs' => [self::MECHANISM_PENDING, 'E21.2G: driver assignments 7 y after they end; visits 1 y after check-out (a visitor once no visit remains); automation records 1 y after completion; mechanism E21.3E', [
            'transport_route_assignments', 'visitors', 'visitor_visits', 'automation_executions', 'automation_execution_attempts', 'automation_review_items',
        ]],
        'inventory_history' => [self::TENANT_LIFETIME, 'E21.2G: stock balances and movements, School operations without personal data', ['inventory_stock_balances', 'stock_movements']],
        'operational_configuration' => [self::TENANT_LIFETIME, 'School configuration', [
            'hostels', 'hostel_rooms', 'hostel_beds', 'library_titles', 'library_copies', 'transport_routes', 'transport_stops',
            'transport_vehicles', 'inventory_items', 'inventory_locations', 'canteen_outlets', 'canteen_items',
            'canteen_item_inventory_requirements', 'automation_rule_instances',
        ]],
        'school_configuration' => [self::TENANT_LIFETIME, 'School configuration and platform governance', [
            'school_settings', 'school_domains', 'feature_flag_school_overrides', 'school_group_members', 'platform_idempotency_demo_counters',
        ]],
    ];

    /**
     * E21.3B: table => the literal predicate selecting its rows whose
     * mechanism is still pending (a closed list; never caller-supplied).
     * Empty since E21.3C implemented the last such rows.
     *
     * @var array<string, string>
     */
    public const PENDING_ROWS = [];

    /**
     * E21.3C: table => the literal predicate selecting rows of an implemented
     * category whose retention trigger is unknown (a closed list).
     */
    public const UNRESOLVED_ROWS = [
        'admission_applications' => "status IN ('rejected', 'withdrawn') AND terminal_at IS NULL",
        'guardians' => 'no_relationship_since IS NULL AND NOT EXISTS (SELECT 1 FROM student_guardian_relationships r WHERE r.guardian_id = guardians.id)',
    ];

    /** @return array<string, string> table => category */
    public static function tables(): array
    {
        $map = [];
        foreach (self::CATEGORIES as $category => [, , $tables]) {
            foreach ($tables as $table) {
                $map[$table] = $category;
            }
        }

        return $map;
    }
}
