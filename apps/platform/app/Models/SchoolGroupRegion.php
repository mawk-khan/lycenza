<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ORG.12D (ADR 0080 §9, OD-12.10): an optional governance Region of ONE School
 * Group -- a classification of member Schools, never a tenant and never an
 * authorization scope. Platform table (no school_id, no RLS), written only by
 * SchoolGroupStructureService; inactivated, never deleted (the runtime role
 * has no DELETE). Codes are upper-case and unique within the Group.
 *
 * @property string $id
 * @property string $school_group_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int $sort_order
 * @property string $status
 */
class SchoolGroupRegion extends Model
{
    use GeneratesUuidV7, NormalizesCode;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'school_group_regions';

    protected $fillable = ['school_group_id', 'code', 'name', 'description', 'sort_order'];

    protected $attributes = ['status' => self::STATUS_ACTIVE];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** @return BelongsTo<SchoolGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(SchoolGroup::class, 'school_group_id');
    }

    /** @return HasMany<SchoolGroupMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(SchoolGroupMember::class, 'region_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
