<?php

namespace Database\Factories;

use App\Domain\Payroll\Infrastructure\SalaryComponent;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalaryComponent>
 */
class SalaryComponentFactory extends Factory
{
    protected $model = SalaryComponent::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'code' => 'COMP-'.fake()->unique()->numberBetween(1, 1000000),
            'name' => fake()->words(2, true),
            'type' => fake()->randomElement(['earning', 'deduction']),
            'liability_ledger_account_id' => null,
            'currency' => 'INR',
            'status' => 'active',
        ];
    }

    public function earning(): static
    {
        return $this->state(fn () => ['type' => 'earning', 'liability_ledger_account_id' => null]);
    }

    public function deduction(): static
    {
        return $this->state(fn () => ['type' => 'deduction']);
    }
}
