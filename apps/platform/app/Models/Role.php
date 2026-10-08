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

    public const SCOPE_SCHOOL = 'school';

    /** POR.1 (ADR 0070 §8.2): the fourth scope, database-separated from staff roles. */
    public const SCOPE_GUARDIAN = 'guardian';

    /** The one Guardian system role (capability delivery only; never checked by name). */
    public const GUARDIAN = 'guardian';

    protected $fillable = ['key', 'name', 'scope', 'is_system', 'runtime_assignable'];

    protected function casts(): array
    {
        // runtime_assignable (Phase 0N.7, ADR 0046): set only from the code
        // catalog; database-guarded (system platform roles only, never the
        // root role, never with a root-reserved capability).
        return ['is_system' => 'boolean', 'runtime_assignable' => 'boolean'];
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

    /** POR.1 (ADR 0070 §8.2): the Guardian portal scope -- never staff authority. */
    public function isGuardianScoped(): bool
    {
        return $this->scope === self::SCOPE_GUARDIAN;
    }
}
