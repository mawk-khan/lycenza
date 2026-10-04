<?php

namespace Tests\Feature\Payroll\Hrx\Concerns;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Infrastructure\LeaveRequest;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Domain\StaffAttendance\Application\Payroll\PayrollAbsenceEvidence;
use App\Domain\StaffAttendance\Application\Payroll\PayrollAbsenceEvidenceReader;
use Tests\Feature\StaffSelfService\Concerns\CreatesSelfServiceFixtures;

/**
 * HRX.5: an HRX world (leave, attendance, staff calendar: Mon-Fri full,
 * Saturday morning only, Sunday off) with an unpaid leave type, plus the
 * real Payroll builder. September 2026 has 22 weekdays and 4 Saturday
 * mornings: 48 required working half-days.
 */
trait CreatesPayrollHrxFixtures
{
    use CreatesSelfServiceFixtures;

    public const SEPTEMBER_WORKING_HALVES = 48;

    /** @return array<string, mixed> attendanceWorld() + 'unpaid' (an untracked unpaid leave type) */
    protected function hrxWorld(): array
    {
        $w = $this->attendanceWorld();
        $w['unpaid'] = $this->leaveType($w['school'], $w['admin'], 'LWP', paid: false, tracked: false);

        return $w;
    }

    protected function evidence(array $w, ?EmploymentRecord $employment = null, string $from = '2026-09-01', string $to = '2026-09-30'): PayrollAbsenceEvidence
    {
        return app(PayrollAbsenceEvidenceReader::class)->read($w['school'], ($employment ?? $w['employment'])->id, $from, $to);
    }

    protected function approvedLeaveOf(array $w, string $from, string $to, ?LeaveType $type = null, string $start = 'full', ?string $end = null, ?EmploymentRecord $employment = null): LeaveRequest
    {
        $request = $this->submitLeave($w, $from, $to, $start, $end, $employment, $type);

        return app(LeaveRequestService::class)->approve($w['school'], $request->id, $w['admin']);
    }
}
