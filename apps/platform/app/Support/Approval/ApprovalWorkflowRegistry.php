<?php

namespace App\Support\Approval;

/**
 * ORG.8A (ADR 0076 §58): the code-owned inventory of every request/decide
 * workflow and its declared security characteristics. An ARCHITECTURE
 * GUARD, never runtime configuration: nothing reads it to route, decide or
 * execute. Each workflow keeps its own table, states, queue and
 * transaction (owner decision 8A-D1); ApprovalWorkflowRegistryTest proves
 * this inventory against the database and the code, so a new approval
 * table, decider column or request/approve capability fails until it is
 * declared here (or in NOT_WORKFLOWS, with the reason).
 *
 * Fields:
 *   tables           the domain's own request/decision tables
 *   request / decide capabilities (whole-School keys; none Campus-adopted)
 *   participants     durable columns naming the material participants
 *   decider          the column recording who decided (otherDecisionColumns: more of them)
 *   dbBackstop       the database maker/checker constraint (null: none)
 *   makerChecker     'shared' (MakerCheckerPolicy + ApprovalDecisionAuthority) or 'domain'
 *   service / decisions  the deciding class and methods (null for isolated Marks code)
 *   authorityService (optional) the authorized entry that takes the decider's current authority
 *   execution        'immediate' (the decision executes in its transaction) or 'separate'
 *   drift            'immutable_terms' | 'fingerprint' | 'frozen_at_approval'
 *   freshMfa         decisions needing FreshMfaRequirement
 *   compatibility    'pilot' | 'as_is' | 'adapter' | 'special_rules' | 'not_applicable'
 *   relationshipConflict  (optional) a domain-specific decider conflict beyond maker/checker
 */
