<?php

namespace App\Jobs;

use App\Domain\Automation\Application\AutomationExecutionService;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs one Automation execution (ADR 0043 §7). Dispatched after commit by
 * AutomationTriggerConsumer and by `automation:executions-redispatch`.
 * School context comes from the explicit constructor argument (not
 * TenantScoped: it is dispatched from an outbox consumer or the scheduler,
 * never a request) and is cleared in `finally` (CLAUDE.md rules 21, 57).
 *
 * `tries = 1`: retries are domain-level, on the execution row, and bounded
 * (rule 59); `$timeout` stays below the queue's `retry_after` (rule 58).
 * Duplicate or concurrent dispatch is harmless: the service's lease claim
 * lets only one worker act.
 */
class RunAutomationExecutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $executionId,
    ) {}

    public function handle(AutomationExecutionService $service, TenantContext $context): void
    {
        $school = School::query()->find($this->schoolId);

        if ($school === null) {
            return;
        }

        try {
            $context->set($school);
            $service->run($school, $this->executionId);
        } finally {
            $context->clearAll();
        }
    }
}
