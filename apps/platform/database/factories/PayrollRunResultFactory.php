<?php

namespace Database\Factories;

use App\Domain\Payroll\Infrastructure\PayrollRunResult;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollRunResult>
 */
class PayrollRunResultFactory extends Factory
{
    protected $model = PayrollRunResult::class;

    public function definition(): array
    {
        $gross = fake()->randomFloat(2, 20000, 100000);
        $deductions = round($gross * 0.1, 2);

        return [
            'school_id' => School::factory(),
            'payroll_run_id' => PayrollRunFactory::new(),
            'employment_record_id' => EmploymentRecordFactory::new(),
            'employee_id' => EmployeeFactory::new(),
            'gross_amount' => $gross,
            'total_deductions' => $deductions,
            'net_amount' => $gross - $deductions,
        ];
    }
}
