<?php

namespace App\Support\Retention;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * E21-RH.7 (ADR 0066 §15): the database-recorded retention anchor.
 *
 * Every table a retention path deletes from (and the eligibility inputs it
 * reads) carries `retention_recorded_at`, which PostgreSQL alone writes: on
 * INSERT, and again whenever a tracked column (an eligibility clock or path
 * key) changes (`retention_stamp_anchor()`, migration
 * 2026_12_01_090100). A retention unit declares its cutoff on its own
 * session (RetentionExpiry::retained()); PostgreSQL refuses deleting a row
 * recorded on or after it. A clock the runtime role wrote in the past
 * therefore buys nothing: the row was recorded when it was written.
 *
 * TABLES mirrors the migration exactly (DatabaseRoleVerifier proves the
 * live schema matches it).
 */
final class RetentionAnchors
{
    public const COLUMN = 'retention_recorded_at';

    /** The trigger every anchored table carries (fires after every other BEFORE trigger). */
    public const TRIGGER = 'zzz_retention_anchor';

    /** @var array<string, list<string>> table => tracked columns */
    public const TABLES = [
        // Students (exit-measured units) and Student core
        'students' => [],
        'student_enrollments' => ['student_id', 'ends_on', 'status', 'academic_year_id', 'campus_id', 'grade_level_id', 'section_id'],
        'student_subject_enrollments' => ['status', 'academic_year_id', 'elective_group_id', 'student_enrollment_id', 'student_id', 'subject_offering_id'],
        'attendance_records' => ['academic_year_id', 'attendance_session_id', 'campus_id', 'grade_level_id', 'section_id', 'student_enrollment_id'],
        'enrollment_rollover_items' => ['mapping_id', 'plan_id', 'source_enrollment_id', 'student_id', 'target_enrollment_id', 'target_section_id'],
        'library_loans' => ['student_id', 'library_copy_id'],
        'transport_student_assignments' => ['student_id', 'dropoff_stop_id', 'pickup_stop_id', 'route_id'],
        'hostel_residency_assignments' => ['student_id', 'hostel_bed_id'],
        'student_guardian_account_links' => ['status', 'unlinked_at', 'guardian_id', 'school_membership_id', 'student_id'],
        'admission_applications' => ['status', 'terminal_at', 'applicant_id', 'academic_year_id', 'campus_id', 'converted_student_enrollment_id', 'converted_student_id', 'grade_level_id'],
        'applicants' => [],
        'communication_domain_preferences' => ['guardian_id', 'student_id'],
        'documents' => ['student_id', 'guardian_id', 'employee_id', 'learning_content_id', 'assignment_id'],
        'student_processing_authorizations' => ['student_id', 'purpose', 'student_guardian_relationship_id', 'terminates_authorization_id'],
        'communication_domain_consent_events' => ['guardian_id', 'student_id'],
        // Guardians
        'guardians' => ['no_relationship_since'],
        'guardian_contacts' => ['guardian_id'],
        // Employees and Payroll per-employment configuration
        'employees' => [],
        'employment_records' => ['employee_id', 'ends_on', 'status', 'employee_category_id'],
        'employee_assignments' => ['employment_record_id', 'campus_id', 'department_id', 'manager_assignment_id', 'position_id'],
        'employee_personal_details' => ['employee_id'],
        'employee_documents' => ['employee_id'],
        'employee_compensation_assignments' => ['employment_record_id', 'salary_structure_id'],
        'employee_statutory_identifiers' => ['employment_record_id'],
        'employee_tax_profile' => ['employment_record_id'],
        'employee_pf_status' => ['employment_record_id'],
        'employee_esi_coverage' => ['employment_record_id'],
        // Academic (year-measured) and LMS
        'curriculum_deliveries' => ['academic_year_id', 'campus_id', 'grade_level_id', 'section_id', 'subject_offering_id', 'syllabus_unit_id'],
        'attendance_sessions' => ['academic_year_id', 'campus_id', 'grade_level_id', 'period_id', 'section_id', 'subject_offering_id', 'teacher_id', 'timetable_entry_id'],
        'timetable_entries' => ['academic_year_id', 'campus_id', 'grade_level_id', 'period_id', 'room_id', 'section_id', 'subject_offering_id', 'teacher_id'],
        'learning_content' => ['subject_offering_id', 'owner_employee_id'],
        'assignments' => ['subject_offering_id', 'owner_employee_id'],
        'learning_content_section_audiences' => ['academic_year_id', 'campus_id', 'grade_level_id', 'learning_content_id', 'section_id', 'subject_offering_id'],
        'assignment_section_audiences' => ['academic_year_id', 'assignment_id', 'campus_id', 'grade_level_id', 'section_id', 'subject_offering_id'],
        'subject_offerings' => ['academic_year_id', 'campus_id', 'elective_group_id', 'grade_level_id', 'subject_id'],
        'teaching_assignments' => ['ends_on', 'ended_at', 'academic_year_id', 'campus_id', 'employee_id', 'grade_level_id', 'section_id', 'subject_offering_id'],
        // Operational residuals
        'transport_route_assignments' => ['ends_on', 'status', 'driver_employee_id', 'route_id', 'vehicle_id'],
        'visitors' => [],
        'visitor_visits' => ['visitor_id', 'status', 'checked_out_at', 'campus_id', 'host_employee_id'],
        'automation_executions' => ['status', 'completed_at', 'rule_instance_id', 'subject_id'],
        'identity_account_invitations' => ['status', 'accepted_at', 'revoked_at', 'expires_at', 'guardian_id', 'student_id'],
        // Communications
        'communication_deliveries' => ['status', 'delivered_at', 'read_at', 'failed_at', 'recipient_id'],
        'communication_messages' => ['thread_id', 'created_at', 'announcement_id', 'reply_to_message_id'],
        'communication_announcements' => ['status', 'published_at', 'cancelled_at', 'campus_id', 'message_id', 'source_template_id'],
        'communication_threads' => ['last_activity_at', 'campus_id'],
        'communication_approval_requests' => ['status', 'decided_at', 'requested_at', 'announcement_id'],
        'communication_delivery_policy_decisions' => ['created_at', 'message_id', 'recipient_guardian_id', 'recipient_student_id'],
        // E21.2A housekeeping
        'email_messages' => ['status', 'finished_at', 'source_id'],
        'email_provider_references' => ['email_message_id'],
        'email_events' => ['result', 'received_at', 'email_message_id'],
        'domain_event_outbox' => ['status', 'processed_at', 'campus_id'],
        'event_consumer_receipts' => ['event_id'],
        'webhook_deliveries' => ['status', 'updated_at', 'webhook_endpoint_id', 'event_id'],
        // Audit, authority history, suppressions, erasure
        'school_audit_events' => ['occurred_at', 'elevation_id', 'subject_id'],
        'platform_audit_events' => ['occurred_at', 'subject_id'],
        'membership_role_assignments' => ['revoked_at', 'school_membership_id'],
        'group_role_assignments' => ['revoked_at', 'school_group_id'],
        'platform_role_assignments' => ['revoked_at'],
        'school_elevations' => ['status', 'ended_at', 'expires_at', 'group_role_assignment_id', 'school_group_id'],
        'api_client_credentials' => ['revoked_at', 'expires_at', 'api_client_id'],
        'email_suppressions' => ['released_at', 'source_event_id', 'source_email_message_id'],
        'erasure_cases' => ['status', 'subject_id'],
        // Payroll evidence and runs
        'payroll_runs' => ['status', 'posted_at', 'corrects_payroll_run_id', 'payroll_period_id'],
        'payroll_run_postings' => ['created_at', 'currency', 'journal_entry_id', 'payroll_run_id', 'reversal_of_payroll_run_posting_id'],
        'payroll_statutory_run_postings' => ['created_at', 'currency', 'journal_entry_id', 'payroll_run_id', 'reversal_of_posting_id'],
        'payroll_run_results' => ['employee_id', 'employment_record_id', 'payroll_run_id'],
        'payroll_adjustments' => ['employment_record_id', 'payroll_run_id', 'salary_component_id'],
        'payroll_lwf_annual_charges' => ['employment_record_id', 'payroll_run_result_id'],
        // HRX Leave and Staff Attendance evidence
        'leave_ledger_entries' => ['created_at', 'allocation_run_id', 'employment_record_id', 'leave_policy_assignment_id', 'leave_request_id', 'leave_type_id', 'leave_year_id', 'reverses_entry_id', 'tracks_balance', 'year_close_id', 'year_close_reconciliation_id', 'source_id'],
        'leave_requests' => ['created_at', 'updated_at', 'employee_id', 'employment_record_id', 'leave_type_id'],
        'leave_request_days' => ['created_at', 'leave_policy_assignment_id', 'leave_policy_id', 'leave_request_id', 'leave_year_id'],
        'leave_decisions' => ['created_at', 'decider_employee_id', 'leave_request_id', 'requester_employee_id'],
        'leave_year_close_items' => ['created_at', 'employment_record_id', 'leave_policy_id', 'leave_type_id', 'year_close_id'],
        'leave_year_close_reconciliations' => ['created_at', 'leave_request_id', 'reversal_entry_id', 'year_close_item_id'],
        'leave_policy_assignments' => ['created_at', 'updated_at', 'ended_at', 'employment_record_id', 'leave_policy_id', 'leave_type_id'],
        'staff_attendance_records' => ['created_at', 'updated_at', 'employee_id', 'employment_record_id'],
        'staff_attendance_corrections' => ['created_at', 'employment_record_id', 'staff_attendance_record_id'],
        // Finance (D8)
        'financial_periods' => ['status', 'closed_at'],
        'charges' => ['academic_year_id', 'cancellation_journal_entry_id', 'currency', 'journal_entry_id', 'receivable_ledger_account_id', 'revenue_ledger_account_id', 'student_id'],
        'payments' => ['currency', 'journal_entry_id', 'provider_event_id', 'settlement_ledger_account_id'],
        'payment_allocations' => ['charge_id', 'payment_id'],
        'payment_receipts' => ['payment_id', 'series_key'],
        'payment_provider_events' => [],
        'journal_entries' => ['financial_period_id', 'currency', 'reversal_of_journal_entry_id'],
        'fee_adjustments' => ['cancellation_journal_entry_id', 'charge_id', 'credit_ledger_account_id', 'currency', 'debit_ledger_account_id', 'fee_assessment_id', 'fee_concession_id', 'journal_entry_id'],
        'fee_assessments' => ['academic_year_id', 'charge_id', 'fee_assessment_run_id', 'fee_head_id', 'fee_structure_id', 'fee_structure_installment_id', 'fee_structure_line_id', 'student_enrollment_id', 'student_id'],
        'fee_assessment_run_items' => ['fee_assessment_id', 'fee_assessment_run_id', 'fee_head_id', 'fee_structure_installment_id', 'fee_structure_line_id', 'student_enrollment_id', 'student_id', 'campus_id', 'grade_level_id'],
        'fee_concessions' => ['academic_year_id', 'charge_id', 'fee_head_id', 'student_id'],
        'late_fee_assessments' => ['charge_id', 'fee_late_fee_rule_id', 'late_fee_run_id', 'source_charge_id'],
        'late_fee_run_items' => ['late_fee_assessment_id', 'late_fee_run_id', 'source_charge_id', 'academic_year_id', 'fee_head_id', 'student_id'],
        'financial_period_charge_states' => ['charge_id', 'financial_period_id'],
        // OPF.1 (ADR 0067 §21): Transport's insert-only fee-selection provenance (Finance evidence).
        'transport_fee_selections' => ['transport_student_assignment_id', 'academic_year_id', 'fee_head_id', 'fee_optional_selection_id'],
        // OPF.2 (ADR 0067 §28): Hostel's insert-only fee-selection provenance (Finance evidence).
        'hostel_fee_selections' => ['hostel_residency_assignment_id', 'academic_year_id', 'fee_head_id', 'fee_optional_selection_id'],
        // OPF.3 (ADR 0067 §29): Admissions' insert-only Admission-fee provenance (Finance evidence).
        'admission_fee_selections' => ['admission_application_id', 'student_id', 'academic_year_id', 'fee_head_id', 'fee_optional_selection_id'],
        // OPF.4 (ADR 0067 §30): Library fine evidence and its voids (Finance evidence; the fine references `charges`).
        'library_fines' => ['library_loan_id', 'student_id', 'library_fine_policy_id', 'fee_head_id', 'academic_year_id', 'charge_id'],
        'library_fine_voids' => ['library_fine_id'],
        // RES.2 (ADR 0068 §12): StudentMark and its value history (retention policy_unresolved until RES-L8).
        'student_marks' => ['examination_paper_id', 'academic_year_id', 'student_id', 'student_enrollment_id', 'student_subject_enrollment_id', 'processing_authorization_id'],
        'student_mark_revisions' => ['student_mark_id', 'student_enrollment_id', 'student_subject_enrollment_id', 'processing_authorization_id'],
    ];

