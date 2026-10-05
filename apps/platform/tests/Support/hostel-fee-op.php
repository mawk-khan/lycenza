<?php

use App\Domain\Fees\Application\Sources\FeeSelectionSource;
use App\Domain\Fees\Application\Sources\FeeSourceSelectionService;
use App\Domain\Hostel\Application\HostelFeeSelectionService;
use App\Domain\Hostel\Infrastructure\HostelResidencyAssignment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// OPF.2 (ADR 0067): a genuinely separate OS process for
// HostelFeeSelectionConcurrencyTest. HeldTransaction makes the holder keep
// its writes uncommitted until released, so the contender is observed blocked
// on them (Tests\Concerns\ForcesConcurrentOverlap).
//
// Usage:
//   php hostel-fee-op.php link <schoolId> <residencyId>
//   php hostel-fee-op.php carry-forward <schoolId> <academicYearId>
//   php hostel-fee-op.php select <schoolId> <studentId> <academicYearId> <feeHeadId> <residencyId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$op = $argv[1];
$school = School::query()->findOrFail($argv[2]);
$context = $app->make(TenantContext::class);
$context->set($school);

try {
    echo HeldTransaction::run(function () use ($app, $op, $school, $argv): string {
        $fees = $app->make(HostelFeeSelectionService::class);
        if ($op === 'link') {
            // The residency-start path: the residency row locked first, as assign() holds it.
            $residency = HostelResidencyAssignment::query()->lockForUpdate()->findOrFail($argv[3]);
            $fees->recordForNewResidency($residency, null);

            return 'links:'.$app->make('db')->table('hostel_fee_selections')->where('hostel_residency_assignment_id', $residency->id)->count();
        }
        if ($op === 'carry-forward') {
            $totals = $fees->carryForward($school, $argv[3], null);

            return "linked:{$totals['linked']} already_linked:{$totals['already_linked']}";
        }

        $result = $app->make(FeeSourceSelectionService::class)->selectForSource($school, $argv[3], $argv[4], $argv[5], FeeSelectionSource::hostel($argv[6]), null);

        return "{$result->outcome}:{$result->selectionId}";
    });
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
} finally {
    $context->clearAll();
}
