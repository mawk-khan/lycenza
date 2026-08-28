<?php

namespace Database\Factories;

use App\Domain\Canteen\Infrastructure\CanteenItem;
use App\Domain\Canteen\Infrastructure\CanteenItemInventoryRequirement;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CanteenItemInventoryRequirement>
 *
 * Deliberately LAZY relation references -- see CanteenOutletFactory's
 * docblock for why (RLS-safety under an already-active TenantContext).
 */
class CanteenItemInventoryRequirementFactory extends Factory
{
    protected $model = CanteenItemInventoryRequirement::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'canteen_item_id' => CanteenItem::factory(),
            'inventory_item_id' => InventoryItem::factory(),
            'quantity_required' => fake()->randomFloat(3, 0.1, 5),
        ];
    }
}
