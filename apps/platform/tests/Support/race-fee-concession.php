<?php

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\FeeAssessmentItemExecutor;
use App\Domain\Fees\Application\FeeConcessionService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// FEE.3 FeeConcessionConcurrencyTest: one concession-side operation in a
// GENUINELY separate OS process (the parent forces and verifies overlap --
// Tests\Concerns\ForcesConcurrentOverlap). Prints the outcome, or
// "rejected:<exception class>".
//
// Usage:
//   php race-fee-concession.php approve <schoolId> <userId> <concessionId>
//   php race-fee-concession.php revoke <schoolId> <userId> <concessionId>
//   php race-fee-concession.php cancel-adjustment <schoolId> <userId> <adjustmentId>
//   php race-fee-concession.php cancel-charge <schoolId> <chargeId>
//   php race-fee-concession.php execute-item <schoolId> <runId> <itemId>

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($args[0]);
// The School context outlives the held transaction: deferred triggers
// (journal balance) run at the real COMMIT under RLS.
$context->set($school);

try {
    echo HeldTransaction::run(function () use ($app, $operation, $args, $school): string {
        $concessions = $app->make(FeeConcessionService::class);

        return match ($operation) {
            'approve' => $concessions->approve($school, $args[2], User::query()->findOrFail($args[1]))->status,
            'revoke' => $concessions->revoke($school, $args[2], User::query()->findOrFail($args[1]))->status,
            'cancel-adjustment' => $concessions->cancelAdjustment($school, $args[2], User::query()->findOrFail($args[1])) ? 'cancelled' : 'no',
            'cancel-charge' => $app->make(ChargeService::class)->cancel($school, $args[1]) ? 'cancelled' : 'no',
            'execute-item' => $app->make(FeeAssessmentItemExecutor::class)->executeItem($school, $args[1], $args[2]),
            default => throw new InvalidArgumentException("Unknown operation {$operation}"),
        };
    });
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
