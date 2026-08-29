<?php

namespace App\Domain\Payroll\Infrastructure;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\PayrollAccountingConfigurationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9.1 (ADR 0032 "Deduction accounting") -- the Payroll -> Finance
 * configuration seam. Exactly one row per School, naming the two fixed
 * accounts a normal (non-statutory) payroll run posts to. Per-deduction
 * liability accounts live on `SalaryComponent::$liability_ledger_account_id`
 * instead of a third column here -- see that model's docblock.
 *
 * @property string $id
 * @property string $school_id
 * @property string $salary_expense_ledger_account_id
 * @property string $salary_payable_ledger_account_id
 * @property string $currency
 */
class PayrollAccountingConfiguration extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = [
        'school_id',
        'salary_expense_ledger_account_id',
        'salary_payable_ledger_account_id',
        'currency',
    ];

    protected static function newFactory(): PayrollAccountingConfigurationFactory
    {
        return PayrollAccountingConfigurationFactory::new();
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function salaryExpenseLedgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'salary_expense_ledger_account_id');
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function salaryPayableLedgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'salary_payable_ledger_account_id');
    }
}
