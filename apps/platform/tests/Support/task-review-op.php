<?php

use App\Domain\WorkforceTasks\Application\TaskException;
use App\Domain\WorkforceTasks\Application\TaskWorkflowService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// HR.R1-C35 WO-22 (TRR-6): one staff-task review in a GENUINELY separate OS
// process, for WorkforceTaskReviewConcurrencyTest.
//
// Usage: php task-review-op.php <schoolId> <actorId> <taskId> <version> <complete|return>
// Prints "ok:<task status>", "refused:<machine code>" for a task refusal,
// or "rejected:<class>" otherwise.

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $schoolId, $actorId, $taskId, $version, $decision] = $argv;
$context = $app->make(TenantContext::class);

try {
    $school = School::query()->findOrFail($schoolId);
    $actor = User::query()->findOrFail($actorId);
    echo 'ok:'.HeldTransaction::run(fn (): string => $app->make(TaskWorkflowService::class)
        ->review($school, $taskId, (int) $version, $decision, $decision === 'return' ? 'Add the dates.' : null, $actor)->status);
} catch (TaskException $e) {
    echo 'refused:'.$e->errorCode();
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
} finally {
    $context->clearAll();
}
