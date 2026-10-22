<?php

use App\Domain\Payments\Application\PaymentCorrectionData;
use App\Domain\Payments\Application\PaymentCorrectionRequestService;
use App\Domain\Payments\Application\PaymentCorrectionService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for PaymentCorrectionRequestConcurrencyTest
// (ORG.8C, ADR 0076 §61): one decision on a payment correction request, or
// one trusted direct correction of its Payment, in a GENUINELY separate OS
// process (HeldTransaction holder or contender).
//
// Usage:
//   php payment-correction-request-op.php approve|reject|withdraw <schoolId> <requestId> <userId>
//   php payment-correction-request-op.php reverse <schoolId> <paymentId> <userId>   (the trusted core, no request)

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $operation, $schoolId, $targetId, $userId] = $argv;
$school = School::query()->findOrFail($schoolId);
$context = $app->make(TenantContext::class);
// Session-level for the whole process, so the School context holds at the real COMMIT.
$context->set($school);

try {
    echo HeldTransaction::run(function () use ($app, $operation, $school, $targetId, $userId): string {
        $requests = $app->make(PaymentCorrectionRequestService::class);
        $actor = User::query()->findOrFail($userId);

        return match ($operation) {
            'approve' => $requests->approve($school, $targetId, $actor)->status,
            'reject' => $requests->reject($school, $targetId, $actor)->status,
            'withdraw' => $requests->withdraw($school, $targetId, $actor)->status,
            'reverse' => $app->make(PaymentCorrectionService::class)->correct($school, new PaymentCorrectionData(
                paymentId: $targetId,
                kind: PaymentCorrectionData::REVERSAL,
                reason: 'Concurrent reversal',
                idempotencyKey: (string) Str::uuid(),
            ), $actor)['replayed'] ? 'replayed' : 'reversed',
            default => throw new InvalidArgumentException("Unknown operation {$operation}"),
        };
    });
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
} finally {
    $context->clearAll();
}
