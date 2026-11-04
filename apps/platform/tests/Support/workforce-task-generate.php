<?php

use App\Domain\WorkforceTasks\Application\TaskGenerationService;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// HR.13: one generation run in a GENUINELY separate OS process, so
// WorkforceTaskConcurrencyTest can race two workers over the same School-day.
// Usage: php workforce-task-generate.php <schoolId> <batch>
// Prints "ok:<created>:<existing>" or "error:<class>".

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $schoolId, $batch] = array_pad($argv, 3, '50');
$context = $app->make(TenantContext::class);

try {
    $run = $app->make(TaskGenerationService::class)->run(School::query()->findOrFail($schoolId), null, (int) $batch);
    echo "ok:{$run->created}:{$run->existing}";
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.substr($e->getMessage(), 0, 160);
} finally {
    $context->clearAll();
}
