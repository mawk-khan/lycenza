<?php

namespace Database\Factories;

use App\Domain\Transport\Infrastructure\TransportVehicle;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransportVehicle>
 */
class TransportVehicleFactory extends Factory
{
    protected $model = TransportVehicle::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'campus_id' => null,
            'code' => strtoupper(fake()->unique()->bothify('VEH-#####')),
            'registration_number' => strtoupper(fake()->unique()->bothify('??-##-??-####')),
            'capacity' => fake()->numberBetween(10, 60),
            'status' => 'active',
        ];
    }
}
