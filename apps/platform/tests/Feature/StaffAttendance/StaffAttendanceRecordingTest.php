<?php

namespace Tests\Feature\StaffAttendance;

use App\Domain\Leave\Application\DayPortion;
use App\Domain\Leave\Application\StaffCalendarService;
use App\Domain\StaffAttendance\Application\Exceptions\StaffAttendanceException;
use App\Domain\StaffAttendance\Application\StaffAttendanceReadService;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;
use Tests\TestCase;

/**
 * HRX.3 (ADR 0065 §24.2, §24.3, §24.7): initial recording, the bulk daily
 * register and the composed read model. The clock is Monday 2026-10-05; the
 * staff week is Mon-Fri full, Saturday morning only, Sunday off.
 */
class StaffAttendanceRecordingTest extends TestCase
{
    use CreatesStaffAttendanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    private function refusal(callable $command): string
    {
        try {
            $command();
        } catch (StaffAttendanceException $e) {
            return $e->errorCode();
        }

        return 'accepted';
    }

    private function rowsIn(array $w, string $table = 'staff_attendance_records'): int
    {
        return $this->inSchool($w['school'], fn () => DB::table($table)->where('school_id', $w['school']->id)->count());
    }

    #[Test]
    public function each_half_is_stored_exactly_and_the_daily_summary_is_derived(): void
    {
        $w = $this->attendanceWorld();
        $reads = app(StaffAttendanceReadService::class);
        $others = [$this->currentEmployment($w['school']), $this->currentEmployment($w['school']), $this->currentEmployment($w['school'])];

        $present = $this->recordAttendance($w, '2026-09-28', 'present', 'present');
        $this->recordAttendance($w, '2026-09-28', 'absent', 'absent', $others[0]);
        $this->recordAttendance($w, '2026-09-28', 'present', 'absent', $others[1]);
        $this->recordAttendance($w, '2026-09-28', 'absent', null, $others[2]);

        $this->assertSame(['present', 'present', 1], [$present->first_half_status, $present->second_half_status, $present->version]);
        $this->assertSame($w['employment']->employee_id, $present->employee_id, 'the Employee is derived from the EmploymentRecord by the database');

        $register = collect($reads->register($w['school'], '2026-09-28', $w['clerk'])['rows'])->keyBy('employment.employmentRecordId');
        $this->assertSame('present', $register[$w['employment']->id]['day']['summary']);
        $this->assertSame('absent', $register[$others[0]->id]['day']['summary']);
        $this->assertSame('half_day_absent', $register[$others[1]->id]['day']['summary']);
        $this->assertSame('partially_recorded', $register[$others[2]->id]['day']['summary']);
        $this->assertSame(['state' => 'unrecorded', 'recorded' => null, 'calendar' => 'working', 'onLeave' => false, 'leave' => null], $register[$others[2]->id]['day']['secondHalf']);
    }

    #[Test]
    public function a_saturday_morning_is_a_single_working_half_and_the_rest_of_the_week_is_off(): void
    {
        $w = $this->attendanceWorld();

        $saturday = $this->recordAttendance($w, '2026-10-03', 'present', null);
        $this->assertNull($saturday->second_half_status);
        $day = app(StaffAttendanceReadService::class)->register($w['school'], '2026-10-03', $w['clerk'])['rows'][0]['day'];
        $this->assertSame(['present', 'off_day', 'present'], [$day['firstHalf']['state'], $day['secondHalf']['state'], $day['summary']], 'an off half is neutral in the summary');

        $other = $this->currentEmployment($w['school']);
        $this->assertSame('STAFF_ATTENDANCE_NOT_WORKING_TIME', $this->refusal(fn () => $this->recordAttendance($w, '2026-10-03', 'present', 'present', $other)));
        $this->assertSame('STAFF_ATTENDANCE_NOT_WORKING_TIME', $this->refusal(fn () => $this->recordAttendance($w, '2026-10-04', 'absent', null, $other)), 'Sunday is off');
        $this->assertSame('off_day', app(StaffAttendanceReadService::class)->register($w['school'], '2026-10-04', $w['clerk'])['rows'][0]['day']['summary']);
    }

