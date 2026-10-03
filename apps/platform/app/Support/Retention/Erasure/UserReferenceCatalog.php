<?php

namespace App\Support\Retention\Erasure;

/**
 * E21.4 (E21-L1 project-adopted, India-aligned development position,
 * pending qualified ratification): every foreign key to `users`, each with
 * exactly ONE minimization treatment. A literal closed list read from the
 * live catalog at E21.4 (87 references; HRX.1 added 9 and HRX.2 4 Leave actor references); `UserReferenceCatalogTest` fails
 * as soon as a migration adds, renames or drops one, so a new reference is
 * unclassified until someone decides it here. A User whose live schema has
 * an unclassified reference is never minimized (fail closed).
 *
 * The FK's own ON DELETE action is NOT the policy: the runtime role cannot
 * delete a User at all (F1), so no CASCADE or SET NULL ever runs.
 *
 * - ACTIVE_PURPOSE_BLOCKER: a CURRENT row (an invited/active membership, a
 *   current Employee, an unrevoked platform or Group grant, an active
 *   elevation, an enabled automation it owns) keeps the User current, so it
 *   cannot be minimized (UserActivePurposes). An ended row is history and
 *   keeps its reference to the tombstone.
 * - RETAIN_REFERENCE: historical actor, author, recipient or subject
 *   reference (audit, D6 authority history, Finance/Payroll operators,
 *   Communications, Documents, ...). It keeps pointing at the tombstone,
 *   unchanged, for its own retention period. Nothing is nulled or rewritten.
 * - DELETE_CHILD: authentication material (MFA, recovery and activation
 *   credentials) that only ever served the login; deleted at minimization.
 * - NULL_UNDER_DOMAIN_LIFECYCLE / UNRESOLVED: no reference uses either
 *   today; they exist so a future reference can be classified explicitly.
 */
final class UserReferenceCatalog
{
    public const ACTIVE_PURPOSE_BLOCKER = 'active_purpose_blocker';

    public const RETAIN_REFERENCE = 'retain_reference_to_tombstone';

    public const NULL_UNDER_DOMAIN_LIFECYCLE = 'may_null_under_domain_lifecycle';

    public const DELETE_CHILD = 'may_delete_child_under_retention_policy';

    public const UNRESOLVED = 'unresolved_fail_closed';

