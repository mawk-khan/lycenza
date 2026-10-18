<?php

namespace App\Domain\Platform\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * ORG.5 (ADR 0076 §12.5): one School's state of one OPTIONAL module.
 * Written only by SchoolModuleEntitlementService (forceFill; nothing is
 * mass assignable). Read through SchoolModuleEntitlements.
 *
 * @property string $id
 * @property string $school_id
 * @property string $module_key
 * @property bool $enabled
 * @property string $reason_code
 */
class SchoolModuleEntitlement extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'changed_at' => 'immutable_datetime'];
    }
}
