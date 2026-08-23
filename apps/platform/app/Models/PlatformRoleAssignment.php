<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Central/platform data: platform-scoped role grants (e.g. Platform
 * Super Admin). A database trigger (see the migration) guarantees
 * role_id always references a role with scope='platform'.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $user_id
 * @property string $role_id
 */
class PlatformRoleAssignment extends Model
{
    use GeneratesUuidV7;

    protected $fillable = ['user_id', 'role_id', 'granted_by_user_id', 'granted_at'];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime'];
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
