<?php

namespace App\Domain\Guardians\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\GuardianFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A Guardian is a permanent School-level identity, never duplicated
 * once per child -- a Guardian with several Students at the same
 * School is one row, linked to each Student through a future
 * StudentGuardianRelationship join table (not yet implemented).
 * Deliberately independent of `users`: this table carries no user_id
 * column -- a future GuardianUserLink would be an explicit, separate
 * join for portal access, not a column here.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $first_name
 * @property string|null $middle_name
 * @property string|null $last_name
 * @property string $status
 */
class Guardian extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'guardians';

    protected $fillable = ['school_id', 'first_name', 'middle_name', 'last_name', 'status'];

    protected static function newFactory(): GuardianFactory
    {
        return GuardianFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
