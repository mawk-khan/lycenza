<?php

namespace Database\Factories;

use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PayrollPeriod>
 */
class PayrollPeriodFactory extends Factory
{
    protected $model = PayrollPeriod::class;

    public function definition(): array
    {
        $month = Carbon::instance(fake()->dateTimeBetween('-1 year', '+1 year'))->startOfMonth();

        return [
            'school_id' => School::factory(),
            'period_month' => $month->toDateString(),
            'starts_on' => $month->toDateString(),
            'ends_on' => $month->copy()->endOfMonth()->toDateString(),
            'payment_date' => null,
            'status' => 'draft',
        ];
    }

    public function forMonth(Carbon $month): static
    {
        $month = $month->copy()->startOfMonth();

        return $this->state(fn () => [
            'period_month' => $month->toDateString(),
            'starts_on' => $month->toDateString(),
            'ends_on' => $month->copy()->endOfMonth()->toDateString(),
        ]);
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => 'open']);
    }
}
