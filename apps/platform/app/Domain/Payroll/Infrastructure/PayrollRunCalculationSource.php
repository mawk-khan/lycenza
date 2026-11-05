<?php

namespace App\Domain\Payroll\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HR.R1-C1 (PAY-2): one compensation assignment a calculation of a regular
 * run rested on, with the effective range it had when the calculation read
 * it. Append-only; written only by PayrollCompensationProvenance::record().
 *
 * @property string $id
 * @property string $school_id
 * @property string $payroll_run_id
 * @property string $payroll_run_calculation_id
 * @property string $compensation_assignment_id
 * @property string $employment_record_id
 * @property string $basis structure | period_override
 */
class PayrollRunCalculationSource extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    public const BASIS_STRUCTURE = 'structure';

    public const BASIS_PERIOD_OVERRIDE = 'period_override';

    protected $fillable = [
        'school_id', 'payroll_run_id', 'payroll_run_calculation_id', 'compensation_assignment_id',
        'employment_record_id', 'effective_from', 'effective_to', 'basis',
    ];
}
