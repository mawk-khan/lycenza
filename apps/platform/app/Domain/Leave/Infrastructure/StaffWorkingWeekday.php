<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HRX.1 (ADR 0065 §4.7): the staff weekly working pattern, one row per ISO
 * weekday (1 = Monday): `full` working day, `first_half` only, or `off`.
 * Staff calendar only; no academic calendar reads or writes it.
 *
 * @property string $id
 * @property string $school_id
 */
class StaffWorkingWeekday extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const PORTIONS = ['full', 'first_half', 'off'];

    protected $table = 'staff_working_weekdays';

    protected $fillable = ['school_id', 'iso_weekday', 'portion', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['iso_weekday' => 'integer'];
    }
}
