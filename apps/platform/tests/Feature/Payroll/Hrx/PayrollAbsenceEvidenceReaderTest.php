<?php

namespace Tests\Feature\Payroll\Hrx;

use App\Domain\Leave\Application\DayPortion;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Application\StaffCalendarService;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Domain\StaffAttendance\Application\Payroll\PayrollAbsenceEvidence;
use App\Domain\StaffAttendance\Application\Payroll\PayrollAbsenceEvidenceReader;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Payroll\Hrx\Concerns\CreatesPayrollHrxFixtures;
use Tests\TestCase;

/**
 * HRX.5 (ADR 0065 §26.3-§26.7): the HRX -> Payroll evidence contract --
 * one effective class per half (HRX.3 precedence), exact integer half-day
 * units, coverage clipped to the EmploymentRecord, completeness, and a
 * deterministic fingerprint. Evidence only: nothing is labelled
 * non-payable, deductible or NCP.
 */
class PayrollAbsenceEvidenceReaderTest extends TestCase
{
    use CreatesPayrollHrxFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    #[Test]
    public function an_untouched_month_is_all_required_working_time_and_unrecorded_never_counts_as_absence(): void
    {
        $w = $this->hrxWorld();
        $e = $this->evidence($w);

        $this->assertSame(PayrollAbsenceEvidenceReader::CONTRACT_VERSION, $e->contractVersion);
        $this->assertSame(['2026-09-01', '2026-09-30'], [$e->coveredFrom, $e->coveredTo]);
        $this->assertSame([
            'requiredWorkingHalfUnits' => self::SEPTEMBER_WORKING_HALVES, 'approvedPaidLeaveHalfUnits' => 0, 'approvedUnpaidLeaveHalfUnits' => 0,
            'recordedAbsenceHalfUnits' => 0, 'recordedPresenceHalfUnits' => 0, 'unresolvedWorkingHalfUnits' => self::SEPTEMBER_WORKING_HALVES,
        ], $e->units(), 'holidays and off-days are not absence; unrecorded is neither present nor absent');
        $this->assertSame([PayrollAbsenceEvidence::INPUT_INCOMPLETE, ['unrecorded_working_time']], [$e->completeness, $e->incompleteReasons]);
        $this->assertCount(60, $e->halves, 'every half of every covered date');
        $classes = collect($e->halves)->countBy('class')->all();
        $this->assertSame(['unrecorded' => 48, 'off_day' => 12], $classes, 'Saturday afternoons (4) and Sundays (8 halves) are off');
    }

    #[Test]
    public function paid_unpaid_leave_absence_and_presence_are_separate_facts_counted_once_per_half(): void
    {
        $w = $this->hrxWorld();
        $this->approvedLeaveOf($w, '2026-09-07', '2026-09-07');                                    // paid, full: 2
        $this->approvedLeaveOf($w, '2026-09-08', '2026-09-08', $w['unpaid'], 'first_half');        // unpaid, first half: 1
        $this->recordAttendance($w, '2026-09-08', null, 'present');                                // the other half present
        $this->recordAttendance($w, '2026-09-09', 'absent', 'absent');                             // absence: 2
        $this->recordAttendance($w, '2026-09-10', 'absent', 'absent');
        $this->approvedLeaveOf($w, '2026-09-10', '2026-09-10', $w['unpaid'], 'second_half');       // leave over a recorded absence
        $this->recordAttendance($w, '2026-09-12', 'present', null);                                // Saturday morning present

        $e = $this->evidence($w);
        $this->assertSame([2, 2, 3, 2], [$e->approvedPaidLeaveHalfUnits, $e->approvedUnpaidLeaveHalfUnits, $e->recordedAbsenceHalfUnits, $e->recordedPresenceHalfUnits],
            'the absence under unpaid leave counts once, as leave: 9 Sep (2) + 10 Sep first half (1) = 3 absent');
        $this->assertSame(self::SEPTEMBER_WORKING_HALVES - 9, $e->unresolvedWorkingHalfUnits);

        $underLeave = collect($e->halves)->first(fn ($h) => $h['date'] === '2026-09-10' && $h['half'] === 2);
        $this->assertSame(['leave_unpaid', 'absent', false], [$underLeave['class'], $underLeave['recorded'], $underLeave['isPaid']], 'the underlying absence stays in the source facts');
        $this->assertNotNull($underLeave['staffAttendanceRecordId']);
        $this->assertSame(['leave_paid', true], [collect($e->halves)->first(fn ($h) => $h['date'] === '2026-09-07')['class'], collect($e->halves)->first(fn ($h) => $h['date'] === '2026-09-07')['isPaid']]);
    }

