<?php

namespace Database\Factories;

use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Inventory\Infrastructure\InventoryStockBalance;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryStockBalance>
 */
class InventoryStockBalanceFactory extends Factory
{
    protected $model = InventoryStockBalance::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'inventory_item_id' => InventoryItem::factory(),
            'inventory_location_id' => InventoryLocation::factory(),
            'quantity_on_hand' => '0.000',
        ];
    }
}
