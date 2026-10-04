<?php

namespace Tests\Feature\Retention;

use App\Models\School;
use App\Support\Retention\RetentionHolds;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Concerns\PurgesCommittedHrxFixtures;
use Tests\Feature\Retention\Concerns\CreatesHrxRetentionFixtures;
use Tests\TestCase;

/**
 * HRX.6: the HRX D9 purge racing the late write that matters, in two real
 * OS processes with an observed lock wait (COMMITTED fixtures).
 * - A late attendance correction holds the employment FOR SHARE and its
 *   `hrx.staff_employment` lock shared; the purge locks the Employee and its
 *   EmploymentRecords FOR UPDATE, then that lock exclusively. A correction
 *   first is new evidence: the attendance unit is kept (Leave, its own unit,
 *   still expires). The purge first makes the late correction wait, then
 *   fail on the vanished record -- never a correction without its record.
 * - Two purges: the second waits on the first's Employee lock, then finds
 *   nothing left and deletes nothing, without an error.
 */
class HrxRetentionConcurrencyTest extends TestCase
{
    use CreatesHrxRetentionFixtures, ForcesConcurrentOverlap, PurgesCommittedHrxFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<School> */
    private array $schools = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotDurableFixtures();
        config(['retention.employee_evidence_years' => 8, 'retention.hold_school_ids' => []]);
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

    /** @return array{w: array<string, mixed>, leaver: array<string, mixed>, record: object} */
    private function world(): array
    {
        $w = $this->pastWorld();
        $leaver = $this->pastLeaver($w);
        $record = DB::connection('pgsql_admin')->table('staff_attendance_records')->where('employee_id', $leaver['employeeId'])->first(['id', 'version']);

        return compact('w', 'leaver', 'record');
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/hrx-retention-op.php', ...$args];
    }

    private function admin(string $table, string $column, string $id): int
    {
        return DB::connection('pgsql_admin')->table($table)->where($column, $id)->count();
    }

    #[Test]
    public function a_late_correction_committed_first_is_new_evidence_and_keeps_the_attendance_unit(): void
    {
        $t = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('correct', $t['w']['school']->id, $t['record']->id, (string) $t['record']->version, $t['w']['clerk']->id),
            $this->script('prune', $t['w']['school']->id),
        );

        $this->assertSame('corrected', $holder);
        $this->assertSame('deleted:1/0 blocked:0/1 errors:0', $contender);
        $this->assertSame(1, $this->admin('staff_attendance_records', 'id', $t['record']->id));
        $this->assertSame(2, $this->admin('staff_attendance_corrections', 'staff_attendance_record_id', $t['record']->id), 'the record keeps its whole history');
        $this->assertSame(0, $this->admin('leave_requests', 'employee_id', $t['leaver']['employeeId']));
    }

    #[Test]
    public function a_purge_committed_first_makes_the_late_correction_wait_and_fail_on_the_vanished_record(): void
    {
        $t = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('prune', $t['w']['school']->id),
            $this->script('correct', $t['w']['school']->id, $t['record']->id, (string) $t['record']->version, $t['w']['clerk']->id),
        );

        $this->assertSame('deleted:1/1 blocked:0/0 errors:0', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertSame(0, $this->admin('staff_attendance_records', 'id', $t['record']->id));
        $this->assertSame(0, $this->admin('staff_attendance_corrections', 'staff_attendance_record_id', $t['record']->id), 'no orphaned correction');
    }

    #[Test]
    public function a_second_concurrent_purge_waits_then_finds_nothing_and_never_errors(): void
    {
        $t = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('prune', $t['w']['school']->id),
            $this->script('prune', $t['w']['school']->id),
        );

        $this->assertSame('deleted:1/1 blocked:0/0 errors:0', $holder);
        $this->assertSame('deleted:0/0 blocked:0/0 errors:0', $contender);
        $this->assertSame(0, $this->admin('leave_requests', 'employee_id', $t['leaver']['employeeId']));
        $this->assertSame(0, $this->admin('staff_attendance_records', 'employee_id', $t['leaver']['employeeId']));
        $this->assertSame(0, $this->admin('leave_ledger_entries', 'employment_record_id', $t['leaver']['employment']->id));
    }

    /** The current platform hold rows (maintenance connection). */
    private function platformHolds(): array
    {
        return DB::connection(RetentionHolds::MAINTENANCE_CONNECTION)->table('retention_holds')->where('scope', 'platform')->orderBy('placed_at')->get(['released_at'])->map(fn ($r) => $r->released_at === null ? 'active' : 'released')->all();
    }

    #[Test]
    public function a_platform_hold_placed_concurrently_makes_the_waiting_purge_refuse(): void
    {
        $t = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('place-platform', $t['w']['school']->id),
            $this->script('prune', $t['w']['school']->id),
        );

        $this->assertSame('placed:created', $holder);
        $this->assertSame('deleted:0/0 blocked:1/1 errors:0', $contender, 'the purge waited on the hold lock, then saw the committed hold');
        $this->assertSame(1, $this->admin('staff_attendance_records', 'id', $t['record']->id));
        $this->assertSame(['active'], $this->platformHolds());
    }

    #[Test]
    public function a_purge_already_past_the_check_finishes_and_the_placement_waits_for_it(): void
    {
        $t = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('prune', $t['w']['school']->id),
            $this->script('place-platform', $t['w']['school']->id),
        );

        $this->assertSame('deleted:1/1 blocked:0/0 errors:0', $holder);
        $this->assertSame('placed:created', $contender, 'the placement became effective only after the purge committed');
        $this->assertSame(0, $this->admin('staff_attendance_records', 'id', $t['record']->id));
        $this->assertSame(['active'], $this->platformHolds());
    }

    #[Test]
    public function a_concurrent_release_lets_the_waiting_purge_proceed_once_committed(): void
    {
        $t = $this->world();
        app(RetentionHolds::class)->place(null, 'regulatory_inquiry', 'RACE-0');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('release-platform', $t['w']['school']->id),
            $this->script('prune', $t['w']['school']->id),
        );

        $this->assertSame('released', $holder);
        $this->assertSame('deleted:1/1 blocked:0/0 errors:0', $contender);
        $this->assertSame(['released'], $this->platformHolds());
    }

    #[Test]
    public function two_operators_placing_the_same_hold_create_exactly_one_and_two_releases_release_once(): void
    {
        $t = $this->world();

        [$first, $second] = $this->raceWithHeldHolder(
            $this->script('place-platform', $t['w']['school']->id),
            $this->script('place-platform', $t['w']['school']->id),
        );
        $this->assertSame(['placed:created', 'placed:existing'], [$first, $second]);
        $this->assertSame(['active'], $this->platformHolds());

        [$first, $second] = $this->raceWithHeldHolder(
            $this->script('release-platform', $t['w']['school']->id),
            $this->script('release-platform', $t['w']['school']->id),
        );
        $this->assertSame('released', $first);
        $this->assertStringStartsWith('rejected:', $second, 'the second release finds no active hold');
        $this->assertSame(['released'], $this->platformHolds());
    }
}
