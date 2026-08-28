<?php

namespace Database\Factories;

use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'inventory_item_id' => InventoryItem::factory(),
            'movement_type' => 'receipt',
            'from_location_id' => null,
            'to_location_id' => null,
            'quantity' => '1.000',
            'occurred_at' => now(),
            'created_at' => now(),
        ];
    }
}
