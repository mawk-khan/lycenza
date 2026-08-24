<?php

use App\Domain\Students\Application\EnrollmentRolloverExecutionService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for EnrollmentRolloverExecutionServiceTest's
// (well, its dedicated concurrency test's) real-concurrency proof: run in
// a GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so two real, independent PHP processes race
// EnrollmentRolloverExecutionService::start() for the SAME validated
// Plan against real PostgreSQL -- not a sequential simulation. Mirrors
// execute-rollover-item.php's identical pattern (Phase 1B.7C).
//
// Usage: php start-rollover-plan.php <schoolId> <planId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $planId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $plan = EnrollmentRolloverPlan::query()->findOrFail($planId);
    $result = $app->make(EnrollmentRolloverExecutionService::class)->start($plan);
    echo 'started:'.$result['planStatus'];
} catch (Throwable $e) {
    echo 'exception:'.$e::class;
} finally {
    $context->clearAll();
}
