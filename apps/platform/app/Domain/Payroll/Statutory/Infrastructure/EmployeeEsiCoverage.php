<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Checkpoint 9.6C (ADR 0036 correction addendum §1.8) -- coverage
 * decided once per contribution period, never re-evaluated mid-period.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employment_record_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $entry_wage
 * @property bool $is_covered
 */
class EmployeeEsiCoverage extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'employee_esi_coverage';

    protected $fillable = [
        'school_id', 'employment_record_id', 'period_start', 'period_end', 'entry_wage', 'is_covered',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'is_covered' => 'boolean',
        ];
    }

    /** @return BelongsTo<EmploymentRecord, $this> */
    public function employmentRecord(): BelongsTo
    {
        return $this->belongsTo(EmploymentRecord::class);
    }
}
