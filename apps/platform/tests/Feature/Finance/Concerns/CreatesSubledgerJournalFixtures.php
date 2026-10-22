<?php

namespace Tests\Feature\Finance\Concerns;

use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeAdjustment;
use App\Domain\Payments\Application\PaymentCorrectionData;
use App\Domain\Payments\Application\PaymentCorrectionService;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use Illuminate\Support\Str;
use Tests\Feature\Fees\Concerns\CreatesFeeConcessionFixtures;

/**
 * ORG.8-P1 (ADR 0076 §57): one journal entry of every Fees/Payments owner
 * kind, each created through its real domain flow (never a fabricated row),
 * on top of the FEE.3 concession world.
 */
trait CreatesSubledgerJournalFixtures
{
    use CreatesFeeConcessionFixtures;

    /** @return array<string, mixed> every Fees/Payments owner kind, each with its journal entry id */
    protected function ownedJournalWorld(): array
    {
        $w = $this->concessionWorld();

        $adjusted = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '500.00');
        $concession = $this->requestTargeted($w, '100.00', 'waiver', $adjusted);
        $this->concessions()->approve($w['school'], $concession->id, $w['checker']);
        $adjustment = $this->adjustmentsOf($w, $adjusted->id)->sole();

        $cancelledAdjustmentCharge = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '300.00');
        $second = $this->requestTargeted($w, '50.00', 'concession', $cancelledAdjustmentCharge);
        $this->concessions()->approve($w['school'], $second->id, $w['checker']);
        $cancelledAdjustment = $this->concessions()->cancelAdjustment($w['school'], $this->adjustmentsOf($w, $cancelledAdjustmentCharge->id)->sole()->id, $w['checker'], 'entered twice');

        $toCancel = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '200.00');
        $this->cancelChargeAs($w['school'], $toCancel->id, $w['recorder']);

        $paid = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '400.00');
        $payment = $this->recordManualPayment($w['school'], $w['recorder'], $w['settlement']->id, [[$paid, '150.00']], '150.00');
        $correction = app(PaymentCorrectionService::class)->correct($w['school'], new PaymentCorrectionData(
            paymentId: $payment->paymentId, kind: PaymentCorrectionData::REVERSAL, reason: 'Recorded against the wrong family', idempotencyKey: (string) Str::uuid(),
        ), $w['recorder']);

        $w['entries'] = $this->inSchool($w['school'], fn () => [
            'charge' => Charge::query()->findOrFail($w['charge']->id)->journal_entry_id,
            'charge_cancellation' => Charge::query()->findOrFail($toCancel->id)->cancellation_journal_entry_id,
            'fee_adjustment' => $adjustment->journal_entry_id,
            'fee_adjustment_cancellation' => FeeAdjustment::query()->findOrFail($cancelledAdjustment->id)->cancellation_journal_entry_id,
            'payment' => $this->findPayment($w['school'], $payment->paymentId)->journal_entry_id,
            'payment_correction' => PaymentCorrection::query()->findOrFail($correction['correctionId'])->journal_entry_id,
        ]);

        return $w;
    }
}
