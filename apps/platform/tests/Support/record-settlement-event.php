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

// Standalone bootstrap script for PaymentProviderEventConcurrencyTest:
// two genuinely separate OS processes deliver the IDENTICAL normalized
// provider event (same provider/provider_event_id/amount/reference)
// concurrently. Mirrors cancel-charge.php's identical pattern. The real
// concurrency guarantee is payment_provider_events_event_unique, reached
// via PaymentProviderEventService::recordSettlement().
//
// Usage: php record-settlement-event.php <schoolId> <chargeId> <settlementAccountId> <amount> <providerEventId> <providerPaymentReference>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $chargeId, $settlementAccountId, $amount, $providerEventId, $providerPaymentReference] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $result = $app->make(PaymentProviderEventService::class)->recordSettlement($school, new RecordSettlementData(
        event: new NormalizedProviderEvent(
            provider: 'test-provider',
            providerEventId: $providerEventId,
            providerPaymentReference: $providerPaymentReference,
            eventType: 'payment.settled',
            amount: Money::of($amount, 'INR'),
            occurredAt: Carbon::parse('2026-09-09T00:00:00Z'),
        ),
        settlementLedgerAccountId: $settlementAccountId,
        allocations: [new ChargeAllocationInput($chargeId, Money::of($amount, 'INR'))],
    ));
    echo $result->outcome->value.':'.$result->paymentId;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
