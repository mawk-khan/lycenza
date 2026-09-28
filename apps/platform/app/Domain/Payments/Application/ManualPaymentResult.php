<?php

namespace App\Domain\Payments\Application;

/**
 * Phase 0O.11A: the typed result of `ManualPaymentRecordingService::record()`.
 */
final class ManualPaymentResult
{
    public function __construct(
        public readonly ManualPaymentOutcome $outcome,
        public readonly string $paymentId,
    ) {}
}
