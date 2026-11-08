<?php

namespace Tests\Feature\StaffAttendance;

use App\Domain\StaffAttendance\Application\Exceptions\StaffAttendanceException;
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

    /** HR.R1-C20 (C18-A1): a register in flight finishes before the lock takes the month. */
    #[Test]
    public function a_register_in_flight_finishes_before_the_lock_takes_the_month(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('register', $w['school']->id, '2026-09-14', $w['clerk']->id, "{$w['employment']->id}:present:absent"),
            $this->op('lock', $w['school']->id, '2026-09', $w['principal']->id),
        );

        $this->assertSame('ok:1', $holder);
        $this->assertStringStartsWith('ok:', $contender);
        $this->assertSame([1, 1], [$this->records($w), $this->locks($w)], 'the register is in the month the lock froze');
    }

    /** HR.R1-C20 (C18-A1): a register waiting on a lock is refused once the lock commits, and writes nothing. */
    #[Test]
    public function a_register_waiting_on_a_lock_is_refused_once_the_lock_commits(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('lock', $w['school']->id, '2026-09', $w['principal']->id),
            $this->op('register', $w['school']->id, '2026-09-14', $w['clerk']->id, "{$w['employment']->id}:present:absent"),
        );

        $this->assertStringStartsWith('ok:', $holder);
        $this->assertSame('rejected:STAFF_ATTENDANCE_MONTH_LOCKED', $contender);
        $this->assertSame([0, 1], [$this->records($w), $this->locks($w)]);
    }

    /**
     * HR.R1-C20 (C18-A2): an approval being applied opens no window. A raw write of the same record
     * waits on the row the approval holds and is then refused; an ordinary correction during the
     * approval is refused at once. Exactly the approved change lands, and the month stays locked.
     */
    #[Test]
    public function an_approval_being_applied_opens_no_window_for_an_ordinary_write(): void
    {
        $w = $this->world();
        $record = $this->recordAttendance($w, '2026-09-14', 'present', 'present');
        $service = app(StaffAttendanceService::class);
        $service->lockMonth($w['school'], '2026-09', $w['principal'], $this->freshMfaProofFor($w['principal']));
        $request = $service->requestLockedCorrection($w['school'], $record->id, 1, 'absent', 'present', 'late_information', (string) Str::uuid(), $w['clerk']);
        $during = null;

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('approve', $w['school']->id, $request->id, $w['head']->id),
            $this->op('raw-update', $w['school']->id, $record->id, 'present'),
            function () use ($service, $w, $record, &$during): void {
                try {
                    $service->correct($w['school'], $record->id, 1, 'present', 'absent', 'other', $w['clerk']);
                    $during = 'corrected';
                } catch (StaffAttendanceException $e) {
                    $during = $e->errorCode();
                }
            },
        );

        $this->assertSame('ok:approved', $holder);
        // A bare version bump has no correction row, so the evidence guard (which fires first) refuses it: the race proves
        // the write waited on the approval's row and changed nothing. The month guard is proven just below.
        $this->assertSame('rejected:staff_attendance_correction_missing', $contender, 'the raw write is refused after waiting');
        $this->assertSame('STAFF_ATTENDANCE_MONTH_LOCKED', $during, 'an ordinary correction during the approval is refused');
        $admin = DB::connection('pgsql_admin');
        $row = $admin->table('staff_attendance_records')->where('id', $record->id)->first();
        $this->assertSame(['absent', 'present', 2], [$row->first_half_status, $row->second_half_status, (int) $row->version], 'exactly the approved values');
        $this->assertSame(1, $admin->table('staff_attendance_corrections')->where('staff_attendance_record_id', $record->id)->count());
        $this->assertSame(1, $admin->table('staff_attendance_corrections')->where('locked_correction_id', $request->id)->count());
        $this->assertSame(1, $this->locks($w), 'still locked');

        // Right after the approval, a well-formed raw correction from the record's new version is refused by the
        // month guard itself: the approval authorized its own values once, never the month.
        $this->assertSame('rejected:staff_attendance_month_locked', $this->runOp($this->op('raw-correct', $w['school']->id, $record->id, $record->employment_record_id, '2', 'absent', 'present', 'present', 'present', $w['clerk']->id)));
        $this->assertSame(2, (int) $admin->table('staff_attendance_records')->where('id', $record->id)->value('version'));

        // Positive control: the same raw correction is accepted in an open month, so the refusal above is the lock.
        $open = $this->recordAttendance($w, '2026-08-14', 'present', 'present');
        $this->assertSame('ok:corrected', $this->runOp($this->op('raw-correct', $w['school']->id, $open->id, $open->employment_record_id, '1', 'present', 'present', 'absent', 'present', $w['clerk']->id)));
        $this->assertSame(2, (int) $admin->table('staff_attendance_records')->where('id', $open->id)->value('version'));
    }

    /** HR.R1-C20 (C18-A5): the requests table refuses REPEATABLE READ for a request and a decision; READ COMMITTED is the control. */
    #[Test]
    public function the_requests_table_refuses_repeatable_read_and_accepts_read_committed(): void
    {
        $w = $this->world();
        $record = $this->recordAttendance($w, '2026-09-14', 'present', 'present');
        app(StaffAttendanceService::class)->lockMonth($w['school'], '2026-09', $w['principal'], $this->freshMfaProofFor($w['principal']));
        $request = fn (string $level) => $this->runOp($this->op('raw-request', $w['school']->id, $level, $record->id, $w['employment']->id, '2026-09-01', '1', 'present', 'present', 'absent', 'present', $w['clerk']->id));
        $requests = fn () => DB::connection('pgsql_admin')->table('staff_attendance_locked_corrections')->where('school_id', $w['school']->id);

        $this->assertSame('rejected:staff_attendance_isolation', $request('rr'));
        $this->assertSame(0, $requests()->count(), 'nothing was requested');
        $this->assertSame('ok:requested', $request('rc'), 'the same request at READ COMMITTED');
        $id = $requests()->value('id');

        $decide = fn (string $level) => $this->runOp($this->op('raw-decide', $w['school']->id, $level, $id, $w['head']->id));
        $this->assertSame('rejected:staff_attendance_isolation', $decide('rr'));
        $this->assertSame(['requested', 1], [$requests()->value('status'), (int) DB::connection('pgsql_admin')->table('staff_attendance_records')->where('id', $record->id)->value('version')], 'nothing was decided or applied');
        $this->assertSame('ok:approved', $decide('rc'), 'the same decision at READ COMMITTED');
        $this->assertSame(['approved', 2], [$requests()->value('status'), (int) DB::connection('pgsql_admin')->table('staff_attendance_records')->where('id', $record->id)->value('version')]);
    }

    private function runOp(array $command): string
    {
        return (new Process($command))->mustRun()->getOutput();
    }
}
