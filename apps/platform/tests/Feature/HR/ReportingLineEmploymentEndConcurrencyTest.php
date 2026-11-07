<?php

namespace Tests\Feature\HR;

use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Models\School;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;
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

    private function team(bool $onACampus = false): array
    {
        $w = $this->leaveWorld();
        $this->schools[] = $w['school'];
        $this->workingWeek($w['school'], $w['admin']);
        $campus = $onACampus ? $this->createCampus($w['school']) : null;
        $people = [];
        foreach (['requester' => ['staff_self_service'], 'manager' => ['teacher', 'line_manager'], 'other' => ['teacher', 'line_manager']] as $who => $roles) {
            $user = $this->createUser();
            $membership = $this->createMembership($user, $w['school']);
            foreach ($roles as $role) {
                $this->assignSchoolRole($membership, $role);
            }
            $employment = $this->createEmploymentRecord($this->createEmployee($w['school'], ['user_id' => $user->id]), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
            $assignment = $this->createEmployeeAssignment($employment, $this->createPosition($w['school']), ['campus_id' => $campus?->id, 'starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true]);
            app(CapabilityResolver::class)->forgetCache($user, $w['school']);
            $people[$who] = compact('user', 'employment', 'assignment');
        }
        $this->reportTo($people['requester']['assignment'], $people['manager']['assignment']);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $people['requester']['employment']->id, $w['policy']->id, '2026-04-01', null, $w['admin']);
        $this->allocate($w, 24, null, $people['requester']['employment']);
        $request = $this->submitLeave($w, '2027-02-01', '2027-02-01', 'full', null, $people['requester']['employment']);

        return $w + $people + ['campus' => $campus, 'request' => $request, 'hr' => $this->fullHrActor($w['school'])];
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

    #[Test]
    public function the_managers_approval_racing_a_placement_change_of_the_requester_never_deadlocks(): void
    {
        // The independent review's cycle: `holdManagerOf()` held the requester's employment without their Employee,
        // and a placement change takes that Employee first. A gate on the leave type pauses the approval at the
        // point where the first version of this correction had the two the wrong way round.
        $team = $this->team();
        $school = $team['school'];
        $campus = $this->createCampus($school);
        $position = $this->createPosition($school);
        $dir = sys_get_temp_dir().'/line_'.bin2hex(random_bytes(6));
        mkdir($dir);
        [$decideSession, $moveSession] = ['line_dec_'.bin2hex(random_bytes(4)), 'line_mov_'.bin2hex(random_bytes(4))];

        $gate = new Process($this->op('gate-type', $school->id, $team['type']->id), null, ['CONCURRENCY_HOLD_DIR' => $dir]);
        $gate->setTimeout(120);
        $gate->start();
        $deadline = microtime(true) + 60;
        while (! file_exists($dir.'/acted') && microtime(true) < $deadline) {
            usleep(2_000);
        }
        $this->assertFileExists($dir.'/acted', 'the gate never held the leave type: '.$gate->getErrorOutput());

        $decide = new Process($this->op('manager-approve', $school->id, $team['request']->id, $team['manager']['user']->id), null, ['CONCURRENCY_SESSION_NAME' => $decideSession]);
        $decide->setTimeout(120);
        $decide->start();
        $this->waitBlocked($decideSession, $decide);

        $move = new Process($this->op('transfer', $school->id, $team['requester']['assignment']->id, CarbonImmutable::parse(SchoolTimezone::today($school))->addDays(30)->toDateString(), $position->id, $campus->id, $team['hr']->id), null, ['CONCURRENCY_SESSION_NAME' => $moveSession]);
        $move->setTimeout(120);
        $move->start();
        $this->waitBlocked($moveSession, $move);

        touch($dir.'/release');
        $gate->wait();
        $decide->wait();
        $move->wait();
        @unlink($dir.'/acted');
        @unlink($dir.'/release');
        @rmdir($dir);

        $this->assertSame('ok:approved', trim($decide->getOutput()));
        $this->assertStringStartsWith('ok:', trim($move->getOutput()));
    }

    #[Test]
    public function a_managers_rejection_racing_a_campus_writer_of_that_line_never_deadlocks(): void
    {
        // The delta review's cycle. A Campus writer took the two Employees in id order, and a decider already holds the
        // manager's. With the subordinate's id lower, each then wanted the other's. A share lock on the manager's
        // Employee parks the writer at that Employee without stopping the decider, which forces the order.
        $team = $this->team(onACampus: true);
        $school = $team['school'];
        $this->assertLessThan($team['manager']['employment']->employee_id, $team['requester']['employment']->employee_id, 'the order the cycle needs');
        $writer = $this->createUser();
        $this->assignSchoolRole($this->createMembership($writer, $school), 'hr_officer', $team['campus']->id);
        $dir = sys_get_temp_dir().'/line_'.bin2hex(random_bytes(6));
        mkdir($dir);
        [$writeSession, $decideSession] = ['line_wri_'.bin2hex(random_bytes(4)), 'line_dec_'.bin2hex(random_bytes(4))];

        $gate = new Process($this->op('gate-employee', $school->id, $team['manager']['employment']->employee_id), null, ['CONCURRENCY_HOLD_DIR' => $dir]);
        $gate->setTimeout(120);
        $gate->start();
        $deadline = microtime(true) + 60;
        while (! file_exists($dir.'/acted') && microtime(true) < $deadline) {
            usleep(2_000);
        }
        $this->assertFileExists($dir.'/acted', 'the gate never held the Employee: '.$gate->getErrorOutput());

        $write = new Process($this->op('line-remove', $school->id, $team['requester']['employment']->id, SchoolTimezone::today($school), $writer->id), null, ['CONCURRENCY_SESSION_NAME' => $writeSession]);
        $write->setTimeout(120);
        $write->start();
        $this->waitBlocked($writeSession, $write);

        $decide = new Process($this->op('manager-reject', $school->id, $team['request']->id, $team['manager']['user']->id), null, ['CONCURRENCY_SESSION_NAME' => $decideSession]);
        $decide->setTimeout(120);
        $decide->start();
        $deadline = microtime(true) + 60;
        while ($decide->isRunning() && microtime(true) < $deadline) {
            $row = DB::connection('pgsql_admin')->selectOne('select wait_event_type from pg_stat_activity where application_name = ?', [$decideSession]);
            if ($row !== null && $row->wait_event_type === 'Lock') {
                break;
            }
            usleep(2_000);
        }

        touch($dir.'/release');
        $gate->wait();
        $decide->wait();
        $write->wait();
        @unlink($dir.'/acted');
        @unlink($dir.'/release');
        @rmdir($dir);

        $this->assertSame('ok:rejected', trim($decide->getOutput()));
        $this->assertStringStartsWith('ok:', trim($write->getOutput()));
    }
}
