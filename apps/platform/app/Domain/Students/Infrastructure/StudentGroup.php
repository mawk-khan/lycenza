<?php

namespace App\Domain\Students\Infrastructure;

use App\Domain\Students\Application\Groups\ActivityCategory;
use App\Domain\Students\Application\Groups\StudentGroupType;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SIS 11.7 / 11.8: a School-owned Student community -- a House, Club or
 * Activity (closed StudentGroupType). Written only through
 * StudentGroupCatalogue (HouseAdministrationService and
 * CommunityAdministrationService); deactivated, never deleted.
 *
 * @property string $id
 * @property string $school_id
 * @property StudentGroupType $group_type
 * @property string $name
 * @property string $code
 * @property ?string $description
 * @property ?ActivityCategory $activity_category
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
        return ['group_type' => StudentGroupType::class, 'activity_category' => ActivityCategory::class, 'display_order' => 'integer', 'version' => 'integer'];
    }
}
