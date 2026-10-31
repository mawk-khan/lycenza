<?php

namespace App\Domain\Students\Infrastructure;

use App\Domain\Students\Application\Groups\StudentGroupType;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SIS 11.7: a School-owned Student community (House only). Written only by
 * HouseAdministrationService; deactivated, never deleted.
 *
 * @property string $id
 * @property string $school_id
 * @property StudentGroupType $group_type
 * @property string $name
 * @property string $code
 * @property ?string $description
 * @property string $status
 * @property int $display_order
 * @property int $version
 * @property Carbon $created_at
 */
class StudentGroup extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'student_groups';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['group_type' => StudentGroupType::class, 'display_order' => 'integer', 'version' => 'integer'];
    }
}
