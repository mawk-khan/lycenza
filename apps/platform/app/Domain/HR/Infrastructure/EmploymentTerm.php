<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * HR.1A: one effective-dated set of an employment's terms -- employment
 * type, staff type and School category -- from `effective_from` until the
 * next term of the same employment (or the employment's end). Append-only:
 * the database creates the first term with every employment
 * (`trg_employment_records_initial_terms`), refuses an UPDATE, and accepts a
 * later term only strictly after the latest one
 * (`trg_employment_terms_append_guard`). The only sanctioned writer of a
 * change is App\Domain\HR\Application\Workforce\EmploymentTermsService.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employment_record_id
 * @property Carbon $effective_from
 * @property string $employment_type
 * @property string|null $staff_type
 * @property string|null $employee_category_id
 * @property string $reason_code
 * @property string|null $recorded_by_user_id
 * @property Carbon $created_at
 */
class EmploymentTerm extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'employment_terms';

    protected $fillable = [
        'school_id', 'employment_record_id', 'effective_from', 'employment_type', 'staff_type',
        'employee_category_id', 'reason_code', 'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EmploymentRecord, $this> */
    public function employmentRecord(): BelongsTo
    {
        return $this->belongsTo(EmploymentRecord::class);
    }

    /** @return BelongsTo<EmployeeCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EmployeeCategory::class, 'employee_category_id');
    }
}
