<?php

namespace Database\Factories;

use App\Domain\Canteen\Infrastructure\CanteenItem;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CanteenItem>
 */
class CanteenItemFactory extends Factory
{
    protected $model = CanteenItem::class;

    public function definition(): array
    {
        $sequence = fake()->unique()->numberBetween(1, 100000);

        return [
            'school_id' => School::factory(),
            'code' => "MENU{$sequence}",
            'name' => fake()->words(2, true),
            'price' => fake()->randomFloat(2, 10, 200),
            'currency' => 'INR',
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
