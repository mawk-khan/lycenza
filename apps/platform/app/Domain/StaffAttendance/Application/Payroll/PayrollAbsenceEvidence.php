<?php

namespace App\Domain\StaffAttendance\Application\Payroll;

/**
 * HRX.5 (ADR 0065 §26.2-§26.7): HRX absence EVIDENCE for one EmploymentRecord
 * over one payroll period -- facts in exact integer half-day units, one
 * effective class per half (HRX.3 precedence), a completeness state and a
 * deterministic fingerprint. It is not a payroll decision: it says nothing is
 * non-payable, deductible or NCP (HRX-L4 is open). Produced only by
 * PayrollAbsenceEvidenceReader.
 *
 * `halves` holds, per half inside coverage: `date`, `half` (1|2), `class`
 * (leave_paid|leave_unpaid|present|absent|holiday|off_day|unrecorded|
 * calendar_unknown), `working` (today's calendar; null when unconfigured)
 * and the source facts (`leaveRequestId`, `isPaid`, `staffAttendanceRecordId`,
 * `version`, `recorded`), null where absent. No reason, no health detail.
 */
final readonly class PayrollAbsenceEvidence
{
    public const COMPLETE = 'complete';

    public const INPUT_INCOMPLETE = 'input_incomplete';

    /**
     * @param  list<string>  $incompleteReasons
     * @param  list<array{date: string, half: int, class: string, working: ?bool, leaveRequestId: ?string, isPaid: ?bool, staffAttendanceRecordId: ?string, version: ?int, recorded: ?string}>  $halves
     */
    public function __construct(
        public string $contractVersion,
        public string $employmentRecordId,
        public string $periodStartsOn,
        public string $periodEndsOn,
        public ?string $coveredFrom,
        public ?string $coveredTo,
        public bool $calendarConfigured,
        public ?int $requiredWorkingHalfUnits,
        public int $approvedPaidLeaveHalfUnits,
        public int $approvedUnpaidLeaveHalfUnits,
        public int $recordedAbsenceHalfUnits,
        public int $recordedPresenceHalfUnits,
        public int $unresolvedWorkingHalfUnits,
        public string $completeness,
        public array $incompleteReasons,
        public array $halves,
        public string $fingerprint,
    ) {}

    /** @return array{requiredWorkingHalfUnits: ?int, approvedPaidLeaveHalfUnits: int, approvedUnpaidLeaveHalfUnits: int, recordedAbsenceHalfUnits: int, recordedPresenceHalfUnits: int, unresolvedWorkingHalfUnits: int} */
    public function units(): array
    {
        return [
            'requiredWorkingHalfUnits' => $this->requiredWorkingHalfUnits,
            'approvedPaidLeaveHalfUnits' => $this->approvedPaidLeaveHalfUnits,
            'approvedUnpaidLeaveHalfUnits' => $this->approvedUnpaidLeaveHalfUnits,
            'recordedAbsenceHalfUnits' => $this->recordedAbsenceHalfUnits,
            'recordedPresenceHalfUnits' => $this->recordedPresenceHalfUnits,
            'unresolvedWorkingHalfUnits' => $this->unresolvedWorkingHalfUnits,
        ];
    }
}
