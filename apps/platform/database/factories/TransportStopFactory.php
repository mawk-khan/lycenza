<?php

namespace Database\Factories;

use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStop;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransportStop>
 */
class TransportStopFactory extends Factory
{
    protected $model = TransportStop::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'route_id' => TransportRoute::factory(),
            'name' => fake()->streetAddress(),
            'sequence' => fake()->unique()->numberBetween(1, 100000),
            'address' => fake()->address(),
            'status' => 'active',
        ];
    }
}
