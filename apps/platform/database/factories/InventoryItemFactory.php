<?php

namespace Database\Factories;

use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'code' => strtoupper(fake()->unique()->bothify('ITEM-#####')),
            'name' => fake()->words(2, true),
            'unit_of_measure' => 'each',
            'status' => 'active',
        ];
    }
}
