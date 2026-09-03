<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class PayrollStatutoryAccountingConfiguration extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $fillable = [
        'school_id',
        'employee_pf_payable_ledger_account_id',
        'employer_eps_payable_ledger_account_id',
        'employer_epf_payable_ledger_account_id',
        'pf_admin_charge_payable_ledger_account_id',
        'edli_payable_ledger_account_id',
        'esi_payable_ledger_account_id',
        'tds_payable_ledger_account_id',
        'professional_tax_payable_ledger_account_id',
        'lwf_payable_ledger_account_id',
        'employer_pf_contribution_expense_ledger_account_id',
        'employer_esi_contribution_expense_ledger_account_id',
        'employer_lwf_contribution_expense_ledger_account_id',
        'currency',
    ];
}
