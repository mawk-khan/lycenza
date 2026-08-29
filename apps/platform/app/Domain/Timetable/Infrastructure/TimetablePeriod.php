<?php

namespace App\Domain\Timetable\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\TimetablePeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned, reusable named time slot ("Period 1", 09:00-09:45) that
 * `TimetableEntry` schedules against (Phase 0H). Persistence +
 * relationships only -- no business behavior lives here.
 * `App\Domain\Timetable\Application\TimetablePeriodService` is the sole
 * write path, including the overlap-validation and referenced-entry
 * guard rules documented on that class.
 *
 * @property string $id
 * @property string $school_id
 * @property string $code
 * @property string $name
 * @property string $start_time
 * @property string $end_time
 * @property int|null $sort_order
 * @property string $status active|inactive
 */
class TimetablePeriod extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'timetable_periods';

    protected $fillable = ['school_id', 'code', 'name', 'start_time', 'end_time', 'sort_order', 'status'];

    protected static function newFactory(): TimetablePeriodFactory
    {
        return TimetablePeriodFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return HasMany<TimetableEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(TimetableEntry::class, 'period_id');
    }
}
