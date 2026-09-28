<?php

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Payments\Application\ChargeAllocationInput;
use App\Domain\Payments\Application\ManualPaymentRecordingService;
use App\Domain\Payments\Application\RecordManualPaymentData;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Models\School;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\HeldTransaction;

// Phase 0O.11A (ADR 0031 implementation amendment section 10): one manual
// payment operation in a GENUINELY separate OS process, for
// ManualPaymentConcurrencyTest (the parent forces and verifies the overlap
// -- Tests\Concerns\ForcesConcurrentOverlap).
//
// Usage:
//   php race-manual-payment.php record <schoolId> <userId> <accountId> <key> <amount> <chargeId:amount,...> [method]
//   php race-manual-payment.php cancel <schoolId> <chargeId>
//   php race-manual-payment.php suspend <schoolId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);
// The School context must outlive the held outer transaction: deferred
// constraint triggers (allocation total, journal balance) run at the real
// COMMIT under RLS, like every other race script here.
$context->set(School::query()->findOrFail($args[0]));

try {
    echo HeldTransaction::run(function () use ($app, $operation, $args): string {
        switch ($operation) {
            case 'record':
                [$schoolId, $userId, $accountId, $key, $amount, $allocationSpec] = $args;
                $school = School::query()->findOrFail($schoolId);
                $allocations = array_map(function (string $pair): ChargeAllocationInput {
                    [$chargeId, $chargeAmount] = explode(':', $pair);

                    return new ChargeAllocationInput($chargeId, Money::of($chargeAmount, 'INR'));
                }, explode(',', $allocationSpec));

                $result = $app->make(ManualPaymentRecordingService::class)->record($school, new RecordManualPaymentData(
                    method: ManualPaymentMethod::from($args[6] ?? 'cash'),
                    amount: Money::of($amount, 'INR'),
                    occurredOn: Carbon::now($school->timezone)->toDateString(),
                    reference: null,
                    settlementLedgerAccountId: $accountId,
                    allocations: $allocations,
                    idempotencyKey: $key,
                ), User::query()->findOrFail($userId));

                return $result->outcome->value.':'.$result->paymentId;
            case 'cancel':
                [$schoolId, $chargeId] = $args;
                $app->make(ChargeService::class)->cancel(School::query()->findOrFail($schoolId), $chargeId);

                return 'cancelled';
            case 'suspend':
                [$schoolId] = $args;
                DB::table('schools')->where('id', $schoolId)->update(['status' => 'suspended']);

                return 'suspended';
        }

        throw new InvalidArgumentException("Unknown operation {$operation}");
    });
} catch (Throwable $e) {
    echo 'error:'.$e::class;
} finally {
    $context->clearAll();
}
