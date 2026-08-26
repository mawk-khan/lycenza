<?php

namespace Database\Factories;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerAccount>
 */
class LedgerAccountFactory extends Factory
{
    protected $model = LedgerAccount::class;

    public function definition(): array
    {
        $sequence = fake()->unique()->numberBetween(1, 100000);

        return [
            'school_id' => School::factory(),
            'code' => "ACC{$sequence}",
            'name' => fake()->words(2, true),
            'type' => fake()->randomElement(['asset', 'liability', 'equity', 'income', 'expense']),
            'currency' => 'INR',
            'is_system' => false,
            'status' => 'active',
        ];
    }

    public function type(string $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    public function system(): static
    {
        return $this->state(fn () => ['is_system' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