    /** The inputs no retention path deletes from (anchored, not delete-guarded). */
    public const INPUTS = ['subject_offerings', 'communication_approval_requests', 'financial_periods'];

    /**
     * Narrows a selection to rows the database recorded before the unit's
     * declared cutoff, so a forged row is skipped (not eligible) rather than
     * failing its batch at the delete guard. Without a declared cutoff it
     * matches nothing.
     *
     * @template TBuilder of Builder|EloquentBuilder<*>
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function recordedBefore(Builder|EloquentBuilder $query, string $alias): Builder|EloquentBuilder
    {
        $query->whereRaw('"'.$alias.'".'.self::COLUMN." < NULLIF(current_setting('app.retention_anchor_cutoff', true), '')::timestamp");

        return $query;
    }

    /**
     * The opposite: rows recorded on or after the declared cutoff (or any
     * row, when none is declared) -- for "a younger row keeps the unit".
     *
     * @template TBuilder of Builder|EloquentBuilder<*>
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function recordedOnOrAfter(Builder|EloquentBuilder $query, string $alias): Builder|EloquentBuilder
    {
        $query->whereRaw('NOT ("'.$alias.'".'.self::COLUMN." < NULLIF(current_setting('app.retention_anchor_cutoff', true), '')::timestamp) IS TRUE");

        return $query;
    }

    /** PostgreSQL refused deleting a row recorded on or after the unit's cutoff (logged by its closed code only). */
    public static function refused(QueryException $e): bool
    {
        if (! str_contains($e->getMessage(), '(retention_anchor)')) {
            return false;
        }
        Log::warning('retention.recorded_within_period', ['reason' => 'retention_anchor']);

        return true;
    }

    public static function anchored(string $table): bool
    {
        return array_key_exists($table, self::TABLES);
    }
}
