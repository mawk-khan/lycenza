<?php

namespace App\Domain\Payroll\Infrastructure;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\PayrollRunResultLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9.1 (ADR 0034 "Deduction accounting" / "Run kinds, correction
 * model, and posting") -- one earning/deduction line backing a
 * `PayrollRunResult`. `amount` is always non-negative; direction is
 * `salary_components.type` (earning|deduction) combined with `effect`
 * (increase|decrease) here, never a negative literal.
 * `resolved_ledger_account_id` snapshots the deduction's Finance
 * destination at calculation time. Frozen alongside its parent result
 * (`trg_payroll_run_result_lines_freeze`).
 *
 * @property string $id
 * @property string $school_id
 * @property string $payroll_run_result_id
 * @property string $salary_component_id
 * @property string $amount
 * @property string $effect increase|decrease
 * @property string|null $resolved_ledger_account_id
 * @property string $currency
 */
class PayrollRunResultLine extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = [
        'school_id',
        'payroll_run_result_id',
        'salary_component_id',
        'amount',
        'effect',
        'resolved_ledger_account_id',
        'currency',
    ];

    protected static function newFactory(): PayrollRunResultLineFactory
    {
        return PayrollRunResultLineFactory::new();
    }

    public function isIncrease(): bool
    {
        return $this->effect === 'increase';
    }

    /** @return BelongsTo<PayrollRunResult, $this> */
    public function result(): BelongsTo
    {
        return $this->belongsTo(PayrollRunResult::class, 'payroll_run_result_id');
    }

    /** @return BelongsTo<SalaryComponent, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function resolvedLedgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'resolved_ledger_account_id');
    }
}
