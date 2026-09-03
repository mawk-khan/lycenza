<?php

namespace App\Domain\Payroll\Infrastructure;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\EmployeeCompensationAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Phase 9.1 (ADR 0034 "Compensation effective-dating and overlap") --
 * an effective-dated binding of an HR `EmploymentRecord` (never bare
 * `Employee`) to an exact `SalaryStructure` revision. Overlap for the
 * same EmploymentRecord is rejected by database trigger
 * (`trg_compensation_assignments_reject_overlap`), not
 * `EXCLUDE USING gist` -- see that migration's docblock. The
 * referenced structure must be `active`
 * (`trg_compensation_assignments_require_active_structure`).
 *
 * @property string $id
 * @property string $school_id
 * @property string $employment_record_id
 * @property string $salary_structure_id
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
class EmployeeCompensationAssignment extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = [
        'school_id',
        'employment_record_id',
        'salary_structure_id',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    protected static function newFactory(): EmployeeCompensationAssignmentFactory
    {
        return EmployeeCompensationAssignmentFactory::new();
    }

    public function isOpenEnded(): bool
    {
        return $this->effective_to === null;
    }

    public function coversDate(\DateTimeInterface $date): bool
    {
        $carbon = Carbon::instance(\DateTime::createFromInterface($date));

        return $carbon->gte($this->effective_from) && ($this->effective_to === null || $carbon->lte($this->effective_to));
    }

    /** @return BelongsTo<EmploymentRecord, $this> */
    public function employmentRecord(): BelongsTo
    {
        return $this->belongsTo(EmploymentRecord::class);
    }

    /** @return BelongsTo<SalaryStructure, $this> */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    /** @return HasMany<CompensationAssignmentValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(CompensationAssignmentValue::class, 'assignment_id');
    }
}
