<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof (checkpoint brief section 12/43,
 * mirroring EmployeeNumberConcurrencyTest's/
 * AcademicYearActivationConcurrencyTest's exact real-process pattern):
 * two GENUINELY separate OS processes -- not sequential calls in one
 * PHP process -- race
 * App\Domain\HR\Application\ReportingHierarchyService::setManager() for
 * the SAME pair of Assignments in OPPOSITE directions (Process 1: A's
 * manager -> B; Process 2: B's manager -> A) against real PostgreSQL.
 * Deterministic ascending-id row locking
 * (`orderBy('id')->lockForUpdate()`, see ReportingHierarchyService's
 * own docblock) must let exactly one succeed and reject the other with
 * ReportingHierarchyCycleException -- never both succeeding (which
 * would produce a real, persisted A<->B cycle) and never both being
 * rejected.
 *
 * Deliberately does NOT use DatabaseTransactions for the fixtures this
 * test creates (see $connectionsToTransact) -- the subprocesses are
 * separate PostgreSQL sessions and can never see this test process's
 * uncommitted rows.
 */
class ReportingHierarchyConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->school->delete(); // cascades employees, employment_records, employee_assignments
        }

        parent::tearDown();
    }

    #[Test]
    public function concurrent_opposite_direction_manager_assignment_produces_exactly_one_rejection(): void
    {
        $this->school = $this->createSchool();
        $position = $this->createPosition($this->school);

        $employeeA = $this->createEmployee($this->school);
        $employmentA = $this->createEmploymentRecord($employeeA);
        $a = $this->createEmployeeAssignment($employmentA, $position);

        $employeeB = $this->createEmployee($this->school);
        $employmentB = $this->createEmploymentRecord($employeeB);
        $b = $this->createEmployeeAssignment($employmentB, $position);

        $script = __DIR__.'/../../Support/set-assignment-manager.php';

        $process1 = new Process(['php', $script, $this->school->id, $a->id, $b->id]); // A's manager -> B
        $process2 = new Process(['php', $script, $this->school->id, $b->id, $a->id]); // B's manager -> A
        $process1->start();
        $process2->start();
        $process1->wait();
        $process2->wait();

        $outputs = [trim($process1->getOutput()), trim($process2->getOutput())];

        $succeeded = array_filter($outputs, fn (string $o) => str_starts_with($o, 'ok:'));
        $rejected = array_filter($outputs, fn (string $o) => str_starts_with($o, 'rejected:'));

        $this->assertCount(1, $succeeded, 'Exactly one of the two opposite-direction concurrent attempts must succeed; got: '.implode(', ', $outputs));
        $this->assertCount(1, $rejected, 'Exactly one of the two opposite-direction concurrent attempts must be rejected; got: '.implode(', ', $outputs));
        $this->assertStringContainsString('ReportingHierarchyCycleException', implode(', ', $rejected));

        $context = app(TenantContext::class);
        [$freshA, $freshB] = $context->withSchool(
            $this->school,
            fn () => [EmployeeAssignment::query()->findOrFail($a->id), EmployeeAssignment::query()->findOrFail($b->id)],
        );

        $this->assertFalse(
            $freshA->manager_assignment_id === $b->id && $freshB->manager_assignment_id === $a->id,
            'A real A<->B cycle must never exist in the database after the race.',
        );
    }
}
