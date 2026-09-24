<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A human Group grant (Phase 0N.5, ADR 0045 section 3): one User, one
 * School Group, one `group`-scope role. Never a School membership. Revoked
 * grants stay (immutable history). Platform-owned, no RLS; created and
 * revoked only by App\Domain\Platform\Application\Groups\SchoolGroupGovernanceService.
 * Sensitive (DATA-CLASSIFICATION.md).
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $user_id
 * @property string $school_group_id
 * @property string $role_id
 * @property string $granted_by_user_id
 * @property Carbon $granted_at
 * @property string|null $revoked_by_user_id
 * @property Carbon|null $revoked_at
 */
class GroupRoleAssignment extends Model
{
    use GeneratesUuidV7;

    protected $fillable = ['user_id', 'school_group_id', 'role_id', 'granted_by_user_id', 'granted_at'];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<SchoolGroup, $this> */
    public function schoolGroup(): BelongsTo
    {
        return $this->belongsTo(SchoolGroup::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * @param  Builder<GroupRoleAssignment>  $query
     * @return Builder<GroupRoleAssignment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
