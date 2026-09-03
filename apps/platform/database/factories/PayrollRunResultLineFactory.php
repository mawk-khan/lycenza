<?php

namespace Database\Factories;

use App\Domain\Payroll\Infrastructure\PayrollRunResultLine;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollRunResultLine>
 */
class PayrollRunResultLineFactory extends Factory
{
    protected $model = PayrollRunResultLine::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'payroll_run_result_id' => PayrollRunResultFactory::new(),
            'salary_component_id' => SalaryComponentFactory::new(),
            'amount' => fake()->randomFloat(2, 1000, 50000),
            'effect' => 'increase',
            'resolved_ledger_account_id' => null,
            'currency' => 'INR',
        ];
    }

    public function decrease(): static
    {
        return $this->state(fn () => ['effect' => 'decrease']);
    }
}
