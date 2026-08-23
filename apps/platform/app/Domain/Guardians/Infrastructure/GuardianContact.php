<?php

namespace App\Domain\Guardians\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\GuardianContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Guardian's contact point (email/mobile) -- never `email`/`phone`/
 * `mobile`/`whatsapp_number` columns directly on Guardian, since a
 * Guardian may have several (personal email, work email, primary and
 * secondary mobile). See docs/modules/STUDENT-GUARDIAN-IDENTITY.md
 * ("Searchable PII").
 *
 * `encrypted_value` and `lookup_hash` are the ONLY representations of
 * the contact value this codebase stores -- there is no plaintext or
 * normalized-plaintext column. `encrypted_value`/`lookup_hash` are
 * `$hidden` so an accidental `toArray()`/`toJson()` (there is no
 * controller yet, but this is defense-in-depth for when one exists)
 * never serializes ciphertext or the lookup digest. Reading the actual
 * contact value requires the `encrypted_value` attribute explicitly
 * (Laravel's `encrypted` cast decrypts it in-memory) -- never log or
 * audit that value; see App\Domain\Guardians\Application\GuardianContactService.
 *
 * Never create/update `encrypted_value`/`lookup_hash` directly --
 * always through GuardianContactService, which owns normalization,
 * encryption, and lookup-hash computation as one atomic operation so
 * every call site shares exactly one implementation of each.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $guardian_id
 * @property ContactType $type
 * @property string $encrypted_value Decrypted in-memory via Laravel's `encrypted` cast.
 * @property string $lookup_hash
 * @property int $lookup_key_version
 * @property string|null $label
 * @property bool $is_primary
 * @property bool $is_active
 * @property Carbon|null $verified_at
 */
class GuardianContact extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'guardian_contacts';

    protected $fillable = [
        'school_id', 'guardian_id', 'type', 'encrypted_value', 'lookup_hash',
        'lookup_key_version', 'label', 'is_primary', 'is_active', 'verified_at',
    ];

    protected $hidden = ['encrypted_value', 'lookup_hash'];

    protected function casts(): array
    {
        return [
            'type' => ContactType::class,
            'encrypted_value' => 'encrypted',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    protected static function newFactory(): GuardianContactFactory
    {
        return GuardianContactFactory::new();
    }

    /** @return BelongsTo<Guardian, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
