<?php

namespace Database\Factories;

use App\Domain\Payroll\Infrastructure\CompensationAssignmentValue;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompensationAssignmentValue>
 *
 * Note: `salary_structure_component_id` must reference a
 * `fixed_amount`-type component (database trigger).
 */
class CompensationAssignmentValueFactory extends Factory
{
    protected $model = CompensationAssignmentValue::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'assignment_id' => EmployeeCompensationAssignmentFactory::new(),
            'salary_structure_component_id' => SalaryStructureComponentFactory::new(),
            'amount' => fake()->randomFloat(2, 10000, 100000),
        ];
    }
}
