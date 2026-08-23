<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Central/platform data (deliberately -- see the migration's docblock
 * for why). "Which schools does this user belong to" is central
 * discovery data; "which role(s) that membership grants" is
 * tenant-owned (MembershipRoleAssignment). Do NOT add school_id-based
 * RLS to this model -- see docs/architecture/adr/0020-tenant-identifier-terminology.md
 * and the migration.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $user_id
 * @property string $school_id
 * @property string $status
 */
class SchoolMembership extends Model
{
    use GeneratesUuidV7;

    protected $fillable = ['user_id', 'school_id', 'status', 'invited_at', 'joined_at'];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return HasMany<MembershipRoleAssignment, $this> */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(MembershipRoleAssignment::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
