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
        'finance_ledger' => [self::TECHNICAL_BLOCKER, 'D8: 8 y after the financial year closes; the period close exists (E21.3A), the retention cutover does not (E21.3A2)', [
            'financial_periods', 'financial_period_account_balances', 'financial_period_charge_states',
            'journal_entries', 'journal_lines', 'charges', 'fee_adjustments', 'fee_assessments', 'fee_assessment_runs',
            'fee_assessment_run_items', 'fee_concessions', 'fee_optional_selections', 'late_fee_assessments', 'late_fee_runs',
            'late_fee_run_items', 'payments', 'payment_allocations', 'payment_provider_events', 'payment_receipts',
            'payment_receipt_counters', 'canteen_orders', 'canteen_order_lines', 'canteen_order_stock_consumptions',
        ]],
        'payroll_ledger' => [self::TECHNICAL_BLOCKER, 'D8/D9: posted payroll evidence; blocked with the ledger', [
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
        'communication_consent' => [self::MECHANISM_PENDING, 'E21.2G: consent evidence and domain preferences follow their subject (Student core record; Guardian personal data); mechanism E21.3B/E21.3C', [
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
        'identity_subject_links' => [self::MECHANISM_PENDING, 'E21.2G: ended portal invitations 7 d after they ended (E21.3B); Student account links go with the Student core record; Guardian links with Guardian personal data (E21.3C)', [
            'identity_account_invitations', 'student_guardian_account_links',
        ]],
        'student_core' => [self::ADOPTED, 'D7: 25 y after final exit', ['students', 'student_enrollments', 'student_subject_enrollments']],
        'student_operational' => [self::ADOPTED, 'D7: 7 y after final exit', ['attendance_records', 'enrollment_rollover_items', 'student_guardian_relationships']],
        'student_rollover_configuration' => [self::TENANT_LIFETIME, 'School configuration', ['enrollment_rollover_plans', 'enrollment_rollover_mappings', 'enrollment_rollover_subject_mappings']],
        'processing_authorizations' => [self::MECHANISM_PENDING, 'E21.2G: legal-basis evidence for the academic record, kept with the Student core record (25 y); mechanism E21.3B', ['student_processing_authorizations']],
        'admissions' => [self::MECHANISM_PENDING, 'E21.2G: converted applications with the Student core record (E21.3B); rejected/withdrawn 1 y after the decision, needs a decision timestamp (E21.3C); live applications are working state', ['applicants', 'admission_applications']],
        'guardians' => [self::MECHANISM_PENDING, 'E21.2G: Guardian personal data 1 y after the Guardian has no relationship and no retained dependent; needs a durable no-relationship marker (E21.3C)', ['guardians', 'guardian_contacts']],
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
        'student_operational_modules' => [self::MECHANISM_PENDING, 'E21.2G: D7 operational history, 7 y after the Student\'s final exit (an open loan or assignment keeps the Student); mechanism E21.3B', [
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
