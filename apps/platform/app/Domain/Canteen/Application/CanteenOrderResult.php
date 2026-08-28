<?php

namespace App\Domain\Canteen\Application;

use App\Domain\Canteen\Infrastructure\CanteenOrder;
use Illuminate\Support\Carbon;

/**
 * The public return value of CanteenOrderService::place()/fulfill()/
 * cancel() -- deliberately NOT the raw CanteenOrder Eloquent model,
 * mirroring App\Domain\Fees\Application\ChargeResult's exact
 * result-boundary discipline.
 */
final class CanteenOrderResult
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $schoolId,
        public readonly string $studentId,
        public readonly string $outletId,
        public readonly string $status,
        public readonly string $totalAmount,
        public readonly string $currency,
        public readonly ?string $chargeId,
        public readonly Carbon $placedAt,
        public readonly ?Carbon $fulfilledAt,
        public readonly ?Carbon $cancelledAt,
    ) {}

    public static function fromModel(CanteenOrder $order): self
    {
        return new self(
            orderId: $order->id,
            schoolId: $order->school_id,
            studentId: $order->student_id,
            outletId: $order->outlet_id,
            status: $order->status,
            totalAmount: $order->total_amount,
            currency: $order->currency,
            chargeId: $order->charge_id,
            placedAt: $order->placed_at,
            fulfilledAt: $order->fulfilled_at,
            cancelledAt: $order->cancelled_at,
        );
    }
}
