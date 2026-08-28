<?php

namespace Database\Factories;

use App\Domain\Hostel\Infrastructure\Hostel;
use App\Models\Campus;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hostel>
 */
class HostelFactory extends Factory
{
    protected $model = Hostel::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'campus_id' => Campus::factory(),
            'code' => strtoupper(fake()->unique()->bothify('HOSTEL-#####')),
            'name' => fake()->words(2, true).' Hostel',
            'status' => 'active',
        ];
    }
}
