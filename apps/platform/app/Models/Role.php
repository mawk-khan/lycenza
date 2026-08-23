<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Central/platform catalog: a role is a convenience bundle of
 * capabilities (docs/security/AUTHORIZATION.md) -- never itself the
 * enforcement point. `scope` (platform|school) determines which
 * assignment table a role may be used with; the database enforces this
 * via triggers on platform_role_assignments / membership_role_assignments,
 * not just this model.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $key
 * @property string $scope
 */
class Role extends Model
{
    use GeneratesUuidV7;

    protected $fillable = ['key', 'name', 'scope', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /** @return BelongsToMany<Capability, $this> */
    public function capabilities(): BelongsToMany
    {
        return $this->belongsToMany(
            Capability::class,
            'role_capabilities',
            'role_id',
            'capability_key',
            'id',
            'key',
        )->withPivot('created_at');
    }

    public function isPlatformScoped(): bool
    {
        return $this->scope === 'platform';
    }

    public function isSchoolScoped(): bool
    {
        return $this->scope === 'school';
    }
}
