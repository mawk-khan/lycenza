<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * HR.3A: one PRIMARY reporting line -- a subordinate EMPLOYMENT reports to a
 * manager EMPLOYMENT -- over an inclusive date range (`ends_on` NULL = open;
 * `ends_on = starts_on - 1` = a line cancelled before it started, ADR 0078).
 * A correction voids a line (`voided_at`) and records a replacement; nothing
 * is edited or deleted. The database guards every invariant
 * (`trg_reporting_relationships_guard`): one line per subordinate per day,
 * never oneself, within both employments, never a loop on any day; parties
 * and start immutable; the end only narrows.
 *
 * The only writer is App\Domain\HR\Application\Reporting\ReportingRelationshipService;
 * readers go through ReportingLine (current, locking) and ReportingAsOfReader.
 *
 * @property string $id
 * @property string $school_id
 * @property string $subordinate_employment_id
 * @property string $manager_employment_id
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property string $started_via
 * @property string|null $start_reason
 * @property string|null $started_by_user_id
 * @property string|null $ended_via
 * @property string|null $end_reason
 * @property string|null $ended_by_user_id
 * @property Carbon|null $voided_at
 * @property string|null $voided_by_user_id
 * @property string|null $void_reason
 * @property Carbon $created_at
 */
class ReportingRelationship extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'reporting_relationships';

    protected $fillable = [
        'subordinate_employment_id', 'manager_employment_id', 'starts_on', 'ends_on',
        'started_via', 'start_reason', 'started_by_user_id', 'ended_via', 'end_reason', 'ended_by_user_id',
        'voided_at', 'voided_by_user_id', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EmploymentRecord, $this> */
    public function subordinateEmployment(): BelongsTo
    {
        return $this->belongsTo(EmploymentRecord::class, 'subordinate_employment_id');
    }

    /** @return BelongsTo<EmploymentRecord, $this> */
    public function managerEmployment(): BelongsTo
    {
        return $this->belongsTo(EmploymentRecord::class, 'manager_employment_id');
    }

    /**
     * Live (not voided, not empty) lines covering the date.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->whereNull('voided_at')->where('starts_on', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date));
    }

    /**
     * Live lines holding at least one day (voided and empty intervals excluded).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('voided_at')->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereColumn('ends_on', '>=', 'starts_on'));
    }
}