final class ApprovalWorkflowRegistry
{
    public const WORKFLOWS = [
        'fee_concessions' => [
            'domain' => 'Fees',
            'tables' => ['fee_concessions'],
            'request' => ['finance.fee_concessions.request'],
            'decide' => ['finance.fee_concessions.approve'],
            'participants' => ['fee_concessions.requested_by_user_id'],
            'decider' => 'fee_concessions.decided_by_user_id',
            'dbBackstop' => 'fee_concessions_sod_check',
            'makerChecker' => 'shared',
            'service' => 'App\Domain\Fees\Application\FeeConcessionService',
            'decisions' => ['approve', 'reject'],
            'execution' => 'immediate',
            'drift' => 'immutable_terms',
            'freshMfa' => ['approve'],
            'compatibility' => 'pilot',
            // ORG.8A-R1 (§59): the concession's own beneficiary conflict, after maker/checker; backed by
            // fee_concessions_beneficiary_guardian_guard; the decider cannot edit the relationship (Rule B).
            'relationshipConflict' => 'guardian_of_student_beneficiary',
            'note' => 'ORG.8A pilot. Request terms and requester are DB-immutable; revoke and adjustment cancellation stay outside the pilot (G-3).',
        ],
        'payment_corrections' => [
            'domain' => 'Payments',
            'tables' => ['payment_correction_requests'],
            'request' => ['finance.payment_corrections.request'],
            'decide' => ['finance.payment_corrections.approve'],
            'participants' => ['payment_correction_requests.requested_by_user_id'],
            'decider' => 'payment_correction_requests.decided_by_user_id',
            'dbBackstop' => 'payment_correction_requests_sod_check',
            'makerChecker' => 'shared',
            'service' => 'App\Domain\Payments\Application\PaymentCorrectionRequestService',
            'decisions' => ['approve', 'reject'],
            'execution' => 'immediate',
            'drift' => 'immutable_terms',
            'freshMfa' => ['approve'],
            'compatibility' => 'as_is',
            // ORG.8C (§61): never a Guardian of any Student the Payment or the request touches; backed by
            // payment_correction_requests_beneficiary_guardian_guard; Rule B (§59) keeps the link un-editable by the decider.
            'relationshipConflict' => 'guardian_of_student_beneficiary',
            'note' => 'ORG.8C. Reallocation, reversal and recorded refund; approval executes through PaymentCorrectionService::executeApproved() against the requested correction generation (stale otherwise); no direct human correction route.',
        ],
        'communication_approvals' => [
            'domain' => 'Communications',
            'tables' => ['communication_approval_requests'],
            'request' => ['communications.announce'],
            'decide' => ['communications.approve'],
            'participants' => ['communication_approval_requests.requested_by_user_id'],
            'decider' => 'communication_approval_requests.decided_by_user_id',
            'dbBackstop' => null,
            'makerChecker' => 'domain',
            'service' => 'App\Domain\Communications\Application\Approval\CommunicationApprovalService',
            'decisions' => ['approve', 'reject'],
            'execution' => 'separate',
            'drift' => 'fingerprint',
            'freshMfa' => [],
            'compatibility' => 'adapter',
            'note' => 'Self-approval refused in the service only (no DB check); the announcement creator is a second candidate participant; the capability is checked in the controller.',
        ],
        'student_mark_corrections' => [
            'domain' => 'Examinations',
            'tables' => ['student_mark_corrections'],
            'request' => ['examinations.marks.correction.request'],
            'decide' => ['examinations.marks.correction.approve'],
            'participants' => ['student_mark_corrections.requested_by_user_id'],
            'decider' => 'student_mark_corrections.decided_by_user_id',
            'dbBackstop' => 'student_mark_corrections_maker_checker_check',
            'makerChecker' => 'domain',
            // Marks code is isolated (ADR 0068 §19.2): nothing outside it names its classes; its own guards pin the service.
            'service' => null,
            'decisions' => [],
            'execution' => 'immediate',
            'drift' => 'immutable_terms',
            'freshMfa' => ['approve', 'reject'],
            'compatibility' => 'as_is',
            'note' => 'Production-refused in code (E36/E37); the capability is checked at the route.',
        ],
        'leave_requests' => [
            'domain' => 'Leave',
            'tables' => ['leave_requests', 'leave_decisions'],
            'request' => ['hr.leave.self', 'hr.leave.manage'],
            'decide' => ['hr.leave.approve', 'hr.leave.manage'],
            'participants' => ['leave_requests.employee_id', 'leave_requests.submitted_by_user_id', 'leave_decisions.requester_employee_id'],
            'decider' => 'leave_decisions.decided_by_user_id',
            'otherDecisionColumns' => ['leave_decisions.decider_employee_id'],
            'dbBackstop' => 'leave_decisions_no_self_decision',
            'makerChecker' => 'domain',
            'service' => 'App\Domain\Leave\Application\LeaveRequestService',
            'decisions' => ['approve', 'approveAsManager', 'reject', 'rejectAsManager'],
            'execution' => 'immediate',
            'drift' => 'immutable_terms',
            'freshMfa' => [],
            'compatibility' => 'special_rules',
            'note' => 'Participation is by Employee identity (trigger-derived), the manager path needs the current reporting line, and the self path is only withdraw/cancel.',
        ],
        'payroll_runs' => [
            'domain' => 'Payroll',
            'tables' => ['payroll_runs', 'payroll_run_calculations'],
            'request' => ['payroll.runs.prepare'],
            'decide' => ['payroll.runs.approve'],
            // ORG.8B (§60): every material preparer -- creator, every recorded calculator, every adjustment author.
            'participants' => ['payroll_runs.prepared_by_user_id', 'payroll_run_calculations.calculated_by_user_id', 'payroll_adjustments.actor_user_id'],
            'decider' => 'payroll_runs.approved_by_user_id',
            'otherDecisionColumns' => ['payroll_runs.posted_by_user_id'],
            'dbBackstop' => 'payroll_runs_participant_approval_guard',
            'makerChecker' => 'shared',
            'service' => 'App\Domain\Payroll\Application\PayrollRunService',
            // The decider's current authority is taken by the authorized entry, in the same transaction.
            'authorityService' => 'App\Domain\Payroll\Application\PayrollRunAdministrationService',
            'decisions' => ['approve'],
            'execution' => 'separate',
            'drift' => 'frozen_at_approval',
            'freshMfa' => ['approve'],
            'compatibility' => 'as_is',
            'note' => 'Approve freezes the results (calculated -> approved); posting is a separate step the approver may also take (OD-8.4); stale results (an adjustment after the last recorded calculation) are refused.',
        ],
        'erasure_cases' => [
            'domain' => 'Compliance',
            'tables' => ['erasure_cases'],
            'request' => [],
            'decide' => [],
            'participants' => [],
            'decider' => null,
            'dbBackstop' => null,
            'makerChecker' => 'domain',
            'service' => 'App\Console\Commands\DecideErasureCase',
            'decisions' => ['handle'],
            'execution' => 'separate',
            'drift' => 'immutable_terms',
            'freshMfa' => [],
            'compatibility' => 'not_applicable',
            'note' => 'A data subject\'s request decided by the operator CLI; the requester is not a User, so maker/checker does not apply.',
        ],
    ];

    /**
     * Decision-shaped columns or capabilities that are NOT approval workflows,
     * with the reason. Anything else decision-shaped must be a workflow.
     */
    public const NOT_WORKFLOWS = [
        'attendance_sessions.submitted_by_user_id' => 'who submitted a Student attendance register; a register is recorded, never requested or decided by a second person',
        'fee_assessment_run_items.overlap_decided_by_user_id' => 'the run operator\'s include/exclude review of an overlapping item in their own run (UAT-C6), not a request decided by a second person',
        'fee_assessment_runs.previewed_by_user_id' => 'who previewed an operator run',
        'late_fee_runs.previewed_by_user_id' => 'who previewed an operator run',
    ];
}
