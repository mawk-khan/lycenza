<?php

namespace Database\Factories;

use App\Domain\HR\Infrastructure\EmployeeExperience;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeExperience>
 *
 * Deliberately does NOT default `school_id`/`employee_id` -- same
 * reasoning as EmployeeAddressFactory: supplied explicitly by the
 * caller via Tests\Concerns\CreatesTenancyFixtures::createEmployeeExperience().
 */
class EmployeeExperienceFactory extends Factory
{
    protected $model = EmployeeExperience::class;

    public function definition(): array
    {
        $startsOn = fake()->dateTimeBetween('-6 years', '-3 years');

        return [
            'organization' => fake()->company(),
            'job_title' => fake()->randomElement(['Teacher', 'Coordinator', 'Consultant', 'Trainer']),
            'starts_on' => $startsOn,
            'ends_on' => fake()->dateTimeBetween($startsOn, '-1 years'),
            'description' => fake()->boolean() ? fake()->sentence() : null,
            'location' => fake()->city(),
            'country_code' => 'IN',
        ];
    }

    public function ongoing(): static
    {
        return $this->state(fn () => ['ends_on' => null]);
    }
}
