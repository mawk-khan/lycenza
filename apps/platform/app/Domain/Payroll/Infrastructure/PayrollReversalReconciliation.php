<?php

namespace App\Domain\Payroll\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HR.R1-C6 (PAY-18, OD-HR-23): a reversal took independence from a run that had
 * already posted, and someone independent must read what happened. OPEN exactly
 * while it has no review; it never clears on its own, blocks nothing else and has
 * no deadline. Append-only; written only in the reversal's own transaction.
 *
 * @property string $id
 * @property string $school_id
 * @property string $payroll_run_id
 * @property string $payroll_run_calculation_id
 * @property string $reversal_posting_id
 * @property string $evidence_version
 */
class PayrollReversalReconciliation extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $fillable = ['school_id', 'payroll_run_id', 'payroll_run_calculation_id', 'reversal_posting_id', 'evidence_version'];
}
