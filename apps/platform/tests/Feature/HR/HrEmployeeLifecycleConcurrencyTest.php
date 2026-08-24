<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.13 -- REQUIRED real-process concurrency proof (checkpoint
 * brief sections 35/36/37/65), mirroring EmployeeNumberConcurrencyTest's/
 * ReportingHierarchyConcurrencyTest's/HrEmployeeImportConcurrencyTest's
 * established real-process pattern: several GENUINELY separate OS
 * processes race EmployeeLifecycleService::rehire()/separate() against
 * real PostgreSQL -- not a sequential simulation.
 *
 * Deliberately does NOT use DatabaseTransactions for the fixtures this
 * test creates (see $connectionsToTransact) -- the subprocesses are
 * separate PostgreSQL sessions and can never see this test process's
 * uncommitted rows.
 */
class HrEmployeeLifecycleConcurrencyTest extends TestCase
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

        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function five_concurrent_rehires_for_the_same_employee_produce_exactly_one_new_employment(): void
    {
        $this->school = $this->createSchool();
        $actor = $this->fullHrActor($this->school);
        $employee = $this->createEmployee($this->school);
        $firstEmployment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2020-01-01'], $actor);
        app(EmploymentService::class)->end($firstEmployment, '2022-12-31', $actor, 'separated');

        $script = __DIR__.'/../../Support/rehire-employee.php';
        $processCount = 5;
        $processes = [];

        for ($i = 0; $i < $processCount; $i++) {
            $process = new Process(['php', $script, $this->school->id, $actor->id, $employee->id, '2026-06-01']);
            $process->start();
            $processes[] = $process;
        }

        foreach ($processes as $process) {
            $process->wait();
        }

        $outputs = array_map(fn (Process $p) => trim($p->getOutput()), $processes);

        foreach ($outputs as $output) {
            $this->assertMatchesRegularExpression('/^(created:|overlap)/', $output, "Every concurrent rehire attempt must resolve safely (never a raw exception); got: {$output}");
        }

        $createdCount = count(array_filter($outputs, fn (string $o) => str_starts_with($o, 'created:')));
        $overlapCount = count(array_filter($outputs, fn (string $o) => $o === 'overlap'));

        $this->assertSame(1, $createdCount, 'Exactly one concurrent rehire must succeed; got: '.implode(', ', $outputs));
        $this->assertSame($processCount - 1, $overlapCount, 'Every other attempt must be a safe, translated EmploymentOverlapException result, never a raw exception.');

        $context = app(TenantContext::class);
        $count = $context->withSchool($this->school, fn () => EmploymentRecord::query()->where('employee_id', $employee->id)->count());
        $this->assertSame(2, $count, 'Exactly two EmploymentRecords must exist -- the original (separated) plus exactly one new rehire, never more.');
    }

    #[Test]
    public function two_concurrent_separations_of_the_same_employment_produce_exactly_one_transition(): void
    {
        $this->school = $this->createSchool();
        $actor = $this->fullHrActor($this->school);
        $employee = $this->createEmployee($this->school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);

        $script = __DIR__.'/../../Support/separate-employment.php';
        $process1 = new Process(['php', $script, $this->school->id, $actor->id, $employment->id, '2026-06-15']);
        $process2 = new Process(['php', $script, $this->school->id, $actor->id, $employment->id, '2026-06-15']);
        $process1->start();
        $process2->start();
        $process1->wait();
        $process2->wait();

        $outputs = [trim($process1->getOutput()), trim($process2->getOutput())];

        foreach ($outputs as $output) {
            $this->assertMatchesRegularExpression('/^(separated:|already_ended)/', $output, "Every concurrent separation attempt must resolve safely (never a raw exception); got: {$output}");
        }

        $separatedCount = count(array_filter($outputs, fn (string $o) => str_starts_with($o, 'separated:')));
        $alreadyEndedCount = count(array_filter($outputs, fn (string $o) => $o === 'already_ended'));

        $this->assertSame(1, $separatedCount, 'Exactly one concurrent separation must actually perform the transition; got: '.implode(', ', $outputs));
        $this->assertSame(1, $alreadyEndedCount, 'The other must receive a safe, translated EmploymentAlreadyEndedException result, never corrupt the row or throw a raw exception.');

        $context = app(TenantContext::class);
        $fresh = $context->withSchool($this->school, fn () => EmploymentRecord::query()->where('id', $employment->id)->first());
        $this->assertSame('2026-06-15', $fresh->ends_on->toDateString(), 'No duplicated/conflicting end date can result from the race.');
    }

    #[Test]
    public function a_concurrent_separation_and_rehire_never_produce_two_overlapping_open_employments(): void
    {
        $this->school = $this->createSchool();
        $actor = $this->fullHrActor($this->school);
        $employee = $this->createEmployee($this->school);
        // A prior, already-separated Employment satisfies rehire()'s
        // history precondition; THIS currently-open Employment is what
        // both concurrent processes race against.
        $priorEmployment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2018-01-01'], $actor);
        app(EmploymentService::class)->end($priorEmployment, '2019-12-31', $actor, 'separated');
        $currentEmployment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2020-01-01'], $actor);

        $separateScript = __DIR__.'/../../Support/separate-employment.php';
        $rehireScript = __DIR__.'/../../Support/rehire-employee.php';

        $separateProcess = new Process(['php', $separateScript, $this->school->id, $actor->id, $currentEmployment->id, '2026-06-15']);
        $rehireProcess = new Process(['php', $rehireScript, $this->school->id, $actor->id, $employee->id, '2026-06-15']);
        $separateProcess->start();
        $rehireProcess->start();
        $separateProcess->wait();
        $rehireProcess->wait();

        $separateOutput = trim($separateProcess->getOutput());
        $rehireOutput = trim($rehireProcess->getOutput());

        $this->assertMatchesRegularExpression('/^(separated:|already_ended)/', $separateOutput, "Separation must resolve safely; got: {$separateOutput}");
        $this->assertMatchesRegularExpression('/^(created:|overlap)/', $rehireOutput, "Rehire must resolve safely; got: {$rehireOutput}");

        // No ad hoc lock was introduced for this race -- PostgreSQL's
        // MVCC (create()'s Employee-row lock always reading only
        // COMMITTED EmploymentRecord state) already guarantees this
        // invariant by construction (checkpoint 8A.13 section 37).
        $context = app(TenantContext::class);
        $openCount = $context->withSchool($this->school, fn () => EmploymentRecord::query()->where('employee_id', $employee->id)->whereNull('ends_on')->count());
        $this->assertLessThanOrEqual(1, $openCount, 'At most one open EmploymentRecord may exist after the race -- never two overlapping open Employments.');

        $totalCount = $context->withSchool($this->school, fn () => EmploymentRecord::query()->where('employee_id', $employee->id)->count());
        $this->assertContains($totalCount, [2, 3], 'Either the rehire lost the race (2 records: prior + current, now ended) or won it (3 records: prior + current-now-ended + new rehire).');
    }
}
