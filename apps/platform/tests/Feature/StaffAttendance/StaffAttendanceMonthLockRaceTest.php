<?php

namespace Tests\Feature\StaffAttendance;

use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Concerns\PurgesCommittedHrxFixtures;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;
use Tests\TestCase;

/**
 * HR.R1-C18 (WO-6): the month lock against real concurrent writers, two OS processes, the contender
 * positively observed blocked on the holder's uncommitted lock. Every write of a month takes the
 * School-month advisory lock SHARED (the triggers too); a lock takes it EXCLUSIVELY. So a write in
 * flight finishes before the lock, and a write after the lock is refused, whoever started first.
 *
 * Child processes use the real clock, so dates are in a past month (September 2026), as the other
 * Staff Attendance races do.
 */
class StaffAttendanceMonthLockRaceTest extends TestCase
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
        $keys = ['hr.staff_attendance.view', 'hr.staff_attendance.manage', 'hr.staff_attendance.lock'];
        $w['principal'] = $this->createUserWithCapabilities($w['school'], $keys);
        $w['head'] = $this->createUserWithCapabilities($w['school'], $keys);

        return $w;
    }

    /** @return list<string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/staff-attendance-op.php', ...$args];
    }

    private function locks(array $w): int
    {
        return DB::connection('pgsql_admin')->table('staff_attendance_month_locks')->where('school_id', $w['school']->id)->count();
    }

    private function records(array $w): int
    {
        return DB::connection('pgsql_admin')->table('staff_attendance_records')->where('school_id', $w['school']->id)->count();
    }

    #[Test]
    public function a_recording_in_flight_finishes_before_the_lock_takes_the_month(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('record', $w['school']->id, $w['employment']->id, '2026-09-14', 'present', 'present', $w['clerk']->id),
            $this->op('lock', $w['school']->id, '2026-09', $w['principal']->id),
        );

        $this->assertSame('ok:1', $holder);
        $this->assertStringStartsWith('ok:', $contender);
        $this->assertSame([1, 1], [$this->records($w), $this->locks($w)]);
    }

    #[Test]
    public function a_recording_waiting_on_a_lock_is_refused_once_the_lock_commits(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('lock', $w['school']->id, '2026-09', $w['principal']->id),
            $this->op('record', $w['school']->id, $w['employment']->id, '2026-09-14', 'present', 'present', $w['clerk']->id),
        );

        $this->assertStringStartsWith('ok:', $holder);
        $this->assertSame('rejected:STAFF_ATTENDANCE_MONTH_LOCKED', $contender);
        $this->assertSame([0, 1], [$this->records($w), $this->locks($w)]);
    }

    #[Test]
    public function a_raw_sql_write_waiting_on_a_lock_is_refused_by_the_database(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('lock', $w['school']->id, '2026-09', $w['principal']->id),
            $this->op('raw-record', $w['school']->id, $w['employment']->id, $w['employment']->employee_id, '2026-09-14', $w['clerk']->id),
        );

        $this->assertStringStartsWith('ok:', $holder);
        $this->assertSame('rejected:staff_attendance_month_locked', $contender);
        $this->assertSame(0, $this->records($w));
    }

    #[Test]
    public function two_locks_of_one_month_never_both_succeed(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('lock', $w['school']->id, '2026-09', $w['principal']->id),
            $this->op('lock', $w['school']->id, '2026-09', $w['head']->id),
        );

        $this->assertStringStartsWith('ok:', $holder);
        $this->assertSame('rejected:STAFF_ATTENDANCE_MONTH_ALREADY_LOCKED', $contender);
        $this->assertSame(1, $this->locks($w));
    }

    #[Test]
    public function two_approvals_of_one_request_apply_it_once(): void
    {
        $w = $this->world();
        $third = $this->createUserWithCapabilities($w['school'], ['hr.staff_attendance.view', 'hr.staff_attendance.lock']);
        $record = $this->recordAttendance($w, '2026-09-14', 'present', 'present');
        $service = app(StaffAttendanceService::class);
        $service->lockMonth($w['school'], '2026-09', $w['principal'], $this->freshMfaProofFor($w['principal']));
        $request = $service->requestLockedCorrection($w['school'], $record->id, 1, 'absent', 'present', 'late_information', (string) Str::uuid(), $w['clerk']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('approve', $w['school']->id, $request->id, $w['head']->id),
            $this->op('approve', $w['school']->id, $request->id, $third->id),
        );

        $this->assertSame('ok:approved', $holder);
        $this->assertSame('rejected:STAFF_ATTENDANCE_CORRECTION_DECIDED', $contender);
        $this->assertSame(2, (int) DB::connection('pgsql_admin')->table('staff_attendance_records')->where('id', $record->id)->value('version'));
        $this->assertSame(1, DB::connection('pgsql_admin')->table('staff_attendance_corrections')->where('locked_correction_id', $request->id)->count());
    }

    #[Test]
    public function a_write_at_repeatable_read_is_refused_so_it_can_never_miss_a_committed_lock(): void
    {
        $w = $this->world();
        $output = (new Process($this->op('raw-record-rr', $w['school']->id, $w['employment']->id, $w['employment']->employee_id, '2026-09-14', $w['clerk']->id)))->mustRun()->getOutput();

        $this->assertSame('rejected:staff_attendance_isolation', $output, 'refused even in an open month: the level, not the lock, is what is checked');
        $this->assertSame(0, $this->records($w));
    }

    #[Test]
    public function a_lock_waiting_on_a_suspension_is_refused(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', __DIR__.'/../../Support/suspend-school.php', $w['school']->id],
            $this->op('lock', $w['school']->id, '2026-09', $w['principal']->id),
        );

        $this->assertSame('suspended', $holder);
        $this->assertSame('rejected:SchoolNotOperationalException', $contender);
        $this->assertSame(0, $this->locks($w));
    }
}