    #[Test]
    public function holidays_and_coverage_are_respected_and_a_period_outside_employment_is_empty(): void
    {
        $w = $this->hrxWorld();
        app(StaffCalendarService::class)->addHoliday($w['school'], '2026-09-14', DayPortion::Full, 'Festival', $w['admin']);
        $this->assertSame(self::SEPTEMBER_WORKING_HALVES - 2, $this->evidence($w)->requiredWorkingHalfUnits, 'a holiday is not required working time');
        $this->assertSame('holiday', collect($this->evidence($w)->halves)->first(fn ($h) => $h['date'] === '2026-09-14')['class']);

        // Joined on 16 Sep, leaving on 25 Sep (notice period): only 16..25 counts.
        $partial = $this->createEmploymentRecord($this->createEmployee($w['school']), ['status' => 'notice_period', 'starts_on' => '2026-09-16', 'ends_on' => '2026-09-25']);
        $this->recordAttendance($w, '2026-09-17', 'absent', 'absent', $partial);
        $e = $this->evidence($w, $partial);
        $this->assertSame(['2026-09-16', '2026-09-25'], [$e->coveredFrom, $e->coveredTo]);
        $this->assertSame(20, count($e->halves));
        $this->assertSame(2, $e->recordedAbsenceHalfUnits);
        $this->assertSame(17, $e->requiredWorkingHalfUnits, '16-18 Wed-Fri (6) + 19 Sat morning (1) + 21-25 Mon-Fri (10)');

        $later = $this->createEmploymentRecord($this->createEmployee($w['school']), ['status' => 'active', 'starts_on' => '2026-11-01', 'ends_on' => null]);
        $none = $this->evidence($w, $later);
        $this->assertSame([null, null, [], PayrollAbsenceEvidence::COMPLETE, 0], [$none->coveredFrom, $none->coveredTo, $none->halves, $none->completeness, $none->requiredWorkingHalfUnits]);
    }

    #[Test]
    public function an_unconfigured_calendar_is_incomplete_evidence_never_a_guess(): void
    {
        $w = $this->leaveWorld();
        $e = $this->evidence($w);

        $this->assertFalse($e->calendarConfigured);
        $this->assertNull($e->requiredWorkingHalfUnits);
        $this->assertSame(['calendar_not_configured'], $e->incompleteReasons);
        $this->assertSame(['calendar_unknown' => 60], collect($e->halves)->countBy('class')->all());
    }

    #[Test]
    public function the_fingerprint_follows_every_evidence_change_and_ignores_presentation(): void
    {
        $w = $this->hrxWorld();
        $prints = [$this->evidence($w)->fingerprint];
        $this->assertSame($prints[0], $this->evidence($w)->fingerprint, 'equal state, equal fingerprint');

        $leave = $this->approvedLeaveOf($w, '2026-09-21', '2026-09-21', $w['unpaid']);
        $prints[] = $this->evidence($w)->fingerprint;                                       // new approved unpaid leave
        app(LeaveRequestService::class)->cancel($w['school'], $leave->id, 'plans_changed', $w['admin']);
        $prints[] = $this->evidence($w)->fingerprint;                                       // cancellation
        $record = $this->recordAttendance($w, '2026-09-22', 'absent', null);
        $prints[] = $this->evidence($w)->fingerprint;                                       // absence added
        $this->correctAttendance($w, $record, 'present', null, 'late_information');
        $prints[] = $this->evidence($w)->fingerprint;                                       // correction
        app(StaffCalendarService::class)->addHoliday($w['school'], '2026-09-29', DayPortion::FirstHalf, 'Half holiday', $w['admin']);
        $prints[] = $this->evidence($w)->fingerprint;                                       // calendar change affecting classification
        foreach ([1, 2, 3, 4, 5] as $i) {
            $this->assertNotSame($prints[$i - 1], $prints[$i], "change {$i} moved the fingerprint");
        }
        $this->assertSame($prints[0], $prints[2], 'cancelling restores exactly the pre-leave evidence, so the pre-leave fingerprint');

        // Presentation never moves it: renaming the leave type and touching the employment's display data.
        $before = $this->evidence($w)->fingerprint;
        $this->inSchool($w['school'], fn () => LeaveType::query()->whereKey($w['unpaid']->id)->update(['name' => 'Renamed']));
        $this->inSchool($w['school'], fn () => DB::table('employees')->where('id', $w['employment']->employee_id)->update(['full_name' => 'Someone Else']));
        $this->assertSame($before, $this->evidence($w)->fingerprint);
    }
}
