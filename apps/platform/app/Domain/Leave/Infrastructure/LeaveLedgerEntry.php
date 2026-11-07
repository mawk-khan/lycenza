<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HRX.1 (ADR 0065 §4.5): one append-only ledger entry -- a kind and a
 * POSITIVE number of integer half-day units; the sign comes from the kind
 * (and `direction` for an adjustment). The balance is the sum of a key's
 * entries; the database refuses any entry that would make it negative.
 * Written only by LeaveLedgerWriter.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employment_record_id
 * @property string $leave_type_id
 * @property string $leave_year_id
 * @property string $kind
 * @property int $units
 * @property string|null $leave_request_id
 * @property string|null $year_close_id
 * @property string|null $year_close_reconciliation_id
 */
class LeaveLedgerEntry extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    /** HR.10 (ADR 0065 §29.3) adds `opening` and `accrual`; every earlier kind keeps its meaning and sign. */
    public const KINDS = ['allocation', 'adjustment', 'consumption', 'reversal', 'carry_forward_in', 'carry_forward_out', 'expiry', 'opening', 'accrual'];

    /** Kinds that increase the balance (an adjustment depends on its direction). The database guard uses the same list. */
    public const CREDIT_KINDS = ['allocation', 'reversal', 'carry_forward_in', 'opening', 'accrual'];

    /** Kinds that fund an account (an adjustment changes an existing funded account). */
    public const FUNDING_KINDS = ['allocation', 'accrual', 'opening'];

    public const ADJUSTMENT_REASONS = ['entitlement_change', 'allocation_correction', 'administrative_correction'];

    public const UPDATED_AT = null;

    protected $table = 'leave_ledger_entries';

    protected $fillable = ['school_id', 'employment_record_id', 'leave_type_id', 'tracks_balance', 'leave_year_id', 'leave_policy_assignment_id', 'kind', 'direction', 'units', 'reason_code', 'allocation_run_id', 'reverses_entry_id', 'source_type', 'source_id', 'actor_user_id', 'leave_request_id', 'year_close_id', 'year_close_reconciliation_id',
        // HR.10: the accrual month and run.
        'accrual_period', 'accrual_run_id'];

    protected function casts(): array
    {
        return ['units' => 'integer', 'tracks_balance' => 'boolean', 'accrual_period' => 'date'];
    }

    /** THE signed-units SQL expression of the ledger's sign convention (one place; mirrors `leave_ledger_guard()`). */
    public static function signedUnitsSql(string $alias = ''): string
    {
        $p = $alias === '' ? '' : "{$alias}.";
        $credit = self::isCreditSql($alias);

        return "CASE WHEN {$credit} THEN {$p}units ELSE -{$p}units END";
    }

    /** THE SQL predicate of a credit (HR.R1-C10, LVA-5). Every reader of the sign uses this or isCredit(). */
    public static function isCreditSql(string $alias = ''): string
    {
        $p = $alias === '' ? '' : "{$alias}.";
        $credits = implode(', ', array_map(fn (string $k) => "'{$k}'", self::CREDIT_KINDS));

        return "({$p}kind IN ({$credits}) OR ({$p}kind = 'adjustment' AND {$p}direction = 'credit'))";
    }

    /** The PHP form of isCreditSql(), for a row already read. */
    public static function isCredit(string $kind, ?string $direction): bool
    {
        return in_array($kind, self::CREDIT_KINDS, true) || ($kind === 'adjustment' && $direction === 'credit');
    }
}
