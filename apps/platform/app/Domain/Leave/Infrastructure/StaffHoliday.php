<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HRX.1 (ADR 0065 §4.7): a dated staff holiday (the full day, or its first or
 * second half). Staff calendar only.
 *
 * @property string $id
 * @property string $school_id
 */
class StaffHoliday extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const PORTIONS = ['full', 'first_half', 'second_half'];

    protected $table = 'staff_holidays';

    protected $fillable = ['school_id', 'holiday_on', 'portion', 'name', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['holiday_on' => 'date'];
    }
}
