<?php

namespace App\Models;

use App\Domain\Campuses\Infrastructure\CampusServiceOffering;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned data. Campus is a sub-tenant dimension, NOT a separate
 * isolation boundary (ADR 0004) -- but it is still RLS-protected like
 * any other tenant-owned table so it can never be read/written outside
 * its own School's context.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $name
 * @property string $code
 * @property string $status
 * @property string|null $phone
 * @property string|null $email
 *                              ORG.12C (ADR 0080 §8): the School profile's structured address shape,
 *                              optional coordinates (both or neither, ~1 m) and the current service
 *                              offerings (`services`, CampusServiceType). No Campus timezone or currency.
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $state_region
 * @property string|null $postal_code
 * @property string|null $country_code NULL = the School's country
 * @property string|null $latitude decimal:5
 * @property string|null $longitude decimal:5
 */
class Campus extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $fillable = [
        'school_id', 'name', 'code', 'status', 'phone', 'email',
        'address_line1', 'address_line2', 'city', 'state_region', 'postal_code', 'country_code', 'latitude', 'longitude',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['latitude' => 'decimal:5', 'longitude' => 'decimal:5'];
    }

    /** @return HasMany<CampusServiceOffering, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(CampusServiceOffering::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
