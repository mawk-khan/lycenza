<?php

namespace Tests\Feature\StaffAttendance;

use App\Domain\Leave\Application\Calendar\CalendarChangeReason;
use App\Domain\Leave\Application\DayPortion;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\StaffCalendarService;
use App\Domain\StaffAttendance\Application\Payroll\PayrollAbsenceEvidence;
use App\Domain\StaffAttendance\Application\Payroll\PayrollAbsenceEvidenceReader;
use App\Domain\StaffAttendance\Application\StaffAttendanceReadService;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * HR.R1-C22 (WO-5, D-6, `docs/hr/HR-R1-C21-D6-DECISION.md` §6.7): every consumer reads the calendar in force on each
 * date, and a date before the history start is "not recorded" everywhere -- never a working, off or holiday day.
 * Leave refuses to count it, Staff Attendance refuses new evidence on it and labels it, and Payroll's capture is
 * incomplete for it. Recorded evidence and approved leave are never rewritten.
 */
class StaffCalendarConsumersTest extends TestCase
{
    use Concerns\CreatesStaffAttendanceFixtures;

    /** @var array<string, mixed> */
    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 10:00:00');
        $this->w = $this->leaveWorld();
        $this->workingWeek($this->w['school'], $this->w['admin'], [1 => 'full', 2 => 'full', 3 => 'full', 4 => 'full', 5 => 'full', 6 => 'off', 7 => 'off'], '2026-09-15');
        $this->allocate($this->w);
        $this->w['clerk'] = $this->attendanceAdmin($this->w['school']);
    }

    private function code(callable $call): string
    {
        try {
            $call();
        } catch (LeaveException $e) {
            return $e->errorCode();
        } catch (Throwable $e) {
            return class_basename($e);
        }
        $this->fail('expected a refusal');
    }

    #[Test]
    public function leave_refuses_a_request_that_touches_an_unrecorded_day_and_writes_nothing(): void
    {
        $ledger = fn () => $this->inSchool($this->w['school'], fn () => DB::table('leave_ledger_entries')->count());
        $before = $ledger();

        $this->assertSame('LEAVE_CALENDAR_NOT_RECORDED', $this->code(fn () => $this->submitLeave($this->w, '2026-09-11', '2026-09-15')));
        $this->assertSame([$before, 0], [$ledger(), $this->inSchool($this->w['school'], fn () => DB::table('leave_requests')->count())]);
        $this->assertSame('submitted', $this->submitLeave($this->w, '2026-09-15', '2026-09-16')->status, 'from the history start it counts as before');
    }

    #[Test]
    public function staff_attendance_refuses_new_evidence_on_an_unrecorded_day_and_labels_it(): void
    {
        $service = app(StaffAttendanceService::class);
        $this->assertSame('LEAVE_CALENDAR_NOT_RECORDED', $this->code(fn () => $service->record($this->w['school'], $this->w['employment']->id, '2026-09-14', 'present', 'present', $this->w['clerk'])));
        $service->record($this->w['school'], $this->w['employment']->id, '2026-09-15', 'present', 'present', $this->w['clerk']);

        $register = app(StaffAttendanceReadService::class)->register($this->w['school'], '2026-09-14', $this->w['clerk']);
        $this->assertSame('calendar_not_recorded', $register['rows'][0]['day']['firstHalf']['state']);
        $month = app(StaffAttendanceReadService::class)->monthSummary($this->w['school'], '2026-09', $this->w['clerk']);
        $row = $month['rows'][0]['halfDays'];
        $this->assertSame(28, $row['calendar_not_recorded'], '1-14 September, two halves a day, never off or working');
        $this->assertSame(2, $row['present'], 'the evidence recorded on the 15th');
    }

    #[Test]
    public function payroll_capture_is_incomplete_for_unrecorded_days_and_detects_a_correction(): void
    {
        $reader = app(PayrollAbsenceEvidenceReader::class);
        $september = $reader->read($this->w['school'], $this->w['employment']->id, '2026-09-01', '2026-09-30');
        $this->assertSame(PayrollAbsenceEvidence::INPUT_INCOMPLETE, $september->completeness);
        $this->assertContains('calendar_not_recorded', $september->incompleteReasons);
        $this->assertNull($september->requiredWorkingHalfUnits, 'unknown, never zero');
        $classes = array_count_values(array_column($september->halves, 'class'));
        $this->assertSame(28, $classes['calendar_not_recorded']);
        $this->assertNotNull(collect($september->halves)->firstWhere('date', '2026-09-15')['calendarVersionId'], 'the version each day was read from');

        $october = $reader->read($this->w['school'], $this->w['employment']->id, '2026-10-01', '2026-10-31');
        $this->assertNotContains('calendar_not_recorded', $october->incompleteReasons);
        $again = $reader->read($this->w['school'], $this->w['employment']->id, '2026-10-01', '2026-10-31');
        $this->assertSame($october->fingerprint, $again->fingerprint, 'stable while nothing changes');

        app(StaffCalendarService::class)->addHoliday($this->w['school'], '2026-10-14', DayPortion::Full, 'Declared late', $this->w['admin'], CalendarChangeReason::LateDeclaration, $this->freshMfaProofFor($this->w['admin']));
        $this->assertNotSame($october->fingerprint, $reader->read($this->w['school'], $this->w['employment']->id, '2026-10-01', '2026-10-31')->fingerprint, 'a correction that changes an answer is detected');
    }
}
