<?php

namespace Database\Factories;

use App\Domain\HR\Infrastructure\EmployeePersonalDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeePersonalDetail>
 *
 * Deliberately does NOT default `school_id`/`employee_id` -- same
 * reasoning as SectionFactory/SubjectOfferingFactory: the owning
 * Employee (and the School it derives from) is supplied explicitly by
 * the caller via Tests\Concerns\CreatesTenancyFixtures::createEmployeePersonalDetail(),
 * not defaulted here.
 *
 * Produces only Restricted-tier values -- no government identifier,
 * bank, or health field exists on this model to accidentally fake.
 */
class EmployeePersonalDetailFactory extends Factory
{
    protected $model = EmployeePersonalDetail::class;

    public function definition(): array
    {
        return [
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),
            'nationality' => 'Indian',
            'marital_status' => fake()->randomElement(['single', 'married']),
            'preferred_language' => 'en',
            'personal_email' => fake()->unique()->safeEmail(),
            'personal_phone' => fake()->numerify('##########'),
            'alternate_phone' => null,
        ];
    }
}
