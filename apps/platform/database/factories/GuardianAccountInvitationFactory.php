<?php

namespace Database\Factories;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Identity\Infrastructure\GuardianAccountInvitation;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GuardianAccountInvitation>
 */
class GuardianAccountInvitationFactory extends Factory
{
    protected $model = GuardianAccountInvitation::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'guardian_id' => Guardian::factory(),
            'student_id' => null,
            'token_hash' => hash('sha256', Str::random(64)),
            'destination_email_hash' => hash('sha256', strtolower(fake()->unique()->safeEmail())),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
            'invited_by_user_id' => User::factory(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['status' => 'revoked', 'revoked_at' => now()]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['status' => 'accepted', 'accepted_at' => now()]);
    }
}
