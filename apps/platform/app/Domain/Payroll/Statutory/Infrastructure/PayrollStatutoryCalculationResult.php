<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Domain\Payroll\Infrastructure\PayrollRunResult;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Checkpoint 9.6C -- immutable statutory snapshot (frozen by
 * `trg_payroll_statutory_results_freeze` the instant the parent run
 * reaches approved/posted). Never updated after that point -- the
 * Application layer (Checkpoint 9.6D) must construct every field at
 * INSERT time, matching `PayrollRunResult`'s own precedent.
 *
 * @property string $id
 * @property string $school_id
 * @property string $payroll_run_result_id
 * @property string|null $pf_rule_version_id
 * @property string|null $esi_rule_version_id
 * @property string|null $professional_tax_rule_version_id
 * @property string|null $lwf_rule_version_id
 * @property string|null $income_tax_rule_version_id
 * @property bool $is_pf_excluded_employee
 * @property string|null $pf_uncapped_statutory_wage
 * @property string|null $pf_contribution_base
 * @property string|null $employee_pf_mandatory
 * @property string|null $employee_pf_voluntary
 * @property string|null $employer_pf_total
 * @property string|null $employer_eps
 * @property string|null $employer_epf
 * @property string|null $pf_edli
 * @property string|null $pf_admin_charge
 * @property bool $esi_is_covered
 * @property string|null $esi_statutory_wage
 * @property string|null $employee_esi
 * @property string|null $employer_esi
 * @property string|null $professional_tax
 * @property bool $lwf_charged
 * @property string|null $employee_lwf
 * @property string|null $employer_lwf
 * @property string|null $tds_monthly_deduction
 * @property string|null $tds_residual_compliance_exception
 */
class PayrollStatutoryCalculationResult extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $fillable = [
        'school_id', 'payroll_run_result_id',
        'pf_rule_version_id', 'esi_rule_version_id', 'professional_tax_rule_version_id',
        'lwf_rule_version_id', 'income_tax_rule_version_id',
        'is_pf_excluded_employee', 'pf_uncapped_statutory_wage', 'pf_contribution_base',
        'employee_pf_mandatory', 'employee_pf_voluntary', 'employer_pf_total',
        'employer_eps', 'employer_epf', 'pf_edli', 'pf_admin_charge',
        'esi_is_covered', 'esi_statutory_wage', 'employee_esi', 'employer_esi',
        'professional_tax',
        'lwf_charged', 'employee_lwf', 'employer_lwf',
        'tds_monthly_deduction', 'tds_residual_compliance_exception',
    ];

    protected function casts(): array
    {
        return [
            'is_pf_excluded_employee' => 'boolean',
            'esi_is_covered' => 'boolean',
            'lwf_charged' => 'boolean',
        ];
    }

    /** @return BelongsTo<PayrollRunResult, $this> */
    public function payrollRunResult(): BelongsTo
    {
        return $this->belongsTo(PayrollRunResult::class);
    }
}