    #[Test]
    public function a_holiday_half_is_refused_and_the_other_half_stays_recordable(): void
    {
        $w = $this->attendanceWorld();
        app(StaffCalendarService::class)->addHoliday($w['school'], '2026-09-30', DayPortion::FirstHalf, 'Founders morning', $w['admin']);

        $this->assertSame('STAFF_ATTENDANCE_NOT_WORKING_TIME', $this->refusal(fn () => $this->recordAttendance($w, '2026-09-30', 'present', 'present')));
        $record = $this->recordAttendance($w, '2026-09-30', null, 'absent');

        $day = app(StaffAttendanceReadService::class)->register($w['school'], '2026-09-30', $w['clerk'])['rows'][0]['day'];
        $this->assertSame(['holiday', 'absent', 'absent'], [$day['firstHalf']['state'], $day['secondHalf']['state'], $day['summary']]);
        $this->assertSame($record->id, $day['record']['id']);
    }

    #[Test]
    public function only_past_or_present_dates_with_evidence_of_an_employment_in_force_are_recorded(): void
    {
        $w = $this->attendanceWorld();

        $this->assertSame('STAFF_ATTENDANCE_DATE_IN_FUTURE', $this->refusal(fn () => $this->recordAttendance($w, '2026-10-06', 'present', 'present')));
        $this->assertSame('accepted', $this->refusal(fn () => $this->recordAttendance($w, '2026-10-05', 'present', 'present')), 'today is recordable');
        $this->assertSame('STAFF_ATTENDANCE_EMPTY', $this->refusal(fn () => $this->recordAttendance($w, '2026-09-29', null, null)));
        $this->assertSame('STAFF_ATTENDANCE_STATUS_INVALID', $this->refusal(fn () => $this->recordAttendance($w, '2026-09-29', 'leave', null)), 'leave is never a stored attendance value');
        $this->assertSame('STAFF_ATTENDANCE_DATE_INVALID', $this->refusal(fn () => $this->recordAttendance($w, '2026-02-30', 'present', null)));

        $this->assertSame('STAFF_ATTENDANCE_EMPLOYMENT_NOT_ELIGIBLE', $this->refusal(fn () => $this->recordAttendance($w, '2023-12-29', 'present', 'present')), 'before the employment started');
        $separated = $this->createEmploymentRecord($this->createEmployee($w['school']), ['status' => 'separated', 'starts_on' => '2024-01-01', 'ends_on' => '2026-09-30']);
        $this->assertSame('STAFF_ATTENDANCE_EMPLOYMENT_NOT_ELIGIBLE', $this->refusal(fn () => $this->recordAttendance($w, '2026-09-29', 'present', 'present', $separated)), 'initial recording needs an employment in force');

        $foreign = $this->attendanceWorld();
        $this->expectException(ModelNotFoundException::class);
        $this->recordAttendance($w, '2026-09-29', 'present', 'present', $foreign['employment']);
    }

