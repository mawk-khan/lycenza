<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\SchoolStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Central/platform data -- the tenant catalog itself. See ADR 0004,
 * ADR 0020, docs/architecture/TENANCY.md. This row's own `id` IS the
 * tenant boundary; it carries no school_id/RLS of its own.
 *
 * `status` (Phase 0B) is the PLATFORM tenant-lifecycle column
 * (Phase 0N.9, ADR 0047: provisioning|active|suspended|archived, see
 * App\Support\Tenancy\SchoolStatus; database-checked, transitions
 * database-enforced, written only by the platform lifecycle services;
 * the column default is `provisioning`, so an operational School is
 * always created `active` explicitly) -- distinct from the School OPERATIONAL
 * profile fields added in Phase 0D below. No `school.profile.manage`-
 * gated code path may write `status`; see
 * App\Http\Controllers\Api\V1\SchoolProfileController, whose validated
 * field list deliberately excludes it. See
 * docs/modules/ORGANIZATION.md ("School operational configuration vs.
 * platform tenant lifecycle").
 *
 * @property string $id UUIDv7 (ADR 0019) -- not an auto-increment int.
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property string $timezone
 * @property string $default_locale
 * @property string|null $legal_name
 * @property string|null $code
 * @property string|null $website
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $state_region
 * @property string|null $postal_code
 * @property string $country_code
 * @property string|null $education_board_id
 */
class School extends Model
{
    use GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $fillable = [
        'name', 'slug', 'status', 'timezone', 'default_locale',
        'legal_name', 'code', 'website', 'email', 'phone',
        'address_line1', 'address_line2', 'city', 'state_region', 'postal_code',
        'country_code', 'education_board_id',
    ];

    /** @return HasMany<Campus, $this> */
    public function campuses(): HasMany
    {
        return $this->hasMany(Campus::class);
    }

    /** @return BelongsTo<EducationBoard, $this> */
    public function educationBoard(): BelongsTo
    {
        return $this->belongsTo(EducationBoard::class);
    }

    /** @return HasMany<SchoolDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(SchoolDomain::class);
    }

    /** @return HasMany<SchoolMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(SchoolMembership::class);
    }

    /** Operational: the only status under which the tenant may operate. */
    public function isActive(): bool
    {
        return $this->status === SchoolStatus::Active->value;
    }

    public function isProvisioning(): bool
    {
        return $this->status === SchoolStatus::Provisioning->value;
    }

    public function isSuspended(): bool
    {
        return $this->status === SchoolStatus::Suspended->value;
    }

    public function lifecycleStatus(): ?SchoolStatus
    {
        return SchoolStatus::tryFrom((string) $this->status);
    }
}
