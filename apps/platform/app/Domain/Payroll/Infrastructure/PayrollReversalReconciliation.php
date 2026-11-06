<?php

namespace App\Domain\Payroll\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * HR.R1-C6 (PAY-18, OD-HR-23): a reversal took independence from a run that had
 * already posted, and someone independent must read what happened. OPEN exactly
 * while it has no review; it never clears on its own, blocks nothing else and has
 * no deadline. Append-only; written only in the reversal's own transaction.
 *
 * It snapshots the moment: the assignment arrays and the reversal's facts never
 * change, whatever later approvals or the reversed run's retention do.
 *
 * @property string $id
 * @property string $school_id
 * @property string $payroll_run_id
 * @property string $payroll_run_calculation_id
 * @property string|null $reversal_posting_id NULL once the reversed run's retention took the posting
 * @property string $reversed_payroll_run_id
 * @property Carbon $reversed_period_month
 * @property Carbon $reversed_at
 * @property string $reversed_by_user_id
 * @property string $evidence_version
 * @property string $first_paid_assignments a PostgreSQL uuid[] literal
 * @property string $implicated_assignments a PostgreSQL uuid[] literal
 */
class PayrollReversalReconciliation extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $fillable = [
        'school_id', 'payroll_run_id', 'payroll_run_calculation_id', 'reversal_posting_id', 'reversed_payroll_run_id', 'reversed_period_month',
        'reversed_at', 'reversed_by_user_id', 'evidence_version', 'first_paid_assignments', 'implicated_assignments',
    ];

    protected function casts(): array
    {
        return ['reversed_period_month' => 'date', 'reversed_at' => 'datetime'];
    }
}
