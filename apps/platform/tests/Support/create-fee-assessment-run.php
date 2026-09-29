<?php

use App\Domain\Fees\Application\FeeAssessmentRunService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// FEE.2 FeeAssessmentConcurrencyTest: one run creation in a separate OS
// process (HOLDER with CONCURRENCY_HOLD_DIR, CONTENDER with
// CONCURRENCY_SESSION_NAME -- Tests\Concerns\ForcesConcurrentOverlap).
//
// Usage: php create-fee-assessment-run.php <schoolId> <feeStructureId> <periodKey> <actorId>

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $schoolId, $structureId, $key, $actorId] = $argv;
$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    HeldTransaction::run(fn () => $app->make(FeeAssessmentRunService::class)->create($school, $structureId, $key, User::query()->findOrFail($actorId)));
    echo 'created';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
