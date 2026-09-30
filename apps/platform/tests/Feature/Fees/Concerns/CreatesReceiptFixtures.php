<?php

namespace Tests\Feature\Fees\Concerns;

use App\Domain\Fees\Application\FeeSettingsService;
use App\Domain\Payments\Application\ManualPaymentResult;
use App\Domain\Payments\Application\ReceiptIssuer;
use App\Domain\Payments\Infrastructure\PaymentReceipt;
use App\Domain\Payments\Infrastructure\PaymentReceiptCounter;
use App\Models\DomainEventOutbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Mockery;

/**
 * FEE.4 fixtures on top of the FEE.3 world (Asia/Kolkata School, a 1000.00
 * charge, a settlement account and a School Admin payment recorder).
 * `legacyPayment()` records a Payment exactly as before FEE.4 -- with the
 * receipt issuer switched off -- so the I2 backfill has real candidates.
 */
trait CreatesReceiptFixtures
{
    use CreatesFeeConcessionFixtures;

    protected function pay(array $w, string $amount, ?string $on = null, $charge = null, ?string $key = null): ManualPaymentResult
    {
        return $this->recordManualPayment($w['school'], $w['recorder'], $w['settlement']->id, [[$charge ?? $w['charge'], $amount]], $amount, occurredOn: $on, idempotencyKey: $key);
    }

    protected function legacyPayment(array $w, string $amount, string $on, $charge = null): ManualPaymentResult
    {
        $issuer = Mockery::mock(ReceiptIssuer::class);
        $issuer->shouldReceive('issue')->andReturn([]);
        app()->instance(ReceiptIssuer::class, $issuer);

        try {
            return $this->pay($w, $amount, $on, $charge);
        } finally {
            app()->forgetInstance(ReceiptIssuer::class);
        }
    }

    protected function receiptOf(array $w, string $paymentId): ?PaymentReceipt
    {
        return $this->inSchool($w['school'], fn () => PaymentReceipt::query()->where('payment_id', $paymentId)->first());
    }

    protected function counterNext(array $w, string $series): ?int
    {
        return $this->inSchool($w['school'], fn () => PaymentReceiptCounter::query()->where('series_key', $series)->value('next_value'));
    }

    protected function receiptCount(array $w): int
    {
        return $this->inSchool($w['school'], fn () => PaymentReceipt::query()->count());
    }

    protected function outboxOf(array $w, string $type): Collection
    {
        return $this->inSchool($w['school'], fn () => DomainEventOutbox::query()->where('event_type', $type)->get());
    }

    protected function setNumbering(array $w, string $prefix, int $month): void
    {
        app(FeeSettingsService::class)->setReceiptNumbering($w['school'], $prefix, $month, $w['actor']);
    }

    /** Today in the School's timezone (manual payments cannot be in the future). */
    protected function today(array $w): string
    {
        return Carbon::now($w['school']->timezone)->toDateString();
    }

    /** A date in the School's previous financial year (April start). */
    protected function lastFinancialYear(array $w): string
    {
        return Carbon::now($w['school']->timezone)->subMonths(13)->toDateString();
    }
}