    /** table => [column => treatment] */
    public const REFERENCES = [
        // HRX.1 (ADR 0065): Leave actors are historical references.
        'leave_settings' => ['updated_by_user_id' => self::RETAIN_REFERENCE],
        'leave_year_start_changes' => ['created_by_user_id' => self::RETAIN_REFERENCE],
        'leave_policies' => ['created_by_user_id' => self::RETAIN_REFERENCE],
        'leave_policy_assignments' => ['created_by_user_id' => self::RETAIN_REFERENCE, 'ended_by_user_id' => self::RETAIN_REFERENCE],
        'staff_working_weekdays' => ['updated_by_user_id' => self::RETAIN_REFERENCE],
        'staff_holidays' => ['created_by_user_id' => self::RETAIN_REFERENCE],
        'leave_allocation_runs' => ['executed_by_user_id' => self::RETAIN_REFERENCE],
        'leave_ledger_entries' => ['actor_user_id' => self::RETAIN_REFERENCE],
        // HRX.2 (ADR 0065 §23): submitters, deciders and year-close actors.
        'leave_requests' => ['submitted_by_user_id' => self::RETAIN_REFERENCE],
        'leave_decisions' => ['decided_by_user_id' => self::RETAIN_REFERENCE],
        'leave_year_closes' => ['executed_by_user_id' => self::RETAIN_REFERENCE],
        'leave_year_close_reconciliations' => ['created_by_user_id' => self::RETAIN_REFERENCE],
        'account_activation_credentials' => ['user_id' => self::DELETE_CHILD],
        'account_recovery_requests' => ['user_id' => self::DELETE_CHILD],
        'api_clients' => ['created_by_user_id' => self::RETAIN_REFERENCE, 'revoked_by_user_id' => self::RETAIN_REFERENCE],
        'attendance_sessions' => ['submitted_by_user_id' => self::RETAIN_REFERENCE],
        'automation_rule_instances' => ['owner_user_id' => self::ACTIVE_PURPOSE_BLOCKER],
        'communication_announcement_recipients' => ['user_id' => self::RETAIN_REFERENCE],
        'communication_announcements' => ['created_by_user_id' => self::RETAIN_REFERENCE, 'emergency_declared_by_user_id' => self::RETAIN_REFERENCE, 'scheduled_by_user_id' => self::RETAIN_REFERENCE],
        'communication_approval_requests' => ['decided_by_user_id' => self::RETAIN_REFERENCE, 'requested_by_user_id' => self::RETAIN_REFERENCE],
        'communication_attachments' => ['created_by_user_id' => self::RETAIN_REFERENCE],
        'communication_delivery_policy_decisions' => ['recipient_user_id' => self::RETAIN_REFERENCE],
        'communication_domain_consent_events' => ['recorded_by_user_id' => self::RETAIN_REFERENCE],
        'communication_messages' => ['sender_user_id' => self::RETAIN_REFERENCE],
        'communication_recipients' => ['recipient_user_id' => self::RETAIN_REFERENCE],
        'communication_templates' => ['created_by_user_id' => self::RETAIN_REFERENCE],
        'communication_thread_participants' => ['user_id' => self::RETAIN_REFERENCE],
        'communication_threads' => ['created_by_user_id' => self::RETAIN_REFERENCE],
        'documents' => ['uploaded_by_user_id' => self::RETAIN_REFERENCE],
        'email_suppressions' => ['released_by_user_id' => self::RETAIN_REFERENCE],
        'employee_documents' => ['uploaded_by_user_id' => self::RETAIN_REFERENCE],
        'employee_notes' => ['author_user_id' => self::RETAIN_REFERENCE],
        'employees' => ['user_id' => self::ACTIVE_PURPOSE_BLOCKER],
        'enrollment_rollover_plans' => ['created_by_user_id' => self::RETAIN_REFERENCE],
        'fee_adjustments' => ['cancelled_by_user_id' => self::RETAIN_REFERENCE, 'posted_by_user_id' => self::RETAIN_REFERENCE],
        'fee_assessment_run_items' => ['excluded_by_user_id' => self::RETAIN_REFERENCE],
        'fee_assessment_runs' => ['cancelled_by_user_id' => self::RETAIN_REFERENCE, 'created_by_user_id' => self::RETAIN_REFERENCE, 'executed_by_user_id' => self::RETAIN_REFERENCE, 'previewed_by_user_id' => self::RETAIN_REFERENCE],
        'fee_assessments' => ['created_by_user_id' => self::RETAIN_REFERENCE, 'voided_by_user_id' => self::RETAIN_REFERENCE],
        'fee_concessions' => ['decided_by_user_id' => self::RETAIN_REFERENCE, 'requested_by_user_id' => self::RETAIN_REFERENCE, 'revoked_by_user_id' => self::RETAIN_REFERENCE],
        'fee_late_fee_rules' => ['created_by_user_id' => self::RETAIN_REFERENCE],
        'fee_optional_selections' => ['selected_by_user_id' => self::RETAIN_REFERENCE, 'withdrawn_by_user_id' => self::RETAIN_REFERENCE],
        'fee_structures' => ['activated_by_user_id' => self::RETAIN_REFERENCE, 'created_by_user_id' => self::RETAIN_REFERENCE, 'retired_by_user_id' => self::RETAIN_REFERENCE],
        'financial_periods' => ['closed_by_user_id' => self::RETAIN_REFERENCE],
        'group_role_assignments' => ['granted_by_user_id' => self::RETAIN_REFERENCE, 'revoked_by_user_id' => self::RETAIN_REFERENCE, 'user_id' => self::ACTIVE_PURPOSE_BLOCKER],
        'identity_account_invitations' => ['invited_by_user_id' => self::RETAIN_REFERENCE, 'revoked_by_user_id' => self::RETAIN_REFERENCE],
        'late_fee_assessments' => ['created_by_user_id' => self::RETAIN_REFERENCE, 'voided_by_user_id' => self::RETAIN_REFERENCE],
        'late_fee_runs' => ['cancelled_by_user_id' => self::RETAIN_REFERENCE, 'created_by_user_id' => self::RETAIN_REFERENCE, 'executed_by_user_id' => self::RETAIN_REFERENCE, 'previewed_by_user_id' => self::RETAIN_REFERENCE],
        'membership_role_assignments' => ['assigned_by_user_id' => self::RETAIN_REFERENCE, 'revoked_by_user_id' => self::RETAIN_REFERENCE],
        'notifications' => ['recipient_user_id' => self::RETAIN_REFERENCE],
        'payment_receipts' => ['issued_by_user_id' => self::RETAIN_REFERENCE],
        'payments' => ['recorded_by_user_id' => self::RETAIN_REFERENCE],
        'payroll_adjustments' => ['actor_user_id' => self::RETAIN_REFERENCE],
        'payroll_run_postings' => ['actor_user_id' => self::RETAIN_REFERENCE],
        'payroll_runs' => ['approved_by_user_id' => self::RETAIN_REFERENCE, 'posted_by_user_id' => self::RETAIN_REFERENCE, 'prepared_by_user_id' => self::RETAIN_REFERENCE],
        'payroll_statutory_run_postings' => ['actor_user_id' => self::RETAIN_REFERENCE],
        'platform_audit_events' => ['actor_user_id' => self::RETAIN_REFERENCE],
        'platform_role_assignments' => ['granted_by_user_id' => self::RETAIN_REFERENCE, 'revoked_by_user_id' => self::RETAIN_REFERENCE, 'user_id' => self::ACTIVE_PURPOSE_BLOCKER],
        'school_audit_events' => ['actor_user_id' => self::RETAIN_REFERENCE],
        'school_elevations' => ['actor_user_id' => self::ACTIVE_PURPOSE_BLOCKER],
        'school_memberships' => ['user_id' => self::ACTIVE_PURPOSE_BLOCKER],
        'school_settings' => ['updated_by_user_id' => self::RETAIN_REFERENCE],
        'schools' => ['closed_by_user_id' => self::RETAIN_REFERENCE],
        'staff_account_invitations' => ['accepted_user_id' => self::RETAIN_REFERENCE, 'invited_by_user_id' => self::RETAIN_REFERENCE, 'revoked_by_user_id' => self::RETAIN_REFERENCE],
        'student_guardian_account_links' => ['linked_by_user_id' => self::RETAIN_REFERENCE, 'unlinked_by_user_id' => self::RETAIN_REFERENCE],
        'student_processing_authorizations' => ['recorded_by_user_id' => self::RETAIN_REFERENCE],
        'teaching_assignments' => ['created_by_user_id' => self::RETAIN_REFERENCE, 'ended_by_user_id' => self::RETAIN_REFERENCE],
        'user_mfa_factors' => ['user_id' => self::DELETE_CHILD],
        'user_mfa_recovery_codes' => ['user_id' => self::DELETE_CHILD],
        'webhook_endpoints' => ['created_by_user_id' => self::RETAIN_REFERENCE],
    ];

    /**
     * Live foreign keys to `users` that this catalog does not classify, or
     * classifies as unresolved. Read-only.
     *
     * @param  list<string>  $live  "table.column" of every live foreign key to users
     * @return list<string>
     */
    public static function unclassified(array $live): array
    {
        return array_values(array_filter($live, fn (string $ref): bool => (self::treatments()[$ref] ?? self::UNRESOLVED) === self::UNRESOLVED));
    }

    /** @return array<string, string> "table.column" => treatment */
    public static function treatments(): array
    {
        $flat = [];
        foreach (self::REFERENCES as $table => $columns) {
            foreach ($columns as $column => $treatment) {
                $flat["{$table}.{$column}"] = $treatment;
            }
        }

        return $flat;
    }
}
