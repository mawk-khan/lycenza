<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Application\Calendar\CalendarChangeReason;
use App\Domain\Leave\Application\Calendar\CalendarDay;
use App\Domain\Leave\Application\Calendar\StaffCalendarHistory;
use App\Domain\Leave\Application\DayPortion;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\Policy\LeaveEligibilityCriteria;
use App\Domain\Leave\Application\Policy\LeavePolicyAsOf;
use App\Domain\Leave\Application\StaffCalendarService;
use App\Domain\Leave\Infrastructure\StaffWorkingWeekVersion;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;
use Tests\TestCase;
use Throwable;

/**
 * HR.R1-C22 (WO-5, D-6, `docs/hr/HR-R1-C21-D6-DECISION.md` §6.10): the effective-dated staff calendar.
 * Every answer is the calendar in force on that date; before the history start it is "not recorded"
 * (never today's week); a past-dated change is a correction (a reason and the actor's own fresh MFA
 * proof) that never reaches a locked Staff Attendance month; nothing is overwritten or deleted.
 */
class StaffCalendarHistoryTest extends TestCase
{
    use CreatesStaffAttendanceFixtures;

    private const WEEK = [1 => 'full', 2 => 'full', 3 => 'full', 4 => 'full', 5 => 'full', 6 => 'first_half', 7 => 'off'];

    private const FOUR_DAYS = [1 => 'full', 2 => 'full', 3 => 'full', 4 => 'full', 5 => 'off', 6 => 'off', 7 => 'off'];

