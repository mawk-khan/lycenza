<?php

namespace App\Domain\Identity\Infrastructure;

use App\Models\Role;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 0O.12B (ADR 0059 section 6.1): one School-scope role an invitation
 * grants at acceptance. Same School as its invitation (composite FK),
 * School-scope roles only (trigger), immutable once written.
 *
 * @property string $id
 * @property string $school_id
 * @property string $staff_account_invitation_id
 * @property string $role_id
 */
class StaffAccountInvitationRole extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
