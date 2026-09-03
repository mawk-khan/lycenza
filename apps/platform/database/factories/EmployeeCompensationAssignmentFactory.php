<?php

namespace Database\Factories;

use App\Domain\Payroll\Infrastructure\EmployeeCompensationAssignment;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeCompensationAssignment>
 *
 * Note: `salary_structure_id` must reference an `active` structure
 * (database trigger) -- callers typically override this with an
 * explicitly-activated `SalaryStructure::factory()->active()->create()`
 * id rather than relying on this factory's own default.
 */
class EmployeeCompensationAssignmentFactory extends Factory
{
    protected $model = EmployeeCompensationAssignment::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'employment_record_id' => EmploymentRecordFactory::new(),
            'salary_structure_id' => SalaryStructureFactory::new()->active(),
            'effective_from' => now()->startOfMonth(),
            'effective_to' => null,
        ];
    }
}
