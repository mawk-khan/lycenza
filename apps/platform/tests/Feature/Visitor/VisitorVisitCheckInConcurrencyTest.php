<?php

namespace Tests\Feature\Visitor;

use App\Domain\Visitor\Infrastructure\VisitorVisit;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof (checkpoint brief section 14/42):
 * two GENUINELY separate OS processes -- not two sequential calls in
 * one PHP process -- both attempt to check the SAME Visitor into two
 * DIFFERENT Campuses at the same time, against real PostgreSQL.
 * Mirrors TransportStudentAssignmentConcurrencyTest's exact pattern:
 * VisitorVisitService::checkIn() takes `lockForUpdate()` on the SAME
 * Visitor row both processes target -- PostgreSQL itself serializes
 * the two transactions on that row lock before either reaches the
 * INSERT, so the loser is caught by its own post-lock "already checked
 * in" check (VisitorAlreadyCheckedInException), never the raw
 * `visitor_visits_one_active_per_visitor` unique-constraint path --
 * that index still exists and is proven directly by
 * tests/Feature/Postgres/VisitorVisitsRlsIsolationTest::the_database_rejects_a_second_active_visit_for_the_same_visitor.
 * This test's job is to prove the END STATE under real concurrency
 * (exactly one active Visit survives), not to force a specific
 * exception class through a specific internal code path.
 *
 * Deliberately does NOT use DatabaseTransactions for the fixtures this
 * test creates (see $connectionsToTransact) -- the two subprocesses
 * are separate PostgreSQL sessions and can never see this test
 * process's uncommitted rows. Mirrors
 * LibraryLoanCheckoutConcurrencyTest/TransportStudentAssignmentConcurrencyTest's
 * exact pattern.
 */
class VisitorVisitCheckInConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->deleteSchoolAsAdmin($this->school); // cascades visitors/visitor_visits
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_processes_checking_in_the_same_visitor_leave_exactly_one_active_visit(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $campusA = $this->createCampus($this->school);
        $campusB = $this->createCampus($this->school);
        $visitor = $this->createVisitor($this->school);

        $script = __DIR__.'/../../Support/check-in-visitor.php';
        $processA = new Process(['php', $script, $this->school->id, $visitor->id, $campusA->id]);
        $processB = new Process(['php', $script, $this->school->id, $visitor->id, $campusB->id]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $checkedInCount = count(array_filter($outputs, fn ($o) => $o === 'checked_in'));

        $this->assertSame(1, $checkedInCount, 'Exactly one of the two concurrent check-ins must succeed.');
        $rejectedOutputs = array_filter($outputs, fn ($o) => $o !== 'checked_in');
        $this->assertCount(1, $rejectedOutputs);
        $this->assertStringContainsString('VisitorAlreadyCheckedInException', reset($rejectedOutputs));

        $activeVisits = $context->withSchool(
            $this->school,
            fn () => VisitorVisit::query()->where('visitor_id', $visitor->id)->where('status', 'checked_in')->count(),
        );
        $this->assertSame(1, $activeVisits, 'The database must contain exactly one active Visit for this Visitor.');
    }
}
