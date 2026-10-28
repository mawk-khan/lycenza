<?php

namespace App\Domain\Campuses\Infrastructure;

use App\Models\Campus;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ORG.12C (ADR 0080 §8, OD-12.5): one service a Campus currently offers.
 * Written only by CampusAdministrationService (insert or delete, never
 * update). Descriptive: never a module entitlement, capability, billing
 * plan or authorization.
 *
 * @property string $id
 * @property string $school_id
 * @property string $campus_id
 * @property string $service_code CampusServiceType value
 * @property string|null $created_by_user_id
 */
class CampusServiceOffering extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'campus_services';

    public const UPDATED_AT = null;

    protected $fillable = ['school_id', 'campus_id', 'service_code', 'created_by_user_id'];

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }
}
