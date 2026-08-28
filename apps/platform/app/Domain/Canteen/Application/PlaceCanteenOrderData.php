<?php

namespace App\Domain\Canteen\Application;

/**
 * The typed input to CanteenOrderService::place() -- matches
 * App\Domain\Fees\Application\AssessChargeData's established precedent
 * (only fields this class actually declares can ever reach the
 * service).
 *
 * @param  list<CanteenOrderLineData>  $lines
 */
final class PlaceCanteenOrderData
{
    /**
     * @param  list<CanteenOrderLineData>  $lines
     */
    public function __construct(
        public readonly string $studentId,
        public readonly string $outletId,
        public readonly array $lines,
    ) {}
}
