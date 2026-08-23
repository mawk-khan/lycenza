<?php

namespace Database\Factories;

use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\GuardianContact;
use App\Models\School;
use App\Support\Privacy\ContactLookupHasher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Low-level factory: sets `encrypted_value` directly (Eloquent's
 * `encrypted` cast on GuardianContact encrypts it transparently on
 * assignment) and computes a REAL lookup_hash via the actual
 * ContactLookupHasher once school_id/type/encrypted_value are resolved
 * (afterMaking, below) -- this factory does not bypass hashing, only
 * the higher-level GuardianContactService orchestration (normalization
 * happens implicitly here by supplying an already-normalized fake
 * value). Prefer
 * Tests\Concerns\CreatesTenancyFixtures::createGuardianContact(), which
 * goes through the real GuardianContactService, for anything that
 * isn't specifically testing a database constraint in isolation.
 *
 * @extends Factory<GuardianContact>
 */
class GuardianContactFactory extends Factory
{
    protected $model = GuardianContact::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'type' => ContactType::Email,
            'encrypted_value' => strtolower(fake()->unique()->safeEmail()),
            'lookup_hash' => '', // computed in configure()'s afterMaking, once school_id/type/encrypted_value are final
            'lookup_key_version' => 0, // likewise
            'label' => null,
            'is_primary' => false,
            'is_active' => true,
            'verified_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (GuardianContact $contact): void {
            $hasher = app(ContactLookupHasher::class);
            $contact->lookup_hash = $hasher->hash($contact->school_id, $contact->type->value, $contact->encrypted_value);
            $contact->lookup_key_version = $hasher->keyVersion();
        });
    }
}
