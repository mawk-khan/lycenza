<?php

namespace App\Domain\Payroll\Infrastructure;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\SalaryComponentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9.1 (ADR 0032 "Compensation model") -- semantic component
 * identity only (Basic, HRA, PF-Employee, ...). No monetary value or
 * rate lives here -- see `SalaryStructureComponent` (structure-level
 * formula) and `CompensationAssignmentValue` (Employee-specific
 * amount).
 *
 * @property string $id
 * @property string $school_id
 * @property string $code
 * @property string $name
 * @property string $type earning|deduction
 * @property string|null $liability_ledger_account_id
 * @property string $currency
 * @property string $status active|inactive
 */
class SalaryComponent extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $fillable = [
        'school_id',
        'code',
        'name',
        'type',
        'liability_ledger_account_id',
        'currency',
        'status',
    ];

    protected static function newFactory(): SalaryComponentFactory
    {
        return SalaryComponentFactory::new();
    }

    public function isEarning(): bool
    {
        return $this->type === 'earning';
    }

    public function isDeduction(): bool
    {
        return $this->type === 'deduction';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function liabilityLedgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'liability_ledger_account_id');
    }
}
