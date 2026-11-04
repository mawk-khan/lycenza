<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Models\School;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Concerns\PurgesCommittedHrxFixtures;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.11 (ADR 0065 §30.5): a line manager's decision racing a separation, in
 * two real OS processes with the contender OBSERVED blocked (COMMITTED
 * fixtures; the children run on the real clock, so the leave is far ahead):
 * - the REQUESTER's employment ends before the leave: the approval waits on
 *   HR's FOR SHARE read, then refuses (the employment no longer covers it) --
 *   nothing consumed;
 * - the MANAGER's employment ends: the approval waits on the manager's
 *   acting-Employee read, then answers the private 404 -- a separated manager
 *   never decides, and nobody else is invented.
 */
class LeaveDecisionSeparationConcurrencyTest extends TestCase
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

    /** A requester reporting to a REAL line_manager role holder, with a submitted request on 2027-02-01 (a Monday). */
    private function team(): array
    {
        $w = $this->leaveWorld();
        $this->schools[] = $w['school'];
        $this->workingWeek($w['school'], $w['admin']);
        $people = [];
        foreach (['requester' => ['staff_self_service'], 'manager' => ['teacher', 'line_manager']] as $who => $roles) {
            $user = $this->createUser();
            $membership = $this->createMembership($user, $w['school']);
            foreach ($roles as $role) {
                $this->assignSchoolRole($membership, $role);
            }
            $employment = $this->createEmploymentRecord($this->createEmployee($w['school'], ['user_id' => $user->id]), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
            $assignment = $this->createEmployeeAssignment($employment, $this->createPosition($w['school']), ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true]);
            app(CapabilityResolver::class)->forgetCache($user, $w['school']);
            $people[$who] = compact('user', 'employment', 'assignment');
        }
        $this->reportTo($people['requester']['assignment'], $people['manager']['assignment']);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $people['requester']['employment']->id, $w['policy']->id, '2026-04-01', null, $w['admin']);
        $this->allocate($w, 24, null, $people['requester']['employment']);
        $request = $this->submitLeave($w, '2027-02-01', '2027-02-01', 'full', null, $people['requester']['employment']);

        return $w + $people + ['request' => $request, 'hr' => $this->fullHrActor($w['school'])];
    }

    private function consumed(string $requestId): int
    {
        return DB::connection('pgsql_admin')->table('leave_ledger_entries')->where('leave_request_id', $requestId)->where('kind', 'consumption')->count();
    }

    #[Test]
    public function an_approval_waiting_on_the_requesters_separation_refuses_and_consumes_nothing(): void
    {
        $t = $this->team();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('end-employment', $t['school']->id, $t['requester']['employment']->id, now()->toDateString(), $t['hr']->id),
            $this->script('manager-approve', $t['school']->id, $t['request']->id, $t['manager']['user']->id),
        );

        $this->assertSame('ok:separated', $holder);
        $this->assertSame('rejected:LEAVE_EMPLOYMENT_NOT_ELIGIBLE', $contender);
        $this->assertSame(0, $this->consumed($t['request']->id));
    }

    #[Test]
    public function an_approval_waiting_on_the_managers_separation_is_refused_as_not_theirs(): void
    {
        $t = $this->team();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('end-employment', $t['school']->id, $t['manager']['employment']->id, now()->toDateString(), $t['hr']->id),
            $this->script('manager-approve', $t['school']->id, $t['request']->id, $t['manager']['user']->id),
        );

        $this->assertSame('ok:separated', $holder);
        $this->assertSame('rejected:ModelNotFoundException', $contender);
        $this->assertSame(0, $this->consumed($t['request']->id));
        $this->assertSame('submitted', DB::connection('pgsql_admin')->table('leave_requests')->where('id', $t['request']->id)->value('status'));
    }
}
