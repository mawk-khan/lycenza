<?php

use App\Domain\Fees\Application\FeeStructureService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// FEE.1 FeeStructureConcurrencyTest: one fee structure activation in a
// genuinely separate OS process, so two real processes can race against
// real PostgreSQL. With CONCURRENCY_HOLD_DIR set it is the HOLDER
// (activation left uncommitted until released); with
// CONCURRENCY_SESSION_NAME it is the observed CONTENDER
// (Tests\Concerns\ForcesConcurrentOverlap).
//
// Usage: php activate-fee-structure.php <schoolId> <feeStructureId> <actorId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $feeStructureId, $actorId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $actor = User::query()->findOrFail($actorId);
    HeldTransaction::run(fn () => $app->make(FeeStructureService::class)->activate($school, $feeStructureId, $actor));
    echo 'activated';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
