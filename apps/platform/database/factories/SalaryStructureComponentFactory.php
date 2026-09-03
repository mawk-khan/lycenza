<?php

namespace Database\Factories;

use App\Domain\Payroll\Infrastructure\SalaryStructureComponent;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalaryStructureComponent>
 */
class SalaryStructureComponentFactory extends Factory
{
    protected $model = SalaryStructureComponent::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'salary_structure_id' => SalaryStructureFactory::new(),
            'salary_component_id' => SalaryComponentFactory::new(),
            'calculation_type' => 'fixed_amount',
            'base_component_id' => null,
            'rate' => null,
            'display_order' => 1,
        ];
    }

    public function percentageOfBase(string $baseComponentId, float $rate = 0.4): static
    {
        return $this->state(fn () => [
            'calculation_type' => 'percentage_of_base',
            'base_component_id' => $baseComponentId,
            'rate' => $rate,
        ]);
    }
}
