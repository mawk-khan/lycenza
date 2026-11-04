<?php

namespace Tests\Feature\WorkforceTasks;

use App\Domain\WorkforceTasks\Application\TaskCatalogueService;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.13: two generation workers racing the same School-day in two real OS
 * processes (committed fixtures, real clock). They serialize on the run row
 * per batch and the database's one-occurrence index: every employment gets
 * exactly one task, whichever worker created it.
 */
class WorkforceTaskConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->deleteSchoolAsAdmin($this->school);
        }
        parent::tearDown();
    }

    #[Test]
    public function two_concurrent_workers_create_every_occurrence_exactly_once(): void
    {
        $this->school = $this->createSchool();
        $hr = $this->createUserWithCapabilities($this->school, ['hr.tasks.configure']);
        $position = $this->createPosition($this->school);
        foreach (range(1, 120) as $i) {
            $record = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2024-01-01', 'status' => 'active', 'staff_type' => 'teaching']);
            $this->createEmployeeAssignment($record, $position, ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true]);
        }
        $catalogue = app(TaskCatalogueService::class);
        $category = $catalogue->createCategory($this->school, 'ops', 'Operations', $hr);
        $catalogue->createTemplate($this->school, 'daily', [
            'title' => 'Daily check', 'category_id' => $category->id, 'completion_mode' => 'self', 'frequency' => 'daily', 'weekdays' => 127,
            'due_days' => 0, 'applies_teaching' => true, 'effective_from' => SchoolTimezone::today($this->school),
        ], $hr);

        $script = __DIR__.'/../../Support/workforce-task-generate.php';
        $processes = array_map(function () use ($script): Process {
            $process = new Process(['php', $script, $this->school->id, '20']);
            $process->start();

            return $process;
        }, [1, 2]);
        foreach ($processes as $process) {
            $process->wait();
        }
        $outputs = array_map(fn (Process $p) => trim($p->getOutput()), $processes);

        foreach ($outputs as $output) {
            $this->assertMatchesRegularExpression('/^ok:\d+:\d+$/', $output);
        }
        $count = app(TenantContext::class)->withSchool($this->school, fn () => DB::table('workforce_tasks')->count());
        $this->assertSame(120, $count, 'every employment exactly once: '.implode(' | ', $outputs));
        // Both workers report the ONE shared run for the School-day: its counts reconcile once.
        $run = app(TenantContext::class)->withSchool($this->school, fn () => DB::table('workforce_task_generation_runs')->sole());
        $this->assertSame(['completed', 120, 120, 0], [$run->status, $run->examined, $run->created, $run->existing]);
    }
}
