<?php

namespace Database\Factories;

use App\Domain\HR\Infrastructure\Department;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 *
 * `campus_id`/`parent_department_id` are deliberately NOT defaulted --
 * both are optional single-parent-per-field relationships that, if
 * defaulted via a nested factory, could easily end up belonging to a
 * different School than the one this Department gets. Tests that need
 * either pass a real, same-School Campus/Department id explicitly.
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => fake()->unique()->word().' Department',
            'code' => strtoupper(fake()->unique()->lexify('DEPT???')),
            'description' => null,
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
