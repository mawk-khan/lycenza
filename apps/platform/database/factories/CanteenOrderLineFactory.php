<?php

namespace Database\Factories;

use App\Domain\Canteen\Infrastructure\CanteenItem;
use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Canteen\Infrastructure\CanteenOrderLine;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CanteenOrderLine>
 *
 * Deliberately LAZY relation references -- see CanteenOutletFactory's
 * docblock for why (RLS-safety under an already-active TenantContext).
 */
class CanteenOrderLineFactory extends Factory
{
    protected $model = CanteenOrderLine::class;

    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);
        $unitPrice = '10.00';
        $lineTotal = bcmul($unitPrice, (string) $quantity, 2);

        return [
            'school_id' => School::factory(),
            'order_id' => CanteenOrder::factory(),
            'canteen_item_id' => CanteenItem::factory(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'currency' => 'INR',
        ];
    }
}
