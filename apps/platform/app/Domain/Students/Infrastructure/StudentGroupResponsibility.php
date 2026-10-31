<?php

namespace App\Domain\Students\Infrastructure;

use App\Domain\Students\Application\Groups\HouseResponsibility;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SIS 11.7: a descriptive House responsibility held during one membership
 * (never a permission). Written only by StudentHouseService.
 *
 * @property string $id
 * @property string $school_id
 * @property string $student_group_id
 * @property string $student_group_membership_id
 * @property string $student_id
 * @property HouseResponsibility $responsibility
 * @property Carbon $starts_on
 * @property ?Carbon $ends_on
 * @property int $version
 */
class StudentGroupResponsibility extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'student_group_responsibilities';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['responsibility' => HouseResponsibility::class, 'starts_on' => 'date', 'ends_on' => 'date', 'version' => 'integer'];
    }
}
