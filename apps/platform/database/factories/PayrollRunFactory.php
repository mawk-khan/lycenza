<?php

namespace Database\Factories;

use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollRun>
 */
class PayrollRunFactory extends Factory
{
    protected $model = PayrollRun::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'payroll_period_id' => PayrollPeriodFactory::new(),
            'run_kind' => 'regular',
            'corrects_payroll_run_id' => null,
            'status' => 'draft',
            'prepared_by_user_id' => User::factory(),
            'approved_by_user_id' => null,
            'posted_by_user_id' => null,
            'approved_at' => null,
            'posted_at' => null,
        ];
    }

    public function calculated(): static
    {
        return $this->state(fn () => ['status' => 'calculated']);
    }

    public function approved(?string $approvedByUserId = null): static
    {
        return $this->state(fn () => [
            'status' => 'approved',
            'approved_by_user_id' => $approvedByUserId ?? User::factory(),
            'approved_at' => now(),
        ]);
    }

    public function posted(?string $postedByUserId = null): static
    {
        return $this->state(fn () => [
            'status' => 'posted',
            'posted_by_user_id' => $postedByUserId ?? User::factory(),
            'posted_at' => now(),
        ]);
    }

    public function correction(string $correctsPayrollRunId): static
    {
        return $this->state(fn () => [
            'run_kind' => 'correction',
            'corrects_payroll_run_id' => $correctsPayrollRunId,
        ]);
    }
}
