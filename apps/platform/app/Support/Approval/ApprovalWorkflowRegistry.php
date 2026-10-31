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
            'participants' => ['communication_approval_requests.requested_by_user_id', 'communication_events.created_by_user_id'],
            'decider' => 'communication_approval_requests.decided_by_user_id',
            // ORG.9D-3: publishing an approved calendar Event (execution, never a decision of its own).
            'otherDecisionColumns' => ['communication_events.published_by_user_id'],
            'dbBackstop' => null,
            'makerChecker' => 'domain',
            'service' => 'App\Domain\Communications\Application\Approval\CommunicationApprovalService',
            'decisions' => ['approve', 'reject'],
            'execution' => 'separate',
            'drift' => 'fingerprint',
            'freshMfa' => [],
            'compatibility' => 'adapter',
            'note' => 'Self-approval refused in the service only (no DB check); the announcement creator is a second candidate participant (ORG.9D-2: refused as decider for a fee_reminder, whose preparer is the only sender); ORG.9D-3: calendar Events share this table (exclusive event_id subject) -- their creator and every audited editor are refused deciders; the capability is checked in the controller.',
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
            // The submitter (User) is the shared participant; the requester Employee is excluded by identity (DB CHECK).
            'participants' => ['leave_requests.employee_id', 'leave_requests.submitted_by_user_id', 'leave_decisions.requester_employee_id'],
            'decider' => 'leave_decisions.decided_by_user_id',
            'otherDecisionColumns' => ['leave_decisions.decider_employee_id'],
            'dbBackstop' => 'leave_decisions_no_self_decision',
            'makerChecker' => 'shared',
            'service' => 'App\Domain\Leave\Application\LeaveRequestService',
            // approve/approveAsManager/reject/rejectAsManager all decide through decide().
            'decisions' => ['decide'],
            'execution' => 'immediate',
            'drift' => 'immutable_terms',
            'freshMfa' => [],
            'compatibility' => 'special_rules',
            'note' => 'ORG.8D (§62). Two paths: manager = whole-School hr.leave.approve AND the current reporting line (ReportingLine::holdManagerOf, FOR SHARE, read at decision time); administrative = hr.leave.manage (any request, fallback). Authority read fresh; the submitter and the requester Employee never decide (service + leave_decisions_no_self_decision + leave_decisions_participant_guard). No Campus logic; Campus-granted managers and cross-Campus managers are owner decision OD-8.8 (not built). No step-up (NONE). Administrative writes never touch the actor\'s own employment (G-4).',
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
     * ORG.8E/ORG.8G (ADR 0076 §63, §65): DIRECT high-impact actions with
     * assurance -- NOT request/approve workflows (owner decisions 8E-D1..D3,
     * OD-8.3). Each re-reads current whole-School authority
     * (`ApprovalDecisionAuthority`) under its row lock, takes the actor's
     * `FreshMfaProof` and a closed code-owned reason, and writes database-
     * required evidence; never maker/checker, participants or approval
     * provenance. `relationshipConflict` names a beneficiary rule the class
     * enforces (service + `dbGuard`); `entryPoints` are the only callers.
     *
     * @var array<class-string, array{capability: string, action: string, freshAuthority: true, freshMfa: true, reason: string, evidence: string, relationshipConflict: string|null, dbGuard?: array{trigger: string, refusal: string}, accountingOwner?: string, entryPoints?: list<string>}>
     */
    public const DIRECT_ASSURED = [
        // ORG.12C (ADR 0080 §8): legal name, registration and affiliation references.
        'App\Domain\Schools\Application\SchoolIdentityService' => [
            'capability' => 'school.profile.manage', 'action' => 'School institutional identity change',
            'freshAuthority' => true, 'freshMfa' => true, 'reason' => 'SchoolIdentityChangeReason (closed)',
            'evidence' => 'school_audit_events school.institutional_identity_changed (changed fields, reason, MFA time; never values)', 'relationshipConflict' => null,
        ],
        'App\Domain\Students\Application\StudentService' => [
            'capability' => 'students.manage', 'action' => 'Student status change',
            'freshAuthority' => true, 'freshMfa' => true, 'reason' => 'StudentLifecycleReason (closed)',
            'evidence' => 'student_lifecycle_events (DB-required: students_lifecycle_evidence)', 'relationshipConflict' => null,
        ],
        'App\Domain\Students\Application\StudentEnrollmentService' => [
            'capability' => 'enrollments.manage', 'action' => 'placement withdraw / cancel',
            'freshAuthority' => true, 'freshMfa' => true, 'reason' => 'StudentLifecycleReason (closed)',
            'evidence' => 'student_lifecycle_events (DB-required on the placement status change)', 'relationshipConflict' => null,
        ],
        'App\Domain\Library\Application\LibraryFineService' => [
            'capability' => 'library.fines.void', 'action' => 'Library fine void (its Charge through ChargeService)',
            'freshAuthority' => true, 'freshMfa' => true, 'reason' => 'LibraryFineVoid::REASON_CODES + note',
            'evidence' => 'library_fine_voids.reason_code (DB-required)', 'relationshipConflict' => null,
        ],
        'App\Domain\Fees\Application\ChargeService' => [
            'capability' => 'finance.charges.manage', 'action' => 'Charge cancel, fee-assessment void, late-fee void, source void (OD-8.3)',
            'freshAuthority' => true, 'freshMfa' => true, 'reason' => 'ChargeCancellationReason (closed; note for other)',
            'evidence' => 'charges.cancellation_reason_code + cancelled_by_user_id (DB-required: charges_cancellation_evidence)',
            'relationshipConflict' => 'guardian_of_student_beneficiary',
            'dbGuard' => ['trigger' => 'charges_cancellation_evidence', 'refusal' => 'charges_cancellation_beneficiary_guardian_guard'],
            'accountingOwner' => 'Fees: ChargeService reverses its own recognition entry (ORG.8-P1 accepts it)',
            'entryPoints' => [
                'App\Domain\Fees\Application\ChargeAdministrationService',
                'App\Domain\Fees\Application\FeeAssessmentService',
                'App\Domain\Payments\Application\LateFeeAssessmentService',
                'App\Domain\Fees\Application\Sources\FeeSourceChargeService',
            ],
        ],
        // ORG.9C-2 (ADR 0076 §70): never a request/approve chain (INV-034 stays DO_NOT_BUILD).
        'App\Domain\Inventory\Application\InventoryStockService' => [
            'capability' => 'inventory.stock.manage', 'action' => 'Inventory stock write-off (writeOff(); issue, receive and transfer stay ordinary)',
            'freshAuthority' => true, 'freshMfa' => true, 'reason' => 'WriteOffReason (closed: damaged, expired, lost; no free text)',
            'evidence' => 'stock_movements write_off movement + write_off_reason (DB-required: stock_movements_write_off_reason_check)', 'relationshipConflict' => null,
            'campusScoped' => 'The Location\'s Campus: requireCurrent() with it (a whole-School grant or an active grant on that Campus); a School-wide Location needs whole-School authority.',
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
        // SIS 11.5: document verification is one reviewer's evidence-quality
        // judgement on an uploaded file (verified, or rejected with a closed
        // reason; final; fixed by a replacement). Nothing is requested, and
        // nothing executes because of it -- compliance stays advisory.
        'student_documents.verified_by_user_id' => 'who verified or rejected a Student document (SIS 11.5 evidence review, never a request decided by a second person)',
        'applicant_documents.verified_by_user_id' => 'who verified or rejected a pre-admission document (SIS 11.5 evidence review, never a request decided by a second person)',
        'students.documents.verify' => 'the SIS 11.5 evidence-review capability: it decides a document\'s quality, never a request; no effect executes because of it',
    ];
}
