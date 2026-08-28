<?php

namespace App\Domain\Payments\Application;

/**
 * Phase 0G.5: the typed input for `PaymentReadService::listPayments()`,
 * mirroring `App\Domain\Fees\Application\ChargeQuery`'s exact shape.
 */
final class PaymentQuery
{
    public function __construct(
        public readonly ?string $providerPaymentReference = null,
        public readonly int $perPage = 25,
        public readonly int $page = 1,
    ) {}
}
