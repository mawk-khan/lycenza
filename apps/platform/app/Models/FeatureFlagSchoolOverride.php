<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned data (RLS-protected). Absence of a row for a given
 * School+flag means "use the flag's default_enabled" -- see
 * App\Support\FeatureFlags\FeatureFlagResolver.
 *
 * @property string $id
 * @property string $school_id
 * @property string $feature_flag_key
 * @property bool $enabled
 */
class FeatureFlagSchoolOverride extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
