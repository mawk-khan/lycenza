<?php

namespace Tests\Feature\HR;

use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Models\School;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\PurgesCommittedHrxFixtures;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.R1-C9: a leave decision against an employment end, on either side of the line.
 *
 * `ReportingLine::holdManagerOf()` took the line and only then an employment: the
 * manager's, and for an approval the requester's. `EmploymentService::end()` takes
 * the employment, then the line. Both were real deadlocks (40P01), each reproduced
 * before the correction. A third session holds the employment, so `end()` queues on
 * it first and the decision queues behind `end()`. Releasing the third session forces
 * the only order that matters. Real services, real processes, committed fixtures.
 */
class ReportingLineEmploymentEndConcurrencyTest extends TestCase
{
    use CreatesLeaveFixtures, PurgesCommittedHrxFixtures;

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

    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/reporting-line-op.php', ...$args];
    }

    private function team(): array
    {
        $w = $this->leaveWorld();
        $this->schools[] = $w['school'];
        $this->workingWeek($w['school'], $w['admin']);
        $people = [];
        foreach (['requester' => ['staff_self_service'], 'manager' => ['teacher', 'line_manager'], 'other' => ['teacher', 'line_manager']] as $who => $roles) {
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

    private function waitBlocked(string $session, Process $process): void
    {
        $deadline = microtime(true) + 60;
        while (microtime(true) < $deadline) {
            $row = DB::connection('pgsql_admin')->selectOne('select wait_event_type from pg_stat_activity where application_name = ?', [$session]);
            if ($row !== null && $row->wait_event_type === 'Lock') {
                return;
            }
            $this->assertTrue($process->isRunning(), "{$session} stopped before it blocked: ".$process->getOutput().$process->getErrorOutput());
            usleep(2_000);
        }
        $this->fail("{$session} never blocked");
    }

    /**
     * @param  'manager'|'requester'  $ending  whose employment ends
     * @param  'manager'|'other'  $decider
     * @return array{0: string, 1: string} [end, decision]
     */
    private function race(array $team, string $ending, string $decider): array
    {
        $dir = sys_get_temp_dir().'/line_'.bin2hex(random_bytes(6));
        mkdir($dir);
        [$endSession, $decideSession] = ['line_end_'.bin2hex(random_bytes(4)), 'line_dec_'.bin2hex(random_bytes(4))];
        $school = $team['school']->id;
        $gate = new Process($this->op('gate', $school, $team[$ending]['employment']->id), null, ['CONCURRENCY_HOLD_DIR' => $dir]);
        $gate->setTimeout(120);
        $gate->start();
        $deadline = microtime(true) + 60;
        while (! file_exists($dir.'/acted') && microtime(true) < $deadline) {
            usleep(2_000);
        }
        $this->assertFileExists($dir.'/acted', 'the gate never held the employment: '.$gate->getErrorOutput());

        $end = new Process($this->op('end', $school, $team[$ending]['employment']->id, SchoolTimezone::today($team['school']), $team['hr']->id), null, ['CONCURRENCY_SESSION_NAME' => $endSession]);
        $end->setTimeout(120);
        $end->start();
        $this->waitBlocked($endSession, $end);

        $decide = new Process($this->op('manager-approve', $school, $team['request']->id, $team[$decider]['user']->id), null, ['CONCURRENCY_SESSION_NAME' => $decideSession]);
        $decide->setTimeout(120);
        $decide->start();
        $this->waitBlocked($decideSession, $decide);

        touch($dir.'/release');
        $gate->wait();
        $end->wait();
        $decide->wait();
        @unlink($dir.'/acted');
        @unlink($dir.'/release');
        @rmdir($dir);

        return [trim($end->getOutput()), trim($decide->getOutput())];
    }

    #[Test]
    public function a_decision_by_someone_else_racing_the_managers_end_never_deadlocks(): void
    {
        $team = $this->team();

        [$end, $decision] = $this->race($team, 'manager', 'other');

        $this->assertSame('ok:separated', $end);
        $this->assertSame('rejected:'.ModelNotFoundException::class, $decision, 'the private refusal, and no deadlock');
        $this->assertSame('submitted', DB::connection('pgsql_admin')->table('leave_requests')->where('id', $team['request']->id)->value('status'));
        $this->assertNotNull(DB::connection('pgsql_admin')->table('reporting_relationships')->where('manager_employment_id', $team['manager']['employment']->id)->value('ends_on'));
    }

    #[Test]
    public function the_managers_approval_racing_the_requesters_end_never_deadlocks(): void
    {
        $team = $this->team();

        [$end, $decision] = $this->race($team, 'requester', 'manager');

        $this->assertSame('ok:separated', $end);
        $this->assertSame('rejected:LEAVE_EMPLOYMENT_NOT_ELIGIBLE', $decision, 'the leave falls after the last day, and no deadlock');
        $this->assertSame('submitted', DB::connection('pgsql_admin')->table('leave_requests')->where('id', $team['request']->id)->value('status'));
        $this->assertSame(0, DB::connection('pgsql_admin')->table('leave_ledger_entries')->where('employment_record_id', $team['requester']['employment']->id)->where('kind', 'consumption')->count());
    }
}
