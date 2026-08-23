<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 */
class MembershipRoleAssignment extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $fillable = ['school_id', 'school_membership_id', 'role_id', 'assigned_by_user_id', 'assigned_at'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime'];
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
}
