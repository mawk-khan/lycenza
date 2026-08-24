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
 * Phase 8A.12 -- REQUIRED real-concurrency proof (checkpoint brief
 * section 78, mirroring EmployeeNumberConcurrencyTest's/
 * ReportingHierarchyConcurrencyTest's established real-process
 * pattern): several GENUINELY separate OS processes race
 * `EmployeeImportService::import()` against real PostgreSQL.
 *
 * Deliberately does NOT use DatabaseTransactions for the fixtures this
 * test creates (see $connectionsToTransact) -- the subprocesses are
 * separate PostgreSQL sessions and can never see this test process's
 * uncommitted rows.
 */
class HrEmployeeImportConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->school->delete(); // cascades employees, employment_records, employee_assignments, memberships
        }

        parent::tearDown();
    }

    #[Test]
    public function five_concurrent_imports_linked_to_the_same_user_produce_exactly_one_employee(): void
    {
        $this->school = $this->createSchool();
        $actor = $this->fullHrActor($this->school);
        $linkedUser = $this->createUser();
        $this->createMembership($linkedUser, $this->school);

        $script = __DIR__.'/../../Support/import-employee.php';
        $processCount = 5;
        $processes = [];

        for ($i = 0; $i < $processCount; $i++) {
            $process = new Process(['php', $script, $this->school->id, $actor->id, 'Concurrent Same User', $linkedUser->id]);
            $process->start();
            $processes[] = $process;
        }

        foreach ($processes as $process) {
            $process->wait();
        }

        $outputs = array_map(fn (Process $p) => trim($p->getOutput()), $processes);

        foreach ($outputs as $output) {
            $this->assertMatchesRegularExpression('/^(created|duplicate_exact):/', $output, "Every concurrent attempt must resolve safely (never a raw exception); got: {$output}");
        }

        $createdCount = count(array_filter($outputs, fn (string $o) => str_starts_with($o, 'created:')));
        $duplicateCount = count(array_filter($outputs, fn (string $o) => str_starts_with($o, 'duplicate_exact:')));

        $this->assertSame(1, $createdCount, 'Exactly one concurrent attempt must succeed in creating the Employee; got: '.implode(', ', $outputs));
        $this->assertSame($processCount - 1, $duplicateCount, 'Every other attempt must be translated into a safe duplicate_exact result, never a raw exception.');

        $context = app(TenantContext::class);
        $employeeCount = $context->withSchool(
            $this->school,
            fn () => Employee::query()->where('school_id', $this->school->id)->where('user_id', $linkedUser->id)->count(),
        );
        $this->assertSame(1, $employeeCount, 'The database must contain exactly one Employee for this User, never two.');
    }

    #[Test]
    public function concurrent_imports_for_different_employees_receive_unique_employee_numbers(): void
    {
        $this->school = $this->createSchool();
        $actor = $this->fullHrActor($this->school);

        $script = __DIR__.'/../../Support/import-employee.php';
        $processCount = 5;
        $processes = [];

        for ($i = 0; $i < $processCount; $i++) {
            $process = new Process(['php', $script, $this->school->id, $actor->id, "Concurrent Distinct Employee {$i}"]);
            $process->start();
            $processes[] = $process;
        }

        foreach ($processes as $process) {
            $process->wait();
        }

        $outputs = array_map(fn (Process $p) => trim($p->getOutput()), $processes);

        foreach ($outputs as $output) {
            $this->assertStringStartsWith('created:', $output, "Every distinct-name concurrent import must succeed; got: {$output}");
        }

        $context = app(TenantContext::class);
        $numbers = $context->withSchool(
            $this->school,
            fn () => Employee::query()->where('school_id', $this->school->id)->pluck('employee_number')->all(),
        );

        $this->assertCount($processCount, $numbers);
        $this->assertCount($processCount, array_unique($numbers), 'The employee-number allocator must remain concurrency-safe when reached through import -- no duplicate numbers.');
    }

    #[Test]
    public function concurrent_imports_with_the_same_name_and_no_authoritative_key_both_succeed_as_separate_employees(): void
    {
        $this->school = $this->createSchool();
        $actor = $this->fullHrActor($this->school);

        $script = __DIR__.'/../../Support/import-employee.php';
        $process1 = new Process(['php', $script, $this->school->id, $actor->id, 'Same Name Person']);
        $process2 = new Process(['php', $script, $this->school->id, $actor->id, 'Same Name Person']);
        $process1->start();
        $process2->start();
        $process1->wait();
        $process2->wait();

        $outputs = [trim($process1->getOutput()), trim($process2->getOutput())];

        // No database uniqueness rule exists on full_name -- both
        // genuinely concurrent attempts may legitimately succeed
        // (checkpoint brief section 39: "the domain may legitimately
        // contain two people with the same name"). Neither raw
        // exception nor an incorrect forced merge is acceptable.
        foreach ($outputs as $output) {
            $this->assertStringStartsWith('created:', $output, "Neither concurrent same-name attempt may fail or be silently merged; got: {$output}");
        }

        $context = app(TenantContext::class);
        $count = $context->withSchool(
            $this->school,
            fn () => Employee::query()->where('school_id', $this->school->id)->count(),
        );
        $this->assertSame(2, $count, 'Two genuinely concurrent same-name imports with no authoritative key must produce two Employees, never a forced merge.');
    }
}
