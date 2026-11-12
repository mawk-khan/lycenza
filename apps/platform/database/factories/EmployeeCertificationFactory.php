<?php

namespace Database\Factories;

use App\Domain\HR\Infrastructure\EmployeeCertification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeCertification>
 *
 * Deliberately does NOT default `school_id`/`employee_id` -- same
 * reasoning as EmployeeAddressFactory: supplied explicitly by the
 * caller via Tests\Concerns\CreatesTenancyFixtures::createEmployeeCertification().
 */
class EmployeeCertificationFactory extends Factory
{
    protected $model = EmployeeCertification::class;

    public function definition(): array
    {
        $issuedOn = fake()->dateTimeBetween('-3 years', '-1 years');

        return [
            'name' => fake()->randomElement(['Teaching Licence', 'First Aid Certificate', 'Child Safeguarding Certificate']),
            'issuer' => fake()->company(),
            'credential_number' => fake()->boolean() ? strtoupper(fake()->bothify('CERT-####??')) : null,
            'issued_on' => $issuedOn,
            'expires_on' => fake()->dateTimeBetween($issuedOn, '+2 years'),
            'verification_status' => 'unverified',
            'verified_at' => null,
        ];
    }

    // HR.R1-C29 (C14-N5): no verified/rejected state. Only a recorded decision on a revision sets one, through
    // the service (CredentialVerificationDecisions); the database refuses a credential born decided.

    public function nonExpiring(): static
    {
        return $this->state(fn () => ['expires_on' => null]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'issued_on' => now()->subYears(3),
            'expires_on' => now()->subDays(10),
        ]);
    }
}
