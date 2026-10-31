<?php

namespace App\Domain\Students\Infrastructure;

use App\Domain\Students\Application\Groups\HouseMembershipReason;
use App\Domain\Students\Application\Groups\StudentGroupType;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SIS 11.7: one effective-dated House membership (inclusive School-local
 * dates; NULL `ends_on` = current). Written only by StudentHouseService.
 *
 * @property string $id
 * @property string $school_id
 * @property string $student_group_id
 * @property StudentGroupType $group_type
 * @property string $student_id
 * @property Carbon $starts_on
 * @property ?Carbon $ends_on
 * @property HouseMembershipReason $start_reason
 * @property ?HouseMembershipReason $end_reason
 * @property string $started_by_user_id
 * @property ?string $ended_by_user_id
 * @property int $version
 * @property-read StudentGroup $group
 */
class StudentGroupMembership extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'student_group_memberships';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'group_type' => StudentGroupType::class,
            'start_reason' => HouseMembershipReason::class,
            'end_reason' => HouseMembershipReason::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<StudentGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(StudentGroup::class, 'student_group_id');
    }
}
