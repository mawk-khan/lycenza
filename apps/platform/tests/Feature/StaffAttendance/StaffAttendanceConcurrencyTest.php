<?php

namespace Tests\Feature\StaffAttendance;

use App\Domain\Leave\Application\LeaveRequestService;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Concerns\PurgesCommittedHrxFixtures;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;
use Tests\TestCase;

/**
 * HRX.3 (ADR 0065 §24.6, §24.8, §24.9): genuine two-process races. The
 * holder runs its command and keeps its transaction open; the contender is
 * observed BLOCKED on a lock (the cross-domain staff-day lock, or a row)
 * before the holder commits. Every race ends in one deterministic,
 * persisted outcome -- never a mixed state.
 *
 * Child processes use the real clock, so every date is safely in the past
 * (September 2026: Monday 14 to Friday 18).
 */
class StaffAttendanceConcurrencyTest extends TestCase
{
    use CreatesStaffAttendanceFixtures, ForcesConcurrentOverlap, PurgesCommittedHrxFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<School> */
    private array $schools = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotDurableFixtures();
    }

    protected function tearDown(): void
    {
        // Committed evidence is append-only and RESTRICT: it goes with its School, the test's Users
        // and its ad hoc capability roles (HRX.6: hermetic; never the canonical seed's).
        $this->purgeCommittedHrxSchools($this->schools);
        try {
            $this->assertDurableFixturesRestored();
        } finally {
            parent::tearDown();
        }
    }

    private function attendance(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/staff-attendance-op.php', ...$args];
    }

    private function leave(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/leave-op.php', ...$args];
    }

    private function world(): array
    {
        $w = $this->attendanceWorld();
        $this->schools[] = $w['school'];

        return $w;
    }

    private function record(array $w, string $date, ?string $employmentId = null): ?object
    {
        return DB::connection('pgsql_admin')->table('staff_attendance_records')->where('school_id', $w['school']->id)
            ->where('employment_record_id', $employmentId ?? $w['employment']->id)->where('attendance_date', $date)->first();
    }

    private function requestStatus(string $id): string
    {
        return (string) DB::connection('pgsql_admin')->table('leave_requests')->where('id', $id)->value('status');
    }

    private function rows(array $w, string $table): int
    {
        return DB::connection('pgsql_admin')->table($table)->where('school_id', $w['school']->id)->count();
    }

    #[Test]
    public function two_initial_recordings_of_one_employment_and_date_cannot_both_succeed(): void
    {
        $w = $this->world();
        $command = $this->attendance('record', $w['school']->id, $w['employment']->id, '2026-09-14', 'present', 'present', $w['clerk']->id);

        [$holder, $contender] = $this->raceWithHeldHolder($command, $this->attendance('record', $w['school']->id, $w['employment']->id, '2026-09-14', 'absent', 'absent', $w['clerk']->id));

        $this->assertSame(['ok:1', 'rejected:STAFF_ATTENDANCE_ALREADY_RECORDED'], [$holder, $contender]);
        $this->assertSame(1, $this->rows($w, 'staff_attendance_records'));
        $this->assertSame('present', $this->record($w, '2026-09-14')->first_half_status);
    }

    #[Test]
    public function the_bulk_register_and_a_single_recording_of_the_same_employment_and_date_never_both_write(): void
    {
        $w = $this->world();
        $other = $this->currentEmployment($w['school']);
        $register = $this->attendance('register', $w['school']->id, '2026-09-15', $w['clerk']->id, "{$other->id}:present:present", "{$w['employment']->id}:present:absent");

        [$holder, $contender] = $this->raceWithHeldHolder($register, $this->attendance('record', $w['school']->id, $w['employment']->id, '2026-09-15', 'absent', 'absent', $w['clerk']->id));
        $this->assertSame(['ok:2', 'rejected:STAFF_ATTENDANCE_ALREADY_RECORDED'], [$holder, $contender]);
        $this->assertSame('absent', $this->record($w, '2026-09-15')->second_half_status, 'the register\'s values persisted');

        // The single recording first: the whole register then refuses and writes nothing.
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->attendance('record', $w['school']->id, $w['employment']->id, '2026-09-16', 'absent', 'absent', $w['clerk']->id),
            $this->attendance('register', $w['school']->id, '2026-09-16', $w['clerk']->id, "{$other->id}:present:present", "{$w['employment']->id}:present:present"),
        );
        $this->assertSame(['ok:1', 'rejected:STAFF_ATTENDANCE_ALREADY_RECORDED'], [$holder, $contender]);
        $this->assertNull($this->record($w, '2026-09-16', $other->id), 'all or nothing: the other employment was not written either');
    }

    #[Test]
    public function two_registers_naming_the_same_employments_in_opposite_orders_serialize_without_deadlock(): void
    {
        $w = $this->world();
        $other = $this->currentEmployment($w['school']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->attendance('register', $w['school']->id, '2026-09-17', $w['clerk']->id, "{$w['employment']->id}:present:present", "{$other->id}:present:present"),
            $this->attendance('register', $w['school']->id, '2026-09-17', $w['clerk']->id, "{$other->id}:absent:absent", "{$w['employment']->id}:absent:absent"),
        );

        $this->assertSame(['ok:2', 'rejected:STAFF_ATTENDANCE_ALREADY_RECORDED'], [$holder, $contender], 'both sort by employment id before locking, so the contender simply waits');
        $this->assertSame(2, $this->rows($w, 'staff_attendance_records'));
    }

    #[Test]
    public function two_corrections_from_the_same_version_exactly_one_wins(): void
    {
        $w = $this->world();
        $record = $this->recordAttendance($w, '2026-09-14', 'present', 'present');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->attendance('correct', $w['school']->id, $record->id, '1', 'absent', 'present', $w['clerk']->id),
            $this->attendance('correct', $w['school']->id, $record->id, '1', 'present', 'absent', $w['clerk']->id),
        );

        $this->assertSame(['ok:2', 'rejected:STAFF_ATTENDANCE_VERSION_STALE'], [$holder, $contender]);
        $row = $this->record($w, '2026-09-14');
        $this->assertSame(['absent', 'present', 2], [$row->first_half_status, $row->second_half_status, (int) $row->version], 'no lost update');
        $this->assertSame(1, $this->rows($w, 'staff_attendance_corrections'), 'no duplicate correction step');
    }

    #[Test]
    public function present_recording_and_leave_approval_on_the_same_half_exactly_one_wins(): void
    {
        $w = $this->world();

        // Attendance first: the approval waits, sees the presence and is refused.
        $first = $this->submitLeave($w, '2026-09-14', '2026-09-14')->id;
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->attendance('record', $w['school']->id, $w['employment']->id, '2026-09-14', 'present', 'present', $w['clerk']->id),
            $this->leave('approve', $w['school']->id, $first, $w['admin']->id),
        );
        $this->assertSame(['ok:1', 'rejected:LEAVE_ATTENDANCE_PRESENT'], [$holder, $contender]);
        $this->assertSame('submitted', $this->requestStatus($first));

        // Approval first: the attendance write waits, sees the leave and is refused.
        $second = $this->submitLeave($w, '2026-09-15', '2026-09-15')->id;
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->leave('approve', $w['school']->id, $second, $w['admin']->id),
            $this->attendance('record', $w['school']->id, $w['employment']->id, '2026-09-15', 'present', '-', $w['clerk']->id),
        );
        $this->assertSame(['ok:approved', 'rejected:STAFF_ATTENDANCE_ON_LEAVE'], [$holder, $contender]);
        $this->assertNull($this->record($w, '2026-09-15'), 'no mixed state');
    }

    #[Test]
    public function an_absent_to_present_correction_and_leave_approval_on_the_same_half_exactly_one_wins(): void
    {
        $w = $this->world();
        $monday = $this->recordAttendance($w, '2026-09-14', 'absent', 'absent');
        $tuesday = $this->recordAttendance($w, '2026-09-15', 'absent', 'absent');

        $first = $this->submitLeave($w, '2026-09-14', '2026-09-14')->id;
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->attendance('correct', $w['school']->id, $monday->id, '1', 'present', 'absent', $w['clerk']->id),
            $this->leave('approve', $w['school']->id, $first, $w['admin']->id),
        );
        $this->assertSame(['ok:2', 'rejected:LEAVE_ATTENDANCE_PRESENT'], [$holder, $contender]);

        $second = $this->submitLeave($w, '2026-09-15', '2026-09-15')->id;
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->leave('approve', $w['school']->id, $second, $w['admin']->id),
            $this->attendance('correct', $w['school']->id, $tuesday->id, '1', 'present', 'absent', $w['clerk']->id),
        );
        $this->assertSame(['ok:approved', 'rejected:STAFF_ATTENDANCE_ON_LEAVE'], [$holder, $contender]);
        $this->assertSame(['absent', 1], [$this->record($w, '2026-09-15')->first_half_status, (int) $this->record($w, '2026-09-15')->version], 'the absence underneath the approved leave is untouched');
    }

    #[Test]
    public function opposite_half_attendance_and_a_half_day_approval_both_succeed_and_absence_never_blocks(): void
    {
        $w = $this->world();
        $morning = $this->submitLeave($w, '2026-09-14', '2026-09-14', 'first_half')->id;

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->leave('approve', $w['school']->id, $morning, $w['admin']->id),
            $this->attendance('record', $w['school']->id, $w['employment']->id, '2026-09-14', '-', 'present', $w['clerk']->id),
        );
        $this->assertSame(['ok:approved', 'ok:1'], [$holder, $contender], 'the contender waited on the day lock, then wrote the other half');

        // Recorded absence on the same half never blocks an approval.
        $tuesday = $this->submitLeave($w, '2026-09-15', '2026-09-15')->id;
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->attendance('record', $w['school']->id, $w['employment']->id, '2026-09-15', 'absent', 'absent', $w['clerk']->id),
            $this->leave('approve', $w['school']->id, $tuesday, $w['admin']->id),
        );
        $this->assertSame(['ok:1', 'ok:approved'], [$holder, $contender]);
        $this->assertSame(['absent', 'absent', 1], [$this->record($w, '2026-09-15')->first_half_status, $this->record($w, '2026-09-15')->second_half_status, (int) $this->record($w, '2026-09-15')->version]);
    }

    #[Test]
    public function attendance_recording_racing_a_leave_cancellation_sees_exactly_one_side(): void
    {
        $w = $this->world();
        $leave = $this->submitLeave($w, '2026-09-14', '2026-09-14')->id;
        app(LeaveRequestService::class)->approve($w['school'], $leave, $w['admin']);

        // Cancellation first: the attendance write waits, then records over the freed half.
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->leave('cancel', $w['school']->id, $leave, $w['admin']->id),
            $this->attendance('record', $w['school']->id, $w['employment']->id, '2026-09-14', 'present', 'present', $w['clerk']->id),
        );
        $this->assertSame(['ok:cancelled', 'ok:1'], [$holder, $contender]);

        // Attendance on the free half first: the cancellation waits and succeeds; nothing is mixed.
        $afternoon = $this->submitLeave($w, '2026-09-15', '2026-09-15', 'second_half')->id;
        app(LeaveRequestService::class)->approve($w['school'], $afternoon, $w['admin']);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->attendance('record', $w['school']->id, $w['employment']->id, '2026-09-15', 'present', '-', $w['clerk']->id),
            $this->leave('cancel', $w['school']->id, $afternoon, $w['admin']->id),
        );
        $this->assertSame(['ok:1', 'ok:cancelled'], [$holder, $contender]);
        $this->assertSame(['cancelled', 'cancelled'], [$this->requestStatus($leave), $this->requestStatus($afternoon)]);
    }
}
