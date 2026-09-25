<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof (docs/modules/HR.md "Employee
 * identifier strategy", mirroring
 * AcademicYearActivationConcurrencyTest's exact real-process pattern):
 * several GENUINELY separate OS processes -- not sequential calls in
 * one PHP process -- all attempt to create an Employee for the SAME
 * School at the same time, against real PostgreSQL. The database's own
 * row lock (SELECT ... FOR UPDATE inside EmployeeNumberAllocator) plus
 * the final unique(school_id, employee_number) backstop is what makes
 * this safe; this test proves the final state, not just that the
 * application code "looks" correct.
 *
 * Deliberately does NOT use DatabaseTransactions for the fixtures this
 * test creates (see $connectionsToTransact) -- the subprocesses are
 * separate PostgreSQL sessions and can never see this test process's
 * uncommitted rows.
 */
class EmployeeNumberConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->deleteSchoolAsAdmin($this->school); // cascades employees, hr_employee_number_counters
        }

        parent::tearDown();
    }

    #[Test]
    public function five_real_concurrent_processes_creating_employees_for_the_same_school_receive_five_distinct_numbers(): void
    {
        $this->school = $this->createSchool();
        // Created and committed BEFORE the subprocesses spawn (this test
        // uses no DB transactions -- $connectionsToTransact = [] above
        // -- so it is genuinely visible to every separate OS process
        // below, exactly like $this->school itself).
        $actor = $this->fullHrActor($this->school);

        $script = __DIR__.'/../../Support/create-employee.php';
        $processCount = 5;
        $processes = [];

        for ($i = 0; $i < $processCount; $i++) {
            $process = new Process(['php', $script, $this->school->id, "Concurrent Employee {$i}", $actor->id]);
            $process->start();
            $processes[] = $process;
        }

        foreach ($processes as $process) {
            $process->wait();
        }

        $outputs = array_map(fn (Process $p) => trim($p->getOutput()), $processes);

        foreach ($outputs as $output) {
            $this->assertMatchesRegularExpression('/^EMP-\d{6}$/', $output, "Every concurrent attempt must succeed with a well-formed number; got: {$output}");
        }

        $this->assertCount($processCount, array_unique($outputs), 'All concurrently allocated employee numbers must be distinct -- got: '.implode(', ', $outputs));

        $context = app(TenantContext::class);
        $storedNumbers = $context->withSchool(
            $this->school,
            fn () => Employee::query()->where('school_id', $this->school->id)->pluck('employee_number')->all(),
        );

        $this->assertCount($processCount, $storedNumbers);
        $this->assertCount($processCount, array_unique($storedNumbers), 'The database must contain exactly '.$processCount.' distinct employee numbers for this School.');
        sort($storedNumbers);
        $this->assertSame(
            ['EMP-000001', 'EMP-000002', 'EMP-000003', 'EMP-000004', 'EMP-000005'],
            $storedNumbers,
            'Numbers must be exactly 1..N with no gaps and no duplicates under real concurrency.',
        );
    }
}
