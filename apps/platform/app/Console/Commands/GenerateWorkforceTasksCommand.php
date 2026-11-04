<?php

namespace App\Console\Commands;

use App\Domain\WorkforceTasks\Application\TaskGenerationService;
use App\Models\School;
use App\Support\Observability\ErrorReporter;
use App\Support\Observability\SafeException;
use App\Support\Observability\SchedulerHeartbeatRecorder;
use App\Support\Tenancy\SchoolStatus;
use Illuminate\Console\Command;

/**
 * HR.13 (owner decision 2026-11-04, "scheduled"): nightly, each ACTIVE
 * School's recurring staff tasks for its own School-local today
 * (TaskGenerationService: idempotent, resumable, complete roster). A
 * suspended or provisioning School is skipped (rule 86; the service also
 * re-checks the School per batch). One School's failure is reported and the
 * rest still run; its run resumes from the cursor next time.
 */
class GenerateWorkforceTasksCommand extends Command
{
    protected $signature = 'workforce-tasks:generate';

    protected $description = "Generate today's recurring staff tasks for every active School (idempotent, resumable).";

    public function handle(TaskGenerationService $generation, SchedulerHeartbeatRecorder $heartbeats): int
    {
        $failures = 0;
        School::query()->where('status', SchoolStatus::Active->value)->orderBy('id')->each(function (School $school) use ($generation, &$failures): void {
            try {
                $run = $generation->run($school);
                $this->line("{$school->id}: {$run->occurrence_on->toDateString()} examined={$run->examined} created={$run->created} existing={$run->existing} not_matched={$run->not_matched}");
            } catch (\Throwable $e) {
                $failures++;
                app(ErrorReporter::class)->report($e, 'platform.workforce_tasks_generate.failed', 'scheduler', 'workforce-tasks-generate');
                $this->error("{$school->id}: generation failed (".SafeException::code($e).'); it resumes on the next run.');
            }
        });

        if ($failures > 0) {
            $heartbeats->recordFailure('workforce-tasks-generate', 'school_failures');

            return self::FAILURE;
        }
        $heartbeats->recordSuccess('workforce-tasks-generate');

        return self::SUCCESS;
    }
}
