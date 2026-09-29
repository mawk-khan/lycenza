<?php

namespace Database\Factories;

use App\Domain\Fees\Infrastructure\FeeHead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * FEE.1: callers must pass `school_id` and both ledger account ids (an
 * asset receivable and an income revenue account of the same School); the
 * database refuses anything else.
 */
class FeeHeadFactory extends Factory
{
    protected $model = FeeHead::class;

    public function definition(): array
    {
        return [
            'code' => 'FH'.fake()->unique()->numberBetween(1, 999999),
            'name' => 'Fee head '.fake()->word(),
            'description' => null,
            'status' => FeeHead::STATUS_ACTIVE,
            'currency' => 'INR',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => FeeHead::STATUS_INACTIVE]);
    }
}