    #[Test]
    public function a_second_record_for_the_same_employment_and_date_is_refused_in_favour_of_a_correction(): void
    {
        $w = $this->attendanceWorld();
        $this->recordAttendance($w, '2026-09-29', null, 'present');

        $this->assertSame('STAFF_ATTENDANCE_ALREADY_RECORDED', $this->refusal(fn () => $this->recordAttendance($w, '2026-09-29', 'present', null)), 'the empty first half is filled by correction, not a new record');
        $this->assertSame(1, $this->rowsIn($w));
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('school_audit_events')->where('school_id', $w['school']->id)->where('event_type', 'staff_attendance.recorded')->count()));
    }

    #[Test]
    public function the_bulk_register_records_many_employments_at_once_all_or_nothing(): void
    {
        $w = $this->attendanceWorld();
        $service = app(StaffAttendanceService::class);
        $b = $this->currentEmployment($w['school']);
        $c = $this->currentEmployment($w['school']);
        $item = fn ($e, ?string $first, ?string $second) => ['employment_record_id' => $e->id, 'first_half' => $first, 'second_half' => $second];

        $records = $service->recordRegister($w['school'], '2026-09-28', [$item($w['employment'], 'present', 'present'), $item($b, 'absent', 'absent'), $item($c, 'present', 'absent')], $w['clerk']);
        $this->assertCount(3, $records);
        $this->assertSame(3, $this->rowsIn($w));
        $events = $this->inSchool($w['school'], fn () => DB::table('school_audit_events')->where('school_id', $w['school']->id)->where('event_type', 'like', 'staff_attendance.%')->pluck('event_type')->countBy()->sortKeys()->all());
        $this->assertSame(['staff_attendance.bulk_recorded' => 1, 'staff_attendance.recorded' => 3], $events);

        // One invalid item -- a duplicate, an existing record, an empty item, a non-working half -- refuses the whole register.
        $d = $this->currentEmployment($w['school']);
        $this->assertSame('STAFF_ATTENDANCE_DUPLICATE_ITEM', $this->refusal(fn () => $service->recordRegister($w['school'], '2026-09-29', [$item($d, 'present', 'present'), $item($d, 'absent', 'absent')], $w['clerk'])));
        $this->assertSame('STAFF_ATTENDANCE_ALREADY_RECORDED', $this->refusal(fn () => $service->recordRegister($w['school'], '2026-09-28', [$item($d, 'present', 'present'), $item($b, 'present', 'present')], $w['clerk'])), 'bulk is never an implicit correction');
        $this->assertSame('STAFF_ATTENDANCE_EMPTY', $this->refusal(fn () => $service->recordRegister($w['school'], '2026-09-29', [$item($d, 'present', 'present'), $item($b, null, null)], $w['clerk'])));
        $this->assertSame('STAFF_ATTENDANCE_NOT_WORKING_TIME', $this->refusal(fn () => $service->recordRegister($w['school'], '2026-10-03', [$item($d, 'present', null), $item($b, 'present', 'present')], $w['clerk'])));
        $this->assertSame('STAFF_ATTENDANCE_REGISTER_SIZE', $this->refusal(fn () => $service->recordRegister($w['school'], '2026-09-29', [], $w['clerk'])));
        $this->assertSame(3, $this->rowsIn($w), 'no refused register wrote anything');
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('staff_attendance_records')->where('employment_record_id', $d->id)->count()));
    }

    #[Test]
    public function the_register_lists_the_schools_employments_on_the_date_with_minimal_labels(): void
    {
        $w = $this->attendanceWorld();
        $later = $this->currentEmployment($w['school'], '2026-10-01');
        $this->createEmploymentRecord($this->createEmployee($w['school']), ['status' => 'draft', 'starts_on' => '2024-01-01', 'ends_on' => null]);
        $this->recordAttendance($w, '2026-09-29', 'present', 'present');

        $register = app(StaffAttendanceReadService::class)->register($w['school'], '2026-09-29', $w['clerk']);
        $this->assertTrue($register['calendarConfigured']);
        $this->assertSame(['firstHalf' => 'working', 'secondHalf' => 'working'], $register['calendar']);
        $ids = array_column(array_column($register['rows'], 'employment'), 'employmentRecordId');
        $this->assertSame([$w['employment']->id], $ids, 'a draft and a not-yet-started employment are not on the register');
        $this->assertNotContains($later->id, $ids);
        $this->assertSame(['employmentRecordId', 'employeeId', 'employeeNumber', 'fullName', 'status', 'recordable'], array_keys($register['rows'][0]['employment']), 'directory-tier labels only');
    }
}
