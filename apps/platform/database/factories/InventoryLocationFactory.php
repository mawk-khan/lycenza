<?php

namespace Database\Factories;

use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryLocation>
 */
class InventoryLocationFactory extends Factory
{
    protected $model = InventoryLocation::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'campus_id' => null,
            'code' => strtoupper(fake()->unique()->bothify('LOC-#####')),
            'name' => fake()->words(2, true).' Store',
            'status' => 'active',
        ];
    }
}
