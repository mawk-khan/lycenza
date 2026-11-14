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
 * @property string|null $access_retained_grant_ids PostgreSQL uuid[] text (HR.R1-C35 WO-20); read it through retainedGrantIds()
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

    protected $fillable = ['reason_code', 'notified_on', 'planned_last_day'];

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

    /**
     * HR.R1-C35 WO-20 (HRC-4): the School role grants bound when access was
     * recorded as kept, as a sorted list of ids; null when nothing is bound
     * (never recorded, or a legacy record from before WO-20 -- tell them apart
     * by `access_retained_at`). Written only by the recording, never rewritten
     * (trg_employment_separation_cases_retained_grants).
     *
     * @return list<string>|null
     */
    public function retainedGrantIds(): ?array
    {
        $raw = $this->access_retained_grant_ids;
        if ($raw === null) {
            return null;
        }
        $ids = array_values(array_filter(explode(',', trim((string) $raw, '{}')), fn (string $id) => $id !== ''));
        sort($ids, SORT_STRING);

        return $ids;
    }

    /** @param  list<string>  $ids  sorted grant ids */
    public static function grantIdArray(array $ids): string
    {
        return '{'.implode(',', $ids).'}';
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
