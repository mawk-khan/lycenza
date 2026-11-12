<?php

namespace Tests\Feature\Leave;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Models\School;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\PurgesCommittedHrxFixtures;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.R1-C34 (WO-16), on COMMITTED data with a real second PostgreSQL session: a link that would make
 * a leave decider known to be the submitter, held open (uncommitted) in another process.
 *
 * A leave decision shares no lock with a link of ANOTHER Employee, and needs none: it reads only
 * committed history, so while the link is uncommitted the decision is taken on the history as it
 * stands and is ordered before the link (the link reads nothing the decision writes, so the result is
 * the serial one). Once the link commits, the next decision by that account is refused. A link of the
 * requester's OWN Employee is ordered by the Employee row instead (an UPDATE of employees).
 */
class LeaveKnownSamePersonConcurrencyTest extends TestCase
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

    #[Test]
    public function an_uncommitted_link_is_invisible_to_the_decision_and_a_committed_one_refuses_the_next(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $w = $this->leaveWorld();
        $this->schools[] = $w['school'];
        $this->workingWeek($w['school'], $w['admin']);
        $requester = $this->staffMember($w['school']);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $requester['employment']->id, $w['policy']->id, '2026-04-01', null, $w['admin']);
        $this->allocate($w, 24, null, $requester['employment']);
        $first = $this->submitLeave($w, '2026-10-12', '2026-10-12', 'full', null, $requester['employment']);
        $second = $this->submitLeave($w, '2026-10-13', '2026-10-13', 'full', null, $requester['employment']);

        $clerk = $this->leaveClerk($w['school']);
        $alt = $this->leaveAdmin($w['school']);
        $hr = $this->fullHrActor($w['school']);
        $own = $this->createEmployee($w['school'], ['user_id' => null]);
        $this->inSchool($w['school'], fn () => app(EmployeeService::class)->linkUser(Employee::query()->findOrFail($own->id), $clerk->id, $hr));
        $this->inSchool($w['school'], fn () => app(EmployeeService::class)->unlinkUser(Employee::query()->findOrFail($own->id), $hr));

        $dir = sys_get_temp_dir().'/c34_'.bin2hex(random_bytes(6));
        mkdir($dir);
        $holder = new Process(['php', __DIR__.'/../../Support/payroll-reconciliation-op.php', 'service-link', $w['school']->id, $own->id, $alt->id, $hr->id], null, ['CONCURRENCY_HOLD_DIR' => $dir]);
        $holder->setTimeout(180);
        $holder->start();
        try {
            $deadline = microtime(true) + 90;
            while (! file_exists($dir.'/acted')) {
                if (! $holder->isRunning() || microtime(true) > $deadline) {
                    $this->fail("The link never reached its held commit.\n".$holder->getOutput().$holder->getErrorOutput());
                }
                usleep(2_000);
            }
            // The link exists only in the other session: the decision cannot see it, and decides.
            $this->assertSame('approved', app(LeaveRequestService::class)->approve($w['school'], $first->id, $alt)->status);
        } finally {
            touch($dir.'/release');
            $holder->wait();
        }
        $this->assertSame('ok', trim($holder->getOutput()));

        try {
            app(LeaveRequestService::class)->approve($w['school'], $second->id, $alt);
            $this->fail('a decision after the link committed must be refused');
        } catch (LeaveException $e) {
            $this->assertSame('LEAVE_SAME_PERSON_DECISION', $e->errorCode());
        }
        $this->assertSame(['approved', 'submitted'], $this->inSchool($w['school'], fn () => [$first->fresh()->status, $second->fresh()->status]), 'the first decision stands; it is never re-read');
    }
}
