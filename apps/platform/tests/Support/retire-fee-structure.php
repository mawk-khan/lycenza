<?php

use App\Domain\Fees\Application\FeeStructureService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// FEE.2 FeeAssessmentConcurrencyTest: a structure retirement in a separate
// OS process, racing a fee assessment item.
//
// Usage: php retire-fee-structure.php <schoolId> <feeStructureId> <actorId>

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $schoolId, $structureId, $actorId] = $argv;
$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    HeldTransaction::run(fn () => $app->make(FeeStructureService::class)->retire($school, $structureId, User::query()->findOrFail($actorId)));
    echo 'retired';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
