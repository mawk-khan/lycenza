<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Central/platform data: structural foundation only for a future
 * School Group/Trust (ADR 0004). See SchoolGroupMember -- mere
 * membership grants no cross-school access by itself.
 *
 * @property string $id UUIDv7 (ADR 0019).
 */
class SchoolGroup extends Model
{
    use GeneratesUuidV7;

    protected $fillable = ['name', 'slug'];

    /** @return BelongsToMany<School, $this> */
    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class, 'school_group_members');
    }
}
