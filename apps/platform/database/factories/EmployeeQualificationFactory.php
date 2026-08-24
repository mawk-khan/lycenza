<?php

namespace Database\Factories;

use App\Domain\HR\Infrastructure\EmployeeQualification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeQualification>
 *
 * Deliberately does NOT default `school_id`/`employee_id` -- same
 * reasoning as EmployeeAddressFactory: supplied explicitly by the
 * caller via Tests\Concerns\CreatesTenancyFixtures::createEmployeeQualification().
 */
class EmployeeQualificationFactory extends Factory
{
    protected $model = EmployeeQualification::class;

    public function definition(): array
    {
        $startsOn = fake()->dateTimeBetween('-8 years', '-4 years');

        return [
            'qualification_type' => fake()->randomElement(['secondary', 'higher_secondary', 'diploma', 'bachelors', 'masters', 'doctorate', 'professional', 'other']),
            'qualification_name' => fake()->randomElement(['Bachelor of Education', 'Bachelor of Science', 'Master of Arts', 'Diploma in Elementary Education']),
            'specialization' => fake()->randomElement(['Mathematics', 'Science', 'English', null]),
            'institution' => fake()->company().' University',
            'awarding_body' => null,
            'country_code' => 'IN',
            'starts_on' => $startsOn,
            'completed_on' => fake()->dateTimeBetween($startsOn, '-2 years'),
            'grade_or_result' => fake()->randomElement(['First Class', 'Distinction', '72%', null]),
            'verification_status' => 'unverified',
            'verified_at' => null,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn () => ['verification_status' => 'verified', 'verified_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['verification_status' => 'rejected', 'verified_at' => null]);
    }

    public function ongoing(): static
    {
        return $this->state(fn () => ['completed_on' => null]);
    }
}
