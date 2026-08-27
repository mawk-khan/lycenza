<?php

use App\Domain\Payments\Application\ChargeAllocationInput;
use App\Domain\Payments\Application\NormalizedProviderEvent;
use App\Domain\Payments\Application\PaymentProviderEventService;
use App\Domain\Payments\Application\RecordSettlementData;
use App\Models\School;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// Standalone bootstrap script for real-process concurrency tests: two
// genuinely separate OS processes race to allocate a settlement against
// the SAME charge (over-allocation prevention) or race a settlement
// allocation against a concurrent cancellation (the interlock). Mirrors
// cancel-charge.php's identical pattern.
//
// Usage: php allocate-payment.php <schoolId> <chargeId> <settlementAccountId> <amount>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $chargeId, $settlementAccountId, $amount] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $result = $app->make(PaymentProviderEventService::class)->recordSettlement($school, new RecordSettlementData(
        event: new NormalizedProviderEvent(
            provider: 'test-provider',
            providerEventId: (string) Str::uuid(),
            providerPaymentReference: (string) Str::uuid(),
            eventType: 'payment.settled',
            amount: Money::of($amount, 'INR'),
            occurredAt: Carbon::now(),
        ),
        settlementLedgerAccountId: $settlementAccountId,
        allocations: [new ChargeAllocationInput($chargeId, Money::of($amount, 'INR'))],
    ));
    echo 'settled:'.$result->paymentId;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
