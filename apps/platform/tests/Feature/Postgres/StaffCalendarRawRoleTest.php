<?php

namespace Tests\Feature\Postgres;

use App\Domain\Leave\Application\Calendar\StaffCalendarHistory;
use App\Domain\Leave\Application\DayPortion;
use App\Domain\Leave\Infrastructure\StaffHoliday;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantRls;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;
use Tests\TestCase;

/**
 * HR.R1-C22 (WO-5, D-6, S-16): the staff calendar's history as the database enforces it, against the
 * runtime role writing raw SQL with no service in the way. Nothing is deleted or overwritten, nothing
 * is recorded before the history start, a past date needs a reason, the migration's `cutover` is not
 * the runtime role's to write, and no change reaches a locked Staff Attendance month.
 */
class StaffCalendarRawRoleTest extends TestCase
{
    use CreatesStaffAttendanceFixtures;

    private School $school;

    private User $admin;

    private string $versionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-05 10:00:00');
        $this->school = $this->createSchool();
        $this->admin = $this->leaveAdmin($this->school);
        $this->workingWeek($this->school, $this->admin, [1 => 'full', 2 => 'full', 3 => 'full', 4 => 'full', 5 => 'full', 6 => 'off', 7 => 'off'], '2026-08-01');
        $this->versionId = (string) $this->inSchool($this->school, fn () => DB::table('staff_working_week_versions')->value('id'));
    }

    /** Raw SQL as the runtime role, in its own savepoint; the database's refusal, or '' if it succeeded. */
    private function raw(callable $statement): string
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $this->school->id]);
        try {
            DB::connection('pgsql')->transaction($statement);

            return '';
        } catch (QueryException $e) {
            return $e->getMessage();
        } finally {
            DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, '']);
        }
    }

    private function version(array $overrides = []): array
    {
        return [...[
            'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'effective_from' => CarbonImmutable::parse(app(StaffCalendarHistory::class)->today($this->school))->addDays(40)->toDateString(),
            'weekday_1' => 'full', 'weekday_2' => 'full', 'weekday_3' => 'full', 'weekday_4' => 'full', 'weekday_5' => 'off', 'weekday_6' => 'off', 'weekday_7' => 'off',
            'status' => 'active', 'recorded_by_user_id' => $this->admin->id, 'recorded_at' => now(),
        ], ...$overrides];
    }

    #[Test]
    public function the_runtime_role_deletes_no_holiday_and_writes_nothing_to_the_retired_week(): void
    {
        $holiday = $this->staffHoliday($this->school, '2026-10-14', DayPortion::Full, 'Founders', $this->admin);

        $this->assertStringContainsString('permission denied', $this->raw(fn () => DB::table('staff_holidays')->where('id', $holiday->id)->delete()), 'S-16');
        $this->assertStringContainsString('permission denied', $this->raw(fn () => DB::table('staff_working_week_versions')->where('id', $this->versionId)->delete()));
        $this->assertStringContainsString('permission denied', $this->raw(fn () => DB::table('staff_working_weekdays')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'iso_weekday' => 1, 'portion' => 'off', 'updated_by_user_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ])), 'the legacy week is read-only');
        $this->assertSame(1, $this->inSchool($this->school, fn () => StaffHoliday::query()->count()));
    }

    #[Test]
    public function terms_are_frozen_and_state_moves_only_as_the_contract_allows(): void
    {
        $this->assertStringContainsString('staff_calendar_immutable', $this->raw(fn () => DB::table('staff_working_week_versions')->where('id', $this->versionId)->update(['weekday_5' => 'off'])));
        $this->assertStringContainsString('staff_calendar_immutable', $this->raw(fn () => DB::table('staff_working_week_versions')->where('id', $this->versionId)->update(['effective_from' => '2026-07-01'])));
        $this->assertStringContainsString('staff_calendar_withdraw_effective', $this->raw(fn () => DB::table('staff_working_week_versions')->where('id', $this->versionId)
            ->update(['status' => 'withdrawn', 'status_changed_by_user_id' => $this->admin->id, 'status_changed_at' => now()])), 'a version in force is not withdrawn');
        $this->assertStringContainsString('staff_calendar_supersede_invalid', $this->raw(fn () => DB::table('staff_working_week_versions')->where('id', $this->versionId)
            ->update(['status' => 'superseded', 'status_changed_by_user_id' => $this->admin->id, 'status_changed_at' => now()])), 'only a replacement supersedes');

        $holiday = $this->staffHoliday($this->school, '2026-10-14', DayPortion::Full, 'Founders', $this->admin);
        $this->assertStringContainsString('staff_calendar_immutable', $this->raw(fn () => DB::table('staff_holidays')->where('id', $holiday->id)->update(['portion' => 'first_half'])));
        $this->assertStringContainsString('staff_calendar_reason_required', $this->raw(fn () => DB::table('staff_holidays')->where('id', $holiday->id)
            ->update(['status' => 'withdrawn', 'withdrawn_by_user_id' => $this->admin->id, 'withdrawn_at' => now()])), 'an effective holiday withdrawn without a reason');
    }

    #[Test]
    public function nothing_before_the_history_start_no_past_date_without_a_reason_and_no_runtime_cutover(): void
    {
        $this->assertStringContainsString('staff_calendar_before_history', $this->raw(fn () => DB::table('staff_working_week_versions')->insert($this->version(['effective_from' => '2026-07-01', 'reason_code' => 'late_declaration']))));
        $this->assertStringContainsString('staff_calendar_before_history', $this->raw(fn () => DB::table('staff_holidays')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'holiday_on' => '2026-07-15', 'portion' => 'full', 'name' => 'Before', 'created_by_user_id' => $this->admin->id,
            'recorded_reason_code' => 'late_declaration', 'created_at' => now(), 'updated_at' => now(), 'recorded_at' => now(),
        ])));
        $this->assertStringContainsString('staff_calendar_reason_required', $this->raw(fn () => DB::table('staff_working_week_versions')->insert($this->version(['effective_from' => '2026-10-01']))));
        $this->assertStringContainsString('staff_calendar_cutover', $this->raw(fn () => DB::table('staff_working_week_versions')->insert($this->version(['reason_code' => 'cutover', 'recorded_by_user_id' => null]))));
        $this->assertStringContainsString('staff_calendar_version_invalid', $this->raw(fn () => DB::table('staff_working_week_versions')->insert($this->version(['status' => 'withdrawn', 'status_changed_at' => now(), 'status_changed_by_user_id' => $this->admin->id]))));
        $this->assertStringContainsString('staff_working_week_versions_one_active', $this->raw(fn () => DB::table('staff_working_week_versions')->insert($this->version(['effective_from' => '2026-08-01', 'reason_code' => 'late_declaration']))), 'two active versions of one date');
        $this->assertSame('', $this->raw(fn () => DB::table('staff_working_week_versions')->insert($this->version())), 'a future version is ordinary');
    }

    #[Test]
    public function a_school_without_history_cannot_backdate_its_first_week_or_holiday(): void
    {
        // D6-3 (C22 review M-1): no backfill, even with a reason; today is the database's School-local date.
        $this->school = $this->createSchool();
        $this->admin = $this->leaveAdmin($this->school);
        $today = app(StaffCalendarHistory::class)->today($this->school);
        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();

        $this->assertStringContainsString('staff_calendar_before_history', $this->raw(fn () => DB::table('staff_working_week_versions')->insert($this->version(['effective_from' => $yesterday, 'reason_code' => 'late_declaration']))));
        $this->assertStringContainsString('staff_calendar_before_history', $this->raw(fn () => DB::table('staff_holidays')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'holiday_on' => $yesterday, 'portion' => 'full', 'name' => 'Backdated', 'created_by_user_id' => $this->admin->id,
            'recorded_reason_code' => 'late_declaration', 'created_at' => now(), 'updated_at' => now(), 'recorded_at' => now(),
        ])));
        $this->assertSame('', $this->raw(fn () => DB::table('staff_working_week_versions')->insert($this->version(['effective_from' => $today]))), 'today starts its history');
    }

    #[Test]
    public function no_raw_change_reaches_a_locked_month(): void
    {
        $locker = $this->createUserWithCapabilities($this->school, ['hr.staff_attendance.view', 'hr.staff_attendance.lock']);
        app(StaffAttendanceService::class)->lockMonth($this->school, '2026-09', $locker, $this->freshMfaProofFor($locker));

        $this->assertStringContainsString('staff_attendance_month_locked', $this->raw(fn () => DB::table('staff_holidays')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'holiday_on' => '2026-09-14', 'portion' => 'full', 'name' => 'Late', 'created_by_user_id' => $this->admin->id,
            'recorded_reason_code' => 'late_declaration', 'created_at' => now(), 'updated_at' => now(), 'recorded_at' => now(),
        ])));
        $this->assertStringContainsString('staff_attendance_month_locked', $this->raw(fn () => DB::table('staff_working_week_versions')->insert($this->version(['effective_from' => '2026-08-15', 'reason_code' => 'entered_in_error']))), 'a version whose answer runs through September');
        $this->assertSame('', $this->raw(fn () => DB::table('staff_working_week_versions')->insert($this->version(['effective_from' => '2026-10-01', 'reason_code' => 'entered_in_error']))), 'October is open');
    }

    #[Test]
    public function another_school_sees_and_changes_nothing(): void
    {
        $other = $this->createSchool();
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $other->id]);
        try {
            $this->assertSame([0, 0], [DB::table('staff_working_week_versions')->count(), DB::table('staff_holidays')->count()]);
            $this->assertSame(0, DB::table('staff_working_week_versions')->where('id', $this->versionId)->update(['status' => 'withdrawn']));
        } finally {
            DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, '']);
        }
    }
}
