<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Application\Calendar\StaffCalendarHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Concerns\PurgesCommittedHrxFixtures;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;
use Tests\TestCase;

/**
 * HR.R1-C22 (WO-5, D6-2): a past-dated calendar correction against a Staff Attendance month lock, two
 * OS processes, the contender observed waiting. The correction takes each month it reaches shared
 * before the calendar lock; the lock takes its month exclusively. So a correction in flight finishes
 * before the lock, and one waiting on a lock is refused once the lock commits.
 *
 * Child processes use the real clock: September 2026 is an ended month. Leave approval against a
 * calendar change is `LeaveRequestConcurrencyTest`; Payroll capture is `PayrollHrxConcurrencyTest`.
 */
class StaffCalendarConcurrencyTest extends TestCase
{
    use CreatesStaffAttendanceFixtures, ForcesConcurrentOverlap, PurgesCommittedHrxFixtures;

    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotDurableFixtures();
    }

    protected function tearDown(): void
    {
        $this->purgeCommittedHrxSchools($this->schools);
        try {
            $this->assertDurableFixturesRestored();
        } finally {
            parent::tearDown();
        }
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->attendanceWorld();
        $this->schools[] = $w['school'];
        $w['locker'] = $this->createUserWithCapabilities($w['school'], ['hr.staff_attendance.view', 'hr.staff_attendance.lock']);

        return $w;
    }

    private function leave(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/leave-op.php', ...$args];
    }

    private function attendance(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/staff-attendance-op.php', ...$args];
    }

    private function versionsFrom(array $w, string $date): int
    {
        return DB::connection('pgsql_admin')->table('staff_working_week_versions')->where('school_id', $w['school']->id)->where('effective_from', $date)->where('status', 'active')->count();
    }

    private function locks(array $w): int
    {
        return DB::connection('pgsql_admin')->table('staff_attendance_month_locks')->where('school_id', $w['school']->id)->count();
    }

    #[Test]
    public function a_correction_in_flight_finishes_before_the_lock_takes_the_month(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->leave('monday', $w['school']->id, 'off', $w['admin']->id, '2026-09-01'),
            $this->attendance('lock', $w['school']->id, '2026-09', $w['locker']->id),
        );

        $this->assertSame('ok:off', $holder);
        $this->assertStringStartsWith('ok:', $contender);
        $this->assertSame([1, 1], [$this->versionsFrom($w, '2026-09-01'), $this->locks($w)], 'the correction is in the month the lock froze');
    }

    #[Test]
    public function a_correction_waiting_on_a_lock_is_refused_once_the_lock_commits(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->attendance('lock', $w['school']->id, '2026-09', $w['locker']->id),
            $this->leave('monday', $w['school']->id, 'off', $w['admin']->id, '2026-09-01'),
        );

        $this->assertStringStartsWith('ok:', $holder);
        $this->assertSame('rejected:LEAVE_CALENDAR_MONTH_LOCKED', $contender);
        $this->assertSame([0, 1], [$this->versionsFrom($w, '2026-09-01'), $this->locks($w)]);
    }

    #[Test]
    public function a_calendar_write_at_repeatable_read_is_refused_and_read_committed_is_the_control(): void
    {
        $w = $this->world();
        $future = CarbonImmutable::parse(app(StaffCalendarHistory::class)->today($w['school']))->addDays(40)->toDateString();
        config(['database.connections.c22_iso' => config('database.connections.pgsql')]);
        $connection = DB::connection('c22_iso');
        $insert = function (string $level, string $date) use ($connection, $w): string {
            $connection->beginTransaction();
            try {
                $connection->statement("SET TRANSACTION ISOLATION LEVEL {$level}");
                $connection->select('select set_config(?, ?, true)', ['app.current_school_id', $w['school']->id]);
                $connection->table('staff_working_week_versions')->insert([
                    'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'effective_from' => $date,
                    'weekday_1' => 'full', 'weekday_2' => 'full', 'weekday_3' => 'full', 'weekday_4' => 'full', 'weekday_5' => 'off', 'weekday_6' => 'off', 'weekday_7' => 'off',
                    'status' => 'active', 'recorded_by_user_id' => $w['admin']->id, 'recorded_at' => now(),
                ]);
                $connection->commit();

                return 'ok';
            } catch (QueryException $e) {
                $connection->rollBack();

                return str_contains($e->getMessage(), 'staff_calendar_isolation') ? 'staff_calendar_isolation' : $e->getMessage();
            }
        };

        try {
            $this->assertSame('staff_calendar_isolation', $insert('REPEATABLE READ', $future));
            $this->assertSame(0, $this->versionsFrom($w, $future), 'nothing was written');
            $this->assertSame('ok', $insert('READ COMMITTED', $future));
            $this->assertSame(1, $this->versionsFrom($w, $future));
        } finally {
            DB::purge('c22_iso');
        }
    }
}
