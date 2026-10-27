<?php

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Students\Application\EnrollmentRolloverExecutionService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// SIS 5.2 AnnualTransitionConcurrencyTest: one Academic Year activation or one
// rollover execution (claim + every item + finalize) in a GENUINELY separate
// OS process, run through HeldTransaction so the parent can force and observe
// the overlap.
//
// Usage: php annual-transition-op.php activate <schoolId> <yearId>
//        php annual-transition-op.php rollover <schoolId> <planId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $op, $schoolId, $id] = $argv;

$context = $app->make(TenantContext::class);
$context->set(School::query()->findOrFail($schoolId));

try {
    if ($op === 'activate') {
        $year = AcademicYear::query()->findOrFail($id);
        HeldTransaction::run(fn () => $app->make(AcademicYearService::class)->activate($year));
        echo 'activated';
    } else {
        $plan = EnrollmentRolloverPlan::query()->findOrFail($id);
        $result = HeldTransaction::run(fn () => $app->make(EnrollmentRolloverExecutionService::class)->start($plan));
        echo 'rolled:'.$result['planStatus'];
    }
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
