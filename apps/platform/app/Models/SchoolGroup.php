<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A School Group / Trust (ADR 0004, ADR 0045): platform-owned, above the
 * tenant boundary, never tenant context. Its member Schools
 * (`school_group_members`, several Groups per School allowed) grant no
 * access by themselves; Group authority comes only from an explicit
 * GroupRoleAssignment. Archived, never deleted (the runtime role cannot
 * DELETE it). Confidential (DATA-CLASSIFICATION.md).
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $name
 * @property string $slug
 * @property string $status
 */
class SchoolGroup extends Model
{
    use GeneratesUuidV7;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = ['name', 'slug'];

    protected $attributes = ['status' => self::STATUS_ACTIVE];

    /** @return BelongsToMany<School, $this> */
    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class, 'school_group_members')->withTimestamps();
    }

    /** @return HasMany<GroupRoleAssignment, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(GroupRoleAssignment::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
