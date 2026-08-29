<?php

namespace App\Domain\Payroll\Infrastructure;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\PayrollRunResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 9.1 (ADR 0032 "Run kinds, correction model, and posting") --
 * one authoritative result row per `EmploymentRecord` per run (never
 * per bare `Employee`). Frozen the instant the parent run reaches
 * `approved` or later (`trg_payroll_run_results_freeze`) -- this
 * model exposes no update-after-freeze helper on purpose.
 * `net_amount = gross_amount - total_deductions` always; sign is
 * non-negative for a regular run, signed for a correction run's
 * aggregate delta (`trg_payroll_run_results_validate_sign`).
 *
 * @property string $id
 * @property string $school_id
 * @property string $payroll_run_id
 * @property string $employment_record_id
 * @property string $employee_id
 * @property string $gross_amount
 * @property string $total_deductions
 * @property string $net_amount
 */
class PayrollRunResult extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = [
        'school_id',
        'payroll_run_id',
        'employment_record_id',
        'employee_id',
        'gross_amount',
        'total_deductions',
        'net_amount',
    ];

    protected static function newFactory(): PayrollRunResultFactory
    {
        return PayrollRunResultFactory::new();
    }

    /** @return BelongsTo<PayrollRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /** @return BelongsTo<EmploymentRecord, $this> */
    public function employmentRecord(): BelongsTo
    {
        return $this->belongsTo(EmploymentRecord::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<PayrollRunResultLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollRunResultLine::class);
    }
}
