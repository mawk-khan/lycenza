<?php

use App\Domain\Fees\Application\FeeAssessmentService;
use App\Domain\Payments\Application\LateFeeAssessmentService;
use App\Domain\Payments\Application\LateFeeItemExecutor;
use App\Domain\Payments\Application\LateFeeRunService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// FEE.5 LateFeeConcurrencyTest: one late-fee operation in a GENUINELY
// separate OS process (the parent forces and verifies overlap --
// Tests\Concerns\ForcesConcurrentOverlap). Prints the outcome, or
// "rejected:<exception class>".
//
// Usage:
//   php race-late-fee.php execute-item <schoolId> <runId> <itemId>
//   php race-late-fee.php create-run <schoolId> <userId> <ruleId> <evaluationDate>
//   php race-late-fee.php void <schoolId> <userId> <lateFeeAssessmentId>
//   php race-late-fee.php void-source <schoolId> <userId> <feeAssessmentId>

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($args[0]);
$context->set($school);

try {
    echo HeldTransaction::run(function () use ($app, $operation, $args, $school): string {
        return match ($operation) {
            'execute-item' => $app->make(LateFeeItemExecutor::class)->executeItem($school, $args[1], $args[2]),
            'create-run' => $app->make(LateFeeRunService::class)->create($school, $args[2], $args[3], User::query()->findOrFail($args[1])) ? 'created' : 'no',
            'void' => $app->make(LateFeeAssessmentService::class)->void($school, $args[2], User::query()->findOrFail($args[1])) ? 'voided' : 'no',
            'void-source' => $app->make(FeeAssessmentService::class)->void($school, $args[2], User::query()->findOrFail($args[1])) ? 'voided' : 'no',
            default => throw new InvalidArgumentException("Unknown operation {$operation}"),
        };
    });
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
