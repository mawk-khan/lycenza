<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tenant-owned data (RLS-protected). Which role(s) a School membership
 * grants WITHIN that School. A database trigger (see the migration)
 * guarantees role_id always references a role with scope='school', and
 * a composite foreign key guarantees school_membership_id's own
 * school_id always matches this row's school_id (section 34).
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $school_membership_id
 * @property string $role_id
 * @property string|null $assigned_by_user_id
 * @property Carbon|null $assigned_at
 * @property string|null $revoked_by_user_id
 * @property Carbon|null $revoked_at
 * @property string|null $revocation_reason
 *
 * Phase 0O.12B (ADR 0059 owner amendment): grants keep their history. A
 * revocation sets `revoked_at`/`revoked_by_user_id`/`revocation_reason`
 * once (database-guarded); the row stays and never becomes active again.
 * Re-granting inserts a NEW row. Authorization reads active rows only
 * (scopeActive). The runtime role cannot DELETE a grant.
 */
class MembershipRoleAssignment extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const REASON_REVOKED = 'revoked';

    public const REASON_MEMBERSHIP_SUSPENDED = 'membership_suspended';

    public const REASON_REACTIVATION_RESET = 'reactivation_reset';

    protected $fillable = ['school_id', 'school_membership_id', 'role_id', 'assigned_by_user_id', 'assigned_at'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<SchoolMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(SchoolMembership::class, 'school_membership_id');
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Active (unrevoked) grants only -- the ONLY rows authorization reads.
     *
     * @param  Builder<MembershipRoleAssignment>  $query
     * @return Builder<MembershipRoleAssignment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
