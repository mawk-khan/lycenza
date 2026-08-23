<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Central/platform data: a machine identity, distinct from User
 * (section 25). See docs/security/AUTHORIZATION.md and
 * docs/architecture/adr/0026-service-identities.md.
 *
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property string $credential_hash
 * @property bool $enabled
 * @property Carbon|null $last_used_at
 */
class ServiceIdentity extends Model
{
    use GeneratesUuidV7;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<Capability, $this> */
    public function capabilities(): BelongsToMany
    {
        return $this->belongsToMany(
            Capability::class,
            'service_identity_capabilities',
            'service_identity_id',
            'capability_key',
            'id',
            'key',
        );
    }

    public function hasCapability(string $capability): bool
    {
        return $this->enabled && $this->capabilities->contains('key', $capability);
    }
}
