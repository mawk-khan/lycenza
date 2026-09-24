<?php

use App\Domain\Automation\Application\AutomationExecutionService;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for AutomationExecutionConcurrencyTest: a
// GENUINELY separate OS process running AutomationExecutionService::run()
// against real PostgreSQL. Prints `acted` when this process claimed and ran
// the execution, `noop` when its lease claim lost.
//
// Usage: php run-automation-execution.php <schoolId> <executionId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $executionId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $acted = HeldTransaction::run(fn () => $app->make(AutomationExecutionService::class)->run($school, $executionId));
    echo $acted ? 'acted' : 'noop';
} catch (Throwable $e) {
    echo 'error:'.$e::class;
} finally {
    $context->clearAll();
}
