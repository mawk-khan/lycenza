<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * HRX.1 (ADR 0065 §4.7): a dated staff holiday (the full day, or its first or
 * second half). Staff calendar only.
 *
 * HR.R1-C22 (WO-5, D-6, S-16): a lifecycle, never deleted. A holiday is born `active` and may be
 * `withdrawn` once; its date, portion and name never change. State, actor and reason columns are
 * forced by `StaffCalendarService` only (rule 97).
 *
 * @property string $id
 * @property string $school_id
 * @property CarbonImmutable $holiday_on
 * @property string $portion
 * @property string $name
 * @property string $status
 * @property ?string $recorded_reason_code
 * @property ?string $withdrawn_by_user_id
 * @property ?CarbonImmutable $withdrawn_at
 * @property ?string $withdrawn_reason_code
 */
class StaffHoliday extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const PORTIONS = ['full', 'first_half', 'second_half'];

    public const ACTIVE = 'active';

    public const WITHDRAWN = 'withdrawn';

    protected $table = 'staff_holidays';

    protected $fillable = ['holiday_on', 'portion', 'name'];

    protected function casts(): array
    {
        return ['holiday_on' => 'immutable_date', 'recorded_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime'];
    }
}
