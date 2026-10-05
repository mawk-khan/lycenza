<?php

use App\Domain\Fees\Application\Sources\FeeSelectionSource;
use App\Domain\Fees\Application\Sources\FeeSourceSelectionService;
use App\Domain\Transport\Application\TransportFeeSelectionService;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// OPF.1 (ADR 0067): a genuinely separate OS process for
// TransportFeeSelectionConcurrencyTest. HeldTransaction makes the holder keep
// its writes uncommitted until released, so the contender is observed blocked
// on them (Tests\Concerns\ForcesConcurrentOverlap).
//
// Usage:
//   php transport-fee-op.php carry-forward <schoolId> <academicYearId>
//   php transport-fee-op.php select <schoolId> <studentId> <academicYearId> <feeHeadId> <sourceId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$op = $argv[1];
$school = School::query()->findOrFail($argv[2]);
$context = $app->make(TenantContext::class);
$context->set($school);

try {
    echo HeldTransaction::run(function () use ($app, $op, $school, $argv): string {
        if ($op === 'carry-forward') {
            $totals = $app->make(TransportFeeSelectionService::class)->carryForward($school, $argv[3], null);

            return "linked:{$totals['linked']} already_linked:{$totals['already_linked']}";
        }

        $result = $app->make(FeeSourceSelectionService::class)->selectForSource($school, $argv[3], $argv[4], $argv[5], FeeSelectionSource::transport($argv[6]), null);

        return "{$result->outcome}:{$result->selectionId}";
    });
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
} finally {
    $context->clearAll();
}
