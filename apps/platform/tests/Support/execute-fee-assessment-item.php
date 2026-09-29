<?php

use App\Domain\Fees\Application\FeeAssessmentItemExecutor;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// FEE.2 FeeAssessmentConcurrencyTest: one item execution in a separate OS
// process. Prints the executor's outcome.
//
// Usage: php execute-fee-assessment-item.php <schoolId> <runId> <itemId>

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $schoolId, $runId, $itemId] = $argv;
$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    echo HeldTransaction::run(fn () => $app->make(FeeAssessmentItemExecutor::class)->executeItem($school, $runId, $itemId));
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
