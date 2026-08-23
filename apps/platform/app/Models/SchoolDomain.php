<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Central/platform data: trusted domain -> School routing. See
 * docs/architecture/TENANCY.md, "School domain resolution".
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $domain
 */
class SchoolDomain extends Model
{
    use GeneratesUuidV7;

    protected $fillable = ['school_id', 'domain', 'type', 'is_primary', 'verified_at'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
