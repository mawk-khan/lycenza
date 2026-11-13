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
 *   knownSamePerson  HR.R1-C34 (WO-16, owner decision HR.R1-C33): whether an account KNOWN to be the same
 *                    person as a participant (two accounts with uncorrected links to one Employee, rule 145) is
 *                    refused too. Enforced: ['check' => the class::method holding the service check, 'dbGuard'
 *                    => the trigger that backs it (DatabaseRoleVerifier::KNOWN_SAME_PERSON_GUARDS)], plus, for
 *                    Payroll (WO-17, C34-N1), 'beneficiaryCheck'/'beneficiaryGuard': the OD-HR-24 self-beneficiary
 *                    refusal, which matches a known-same account too; plus, for separation and Leave (WO-18,
 *                    C34-N3), 'ownCaseCheck'/'ownCaseGuards': "never one's own" for the other lifecycle writes; or
 *                    ['handoff' => the C33 handoff to the owning domain, decided there, never here]. Every
 *                    HR-family and Payroll entry is enforced (KnownSamePersonCoverageTest). Unknown (no shared
 *                    Employee) is never refused and never proof of two people (RG-ID-1).
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
            'knownSamePerson' => ['handoff' => 'C33-H1'],
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
            'knownSamePerson' => ['handoff' => 'C33-H2'],
            'relationshipConflict' => 'guardian_of_student_beneficiary',
            'note' => 'ORG.8C. Reallocation, reversal and recorded refund; approval executes through PaymentCorrectionService::executeApproved() against the requested correction generation (stale otherwise); no direct human correction route.',
        ],
        // HR.R1-C8 (OD-HR-25 as amended): two linked staff, and an approval is what voids the link. No key is listed
        // because `hr.employees.manage` is Campus-adopted: both steps need it WHOLE-SCHOOL (requireWholeSchool, the
        // fresh read, and `campus_id IS NULL` in the database), plus a link to another active Employee.
        'employee_account_link_corrections' => [
            'domain' => 'HR',
            'tables' => ['employee_account_link_corrections'],
            'request' => [],
            'decide' => [],
            'participants' => ['employee_account_link_corrections.requested_by_user_id'],
            'decider' => 'employee_account_link_corrections.decided_by_user_id',
            'dbBackstop' => 'employee_account_link_corrections_sod_check',
            'makerChecker' => 'shared',
            'service' => 'App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkCorrectionService',
            'decisions' => ['approve', 'refuse'],
            'execution' => 'immediate',
            'drift' => 'fingerprint',
            'freshMfa' => ['approve', 'refuse'],
            'compatibility' => 'as_is',
            'knownSamePerson' => ['check' => 'App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkCorrectionService::assertMayDecide', 'dbGuard' => 'trg_employee_account_link_corrections_guard'],
            'note' => 'Separation of duties, never proof of two people. Also refused: an account ever linked to the Employee, and an account known to be the requester (a shared uncorrected link).',
        ],
        // HR.R1-C18 (WO-6; S-6 decided HR.R1-C17): a correction of a LOCKED Staff Attendance month. Requested by a
        // holder of hr.staff_attendance.manage, decided by another holder of hr.staff_attendance.lock (whole-School,
        // re-read under the lock), never the requester or the Employee. The database applies an approval from the
        // request's own values; a stale request can only be refused. A month is never reopened.
        'staff_attendance_locked_corrections' => [
            'domain' => 'StaffAttendance',
            'tables' => ['staff_attendance_locked_corrections'],
            'request' => ['hr.staff_attendance.manage'],
            'decide' => ['hr.staff_attendance.lock'],
            'participants' => ['staff_attendance_locked_corrections.requested_by_user_id'],
            'decider' => 'staff_attendance_locked_corrections.decided_by_user_id',
            'dbBackstop' => 'staff_attendance_locked_corrections_sod_check',
            'makerChecker' => 'shared',
            'service' => 'App\Domain\StaffAttendance\Application\StaffAttendanceService',
            'decisions' => ['approveLockedCorrection', 'refuseLockedCorrection'],
            'execution' => 'immediate',
            'drift' => 'immutable_terms',
            'freshMfa' => ['approveLockedCorrection', 'refuseLockedCorrection'],
            'compatibility' => 'as_is',
            'knownSamePerson' => ['check' => 'App\Domain\StaffAttendance\Application\StaffAttendanceService::independentOf', 'dbGuard' => 'trg_staff_attendance_locked_corrections_guard'],
            'note' => 'Separation of duties, never proof of two people (HR.R1-C34: known same person refused here since C18; unknown stays RG-ID-1). Also refused in the database: a decider who holds no whole-School lock key, a known same person, or any account ever linked to the Employee.',
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
            'knownSamePerson' => ['handoff' => 'C33-H3'],
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
            'knownSamePerson' => ['handoff' => 'C33-H4'],
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
            // HR.R1-C34: nor an account known to be the submitter, or the requester through an uncorrected link, current or ended.
            'knownSamePerson' => ['check' => 'App\Domain\Leave\Application\LeaveRequestService::assertMayDecide', 'dbGuard' => 'trg_leave_decisions_participant_guard',
                // WO-18 (C34-N3): administrative withdraw and cancel of one's own leave, person-level.
                'ownCaseCheck' => 'App\Domain\Leave\Application\LeaveRequestService::refuseKnownToBeRequester', 'ownCaseGuards' => ['trg_leave_decisions_participant_guard']],
            'note' => 'ORG.8D (§62). Two paths: manager = whole-School hr.leave.approve AND the current reporting line (ReportingLine::holdManagerOf, FOR SHARE, read at decision time; HR.3A: the dated employment-level line effective on the decision date); administrative = hr.leave.manage (any request, fallback). Authority read fresh; the submitter and the requester Employee never decide (service + leave_decisions_no_self_decision + leave_decisions_participant_guard). No Campus logic; Campus-granted managers and cross-Campus managers are owner decision OD-8.8 (not built). No step-up (NONE). Administrative writes never touch the actor\'s own employment (G-4).',
        ],
        // HR.12 (owner decision HR-061, 2026-11-04; ADR 0028 HR.12 amendment): HR records, ANOTHER person decides.
        'employment_separation_cases' => [
            'domain' => 'HR',
            'tables' => ['employment_separation_cases', 'separation_clearance_items'],
            'request' => ['hr.separations.manage'],
            'decide' => ['hr.separations.approve'],
            'participants' => ['employment_separation_cases.requested_by_user_id'],
            'decider' => 'employment_separation_cases.decided_by_user_id',
            'dbBackstop' => 'employment_separation_cases_sod_check',
            'makerChecker' => 'shared',
            'service' => 'App\Domain\HR\Application\Separation\SeparationCaseService',
            'decisions' => ['approve', 'reject'],
            'execution' => 'separate',
            'drift' => 'immutable_terms',
            'freshMfa' => ['approve'],
            'compatibility' => 'as_is',
            // HR.R1-C34: nor an account known to be the recorder, or the separating Employee through an uncorrected link.
            'knownSamePerson' => ['check' => 'App\Domain\HR\Application\Separation\SeparationCaseService::assertMayDecide', 'dbGuard' => 'trg_employment_separation_cases_guard',
                // WO-18 (C34-N3): every separation write, person-level ("never one's own", OD-HR-25 amended for HR).
                'ownCaseCheck' => 'App\Domain\HR\Application\Separation\SeparationCaseService::lockEmployment', 'ownCaseGuards' => ['trg_employment_separation_cases_guard', 'trg_separation_clearance_items_guard']],
            'note' => 'Approval moves the employment to notice_period; finalization (EmploymentService::end()) is a separate HR step on or after the planned last day. Terms (employment, reason, notice date, requester) are DB-immutable; nobody records, decides, finalizes or clears their own separation (trg_employment_separation_cases_guard).',
        ],
        'payroll_runs' => [
            'domain' => 'Payroll',
            'tables' => ['payroll_runs', 'payroll_run_calculations'],
            'request' => ['payroll.runs.prepare'],
            // OD-HR-1 (ADR 0034 amendment): approving needs sight of the pay it approves.
            'decide' => ['payroll.runs.approve', 'payroll.compensation.sensitive.view'],
            // ORG.8B (§60): every material preparer -- creator, every recorded calculator, every adjustment author;
            // OD-HR-2: and the recorded author of every compensation change the run rests on.
            'participants' => ['payroll_runs.prepared_by_user_id', 'payroll_run_calculations.calculated_by_user_id', 'payroll_adjustments.actor_user_id', 'employee_compensation_assignments.assigned_by_user_id'],
            'decider' => 'payroll_runs.approved_by_user_id',
            'otherDecisionColumns' => ['payroll_runs.posted_by_user_id'],
            // The evidence binding is payroll_runs_approval_evidence_guard (approved_calculation_id, OD-HR-1).
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
            // HR.R1-C34: nor an account known to be the same person as any participant, under the compensation lock.
            // WO-17 (C34-N1): the self-beneficiary refusal matches a known-same account too.
            'knownSamePerson' => ['check' => 'App\Domain\Payroll\Application\PayrollRunService::assertMayDecide', 'dbGuard' => 'payroll_runs_participant_approval_guard',
                'beneficiaryCheck' => 'App\Domain\Payroll\Application\PayrollSelfBeneficiaries::of', 'beneficiaryGuard' => 'trg_payroll_runs_self_beneficiary'],
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
            'knownSamePerson' => ['handoff' => 'C33-H5'],
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
     * enforces (service + `dbGuard`); `entryPoints` are the only callers;
     * `writeMethods` (WO-19) are the class's own public write methods, each
     * of which takes the proof and re-reads authority.
     *
     * @var array<class-string, array{capability: string, action: string, freshAuthority: true, freshMfa: true, reason: string, evidence: string, relationshipConflict: string|null, dbGuard?: array{trigger: string, refusal: string}, accountingOwner?: string, entryPoints?: list<string>, writeMethods?: list<string>}>
     */
    public const DIRECT_ASSURED = [
        // HR.R1-C1 (S-5, S-13): posting an approved payroll run (and reversing its posting, payroll.runs.reverse)
        // is a direct action, not a request/decide workflow: current capability re-read under the run lock, the
        // actor's own fresh-MFA proof, the posting row as evidence.
        'App\Domain\Payroll\Application\PayrollPostingService' => [
            'capability' => 'payroll.runs.post', 'action' => 'Payroll run posting (and its reversal, payroll.runs.reverse)',
            'freshAuthority' => true, 'freshMfa' => true, 'reason' => 'none for posting; an optional bounded note for a reversal (existing contract)',
            'evidence' => 'payroll_run_postings (append-only) + payroll_posting_attestations when a reversal took the approval\'s independence (HR.R1-C4) + school_audit_events payroll.run.posted / payroll.run.posting_reversed', 'relationshipConflict' => null,
            // HR.R1-C34: the attestor is never known to be the same person as the approver or a maker.
            'knownSamePerson' => ['check' => 'App\Domain\Payroll\Application\PayrollPostingIndependence::assertMayAttest', 'dbGuard' => 'trg_payroll_posting_attestations_guard',
                'beneficiaryCheck' => 'App\Domain\Payroll\Application\PayrollSelfBeneficiaries::of', 'beneficiaryGuard' => 'trg_payroll_posting_attestations_self_beneficiary'],
        ],
        // HR.R1-C6 (PAY-18, OD-HR-23): closing an OPEN obligation is a direct review, never a second approval.
        'App\Domain\Payroll\Application\PayrollReversalReconciliationService' => [
            'capability' => 'payroll.runs.approve', 'action' => 'Independent review of a post-posting reversal (with current payroll.compensation.sensitive.view)',
            'freshAuthority' => true, 'freshMfa' => true, 'reason' => 'a closed statement code; no free text',
            'evidence' => 'payroll_reversal_reconciliation_reviews (append-only) + school_audit_events payroll.run.reconciliation_reviewed',
            // Never the run's makers or approver, whoever executed the reversal, or the Employee of a change it pays first.
            'relationshipConflict' => 'payroll_run_independence',
            'dbGuard' => ['trigger' => 'trg_payroll_reversal_reconciliation_reviews_guard', 'refusal' => 'a reviewer is independent of the run, its reversal and its first-paid changes'],
            // HR.R1-C34: nor known to be the same person as the approver, the reverser, a maker or an author.
            'knownSamePerson' => ['check' => 'App\Domain\Payroll\Application\PayrollReversalReconciliationService::conflict', 'dbGuard' => 'trg_payroll_reversal_reconciliation_reviews_guard',
                'beneficiaryCheck' => 'App\Domain\Payroll\Application\PayrollSelfBeneficiaries::ofObligation', 'beneficiaryGuard' => 'trg_payroll_reversal_reconciliation_reviews_guard'],
        ],
        // ORG.12C (ADR 0080 §8): legal name, registration and affiliation references.
        'App\Domain\Schools\Application\SchoolIdentityService' => [
            'capability' => 'school.profile.manage', 'action' => 'School institutional identity change',
            'freshAuthority' => true, 'freshMfa' => true, 'reason' => 'SchoolIdentityChangeReason (closed)',
            'evidence' => 'school_audit_events school.institutional_identity_changed (changed fields, reason, MFA time; never values)', 'relationshipConflict' => null,
        ],
        // HR.12 (owner decision 2026-11-04): the one direct employment end left -- a death.
        'App\Domain\HR\Application\EmployeeLifecycleService' => [
            'capability' => 'hr.employees.assignments.manage', 'action' => 'Employment end recorded as deceased (every other separation is an approved case)',
            'freshAuthority' => true, 'freshMfa' => true, 'reason' => 'the status itself (deceased only; closed)',
            'evidence' => 'school_audit_events hr.employment.ended (status deceased) + employment_records.status', 'relationshipConflict' => null,
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
        // HR.R1-C22 (WO-5, D6-5): a past-dated staff calendar change is a direct correction, never a request.
        'App\Domain\Leave\Application\StaffCalendarService' => [
            'capability' => 'hr.leave.configure', 'action' => 'Past-dated staff calendar correction (a week version or holiday dated before the School\'s today, or the withdrawal of a holiday in effect); today and later stay ordinary',
            'freshAuthority' => true, 'freshMfa' => true, 'reason' => 'CalendarChangeReason (closed: late declaration, entered in error, administrative review; no free text)',
            'evidence' => 'staff_working_week_versions.reason_code / staff_holidays.recorded_reason_code / withdrawn_reason_code (DB-required for a past date: staff_calendar_reason_required) + school_audit_events leave.calendar.* (mfa: fresh)', 'relationshipConflict' => null,
        ],
        // HR.R1-C35 WO-19 (D-4, D-C35-3; S-7): Leave entitlement writes. Allocation and the annual allocation run
        // (and, since HR.10, adjustment and opening) take the actor's own fresh-MFA proof IN THE SERVICE; each
        // re-reads current hr.leave.manage inside its write transaction. Consumption and reversal keep the leave
        // decision's authority; accrual and the year close re-read authority only (FRESH_AUTHORITY_WRITES).
        'App\Domain\Leave\Application\LeaveLedgerService' => [
            'capability' => 'hr.leave.manage', 'action' => 'Leave allocation, annual allocation run, adjustment and opening balance (allocate, executeRun, adjust, recordOpening)',
            'freshAuthority' => true, 'freshMfa' => true, 'reason' => 'none for an allocation, a run or an opening (the units are the decision); an adjustment carries a closed adjustment reason code (LeaveLedgerEntry::ADJUSTMENT_REASONS)',
            'evidence' => 'leave_ledger_entries (append-only; one allocation per account and year, funding exclusivity, database-enforced) + leave_allocation_runs + school_audit_events leave.allocation.granted / leave.allocation_run.executed / leave.adjustment.recorded / leave.opening.recorded (freshMfa: true)', 'relationshipConflict' => null,
            'writeMethods' => ['allocate', 'executeRun', 'adjust', 'recordOpening'],
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
     * HR.R1-C35 WO-19 (D-4, owner decision D-C35-3: "a fresh authority read on
     * every Leave ledger and accrual write entry point"): direct writes that
     * re-read the actor's CURRENT authority (`ApprovalDecisionAuthority::
     * requireCurrent()`) inside their own write transaction, holding the
     * membership and grants FOR SHARE to commit, but take NO fresh-MFA step-up
     * -- the owner decided MFA for Leave allocation and allocation runs only
     * (DIRECT_ASSURED). Never workflows: no participants, maker/checker or
     * provenance. `writeMethods` are the public methods that open such a write
     * transaction; each re-reads (pinned by ApprovalWorkflowRegistryTest).
     *
     * @var array<class-string, array{capability: string, action: string, freshAuthority: true, freshMfa: false, writeMethods: list<string>, reason: string}>
     */
    public const FRESH_AUTHORITY_WRITES = [
        'App\Domain\Leave\Application\Accrual\LeaveAccrualService' => [
            'capability' => 'hr.leave.manage', 'action' => 'Monthly leave accrual: a run opened or resumed, and every batch that credits the ledger',
            'freshAuthority' => true, 'freshMfa' => false, 'writeMethods' => ['start', 'processBatch'],
            'reason' => 'D-C35-3 decided a fresh authority read for accrual, not a step-up; units are the assigned policy version\'s, nobody chooses an amount',
        ],
        'App\Domain\Leave\Application\LeaveYearCloseService' => [
            'capability' => 'hr.leave.manage', 'action' => 'Leave year close (carry-forward and expiry movements)',
            'freshAuthority' => true, 'freshMfa' => false, 'writeMethods' => ['execute'],
            'reason' => 'D-C35-3 covers every ledger write entry point; no MFA decision exists for the close (D-4 named allocation and runs). A reconciliation inside a cancellation keeps that decision\'s authority',
        ],
    ];

    /**
     * Decision-shaped columns or capabilities that are NOT approval workflows,
     * with the reason. Anything else decision-shaped must be a workflow.
     */
    public const NOT_WORKFLOWS = [
        // HR.R1-C29 (C14-N5): one person verifies or rejects one revision directly; no request, no second decider (C29-N1).
        'employee_credential_verifications.decided_by_user_id' => 'who verified or rejected a revision of a qualification or certification: a direct HR attestation under hr.employees.qualifications.manage, never a request decided by a second person',
        // HR.R1-C6 (OD-HR-23): declared in DIRECT_ASSURED. Nothing is requested, and nothing executes because of it.
        'payroll_reversal_reconciliation_reviews.reviewed_by_user_id' => 'who independently reviewed a post-posting reversal (an evidence statement that changes no run, never a request decided by a second person)',
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
