<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Checkpoint 9.6C -- PAN/UAN/PF Member ID/ESIC IP Number. Never
 * create/update `encrypted_value`/`lookup_hash` directly -- the
 * Application-layer service that writes this table (Checkpoint 9.6D)
 * computes `lookup_hash` via `StatutoryIdentifierLookupHasher`, the
 * same "never hand-compute the digest at a call site" discipline
 * `GuardianContact` already established. `$hidden` prevents an
 * accidental `toArray()`/`toJson()` leak of either sensitive column.
 *
 * @property string $encrypted_value Decrypted in-memory via Laravel's `encrypted` cast.
 */
class EmployeeStatutoryIdentifier extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $fillable = [
        'school_id', 'employment_record_id', 'identifier_type',
        'encrypted_value', 'lookup_hash', 'lookup_key_version',
    ];

    protected $hidden = ['encrypted_value', 'lookup_hash'];

    protected function casts(): array
    {
        return [
            'encrypted_value' => 'encrypted',
        ];
    }

    /** @return BelongsTo<EmploymentRecord, $this> */
    public function employmentRecord(): BelongsTo
    {
        return $this->belongsTo(EmploymentRecord::class);
    }
}
