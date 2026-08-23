<?php

namespace Database\Factories;

use App\Domain\HR\Infrastructure\EmployeeAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeAddress>
 *
 * Deliberately does NOT default `school_id`/`employee_id` -- same
 * reasoning as SectionFactory/RoomFactory: supplied explicitly by the
 * caller via Tests\Concerns\CreatesTenancyFixtures::createEmployeeAddress().
 */
class EmployeeAddressFactory extends Factory
{
    protected $model = EmployeeAddress::class;

    public function definition(): array
    {
        return [
            'address_type' => 'current',
            'address_line1' => fake()->streetAddress(),
            'address_line2' => null,
            'city' => fake()->city(),
            'state_region' => fake()->randomElement(['Maharashtra', 'Karnataka', 'Delhi', 'Tamil Nadu', 'Gujarat']),
            'postal_code' => fake()->postcode(),
            'country_code' => 'IN',
        ];
    }

    public function permanent(): static
    {
        return $this->state(fn () => ['address_type' => 'permanent']);
    }

    public function mailing(): static
    {
        return $this->state(fn () => ['address_type' => 'mailing']);
    }

    public function other(): static
    {
        return $this->state(fn () => ['address_type' => 'other']);
    }
}
