<?php

namespace Tests\Concerns;

use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Payments\Application\ChargeAllocationInput;
use App\Domain\Payments\Application\NormalizedProviderEvent;
use App\Domain\Payments\Application\PaymentProviderEventResult;
use App\Domain\Payments\Application\PaymentProviderEventService;
use App\Domain\Payments\Application\RecordSettlementData;
use App\Domain\Payments\Infrastructure\Payment;
use App\Models\School;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Phase 0G.5 test fixtures. `recordSettlement()` delegates to the real
 * `App\Domain\Payments\Application\PaymentProviderEventService::recordSettlement()`
 * rather than constructing rows by hand -- the same rule
 * `Tests\Concerns\CreatesFeesFixtures`/`CreatesFinanceFixtures` already
 * established for their own modules.
 */
trait CreatesPaymentsFixtures
{
    /**
     * @param  list<array{0: Charge, 1: string}>  $allocations  pairs of (Charge, amount-as-decimal-string)
     */
    protected function recordSettlement(
        School $school,
        string $settlementLedgerAccountId,
        array $allocations,
        string $amount,
        string $currency = 'INR',
        ?string $provider = 'test-provider',
        ?string $providerEventId = null,
        ?string $providerPaymentReference = null,
        ?Carbon $occurredAt = null,
    ): PaymentProviderEventResult {
        $allocationInputs = [];
        foreach ($allocations as [$charge, $chargeAmount]) {
            $allocationInputs[] = new ChargeAllocationInput($charge->id, Money::of($chargeAmount, $currency));
        }

        return app(PaymentProviderEventService::class)->recordSettlement($school, new RecordSettlementData(
            event: new NormalizedProviderEvent(
                provider: $provider,
                providerEventId: $providerEventId ?? (string) Str::uuid(),
                providerPaymentReference: $providerPaymentReference ?? (string) Str::uuid(),
                eventType: 'payment.settled',
                amount: Money::of($amount, $currency),
                occurredAt: $occurredAt ?? Carbon::now(),
            ),
            settlementLedgerAccountId: $settlementLedgerAccountId,
            allocations: $allocationInputs,
        ));
    }

    protected function findPayment(School $school, string $paymentId): Payment
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => Payment::query()->with('allocations')->findOrFail($paymentId),
        );
    }
}
