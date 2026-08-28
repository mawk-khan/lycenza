<?php

namespace Database\Factories;

use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Canteen\Infrastructure\CanteenOrderStockConsumption;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CanteenOrderStockConsumption>
 *
 * Deliberately LAZY relation references -- see CanteenOutletFactory's
 * docblock for why (RLS-safety under an already-active TenantContext).
 * `stock_movement_id`'s default lazily creates a plain StockMovement
 * with `movement_type = 'receipt'` (the default valid shape needing no
 * `from_location_id`) -- a test needing an 'issue'-shaped Movement
 * always overrides `stock_movement_id` explicitly with a real one.
 */
class CanteenOrderStockConsumptionFactory extends Factory
{
    protected $model = CanteenOrderStockConsumption::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'canteen_order_id' => CanteenOrder::factory(),
            'stock_movement_id' => StockMovement::factory()->state(['to_location_id' => InventoryLocation::factory()]),
        ];
    }
}
