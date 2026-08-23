<?php

namespace Database\Factories;

use App\Models\Campus;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campus>
 */
class CampusFactory extends Factory
{
    protected $model = Campus::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => fake()->city().' Campus',
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'status' => 'active',
        ];
    }
}
