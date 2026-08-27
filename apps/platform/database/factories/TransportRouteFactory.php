<?php

namespace Database\Factories;

use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransportRoute>
 */
class TransportRouteFactory extends Factory
{
    protected $model = TransportRoute::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'campus_id' => null,
            'code' => strtoupper(fake()->unique()->bothify('RT-#####')),
            'name' => fake()->streetName().' Route',
            'description' => null,
            'status' => 'active',
        ];
    }
}
