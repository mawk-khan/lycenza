<?php

namespace App\Domain\Students\Infrastructure;

use App\Domain\Students\Application\Groups\CommunityMembershipReason;
use App\Domain\Students\Application\Groups\HouseMembershipReason;
use App\Domain\Students\Application\Groups\StudentGroupType;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use BackedEnum;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property HouseMembershipReason|CommunityMembershipReason $start_reason
 * @property HouseMembershipReason|CommunityMembershipReason|null $end_reason
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
            'starts_on' => 'date',
            'ends_on' => 'date',
            'version' => 'integer',
        ];
    }

    /**
     * SIS 11.8: the reason vocabulary is per type -- HouseMembershipReason on
     * a House, CommunityMembershipReason on a Club or Activity (the database
     * checks each type's set; some codes exist in both).
     *
     * @return Attribute<HouseMembershipReason|CommunityMembershipReason, HouseMembershipReason|CommunityMembershipReason|string>
     */
    protected function startReason(): Attribute
    {
        return $this->reasonAttribute();
    }

    /** @return Attribute<HouseMembershipReason|CommunityMembershipReason|null, HouseMembershipReason|CommunityMembershipReason|string|null> */
    protected function endReason(): Attribute
    {
        return $this->reasonAttribute();
    }

    /** @return Attribute<mixed, mixed> */
    private function reasonAttribute(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value, array $attributes) => $value === null ? null
                : (($attributes['group_type'] ?? null) === StudentGroupType::HOUSE->value ? HouseMembershipReason::from($value) : CommunityMembershipReason::from($value)),
            set: fn ($value) => $value instanceof BackedEnum ? $value->value : $value,
        );
    }

    /** @return BelongsTo<StudentGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(StudentGroup::class, 'student_group_id');
    }
}
