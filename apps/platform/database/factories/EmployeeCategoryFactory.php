<?php

namespace Database\Factories;

use App\Domain\HR\Infrastructure\EmployeeCategory;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeCategory>
 */
class EmployeeCategoryFactory extends Factory
{
    protected $model = EmployeeCategory::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => fake()->randomElement(['Teaching', 'Non-Teaching', 'Contract', 'Visiting']).' '.fake()->unique()->numerify('####'),
            'code' => strtoupper(fake()->unique()->lexify('CAT???')),
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
