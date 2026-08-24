<?php

namespace Database\Factories;

use App\Domain\HR\Infrastructure\Position;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    protected $model = Position::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => fake()->randomElement(['Teacher', 'Senior Teacher', 'Accountant', 'Librarian', 'Receptionist', 'Driver', 'IT Administrator', 'Counsellor']).' '.fake()->unique()->numerify('####'),
            'code' => strtoupper(fake()->unique()->lexify('POS???')),
            'description' => null,
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
