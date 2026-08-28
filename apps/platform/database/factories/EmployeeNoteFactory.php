<?php

namespace Database\Factories;

use App\Domain\HR\Infrastructure\EmployeeNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeNote>
 *
 * Deliberately does NOT default `school_id`/`employee_id`/`author_user_id`
 * -- same reasoning as EmployeeAddressFactory: supplied explicitly by
 * the caller.
 */
class EmployeeNoteFactory extends Factory
{
    protected $model = EmployeeNote::class;

    public function definition(): array
    {
        return [
            'body' => fake()->sentence(),
            'classification_tier' => 'sensitive',
        ];
    }

    public function confidential(): static
    {
        return $this->state(fn () => ['classification_tier' => 'confidential']);
    }
}
