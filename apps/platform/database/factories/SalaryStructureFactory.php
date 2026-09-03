<?php

namespace Database\Factories;

use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalaryStructure>
 */
class SalaryStructureFactory extends Factory
{
    protected $model = SalaryStructure::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'code' => 'STRUCT-'.fake()->unique()->numberBetween(1, 1000000),
            'version' => 1,
            'name' => fake()->words(3, true),
            'status' => 'draft',
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }

    public function superseded(): static
    {
        return $this->state(fn () => ['status' => 'superseded']);
    }
}
