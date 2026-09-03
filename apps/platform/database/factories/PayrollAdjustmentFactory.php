<?php

namespace Database\Factories;

use App\Domain\Payroll\Infrastructure\PayrollAdjustment;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollAdjustment>
 *
 * Note: `mode` must match the parent run's `run_kind` (database
 * trigger) -- `manualOverride()` and `correctionDelta()` states exist
 * so a caller pairs the right mode with the right run kind explicitly.
 */
class PayrollAdjustmentFactory extends Factory
{
    protected $model = PayrollAdjustment::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'payroll_run_id' => PayrollRunFactory::new(),
            'employment_record_id' => EmploymentRecordFactory::new(),
            'salary_component_id' => SalaryComponentFactory::new(),
            'mode' => 'manual_override',
            'amount' => fake()->randomFloat(2, 1000, 50000),
            'effect' => null,
            'reason' => fake()->sentence(),
            'actor_user_id' => User::factory(),
        ];
    }

    public function manualOverride(): static
    {
        return $this->state(fn () => ['mode' => 'manual_override', 'effect' => null]);
    }

    public function correctionDelta(string $effect = 'increase'): static
    {
        return $this->state(fn () => ['mode' => 'correction_delta', 'effect' => $effect]);
    }
}
