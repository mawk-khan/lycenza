<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ORG.12D (ADR 0080 §9): read model of one School <-> School Group
 * membership (`school_group_members`, several Groups per School allowed,
 * ADR 0045 §7) with its relationship classification, Region, Zone and
 * network reference. Written only by SchoolGroupGovernanceService; nothing
 * is mass assignable. A membership grants nothing in the School.
 *
 * @property string $id
 * @property string $school_group_id
 * @property string $school_id
 * @property string|null $relationship_type
 * @property string|null $region_id
 * @property string|null $zone_id
 * @property string|null $network_reference
 */
class SchoolGroupMember extends Model
{
    protected $table = 'school_group_members';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['*'];
}
