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
 * - `policy_unresolved`: no adopted period yet (E21.2G);
 * - `tenant_lifetime`: School configuration that lives as long as the
 *   School itself. It goes only with a future authorized tenant purge.
 */
final class TenantRetentionCatalog
{
    public const ADOPTED = 'adopted';

    public const TECHNICAL_BLOCKER = 'technical_blocker';

    public const POLICY_UNRESOLVED = 'policy_unresolved';

    public const TENANT_LIFETIME = 'tenant_lifetime';

    /** category => [status, decision, tables] */
    public const CATEGORIES = [
        'finance_ledger' => [self::TECHNICAL_BLOCKER, 'D8: 8 y after the financial year closes; blocked until a financial-year close exists', [
            'journal_entries', 'journal_lines', 'charges', 'fee_adjustments', 'fee_assessments', 'fee_assessment_runs',
            'fee_assessment_run_items', 'fee_concessions', 'fee_optional_selections', 'late_fee_assessments', 'late_fee_runs',
            'late_fee_run_items', 'payments', 'payment_allocations', 'payment_provider_events', 'payment_receipts',
            'payment_receipt_counters', 'canteen_orders', 'canteen_order_lines',
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
        'communications' => [self::ADOPTED, 'D3: content 3 y after its Academic Year; telemetry 1 y', [
            'communication_announcements', 'communication_announcement_academic_cohorts', 'communication_announcement_audience_members',
            'communication_announcement_channels', 'communication_announcement_domain_audience_members', 'communication_announcement_recipients',
            'communication_approval_requests', 'communication_attachments', 'communication_messages', 'communication_recipients',
            'communication_threads', 'communication_thread_participants', 'communication_deliveries', 'communication_delivery_attempts',
            'communication_delivery_policy_decisions',
        ]],
        'communication_consent' => [self::POLICY_UNRESOLVED, 'consent evidence and preferences: no adopted period (E21.2G)', [
            'communication_domain_consent_events', 'communication_domain_preferences', 'communication_preferences',
        ]],
        'communication_configuration' => [self::TENANT_LIFETIME, 'School configuration', [
            'communication_approval_policies', 'communication_channel_policies', 'communication_conversation_policies',
            'communication_delivery_timing_policies', 'communication_templates',
        ]],
        'notifications' => [self::POLICY_UNRESOLVED, 'outside D3: no adopted period (E21.2G)', ['notifications']],
        'integrations' => [self::ADOPTED, 'D4: deliveries 30/90 d, outbox 30 d', ['webhook_deliveries', 'webhook_delivery_attempts', 'domain_event_outbox', 'event_consumer_receipts']],
        'integration_configuration' => [self::TENANT_LIFETIME, 'School configuration', ['webhook_endpoints', 'webhook_subscriptions', 'api_clients']],
        'api_credentials' => [self::POLICY_UNRESOLVED, 'revoked API credentials: deferred to E21.2G (D6)', ['api_client_credentials']],
        'technical_ttl' => [self::ADOPTED, 'technical TTL (48 h idempotency)', ['api_idempotency_keys']],
        'authority' => [self::ADOPTED, 'D6: 7 y after the authority ends', ['membership_role_assignments', 'teaching_assignments', 'school_elevations']],
        'identity' => [self::POLICY_UNRESOLVED, 'memberships, invitations and account links: no adopted period (D10, E21.2G)', [
            'school_memberships', 'staff_account_invitations', 'staff_account_invitation_roles', 'identity_account_invitations',
            'student_guardian_account_links',
        ]],
        'student_core' => [self::ADOPTED, 'D7: 25 y after final exit', ['students', 'student_enrollments', 'student_subject_enrollments']],
        'student_operational' => [self::ADOPTED, 'D7: 7 y after final exit', ['attendance_records', 'enrollment_rollover_items', 'student_guardian_relationships']],
        'student_rollover_configuration' => [self::TENANT_LIFETIME, 'School configuration', ['enrollment_rollover_plans', 'enrollment_rollover_mappings', 'enrollment_rollover_subject_mappings']],
        'processing_authorizations' => [self::POLICY_UNRESOLVED, 'undeletable legal-basis evidence: no adopted period (E21.2G)', ['student_processing_authorizations']],
        'admissions' => [self::POLICY_UNRESOLVED, 'no adopted trigger (E21.2G)', ['applicants', 'admission_applications']],
        'guardians' => [self::POLICY_UNRESOLVED, 'Guardian personal data: no adopted period (D10, E21.2G)', ['guardians', 'guardian_contacts']],
        'documents' => [self::ADOPTED, 'D5: inherits its owner (Student, Employee); others kept with their parent', ['documents']],
        'academic_content' => [self::POLICY_UNRESOLVED, 'School academic content: no adopted period (E21.2G)', [
            'curriculum_deliveries', 'syllabus_units', 'examinations', 'examination_papers', 'learning_content',
            'learning_content_section_audiences', 'assignments', 'assignment_section_audiences', 'timetable_entries',
        ]],
        'attendance_sessions' => [self::POLICY_UNRESOLVED, 'Section register headers: no adopted period (E21.2G)', ['attendance_sessions']],
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
        'operational_modules' => [self::POLICY_UNRESOLVED, 'Hostel, Library, Transport, Inventory, Visitor, Canteen stock and Automation records: no adopted period (E21.2G)', [
            'hostel_residency_assignments', 'library_loans', 'transport_route_assignments', 'transport_student_assignments',
            'inventory_stock_balances', 'stock_movements', 'visitors', 'visitor_visits', 'canteen_order_stock_consumptions',
            'automation_executions', 'automation_execution_attempts', 'automation_review_items',
        ]],
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
