<?php

namespace Tests\Feature\Transport;

use App\Domain\Students\Infrastructure\Student;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof (checkpoint brief section 33): two
 * GENUINELY separate OS processes -- not two sequential calls in one
 * PHP process -- both attempt to assign the SAME Student to two
 * DIFFERENT Routes at the same time, against real PostgreSQL. Mirrors
 * LibraryLoanCheckoutConcurrencyTest's exact pattern:
 * TransportStudentAssignmentService::assign() takes `lockForUpdate()`
 * on the SAME Student row both processes target -- PostgreSQL itself
 * serializes the two transactions on that row lock before either
 * reaches the INSERT, so the loser is caught by its own post-lock
 * "already assigned" check (StudentAlreadyAssignedException), never
 * the raw `transport_student_assignments_one_active_per_student`
 * unique-constraint path -- that index still exists and is proven
 * directly by
 * tests/Feature/Postgres/TransportStudentAssignmentsRlsIsolationTest::the_database_rejects_a_second_active_assignment_for_the_same_student.
 * This test's job is to prove the END STATE under real concurrency
 * (exactly one active assignment survives), not to force a specific
 * exception class through a specific internal code path.
 */
class TransportStudentAssignmentConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->school->delete(); // cascades transport_routes/students/student_assignments
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_processes_assigning_the_same_student_leave_exactly_one_active_assignment(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $routeA = $this->createTransportRoute($this->school);
        $routeB = $this->createTransportRoute($this->school);
        $student = $context->withSchool($this->school, fn () => Student::factory()->for($this->school, 'school')->create(['status' => 'active']));

        $script = __DIR__.'/../../Support/assign-transport-student.php';
        $processA = new Process(['php', $script, $this->school->id, $student->id, $routeA->id]);
        $processB = new Process(['php', $script, $this->school->id, $student->id, $routeB->id]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $assignedCount = count(array_filter($outputs, fn ($o) => $o === 'assigned'));

        $this->assertSame(1, $assignedCount, 'Exactly one of the two concurrent assignments must succeed.');
        $rejectedOutputs = array_filter($outputs, fn ($o) => $o !== 'assigned');
        $this->assertCount(1, $rejectedOutputs);
        $this->assertStringContainsString('StudentAlreadyAssignedException', reset($rejectedOutputs));

        $activeAssignments = $context->withSchool(
            $this->school,
            fn () => TransportStudentAssignment::query()->where('student_id', $student->id)->where('status', 'active')->count(),
        );
        $this->assertSame(1, $activeAssignments, 'The database must contain exactly one active Transport assignment for this Student.');
    }
}
