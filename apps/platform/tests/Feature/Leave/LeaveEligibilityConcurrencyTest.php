<?php

namespace Tests\Feature\Leave;

use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\Policy\LeaveEligibilityCriteria;
use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Concerns\PurgesCommittedHrxFixtures;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.9 (ADR 0065 §28.5): eligibility writers racing, in two real OS
 * processes with the contender OBSERVED blocked on the holder's lock
 * (COMMITTED fixtures, released only once pg_stat_activity shows the wait):
 * - two runs of one leave type: the second waits for the first's
 *   eligibility lock, then finds every employment ASSIGNED -- nobody is
 *   assigned twice;
 * - two versions with overlapping rules: one is created, the other refused
 *   (LEAVE_ELIGIBILITY_OVERLAP) -- never two ambiguous active versions;
 * - a run against a supersede: the run waits and resolves against the
 *   committed replacement, never a half-changed rule set;
 * - a run against an employment end: the run's FOR SHARE read of the
 *   employment waits, then skips the ended employment.
 */
class LeaveEligibilityConcurrencyTest extends TestCase
{
    use CreatesLeaveFixtures, ForcesConcurrentOverlap, PurgesCommittedHrxFixtures;

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
        $this->purgeCommittedHrxSchools($this->schools);
        try {
            $this->assertDurableFixturesRestored();
        } finally {
            parent::tearDown();
        }
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/leave-op.php', ...$args];
    }

    /** @return array{school: School, admin: User, type: LeaveType} */
    private function world(): array
    {
        $school = $this->createSchool();
        $this->schools[] = $school;
        $admin = $this->leaveAdmin($school);

        return ['school' => $school, 'admin' => $admin, 'type' => $this->leaveType($school, $admin)];
    }

    private function teachingVersion(array $w): LeavePolicy
    {
        return app(LeavePolicyService::class)->create($w['school'], $w['type']->id, [
            'name' => 'Teaching', 'annual_allocation_units' => 24, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null, 'carry_forward_expiry_days' => null,
        ], null, $w['admin'], [new LeaveEligibilityCriteria(StaffType::TEACHING, null, null)]);
    }

    private function teacher(School $school, ?string $endsOn = null): string
    {
        $employment = $this->createEmploymentRecord($this->createEmployee($school), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => $endsOn, 'staff_type' => 'teaching']);

        return $employment->id;
    }

    /** @return list<object> */
    private function assignments(School $school): array
    {
        return DB::connection('pgsql_admin')->table('leave_policy_assignments')->where('school_id', $school->id)->orderBy('employment_record_id')->get()->all();
    }

    #[Test]
    public function two_concurrent_runs_assign_each_employment_once(): void
    {
        $w = $this->world();
        $this->teachingVersion($w);
        $a = $this->teacher($w['school']);
        $b = $this->teacher($w['school']);
        $run = $this->script('eligibility-run', $w['school']->id, $w['type']->id, now()->toDateString(), $w['admin']->id);

        [$holder, $contender] = $this->raceWithHeldHolder($run, $run);

        $this->assertSame('ok:2', $holder);
        $this->assertSame('ok:0', $contender, 'the waiting run sees both employments assigned');
        $this->assertEqualsCanonicalizing([$a, $b], array_column($this->assignments($w['school']), 'employment_record_id'));
        $this->assertSame(2, DB::connection('pgsql_admin')->table('leave_eligibility_runs')->where('school_id', $w['school']->id)->count());
    }

    #[Test]
    public function two_concurrent_overlapping_versions_never_both_become_active(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('version', $w['school']->id, $w['type']->id, 'teaching', 'none', $w['admin']->id),
            $this->script('version', $w['school']->id, $w['type']->id, 'any', 'none', $w['admin']->id),
        );

        $this->assertSame('ok:active', $holder);
        $this->assertSame('rejected:LEAVE_ELIGIBILITY_OVERLAP', $contender);
        $this->assertSame(1, DB::connection('pgsql_admin')->table('leave_policies')->where('school_id', $w['school']->id)->where('status', 'active')->count());
    }

    #[Test]
    public function a_run_waiting_on_a_supersede_resolves_against_the_committed_replacement(): void
    {
        $w = $this->world();
        $v1 = $this->teachingVersion($w);
        $teacher = $this->teacher($w['school']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('version', $w['school']->id, $w['type']->id, 'teaching', $v1->id, $w['admin']->id),
            $this->script('eligibility-run', $w['school']->id, $w['type']->id, now()->toDateString(), $w['admin']->id),
        );

        $this->assertSame('ok:active', $holder);
        $this->assertSame('ok:1', $contender);
        $v2 = DB::connection('pgsql_admin')->table('leave_policies')->where('school_id', $w['school']->id)->where('status', 'active')->value('id');
        $assignment = $this->assignments($w['school'])[0];
        $this->assertSame([$teacher, $v2], [$assignment->employment_record_id, $assignment->leave_policy_id], 'never the retired version');
    }

    #[Test]
    public function a_run_waiting_on_an_employment_end_skips_the_ended_employment(): void
    {
        $w = $this->world();
        $this->teachingVersion($w);
        $leaving = $this->teacher($w['school']);
        $hr = $this->fullHrActor($w['school']);
        $today = now()->toDateString();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('end-employment', $w['school']->id, $leaving, $today, $hr->id),
            $this->script('eligibility-run', $w['school']->id, $w['type']->id, now()->addDay()->toDateString(), $w['admin']->id),
        );

        $this->assertSame('ok:separated', $holder);
        $this->assertSame('ok:0', $contender, 'the ended employment does not span the as-of date');
        $this->assertSame([], $this->assignments($w['school']));
    }
}
