<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned data (RLS-protected). Do not query/write this directly
 * from outside App\Support\Settings\SchoolSettingsService -- that
 * service is what enforces "typed schema/validation" and audits
 * changes; a raw model write bypasses both.
 *
 * @property string $id
 * @property string $school_id
 * @property string $key
 * @property mixed $value
 */
class SchoolSetting extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
