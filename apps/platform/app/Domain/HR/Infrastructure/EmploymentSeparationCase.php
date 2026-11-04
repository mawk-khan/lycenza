<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * HR.12: one separation case of one employment (ADR 0028 HR.12 amendment).
 * Written only by App\Domain\HR\Application\Separation\SeparationCaseService;
 * the lifecycle graph and its evidence are database-enforced
 * (trg_employment_separation_cases_guard). `school_id`, `status` and every
 * decision column are deliberately not mass assignable.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employment_record_id
 * @property string $reason_code
 * @property Carbon $notified_on
 * @property Carbon $planned_last_day
 * @property string $status
 * @property int $version
 * @property string $requested_by_user_id
 * @property Carbon $requested_at
 * @property string|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property string|null $withdrawn_by_user_id
 * @property Carbon|null $withdrawn_at
 * @property string|null $cancelled_by_user_id
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property string|null $finalized_by_user_id
 * @property Carbon|null $finalized_at
 * @property Carbon|null $final_ends_on
 * @property string|null $access_retained_by_user_id
 * @property Carbon|null $access_retained_at
 * @property string|null $completed_by_user_id
 * @property Carbon|null $completed_at
 */
class EmploymentSeparationCase extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const array STATUSES = ['requested', 'approved', 'rejected', 'withdrawn', 'cancelled', 'finalized', 'completed'];

    /** A case that is still being worked: the employment is not yet separated by it. */
    public const array OPEN_STATUSES = ['requested', 'approved'];

    protected $table = 'employment_separation_cases';

    protected $fillable = ['employment_record_id', 'reason_code', 'notified_on', 'planned_last_day', 'requested_by_user_id', 'requested_at'];

    protected function casts(): array
    {
        return [
            'notified_on' => 'date',
            'planned_last_day' => 'date',
            'final_ends_on' => 'date',
            'version' => 'integer',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'finalized_at' => 'datetime',
            'access_retained_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EmploymentRecord, $this> */
    public function employment(): BelongsTo
    {
        return $this->belongsTo(EmploymentRecord::class, 'employment_record_id');
    }

    /** @return HasMany<SeparationClearanceItem, $this> */
    public function clearanceItems(): HasMany
    {
        return $this->hasMany(SeparationClearanceItem::class, 'case_id');
    }

    /** @return HasMany<EmploymentSeparationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(EmploymentSeparationEvent::class, 'case_id');
    }
}