    private School $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->admin = $this->leaveAdmin($this->school);
    }

    private function history(): StaffCalendarHistory
    {
        return app(StaffCalendarHistory::class);
    }

    private function calendar(): StaffCalendarService
    {
        return app(StaffCalendarService::class);
    }

    private function today(): string
    {
        return $this->history()->today($this->school);
    }

    private function plus(int $days): string
    {
        return CarbonImmutable::parse($this->today())->addDays($days)->toDateString();
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

    private function proof(?User $user = null): FreshMfaProof
    {
        return $this->freshMfaProofFor($user ?? $this->admin);
    }

    #[Test]
    public function an_unconfigured_school_answers_not_configured_and_refuses_to_count(): void
    {
        $this->assertSame(CalendarDay::NOT_CONFIGURED, $this->history()->day($this->school, '2026-10-05')->status);
        $this->assertSame('LEAVE_CALENDAR_NOT_CONFIGURED', $this->code(fn () => $this->history()->calculator($this->school, '2026-10-01', '2026-10-31')));
        $this->assertNull($this->history()->historyStart($this->school));
    }

    #[Test]
    public function a_school_without_history_starts_today_or_later_never_in_the_past(): void
    {
        // D6-3 (C22 review M-1): no backfill. A first week or holiday on a past date is refused, even as a correction.
        $this->assertSame('LEAVE_CALENDAR_BEFORE_HISTORY', $this->code(fn () => $this->calendar()->recordWeek($this->school, $this->plus(-1), self::WEEK, $this->admin, CalendarChangeReason::LateDeclaration, $this->proof())));
        $this->assertSame('LEAVE_CALENDAR_BEFORE_HISTORY', $this->code(fn () => $this->calendar()->addHoliday($this->school, $this->plus(-1), DayPortion::Full, 'Backdated', $this->admin, CalendarChangeReason::LateDeclaration, $this->proof())));
        $this->assertNull($this->history()->historyStart($this->school), 'nothing was written');

        // A future first version never blocks today's: today and later are never "before the history start".
        $this->calendar()->recordWeek($this->school, $this->plus(20), self::FOUR_DAYS, $this->admin);
        $this->calendar()->recordWeek($this->school, $this->today(), self::WEEK, $this->admin);
        $this->assertSame($this->today(), $this->history()->historyStart($this->school));
        $this->assertSame('LEAVE_CALENDAR_BEFORE_HISTORY', $this->code(fn () => $this->calendar()->recordWeek($this->school, $this->plus(-1), self::WEEK, $this->admin, CalendarChangeReason::LateDeclaration, $this->proof())));
    }

    #[Test]
    public function before_the_history_start_every_date_reads_not_recorded_never_todays_week(): void
    {
        $this->workingWeek($this->school, $this->admin, self::WEEK, '2026-09-15');

        $days = $this->history()->range($this->school, '2026-09-13', '2026-09-16');
        $this->assertSame([CalendarDay::NOT_RECORDED, CalendarDay::NOT_RECORDED, CalendarDay::RECORDED, CalendarDay::RECORDED], array_values(array_map(fn (CalendarDay $d) => $d->status, $days)), 'the day before, then the start');
        $this->assertNull($days['2026-09-14']->weekPortion, 'nothing is guessed for an unrecorded day');
        $this->assertSame('2026-09-15', $this->history()->historyStart($this->school));
        $this->assertSame('LEAVE_CALENDAR_NOT_RECORDED', $this->code(fn () => $this->history()->calculator($this->school, '2026-09-14', '2026-09-16')));
        $this->assertSame(2, $this->history()->calculator($this->school, '2026-09-15', '2026-09-16')->units('2026-09-15', DayPortion::Full));
    }

    #[Test]
    public function each_date_reads_the_version_in_force_on_it_across_a_boundary(): void
    {
        $this->workingWeek($this->school, $this->admin, self::WEEK, '2026-01-01');
        $this->workingWeek($this->school, $this->admin, self::FOUR_DAYS, '2026-03-02');

        // Friday 2026-02-27 (old week), Monday 2026-03-02 (new), Friday 2026-03-06 (new: off).
        $c = $this->history()->calculator($this->school, '2026-02-27', '2026-03-06');
        $this->assertSame([2, 2, 0], [$c->units('2026-02-27', DayPortion::Full), $c->units('2026-03-02', DayPortion::Full), $c->units('2026-03-06', DayPortion::Full)]);
        $this->assertSame(1, $c->units('2026-02-28', DayPortion::Full), 'the last Saturday of the old week, half');
        $days = $this->history()->range($this->school, '2026-03-01', '2026-03-02');
        $this->assertNotSame($days['2026-03-01']->versionId, $days['2026-03-02']->versionId, 'the day before and the day of the change');
    }

    #[Test]
    public function a_leap_day_and_a_year_boundary_read_their_own_versions(): void
    {
        $this->workingWeek($this->school, $this->admin, self::WEEK, '2023-12-29');
        $this->workingWeek($this->school, $this->admin, self::FOUR_DAYS, '2024-01-01');

        $days = $this->history()->range($this->school, '2023-12-31', '2024-03-01');
        $this->assertSame(['off', 'full', 'off'], [$days['2023-12-31']->weekPortion, $days['2024-01-01']->weekPortion, $days['2024-03-01']->weekPortion]);
        $this->assertSame('full', $days['2024-02-29']->weekPortion, 'Thursday 29 February 2024');
    }

    #[Test]
    public function a_scheduled_version_applies_only_from_its_date_and_can_be_withdrawn_until_then(): void
    {
        $this->workingWeek($this->school, $this->admin);
        $future = $this->plus(30);
        $scheduled = $this->calendar()->recordWeek($this->school, $future, self::FOUR_DAYS, $this->admin);
        $this->assertSame(self::WEEK, $this->calendar()->pattern($this->school), 'today still reads the week in force');
        $this->assertSame($scheduled->id, $this->history()->day($this->school, $future)->versionId);

        $this->calendar()->withdrawWeek($this->school, $scheduled->id, $this->admin);
        $this->assertNotSame($scheduled->id, $this->history()->day($this->school, $future)->versionId);
        $this->assertSame(StaffWorkingWeekVersion::WITHDRAWN, $this->inSchool($this->school, fn () => StaffWorkingWeekVersion::query()->find($scheduled->id)->status));

        $inForce = $this->history()->day($this->school, $this->today())->versionId;
        $this->assertSame('LEAVE_CALENDAR_WITHDRAW_EFFECTIVE', $this->code(fn () => $this->calendar()->withdrawWeek($this->school, $inForce, $this->admin)));
    }

    #[Test]
    public function a_past_date_is_a_correction_with_a_reason_and_the_actors_own_fresh_proof(): void
    {
        $this->workingWeek($this->school, $this->admin);
        $other = $this->leaveAdmin($this->school);

        $this->assertSame('LEAVE_CALENDAR_REASON_REQUIRED', $this->code(fn () => $this->calendar()->recordWeek($this->school, '2026-09-01', self::FOUR_DAYS, $this->admin)));
        $this->assertSame('LogicException', $this->code(fn () => $this->calendar()->recordWeek($this->school, '2026-09-01', self::FOUR_DAYS, $this->admin, CalendarChangeReason::EnteredInError)));
        $this->assertSame('LogicException', $this->code(fn () => $this->calendar()->recordWeek($this->school, '2026-09-01', self::FOUR_DAYS, $this->admin, CalendarChangeReason::EnteredInError, $this->proof($other))), 'another user\'s proof');
        $this->assertSame('LEAVE_CALENDAR_REASON_REQUIRED', $this->code(fn () => $this->calendar()->addHoliday($this->school, '2026-09-14', DayPortion::Full, 'Late', $this->admin)));

        $version = $this->calendar()->recordWeek($this->school, '2026-09-01', self::FOUR_DAYS, $this->admin, CalendarChangeReason::EnteredInError, $this->proof());
        $this->assertSame(['entered_in_error', $this->admin->id], [$version->reason_code, $version->recorded_by_user_id], 'provenance on the row');
        $this->assertSame('off', $this->history()->day($this->school, '2026-09-04')->weekPortion, 'the correction changed the past answer, deliberately');
        $this->assertSame(self::WEEK, $this->inSchool($this->school, fn () => StaffWorkingWeekVersion::query()->where('effective_from', self::CALENDAR_HISTORY_START)->sole()->pattern()), 'the earlier version is untouched');
    }

    #[Test]
    public function a_same_date_correction_supersedes_and_nothing_goes_before_the_history_start(): void
    {
        $this->workingWeek($this->school, $this->admin, self::WEEK, '2026-09-01');
        $first = $this->history()->day($this->school, '2026-09-01')->versionId;
        $this->workingWeek($this->school, $this->admin, self::FOUR_DAYS, '2026-09-01');

        $rows = $this->inSchool($this->school, fn () => StaffWorkingWeekVersion::query()->where('effective_from', '2026-09-01')->orderBy('recorded_at')->get());
        $this->assertSame([StaffWorkingWeekVersion::SUPERSEDED, StaffWorkingWeekVersion::ACTIVE], $rows->pluck('status')->all());
        $this->assertSame($first, $rows[1]->supersedes_id);
        $this->assertSame('LEAVE_CALENDAR_BEFORE_HISTORY', $this->code(fn () => $this->workingWeek($this->school, $this->admin, self::WEEK, '2026-08-31')));
        $this->assertSame('LEAVE_CALENDAR_BEFORE_HISTORY', $this->code(fn () => $this->staffHoliday($this->school, '2026-08-20', DayPortion::Full, 'Before', $this->admin)));
    }

    #[Test]
    public function a_holiday_is_withdrawn_never_deleted_and_an_effective_one_only_as_a_correction(): void
    {
        $this->workingWeek($this->school, $this->admin);
        $future = $this->staffHoliday($this->school, $this->plus(20), DayPortion::Full, 'Founders', $this->admin);
        $this->assertSame('LEAVE_HOLIDAY_EXISTS', $this->code(fn () => $this->staffHoliday($this->school, $this->plus(20), DayPortion::Full, 'Again', $this->admin)));
        $this->calendar()->withdrawHoliday($this->school, $future->id, $this->admin);
        $this->assertNull($this->history()->day($this->school, $this->plus(20))->holiday);
        $this->staffHoliday($this->school, $this->plus(20), DayPortion::FirstHalf, 'Rescheduled', $this->admin);
        $this->assertSame('first_half', $this->history()->day($this->school, $this->plus(20))->holiday['portion'], 'a new active holiday after the withdrawal');

        $past = $this->staffHoliday($this->school, '2026-09-14', DayPortion::Full, 'Late', $this->admin);
        $this->assertSame('LEAVE_CALENDAR_REASON_REQUIRED', $this->code(fn () => $this->calendar()->withdrawHoliday($this->school, $past->id, $this->admin)));
        $this->withdrawStaffHoliday($this->school, $past, $this->admin);
        $this->assertSame('LEAVE_HOLIDAY_WITHDRAWN', $this->code(fn () => $this->withdrawStaffHoliday($this->school, $past, $this->admin)));
        $this->assertSame(3, $this->inSchool($this->school, fn () => DB::table('staff_holidays')->count()), 'every holiday is kept');
    }

    #[Test]
    public function a_correction_never_reaches_a_locked_staff_attendance_month(): void
    {
        $this->travelTo('2026-11-05 10:00:00');
        $this->workingWeek($this->school, $this->admin);
        $locker = $this->createUserWithCapabilities($this->school, ['hr.staff_attendance.view', 'hr.staff_attendance.lock']);
        app(StaffAttendanceService::class)->lockMonth($this->school, '2026-09', $locker, $this->proof($locker));

        $this->assertSame('LEAVE_CALENDAR_MONTH_LOCKED', $this->code(fn () => $this->staffHoliday($this->school, '2026-09-14', DayPortion::Full, 'Late', $this->admin)));
        $this->assertSame('LEAVE_CALENDAR_MONTH_LOCKED', $this->code(fn () => $this->workingWeek($this->school, $this->admin, self::FOUR_DAYS, '2026-08-01')), 'a version whose answer runs into September');
        $this->workingWeek($this->school, $this->admin, self::FOUR_DAYS, '2026-10-01');
        $this->assertSame('LEAVE_CALENDAR_MONTH_LOCKED', $this->code(fn () => $this->workingWeek($this->school, $this->admin, self::WEEK, '2026-09-20')), 'even when a later version starts in October');
        $this->staffHoliday($this->school, '2026-10-14', DayPortion::Full, 'Open month', $this->admin);
        $this->assertSame('full', $this->history()->day($this->school, '2026-09-14')->weekPortion, 'September reads as it did');
    }

    #[Test]
    public function a_timezone_change_never_moves_a_stored_date(): void
    {
        $this->workingWeek($this->school, $this->admin, self::WEEK, '2026-09-15');
        $before = $this->history()->day($this->school, '2026-09-15');
        DB::table('schools')->where('id', $this->school->id)->update(['timezone' => 'Pacific/Kiritimati']);

        $after = $this->history()->day($this->school->refresh(), '2026-09-15');
        $this->assertSame([$before->status, $before->weekPortion, $before->versionId], [$after->status, $after->weekPortion, $after->versionId]);
        $this->assertSame(CalendarDay::NOT_RECORDED, $this->history()->day($this->school, '2026-09-14')->status);
    }

    #[Test]
    public function a_suspended_school_and_an_actor_without_the_key_change_nothing(): void
    {
        $this->workingWeek($this->school, $this->admin);
        $viewer = $this->createUserWithCapabilities($this->school, ['hr.leave.view']);
        $this->assertSame('AuthorizationException', $this->code(fn () => $this->calendar()->recordWeek($this->school, $this->plus(10), self::FOUR_DAYS, $viewer)));
        $this->assertSame('AuthorizationException', $this->code(fn () => $this->calendar()->addHoliday($this->school, $this->plus(10), DayPortion::Full, 'No', $viewer)));

        DB::table('schools')->where('id', $this->school->id)->update(['status' => 'suspended']);
        $this->assertSame('SchoolNotOperationalException', $this->code(fn () => $this->calendar()->recordWeek($this->school, $this->plus(10), self::FOUR_DAYS, $this->admin)));
        $this->assertSame('SchoolNotOperationalException', $this->code(fn () => $this->staffHoliday($this->school, $this->plus(10), DayPortion::Full, 'Closed', $this->admin)));
        $this->assertSame(1, $this->inSchool($this->school, fn () => StaffWorkingWeekVersion::query()->count()));
    }

    #[Test]
    public function the_policy_as_of_a_date_is_the_dated_assignments_own_version(): void
    {
        $w = $this->leaveWorld();
        $asOf = app(LeavePolicyAsOf::class);
        $assignment = $this->inSchool($w['school'], fn () => DB::table('leave_policy_assignments')->where('employment_record_id', $w['employment']->id)->first());

        $this->assertNull($asOf->forEmployment($w['school'], $w['employment']->id, $w['type']->id, CarbonImmutable::parse($assignment->effective_from)->subDay()->toDateString(), $w['admin']), 'before the assignment');
        $this->assertSame($w['policy']->id, $asOf->forEmployment($w['school'], $w['employment']->id, $w['type']->id, $assignment->effective_from, $w['admin'])?->id, 'on its first day');
        $newer = app(LeavePolicyService::class)->create($w['school'], $w['type']->id, [
            'name' => 'Revised', 'annual_allocation_units' => 30, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null, 'carry_forward_expiry_days' => null,
        ], $w['policy']->id, $w['admin'], [new LeaveEligibilityCriteria(null, null, null)]);
        $answer = $asOf->forEmployment($w['school'], $w['employment']->id, $w['type']->id, $assignment->effective_from, $w['admin']);
        $this->assertSame([$w['policy']->id, 24], [$answer?->id, (int) $answer?->annual_allocation_units], 'a superseded version is still the answer for its dates, never today\'s policy');
        $this->assertNotSame($newer->id, $answer?->id);
        $viewer = $this->createUserWithCapabilities($w['school'], []);
        $this->assertSame('AuthorizationException', $this->code(fn () => $asOf->forEmployment($w['school'], $w['employment']->id, $w['type']->id, $assignment->effective_from, $viewer)));
    }
}
