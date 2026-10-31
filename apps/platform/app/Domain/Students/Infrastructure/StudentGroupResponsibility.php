<?php

namespace App\Domain\Students\Infrastructure;

use App\Domain\Students\Application\Groups\ClubResponsibility;
use App\Domain\Students\Application\Groups\HouseResponsibility;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use BackedEnum;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SIS 11.7 / 11.8: a descriptive House or Club responsibility held during
 * one membership (never a permission). Written only by StudentHouseService
 * (House) and StudentCommunityService (Club). The House and Club codes are
 * disjoint, so the value alone names its catalogue.
 *
 * @property string $id
 * @property string $school_id
 * @property string $student_group_id
 * @property string $student_group_membership_id
 * @property string $student_id
 * @property HouseResponsibility|ClubResponsibility $responsibility
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
        return ['starts_on' => 'date', 'ends_on' => 'date', 'version' => 'integer'];
    }

    /** @return Attribute<HouseResponsibility|ClubResponsibility, HouseResponsibility|ClubResponsibility|string> */
    protected function responsibility(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => HouseResponsibility::tryFrom($value) ?? ClubResponsibility::from($value),
            set: fn ($value) => $value instanceof BackedEnum ? $value->value : $value,
        );
    }
}
