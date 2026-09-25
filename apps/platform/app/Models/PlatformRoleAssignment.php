<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Central/platform data: platform-scoped role grants (e.g. Platform
 * Super Admin). A database trigger (see the migration) guarantees
 * role_id always references a role with scope='platform'.
 *
 * Phase 0N.7 (ADR 0046): history-keeping. `granted_by_user_id` NULL means
 * provisioned out of band (the root role only ever arrives this way); set
 * means a runtime grant, which the database allows only for a
 * `runtime_assignable` role (v1: `platform_auditor`). Revocation sets
 * `revoked_at`/`revoked_by_user_id` once and keeps the row; nobody grants
 * or revokes their own. Written at runtime only by
 * App\Domain\Platform\Application\Roles\PlatformRoleGovernanceService.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $user_id
 * @property string $role_id
 * @property string|null $granted_by_user_id
 * @property Carbon $granted_at
 * @property string|null $revoked_by_user_id
 * @property Carbon|null $revoked_at
 */
class PlatformRoleAssignment extends Model
{
    use GeneratesUuidV7;

    protected $fillable = ['user_id', 'role_id', 'granted_by_user_id', 'granted_at'];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Phase 0N.7 (ADR 0046): revoked grants stay as history; only active
     * ones confer anything.
     *
     * @param  Builder<PlatformRoleAssignment>  $query
     * @return Builder<PlatformRoleAssignment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
