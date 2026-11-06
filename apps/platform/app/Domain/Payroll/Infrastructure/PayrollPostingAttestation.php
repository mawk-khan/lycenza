<?php

namespace App\Domain\Payroll\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HR.R1-C4 (PAY-16, OD-HR-22): an independent poster's statement that they
 * reviewed and accepted a change in a run's maker/checker evidence before
 * posting it. It is never a new approval. Append-only; written only by
 * PayrollPostingService::post(), in the posting transaction.
 *
 * @property string $id
 * @property string $school_id
 * @property string $payroll_run_id
 * @property string $payroll_run_calculation_id
 * @property string $payroll_run_posting_id
 * @property string $evidence_version
 * @property string $statement_code
 * @property string $attested_by_user_id
 */
class PayrollPostingAttestation extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    public const STATEMENT_INDEPENDENCE_CHANGED = 'independence_changed_v1';

    protected $fillable = [
        'school_id', 'payroll_run_id', 'payroll_run_calculation_id', 'payroll_run_posting_id',
        'evidence_version', 'statement_code', 'attested_by_user_id',
    ];
}
