<?php

namespace Database\Factories;

use App\Domain\HR\Infrastructure\EmployeeEmergencyContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeEmergencyContact>
 *
 * Deliberately does NOT default `school_id`/`employee_id` -- supplied
 * explicitly by the caller via
 * Tests\Concerns\CreatesTenancyFixtures::createEmployeeEmergencyContact().
 * `is_primary` defaults false; ordinary factory usage must not
 * accidentally create two primaries for the same Employee.
 */
class EmployeeEmergencyContactFactory extends Factory
{
    protected $model = EmployeeEmergencyContact::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'relationship' => fake()->randomElement(['Spouse', 'Parent', 'Sibling', 'Friend']),
            'phone' => fake()->numerify('##########'),
            'alternate_phone' => null,
            'email' => fake()->safeEmail(),
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => ['is_primary' => true]);
    }
}
