<?php

namespace App\Domain\Payroll\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * HRX.5 (ADR 0065 §26.8): the HRX absence evidence a regular run's result was
 * calculated alongside -- captured once per result, frozen with the run,
 * deleted only with its result. Evidence only: it never feeds an amount
 * (HRX-L4 open).
 *
 * @property string $id
 * @property string $school_id
 * @property string $payroll_run_result_id
 * @property string $payroll_run_id
 * @property string $employment_record_id
 * @property string $contract_version
 * @property string $fingerprint
 * @property string $completeness
 * @property list<string> $incomplete_reasons
 * @property Carbon $period_starts_on
 * @property Carbon $period_ends_on
 * @property Carbon|null $covered_from
 * @property Carbon|null $covered_to
 * @property int|null $required_working_half_units
 * @property int $approved_paid_leave_half_units
 * @property int $approved_unpaid_leave_half_units
 * @property int $recorded_absence_half_units
 * @property int $recorded_presence_half_units
 * @property int $unresolved_working_half_units
 * @property list<array<string, mixed>> $evidence
 * @property string $captured_by_user_id
 * @property Carbon $captured_at
 */
class PayrollRunHrxInput extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public $timestamps = false;

    protected $table = 'payroll_run_hrx_inputs';

    protected $fillable = [
        'school_id', 'payroll_run_result_id', 'payroll_run_id', 'employment_record_id', 'contract_version', 'fingerprint', 'completeness',
        'incomplete_reasons', 'period_starts_on', 'period_ends_on', 'covered_from', 'covered_to', 'required_working_half_units',
        'approved_paid_leave_half_units', 'approved_unpaid_leave_half_units', 'recorded_absence_half_units', 'recorded_presence_half_units',
        'unresolved_working_half_units', 'evidence', 'captured_by_user_id', 'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'incomplete_reasons' => 'array', 'evidence' => 'array', 'period_starts_on' => 'date', 'period_ends_on' => 'date',
            'covered_from' => 'date', 'covered_to' => 'date', 'captured_at' => 'datetime',
            'required_working_half_units' => 'integer', 'approved_paid_leave_half_units' => 'integer', 'approved_unpaid_leave_half_units' => 'integer',
            'recorded_absence_half_units' => 'integer', 'recorded_presence_half_units' => 'integer', 'unresolved_working_half_units' => 'integer',
        ];
    }
}
