<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * HR.R1-C22 (WO-5, D-6): one effective-dated version of a School's staff working week. Append-only:
 * its terms never change; only `StaffCalendarService` records one, on a fixed array, and a version
 * leaves `active` only by a future withdrawal or a same-date replacement (database-enforced).
 *
 * @property string $id
 * @property string $school_id
 * @property CarbonImmutable $effective_from
 * @property string $status
 * @property ?string $supersedes_id
 * @property ?string $reason_code
 * @property ?string $recorded_by_user_id
 * @property CarbonImmutable $recorded_at
 * @property ?string $status_changed_by_user_id
 * @property ?CarbonImmutable $status_changed_at
 */
class StaffWorkingWeekVersion extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const ACTIVE = 'active';

    public const WITHDRAWN = 'withdrawn';

    public const SUPERSEDED = 'superseded';

    public const PORTIONS = ['full', 'first_half', 'off'];

    public $timestamps = false;

    protected $table = 'staff_working_week_versions';

    /** Every column is forced by its writer (rule 97). */
    protected $fillable = [];

    protected function casts(): array
    {
        return ['effective_from' => 'immutable_date', 'recorded_at' => 'immutable_datetime', 'status_changed_at' => 'immutable_datetime'];
    }

    /** @return array<int, string> ISO weekday => portion */
    public function pattern(): array
    {
        return array_combine(range(1, 7), array_map(fn (int $d) => (string) $this->getAttribute("weekday_{$d}"), range(1, 7)));
    }
}
