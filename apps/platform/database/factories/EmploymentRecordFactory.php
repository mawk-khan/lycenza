<?php

namespace Database\Factories;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmploymentRecord>
 *
 * Deliberately does NOT default `school_id`/`employee_id` -- same
 * reasoning as EmployeePersonalDetailFactory/EmployeeAddressFactory:
 * supplied explicitly by the caller via
 * Tests\Concerns\CreatesTenancyFixtures::createEmploymentRecord().
 */
class EmploymentRecordFactory extends Factory
{
    protected $model = EmploymentRecord::class;

    public function definition(): array
    {
        return [
            'employment_type' => 'permanent',
            'starts_on' => fake()->dateTimeBetween('-3 years', '-1 year')->format('Y-m-d'),
            'ends_on' => null,
            'probation_ends_on' => null,
            'status' => 'active',
        ];
    }

    /**
     * A closed, historical Employment -- both dates in the past,
     * ends_on strictly after starts_on.
     */
    public function ended(): static
    {
        return $this->state(function (array $attributes) {
            $startsOn = $attributes['starts_on'] ?? fake()->dateTimeBetween('-5 years', '-3 years')->format('Y-m-d');

            return [
                'starts_on' => $startsOn,
                'ends_on' => fake()->dateTimeBetween($startsOn, '-1 year')->format('Y-m-d'),
                'status' => 'separated',
            ];
        });
    }

    /**
     * A future-dated, not-yet-started Employment (planned hire).
     */
    public function future(): static
    {
        return $this->state(fn () => [
            'starts_on' => fake()->dateTimeBetween('+1 month', '+6 months')->format('Y-m-d'),
            'status' => 'pre_joining',
        ]);
    }
}
